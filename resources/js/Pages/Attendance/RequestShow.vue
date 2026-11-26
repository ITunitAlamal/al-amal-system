<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';
import { requestStatusTone } from '../../Components/Attendance/attendanceUi.js';

/**
 * One attendance request («طلب إذن» or «تبديل المناوبة»): details, the approval path, and what this viewer may do now.
 * Approvers act from here (opened from «مهامي» or a notification); HR also acts inline in /admin/attendance.
 */
const props = defineProps({
    kind: { type: String, required: true },
    id: { type: Number, required: true },
    details: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    progress: { type: Object, default: () => ({ steps: [], summary: null }) },
    workflow: { type: Object, default: null },
});
const note = ref('');
const reason = ref('');
const busy = ref(false);
const errors = ref({});
function post(url, data, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return;
    busy.value = true;
    router.post(url, data, { preserveScroll: true, onError: e => { errors.value = e; }, onFinish: () => { busy.value = false; } });
}
const base = props.kind === 'permission' ? `/attendance/permissions/${props.id}` : `/attendance/swaps/${props.id}`;
</script>

<template>
    <AppLayout :title="kind === 'permission' ? 'طلب إذن' : 'طلب تبديل مناوبة'">
        <div dir="rtl" class="mx-auto max-w-4xl space-y-4">
            <PageHeader eyebrow="الحضور والانصراف" :title="kind === 'permission' ? 'طلب إذن' : 'طلب تبديل مناوبة'"
                :description="workflow ? `المرجع ${workflow.referenceNo}` : 'لم يدخل مسار الاعتماد بعد.'">
                <a href="/my/profile?tab=attendance" class="btn btn-secondary">ملفي — الحضور والانصراف</a>
            </PageHeader>

            <section class="card space-y-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-bold" :class="requestStatusTone[details.status]">{{ details.status_label }}</span>
                    <template v-if="kind === 'permission'">
                        <strong>{{ details.type }}</strong><span dir="ltr">{{ details.work_date }} {{ details.from }}–{{ details.to }}</span><span>{{ details.minutes }} دقيقة</span>
                        <span class="text-xs text-slate-500">{{ details.source }}</span>
                    </template>
                    <strong v-else>{{ details.requester }} ⇄ {{ details.counterpart }}</strong>
                </div>
                <p v-if="details.effect" class="text-xs text-slate-600">الأثر: {{ details.effect }}. لا يُخصم شيء تلقائيًا، والبصمة الأصلية لا تتغير.</p>
                <table v-if="kind === 'swap'" class="data-table">
                    <thead><tr><th class="p-2">اليوم</th><th>وردية {{ details.requester }}</th><th>وردية {{ details.counterpart }}</th></tr></thead>
                    <tbody><tr v-for="d in details.days" :key="d.date" class="border-t"><td class="p-2" dir="ltr">{{ d.date }}</td><td>{{ d.requester }}</td><td>{{ d.counterpart }}</td></tr></tbody>
                </table>
                <p><span class="font-semibold">السبب:</span> {{ details.reason }}</p>
                <p v-if="details.counterpart_note"><span class="font-semibold">رد الزميل:</span> {{ details.counterpart_note }} <span class="text-xs text-slate-500">{{ details.counterpart_responded_at }}</span></p>
                <p v-if="details.decision_note"><span class="font-semibold">ملاحظة القرار:</span> {{ details.decision_note }} <span class="text-xs text-slate-500">{{ details.decided_at }}</span></p>
                <p v-if="details.revocation_reason || details.reversal_reason"><span class="font-semibold">سبب الإلغاء بعد الاعتماد:</span> {{ details.revocation_reason || details.reversal_reason }}</p>
                <a v-if="details.attachment_url" :href="details.attachment_url" class="text-navy-700 underline">المرفق: {{ details.attachment_name }}</a>
            </section>

            <section v-if="progress.steps.length" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>
            <WorkflowActions :workflow="workflow" />

            <section v-if="can.respond || can.cancel || can.revoke || can.reverse" class="card space-y-3">
                <h2 class="card-title">إجراءاتك على الطلب</h2>
                <div v-if="can.respond" class="flex flex-wrap gap-2">
                    <input v-model="note" class="field min-w-0 flex-1" maxlength="1000" placeholder="ملاحظة (اختيارية)" aria-label="ملاحظة الرد" />
                    <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="post(`${base}/respond`, { accept: true, note }, 'الموافقة على التبديل ورفعه للاعتماد؟')">موافقة</button>
                    <button type="button" class="btn btn-danger btn-sm" :disabled="busy" @click="post(`${base}/respond`, { accept: false, note }, 'الاعتذار عن التبديل؟')">اعتذار</button>
                </div>
                <button v-if="can.cancel" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="post(`${base}/cancel`, {}, 'سحب الطلب؟')">سحب الطلب</button>
                <div v-if="can.revoke || can.reverse" class="flex flex-wrap gap-2">
                    <input v-model="reason" class="field min-w-0 flex-1" maxlength="2000" placeholder="سبب الإلغاء بعد الاعتماد (إلزامي)" aria-label="سبب الإلغاء" />
                    <button type="button" class="btn btn-danger btn-sm" :disabled="busy || reason.trim().length < 10"
                        @click="post(kind === 'permission' ? `/admin/attendance/permissions/${id}/revoke` : `/admin/attendance/swaps/${id}/reverse`, { reason }, 'إلغاء الطلب المعتمد وإعادة احتساب الأيام المتأثرة؟')">
                        {{ kind === 'permission' ? 'إلغاء الإذن المعتمد' : 'إلغاء التبديل المعتمد' }}</button>
                </div>
                <p v-for="(message, key) in errors" :key="key" class="field-error">{{ message }}</p>
            </section>
        </div>
    </AppLayout>
</template>
