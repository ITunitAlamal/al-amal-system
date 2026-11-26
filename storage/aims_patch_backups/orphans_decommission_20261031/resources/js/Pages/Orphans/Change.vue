<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';

// A change request on an approved file: value before and after, reason, stepper and the decision actions.
defineProps({
    change: { type: Object, required: true },
    diff: { type: Array, default: () => [] },
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
    canStart: Boolean,
});
</script>

<template>
    <AppLayout :title="`طلب تعديل ${change.reference_no}`">
        <div class="mx-auto max-w-4xl space-y-4">
            <Link :href="`/orphans/households/${change.household.public_id}`" class="text-sm font-semibold text-blue-700 hover:underline">→ ملف الأسرة {{ change.household.reference_no }}</Link>
            <PageHeader eyebrow="تعديل ملف معتمد" :title="`${change.category} — ${change.reference_no}`" description="لا يُكتب التعديل على الملف إلا عند قرار المدير التنفيذي (أو مفوضه المكتوب)، وتبقى القيمة السابقة في السجل.">
                <template #meta><div class="mt-2"><StatusBadge :label="change.status_label" :tone="change.status === 'applied' ? 'success' : change.status === 'rejected' ? 'danger' : 'info'" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>
            <section class="card space-y-3 text-sm">
                <table class="w-full"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الحقل</th><th>القيمة الحالية</th><th>القيمة المطلوبة</th></tr></thead>
                    <tbody><tr v-for="row in diff" :key="row.field" class="border-t border-slate-100"><td class="py-2 font-semibold">{{ row.field }}</td><td>{{ row.before }}</td><td class="text-blue-800">{{ row.after }}</td></tr></tbody></table>
                <p><span class="text-slate-500">السبب:</span> {{ change.reason }}<span v-if="change.from_portal" class="ms-1 text-xs text-slate-500">(مقدم من بوابة الوصي)</span></p>
                <p v-if="change.decision_note"><span class="text-slate-500">ملاحظة القرار:</span> {{ change.decision_note }}</p>
                <button v-if="canStart" type="button" class="btn btn-primary btn-sm" @click="router.post(`/orphans/changes/${change.id}/start`, {}, { preserveScroll: true })">تحققت من الطلب وأدلته — رفعه لرئيس القسم</button>
            </section>
            <WorkflowActions :workflow="workflow" />
        </div>
    </AppLayout>
</template>
