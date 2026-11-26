<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import GeographicAddress from '../../../Components/GeographicAddress.vue';
import PersonFields from '../../../Components/Registry/PersonFields.vue';
import GuardianPicker from '../../../Components/Registry/GuardianPicker.vue';
import FormSteps from '../../../Components/FormSteps.vue';
import { useFormWizard } from '../../../Support/useFormWizard.js';
import { emptyAddress, emptyPerson } from '../../../Support/orphans.js';

// Guardian first (existing or new), then the child and the explicit relationship, saved atomically.
const props = defineProps({ options: Object, canApprove: Boolean, staff: Array, guardian: { type: Object, default: null } });
const picked = ref(props.guardian);
const childExisting = ref(null);
const parentExisting = ref({ father: null, mother: null });
const showParents = ref(false);
const form = useForm({
    guardian_mode: props.guardian ? 'existing' : 'existing', guardian_public_id: props.guardian?.public_id ?? null, guardian_person_public_id: null, guardian: emptyPerson(),
    child: emptyPerson(), orphan: { orphan_class_value_id: null, assigned_to: null, notes: '' }, address: emptyAddress(),
    link: { link_status: 'claimed', kinship_value_id: null, authority_kind_value_id: null, legal_basis: '', starts_on: '', ends_on: '', evidence_note: '' },
    father: { ...emptyPerson(), status: 'deceased', event_date: '', person_public_id: null }, mother: { ...emptyPerson(), status: '', event_date: '', person_public_id: null },
});
const steps = ['الوصي', 'بيانات اليتيم', 'علاقة الوصاية', 'الأب والأم', 'المراجعة والحفظ'];
const { step, scope, move, selectError: selectStepError, onError: showErrorStep } = useFormWizard(form, field => field.startsWith('guardian') ? 0 : /^(child|orphan|address)/.test(field) ? 1 : field.startsWith('link') ? 2 : 3);
function selectError(field) { if (/^(father|mother)/.test(field)) showParents.value = true; return selectStepError(field); }
function onError(errors) { if (Object.keys(errors).some(field => /^(father|mother)/.test(field))) showParents.value = true; return showErrorStep(errors); }
const personName = person => person.full_name || [person.first_name, person.father_name, person.grandfather_name, person.family_name].filter(Boolean).join(' ');
function pick(value) { picked.value = value; form.guardian_public_id = value?.public_id ?? null; }
function guardianExisting(match) { form.guardian_mode = 'person'; form.guardian_person_public_id = match.public_id; picked.value = { ...match, reference_no: match.roles.join('، ') }; }
function useParent(relation, match) { parentExisting.value[relation] = match; form[relation].person_public_id = match.public_id; }
function submit() {
    if (form.processing) return;
    if (step.value < steps.length - 1) { move(step.value + 1); return; }
    form.transform(data => ({ ...data, child: childExisting.value ? {} : data.child })).post('/orphans/registry/orphans', { onError });
}
</script>

