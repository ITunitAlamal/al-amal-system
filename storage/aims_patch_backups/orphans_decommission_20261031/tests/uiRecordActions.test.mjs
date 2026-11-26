import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { computed, ref } from 'vue';
import { correspondenceConsequences } from '../resources/js/Support/actionConfirmation.js';
import { createUuid } from '../resources/js/Support/uuid.js';

function setupComponent(path, context, exposed) {
    const source = readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    vm.createContext(context);
    vm.runInContext(`${source}\nglobalThis.api = { ${exposed} };`, context);
    return context.api;
}

test('copy reports success only after writing and offers selected manual text on failure', async () => {
    let written;
    let focused = false;
    let selected = false;
    let cleanup;
    const timers = new Set();
    const context = {
        ref, nextTick: async () => {},
        defineProps: () => ({ value: 'EMP-007', label: 'Employee' }),
        navigator: { clipboard: { writeText: async value => { written = value; } } },
        onUnmounted: callback => { cleanup = callback; },
        setTimeout: callback => { timers.add(callback); return callback; },
        clearTimeout: callback => timers.delete(callback),
    };
    const api = setupComponent('Components/CopyReference.vue', context, 'copy, message, manualValue, manualInput, copying');
    await api.copy();
    assert.equal(written, 'EMP-007');
    assert.ok(api.message.value);
    assert.equal(api.manualValue.value, '');
    context.navigator.clipboard.writeText = async () => { throw new Error('denied'); };
    api.manualInput.value = { focus: () => { focused = true; }, select: () => { selected = true; } };
    await api.copy();
    assert.equal(api.manualValue.value, 'EMP-007');
    assert.equal(focused && selected, true);
    assert.equal(api.copying.value, false);
    assert.equal(timers.size, 0);
    cleanup();
});

test('correspondence cancellation sends no request and confirmation preserves the existing action endpoint', () => {
    let accepted = false;
    let prompts = [];
    let requests = [];
    const context = {
        computed, ref, watch: () => {}, createUuid,
        defineProps: () => ({ letter: { public_id: 'record-1', reference: 'REF-24', subject: 'Subject', revision: 2 }, allowed: ['withdraw'], history: [], workflowActions: [] }),
        useForm: data => ({ ...data, processing: false, post: url => requests.push(url) }),
        correspondenceConsequences,
        confirmRecordAction: (...args) => { prompts.push(args); return accepted; },
    };
    const api = setupComponent('Pages/Correspondence/Show.vue', context, 'send, form');
    api.send('withdraw');
    assert.equal(requests.length, 0);
    assert.ok(prompts[0][1].includes('REF-24'));
    assert.ok(prompts[0][1].includes('Subject'));
    accepted = true;
    api.send('withdraw');
    assert.deepEqual(requests, ['/correspondence/record-1/actions']);
    api.form.processing = true;
    api.send('withdraw');
    assert.equal(requests.length, 1);
    assert.equal(prompts.length, 2);
});

test('guardian decisions are not sent or changed when confirmation is cancelled', () => {
    let accepted = false;
    let requests = 0;
    const context = {
        defineProps: () => ({ selected: { guardian_name: 'Guardian', verifiedChildren: 3 } }),
        useForm: data => ({ ...data, processing: false, post: () => requests++ }),
        guardianStates: [],
        confirmRecordAction: () => accepted,
    };
    const api = setupComponent('Pages/Admin/Orphans/Guardians.vue', context, 'decide, form');
    api.decide('guardian-1', 'reject');
    assert.equal(requests, 0);
    assert.equal(api.form.decision, 'approve');
    accepted = true;
    api.decide('guardian-1', 'reject');
    assert.equal(requests, 1);
    assert.equal(api.form.decision, 'reject');
    api.form.processing = true;
    api.decide('guardian-1', 'approve');
    assert.equal(requests, 1);
});
