import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { computed, ref } from 'vue';
import * as dates from '../resources/js/Support/dates.js';
import { kpiDateTime, kpiNumber } from '../resources/js/Support/kpiFormat.js';
import { prioritizeActions, taskDeadline, uniqueShortcuts } from '../resources/js/Support/homePriorities.js';

test('action priorities put overdue work first without mutating source order or summing overlapping counters', () => {
    const input = [{ key: 'quiet', value: 0 }, { key: 'inbox', value: 20, tone: 'warning' }, { key: 'late', value: 1, tone: 'danger' }, { key: 'notice', value: 30, tone: 'info' }];
    assert.deepEqual(prioritizeActions(input).map(item => item.key), ['late', 'inbox', 'notice']);
    assert.equal(input[0].key, 'quiet');
    assert.deepEqual(prioritizeActions([]), []);
});

test('deadline labels distinguish overdue, under an hour, upcoming and invalid dates', () => {
    const now = Date.parse('2026-10-24T10:00:00Z');
    assert.equal(taskDeadline({ overdue: true }, now).tone, 'danger');
    assert.equal(taskDeadline({ dueAt: '2026-10-24T09:59:00Z' }, now).tone, 'danger');
    assert.equal(taskDeadline({ dueAt: '2026-10-24T10:30:00Z' }, now).tone, 'warning');
    assert.match(taskDeadline({ dueAt: '2026-10-24T11:30:00Z' }, now).label, /2/);
    assert.equal(taskDeadline({ dueAt: '2026-10-26T10:00:00Z' }, now).tone, 'muted');
    assert.equal(taskDeadline({ dueAt: 'invalid' }, now), null);
    assert.equal(taskDeadline({}, now), null);
});

test('shortcuts only use supplied URLs, exclude duplicates and keep the allowed order', () => {
    const allowed = [{ url: '/tasks' }, { url: '/finance' }, { url: '/finance' }, { url: '/finance?tab=pending' }, {}];
    assert.deepEqual(uniqueShortcuts(allowed, ['/tasks']).map(item => item.url), ['/finance', '/finance?tab=pending']);
    assert.deepEqual(uniqueShortcuts([], ['/tasks']), []);
});

