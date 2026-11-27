<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { arabicLatinDigitsLocale, formatDate } from '../../Support/dates.js';

const props = defineProps({
    period: { type: String, required: true },
    periods: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    budgets: { type: Array, default: () => [] },
});
const period = ref(props.period);
watch(() => props.period, value => { period.value = value; });
const editing = ref(null);
const showingForm = ref(false);
const formSection = ref(null);
const saved = ref('');
const form = useForm({
    department_id: '', period: props.period, currency: props.currencies[0]?.code || '',
    allocated_amount: '', as_of: '', reference: '', notes: '', revision: null,
});
const errors = computed(() => [...new Set(Object.values(form.errors).flat())]);
const stale = computed(() => !!form.errors.revision);
const money = value => new Intl.NumberFormat(arabicLatinDigitsLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
const date = value => formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' }, '—');
const month = value => value ? formatDate(`${value}-01T12:00:00`, { month: 'long', year: 'numeric' }, '—') : '—';

function changePeriod() {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(period.value) || period.value < '2020-01' || period.value > '2100-12') return;
    router.get('/finance/department-budgets', { period: period.value }, { preserveScroll: true });
}
async function openForm(budget = null) {
    editing.value = budget;
    saved.value = '';
    form.clearErrors();
    Object.assign(form, budget ? {
        department_id: budget.departmentId, period: budget.period, currency: budget.currency,
        allocated_amount: budget.allocated, as_of: budget.allocationAsOf || budget.asOf,
        reference: budget.reference || '', notes: budget.notes || '', revision: budget.revision,
    } : {
        department_id: '', period: props.period, currency: props.currencies[0]?.code || '',
        allocated_amount: '', as_of: '', reference: '', notes: '', revision: null,
    });
    showingForm.value = true;
    await nextTick();
    formSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    formSection.value?.focus({ preventScroll: true });
}
function closeForm() {
    if (form.processing) return;
    showingForm.value = false;
    editing.value = null;
    form.clearErrors();
}
function submit() {
    if (form.processing) return;
    saved.value = '';
    form.transform(data => {
        const values = { allocated_amount: data.allocated_amount, as_of: data.as_of, reference: data.reference, notes: data.notes || null };
        return editing.value ? { ...values, revision: data.revision } : { ...values, department_id: data.department_id, period: data.period, currency: data.currency };
    });
    const options = {
        preserveScroll: true,
        onSuccess: () => { showingForm.value = false; editing.value = null; saved.value = 'حُفظت الميزانية، وأصبح ملخصها متاحًا لرئيس القسم.'; },
        onHttpException: response => {
            if (response.status === 403) {
                form.setError('access', 'لم تعد صلاحية إدارة الميزانيات متاحة لهذا الحساب. راجع رئيس الحسابات.');
                return false;
            }
        },
        onNetworkError: () => { form.setError('connection', 'تعذر الاتصال بالخادم. تحقق من الاتصال ثم حاول مجددًا.'); return false; },
    };
    if (editing.value) form.patch(`/finance/department-budgets/${editing.value.id}`, options);
    else form.post('/finance/department-budgets', options);
}
function loadLatest() {
    if (form.processing || !editing.value) return;
    const id = editing.value.id;
    router.reload({ only: ['budgets'], preserveScroll: true, onSuccess: page => {
        const budget = page.props.budgets?.find(item => item.id === id);
        if (budget) openForm(budget);
    } });
}
</script>

