<script setup>
import { computed, reactive, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';

// «جدول المناوبات»: the head paints the month with a chosen tool (a shift type, custom hours, rest, or clear),
// cell by cell, by whole row (an employee's remaining days), by whole column (one day for everyone), or by repeating
// one week. Nothing is sent until «حفظ التغييرات». The server checks scope, dates and times again.
const props = defineProps({ department: Object, month: String, today: String, editable: Boolean, dates: Array, shiftTypes: Array, rows: Array, departments: Array,
    manageTypes: Boolean, canOrganizationTypes: Boolean, allTypes: Array, warnings: { type: Array, default: () => [] } });
const page = usePage();
const tool = reactive({ mode: props.shiftTypes[0] ? `type:${props.shiftTypes[0].id}` : 'off', start: '08:00', end: '16:00', note: '' });
const pending = ref({});
const saving = ref(false);
const key = (employeeId, date) => `${employeeId}|${date}`;
const pendingCount = computed(() => Object.keys(pending.value).length);
const typeById = computed(() => Object.fromEntries(props.allTypes.map(t => [t.id, t])));
const editableDate = d => props.editable && !d.past;

function toolChange() {
    if (tool.mode === 'clear') return { action: 'clear' };
    if (tool.mode === 'off') return { action: 'set', kind: 'off', note: tool.note || null };
    if (tool.mode === 'custom') return { action: 'set', kind: 'shift', start: tool.start, end: tool.end, note: tool.note || null };
    return { action: 'set', kind: 'shift', shift_type_id: Number(tool.mode.slice(5)), note: tool.note || null };
}
function apply(row, date, change = toolChange()) {
    if (!editableDate(date)) return;
    pending.value = { ...pending.value, [key(row.id, date.date)]: { employee_id: row.id, date: date.date, ...change } };
}
const applyRow = row => props.dates.filter(editableDate).forEach(d => apply(row, d));
const applyColumn = date => props.rows.forEach(row => apply(row, date));
/** Repeats the first editable 7 days of the row (as planned or pending) over the rest of the month. */
function repeatWeek(row) {
    const days = props.dates.filter(editableDate);
    const week = days.slice(0, 7).map(d => planOf(row, d));
    if (!window.confirm('تكرار نمط أول سبعة أيام على بقية الشهر لهذا الموظف؟')) return;
    days.slice(7).forEach((d, i) => {
        const p = week[i % 7];
        apply(row, d, !p ? { action: 'clear' } : p.kind === 'off' ? { action: 'set', kind: 'off' }
            : p.shift_type_id ? { action: 'set', kind: 'shift', shift_type_id: p.shift_type_id } : { action: 'set', kind: 'shift', start: p.start, end: p.end });
    });
}
/** The plan shown in a cell: pending change first, then the saved entry. */
function planOf(row, date) {
    const change = pending.value[key(row.id, date.date)];
    if (change) {
        if (change.action === 'clear') return null;
        if (change.kind === 'off') return { kind: 'off' };
        const t = change.shift_type_id ? typeById.value[change.shift_type_id] : null;
        return { kind: 'shift', shift_type_id: change.shift_type_id || null, start: t ? t.start : change.start, end: t ? t.end : change.end };
    }
    return row.cells[date.date].entry;
}
function label(plan) {
    if (!plan) return '';
    if (plan.kind === 'off') return 'راحة';
    const t = plan.shift_type_id ? typeById.value[plan.shift_type_id] : null;
    return t ? t.name : `${plan.start}–${plan.end}`;
}
function save() {
    if (!pendingCount.value || saving.value) return;
    saving.value = true;
    router.post(`/attendance/shifts/${props.department.id}`, { changes: Object.values(pending.value) }, {
        preserveScroll: true, onSuccess: () => { pending.value = {}; }, onFinish: () => { saving.value = false; },
    });
}
function discard() { if (window.confirm('إلغاء التغييرات غير المحفوظة؟')) pending.value = {}; }
function go(params) { router.get('/attendance/shifts', { department: props.department.id, month: props.month, ...params }, { preserveState: false }); }

const typeForm = useForm({ id: null, name: '', start: '08:00', end: '16:00', is_active: true, scope: 'department' });
function editType(t) { Object.assign(typeForm, { id: t.id, name: t.name, start: t.start, end: t.end, is_active: t.is_active, scope: t.scope }); }
function saveType() {
    const payload = { name: typeForm.name, start: typeForm.start, end: typeForm.end, is_active: typeForm.is_active, department_id: typeForm.scope === 'organization' ? null : props.department.id };
    const done = { preserveScroll: true, onSuccess: () => typeForm.reset() };
    typeForm.transform(() => payload);
    if (typeForm.id) typeForm.put(`/attendance/shift-types/${typeForm.id}`, done); else typeForm.post('/attendance/shift-types', done);
}
</script>

<template>
    <AppLayout title="جدول المناوبات">
        <div class="mx-auto max-w-[1400px] space-y-4">
            <PageHeader eyebrow="الإدارة والمتابعة" title="جدول المناوبات"
                description="خطة شهرية لكل موظف: مناوبة أو راحة لكل يوم. تسري فورًا على احتساب الحضور، ويُشعر الموظف بالتغيير. الأيام الماضية للاطلاع فقط؛ تصحيحها باستثناء من الموارد البشرية." />
            <p v-if="page.props.flash?.success" class="alert-success" role="status">{{ page.props.flash.success }}</p>
            <div v-if="warnings.length" class="alert-warning" role="alert"><p class="font-semibold">تنبيه راحة بين مناوبتين (لا يمنع الحفظ):</p><ul class="list-disc ps-5 text-sm"><li v-for="w in warnings" :key="w">{{ w }}</li></ul></div>

            <div class="card flex flex-wrap items-end gap-3">
                <label class="text-sm">القسم<select class="field mt-1" :value="department.id" @change="go({ department: $event.target.value })"><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}{{ d.editable ? '' : ' (اطلاع)' }}</option></select></label>
                <label class="text-sm">الشهر<input type="month" class="field mt-1" dir="ltr" :value="month" @change="go({ month: $event.target.value })"></label>
                <p v-if="!editable" class="text-sm text-slate-600">للاطلاع فقط: يعدّل الجدولَ رئيسُ القسم المعيّن.</p>
            </div>

            <div v-if="editable" class="card sticky top-2 z-20 flex flex-wrap items-end gap-3">
                <label class="text-sm">الأداة
                    <select v-model="tool.mode" class="field mt-1">
                        <option v-for="t in shiftTypes" :key="t.id" :value="`type:${t.id}`">{{ t.name }} ({{ t.start }}–{{ t.end }}{{ t.next_day ? ' +1' : '' }})</option>
                        <option value="custom">أوقات خاصة…</option><option value="off">راحة</option><option value="clear">مسح (الدوام العام)</option>
                    </select>
                </label>
                <template v-if="tool.mode === 'custom'">
                    <label class="text-sm">من<input v-model="tool.start" type="time" class="field mt-1" dir="ltr"></label>
                    <label class="text-sm">إلى<input v-model="tool.end" type="time" class="field mt-1" dir="ltr"><span class="block text-xs text-slate-500">نهاية قبل البداية = صباح اليوم التالي</span></label>
                </template>
                <label v-if="tool.mode !== 'clear'" class="text-sm">ملاحظة (اختياري)<input v-model="tool.note" class="field mt-1" maxlength="200"></label>
                <span class="text-xs text-slate-500">اضغط خلية، أو اسم الموظف لكل أيامه القادمة، أو رأس اليوم لكل الموظفين.</span>
                <span class="ms-auto flex gap-2">
                    <button type="button" class="btn btn-primary" :disabled="!pendingCount || saving" @click="save">{{ saving ? 'جارٍ الحفظ…' : `حفظ التغييرات (${pendingCount})` }}</button>
                    <button v-if="pendingCount" type="button" class="btn btn-secondary" @click="discard">إلغاء</button>
                </span>
            </div>

            <EmptyState v-if="!rows.length" title="لا يوجد موظفون نشطون في هذا القسم" />
            <div v-else class="card overflow-x-auto p-0">
                <table class="min-w-full border-collapse text-xs">
                    <thead class="sticky top-0 bg-white">
                        <tr>
                            <th class="sticky start-0 z-10 min-w-[11rem] bg-white p-2 text-start">الموظف</th>
                            <th v-for="d in dates" :key="d.date" class="min-w-[4.2rem] border-s border-slate-100 p-1 text-center" :class="[d.weekend ? 'bg-slate-50' : '', d.today ? 'bg-amber-50' : '']">
                                <button v-if="editableDate(d)" type="button" class="w-full" :title="`تطبيق الأداة على ${d.date} لكل الموظفين`" @click="applyColumn(d)">{{ d.day }}<span class="block font-normal text-slate-500">{{ d.weekday }}</span></button>
                                <span v-else>{{ d.day }}<span class="block font-normal text-slate-500">{{ d.weekday }}</span></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-t border-slate-100">
                            <th class="sticky start-0 z-10 bg-white p-2 text-start font-normal">
                                <button v-if="editable" type="button" class="text-start font-semibold text-navy-800 underline-offset-2 hover:underline" :title="'تطبيق الأداة على كل أيام الموظف القادمة'" @click="applyRow(row)">{{ row.name }}</button>
                                <span v-else class="font-semibold text-navy-800">{{ row.name }}</span>
                                <span v-if="row.unit && row.unit !== department.name" class="block text-slate-500">{{ row.unit }}</span>
                                <button v-if="editable" type="button" class="text-blue-700 underline" @click="repeatWeek(row)">كرر نمط الأسبوع</button>
                            </th>
                            <td v-for="d in dates" :key="d.date" class="border-s border-slate-100 p-0.5 text-center align-top" :class="[d.weekend ? 'bg-slate-50' : '', d.today ? 'bg-amber-50' : '']">
                                <button type="button" class="block min-h-[2.6rem] w-full rounded px-0.5 py-1" :disabled="!editableDate(d)"
                                    :class="[pending[`${row.id}|${d.date}`] ? 'ring-2 ring-amber-400' : '', planOf(row, d) ? (planOf(row, d).kind === 'off' ? 'bg-slate-200' : 'bg-navy-100 text-navy-800') : 'text-slate-400']"
                                    :title="`${row.name} — ${d.date}: ${row.cells[d.date].applies}${row.cells[d.date].source ? ' (' + row.cells[d.date].source + ')' : ''}${row.cells[d.date].entry?.note ? ' — ' + row.cells[d.date].entry.note : ''}${row.cells[d.date].result ? ' — ' + row.cells[d.date].result.label : ''}`"
                                    @click="apply(row, d)">
                                    <span v-if="planOf(row, d)">{{ label(planOf(row, d)) }}</span>
                                    <span v-else>{{ row.cells[d.date].work ? row.cells[d.date].applies : '—' }}</span>
                                    <span v-if="row.cells[d.date].result" class="block font-bold" :class="['غ', 'ن'].includes(row.cells[d.date].result.mark) ? 'text-red-700' : row.cells[d.date].result.mark === 'ت' ? 'text-amber-700' : 'text-emerald-700'">{{ row.cells[d.date].result.mark }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-slate-600">الخلية الملونة خطة من هذا الجدول، والرمادية الفاتحة الدوام العام. في الأيام الماضية: ح حضور، ت تأخير، م خروج مبكر، ن بصمة ناقصة، غ بلا بصمات، ؟ غير مسجل على الجهاز. التبديل المعتمد والاستثناء الفردي يسبقان الجدول.</p>

            <details v-if="manageTypes" class="card space-y-3">
                <summary class="card-title cursor-pointer">أنواع المناوبات</summary>
                <table class="w-full text-sm"><thead><tr class="text-slate-600"><th class="text-start">الاسم</th><th>من</th><th>إلى</th><th>النطاق</th><th>الحالة</th><th></th></tr></thead>
                    <tbody><tr v-for="t in allTypes" :key="t.id" class="border-t border-slate-100 text-center"><td class="py-1 text-start">{{ t.name }}</td><td dir="ltr">{{ t.start }}</td><td dir="ltr">{{ t.end }}{{ t.next_day ? ' +1' : '' }}</td>
                        <td>{{ t.scope === 'organization' ? 'عام' : 'القسم' }}</td><td>{{ t.is_active ? 'نشط' : 'موقوف' }}</td>
                        <td><button v-if="t.scope === 'department' || canOrganizationTypes" type="button" class="btn btn-sm btn-secondary" @click="editType(t)">تعديل</button></td></tr></tbody></table>
                <form class="grid gap-3 sm:grid-cols-6" @submit.prevent="saveType">
                    <label class="text-sm sm:col-span-2">الاسم<input v-model="typeForm.name" class="field mt-1" maxlength="60" required placeholder="مثال: ليلي"><span v-if="typeForm.errors.name" class="field-error">{{ typeForm.errors.name }}</span></label>
                    <label class="text-sm">من<input v-model="typeForm.start" type="time" class="field mt-1" dir="ltr" required></label>
                    <label class="text-sm">إلى<input v-model="typeForm.end" type="time" class="field mt-1" dir="ltr" required><span v-if="typeForm.errors.end" class="field-error">{{ typeForm.errors.end }}</span></label>
                    <label v-if="canOrganizationTypes && !typeForm.id" class="text-sm">النطاق<select v-model="typeForm.scope" class="field mt-1"><option value="department">هذا القسم</option><option value="organization">عام لكل الأقسام</option></select></label>
                    <label v-if="typeForm.id" class="flex items-center gap-2 self-end pb-2 text-sm"><input v-model="typeForm.is_active" type="checkbox"> نشط</label>
                    <div class="flex items-end gap-2"><button type="submit" class="btn btn-primary" :disabled="typeForm.processing">{{ typeForm.id ? 'حفظ التعديل' : 'إضافة نوع' }}</button>
                        <button v-if="typeForm.id" type="button" class="btn btn-secondary" @click="typeForm.reset()">إلغاء</button></div>
                </form>
                <p class="text-xs text-slate-500">تعديل نوع المناوبة لا يغيّر الأيام المخططة سابقًا؛ تُنسخ أوقاته عند اختياره. النوع لا يُحذف بل يُوقف.</p>
            </details>
        </div>
    </AppLayout>
</template>
