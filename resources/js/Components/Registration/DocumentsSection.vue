<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { nameOf } from '../../Support/registration.js';

// Optional initial attachments: PDF/JPG/PNG up to 10MB, stored privately and attributed to a person, a
// relationship or the household. A missing document is a completion item, never a blocker.
const props = defineProps({ form: Object, options: Object, documents: Array, missing: Array, editable: Boolean, documentsUrl: String, beforeUpload: Function });
const file = ref(null);
const input = ref(null);
const type = ref(null);
const target = ref('household');
const busy = ref(false);
const error = ref('');
const targets = computed(() => {
    const list = [['request', 'الطلب عامة'], ['household', 'الأسرة'], ['father', 'الأب'], ['mother', 'الأم']];
    if (nameOf(props.form.request.applicant)) list.push(['applicant', 'مقدم الطلب']);
    props.form.guardians.forEach((g, i) => list.push([`guardian:${g.key}`, `الوصي ${i + 1}${g.role === 'mother' ? ' — الأم' : g.role === 'applicant' ? ' — مقدم الطلب' : nameOf(g) ? ` — ${nameOf(g)}` : ''}`]));
    props.form.children.forEach((c, i) => list.push([`child:${c.key}`, `اليتيم ${i + 1}${nameOf(c) ? ` — ${nameOf(c)}` : ''}`]));
    return list;
});
const targetLabel = key => targets.value.find(([k]) => k === key)?.[1] || 'غير محدد';
function pick(event) {
    error.value = '';
    const chosen = event.target.files?.[0] || null;
    if (chosen && !['application/pdf', 'image/jpeg', 'image/png'].includes(chosen.type)) { error.value = 'الأنواع المسموحة: PDF وJPG وPNG.'; event.target.value = ''; return; }
    if (chosen && chosen.size > 10 * 1024 * 1024) { error.value = 'الحد الأعلى لحجم الملف 10 ميغابايت.'; event.target.value = ''; return; }
    file.value = chosen;
}
async function upload() {
    if (!file.value || busy.value) return;
    busy.value = true;
    error.value = '';
    // The draft is saved first so that a newly added person can own the document.
    await props.beforeUpload();
    router.post(props.documentsUrl, { file: file.value, document_type_value_id: type.value, target: target.value }, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { file.value = null; if (input.value) input.value.value = ''; },
        onError: errors => { error.value = Object.values(errors).join(' • '); },
        onFinish: () => { busy.value = false; },
    });
}
function remove(doc) {
    if (!window.confirm(`حذف المرفق «${doc.original_name}»؟`)) return;
    router.delete(`${props.documentsUrl}/${doc.public_id}`, { preserveScroll: true });
}
</script>

<template>
    <section class="card space-y-5">
        <h2 class="card-title" tabindex="-1" data-step-heading>المرفقات والمستندات (اختيارية عند التقديم)</h2>
        <p class="text-sm text-slate-600">يمكن إرسال الطلب دون مرفقات؛ يطلب القسم الناقص لاحقًا. الملفات تُحفظ بشكل خاص ولا تُنشر بروابط عامة.</p>
        <div v-if="editable" class="grid gap-3 rounded-xl border border-blue-200 bg-blue-50/40 p-4 sm:grid-cols-4">
            <label class="text-sm">صاحب المستند<select v-model="target" data-error-field="target" class="field mt-1"><option v-for="[key, label] in targets" :key="key" :value="key">{{ label }}</option></select></label>
            <label class="text-sm">نوع المستند<select v-model="type" data-error-field="document_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="d in options.documents" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
            <label class="text-sm">الملف (PDF أو صورة)<input ref="input" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-error-field="file" class="field mt-1" @change="pick"></label>
            <button type="button" class="btn btn-primary self-end" :disabled="!file || busy" @click="upload">{{ busy ? 'جارٍ الرفع…' : 'إرفاق' }}</button>
            <p v-if="error" class="field-error sm:col-span-4" role="alert">{{ error }}</p>
        </div>
        <div>
            <h3 class="font-semibold text-navy-800">المرفقات ({{ documents.length }})</h3>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                <li v-for="d in documents" :key="d.public_id" class="flex flex-wrap items-center gap-2 py-2">
                    <span class="font-medium">{{ d.type || 'مستند' }}</span><span class="text-slate-500">· {{ targetLabel(d.target) }} · {{ d.original_name }}</span>
                    <span class="text-xs text-slate-500">{{ { pending: 'بانتظار المراجعة', accepted: 'مقبول', rejected: 'مرفوض' }[d.review_status] }}</span>
                    <button v-if="editable && d.review_status === 'pending'" type="button" class="btn btn-sm btn-danger ms-auto" @click="remove(d)">حذف</button>
                </li>
                <li v-if="!documents.length" class="py-2 text-slate-500">لا توجد مرفقات بعد.</li>
            </ul>
        </div>
        <div v-if="missing.length" class="rounded-xl bg-gold-50 p-4 text-sm">
            <h3 class="font-semibold text-gold-900">مستندات مفيدة لم تُرفق بعد</h3>
            <ul class="mt-2 list-disc space-y-1 ps-5"><li v-for="m in missing" :key="`${m.target}-${m.type}`">{{ m.label }}</li></ul>
            <p class="mt-2 text-xs text-slate-600">قائمة استكمال فقط؛ لا تمنع الإرسال.</p>
        </div>
    </section>
</template>
