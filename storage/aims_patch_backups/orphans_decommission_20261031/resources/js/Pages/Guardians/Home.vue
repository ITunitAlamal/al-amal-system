<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import GuardianLayout from '../../Layouts/GuardianLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';
import { useUnsavedChanges } from '../../Support/useUnsavedChanges.js';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import { guardianStates } from '../../Support/guardianProgress.js';

defineProps({ applications: Array, children: Array, registryAvailable: Boolean });
const form = useForm({ deceased_national_id: '', relationship: '' });
const resubmitForm = useForm({ relationship: '' });
const { confirmLeave } = useUnsavedChanges(() => form.isDirty || (selected.value !== null && resubmitForm.isDirty));
const selected = ref(null);
const retrying = ref(null);
const states = guardianStates;
const childResults = {
    verified: ['متحقق', 'success'],
    pending: ['بانتظار التحقق', 'info'],
    incomplete: ['بحاجة إلى سند تمثيل', 'warning'],
    conflict: ['قيد مراجعة القسم', 'warning'],
    ineligible: ['خارج الفئة العمرية', 'muted'],
};

function retry(id) {
    retrying.value = id;
    router.post(`/guardian/applications/${id}/verify`, {}, { preserveScroll: true, onFinish: () => { retrying.value = null; } });
}
function openResubmission(application) {
    if (selected.value !== null && resubmitForm.isDirty && !confirmLeave()) return;
    resubmitForm.defaults({ relationship: application.relationship });
    resubmitForm.reset();
    resubmitForm.clearErrors();
    selected.value = application.public_id;
}
function resubmit() {
    resubmitForm.put(`/guardian/applications/${selected.value}`, { preserveScroll: true, onSuccess: () => { selected.value = null; } });
}
</script>

<template>
    <GuardianLayout title="طلباتي وملفات الأطفال">
        <header>
            <p class="eyebrow">بوابة الأوصياء</p>
            <h1 class="mt-1 text-2xl font-bold text-navy-800">طلباتي وملفات الأطفال</h1>
            <p class="mt-2 text-slate-600">تابع نتيجة التحقق وملاحظات المشرف. تظهر ملفات الأطفال بعد اعتماد الطلب.</p>
        </header>
        <p v-if="!registryAvailable" class="alert-warning leading-7">خدمة التحقق غير متاحة حاليًا؛ طلبك محفوظ. لا تحتاج إلى تسجيل الأطفال يدويًا أو إعادة إنشاء حساب.</p>

        <section class="grid gap-4 md:grid-cols-2">
            <article v-for="app in applications" :key="app.public_id" class="card space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-sans font-semibold text-navy-800">طلب المتوفى •••• {{ app.deceased_suffix }}</h2>
                    <StatusBadge :label="states[app.status]?.[0] || app.status" :tone="states[app.status]?.[1] || 'muted'" />
                </div>
                <StatusStepper :steps="app.progress.steps" compact />
                <p class="text-sm text-slate-600">{{ app.relationship }}</p>
                <p v-if="app.review_note" class="rounded-lg bg-slate-50 p-3 text-sm leading-7"><strong>ملاحظة المشرف:</strong> {{ app.review_note }}</p>
                <div v-if="Object.keys(app.children_summary || {}).length" class="space-y-1.5">
                    <p class="text-xs font-semibold text-slate-500">نتيجة التحقق من الأطفال</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template v-for="(meaning, key) in childResults" :key="key">
                            <StatusBadge v-if="app.children_summary[key]" :label="`${meaning[0]}: ${app.children_summary[key]}`" :tone="meaning[1]" />
                        </template>
                    </div>
                    <p v-if="app.children_summary.incomplete" class="text-xs leading-6 text-slate-500">صلة القرابة لا تكفي وحدها؛ يلزم سند ولاية أو وصاية أو وكالة رسمي لهؤلاء الأطفال.</p>
                </div>
                <p v-if="app.status === 'approved'" class="text-sm font-semibold text-emerald-700">رُبط {{ app.imported_children_count }} طفلًا متحققًا منه بحسابك.</p>
                <div class="flex flex-wrap gap-2">
                    <button v-if="registryAvailable && ['awaiting_api', 'verification_failed'].includes(app.status)" type="button" :disabled="retrying === app.public_id" class="btn btn-secondary btn-sm" @click="retry(app.public_id)">إعادة محاولة التحقق</button>
                    <button v-if="app.status === 'needs_changes'" type="button" class="btn btn-gold btn-sm" @click="openResubmission(app)">استكمال وصف الصفة والعلاقة</button>
                </div>
                <form v-if="selected === app.public_id" class="space-y-3" @submit.prevent="resubmit">
                    <FormErrorSummary :errors="resubmitForm.errors" />
                    <label :for="`resubmit-${app.public_id}`" class="field-label">الصفة والعلاقة بعد الاستكمال</label>
                    <textarea :id="`resubmit-${app.public_id}`" data-error-field="relationship" v-model="resubmitForm.relationship" class="field" maxlength="200" required />
                    <button type="submit" :disabled="resubmitForm.processing" class="btn btn-primary btn-sm">إرسال الاستكمال</button>
                </form>
            </article>
        </section>

        <section class="card">
            <h2 class="card-title">الأطفال المرتبطون بوصايتك المعتمدة</h2>
            <EmptyState v-if="!children.length" class="mt-4" title="لا توجد ملفات أطفال مرتبطة بحسابك حتى الآن" />
            <ul v-else class="mt-4 divide-y divide-slate-100">
                <li v-for="child in children" :key="child.public_id" class="flex flex-wrap justify-between gap-3 py-3">
                    <strong class="text-navy-800">{{ child.full_name }}</strong>
                    <span class="text-sm text-slate-600">تاريخ الميلاد: <span dir="ltr">{{ child.date_of_birth }}</span></span>
                </li>
            </ul>
        </section>

        <details class="card">
            <summary class="cursor-pointer font-semibold text-navy-800">طلب مرتبط بمتوفى آخر</summary>
            <form class="mt-5 space-y-4" @submit.prevent="form.post('/guardian/applications', { preserveScroll: true, onSuccess: () => form.reset() })">
                <FormErrorSummary :errors="form.errors" />
                <div>
                    <label for="new-deceased" class="field-label">رقم هوية المتوفى</label>
                    <input id="new-deceased" data-error-field="deceased_national_id" v-model="form.deceased_national_id" class="field" dir="ltr" maxlength="40" required>
                </div>
                <div>
                    <label for="new-relationship" class="field-label">صفتك وعلاقتك بالمتوفى أو الأطفال</label>
                    <textarea id="new-relationship" data-error-field="relationship" v-model="form.relationship" class="field" maxlength="200" required />
                </div>
                <button type="submit" :disabled="form.processing" class="btn btn-primary">حفظ طلب جديد</button>
            </form>
        </details>
    </GuardianLayout>
</template>
