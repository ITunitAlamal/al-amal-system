<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { getJson, requestStatusTone } from './attendanceUi.js';

/**
 * «الطلبات والموافقات»: permission and swap requests with the workflow actions of whoever holds the current step,
 * HR revocation/reversal with a reason, and the rules of permission types and exemption reasons (system constants).
 */
const props = defineProps({
    can: { type: Object, default: () => ({}) },
    constants: { type: Object, default: () => ({}) },
    completion: { type: Boolean, default: false },
});
const emit = defineEmits(['counts']);

const inbox = ref(null);
const error = ref('');
const message = ref('');
const busy = ref(false);
const comment = ref({});
const reason = ref({});
const show = ref('open');

async function load() {
    try {
        inbox.value = await getJson('/admin/attendance/requests');
        emit('counts', inbox.value.counts);
        error.value = '';
    } catch (e) {
        error.value = e.message;
    }
}
onMounted(() => { if (props.completion) load(); });
defineExpose({ load });

function post(url, data, success, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return;
    busy.value = true;
    message.value = '';
    router.post(url, data, { preserveScroll: true, preserveState: true,
        onSuccess: () => { message.value = success; load(); },
        onError: errors => { message.value = Object.values(errors).join(' — ') || 'تعذّر تنفيذ الإجراء.'; },
        onFinish: () => { busy.value = false; } });
}
function act(item, action) {
    const text = (comment.value[item.workflow.publicId] || '').trim();
    if (action.requires_comment && !text) { message.value = 'هذا الإجراء يتطلب كتابة السبب.'; return; }
    post(`/workflows/${item.workflow.publicId}/act`, { transition_id: action.id, revision: item.workflow.revision, comment: text || null }, `تم: ${action.name}.`, `تأكيد: ${action.name}؟`);
}
const visible = list => (list || []).filter(item => show.value === 'all' || ['submitted', 'awaiting_counterpart', 'pending_approval'].includes(item.status));

// Rules of permission types / exemption reasons
const rules = ref(Object.fromEntries((props.constants.permissionTypes || []).map(t => [t.id, { ...t.rules }])));
const reasonEffects = ref(Object.fromEntries((props.constants.exemptionReasons || []).map(r => [r.id, r.effect])));
function saveRules(type) {
    const r = rules.value[type.id];
    post(`/admin/attendance/constants/${type.id}/rules`, { ...r, excuses: r.excuses ? 1 : 0 }, `حُفظت قواعد «${type.name}».`);
}
function saveEffect(reasonRow) { post(`/admin/attendance/constants/${reasonRow.id}/rules`, { effect: reasonEffects.value[reasonRow.id] }, `حُفظ أثر «${reasonRow.name}».`); }

// Shift-swap limits (HR head or executive director; audited)
const swap = ref({ ...(props.constants.swapSettings || {}) });
function saveSwap() {
    post('/admin/attendance/settings', { ...swap.value, swap_same_department: swap.value.swap_same_department ? 1 : 0 }, 'حُفظت قواعد تبديل المناوبة.', 'حفظ قواعد تبديل المناوبة؟ تسري على الطلبات الجديدة وعلى الاعتماد.');
}
</script>

