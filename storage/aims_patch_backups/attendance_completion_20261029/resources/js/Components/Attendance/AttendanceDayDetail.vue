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
    post(`/admin/attendance/punches/${punch.id}/correction`, { corrected_direction: form.direction, reason: form.reason }, 'سُجّل طلب التصحيح بانتظار اعتماد مدير النظام.');
}

function decide(correction, decision) {
    if (!window.confirm(decision === 'approve' ? 'اعتماد التصحيح وإعادة احتساب اليوم؟' : 'رفض طلب التصحيح؟')) return;
    post(`/admin/attendance/corrections/${correction.id}/decision`, { decision, review_note: reviewNote.value[correction.id] || null }, 'تم تسجيل القرار.');
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
            <ul v-if="detail.day?.notes?.length" class="list-inside list-disc text-amber-900">
                <li v-for="note in detail.day.notes" :key="note">{{ note }}</li>
            </ul>
            <p v-if="!detail.punches.length" class="text-slate-500">لا توجد بصمات في نافذة هذا اليوم.</p>
            <div v-for="punch in detail.punches" :key="punch.id" class="space-y-2 rounded-lg bg-white p-3 ring-1 ring-slate-200">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <strong class="font-mono" dir="ltr">{{ punch.time }}</strong>
                    <span>الاتجاه المعتمد: <strong>{{ punch.effective_label }}</strong></span>
                    <span v-if="punch.original_label && punch.corrected" class="text-slate-600">الأصل من الجهاز: {{ punch.original_label }}</span>
                    <span class="text-xs text-slate-500">{{ punch.source }}<template v-if="punch.device_code !== null && punch.device_code !== undefined"> — رمز الجهاز {{ punch.device_code }}</template></span>
                    <span v-if="punch.flag" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900">{{ punch.flag }} — يحتاج مراجعة</span>
                    <span v-if="!punch.counted" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-700">غير محتسبة ({{ punch.match_status }})</span>
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
                    <div v-if="detail.can.decide_correction && c.status === 'pending'" class="mt-2 flex flex-wrap gap-2">
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
