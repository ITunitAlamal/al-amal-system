<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';
import GeographicAddress from '../../Components/GeographicAddress.vue';
import RuleFields from '../../Components/Orphans/RuleFields.vue';
import DocumentsChecklist from '../../Components/Orphans/DocumentsChecklist.vue';
import { childFields, emptyAddress, guardianFields, householdFields, parentFields } from '../../Support/orphanFields';

// One family file: household facts, addresses, guardian, parents (shared by siblings), children, documents and requests.
const props = defineProps({
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
});
const base = `/orphans/households/${props.household.public_id}`;
const editable = computed(() => props.household.editable && props.can.records);
const section = ref('family');
const sections = [['family', 'الأسرة والسكن'], ['people', 'الوصي والوالدان'], ['children', `الأطفال (${props.children.length})`], ['documents', 'المستندات'], ['requests', 'الطلبات والزيارات'], ['support', `الكفالات والمساعدات (${props.sponsorships.length})`], ['payment', 'بيانات الصرف']];
const assistanceForm = useForm({ child_public_id: null, assistance_type_value_id: null, description: '', quantity: null, estimated_value: '', currency: null, funding_source: '', evidence_document_ids: [] });
function requestAssistance() { assistanceForm.post(`${base}/assistance`, { preserveScroll: true }); }

const familyForm = useForm({ ...props.household.fields, original_address: props.household.original_address || emptyAddress(), current_address: props.household.current_address || emptyAddress() });
const familyFields = computed({ get: () => familyForm.data(), set: value => Object.keys(value).forEach(key => { familyForm[key] = value[key]; }) });
function saveFamily() { familyForm.put(base, { preserveScroll: true }); }

const guardianForm = useForm({ name: '', identity_type_value_id: null, identity_no: '', phone: '', alt_phone: '', email: '', date_of_birth: '', sex: null, occupation: '', employer: '', guardian_authority_value_id: props.household.fields.guardian_authority_value_id });
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
const childForm = useForm({ child_public_id: null, sole_survivor: false, father_id: null, mother_id: null });
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
            <Link href="/orphans" class="text-sm font-semibold text-blue-700 hover:underline">→ ملفات الأيتام والأسر</Link>
            <PageHeader eyebrow="ملف أسرة" :title="`${household.fields.family_name} — ${household.reference_no}`" :description="household.editable ? 'الملف قيد الإعداد: يُعدّل مباشرة حتى رفعه للقرار.' : 'الملف معتمد: أي تعديل يتم بطلب تعديل يقرره المدير التنفيذي.'">
                <template #meta><div class="mt-2"><StatusBadge :label="household.status_label" :tone="tone[household.status]" /></div></template>
                <button v-if="can.records && children.some(c => ['applicant', 'pending_activation'].includes(c.status))" type="button" class="btn btn-primary btn-sm" @click="openApplication">طلب اعتماد الأطفال غير المفعّلين</button>
            </PageHeader>

            <div class="flex flex-wrap gap-2" role="tablist">
                <button v-for="[key, label] in sections" :key="key" type="button" role="tab" :aria-selected="section === key" class="btn btn-sm" :class="section === key ? 'btn-primary' : 'btn-secondary'" @click="section = key">{{ label }}</button>
            </div>

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
                    </dl>
                    <form v-if="editable" class="space-y-3 border-t border-slate-100 pt-3" data-error-scope @submit.prevent="saveGuardian">
                        <p class="text-sm font-semibold">{{ guardian ? 'استبدال الوصي أو تحديث بياناته' : 'تسجيل الوصي' }}</p>
                        <FormErrorSummary :errors="guardianForm.errors" />
                        <RuleFields v-model="guardianModel" :fields="guardianFields" :rules="rules.guardian" :options="options" :errors="guardianForm.errors" />
                        <label class="block text-sm"><span class="field-label">صفة التمثيل القانوني المدعاة</span>
                            <select v-model="guardianForm.guardian_authority_value_id" class="field"><option :value="null">لا يوجد سند</option><option v-for="o in options.guardian_authority_kinds" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
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
                                <td class="py-2"><Link :href="`/orphans/children/${c.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ c.full_name }}</Link></td>
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
