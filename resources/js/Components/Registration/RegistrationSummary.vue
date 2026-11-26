<script setup>
import { computed } from 'vue';
import { ageOf, labelOf, nameOf, pairLabel, vitalOptions, ynu } from '../../Support/registration.js';

// Read-only view of a family request, used for the applicant's final check and in the reviewer's workspace.
// Every value is shown as reported; unanswered fields read «غير مذكور».
const props = defineProps({ form: Object, options: Object, canHealth: Boolean, childStatus: { type: Object, default: () => ({}) } });
const o = computed(() => props.options);
const v = value => (value === '' || value === null || value === undefined ? 'غير مذكور' : value);
const yn = value => (value ? pairLabel(ynu, value) : 'غير مذكور');
const money = (amount, currency) => (amount === '' || amount === null || amount === undefined ? 'غير مذكور' : `${amount} ${o.value.currencies.find(c => c.code === currency)?.name || currency || ''}`);
const guardianOf = child => {
    const g = props.form.guardians.find(x => x.key === child.guardian_key);
    if (!g) return 'لم يُحدد';
    return g.role === 'mother' ? `الأم (${nameOf(props.form.mother) || 'دون اسم'})` : g.role === 'applicant' ? `مقدم الطلب (${nameOf(props.form.request?.applicant) || 'دون اسم'})` : nameOf(g) || 'دون اسم';
};
const parent = relation => props.form[relation] || {};
const status = p => {
    const label = p.vital_status ? pairLabel(vitalOptions, p.vital_status) : 'غير مذكورة';
    if (p.vital_status === 'deceased') return `${label} · ${p.death?.date || (p.death?.date_unknown ? 'التاريخ غير معروف' : 'دون تاريخ')}${p.death?.certificate_no ? ` · شهادة ${p.death.certificate_no}` : ''}`;
    if (p.vital_status === 'missing') return `${label} · آخر ظهور ${p.missing?.last_seen_on || 'غير معروف'}${p.missing?.last_seen_place ? ` في ${p.missing.last_seen_place}` : ''}`;
    return label;
};
const h = computed(() => props.form.household || {});
const r = computed(() => props.form.residence || {});
const pick = (map, key) => (key ? map?.[key] || key : 'غير مذكور');
const aidFor = a => (a.child_key ? nameOf(props.form.children.find(c => c.key === a.child_key)) || 'يتيم محدد' : 'الأسرة');
const place = a => [a?.governorate, a?.city, a?.area, a?.neighborhood].filter(Boolean).join(' / ') || a?.address_details || (a?.governorate_value_id ? 'محدد بالمعرفات' : 'غير مذكور');
</script>

