import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { computed, ref } from 'vue';

function monitor(fetcher) {
    let mount;
    let unmount;
    let poll;
    const events = {};
    const timers = new Map();
    let timerId = 0;
    const context = {
        computed, ref, AbortController, performance, Date,
        navigator: { onLine: true },
        fetch: fetcher,
        onMounted: callback => { mount = callback; },
        onUnmounted: callback => { unmount = callback; },
        window: {
            setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
            clearTimeout: id => timers.delete(id),
            setInterval: callback => { poll = callback; return 1; },
            clearInterval: () => { poll = null; },
            addEventListener: (name, callback) => { events[name] = callback; },
            removeEventListener: name => delete events[name],
        },
        document: {
            hidden: false,
            addEventListener: (name, callback) => { events[name] = callback; },
            removeEventListener: name => delete events[name],
        },
    };
    const source = readFileSync(new URL('../resources/js/Components/ConnectionStatus.vue', import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.api = { checkConnection, status, checking, lastConnected, responseTime };`, context);
    return { ...context.api, context, mount: () => mount(), unmount: () => unmount(), poll: () => poll?.(), events, timers };
}

test('server response, not browser connectivity alone, determines availability', async () => {
    let response = { ok: true, status: 200, json: async () => ({ status: 'online' }) };
    let calls = 0;
    const app = monitor(async (url, options) => {
        calls++;
        assert.equal(url, '/connection-status');
        assert.equal(options.cache, 'no-store');
        return response;
    });
    await app.checkConnection();
    assert.equal(app.status.value, 'online');
    assert.ok(app.lastConnected.value);
    assert.ok(app.responseTime.value >= 0);
    response = { ok: false, status: 503 };
    await app.checkConnection();
    assert.equal(app.status.value, 'unavailable');
    assert.equal(app.responseTime.value, null);
    for (const expired of [{ status: 401 }, { status: 419 }, { type: 'opaqueredirect' }]) {
        response = expired;
        await app.checkConnection();
        assert.equal(app.status.value, 'session');
    }
    app.context.navigator.onLine = false;
    const previousCalls = calls;
    await app.checkConnection();
    assert.equal(app.status.value, 'offline');
    assert.equal(calls, previousCalls);
    app.unmount();
});

test('malformed responses and network failures never report online', async () => {
    for (const fetcher of [
        async () => ({ ok: true, json: async () => ({ status: 'unexpected' }) }),
        async () => ({ ok: true, json: async () => { throw new Error('not JSON'); } }),
        async () => { throw new Error('network failure'); },
    ]) {
        const app = monitor(fetcher);
        await app.checkConnection();
        assert.equal(app.status.value, 'unavailable');
        assert.equal(app.checking.value, false);
        assert.equal(app.timers.size, 0);
        app.unmount();
    }
});

test('timeout releases the request lock and duplicate checks do not send extra requests', async () => {
    let calls = 0;
    const app = monitor((url, { signal }) => {
        calls++;
        return new Promise((resolve, reject) => signal.addEventListener('abort', () => reject(new Error('aborted'))));
    });
    const pending = app.checkConnection();
    await app.checkConnection();
    assert.equal(calls, 1);
    assert.equal(app.checking.value, true);
    [...app.timers.values()][0]();
    await pending;
    assert.equal(app.status.value, 'unavailable');
    assert.equal(app.checking.value, false);
    app.unmount();
});

test('hidden pages skip polling and cleanup removes listeners and pending updates', async () => {
    let resolve;
    let calls = 0;
    const app = monitor(() => { calls++; return new Promise(done => { resolve = done; }); });
    app.mount();
    app.context.document.hidden = true;
    app.poll();
    assert.equal(calls, 1);
    app.unmount();
    resolve({ ok: true, status: 200, json: async () => ({ status: 'online' }) });
    await new Promise(done => setImmediate(done));
    assert.equal(app.status.value, 'checking');
    assert.equal(app.lastConnected.value, null);
    assert.equal(Object.keys(app.events).length, 0);
    assert.equal(app.timers.size, 0);
});
