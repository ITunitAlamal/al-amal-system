<script setup>
import { computed, ref, watch } from 'vue';
import SectionSelect from '../../Components/Orphans/SectionSelect.vue';
import ListToolbar from '../../Components/Orphans/ListToolbar.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// Sponsors, sponsorships, one-off donations and assistance of the department. Money is recorded by Accounts only.
const props = defineProps({
    aidSwitch: { type: Boolean, default: false },
    tab: { type: String, default: 'sponsorships' },
    filters: { type: Object, default: () => ({}) },
    sponsorships: { type: Array, default: () => [] },
    sponsors: { type: Array, default: () => [] },
    contributions: { type: Array, default: () => [] },
    assistance: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    options: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    programs: { type: Object, default: null },
});
const current = ref(props.tab);
const waitingCount = computed(() => (props.programs?.waitlist || []).reduce((n, p) => n + p.entries.filter(e => e.status === 'waiting').length, 0));
const renewalsDue = computed(() => (props.programs?.agreements || []).filter(a => a.renewal_due).length);
const tabs = computed(() => [['sponsorships', `الكفالات (${props.sponsorships.length})`], ['sponsors', `الكفلاء (${props.sponsors.length})`],
    ['contributions', `التبرعات لمرة واحدة (${props.contributions.length})`], ['assistance', `المساعدات (${props.assistance.length})`],
    ...(props.programs ? [['waitlist', `قائمة الانتظار (${waitingCount.value})`], ['agreements', `الاتفاقيات (${props.programs.agreements.length}${renewalsDue.value ? ` · تجديد ${renewalsDue.value}` : ''})`],
        ['funds', `الصناديق (${props.programs.funds.length})`], ['gap', 'فجوة الاحتياج']] : [])]);
const tone = { draft: 'warning', pending_approval: 'info', active: 'success', paused: 'muted', ended: 'muted', rejected: 'danger', cancelled: 'muted', superseded: 'muted' };
const statusLabels = { draft: 'مسودة', pending_approval: 'بانتظار الاعتماد', active: 'نشطة', paused: 'موقوفة', ended: 'منتهية', rejected: 'مرفوضة', cancelled: 'ملغاة', superseded: 'مستبدلة' };

const search = ref(props.filters.q || '');
const status = ref(props.filters.status || '');
function filter() { router.get('/orphans/sponsorships', { q: search.value || undefined, status: status.value || undefined, tab: 'sponsorships' }, { preserveState: true, replace: true }); }
watch(() => props.filters, value => { search.value = value.q || ''; status.value = value.status || ''; });
const activeFilters = computed(() => [
    ...(props.filters.q ? [{ key: 'q', label: `البحث: ${props.filters.q}` }] : []),
    ...(props.filters.status ? [{ key: 'status', label: `الحالة: ${statusLabels[props.filters.status]}` }] : []),
]);
function removeFilter(key) {
    router.get('/orphans/sponsorships', { q: key === 'q' ? undefined : props.filters.q, status: key === 'status' ? undefined : props.filters.status, tab: 'sponsorships' }, { preserveState: true });
}
function clearFilters() { router.get('/orphans/sponsorships', { tab: 'sponsorships' }, { preserveState: true }); }

const creating = ref(false);
const form = useForm({ sponsor_id: null, beneficiary_type: 'child', beneficiary_reference: '', type_value_id: null, amount: '', currency: 'ILS', frequency_value_id: null,
    purpose_value_id: null, savings_amount: '', starts_on: '', ends_on: '', conditions: '', source: '', agreement_id: null, skip_reason: '' });
const purpose = computed(() => (props.options?.sponsorship_purposes || []).find(p => p.id === form.purpose_value_id)?.flags || {});
const split = computed(() => purpose.value.expenses && purpose.value.savings);
function create() { form.post('/orphans/sponsorships', { preserveScroll: true }); }
const sponsorAgreements = computed(() => (props.programs?.options?.agreements || []).filter(a => a.sponsor_id === form.sponsor_id
    && (a.kind === 'family' ? form.beneficiary_type === 'household' : a.kind === 'fund' || form.beneficiary_type === 'child')));
// Nominating from the waiting list opens the new sponsorship form with the beneficiary and program filled in.
const nominee = ref(null);
function nominate(entry) {
    nominee.value = entry;
    Object.assign(form, { beneficiary_type: entry.beneficiary_type, beneficiary_reference: entry.beneficiary_reference, type_value_id: entry.program_value_id, skip_reason: '' });
    current.value = 'sponsorships';
    creating.value = true;
}

