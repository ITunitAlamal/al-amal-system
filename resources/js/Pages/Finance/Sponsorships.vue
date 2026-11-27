<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { createUuid } from '../../Support/uuid.js';

// Sponsorship dues referred by the Orphans department. The sponsor pays in the external accounting software; Accounts
// record its receipt number, date and amount on the due here (no bookkeeping). The server checks every rule again.
const props = defineProps({ dues: Array, receipts: Array, documentary: Boolean, externalSystem: String, filters: Object });

const filterLabels = { open: 'غير المحصلة', overdue: 'المتأخرة', collected: 'المحصلة', all: 'الكل' };
const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const newKey = () => createUuid();
const filter = ref({ ...props.filters });
const apply = () => router.get('/finance/sponsorships', filter.value, { preserveState: true, preserveScroll: true, replace: true });
const today = new Date().toISOString().slice(0, 10);

const openDues = computed(() => props.dues.filter(d => Number(d.remaining) > 0));
const requested = Number(new URLSearchParams(String(usePage().url).split('?')[1] || '').get('due')) || '';
const dueOf = id => props.dues.find(d => Number(d.id) === Number(id));
const form = useForm({ request_key: newKey(), due_id: dueOf(requested) ? requested : '', reference: '', posted_on: today, amount: dueOf(requested) ? Number(dueOf(requested).remaining).toFixed(2) : '', note: '' });
const selected = computed(() => dueOf(form.due_id));
function choose(due) {
    form.due_id = due.id;
    form.amount = Number(due.remaining).toFixed(2);
    document.getElementById('sponsorship-receipt')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function record() {
    form.post('/finance/sponsorships/receipts', { preserveScroll: true, onSuccess: () => { form.reset(); form.request_key = newKey(); } });
}
function reverse(receipt) {
    const reason = prompt('سبب عكس القبض (مثل: عُكس السند في البرنامج المحاسبي):');
    if (!reason) return;
    const reference = prompt('رقم سند العكس في البرنامج المحاسبي (اختياري):') || null;
    router.post(`/finance/sponsorships/receipts/${receipt.id}/reverse`, { reason, reversal_reference: reference }, { preserveScroll: true });
}
const overdue = d => Number(d.remaining) > 0 && d.due_on < today;
</script>

<template>
<AppLayout title="قبض الكفالات">
<div dir="rtl" class="mx-auto max-w-7xl space-y-5">
    <PageHeader eyebrow="قسم الحسابات" title="قبض الكفالات" :description="`استحقاقات الكفالات التي أحالها قسم الأيتام. القبض يتم في ${externalSystem}؛ هنا يُسجَّل رقم السند وتاريخه ومبلغه على الاستحقاق فقط، فيظهر التحصيل والمتأخرات للقسم.`">
        <Link href="/finance" class="btn btn-secondary">قسم الحسابات</Link>
    </PageHeader>

    <p v-if="!documentary" class="alert-warning text-sm">قسم الحسابات ليس في وضع الدورة المستندية؛ يُسجَّل قبض الكفالات بسند القبض الداخلي.</p>

    <form v-else id="sponsorship-receipt" class="card space-y-3" @submit.prevent="record">
        <h2 class="card-title">تسجيل سند قبض من {{ externalSystem }}</h2>
        <select v-model="form.due_id" class="field" required aria-label="الاستحقاق" @change="selected && (form.amount = Number(selected.remaining).toFixed(2))">
            <option value="">اختر الاستحقاق</option>
            <option v-for="d in openDues" :key="d.id" :value="d.id">{{ d.sponsorship_reference }} — {{ d.sponsor }} — {{ d.due_on }} — متبقٍ {{ money(d.remaining) }} {{ d.currency }}</option>
        </select>
        <div class="grid gap-2 md:grid-cols-4">
            <input v-model="form.reference" class="field" required maxlength="150" placeholder="رقم سند القبض" dir="ltr" aria-label="رقم سند القبض">
            <input v-model="form.posted_on" type="date" class="field" required :max="today" aria-label="تاريخ السند">
            <input v-model="form.amount" type="number" min="0.01" step="0.01" class="field" required placeholder="المبلغ" aria-label="المبلغ">
            <input v-model="form.note" class="field" maxlength="2000" placeholder="ملاحظة (اختياري)">
        </div>
        <p class="text-xs text-slate-500">لا يتجاوز المبلغ المتبقي على الاستحقاق؛ الزيادة تبقى رصيدًا للكافل في {{ externalSystem }}. لا يسجل القبضَ من أحال الاستحقاق.</p>
        <p v-for="(message, key) in form.errors" :key="key" class="field-error">{{ message }}</p>
        <button class="btn btn-primary" :disabled="form.processing">تسجيل القبض</button>
    </form>

    <section class="card space-y-3 overflow-x-auto">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="card-title flex-1">الاستحقاقات المحالة</h2>
            <select v-model="filter.filter" class="field w-auto" aria-label="تصفية" @change="apply"><option v-for="(label, key) in filterLabels" :key="key" :value="key">{{ label }}</option></select>
            <input v-model="filter.search" class="field w-56" placeholder="رقم الكفالة أو الكافل أو المستحق" aria-label="بحث" @keyup.enter="apply">
            <button type="button" class="btn btn-secondary btn-sm" @click="apply">بحث</button>
        </div>
        <table v-if="dues.length" class="data-table">
            <thead><tr><th>الكفالة</th><th>الكافل</th><th>الاستحقاق</th><th>المستحق</th><th>المحصل</th><th>المتبقي</th><th>الحالة</th><th></th></tr></thead>
            <tbody><tr v-for="d in dues" :key="d.id">
                <td dir="ltr">{{ d.sponsorship_reference }}</td><td>{{ d.sponsor }}</td><td>{{ d.due_on }}</td>
                <td>{{ money(d.amount) }} <span dir="ltr" class="text-xs">{{ d.currency }}</span></td><td>{{ money(d.collected) }}</td><td class="font-semibold">{{ money(d.remaining) }}</td>
                <td><StatusBadge :label="Number(d.remaining) <= 0 ? 'محصل' : (overdue(d) ? 'متأخر' : 'غير محصل')" :tone="Number(d.remaining) <= 0 ? 'success' : (overdue(d) ? 'danger' : 'warning')" /></td>
                <td><button v-if="documentary && Number(d.remaining) > 0" type="button" class="btn btn-secondary btn-sm" @click="choose(d)">تسجيل قبض</button></td>
            </tr></tbody>
        </table>
        <EmptyState v-else title="لا توجد استحقاقات في هذا التصنيف" description="تظهر هنا استحقاقات الكفالات عند إحالتها من قسم الأيتام." />
    </section>

    <section class="card space-y-2">
        <h2 class="card-title">آخر القبض المسجل</h2>
        <ul v-if="receipts.length" class="divide-y divide-slate-100 text-sm">
            <li v-for="x in receipts" :key="x.id" class="flex flex-wrap items-center gap-2 py-2" :class="x.reversed_at ? 'text-slate-400' : ''">
                <span dir="ltr" class="font-mono">{{ x.accounting_reference }}</span><span>{{ x.sponsorship_reference }} — {{ x.due_on }}</span><b>{{ money(x.amount) }} {{ x.currency }}</b>
                <span class="text-xs text-slate-500">سند بتاريخ {{ String(x.posted_on).slice(0, 10) }} · {{ x.recorded_by }}</span>
                <StatusBadge v-if="x.reversed_at" status="reversed" /><span v-if="x.reversed_at" class="text-xs">{{ x.reversal_reason }}</span>
                <button v-else type="button" class="btn btn-secondary btn-sm mr-auto" @click="reverse(x)">عكس</button>
            </li>
        </ul>
        <EmptyState v-else title="لم يُسجل قبض بعد" />
    </section>
</div>
</AppLayout>
</template>
