<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import OrphanPicker from '../../../Components/Registry/OrphanPicker.vue';

const props = defineProps({ tab: String, rows: Object, filters: Object, abilities: Object, labels: Object, options: Object, sponsors: Array });
const tabs = { sponsorships: 'الكفالات', sponsors: 'الكفلاء', assistance: 'طلبات المساعدة', benefits: 'سجل الاستفادة' };
const q = ref(props.filters.q || '');
const open = ref(false);
function go(extra = {}) { router.get('/orphans/aid', Object.fromEntries(Object.entries({ tab: props.tab, q: q.value, ...extra }).filter(([, v]) => v)), { preserveState: true }); }
const sponsor = useForm({ kind: 'individual', name: '', contact_name: '', phone: '', email: '', country: '', notes: '' });
const sponsorship = useForm({ sponsor_id: null, program_value_id: null, coverage: 'individual', amount: '', currency: 'USD', period_months: 1, frequency_value_id: null, starts_on: new Date().toISOString().slice(0, 10), ends_on: '', notes: '', orphans: [], households: [] });
const picked = ref([]);
const household = ref('');
const assistance = useForm({ unit: 'orphan', orphan_public_id: null, household_reference: '', type_value_id: null, description: '', quantity: null, amount: null, currency: null });
const assistancePick = ref(null);
const benefit = useForm({ unit: 'orphan', orphan_public_id: null, household_reference: '', source: 'other', assistance_public_id: null, type_value_id: null, description: '', quantity: null, unit_label: '',
    amount: null, currency: null, recipient_name: '', delivered_on: new Date().toISOString().slice(0, 10), fulfillment_ref: '' });
const benefitPick = ref(null);
const statusTones = { draft: 'warning', active: 'success', partial: 'info', suspended: 'danger', expired: 'muted', ended: 'muted', requested: 'info', approved: 'success', rejected: 'danger', fulfilled: 'success', cancelled: 'muted' };
function saveSponsorship() { sponsorship.orphans = picked.value.map(p => p.public_id); sponsorship.households = household.value ? household.value.split(/[\s,،]+/).filter(Boolean) : []; sponsorship.post('/orphans/aid/sponsorships'); }
function decide(row, decision) { const note = decision === 'approved' ? '' : window.prompt('السبب:'); if (decision !== 'approved' && !note) return; router.post(`/orphans/aid/assistance/${row.public_id}`, { decision, note }, { preserveScroll: true }); }
function deliver(row) { benefit.reset(); benefit.source = 'assistance'; benefit.assistance_public_id = row.public_id; benefit.unit = row.orphan_id ? 'orphan' : 'household'; benefit.orphan_public_id = row.orphan_public_id;
    benefit.household_reference = row.household_reference || ''; benefit.description = row.description; benefit.quantity = row.quantity; benefit.amount = row.amount; benefit.currency = row.currency; benefitPick.value = { full_name: row.orphan_name || row.household_reference }; open.value = 'benefit'; }
function reverse(row) { const reason = window.prompt('سبب التصحيح (يبقى القيد الأصلي محفوظًا):'); if (reason) router.post(`/orphans/aid/benefits/${row.public_id}/reverse`, { reason }, { preserveScroll: true }); }
</script>

