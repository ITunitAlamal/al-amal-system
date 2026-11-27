import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { computed, reactive, ref } from 'vue';
import { columnStorageKey, normalizeHiddenColumns } from '../resources/js/Support/tableColumns.js';

function component(path, context, exposed) {
    const source = readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.api = { ${exposed} };`, context);
    return context.api;
}

test('external correspondence navigation follows secretariat access and is removed when that access ends', () => {
    const props = reactive({
        filters: { channel: 'internal', box: 'inbox' }, records: { data: [] },
        counts: { all: 2, external_incoming: 3, external_outgoing: 4 },
        isAdmin: true, canCreate: true, canCreateExternalArchive: false,
    });
    const context = { computed, ref, watch: () => {}, defineProps: () => props, usePage: () => ({ props: {} }) };
    const api = component('Pages/Correspondence/Index.vue', context, 'channels');

    assert.deepEqual(Array.from(api.channels.value, item => item.id), ['internal']);
    props.canCreateExternalArchive = true;
    assert.deepEqual(Array.from(api.channels.value, item => item.id), ['internal', 'external']);
    assert.equal(api.channels.value[1].count, 7);
    props.canCreateExternalArchive = false;
    assert.deepEqual(Array.from(api.channels.value, item => item.id), ['internal']);
});

const columns = [{ key: 'name', required: true }, { key: 'number', required: true }, { key: 'actions', required: true }, { key: 'department' }, { key: 'status' }];

test('required and unknown columns cannot be hidden and storage is scoped by user and list', () => {
    assert.deepEqual(normalizeHiddenColumns(['name', 'number', 'actions', 'department', 'department', 'unknown'], columns), ['department']);
    assert.deepEqual(normalizeHiddenColumns({ department: true }, columns), []);
    assert.deepEqual(normalizeHiddenColumns(null, columns), []);
    assert.notEqual(columnStorageKey(7, 'employees'), columnStorageKey(8, 'employees'));
    assert.notEqual(columnStorageKey(7, 'employees'), columnStorageKey(7, 'letters'));
    assert.equal(columnStorageKey(null, 'employees'), null);
});

test('column selection restores, resets, isolates users and tolerates blocked or corrupt storage', () => {
    const page = { props: { auth: { user: { id: 7 } } } };
    const storage = new Map([[columnStorageKey(7, 'employees'), '["name","department"]']]);
    let changeUser;
    let selected;
    const context = {
        defineProps: () => ({ table: 'employees', columns, modelValue: [] }),
        defineEmits: () => (name, value) => { selected = value; },
        usePage: () => page,
        columnStorageKey, normalizeHiddenColumns,
        onMounted: callback => callback(),
        watch: (getter, callback) => { changeUser = callback; },
        localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
    };
    const api = component('Components/ColumnPicker.vue', context, 'load, update');
    assert.deepEqual(selected, ['department']);
    api.update(['status']);
    assert.equal(storage.get(columnStorageKey(7, 'employees')), '["status"]');
    api.update([]);
    assert.deepEqual(selected, []);
    page.props.auth.user.id = 8;
    changeUser();
    assert.equal(selected.length, 0);
    storage.set(columnStorageKey(8, 'employees'), '{broken');
    api.load();
    assert.equal(selected.length, 0);
    context.localStorage.getItem = () => { throw new Error('blocked'); };
    context.localStorage.setItem = () => { throw new Error('blocked'); };
    api.load();
    api.update(['department']);
    assert.deepEqual(selected, ['department']);
});

test('preview opens a modal, closes on its backdrop and restores trigger focus', async () => {
    const props = { title: '', fields: [], href: '' };
    let changed;
    let closed = 0;
    let focusRestored = false;
    const context = {
        ref, nextTick: async () => {}, useId: () => 'preview-heading',
        defineProps: () => props, defineEmits: () => () => { closed++; },
        watch: (getter, callback) => { changed = callback; }, onUnmounted: () => {},
        document: { activeElement: { isConnected: true, focus: () => { focusRestored = true; } } },
    };
    const api = component('Components/RecordPreview.vue', context, 'dialog, closed, closeOnBackdrop');
    api.dialog.value = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    await changed('Employee');
    assert.equal(api.dialog.value.open, true);
    api.closeOnBackdrop({ target: {} });
    assert.equal(api.dialog.value.open, true);
    api.closeOnBackdrop({ target: api.dialog.value });
    assert.equal(api.dialog.value.open, false);
    api.closed();
    assert.equal(closed, 1);
    assert.equal(focusRestored, true);
    api.dialog.value = null;
    await changed('Unmounted');
});

test('removing an applied filter keeps other applied filters and resets pagination', () => {
    const cases = [
        ['Pages/Admin/EmployeeProfiles/Index.vue', { filters: { search: 'staff', department: 5, state: 'draft' }, departments: [], employees: { data: [] } }, 'search', '/admin/employee-profiles', { department: 5, state: 'draft' }, {}],
        ['Pages/Admin/Employees/Index.vue', { filters: { q: 'staff', status: 'active' }, employees: { data: [] }, roles: [], departments: [] }, 'q', '/admin/employees', { status: 'active' }, {}],
        ['Pages/Correspondence/Index.vue', { filters: { q: 'report', box: 'sent', channel: 'internal', direction: 'outgoing' }, records: { data: [] } }, 'q', '/correspondence', { box: 'sent', channel: 'internal', direction: 'outgoing', q: '' }, { channel: 'internal', direction: 'outgoing', box: 'sent' }],
        ['Pages/Correspondence/Index.vue', { filters: { q: 'report', box: 'all', channel: 'external', direction: 'incoming' }, records: { data: [] } }, 'q', '/correspondence', { box: 'all', channel: 'external', direction: 'incoming', q: '' }, { channel: 'external', direction: 'incoming', box: 'all' }],
    ];
    for (const [path, props, key, url, expected, cleared] of cases) {
        let request;
        const context = { computed, ref, watch: () => {}, URLSearchParams, defineProps: () => props, usePage: () => ({ props: { flash: {} } }), useForm: data => data, router: { get: (url, data) => { request = { url, data }; } } };
        const api = component(path, context, 'removeFilter, clearFilters');
        api.removeFilter(key);
        assert.equal(request.url, url);
        assert.deepEqual(JSON.parse(JSON.stringify(request.data)), expected);
        api.clearFilters();
        assert.deepEqual(JSON.parse(JSON.stringify(request.data)), cleared);
    }
});
