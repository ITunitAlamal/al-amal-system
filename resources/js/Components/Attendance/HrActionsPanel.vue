<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { getJson, localToday, requestStatusTone } from './attendanceUi.js';

/**
 * «الإجراءات الإدارية»: the confidential register. Drafts are prepared by HR, reviewed by someone else (HR head or the
 * executive director), then issued or rejected; every step is kept in an append-only timeline. Nothing here is created
 * automatically from attendance colors, and nothing has a payroll effect.
 */
const props = defineProps({ employees: { type: Array, default: () => [] } });

const list = ref([]);
const types = ref([]);
const can = ref({});
const error = ref('');
const message = ref('');
const busy = ref(false);
const status = ref('');
const search = ref('');
const editing = ref(null);
const note = ref({});
const empty = () => ({ employee_id: '', type_value_id: '', incident_date: localToday(), attendance_date: '', facts: '', evidence: '', attachment: null });
const form = ref(empty());

async function load() {
    try {
        const params = new URLSearchParams();
        if (status.value) params.set('status', status.value);
        if (search.value.trim()) params.set('q', search.value.trim());
        const result = await getJson(`/admin/attendance/actions?${params}`);
        list.value = result.data;
        types.value = result.types;
        can.value = result.can;
        error.value = '';
    } catch (e) {
        error.value = e.message;
    }
}
onMounted(load);

function post(url, data, success, confirmText, options = {}) {
    if (confirmText && !window.confirm(confirmText)) return;
    busy.value = true;
    message.value = '';
    router.post(url, data, { preserveScroll: true, preserveState: true, ...options,
        onSuccess: () => { message.value = success; load(); options.done?.(); },
        onError: errors => { message.value = Object.values(errors).join(' — ') || 'تعذّر تنفيذ الإجراء.'; },
        onFinish: () => { busy.value = false; } });
}
function save() {
    const f = form.value;
    if (!f.employee_id || !f.type_value_id || f.facts.trim().length < 10) { message.value = 'اختر الموظف والنوع واكتب الوقائع (10 أحرف على الأقل).'; return; }
    const url = editing.value ? `/admin/attendance/actions/${editing.value}` : '/admin/attendance/actions';
    post(url, { ...f }, editing.value ? 'حُفظ التعديل.' : 'حُفظت المسودة.', null, { forceFormData: true, done: () => { form.value = empty(); editing.value = null; } });
}
function edit(a) {
    editing.value = a.id;
    form.value = { employee_id: a.employee_id, type_value_id: a.type_value_id, incident_date: a.incident_date, attendance_date: a.attendance_date || '', facts: a.facts, evidence: a.evidence || '', attachment: null };
}
function decide(a, decision) {
    const text = (note.value[a.id] || '').trim();
    if (decision !== 'issue' && !text) { message.value = 'اكتب سبب القرار.'; return; }
    post(`/admin/attendance/actions/${a.id}/decide`, { decision, note: text || null }, 'سُجل القرار.',
        { issue: 'إصدار الإجراء وإبلاغ الموظف؟', reject: 'رفض الإجراء؟', return: 'إعادة الإجراء للتعديل؟' }[decision]);
}
function cancel(a) {
    const text = (note.value[a.id] || '').trim();
    if (text.length < 10) { message.value = 'اكتب سبب الإلغاء (10 أحرف على الأقل) في خانة الملاحظة.'; return; }
    post(`/admin/attendance/actions/${a.id}/cancel`, { reason: text }, 'أُلغي الإجراء.', 'إلغاء الإجراء مع حفظ سجله؟');
}
const name = e => [e.first_name_ar, e.father_name_ar, e.family_name_ar].filter(Boolean).join(' ');
</script>

