<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// An assistance request: decided by the executive director, executed by Accounts/stores, closed only with delivery proof.
const props = defineProps({
    request: { type: Object, required: true },
    documents: { type: Array, default: () => [] },
    householdDocuments: { type: Array, default: () => [] },
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
    options: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
const r = props.request;
const form = useForm({ child_public_id: r.child?.public_id ?? null, assistance_type_value_id: r.assistance_type_value_id, description: r.description, quantity: r.quantity,
    estimated_value: r.estimated_value ?? '', currency: r.currency, funding_source: r.funding_source ?? '', evidence_document_ids: [...r.evidence_document_ids] });
function save() { form.put(`/orphans/assistance/${r.public_id}`, { preserveScroll: true }); }
const tone = { in_review: 'info', returned: 'warning', approved: 'info', executed: 'warning', delivered: 'success', rejected: 'danger' };
const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hint = {
    approved: 'معتمد من المدير التنفيذي وبانتظار التنفيذ لدى الحسابات/المخازن: يكتب المنفذ رقم المستند في التعليق.',
    executed: 'نُفّذ؛ يؤكد مقدم الطلب التسليم للمستفيد ويكتب في التعليق وصف الإثبات (من استلم، متى، المستند).',
};
</script>

<template>
    <AppLayout :title="`طلب مساعدة ${request.reference_no}`">
        <div class="mx-auto max-w-4xl space-y-4">
            <Link :href="`/orphans/households/${request.household.public_id}`" class="text-sm font-semibold text-blue-700 hover:underline">→ ملف الأسرة {{ request.household.reference_no }}</Link>
            <PageHeader eyebrow="مساعدة لأسرة/يتيم" :title="`${request.reference_no} — ${request.type || 'مساعدة'}`" description="الاعتماد وحده ليس تسليمًا: يُغلق الطلب بعد تنفيذ الحسابات وإثبات التسليم.">
                <template #meta><div class="mt-2"><StatusBadge :label="request.status_label" :tone="tone[request.status]" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>
            <p v-if="hint[request.status]" class="rounded-xl bg-gold-50 p-3 text-sm text-gold-900">{{ hint[request.status] }}</p>
            <WorkflowActions :workflow="workflow" />

            <section class="card">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">المستفيد</dt><dd>{{ request.child ? `${request.child.name} (${request.child.file_no || 'قيد الدراسة'})` : `الأسرة ${request.household.reference_no}` }}</dd></div>
                    <div><dt class="text-slate-500">مقدم الطلب</dt><dd>{{ request.created_by }} — {{ String(request.created_at).slice(0, 10) }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">الحاجة ومبرراتها</dt><dd class="whitespace-pre-line">{{ request.description }}</dd></div>
                    <div><dt class="text-slate-500">الكمية</dt><dd>{{ request.quantity ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">القيمة التقديرية</dt><dd>{{ request.estimated_value ? `${money(request.estimated_value)} ${request.currency}` : '—' }}</dd></div>
                    <div><dt class="text-slate-500">مصدر التمويل المقترح</dt><dd>{{ request.funding_source || '—' }}</dd></div>
                    <div><dt class="text-slate-500">المستندات الداعمة</dt><dd><span v-for="d in documents" :key="d.id" class="me-2">{{ d.type }}</span><span v-if="!documents.length">—</span></dd></div>
                    <div v-if="request.execution_reference"><dt class="text-slate-500">مرجع التنفيذ</dt><dd>{{ request.execution_reference }} — {{ request.executed_by }} ({{ String(request.executed_at).slice(0, 10) }})</dd></div>
                    <div v-if="request.delivery_proof"><dt class="text-slate-500">إثبات التسليم</dt><dd>{{ request.delivery_proof }} — {{ request.delivered_by }} ({{ String(request.delivered_at).slice(0, 10) }})</dd></div>
                </dl>
            </section>

            <form v-if="can.edit && options" class="card space-y-3" data-error-scope @submit.prevent="save">
                <h2 class="card-title">استكمال الطلب المعاد</h2>
                <p class="text-xs text-slate-500">احفظ الاستكمال ثم اختر «رفع لرئيس القسم» من «الإجراء المطلوب منك».</p>
                <FormErrorSummary :errors="form.errors" />
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <label class="block"><span class="field-label">نوع المساعدة</span><select v-model="form.assistance_type_value_id" class="field"><option :value="null">—</option><option v-for="o in options.assistance_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label class="block"><span class="field-label">الكمية</span><input v-model="form.quantity" type="number" min="0.01" step="0.01" class="field"></label>
                    <label class="block sm:col-span-2"><span class="field-label">الحاجة ومبرراتها *</span><textarea v-model="form.description" class="field" rows="3" minlength="10" maxlength="2000" required></textarea></label>
                    <label class="block"><span class="field-label">القيمة التقديرية</span><input v-model="form.estimated_value" inputmode="decimal" dir="ltr" class="field"></label>
                    <label class="block"><span class="field-label">العملة</span><select v-model="form.currency" class="field"><option :value="null">—</option><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                    <label class="block sm:col-span-2"><span class="field-label">مصدر التمويل المقترح</span><input v-model="form.funding_source" class="field" maxlength="200"></label>
                    <fieldset v-if="householdDocuments.length" class="sm:col-span-2"><legend class="field-label">المستندات الداعمة</legend>
                        <label v-for="d in householdDocuments" :key="d.id" class="me-4 inline-flex items-center gap-1"><input v-model="form.evidence_document_ids" type="checkbox" :value="d.id"> {{ d.label }}</label>
                    </fieldset>
                </div>
                <button class="btn btn-primary btn-sm" :disabled="form.processing">حفظ الاستكمال</button>
            </form>
        </div>
    </AppLayout>
</template>
