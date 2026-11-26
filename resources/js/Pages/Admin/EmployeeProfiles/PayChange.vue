<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';

/**
 * A pay or bank change request: what changes, from when, against the current record; the decision is taken on the
 * central workflow. Amounts appear only to holders of the pay permission.
 */
const props = defineProps({
    change: { type: Object, required: true },
    current: { type: Object, default: () => ({}) },
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
    editable: { type: Boolean, default: false },
    canWithdraw: { type: Boolean, default: false },
    choices: { type: Object, default: null },
    canSeePay: { type: Boolean, default: false },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success || '');
const money = (value, currency) => (value === null || value === undefined || value === '' ? '—' : `${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency || ''}`);
const rate = value => (value === null || value === undefined || value === '' ? '—' : `${Number(value).toLocaleString('en-US', { maximumFractionDigits: 4 })}%`);
const tone = { in_review: 'info', returned: 'warning', approved: 'success', rejected: 'danger', withdrawn: 'muted' };
const rows = [['base_salary', 'الراتب الأساسي', 'money'], ['spouse_allowance', 'علاوة الزوجة', 'money'], ['children_allowance', 'علاوة الأبناء', 'money'],
    ['cost_of_living_rate', 'نسبة غلاء المعيشة', 'rate'], ['nature_of_work_rate', 'نسبة طبيعة العمل', 'rate']];

const actions = computed(() => props.workflow?.actions ?? []);
const selected = ref(null);
const chosen = computed(() => actions.value.find(action => action.id === selected.value) || null);
const actionForm = useForm({ transition_id: null, revision: props.workflow?.revision ?? 0, comment: '' });
function act() {
    actionForm.transition_id = selected.value;
    actionForm.revision = props.workflow.revision;
    actionForm.post(`/workflows/${props.workflow.publicId}/act`, { preserveScroll: true, onSuccess: () => { selected.value = null; actionForm.reset('comment'); } });
}

const revise = useForm({
    effective_from: props.change.effective_from ? String(props.change.effective_from).slice(0, 10) : '', changes_pay: props.change.changes_pay, changes_bank: props.change.changes_bank,
    currency_code: props.change.pay?.currency_code || '', base_salary: props.change.pay?.base_salary ?? '', spouse_allowance: props.change.pay?.spouse_allowance ?? '',
    children_allowance: props.change.pay?.children_allowance ?? '', cost_of_living_rate: props.change.pay?.cost_of_living_rate ?? '', nature_of_work_rate: props.change.pay?.nature_of_work_rate ?? '',
    bank_value_id: props.change.bank_value_id ?? '', account_number: '', reason: props.change.reason || '', reference: props.change.reference || '',
});
function saveRevision() {
    revise.transform(data => Object.fromEntries(Object.entries(data).map(([key, item]) => [key, item === '' ? null : item])))
        .put(`/admin/employee-pay-changes/${props.change.id}`, { preserveScroll: true });
}
const withdrawForm = useForm({ reason: '' });
function withdraw() {
    const reason = window.prompt('سبب سحب الطلب:');
    if (!reason || reason.trim().length < 5) return;
    withdrawForm.reason = reason;
    withdrawForm.post(`/admin/employee-pay-changes/${props.change.id}/withdraw`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="طلب تعديل راتب أو حساب بنكي">
        <div class="mx-auto max-w-5xl space-y-4">
            <Link :href="`/admin/employee-profiles/${change.employee.id}?tab=pay`" class="text-sm font-semibold text-blue-700 hover:underline">→ ملف {{ change.employee.name }}</Link>
            <PageHeader eyebrow="الموارد البشرية" :title="`تعديل ${[change.changes_pay ? 'الراتب' : '', change.changes_bank ? 'الحساب البنكي' : ''].filter(Boolean).join(' و')}`"
                :description="workflow ? `رقم المعاملة ${workflow.referenceNo}` : ''">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <StatusBadge :label="change.statusLabel" :tone="tone[change.status] || 'muted'" />
                        <span class="text-slate-500">{{ change.employee.name }} — <span class="font-mono" dir="ltr">{{ change.employee.number }}</span></span>
                    </div>
                </template>
            </PageHeader>
            <p v-if="flash" class="alert-success">{{ flash }}</p>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>

            <section class="card space-y-4">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">يسري من</dt><dd class="font-semibold" dir="ltr">{{ String(change.effective_from).slice(0, 10) }}</dd></div>
                    <div><dt class="text-slate-500">مقدم الطلب</dt><dd class="font-semibold">{{ change.requester }}</dd></div>
                    <div><dt class="text-slate-500">المستند المرجعي</dt><dd class="font-semibold">{{ change.reference }}</dd></div>
                    <div class="sm:col-span-3"><dt class="text-slate-500">سبب التعديل</dt><dd class="whitespace-pre-line">{{ change.reason }}</dd></div>
                    <div v-if="change.decider"><dt class="text-slate-500">صاحب القرار</dt><dd class="font-semibold">{{ change.decider }}</dd></div>
                </dl>
                <table v-if="change.changes_pay && canSeePay" class="data-table">
                    <thead><tr><th>البند</th><th>الحالي</th><th>المطلوب</th></tr></thead>
                    <tbody><tr v-for="[key, label, kind] in rows" :key="key">
                        <td>{{ label }}</td>
                        <td>{{ kind === 'money' ? money(current?.pay?.[key], current?.pay?.currency_code) : rate(current?.pay?.[key]) }}</td>
                        <td class="font-semibold">{{ kind === 'money' ? money(change.pay?.[key], change.pay?.currency_code) : rate(change.pay?.[key]) }}</td>
                    </tr></tbody>
                </table>
                <p v-else-if="change.changes_pay" class="text-sm text-slate-500">مبالغ الراتب لا تظهر إلا لمن يحمل صلاحية الاطلاع على الرواتب.</p>
                <dl v-if="change.changes_bank" class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">الحساب الحالي</dt><dd dir="ltr" class="font-mono">{{ current?.bank ? `${current.bank.bank || ''} ${current.bank.account || ''}` : '—' }}</dd></div>
                    <div><dt class="text-slate-500">الحساب المطلوب</dt><dd dir="ltr" class="font-mono font-semibold">{{ change.bank ? `${change.bank.bank || ''} ${change.bank.account || ''}` : '—' }}</dd></div>
                </dl>
                <p class="text-xs text-slate-500">الاعتماد يغلق السجل الحالي قبل تاريخ السريان بيوم ويضيف السجل الجديد، ولا يُعد صرفًا ولا قيدًا محاسبيًا.</p>
            </section>

            <section v-if="actions.length" class="card space-y-3">
                <h2 class="card-title">الإجراء المطلوب منك</h2>
                <div class="flex flex-wrap gap-2">
                    <button v-for="a in actions" :key="a.id" type="button" class="btn btn-sm" :class="selected === a.id ? 'btn-primary' : 'btn-secondary'" @click="selected = a.id; actionForm.clearErrors()">{{ a.name }}</button>
                </div>
                <form v-if="chosen" class="space-y-2" @submit.prevent="act">
                    <textarea v-model="actionForm.comment" rows="3" maxlength="5000" class="field" :required="Boolean(chosen.requires_comment)" :placeholder="chosen.requires_comment ? 'التعليق إلزامي لهذا الإجراء' : 'تعليق (اختياري)'"></textarea>
                    <p v-for="(message, key) in actionForm.errors" :key="key" class="field-error">{{ message }}</p>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="actionForm.processing">تأكيد: {{ chosen.name }}</button>
                </form>
            </section>

            <section v-if="editable" class="card space-y-3">
                <h2 class="card-title">تصحيح الطلب بعد إعادته</h2>
                <div class="grid gap-3 md:grid-cols-3">
                    <label class="text-sm">تاريخ السريان<input v-model="revise.effective_from" type="date" class="field mt-1" /></label>
                    <template v-if="revise.changes_pay">
                        <label class="text-sm">العملة<select v-model="revise.currency_code" class="field mt-1"><option value="">اختر</option><option v-for="c in choices?.currencies || []" :key="c" :value="c">{{ c }}</option></select></label>
                        <label v-for="[key, label] in rows" :key="key" class="text-sm">{{ label }}<input v-model="revise[key]" type="number" min="0" step="0.01" dir="ltr" class="field mt-1" /></label>
                    </template>
                    <template v-if="revise.changes_bank">
                        <label class="text-sm">البنك<select v-model="revise.bank_value_id" class="field mt-1"><option value="">اختر</option><option v-for="b in choices?.banks || []" :key="b.id" :value="b.id">{{ b.name_ar }}</option></select></label>
                        <label class="text-sm">رقم الحساب (أعد إدخاله)<input v-model="revise.account_number" maxlength="40" dir="ltr" autocomplete="off" class="field mt-1" /></label>
                    </template>
                    <label class="text-sm md:col-span-2">السبب<textarea v-model="revise.reason" rows="2" maxlength="2000" class="field mt-1"></textarea></label>
                    <label class="text-sm">المستند المرجعي<input v-model="revise.reference" maxlength="255" class="field mt-1" /></label>
                </div>
                <p v-for="(message, key) in revise.errors" :key="key" class="field-error">{{ message }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary btn-sm" :disabled="revise.processing" @click="saveRevision">حفظ التصحيح</button>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="withdrawForm.processing" @click="withdraw">سحب الطلب</button>
                </div>
                <p class="text-xs text-slate-500">بعد الحفظ استخدم إجراء «رفع الطلب إلى المدير التنفيذي» أعلاه.</p>
            </section>
            <button v-else-if="canWithdraw" type="button" class="btn btn-secondary btn-sm" :disabled="withdrawForm.processing" @click="withdraw">سحب الطلب</button>
        </div>
    </AppLayout>
</template>
