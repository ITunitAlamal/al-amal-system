<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ dues: Array, totals: Array, receipts: Array, paymentMethods: Array, externalSystem: String, filters: Object, filterLabels: Object });

const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random()}`.replace(/[^0-9a-f-]/g, '0'));
const filter = ref({ ...props.filters });
const apply = () => router.get('/finance/rentals', filter.value, { preserveState: true, preserveScroll: true, replace: true });

const openDues = computed(() => props.dues.filter(d => Number(d.remaining) > 0));
const form = useForm({ request_key: newKey(), reference: '', posted_on: '', payment_method_value_id: null, note: '', allocations: [{ due_id: '', amount: '' }] });
const dueOf = id => props.dues.find(d => Number(d.id) === Number(id));
function pick(row) {
    const due = dueOf(row.due_id);
    if (due && !row.amount) row.amount = Number(due.remaining).toFixed(2);
}
const currencies = computed(() => [...new Set(form.allocations.map(a => dueOf(a.due_id)?.currency_code).filter(Boolean))]);
function record() {
    form.post('/finance/rentals/receipts', { preserveScroll: true, onSuccess: () => { form.reset(); form.request_key = newKey(); } });
}
function reverse(receipt) {
    const reason = prompt('سبب عكس القبض (مثل: عُكس السند في البرنامج المحاسبي):');
    if (!reason) return;
    const reference = prompt('رقم سند العكس في البرنامج المحاسبي (اختياري):') || null;
    router.post(`/finance/rentals/receipts/${receipt.id}/reverse`, { reason, reversal_reference: reference }, { preserveScroll: true });
}
</script>

<template>
<AppLayout title="قبض الإيجارات">
<div dir="rtl" class="mx-auto max-w-7xl space-y-5">
    <PageHeader eyebrow="قسم الحسابات" title="قبض الإيجارات وتسوياتها" :description="`القبض يتم في ${externalSystem}؛ هنا يُسجَّل رقم السند وتاريخه ومبلغه على الاستحقاق فقط، دون قيود محاسبية. التسويات تصلك مهامَّ في «مهامي».`">
        <Link href="/finance" class="btn btn-secondary">قسم الحسابات</Link>
        <Link href="/tasks" class="btn btn-secondary">مهامي (التسويات)</Link>
    </PageHeader>

    <section class="card overflow-x-auto">
        <h2 class="card-title mb-2">الأرصدة حسب العملة</h2>
        <table v-if="totals.length" class="data-table">
            <thead><tr><th>العملة</th><th>المستحق</th><th>المقبوض</th><th>التخفيضات المرحّلة</th><th>المتبقي</th><th>منه متأخر</th></tr></thead>
            <tbody><tr v-for="t in totals" :key="t.currency"><td dir="ltr">{{ t.currency }}</td><td>{{ money(t.amount) }}</td><td>{{ money(t.paid) }}</td><td>{{ money(t.reduced) }}</td><td>{{ money(t.remaining) }}</td><td>{{ money(t.overdue) }}</td></tr></tbody>
        </table>
        <EmptyState v-else title="لا توجد استحقاقات إيجار بعد" description="تتولد عند تفعيل عقود الإيجار." />
    </section>

    <form class="card space-y-3" @submit.prevent="record">
        <h2 class="card-title">تسجيل سند قبض من البرنامج المحاسبي</h2>
        <div class="grid gap-2 md:grid-cols-4">
            <input v-model="form.reference" class="field" required placeholder="رقم السند" dir="ltr" aria-label="رقم السند">
            <input v-model="form.posted_on" type="date" class="field" required aria-label="تاريخ السند">
            <select v-model="form.payment_method_value_id" class="field" aria-label="طريقة الدفع"><option :value="null">طريقة الدفع{{ paymentMethods.length ? '' : ' (لا قيم في الثوابت)' }}</option><option v-for="o in paymentMethods" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            <input v-model="form.note" class="field" placeholder="ملاحظة">
        </div>
        <h3 class="text-sm font-bold">توزيع السند على الاستحقاقات (عملة واحدة)</h3>
        <div v-for="(row, i) in form.allocations" :key="i" class="flex flex-wrap gap-2">
            <select v-model="row.due_id" class="field flex-1" required aria-label="الاستحقاق" @change="pick(row)">
                <option value="">الاستحقاق</option>
                <option v-for="d in openDues" :key="d.id" :value="d.id">{{ d.contract_number }} — {{ d.tenant }} — {{ d.due_on }} — متبقٍ {{ money(d.remaining) }} {{ d.currency_code }}</option>
            </select>
            <input v-model="row.amount" type="number" min="0.01" step="0.01" class="field w-40" required placeholder="المبلغ" aria-label="المبلغ">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="form.allocations.length === 1" @click="form.allocations.splice(i, 1)">حذف</button>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" @click="form.allocations.push({ due_id: '', amount: '' })">+ استحقاق آخر من السند نفسه</button>
        <p v-if="currencies.length > 1" class="field-error">السند الواحد بعملة واحدة؛ اختر استحقاقات بالعملة نفسها.</p>
        <p class="text-xs text-slate-500">لا يتجاوز المبلغ المتبقي على الاستحقاق؛ الزيادة تبقى رصيدًا دائنًا للمستأجر في {{ externalSystem }} وتُخصص لاحقًا أو بتسوية معتمدة.</p>
        <p v-for="(message, key) in form.errors" :key="key" class="field-error">{{ message }}</p>
        <button class="btn btn-primary" :disabled="form.processing || currencies.length > 1">تسجيل القبض</button>
    </form>

    <section class="card space-y-3 overflow-x-auto">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="card-title flex-1">الاستحقاقات</h2>
            <select v-model="filter.filter" class="field w-auto" aria-label="تصفية" @change="apply"><option v-for="(label, key) in filterLabels" :key="key" :value="key">{{ label }}</option></select>
            <input v-model="filter.search" class="field w-56" placeholder="رقم العقد أو المستأجر" aria-label="بحث" @keyup.enter="apply">
            <button type="button" class="btn btn-secondary btn-sm" @click="apply">بحث</button>
        </div>
        <table v-if="dues.length" class="data-table">
            <thead><tr><th>العقد</th><th>المستأجر</th><th>الاستحقاق</th><th>البند</th><th>المستحق</th><th>المقبوض</th><th>التسويات (−/+)</th><th>المتبقي</th><th>الحالة</th></tr></thead>
            <tbody><tr v-for="d in dues" :key="d.id">
                <td dir="ltr">{{ d.contract_number }}</td><td>{{ d.tenant }}</td><td>{{ d.due_on }}</td><td>{{ d.kind === 'service' ? 'خدمة' : 'إيجار' }}</td>
                <td>{{ money(d.amount) }} <span dir="ltr" class="text-xs">{{ d.currency_code }}</span></td><td>{{ money(d.paid) }}</td><td>{{ money(d.reduced) }} / {{ money(d.increased) }}</td>
                <td class="font-semibold">{{ money(d.remaining) }}</td><td><StatusBadge :status="d.overdue ? 'overdue' : d.payment_state" /></td>
            </tr></tbody>
        </table>
        <EmptyState v-else title="لا توجد استحقاقات في هذا التصنيف" />
    </section>

    <section class="card space-y-2">
        <h2 class="card-title">آخر القبض المسجل</h2>
        <ul v-if="receipts.length" class="divide-y divide-slate-100 text-sm">
            <li v-for="x in receipts" :key="x.id" class="flex flex-wrap items-center gap-2 py-2" :class="x.reversed_at ? 'text-slate-400' : ''">
                <span dir="ltr" class="font-mono">{{ x.accounting_reference }}</span><span>{{ x.contract_number }} — {{ x.due_on }}</span><b>{{ money(x.amount) }} {{ x.currency_code }}</b>
                <span class="text-xs text-slate-500">سند بتاريخ {{ String(x.accounting_posted_at).slice(0, 10) }} · {{ x.recorded_by }}</span>
                <StatusBadge v-if="x.reversed_at" status="reversed" /><span v-if="x.reversed_at" class="text-xs">{{ x.reversal_reason }}</span>
                <button v-else type="button" class="btn btn-secondary btn-sm mr-auto" @click="reverse(x)">عكس</button>
            </li>
        </ul>
        <EmptyState v-else title="لم يُسجل قبض بعد" />
    </section>
</div>
</AppLayout>
</template>
