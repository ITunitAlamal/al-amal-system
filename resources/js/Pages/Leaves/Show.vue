<script setup>
import { arabicLatinDigitsLocale, formatDateTime } from '../../Support/dates.js';
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import StepAssignmentPanel from '../../Components/Workflow/StepAssignmentPanel.vue';

const props = defineProps({
    username: String,
    navigation: Array,
    request: Object,
    assignment: { type: Object, default: null },
    progress: { type: Object, default: () => ({ steps: [], summary: null }) },
    workflow: { type: Object, default: null },
    canApprove: Boolean,
    isOwner: Boolean,
    canEdit: Boolean,
    settings: Object,
    history: Array,
});

const statusLabels = {
    draft: 'مسودة', submitted: 'قيد المراجعة', manager_approved: 'بانتظار الاعتماد النهائي',
    pending_registration: 'معتمدة — بانتظار الإبلاغ وتسجيل الموارد البشرية',
    returned: 'مُعاد للتعديل', approved: 'معتمدة ومسجلة', rejected: 'مرفوضة', cancelled: 'ملغاة',
};
const statusTones = { draft: 'warning', submitted: 'info', manager_approved: 'info', pending_registration: 'info', returned: 'warning', approved: 'success', rejected: 'danger', cancelled: 'muted' };
const statusLabel = status => (status === 'submitted' && !props.workflow ? 'بانتظار الاعتماد' : statusLabels[status] || status);
const formattedDate = value => (value ? new Intl.DateTimeFormat(arabicLatinDigitsLocale, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : '—');
const formattedDateTime = value => formatDateTime(value);

// Legacy single-step decision.
const decision = useForm({ decision: 'approve', comment: '' });
const decide = () => decision.post(`/leaves/${props.request.id}/decision`, { preserveScroll: true });
const canDecide = computed(() => props.canApprove && props.request.status === 'submitted');
const cancelForm = useForm({});
const submitForm = useForm({});
const canCancel = computed(() => props.isOwner && ['draft', 'submitted', 'manager_approved', 'returned'].includes(props.request.status));
function cancelRequest() {
    if (!window.confirm('إلغاء طلب الإجازة؟ لن يُخصم أي رصيد.')) return;
    cancelForm.post(`/leaves/${props.request.id}/cancel`);
}

// Workflow actions of the step this user holds now.
const actions = computed(() => props.workflow?.actions ?? []);
const selectedAction = ref(null);
const chosen = computed(() => actions.value.find(action => action.id === selectedAction.value) || null);
const actionForm = useForm({ transition_id: null, revision: props.workflow?.revision ?? 1, comment: '' });
watch(() => props.workflow?.revision, value => { actionForm.revision = value ?? 1; });
const isBackward = action => ['reject', 'return'].includes(action.action_code);
function choose(action) {
    selectedAction.value = action.id;
    actionForm.clearErrors();
}
function submitAction() {
    if (!chosen.value) return;
    actionForm.transition_id = chosen.value.id;
    actionForm.post(`/workflows/${props.workflow.publicId}/act`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedAction.value = null;
            actionForm.reset('comment');
        },
    });
}
</script>