<template>
    <AppLayout title="ميزانيات الأقسام">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="رئيس قسم الحسابات" title="ميزانيات الأقسام الشهرية" description="أدخل المخصص الشهري لكل قسم. يُحسب مصروف الشهر والمتبقي تلقائيًا من عمليات الصرف المسجلة والمنفذة في النظام، وتُعرض كل عملة بصورة مستقلة.">
                <button type="button" class="btn btn-primary" :disabled="form.processing" @click="openForm()">إدخال ميزانية</button>
                <Link href="/finance" class="btn btn-secondary">العودة إلى الحسابات</Link>
            </PageHeader>
            <p v-if="saved" class="alert-success" role="status">{{ saved }}</p>

            <section v-if="showingForm" ref="formSection" tabindex="-1" class="card scroll-mt-24 space-y-4 focus:outline-none" aria-labelledby="department-budget-form-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="department-budget-form-title" class="card-title">{{ editing ? 'تحديث ميزانية القسم' : 'إدخال ميزانية قسم' }}</h2>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="form.processing" @click="closeForm">إغلاق</button>
                </div>
                <div v-if="errors.length" class="alert-error space-y-2" role="alert" aria-live="assertive">
                    <ul class="list-inside list-disc"><li v-for="error in errors" :key="error">{{ error }}</li></ul>
                    <button v-if="stale" type="button" class="btn btn-secondary btn-sm" @click="loadLatest">تحميل آخر نسخة</button>
                </div>
                <form class="space-y-4" @submit.prevent="submit">
                    <fieldset :disabled="form.processing" class="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="block text-sm font-semibold text-slate-700">القسم
                            <select v-model="form.department_id" class="field mt-1 w-full" required :disabled="!!editing" :aria-invalid="!!form.errors.department_id"><option value="">اختر القسم</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">شهر الميزانية
                            <input v-model="form.period" type="month" class="field mt-1 w-full" min="2020-01" max="2100-12" required :disabled="!!editing" :aria-invalid="!!form.errors.period" />
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">العملة
                            <select v-model="form.currency" class="field mt-1 w-full" required :disabled="!!editing" :aria-invalid="!!form.errors.currency"><option value="">اختر العملة</option><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} — {{ currency.name_ar }}</option></select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">المخصص الشهري
                            <input v-model="form.allocated_amount" type="text" inputmode="decimal" dir="ltr" class="field mt-1 w-full text-right" required :aria-invalid="!!form.errors.allocated_amount" placeholder="0.00" />
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">تاريخ المخصص / الميزانية
                            <input v-model="form.as_of" type="date" class="field mt-1 w-full" required :aria-invalid="!!form.errors.as_of" />
                        </label>
                        <label class="block text-sm font-semibold text-slate-700 sm:col-span-2 lg:col-span-3">مرجع اعتماد المخصص
                            <input v-model="form.reference" class="field mt-1 w-full" required maxlength="120" :aria-invalid="!!form.errors.reference" />
                        </label>
                        <label class="block text-sm font-semibold text-slate-700 sm:col-span-2 lg:col-span-3">ملاحظات <span class="font-normal text-slate-500">(اختياري)</span>
                            <textarea v-model="form.notes" class="field mt-1 w-full" rows="3" maxlength="2000" :aria-invalid="!!form.errors.notes" />
                        </label>
                    </fieldset>
                    <p class="rounded-lg bg-slate-50 p-3 text-xs leading-6 text-slate-600">يُحتسب مصروف الشهر من الصرف المنفذ وسندات صرف فواتير الموردين المسجلة خلال الشهر نفسه، بعد احتساب العكس ومنع التكرار. طلبات الشراء المعلقة لا تُخصم من الميزانية.</p>
                    <p v-if="editing" class="text-xs leading-6 text-slate-500">لتسجيل ميزانية قسم أو شهر أو عملة أخرى، استخدم «إدخال ميزانية».</p>
                    <div class="flex flex-wrap gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing" :aria-busy="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ الميزانية' }}</button><button type="button" class="btn btn-secondary" :disabled="form.processing" @click="closeForm">إلغاء</button></div>
                </form>
            </section>

            <section class="card min-w-0 space-y-4" aria-labelledby="department-budgets-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="department-budgets-title" class="card-title">ميزانيات {{ month(props.period) }}</h2>
                    <label class="flex flex-wrap items-center gap-2 text-sm text-slate-600">الشهر
                        <input v-model="period" type="month" min="2020-01" max="2100-12" list="department-budget-periods" class="field" @change="changePeriod" />
                        <datalist id="department-budget-periods"><option v-for="item in periods" :key="item" :value="item">{{ month(item) }}</option></datalist>
                    </label>
                </div>
                <div v-if="budgets.length" class="overflow-x-auto">
                    <table class="data-table w-full text-sm">
                        <thead><tr><th scope="col">القسم</th><th scope="col">العملة</th><th scope="col">المخصص</th><th scope="col">المصروف تلقائيًا</th><th scope="col">المتبقي المتاح</th><th scope="col">حتى تاريخ</th><th scope="col">مرجع المخصص</th><th scope="col"><span class="sr-only">الإجراء</span></th></tr></thead>
                        <tbody><tr v-for="budget in budgets" :key="budget.id">
                            <td class="font-semibold text-navy-800">{{ budget.department }}</td><td><bdi>{{ budget.currency }}</bdi></td><td><bdi class="whitespace-nowrap">{{ money(budget.allocated) }}</bdi></td><td><bdi class="whitespace-nowrap">{{ money(budget.spent) }}</bdi></td>
                            <td :class="Number(budget.remaining) < 0 ? 'text-red-700' : 'font-semibold text-emerald-700'"><bdi class="whitespace-nowrap">{{ money(budget.remaining) }}</bdi><StatusBadge v-if="Number(budget.remaining) < 0" class="mt-1" label="تجاوز الميزانية" tone="danger" /></td>
                            <td class="whitespace-nowrap">{{ date(budget.asOf) }}</td><td class="max-w-xs break-words">{{ budget.reference }}</td><td><button type="button" class="btn btn-secondary btn-sm" :disabled="form.processing" :aria-label="`تحديث ميزانية ${budget.department} ${budget.currency}`" @click="openForm(budget)">تحديث</button></td>
                        </tr></tbody>
                    </table>
                </div>
                <EmptyState v-else title="لم تُسجل ميزانيات لهذا الشهر" description="أدخل ميزانية القسم ليتمكن رئيسه من متابعة المخصص والمصروف والمتبقي خلال الشهر." />
            </section>
        </div>
    </AppLayout>
</template>
