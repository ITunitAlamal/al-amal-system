<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

/**
 * Pay and bank records (effective-dated). Imported figures are reference data, not a payroll approval; a change is
 * a request the executive director approves before it takes effect.
 */
const props = defineProps({
    employeeId: { type: [Number, String], required: true },
    pay: { type: Object, default: null },
    bank: { type: Object, default: null },
    payChange: { type: Object, default: null },
    canRequest: { type: Boolean, default: false },
    choices: { type: Object, default: null },
});

const money = (value, currency) => (value === null || value === undefined ? '—' : `${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency || ''}`);
const rate = value => (value === null || value === undefined ? '—' : `${Number(value).toLocaleString('en-US', { maximumFractionDigits: 4 })}%`);
const statusLabel = { baseline: ['مرجعي من ملف الموظفين (غير معتمد للصرف)', 'warning'], approved: ['معتمد بقرار', 'success'] };
const current = computed(() => props.pay?.current || null);
const showForm = ref(false);
const form = useForm({
    effective_from: '', changes_pay: true, changes_bank: false, currency_code: current.value?.currency_code || '',
    base_salary: current.value?.base_salary ?? '', spouse_allowance: current.value?.spouse_allowance ?? '', children_allowance: current.value?.children_allowance ?? '',
    cost_of_living_rate: current.value?.cost_of_living_rate ?? '', nature_of_work_rate: current.value?.nature_of_work_rate ?? '',
    bank_value_id: '', account_number: '', reason: '', reference: '',
});
function submit() {
    form.transform(data => ({ ...data, ...Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])) }))
        .post(`/admin/employee-profiles/${props.employeeId}/pay-changes`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <p v-if="payChange" class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm">
            طلب تعديل مفتوح: {{ payChange.label }}. <Link :href="`/admin/employee-pay-changes/${payChange.id}`" class="font-semibold text-blue-700 underline">فتح الطلب</Link>
        </p>

        <section v-if="pay">
            <h3 class="mb-2 font-bold text-navy-800">الراتب والعلاوات</h3>
            <div v-if="current" class="rounded-xl border border-slate-200 p-3">
                <StatusBadge :label="(statusLabel[current.status] || [current.status])[0]" :tone="(statusLabel[current.status] || [null, 'muted'])[1]" />
                <span class="ms-2 text-xs text-slate-500" dir="ltr">من {{ String(current.effective_from).slice(0, 10) }}</span>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">الراتب الأساسي</dt><dd class="font-semibold">{{ money(current.base_salary, current.currency_code) }}</dd></div>
                    <div><dt class="text-slate-500">علاوة الزوجة</dt><dd class="font-semibold">{{ money(current.spouse_allowance, current.currency_code) }}</dd></div>
                    <div><dt class="text-slate-500">علاوة الأبناء</dt><dd class="font-semibold">{{ money(current.children_allowance, current.currency_code) }}</dd></div>
                    <div><dt class="text-slate-500">نسبة غلاء المعيشة</dt><dd class="font-semibold">{{ rate(current.cost_of_living_rate) }}</dd></div>
                    <div><dt class="text-slate-500">نسبة طبيعة العمل</dt><dd class="font-semibold">{{ rate(current.nature_of_work_rate) }}</dd></div>
                    <div><dt class="text-slate-500">جملة الراتب كما وردت في المصدر</dt><dd class="font-semibold">{{ money(current.source_total_salary, current.currency_code) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-slate-500">القيم الفارغة غير معروفة في المصدر (ليست صفرًا). الجملة تُحفظ كما وردت ولا يعاد حسابها.</p>
            </div>
            <p v-else class="text-sm text-slate-500">لا يوجد سجل راتب.</p>
            <details v-if="pay.history?.length > 1" class="mt-2 text-sm">
                <summary class="cursor-pointer font-semibold text-blue-700">السجل السابق ({{ pay.history.length - 1 }})</summary>
                <table class="data-table mt-2"><thead><tr><th>من</th><th>إلى</th><th>الأساسي</th><th>الحالة</th></tr></thead>
                    <tbody><tr v-for="row in pay.history.slice(1)" :key="row.id"><td dir="ltr">{{ String(row.effective_from).slice(0, 10) }}</td><td dir="ltr">{{ row.effective_to ? String(row.effective_to).slice(0, 10) : '—' }}</td><td>{{ money(row.base_salary, row.currency_code) }}</td><td>{{ (statusLabel[row.status] || [row.status])[0] }}</td></tr></tbody>
                </table>
            </details>
        </section>

        <section v-if="bank">
            <h3 class="mb-2 font-bold text-navy-800">الحساب البنكي</h3>
            <ul v-if="bank.history?.length" class="space-y-1 text-sm">
                <li v-for="row in bank.history" :key="row.id" :class="row.effective_to ? 'text-slate-400' : ''">
                    <span class="font-semibold">{{ row.bank || 'بنك غير محدد' }}</span> — <span class="font-mono" dir="ltr">{{ row.account || '—' }}</span>
                    <span class="text-xs"> · من {{ String(row.effective_from).slice(0, 10) }}{{ row.effective_to ? ' إلى ' + String(row.effective_to).slice(0, 10) : '' }} · {{ (statusLabel[row.status] || [row.status])[0] }}</span>
                </li>
            </ul>
            <p v-else class="text-sm text-slate-500">لا يوجد حساب بنكي مسجل.</p>
            <p v-if="!bank.full" class="mt-1 text-xs text-slate-500">رقم الحساب مقنّع؛ عرضه كاملًا لمن يحمل صلاحية الاطلاع على الحسابات البنكية.</p>
        </section>

        <div v-if="canRequest">
            <button v-if="!showForm" type="button" class="btn btn-secondary btn-sm" @click="showForm = true">طلب تعديل الراتب أو الحساب البنكي</button>
            <form v-else class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="submit">
                <p class="text-xs text-slate-600">يُرفع الطلب إلى المدير التنفيذي (وتعديل المدير التنفيذي نفسه إلى رئيس قسم الحسابات) ولا يسري قبل اعتماده؛ السجل الحالي يُغلق قبل تاريخ السريان بيوم ولا يُمحى.</p>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="form.changes_pay" type="checkbox" /> تعديل الراتب والعلاوات</label>
                    <label class="flex items-center gap-2"><input v-model="form.changes_bank" type="checkbox" /> تعديل الحساب البنكي</label>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <label class="text-sm">تاريخ السريان *<input v-model="form.effective_from" type="date" required class="field mt-1" /></label>
                    <template v-if="form.changes_pay">
                        <label class="text-sm">العملة *<select v-model="form.currency_code" class="field mt-1"><option value="">اختر</option><option v-for="c in choices?.currencies || []" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="text-sm">الراتب الأساسي<input v-model="form.base_salary" type="number" min="0" step="0.01" dir="ltr" class="field mt-1" /></label>
                        <label class="text-sm">علاوة الزوجة<input v-model="form.spouse_allowance" type="number" min="0" step="0.01" dir="ltr" class="field mt-1" /></label>
                        <label class="text-sm">علاوة الأبناء<input v-model="form.children_allowance" type="number" min="0" step="0.01" dir="ltr" class="field mt-1" /></label>
                        <label class="text-sm">نسبة غلاء المعيشة (%)<input v-model="form.cost_of_living_rate" type="number" min="0" max="100" step="0.01" dir="ltr" class="field mt-1" /></label>
                        <label class="text-sm">نسبة طبيعة العمل (%)<input v-model="form.nature_of_work_rate" type="number" min="0" max="100" step="0.01" dir="ltr" class="field mt-1" /></label>
                    </template>
                    <template v-if="form.changes_bank">
                        <label class="text-sm">البنك<select v-model="form.bank_value_id" class="field mt-1"><option value="">اختر</option><option v-for="b in choices?.banks || []" :key="b.id" :value="b.id">{{ b.name_ar }}</option></select></label>
                        <label class="text-sm">رقم الحساب *<input v-model="form.account_number" maxlength="40" dir="ltr" autocomplete="off" class="field mt-1" /></label>
                    </template>
                    <label class="text-sm md:col-span-2">سبب التعديل *<textarea v-model="form.reason" rows="2" maxlength="2000" class="field mt-1"></textarea></label>
                    <label class="text-sm">المستند المرجعي (رقم القرار أو الكتاب) *<input v-model="form.reference" maxlength="255" class="field mt-1" /></label>
                </div>
                <p v-for="(message, key) in form.errors" :key="key" class="field-error">{{ message }}</p>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">{{ form.processing ? 'جارٍ الإرسال...' : 'إرسال للمدير التنفيذي' }}</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="showForm = false">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</template>