// Agreements, templates and funds.
const kindLabels = computed(() => props.programs?.kinds || {});
const agreementForm = useForm({ kind: 'group', sponsor_id: null, fund_id: null, template_id: null, starts_on: '', ends_on: '', renewal_alert_days: 30, notes: '' });
const creatingAgreement = ref(false);
function createAgreement() { agreementForm.post('/orphans/agreements', { preserveScroll: true }); }
const templateForm = useForm({ id: null, code: '', title: '', kind: 'group', body: '', notes: '' });
const editingTemplate = ref(false);
function editTemplate(t = null) {
    editingTemplate.value = true;
    templateForm.clearErrors();
    Object.assign(templateForm, t ? { id: t.id, code: t.code, title: t.title, kind: t.kind, body: t.body, notes: t.notes || '' } : { id: null, code: '', title: '', kind: 'group', body: '', notes: '' });
}
function saveTemplate() {
    const done = { preserveScroll: true, onSuccess: () => { editingTemplate.value = false; } };
    templateForm.id ? templateForm.put(`/orphans/agreement-templates/${templateForm.id}`, done) : templateForm.post('/orphans/agreement-templates', done);
}
function templateAction(t, action) {
    if (action === 'activate' && !confirm('اعتماد نص القالب؟ لا يُعدّل بعد الاعتماد إلا بإصدار جديد.')) return;
    router.post(`/orphans/agreement-templates/${t.id}/${action}`, {}, { preserveScroll: true });
}
const fundForm = useForm({ name: '', currency: 'ILS', description: '' });
function createFund() { fundForm.post('/orphans/funds', { preserveScroll: true, onSuccess: () => fundForm.reset() }); }
function closeFund(f) {
    const reason = prompt('سبب إغلاق الصندوق:');
    if (reason) router.post(`/orphans/funds/${f.id}/close`, { reason }, { preserveScroll: true });
}
const agreementTone = { draft: 'warning', signed: 'success', renewed: 'muted', ended: 'muted', cancelled: 'muted' };

const sponsorForm = useForm({ id: null, kind: 'person', name: '', country: '', phone: '', email: '', address: '', preferences: '', confidential: false, status: 'active' });
const editingSponsor = ref(false);
function editSponsor(s = null) {
    editingSponsor.value = true;
    sponsorForm.clearErrors();
    Object.assign(sponsorForm, s ? { id: s.id, kind: s.kind, name: s.name, country: s.country || '', phone: s.phone || '', email: s.email || '', address: s.address || '',
        preferences: s.preferences || '', confidential: s.confidential, status: s.status } : { id: null, kind: 'person', name: '', country: '', phone: '', email: '', address: '', preferences: '', confidential: false, status: 'active' });
}
function saveSponsor() {
    const done = { preserveScroll: true, onSuccess: () => { editingSponsor.value = false; } };
    sponsorForm.id ? sponsorForm.put(`/orphans/sponsors/${sponsorForm.id}`, done) : sponsorForm.post('/orphans/sponsors', done);
}

