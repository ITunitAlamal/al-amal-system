<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import RecordContext from '../../Components/Orphans/RecordContext.vue';
import SectionSelect from '../../Components/Orphans/SectionSelect.vue';
import { recordResultsUrl, recordWithResults } from '../../Support/orphanNavigation.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';
import GeographicAddress from '../../Components/GeographicAddress.vue';
import RuleFields from '../../Components/Orphans/RuleFields.vue';
import DocumentsChecklist from '../../Components/Orphans/DocumentsChecklist.vue';
import DynamicFields from '../../Components/DynamicFields.vue';
import { childFields, emptyAddress, guardianFields, householdFields, parentFields } from '../../Support/orphanFields';

// One family file: household facts, addresses, guardian, parents (shared by siblings), children, documents and requests.
const props = defineProps({
    guardianChoices: { type: Array, default: () => [] },
    household: { type: Object, required: true },
    guardian: { type: Object, default: null },
    parents: { type: Array, default: () => [] },
    children: { type: Array, default: () => [] },
    checklist: { type: Array, default: () => [] },
    applications: { type: Array, default: () => [] },
    changes: { type: Array, default: () => [] },
    visits: { type: Array, default: () => [] },
    payments: { type: Array, default: () => [] },
    sponsorships: { type: Array, default: () => [] },
    assistance: { type: Array, default: () => [] },
    assistanceOptions: { type: Object, default: null },
    rules: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    researchers: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    guardianFields: { type: Array, default: () => [] },
    guardianValues: { type: Object, default: () => ({}) },
    emergencies: { type: Array, default: () => [] },
    referrals: { type: Array, default: () => [] },
    caseAlerts: { type: Object, default: () => ({}) },
    followups: { type: Array, default: () => [] },
    followupPanel: { type: Object, default: () => ({}) },
    needPanel: { type: Object, default: () => ({}) },
    waitlistPanel: { type: Object, default: () => ({}) },
});
const page = usePage();
const resultsUrl = computed(() => recordResultsUrl(page.url));
const base = `/orphans/households/${props.household.public_id}`;
const editable = computed(() => props.household.editable && props.can.records);
const section = ref('family');
const sections = [['family', 'الأسرة والسكن'], ['people', 'الوصي والوالدان'], ['children', `الأطفال (${props.children.length})`], ['documents', 'المستندات'], ['requests', 'الطلبات والزيارات'], ['support', `الكفالات والمساعدات (${props.sponsorships.length})`], ['payment', 'بيانات الصرف']];
const assistanceForm = useForm({ child_public_id: null, assistance_type_value_id: null, description: '', quantity: null, estimated_value: '', currency: null, funding_source: '', evidence_document_ids: [] });
function requestAssistance() { assistanceForm.post(`${base}/assistance`, { preserveScroll: true }); }

const familyForm = useForm({ ...props.household.fields, original_address: props.household.original_address || emptyAddress(), current_address: props.household.current_address || emptyAddress() });
const familyFields = computed({ get: () => familyForm.data(), set: value => Object.keys(value).forEach(key => { familyForm[key] = value[key]; }) });
function saveFamily() { familyForm.put(base, { preserveScroll: true }); }

const guardianForm = useForm({ name: '', identity_type_value_id: null, identity_no: '', phone: '', alt_phone: '', email: '', date_of_birth: '', sex: null, occupation: '', employer: '', guardian_authority_value_id: props.household.fields.guardian_authority_value_id, fields: { ...props.guardianValues } });

// Emergency flag (head alert only) and manual referrals with a responsible staff member.
const openEmergency = computed(() => props.emergencies.find(flag => flag.open) || null);
const emergencyHistory = computed(() => props.emergencies.filter(flag => !flag.open));
const raising = ref(false);
const emergencyForm = useForm({ reason: '', child_public_id: null });
function raiseEmergency() { emergencyForm.post(`${base}/emergency`, { preserveScroll: true, onSuccess: () => { raising.value = false; emergencyForm.reset(); } }); }
const clearForm = useForm({ note: '' });
function clearEmergency() { clearForm.post(`/orphans/emergencies/${openEmergency.value.id}/clear`, { preserveScroll: true, onSuccess: () => clearForm.reset() }); }
const referralForm = useForm({ referred_to: '', reason: '', referred_on: new Date().toISOString().slice(0, 10), responsible_user_id: null, child_public_id: null });
function recordReferral() { referralForm.post(`${base}/referrals`, { preserveScroll: true, onSuccess: () => referralForm.reset() }); }
const outcomes = ref({});
function closeReferral(referral) { router.post(`/orphans/referrals/${referral.id}/close`, { outcome: outcomes.value[referral.id] || '' }, { preserveScroll: true }); }
const childName = publicId => props.children.find(child => child.public_id === publicId)?.full_name;

// Case follow-up: one open follow-up with a due date and a responsible member; an approved visit completes it.
const openFollowup = computed(() => props.followups.find(f => f.status === 'open') || null);
const followupHistory = computed(() => props.followups.filter(f => f.status !== 'open'));
const followupForm = useForm({ due_on: '', reason: '', assigned_to: null, child_public_id: null });
function scheduleFollowup() { followupForm.post(`${base}/followups`, { preserveScroll: true, onSuccess: () => followupForm.reset() }); }
const completeForm = useForm({ outcome: '', next_due_on: '' });
function completeFollowup() { completeForm.post(`/orphans/followups/${openFollowup.value.id}/complete`, { preserveScroll: true, onSuccess: () => completeForm.reset() }); }
const followupAction = useForm({ reason: '', assigned_to: null });
function cancelFollowup() { followupAction.post(`/orphans/followups/${openFollowup.value.id}/cancel`, { preserveScroll: true, onSuccess: () => followupAction.reset() }); }
function reassignFollowup() { followupAction.post(`/orphans/followups/${openFollowup.value.id}/reassign`, { preserveScroll: true, onSuccess: () => followupAction.reset() }); }
// Assessed monthly need (compared with commitments, never with money received) and the sponsorship waiting list.
const needForm = useForm({ monthly_amount: '', currency: props.needPanel.position?.currency || 'ILS', basis: '' });
function assessNeed() { needForm.post(`${base}/need`, { preserveScroll: true, onSuccess: () => needForm.reset('monthly_amount', 'basis') }); }
const waitlistForm = useForm({ child_public_id: null, program_value_id: null, reason: '' });
function addToWaitlist() {
    waitlistForm.transform(data => ({ ...data, beneficiary_type: data.child_public_id ? 'child' : 'household' })).post(`${base}/waitlist`, { preserveScroll: true, onSuccess: () => waitlistForm.reset() });
}
function removeFromWaitlist(entry) {
    const reason = prompt('سبب الإخراج من قائمة الانتظار:');
    if (reason) router.post(`/orphans/waitlist/${entry.id}/remove`, { reason }, { preserveScroll: true });
}
const intervalForm = useForm({ months: props.followupPanel.interval ?? '' });
function saveInterval() { intervalForm.transform(data => ({ months: data.months === '' ? null : data.months })).put(`${base}/followup-interval`, { preserveScroll: true }); }
const guardianModel = computed({ get: () => guardianForm.data(), set: value => Object.keys(value).forEach(key => { guardianForm[key] = value[key]; }) });
function saveGuardian() { guardianForm.post(`${base}/guardian`, { preserveScroll: true }); }

