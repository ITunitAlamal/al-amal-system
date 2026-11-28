<script setup>
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import FormSteps from '../FormSteps.vue';
import FormErrorSummary from '../FormErrorSummary.vue';
import PersonFields from '../Registry/PersonFields.vue';
import ParentSection from './ParentSection.vue';
import HouseholdSection from './HouseholdSection.vue';
import GuardiansSection from './GuardiansSection.vue';
import ResidenceSection from './ResidenceSection.vue';
import ChildCard from './ChildCard.vue';
import DocumentsSection from './DocumentsSection.vue';
import RegistrationSummary from './RegistrationSummary.vue';
import FieldError from './FieldError.vue';
import { useFormWizard } from '../../Support/useFormWizard.js';
import { blankChild, hydrate, nameOf, stepForError, steps } from '../../Support/registration.js';
import { createFieldRules } from '../../Support/fieldRules.js';

// One family request with independent child cards. «حفظ كمسودة» accepts partial answers. «إرسال» applies the
// conditional requirements and the declaration. The server validates everything again and decides each child separately.
const props = defineProps({ application: Object, form: Object, documents: Array, missingDocuments: Array, childStatus: Object, canHealth: Boolean, options: Object,
    urls: Object, portal: Boolean, account: { type: Object, default: null }, fieldRules: { type: Object, default: () => ({}) } });
const form = useForm(hydrate(props.form));
// Same conditions as the server (RegistrationFieldSpec): markers on required fields and the list of what is still missing.
const rules = reactive(createFieldRules(props.fieldRules, form, props.options));
provide('fieldRules', rules);
const missing = computed(() => rules.missing());
const { step, scope, move, selectError, onError } = useFormWizard(form, stepForError);
const editable = computed(() => props.application.editable);
const lockedKeys = computed(() => Object.entries(props.childStatus || {}).filter(([, s]) => ['approved', 'rejected'].includes(s.status)).map(([key]) => key));
const last = steps.length - 1;
// Rough completion per step for the step list (an aid only; the server decides what is required).
const progress = computed(() => ({
    0: !!(nameOf(form.request.applicant) || props.portal) && !!form.request.relationship_value_id,
    1: !!form.father.vital_status && !!nameOf(form.father), 2: !!form.mother.vital_status && !!nameOf(form.mother),
    3: form.household.declared_members !== '' && form.household.declared_members !== null, 4: form.guardians.length > 0,
    5: !!(form.residence.original.governorate_value_id || form.residence.original.address_details), 6: form.children.every(c => nameOf(c) && c.guardian_key),
    7: true, 8: form.declaration.truth && form.declaration.consent,
}));
const labels = computed(() => steps.map((label, i) => (progress.value[i] ? `${label} ✓` : label)));

function addChild() {
    form.children.push(blankChild());
    const only = form.guardians.length === 1 ? form.guardians[0].key : '';
    if (only) form.children[form.children.length - 1].guardian_key = only;
}
function removeChild(i) {
    const c = form.children[i];
    if (lockedKeys.value.includes(c.key)) return;
    if (!window.confirm(`حذف بطاقة «${nameOf(c) || `اليتيم ${i + 1}`}» من الطلب؟`)) return;
    form.children.splice(i, 1);
}
function saveDraft() {
    if (form.processing || !editable.value) return Promise.resolve();
    return new Promise(resolve => form.put(props.urls.save, { preserveScroll: true, onSuccess: () => form.defaults(), onError, onFinish: resolve }));
}
function submit() {
    if (form.processing) return;
    if (step.value < last) { move(step.value + 1); return; }
    form.post(props.urls.submit, { preserveScroll: true, onError });
}
// Silent auto-save every minute while the draft has unsaved changes: one small JSON request, nothing when idle.
// Every save carries the version it was based on, so a newer copy saved elsewhere is never overwritten.
const version = ref(props.application.version);
watch(() => props.application.version, v => { version.value = v; });
form.transform(data => ({ ...data, version: version.value, last_step: step.value }));
const autoState = ref({ text: '', tone: '' });
let timer = null;
let stopped = false;
async function autoSave() {
    if (stopped || !editable.value || form.processing || !form.isDirty || document.hidden) return;
    const snapshot = JSON.parse(JSON.stringify(form.data()));
    try {
        const { data } = await axios.put(props.urls.save, { ...snapshot, version: version.value, last_step: step.value }, { headers: { Accept: 'application/json' } });
        version.value = data.version;
        form.defaults(snapshot);
        autoState.value = { text: `حُفظ تلقائيًا ${new Date().toTimeString().slice(0, 5)}`, tone: 'text-emerald-700' };
    } catch (error) {
        const status = error?.response?.status;
        if (status === 409) stopped = true;
        autoState.value = { tone: 'text-amber-700', text: status === 409 ? 'حُفظت نسخة أحدث من الطلب في نافذة أخرى؛ أوقف الحفظ التلقائي. أعد تحميل الصفحة.'
            : status === 422 ? 'تعذر الحفظ التلقائي: بعض القيم غير صالحة. اضغط «حفظ كمسودة» لعرضها.' : 'تعذر الحفظ التلقائي؛ ستُعاد المحاولة.' };
    }
}
onMounted(() => { timer = window.setInterval(autoSave, 60000); });
onBeforeUnmount(() => window.clearInterval(timer));
if (!form.declaration.name) form.declaration.name = props.portal ? (props.account?.name || '') : nameOf(form.request.applicant);
function discard() { if (window.confirm('حذف هذه المسودة نهائيًا؟ لا يمكن التراجع.')) router.post(props.urls.discard); }
</script>

