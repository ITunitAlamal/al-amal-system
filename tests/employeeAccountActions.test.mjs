import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { computed, ref } from 'vue';

function accountActions() {
    const requests = [];
    const context = {
        computed, ref, watch: () => {},
        defineProps: () => ({ filters: {}, employees: { data: [] }, departments: [], roles: [] }),
        useForm: defaults => {
            let transform = data => data;
            const form = {
                ...defaults, errors: {}, processing: false,
                reset() { Object.assign(this, defaults); },
                clearErrors() { this.errors = {}; },
                transform(callback) { transform = callback; return this; },
            };
            for (const method of ['put', 'delete']) {
                form[method] = (url, options) => requests.push({ method, url, data: transform(form), options });
            }
            return form;
        },
    };
    const script = readFileSync(new URL('../resources/js/Pages/Admin/Employees/Index.vue', import.meta.url), 'utf8')
        .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?\n/gm, '');
    vm.createContext(context);
    vm.runInContext(`${script}\nglobalThis.api = { accountTarget, accountAction, accountForm, showPassword, openAccountAction, closeAccountAction, submitAccountAction };`, context);
    return { ...context.api, requests };
}

test('temporary password is cleared and hidden when cancelling or opening another user', () => {
    const app = accountActions();
    app.openAccountAction({ id: 2 }, 'password');
    app.accountForm.password = 'Temporary-secret-123!';
    app.showPassword.value = true;
    app.closeAccountAction();
    assert.equal(app.accountForm.password, '');
    assert.equal(app.showPassword.value, false);
    assert.equal(app.accountTarget.value, null);
    assert.equal(app.requests.length, 0);
    app.openAccountAction({ id: 3 }, 'password');
    assert.equal(app.accountForm.password, '');
});

test('password changes submit only the new credentials and clear them after success', () => {
    const app = accountActions();
    app.openAccountAction({ id: 2 }, 'password');
    app.accountForm.password = app.accountForm.password_confirmation = 'Temporary-secret-123!';
    app.showPassword.value = true;
    app.submitAccountAction();
    assert.equal(app.requests[0].method, 'put');
    assert.equal(app.requests[0].url, '/admin/employees/2/password');
    assert.deepEqual(Object.keys(app.requests[0].data), ['password', 'password_confirmation']);
    app.accountForm.processing = true;
    app.submitAccountAction();
    assert.equal(app.requests.length, 1);
    app.requests[0].options.onSuccess();
    assert.equal(app.accountForm.password, '');
    assert.equal(app.accountForm.password_confirmation, '');
    assert.equal(app.accountTarget.value, null);
    assert.equal(app.showPassword.value, false);
});

test('deletion uses the selected account and sends confirmation without password data', () => {
    const app = accountActions();
    app.openAccountAction({ id: 3 }, 'delete');
    app.accountForm.confirm = true;
    app.submitAccountAction();
    assert.equal(app.requests[0].method, 'delete');
    assert.equal(app.requests[0].url, '/admin/employees/3');
    assert.deepEqual(Object.keys(app.requests[0].data), ['confirm']);
    assert.equal(app.requests[0].data.confirm, true);
});
