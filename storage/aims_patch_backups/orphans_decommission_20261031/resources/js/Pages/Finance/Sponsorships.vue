<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

// Accounts' page for sponsorships: every record carries the voucher of the external accounting software.
const props = defineProps({
    dues: Array, collections: Array, payable: Array, disbursements: Array, payouts: Array, savings: Array, savingsEntries: Array, contributions: Array,
    currencies: Array, externalSystem: String, filters: Object, filterLabels: Object,
});
const tab = ref('dues');
const tabs = computed(() => [['dues', 'التحصيل على الاستحقاقات'], ['payouts', `بيانات صرف بانتظار الاعتماد (${props.payouts.length})`], ['disburse', 'الصرف للأسر'],
    ['savings', 'ادخار الأيتام'], ['donations', `التبرعات (${props.contributions.filter(c => c.status === 'pledged').length} بانتظار القبض)`]]);
const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random()}`.replace(/[^0-9a-f-]/g, '0'));
const post = (action, data) => router.post(`/finance/sponsorships/${action}`, data, { preserveScroll: true });
const ask = text => { const value = prompt(text); return value && value.trim() ? value.trim() : null; };

const filter = ref({ ...props.filters });
const apply = () => router.get('/finance/sponsorships', filter.value, { preserveState: true, preserveScroll: true, replace: true });
const openDues = computed(() => props.dues.filter(d => Number(d.remaining) > 0));
const collection = useForm({ request_key: newKey(), schedule_id: '', amount: '', reference: '', posted_on: '' });
function pickDue() {
    const due = props.dues.find(d => Number(d.id) === Number(collection.schedule_id));
    if (due) collection.amount = Number(due.remaining).toFixed(2);
}
function recordCollection() { collection.post('/finance/sponsorships/collection', { preserveScroll: true, onSuccess: () => { collection.reset(); collection.request_key = newKey(); } }); }
function reverseCollection(c) { const reason = ask('سبب عكس التحصيل (مثل: عُكس السند في البرنامج المحاسبي):'); if (reason) post('collection-reverse', { id: c.id, reason }); }

const details = reactive({});
async function reveal(p) {
    const response = await fetch(`/finance/sponsorships/payouts/${p.id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    details[p.id] = response.ok ? await response.json() : { error: 'تعذر عرض البيانات.' };
}
function approvePayout(p) { post('payout-approve', { id: p.id, note: null }); }
function rejectPayout(p) { const note = ask('سبب رفض بيانات الصرف:'); if (note) post('payout-reject', { id: p.id, note }); }

const disbursement = useForm({ sponsorship_id: '', amount: '', reference: '', executed_on: '' });
function pickSponsorship() {
    const row = props.payable.find(s => Number(s.id) === Number(disbursement.sponsorship_id));
    if (row) disbursement.amount = Number(row.available).toFixed(2);
}
function recordDisbursement() { disbursement.post('/finance/sponsorships/disbursement', { preserveScroll: true, onSuccess: () => disbursement.reset() }); }
function reverseDisbursement(x) { const reason = ask('سبب عكس الصرف:'); if (reason) post('disbursement-reverse', { id: x.id, reason }); }

const debit = useForm({ file_no: '', currency: 'ILS', amount: '', reference: '', reason: '' });
function recordDebit() { debit.post('/finance/sponsorships/savings-debit', { preserveScroll: true, onSuccess: () => debit.reset() }); }

function collectDonation(c) {
    const reference = ask(`رقم سند القبض في ${props.externalSystem}:`);
    if (!reference) return;
    const postedOn = ask('تاريخ السند (YYYY-MM-DD):');
    if (postedOn) post('contribution-collect', { id: c.id, reference, posted_on: postedOn });
}
function refundDonation(c) { const reason = ask('سبب رد التبرع ومرجع سند الرد:'); if (reason) post('contribution-refund', { id: c.id, reason }); }
const kindLabels = { credit: 'إيداع من تحصيل', debit: 'سحب', reversal: 'عكس إيداع' };
</script>