<template>
    <div class="space-y-5">
        <div class="card flex flex-wrap items-center gap-3 text-sm">
            <span>رقم الطلب: <strong class="font-mono" dir="ltr">{{ application.reference_no }}</strong></span>
            <span class="rounded-full bg-slate-100 px-2 py-0.5">{{ application.status_label }}</span>
            <span v-if="application.last_saved_at" class="text-slate-500">آخر حفظ: {{ String(application.last_saved_at).slice(0, 16) }}</span>
            <span v-if="autoState.text" :class="autoState.tone" role="status">{{ autoState.text }}</span>
            <span class="text-slate-500">المصدر: {{ application.source === 'portal' ? 'بوابة الأوصياء' : 'إدخال من القسم' }}</span>
        </div>
        <p v-if="application.completion_request" class="alert-warning">مطلوب استكمال: {{ application.completion_request }}</p>
        <p v-if="!editable" class="alert-info">الطلب قيد المراجعة؛ لا يمكن تعديله الآن.</p>
        <FormSteps :steps="labels" :model-value="step" :disabled="form.processing" @update:model-value="move" />
        <form ref="scope" class="aims-long-form space-y-5" novalidate :aria-busy="form.processing" @submit.prevent="submit">
            <FormErrorSummary :errors="form.errors" @select="selectError" />
            <p class="text-xs text-slate-500">العلامة <span class="text-red-600">*</span> تعني أن الحقل مطلوب عند الإرسال حسب إجاباتك الحالية؛ ما عداه اختياري. «غير معروف» إجابة مقبولة، ولا يُطلب تخمين.</p>
            <fieldset :disabled="!editable" class="space-y-5">
                <section v-show="step === 0" class="card space-y-4">
                    <h2 class="card-title" tabindex="-1" data-step-heading>بيانات طلب التسجيل</h2>
                    <p class="text-sm text-slate-600">طلب واحد للأسرة، وفيه بطاقة مستقلة لكل يتيم. يمكنك الحفظ كمسودة والعودة لاحقًا.</p>
                    <div v-if="portal" class="rounded-xl bg-slate-50 p-3 text-sm">مقدم الطلب صاحب الحساب: <strong>{{ account?.name }}</strong> · الهوية <span class="font-mono" dir="ltr">{{ account?.id_masked }}</span> · الجوال <span dir="ltr">{{ account?.phone }}</span>
                        <span class="block text-xs text-slate-500">رقم الهوية والجوال من الحساب ولا يُعدلان هنا.</span></div>
                    <fieldset v-if="portal" class="grid gap-3 sm:grid-cols-4"><legend class="field-label">اسمك الرباعي كما في الهوية</legend>
                        <label class="text-sm">الاسم الأول<input v-model="form.request.applicant.first_name" data-error-field="request.applicant.first_name" class="field mt-1" maxlength="100"></label>
                        <label class="text-sm">اسم الأب<input v-model="form.request.applicant.father_name" class="field mt-1" maxlength="100"></label>
                        <label class="text-sm">اسم الجد<input v-model="form.request.applicant.grandfather_name" class="field mt-1" maxlength="100"></label>
                        <label class="text-sm">اسم العائلة<input v-model="form.request.applicant.family_name" class="field mt-1" maxlength="100"></label>
                        <label class="text-sm">جوال بديل<input v-model="form.request.applicant.alt_phone" data-error-field="request.applicant.alt_phone" class="field mt-1" dir="ltr" inputmode="tel" maxlength="16"><FieldError :errors="form.errors" name="request.applicant.alt_phone" /></label>
                    </fieldset>
                    <PersonFields v-else :model-value="form.request.applicant" prefix="request.applicant." :errors="form.errors" :options="options" who="مقدم الطلب" :show-dob="false" />
                    <p class="text-xs text-slate-500">مقدم الطلب ليس بالضرورة الوصي القانوني، ولا يحق له الاطلاع على ملفات الأطفال إلا بعد اعتماد وصايته على كل طفل.</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">البريد الإلكتروني (اختياري)<input v-model="form.request.applicant.email" type="email" data-error-field="request.applicant.email" class="field mt-1" dir="ltr" maxlength="150"><FieldError :errors="form.errors" name="request.applicant.email" /></label>
                        <label class="text-sm">طريقة التواصل المفضلة<select v-model="form.request.applicant.contact_channel" data-error-field="request.applicant.contact_channel" class="field mt-1"><option value="">غير محددة</option><option v-for="(label, key) in options.contact_channels" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="text-sm">صلة مقدم الطلب بالأسرة<select v-model="form.request.relationship_value_id" data-error-field="request.relationship_value_id" class="field mt-1"><option :value="null">اختر</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select><FieldError :errors="form.errors" name="request.relationship_value_id" /></label>
                    </div>
                    <label class="block text-sm">ملاحظات الطلب (اختياري)<textarea v-model="form.request.notes" data-error-field="request.notes" class="field mt-1" rows="3" maxlength="2000" /></label>
                </section>

                <ParentSection v-show="step === 1" :form="form" relation="father" :options="options" :can-health="canHealth" :lookup="!portal" :portal="portal" />
                <ParentSection v-show="step === 2" :form="form" relation="mother" :options="options" :can-health="canHealth" :lookup="!portal" :portal="portal" />
                <HouseholdSection v-show="step === 3" :form="form" :options="options" :portal="portal" />
                <GuardiansSection v-show="step === 4" :form="form" :options="options" :portal="portal" :locked-keys="lockedKeys" />
                <ResidenceSection v-show="step === 5" :form="form" :options="options" />

                <section v-show="step === 6" class="space-y-4">
                    <div class="card flex flex-wrap items-center justify-between gap-3">
                        <h2 class="card-title" tabindex="-1" data-step-heading>بيانات الأيتام ({{ form.children.length }})</h2>
                        <button v-if="form.children.length < 15" type="button" class="btn btn-primary" @click="addChild">+ إضافة يتيم آخر</button>
                    </div>
                    <FieldError :errors="form.errors" name="children" />
                    <ChildCard v-for="(child, i) in form.children" :key="child.key" :form="form" :index="i" :options="options" :can-health="canHealth" :portal="portal"
                        :status="childStatus?.[child.key] || null" @remove="removeChild(i)" />
                    <button v-if="form.children.length < 15 && form.children.length > 1" type="button" class="btn btn-secondary" @click="addChild">+ إضافة يتيم آخر</button>
                </section>
            </fieldset>

            <DocumentsSection v-show="step === 7" :form="form" :options="options" :documents="documents" :missing="missingDocuments" :editable="editable" :documents-url="urls.documents" :before-upload="saveDraft" />

            <section v-if="step === 8" class="card space-y-4">
                <h2 class="card-title" tabindex="-1" data-step-heading>المراجعة والإقرار</h2>
                <div v-if="editable && missing.length" class="alert-warning space-y-2" role="status">
                    <p class="font-semibold">حقول مطلوبة لم تُستكمل بعد ({{ missing.length }}) — يمكن الحفظ كمسودة، لكن الإرسال يحتاجها:</p>
                    <ul class="flex flex-wrap gap-2 text-sm"><li v-for="m in missing" :key="m.key"><button type="button" class="underline" @click="selectError(m.key)">{{ m.label }}</button></li></ul>
                </div>
                <RegistrationSummary :form="form" :options="options" :can-health="canHealth" :child-status="childStatus" />
                <fieldset :disabled="!editable" class="space-y-2 rounded-xl border border-slate-200 p-4 text-sm">
                    <label class="flex items-start gap-2" data-error-field="declaration.truth"><input v-model="form.declaration.truth" type="checkbox" class="mt-1"> أقر بأن البيانات المدخلة صحيحة حسب علمي، وأن ما لم أعرفه تركته «غير معروف».</label>
                    <FieldError :errors="form.errors" name="declaration.truth" />
                    <label class="flex items-start gap-2" data-error-field="declaration.consent"><input v-model="form.declaration.consent" type="checkbox" class="mt-1"> أوافق على أن يراجع القسم البيانات والمستندات ويتحقق منها، وأعلم أن الإرسال ليس اعتمادًا للوصاية أو للاستفادة.</label>
                    <FieldError :errors="form.errors" name="declaration.consent" />
                    <div class="grid gap-3 pt-2 sm:grid-cols-2">
                        <label class="text-sm">اسم المُقر كاملًا<input v-model="form.declaration.name" data-error-field="declaration.name" class="field mt-1" maxlength="200"><FieldError :errors="form.errors" name="declaration.name" /></label>
                        <p class="self-end text-sm text-slate-600">تاريخ الإقرار: يُسجل تلقائيًا عند الإرسال.</p>
                    </div>
                </fieldset>
            </section>

            <div class="aims-form-actions card flex flex-wrap items-center gap-2">
                <button v-if="step > 0" type="button" class="btn btn-secondary" :disabled="form.processing" @click="move(step - 1)">السابق</button>
                <button v-if="step < last" type="submit" class="btn btn-primary">التالي</button>
                <button v-else-if="editable" type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ الإرسال…' : application.status === 'draft' ? 'إرسال الطلب للمراجعة' : 'إعادة إرسال الطلب' }}</button>
                <button v-if="editable" type="button" class="btn btn-secondary" :disabled="form.processing" @click="saveDraft">حفظ كمسودة</button>
                <button v-if="application.status === 'draft'" type="button" class="btn btn-danger" :disabled="form.processing" @click="discard">حذف المسودة</button>
                <a :href="urls.back" class="btn btn-secondary sm:ms-auto">رجوع</a>
            </div>
        </form>
    </div>
</template>
