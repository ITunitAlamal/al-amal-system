<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';

// One structural proposal: what changes and why; the executive director decides on the central workflow.
const props = defineProps({
    change: { type: Object, required: true },
    blockers: { type: Array, default: () => [] },
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
    canWithdraw: { type: Boolean, default: false },
});
const tone = { suggested: 'muted', in_review: 'info', returned: 'warning', rejected: 'danger', withdrawn: 'muted', applied: 'success' };
const actions = computed(() => props.workflow?.actions ?? []);
const selected = ref(null);
const chosen = computed(() => actions.value.find(action => action.id === selected.value) || null);
const actionForm = useForm({ transition_id: null, revision: props.workflow?.revision ?? 0, comment: '' });
function act() {
    actionForm.transition_id = selected.value;
    actionForm.revision = props.workflow.revision;
    actionForm.post(`/workflows/${props.workflow.publicId}/act`, { preserveScroll: true, onSuccess: () => { selected.value = null; actionForm.reset('comment'); } });
}
const withdrawForm = useForm({ reason: '' });
function withdraw() {
    const reason = window.prompt('سبب سحب المقترح:');
    if (!reason || reason.trim().length < 5) { return; }
    withdrawForm.reason = reason;
    withdrawForm.post(`/admin/organization/changes/${props.change.id}/withdraw`, { preserveScroll: true });
}
const summary = computed(() => {
    const c = props.change;
    return {
        create_department: `إنشاء القسم المستقل «${c.proposed_name}» بلا تبعية لقسم آخر`,
        create_unit: `إنشاء الوحدة «${c.proposed_name}» تحت «${c.parent}»`,
        convert_to_unit: `تحويل «${c.node}» إلى وحدة تحت «${c.parent}»${c.proposed_name ? ` باسم «${c.proposed_name}»` : ''}`,
        rename: `إعادة تسمية «${c.node}» إلى «${c.proposed_name}»`,
        retire: `أرشفة «${c.node}»`,
        reparent: c.parent ? `نقل «${c.node}» ليتبع «${c.parent}»` : `جعل «${c.node}» قسمًا رئيسيًا`,
        reactivate: `إعادة تفعيل «${c.node}»`,
    }[c.type] || c.type_label;
});
</script>

<template>
    <AppLayout title="مقترح تعديل الهيكل">
        <div class="mx-auto max-w-4xl space-y-4">
            <Link href="/admin/organization?tab=changes" class="text-sm font-semibold text-blue-700 hover:underline">→ الهيكل التنظيمي</Link>
            <PageHeader eyebrow="الهيكل التنظيمي" :title="summary" :description="change.reference_no ? `رقم المعاملة ${change.reference_no}` : ''">
                <template #meta><div class="mt-2"><StatusBadge :label="change.status_label" :tone="tone[change.status] || 'muted'" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>

            <section class="card space-y-3 text-sm">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div><dt class="text-slate-500">نوع التعديل</dt><dd class="font-semibold">{{ change.type_label }}</dd></div>
                    <div><dt class="text-slate-500">مقدم المقترح</dt><dd class="font-semibold">{{ change.proposer || 'اقتراح النظام' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">المبرر</dt><dd class="whitespace-pre-line">{{ change.rationale }}</dd></div>
                    <div v-if="change.details.description" class="sm:col-span-2"><dt class="text-slate-500">الوصف</dt><dd class="whitespace-pre-line">{{ change.details.description }}</dd></div>
                    <div v-if="change.details.responsibilities" class="sm:col-span-2"><dt class="text-slate-500">المسؤوليات</dt><dd class="whitespace-pre-line">{{ change.details.responsibilities }}</dd></div>
                    <div v-if="change.decider"><dt class="text-slate-500">صاحب القرار</dt><dd class="font-semibold">{{ change.decider }}{{ change.authority === 'owner_decision' ? ' (تنفيذ قرار المالك)' : '' }}</dd></div>
                    <div v-if="change.applied_at"><dt class="text-slate-500">طُبّق في</dt><dd dir="ltr" class="text-right">{{ String(change.applied_at).slice(0, 16) }}</dd></div>
                </dl>
                <div v-if="blockers.length" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900" role="alert">
                    <p class="font-semibold">لا يمكن الاعتماد قبل معالجة:</p>
                    <ul class="mt-1 list-inside list-disc"><li v-for="item in blockers" :key="item">{{ item }}</li></ul>
                </div>
                <p class="text-xs text-slate-500">الاعتماد يطبّق التعديل على الهيكل فورًا ويسجله؛ الأرشفة تخفي القسم من القوائم ولا تحذف سجله أو تاريخه.</p>
            </section>

            <section v-if="actions.length" class="card space-y-3">
                <h2 class="card-title">الإجراء المطلوب منك</h2>
                <div class="flex flex-wrap gap-2">
                    <button v-for="a in actions" :key="a.id" type="button" class="btn btn-sm" :class="selected === a.id ? 'btn-primary' : 'btn-secondary'" @click="selected = a.id; actionForm.clearErrors()">{{ a.name }}</button>
                </div>
                <form v-if="chosen" class="space-y-2" @submit.prevent="act">
                    <textarea v-model="actionForm.comment" rows="3" maxlength="5000" class="field" :required="Boolean(chosen.requires_comment)" :placeholder="chosen.requires_comment ? 'التعليق إلزامي لهذا الإجراء' : 'تعليق (اختياري)'"></textarea>
                    <p v-for="(message, key) in actionForm.errors" :key="key" class="field-error">{{ message }}</p>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="actionForm.processing">تأكيد: {{ chosen.name }}</button>
                </form>
            </section>
            <button v-if="canWithdraw" type="button" class="btn btn-secondary btn-sm" :disabled="withdrawForm.processing" @click="withdraw">سحب المقترح</button>
        </div>
    </AppLayout>
</template>
