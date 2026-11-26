<script setup>
import { computed } from 'vue';
import PersonFields from '../Registry/PersonFields.vue';
import Choice from './Choice.vue';
import FieldError from './FieldError.vue';
import Req from './Req.vue';
import { ageOf, codeOf, modalities, nameOf, regularity, severities } from '../../Support/registration.js';

// One independent child record: identity, its own guardian relationship, education, restricted health, needs and
// reported external sponsorship. A decided card is read-only.
const props = defineProps({ form: Object, index: Number, options: Object, canHealth: Boolean, portal: Boolean, status: { type: Object, default: null } });
const emit = defineEmits(['remove']);
const c = computed(() => props.form.children[props.index]);
const pre = computed(() => `children.${props.index}.`);
const e = computed(() => props.form.errors);
const locked = computed(() => ['approved', 'rejected'].includes(props.status?.status));
const eduCode = computed(() => codeOf(props.options.enrollment, c.value.education.status_value_id));
const studying = computed(() => ['enrolled', 'intermittent', 'kindergarten', 'university'].includes(eduCode.value));
const notAttending = computed(() => ['dropped_out', 'never_attended', 'not_enrolled'].includes(eduCode.value));
const university = computed(() => eduCode.value === 'university' || codeOf(props.options.stages, c.value.education.stage_value_id) === 'university');
const healthCode = computed(() => codeOf(props.options.health, c.value.health.status_value_id));
const healthDetails = computed(() => healthCode.value && !['no_known_issue', 'unknown'].includes(healthCode.value));
const guardianLabel = g => ({ applicant: `مقدم الطلب${nameOf(props.form.request.applicant) ? ` — ${nameOf(props.form.request.applicant)}` : ''}`, mother: `الأم${nameOf(props.form.mother) ? ` — ${nameOf(props.form.mother)}` : ''}` }[g.role] || nameOf(g) || 'وصي دون اسم');
</script>