const parentRole = ref(null);
const parentForm = useForm({ parent_id: null, link_existing: false, disability_types: [] });
const parentModel = computed({ get: () => parentForm.data(), set: value => Object.keys(value).forEach(key => { parentForm[key] = value[key]; }) });
function editParent(role, parent = null) {
    parentRole.value = role;
    parentForm.reset();
    parentForm.clearErrors();
    Object.assign(parentForm, { parent_id: parent?.id ?? null, link_existing: false, ...(parent?.fields || {}), identity_no: '' });
}
function saveParent() { parentForm.post(`${base}/parents/${parentRole.value}`, { preserveScroll: true, onSuccess: () => { parentRole.value = null; } }); }

const childEditing = ref(false);
const childForm = useForm({ child_public_id: null, sole_survivor: false, father_id: null, mother_id: null, guardian_id: null });
const childModel = computed({ get: () => childForm.data(), set: value => Object.keys(value).forEach(key => { childForm[key] = value[key]; }) });
function editChild(child = null) {
    childEditing.value = true;
    childForm.reset();
    childForm.clearErrors();
    const fathers = props.parents.filter(p => p.role === 'father');
    const mothers = props.parents.filter(p => p.role === 'mother');
    Object.assign(childForm, child ? { child_public_id: child.public_id, ...child.fields, identity_no: '' } : { father_id: fathers.length === 1 ? fathers[0].id : null, mother_id: mothers.length === 1 ? mothers[0].id : null });
}
function saveChild() { childForm.post(`${base}/children`, { preserveScroll: true, onSuccess: () => { childEditing.value = false; } }); }
// On edit the stored document number is kept when the field is left empty, so it is not marked required there.
const keepIdentity = (rules, editing) => (editing ? { ...rules, identity_no: { req: 'optional' } } : rules);
const childRules = computed(() => keepIdentity(props.rules.child || {}, Boolean(childForm.child_public_id)));
const parentRules = computed(() => keepIdentity(props.rules[parentRole.value] || {}, Boolean(parentForm.parent_id)));

const changeForm = useForm({ category: 'family', subject_type: 'household', subject_id: props.household.id, changes: { current_address: emptyAddress() }, reason: '' });
const changeModel = computed({ get: () => changeForm.changes, set: value => { changeForm.changes = value; } });
const changeFieldSet = computed(() => householdFields.filter(f => (changeForm.category === 'residence'
    ? ['displaced', 'displaced_since', 'shelter_type_value_id', 'current_tenure_value_id', 'rent_amount']
    : ['family_size', 'dependents_count', 'working_members_count', 'income_source_value_id', 'monthly_income', 'economic_status_value_id', 'needs_level_value_id', 'basic_needs']).includes(f.key)));
function requestChange() {
    const keys = changeFieldSet.value.map(f => f.key).concat(changeForm.category === 'residence' ? ['current_address'] : []);
    const picked = Object.fromEntries(Object.entries(changeForm.changes).filter(([key, value]) => keys.includes(key) && value !== null && value !== '' && !(key === 'current_address' && !value.governorate_value_id && !value.address_other)));
    changeForm.transform(data => ({ ...data, changes: picked })).post('/orphans/changes', { preserveScroll: true });
}

const visitForm = useForm({ reason: '', scheduled_on: '', visit_type_value_id: null, researcher_id: null, orphan_child_id: null });
function planVisit() { visitForm.post(`${base}/visits`, { preserveScroll: true }); }
const paymentForm = useForm({ channel_value_id: null, institution_value_id: null, branch: '', account_holder: '', account_identifier: '' });
function savePayment() { paymentForm.post(`${base}/payments`, { preserveScroll: true, onSuccess: () => paymentForm.reset() }); }
function openApplication() { router.post(`${base}/application`); }
const tone = { draft: 'warning', registered: 'info', active: 'success', inactive: 'muted', closed: 'muted', applicant: 'info', pending_activation: 'warning', suspended: 'muted' };
</script>

