<script setup>
import { formatDateTime } from '../../Support/dates.js';
import { computed, reactive, ref, watch } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import { createUuid } from '../../Support/uuid.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import CorrespondenceFiles from '../../Components/CorrespondenceFiles.vue';
import CopyReference from '../../Components/CopyReference.vue';
import AppDialog from '../../Components/AppDialog.vue';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import { confirmRecordAction, correspondenceConsequences } from '../../Support/actionConfirmation.js';

const props = defineProps({
    letter: Object, history: Array, task: Object, allowed: Array, sourceDepartment: Number, workflow: Object, workflowActions: Array,
    workflowRevision: Number, settings: Object, replyTo: Object, meta: Object, canPrintAudit: Boolean,
    effectiveEdition: Object, canProposeEdition: Boolean, editions: Array, parentLetter: Object, referrals: Array,
    exceptionalAccess: String, confidentialAccess: Object, decision: Object,
});

// Exceptional access to a confidential record: issued and revoked by the executive director, audited by the server.
const grantOpen = ref(false);
const grant = useForm({ user_id: '', ends_on: '', reason: '' });
const today = new Date().toISOString().slice(0, 10);
function issueGrant() {
    grant.post(`/correspondence/${props.letter.public_id}/confidential-access`, { preserveScroll: true, onSuccess: () => { grant.reset(); grantOpen.value = false; } });
}
const revoking = ref(null);
const revokeDecision = computed(() => revoking.value ? { title: 'إلغاء الاطلاع الاستثنائي', description: revoking.value.user_name, required: true, danger: true, confirmLabel: 'إلغاء الاطلاع', maxLength: 1000 } : null);
const revokeBusy = ref(false);
const revokeError = ref('');
function revokeGrant(note) {
    revokeBusy.value = true;
    revokeError.value = '';
    router.post(`/correspondence/${props.letter.public_id}/confidential-access/${revoking.value.id}/revoke`, { revoke_reason: note }, {
        preserveScroll: true,
        onSuccess: () => { revoking.value = null; },
        onError: errors => { revokeError.value = Object.values(errors)[0] || 'تعذر الإلغاء.'; },
        onFinish: () => { revokeBusy.value = false; },
    });
}

const statusLabels = { draft: 'مسودة', issued: 'مرسلة', processing: 'قيد المتابعة', processed: 'أُنجزت — بانتظار الطلب المرتبط', closed: 'منتهية', archived: 'مؤرشفة', withdrawn: 'مسحوبة' };
const statusTones = { draft: 'warning', issued: 'info', processing: 'info', processed: 'success', closed: 'success', archived: 'muted', withdrawn: 'muted' };
const eventLabels = { issue: 'أُرسلت', receive: 'استُلمت', read: 'فُتحت', acknowledge: 'أُقرّ بالاستلام', forward: 'أُحيلت', reply: 'رد', return: 'أُعيدت', refuse: 'رُفض التكليف', complete: 'أُنجزت', close: 'أُغلقت', archive: 'أُرشفت', external_register: 'قُيّدت في الأرشيف الخارجي', withdraw: 'سُحبت', correction: 'تصحيح', workflow: 'إجراء في مسار القرار' };
const actionLabels = { reply: 'رد', forward: 'إحالة', return: 'إعادة للاستكمال', refuse: 'رفض التكليف مع إعادته', complete: 'إنجاز وإغلاق', correction: 'إضافة مرفقات / تصحيح موثق', withdraw: 'سحب المراسلة', close: 'إغلاق', archive: 'أرشفة', reopen: 'إعادة فتح موثقة', reassign: 'إعادة إسناد', workflow: 'متابعة مسار القرار' };
Object.assign(eventLabels, { reopen: 'أُعيد فتحها', reassign: 'أُعيد إسنادها', refer: 'أُحيلت للمتابعة' });
// A referral writes and sends a linked «إحالة» letter: incoming mail from the Secretariat, or Board work handed to a colleague.
actionLabels.refer = props.letter.channel === 'external' ? 'إحالة للمتابعة' : 'إحالة إلى موظف في القسم';
const date = value => formatDateTime(value);
const replacement = useForm({ submission_key: createUuid(), subject: props.effectiveEdition?.subject || props.letter.subject, body: props.effectiveEdition?.body || props.letter.body, reason: '', substantive: true, attachments: [] });
function proposeEdition() {
    replacement.post(`/correspondence/${props.letter.public_id}/editions`, { forceFormData: true });
}

