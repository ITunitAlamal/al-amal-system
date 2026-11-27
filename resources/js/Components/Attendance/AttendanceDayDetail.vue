<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { getJson } from './attendanceUi.js';

/**
 * The punches behind one attendance day, opened inline under the ledger row: original and effective directions,
 * automatic and manual corrections, the audit trail (HR) and the correction actions the viewer may take.
 */
const props = defineProps({
    url: { type: String, required: true },
});
const emit = defineEmits(['changed']);

const detail = ref(null);
const loading = ref(false);
const error = ref('');
const busy = ref(false);
const message = ref('');
const revertReason = ref({});
const requestForm = ref({});
const reviewNote = ref({});
const matchLabels = { unmatched: 'غير مطابقة', time_review: 'بانتظار تأكيد الوقت', time_replaced: 'استُبدلت بحركة مؤكدة الوقت' };

async function load() {
    loading.value = true;
    error.value = '';
    try {
        detail.value = await getJson(props.url);
        for (const punch of detail.value.punches) {
            requestForm.value[punch.id] ||= { direction: '', reason: '' };
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function post(url, data, success) {
    busy.value = true;
    message.value = '';
    router.post(url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { message.value = success; load(); emit('changed'); },
        onError: errors => { message.value = Object.values(errors).join(' — ') || 'تعذّر تنفيذ الإجراء.'; },
        onFinish: () => { busy.value = false; },
    });
}

function revert(correction) {
    const reason = (revertReason.value[correction.id] || '').trim();
    if (reason.length < 10) { message.value = 'اكتب سبب التراجع (10 أحرف على الأقل).'; return; }
    if (!window.confirm('التراجع عن التصحيح التلقائي وإعادة احتساب اليوم؟ لن يُعاد تطبيقه تلقائيًا.')) return;
    post(`/admin/attendance/auto-corrections/${correction.id}/revert`, { reason }, 'تم التراجع وإعادة الاحتساب.');
}

function requestCorrection(punch) {
    const form = requestForm.value[punch.id] || {};
    if (!form.direction || (form.reason || '').trim().length < 10) { message.value = 'اختر الاتجاه الصحيح واكتب السبب (10 أحرف على الأقل).'; return; }
    post(`/admin/attendance/punches/${punch.id}/correction`, { corrected_direction: form.direction, reason: form.reason }, 'سُجّل طلب التصحيح بانتظار قرار رئيس قسم الموارد البشرية (أو المدير التنفيذي إن كان رئيس القسم مقدمه أو صاحب البصمة).');
}

function decide(correction, decision) {
    if (!window.confirm(decision === 'approve' ? 'اعتماد التصحيح وإعادة احتساب اليوم؟' : 'رفض طلب التصحيح؟')) return;
    post(`/admin/attendance/corrections/${correction.id}/decision`, { decision, review_note: reviewNote.value[correction.id] || null }, 'تم تسجيل القرار.');
}

// HR quick actions from the day: an approved permission registered with a justification, or a draft administrative action.
const permissionForm = ref({ type_value_id: '', from: '', to: '', reason: '', justification: '' });
const actionForm = ref({ type_value_id: '', facts: '', evidence: '' });
function registerPermission() {
    const f = permissionForm.value;
    if (!f.type_value_id || !f.from || !f.to || f.reason.trim().length < 5 || f.justification.trim().length < 10) {
        message.value = 'أكمل النوع والوقت والسبب، واكتب مبرر التسجيل (10 أحرف على الأقل).';
        return;
    }
    if (!window.confirm('تسجيل إذن معتمد نيابة عن الموظف وإعادة احتساب اليوم؟')) return;
    post('/admin/attendance/permissions', { ...f, employee_id: detail.value.employee_id, work_date: detail.value.work_date }, 'سُجل الإذن وأُعيد احتساب اليوم.');
}
function createAction() {
    const f = actionForm.value;
    if (!f.type_value_id || f.facts.trim().length < 10) { message.value = 'اختر نوع الإجراء واكتب الوقائع (10 أحرف على الأقل).'; return; }
    post('/admin/attendance/actions', { ...f, employee_id: detail.value.employee_id, incident_date: detail.value.work_date, attendance_date: detail.value.work_date },
        'حُفظت مسودة الإجراء في «الإجراءات الإدارية»؛ لا يصدر إلا بعد المراجعة.');
}

const statusLabel = { applied: 'فعّال', reverted: 'ملغى', pending: 'بانتظار الاعتماد', approved: 'معتمد', rejected: 'مرفوض', revoked: 'مسحوب' };
const auditLabel = {
    direction_correction_requested: 'طلب تصحيح اتجاه', direction_correction_approve: 'اعتماد تصحيح', direction_correction_reject: 'رفض تصحيح',
    direction_correction_revoked: 'سحب تصحيح معتمد', auto_direction_reverted: 'تراجع عن تصحيح تلقائي', manual_created: 'إدخال يدوي',
    canonical_match_reviewed: 'ربط حركة غير مطابقة',
};

onMounted(load);
defineExpose({ load });
</script>

<template>
    <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
        <p v-if="loading && !detail" class="text-slate-500">جارٍ تحميل البصمات...</p>
        <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
        <p v-if="message" role="status" class="rounded-lg bg-white p-2 text-navy-800 ring-1 ring-slate-200">{{ message }}</p>
        <template v-if="detail">
            <div v-if="detail.day" class="flex flex-wrap gap-x-4 gap-y-1 rounded-lg bg-white p-2 text-xs text-slate-700 ring-1 ring-slate-200">
                <span v-if="detail.day.shift_name">الوردية: <strong>{{ detail.day.shift_name }}</strong></span>
                <span v-if="detail.day.shift_source">المصدر: {{ detail.day.shift_source }}</span>
                <span v-if="detail.day.expected_in" dir="ltr">{{ detail.day.expected_in }} – {{ detail.day.expected_out }}</span>
                <span v-if="detail.day.actual_late_minutes">تأخير فعلي {{ detail.day.actual_late_minutes }} د · سماح {{ detail.day.grace_used_minutes || 0 }} · بإذن {{ detail.day.excused_late_minutes || 0 }} · محتسب {{ detail.day.chargeable_late_minutes }}</span>
                <span v-if="detail.day.net_minutes !== null && detail.day.net_minutes !== undefined">صافي العمل بعد الاستراحة غير المدفوعة: {{ detail.day.net_minutes }} د</span>
            </div>
            <ul v-if="detail.day?.notes?.length" class="list-inside list-disc text-amber-900">
                <li v-for="note in detail.day.notes" :key="note">{{ note }}</li>
            </ul>
            <ul v-if="detail.day?.info?.length" class="list-inside list-disc text-sky-900">
                <li v-for="line in detail.day.info" :key="line">{{ line }}</li>
            </ul>
            <ul v-if="detail.requests?.length" class="space-y-1">
                <li v-for="r in detail.requests" :key="r.kind + r.id" class="text-amber-900">{{ r.status === 'approved' ? '✓' : '⏳' }} {{ r.label }}
                    <a :href="r.kind === 'permission' ? `/attendance/requests/permission/${r.id}` : `/attendance/requests/swap/${r.id}`" class="ms-1 text-xs text-navy-700 underline">التفاصيل</a></li>
            </ul>
            <ul v-if="detail.hr_actions?.length" class="space-y-1">
                <li v-for="a in detail.hr_actions" :key="a.id" class="font-semibold text-red-800">⚑ {{ a.label }}</li>
            </ul>
            <p v-if="!detail.punches.length" class="text-slate-500">لا توجد بصمات في نافذة هذا اليوم.</p>
            <div v-for="punch in detail.punches" :key="punch.id" class="space-y-2 rounded-lg bg-white p-3 ring-1 ring-slate-200">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <strong class="font-mono" dir="ltr">{{ punch.time }}</strong>
                    <span>الاتجاه المعتمد: <strong>{{ punch.effective_label }}</strong></span>
                    <span v-if="punch.original_label && punch.corrected" class="text-slate-600">الأصل من الجهاز: {{ punch.original_label }}</span>
                    <span class="text-xs text-slate-500">{{ punch.source }}<template v-if="punch.device_code !== null && punch.device_code !== undefined"> — رمز الجهاز {{ punch.device_code }}</template></span>
                    <span v-if="punch.flag" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900">{{ punch.flag }} — يحتاج مراجعة</span>
                    <span v-if="!punch.counted" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-700">غير محتسبة ({{ matchLabels[punch.match_status] || punch.match_status }})</span>
                </div>
                <p v-if="punch.reason" class="text-xs text-slate-600">سبب الإدخال: {{ punch.reason }}</p>
                <div v-for="c in punch.auto || []" :key="'a' + c.id" class="rounded-md border border-slate-200 p-2">
                    <span class="me-2 rounded px-2 py-0.5 text-xs font-semibold" :class="c.status === 'applied' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'">تصحيح تلقائي {{ statusLabel[c.status] || c.status }}</span>
                    <span class="text-xs text-slate-600">{{ c.original }} ← {{ c.corrected }} — {{ c.rule }} — {{ c.applied_at }}</span>
                    <p class="mt-1">{{ c.note }}</p>
                    <p v-if="c.status === 'reverted'" class="mt-1 text-slate-700">تراجع {{ c.reverted_by || '' }} في {{ c.reverted_at }}: {{ c.reversion_reason }}</p>
                    <div v-if="detail.can.revert_auto && c.status === 'applied'" class="mt-2 flex flex-wrap gap-2">
                        <input v-model="revertReason[c.id]" class="field min-w-0 flex-1" maxlength="1000" placeholder="سبب التراجع (إلزامي)" aria-label="سبب التراجع عن التصحيح" />
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="revert(c)">التراجع عن التصحيح</button>
                    </div>
                </div>
                <div v-for="c in punch.manual || []" :key="'m' + c.id" class="rounded-md border border-slate-200 p-2">
                    <span class="me-2 rounded bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800">تصحيح يدوي {{ statusLabel[c.status] || c.status }}</span>
                    <span class="text-xs text-slate-600">{{ c.original }} ← {{ c.corrected }} — طلبه {{ c.requested_by }} في {{ c.requested_at }}</span>
                    <p class="mt-1">{{ c.reason }}</p>
                    <p v-if="c.reviewed_by" class="text-xs text-slate-600">القرار: {{ c.reviewed_by }} في {{ c.reviewed_at }} {{ c.review_note ? '— ' + c.review_note : '' }}</p>
                    <div v-if="(detail.can.decide_correction_ids || []).includes(c.id) && c.status === 'pending'" class="mt-2 flex flex-wrap gap-2">
                        <input v-model="reviewNote[c.id]" class="field min-w-0 flex-1" maxlength="1000" placeholder="ملاحظة القرار (اختيارية)" aria-label="ملاحظة القرار" />
                        <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="decide(c, 'approve')">اعتماد</button>
                        <button type="button" class="btn btn-danger btn-sm" :disabled="busy" @click="decide(c, 'reject')">رفض</button>
                    </div>
                </div>
                <details v-if="detail.can.request_correction && punch.counted && punch.source === 'جهاز البصمة' && !(punch.manual || []).some(c => c.status === 'pending')" class="text-xs">
                    <summary class="cursor-pointer font-semibold text-navy-700">طلب تصحيح اتجاه هذه البصمة</summary>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <select v-model="requestForm[punch.id].direction" class="field w-32" aria-label="الاتجاه الصحيح">
                            <option value="">الاتجاه الصحيح</option><option value="in">دخول</option><option value="out">خروج</option>
                        </select>
                        <input v-model="requestForm[punch.id].reason" class="field min-w-0 flex-1" maxlength="1000" placeholder="السبب (10 أحرف على الأقل)" aria-label="سبب التصحيح" />
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="requestCorrection(punch)">إرسال للاعتماد</button>
                    </div>
                </details>
            </div>
            <details v-if="detail.can.register_permission && detail.permission_types?.length" class="rounded-lg bg-white p-2 text-xs ring-1 ring-slate-200">
                <summary class="cursor-pointer font-semibold text-navy-700">تسجيل إذن معتمد نيابة عن الموظف (طارئ موثق)</summary>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                    <select v-model="permissionForm.type_value_id" class="field" aria-label="نوع الإذن"><option value="">نوع الإذن</option><option v-for="t in detail.permission_types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                    <label class="flex items-center gap-1">من<input v-model="permissionForm.from" type="time" class="field" /></label>
                    <label class="flex items-center gap-1">إلى<input v-model="permissionForm.to" type="time" class="field" /></label>
                    <input v-model="permissionForm.reason" class="field" maxlength="2000" placeholder="سبب الإذن" aria-label="سبب الإذن" />
                    <input v-model="permissionForm.justification" class="field" maxlength="2000" placeholder="مبرر التسجيل نيابة عنه (إلزامي)" aria-label="مبرر التسجيل" />
                </div>
                <button type="button" class="btn btn-secondary btn-sm mt-2" :disabled="busy" @click="registerPermission">تسجيل الإذن</button>
            </details>
            <details v-if="detail.can.create_hr_action && detail.hr_action_types?.length" class="rounded-lg bg-white p-2 text-xs ring-1 ring-slate-200">
                <summary class="cursor-pointer font-semibold text-red-800">فتح إجراء إداري من هذا اليوم (مسودة للمراجعة)</summary>
                <p class="mt-1 text-slate-600">لا يُنشأ أي إجراء تلقائيًا من الألوان أو أخطاء الجهاز. اكتب وقائع موضوعية؛ لا يصدر الإجراء إلا بعد مراجعة شخص آخر.</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    <select v-model="actionForm.type_value_id" class="field" aria-label="نوع الإجراء"><option value="">نوع الإجراء</option><option v-for="t in detail.hr_action_types" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                    <textarea v-model="actionForm.facts" rows="2" class="field sm:col-span-2" maxlength="5000" placeholder="الوقائع الموضوعية (إلزامية)" aria-label="الوقائع"></textarea>
                    <textarea v-model="actionForm.evidence" rows="2" class="field sm:col-span-3" maxlength="5000" placeholder="الأدلة والمراجع (اختياري)" aria-label="الأدلة"></textarea>
                </div>
                <button type="button" class="btn btn-secondary btn-sm mt-2" :disabled="busy" @click="createAction">حفظ كمسودة</button>
            </details>
            <details v-if="detail.audit?.length" class="rounded-lg bg-white p-2 ring-1 ring-slate-200">
                <summary class="cursor-pointer font-semibold text-navy-800">سجل التدقيق ({{ detail.audit.length }})</summary>
                <ul class="mt-2 divide-y divide-slate-100">
                    <li v-for="event in detail.audit" :key="event.id" class="py-1">
                        <span class="font-semibold">{{ auditLabel[event.action] || event.action }}</span> — {{ event.actor || 'النظام' }}
                        <span class="text-xs text-slate-500" dir="ltr">{{ event.at }}</span>
                        <span v-if="event.details?.reason" class="block text-xs text-slate-600">{{ event.details.reason }}</span>
                    </li>
                </ul>
            </details>
        </template>
    </div>
</template>