const donation = useForm({ sponsor_id: null, donor_name: '', donor_contact: '', amount: '', currency: 'ILS', earmark_type: 'general', earmark_reference: '', notes: '' });
function pledge() { donation.post('/orphans/contributions', { preserveScroll: true, onSuccess: () => donation.reset() }); }
function cancelPledge(c) {
    const reason = prompt('سبب إلغاء التعهد:');
    if (reason) router.post(`/orphans/contributions/${c.id}/cancel`, { reason }, { preserveScroll: true });
}
const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <AppLayout title="الكفالات والتبرعات والمساعدات">
        <div class="space-y-4">
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="الكفالات والكفلاء والتبرعات" description="تُعدّ الكفالة في القسم، يوصي بها رئيسه ويعتمدها المدير التنفيذي. التحصيل والصرف والادخار تسجلها الحسابات من سنداتها؛ لا يُعد أي مبلغ مدفوعًا قبل ذلك.">
                <Link href="/orphans" class="btn btn-secondary btn-sm">لوحة القسم</Link>
            </PageHeader>
            <nav v-if="aidSwitch" class="flex flex-wrap gap-2" aria-label="الكفالات والمساعدات">
                <span class="btn btn-primary btn-sm" aria-current="page">الكفالات</span>
                <Link href="/relief/campaigns" class="btn btn-secondary btn-sm">الحملات الإغاثية</Link>
            </nav>

            <div class="grid gap-3 sm:grid-cols-5">
                <div class="card"><p class="text-xs text-slate-500">كفالات نشطة</p><p class="text-2xl font-bold">{{ counts.active }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">بانتظار الاعتماد</p><p class="text-2xl font-bold">{{ counts.pending }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">غير منتظمة (متأخرة)</p><p class="text-2xl font-bold">{{ counts.irregular }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">أطفال مكفولون</p><p class="text-2xl font-bold">{{ counts.sponsored_children }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">أطفال نشطون غير مكفولين</p><p class="text-2xl font-bold">{{ counts.unsponsored_children }}</p></div>
            </div>

            <SectionSelect v-model="current" :options="tabs" label="الكفالات والمساعدات" />

            <template v-if="current === 'sponsorships'">
                <div class="flex flex-wrap items-center gap-2">
                    <ListToolbar v-model="search" class="w-full" label="رقم الكفالة أو ملف الطفل أو الأسرة أو الكافل" :maxlength="40" :filters="activeFilters" @apply="filter" @remove="removeFilter" @clear="clearFilters">
                        <template #quick><label><span class="field-label">الحالة</span><select v-model="status" class="field"><option value="">القائمة (دون الملغاة والمستبدلة)</option><option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option></select></label></template>
                    </ListToolbar>
                    <button v-if="can.manage" type="button" class="btn btn-primary btn-sm" @click="creating = !creating">كفالة جديدة</button>
                </div>

                <form v-if="creating && options" class="card space-y-3" data-error-scope @submit.prevent="create">
                    <h2 class="card-title">مسودة كفالة جديدة</h2>
                    <FormErrorSummary :errors="form.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block sm:col-span-2"><span class="field-label">الكافل *</span><select v-model="form.sponsor_id" class="field" required><option :value="null">اختر — أو سجّل كافلًا من تبويب الكفلاء</option><option v-for="s in options.sponsors" :key="s.id" :value="s.id">{{ s.label }}</option></select></label>
                        <label class="block"><span class="field-label">نوع الكفالة</span><select v-model="form.type_value_id" class="field"><option :value="null">—</option><option v-for="o in options.sponsorship_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <fieldset class="block"><legend class="field-label">المستفيد *</legend>
                            <label class="me-3"><input v-model="form.beneficiary_type" type="radio" value="child"> طفل</label><label><input v-model="form.beneficiary_type" type="radio" value="household"> أسرة</label></fieldset>
                        <label class="block sm:col-span-2"><span class="field-label">{{ form.beneficiary_type === 'child' ? 'رقم ملف الطفل (ملف نشط) *' : 'رقم ملف الأسرة (FAM-…) *' }}</span><input v-model="form.beneficiary_reference" dir="ltr" class="field" maxlength="30" required></label>
                        <label class="block"><span class="field-label">المبلغ لكل دورة *</span><input v-model="form.amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">العملة *</span><select v-model="form.currency" class="field" required><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="block"><span class="field-label">الدورية *</span><select v-model="form.frequency_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.sponsorship_frequencies" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block"><span class="field-label">الغرض *</span><select v-model="form.purpose_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.sponsorship_purposes" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label v-if="split" class="block"><span class="field-label">منه للادخار *</span><input v-model="form.savings_amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <p v-if="split && form.amount" class="self-end text-xs text-slate-500">للمصاريف: {{ money(Number(form.amount) - Number(form.savings_amount || 0)) }}</p>
                        <label class="block"><span class="field-label">تاريخ البداية *</span><input v-model="form.starts_on" type="date" class="field" required></label>
                        <label class="block"><span class="field-label">تاريخ النهاية (اختياري)</span><input v-model="form.ends_on" type="date" class="field"></label>
                        <label class="block"><span class="field-label">المصدر/المرجع</span><input v-model="form.source" class="field" maxlength="200"></label>
                        <label class="block sm:col-span-3"><span class="field-label">الشروط</span><textarea v-model="form.conditions" class="field" rows="2" maxlength="2000"></textarea></label>
                        <label v-if="sponsorAgreements.length" class="block sm:col-span-2"><span class="field-label">ضمن اتفاقية الكافل (اختياري)</span>
                            <select v-model="form.agreement_id" class="field"><option :value="null">بلا اتفاقية</option><option v-for="a in sponsorAgreements" :key="a.id" :value="a.id">{{ a.agreement_no }} — {{ kindLabels[a.kind] }}</option></select></label>
                    </div>
                    <div v-if="nominee" class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
                        ترشيح من قائمة الانتظار: {{ nominee.beneficiary }} — الترتيب {{ nominee.rank }} في برنامج {{ nominee.program }}.
                        <label v-if="nominee.ahead_waiting > 0" class="mt-2 block"><span class="field-label">يسبقه {{ nominee.ahead_waiting }} بالانتظار؛ سبب تقديمه *</span>
                            <textarea v-model="form.skip_reason" class="field" rows="2" minlength="10" maxlength="1000" required></textarea></label>
                    </div>
                    <label v-else-if="form.errors.skip_reason" class="block text-sm"><span class="field-label">سبب تقديم هذا المستفيد على من يسبقه في قائمة الانتظار *</span>
                        <textarea v-model="form.skip_reason" class="field" rows="2" minlength="10" maxlength="1000" required></textarea></label>
                    <button class="btn btn-primary btn-sm" :disabled="form.processing">حفظ المسودة</button>
                </form>

                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الكفالة</th><th>الكافل</th><th>المستفيد</th><th>المبلغ</th><th>الدورية</th><th>الحالة</th></tr></thead>
                        <tbody>
                            <tr v-for="s in sponsorships" :key="s.public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="`/orphans/sponsorships/${s.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ s.sponsorship_no }}</Link></td>
                                <td>{{ s.sponsor }}</td><td>{{ s.beneficiary }}</td><td>{{ money(s.amount) }} <span dir="ltr" class="text-xs">{{ s.currency }}</span></td><td>{{ s.frequency }}</td>
                                <td class="space-x-1"><StatusBadge :label="s.status_label" :tone="tone[s.status]" /><StatusBadge v-if="s.status === 'active' && s.regularity" :label="s.regularity" tone="muted" /></td>
                            </tr>
                            <tr v-if="!sponsorships.length"><td colspan="6" class="py-4 text-center text-slate-500">لا توجد كفالات مطابقة.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <template v-else-if="current === 'sponsors'">
                <div v-if="can.manage"><button type="button" class="btn btn-primary btn-sm" @click="editSponsor()">كافل جديد</button></div>
                <form v-if="editingSponsor" class="card space-y-3" data-error-scope @submit.prevent="saveSponsor">
                    <h2 class="card-title">{{ sponsorForm.id ? 'تعديل بيانات الكافل' : 'كافل جديد' }}</h2>
                    <FormErrorSummary :errors="sponsorForm.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block"><span class="field-label">النوع *</span><select v-model="sponsorForm.kind" class="field"><option v-for="(label, key) in options.kinds" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="block sm:col-span-2"><span class="field-label">الاسم *</span><input v-model="sponsorForm.name" class="field" maxlength="200" required></label>
                        <label class="block"><span class="field-label">الدولة</span><input v-model="sponsorForm.country" class="field" maxlength="100"></label>
                        <label class="block"><span class="field-label">الهاتف</span><input v-model="sponsorForm.phone" dir="ltr" class="field" maxlength="20"></label>
                        <label class="block"><span class="field-label">البريد</span><input v-model="sponsorForm.email" type="email" dir="ltr" class="field" maxlength="150"></label>
                        <label class="block sm:col-span-3"><span class="field-label">العنوان</span><input v-model="sponsorForm.address" class="field" maxlength="500"></label>
                        <label class="block sm:col-span-3"><span class="field-label">التفضيلات المعتمدة</span><textarea v-model="sponsorForm.preferences" class="field" rows="2" maxlength="1000"></textarea></label>
                        <label class="flex items-center gap-2"><input v-model="sponsorForm.confidential" type="checkbox"> كافل سري (لا يظهر اسمه خارج القسم والحسابات والمدير التنفيذي)</label>
                        <label v-if="sponsorForm.id" class="block"><span class="field-label">الحالة</span><select v-model="sponsorForm.status" class="field"><option value="active">نشط</option><option value="inactive">غير نشط</option></select></label>
                    </div>
                    <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="sponsorForm.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="editingSponsor = false">إلغاء</button></div>
                </form>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الرقم</th><th>الاسم</th><th>النوع</th><th>الدولة</th><th>كفالات نشطة</th><th>الحالة</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="s in sponsors" :key="s.id" class="border-t border-slate-100">
                                <td class="py-2" dir="ltr">{{ s.donor_no }}</td><td><Link :href="`/orphans/sponsors/${s.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ s.name }}</Link><span v-if="s.confidential" class="ms-1 text-xs text-amber-700">(سري)</span></td><td>{{ s.kind_label }}</td><td>{{ s.country || '—' }}</td>
                                <td>{{ s.active_count }}</td><td><StatusBadge :status="s.status" /></td>
                                <td><button v-if="can.manage" type="button" class="btn btn-secondary btn-sm" @click="editSponsor(s)">تعديل</button></td>
                            </tr>
                            <tr v-if="!sponsors.length"><td colspan="7" class="py-4 text-center text-slate-500">لا يوجد كفلاء مسجلون.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <template v-else-if="current === 'contributions'">
                <form v-if="can.manage" class="card space-y-3" data-error-scope @submit.prevent="pledge">
                    <h2 class="card-title">تسجيل تبرع لمرة واحدة (تعهد)</h2>
                    <p class="text-xs text-slate-500">لا يُنشئ كفالة دورية ولا يضيف إلى ادخار أي طفل. يصبح «مقبوضًا» عندما تسجل الحسابات سند القبض.</p>
                    <FormErrorSummary :errors="donation.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block"><span class="field-label">كافل مسجل</span><select v-model="donation.sponsor_id" class="field"><option :value="null">— متبرع غير مسجل</option><option v-for="s in options.sponsors" :key="s.id" :value="s.id">{{ s.label }}</option></select></label>
                        <label v-if="!donation.sponsor_id" class="block"><span class="field-label">اسم المتبرع *</span><input v-model="donation.donor_name" class="field" maxlength="200" required></label>
                        <label v-if="!donation.sponsor_id" class="block"><span class="field-label">وسيلة التواصل</span><input v-model="donation.donor_contact" class="field" maxlength="200"></label>
                        <label class="block"><span class="field-label">المبلغ *</span><input v-model="donation.amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">العملة *</span><select v-model="donation.currency" class="field"><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="block"><span class="field-label">التخصيص *</span><select v-model="donation.earmark_type" class="field"><option v-for="(label, key) in options.earmarks" :key="key" :value="key">{{ label }}</option></select></label>
                        <label v-if="donation.earmark_type !== 'general'" class="block"><span class="field-label">رقم {{ { child: 'ملف الطفل', household: 'ملف الأسرة', campaign: 'الحملة', fund: 'الصندوق (FND-…)' }[donation.earmark_type] }} *</span><input v-model="donation.earmark_reference" dir="ltr" class="field" maxlength="30" required></label>
                        <label class="block sm:col-span-3"><span class="field-label">ملاحظات</span><input v-model="donation.notes" class="field" maxlength="1000"></label>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="donation.processing">تسجيل التعهد</button>
                </form>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">المرجع</th><th>المتبرع</th><th>المبلغ</th><th>التخصيص</th><th>الحالة</th><th>سند الحسابات</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="c in contributions" :key="c.id" class="border-t border-slate-100">
                                <td class="py-2" dir="ltr">{{ c.reference_no }}</td><td>{{ c.donor }}</td><td>{{ money(c.amount) }} <span dir="ltr" class="text-xs">{{ c.currency }}</span></td><td>{{ c.earmark }}</td>
                                <td><StatusBadge :label="c.status_label" :tone="c.status === 'collected' ? 'success' : c.status === 'pledged' ? 'info' : 'muted'" /></td>
                                <td dir="ltr">{{ c.accounting_reference || '—' }}</td>
                                <td><button v-if="c.can_cancel" type="button" class="btn btn-secondary btn-sm" @click="cancelPledge(c)">إلغاء</button></td>
                            </tr>
                            <tr v-if="!contributions.length"><td colspan="7" class="py-4 text-center text-slate-500">لا توجد تبرعات مسجلة.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <template v-else-if="current === 'waitlist' && programs">
                <p class="text-xs text-slate-500">يُدرج المستفيد من ملف الأسرة (تبويب «الكفالات والمساعدات»). الترتيب يشرح نفسه: التقييم الاجتماعي المعتمد ثم أقدمية الانتظار.
                    الترشيح قرار بشري: يفتح مسودة كفالة تمر بتوصية رئيس القسم واعتماد المدير التنفيذي، وتقديم مستفيد على من يسبقه يتطلب سببًا مكتوبًا يبقى في السجل.</p>
                <section v-for="p in programs.waitlist" :key="p.id ?? 0" class="card space-y-2 text-sm">
                    <h2 class="card-title">{{ p.name }}</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">#</th><th>المستفيد</th><th>سبب الترتيب</th><th>الانتظار</th><th>الحالة</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="e in p.entries" :key="e.id" class="border-t border-slate-100 align-top">
                                    <td class="py-2 font-semibold">{{ e.rank }}</td>
                                    <td><Link :href="`/orphans/households/${e.household_public_id}`" class="text-blue-700 hover:underline">{{ e.beneficiary }}</Link><p class="text-xs text-slate-500">{{ e.reason }}</p></td>
                                    <td class="text-xs">{{ e.score_label }}</td>
                                    <td class="text-xs">{{ e.waiting_days }} يوم (منذ <span dir="ltr">{{ e.since }}</span>)</td>
                                    <td><StatusBadge :label="e.status_label" :tone="e.status === 'nominated' ? 'warning' : 'info'" />
                                        <Link v-if="e.sponsorship_public_id" :href="`/orphans/sponsorships/${e.sponsorship_public_id}`" class="ms-1 text-xs text-blue-700 hover:underline" dir="ltr">{{ e.sponsorship_no }}</Link>
                                        <p v-if="e.skip_reason" class="text-xs text-amber-800">قُدّم على غيره: {{ e.skip_reason }}</p></td>
                                    <td><button v-if="can.manage && e.status === 'waiting'" type="button" class="btn btn-secondary btn-sm" @click="nominate(e)">ترشيح لكفالة</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <p v-if="!programs.waitlist.length" class="card text-sm text-slate-500">قائمة الانتظار فارغة.</p>
            </template>

            <template v-else-if="current === 'agreements' && programs">
                <div v-if="can.manage" class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary btn-sm" @click="creatingAgreement = !creatingAgreement">اتفاقية جديدة</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="editTemplate()">قالب اتفاقية جديد</button>
                </div>
                <form v-if="creatingAgreement && programs.options" class="card space-y-3" data-error-scope @submit.prevent="createAgreement">
                    <h2 class="card-title">اتفاقية كفالة جديدة</h2>
                    <FormErrorSummary :errors="agreementForm.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block"><span class="field-label">النوع *</span><select v-model="agreementForm.kind" class="field"><option v-for="(label, key) in kindLabels" :key="key" :value="key">{{ label }}</option></select></label>
                        <label v-if="agreementForm.kind !== 'fund'" class="block sm:col-span-2"><span class="field-label">الكافل *</span>
                            <select v-model="agreementForm.sponsor_id" class="field" required><option :value="null">اختر</option><option v-for="s in options.sponsors" :key="s.id" :value="s.id">{{ s.label }}</option></select></label>
                        <label v-else class="block sm:col-span-2"><span class="field-label">الصندوق *</span>
                            <select v-model="agreementForm.fund_id" class="field" required><option :value="null">اختر</option><option v-for="f in programs.options.funds" :key="f.id" :value="f.id">{{ f.fund_no }} — {{ f.name }}</option></select></label>
                        <label class="block sm:col-span-2"><span class="field-label">القالب المعتمد</span>
                            <select v-model="agreementForm.template_id" class="field"><option :value="null">يُختار لاحقًا</option><option v-for="t in programs.options.templates.filter(t => t.kind === agreementForm.kind)" :key="t.id" :value="t.id">{{ t.title }} (إصدار {{ t.version }})</option></select></label>
                        <label class="block"><span class="field-label">التنبيه قبل الانتهاء (يوم)</span><input v-model.number="agreementForm.renewal_alert_days" type="number" min="1" max="180" class="field"></label>
                        <label class="block"><span class="field-label">تاريخ البداية *</span><input v-model="agreementForm.starts_on" type="date" class="field" required></label>
                        <label class="block"><span class="field-label">تاريخ النهاية</span><input v-model="agreementForm.ends_on" type="date" class="field"></label>
                        <label class="block sm:col-span-3"><span class="field-label">ملاحظات</span><input v-model="agreementForm.notes" class="field" maxlength="2000"></label>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="agreementForm.processing">إنشاء الاتفاقية</button>
                </form>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الاتفاقية</th><th>الكافل</th><th>النوع</th><th>الكفالات</th><th>الفترة</th><th>الحالة</th></tr></thead>
                        <tbody>
                            <tr v-for="a in programs.agreements" :key="a.public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="`/orphans/agreements/${a.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ a.agreement_no }}</Link></td>
                                <td>{{ a.sponsor }}</td><td>{{ a.kind_label }}</td><td>{{ a.active_lines }} نشطة / {{ a.lines }}</td>
                                <td><span dir="ltr">{{ a.starts_on }}</span> – <span dir="ltr">{{ a.ends_on || 'مفتوحة' }}</span></td>
                                <td class="space-x-1"><StatusBadge :label="a.status_label" :tone="agreementTone[a.status]" /><StatusBadge v-if="a.renewal_due" label="حان التجديد" tone="danger" /></td>
                            </tr>
                            <tr v-if="!programs.agreements.length"><td colspan="6" class="py-4 text-center text-slate-500">لا توجد اتفاقيات.</td></tr>
                        </tbody>
                    </table>
                </section>
                <form v-if="editingTemplate" class="card space-y-3" data-error-scope @submit.prevent="saveTemplate">
                    <h2 class="card-title">{{ templateForm.id ? 'تعديل مسودة القالب' : 'قالب اتفاقية جديد' }}</h2>
                    <FormErrorSummary :errors="templateForm.errors" />
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <label class="block"><span class="field-label">رمز القالب *</span><input v-model="templateForm.code" dir="ltr" class="field" maxlength="40" required placeholder="group_monthly"></label>
                        <label class="block"><span class="field-label">النوع *</span><select v-model="templateForm.kind" class="field"><option v-for="(label, key) in kindLabels" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="block"><span class="field-label">العنوان *</span><input v-model="templateForm.title" class="field" maxlength="200" required></label>
                        <label class="block sm:col-span-3"><span class="field-label">نص الاتفاقية *</span><textarea v-model="templateForm.body" class="field font-mono" rows="12" maxlength="20000" required></textarea></label>
                        <p class="text-xs text-slate-500 sm:col-span-3">الحقول المتاحة: <span v-for="(label, key) in programs.placeholders" :key="key" class="me-2 whitespace-nowrap"><code dir="ltr">{{ key }}</code> {{ label }}</span></p>
                        <label class="block sm:col-span-3"><span class="field-label">ملاحظات للمراجع</span><input v-model="templateForm.notes" class="field" maxlength="1000"></label>
                    </div>
                    <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="templateForm.processing">حفظ المسودة</button><button type="button" class="btn btn-secondary btn-sm" @click="editingTemplate = false">إغلاق</button></div>
                </form>
                <section class="card space-y-2 text-sm">
                    <h2 class="card-title">قوالب الاتفاقيات</h2>
                    <p class="text-xs text-slate-500">يُعدّ القالب في القسم ويعتمد المدير التنفيذي نصه. القالب المعتمد لا يُعدّل؛ التغيير بإصدار جديد، والاتفاقية الموقعة تحتفظ بنصها كما وُقّع.</p>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="t in programs.templates" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><strong>{{ t.title }}</strong> <span class="text-xs text-slate-500" dir="ltr">{{ t.code }} v{{ t.version }}</span> — {{ t.kind_label }}<span v-if="t.activated_by" class="text-xs text-slate-500"> — اعتمده {{ t.activated_by }}</span></span>
                            <span class="flex flex-wrap items-center gap-2"><StatusBadge :label="t.status_label" :tone="t.status === 'active' ? 'success' : t.status === 'draft' ? 'warning' : 'muted'" />
                                <button v-if="can.manage && t.status === 'draft'" type="button" class="btn btn-secondary btn-sm" @click="editTemplate(t)">تعديل</button>
                                <button v-if="can.executive && t.status === 'draft'" type="button" class="btn btn-primary btn-sm" @click="templateAction(t, 'activate')">اعتماد النص</button>
                                <button v-if="can.manage && t.status !== 'draft'" type="button" class="btn btn-secondary btn-sm" @click="templateAction(t, 'version')">إصدار جديد</button>
                                <button v-if="can.executive && t.status === 'active'" type="button" class="btn btn-secondary btn-sm" @click="templateAction(t, 'retire')">إيقاف</button></span>
                        </li>
                        <li v-if="!programs.templates.length" class="py-2 text-slate-500">لا توجد قوالب. أعدّ القالب بنص الجمعية المعتمد.</li>
                    </ul>
                </section>
            </template>

            <template v-else-if="current === 'funds' && programs">
                <p class="text-xs text-slate-500">الصندوق المجمع يتلقى تبرعات عدة كفلاء (تبرع لمرة واحدة بتخصيص «لصندوق كفالة» ورقمه)، ويكفل أيتامًا بكفالات تُعتمد وتُحصّل كأي كفالة.
                    كل الأرقام من سندات الحسابات؛ التعهد لا يُعد مالًا مقبوضًا.</p>
                <form v-if="can.head" class="card grid gap-3 text-sm sm:grid-cols-4" data-error-scope @submit.prevent="createFund">
                    <FormErrorSummary :errors="fundForm.errors" class="sm:col-span-4" />
                    <label class="block sm:col-span-2"><span class="field-label">اسم الصندوق *</span><input v-model="fundForm.name" class="field" maxlength="180" required></label>
                    <label class="block"><span class="field-label">العملة *</span><select v-model="fundForm.currency" class="field"><option v-for="c in options?.currencies || []" :key="c" :value="c">{{ c }}</option></select></label>
                    <div class="self-end"><button class="btn btn-primary btn-sm" :disabled="fundForm.processing">إنشاء صندوق</button></div>
                    <label class="block sm:col-span-4"><span class="field-label">الوصف</span><input v-model="fundForm.description" class="field" maxlength="2000"></label>
                </form>
                <section v-for="f in programs.funds" :key="f.id" class="card space-y-2 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="card-title"><span dir="ltr">{{ f.fund_no }}</span> — {{ f.name }}</h2>
                        <span class="flex items-center gap-2"><StatusBadge :label="f.status === 'active' ? 'قائم' : 'مغلق'" :tone="f.status === 'active' ? 'success' : 'muted'" />
                            <button v-if="can.head && f.status === 'active'" type="button" class="btn btn-secondary btn-sm" @click="closeFund(f)">إغلاق</button></span>
                    </div>
                    <p v-if="f.description" class="text-slate-600">{{ f.description }}</p>
                    <p class="text-xs text-slate-500">كفالات نشطة: {{ f.sponsorships }}<span v-if="f.close_reason"> — سبب الإغلاق: {{ f.close_reason }}</span></p>
                    <table v-if="f.figures.length" class="w-full">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-1">العملة</th><th>تبرعات متعهد بها</th><th>تبرعات مقبوضة</th><th>تعهد الكفالات شهريًا</th><th>محصّل لكفالات الصندوق</th><th>الرصيد حسب سندات الحسابات</th></tr></thead>
                        <tbody><tr v-for="r in f.figures" :key="r.currency" class="border-t border-slate-100" dir="ltr"><td class="py-1">{{ r.currency }}</td><td>{{ r.pledged }}</td><td>{{ r.received }}</td><td>{{ r.monthly_commitment }}</td><td>{{ r.spent }}</td><td class="font-semibold">{{ r.balance }}</td></tr></tbody>
                    </table>
                </section>
                <p v-if="!programs.funds.length" class="card text-sm text-slate-500">لا توجد صناديق.</p>
            </template>

            <template v-else-if="current === 'gap' && programs">
                <p class="text-xs text-slate-500">الاحتياج يقدّره الباحث في ملف الأسرة. التعهد هو جزء المصاريف من الكفالات النشطة محسوبًا شهريًا بالعملة نفسها، ولا يُحوَّل بين العملات. المقبوض فعليًا يظهر للعلم ولا يدخل في الحساب.</p>
                <p v-if="programs.gap.unassessed" class="card text-sm text-amber-800">ملفات نشطة بلا تقدير احتياج: {{ programs.gap.unassessed }}</p>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الأسرة</th><th>الاحتياج الشهري</th><th>التعهد الشهري</th><th>الفجوة</th><th>التغطية</th><th>مقبوض 90 يومًا (للعلم)</th></tr></thead>
                        <tbody>
                            <tr v-for="g in programs.gap.rows" :key="g.household_public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="`/orphans/households/${g.household_public_id}`" class="font-semibold text-blue-700 hover:underline">{{ g.household }}</Link> {{ g.family_name }}</td>
                                <td dir="ltr">{{ g.need }} {{ g.currency }}</td><td dir="ltr">{{ g.commitment }}</td>
                                <td dir="ltr" :class="{ 'font-semibold text-red-700': Number(g.gap) > 0 }">{{ g.gap }}</td><td>{{ g.coverage }}٪</td>
                                <td class="text-xs" dir="ltr"><span v-for="(amount, currency) in g.received_90_days" :key="currency" class="me-2">{{ amount }} {{ currency }}</span><span v-if="g.other_commitments.length" class="block text-slate-500">+ {{ g.other_commitments.join('، ') }}</span></td>
                            </tr>
                            <tr v-if="!programs.gap.rows.length"><td colspan="6" class="py-4 text-center text-slate-500">لا توجد أسر بتقدير احتياج.</td></tr>
                        </tbody>
                    </table>
                </section>
            </template>

            <section v-else class="card space-y-2 text-sm">
                <p class="text-xs text-slate-500">يُقدَّم طلب المساعدة من ملف الأسرة (تبويب «الكفالات والمساعدات») مع مستنداته.</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="a in assistance" :key="a.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/orphans/assistance/${a.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ a.reference_no }}</Link> — أسرة {{ a.household }} — {{ a.type || 'مساعدة' }}<span v-if="a.estimated_value"> — {{ money(a.estimated_value) }} {{ a.currency }}</span></span>
                        <StatusBadge :label="a.status_label" :tone="a.status === 'delivered' ? 'success' : a.status === 'rejected' ? 'danger' : 'info'" />
                    </li>
                    <li v-if="!assistance.length" class="py-3 text-slate-500">لا توجد طلبات مساعدة.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
