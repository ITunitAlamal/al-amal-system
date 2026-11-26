import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

function loadSupport(filename, context) {
    const source = readFileSync(new URL(`../resources/js/Support/${filename}.js`, import.meta.url), 'utf8')
        .replace(/^import .*;\r?\n/gm, '')
        .replace('export function', 'function');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.install = ${filename};`, context);
    context.install();
}

test('dirty forms can cancel navigation without blocking saves or background requests', () => {
    const events = {};
    const native = {};
    let dirty = true;
    let accept = false;
    let prompts = 0;
    let cleanup;
    const context = {
        onMounted: callback => callback(),
        onUnmounted: callback => { cleanup = callback; },
        router: { on: (name, callback) => { events[name] = callback; return () => delete events[name]; } },
        window: {
            confirm: () => { prompts++; return accept; },
            addEventListener: (name, callback) => { native[name] = callback; },
            removeEventListener: name => delete native[name],
        },
    };
    const source = readFileSync(new URL('../resources/js/Support/useUnsavedChanges.js', import.meta.url), 'utf8')
        .replace(/^import .*;\r?\n/gm, '').replace('export function', 'function');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.install = useUnsavedChanges;`, context);
    context.install(() => dirty);
    const navigate = (overrides = {}) => {
        const event = { detail: { visit: { method: 'get', showProgress: true, ...overrides } }, preventDefault() { this.defaultPrevented = true; } };
        events.before(event);
        return !!event.defaultPrevented;
    };
    assert.equal(navigate(), true);
    assert.equal(navigate({ method: 'post' }), false);
    assert.equal(navigate({ method: 'put' }), false);
    assert.equal(navigate({ prefetch: true }), false);
    assert.equal(navigate({ showProgress: false }), false);
    assert.equal(prompts, 1);
    const unload = { preventDefault() { this.prevented = true; } };
    native.beforeunload(unload);
    assert.equal(unload.prevented, true);
    accept = true;
    assert.equal(navigate(), false);
    dirty = false;
    assert.equal(navigate(), false);
    assert.equal(prompts, 2);
    const cleanUnload = { preventDefault() { this.prevented = true; } };
    native.beforeunload(cleanUnload);
    assert.equal(cleanUnload.prevented, undefined);
    cleanup();
    assert.equal(Object.keys(events).length + Object.keys(native).length, 0);
});

test('return navigation restores applied filters while respecting explicit filters and user isolation', () => {
    const events = {};
    let observe;
    let cleanup;
    let stopped = false;
    const page = { url: '/correspondence?q=report&box=sent&page=3&unrelated=secret', props: { auth: { user: { id: 7 } } } };
    const context = {
        URL, URLSearchParams,
        window: { location: { origin: 'https://aims.test' } },
        usePage: () => page,
        onMounted: callback => callback(),
        onUnmounted: callback => { cleanup = callback; },
        watch: (getter, callback) => { observe = callback; callback(getter()); return () => { stopped = true; }; },
        router: { on: (name, callback) => { events[name] = callback; return () => delete events[name]; } },
    };
    loadSupport('useListFilters', context);
    const navigate = (url = '/correspondence', overrides = {}) => {
        const visit = { url: new URL(url, context.window.location.origin), method: 'get', ...overrides };
        events.before({ detail: { visit } });
        return visit.url.search;
    };
    assert.equal(navigate(), '?q=report&box=sent&page=3');
    assert.equal(navigate('/correspondence?q=new'), '?q=new');
    assert.equal(navigate('/correspondence', { preserveState: true }), '');
    assert.equal(navigate('/correspondence', { method: 'post' }), '');
    assert.equal(navigate('/correspondence', { prefetch: true }), '');
    assert.equal(navigate('https://external.test/correspondence'), '');
    assert.equal(navigate('/unrelated'), '');
    observe(['/correspondence', 7]);
    assert.equal(navigate(), '');
    observe(['/admin/employees?q=staff&status=active&page=2', 7]);
    assert.equal(navigate('/admin/employees'), '?q=staff&status=active&page=2');
    observe(['/admin/employee-profiles?search=staff&page=4', 7]);
    assert.equal(navigate('/admin/employee-profiles'), '?search=staff&page=4');
    observe(['/dashboard', 8]);
    assert.equal(navigate('/admin/employees'), '');
    assert.equal(navigate('/admin/employee-profiles'), '');
    cleanup();
    assert.equal(stopped, true);
    assert.equal(Object.keys(events).length, 0);
});