<template>
    <article class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" :aria-label="`اليتيم ${index + 1}`">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-lg font-semibold text-navy-800">اليتيم {{ index + 1 }}{{ nameOf(c) ? ` — ${nameOf(c)}` : '' }}<span v-if="ageOf(c.date_of_birth)" class="ms-2 text-sm font-normal text-slate-500">({{ ageOf(c.date_of_birth) }})</span></h3>
            <span v-if="status" class="text-sm" :class="status.status === 'approved' ? 'text-green-700' : status.status === 'rejected' ? 'text-red-700' : 'text-slate-600'">{{ status.label }}<span v-if="status.note"> — {{ status.note }}</span></span>
            <button v-if="!locked" type="button" class="btn btn-sm btn-danger" @click="emit('remove')">حذف البطاقة</button>
        </div>
        <p v-if="locked" class="alert-info">صدر قرار بشأن هذا الطفل؛ البطاقة للعرض فقط.</p>
        <fieldset :disabled="locked" class="space-y-4">
            <PersonFields :model-value="c" :prefix="pre" :errors="e" :options="options" who="اليتيم" :show-contact="false" :lookup="!portal" :id-reasons="options.id_missing_reasons" />
            <p class="text-xs text-slate-500">الجنس وتاريخ الميلاد اختياريان إن لم يكونا معروفين؛ لا يُخمَّنان من الاسم ولا يُقدَّر العمر.</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">مكان الميلاد<input v-model="c.place_of_birth" :data-error-field="`${pre}place_of_birth`" class="field mt-1" maxlength="150"></label>
                <label class="text-sm">الجنسية<select v-model="c.nationality_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="n in options.nationalities" :key="n.id" :value="n.id">{{ n.name }}</option></select></label>
                <label class="text-sm">رقم القيد المدني (إن وجد)<input v-model="c.civil_registry_ref" :data-error-field="`${pre}civil_registry_ref`" class="field mt-1" dir="ltr" maxlength="60"></label>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="c.resides_with_household" type="checkbox"> يسكن مع الأسرة المسجلة في هذا الطلب</label>

            <fieldset class="space-y-3 rounded-xl border border-blue-200 bg-blue-50/40 p-4">
                <legend class="px-1 font-semibold text-navy-800">الوصي على هذا الطفل</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">الوصي<Req :k="`${pre}guardian_key`" /><select v-model="c.guardian_key" :data-error-field="`${pre}guardian_key`" class="field mt-1"><option value="">اختر من الأوصياء</option><option v-for="g in form.guardians" :key="g.key" :value="g.key">{{ guardianLabel(g) }}</option></select><FieldError :errors="e" :name="`${pre}guardian_key`" /></label>
                    <label class="text-sm">صلة الوصي بالطفل<Req :k="`${pre}kinship_value_id`" /><select v-model="c.kinship_value_id" :data-error-field="`${pre}kinship_value_id`" class="field mt-1"><option :value="null">اختر</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select><FieldError :errors="e" :name="`${pre}kinship_value_id`" /></label>
                    <label v-if="codeOf(options.kinship, c.kinship_value_id) === 'other'" class="text-sm">وضّح صلة القرابة<Req :k="`${pre}kinship_note`" /><input v-model="c.kinship_note" :data-error-field="`${pre}kinship_note`" class="field mt-1" maxlength="200"><FieldError :errors="e" :name="`${pre}kinship_note`" /></label>
                    <label class="text-sm">هل الوصاية موثقة في المحكمة؟<Req :k="`${pre}guardianship.court_status_value_id`" /><select v-model="c.guardianship.court_status_value_id" :data-error-field="`${pre}guardianship.court_status_value_id`" class="field mt-1"><option :value="null">اختر</option><option v-for="s in options.court_statuses" :key="s.id" :value="s.id">{{ s.name }}</option></select><FieldError :errors="e" :name="`${pre}guardianship.court_status_value_id`" /><span class="text-xs text-slate-500">حالة يذكرها مقدم الطلب، وليست اعتمادًا للوصاية.</span></label>
                    <label class="text-sm">نوع السند<select v-model="c.guardianship.authority_kind_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="a in options.authority" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                    <label class="text-sm">الجهة المصدرة<input v-model="c.guardianship.issuing_authority" class="field mt-1" maxlength="200" placeholder="مثال: المحكمة الشرعية"></label>
                    <label class="text-sm">رقم القرار أو الحجة<input v-model="c.guardianship.decision_no" class="field mt-1" dir="ltr" maxlength="100"></label>
                    <label class="text-sm">تاريخ السريان<input v-model="c.guardianship.starts_on" type="date" class="field mt-1"></label>
                    <label class="text-sm">تاريخ الانتهاء (إن وجد)<input v-model="c.guardianship.ends_on" type="date" :data-error-field="`${pre}guardianship.ends_on`" class="field mt-1"><FieldError :errors="e" :name="`${pre}guardianship.ends_on`" /></label>
                    <label class="text-sm sm:col-span-2">ملاحظات الإثبات<input v-model="c.guardianship.evidence_note" class="field mt-1" maxlength="1000"></label>
                </div>
                <p v-if="!form.guardians.length" class="text-sm text-gold-800">أضف الوصي أولًا في خطوة «الأوصياء».</p>
                <p class="text-xs text-slate-500">تُراجع هذه العلاقة وتُعتمد لهذا الطفل وحده، ولا تمنح وصولًا للبوابة قبل اعتمادها.</p>
            </fieldset>

            <details class="rounded-xl border border-slate-200 p-4" :open="!!c.education.status_value_id">
                <summary class="cursor-pointer font-semibold text-navy-800">التعليم</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">الحالة التعليمية<select v-model="c.education.status_value_id" :data-error-field="`${pre}education.status_value_id`" class="field mt-1"><option :value="null">غير محددة</option><option v-for="s in options.enrollment" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <template v-if="studying">
                        <label class="text-sm">المرحلة<select v-model="c.education.stage_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="s in options.stages" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                        <label class="text-sm">الصف الدراسي<Req :k="`${pre}education.grade_value_id`" /><select v-model="c.education.grade_value_id" :data-error-field="`${pre}education.grade_value_id`" class="field mt-1"><option :value="null">اختر</option><option v-for="g in options.grades" :key="g.id" :value="g.id">{{ g.name }}</option></select><FieldError :errors="e" :name="`${pre}education.grade_value_id`" /><span v-if="c.education.grade" class="text-xs text-slate-500">مُدخل سابقًا نصًا: {{ c.education.grade }}</span></label>
                        <label class="text-sm">المستوى التحصيلي<select v-model="c.education.achievement_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="a in options.achievement" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                        <label class="text-sm">اسم المدرسة أو الجامعة<input v-model="c.education.school_name" class="field mt-1" maxlength="200"></label>
                        <label class="text-sm">نوع المؤسسة<select v-model="c.education.institution_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="t in options.institution_types" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                        <label class="text-sm">نمط التعليم<select v-model="c.education.modality" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in modalities" :key="v" :value="v">{{ t }}</option></select></label>
                        <label class="text-sm">العام الدراسي<input v-model="c.education.academic_year" :data-error-field="`${pre}education.academic_year`" class="field mt-1" dir="ltr" placeholder="2026/2027" maxlength="9"><FieldError :errors="e" :name="`${pre}education.academic_year`" /></label>
                        <label class="text-sm">الانتظام في الحضور<input v-model="c.education.attendance" class="field mt-1" maxlength="300"></label>
                        <label class="text-sm">ملاحظة عن المستوى الدراسي<input v-model="c.education.performance" class="field mt-1" maxlength="100"></label>
                        <label v-if="university" class="text-sm">التخصص الجامعي<input v-model="c.education.specialization" class="field mt-1" maxlength="150"></label>
                    </template>
                    <label v-if="notAttending" class="text-sm sm:col-span-2">سبب عدم الالتحاق أو التسرب<Req :k="`${pre}education.nonattendance_reason`" /><input v-model="c.education.nonattendance_reason" :data-error-field="`${pre}education.nonattendance_reason`" class="field mt-1" maxlength="300"><FieldError :errors="e" :name="`${pre}education.nonattendance_reason`" /></label>
                    <label v-if="studying || notAttending" class="text-sm sm:col-span-3">صعوبات التعلم أو احتياجات الدعم التعليمي<textarea v-model="c.education.barriers" class="field mt-1" rows="2" maxlength="1000" /></label>
                    <label v-if="studying" class="flex items-center gap-2 text-sm"><input v-model="c.education.transport_need" type="checkbox"> يحتاج مواصلات مدرسية</label>
                    <label v-if="studying" class="text-sm sm:col-span-2">أدوات مساعدة للتعلم (إن لزم)<input v-model="c.education.assistive_need" class="field mt-1" maxlength="300"></label>
                </div>
            </details>

            <details v-if="canHealth" class="rounded-xl border border-gold-200 p-4" :open="!!c.health.status_value_id">
                <summary class="cursor-pointer font-semibold text-navy-800">الصحة والإعاقة (مقيد الاطلاع)</summary>
                <p class="mt-2 text-xs text-slate-500">لا تظهر هذه البيانات في البحث العام أو التصدير، ولا تعني الإعاقة استحقاقًا تلقائيًا. يمكن ترك ما لا تعرفه.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">الحالة الصحية العامة<select v-model="c.health.status_value_id" class="field mt-1"><option :value="null">لم تُذكر</option><option v-for="h in options.health" :key="h.id" :value="h.id">{{ h.name }}</option></select></label>
                    <template v-if="healthDetails">
                        <label class="text-sm sm:col-span-2">التشخيص أو التفاصيل اللازمة للخدمة<textarea v-model="c.health.details" class="field mt-1" rows="2" maxlength="2000" /></label>
                        <template v-if="healthCode === 'disability'">
                            <label class="text-sm">نوع الإعاقة<select v-model="c.health.disability_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="d in options.disability" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
                            <label class="text-sm">الشدة (إن وُثقت)<select v-model="c.health.severity" class="field mt-1"><option value="">غير محددة</option><option v-for="[v, t] in severities" :key="v" :value="v">{{ t }}</option></select></label>
                        </template>
                        <Choice v-model="c.health.regular_follow_up" label="متابعة منتظمة؟" :name="`${pre}health.regular_follow_up`" :errors="e" />
                        <label v-if="c.health.regular_follow_up === 'yes'" class="text-sm">تكرار المتابعة<input v-model="c.health.follow_up_frequency" class="field mt-1" maxlength="100"></label>
                        <label class="text-sm">أدوية أساسية (عند الضرورة)<input v-model="c.health.medications" class="field mt-1" maxlength="500"></label>
                        <label class="text-sm">أجهزة مساعدة<input v-model="c.health.assistive_devices" class="field mt-1" maxlength="300"></label>
                        <label class="text-sm">احتياجات الوصول للعلاج<input v-model="c.health.access_needs" class="field mt-1" maxlength="500"></label>
                        <label class="text-sm sm:col-span-2">قيود أو تسهيلات مطلوبة<input v-model="c.health.accommodations" class="field mt-1" maxlength="1000"></label>
                        <label class="text-sm">مصدر المعلومة<select v-model="c.health.source" class="field mt-1"><option value="">غير محدد</option><option value="caregiver">إفادة مقدم الرعاية</option><option value="clinician">تقرير مختص</option><option value="document">مستند</option></select></label>
                        <label class="text-sm">تاريخ آخر تقييم أو فحص<input v-model="c.health.assessed_on" type="date" :max="new Date().toISOString().slice(0, 10)" :data-error-field="`${pre}health.assessed_on`" class="field mt-1"></label>
                    </template>
                </div>
            </details>

            <details class="rounded-xl border border-slate-200 p-4">
                <summary class="cursor-pointer font-semibold text-navy-800">الاحتياجات</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-4">
                    <Choice v-model="c.needs.food" label="غذاء" :name="`${pre}needs.food`" :errors="e" />
                    <Choice v-model="c.needs.clothing" label="كسوة" :name="`${pre}needs.clothing`" :errors="e" />
                    <Choice v-model="c.needs.education" label="تعليم" :name="`${pre}needs.education`" :errors="e" />
                    <Choice v-model="c.needs.health" label="صحة" :name="`${pre}needs.health`" :errors="e" />
                    <label class="text-sm sm:col-span-2">أثر النزوح على الطفل<input v-model="c.needs.displacement_impact" class="field mt-1" maxlength="500"></label>
                    <label class="text-sm sm:col-span-2">ملاحظات<input v-model="c.needs.notes" class="field mt-1" maxlength="1000"></label>
                </div>
                <p class="mt-2 text-xs text-slate-500">إفادة أولية؛ التقييم الاجتماعي يُسجله الباحث في زيارة مستقلة.</p>
            </details>

            <details v-if="canHealth || !portal" class="rounded-xl border border-slate-200 p-4" :open="!!(c.social.loss_circumstances || c.social.researcher_notes)">
                <summary class="cursor-pointer font-semibold text-navy-800">الظروف الاجتماعية (اختياري)</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label v-if="canHealth" class="text-sm">ظروف فقد الوالد كما تريد الأسرة ذكرها (مقيد الاطلاع)<textarea v-model="c.social.loss_circumstances" :data-error-field="`${pre}social.loss_circumstances`" class="field mt-1" rows="2" maxlength="1000" /><span class="text-xs text-slate-500">اختياري تمامًا؛ لا تُطلب تفاصيل مؤلمة أو غير لازمة.</span></label>
                    <label v-if="!portal" class="text-sm">ملاحظات الباحث (داخلية، لا تظهر للأسرة)<textarea v-model="c.social.researcher_notes" :data-error-field="`${pre}social.researcher_notes`" class="field mt-1" rows="2" maxlength="2000" /></label>
                </div>
            </details>

            <details class="rounded-xl border border-slate-200 p-4" :open="c.sponsorship.status === 'yes'">
                <summary class="cursor-pointer font-semibold text-navy-800">كفالة من جهة أخرى</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <Choice v-model="c.sponsorship.status" label="هل للطفل كفالة من جهة أخرى؟" :name="`${pre}sponsorship.status`" :errors="e" class="sm:col-span-3" />
                    <template v-if="c.sponsorship.status === 'yes'">
                        <label class="text-sm">الجهة الكافلة<input v-model="c.sponsorship.organization" :data-error-field="`${pre}sponsorship.organization`" class="field mt-1" maxlength="200"><FieldError :errors="e" :name="`${pre}sponsorship.organization`" /></label>
                        <label class="text-sm">نوع الكفالة<select v-model="c.sponsorship.support_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="t in options.sponsorship_types" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                        <label class="text-sm">الانتظام<select v-model="c.sponsorship.regularity" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in regularity" :key="v" :value="v">{{ t }}</option></select></label>
                        <label class="text-sm">الدورية<select v-model="c.sponsorship.frequency_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="f in options.frequencies" :key="f.id" :value="f.id">{{ f.name }}</option></select></label>
                        <label class="text-sm">من تاريخ<input v-model="c.sponsorship.starts_on" type="date" class="field mt-1"></label>
                        <label class="text-sm">إلى تاريخ<input v-model="c.sponsorship.ends_on" type="date" :data-error-field="`${pre}sponsorship.ends_on`" class="field mt-1"><FieldError :errors="e" :name="`${pre}sponsorship.ends_on`" /></label>
                        <label class="text-sm">المبلغ المُفاد به<input v-model="c.sponsorship.amount" type="number" min="0" step="0.01" class="field mt-1" dir="ltr"></label>
                        <label class="text-sm">العملة<select v-model="c.sponsorship.currency" :data-error-field="`${pre}sponsorship.currency`" class="field mt-1"><option v-for="cur in options.currencies" :key="cur.code" :value="cur.code">{{ cur.name }}</option></select><FieldError :errors="e" :name="`${pre}sponsorship.currency`" /></label>
                        <label class="text-sm">الإثبات<input v-model="c.sponsorship.evidence_note" class="field mt-1" maxlength="500"></label>
                    </template>
                </div>
                <p class="mt-2 text-xs text-slate-500">كفالات الجمعية تظهر من وحدة الكفالات، والاستفادة الفعلية من التسليمات المؤكدة فقط.</p>
            </details>
        </fieldset>
    </article>
</template>