function home(props) {
    let request;
    const context = {
        computed, ref, watch: () => {}, nextTick: async () => {},
        defineProps: () => props, usePage: () => ({ props: { auth: { user: { name: 'User' } } } }),
        prioritizeActions, taskDeadline, uniqueShortcuts,
        router: { reload: options => { request = options; } },
    };
    const script = readFileSync(new URL('../resources/js/Pages/Home.vue', import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0];
    for (const match of script.matchAll(/import \{([^}]+)\} from ['"][^'"]*\/dates\.js['"]/g)) {
        for (const name of match[1].split(',').map(name => name.trim())) {
            context[name] = dates[name];
        }
    }
    const source = script.replace(/^import .*;\r?\n/gm, '');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.api = { dashboardLayout, sections, stats, leadingShortcuts, remainingShortcuts, visibleTasks, taskView, refreshSummary, refreshing, refreshError, date, dateTime, dayLabel, ago, isLate, quickDue, quickDueDate, quickDueHour, quickDueMinute, quickDuePeriod, quickDuePickerOpen, openQuickDuePicker };`, context);
    return { ...context.api, request: () => request };
}

test('home and KPI dates render database timestamps and tolerate missing or invalid values', () => {
    const app = home({ layout: [], shortcuts: [], home: { stats: {}, tasks: [] }, actionCenter: [] });
    const timestamp = '2020-01-02 10:30:00.123456+02';
    for (const format of [app.date, app.dateTime, app.dayLabel, app.ago, kpiDateTime]) {
        assert.ok(format(timestamp));
        assert.doesNotThrow(() => format(null));
        assert.doesNotThrow(() => format('invalid'));
    }
    assert.equal(app.isLate(timestamp), true);
    assert.equal(app.isLate('2999-01-02 10:30:00+02'), false);
    assert.equal(app.isLate('invalid'), false);
    assert.equal(app.isLate(null), false);
    assert.equal(app.ago('invalid'), '—');
    assert.equal(kpiDateTime(null), '—');
});

test('system date formatting uses Arabic Gregorian month names with Latin digits', () => {
    const formattedDate = dates.formatDate('2026-10-27', { day: 'numeric', month: 'long', year: 'numeric' });
    const formattedDateTime = dates.formatDateTime('2026-10-27 15:02:00');
    const formattedNumber = new Intl.NumberFormat(dates.arabicLatinDigitsLocale).format(1234);

    assert.match(formattedDate, /27/);
    assert.match(formattedDate, /أكتوبر/);
    assert.match(formattedDate, /2026/);
    assert.match(formattedDateTime, /27/);
    assert.match(formattedDateTime, /أكتوبر/);
    assert.match(formattedNumber, /1,234/);
    assert.match(kpiNumber(1234), /1,234/);
    assert.doesNotMatch(`${formattedDate} ${formattedDateTime} ${formattedNumber} ${kpiNumber(1234)}`, /[٠-٩]/);
});

test('role shortcuts do not duplicate header actions and configured sections retain their order', () => {
    const props = {
        executiveWorkspace: true,
        layout: [{ key: 'tasks', section: 'base' }, { key: 'shortcuts', section: 'role' }, { key: 'finance', section: 'role' }],
        shortcuts: [{ code: 'tasks', url: '/tasks' }, ...['a', 'b', 'c', 'd'].map(code => ({ code, url: `/${code}` }))],
        home: { stats: { tasks: 2, overdue: 1, open: 0, completed: 1 }, tasks: [{ key: 'one', overdue: true }, { key: 'two' }] },
        actionCenter: [{ key: 'tasks', value: 2, tone: 'danger' }],
    };
    const app = home(props);
    assert.deepEqual(Array.from(app.leadingShortcuts.value, item => item.code), ['a', 'b', 'c']);
    assert.deepEqual(Array.from(app.remainingShortcuts.value, item => item.code), ['d']);
    assert.equal(app.stats.value.length, 2);
    assert.deepEqual(Array.from(app.sections.value[1].blocks, item => item.key), ['shortcuts', 'finance']);
    app.taskView.value = 'overdue';
    assert.equal(app.visibleTasks.value.length, 1);
    assert.equal(props.home.tasks.length, 2);
});

test('personal dashboard shows only the requested blocks in the requested order', () => {
    const requestedBlocks = ['tasks', 'todo', 'my_requests', 'correspondence', 'leave_balances', 'calendar', 'notifications', 'activity', 'evaluation', 'profile', 'team'];
    const props = {
        layout: [
            { key: 'finance', section: 'executive' },
            ...requestedBlocks.slice(0, -1).reverse().map(key => ({ key, section: 'base' })),
            { key: 'team', section: 'role' },
            { key: 'stats', section: 'base' },
        ],
        shortcuts: [{ code: 'finance', url: '/finance' }],
        home: { stats: {}, tasks: [] },
        actionCenter: [],
    };
    const app = home(props);

    assert.deepEqual(Array.from(app.dashboardLayout.value, block => block.key), requestedBlocks);
    assert.deepEqual(
        Array.from(app.sections.value, section => Array.from(section.blocks, block => block.key)),
        [requestedBlocks.slice(0, -1), ['team']],
    );
    assert.deepEqual(Array.from(app.leadingShortcuts.value), []);
});

test('personal task quick-add prioritizes title space and captures date with an Arabic 12-hour time selector', () => {
    const source = readFileSync(new URL('../resources/js/Pages/Home.vue', import.meta.url), 'utf8');
    const form = source.match(/<form class="mb-3 flex flex-nowrap items-center gap-2" @submit\.prevent="addTodo">([\s\S]*?)<\/form>/)?.[1];
    assert.ok(form);
    assert.match(form, /id="todo-quick-title" v-model="quickTitle"[^>]*class="field min-w-0 flex-1"/);
    assert.match(form, /type="button" class="btn btn-secondary btn-sm" aria-label="اختيار موعد استحقاق" :aria-expanded="quickDuePickerOpen" aria-controls="todo-quick-due-picker"/);
    assert.match(form, /v-if="quickDuePickerOpen" id="todo-quick-due-picker"/);
    assert.match(form, /id="todo-quick-due-date" v-model="quickDueDate" type="date"/);
    assert.match(form, /id="todo-quick-due-hour" v-model="quickDueHour"/);
    assert.match(form, /id="todo-quick-due-minute" v-model="quickDueMinute"/);
    assert.match(form, /id="todo-quick-due-period" v-model="quickDuePeriod"[\s\S]*<option value="am">صباحًا \(ص\)<\/option>[\s\S]*<option value="pm">مساءً \(م\)<\/option>/);
    assert.match(form, /<button type="submit" class="btn btn-primary btn-sm shrink-0"[^>]*>إضافة<\/button>/);
    assert.match(source, /v-if="quickDue"[^>]*>موعد الاستحقاق: \{\{ dateTime\(quickDue\) \}\}/);

    const app = home({ layout: [], shortcuts: [], home: { stats: {}, tasks: [] }, actionCenter: [] });
    app.openQuickDuePicker();
    assert.equal(app.quickDuePickerOpen.value, true);
    app.openQuickDuePicker();
    assert.equal(app.quickDuePickerOpen.value, false);

    app.quickDueDate.value = '2026-10-27';
    app.quickDueHour.value = '12';
    app.quickDueMinute.value = '05';
    app.quickDuePeriod.value = 'am';
    assert.equal(app.quickDue.value, '2026-10-27T00:05');
    app.quickDuePeriod.value = 'pm';
    assert.equal(app.quickDue.value, '2026-10-27T12:05');
    app.quickDueHour.value = '01';
    assert.equal(app.quickDue.value, '2026-10-27T13:05');
});

test('summary refresh is explicit, guarded, scoped and reports unsuccessful completion', () => {
    const app = home({ layout: [], shortcuts: [], home: { stats: {}, tasks: [] }, actionCenter: [] });
    app.refreshSummary();
    const first = app.request();
    assert.deepEqual(Array.from(first.only), ['home', 'actionCenter']);
    app.refreshSummary();
    assert.equal(app.request(), first);
    first.onFinish();
    assert.equal(app.refreshing.value, false);
    assert.ok(app.refreshError.value);
    app.refreshSummary();
    app.request().onSuccess();
    app.request().onFinish();
    assert.equal(app.refreshError.value, '');
});
