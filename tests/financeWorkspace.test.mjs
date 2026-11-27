import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { computed, ref } from 'vue';

function workspace(permissions, documentary = false, canManageDepartmentBudgets = false) {
    const calls = [];
    const props = { allowed: ['finance.view', ...permissions], summary: {}, transactionKinds: [], transactionKind: null, canManageDepartmentBudgets };
    const context = vm.createContext({
        computed, ref, watch: () => {}, onMounted: () => {}, defineProps: () => props,
        usePage: () => ({ url: '/finance' }), URLSearchParams,
        router: { replace: () => {}, visit: () => {} },
        axios: {
            get: async url => { calls.push(['get', url]); return { data: url === '/finance/bootstrap' ? { allowed: props.allowed, mode: { documentary } } : { data: [] } }; },
            post: async (url, payload) => { calls.push(['post', url, JSON.parse(JSON.stringify(payload))]); return { data: { id: 1 } }; },
        },
    });
    const source = readFileSync(new URL('../resources/js/Pages/Finance/Index.vue', import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    vm.runInContext(source, context);
    const run = code => vm.runInContext(code, context);
    run(`metadata.value = { mode: { documentary: ${documentary} } }`);
    return { run, calls, sections: () => JSON.parse(run('JSON.stringify(menu.value.flatMap(group => group.items.map(item => item.id)))')) };
}

test('execute-only employees can find their own vouchers and multiple permissions add both sections', () => {
    const payer = workspace(['finance.payment.execute']);
    assert.deepEqual(payer.sections(), ['payments']);
    const collector = workspace(['finance.receipt.execute']);
    assert.deepEqual(collector.sections(), ['receipts']);
    const both = workspace(['finance.payment.execute', 'finance.receipt.execute']);
    assert.deepEqual(both.sections(), ['payments', 'receipts']);
});

test('procurement, stores and payroll workspaces do not inherit unrelated unit sections', () => {
    const purchasing = workspace(['finance.procurement.manage']);
    assert.ok(purchasing.sections().includes('purchases'));
    for (const section of ['stock', 'payments', 'receipts', 'payroll_runs']) assert.ok(!purchasing.sections().includes(section));
    assert.deepEqual(workspace(['finance.stock.issue']).sections(), ['pending', 'overview', 'stock']);
    assert.deepEqual(workspace(['finance.payroll.record']).sections(), ['payroll_runs']);
});

test('department budget management is exposed only by the server-confirmed accounts head appointment', () => {
    assert.ok(!workspace(['finance.budget.manage', 'finance.monitor']).sections().includes('department_budgets'));
    assert.ok(workspace([], true, true).sections().includes('department_budgets'));
});

test('receipt and payment lists send separate kind filters and forms force the current section kind', async () => {
    for (const [kind, section] of [['payment', 'payments'], ['receipt', 'receipts']]) {
        const page = workspace([`finance.${kind}.draft`]);
        await page.run(`openModule('${section}')`);
        assert.equal(page.calls[0][1], `/finance/records/transactions?kind=${kind}`);
        page.run(`startCreate(); form.value.kind = '${kind === 'payment' ? 'receipt' : 'payment'}'; form.value.amount = '25.00'`);
        await page.run('createRecord()');
        const post = page.calls.find(call => call[0] === 'post');
        assert.equal(post[1], '/finance/transactions');
        assert.equal(post[2].kind, kind);
    }
});

test('documentary vouchers need payment recording and invoice preparation alone has no cash action', async () => {
    const accountant = workspace(['finance.purchase.invoice'], true);
    assert.ok(!accountant.sections().includes('payment_vouchers'));
    accountant.run("active.value = 'invoices'");
    assert.equal(accountant.run("rowForms({id: 1, state: 'pending'}).length"), 0);
    const payer = workspace(['finance.payment.record'], true);
    assert.ok(payer.sections().includes('payment_vouchers'));
    assert.ok(!payer.sections().includes('receipts'));
    await payer.run("openModule('payment_vouchers')");
    assert.equal(payer.calls[0][1], '/finance/records/invoices');
    assert.equal(payer.run("rowForms({id: 1, state: 'pending'})[0][0]"), 'externalVoucher');
});