<template>
<AppLayout title="قبض الكفالات والتبرعات وصرفها">
<div dir="rtl" class="mx-auto max-w-7xl space-y-5">
    <PageHeader eyebrow="قسم الحسابات" title="الكفالات والتبرعات: القبض والصرف والادخار" :description="`القبض والصرف يتمان في ${externalSystem}؛ هنا يُسجَّل رقم السند وتاريخه ومبلغه فقط، دون قيود محاسبية. مساعدات الأسر تصلك مهامَّ في «مهامي».`">
        <Link href="/finance" class="btn btn-secondary">قسم الحسابات</Link>
        <Link href="/tasks" class="btn btn-secondary">مهامي (تنفيذ المساعدات)</Link>
    </PageHeader>

    <div class="flex flex-wrap gap-2" role="tablist">
        <button v-for="[key, label] in tabs" :key="key" type="button" role="tab" :aria-selected="tab === key" class="btn btn-sm" :class="tab === key ? 'btn-primary' : 'btn-secondary'" @click="tab = key">{{ label }}</button>
    </div>

    <template v-if="tab === 'dues'">
        <form class="card space-y-3" @submit.prevent="recordCollection">
            <h2 class="card-title">تسجيل تحصيل من سند قبض</h2>
            <div class="grid gap-2 md:grid-cols-4">
                <select v-model="collection.schedule_id" class="field md:col-span-2" required aria-label="الاستحقاق" @change="pickDue">
                    <option value="">الاستحقاق</option>
                    <option v-for="d in openDues" :key="d.id" :value="d.id">{{ d.sponsorship_no }} — {{ d.beneficiary }} — {{ d.period_start }} — متبقٍ {{ money(d.remaining) }} {{ d.currency }}</option>
                </select>
                <input v-model="collection.amount" type="number" min="0.01" step="0.01" class="field" required placeholder="المبلغ" aria-label="المبلغ">
                <input v-model="collection.reference" class="field" required placeholder="رقم السند" dir="ltr" aria-label="رقم السند">
                <input v-model="collection.posted_on" type="date" class="field" required aria-label="تاريخ السند">
            </div>
            <p class="text-xs text-slate-500">لا يتجاوز المبلغ المتبقي على الاستحقاق؛ الزيادة تبقى رصيدًا للكافل في {{ externalSystem }}. حصة الادخار تُقيد في دفتر الطفل تلقائيًا بنسبة ما حُصّل.</p>
            <p v-for="(message, key) in collection.errors" :key="key" class="field-error">{{ message }}</p>
            <button class="btn btn-primary" :disabled="collection.processing">تسجيل التحصيل</button>
        </form>

        <section class="card space-y-3 overflow-x-auto">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="card-title flex-1">الاستحقاقات</h2>
                <select v-model="filter.filter" class="field w-auto" aria-label="تصفية" @change="apply"><option v-for="(label, key) in filterLabels" :key="key" :value="key">{{ label }}</option></select>
                <input v-model="filter.search" class="field w-56" placeholder="رقم الكفالة أو الملف أو الكافل" aria-label="بحث" @keyup.enter="apply">
                <button type="button" class="btn btn-secondary btn-sm" @click="apply">بحث</button>
            </div>
            <table v-if="dues.length" class="data-table">
                <thead><tr><th>الكفالة</th><th>الكافل</th><th>المستفيد</th><th>الفترة</th><th>المستحق</th><th>المحصّل</th><th>المتبقي</th><th>الحالة</th></tr></thead>
                <tbody><tr v-for="d in dues" :key="d.id">
                    <td dir="ltr">{{ d.sponsorship_no }}</td><td>{{ d.donor }}</td><td>{{ d.beneficiary }}</td><td>{{ d.period_start }}</td>
                    <td>{{ money(d.expected) }} <span dir="ltr" class="text-xs">{{ d.currency }}</span></td><td>{{ money(d.collected) }}</td><td class="font-semibold">{{ money(d.remaining) }}</td>
                    <td><StatusBadge v-if="d.overdue" status="overdue" /><StatusBadge v-else :label="d.status_label" :tone="d.status === 'collected' ? 'success' : 'info'" /></td>
                </tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد استحقاقات في هذا التصنيف" description="تتولد عند اعتماد المدير التنفيذي للكفالة." />
        </section>

        <section class="card space-y-2">
            <h2 class="card-title">آخر التحصيلات المسجلة</h2>
            <ul v-if="collections.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="c in collections" :key="c.id" class="flex flex-wrap items-center gap-2 py-2" :class="c.reversed_at ? 'text-slate-400' : ''">
                    <span dir="ltr" class="font-mono">{{ c.accounting_reference }}</span><span>{{ c.sponsorship_no }} — {{ c.period_start }}</span><b>{{ money(c.amount) }} {{ c.currency }}</b>
                    <span class="text-xs text-slate-500">سند بتاريخ {{ c.posted_on }} · {{ c.recorded_by }}</span>
                    <StatusBadge v-if="c.reversed_at" status="reversed" /><span v-if="c.reversed_at" class="text-xs">{{ c.reversal_reason }}</span>
                    <button v-else type="button" class="btn btn-secondary btn-sm mr-auto" @click="reverseCollection(c)">عكس</button>
                </li>
            </ul>
            <EmptyState v-else title="لم يُسجل تحصيل بعد" />
        </section>
    </template>

    <section v-else-if="tab === 'payouts'" class="card space-y-2">
        <h2 class="card-title">بيانات صرف الأسر بانتظار الاعتماد</h2>
        <p class="text-xs text-slate-500">لا يُصرف لأسرة إلا على بيانات معتمدة. اعتماد بيانات جديدة يستبدل السابقة. لا يعتمد مُدخل البيانات ما أدخله.</p>
        <ul v-if="payouts.length" class="divide-y divide-slate-100 text-sm">
            <li v-for="p in payouts" :key="p.id" class="space-y-1 py-2">
                <div class="flex flex-wrap items-center gap-2">
                    <b>أسرة {{ p.household }}</b><span>{{ p.channel }}{{ p.institution ? ` — ${p.institution}` : '' }}</span><span dir="ltr">{{ p.account }}</span>
                    <span class="text-xs text-slate-500">أدخلها {{ p.submitted_by }} · {{ String(p.created_at).slice(0, 10) }}</span>
                    <span class="mr-auto flex gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="reveal(p)">عرض البيانات كاملة</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="p.own" @click="approvePayout(p)">اعتماد</button>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="p.own" @click="rejectPayout(p)">رفض</button>
                    </span>
                </div>
                <p v-if="details[p.id]" class="rounded bg-slate-50 p-2 text-xs">
                    <template v-if="details[p.id].error">{{ details[p.id].error }}</template>
                    <template v-else>صاحب الحساب: {{ details[p.id].account_holder }} — الرقم: <span dir="ltr" class="font-mono">{{ details[p.id].account_identifier }}</span>{{ details[p.id].branch ? ` — الفرع: ${details[p.id].branch}` : '' }}</template>
                </p>
            </li>
        </ul>
        <EmptyState v-else title="لا توجد بيانات صرف بانتظار الاعتماد" />
    </section>

    <template v-else-if="tab === 'disburse'">
        <form class="card space-y-3" @submit.prevent="recordDisbursement">
            <h2 class="card-title">تسجيل صرف لأسرة من سند صرف</h2>
            <div class="grid gap-2 md:grid-cols-4">
                <select v-model="disbursement.sponsorship_id" class="field md:col-span-2" required aria-label="الكفالة" @change="pickSponsorship">
                    <option value="">الكفالة</option>
                    <option v-for="s in payable" :key="s.id" :value="s.id" :disabled="!s.has_payout">{{ s.sponsorship_no }} — أسرة {{ s.household }} — متاح {{ money(s.available) }} {{ s.currency }}{{ s.has_payout ? '' : ' (لا بيانات صرف معتمدة)' }}</option>
                </select>
                <input v-model="disbursement.amount" type="number" min="0.01" step="0.01" class="field" required placeholder="المبلغ" aria-label="المبلغ">
                <input v-model="disbursement.reference" class="field" required placeholder="رقم سند الصرف" dir="ltr" aria-label="رقم سند الصرف">
                <input v-model="disbursement.executed_on" type="date" class="field" required aria-label="تاريخ الصرف">
            </div>
            <p class="text-xs text-slate-500">يُصرف من حصة المصاريف المحصّلة فقط، على بيانات الصرف المعتمدة للأسرة.</p>
            <p v-for="(message, key) in disbursement.errors" :key="key" class="field-error">{{ message }}</p>
            <button class="btn btn-primary" :disabled="disbursement.processing">تسجيل الصرف</button>
        </form>
        <section class="card space-y-2">
            <h2 class="card-title">آخر الصرف المسجل</h2>
            <ul v-if="disbursements.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="x in disbursements" :key="x.id" class="flex flex-wrap items-center gap-2 py-2" :class="x.status === 'reversed' ? 'text-slate-400' : ''">
                    <span dir="ltr" class="font-mono">{{ x.accounting_reference }}</span><span>{{ x.sponsorship_no }} — أسرة {{ x.household }}</span><b>{{ money(x.amount) }} {{ x.currency }}</b>
                    <span class="text-xs text-slate-500">{{ x.executed_on }} · {{ x.recorded_by }}</span>
                    <StatusBadge v-if="x.status === 'reversed'" status="reversed" /><span v-if="x.status === 'reversed'" class="text-xs">{{ x.reversal_reason }}</span>
                    <button v-else type="button" class="btn btn-secondary btn-sm mr-auto" @click="reverseDisbursement(x)">عكس</button>
                </li>
            </ul>
            <EmptyState v-else title="لم يُسجل صرف بعد" />
        </section>
    </template>

    <template v-else-if="tab === 'savings'">
        <section class="card overflow-x-auto">
            <h2 class="card-title mb-2">أرصدة ادخار الأيتام</h2>
            <table v-if="savings.length" class="data-table">
                <thead><tr><th>ملف الطفل</th><th>العملة</th><th>الرصيد</th></tr></thead>
                <tbody><tr v-for="b in savings" :key="`${b.file_no}-${b.currency}`"><td dir="ltr">{{ b.file_no }}</td><td dir="ltr">{{ b.currency }}</td><td class="font-semibold">{{ money(b.balance) }}</td></tr></tbody>
            </table>
            <EmptyState v-else title="لا توجد أرصدة ادخار" description="يُقيد الادخار من التحصيلات المسجلة على كفالات الادخار." />
        </section>
        <form class="card space-y-3" @submit.prevent="recordDebit">
            <h2 class="card-title">تسجيل سحب من ادخار طفل</h2>
            <div class="grid gap-2 md:grid-cols-4">
                <input v-model="debit.file_no" class="field" required placeholder="رقم ملف الطفل" dir="ltr" aria-label="رقم ملف الطفل">
                <select v-model="debit.currency" class="field" aria-label="العملة"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select>
                <input v-model="debit.amount" type="number" min="0.01" step="0.01" class="field" required placeholder="المبلغ" aria-label="المبلغ">
                <input v-model="debit.reference" class="field" required placeholder="رقم سند الصرف" dir="ltr" aria-label="رقم سند الصرف">
                <textarea v-model="debit.reason" class="field md:col-span-4" rows="2" required minlength="5" maxlength="1000" placeholder="سبب السحب ومرجع الإذن المعتمد" aria-label="سبب السحب"></textarea>
            </div>
            <p v-for="(message, key) in debit.errors" :key="key" class="field-error">{{ message }}</p>
            <button class="btn btn-primary" :disabled="debit.processing">تسجيل السحب</button>
        </form>
        <section class="card space-y-2">
            <h2 class="card-title">آخر حركات الادخار</h2>
            <ul v-if="savingsEntries.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="e in savingsEntries" :key="e.id" class="flex flex-wrap gap-2 py-2">
                    <span dir="ltr">{{ e.file_no }}</span><span>{{ kindLabels[e.kind] }}</span><b>{{ money(e.amount) }} {{ e.currency }}</b><span dir="ltr" class="font-mono">{{ e.accounting_reference }}</span>
                    <span class="text-xs text-slate-500">{{ e.reason }} · {{ e.recorded_by }} · {{ String(e.created_at).slice(0, 10) }}</span>
                </li>
            </ul>
            <EmptyState v-else title="لا توجد حركات" />
        </section>
    </template>

    <section v-else class="card space-y-2">
        <h2 class="card-title">التبرعات لمرة واحدة</h2>
        <ul v-if="contributions.length" class="divide-y divide-slate-100 text-sm">
            <li v-for="c in contributions" :key="c.id" class="flex flex-wrap items-center gap-2 py-2">
                <span dir="ltr">{{ c.reference_no }}</span><span>{{ c.donor }}</span><b>{{ money(c.amount) }} {{ c.currency }}</b><span class="text-xs text-slate-500">{{ c.earmark }}</span>
                <StatusBadge :label="c.status_label" :tone="c.status === 'collected' ? 'success' : 'info'" /><span v-if="c.accounting_reference" dir="ltr" class="font-mono text-xs">{{ c.accounting_reference }}</span>
                <button v-if="c.status === 'pledged'" type="button" class="btn btn-primary btn-sm mr-auto" @click="collectDonation(c)">تسجيل القبض</button>
                <button v-else type="button" class="btn btn-secondary btn-sm mr-auto" @click="refundDonation(c)">رد</button>
            </li>
        </ul>
        <EmptyState v-else title="لا توجد تبرعات بانتظار القبض أو مقبوضة" />
    </section>
</div>
</AppLayout>
</template>
