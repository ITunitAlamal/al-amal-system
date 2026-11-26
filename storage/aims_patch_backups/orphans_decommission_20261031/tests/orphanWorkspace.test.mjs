import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compileTemplate } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import { recordResultsUrl, recordWithResults } from '../resources/js/Support/orphanNavigation.js';

function setupComponent(path, context, exposed) {
    const source = readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
    const script = source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    vm.createContext(context);
    vm.runInContext(`${script}\nglobalThis.api = { ${exposed} };`, context);
    return { api: context.api, source };
}

function staging(sensitive = true, get = async () => ({ data: { total: 0 } })) {
    return setupComponent('Pages/Orphans/Staging.vue', {
        computed: Vue.computed, ref: Vue.ref, watch: () => {}, onMounted: () => {},
        defineProps: () => ({ ready: true, batch: { public_id: 'synthetic-batch' }, can: { sensitive }, issueTypes: [], reviewStatuses: {}, severities: {} }),
        location: { search: '?tab=children' }, URLSearchParams,
        axios: { get },
    }, 'searchTerm, filters, tab, data, busy, applied, activeFilters, load, exportUrl');
}

test('one staging search field preserves exact nine-digit IDs and canonical Arabic search text', () => {
    const { api } = staging();

    api.searchTerm.value = '001234567';
    assert.equal(api.filters.value.national_id, '001234567');
    assert.equal(api.filters.value.q, '');

    api.searchTerm.value = 'أحمَد';
    assert.equal(api.filters.value.q, 'أحمَد');
    assert.equal(api.filters.value.national_id, '');
});

test('the search field never submits an identity criterion without sensitive-data capability', async () => {
    let query;
    const { api } = staging(false, async (url, options) => { query = options.params; return { data: { total: 0 } }; });

    api.searchTerm.value = '001234567';
    await api.load();

    assert.equal(query.national_id, undefined);
    assert.equal(query.q, '001234567');
});

test('staging export uses the applied result set rather than unsubmitted edits and omits pagination', async () => {
    const { api } = staging();
    api.searchTerm.value = '001234567';
    await api.load();

    api.searchTerm.value = 'different draft';
    const exported = new URL(api.exportUrl('csv'), 'https://aims.invalid');

    assert.equal(exported.pathname, '/orphans/staging/export/csv');
    assert.equal(exported.searchParams.get('national_id'), '001234567');
    assert.equal(exported.searchParams.has('q'), false);
    assert.equal(exported.searchParams.has('page'), false);
    assert.equal(exported.searchParams.has('unmask'), false);
    assert.ok(api.activeFilters.value.find(filter => filter.key === 'national_id').label.includes('•••••4567'));
});

test('an older staging response cannot overwrite the newest search or its export criteria', async () => {
    const pending = [];
    const { api } = staging(true, () => new Promise(resolve => pending.push(resolve)));
    api.searchTerm.value = 'first';
    const first = api.load();
    api.searchTerm.value = 'second';
    const second = api.load();

    pending[1]({ data: { total: 2 } });
    await second;
    pending[0]({ data: { total: 99 } });
    await first;

    assert.equal(api.data.value.total, 2);
    assert.equal(api.busy.value, false);
    assert.equal(new URL(api.exportUrl('xlsx'), 'https://aims.invalid').searchParams.get('q'), 'second');
});

test('action disclosure restores focus on Escape and removes its outside-click listener', () => {
    let mounted, unmounted, focused = false;
    const listeners = new Map();
    const { api } = setupComponent('Components/Orphans/ActionDisclosure.vue', {
        ref: Vue.ref, defineProps: () => ({}),
        onMounted: callback => { mounted = callback; }, onUnmounted: callback => { unmounted = callback; },
        document: { addEventListener: (key, callback) => listeners.set(key, callback), removeEventListener: key => listeners.delete(key) },
    }, 'root, trigger, close');
    api.root.value = { open: true, contains: () => false };
    api.trigger.value = { focus: () => { focused = true; } };

    mounted();
    api.close(true);
    assert.equal(api.root.value.open, false);
    assert.equal(focused, true);
    api.root.value.open = true;
    listeners.get('click')({ target: {} });
    assert.equal(api.root.value.open, false);
    unmounted();
    assert.equal(listeners.size, 0);
});

test('child profile renders four primary sections and hides restricted health content', async () => {
    const props = {
        child: { public_id: 'synthetic-child', full_name: 'Synthetic fixture', file_no: 'TEST', status: 'active', status_label: 'نشط', verification: 'بانتظار التحقق', date_of_birth: null, age: null },
        household: { public_id: 'synthetic-family', reference_no: 'TEST-FAMILY' },
        guardianships: [], education: [], health: [{ id: 1, medications: 'RESTRICTED_HEALTH_FIXTURE' }], score: null, scoreHistory: [], socialRuleset: false,
        documents: [], changes: [], rules: {}, options: {}, changeCategories: {}, sponsorship: null, can: { sensitive: false, records: false },
    };
    const { api, source } = setupComponent('Pages/Orphans/Child.vue', {
        computed: Vue.computed, ref: Vue.ref, defineProps: () => props,
        usePage: () => ({ url: '/orphans/children/synthetic-child' }),
        useForm: values => ({ ...values, data: () => values, errors: {} }),
        recordResultsUrl, recordWithResults,
    }, 'props, tab, tabs, resultsUrl, base, educationForm, educationModel, healthForm, healthModel, overrideForm, changeForm, changeModel, changeFields, factorLabel, tone');
    assert.equal(api.tabs.length, 4);
    const template = source.split('<template>')[1].slice(0, source.split('<template>')[1].lastIndexOf('</template>'));
    const compiled = compileTemplate({ source: template, filename: 'Child.vue', id: 'fixture' }).code
        .replace(/import \{([^}]+)\} from "vue"/g, (match, imports) => `const {${imports.replace(/\bas\b/g, ':')}} = Vue;`)
        .replace('export function render', 'function render');
    const renderContext = { Vue };
    vm.createContext(renderContext);
    vm.runInContext(`${compiled}\nglobalThis.render = render;`, renderContext);
    const stub = { template: '<div><slot /><slot name="meta" /></div>' };
    const render = async section => {
        api.tab.value = section;
        const app = Vue.createSSRApp({ setup: () => ({ ...props, ...api, recordWithResults }), render: renderContext.render });
        for (const name of ['AppLayout', 'RecordContext', 'PageHeader', 'StatusBadge', 'Link', 'DocumentsChecklist', 'FormErrorSummary', 'RuleFields']) app.component(name, stub);
        return renderToString(app);
    };

    const social = await render('social');
    assert.equal(social.includes('RESTRICTED_HEALTH_FIXTURE'), false);
    assert.ok(social.includes('التعليم'));
    assert.ok(social.includes('التقييم الاجتماعي'));
    const records = await render('records');
    assert.ok(records.includes('المستندات'));
    assert.ok(records.includes('طلبات التعديل'));
});