<template>
    <AppLayout title="إضافة يتيم">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader eyebrow="إدارة الأيتام والأوصياء" title="إضافة يتيم مع وصيه" description="لا يُحفظ ملف يتيم دون ارتباط مباشر بوصي. الارتباط يُسجل كادعاء أو بانتظار التحقق، ولا يمنح وصولًا للبوابة قبل اعتماده لهذا الطفل." />
            <FormSteps :steps="steps" :model-value="step" :disabled="form.processing" @update:model-value="move" />
            <form ref="scope" class="aims-long-form space-y-5" novalidate :aria-busy="form.processing" @submit.prevent="submit">
                <h2 tabindex="-1" data-step-heading class="sr-only">{{ steps[step] }}</h2>
                <FormErrorSummary :errors="form.errors" @select="selectError" />
                <section v-show="step === 0" class="card space-y-4">
                    <h2 class="card-title">1. الوصي</h2>
                    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="مصدر الوصي">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.guardian_mode" type="radio" value="existing"> وصي مسجل</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.guardian_mode" type="radio" value="new"> وصي جديد</label>
                    </div>
                    <GuardianPicker v-if="form.guardian_mode === 'existing'" :model-value="picked" @update:model-value="pick" />
                    <div v-else-if="form.guardian_mode === 'person'" class="alert-info flex flex-wrap items-center gap-2">سيُستخدم الشخص المسجل «{{ picked?.full_name }}» وصيًا.
                        <button type="button" class="btn btn-sm btn-secondary" @click="form.guardian_mode = 'new'; form.guardian_person_public_id = null">إلغاء</button></div>
                    <PersonFields v-else v-model="form.guardian" prefix="guardian." :errors="form.errors" :options="options" who="الوصي" @use-existing="guardianExisting" />
                    <p v-if="form.errors.guardian_public_id" class="field-error">{{ form.errors.guardian_public_id }}</p>
                </section>

                <section v-show="step === 1" class="card space-y-4">
                    <h2 class="card-title">2. اليتيم</h2>
                    <div v-if="childExisting" class="alert-warning">هذا الشخص مسجل مسبقًا: «{{ childExisting.full_name }}» ({{ childExisting.roles.join('، ') || 'دون ملف' }}). افتح ملفه بدل إنشاء ملف مكرر.
                        <button type="button" class="btn btn-sm btn-secondary ms-2" @click="childExisting = null">متابعة كشخص مختلف</button></div>
                    <PersonFields v-model="form.child" prefix="child." :errors="form.errors" :options="options" who="اليتيم" :show-contact="false" @use-existing="childExisting = $event" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">فئة اليتم (اختياري؛ تُشتق من حالة الوالدين المتحقق منها)
                            <select v-model="form.orphan.orphan_class_value_id" class="field mt-1"><option :value="null">تُحدد لاحقًا</option><option v-for="c in options.classes" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                        <label class="text-sm">الموظف المسؤول (اختياري)
                            <select v-model="form.orphan.assigned_to" class="field mt-1"><option :value="null">غير محدد</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    </div>
                    <fieldset><legend class="field-label">العنوان الحالي لليتيم (اختياري)</legend>
                        <GeographicAddress :model-value="form.address" :errors="form.errors" prefix="address." optional @update:model-value="Object.assign(form.address, $event)" /></fieldset>
                </section>

                <section v-show="step === 2" class="card space-y-4">
                    <h2 class="card-title">3. علاقة الوصاية</h2>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">حالة الارتباط
                            <select v-model="form.link.link_status" class="field mt-1">
                                <option value="claimed">مُدّعاة (بحاجة إلى إثبات)</option><option value="pending">بانتظار التحقق</option>
                                <option v-if="canApprove" value="approved">معتمدة (بعد التحقق من السند)</option>
                            </select></label>
                        <label class="text-sm">صلة القرابة<select v-model="form.link.kinship_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select></label>
                        <label class="text-sm">نوع السند<select v-model="form.link.authority_kind_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="a in options.authority" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                        <label class="text-sm">بداية السريان<input v-model="form.link.starts_on" type="date" class="field mt-1"><span v-if="form.errors['link.starts_on']" class="field-error">{{ form.errors['link.starts_on'] }}</span></label>
                        <label class="text-sm">نهاية السريان (اختياري)<input v-model="form.link.ends_on" type="date" class="field mt-1"></label>
                        <label class="text-sm">الأساس القانوني<input v-model="form.link.legal_basis" class="field mt-1" maxlength="300" placeholder="مثال: حجة وصاية رقم…"></label>
                    </div>
                    <label class="block text-sm">ملاحظات الإثبات<textarea v-model="form.link.evidence_note" class="field mt-1" rows="2" maxlength="2000" /></label>
                    <p class="text-xs text-slate-500">الأم أو الأب أو صلة القرابة لا تعني الوصاية تلقائيًا؛ الوصاية تُعتمد صراحة لكل طفل.</p>
                </section>

                <section v-show="step === 3" class="card space-y-4">
                    <div class="flex items-center justify-between"><h2 class="card-title">4. الأب والأم (اختياري الآن)</h2>
                        <button type="button" class="btn btn-sm btn-secondary" :aria-expanded="showParents" @click="showParents = !showParents">{{ showParents ? 'إخفاء' : 'إدخال الآن' }}</button></div>
                    <template v-if="showParents">
                        <div v-for="relation in ['father', 'mother']" :key="relation" class="space-y-3 rounded-xl border border-slate-200 p-4">
                            <h3 class="font-semibold text-navy-800">{{ relation === 'father' ? 'الأب' : 'الأم' }}</h3>
                            <div v-if="parentExisting[relation]" class="alert-info">سيُربط الشخص المسجل «{{ parentExisting[relation].full_name }}» (يُشارك بين الإخوة).
                                <button type="button" class="btn btn-sm btn-secondary ms-2" @click="parentExisting[relation] = null; form[relation].person_public_id = null">إلغاء</button></div>
                            <PersonFields v-else v-model="form[relation]" :prefix="`${relation}.`" :errors="form.errors" :options="options" :who="relation === 'father' ? 'الأب' : 'الأم'" :show-contact="relation === 'mother'" @use-existing="useParent(relation, $event)" />
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm">الحالة<select v-model="form[relation].status" class="field mt-1"><option value="">غير محددة</option><option value="alive">على قيد الحياة</option><option value="deceased">متوفى</option><option value="missing">مفقود</option><option value="unknown">غير معروفة</option></select></label>
                                <label v-if="['deceased', 'missing'].includes(form[relation].status)" class="text-sm">تاريخ الوفاة / الفقد (كما أُفيد)<input v-model="form[relation].event_date" type="date" class="field mt-1"></label>
                            </div>
                            <p class="text-xs text-slate-500">الحالة تُسجل «مُفادة» حتى يتحقق منها موظف مخول بمستند؛ فئة اليتم تُشتق فقط من الحالات المتحقق منها.</p>
                        </div>
                    </template>
                </section>

                <section v-if="step === 4" class="card space-y-4">
                    <h2 class="card-title">راجع البيانات قبل إنشاء الملف</h2>
                    <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div><dt class="text-slate-500">اليتيم</dt><dd class="mt-1 font-semibold">{{ personName(form.child) || 'لم يُدخل الاسم' }}</dd></div>
                        <div><dt class="text-slate-500">الوصي</dt><dd class="mt-1 font-semibold">{{ form.guardian_mode === 'new' ? personName(form.guardian) : picked?.full_name || 'لم يُحدد وصي' }}</dd></div>
                        <div><dt class="text-slate-500">حالة العلاقة لهذا الطفل</dt><dd>{{ { claimed: 'مُدّعاة — بحاجة إلى إثبات', pending: 'بانتظار التحقق', approved: 'معتمدة بعد التحقق' }[form.link.link_status] }}</dd></div>
                        <div><dt class="text-slate-500">تاريخ الميلاد</dt><dd>{{ form.child.date_of_birth || 'غير مسجل' }}</dd></div>
                    </dl>
                    <p class="alert-info">الحفظ ينشئ الملف وارتباطه المباشر بالوصي. لا تُمنح صلاحية البوابة تلقائيًا، ولا يُعدّ اشتراك الأسرة دليلًا على الوصاية.</p>
                    <p class="text-sm text-slate-600">يمكن استكمال المستندات والتعليم والرعاية والسكن من الملف بعد حفظه.</p>
                </section>
                <div class="aims-form-actions card flex flex-wrap items-center gap-2">
                    <button v-if="step > 0" type="button" class="btn btn-secondary" :disabled="form.processing" @click="move(step - 1)">السابق</button>
                    <button v-if="step < 4" type="submit" class="btn btn-primary">التالي</button>
                    <button v-else type="submit" class="btn btn-primary" :disabled="form.processing || !!childExisting || (form.guardian_mode === 'existing' && !form.guardian_public_id)">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ الملف والارتباط' }}</button>
                    <Link href="/orphans/registry" class="btn btn-secondary">رجوع</Link>
                    <span class="text-xs text-slate-500 sm:ms-auto">لا تُحفظ البيانات حتى تأكيد الحفظ.</span>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
