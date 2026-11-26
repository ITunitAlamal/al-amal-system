<script setup>
import { computed } from 'vue';
import PersonFields from '../Registry/PersonFields.vue';
import AddressBlock from './AddressBlock.vue';
import Choice from './Choice.vue';
import FieldError from './FieldError.vue';
import Req from './Req.vue';
import { codeOf, confidenceOptions, sourceOptions, stability, vitalOptions } from '../../Support/registration.js';

// Father or mother. Life status, documents and history are separate facts: «مفقود» is never «متوفى», and the
// father's income before death is history, not the household's current capacity.
const props = defineProps({ form: Object, relation: String, options: Object, canHealth: Boolean, lookup: Boolean, portal: Boolean });
const p = computed(() => props.form[props.relation]);
const pre = computed(() => `${props.relation}.`);
const who = computed(() => (props.relation === 'father' ? 'الأب' : 'الأم'));
const e = computed(() => props.form.errors);
const higher = computed(() => props.options.higher_education.includes(codeOf(props.options.education_levels, p.value.education.level_value_id)));
const deceased = computed(() => p.value.vital_status === 'deceased');
</script>

<template>
    <section class="card space-y-5">
        <h2 class="card-title" tabindex="-1" data-step-heading>بيانات {{ who }}</h2>
        <PersonFields :model-value="p" :prefix="pre" :errors="e" :options="options" :who="who" :show-contact="relation === 'mother'" :lookup="lookup" />
        <label class="block text-sm sm:w-1/2">مكان الميلاد (إن كان معروفًا)<input v-model="p.place_of_birth" :data-error-field="`${pre}place_of_birth`" class="field mt-1" maxlength="150"></label>

        <fieldset class="space-y-3 rounded-xl border border-slate-200 p-4">
            <legend class="px-1 font-semibold text-navy-800">الحالة</legend>
            <Choice v-model="p.vital_status" :label="`حالة ${who}`" :name="`${pre}vital_status`" :options="vitalOptions" :errors="e" />
            <div v-if="p.vital_status === 'deceased'" class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">تاريخ الوفاة<Req :k="`${pre}death.date`" /><input v-model="p.death.date" type="date" :max="new Date().toISOString().slice(0, 10)" :data-error-field="`${pre}death.date`" class="field mt-1" :disabled="p.death.date_unknown"><FieldError :errors="e" :name="`${pre}death.date`" /></label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm"><input v-model="p.death.date_unknown" type="checkbox"> التاريخ غير معروف</label>
                <label class="text-sm">مكان الوفاة<input v-model="p.death.place" :data-error-field="`${pre}death.place`" class="field mt-1" maxlength="200"></label>
                <label class="text-sm">سبب الوفاة (اختياري)<select v-model="p.death.cause_value_id" :data-error-field="`${pre}death.cause_value_id`" class="field mt-1"><option :value="null">غير مذكور</option><option v-for="c in options.death_causes" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                <label class="text-sm">توضيح السبب<Req :k="`${pre}death.cause_note`" /><input v-model="p.death.cause_note" :data-error-field="`${pre}death.cause_note`" class="field mt-1" maxlength="500"><FieldError :errors="e" :name="`${pre}death.cause_note`" /></label>
                <label class="text-sm">رقم شهادة الوفاة (إن وجدت)<input v-model="p.death.certificate_no" :data-error-field="`${pre}death.certificate_no`" class="field mt-1" dir="ltr" maxlength="60"></label>
                <Choice v-model="p.death.documents" label="هل توجد وثائق وفاة؟" :name="`${pre}death.documents`" :errors="e" class="sm:col-span-3" />
                <label class="text-sm sm:col-span-2">الإثبات المتوفر (اختياري)<input v-model="p.death.evidence_note" :data-error-field="`${pre}death.evidence_note`" class="field mt-1" maxlength="500" placeholder="مثال: شهادة وفاة من وزارة الصحة، إفادة شهود"></label>
                <label v-if="!portal" class="text-sm sm:col-span-3">السبب الرسمي بعد التحقق (للقسم فقط)<input v-model="p.death.official_cause" :data-error-field="`${pre}death.official_cause`" class="field mt-1" maxlength="500"><span class="text-xs text-slate-500">منفصل عن السبب المُفاد، ولا يظهر في بوابة المستفيدين.</span></label>
            </div>
            <div v-if="p.vital_status === 'missing'" class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">تاريخ آخر ظهور<Req :k="`${pre}missing.last_seen_on`" /><input v-model="p.missing.last_seen_on" type="date" :data-error-field="`${pre}missing.last_seen_on`" class="field mt-1" :disabled="p.missing.date_unknown"><FieldError :errors="e" :name="`${pre}missing.last_seen_on`" /></label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm"><input v-model="p.missing.date_unknown" type="checkbox"> التاريخ غير معروف</label>
                <label class="text-sm">مكان آخر ظهور<input v-model="p.missing.last_seen_place" :data-error-field="`${pre}missing.last_seen_place`" class="field mt-1" maxlength="200"></label>
                <label class="text-sm">تاريخ الإبلاغ<input v-model="p.missing.reported_on" type="date" :data-error-field="`${pre}missing.reported_on`" class="field mt-1"><FieldError :errors="e" :name="`${pre}missing.reported_on`" /></label>
                <label class="text-sm">الجهة المُبلَّغة<input v-model="p.missing.reported_to" :data-error-field="`${pre}missing.reported_to`" class="field mt-1" maxlength="200"></label>
                <label class="text-sm">رقم البلاغ أو المرجع<input v-model="p.missing.report_ref" :data-error-field="`${pre}missing.report_ref`" class="field mt-1" dir="ltr" maxlength="100"></label>
                <label class="text-sm sm:col-span-3">تفاصيل الفقد ووضعه الحالي (اختياري)<textarea v-model="p.missing.details" :data-error-field="`${pre}missing.details`" class="field mt-1" rows="2" maxlength="1000" placeholder="مثال: ما زال مفقودًا، أو معتقل حسب إفادة الأسرة" /></label>
                <p class="text-xs text-slate-500 sm:col-span-3">المفقود لا يُعامل كمتوفى؛ تبقى حالته «مفقود» حتى يثبت غير ذلك بمستند.</p>
            </div>
            <div v-if="p.vital_status && p.vital_status !== 'unknown'" class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">مصدر هذه المعلومة<input v-model="p.status_source" :data-error-field="`${pre}status_source`" class="field mt-1" maxlength="200" placeholder="مثال: شهادة وفاة، إفادة الأسرة"></label>
                <label class="text-sm">درجة الثقة بالمعلومة<select v-model="p.status_confidence" :data-error-field="`${pre}status_confidence`" class="field mt-1"><option value="">غير محددة</option><option v-for="[v, t] in confidenceOptions" :key="v" :value="v">{{ t }}</option></select></label>
            </div>
            <p class="text-xs text-slate-500">تُسجل الحالة «مُفادة» حتى يتحقق منها موظف مخول بمستند، وتصنيف اليتم يُشتق من الحالات المتحقق منها فقط.</p>
        </fieldset>

        <template v-if="relation === 'mother'">
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">الحالة الاجتماعية الحالية<select v-model="p.marital_status_value_id" :data-error-field="`${pre}marital_status_value_id`" class="field mt-1"><option :value="null">غير محددة</option><option v-for="m in options.marital" :key="m.id" :value="m.id">{{ m.name }}</option></select></label>
                <label v-if="p.marital_status_value_id" class="text-sm">تاريخ تغيّر الحالة الاجتماعية (إن وُجد)<input v-model="p.marital_status_since" type="date" :max="new Date().toISOString().slice(0, 10)" :data-error-field="`${pre}marital_status_since`" class="field mt-1"><FieldError :errors="e" :name="`${pre}marital_status_since`" /></label>
                <label class="text-sm">طريقة التواصل المفضلة<select v-model="p.contact_channel" :data-error-field="`${pre}contact_channel`" class="field mt-1"><option value="">غير محددة</option><option v-for="(label, key) in options.contact_channels" :key="key" :value="key">{{ label }}</option></select></label>
            </div>
            <div v-if="p.vital_status !== 'deceased'" class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">القدرة على رعاية الأطفال والمُعالين<select v-model="p.caregiving.capacity" :data-error-field="`${pre}caregiving.capacity`" class="field mt-1"><option value="">غير محددة</option><option v-for="(label, key) in options.caregiving" :key="key" :value="key">{{ label }}</option></select></label>
                <label v-if="p.caregiving.capacity && p.caregiving.capacity !== 'yes'" class="text-sm sm:col-span-2">توضيح مختصر (دون تفاصيل طبية)<input v-model="p.caregiving.note" :data-error-field="`${pre}caregiving.note`" class="field mt-1" maxlength="500"></label>
            </div>
            <Choice v-if="p.vital_status !== 'deceased'" v-model="p.resides_with_household" label="هل تسكن الأم مع الأسرة؟" :name="`${pre}resides_with_household`" :errors="e" />
            <AddressBlock v-if="p.resides_with_household === 'no'" :model-value="p.address" :errors="e" :prefix="`${pre}address.`" legend="عنوان سكن الأم الحالي" />
        </template>
        <label v-else class="block text-sm">مكان السكن قبل الوفاة أو آخر سكن معروف<input v-model="p.residence_before" :data-error-field="`${pre}residence_before`" class="field mt-1" maxlength="500"></label>

        <details class="rounded-xl border border-slate-200 p-4" :open="!!p.education.level_value_id">
            <summary class="cursor-pointer font-semibold text-navy-800">التعليم (إن كان معروفًا)</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="text-sm">أعلى مستوى تعليمي<select v-model="p.education.level_value_id" :data-error-field="`${pre}education.level_value_id`" class="field mt-1"><option :value="null">غير معروف</option><option v-for="l in options.education_levels" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
                <label v-if="higher" class="text-sm">التخصص<input v-model="p.education.specialization" :data-error-field="`${pre}education.specialization`" class="field mt-1" maxlength="150"></label>
                <label v-if="higher && relation === 'mother'" class="text-sm">المؤسسة التعليمية<input v-model="p.education.institution" :data-error-field="`${pre}education.institution`" class="field mt-1" maxlength="200"></label>
                <label class="text-sm">تأهيل مهني (إن وجد)<input v-model="p.education.vocational" :data-error-field="`${pre}education.vocational`" class="field mt-1" maxlength="300"></label>
            </div>
        </details>

        <details class="rounded-xl border border-slate-200 p-4" :open="!!(p.economic.occupation || p.economic.income_amount)">
            <summary class="cursor-pointer font-semibold text-navy-800">{{ relation === 'father' && deceased ? 'الوضع الاقتصادي قبل الوفاة' : 'الوضع الاقتصادي' }}</summary>
            <p v-if="relation === 'father' && deceased" class="mt-2 text-xs text-slate-500">دخل الأب قبل الوفاة معلومة تاريخية، ولا يُعدّ قدرة الأسرة الحالية.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <Choice v-if="relation === 'mother'" v-model="p.economic.employed" label="هل تعمل؟" :name="`${pre}economic.employed`" :errors="e" class="sm:col-span-3" />
                <template v-if="relation === 'father' || p.economic.employed === 'yes'">
                    <label class="text-sm">{{ relation === 'mother' ? 'المسمى الوظيفي' : 'المهنة' }}<input v-model="p.economic.occupation" :data-error-field="`${pre}economic.occupation`" class="field mt-1" maxlength="200"></label>
                    <label class="text-sm">فئة العمل<select v-model="p.economic.employment_status_value_id" :data-error-field="`${pre}economic.employment_status_value_id`" class="field mt-1"><option :value="null">غير محددة</option><option v-for="s in options.employment" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm">جهة العمل (اختياري)<input v-model="p.economic.employer" :data-error-field="`${pre}economic.employer`" class="field mt-1" maxlength="200"></label>
                    <label class="text-sm">قطاع العمل<input v-model="p.economic.sector" :data-error-field="`${pre}economic.sector`" class="field mt-1" maxlength="150" placeholder="مثال: حكومي، خاص، أهلي، عمل حر"></label>
                    <label class="text-sm">استقرار العمل<select v-model="p.economic.stability" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in stability" :key="v" :value="v">{{ t }}</option></select></label>
                    <label class="text-sm">{{ relation === 'father' && deceased ? 'الدخل الشهري التقريبي قبل الوفاة' : 'الدخل الشهري' }}<input v-model="p.economic.income_amount" type="number" min="0" step="0.01" :data-error-field="`${pre}economic.income_amount`" class="field mt-1" dir="ltr"></label>
                    <label class="text-sm">العملة<select v-model="p.economic.currency" :data-error-field="`${pre}economic.currency`" class="field mt-1"><option v-for="c in options.currencies" :key="c.code" :value="c.code">{{ c.name }}</option></select><FieldError :errors="e" :name="`${pre}economic.currency`" /></label>
                    <label v-if="options.income_bands?.length" class="text-sm">فئة الدخل (إن لم يُعرف المبلغ)<select v-model="p.economic.income_band_value_id" :data-error-field="`${pre}economic.income_band_value_id`" class="field mt-1"><option :value="null">غير معروفة</option><option v-for="b in options.income_bands" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
                    <label v-if="relation === 'mother'" class="text-sm">انتظام الدخل<select v-model="p.economic.income_regularity" class="field mt-1"><option value="">غير محدد</option><option value="regular">منتظم</option><option value="irregular">غير منتظم</option><option value="unknown">غير معروف</option></select></label>
                </template>
                <label class="text-sm">مصادر دخل أخرى<input v-model="p.economic.other_income" :data-error-field="`${pre}economic.other_income`" class="field mt-1" maxlength="300"></label>
                <label class="text-sm">عدد المُعالين{{ relation === 'father' && deceased ? ' وقتها' : '' }}<input v-model="p.economic.dependents_count" type="number" min="0" max="200" :data-error-field="`${pre}economic.dependents_count`" class="field mt-1" dir="ltr"></label>
                <template v-if="relation === 'father'">
                    <Choice v-model="p.economic.supported_household" label="هل كان يعيل الأسرة؟" :name="`${pre}economic.supported_household`" :errors="e" />
                    <Choice v-if="deceased" v-model="p.economic.breadwinner_loss" label="هل فقدت الأسرة معيلها الأساسي بوفاته؟" :name="`${pre}economic.breadwinner_loss`" :errors="e" />
                    <label class="text-sm">مصدر المعلومة<select v-model="p.economic.source" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in sourceOptions" :key="v" :value="v">{{ t }}</option></select></label>
                    <label class="text-sm">درجة الثقة بالمعلومة<select v-model="p.economic.confidence" class="field mt-1"><option value="">غير محددة</option><option v-for="[v, t] in confidenceOptions" :key="v" :value="v">{{ t }}</option></select></label>
                </template>
                <Choice v-model="p.economic.benefits" label="هل يوجد معاش أو تعويض أو مخصص (إن كان معروفًا)؟" :name="`${pre}economic.benefits`" :errors="e" class="sm:col-span-3" />
                <label v-if="p.economic.benefits === 'yes'" class="text-sm sm:col-span-3">نوعه والجهة<input v-model="p.economic.benefits_note" :data-error-field="`${pre}economic.benefits_note`" class="field mt-1" maxlength="500"><FieldError :errors="e" :name="`${pre}economic.benefits_note`" /></label>
                <label class="text-sm sm:col-span-3">ملاحظات اجتماعية واقتصادية مختصرة<textarea v-model="p.economic.notes" :data-error-field="`${pre}economic.notes`" class="field mt-1" rows="2" maxlength="2000" /></label>
            </div>
        </details>

        <details v-if="relation === 'mother' && canHealth && p.vital_status !== 'deceased'" class="rounded-xl border border-gold-200 p-4">
            <summary class="cursor-pointer font-semibold text-navy-800">احتياجات الدعم والرعاية (اختياري، مقيد الاطلاع)</summary>
            <p class="mt-2 text-xs text-slate-500">اذكر فقط ما يلزم لتقديم الخدمة؛ لا تُطلب تفاصيل طبية غير ضرورية.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="text-sm">احتياجات الرعاية أو الدعم<textarea v-model="p.care.support_needs" :data-error-field="`${pre}care.support_needs`" class="field mt-1" rows="2" maxlength="1000" /></label>
                <label class="text-sm">تسهيلات مطلوبة بسبب إعاقة (إن وجدت)<textarea v-model="p.care.accommodation" :data-error-field="`${pre}care.accommodation`" class="field mt-1" rows="2" maxlength="1000" /></label>
            </div>
        </details>
    </section>
</template>
