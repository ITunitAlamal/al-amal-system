<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue';
import axios from 'axios';
import { useForm } from '@inertiajs/vue3';
import { arabicLatinDigitsLocale, formatDateTime, parseDate } from '../../Support/dates.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import DynamicFields from '../../Components/DynamicFields.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';

const props = defineProps({
    publicId: { type: String, required: true },
    referenceNo: { type: String, default: null },
    workflowName: { type: String, default: null },
    revision: { type: Number, default: 1 },
    dueAt: { type: String, default: null },
    slaBreached: { type: Boolean, default: false },
    progress: { type: Object, default: () => ({ steps: [], summary: null }) },
    purchase: { type: Object, default: null },
    actions: { type: Array, default: () => [] },
    stepFields: { type: Array, default: () => [] },
    fieldValues: { type: Array, default: () => [] },
    hold: { type: Object, default: null },
    canHold: { type: Boolean, default: false },
    canResume: { type: Boolean, default: false },
    reopenSteps: { type: Array, default: () => [] },
});

const currentTimestamp = ref(Date.now());
let deadlineRefreshTimer = null;
const deadline = computed(() => {
    const dueDate = parseDate(props.dueAt);
    if (!dueDate) return null;

    const remainingMinutes = Math.ceil((dueDate.getTime() - currentTimestamp.value) / 60000);
    const isOverdue = props.slaBreached || remainingMinutes <= 0;
    const absoluteMinutes = Math.abs(remainingMinutes);
    const days = Math.floor(absoluteMinutes / 1440);
    const hours = Math.floor((absoluteMinutes % 1440) / 60);
    const minutes = absoluteMinutes % 60;
    const duration = days > 0
        ? `${days} يوم${hours > 0 ? ` و${hours} س` : ''}`
        : hours > 0 ? `${hours} س${minutes > 0 ? ` و${minutes} د` : ''}` : `${Math.max(minutes, 1)} د`;
    const status = isOverdue
        ? remainingMinutes <= 0 ? `متأخرة ${duration}` : 'تم تجاوز المهلة'
        : `متبقي ${duration}`;

    return {
        at: formatDateTime(props.dueAt),
        status,
        tone: isOverdue ? 'danger' : remainingMinutes <= 1440 ? 'warning' : 'info',
        overdue: isOverdue,
    };
});

// Suspend (holder), resume (holder) and reopen (executive director) — ED requirements doc §5.
const showHold = ref(false);
const holdForm = useForm({ revision: props.revision, reason: '', review_on: '' });
const resumeForm = useForm({ revision: props.revision, comment: '' });
const reopenForm = useForm({ step_id: null, reason: '' });
watch(() => props.revision, value => { holdForm.revision = value; resumeForm.revision = value; });
const today = new Date().toISOString().slice(0, 10);
function submitHold() {
    holdForm.post(`/workflows/${props.publicId}/hold`, { preserveScroll: true, onSuccess: () => { showHold.value = false; holdForm.reset('reason', 'review_on'); loadDetails(); } });
}
function submitResume() {
    resumeForm.post(`/workflows/${props.publicId}/resume`, { preserveScroll: true, onSuccess: () => { resumeForm.reset('comment'); loadDetails(); } });
}
function submitReopen() {
    if (!confirm('إعادة فتح المعاملة وإسنادها إلى المرحلة المختارة؟')) return;
    reopenForm.post(`/workflows/${props.publicId}/reopen`, { preserveScroll: true, onSuccess: () => { reopenForm.reset(); loadDetails(); } });
}

const selectedAction = ref(null);
const actionForm = useForm({ transition_id: null, revision: props.revision, comment: '', fields: {}, picked_user_id: null });
const chosen = computed(() => props.actions.find(action => action.id === selectedAction.value) || null);
const requiredStepFields = computed(() => props.stepFields.filter(field => field.required));
const pickedAssignee = computed(() => chosen.value?.pick_options?.find(option => option.id === actionForm.picked_user_id) || null);
const fieldContext = computed(() => Object.fromEntries(props.fieldValues.map(item => [item.code, item.value])));
watch(() => props.revision, value => { actionForm.revision = value; });
function choose(action) {
    selectedAction.value = action.id;
    actionForm.clearErrors();
    actionForm.picked_user_id = null;
}
function submitAction() {
    if (!chosen.value) return;
    actionForm.transition_id = chosen.value.id;
    actionForm.post(`/workflows/${props.publicId}/act`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedAction.value = null;
            actionForm.reset('comment', 'fields', 'picked_user_id');
            loadDetails();
        },
    });
}
const isBackward = action => ['reject', 'return'].includes(action.action_code);