<template>
    <div class="space-y-4 text-sm">
        <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-navy-800">مقدم الطلب</h3>
            <p class="mt-1">{{ nameOf(form.request?.applicant) || 'غير مذكور' }} · الجوال <span dir="ltr">{{ v(form.request?.applicant?.phone) }}</span> · الصلة بالأسرة: {{ labelOf(o.kinship, form.request?.relationship_value_id) || 'غير مذكورة' }}</p>
            <p v-if="form.request?.applicant?.email || form.request?.applicant?.contact_channel">البريد: <span dir="ltr">{{ v(form.request.applicant.email) }}</span> · التواصل المفضل: {{ pick(o.contact_channels, form.request.applicant.contact_channel) }}</p>
            <p v-if="form.request?.notes" class="mt-1 text-slate-600">ملاحظات: {{ form.request.notes }}</p>
        </section>
        <section class="grid gap-4 sm:grid-cols-2">
            <div v-for="relation in ['father', 'mother']" :key="relation" class="rounded-xl border border-slate-200 p-4">
                <h3 class="font-semibold text-navy-800">{{ relation === 'father' ? 'الأب' : 'الأم' }}: {{ nameOf(parent(relation)) || 'غير مذكور' }}</h3>
                <p class="mt-1">الحالة: {{ status(parent(relation)) }}<span v-if="parent(relation).status_confidence"> · الثقة: {{ { high: 'عالية', medium: 'متوسطة', low: 'منخفضة' }[parent(relation).status_confidence] }}</span></p>
                <p v-if="parent(relation).death?.official_cause" class="text-slate-600">السبب الرسمي (القسم): {{ parent(relation).death.official_cause }}</p>
                <p>الميلاد: {{ v(parent(relation).date_of_birth) }} · التعليم: {{ labelOf(o.education_levels, parent(relation).education?.level_value_id) || 'غير مذكور' }}</p>
                <p>{{ relation === 'father' && parent(relation).vital_status === 'deceased' ? 'الدخل قبل الوفاة' : 'الدخل' }}: {{ money(parent(relation).economic?.income_amount, parent(relation).economic?.currency) }} · المهنة: {{ v(parent(relation).economic?.occupation) }}</p>
                <p v-if="parent(relation).economic?.benefits">معاش أو تعويض: {{ yn(parent(relation).economic.benefits) }}<span v-if="parent(relation).economic.benefits_note"> · {{ parent(relation).economic.benefits_note }}</span></p>
                <p v-if="relation === 'mother'">الحالة الاجتماعية: {{ labelOf(o.marital, parent(relation).marital_status_value_id) || 'غير مذكورة' }}<span v-if="parent(relation).marital_status_since"> منذ {{ parent(relation).marital_status_since }}</span> · تسكن مع الأسرة: {{ yn(parent(relation).resides_with_household) }}</p>
                <p v-if="relation === 'mother' && parent(relation).caregiving?.capacity">القدرة على الرعاية: {{ pick(o.caregiving, parent(relation).caregiving.capacity) }}<span v-if="parent(relation).caregiving.note"> · {{ parent(relation).caregiving.note }}</span></p>
            </div>
        </section>
        <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-navy-800">الأسرة والسكن</h3>
            <p class="mt-1">الأفراد: {{ v(h.declared_members) }} (ذكور {{ v(h.male_count) }}، إناث {{ v(h.female_count) }}، غير محدد {{ v(h.unknown_sex_count) }}) · الأيتام في الطلب: {{ form.children.length }}</p>
            <p>رب الأسرة: {{ labelOf(o.kinship, h.head_relation_value_id) || 'غير مذكور' }} · ذوو احتياجات الدعم: {{ v(h.special_needs_count) }}<span v-if="h.contact_phone"> · جوال الأسرة <span dir="ltr">{{ h.contact_phone }}</span></span></p>
            <p>الدخل: {{ money(h.total_income, h.currency) }} · الإيجار: {{ money(h.rent_amount, h.currency) }} · الوضع الاقتصادي (إفادة): {{ labelOf(o.economic, h.economic_status_value_id) || 'غير مذكور' }}<span v-if="h.assessed_economic_status_value_id"> · (تقييم القسم): {{ labelOf(o.economic, h.assessed_economic_status_value_id) }}</span></p>
            <p>مساعدات خارجية: {{ yn(h.external_aid) }}<span v-if="h.aid?.length"> ({{ h.aid.filter(a => a.organization).map(a => `${a.organization} — ${aidFor(a)}`).join('، ') }})</span></p>
            <p>السكن الأصلي: {{ place(r.original) }} · نازحة: {{ yn(r.displaced) }}<span v-if="r.displaced === 'yes'"> منذ {{ v(r.displaced_since) }} · مرات النزوح {{ v(r.displacement_count) }} · آخر انتقال {{ v(r.last_moved_on) }} · الحالي: {{ place(r.current) }} · المأوى: {{ labelOf(o.shelter, r.shelter_type_value_id) || 'غير مذكور' }}</span></p>
            <p>حيازة السكن: {{ labelOf(o.tenure, h.tenure_value_id) || 'غير مذكورة' }} · استقرار المأوى: {{ pick(o.shelter_stability, r.current?.stability) }} · مستضافة: {{ yn(r.hosted) }}<span v-if="r.host_note"> ({{ r.host_note }})</span><span v-if="r.accessibility_needs"> · سكن ميسّر: {{ r.accessibility_needs }}</span></p>
            <p v-if="h.members?.length">أفراد آخرون: {{ h.members.map(m => nameOf(m) || 'دون اسم').join('، ') }}</p>
        </section>
        <section class="rounded-xl border border-slate-200 p-4">
            <h3 class="font-semibold text-navy-800">الأيتام ({{ form.children.length }})</h3>
            <ol class="mt-2 space-y-2">
                <li v-for="(c, i) in form.children" :key="c.key" class="rounded-lg bg-slate-50 p-3">
                    <p class="font-semibold">{{ i + 1 }}. {{ nameOf(c) || 'دون اسم' }} <span class="font-normal text-slate-500">· {{ c.sex === 'male' ? 'ذكر' : c.sex === 'female' ? 'أنثى' : 'الجنس غير مذكور' }} · {{ c.date_of_birth ? `${c.date_of_birth} (${ageOf(c.date_of_birth)})` : 'الميلاد غير مذكور' }}</span>
                        <span v-if="childStatus[c.key]" class="ms-2 text-xs text-blue-800">{{ childStatus[c.key].label }}</span></p>
                    <p>الوصي: {{ guardianOf(c) }} · الصلة: {{ labelOf(o.kinship, c.kinship_value_id) || 'غير محددة' }}<span v-if="c.guardianship?.decision_no"> · قرار {{ c.guardianship.decision_no }}</span></p>
                    <p>التعليم: {{ labelOf(o.enrollment, c.education?.status_value_id) || 'غير مذكور' }}<span v-if="c.education?.school_name"> · {{ c.education.school_name }}</span><span v-if="c.education?.nonattendance_reason"> · السبب: {{ c.education.nonattendance_reason }}</span></p>
                    <p v-if="canHealth && c.health?.status_value_id">الصحة (مقيد): {{ labelOf(o.health, c.health.status_value_id) }}<span v-if="c.health.disability_type_value_id"> · {{ labelOf(o.disability, c.health.disability_type_value_id) }}</span></p>
                    <p>كفالة خارجية: {{ yn(c.sponsorship?.status) }}<span v-if="c.sponsorship?.organization"> · {{ c.sponsorship.organization }}</span></p>
                    <p v-if="canHealth && c.social?.loss_circumstances" class="text-slate-600">ظروف الفقد (مقيد): {{ c.social.loss_circumstances }}</p>
                    <p v-if="c.social?.researcher_notes" class="text-slate-600">ملاحظات الباحث: {{ c.social.researcher_notes }}</p>
                </li>
            </ol>
        </section>
    </div>
</template>
