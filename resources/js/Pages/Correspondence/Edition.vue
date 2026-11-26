<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
const props = defineProps({ edition: Object, differences: Object, progress: Object, actions: Array, revision: Number, backUrl: String, attachments: Array });
const form = useForm({ transition_id: '', revision: props.revision, comment: '' });
const labels = { pending: 'قيد الاعتماد — غير نافذة', approved: 'نسخة معتمدة؛ النافذة هي آخر نسخة معتمدة', rejected: 'مرفوضة' };
const tones = { pending: 'warning', approved: 'success', rejected: 'danger' };
function submit() {
    form.revision = props.revision;
    form.post(`/correspondence/editions/${props.edition.public_id}/actions`, { preserveScroll: true, onSuccess: () => form.reset('comment', 'transition_id') });
}
</script>
<template>
    <AppLayout title="اعتماد نسخة بديلة">
        <main dir="rtl" class="mx-auto max-w-6xl space-y-5">
            <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-slate-500">مراجعة نص بديل</p>
                    <h1 class="mt-1 text-2xl font-bold text-navy-900">النسخة {{ edition.version }}</h1>
                    <div class="mt-3"><StatusBadge :status="edition.status" :label="labels[edition.status]" :tone="tones[edition.status]" /></div>
                </div>
                <Link :href="backUrl" class="btn btn-secondary">العودة إلى المراسلة</Link>
            </header>
            <p v-if="edition.origin_role === 'board'" role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950">معلّقة: لم تُوثق سلطة اعتماد تعديل نص المجلس. لا تمنح صلاحية السكرتاريا أو المدير التنفيذي حق اعتمادها.</p>
            <section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <h2 class="text-sm font-bold text-slate-700">سبب التعديل</h2>
                <p class="mt-2 whitespace-pre-wrap leading-7 text-slate-800">{{ edition.reason }}</p>
            </section>
            <section aria-label="مقارنة النصوص">
                <div class="mb-3">
                    <h2 class="text-lg font-bold text-navy-900">مقارنة النصوص</h2>
                    <p class="mt-1 text-sm text-slate-500">الأصل محفوظ دون تغيير؛ النسخة المقترحة لا تصبح نافذة إلا بعد اكتمال الاعتماد.</p>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">النص السابق</span>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800">{{ differences.before.subject }}</h3>
                        <p class="mt-3 whitespace-pre-wrap break-words leading-8 text-slate-700">{{ differences.before.body }}</p>
                    </article>
                    <article class="rounded-2xl border border-blue-200 bg-blue-50/40 p-5 shadow-sm">
                        <div class="mb-4 flex items-center gap-2 border-b border-blue-100 pb-3">
                            <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800">النص المقترح</span>
                        </div>
                        <h3 class="text-lg font-bold text-navy-900">{{ edition.subject }}</h3>
                        <p class="mt-3 whitespace-pre-wrap break-words leading-8 text-slate-800">{{ edition.body }}</p>
                    </article>
                </div>
            </section>
            <section v-if="attachments.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold text-navy-900">مرفقات هذه النسخة</h2>
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <li v-for="file in attachments" :key="file.public_id" class="p-3">
                        <a :href="`/correspondence/files/${file.public_id}`" class="font-medium text-blue-700 underline underline-offset-2">{{ file.original_filename }}</a>
                        <span class="ms-2 text-xs text-slate-500">الإصدار {{ file.document_version }}</span>
                    </li>
                </ul>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-bold text-navy-900">مراحل الاعتماد</h2>
                <StatusStepper :steps="progress.steps" :summary="progress.summary" />
            </section>
            <form v-if="actions.length" class="space-y-4 rounded-2xl border border-navy-200 bg-white p-5 shadow-sm" @submit.prevent="submit">
                <div>
                    <h2 class="font-bold text-navy-900">الإجراء المطلوب منك</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">تسجيل السكرتاريا وتدقيقها لا يمثل اعتمادًا إداريًا. اعتماد خطاب تنفيذ قرار مجلس يتضمن الإقرار بعدم تغيير مضمونه.</p>
                </div>
                <div v-if="Object.keys(form.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                    <p class="font-bold">تعذر تسجيل الإجراء:</p>
                    <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                </div>
                <label class="block text-sm font-semibold">الإجراء
                    <select v-model="form.transition_id" class="field mt-1 w-full" required><option value="">اختر الإجراء</option><option v-for="action in actions" :key="action.id" :value="action.id">{{ action.name }}</option></select>
                </label>
                <label class="block text-sm font-semibold">سبب القرار أو نتيجة المراجعة
                    <textarea v-model="form.comment" class="field mt-1 w-full" rows="4" required maxlength="5000"></textarea>
                </label>
                <button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ التسجيل…' : 'تسجيل الإجراء' }}</button>
            </form>
        </main>
    </AppLayout>
</template>
