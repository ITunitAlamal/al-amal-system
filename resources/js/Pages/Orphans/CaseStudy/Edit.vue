<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import QuestionField from '../../../Components/CaseStudy/QuestionField.vue';
import { holds } from '../../../Support/fieldRules.js';

// «دراسة الحالة»: a separate stage after registration. Everything is optional while drafting; the questionnaire's
// required answers apply on «إرسال للمراجعة». Restricted sections arrive only for holders of the health capability.
const props = defineProps({ household: Object, study: Object, history: Array, schema: Object, answers: Object, options: Object, persons: Array,
    canHealth: Boolean, canEdit: Boolean, canReview: Boolean, recommendations: { type: Object, default: () => ({}) }, staff: { type: Array, default: () => [] } });
const base = () => Object.fromEntries(Object.keys(props.schema).map(s => [s, { ...(props.answers?.[s] || {}) }]));
const form = useForm({ answers: base(), assessed_on: props.study?.assessed_on || '', lock_version: props.study?.lock_version ?? 0 });
const editable = computed(() => props.canEdit && props.study && ['draft', 'returned'].includes(props.study.status));
const visible = q => !q.show_if || holds(q.show_if, form.answers);
const required = q => q.required === true || (Array.isArray(q.required) && holds(q.required, form.answers));
const answered = (section, q) => { const v = form.answers[section]?.[q.key]; return !(v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)); };
const progress = computed(() => Object.fromEntries(Object.entries(props.schema).map(([key, s]) => {
    const shown = s.questions.filter(visible);
    return [key, `${shown.filter(q => answered(key, q)).length}/${shown.length}`];
})));
const decision = useForm({ note: '', recommendation: '' });
const assignForm = useForm({ assigned_to: props.study?.assigned_to ?? null });
function assign() { assignForm.post(`${url.value}/assign`, { preserveScroll: true }); }
const url = computed(() => `/orphans/case-studies/${props.study?.public_id}`);