<template>
    <AppLayout :title="`ملف الأسرة ${household.reference_no}`">
        <div class="space-y-4">
            <RecordContext :results-url="resultsUrl" label="ملف أسرة" />
            <PageHeader eyebrow="ملف أسرة" :title="`${household.fields.family_name} — ${household.reference_no}`" :description="household.editable ? 'الملف قيد الإعداد: يُعدّل مباشرة حتى رفعه للقرار.' : 'الملف معتمد: أي تعديل يتم بطلب تعديل يقرره المدير التنفيذي.'">
                <template #meta><div class="mt-2"><StatusBadge :label="household.status_label" :tone="tone[household.status]" /></div></template>
                <button v-if="can.records && children.some(c => ['applicant', 'pending_activation'].includes(c.status))" type="button" class="btn btn-primary btn-sm" @click="openApplication">طلب اعتماد الأطفال غير المفعّلين</button>
            </PageHeader>

            <section v-if="openEmergency" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert" aria-labelledby="emergency-title">
                <h2 id="emergency-title" class="font-bold">حالة طوارئ قائمة</h2>
                <p class="mt-1 whitespace-pre-line">{{ openEmergency.reason }}</p>
                <p class="mt-1 text-xs">وسمها {{ openEmergency.raised_by }} — <span dir="ltr">{{ openEmergency.raised_at.slice(0, 16) }}</span><span v-if="openEmergency.child"> — الطفل: {{ childName(openEmergency.child) }}</span>
                    — {{ openEmergency.alerted ? 'نُبّه رئيس القسم' : 'لم يُنبَّه أحد: لا يوجد رئيس قسم معيّن' }}</p>
                <p class="mt-1 text-xs">تنبيه فقط: لا يبدأ الوسم أي صرف أو موافقة، والمساعدة العاجلة تمر بمسارها المعتاد.</p>
                <form v-if="caseAlerts.clear" class="mt-3 flex flex-wrap gap-2" @submit.prevent="clearEmergency">
                    <input v-model="clearForm.note" class="field min-w-0 flex-1" maxlength="2000" required placeholder="ما انتهت إليه الحالة (يُحفظ في سجل الملف)" aria-label="ملاحظة رفع الوسم">
                    <button class="btn btn-secondary btn-sm" :disabled="clearForm.processing">رفع وسم الطوارئ</button>
                </form>
                <p v-for="(m, k) in clearForm.errors" :key="k" class="field-error">{{ m }}</p>
            </section>
            <div v-else-if="caseAlerts.available && caseAlerts.raise" class="space-y-2">
                <button v-if="!raising" type="button" class="btn btn-secondary btn-sm" @click="raising = true">وسم الملف بحالة طوارئ</button>
                <form v-else class="card space-y-3 border-red-200" data-error-scope @submit.prevent="raiseEmergency">
                    <h2 class="card-title">وسم حالة طوارئ</h2>
                    <p class="text-xs text-slate-500">يُنبَّه رئيس القسم فقط. لا ينشئ الوسم مسار موافقات ولا صرفًا.<span v-if="!caseAlerts.head_appointed" class="font-semibold text-amber-700"> لا يوجد رئيس قسم معيّن الآن، فلن يصل التنبيه لأحد.</span></p>
                    <FormErrorSummary :errors="emergencyForm.errors" />
                    <label class="block text-sm"><span class="field-label">يخص طفلًا محددًا (اختياري)</span>
                        <select v-model="emergencyForm.child_public_id" class="field"><option :value="null">الأسرة كاملة</option><option v-for="c in children" :key="c.public_id" :value="c.public_id">{{ c.full_name }}</option></select></label>
                    <label class="block text-sm"><span class="field-label">سبب الطوارئ *</span><textarea v-model="emergencyForm.reason" class="field" rows="3" minlength="10" maxlength="2000" required></textarea></label>
                    <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="emergencyForm.processing">وسم وتنبيه رئيس القسم</button><button type="button" class="btn btn-secondary btn-sm" @click="raising = false">إلغاء</button></div>
                </form>
            </div>

            <SectionSelect v-model="section" :options="sections" label="أقسام ملف الأسرة" />

            <template v-if="section === 'family'">
                <form class="card space-y-4" data-error-scope @submit.prevent="saveFamily">
                    <h2 class="card-title">بيانات الأسرة والسكن</h2>
                    <FormErrorSummary :errors="familyForm.errors" />
                    <RuleFields v-model="familyFields" :fields="householdFields" :rules="rules.household" :options="options" :errors="familyForm.errors" :disabled="!editable" />
                    <div class="grid gap-4 lg:grid-cols-2">
                        <fieldset class="space-y-2"><legend class="font-semibold">العنوان الأصلي</legend>
                            <GeographicAddress v-if="editable" :model-value="familyForm.original_address" prefix="original_address." :errors="familyForm.errors" @update:model-value="familyForm.original_address = { ...familyForm.original_address, ...$event }" />
                            <p v-else class="text-sm">{{ [household.original_address?.governorate, household.original_address?.city, household.original_address?.neighborhood].filter(Boolean).join(' — ') || '—' }}</p></fieldset>
                        <fieldset class="space-y-2"><legend class="font-semibold">السكن/النزوح الحالي</legend>
                            <GeographicAddress v-if="editable" :model-value="familyForm.current_address" prefix="current_address." :errors="familyForm.errors" @update:model-value="familyForm.current_address = { ...familyForm.current_address, ...$event }" />
                            <p v-else class="text-sm">{{ [household.current_address?.governorate, household.current_address?.city, household.current_address?.neighborhood].filter(Boolean).join(' — ') || '—' }} <span class="text-xs text-slate-500">منذ {{ household.current_address?.valid_from }}</span></p></fieldset>
                    </div>
                    <details v-if="household.address_history.length" class="text-sm"><summary class="cursor-pointer font-semibold">سجل العناوين السابقة (مسار النزوح)</summary>
                        <ul class="mt-2 list-inside list-disc"><li v-for="(a, i) in household.address_history" :key="i">{{ a.kind === 'current' ? 'سكن' : 'أصلي' }}: {{ [a.governorate, a.city, a.neighborhood].filter(Boolean).join(' — ') }} ({{ a.valid_from }} ← {{ a.valid_to }})</li></ul></details>
                    <button v-if="editable" class="btn btn-primary" :disabled="familyForm.processing">حفظ</button>
                </form>
                <form v-if="!household.editable && can.records" class="card space-y-3" data-error-scope @submit.prevent="requestChange">
                    <h2 class="card-title">طلب تعديل بيانات الأسرة</h2>
                    <p class="text-xs text-slate-500">يُحفظ ما قبل التعديل وما بعده، ولا يُطبّق إلا بعد توصية رئيس القسم وقرار المدير التنفيذي.</p>
                    <FormErrorSummary :errors="changeForm.errors" />
                    <select v-model="changeForm.category" class="field max-w-xs"><option value="family">الوضع الأسري والاقتصادي</option><option value="residence">السكن والنزوح</option></select>
                    <RuleFields v-model="changeModel" :fields="changeFieldSet" :rules="{}" :options="options" :errors="changeForm.errors" prefix="changes." />
                    <GeographicAddress v-if="changeForm.category === 'residence'" :model-value="changeForm.changes.current_address" prefix="current_address." optional :errors="changeForm.errors"
                        @update:model-value="changeForm.changes = { ...changeForm.changes, current_address: { ...changeForm.changes.current_address, ...$event } }" />
                    <textarea v-model="changeForm.reason" class="field" rows="2" maxlength="2000" required placeholder="سبب التعديل والمصدر (زيارة، مستند، إفادة الوصي)"></textarea>
                    <button class="btn btn-primary btn-sm" :disabled="changeForm.processing">تقديم طلب التعديل</button>
                </form>
            </template>

            <template v-else-if="section === 'people'">
                <section class="card space-y-3">
                    <h2 class="card-title">الوصي / المستلم المفوض</h2>
                    <dl v-if="guardian" class="grid gap-2 text-sm sm:grid-cols-3">
                        <div><dt class="text-slate-500">الاسم</dt><dd class="font-semibold">{{ guardian.name }} ({{ guardian.reference_no }})</dd></div>
                        <div><dt class="text-slate-500">الوثيقة</dt><dd dir="ltr" class="text-right">{{ guardian.identity }}</dd></div>
                        <div><dt class="text-slate-500">الجوال</dt><dd dir="ltr" class="text-right">{{ guardian.phone }}</dd></div>
                        <div><dt class="text-slate-500">صفة التمثيل المدعاة</dt><dd>{{ guardian.authority || 'لم تحدد' }}</dd></div>
                        <div><dt class="text-slate-500">بوابة الوصي</dt><dd>{{ guardian.portal_active ? 'مفعّلة' : 'غير مفعّلة' }}</dd></div>
                        <div v-for="item in guardian.extra || []" :key="item.code"><dt class="text-slate-500">{{ item.label }}</dt><dd>{{ item.value ?? '—' }}</dd></div>
                    </dl>
                    <form v-if="editable" class="space-y-3 border-t border-slate-100 pt-3" data-error-scope @submit.prevent="saveGuardian">
                        <p class="text-sm font-semibold">{{ guardian ? 'استبدال الوصي أو تحديث بياناته' : 'تسجيل الوصي' }}</p>
                        <FormErrorSummary :errors="guardianForm.errors" />
                        <RuleFields v-model="guardianModel" :fields="guardianFields" :rules="rules.guardian" :options="options" :errors="guardianForm.errors" />
                        <label class="block text-sm"><span class="field-label">صفة التمثيل القانوني المدعاة</span>
                            <select v-model="guardianForm.guardian_authority_value_id" class="field"><option :value="null">لا يوجد سند</option><option v-for="o in options.guardian_authority_kinds" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <fieldset v-if="guardianFields.length" class="space-y-2 border-t border-slate-100 pt-3"><legend class="text-sm font-semibold">بيانات إضافية للوصي</legend>
                            <DynamicFields v-model="guardianForm.fields" :fields="guardianFields" :errors="guardianForm.errors" :context="guardianValues" /></fieldset>
                        <button class="btn btn-primary btn-sm" :disabled="guardianForm.processing">حفظ الوصي</button>
                    </form>
                </section>
                <section class="card space-y-3">
                    <div class="flex items-center justify-between"><h2 class="card-title">الوالدان</h2>
                        <div v-if="editable" class="flex gap-2"><button type="button" class="btn btn-secondary btn-sm" @click="editParent('father')">إضافة أب</button><button type="button" class="btn btn-secondary btn-sm" @click="editParent('mother')">إضافة أم</button></div></div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="p in parents" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><span class="font-semibold">{{ p.role === 'father' ? 'الأب' : 'الأم' }}:</span> {{ p.full_name }} <span dir="ltr" class="text-slate-500">{{ p.identity }}</span> — {{ p.vital || 'الحالة غير محددة' }}<span v-if="p.death_date"> ({{ p.death_date }})</span></span>
                            <button v-if="editable" type="button" class="btn btn-secondary btn-sm" @click="editParent(p.role, p)">تعديل</button>
                        </li>
                        <li v-if="!parents.length" class="py-2 text-slate-500">لم يُسجّل الوالدان بعد.</li>
                    </ul>
                    <form v-if="parentRole" class="space-y-3 border-t border-slate-100 pt-3" data-error-scope @submit.prevent="saveParent">
                        <FormErrorSummary :errors="parentForm.errors" />
                        <RuleFields v-model="parentModel" :fields="parentFields(parentRole)" :rules="parentRules" :options="options" :errors="parentForm.errors" />
                        <label class="flex items-center gap-2 text-sm"><input v-model="parentForm.link_existing" type="checkbox"> إن كان صاحب الوثيقة مسجلًا مسبقًا فاربطه بهذا الملف (دون إنشاء سجل مكرر)</label>
                        <p v-if="parentForm.parent_id" class="text-xs text-slate-500">اترك رقم الوثيقة فارغًا للإبقاء على المسجل.</p>
                        <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="parentForm.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="parentRole = null">إلغاء</button></div>
                    </form>
                </section>
            </template>

            <template v-else-if="section === 'children'">
                <section class="card space-y-3">
                    <div class="flex items-center justify-between"><h2 class="card-title">الأطفال</h2><button v-if="editable" type="button" class="btn btn-secondary btn-sm" @click="editChild()">إضافة طفل</button></div>
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الاسم</th><th>رقم الملف</th><th>العمر</th><th>التصنيف</th><th>التحقق</th><th>الحالة</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="c in children" :key="c.public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="recordWithResults(`/orphans/children/${c.public_id}`, resultsUrl)" class="font-semibold text-blue-700 hover:underline">{{ c.full_name }}</Link></td>
                                <td>{{ c.file_no || '—' }}</td><td>{{ c.age }}</td><td>{{ c.class }}</td><td>{{ c.verification }}</td>
                                <td><StatusBadge :label="c.status_label" :tone="tone[c.status]" /></td>
                                <td><button v-if="editable && !['active', 'suspended', 'closed'].includes(c.status)" type="button" class="btn btn-secondary btn-sm" @click="editChild(c)">تعديل</button></td>
                            </tr>
                            <tr v-if="!children.length"><td colspan="7" class="py-3 text-slate-500">لم يُضف أطفال بعد.</td></tr>
                        </tbody>
                    </table>
                    <form v-if="childEditing" class="space-y-3 border-t border-slate-100 pt-3" data-error-scope @submit.prevent="saveChild">
                        <FormErrorSummary :errors="childForm.errors" />
                        <RuleFields v-model="childModel" :fields="childFields" :rules="childRules" :options="options" :errors="childForm.errors" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label v-if="!childForm.child_public_id" class="block text-sm"><span class="field-label">الوصي الشرعي لهذا الطفل <span class="text-red-600">*</span></span>
                                <select v-model="childForm.guardian_id" required class="field"><option :value="null">اختر الوصي</option><option v-for="g in guardianChoices || []" :key="g.id" :value="g.id">{{ g.name }} — {{ g.reference_no || 'بلا رقم' }}</option></select>
                                <span class="text-xs text-slate-500">يُسجَّل رابط الوصاية لهذا الطفل «بانتظار التحقق». لوصي آخر استخدم «إضافة يتيم» في «الأيتام والأوصياء».</span></label>
                            <label class="block text-sm"><span class="field-label">الأب</span><select v-model="childForm.father_id" class="field"><option :value="null">اختر</option><option v-for="p in parents.filter(p => p.role === 'father')" :key="p.id" :value="p.id">{{ p.full_name }}</option></select></label>
                            <label class="block text-sm"><span class="field-label">الأم</span><select v-model="childForm.mother_id" class="field"><option :value="null">اختر</option><option v-for="p in parents.filter(p => p.role === 'mother')" :key="p.id" :value="p.id">{{ p.full_name }}</option></select></label>
                        </div>
                        <p class="text-xs text-slate-500">لا تُطلب الجنسية في نموذج الطفل الجديد. اترك رقم الوثيقة فارغًا عند التعديل للإبقاء على المسجل.</p>
                        <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="childForm.processing">حفظ الطفل</button><button type="button" class="btn btn-secondary btn-sm" @click="childEditing = false">إلغاء</button></div>
                    </form>
                </section>
            </template>

            <section v-else-if="section === 'documents'" class="card space-y-3">
                <h2 class="card-title">المستندات المطلوبة</h2>
                <DocumentsChecklist :items="checklist" :can-upload="can.records" :can-verify="can.verify" />
            </section>

            <template v-else-if="section === 'requests'">
                <section class="card space-y-2 text-sm">
                    <h2 class="card-title">طلبات الاعتماد</h2>
                    <p v-for="a in applications" :key="a.id"><Link :href="`/orphans/applications/${a.id}`" class="font-semibold text-blue-700 hover:underline">{{ a.reference_no }}</Link> — {{ a.status_label }}</p>
                    <p v-if="!applications.length" class="text-slate-500">لا توجد طلبات.</p>
                    <h2 class="card-title pt-2">طلبات التعديل</h2>
                    <p v-for="c in changes" :key="c.id"><Link :href="`/orphans/changes/${c.id}`" class="font-semibold text-blue-700 hover:underline">{{ c.reference_no }}</Link> — {{ c.category_label }} — {{ c.status_label }}</p>
                    <p v-if="!changes.length" class="text-slate-500">لا توجد طلبات تعديل.</p>
                    <h2 class="card-title pt-2">الزيارات الاجتماعية</h2>
                    <p v-for="v in visits" :key="v.id"><Link :href="`/orphans/visits/${v.id}`" class="font-semibold text-blue-700 hover:underline">{{ v.reference_no }}</Link> — {{ v.scheduled_on }} — {{ v.status_label }}</p>
                    <p v-if="!visits.length" class="text-slate-500">لا توجد زيارات.</p>
                </section>
                <section v-if="caseAlerts.available" class="card space-y-3 text-sm">
                    <h2 class="card-title">الإحالات اليدوية</h2>
                    <p class="text-xs text-slate-500">تدوين لإحالة الأسرة أو الطفل إلى جهة أخرى مع مسؤول يتابعها ويسجل نتيجتها. لا تُرسل أي بيانات خارج النظام.</p>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="r in referrals" :key="r.id" class="space-y-1 py-2">
                            <p class="flex flex-wrap items-center gap-2"><strong>إلى: {{ r.referred_to }}</strong><StatusBadge :label="r.status_label" :tone="r.status === 'open' ? 'warning' : 'success'" />
                                <span class="text-xs text-slate-500"><span dir="ltr">{{ r.referred_on }}</span> — المسؤول: {{ r.responsible }}<span v-if="r.child"> — الطفل: {{ childName(r.child) }}</span></span></p>
                            <p class="text-slate-700">{{ r.reason }}</p>
                            <p v-if="r.outcome" class="text-xs text-emerald-800">النتيجة: {{ r.outcome }} ({{ r.closed_by }})</p>
                            <form v-if="r.can_close" class="flex flex-wrap gap-2" @submit.prevent="closeReferral(r)">
                                <input v-model="outcomes[r.id]" class="field min-w-0 flex-1" maxlength="2000" minlength="5" required placeholder="نتيجة الإحالة" :aria-label="`نتيجة الإحالة إلى ${r.referred_to}`">
                                <button class="btn btn-secondary btn-sm">تسجيل النتيجة وإغلاق</button>
                            </form>
                        </li>
                        <li v-if="!referrals.length" class="py-2 text-slate-500">لا توجد إحالات.</li>
                    </ul>
                    <form v-if="caseAlerts.refer" class="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2" data-error-scope @submit.prevent="recordReferral">
                        <FormErrorSummary :errors="referralForm.errors" class="sm:col-span-2" />
                        <label class="block"><span class="field-label">الجهة المحال إليها *</span><input v-model="referralForm.referred_to" class="field" maxlength="200" required></label>
                        <label class="block"><span class="field-label">تاريخ الإحالة *</span><input v-model="referralForm.referred_on" type="date" class="field" required></label>
                        <label class="block"><span class="field-label">المسؤول عن المتابعة *</span>
                            <select v-model="referralForm.responsible_user_id" class="field" required><option :value="null">اختر</option><option v-for="u in caseAlerts.responsible" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                        <label class="block"><span class="field-label">يخص طفلًا محددًا (اختياري)</span>
                            <select v-model="referralForm.child_public_id" class="field"><option :value="null">الأسرة كاملة</option><option v-for="c in children" :key="c.public_id" :value="c.public_id">{{ c.full_name }}</option></select></label>
                        <label class="block sm:col-span-2"><span class="field-label">سبب الإحالة *</span><textarea v-model="referralForm.reason" class="field" rows="2" minlength="10" maxlength="2000" required></textarea></label>
                        <div><button class="btn btn-primary btn-sm" :disabled="referralForm.processing">تسجيل الإحالة</button></div>
                    </form>
                    <template v-if="emergencyHistory.length">
                        <h2 class="card-title pt-2">سجل حالات الطوارئ</h2>
                        <ul class="divide-y divide-slate-100 text-xs">
                            <li v-for="f in emergencyHistory" :key="f.id" class="py-2"><span dir="ltr">{{ f.raised_at.slice(0, 10) }}</span> — {{ f.reason }} — رُفع الوسم: {{ f.clear_note }} ({{ f.cleared_by }})</li>
                        </ul>
                    </template>
                </section>
                <section v-if="followupPanel.available" class="card space-y-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="card-title">متابعة الحالة</h2>
                        <span class="text-xs text-slate-500">دورية المراجعة: {{ followupPanel.interval_label || 'غير مقررة' }}<span v-if="followupPanel.interval === null && followupPanel.interval_label"> (الافتراضية للقسم)</span></span>
                    </div>
                    <div v-if="openFollowup" class="space-y-2 rounded-lg border p-3" :class="openFollowup.overdue ? 'border-red-300 bg-red-50' : 'border-slate-200'">
                        <p class="flex flex-wrap items-center gap-2"><strong>الموعد: <span dir="ltr">{{ openFollowup.due_on }}</span></strong>
                            <StatusBadge v-if="openFollowup.overdue" label="متأخرة" tone="danger" /><StatusBadge v-if="openFollowup.escalated" label="صُعّدت لرئيس القسم" tone="danger" />
                            <span class="text-xs text-slate-500">المسؤول: {{ openFollowup.assignee }} — {{ openFollowup.source_label }}<span v-if="openFollowup.child"> — الطفل: {{ openFollowup.child }}</span></span></p>
                        <p class="text-slate-700">{{ openFollowup.reason }}</p>
                        <p class="text-xs text-slate-500">اعتماد تقرير زيارة لهذه الأسرة يُنجز المتابعة تلقائيًا. للمتابعة دون زيارة (اتصال أو مراجعة مكتبية) سجّل نتيجتها هنا.</p>
                        <form v-if="openFollowup.can_complete" class="grid gap-2 sm:grid-cols-3" data-error-scope @submit.prevent="completeFollowup">
                            <FormErrorSummary :errors="completeForm.errors" class="sm:col-span-3" />
                            <label class="block sm:col-span-2"><span class="field-label">نتيجة المتابعة *</span><textarea v-model="completeForm.outcome" class="field" rows="2" minlength="10" maxlength="2000" required></textarea></label>
                            <label class="block"><span class="field-label">موعد المتابعة التالية</span><input v-model="completeForm.next_due_on" type="date" class="field"><span class="text-xs text-slate-500">فارغ = حسب دورية المراجعة</span></label>
                            <div class="sm:col-span-3"><button class="btn btn-primary btn-sm" :disabled="completeForm.processing">تسجيل النتيجة وإنجاز المتابعة</button></div>
                        </form>
                        <div v-if="openFollowup.can_manage" class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-2">
                            <label class="block"><span class="field-label">إعادة الإسناد</span>
                                <select v-model="followupAction.assigned_to" class="field"><option :value="null">اختر</option><option v-for="u in followupPanel.assignees" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                            <button type="button" class="btn btn-secondary btn-sm" :disabled="!followupAction.assigned_to || followupAction.processing" @click="reassignFollowup">إسناد</button>
                            <input v-model="followupAction.reason" class="field min-w-0 flex-1" maxlength="2000" placeholder="سبب الإلغاء" aria-label="سبب إلغاء المتابعة">
                            <button type="button" class="btn btn-secondary btn-sm" :disabled="followupAction.reason.trim().length < 5 || followupAction.processing" @click="cancelFollowup">إلغاء المتابعة</button>
                            <p v-for="(m, k) in followupAction.errors" :key="k" class="field-error w-full">{{ m }}</p>
                        </div>
                    </div>
                    <form v-else-if="followupPanel.schedule" class="grid gap-3 sm:grid-cols-2" data-error-scope @submit.prevent="scheduleFollowup">
                        <FormErrorSummary :errors="followupForm.errors" class="sm:col-span-2" />
                        <label class="block"><span class="field-label">موعد المتابعة *</span><input v-model="followupForm.due_on" type="date" class="field" required></label>
                        <label v-if="followupPanel.head" class="block"><span class="field-label">المسؤول عن المتابعة</span>
                            <select v-model="followupForm.assigned_to" class="field"><option :value="null">أنا</option><option v-for="u in followupPanel.assignees" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                        <label class="block"><span class="field-label">يخص طفلًا محددًا (اختياري)</span>
                            <select v-model="followupForm.child_public_id" class="field"><option :value="null">الأسرة كاملة</option><option v-for="c in children" :key="c.public_id" :value="c.public_id">{{ c.full_name }}</option></select></label>
                        <label class="block sm:col-span-2"><span class="field-label">سبب المتابعة *</span><textarea v-model="followupForm.reason" class="field" rows="2" minlength="5" maxlength="1000" required></textarea></label>
                        <div><button class="btn btn-primary btn-sm" :disabled="followupForm.processing">جدولة المتابعة</button></div>
                    </form>
                    <p v-else class="text-slate-500">لا توجد متابعة مفتوحة.</p>
                    <form v-if="followupPanel.head" class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3" @submit.prevent="saveInterval">
                        <label class="block"><span class="field-label">دورية مراجعة هذه الحالة (شهر)</span>
                            <input v-model.number="intervalForm.months" type="number" min="1" max="24" class="field w-28" :placeholder="followupPanel.default_interval ? String(followupPanel.default_interval) : 'الافتراضية'"></label>
                        <button class="btn btn-secondary btn-sm" :disabled="intervalForm.processing">حفظ الدورية</button>
                        <span class="text-xs text-slate-500">للحالات الحساسة دورية أقصر. فارغ = دورية القسم الافتراضية.</span>
                        <p v-for="(m, k) in intervalForm.errors" :key="k" class="field-error w-full">{{ m }}</p>
                    </form>
                    <template v-if="followupHistory.length">
                        <h3 class="font-semibold">سجل المتابعات</h3>
                        <ul class="divide-y divide-slate-100 text-xs">
                            <li v-for="f in followupHistory" :key="f.id" class="py-2"><span dir="ltr">{{ f.due_on }}</span> — {{ f.status_label }}: {{ f.outcome }}
                                <Link v-if="f.completed_visit_id" :href="`/orphans/visits/${f.completed_visit_id}`" class="text-blue-700 hover:underline">{{ f.completed_visit }}</Link>
                                <span class="text-slate-500"> ({{ f.closed_by }}، <span dir="ltr">{{ f.closed_at }}</span>)</span></li>
                        </ul>
                    </template>
                </section>
                <form v-if="can.visits" class="card space-y-3" @submit.prevent="planVisit">
                    <h2 class="card-title">جدولة زيارة اجتماعية</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm"><span class="field-label">موعد الزيارة *</span><input v-model="visitForm.scheduled_on" type="date" class="field" required></label>
                        <label class="block text-sm"><span class="field-label">نوع الزيارة</span><select v-model="visitForm.visit_type_value_id" class="field"><option :value="null">—</option><option v-for="o in options.social_visit_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label v-if="researchers.length" class="block text-sm"><span class="field-label">الباحث المكلف</span><select v-model="visitForm.researcher_id" class="field"><option :value="null">أنا</option><option v-for="r in researchers" :key="r.id" :value="r.id">{{ r.name }}</option></select></label>
                        <label class="block text-sm sm:col-span-2"><span class="field-label">سبب الزيارة *</span><textarea v-model="visitForm.reason" class="field" rows="2" maxlength="1000" required></textarea></label>
                    </div>
                    <p v-for="(m, k) in visitForm.errors" :key="k" class="field-error">{{ m }}</p>
                    <button class="btn btn-primary btn-sm" :disabled="visitForm.processing">جدولة</button>
                </form>
            </template>

            <template v-else-if="section === 'support'">
                <section class="card space-y-2 text-sm">
                    <h2 class="card-title">كفالات الأسرة وأطفالها</h2>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="s in sponsorships" :key="s.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><Link :href="`/orphans/sponsorships/${s.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ s.sponsorship_no }}</Link> — {{ s.beneficiary }} — {{ s.amount }} {{ s.currency }} ({{ s.frequency }})</span>
                            <span class="flex gap-1"><StatusBadge :label="s.status_label" :tone="s.status === 'active' ? 'success' : s.status === 'paused' ? 'muted' : 'info'" /><StatusBadge v-if="s.regularity && s.status === 'active'" :label="s.regularity" tone="muted" /></span>
                        </li>
                        <li v-if="!sponsorships.length" class="py-2 text-slate-500">لا توجد كفالات لهذه الأسرة.</li>
                    </ul>
                    <h2 class="card-title pt-2">طلبات المساعدة</h2>
                    <p v-for="a in assistance" :key="a.public_id"><Link :href="`/orphans/assistance/${a.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ a.reference_no }}</Link> — {{ a.type || 'مساعدة' }} — {{ a.status_label }}</p>
                    <p v-if="!assistance.length" class="text-slate-500">لا توجد طلبات مساعدة.</p>
                </section>
                <section v-if="needPanel.available" class="card space-y-3 text-sm">
                    <h2 class="card-title">الاحتياج الشهري والتعهدات</h2>
                    <template v-if="needPanel.position?.need">
                        <div class="grid gap-2 sm:grid-cols-4">
                            <p><span class="text-slate-500">الاحتياج المقدّر:</span> <strong dir="ltr">{{ needPanel.position.need }} {{ needPanel.position.currency }}</strong></p>
                            <p><span class="text-slate-500">التعهد الشهري للكفلاء:</span> <strong dir="ltr">{{ needPanel.position.commitment }}</strong></p>
                            <p><span class="text-slate-500">الفجوة:</span> <strong dir="ltr" :class="{ 'text-red-700': Number(needPanel.position.gap) > 0 }">{{ needPanel.position.gap }}</strong></p>
                            <p><span class="text-slate-500">التغطية:</span> <strong>{{ needPanel.position.coverage }}٪</strong></p>
                        </div>
                        <p v-if="needPanel.position.other_commitments.length" class="text-xs text-slate-500">تعهدات بعملة أخرى (لا تُحوَّل): {{ needPanel.position.other_commitments.join('، ') }}</p>
                        <p class="text-xs text-slate-500">التعهد هو جزء المصاريف من الكفالات النشطة محسوبًا شهريًا، وليس مالًا مقبوضًا.
                            <span v-if="Object.keys(needPanel.position.received_90_days).length">المقبوض فعليًا خلال 90 يومًا حسب سندات الحسابات (للعلم):
                                <span v-for="(amount, currency) in needPanel.position.received_90_days" :key="currency" dir="ltr" class="me-2">{{ amount }} {{ currency }}</span></span></p>
                    </template>
                    <p v-else class="text-slate-500">لم يُقدّر احتياج هذه الأسرة بعد.</p>
                    <form v-if="needPanel.can" class="grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-4" data-error-scope @submit.prevent="assessNeed">
                        <FormErrorSummary :errors="needForm.errors" class="sm:col-span-4" />
                        <label class="block"><span class="field-label">الاحتياج الشهري *</span><input v-model="needForm.monthly_amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">العملة *</span><select v-model="needForm.currency" class="field" required><option v-for="c in needPanel.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="block sm:col-span-2"><span class="field-label">أساس التقدير *</span><input v-model="needForm.basis" class="field" minlength="10" maxlength="2000" required placeholder="مثال: إيجار 600 + غذاء 900 + علاج شهري 200"></label>
                        <div class="sm:col-span-4"><button class="btn btn-secondary btn-sm" :disabled="needForm.processing">{{ needPanel.position?.need ? 'تحديث التقدير' : 'تسجيل التقدير' }}</button></div>
                    </form>
                    <details v-if="needPanel.history.length > 1" class="text-xs"><summary class="cursor-pointer text-slate-600">التقديرات السابقة</summary>
                        <ul class="mt-1 space-y-1"><li v-for="n in needPanel.history.filter(h => !h.is_current)" :key="n.id"><span dir="ltr">{{ n.assessed_on }}</span> — <span dir="ltr">{{ n.monthly_amount }} {{ n.currency }}</span> — {{ n.basis }} ({{ n.assessor }})</li></ul>
                    </details>
                </section>
                <section v-if="waitlistPanel.available" class="card space-y-3 text-sm">
                    <h2 class="card-title">قائمة انتظار الكفالة</h2>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="w in waitlistPanel.entries" :key="w.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{{ w.beneficiary }} — برنامج {{ w.program }} — منذ <span dir="ltr">{{ w.since }}</span><span v-if="w.rank"> — الترتيب {{ w.rank }}</span>
                                <span v-if="w.close_reason" class="text-xs text-slate-500"> ({{ w.close_reason }})</span></span>
                            <span class="flex items-center gap-2"><StatusBadge :label="w.status_label" :tone="w.status === 'sponsored' ? 'success' : w.status === 'removed' ? 'muted' : 'info'" />
                                <button v-if="waitlistPanel.head && w.status === 'waiting'" type="button" class="btn btn-secondary btn-sm" @click="removeFromWaitlist(w)">إخراج</button></span>
                        </li>
                        <li v-if="!waitlistPanel.entries.length" class="py-2 text-slate-500">الأسرة ليست في قائمة الانتظار.</li>
                    </ul>
                    <form v-if="waitlistPanel.can" class="grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-3" data-error-scope @submit.prevent="addToWaitlist">
                        <FormErrorSummary :errors="waitlistForm.errors" class="sm:col-span-3" />
                        <label class="block"><span class="field-label">المستفيد *</span>
                            <select v-model="waitlistForm.child_public_id" class="field"><option :value="null">الأسرة كاملة</option><option v-for="c in children.filter(c => c.status === 'active')" :key="c.public_id" :value="c.public_id">{{ c.full_name }}</option></select></label>
                        <label class="block"><span class="field-label">برنامج الكفالة</span>
                            <select v-model="waitlistForm.program_value_id" class="field"><option :value="null">عام</option><option v-for="p in waitlistPanel.programs" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
                        <label class="block sm:col-span-3"><span class="field-label">سبب الإدراج *</span><input v-model="waitlistForm.reason" class="field" minlength="10" maxlength="1000" required></label>
                        <div class="sm:col-span-3"><button class="btn btn-secondary btn-sm" :disabled="waitlistForm.processing">إدراج في قائمة الانتظار</button>
                            <span class="ms-2 text-xs text-slate-500">الترتيب بالتقييم الاجتماعي المعتمد ثم أقدمية الانتظار، والترشيح بقرار بشري.</span></div>
                    </form>
                </section>
                <form v-if="assistanceOptions" class="card space-y-3" data-error-scope @submit.prevent="requestAssistance">
                    <h2 class="card-title">طلب مساعدة</h2>
                    <p class="text-xs text-slate-500">يُرفع لرئيس القسم ثم المدير التنفيذي، وينفذه قسم الحسابات/المخازن، ثم تؤكد أنت التسليم بإثبات. الاعتماد وحده ليس تسليمًا.</p>
                    <FormErrorSummary :errors="assistanceForm.errors" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm"><span class="field-label">نوع المساعدة</span><select v-model="assistanceForm.assistance_type_value_id" class="field"><option :value="null">—</option><option v-for="o in assistanceOptions.assistance_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block text-sm"><span class="field-label">لطفل محدد (اختياري)</span><select v-model="assistanceForm.child_public_id" class="field"><option :value="null">الأسرة كاملة</option><option v-for="c in children" :key="c.public_id" :value="c.public_id">{{ c.full_name }}</option></select></label>
                        <label class="block text-sm sm:col-span-2"><span class="field-label">الحاجة ومبرراتها *</span><textarea v-model="assistanceForm.description" class="field" rows="3" minlength="10" maxlength="2000" required></textarea></label>
                        <label class="block text-sm"><span class="field-label">الكمية</span><input v-model="assistanceForm.quantity" type="number" min="0.01" step="0.01" class="field"></label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block text-sm"><span class="field-label">القيمة التقديرية</span><input v-model="assistanceForm.estimated_value" inputmode="decimal" dir="ltr" class="field"></label>
                            <label class="block text-sm"><span class="field-label">العملة</span><select v-model="assistanceForm.currency" class="field" :required="Boolean(assistanceForm.estimated_value)"><option :value="null">—</option><option v-for="c in assistanceOptions.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        </div>
                        <label class="block text-sm sm:col-span-2"><span class="field-label">مصدر التمويل المقترح</span><input v-model="assistanceForm.funding_source" class="field" maxlength="200"></label>
                        <fieldset v-if="assistanceOptions.documents.length" class="text-sm sm:col-span-2"><legend class="field-label">المستندات الداعمة من ملف الأسرة</legend>
                            <label v-for="d in assistanceOptions.documents" :key="d.id" class="me-4 inline-flex items-center gap-1"><input v-model="assistanceForm.evidence_document_ids" type="checkbox" :value="d.id"> {{ d.label }}</label>
                        </fieldset>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="assistanceForm.processing">تقديم الطلب</button>
                </form>
            </template>

            <template v-else>
                <section class="card space-y-3">
                    <h2 class="card-title">بيانات صرف المستحقات</h2>
                    <p class="text-xs text-slate-500">تُحفظ مشفرة وتُعرض مقنعة. لا يُصرف على بيانات جديدة قبل اعتماد قسم الحسابات، ولا يبدأ تعديلها أي صرف تلقائيًا.</p>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="p in payments" :key="p.id" class="flex flex-wrap justify-between gap-2 py-2"><span>{{ p.channel }} {{ p.institution ? `— ${p.institution}` : '' }} <span dir="ltr">{{ p.account }}</span></span><span>{{ p.status_label }}<span v-if="p.review_note" class="text-xs text-slate-500"> — {{ p.review_note }}</span></span></li>
                        <li v-if="!payments.length" class="py-2 text-slate-500">لا توجد بيانات صرف.</li>
                    </ul>
                    <form v-if="can.sponsorships" class="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2" @submit.prevent="savePayment">
                        <label class="block text-sm"><span class="field-label">القناة *</span><select v-model="paymentForm.channel_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.channel_value_id" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block text-sm"><span class="field-label">البنك/المؤسسة</span><select v-model="paymentForm.institution_value_id" class="field"><option :value="null">—</option><option v-for="o in options.institution_value_id" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block text-sm"><span class="field-label">الفرع</span><input v-model="paymentForm.branch" class="field" maxlength="120"></label>
                        <label class="block text-sm"><span class="field-label">اسم صاحب الحساب *</span><input v-model="paymentForm.account_holder" class="field" maxlength="150" required></label>
                        <label class="block text-sm sm:col-span-2"><span class="field-label">رقم الحساب/المحفظة *</span><input v-model="paymentForm.account_identifier" dir="ltr" class="field" maxlength="40" required></label>
                        <p v-for="(m, k) in paymentForm.errors" :key="k" class="field-error sm:col-span-2">{{ m }}</p>
                        <div><button class="btn btn-primary btn-sm" :disabled="paymentForm.processing">إرسال لاعتماد الحسابات</button></div>
                    </form>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
