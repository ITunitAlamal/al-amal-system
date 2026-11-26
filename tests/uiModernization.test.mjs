import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { computed, reactive, ref, nextTick } from 'vue';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { createLatestRequest } from '../resources/js/Support/latestRequest.js';
import { reportExportUrl, reportFiltersChanged } from '../resources/js/Support/reportContext.js';

function evaluate(path, context, exports, module = false) {
    let source = readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
    if (!module) source = source.split('<script setup>')[1].split('</script>')[0];
    source = source.replace(/^import .*;\r?\n/gm, '').replaceAll('export function ', 'function ');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.api = { ${exports} };`, context);
    return context.api;
}

test('all current Vue pages and shared components compile with their real binding metadata', () => {
    function walk(directory) {
        for (const entry of readdirSync(directory, { withFileTypes: true })) {
            const path = `${directory}/${entry.name}`;
            if (entry.isDirectory()) { walk(path); continue; }
            if (!path.endsWith('.vue')) continue;
            const { descriptor, errors } = parse(readFileSync(path, 'utf8'), { filename: path });
            assert.deepEqual(errors, [], path);
            const script = descriptor.script || descriptor.scriptSetup ? compileScript(descriptor, { id: path }) : null;
            const template = compileTemplate({ source: descriptor.template.content, filename: path, id: path, compilerOptions: { bindingMetadata: script?.bindings } });
            assert.deepEqual(template.errors, [], path);
        }
    }
    walk(new URL('../resources/js', import.meta.url).pathname.replace(/^\/([A-Z]:)/, '$1'));
});

test('preview aborts previous loads and never publishes stale personal data', async () => {
    const pending = [];
    let visible;
    const request = createLatestRequest((url, signal) => new Promise(resolve => pending.push({ url, signal, resolve })), state => { visible = state; });
    const first = request.run('/first');
    const second = request.run('/second');
    assert.equal(pending[0].signal.aborted, true);
    pending[1].resolve({ name: 'Current record' });
    await second;
    pending[0].resolve({ name: 'Previous record' });
    await first;
    assert.equal(visible.data.name, 'Current record');
    await request.run(null);
    assert.equal(visible.data, null);
    assert.equal(visible.loading, false);
});

test('preview failures remain honest, retry succeeds, and unmounted previews ignore responses', async () => {
    let fail = true;
    let state;
    const loader = createLatestRequest(async () => { if (fail) throw new Error('غير متاح'); return { id: 2 }; }, value => { state = value; });
    await loader.run('/record');
    assert.equal(state.error, 'غير متاح');
    assert.equal(state.data, null);
    fail = false;
    await loader.run('/record');
    assert.equal(state.error, '');
    assert.equal(state.data.id, 2);
    let resolve;
    const abandoned = createLatestRequest(() => new Promise(r => { resolve = r; }), value => { state = value; });
    const pending = abandoned.run('/record');
    abandoned.cancel();
    resolve({ id: 'must not appear' });
    await pending;
    assert.equal(state.data, null);
});

test('report exports use applied filters, preserve IDs and dates, and encode purpose without mutating context', () => {
    const applied = { governorate: '003', from: '2026-10-01', to: '2026-10-31', sex: '', unwanted: 'discard' };
    const draft = { ...applied, governorate: '005' };
    assert.equal(reportFiltersChanged(draft, applied), true);
    assert.equal(reportFiltersChanged({ from: '2026-10-01', governorate: '003', to: '2026-10-31' }, applied), false);
    const url = new URL(reportExportUrl(applied, 'registry', 'xlsx', 'مراجعة & متابعة'), 'https://example.test');
    assert.equal(url.searchParams.get('governorate'), '003');
    assert.equal(url.searchParams.get('purpose'), 'مراجعة & متابعة');
    assert.equal(url.searchParams.has('unwanted'), false);
    assert.equal(url.searchParams.has('sex'), false);
    assert.equal(applied.governorate, '003');
});

test('decision cancellation sends nothing; duplicate submissions are blocked and errors allow retry', () => {
    const calls = [];
    const { useDecision } = evaluate('Support/useDecision.js', { ref, router: { post: (...args) => calls.push(args) } }, 'useDecision', true);
    const flow = useDecision();
    const options = { title: 'قرار', required: true, url: '/unchanged/action', data: { action: 'approve', content_hash: 'version-hash' } };
    flow.ask(options);
    flow.close();
    flow.confirm('سبب');
    assert.equal(calls.length, 0);
    flow.ask(options);
    flow.confirm(' ');
    assert.equal(calls.length, 0);
    assert.match(flow.error.value, /سبب/);
    flow.confirm('قرار موثق');
    flow.confirm('ضغط مكرر');
    flow.close();
    assert.equal(calls.length, 1);
    assert.equal(flow.decision.value.url, '/unchanged/action');
    assert.equal(calls[0][1].content_hash, 'version-hash');
    calls[0][2].onError({ note: 'راجع البيانات' });
    calls[0][2].onFinish();
    assert.equal(flow.error.value, 'راجع البيانات');
    assert.equal(flow.busy.value, false);
    flow.confirm('تصحيح');
    calls[1][2].onSuccess();
    calls[1][2].onFinish();
    assert.equal(flow.decision.value, null);
});

test('dialog uses modal browser behavior, blocks closing during a save and restores focus and scroll', async () => {
    const props = { open: false, busy: false };
    let changed, cleanup, closes = 0, focused = 0;
    const body = { style: { overflow: 'auto' } };
    const context = { ref, nextTick, useId: () => 'dialog-title', defineProps: () => props, defineEmits: () => () => { closes++; },
        watch: (_get, callback) => { changed = callback; }, onUnmounted: callback => { cleanup = callback; },
        document: { body, activeElement: { isConnected: true, focus: () => { focused++; } } } };
    const api = evaluate('Components/AppDialog.vue', context, 'dialog, close, closed');
    api.dialog.value = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    props.open = true;
    await changed(true);
    assert.equal(api.dialog.value.open, true);
    assert.equal(body.style.overflow, 'hidden');
    props.busy = true;
    api.close();
    assert.equal(closes, 0);
    props.busy = false;
    api.close();
    assert.equal(closes, 1);
    props.open = false;
    await changed(false);
    assert.equal(body.style.overflow, 'auto');
    assert.equal(focused, 1);
    cleanup();
});

test('wizard validates visible controls, retains form data and opens the server error step', async () => {
    let protectedDirty, invalidReported = false, focused = false;
    const { useFormWizard } = evaluate('Support/useFormWizard.js', { ref, nextTick, useUnsavedChanges: get => { protectedDirty = get; } }, 'useFormWizard', true);
    const form = { isDirty: true, processing: false, wasSuccessful: false, child: { name: 'Retained' } };
    const wizard = useFormWizard(form, field => field.startsWith('children') ? 1 : 0);
    const invalid = { getClientRects: () => [1], checkValidity: () => false, reportValidity: () => { invalidReported = true; } };
    wizard.scope.value = { querySelectorAll: () => [invalid], querySelector: () => null };
    await wizard.move(1);
    assert.equal(wizard.step.value, 0);
    assert.equal(invalidReported, true);
    wizard.scope.value.querySelectorAll = () => [];
    await wizard.move(3);
    assert.equal(wizard.step.value, 3);
    assert.equal(form.child.name, 'Retained');
    const details = { tagName: 'DETAILS', open: false, parentElement: null };
    wizard.scope.value.querySelectorAll = () => [{ dataset: { errorField: 'children.0.first_name' }, parentElement: details, focus: () => { focused = true; } }];
    await wizard.onError({ 'children.0.first_name': 'الاسم مطلوب' });
    assert.equal(wizard.step.value, 1);
    assert.equal(details.open, true);
    assert.equal(focused, true);
    assert.equal(protectedDirty(), true);
    form.processing = true;
    assert.equal(protectedDirty(), false);
});

test('campaign approval dialog remains unavailable to non-executives for initial and changed rosters', () => {
    for (const executive of [false, true]) {
        for (const change of [null, { reason: 'Correction' }]) {
            let asked;
            const props = { campaign: { public_id: 'campaign' }, current: { status: 'approved' }, approval: { change, list: { public_id: 'roster', version: 2, content_hash: 'immutable-hash' } }, delivery: {}, abilities: { executive } };
            const api = evaluate('Pages/Orphans/Campaigns/Show.vue', { defineProps: () => props, ref, computed, useForm: data => data,
                useDecision: () => ({ ask: options => { asked = options; } }) }, 'decide, isDraft');
            api.decide('approve');
            assert.equal(Boolean(asked), executive);
            assert.equal(api.isDraft.value, false);
            if (executive) {
                assert.equal(asked.url, '/orphans/campaigns/campaign/lists/roster');
                assert.equal(asked.data.content_hash, 'immutable-hash');
            }
        }
    }
});

test('import progress follows server status, polls without overlap and stops when complete', () => {
    const props = reactive({ batch: { status: 'uploaded', mapping: {} }, suggested: {} });
    let statusChanged, interval, unmount, options;
    let requests = 0;
    const document = { hidden: false };
    const api = evaluate('Pages/Orphans/Import/Batch.vue', { defineProps: () => props, ref, computed, useForm: data => data,
        watch: (_source, callback) => { statusChanged = callback; callback(props.batch.status); },
        onUnmounted: callback => { unmount = callback; }, document,
        setInterval: callback => { interval = callback; return 1; }, clearInterval: () => { interval = null; },
        router: { reload: value => { requests++; options = value; } },
    }, 'steps, refresh, refreshError');
    assert.equal(interval, null);
    props.batch.status = 'executing';
    statusChanged('executing');
    assert.equal(api.steps.value[5].state, 'current');
    interval(); interval();
    assert.equal(requests, 1);
    options.onError(); options.onFinish();
    assert.equal(api.refreshError.value, true);
    document.hidden = true;
    interval();
    assert.equal(requests, 1);
    document.hidden = false;
    interval(); options.onSuccess(); options.onFinish();
    assert.equal(requests, 2);
    assert.equal(api.refreshError.value, false);
    props.batch.status = 'completed';
    statusChanged('completed');
    assert.equal(interval, null);
    assert.equal(api.steps.value.every(step => step.state === 'done'), true);
    unmount();
});
