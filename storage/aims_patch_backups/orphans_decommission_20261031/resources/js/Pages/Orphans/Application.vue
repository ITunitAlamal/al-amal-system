<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';
import DocumentsChecklist from '../../Components/Orphans/DocumentsChecklist.vue';

// First-time activation: each child is verified, recommended by the head and decided by the executive director separately.
const props = defineProps({
    application: { type: Object, required: true },
    children: { type: Array, default: () => [] },
    problems: { type: Array, default: () => [] },
    checklist: { type: Array, default: () => [] },
    manualReview: Boolean,
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
const notes = reactive({});
function act(row, action, value) {
    const note = notes[`${row.id}:${action}`] || '';
    router.post(`/orphans/application-children/${row.id}/${action}`, { value, note }, { preserveScroll: true });
}
const verifyTone = { pending: 'info', api_verified: 'success', manual_verified: 'success', conflict: 'danger', incomplete: 'warning' };
</script>

<template>
    <AppLayout :title="`طلب اعتماد ${application.reference_no}`">
        <div class="space-y-4">
            <Link :href="`/orphans/households/${application.household.public_id}`" class="text-sm font-semibold text-blue-700 hover:underline">→ ملف الأسرة {{ application.household.reference_no }}</Link>
            <PageHeader eyebrow="اعتماد ملف يتيم لأول مرة" :title="`الطلب ${application.reference_no}`" description="الموظف يراجع ويتحقق، رئيس القسم يوصي لكل طفل، والمدير التنفيذي يقرر لكل طفل؛ رقم الملف الرسمي يصدر عند الاعتماد فقط.">
                <template #meta><div class="mt-2"><StatusBadge :label="application.status_label" tone="info" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>

            <section v-if="!application.submitted" class="card space-y-2">
                <h2 class="card-title">جاهزية الرفع لرئيس القسم</h2>
                <ul v-if="problems.length" class="list-inside list-disc text-sm text-amber-800"><li v-for="p in problems" :key="p">{{ p }}</li></ul>
                <p v-else class="text-sm text-emerald-700">البيانات والمستندات المطلوبة مكتملة للرفع.</p>
                <p v-if="!manualReview" class="text-xs text-slate-500">التحقق اليدوي بالمستندات غير مفعّل بقرار المؤسسة؛ يبقى الطفل «بانتظار التحقق» حتى يتوفر التحقق من السجل المدني، ولا يُعتمد قبل ذلك.</p>
                <button v-if="can.submit" type="button" class="btn btn-primary btn-sm" :disabled="problems.length > 0" @click="router.post(`/orphans/applications/${application.id}/submit`, {}, { preserveScroll: true })">رفع الطلب لرئيس القسم</button>
            </section>

            <section class="card space-y-3">
                <h2 class="card-title">الأطفال في الطلب</h2>
                <div v-for="row in children" :key="row.id" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <Link :href="`/orphans/children/${row.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ row.name }}</Link>
                        <span class="text-slate-500">{{ row.age }} سنة — {{ row.class || 'دون تصنيف' }}<span v-if="row.file_no"> — {{ row.file_no }}</span></span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <StatusBadge :label="row.verification_label" :tone="verifyTone[row.verification]" />
                        <StatusBadge v-if="row.recommendation" :label="row.labels[row.recommendation]" tone="navy" />
                        <StatusBadge v-if="row.decision" :label="row.labels[row.decision]" :tone="row.decision === 'approved' ? 'success' : row.decision === 'rejected' ? 'danger' : 'warning'" />
                    </div>
                    <p v-if="row.verification_note" class="text-xs text-slate-600">التحقق: {{ row.verification_note }}</p>
                    <p v-if="row.recommendation_note" class="text-xs text-slate-600">التوصية: {{ row.recommendation_note }}</p>
                    <p v-if="row.decision_note" class="text-xs text-slate-600">القرار: {{ row.decision_note }}</p>
                    <div v-if="can.verify" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                        <input v-model="notes[`${row.id}:verify`]" class="field flex-1" maxlength="2000" placeholder="ما الذي رُوجع وأساس النتيجة">
                        <button v-if="manualReview" type="button" class="btn btn-secondary btn-sm" @click="act(row, 'verify', 'manual_verified')">متحقق بمراجعة موثقة</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="act(row, 'verify', 'incomplete')">ناقص</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="act(row, 'verify', 'conflict')">تعارض</button>
                    </div>
                    <div v-if="can.recommend" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                        <input v-model="notes[`${row.id}:recommend`]" class="field flex-1" maxlength="2000" placeholder="مبرر التوصية (إلزامي لغير الاعتماد)">
                        <button type="button" class="btn btn-primary btn-sm" @click="act(row, 'recommend', 'approve')">أوصي بالاعتماد</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="act(row, 'recommend', 'defer')">التأجيل</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="act(row, 'recommend', 'reject')">الرفض</button>
                    </div>
                    <div v-if="can.decide" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                        <input v-model="notes[`${row.id}:decide`]" class="field flex-1" maxlength="2000" placeholder="سبب القرار (إلزامي لغير الاعتماد)">
                        <button type="button" class="btn btn-primary btn-sm" @click="act(row, 'decide', 'approved')">اعتماد</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="act(row, 'decide', 'deferred')">تأجيل</button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="act(row, 'decide', 'rejected')">رفض</button>
                    </div>
                </div>
                <p v-if="can.decide" class="text-xs text-slate-500">ثم استخدم «اعتماد القرار لكل طفل وفق التوصيات» أدناه؛ الطفل الذي لم تحدد له قرارًا يأخذ توصية رئيس القسم.</p>
            </section>

            <WorkflowActions :workflow="workflow" />
            <section class="card space-y-2"><h2 class="card-title">المستندات</h2><DocumentsChecklist :items="checklist" :can-upload="false" :can-verify="can.verify" /></section>
        </div>
    </AppLayout>
</template>