const mainActions = computed(() => ['reply', 'forward', 'complete', 'workflow', 'refer'].filter(action => props.allowed.includes(action)));
const otherActions = computed(() => ['return', 'refuse', 'close', 'withdraw', 'correction', 'archive', 'reopen', 'reassign'].filter(action => props.allowed.includes(action)));
const originals = computed(() => props.history.filter(entry => entry.is_original).flatMap(entry => entry.attachments));
const timeline = computed(() => props.history.filter(entry => !['draft', 'draft_edit'].includes(entry.action)));
const followUp = computed(() => {
    const task = props.task;
    if (!task || props.letter.status === 'draft') { return ''; }
    if (task.completed_at) { return `أُنجزت ${date(task.completed_at)}`; }
    if (task.status === 'cancelled') { return 'سُحبت قبل فتحها'; }
    return task.read_at ? `فتحها ${props.meta.holder || 'المستلم'} ${date(task.read_at)}` : `لم يفتحها ${props.meta.holder || 'المستلم'} بعد`;
});

const form = useForm({ idempotency_key: createUuid(), revision: props.letter.revision, action: '', note: '', recipient_id: '', recipient_department_id: '', due_at: '', attachments: [], replacements: [], transition_id: '', workflow_revision: props.workflowRevision });
const chosenTransition = computed(() => props.workflowActions?.find(transition => String(transition.id) === String(form.transition_id)));
// A head of several departments: the executive director names the department that executes the decision.
const executingDepartments = computed(() => chosenTransition.value?.executing_departments?.[form.recipient_id] || []);
watch(() => [form.transition_id, form.recipient_id], () => { if (form.action === 'workflow') { form.recipient_department_id = ''; } });
// A decision the Secretariat returned: the board secretary completes or corrects its data with his action back to registration.
const decisionFields = reactive({ decision_number: props.decision?.decision_number || '', decision_date: props.decision?.decision_date || '', meeting_number: props.decision?.meeting_number || '', meeting_date: props.decision?.meeting_date || '' });
const editsDecision = computed(() => form.action === 'workflow' && Boolean(chosenTransition.value?.decision_fields));
form.transform(data => (editsDecision.value ? { ...data, decision: { ...decisionFields } } : data));
const picksRecipient = computed(() => ['forward', 'return', 'refuse', 'reopen', 'reassign', 'refer'].includes(form.action) || (form.action === 'reply' && !props.replyTo));
const needsNote = computed(() => ['reply', 'forward', 'return', 'refuse', 'withdraw', 'correction', 'workflow', 'reopen', 'reassign', 'refer'].includes(form.action));
const optionalDue = computed(() => ['forward', 'refer'].includes(form.action));
const takesFiles = computed(() => ['reply', 'forward', 'return', 'refuse', 'complete', 'correction', 'workflow'].includes(form.action));
const recipients = ref([]);
const recipientKey = ref('');
const loadError = ref('');
const groups = computed(() => {
    const byDepartment = new Map();
    for (const person of recipients.value) {
        if (!byDepartment.has(person.department)) { byDepartment.set(person.department, []); }
        byDepartment.get(person.department).push(person);
    }
    return [...byDepartment].map(([name, people]) => ({ name, people }));
});
watch(recipientKey, value => { [form.recipient_id, form.recipient_department_id] = value ? value.split(':') : ['', '']; });
watch(() => props.letter.revision, value => { form.revision = value; form.workflow_revision = props.workflowRevision; form.idempotency_key = createUuid(); form.action = ''; });