<template>
    <section class="space-y-4" dir="rtl">
        <p v-if="!completion" class="alert-info">طلبات الإذن وتبديل المناوبة تعمل بعد تنفيذ ترحيل الحضور المعتمد (بانتظار موافقة الإدارة على تشغيله).</p>
        <p v-if="error" role="alert" class="alert-error">{{ error }}</p>
        <p v-if="message" role="status" class="alert-info">{{ message }}</p>
        <template v-if="inbox">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <button type="button" class="btn btn-sm" :class="show === 'open' ? 'btn-primary' : 'btn-secondary'" @click="show = 'open'">قيد الإجراء</button>
                <button type="button" class="btn btn-sm" :class="show === 'all' ? 'btn-primary' : 'btn-secondary'" @click="show = 'all'">الكل (آخر 14–30 يومًا)</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="load">تحديث</button>
            </div>

            <div class="card space-y-3">
                <h2 class="card-title">طلبات الإذن ({{ inbox.counts.permissions }} قيد الاعتماد)</h2>
                <p v-if="!visible(inbox.permissions).length" class="text-sm text-slate-500">لا توجد طلبات.</p>
                <div v-for="p in visible(inbox.permissions)" :key="p.id" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="requestStatusTone[p.status]">{{ p.status_label }}</span>
                        <strong>{{ p.employee }}</strong><span>{{ p.type }}</span><span dir="ltr">{{ p.work_date }} {{ p.from }}–{{ p.to }}</span><span>{{ p.minutes }} د</span>
                        <span v-if="p.source === 'hr'" class="text-xs text-slate-500">سجلته الموارد البشرية</span>
                        <a :href="p.url" class="text-xs text-navy-700 underline">التفاصيل</a>
                    </div>
                    <p class="text-slate-700">{{ p.reason }}</p>
                    <p v-if="p.workflow?.step" class="text-xs text-slate-600">المرحلة: {{ p.workflow.step }} — لدى: {{ p.workflow.holders.join('، ') || '—' }} ({{ p.workflow.referenceNo }})</p>
                    <p v-if="p.decision_note" class="text-xs text-slate-600">القرار: {{ p.decision_note }}</p>
                    <div v-if="p.workflow?.actions?.length" class="flex flex-wrap gap-2">
                        <input v-model="comment[p.workflow.publicId]" class="field min-w-0 flex-1" maxlength="5000" placeholder="تعليق (إلزامي للرفض)" aria-label="تعليق القرار" />
                        <button v-for="a in p.workflow.actions" :key="a.id" type="button" class="btn btn-sm" :class="a.action_code === 'reject' ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="act(p, a)">{{ a.name }}</button>
                    </div>
                    <div v-if="can.decideHr && p.status === 'approved'" class="flex flex-wrap gap-2">
                        <input v-model="reason['p' + p.id]" class="field min-w-0 flex-1" maxlength="2000" placeholder="سبب إلغاء الإذن المعتمد" aria-label="سبب الإلغاء" />
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || (reason['p' + p.id] || '').trim().length < 10"
                            @click="post(`/admin/attendance/permissions/${p.id}/revoke`, { reason: reason['p' + p.id] }, 'أُلغي الإذن.', 'إلغاء الإذن المعتمد وإعادة احتساب اليوم؟')">إلغاء الإذن</button>
                    </div>
                </div>
            </div>

            <div class="card space-y-3">
                <h2 class="card-title">طلبات تبديل المناوبة ({{ inbox.counts.swaps }} قيد الإجراء)</h2>
                <p v-if="!visible(inbox.swaps).length" class="text-sm text-slate-500">لا توجد طلبات.</p>
                <div v-for="s in visible(inbox.swaps)" :key="s.id" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="requestStatusTone[s.status]">{{ s.status_label }}</span>
                        <strong>{{ s.requester }} ⇄ {{ s.counterpart }}</strong><a :href="s.url" class="text-xs text-navy-700 underline">التفاصيل</a>
                    </div>
                    <p v-for="d in s.days" :key="d.date" class="text-xs"><span dir="ltr">{{ d.date }}</span>: {{ s.requester }} {{ d.requester }} ⇄ {{ s.counterpart }} {{ d.counterpart }}</p>
                    <p class="text-slate-700">{{ s.reason }}</p>
                    <p v-if="s.workflow?.step" class="text-xs text-slate-600">المرحلة: {{ s.workflow.step }} — لدى: {{ s.workflow.holders.join('، ') || '—' }} ({{ s.workflow.referenceNo }})</p>
                    <div v-if="s.workflow?.actions?.length" class="flex flex-wrap gap-2">
                        <input v-model="comment[s.workflow.publicId]" class="field min-w-0 flex-1" maxlength="5000" placeholder="تعليق (إلزامي للرفض)" aria-label="تعليق القرار" />
                        <button v-for="a in s.workflow.actions" :key="a.id" type="button" class="btn btn-sm" :class="a.action_code === 'reject' ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="act(s, a)">{{ a.name }}</button>
                    </div>
                    <div v-if="can.decideHr && s.status === 'approved'" class="flex flex-wrap gap-2">
                        <input v-model="reason['s' + s.id]" class="field min-w-0 flex-1" maxlength="2000" placeholder="سبب إلغاء التبديل المعتمد" aria-label="سبب إلغاء التبديل" />
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || (reason['s' + s.id] || '').trim().length < 10"
                            @click="post(`/admin/attendance/swaps/${s.id}/reverse`, { reason: reason['s' + s.id] }, 'أُلغي التبديل.', 'إلغاء التبديل المعتمد وإعادة الجدولين كما كانا؟')">إلغاء التبديل</button>
                    </div>
                </div>
            </div>
        </template>

        <details v-if="completion && (can.manage || can.decideHr)" class="card">
            <summary class="cursor-pointer font-semibold text-navy-800">قواعد تبديل المناوبة</summary>
            <p v-if="!swap.policy_reference" class="alert-info mt-2 text-xs">قيم مؤقتة لم تُربط بعد بمرجع سياسة عمل معتمد. راجعها مع لائحة شؤون الموظفين وسجّل المرجع.</p>
            <div class="mt-3 grid gap-2 text-xs md:grid-cols-4">
                <label class="flex items-end gap-1 md:col-span-4"><input v-model="swap.swap_same_department" type="checkbox" :disabled="!can.decideHr" /> التبديل بين موظفي القسم نفسه فقط</label>
                <label>أدنى راحة بين ورديتين (ساعة)<input v-model.number="swap.swap_min_rest_hours" type="number" min="0" max="24" class="field mt-1" :disabled="!can.decideHr" /></label>
                <label>أقصى ساعات للوردية الواحدة<input v-model.number="swap.swap_max_hours_per_day" type="number" min="4" max="24" class="field mt-1" :disabled="!can.decideHr" /></label>
                <label>أقصى عدد أيام في الطلب<input v-model.number="swap.swap_max_days" type="number" min="1" max="31" class="field mt-1" :disabled="!can.decideHr" /></label>
                <label>مرجع السياسة المعتمدة<input v-model="swap.policy_reference" type="text" maxlength="255" class="field mt-1" placeholder="مثل: لائحة شؤون الموظفين، المادة…" :disabled="!can.decideHr" /></label>
            </div>
            <p v-if="swap.updated_by" class="mt-2 text-xs text-slate-500">آخر تعديل: {{ swap.updated_by }} — <span dir="ltr">{{ (swap.updated_at || '').slice(0, 16) }}</span></p>
            <p v-if="!can.decideHr" class="mt-2 text-xs text-slate-500">يعدّلها رئيس قسم الموارد البشرية أو المدير التنفيذي.</p>
            <button v-else type="button" class="btn btn-secondary btn-sm mt-3" :disabled="busy" @click="saveSwap">حفظ قواعد التبديل</button>
        </details>

        <details v-if="can.manage && completion" class="card">
            <summary class="cursor-pointer font-semibold text-navy-800">قواعد أنواع الأذونات وأسباب الإعفاء</summary>
            <p class="mt-2 text-xs text-slate-600">الأسماء والتفعيل والترتيب وتواريخ السريان تُدار من «ثوابت النظام». هنا تُضبط قواعد كل نوع، وتسري على الطلبات الجديدة فقط. الأثر المالي «لا شيء» حتى تعتمد الإدارة سياسة مكتوبة.</p>
            <div v-for="t in constants.permissionTypes || []" :key="t.id" class="mt-3 grid gap-2 rounded-lg border border-slate-200 p-3 text-xs md:grid-cols-4">
                <strong class="md:col-span-4">{{ t.name }} <span v-if="!t.is_active" class="text-slate-500">(غير مفعّل)</span></strong>
                <label>الأثر<select v-model="rules[t.id].effect" class="field mt-1"><option v-for="(label, key) in constants.permissionEffects" :key="key" :value="key">{{ label }}</option></select></label>
                <label>الحد الأقصى (دقيقة)<input v-model.number="rules[t.id].max_minutes" type="number" min="1" max="1440" class="field mt-1" /></label>
                <label>المهلة المسبقة (ساعة)<input v-model.number="rules[t.id].lead_hours" type="number" min="0" max="720" class="field mt-1" /></label>
                <label>أقصى أثر رجعي (يوم)<input v-model.number="rules[t.id].retro_days" type="number" min="0" max="366" class="field mt-1" /></label>
                <label>المرفق<select v-model="rules[t.id].attachment" class="field mt-1"><option v-for="(label, key) in constants.attachmentRules" :key="key" :value="key">{{ label }}</option></select></label>
                <label>سقف شهري (عدد)<input v-model.number="rules[t.id].monthly_cap_count" type="number" min="1" max="100" class="field mt-1" /></label>
                <label>سقف شهري (دقيقة)<input v-model.number="rules[t.id].monthly_cap_minutes" type="number" min="1" class="field mt-1" /></label>
                <label class="flex items-end gap-1"><input v-model="rules[t.id].excuses" type="checkbox" /> الإذن المعتمد يفسّر الدقائق</label>
                <div class="md:col-span-4"><button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="saveRules(t)">حفظ القواعد</button></div>
            </div>
            <div v-for="r in constants.exemptionReasons || []" :key="'r' + r.id" class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 p-3 text-xs">
                <strong>{{ r.name }}</strong>
                <select v-model="reasonEffects[r.id]" class="field w-auto" :aria-label="`أثر ${r.name}`"><option value="no_duty">لا يوجد دوام مقرر</option><option value="no_punch">معفى من البصمة (يعمل)</option></select>
                <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="saveEffect(r)">حفظ</button>
            </div>
        </details>
    </section>
</template>
