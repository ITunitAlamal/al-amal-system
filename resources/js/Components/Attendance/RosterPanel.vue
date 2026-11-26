<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { getJson, localToday, rosterTone } from './attendanceUi.js';

/**
 * «جدول المناوبات»: who works when, from the canonical roster (exceptions, swaps, exemptions, assignments, default).
 * HR selects employees to assign a template or an exemption after previewing the impact; nothing applies before the
 * existing approval. An emergency change applies at once only for those holding the approval authority.
 */
const props = defineProps({
    schedules: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
    constants: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    completion: { type: Boolean, default: false },
});

const today = localToday();
const from = ref(today);
const span = ref(7);
const department = ref('');
const search = ref('');
const page = ref(1);
const grid = ref(null);
const loading = ref(false);
const error = ref('');
const message = ref('');
const selected = ref(new Set());
const focus = ref(null);

const to = computed(() => {
    const d = new Date(`${from.value}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + Number(span.value) - 1);
    return d.toISOString().slice(0, 10);
});

async function load(newPage = page.value) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ from: from.value, to: to.value, page: String(newPage) });
        if (department.value) params.set('department_id', department.value);
        if (search.value.trim()) params.set('q', search.value.trim());
        grid.value = await getJson(`/admin/attendance/roster?${params}`);
        page.value = grid.value.current_page;
        error.value = '';
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load(1));
watch([from, span, department], () => load(1));

function toggle(id) {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
}
function selectPage() { selected.value = new Set([...selected.value, ...(grid.value?.rows || []).map(r => r.employee_id)]); }
const selectedNames = computed(() => props.employees.filter(e => selected.value.has(e.id)).map(e => [e.first_name_ar, e.family_name_ar].join(' ')));

// Bulk assignment / exemption with a preview first
const mode = ref('assignment');
const form = ref({ schedule_id: '', reason_value_id: '', effect: '', starts_on: today, ends_on: '', note: '', scope: 'selected', department_id: '' });
const preview = ref(null);
const busy = ref(false);
const reason = computed(() => (props.constants.exemptionReasons || []).find(r => r.id === Number(form.value.reason_value_id)));
watch(reason, value => { if (value) form.value.effect = value.effect; });
function scopePayload() {
    return form.value.scope === 'department' ? { department_id: form.value.department_id || null } : { employee_ids: [...selected.value] };
}
async function previewChange() {
    message.value = '';
    preview.value = null;
    if (form.value.scope === 'selected' && !selected.value.size) { message.value = 'حدد موظفًا واحدًا على الأقل من الجدول.'; return; }
    busy.value = true;
    try {
        const response = await fetch('/admin/attendance/roster/preview', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
            body: JSON.stringify({ kind: mode.value, schedule_id: form.value.schedule_id || null, starts_on: form.value.starts_on, ends_on: form.value.ends_on || null, ...scopePayload() }),
        });
        const body = await response.json();
        if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat().join(' — ') || 'تعذّرت المعاينة.');
        preview.value = body;
    } catch (e) {
        message.value = e.message;
    } finally {
        busy.value = false;
    }
}
function submitChange() {
    if (!preview.value) return;
    const url = mode.value === 'assignment' ? '/admin/attendance/assignments/bulk' : '/admin/attendance/exemptions';
    const payload = mode.value === 'assignment'
        ? { schedule_id: form.value.schedule_id, starts_on: form.value.starts_on, ends_on: form.value.ends_on || null, ...scopePayload() }
        : { reason_value_id: form.value.reason_value_id, effect: form.value.effect, starts_on: form.value.starts_on, ends_on: form.value.ends_on, note: form.value.note, ...scopePayload() };
    if (!window.confirm('إرسال التغيير لاعتماد المدير التنفيذي؟ لا يسري قبل الاعتماد.')) return;
    busy.value = true;
    router.post(url, payload, { preserveScroll: true, preserveState: true,
        onSuccess: () => { message.value = 'أُرسل للاعتماد. يظهر في «التكليفات» أو «الاستثناءات والإعفاءات» بانتظار القرار.'; preview.value = null; },
        onError: errors => { message.value = Object.values(errors).join(' — '); }, onFinish: () => { busy.value = false; } });
}

// Emergency change of one employee (from a selected cell)
const emergency = ref({ effect: 'schedule', start_time: '08:00', end_time: '16:00', ends_next_day: false, ends_on: '', reason: '' });
function openCell(row, cell, day) { focus.value = { row, cell, day }; emergency.value.ends_on = day.date; }
function submitEmergency() {
    const f = focus.value;
    if (!f || emergency.value.reason.trim().length < 10) { message.value = 'اكتب سبب التغيير الطارئ (10 أحرف على الأقل).'; return; }
    if (!window.confirm(props.can.approveRoster ? 'تطبيق التغيير الطارئ الآن وإبلاغ الموظف؟' : 'إرسال التغيير الطارئ للاعتماد؟')) return;
    busy.value = true;
    router.post('/admin/attendance/emergency-changes', { employee_id: f.row.employee_id, starts_on: f.day.date, ends_on: emergency.value.ends_on || f.day.date, ...emergency.value },
        { preserveScroll: true, preserveState: true, onSuccess: () => { message.value = props.can.approveRoster ? 'طُبق التغيير.' : 'أُرسل للاعتماد.'; focus.value = null; load(); },
            onError: errors => { message.value = Object.values(errors).join(' — '); }, onFinish: () => { busy.value = false; } });
}
</script>

<template>
    <section class="space-y-4" dir="rtl">
        <p v-if="message" role="status" class="alert-info">{{ message }}</p>
        <div class="card grid gap-3 md:grid-cols-5">
            <label class="text-sm font-semibold text-slate-700">من تاريخ<input v-model="from" type="date" class="field mt-1" /></label>
            <label class="text-sm font-semibold text-slate-700">المدة<select v-model="span" class="field mt-1"><option :value="7">أسبوع</option><option :value="14">أسبوعان</option><option :value="31">شهر</option></select></label>
            <label class="text-sm font-semibold text-slate-700">القسم<select v-model="department" class="field mt-1"><option value="">كل الأقسام</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
            <label class="text-sm font-semibold text-slate-700">اسم الموظف<input v-model="search" type="search" class="field mt-1" placeholder="بحث بالاسم" @keydown.enter.prevent="load(1)" /></label>
            <div class="flex items-end gap-2"><button type="button" class="btn btn-primary" :disabled="loading" @click="load(1)">عرض</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="selectPage">تحديد الصفحة</button>
                <button v-if="selected.size" type="button" class="btn btn-secondary btn-sm" @click="selected = new Set()">إلغاء التحديد ({{ selected.size }})</button></div>
        </div>
        <p v-if="error" role="alert" class="alert-error">{{ error }}</p>

        <div class="flex flex-wrap gap-2 text-xs">
            <span v-for="[key, label] in [['work','دوام'],['off','راحة'],['no_duty','لا يوجد دوام مقرر'],['no_punch','معفى من البصمة'],['leave','إجازة'],['holiday','عطلة'],['exception','استثناء بلا أوقات']]" :key="key"
                class="rounded-full px-2 py-0.5 ring-1" :class="rosterTone[key]">{{ label }}</span>
            <span class="rounded-full bg-white px-2 py-0.5 ring-1 ring-slate-200">⇄ تبديل معتمد</span>
        </div>

        <div v-if="grid" class="overflow-x-auto rounded-xl border border-slate-300">
            <table class="min-w-full border-collapse text-xs">
                <thead class="bg-navy-800 text-white">
                    <tr><th class="sticky right-0 z-10 bg-navy-800 px-2 py-2 text-right">الموظف</th>
                        <th v-for="day in grid.days" :key="day.date" class="min-w-[6.5rem] border-r border-navy-600 px-1 py-2 text-center font-semibold">{{ day.weekday }}<span class="block font-normal opacity-80" dir="ltr">{{ day.date.slice(5) }}</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in grid.rows" :key="row.employee_id" class="odd:bg-white even:bg-slate-50">
                        <th class="sticky right-0 z-10 border-t border-slate-200 bg-inherit px-2 py-1.5 text-right font-semibold text-navy-900">
                            <label class="flex items-start gap-2"><input type="checkbox" :checked="selected.has(row.employee_id)" :aria-label="`تحديد ${row.name}`" @change="toggle(row.employee_id)" />
                                <span>{{ row.name }}<span class="block font-normal text-slate-500">{{ row.department || 'بلا قسم' }}</span></span></label>
                        </th>
                        <td v-for="(cell, index) in row.cells" :key="index" class="border-t border-r border-slate-200 p-1">
                            <button type="button" class="w-full rounded-md px-1 py-1 text-center ring-1" :class="rosterTone[cell.status] || rosterTone.none"
                                :title="[cell.shift, cell.tier, cell.override_label].filter(Boolean).join(' — ')" @click="openCell(row, cell, grid.days[index])">
                                <span class="block font-semibold" dir="auto">{{ cell.label }}</span>
                                <span v-if="cell.swap" class="block">⇄</span>
                                <span v-else-if="cell.exemption" class="block truncate">{{ cell.exemption }}</span>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!grid.rows.length"><td :colspan="grid.days.length + 1" class="p-4 text-center text-slate-500">لا يوجد موظفون مطابقون.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="grid && grid.last_page > 1" class="flex items-center gap-3 text-sm">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="page <= 1" @click="load(page - 1)">السابق</button>
            <span>الصفحة {{ page }} من {{ grid.last_page }} ({{ grid.total }} موظفًا)</span>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="page >= grid.last_page" @click="load(page + 1)">التالي</button>
        </div>

        <!-- One cell: source and emergency change -->
        <div v-if="focus" class="card space-y-2 text-sm">
            <div class="flex flex-wrap items-center gap-3"><strong>{{ focus.row.name }}</strong><span dir="ltr">{{ focus.day.date }}</span><span>{{ focus.cell.label }}</span>
                <span v-if="focus.cell.shift" class="text-slate-600">{{ focus.cell.shift }}</span><span v-if="focus.cell.tier" class="text-xs text-slate-500">المصدر: {{ focus.cell.tier }}</span>
                <span v-if="focus.cell.override_label" class="text-xs text-slate-500">{{ focus.cell.override_label }}</span>
                <button type="button" class="btn btn-secondary btn-sm ms-auto" @click="focus = null">إغلاق</button></div>
            <form v-if="can.manage && completion" class="grid gap-2 md:grid-cols-6" @submit.prevent="submitEmergency">
                <p class="text-xs text-slate-600 md:col-span-6">تغيير طارئ على جدول هذا الموظف (حتى أسبوعين) مع سبب إلزامي؛ تُحفظ صورة الجدول قبل التغيير وبعده في سجل التدقيق.</p>
                <label class="text-xs font-semibold">النوع<select v-model="emergency.effect" class="field mt-1"><option value="schedule">أوقات دوام</option><option value="off">يوم راحة</option></select></label>
                <template v-if="emergency.effect === 'schedule'">
                    <label class="text-xs font-semibold">من<input v-model="emergency.start_time" type="time" class="field mt-1" /></label>
                    <label class="text-xs font-semibold">إلى<input v-model="emergency.end_time" type="time" class="field mt-1" /></label>
                    <label class="flex items-end gap-1 text-xs"><input v-model="emergency.ends_next_day" type="checkbox" /> ينتهي اليوم التالي</label>
                </template>
                <label class="text-xs font-semibold">حتى تاريخ<input v-model="emergency.ends_on" type="date" :min="focus.day.date" class="field mt-1" /></label>
                <label class="text-xs font-semibold md:col-span-4">السبب<input v-model="emergency.reason" maxlength="2000" class="field mt-1" /></label>
                <div class="flex items-end"><button class="btn btn-primary btn-sm" :disabled="busy">{{ can.approveRoster ? 'تطبيق التغيير' : 'إرسال للاعتماد' }}</button></div>
            </form>
        </div>

        <!-- Bulk change with preview -->
        <div v-if="can.manage" class="card space-y-3">
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm" :class="mode === 'assignment' ? 'btn-primary' : 'btn-secondary'" @click="mode = 'assignment'; preview = null">إسناد قالب دوام</button>
                <button v-if="completion" type="button" class="btn btn-sm" :class="mode === 'exemption' ? 'btn-primary' : 'btn-secondary'" @click="mode = 'exemption'; preview = null">إعفاء من الدوام أو البصمة</button>
            </div>
            <div class="grid gap-2 md:grid-cols-4">
                <label class="text-sm font-semibold text-slate-700">النطاق<select v-model="form.scope" class="field mt-1"><option value="selected">الموظفون المحددون ({{ selected.size }})</option><option value="department">قسم كامل</option></select></label>
                <label v-if="form.scope === 'department'" class="text-sm font-semibold text-slate-700">القسم<select v-model="form.department_id" class="field mt-1"><option value="">اختر</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
                <label v-if="mode === 'assignment'" class="text-sm font-semibold text-slate-700">القالب<select v-model="form.schedule_id" class="field mt-1"><option value="">اختر</option><option v-for="s in schedules.filter(s => s.is_active)" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                <template v-else>
                    <label class="text-sm font-semibold text-slate-700">السبب (ثوابت النظام)<select v-model="form.reason_value_id" class="field mt-1"><option value="">اختر</option><option v-for="r in (constants.exemptionReasons || []).filter(r => r.is_active)" :key="r.id" :value="r.id">{{ r.name }}</option></select></label>
                    <label class="text-sm font-semibold text-slate-700">الأثر<select v-model="form.effect" class="field mt-1"><option value="no_duty">لا يوجد دوام مقرر (إعفاء من الدوام)</option><option value="no_punch">معفى من البصمة (يعمل دون بصمة)</option></select></label>
                </template>
                <label class="text-sm font-semibold text-slate-700">من<input v-model="form.starts_on" type="date" class="field mt-1" /></label>
                <label class="text-sm font-semibold text-slate-700">إلى<input v-model="form.ends_on" type="date" class="field mt-1" :required="mode === 'exemption'" /></label>
                <label v-if="mode === 'exemption'" class="text-sm font-semibold text-slate-700 md:col-span-2">التفاصيل والمرجع<input v-model="form.note" maxlength="2000" class="field mt-1" /></label>
            </div>
            <p v-if="form.scope === 'selected' && selectedNames.length" class="text-xs text-slate-600">المحددون: {{ selectedNames.slice(0, 12).join('، ') }}<template v-if="selectedNames.length > 12"> و{{ selectedNames.length - 12 }} آخرون</template></p>
            <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-secondary" :disabled="busy" @click="previewChange">معاينة الأثر</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !preview" @click="submitChange">إرسال للاعتماد</button></div>
            <div v-if="preview" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
                <p><strong>{{ preview.employees }}</strong> موظفًا — <strong>{{ preview.days_changed }}</strong> يومًا يتغير فيه الدوام<template v-if="preview.retroactive_days"> — منها {{ preview.retroactive_days }} بأثر رجعي</template>.</p>
                <ul class="list-inside list-disc text-xs text-amber-900"><li v-for="w in preview.warnings" :key="w">{{ w }}</li></ul>
                <table class="data-table text-xs"><thead><tr><th class="p-1">الموظف</th><th>أيام تتغير</th><th>تبقى باستثناء أعلى</th><th>أيام إجازة</th><th>تكليف قيد الاعتماد</th></tr></thead>
                    <tbody><tr v-for="r in preview.rows.slice(0, 50)" :key="r.employee_id" class="border-t"><td class="p-1">{{ r.name }}</td><td>{{ r.days_changed }}</td><td>{{ r.kept_by_exception }}</td><td>{{ r.leave_days }}</td><td>{{ r.pending_assignment ? 'نعم' : '—' }}</td></tr></tbody></table>
            </div>
        </div>
    </section>
</template>