async function loadRecipients() {
    try {
        const query = new URLSearchParams({ department_id: props.sourceDepartment, type_value_id: props.letter.type_value_id, classification_value_id: props.letter.classification_value_id, letter: props.letter.public_id });
        if (['reopen', 'reassign', 'refer'].includes(form.action)) { query.set('action', form.action); }
        const response = await fetch(`/correspondence/recipients?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) { throw new Error(); }
        recipients.value = (await response.json()).recipients;
        loadError.value = '';
    } catch {
        recipients.value = [];
        loadError.value = 'تعذر تحميل قائمة المستلمين.';
    }
}

function start(action) {
    form.clearErrors();
    form.reset('note', 'attachments', 'replacements', 'due_at', 'transition_id');
    recipientKey.value = '';
    form.recipient_id = '';
    form.recipient_department_id = '';
    form.action = action;
    if (action === 'reply' && props.replyTo) {
        form.recipient_id = props.replyTo.id;
        form.recipient_department_id = props.replyTo.department_id;
    }
    if (picksRecipient.value) { loadRecipients(); }
}

function send(action) {
    if (form.processing) { return; }
    form.action = action || form.action;
    const consequence = ['complete', 'withdraw', 'close', 'archive', 'reopen', 'reassign'].includes(form.action) ? correspondenceConsequences[form.action] : '';
    if (consequence && !confirmRecordAction(actionLabels[form.action], `${props.letter.reference || 'مسودة'} — ${props.letter.subject}`, consequence)) { return; }
    form.post(`/correspondence/${props.letter.public_id}/actions`, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { form.reset('attachments', 'replacements', 'note'); form.idempotency_key = createUuid(); if (form.action === 'refer') { form.action = ''; } },
    });
}
</script>

<template>
    <AppLayout title="تفاصيل المراسلة" class="correspondence-print-page">
        <div class="correspondence-document mx-auto max-w-5xl space-y-5">
            <PageHeader :title="letter.subject">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <CopyReference v-if="letter.reference" class="print:hidden" :value="letter.reference" label="رقم المراسلة" />
                        <span v-if="letter.reference" class="hidden print:inline">رقم المراسلة: {{ letter.reference }}</span>
                        <StatusBadge :status="letter.status" :label="statusLabels[letter.status] || letter.status" :tone="statusTones[letter.status] || 'muted'" />
                        <StatusBadge v-if="meta.priority_code === 'urgent'" label="عاجل" tone="danger" />
                        <StatusBadge v-if="meta.classification_code && meta.classification_code !== 'internal'" :label="meta.classification" tone="warning" />
                    </div>
                </template>
                <Link v-if="letter.issued_at && letter.status !== 'withdrawn'" :href="`/correspondence/${letter.public_id}/print`" class="btn btn-secondary print:hidden" aria-label="طباعة المراسلة" title="طباعة المراسلة">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                        <path d="M7 14h10v7H7zM17 11h.01" />
                    </svg>
                </Link>
                <Link v-if="canPrintAudit && letter.issued_at && letter.status !== 'withdrawn'" :href="`/correspondence/${letter.public_id}/print?appendix=1`" class="btn btn-secondary print:hidden">طباعة ملحق التدقيق الداخلي</Link>
                <Link href="/correspondence" class="btn btn-secondary print:hidden">المراسلات</Link>
            </PageHeader>

            <!-- Draft: one step to send -->
            <section v-if="allowed.includes('issue')" class="card flex flex-wrap items-center gap-3 print:hidden">
                <p class="text-sm text-slate-700">هذه مسودة لم تُرسل بعد.</p>
                <button type="button" class="btn btn-primary" :disabled="form.processing" @click="send('issue')">إرسال الآن</button>
                <Link :href="`/correspondence/${letter.public_id}/edit`" class="btn btn-secondary">تحرير</Link>
            </section>

            <p v-if="exceptionalAccess" class="alert-warning print:hidden" role="status">تطّلع على هذه المراسلة {{ meta.classification || 'السرية' }} باطلاع استثنائي من المدير التنفيذي حتى {{ date(exceptionalAccess) }}، للاطلاع فقط، ويُسجَّل كل فتح وطباعة وتنزيل في سجل النظام.</p>

            <section v-if="confidentialAccess" class="card space-y-3 print:hidden">
                <div class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 pb-3">
                    <div>
                        <h2 class="font-bold text-navy-800">الاطلاع الاستثنائي</h2>
                        <p class="mt-1 text-sm text-slate-500">لا يطّلع مدير النظام ولا غيره على المراسلة {{ meta.classification }} إلا بتداولها المعتمد أو باطلاع تمنحه أنت لشخص محدد حتى تاريخ محدد.</p>
                    </div>
                    <button type="button" class="btn btn-secondary" @click="grantOpen = true">منح اطلاع استثنائي</button>
                </div>
                <ul v-if="confidentialAccess.grants.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    <li v-for="item in confidentialAccess.grants" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 p-3">
                        <span><span class="font-semibold text-navy-800">{{ item.user_name }}</span> — حتى {{ date(item.ends_at) }} — {{ item.reason }}</span>
                        <span class="flex items-center gap-2">
                            <StatusBadge :label="item.state" :tone="item.state === 'ساري' ? 'warning' : 'muted'" />
                            <button v-if="item.can_revoke" type="button" class="btn btn-secondary" @click="revokeError = ''; revoking = item">إلغاء</button>
                        </span>
                    </li>
                </ul>
            </section>
            <AppDialog :open="grantOpen" title="منح اطلاع استثنائي" description="للاطلاع على هذه المراسلة وحدها حتى التاريخ المحدد، دون أي إجراء عليها." :busy="grant.processing" @close="grantOpen = false">
                <form class="space-y-3" @submit.prevent="issueGrant">
                    <p v-for="(error, key) in grant.errors" :key="key" class="alert-error" role="alert">{{ error }}</p>
                    <label class="field-label">الموظف<select v-model="grant.user_id" class="field mt-1" required><option value="" disabled>اختر</option><option v-for="person in confidentialAccess?.candidates || []" :key="person.id" :value="person.id">{{ person.name }}</option></select></label>
                    <label class="field-label">ينتهي في نهاية يوم<input v-model="grant.ends_on" type="date" class="field mt-1" :min="today" required /></label>
                    <label class="field-label">سبب الاطلاع<textarea v-model="grant.reason" class="field mt-1" rows="3" minlength="5" maxlength="1000" required></textarea></label>
                    <div class="flex flex-wrap gap-2"><button class="btn btn-primary" :disabled="grant.processing">منح الاطلاع</button><button type="button" class="btn btn-secondary" :disabled="grant.processing" @click="grantOpen = false">إلغاء</button></div>
                </form>
            </AppDialog>
            <DecisionDialog :decision="revokeDecision" :busy="revokeBusy" :error="revokeError" @close="revoking = null" @confirm="revokeGrant" />

            <section class="card">
                <h2 class="mb-4 text-base font-bold text-navy-800">{{ letter.channel === 'external' ? 'بيانات الأرشفة الخارجية' : 'ملخص المراسلة' }}</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-slate-500">{{ letter.channel === 'external' ? (letter.direction === 'incoming' ? 'الجهة المرسلة' : 'الجهة المرسل إليها') : 'من' }}</dt><dd class="mt-1 font-semibold text-navy-800">{{ letter.channel === 'external' ? letter.external_party : (meta.author || '—') }}<span v-if="letter.channel !== 'external' && meta.author_department" class="font-normal text-slate-500"> — {{ meta.author_department }}</span></dd></div>
                    <div v-if="letter.channel !== 'external'"><dt class="text-slate-500">إلى</dt><dd class="mt-1 font-semibold text-navy-800">{{ meta.recipient || '—' }}</dd></div>
                    <div v-if="letter.channel !== 'external' && meta.holder && meta.holder !== meta.recipient"><dt class="text-slate-500">المسؤول الحالي</dt><dd class="mt-1 font-semibold text-navy-800">{{ meta.holder }}</dd></div>
                    <div><dt class="text-slate-500">{{ letter.channel === 'external' ? 'تاريخ المراسلة' : 'تاريخ الكتاب' }}</dt><dd class="mt-1 font-medium">{{ letter.external_date || letter.letter_date || '—' }}</dd></div>
                    <div v-if="letter.channel === 'external' && letter.external_reference"><dt class="text-slate-500">رقم المراسلة لدى الجهة</dt><dd class="mt-1 font-medium">{{ letter.external_reference }}</dd></div>
                    <div v-if="letter.channel === 'external'"><dt class="text-slate-500">قيدها في النظام</dt><dd class="mt-1 font-medium">{{ meta.author || '—' }}<span v-if="meta.author_department" class="font-normal text-slate-500"> — {{ meta.author_department }}</span></dd></div>
                    <div v-if="followUp"><dt class="text-slate-500">حالة المتابعة</dt><dd class="mt-1 font-medium">{{ followUp }}</dd></div>
                    <div v-if="task?.due_at && !task.completed_at"><dt class="text-slate-500">الموعد المطلوب</dt><dd class="mt-1 font-semibold">{{ date(task.due_at) }}</dd></div>
                    <div v-if="meta.type_code && meta.type_code !== 'memo'"><dt class="text-slate-500">نوع المراسلة</dt><dd class="mt-1 font-medium">{{ meta.type }}</dd></div>
                    <div v-if="parentLetter" class="sm:col-span-2 lg:col-span-3"><dt class="text-slate-500">{{ parentLetter.channel === 'external' ? 'البريد الوارد المُحال' : 'المراسلة المرتبطة' }}</dt><dd class="mt-1 font-medium"><Link :href="`/correspondence/${parentLetter.public_id}`" class="text-blue-700 underline underline-offset-2">{{ parentLetter.reference || 'بدون رقم' }}</Link> — {{ parentLetter.subject }}</dd></div>
                </dl>
            </section>

            <section v-if="decision" class="card">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-base font-bold text-navy-800">بيانات قرار مجلس الإدارة</h2>
                    <Link v-if="decision.recorded_at" href="/correspondence/decisions" class="text-sm font-semibold text-blue-700 underline underline-offset-2 print:hidden">سجل القرارات</Link>
                </div>
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-slate-500">رقم القرار</dt><dd class="mt-1 font-semibold text-navy-800">{{ decision.decision_number || '—' }}</dd></div>
                    <div><dt class="text-slate-500">تاريخ القرار</dt><dd class="mt-1 font-medium">{{ decision.decision_date || '—' }}</dd></div>
                    <div><dt class="text-slate-500">الجلسة</dt><dd class="mt-1 font-medium">{{ decision.meeting_number || '—' }}<span v-if="decision.meeting_date" class="text-slate-500"> — {{ decision.meeting_date }}</span></dd></div>
                    <div><dt class="text-slate-500">الحالة</dt><dd class="mt-1 font-medium">{{ decision.closed_at ? `مغلق ${date(decision.closed_at)}` : (decision.recorded_at ? (workflow?.summary?.where || workflow?.summary?.status || 'قيد المتابعة') : (letter.status === 'draft' ? 'لم يُرسل بعد' : 'بانتظار تسجيل السكرتاريا')) }}</dd></div>
                </dl>
                <div v-if="decision.assignments.length" class="mt-4 border-t border-slate-100 pt-3">
                    <h3 class="mb-2 text-sm font-bold text-slate-700">الأقسام المكلفة بالتنفيذ</h3>
                    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                        <li v-for="item in decision.assignments" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 p-3">
                            <span><span class="font-semibold text-navy-800">{{ item.department }}</span> — {{ item.head }}<span v-if="item.due_at && item.status === 'executing'" class="text-slate-500"> — الموعد {{ date(item.due_at) }}</span></span>
                            <span class="flex items-center gap-2">
                                <StatusBadge v-if="item.overdue" label="متأخر" tone="danger" />
                                <StatusBadge :label="item.label" :tone="{ executing: 'info', reported: 'warning', accepted: 'success' }[item.status] || 'muted'" />
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-if="referrals?.length" class="card print:hidden">
                <h2 class="mb-3 font-bold text-navy-800">الإحالات المرتبطة</h2>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    <li v-for="item in referrals" :key="item.public_id" class="flex flex-wrap items-center justify-between gap-2 p-3">
                        <Link :href="`/correspondence/${item.public_id}`" class="font-semibold text-blue-700 underline underline-offset-2">{{ item.reference || item.subject }}</Link>
                        <span class="flex flex-wrap items-center gap-2 text-slate-600">
                            <span v-if="item.holder_name && item.task_status === 'open'">لدى {{ item.holder_name }}</span>
                            <StatusBadge :status="item.status" :label="statusLabels[item.status] || item.status" :tone="statusTones[item.status] || 'muted'" />
                        </span>
                    </li>
                </ul>
            </section>

            <article class="card space-y-4">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <h2 class="font-bold text-navy-800">{{ letter.channel === 'external' ? 'ملخص المراسلة' : 'نص المراسلة' }}</h2>
                        <p v-if="effectiveEdition" class="mt-1 text-sm font-medium text-emerald-800">النص النافذ — النسخة البديلة {{ effectiveEdition.version }}</p>
                    </div>
                    <p class="text-sm text-slate-500">{{ letter.channel === 'external' ? 'سجل محفوظ للأرشفة' : settings.letterhead }}</p>
                </header>
                <h3 v-if="effectiveEdition" class="text-lg font-bold text-navy-900">{{ effectiveEdition.subject }}</h3>
                <p v-if="effectiveEdition?.body || letter.body" class="whitespace-pre-wrap break-words text-base leading-8 text-slate-800">{{ effectiveEdition?.body || letter.body }}</p>
                <p v-else class="text-sm text-slate-500">لم يُضف ملخص؛ راجع المرفق المحفوظ إن وجد.</p>
                <details v-if="effectiveEdition" class="rounded-lg border border-slate-200 bg-slate-50 p-3 print:hidden">
                    <summary class="cursor-pointer font-semibold text-slate-700">عرض النص الأصلي المحفوظ</summary>
                    <p class="mt-3 whitespace-pre-wrap break-words leading-7 text-slate-700">{{ letter.body }}</p>
                </details>
                <p class="border-t border-slate-100 pt-3 text-sm text-slate-500">{{ settings.signature_label }}</p>
                <div v-if="originals.length" class="border-t border-slate-100 pt-3">
                    <h3 class="mb-2 text-sm font-bold text-slate-700">{{ letter.channel === 'external' ? 'نسخ البريد المحفوظة' : 'المرفقات الأصلية' }}</h3>
                    <ul class="space-y-2 text-sm">
                    <li v-for="file in originals" :key="file.public_id"><a :href="`/correspondence/files/${file.public_id}`" class="text-blue-700 underline">{{ file.original_filename }}</a> <span class="text-slate-500">({{ Math.ceil(file.file_size / 1024) }} ك.ب)</span></li>
                    </ul>
                </div>
            </article>

            <section v-if="workflow" class="card print:hidden">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-bold text-navy-800">مسار الإجراء</h2>
                        <p class="mt-1 text-sm text-slate-500">تسلسل مراحل المراسلة وحالتها الحالية.</p>
                    </div>
                </div>
                <StatusStepper :steps="workflow.steps" :summary="workflow.summary" />
            </section>
            <section v-if="editions?.length || canProposeEdition" class="card space-y-3 print:hidden">
                <div class="border-b border-slate-100 pb-3">
                    <h2 class="font-bold text-navy-800">النسخ النصية وسجل الاعتماد</h2>
                    <p class="mt-1 text-sm text-slate-500">يبقى النص الأصلي محفوظًا؛ النسخة المعتمدة فقط هي التي تصبح نافذة.</p>
                </div>
                <ul v-if="editions?.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <li v-for="edition in editions" :key="edition.public_id" class="flex flex-wrap items-center justify-between gap-2 p-3">
                        <Link :href="`/correspondence/editions/${edition.public_id}`" class="font-semibold text-blue-700 underline underline-offset-2">النسخة {{ edition.version }}</Link>
                        <StatusBadge :status="edition.status" :label="{ pending: 'قيد الاعتماد', approved: 'معتمدة ونافذة', rejected: 'مرفوضة' }[edition.status] || edition.status" :tone="{ pending: 'warning', approved: 'success', rejected: 'danger' }[edition.status] || 'muted'" />
                    </li>
                </ul>
                <details v-if="canProposeEdition" class="rounded-lg border border-slate-200 p-3">
                    <summary class="cursor-pointer font-semibold text-navy-800">اقتراح نسخة بديلة دون تغيير الأصل</summary>
                    <form class="space-y-3" @submit.prevent="proposeEdition">
                        <p v-for="(error, key) in replacement.errors" :key="key" role="alert">{{ error }}</p>
                        <label class="block text-sm font-semibold">الموضوع الجديد<input v-model="replacement.subject" class="field mt-1 w-full" required maxlength="250" /></label>
                        <label class="block text-sm font-semibold">النص الجديد<textarea v-model="replacement.body" class="field mt-1 w-full leading-7" rows="8" required maxlength="50000"></textarea></label>
                        <label class="block text-sm font-semibold">سبب التعديل<textarea v-model="replacement.reason" class="field mt-1 w-full" rows="3" required maxlength="5000"></textarea></label>
                        <label class="flex items-start gap-2 text-sm"><input v-model="replacement.substantive" class="mt-1" type="checkbox" /> يتضمن تعديلًا جوهريًا إذا كان مرتبطًا بقرار مجلس. إلغاء الاختيار إقرار بأن التعديل لا يغيّر مضمون القرار.</label>
                        <CorrespondenceFiles v-model="replacement.attachments" :settings="settings" :progress="replacement.progress" :disabled="replacement.processing" />
                        <button class="btn btn-primary" :disabled="replacement.processing">إرسال إلى مسار الاعتماد</button>
                    </form>
                </details>
            </section>

            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1.05fr)_minmax(360px,0.95fr)]">
                <section v-if="mainActions.length || otherActions.length" class="card space-y-4 print:hidden">
                    <div class="border-b border-slate-100 pb-3">
                        <h2 class="font-bold text-navy-800">إجراءات المراسلة</h2>
                        <p class="mt-1 text-sm text-slate-500">اختر الإجراء المناسب؛ ستظهر الحقول المطلوبة قبل تسجيله.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="action in mainActions" :key="action" type="button" class="btn" :class="form.action === action ? 'btn-primary' : 'btn-secondary'" @click="start(action)">{{ actionLabels[action] }}</button>
                        <details v-if="otherActions.length" class="relative">
                            <summary class="btn btn-secondary cursor-pointer list-none">إجراءات أخرى</summary>
                            <div class="absolute end-0 z-10 mt-2 flex min-w-52 flex-col gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                <button v-for="action in otherActions" :key="action" type="button" class="btn btn-sm btn-secondary justify-start" @click="start(action)">{{ actionLabels[action] }}</button>
                            </div>
                        </details>
                    </div>

                    <form v-if="form.action && form.action !== 'issue'" class="space-y-4 border-t border-slate-100 pt-4" @submit.prevent="send()">
                        <div class="rounded-lg border border-navy-100 bg-navy-50/60 p-3">
                            <p class="text-xs font-semibold text-slate-500">الإجراء المحدد</p>
                            <p class="mt-1 font-bold text-navy-900">{{ actionLabels[form.action] }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ letter.reference || 'مسودة' }} — {{ letter.subject }}</p>
                        </div>
                        <div v-if="Object.keys(form.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                            <p class="mb-1 font-bold">تعذر تسجيل الإجراء:</p>
                            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <template v-if="form.action === 'workflow'">
                                <label class="block text-sm font-semibold">الإجراء
                                    <select v-model="form.transition_id" class="field mt-1 w-full" required><option value="">اختر</option><option v-for="transition in workflowActions" :key="transition.id" :value="transition.id">{{ transition.name }}</option></select>
                                </label>
                                <label v-if="chosenTransition?.needs_pick" class="block text-sm font-semibold">مسؤول المرحلة التالية
                                    <select v-model="form.recipient_id" class="field mt-1 w-full" required><option value="">اختر</option><option v-for="person in chosenTransition.pick_options" :key="person.id" :value="person.id">{{ person.name }}</option></select>
                                </label>
                                <label v-if="executingDepartments.length" class="block text-sm font-semibold">القسم المكلف بالتنفيذ
                                    <select v-model="form.recipient_department_id" class="field mt-1 w-full" required><option value="">اختر</option><option v-for="item in executingDepartments" :key="item.id" :value="item.id">{{ item.name }}</option></select>
                                </label>
                                <template v-if="editsDecision">
                                    <label class="block text-sm font-semibold">رقم القرار<input v-model="decisionFields.decision_number" class="field mt-1 w-full" maxlength="100" required></label>
                                    <label class="block text-sm font-semibold">تاريخ القرار<input v-model="decisionFields.decision_date" type="date" class="field mt-1 w-full" required></label>
                                    <label class="block text-sm font-semibold">رقم الجلسة<input v-model="decisionFields.meeting_number" class="field mt-1 w-full" maxlength="100" required></label>
                                    <label class="block text-sm font-semibold">تاريخ الجلسة <span class="font-normal text-slate-500">(اختياري)</span><input v-model="decisionFields.meeting_date" type="date" class="field mt-1 w-full"></label>
                                </template>
                            </template>

                            <p v-if="form.action === 'reply' && replyTo" class="rounded-lg bg-slate-50 p-3 text-sm lg:col-span-2">إلى: <span class="font-semibold text-navy-800">{{ replyTo.label }}</span></p>
                            <template v-if="picksRecipient">
                                <label class="block text-sm font-semibold" :class="['forward', 'return', 'refuse', 'reopen', 'reassign', 'refer'].includes(form.action) ? 'lg:col-span-2' : ''">إلى
                                    <select v-model="recipientKey" class="field mt-1 w-full" required>
                                        <option value="">اختر المستلم</option>
                                        <optgroup v-for="group in groups" :key="group.name" :label="group.name">
                                            <option v-for="person in group.people" :key="`${person.id}:${person.department_id}`" :value="`${person.id}:${person.department_id}`">{{ person.name }}</option>
                                        </optgroup>
                                    </select>
                                </label>
                                <p v-if="loadError" class="text-sm text-red-700" role="alert">{{ loadError }}</p>
                                <label v-if="['forward', 'reopen', 'reassign', 'refer'].includes(form.action)" class="block text-sm font-semibold">موعد المتابعة {{ optionalDue ? '(اختياري)' : '(مطلوب)' }}
                                    <input v-model="form.due_at" type="datetime-local" class="field mt-1 w-full" :required="!optionalDue" />
                                </label>
                            </template>

                            <label class="block text-sm font-semibold lg:col-span-2">{{ form.action === 'complete' ? 'ملاحظة (اختيارية)' : (form.action === 'reply' ? 'نص الرد' : (form.action === 'refer' ? 'نص الإحالة (المطلوب من المستلم)' : 'الملاحظة')) }}
                                <textarea v-model="form.note" class="field mt-1 w-full" rows="5" :required="needsNote"></textarea>
                            </label>
                            <div v-if="takesFiles" class="lg:col-span-2">
                                <CorrespondenceFiles v-model="form.attachments" :settings="settings" :progress="form.progress" :disabled="form.processing" />
                            </div>
                            <div v-if="form.action === 'correction' && form.attachments.length" class="space-y-2 lg:col-span-2">
                                <label v-for="(file, index) in form.attachments" :key="index" class="block text-sm">{{ file.name }} — يحل محل
                                    <select v-model="form.replacements[index]" class="field mt-1 w-full">
                                        <option value="">لا شيء (مرفق جديد)</option>
                                        <template v-for="entry in history" :key="entry.public_id"><option v-for="old in entry.attachments" :key="old.public_id" :value="old.public_id">{{ old.original_filename }} — الإصدار {{ old.document_version }}</option></template>
                                    </select>
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                            <button type="submit" class="btn btn-primary min-w-36" :disabled="form.processing">{{ form.processing ? 'جارٍ التسجيل…' : actionLabels[form.action] }}</button>
                            <button type="button" class="btn btn-secondary" :disabled="form.processing" @click="form.action = ''">إلغاء</button>
                        </div>
                    </form>
                </section>

                <section v-if="timeline.length" class="card print:hidden">
                    <header class="mb-5 flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
                        <div>
                            <h2 class="font-bold text-navy-800">السجل الزمني</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ timeline.length }} إجراء موثق</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">الأحدث في الأسفل</span>
                    </header>
                    <ol class="space-y-0">
                        <li v-for="(entry, index) in timeline" :key="entry.public_id" class="relative grid grid-cols-[1.25rem_minmax(0,1fr)] gap-3 pb-6 last:pb-0">
                            <span class="relative flex justify-center" aria-hidden="true">
                                <span class="absolute inset-y-0 w-px bg-slate-200" :class="index === timeline.length - 1 ? 'hidden' : ''"></span>
                                <span class="relative mt-1 h-3 w-3 rounded-full border-2 border-white bg-navy-700 ring-2 ring-navy-100"></span>
                            </span>
                            <article class="min-w-0 rounded-xl border border-slate-200 bg-white p-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <h3 class="font-semibold text-navy-900">{{ eventLabels[entry.action] || entry.action }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">من {{ entry.actor_name }}<span v-if="entry.recipient_name"> إلى {{ entry.recipient_name }}</span></p>
                                    </div>
                                    <time :datetime="entry.created_at" class="shrink-0 text-xs tabular-nums text-slate-500">{{ date(entry.created_at) }}</time>
                                </div>
                                <p v-if="entry.note" class="mt-3 whitespace-pre-wrap break-words border-t border-slate-100 pt-3 text-sm leading-6 text-slate-700">{{ entry.note }}</p>
                                <ul v-if="entry.attachments.length && !entry.is_original" class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-sm">
                                    <li v-for="file in entry.attachments" :key="file.public_id" class="flex flex-wrap items-center justify-between gap-2">
                                        <a :href="`/correspondence/files/${file.public_id}`" class="break-all font-medium text-blue-700 underline underline-offset-2">{{ file.original_filename }}</a>
                                        <span class="text-xs text-slate-500">الإصدار {{ file.document_version }}</span>
                                    </li>
                                </ul>
                            </article>
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    .correspondence-print-page {
        min-height: 0 !important;
        background: white !important;
        color: black;
    }

    .correspondence-print-page > :not(:has(> main)),
    .correspondence-print-page > div:has(> main) > :not(main),
    .correspondence-print-page main > :not(.correspondence-document) {
        display: none !important;
    }

    .correspondence-print-page > div:has(> main),
    .correspondence-print-page main {
        display: block !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
    }

    .correspondence-print-page .correspondence-document {
        max-width: none;
        overflow-wrap: anywhere;
    }

    .correspondence-print-page .card {
        box-shadow: none;
        background: white;
    }

    .correspondence-print-page h1,
    .correspondence-print-page h2 {
        break-after: avoid;
    }

    .correspondence-print-page p {
        orphans: 3;
        widows: 3;
    }
}
</style>