const loading = ref(true);
const error = ref('');
const details = ref(null);
const timeline = computed(() => details.value?.timeline ?? []);
const isPublic = computed(() => details.value?.access_level === 'public_status');
const request = computed(() => details.value?.request ?? {});
const instance = computed(() => details.value?.instance ?? {});
const timelineFilter = ref('all');
const latestEvent = computed(() => timeline.value.length ? timeline.value[timeline.value.length - 1] : null);
const filteredTimeline = computed(() => timeline.value.filter(event => {
    if (timelineFilter.value === 'comments') return Boolean(event.comment?.trim());
    if (timelineFilter.value === 'attachments') return Boolean(event.attachments?.length);

    return true;
}));

const actionLabels = {
    submit: 'إرسال الطلب', forward: 'إحالة المعاملة', assign: 'تكليف / توجيه',
    return: 'إرجاع المعاملة', reject: 'رفض الطلب', complete: 'إغلاق المعاملة',
    finish: 'إنهاء الأعمال', received: 'استلام', approve: 'اعتماد',
    check_stock: 'فحص المخزون', issue: 'صرف المواد', sla_breached: 'تجاوز مهلة المرحلة',
    submit_executive: 'إرسال إلى المدير التنفيذي مباشرة', external: 'طلب تنفيذ خارجي', submit_needs: 'رفع الاحتياجات للاعتماد',
    no_materials: 'بدء التنفيذ دون مواد', submit_cost: 'رفع التكلفة للاعتماد', provide: 'توفير الاحتياجات',
    completed_work: 'إثبات الإنجاز ومحضر الاستلام', paid: 'صرف الأجرة', accept: 'تأكيد الإنجاز', close: 'اعتماد الإغلاق',
    escalate: 'تصعيد', available: 'المواد متوفرة', unavailable: 'المواد غير متوفرة', request_approval: 'طلب اعتماد التكلفة',
    delegation_returned: 'إعادة بعد انتهاء التفويض', version_migrated: 'نقل إلى إصدار المسار الجديد', cancel: 'إلغاء الطلب',
    hold: 'تعليق المعاملة', resume: 'استئناف المعاملة', reopen: 'إعادة فتح المعاملة',
    internal: 'تنفيذ داخلي', done: 'تم التنفيذ', contract_sent: 'إرسال التكلفة والعقد', receipt: 'محضر الاستلام',
    recommend: 'موافقة مبدئية ورفع', disbursed: 'صرف السلفة', respond: 'الرد على الشكوى', report: 'رفع نتيجة المعالجة', object: 'اعتراض على الرد',
};
function formattedDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat(arabicLatinDigitsLocale, {
        day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(date);
}
async function loadDetails() {
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get(`/workflows/${encodeURIComponent(props.publicId)}/details`);
        details.value = response.data?.data ?? null;
        if (!details.value) error.value = 'لم تصل بيانات المعاملة من الخادم.';
    } catch (exception) {
        const status = exception.response?.status;
        error.value = status === 403
            ? 'ليس لديك صلاحية الاطلاع على هذه المعاملة.'
            : status === 404 ? 'المعاملة غير موجودة.' : 'تعذر تحميل المعاملة. تحقق من الاتصال وأعد المحاولة.';
    } finally {
        loading.value = false;
    }
}
onMounted(() => {
    loadDetails();
    deadlineRefreshTimer = window.setInterval(() => {
        currentTimestamp.value = Date.now();
    }, 60000);
});
onBeforeUnmount(() => {
    if (deadlineRefreshTimer !== null) window.clearInterval(deadlineRefreshTimer);
});
</script>

