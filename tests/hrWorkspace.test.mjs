import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

test('HR workspace filtering and pagination stay on home and preserve query state', () => {
    const visits = [];
    const watchers = [];
    const source = readFileSync(new URL('../resources/js/Components/Hr/ServicesWorkspace.vue', import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    const props = {
        endpoint: '/ui-preview', today: '2026-10-29',
        filters: { date: '2026-10-28', q: '', attendance_status: '', services: 'open' },
        pages: { attendance_page: 3, leaves_page: 2, services_page: 4 },
    };
    const context = vm.createContext({
        defineOptions: () => {}, defineProps: () => props,
        reactive: value => value, computed: callback => callback,
        watch: (source, callback) => watchers.push(callback),
        router: { get: (...args) => visits.push(JSON.parse(JSON.stringify(args))) },
    });
    vm.runInContext(source, context);
    context.goPage('attendance_page', 5, 'attendance');
    let [endpoint, query, options] = visits.at(-1);
    assert.equal(endpoint, '/ui-preview');
    assert.equal(query.attendance_page, 5);
    assert.equal(query.leaves_page, 2);
    assert.deepEqual(options.only, ['attendance', 'filters', 'pages']);
    assert.equal(options.preserveState, true);
    context.filterServices('all');
    assert.equal(visits.at(-1)[1].services, 'all');
    assert.equal(visits.at(-1)[1].services_page, undefined);
    watchers[0]({ ...props.filters, q: '123' });
    watchers[1]({ attendance_page: 2, leaves_page: 1, services_page: 1 });
    context.searchAttendance();
    assert.equal(visits.at(-1)[1].q, '123');
    assert.equal(visits.at(-1)[1].attendance_page, undefined);
    context.changeDate('2026-10-27');
    assert.equal(visits.at(-1)[1].date, '2026-10-27');
    assert.equal(visits.at(-1)[2].only, undefined);
    assert.ok(Object.keys(visits.at(-1)[1]).every(key => !key.endsWith('_page')));
});
