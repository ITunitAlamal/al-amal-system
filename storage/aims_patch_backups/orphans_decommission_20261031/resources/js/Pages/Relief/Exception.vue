<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';

// A campaign exception: decided personally and exclusively by the executive director, with a recorded reason.
defineProps({ exception: { type: Object, required: true }, progress: { type: Object, default: null }, workflow: { type: Object, default: null } });
</script>

<template>
    <AppLayout :title="`استثناء ${exception.reference_no}`">
        <div class="mx-auto max-w-4xl space-y-4">
            <Link :href="`/relief/campaigns/${exception.campaign.public_id}?tab=exceptions`" class="text-sm font-semibold text-blue-700 hover:underline">→ الحملة {{ exception.campaign.reference_no }}</Link>
            <PageHeader eyebrow="استثناء من قواعد حملة" :title="`${exception.reference_no} — ${exception.unit}`" description="القرار للمدير التنفيذي وحده ولا يُفوَّض؛ لا يصل المستثنى إلى أي دفعة ميدانية قبل إعادة اعتماد الكشف.">
                <template #meta><div class="mt-2"><StatusBadge :label="exception.status_label" :tone="['approved', 'applied'].includes(exception.status) ? 'success' : exception.status === 'rejected' ? 'danger' : 'info'" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>
            <section class="card space-y-2 text-sm">
                <p><span class="text-slate-500">الفئة:</span> {{ exception.category }} — <span class="text-slate-500">القاعدة المخالفة:</span> {{ exception.rule_violated }}</p>
                <p><span class="text-slate-500">الشرح:</span> {{ exception.explanation }}</p>
                <p><span class="text-slate-500">توصية الموظف ({{ exception.requested_by }}):</span> {{ exception.staff_recommendation }}</p>
                <p v-if="exception.head_recommendation"><span class="text-slate-500">رأي رئيس القسم:</span> {{ exception.head_recommendation }}</p>
                <p><span class="text-slate-500">المقترح:</span> <span v-for="p in exception.proposed" :key="p.label" class="me-2">{{ p.label }}: {{ p.quantity }}</span></p>
                <p v-if="exception.budget_impact !== null || exception.stock_impact"><span class="text-slate-500">الأثر:</span> {{ exception.budget_impact ?? '—' }} {{ exception.stock_impact }}</p>
                <p v-if="exception.decided_by"><span class="text-slate-500">القرار:</span> {{ exception.decided_by }} — {{ String(exception.decided_at).slice(0, 16) }} — {{ exception.decision_reason }}</p>
                <p class="text-xs text-slate-500">صالح حتى {{ exception.expires_on }}.</p>
            </section>
            <WorkflowActions :workflow="workflow" />
        </div>
    </AppLayout>
</template>