<template>
    <AppLayout title="تفاصيل الطلب">
        <div class="mx-auto max-w-6xl space-y-6">
            <PageHeader eyebrow="الملف الإلكتروني للطلب" :title="request.title || 'تفاصيل الطلب'">
                <template #meta>
                    <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span class="font-semibold text-navy-700" dir="ltr">{{ referenceNo || instance.public_id || '' }}</span>
                        <span v-if="workflowName">· {{ workflowName }}</span>
                        <StatusBadge v-if="instance.status" :status="instance.status" />
                    </p>
                    <div
                        v-if="deadline"
                        class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border px-4 py-3"
                        :class="deadline.overdue ? 'border-red-200 bg-red-50' : deadline.tone === 'warning' ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50'"
                    >
                        <span class="text-sm font-semibold text-slate-700">الموعد النهائي:</span>
                        <time class="text-sm font-bold text-navy-800" :datetime="dueAt">{{ deadline.at }}</time>
                        <StatusBadge :label="deadline.status" :tone="deadline.tone" />
                    </div>
                </template>
                <button type="button" class="btn btn-secondary" :disabled="loading" @click="loadDetails">تحديث</button>
            </PageHeader>

            <section v-if="purchase" class="card" aria-labelledby="purchase-progress-title">
                <h2 id="purchase-progress-title" class="card-title mb-3">مسار الشراء في الحسابات — {{ purchase.number }}</h2>
                <StatusStepper :steps="purchase.progress.steps" :summary="purchase.progress.summary" />
            </section>
            <section v-if="progress.steps?.length" class="card">
                <h2 class="card-title mb-4">مراحل الطلب</h2>
                <StatusStepper :steps="progress.steps" :summary="progress.summary" />
                <p v-if="progress.steps.some(step => step.state === 'upcoming')" class="mt-3 text-xs text-slate-500">المراحل الرمادية هي المسار المتوقع؛ قد يتغير حسب قرارات المعتمدين وشروط المسار. اضغط على أي مرحلة لعرض تفاصيلها.</p>
            </section>

            <div v-if="loading" class="card animate-pulse space-y-3" role="status" aria-label="جارٍ تحميل الطلب">
                <div class="h-4 w-1/3 rounded bg-slate-200"></div>
                <div class="h-4 w-2/3 rounded bg-slate-100"></div>
                <div class="h-4 w-1/2 rounded bg-slate-100"></div>
            </div>
            <div v-else-if="error" class="alert-error" role="alert">{{ error }}</div>
            <template v-else-if="details">
                <section v-if="hold" class="card border-2 border-amber-300" role="status">
                    <h2 class="card-title">المعاملة معلقة مؤقتًا</h2>
                    <p class="mt-2 text-sm text-slate-600">منذ {{ formattedDate(hold.since) }}<span v-if="hold.by"> بواسطة {{ hold.by }}</span> · موعد المراجعة: <strong>{{ hold.until }}</strong></p>
                    <p v-if="hold.reason" class="mt-3 whitespace-pre-wrap rounded-lg bg-amber-50 p-3 text-sm">{{ hold.reason }}</p>
                    <form v-if="canResume" class="mt-4 space-y-3 border-t border-slate-100 pt-4" @submit.prevent="submitResume">
                        <div>
                            <label for="resume-comment" class="field-label">ملاحظة الاستئناف (اختيارية)</label>
                            <textarea id="resume-comment" v-model="resumeForm.comment" rows="2" maxlength="2000" class="field" />
                        </div>
                        <p v-if="resumeForm.errors.hold" class="field-error">{{ resumeForm.errors.hold }}</p>
                        <button type="submit" class="btn btn-primary" :disabled="resumeForm.processing">{{ resumeForm.processing ? 'جارٍ الاستئناف...' : 'استئناف المعاملة' }}</button>
                    </form>
                </section>

                <section v-if="actions.length" class="card border-2 border-gold-300">
                    <h2 class="card-title">الإجراء المطلوب منك</h2>
                    <p class="mt-1 text-sm text-slate-500">اختر الإجراء ثم أكّده. لا يمكن اعتماد طلب قدّمته بنفسك.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
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
                        <section class="rounded-xl border border-blue-200 bg-blue-50/70 p-4" aria-labelledby="action-summary-title">
                            <h3 id="action-summary-title" class="text-sm font-bold text-navy-800">ملخص الإجراء قبل الإرسال</h3>
                            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs text-slate-600">المرحلة التالية</dt>
                                    <dd class="mt-1 font-semibold text-navy-800">{{ chosen.to_step_name }}<span v-if="chosen.has_conditions" class="font-normal text-slate-600"> (قد يتغير المسار حسب الشروط)</span></dd>
                                </div>
                                <div v-if="chosen.needs_pick">
                                    <dt class="text-xs text-slate-600">المسؤول عن المرحلة التالية</dt>
                                    <dd class="mt-1 font-semibold text-navy-800">{{ pickedAssignee?.name || 'يلزم اختيار مسؤول أدناه' }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 border-t border-blue-200 pt-3">
                                <p class="text-xs font-semibold text-slate-700">المتطلبات قبل التأكيد</p>
                                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-700">
                                    <li v-if="chosen.requires_comment">إدخال الملاحظة أو السبب المطلوب.</li>
                                    <li v-if="chosen.needs_pick">اختيار المسؤول عن المرحلة التالية.</li>
                                    <li v-if="requiredStepFields.length">
                                        استكمال الحقول الإلزامية:
                                        <span class="font-semibold">{{ requiredStepFields.map(field => field.label).join('، ') }}</span>
                                        <span class="text-xs text-slate-500"> (بحسب شروط ظهور الحقول)</span>
                                    </li>
                                    <li v-if="!chosen.requires_comment && !chosen.needs_pick && !requiredStepFields.length">لا توجد متطلبات إضافية محددة لهذا الإجراء.</li>
                                </ul>
                            </div>
                        </section>
                        <DynamicFields v-if="stepFields.length" v-model="actionForm.fields" :fields="stepFields" :errors="actionForm.errors" :context="fieldContext" />
                        <div v-if="chosen.needs_pick">
                            <label for="act-pick" class="field-label">المسؤول عن المرحلة التالية <span class="text-red-600">*</span></label>
                            <select id="act-pick" v-model="actionForm.picked_user_id" required class="field">
                                <option :value="null" disabled>اختر</option>
                                <option v-for="option in chosen.pick_options" :key="option.id" :value="option.id">{{ option.name }}</option>
                            </select>
                            <p v-if="actionForm.errors.picked_user_id" class="field-error">{{ actionForm.errors.picked_user_id }}</p>
                        </div>
                        <div>
                            <label for="act-comment" class="field-label">الملاحظة أو السبب<span v-if="chosen.requires_comment" class="text-red-600"> *</span></label>
                            <textarea id="act-comment" v-model="actionForm.comment" rows="3" maxlength="5000" :required="chosen.requires_comment" class="field" />
                            <p v-if="actionForm.errors.comment" class="field-error">{{ actionForm.errors.comment }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" :disabled="actionForm.processing" :class="['btn px-6 py-2.5', isBackward(chosen) ? 'bg-red-600 text-white hover:bg-red-700' : 'btn-primary']">
                                {{ actionForm.processing ? 'جارٍ التنفيذ...' : `تأكيد: ${chosen.name}` }}
                            </button>
                            <button type="button" class="btn btn-secondary" @click="selectedAction = null">إلغاء</button>
                        </div>
                    </form>
                    <div v-if="canHold" class="mt-5 border-t border-slate-100 pt-4">
                        <button v-if="!showHold" type="button" class="btn btn-secondary btn-sm" @click="showHold = true">تعليق المعاملة بانتظار معلومة أو موافقة أو مورد</button>
                        <form v-else class="grid gap-3 md:grid-cols-3" @submit.prevent="submitHold">
                            <div class="md:col-span-2">
                                <label for="hold-reason" class="field-label">سبب التعليق <span class="text-red-600">*</span></label>
                                <textarea id="hold-reason" v-model="holdForm.reason" rows="2" minlength="5" maxlength="2000" required class="field" />
                                <p v-if="holdForm.errors.reason" class="field-error">{{ holdForm.errors.reason }}</p>
                            </div>
                            <div>
                                <label for="hold-review" class="field-label">موعد المراجعة <span class="text-red-600">*</span></label>
                                <input id="hold-review" v-model="holdForm.review_on" type="date" :min="today" required class="field" />
                                <p v-if="holdForm.errors.review_on" class="field-error">{{ holdForm.errors.review_on }}</p>
                            </div>
                            <p v-if="holdForm.errors.hold" class="field-error md:col-span-3">{{ holdForm.errors.hold }}</p>
                            <p class="text-xs text-slate-500 md:col-span-3">تبقى المعاملة لديك في المرحلة نفسها وتتوقف مهلتها، ولا يُتخذ فيها إجراء حتى تستأنفها. يصلك تذكير في موعد المراجعة.</p>
                            <div class="flex gap-2 md:col-span-3">
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="holdForm.processing">تأكيد التعليق</button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="showHold = false">إلغاء</button>
                            </div>
                        </form>
                    </div>
                </section>

                <section v-if="reopenSteps.length" class="card">
                    <h2 class="card-title">إعادة فتح المعاملة</h2>
                    <p class="mt-1 text-sm text-slate-500">عند اكتشاف نقص بعد الإغلاق أو عدم اكتمال الإنجاز. يُسجَّل السبب والمرحلة التي تعود إليها في سجل المعاملة والتدقيق.</p>
                    <form class="mt-4 grid gap-3 md:grid-cols-3" @submit.prevent="submitReopen">
                        <div>
                            <label for="reopen-step" class="field-label">تعود إلى مرحلة <span class="text-red-600">*</span></label>
                            <select id="reopen-step" v-model="reopenForm.step_id" required class="field">
                                <option :value="null" disabled>اختر</option>
                                <option v-for="step in reopenSteps" :key="step.id" :value="step.id">{{ step.name }}</option>
                            </select>
                            <p v-if="reopenForm.errors.step_id" class="field-error">{{ reopenForm.errors.step_id }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label for="reopen-reason" class="field-label">سبب إعادة الفتح <span class="text-red-600">*</span></label>
                            <textarea id="reopen-reason" v-model="reopenForm.reason" rows="2" minlength="5" maxlength="2000" required class="field" />
                            <p v-if="reopenForm.errors.reason || reopenForm.errors.reopen" class="field-error">{{ reopenForm.errors.reason || reopenForm.errors.reopen }}</p>
                        </div>
                        <div class="md:col-span-3"><button type="submit" class="btn btn-primary" :disabled="reopenForm.processing">إعادة فتح المعاملة</button></div>
                    </form>
                </section>

                <section class="card">
                    <h2 class="card-title">بيانات الطلب</h2>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div><dt class="text-xs text-slate-500">المرحلة الحالية</dt><dd class="mt-1 font-semibold text-navy-800">{{ instance.current_step_name || '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">المسؤول الحالي</dt><dd class="mt-1 font-semibold text-navy-800">{{ instance.current_responsible || '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">تاريخ البدء</dt><dd class="mt-1 text-sm">{{ formattedDate(instance.started_at || instance.created_at) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">تاريخ الإغلاق</dt><dd class="mt-1 text-sm">{{ formattedDate(instance.completed_at) }}</dd></div>
                    </dl>
                    <dl v-if="fieldValues.length" class="mt-5 grid gap-4 rounded-xl bg-navy-50/60 p-4 sm:grid-cols-2">
                        <div v-for="item in fieldValues" :key="item.code"><dt class="text-xs text-slate-500">{{ item.label }}</dt><dd class="mt-1 text-sm font-semibold">{{ item.value ?? '—' }}</dd></div>
                    </dl>
                    <div v-if="!isPublic && request.description" class="mt-5 rounded-xl bg-slate-50 p-4">
                        <h3 class="mb-2 text-sm font-bold text-navy-800">وصف الطلب</h3>
                        <p class="whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ request.description }}</p>
                    </div>
                    <p v-if="isPublic" class="alert-info mt-5 text-xs">تظهر لك الحالة العامة وسجل الإحالات المسموح بعرضه. الملاحظات الداخلية والمرفقات المالية غير متاحة لمقدم الطلب.</p>
                </section>

                <section class="card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="card-title">سجل الإجراءات</h2>
                            <p class="mt-1 text-xs text-slate-500">كل إحالة أو قرار على الطلب بالترتيب الزمني.</p>
                        </div>
                        <p v-if="latestEvent" class="text-xs text-slate-500">آخر تحديث: {{ formattedDate(latestEvent.occurred_at) }}</p>
                    </div>
                    <EmptyState v-if="!timeline.length" class="mt-4" title="لا توجد إجراءات قابلة للعرض حاليًا" />
                    <template v-else>
                        <div v-if="!isPublic" class="mt-4 flex flex-wrap gap-2" role="group" aria-label="تصفية سجل الإجراءات">
                            <button type="button" class="btn btn-sm" :class="timelineFilter === 'all' ? 'btn-primary' : 'btn-secondary'" :aria-pressed="timelineFilter === 'all'" @click="timelineFilter = 'all'">كل الإجراءات</button>
                            <button type="button" class="btn btn-sm" :class="timelineFilter === 'comments' ? 'btn-primary' : 'btn-secondary'" :aria-pressed="timelineFilter === 'comments'" @click="timelineFilter = 'comments'">الملاحظات</button>
                            <button type="button" class="btn btn-sm" :class="timelineFilter === 'attachments' ? 'btn-primary' : 'btn-secondary'" :aria-pressed="timelineFilter === 'attachments'" @click="timelineFilter = 'attachments'">المرفقات</button>
                        </div>
                        <p v-if="!filteredTimeline.length" class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-500" role="status">
                            {{ timelineFilter === 'comments' ? 'لا توجد ملاحظات في سجل الإجراءات.' : timelineFilter === 'attachments' ? 'لا توجد مرفقات في سجل الإجراءات.' : 'لا توجد إجراءات قابلة للعرض حاليًا.' }}
                        </p>
                        <ol v-else class="mt-5 space-y-4 border-r-2 border-navy-100 pr-5">
                        <li v-for="(event, index) in filteredTimeline" :key="event.id || index" class="relative rounded-xl border p-4" :class="event === latestEvent ? 'border-gold-300 bg-gold-50/40' : 'border-slate-100 bg-white'">
                            <span class="absolute -right-[1.85rem] top-5 h-4 w-4 rounded-full border-4 border-white ring-1" :class="event === latestEvent ? 'bg-gold-500 ring-gold-400' : 'bg-gold-400 ring-gold-300'" aria-hidden="true"></span>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-sans text-sm font-bold text-navy-800">{{ event.label || actionLabels[event.action] || event.action || 'إجراء' }}</h3>
                                    <StatusBadge v-if="event === latestEvent" label="الأحدث" tone="warning" />
                                </div>
                                <time class="text-xs text-slate-500">{{ formattedDate(event.occurred_at) }}</time>
                            </div>
                            <p class="mt-2 text-sm text-slate-600">
                                <span>{{ event.from_step || 'بداية الطلب' }}</span>
                                <span class="mx-2 text-gold-500" aria-hidden="true">←</span>
                                <span class="font-semibold text-navy-700">{{ event.to_step || '—' }}</span>
                            </p>
                            <template v-if="!isPublic">
                                <p v-if="event.actor_name" class="mt-2 text-xs text-slate-500">بواسطة: {{ event.actor_name }}</p>
                                <p v-if="event.comment" class="mt-3 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm">{{ event.comment }}</p>
                                <div v-if="event.attachments?.length" class="mt-3">
                                    <p class="mb-2 text-xs font-semibold text-slate-600">المرفقات</p>
                                    <ul class="space-y-2">
                                        <li v-for="file in event.attachments" :key="file.public_id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                                            {{ file.filename }} <span class="mr-2 text-xs text-slate-400">(عرض وصفي فقط)</span>
                                        </li>
                                    </ul>
                                </div>
                            </template>
                        </li>
                        </ol>
                    </template>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
