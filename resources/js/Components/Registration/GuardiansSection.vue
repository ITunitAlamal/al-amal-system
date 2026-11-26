<script setup>
import { computed } from 'vue';
import PersonFields from '../Registry/PersonFields.vue';
import AddressBlock from './AddressBlock.vue';
import Choice from './Choice.vue';
import FieldError from './FieldError.vue';
import { blankGuardian, nameOf, stability } from '../../Support/registration.js';

// Legal guardians of the family's children. The mother or the applicant reuse their own record (no second copy).
// Each child picks its guardian on its own card, so siblings may have different guardians. A listed guardian is a
// claim reviewed per child; it grants no portal access before approval.
const props = defineProps({ form: Object, options: Object, portal: Boolean, lockedKeys: { type: Array, default: () => [] } });
const e = computed(() => props.form.errors);
const roles = computed(() => props.form.guardians.map(g => g.role));
const motherAlive = computed(() => props.form.mother.vital_status !== 'deceased');
function add(role) { props.form.guardians.push(blankGuardian(role)); }
function remove(i) {
    const g = props.form.guardians[i];
    const used = props.form.children.filter(c => c.guardian_key === g.key);
    if (used.some(c => props.lockedKeys.includes(c.key))) { window.alert('لا يمكن حذف وصي صدر قرار بشأن أحد أطفاله.'); return; }
    if (!window.confirm(used.length ? `هذا الوصي مختار لـ${used.length} من الأيتام. حذفه يلغي اختياره لهم. متابعة؟` : 'حذف هذا الوصي من الطلب؟')) return;
    used.forEach(c => { c.guardian_key = ''; });
    props.form.guardians.splice(i, 1);
}
const title = g => ({ applicant: `مقدم الطلب${nameOf(props.form.request.applicant) ? ` — ${nameOf(props.form.request.applicant)}` : ''}`, mother: `الأم${nameOf(props.form.mother) ? ` — ${nameOf(props.form.mother)}` : ''}` }[g.role] || nameOf(g) || 'وصي آخر');
</script>

<template>
    <section class="card space-y-5">
        <h2 class="card-title" tabindex="-1" data-step-heading>بيانات الأوصياء</h2>
        <p class="alert-info">الأم أو القريب لا يصبح وصيًا قانونيًا تلقائيًا. تُراجع الوصاية وتُعتمد لكل طفل على حدة، ولا تفتح البوابة إلا بعد اعتمادها.</p>
        <FieldError :errors="e" name="guardians" />
        <div class="flex flex-wrap gap-2">
            <button v-if="!roles.includes('applicant')" type="button" class="btn btn-sm btn-secondary" @click="add('applicant')">مقدم الطلب هو الوصي</button>
            <button v-if="!roles.includes('mother') && motherAlive" type="button" class="btn btn-sm btn-secondary" @click="add('mother')">الأم هي الوصية</button>
            <button type="button" class="btn btn-sm btn-secondary" @click="add('other')">إضافة وصي آخر</button>
        </div>

        <article v-for="(g, i) in form.guardians" :key="g.key" class="space-y-4 rounded-xl border border-slate-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-navy-800">الوصي {{ i + 1 }}: {{ title(g) }}</h3>
                <button type="button" class="btn btn-sm btn-danger" @click="remove(i)">حذف</button>
            </div>
            <FieldError :errors="e" :name="`guardians.${i}.role`" />
            <p v-if="g.role === 'mother'" class="text-sm text-slate-600">تُستخدم بيانات الأم من قسم «الأم» نفسه، ولا تُدخل مرة ثانية.</p>
            <p v-else-if="g.role === 'applicant'" class="text-sm text-slate-600">تُستخدم بيانات مقدم الطلب من الخطوة الأولى.</p>
            <PersonFields v-else :model-value="g" :prefix="`guardians.${i}.`" :errors="e" :options="options" who="الوصي" :lookup="!portal" :id-reasons="options.id_missing_reasons" />

            <template v-if="g.role !== 'mother'">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label v-if="g.role === 'other'" class="text-sm">البريد الإلكتروني (اختياري)<input v-model="g.email" type="email" :data-error-field="`guardians.${i}.email`" class="field mt-1" dir="ltr" maxlength="150"><FieldError :errors="e" :name="`guardians.${i}.email`" /></label>
                    <label class="text-sm">طريقة التواصل المفضلة<select v-model="g.contact_channel" class="field mt-1"><option value="">غير محددة</option><option v-for="(label, key) in options.contact_channels" :key="key" :value="key">{{ label }}</option></select></label>
                    <label class="text-sm">المستوى التعليمي<select v-model="g.education.level_value_id" class="field mt-1"><option :value="null">غير معروف</option><option v-for="l in options.education_levels" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
                    <label class="text-sm">المؤهل أو التخصص<input v-model="g.education.specialization" class="field mt-1" maxlength="150"></label>
                </div>
                <Choice v-model="g.resides_with_household" label="هل يسكن الوصي مع الأسرة؟" :name="`guardians.${i}.resides_with_household`" :errors="e" />
                <template v-if="g.resides_with_household === 'no'">
                    <Choice v-model="g.displaced" label="هل الوصي نازح؟" :name="`guardians.${i}.displaced`" :errors="e" />
                    <AddressBlock :model-value="g.address" :errors="e" :prefix="`guardians.${i}.address.`" legend="عنوان الوصي الحالي" />
                </template>
                <details class="rounded-lg border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm font-semibold text-navy-800">الوضع الاقتصادي للوصي</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <Choice v-model="g.economic.employed" label="هل يعمل؟" :name="`guardians.${i}.economic.employed`" :errors="e" class="sm:col-span-3" />
                        <template v-if="g.economic.employed === 'yes'">
                            <label class="text-sm">المهنة<input v-model="g.economic.occupation" class="field mt-1" maxlength="200"></label>
                            <label class="text-sm">استقرار العمل<select v-model="g.economic.stability" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in stability" :key="v" :value="v">{{ t }}</option></select></label>
                        </template>
                        <label class="text-sm">الدخل الشخصي الشهري<input v-model="g.economic.income_amount" type="number" min="0" step="0.01" class="field mt-1" dir="ltr"></label>
                        <label class="text-sm">العملة<select v-model="g.economic.currency" :data-error-field="`guardians.${i}.economic.currency`" class="field mt-1"><option v-for="c in options.currencies" :key="c.code" :value="c.code">{{ c.name }}</option></select><FieldError :errors="e" :name="`guardians.${i}.economic.currency`" /></label>
                        <label class="text-sm">مصادر دخل أخرى<input v-model="g.economic.other_income" class="field mt-1" maxlength="300"></label>
                        <label class="text-sm">إجمالي من يعيلهم<input v-model="g.economic.dependents_count" type="number" min="0" max="200" class="field mt-1" dir="ltr"></label>
                        <label class="text-sm sm:col-span-2">وضع السكن<input v-model="g.economic.housing_note" class="field mt-1" maxlength="300"></label>
                        <label class="text-sm sm:col-span-3">ملاحظات الحاجة والدعم<textarea v-model="g.economic.notes" class="field mt-1" rows="2" maxlength="2000" /></label>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">دخل الوصي الشخصي منفصل عن دخل الأسرة الإجمالي.</p>
                </details>
            </template>
        </article>
        <p v-if="!form.guardians.length" class="text-sm text-slate-500">لم يُضف وصي بعد. كل يتيم يحتاج وصيًا مرتبطًا به.</p>
    </section>
</template>