<template>
    <AppLayout title="الكفالات والمساعدات">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="الكفالات والمساعدات" description="الكفالة التزام وليست مبلغًا مقبوضًا؛ القبض والصرف يُسجلان في الحسابات. سجل الاستفادة يضم التسليمات المؤكدة فقط." />
            <nav class="flex flex-wrap gap-2"><button v-for="(label, key) in tabs" :key="key" type="button" :class="['btn', tab === key ? 'btn-primary' : 'btn-secondary']" @click="router.get('/orphans/aid', { tab: key })">{{ label }}</button></nav>

            <section class="card p-0">
                <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                    <label v-if="['sponsorships', 'sponsors'].includes(tab)" class="min-w-0 flex-1 text-sm font-semibold">بحث<input v-model="q" type="search" class="field mt-1 font-normal" placeholder="الرقم المرجعي أو اسم الكافل" @keydown.enter="go()"></label>
                    <label v-if="tab === 'sponsorships' || tab === 'assistance'" class="text-sm font-semibold">الحالة<select :value="filters.status || ''" class="field mt-1 font-normal" @change="go({ status: $event.target.value })"><option value="">الكل</option>
                        <option v-for="(label, key) in (tab === 'sponsorships' ? labels.sponsorship : labels.assistance)" :key="key" :value="key">{{ label }}</option></select></label>
                    <template v-if="abilities.sponsorships">
                        <button v-if="tab === 'sponsors'" type="button" class="btn btn-primary" @click="open = open === 'sponsor' ? false : 'sponsor'">إضافة كافل</button>
                        <button v-if="tab === 'sponsorships'" type="button" class="btn btn-primary" @click="open = open === 'sponsorship' ? false : 'sponsorship'">كفالة جديدة</button>
                        <button v-if="tab === 'assistance'" type="button" class="btn btn-primary" @click="open = open === 'assistance' ? false : 'assistance'">طلب مساعدة</button>
                    </template>
                    <button v-if="tab === 'benefits' && (abilities.sponsorships || abilities.delivery)" type="button" class="btn btn-primary" @click="benefit.reset(); benefitPick = null; open = open === 'benefit' ? false : 'benefit'">تسجيل استفادة مؤكدة</button>
                </div>

                <form v-if="open === 'sponsor'" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-3" @submit.prevent="sponsor.post('/orphans/aid/sponsors', { onSuccess: () => { open = false; sponsor.reset(); } })">
                    <label class="text-sm">النوع<select v-model="sponsor.kind" class="field mt-1"><option value="individual">فرد</option><option value="institution">مؤسسة</option></select></label>
                    <label class="text-sm sm:col-span-2">الاسم<input v-model="sponsor.name" class="field mt-1" maxlength="300" required></label>
                    <label class="text-sm">جهة الاتصال<input v-model="sponsor.contact_name" class="field mt-1"></label><label class="text-sm">الجوال<input v-model="sponsor.phone" class="field mt-1" dir="ltr"></label>
                    <label class="text-sm">البريد<input v-model="sponsor.email" class="field mt-1" dir="ltr"></label><label class="text-sm">الدولة<input v-model="sponsor.country" class="field mt-1"></label>
                    <label class="text-sm sm:col-span-2">ملاحظات<input v-model="sponsor.notes" class="field mt-1"></label>
                    <p v-for="(e, k) in sponsor.errors" :key="k" class="field-error sm:col-span-3">{{ e }}</p>
                    <div class="sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="sponsor.processing">حفظ</button></div>
                </form>

                <form v-if="open === 'sponsorship'" class="space-y-3 border-b border-slate-200 p-4" @submit.prevent="saveSponsorship">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <label class="text-sm sm:col-span-2">الكافل<select v-model="sponsorship.sponsor_id" class="field mt-1" required><option :value="null">اختر</option><option v-for="s in sponsors" :key="s.id" :value="s.id">{{ s.name }} ({{ s.reference_no }})</option></select></label>
                        <label class="text-sm">البرنامج<select v-model="sponsorship.program_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="p in options.programs" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
                        <label class="text-sm">نوع التغطية<select v-model="sponsorship.coverage" class="field mt-1"><option v-for="(label, key) in labels.coverage" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="text-sm">القيمة لكل فترة<input v-model="sponsorship.amount" type="number" min="0.01" step="0.01" class="field mt-1" required></label>
                        <label class="text-sm">العملة<select v-model="sponsorship.currency" class="field mt-1"><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="text-sm">طول الفترة (أشهر)<input v-model.number="sponsorship.period_months" type="number" min="1" max="12" class="field mt-1"></label>
                        <label class="text-sm">التكرار (ثوابت)<select v-model="sponsorship.frequency_value_id" class="field mt-1"><option :value="null">—</option><option v-for="f in options.frequencies" :key="f.id" :value="f.id">{{ f.name }}</option></select></label>
                        <label class="text-sm">البدء<input v-model="sponsorship.starts_on" type="date" class="field mt-1" required></label>
                        <label class="text-sm">الانتهاء (اختياري)<input v-model="sponsorship.ends_on" type="date" class="field mt-1"></label>
                    </div>
                    <div v-if="sponsorship.coverage !== 'household'" class="space-y-2"><p class="text-sm font-semibold">الأيتام المكفولون</p>
                        <OrphanPicker @pick="p => picked.find(x => x.public_id === p.public_id) || picked.push(p)" />
                        <ul class="flex flex-wrap gap-2"><li v-for="(p, i) in picked" :key="p.public_id" class="rounded-full bg-blue-50 px-3 py-1 text-sm">{{ p.full_name }} <button type="button" aria-label="إزالة" @click="picked.splice(i, 1)">×</button></li></ul></div>
                    <label v-if="['household', 'multiple', 'pooled'].includes(sponsorship.coverage)" class="block text-sm">أرقام الأسر (HH-…)، مفصولة بفواصل<input v-model="household" class="field mt-1 font-mono" dir="ltr"></label>
                    <p v-for="(e, k) in sponsorship.errors" :key="k" class="field-error">{{ e }}</p>
                    <button type="submit" class="btn btn-primary" :disabled="sponsorship.processing">حفظ كمسودة</button>
                </form>

                <form v-if="open === 'assistance'" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-3" @submit.prevent="assistance.post('/orphans/aid/assistance', { onSuccess: () => { open = false; assistance.reset(); assistancePick = null; } })">
                    <label class="text-sm">المستفيد<select v-model="assistance.unit" class="field mt-1"><option value="orphan">يتيم</option><option value="household">أسرة</option></select></label>
                    <div v-if="assistance.unit === 'orphan'" class="sm:col-span-2"><p v-if="assistancePick" class="text-sm">{{ assistancePick.full_name }}</p><OrphanPicker v-else @pick="p => { assistancePick = p; assistance.orphan_public_id = p.public_id; }" /></div>
                    <label v-else class="text-sm sm:col-span-2">رقم الأسرة<input v-model="assistance.household_reference" class="field mt-1 font-mono" dir="ltr"></label>
                    <label class="text-sm">نوع المساعدة<select v-model="assistance.type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                    <label class="text-sm">الكمية<input v-model="assistance.quantity" type="number" min="0" class="field mt-1"></label>
                    <label class="text-sm">المبلغ<input v-model="assistance.amount" type="number" min="0" step="0.01" class="field mt-1"></label>
                    <label class="text-sm sm:col-span-3">الوصف<textarea v-model="assistance.description" class="field mt-1" rows="2" required /></label>
                    <p v-for="(e, k) in assistance.errors" :key="k" class="field-error sm:col-span-3">{{ e }}</p>
                    <div class="sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="assistance.processing">تسجيل</button></div>
                </form>

                <form v-if="open === 'benefit'" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-3" @submit.prevent="benefit.post('/orphans/aid/benefits', { onSuccess: () => { open = false; } })">
                    <p class="alert-info sm:col-span-3">سجّل التسليم الفعلي فقط. مساعدة الأسرة تُسجل مرة واحدة على الأسرة؛ ما يخص الطفل يُسجل على الطفل ولو استلمه الوصي.</p>
                    <label class="text-sm">المستفيد<select v-model="benefit.unit" class="field mt-1" :disabled="!!benefit.assistance_public_id"><option value="orphan">يتيم</option><option value="household">أسرة</option></select></label>
                    <div v-if="benefit.unit === 'orphan'" class="sm:col-span-2"><p v-if="benefitPick" class="text-sm">{{ benefitPick.full_name }}</p><OrphanPicker v-else @pick="p => { benefitPick = p; benefit.orphan_public_id = p.public_id; }" /></div>
                    <label v-else class="text-sm sm:col-span-2">رقم الأسرة<input v-model="benefit.household_reference" class="field mt-1 font-mono" dir="ltr" :disabled="!!benefit.assistance_public_id"></label>
                    <label class="text-sm sm:col-span-3">البيان<input v-model="benefit.description" class="field mt-1" maxlength="500" required></label>
                    <label class="text-sm">الكمية<input v-model="benefit.quantity" type="number" min="0" class="field mt-1"></label><label class="text-sm">الوحدة<input v-model="benefit.unit_label" class="field mt-1" maxlength="30"></label>
                    <label class="text-sm">المبلغ<input v-model="benefit.amount" type="number" min="0" step="0.01" class="field mt-1"></label>
                    <label class="text-sm">المستلم الفعلي<input v-model="benefit.recipient_name" class="field mt-1" required></label>
                    <label class="text-sm">تاريخ التسليم<input v-model="benefit.delivered_on" type="date" class="field mt-1" required></label>
                    <label class="text-sm">مرجع التنفيذ (سند/حركة مخزن/محضر)<input v-model="benefit.fulfillment_ref" class="field mt-1" maxlength="140"></label>
                    <p v-for="(e, k) in benefit.errors" :key="k" class="field-error sm:col-span-3">{{ e }}</p>
                    <div class="sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="benefit.processing">تسجيل</button></div>
                </form>

                <div class="overflow-x-auto p-4">
                    <EmptyState v-if="!rows.data.length" :title="`لا توجد بيانات في «${tabs[tab]}» بعد`" />
                    <table v-else-if="tab === 'sponsorships'" class="data-table min-w-[720px]">
                        <thead><tr><th scope="col">الكفالة</th><th scope="col">الكافل</th><th scope="col">التغطية</th><th scope="col">القيمة</th><th scope="col">المستفيدون</th><th scope="col">الحالة</th></tr></thead>
                        <tbody><tr v-for="s in rows.data" :key="s.public_id"><td><Link :href="`/orphans/aid/sponsorships/${s.public_id}`" class="font-mono text-blue-700 hover:underline" dir="ltr">{{ s.reference_no }}</Link></td>
                            <td>{{ s.sponsor_name }}</td><td>{{ labels.coverage[s.coverage] }}</td><td class="tabular-nums">{{ s.amount }} {{ s.currency }} / {{ s.period_months }} شهر</td><td>{{ s.beneficiaries }}</td>
                            <td><StatusBadge :label="labels.sponsorship[s.effective_status]" :tone="statusTones[s.effective_status]" /></td></tr></tbody>
                    </table>
                    <table v-else-if="tab === 'sponsors'" class="data-table">
                        <thead><tr><th scope="col">الكافل</th><th scope="col">النوع</th><th scope="col">التواصل</th><th scope="col">كفالات نشطة</th></tr></thead>
                        <tbody><tr v-for="s in rows.data" :key="s.id"><td>{{ s.name }} <span class="font-mono text-xs text-slate-500" dir="ltr">{{ s.reference_no }}</span></td><td>{{ s.kind === 'institution' ? 'مؤسسة' : 'فرد' }}</td>
                            <td dir="ltr" class="text-end">{{ [s.phone, s.email].filter(Boolean).join(' · ') || '—' }}</td><td>{{ s.active_count }}</td></tr></tbody>
                    </table>
                    <ul v-else-if="tab === 'assistance'" class="divide-y divide-slate-100">
                        <li v-for="a in rows.data" :key="a.public_id" class="flex flex-wrap items-center gap-2 py-2 text-sm"><span class="font-mono" dir="ltr">{{ a.reference_no }}</span><StatusBadge :label="labels.assistance[a.status]" :tone="statusTones[a.status]" />
                            <Link v-if="a.orphan_public_id" :href="`/orphans/registry/orphans/${a.orphan_public_id}`" class="text-blue-700 hover:underline">{{ a.orphan_name }}</Link><span v-else class="font-mono" dir="ltr">{{ a.household_reference }}</span>
                            <span class="min-w-0 flex-1 text-slate-700">{{ a.type ? `${a.type} · ` : '' }}{{ a.description }}</span>
                            <template v-if="a.status === 'requested' && abilities.assign"><button type="button" class="btn btn-sm btn-primary" @click="decide(a, 'approved')">اعتماد</button><button type="button" class="btn btn-sm btn-danger" @click="decide(a, 'rejected')">رفض</button></template>
                            <button v-if="a.status === 'approved' && (abilities.sponsorships || abilities.delivery)" type="button" class="btn btn-sm btn-secondary" @click="deliver(a)">تسجيل التسليم</button></li>
                    </ul>
                    <table v-else class="data-table min-w-[720px]">
                        <thead><tr><th scope="col">التاريخ</th><th scope="col">المستفيد</th><th scope="col">البيان</th><th scope="col">المستلم</th><th scope="col">المرجع</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                        <tbody><tr v-for="b in rows.data" :key="b.public_id" :class="b.kind === 'reversal' || b.reversed ? 'text-slate-400' : ''"><td>{{ b.delivered_on }}</td>
                            <td><Link v-if="b.orphan_public_id" :href="`/orphans/registry/orphans/${b.orphan_public_id}?tab=aid`" class="text-blue-700 hover:underline">{{ b.orphan_name }}</Link><span v-else class="font-mono" dir="ltr">{{ b.household_reference }} (أسرة)</span></td>
                            <td>{{ b.kind === 'reversal' ? `تصحيح: ${b.reason}` : b.description }} <StatusBadge v-if="b.reversed" label="مُصحّح" tone="muted" /></td><td>{{ b.recipient_name }}</td><td class="font-mono text-xs" dir="ltr">{{ b.fulfillment_ref }}</td>
                            <td><button v-if="b.kind === 'delivery' && !b.reversed && abilities.assign" type="button" class="btn btn-sm btn-secondary" @click="reverse(b)">تصحيح</button></td></tr></tbody>
                    </table>
                </div>
                <footer v-if="rows.last_page > 1" class="flex flex-wrap gap-1 border-t border-slate-200 p-4"><template v-for="(l, i) in rows.links" :key="i"><Link v-if="l.url" :href="l.url" :class="['rounded-lg border px-3 py-2 text-sm', l.active ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-200']" v-html="l.label" /></template></footer>
            </section>
        </div>
    </AppLayout>
</template>