<template>
    <section class="space-y-4" dir="rtl">
        <p class="alert-info">سجل سري لرئيس قسم الموارد البشرية والمدير التنفيذي. لا يصدر أي إجراء تلقائيًا من التأخير أو أخطاء الجهاز أو التصحيحات المبدئية، ولا يترتب عليه خصم من الراتب داخل النظام. لا يراجع معدّ الإجراء إجراءه.</p>
        <p v-if="error" role="alert" class="alert-error">{{ error }}</p>
        <p v-if="message" role="status" class="alert-info">{{ message }}</p>

        <form v-if="can.manage" class="card grid gap-2 md:grid-cols-3" @submit.prevent="save">
            <h2 class="card-title md:col-span-3">{{ editing ? 'تعديل المسودة' : 'إجراء جديد (مسودة)' }}</h2>
            <label class="text-sm font-semibold text-slate-700">الموظف<select v-model="form.employee_id" required class="field mt-1" :disabled="Boolean(editing)"><option value="">اختر</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ name(e) }}</option></select></label>
            <label class="text-sm font-semibold text-slate-700">نوع الإجراء (ثوابت النظام)<select v-model="form.type_value_id" required class="field mt-1"><option value="">اختر</option><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}{{ t.decider === 'executive' ? ' — يقرره المدير التنفيذي' : '' }}</option></select></label>
            <label class="text-sm font-semibold text-slate-700">تاريخ الواقعة<input v-model="form.incident_date" type="date" required class="field mt-1" /></label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">الوقائع الموضوعية<textarea v-model="form.facts" rows="3" required maxlength="5000" class="field mt-1"></textarea></label>
            <label class="text-sm font-semibold text-slate-700">يوم الدوام المرتبط (اختياري)<input v-model="form.attendance_date" type="date" class="field mt-1" /></label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">الأدلة والمراجع<textarea v-model="form.evidence" rows="2" maxlength="5000" class="field mt-1"></textarea></label>
            <label class="text-sm font-semibold text-slate-700">مرفق (PDF أو صورة)<input type="file" accept=".pdf,.jpg,.jpeg,.png" class="field mt-1" @change="form.attachment = $event.target.files[0] || null" /></label>
            <div class="flex gap-2 md:col-span-3"><button class="btn btn-primary" :disabled="busy">{{ editing ? 'حفظ التعديل' : 'حفظ المسودة' }}</button>
                <button v-if="editing" type="button" class="btn btn-secondary" @click="editing = null; form = empty()">إلغاء التعديل</button></div>
        </form>

        <div class="card flex flex-wrap items-end gap-2">
            <label class="text-sm font-semibold text-slate-700">الحالة<select v-model="status" class="field mt-1" @change="load"><option value="">الكل</option><option value="draft">مسودة</option><option value="in_review">قيد المراجعة</option><option value="issued">صادر</option><option value="rejected">مرفوض</option><option value="cancelled">ملغى</option></select></label>
            <label class="text-sm font-semibold text-slate-700">الاسم أو المرجع<input v-model="search" class="field mt-1" @keydown.enter.prevent="load" /></label>
            <button type="button" class="btn btn-secondary" @click="load">بحث</button>
        </div>

        <p v-if="!list.length" class="card text-center text-sm text-slate-500">لا توجد إجراءات.</p>
        <article v-for="a in list" :key="a.id" class="card space-y-2 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="requestStatusTone[a.status]">{{ a.status_label }}</span>
                <strong>{{ a.type }}</strong><span>{{ a.employee_name }}</span><span class="text-xs text-slate-500">{{ a.reference_no }} · إصدار {{ a.version }}</span>
                <span dir="ltr" class="text-xs">{{ a.incident_date }}</span>
            </div>
            <p>{{ a.facts }}</p>
            <p v-if="a.evidence" class="text-xs text-slate-600">الأدلة: {{ a.evidence }}</p>
            <a v-if="a.has_attachment" :href="`/admin/attendance/actions/${a.id}/attachment`" class="text-xs text-navy-700 underline">المرفق: {{ a.attachment_name }}</a>
            <p v-if="a.review_note" class="text-xs text-slate-600">ملاحظة المراجعة: {{ a.review_note }} — {{ a.reviewed_by_name }}</p>
            <p v-if="a.acknowledged_at" class="text-xs text-slate-600">اطلاع الموظف: {{ a.acknowledged_at }}</p>
            <p v-if="a.employee_response" class="text-xs text-slate-700">رد الموظف: {{ a.employee_response }}</p>
            <p v-if="a.cancel_reason" class="text-xs text-slate-600">سبب الإلغاء: {{ a.cancel_reason }}</p>
            <div v-if="['draft', 'in_review', 'issued'].includes(a.status)" class="flex flex-wrap gap-2">
                <input v-model="note[a.id]" class="field min-w-0 flex-1" maxlength="2000" placeholder="ملاحظة القرار أو سبب الإلغاء" aria-label="ملاحظة" />
                <template v-if="a.status === 'draft' && can.manage">
                    <button type="button" class="btn btn-secondary btn-sm" @click="edit(a)">تعديل</button>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="post(`/admin/attendance/actions/${a.id}/submit`, {}, 'رُفع للمراجعة.', 'رفع الإجراء للمراجعة؟')">رفع للمراجعة</button>
                </template>
                <template v-if="a.status === 'in_review' && can.decide && a.created_by !== can.user_id">
                    <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="decide(a, 'issue')">إصدار</button>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="decide(a, 'return')">إعادة للتعديل</button>
                    <button type="button" class="btn btn-danger btn-sm" :disabled="busy" @click="decide(a, 'reject')">رفض</button>
                </template>
                <button v-if="can.decide || (can.manage && a.status !== 'issued' && a.created_by === can.user_id)" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="cancel(a)">إلغاء الإجراء</button>
            </div>
            <details v-if="a.timeline.length" class="text-xs">
                <summary class="cursor-pointer font-semibold text-navy-800">السجل الزمني ({{ a.timeline.length }})</summary>
                <ul class="mt-1 divide-y divide-slate-100"><li v-for="(t, i) in a.timeline" :key="i" class="py-1">{{ t.event }} — {{ t.actor }} <span dir="ltr" class="text-slate-500">{{ t.at }}</span> (إصدار {{ t.version }})<span v-if="t.note" class="block text-slate-600">{{ t.note }}</span></li></ul>
            </details>
        </article>
    </section>
</template>
