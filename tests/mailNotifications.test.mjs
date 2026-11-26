import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { setImmediate } from 'node:timers/promises';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createRenderer } from 'vue';

// Run the real component with Vue's renderer and controlled network/audio browser boundaries.
const source = await readFile(new URL('../resources/js/Components/NotificationBell.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const inertiaStub = `export const router = {
    on: (...args) => globalThis.mailHarness.router.on(...args),
    visit: (...args) => globalThis.mailHarness.router.visit(...args),
    post: (...args) => globalThis.mailHarness.router.post(...args),
};
export const usePage = () => ({ props: { notifications: { unread: 4 } } });
export const Link = {};`;
const dataUrl = code => `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
const script = compileScript(descriptor, { id: 'mail-test', genDefaultAs: 'component' }).content
    .replaceAll("'vue'", JSON.stringify(import.meta.resolve('vue')))
    .replaceAll("'@inertiajs/vue3'", JSON.stringify(dataUrl(inertiaStub)))
    .replaceAll("'../Support/dates.js'", JSON.stringify(new URL('../resources/js/Support/dates.js', import.meta.url).href));
const { default: Component } = await import(dataUrl(`${script}\ncomponent.render = () => null; export default component;`));
const renderer = createRenderer({
    createComment: () => ({}), insert() {}, remove() {}, parentNode: () => null,
    nextSibling: () => null, createElement: () => ({}), createText: () => ({}),
    setText() {}, setElementText() {}, patchProp() {},
});

async function mountFeed(t, channel = 'mail') {
    t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });
    const storage = new Map();
    const state = {
        response: { items: [{ id: 10, title: 'Existing', read: false }], unread: 1 },
        requests: [], tones: 0, visits: [], posts: [], finish: null, fail: false,
    };
    const document = new EventTarget();
    document.visibilityState = 'visible';
    class AudioContext {
        state = 'running';
        currentTime = 0;
        resume() { return Promise.resolve(); }
        close() { return Promise.resolve(); }
        createOscillator() {
            return { frequency: {}, connect() { return this; }, start() { state.tones++; }, stop() {}, disconnect() {} };
        }
        createGain() {
            return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} };
        }
    }
    const globals = {
        document,
        window: { AudioContext, localStorage: {
            getItem: key => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        } },
        fetch: async url => {
            state.requests.push(url);
            if (state.fail) throw new Error('Offline');
            return { ok: true, json: async () => state.response };
        },
        mailHarness: { router: {
            on: (_event, callback) => { state.finish = callback; return () => { state.finish = null; }; },
            visit: url => state.visits.push(url),
            post: url => state.posts.push(url),
        } },
    };
    const original = new Map(Object.keys(globals).map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    for (const [key, value] of Object.entries(globals)) Object.defineProperty(globalThis, key, { value, configurable: true, writable: true });
    const app = renderer.createApp(Component, { channel });
    const instance = app.mount({});
    t.after(() => {
        app.unmount();
        for (const [key, descriptor] of original) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
    });
    await setImmediate();
    return { feed: instance.$.setupState, state, storage, document };
}

test('mail starts silently, then sounds once for a new delivery even if the unread total stays the same', async t => {
    const { feed, state, storage } = await mountFeed(t);
    assert.equal(state.tones, 0);
    assert.equal(feed.prefs.sound, true);
    assert.equal(feed.unread, 1);
    assert.deepEqual(state.requests, ['/correspondence/recent']);
    state.response = { items: [{ id: 11, title: 'New', read: false }], unread: 1 };

    await feed.poll();
    assert.equal(state.tones, 2);
    assert.equal(feed.toast.title, 'New');
    storage.delete('aims.mail.lastSoundAt');
    await feed.poll();
    assert.equal(state.tones, 2);
    // An older letter resurfacing after newer letters leave the inbox is not a new delivery.
    state.response = { items: [{ id: 5, title: 'Older', read: false }], unread: 1 };
    await feed.poll();
    assert.equal(state.tones, 2);
});

test('mail mute is independent of the bell and still updates while the tab is hidden', async t => {
    const { feed, state, storage, document } = await mountFeed(t);
    feed.toggleSound();
    assert.equal(JSON.parse(storage.get('aims.mail.prefs')).sound, false);
    assert.equal(storage.has('aims.notify.prefs'), false);
    document.visibilityState = 'hidden';
    state.response = { items: [{ id: 12, title: 'Incoming', read: false }], unread: 2 };

    await feed.poll();
    assert.equal(feed.unread, 2);
    assert.equal(feed.toast.title, 'Incoming');
    assert.equal(state.tones, 0);
});

test('mail opens its correspondence page and navigation refreshes the read state', async t => {
    const { feed, state } = await mountFeed(t);
    feed.openItem({ id: 10, url: '/correspondence/letter-id' });
    assert.deepEqual(state.visits, ['/correspondence/letter-id']);
    assert.deepEqual(state.posts, []);
    state.response = { items: [{ id: 10, read: true }], unread: 0 };

    await state.finish();
    assert.equal(feed.unread, 0);
    assert.equal(feed.items[0].read, true);
    assert.equal(state.tones, 0);
});

test('a failed poll preserves the inbox and a successful retry detects the new delivery', async t => {
    const { feed, state } = await mountFeed(t);
    state.fail = true;
    await feed.poll();
    assert.equal(feed.failed, true);
    assert.equal(feed.items[0].id, 10);
    state.fail = false;
    state.response = { items: [{ id: 20, title: 'Retry', read: false }], unread: 2 };

    await feed.poll();
    assert.equal(feed.failed, false);
    assert.equal(feed.unread, 2);
    assert.equal(state.tones, 2);
});

test('the general bell retains its own endpoint, sound defaults and notification opening action', async t => {
    const { feed, state } = await mountFeed(t, 'notifications');
    assert.deepEqual(state.requests, ['/notifications/recent']);
    assert.equal(feed.prefs.sound, false);

    feed.openItem({ id: 'notification-id' });
    assert.deepEqual(state.posts, ['/notifications/notification-id/open']);
    assert.deepEqual(state.visits, []);
});