<template>
    <AppLayout title="طلب إجازة" :navigation="navigation">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link href="/leaves" class="text-sm font-semibold text-blue-700 hover:underline">→ طلبات الإجازات</Link>
            <PageHeader eyebrow="طلب إجازة" :title="workflow?.referenceNo ? `طلب الإجازة ${workflow.referenceNo}` : `طلب الإجازة #${request.id}`">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <StatusBadge :label="statusLabel(request.status)" :tone="statusTones[request.status] || 'muted'" />
                        <span v-if="workflow?.name">· {{ workflow.name }}</span>
                    </div>
                </template>
            </PageHeader>

            <section class="card">
                <h2 class="card-title mb-4">مراحل الطلب</h2>
                <StatusStepper :steps="progress.steps" :summary="progress.summary" />
                <p v-if="progress.steps.some(step => step.state === 'upcoming')" class="mt-3 text-xs text-slate-500">المراحل الرمادية هي المسار المتوقع حسب إعدادات مسار الإجازات. {{ workflow || settings?.approval_mode === 'workflow' ? 'يُخصم الرصيد مرة واحدة عند تسجيل الموارد البشرية للإجازة، لا عند الاعتماد.' : 'يُخصم الرصيد مرة واحدة عند اعتماد الطلب.' }}</p>
            </section>
            <StepAssignmentPanel v-if="assignment && workflow" :assignment="assignment" :public-id="workflow.publicId" :revision="workflow.revision" />

            <section v-if="actions.length" class="card border-2 border-gold-300">
                <h2 class="card-title">الإجراء المطلوب منك</h2>
                <p class="mt-1 text-sm text-slate-500">
                    <template v-if="isOwner">أُعيد الطلب إليك. عدّله إن لزم، ثم أعد إرساله ليكمل مساره.</template>
                    <template v-else>اختر الإجراء ثم أكّده. يُسجَّل قرارك باسمك في سجل الطلب.</template>
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link v-if="canEdit && request.status === 'returned'" :href="`/leaves/${request.id}/edit`" class="btn btn-secondary">تعديل الطلب</Link>
                    <button
                        v-for="action in actions"
                        :key="action.id"
                        type="button"
                        :class="['btn', selectedAction === action.id ? (isBackward(action) ? 'bg-red-600 text-white' : 'btn-primary') : isBackward(action) ? 'btn-danger' : 'btn-secondary']"
                        :aria-pressed="selectedAction === action.id"
                        @click="choose(action)"
                    >
                        {{ action.name }}
                    </button>
                </div>
                <form v-if="chosen" class="mt-5 space-y-4 border-t border-slate-100 pt-5" @submit.prevent="submitAction">
                    <p class="alert-info">ينتقل الطلب إلى: <strong>{{ chosen.to_step_name }}</strong><span v-if="chosen.has_conditions"> (إذا تحققت شروط هذا الإجراء)</span><span v-if="chosen.ends_request && chosen.action_code !== 'reject'">، ويُخصم الرصيد مرة واحدة</span></p>
                    <div>
                        <label for="act-comment" class="field-label">الملاحظة أو السبب<span v-if="chosen.requires_comment" class="text-red-600"> *</span></label>
                        <textarea id="act-comment" v-model="actionForm.comment" rows="3" maxlength="5000" :required="chosen.requires_comment" class="field" />
                        <p v-if="actionForm.errors.comment" class="field-error">{{ actionForm.errors.comment }}</p>
                        <p v-if="actionForm.errors.transition" class="field-error">{{ actionForm.errors.transition }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" :disabled="actionForm.processing" :class="['btn px-6 py-2.5', isBackward(chosen) ? 'bg-red-600 text-white hover:bg-red-700' : 'btn-primary']">
                            {{ actionForm.processing ? 'جارٍ التنفيذ...' : `تأكيد: ${chosen.name}` }}
                        </button>
                        <button type="button" class="btn btn-secondary" @click="selectedAction = null">إلغاء</button>
                    </div>
                </form>
            </section>

            <section class="card">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div><dt class="text-xs text-slate-500">من</dt><dd class="mt-1 font-semibold text-navy-800">{{ formattedDate(request.start_date) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">إلى</dt><dd class="mt-1 font-semibold text-navy-800">{{ formattedDate(request.end_date) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">الأيام المحتسبة</dt><dd class="mt-1 font-semibold text-navy-800">{{ request.requested_days ?? 'تُحتسب عند الإرسال' }}</dd></div>
                </dl>
                <div class="mt-4 rounded-xl bg-slate-50 p-4">
                    <p class="text-xs text-slate-500">السبب</p>
                    <p class="mt-1 whitespace-pre-wrap text-sm leading-7">{{ request.reason }}</p>
                </div>
            </section>

            <!-- A registered leave has no cancel action: same guidance as the refusal in EmployeeLeaveRequestController::cancel. -->
            <p v-if="request.status === 'approved'" class="alert-info" role="note">
                <template v-if="isOwner">هذه الإجازة مسجلة وخُصمت من رصيدك، ولا يمكن إلغاؤها من هذه الصفحة. راجع رئيس قسم الموارد البشرية واذكر سبب الإلغاء؛ تُعاد الأيام إلى رصيدك بـ«تسوية موثقة» يسجلها مدير النظام من صفحة «أرصدة الإجازات».</template>
                <template v-else>هذه الإجازة مسجلة وخُصمت من رصيد الموظف، ولا تُلغى من هذه الصفحة. يراجع الموظف رئيس قسم الموارد البشرية ويذكر سبب الإلغاء؛ تُعاد الأيام إلى رصيده بـ«تسوية موثقة» يسجلها مدير النظام من صفحة «أرصدة الإجازات».</template>
            </p>

            <section v-if="canCancel" class="card flex flex-wrap gap-2">
                <Link v-if="canEdit && request.status === 'draft'" :href="`/leaves/${request.id}/edit`" class="btn btn-secondary">تعديل المسودة</Link>
                <button v-if="request.status === 'draft'" type="button" class="btn btn-primary" :disabled="submitForm.processing" @click="submitForm.post(`/leaves/${request.id}/submit`, { preserveScroll: true })">إرسال للاعتماد</button>
                <button type="button" class="btn btn-danger" :disabled="cancelForm.processing" @click="cancelRequest">إلغاء الطلب</button>
            </section>

            <form v-if="canDecide" class="card space-y-4 border-2 border-gold-300" @submit.prevent="decide">
                <h2 class="card-title">قرارك على الطلب</h2>
                <fieldset class="flex flex-wrap gap-2">
                    <legend class="sr-only">القرار</legend>
                    <label :class="['btn cursor-pointer', decision.decision === 'approve' ? 'btn-primary' : 'btn-secondary']"><input v-model="decision.decision" type="radio" value="approve" class="sr-only">اعتماد</label>
                    <label :class="['btn cursor-pointer', decision.decision === 'reject' ? 'bg-red-600 text-white' : 'btn-danger']"><input v-model="decision.decision" type="radio" value="reject" class="sr-only">رفض</label>
                </fieldset>
                <div>
                    <label for="leave-comment" class="field-label">تعليق<span v-if="decision.decision === 'reject'"> (يُنصح بذكر السبب)</span></label>
                    <textarea id="leave-comment" v-model="decision.comment" class="field" rows="3" maxlength="5000" />
                </div>
                <button type="submit" :disabled="decision.processing" :class="['btn px-6 py-2.5', decision.decision === 'reject' ? 'bg-red-600 text-white hover:bg-red-700' : 'btn-primary']">
                    {{ decision.processing ? 'جارٍ الحفظ...' : decision.decision === 'reject' ? 'تأكيد الرفض' : 'تأكيد الاعتماد' }}
                </button>
            </form>

            <section class="card">
                <h2 class="card-title mb-3">سجل إجراءات الطلب</h2>
                <EmptyState v-if="!history.length" title="لم تُسجل إجراءات بعد" />
                <ol v-else class="space-y-3 border-r-2 border-navy-100 pr-5">
                    <li v-for="(item, index) in history" :key="index" class="relative rounded-xl border border-slate-100 p-3 text-sm">
                        <span class="absolute -right-[1.85rem] top-4 h-4 w-4 rounded-full border-4 border-white bg-gold-400 ring-1 ring-gold-300" aria-hidden="true"></span>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p><strong class="text-navy-800">{{ item.name }}</strong>: {{ statusLabel(item.from_status) }} ← {{ statusLabel(item.to_status) }}</p>
                            <time class="text-xs text-slate-500">{{ formattedDateTime(item.created_at) }}</time>
                        </div>
                        <p v-if="item.comment" class="mt-1 text-slate-600">{{ item.comment }}</p>
                    </li>
                </ol>
            </section>
        </div>
    </AppLayout>
</template>
