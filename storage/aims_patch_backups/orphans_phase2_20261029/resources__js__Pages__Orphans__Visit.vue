<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// A social visit: the researcher's attested report, then the head's review on the workflow.
const props = defineProps({
    visit: { type: Object, required: true },
    canReport: Boolean,
    canSubmit: Boolean,
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
});
const form = useForm({
    visited_on: props.visit.visited_on || '', interviewed: props.visit.interviewed || '', social_findings: props.visit.social_findings || '',
    economic_findings: props.visit.economic_findings || '', housing_findings: props.visit.housing_findings || '', recommendations: props.visit.recommendations || '',
    needs: props.visit.needs || [], action_items: props.visit.action_items || [], next_visit_on: props.visit.next_visit_on || '',
});
const needsText = ref((props.visit.needs || []).join('\n'));
const actionsText = ref((props.visit.action_items || []).join('\n'));
const toLines = text => text.split('\n').map(s => s.trim()).filter(Boolean);
function save() { form.transform(data => ({ ...data, needs: toLines(needsText.value), action_items: toLines(actionsText.value) })).put(`/orphans/visits/${props.visit.id}`, { preserveScroll: true }); }
function submit() {
    if (!window.confirm('أقر بأن ما ورد في التقرير صحيح وفق ما شاهدته وسمعته في الزيارة. هل ترفعه لرئيس القسم؟')) return;
    router.post(`/orphans/visits/${props.visit.id}/submit`, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="`زيارة ${visit.reference_no}`">
        <div class="mx-auto max-w-4xl space-y-4">
            <Link :href="`/orphans/households/${visit.household.public_id}`" class="text-sm font-semibold text-blue-700 hover:underline">→ ملف الأسرة {{ visit.household.reference_no }}</Link>
            <PageHeader eyebrow="زيارة اجتماعية" :title="`${visit.reference_no} — ${visit.scheduled_on}`" :description="`الباحث: ${visit.researcher}${visit.type ? ' — ' + visit.type : ''}${visit.child ? ' — ' + visit.child : ''}`">
                <template #meta><div class="mt-2"><StatusBadge :label="visit.status_label" tone="info" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>
            <section class="card text-sm"><p><span class="text-slate-500">سبب الزيارة:</span> {{ visit.reason }}</p>
                <p v-if="visit.review_note" class="mt-2"><span class="text-slate-500">ملاحظة المراجعة:</span> {{ visit.review_note }}</p>
                <p class="mt-2 text-xs text-slate-500">التصوير والموقع الجغرافي غير مفعّلين: لا يوجد أساس موافقة وسياسة معتمدة لهما.</p></section>
            <form v-if="canReport" class="card space-y-3" data-error-scope @submit.prevent="save">
                <h2 class="card-title">تقرير الزيارة</h2>
                <FormErrorSummary :errors="form.errors" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm"><span class="field-label">تاريخ الزيارة *</span><input v-model="form.visited_on" type="date" class="field" required></label>
                    <label class="block text-sm"><span class="field-label">الزيارة القادمة</span><input v-model="form.next_visit_on" type="date" class="field"></label>
                    <label class="block text-sm sm:col-span-2"><span class="field-label">من قوبل *</span><input v-model="form.interviewed" class="field" maxlength="500" required></label>
                    <label v-for="[key, label] in [['social_findings', 'الوضع الاجتماعي'], ['economic_findings', 'الوضع الاقتصادي'], ['housing_findings', 'السكن'], ['recommendations', 'التوصيات *']]" :key="key" class="block text-sm sm:col-span-2">
                        <span class="field-label">{{ label }}</span><textarea v-model="form[key]" class="field" rows="3" maxlength="3000"></textarea></label>
                    <label class="block text-sm"><span class="field-label">الاحتياجات (سطر لكل احتياج)</span><textarea v-model="needsText" class="field" rows="3"></textarea></label>
                    <label class="block text-sm"><span class="field-label">إجراءات المتابعة (سطر لكل إجراء)</span><textarea v-model="actionsText" class="field" rows="3"></textarea></label>
                </div>
                <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="form.processing">حفظ التقرير</button>
                    <button v-if="canSubmit" type="button" class="btn btn-secondary btn-sm" @click="submit">إقرار ورفع لرئيس القسم</button></div>
            </form>
            <section v-else class="card space-y-2 text-sm">
                <p><span class="text-slate-500">تاريخ الزيارة:</span> {{ visit.visited_on || '—' }} — <span class="text-slate-500">من قوبل:</span> {{ visit.interviewed || '—' }}</p>
                <p v-for="[key, label] in [['social_findings', 'الوضع الاجتماعي'], ['economic_findings', 'الوضع الاقتصادي'], ['housing_findings', 'السكن'], ['recommendations', 'التوصيات']]" :key="key" v-show="visit[key]"><span class="text-slate-500">{{ label }}:</span> {{ visit[key] }}</p>
                <p v-if="visit.needs.length"><span class="text-slate-500">الاحتياجات:</span> {{ visit.needs.join('، ') }}</p>
                <p v-if="visit.action_items.length"><span class="text-slate-500">المتابعة:</span> {{ visit.action_items.join('، ') }}</p>
            </section>
            <WorkflowActions :workflow="workflow" />
        </div>
    </AppLayout>
</template>