function start() { router.post(`/orphans/households/${props.household.public_id}/case-study`); }
function save() { form.put(url.value, { preserveScroll: true, onSuccess: () => { form.lock_version = props.study.lock_version; form.defaults(); } }); }
function submit() { if (window.confirm('إرسال الدراسة لمراجعة رئيس القسم؟ لا يمكن تعديلها بعد الإرسال إلا إذا أعيدت.')) form.post(`${url.value}/submit`, { preserveScroll: true }); }
function decide(kind) { decision.post(`${url.value}/${kind}`, { preserveScroll: true, onSuccess: () => decision.reset() }); }
function selectError(key) { document.querySelector(`[data-error-field="${key}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

// Quiet auto-save every minute while there are unsaved changes (version-checked like the registration form).
const autoState = ref('');
let timer = null;
async function autoSave() {
    if (!editable.value || form.processing || !form.isDirty || document.hidden) return;
    try {
        const { data } = await window.axios.put(url.value, { answers: form.answers, assessed_on: form.assessed_on, lock_version: form.lock_version }, { headers: { Accept: 'application/json' } });
        form.lock_version = data.lock_version;
        form.defaults();
        autoState.value = `حُفظ تلقائيًا ${new Date().toTimeString().slice(0, 5)}`;
    } catch (error) {
        autoState.value = error?.response?.status === 409 ? 'حُفظت نسخة أحدث في نافذة أخرى؛ أعد تحميل الصفحة.' : 'تعذر الحفظ التلقائي؛ اضغط «حفظ كمسودة» لعرض السبب.';
    }
}
onMounted(() => { timer = window.setInterval(autoSave, 60000); });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <AppLayout title="دراسة الحالة">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="إدارة الأيتام والأوصياء" :title="`دراسة الحالة — الأسرة ${household.reference_no}`"
                description="مرحلة متابعة بعد التسجيل: لا تؤخر التسجيل، ولا تقرر استحقاقًا تلقائيًا. كل الأسئلة اختيارية في المسودة؛ المطلوب يُطلب عند الإرسال للمراجعة فقط." />
            <div v-if="!study" class="card space-y-3">
                <p class="text-sm">لا توجد دراسة حالة لهذه الأسرة بعد.</p>
                <button v-if="canEdit" type="button" class="btn btn-primary" @click="start">بدء دراسة الحالة</button>
            </div>
            <template v-else>
                <div class="card flex flex-wrap items-center gap-3 text-sm">
                    <span class="rounded-full bg-slate-100 px-2 py-0.5">{{ study.status_label }}</span>
                    <span v-if="study.assignee">الباحث المكلَّف: {{ study.assignee }}</span>
                    <span v-if="study.assessor">الباحث: {{ study.assessor }}</span>
                    <span v-if="study.recommendation" class="font-semibold">{{ study.recommendation }}</span>
                    <span v-if="study.reviewer">المراجع: {{ study.reviewer }} · {{ String(study.reviewed_at).slice(0, 10) }}</span>
                    <span class="text-slate-500">نسخة الاستمارة {{ study.version }}</span>
                    <span v-if="autoState" class="text-slate-600" role="status">{{ autoState }}</span>
                </div>
                <div v-if="canReview && ['draft', 'returned'].includes(study.status)" class="card flex flex-wrap items-end gap-2 text-sm">
                    <label class="min-w-56 flex-1">تعيين الباحث<select v-model="assignForm.assigned_to" class="field mt-1"><option :value="null">غير مسند (أي موظف في القسم)</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <button type="button" class="btn btn-secondary" :disabled="assignForm.processing" @click="assign">حفظ التعيين</button>
                    <span v-if="assignForm.errors.assigned_to" class="field-error">{{ assignForm.errors.assigned_to }}</span>
                </div>
                <p v-if="study.status === 'returned' && study.review_note" class="alert-warning">ملاحظة المراجعة: {{ study.review_note }}</p>
                <p v-if="!canHealth" class="alert-info">الجانبان النفسي والصحي مقيدان بقدرة «البيانات الصحية»؛ لا يظهران لك، وما أدخله غيرك فيهما يبقى كما هو.</p>

                <nav class="card sticky top-2 z-10 flex flex-wrap gap-2 text-sm" aria-label="أقسام الدراسة">
                    <a v-for="(s, key) in schema" :key="key" :href="`#cs-${key}`" class="rounded-full border border-slate-200 px-3 py-1 hover:bg-slate-50">{{ s.title }} <span class="tabular-nums text-slate-500">{{ progress[key] }}</span></a>
                </nav>

                <form class="space-y-5" novalidate @submit.prevent="save">
                    <FormErrorSummary :errors="form.errors" @select="selectError" />
                    <div class="card grid gap-3 sm:grid-cols-3">
                        <label class="text-sm" data-error-field="assessed_on">تاريخ الدراسة<span class="ms-0.5 text-red-600" title="مطلوب عند الإرسال">*</span><input v-model="form.assessed_on" type="date" :max="new Date().toISOString().slice(0, 10)" class="field mt-1" :disabled="!editable"><span v-if="form.errors.assessed_on" class="field-error">{{ form.errors.assessed_on }}</span></label>
                    </div>
                    <section v-for="(s, key) in schema" :id="`cs-${key}`" :key="key" class="card space-y-4">
                        <h2 class="card-title">{{ s.title }}<span v-if="s.sensitive" class="ms-2 text-xs font-normal text-gold-800">(مقيد الاطلاع)</span></h2>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <template v-for="q in s.questions" :key="q.key">
                                <QuestionField v-if="visible(q)" :q="q" :holder="form.answers[key]" :path="`${key}.${q.key}`" :options="options" :persons="persons" :errors="form.errors" :disabled="!editable" :required="required(q)" />
                            </template>
                        </div>
                    </section>
                    <div v-if="editable" class="aims-form-actions card flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-secondary" :disabled="form.processing">حفظ كمسودة</button>
                        <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">إرسال للمراجعة</button>
                        <span class="self-center text-xs text-slate-500">العلامة * مطلوبة عند الإرسال فقط.</span>
                    </div>
                </form>

                <div v-if="canReview && study.status === 'submitted' && !study.mine" class="card space-y-3">
                    <h2 class="card-title">مراجعة رئيس القسم</h2>
                    <label class="block text-sm">التوصية (مطلوبة عند اعتماد المراجعة)<select v-model="decision.recommendation" class="field mt-1"><option value="">اختر</option><option v-for="(label, key) in recommendations" :key="key" :value="key">{{ label }}</option></select><span v-if="decision.errors.recommendation" class="field-error">{{ decision.errors.recommendation }}</span></label>
                    <label class="block text-sm">ملاحظة (مطلوبة عند الإعادة)<textarea v-model="decision.note" class="field mt-1" rows="2" maxlength="2000" /><span v-if="decision.errors.note" class="field-error">{{ decision.errors.note }}</span></label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary" :disabled="decision.processing" @click="decide('review')">اعتماد المراجعة</button>
                        <button type="button" class="btn btn-secondary" :disabled="decision.processing" @click="decide('return')">إعادة للاستكمال</button>
                    </div>
                    <p class="text-xs text-slate-500">المراجعة توثق اكتمال الدراسة، ولا تمنح استحقاقًا أو كفالة.</p>
                </div>
            </template>

            <div v-if="history.length > 1" class="card">
                <h2 class="card-title">الدراسات السابقة</h2>
                <ul class="mt-2 space-y-1 text-sm"><li v-for="h in history" :key="h.public_id"><a :href="`/orphans/households/${household.public_id}/case-study/${h.public_id}`" class="underline">{{ h.status_label }} · {{ h.assessed_on || '—' }}</a></li></ul>
            </div>
            <div v-if="study?.status === 'reviewed' && canEdit" class="card"><button type="button" class="btn btn-secondary" @click="start">بدء دراسة متابعة جديدة</button></div>
        </div>
    </AppLayout>
</template>
