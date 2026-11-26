<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AttendanceDayDetail from './AttendanceDayDetail.vue';
import { dayTone, downloadWorkbook, filterOptions, getJson, localToday, minutesLabel, punctualityEdge, punctualityTone } from './attendanceUi.js';

/**
 * «سجل دوام الموظفين»: overview cards, filters, the day table (cards on narrow screens) and inline punch details.
 * mode "hr": all employees (search by Arabic name, server paging). mode "employee": one employee's file.
 * The data is re-read every 30 seconds while the page is visible, without touching the filters or open rows.
 */
const props = defineProps({
    mode: { type: String, default: 'hr' },
    endpoint: { type: String, required: true },
    exportUrl: { type: String, required: true },
    dayUrl: { type: Function, required: true },
    employees: { type: Array, default: () => [] },
    defaultFrom: { type: String, default: '' },
    defaultTo: { type: String, default: '' },
    autoRefreshSeconds: { type: Number, default: 30 },
    confidentialExport: { type: Boolean, default: false },
});
const withActions = ref(false);
const emit = defineEmits(['refreshed']);

const hr = computed(() => props.mode === 'hr');
const today = localToday();
const from = ref(props.defaultFrom || today);
const to = ref(props.defaultTo || today);
const search = ref('');
const filter = ref('');
const page = ref(1);
const rows = ref([]);
const summary = ref(null);
const todayRow = ref(null);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const exporting = ref(false);
const error = ref('');
const refreshedAt = ref(null);
const open = ref(new Set());
let controller = null;
let timer = null;

const normalize = value => String(value || '').normalize('NFKD').replace(/[ً-ٰٟـ]/g, '')
    .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي').replace(/\s+/g, ' ').trim().toLowerCase();
const employeeName = e => [e.first_name_ar, e.father_name_ar, e.family_name_ar].filter(Boolean).join(' ');
const suggestions = computed(() => {
    const words = normalize(search.value).split(' ').filter(Boolean);
    if (!hr.value || !words.length) return [];
    return props.employees.filter(e => words.every(w => normalize(employeeName(e)).includes(w))).slice(0, 8);
});
const showSuggestions = ref(false);
function hideSuggestionsSoon() { setTimeout(() => { showSuggestions.value = false; }, 150); }

function query(extra = {}) {
    const params = new URLSearchParams({ from: from.value, to: to.value, ...extra });
    if (hr.value && search.value.trim()) params.set('q', search.value.trim());
    if (filter.value) params.set('filter', filter.value);
    return params;
}

function validRange(maxDays) {
    if (!from.value || !to.value || from.value > to.value) return 'اختر فترة صحيحة.';
    const days = Math.round((Date.parse(`${to.value}T12:00:00Z`) - Date.parse(`${from.value}T12:00:00Z`)) / 86400000) + 1;
    return days > maxDays ? `الحد الأقصى ${maxDays} يومًا.` : '';
}

/** Reloads the stored results for the current filters; quiet=true keeps the table as is while loading. */
async function load(newPage = page.value, quiet = false) {
    const invalid = validRange(hr.value ? 31 : 93);
    if (invalid) { error.value = invalid; return; }
    controller?.abort();
    controller = new AbortController();
    if (!quiet) loading.value = true;
    try {
        const params = query(hr.value ? { page: String(newPage) } : {});
        const result = await getJson(`${props.endpoint}?${params}`, controller.signal);
        rows.value = result.data || [];
        summary.value = result.summary || null;
        todayRow.value = result.today || null;
        page.value = result.current_page || 1;
        lastPage.value = result.last_page || 1;
        total.value = result.total ?? rows.value.length;
        refreshedAt.value = new Date();
        error.value = '';
        emit('refreshed', summary.value);
    } catch (e) {
        if (e.name !== 'AbortError') error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function submit() { open.value = new Set(); load(1); }
function pick(value) { filter.value = filter.value === value ? '' : value; load(1); }
function choose(e) { search.value = employeeName(e); showSuggestions.value = false; load(1); }
function toggle(row) {
    const key = `${row.employee_id}|${row.work_date}`;
    const next = new Set(open.value);
    next.has(key) ? next.delete(key) : next.add(key);
    open.value = next;
}
const isOpen = row => open.value.has(`${row.employee_id}|${row.work_date}`);

async function exportXlsx() {
    const invalid = validRange(93);
    if (invalid) { error.value = invalid; return; }
    exporting.value = true;
    error.value = '';
    try {
        await downloadWorkbook(`${props.exportUrl}?${query(props.confidentialExport && withActions.value ? { with_actions: '1' } : {})}`);
    } catch (e) {
        error.value = e.message;
    } finally {
        exporting.value = false;
    }
}

function schedule() {
    clearTimeout(timer);
    if (props.autoRefreshSeconds > 0) {
        timer = setTimeout(async () => {
            if (document.visibilityState === 'visible' && !loading.value) await load(page.value, true);
            schedule();
        }, props.autoRefreshSeconds * 1000);
    }
}
function onVisible() { if (document.visibilityState === 'visible' && refreshedAt.value && Date.now() - refreshedAt.value > props.autoRefreshSeconds * 1000) load(page.value, true); }

watch([from, to], () => { if (from.value && to.value && from.value <= to.value && !validRange(hr.value ? 31 : 93)) load(1); });
onMounted(() => { load(1); schedule(); document.addEventListener('visibilitychange', onVisible); });
onUnmounted(() => { clearTimeout(timer); controller?.abort(); document.removeEventListener('visibilitychange', onVisible); });
defineExpose({ refresh: () => load(page.value, true) });

const cards = computed(() => {
    const s = summary.value || {};
    return [
        ['on_time', 'ملتزم', s.on_time, 'border-emerald-300 bg-emerald-50 text-emerald-900'],
        ['grace', 'ضمن السماح', s.grace, 'border-yellow-300 bg-yellow-50 text-yellow-900'],
        ['late', 'متأخر', s.late, 'border-red-300 bg-red-50 text-red-900'],
        ['working', 'قيد العمل', s.working, 'border-sky-300 bg-sky-50 text-sky-900'],
        ['absent', 'غائب', s.absent, 'border-slate-300 bg-white text-slate-800'],
        ['review', 'تحتاج مراجعة', s.review, 'border-amber-300 bg-amber-50 text-amber-900'],
        ['not_enrolled', 'غير مسجل على الجهاز', s.not_enrolled, 'border-orange-200 bg-orange-50 text-orange-900'],
    ];
});
const singleDay = computed(() => from.value === to.value);
const time = date => date ? date.toLocaleTimeString('en-GB', { hour12: false }) : '—';
const scheduleText = row => row.expected_in ? `${row.expected_in} – ${row.expected_out}` : '—';
const lateText = row => {
    if (row.actual_late_minutes === null || row.actual_late_minutes === undefined) return '—';
    if (row.actual_late_minutes === 0) return '0';
    const parts = [];
    if (row.excused_late_minutes) parts.push(`بإذن ${row.excused_late_minutes}`);
    parts.push(row.chargeable_late_minutes > 0 ? `المحتسب ${row.chargeable_late_minutes}` : (row.excused_late_minutes ? 'المحتسب 0' : 'ضمن السماح'));
    return `${row.actual_late_minutes} د (${parts.join('، ')})`;
};
const durationText = row => row.attendance_minutes !== null && row.attendance_minutes !== undefined ? minutesLabel(row.attendance_minutes)
    : (row.elapsed_minutes !== null && row.elapsed_minutes !== undefined ? `${minutesLabel(row.elapsed_minutes)} حتى الآن` : '—');
</script>

<template>
    <section class="space-y-4" dir="rtl">
        <!-- Overview -->
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
            <button v-for="[key, label, value, tone] in cards" :key="key" type="button"
                class="rounded-xl border p-3 text-right transition hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500"
                :class="[tone, filter === key ? 'ring-2 ring-navy-600' : '']" :aria-pressed="filter === key" @click="pick(key)">
                <span class="block text-xs font-semibold opacity-80">{{ label }}</span>
                <span class="mt-1 block text-2xl font-bold">{{ value ?? '—' }}</span>
            </button>
        </div>
        <p v-if="summary && !singleDay" class="text-xs text-slate-500">الأرقام لكل الأيام في الفترة المحددة ({{ summary.total }} يوم عمل/موظف).</p>

        <div v-if="!hr && todayRow" class="card flex flex-wrap items-center gap-3 text-sm">
            <strong class="text-navy-900">اليوم:</strong>
            <span>الدخول {{ todayRow.actual_in || '—' }}</span><span>الخروج {{ todayRow.actual_out || '—' }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-bold ring-1" :class="punctualityTone[todayRow.punctuality]">{{ todayRow.punctuality_label }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-bold ring-1" :class="dayTone[todayRow.day_state]">{{ todayRow.day_label }}</span>
        </div>
        <div v-if="!hr && summary" class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
            <div class="card p-3"><span class="block text-xs text-slate-500">أيام مكتملة</span><strong>{{ summary.complete }}</strong></div>
            <div class="card p-3"><span class="block text-xs text-slate-500">دقائق التأخير المحتسبة</span><strong>{{ summary.late_minutes }}</strong></div>
            <div class="card p-3"><span class="block text-xs text-slate-500">دقائق السماح المستخدمة</span><strong>{{ summary.grace_minutes_used }}</strong></div>
            <div class="card p-3"><span class="block text-xs text-slate-500">خروج مبكر (دقيقة / أيام)</span><strong>{{ summary.early_minutes }} / {{ summary.early }}</strong></div>
        </div>

        <!-- Filters -->
        <form class="card grid gap-3 md:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto]" @submit.prevent="submit">
            <label class="text-sm font-semibold text-slate-700">من تاريخ<input v-model="from" required type="date" class="field mt-1" /></label>
            <label class="text-sm font-semibold text-slate-700">إلى تاريخ<input v-model="to" required type="date" class="field mt-1" /></label>
            <div v-if="hr" class="relative text-sm font-semibold text-slate-700">
                <label for="attendance-name-search">اسم الموظف</label>
                <input id="attendance-name-search" v-model="search" type="search" autocomplete="off" class="field mt-1" placeholder="اكتب الاسم أو جزءًا منه"
                    aria-autocomplete="list" aria-controls="attendance-name-results" @focus="showSuggestions = true" @input="showSuggestions = true"
                    @keydown.esc="showSuggestions = false" @blur="hideSuggestionsSoon" />
                <ul v-if="showSuggestions && suggestions.length" id="attendance-name-results" role="listbox"
                    class="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                    <li v-for="e in suggestions" :key="e.id" role="option" :aria-selected="false">
                        <button type="button" class="w-full px-3 py-2 text-right font-normal hover:bg-navy-50" @mousedown.prevent="choose(e)">{{ employeeName(e) }}</button>
                    </li>
                </ul>
            </div>
            <label class="text-sm font-semibold text-slate-700">الحالة
                <select v-model="filter" class="field mt-1" @change="load(1)">
                    <option v-for="[value, label] in filterOptions" :key="value" :value="value">{{ label }}</option>
                </select>
            </label>
            <div class="flex flex-wrap items-end gap-2">
                <button class="btn btn-primary" :disabled="loading">{{ loading ? 'جارٍ البحث...' : 'عرض' }}</button>
                <button type="button" class="btn btn-secondary inline-flex items-center gap-1" :disabled="loading" @click="load(page)">
                    <svg class="h-4 w-4" :class="{ 'animate-spin': loading }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14-5L4 8" /><path d="M4 4v4h4" /><path d="M4 13a8 8 0 0 0 14 5l2-2" /><path d="M20 20v-4h-4" /></svg>
                    تحديث حالة البصمات
                </button>
                <button type="button" class="btn btn-secondary" :disabled="exporting" @click="exportXlsx">{{ exporting ? 'جارٍ التصدير...' : 'تصدير Excel' }}</button>
                <label v-if="confidentialExport" class="flex items-center gap-1 text-xs text-slate-600"><input v-model="withActions" type="checkbox" /> تضمين الإجراءات الإدارية (سري)</label>
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
            <span>عدد النتائج: <strong class="text-navy-900">{{ total }}</strong><template v-if="hr && lastPage > 1"> — الصفحة {{ page }} من {{ lastPage }}</template></span>
            <span>آخر تحديث: <span dir="ltr">{{ time(refreshedAt) }}</span> — يتجدد تلقائيًا كل {{ autoRefreshSeconds }} ثانية</span>
        </div>
        <p v-if="error" role="alert" class="alert-error">{{ error }}</p>

        <!-- Wide screens: table -->
        <div class="hidden overflow-hidden rounded-xl border border-slate-300 lg:block">
            <table class="w-full table-fixed border-collapse text-sm">
                <colgroup>
                    <col class="w-[8%]" /><col v-if="hr" class="w-[14%]" /><col class="w-[9%]" /><col class="w-[6%]" /><col class="w-[7%]" />
                    <col class="w-[8%]" /><col class="w-[10%]" /><col class="w-[7%]" /><col class="w-[11%]" /><col class="w-[10%]" /><col />
                </colgroup>
                <thead class="bg-navy-800 text-white">
                    <tr class="[&>th]:border-l [&>th]:border-navy-600 [&>th]:px-2 [&>th]:py-2.5 [&>th]:text-right [&>th]:font-semibold">
                        <th>التاريخ</th><th v-if="hr">الموظف</th><th>الدوام المقرر</th><th>الدخول</th><th>الخروج</th><th>مدة الحضور</th><th>تأخير</th>
                        <th>خروج مبكر</th><th>الحالة</th><th>الالتزام بالوقت</th><th>ملاحظات التصحيح</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.id">
                        <tr class="cursor-pointer align-top odd:bg-white even:bg-slate-50 hover:bg-navy-50 [&>td]:border-l [&>td]:border-t [&>td]:border-slate-200 [&>td]:px-2 [&>td]:py-2"
                            :class="punctualityEdge[row.punctuality]" :aria-expanded="isOpen(row)" tabindex="0"
                            @click="toggle(row)" @keydown.enter.prevent="toggle(row)" @keydown.space.prevent="toggle(row)">
                            <td dir="ltr" class="text-right">{{ row.work_date }}</td>
                            <td v-if="hr" class="font-semibold text-navy-900">{{ row.employee_name }}<span class="block text-xs font-normal text-slate-500">#{{ row.employee_number }}</span></td>
                            <td><span dir="ltr" class="block text-right">{{ scheduleText(row) }}</span><span v-if="row.shift_name" class="block text-xs text-slate-500">{{ row.shift_name }}</span></td>
                            <td dir="ltr" class="text-right font-semibold">{{ row.actual_in || '—' }}</td>
                            <td dir="ltr" class="text-right">{{ row.actual_out || '—' }}</td>
                            <td>{{ durationText(row) }}</td>
                            <td>{{ lateText(row) }}</td>
                            <td :class="row.early_minutes > 0 ? 'font-semibold text-red-700' : ''">{{ row.early_minutes > 0 ? minutesLabel(row.early_minutes) : (row.early_minutes === 0 ? '0' : '—') }}</td>
                            <td>
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-bold ring-1" :class="dayTone[row.day_state]">{{ row.day_label }}</span>
                                <span v-if="row.day_hint" class="mt-1 block text-xs text-slate-500">{{ row.day_hint }}</span>
                            </td>
                            <td>
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-bold ring-1" :class="punctualityTone[row.punctuality]">{{ row.punctuality_label }}</span>
                                <span v-if="row.punctuality_provisional" class="mt-1 block text-xs text-amber-800">{{ row.punctuality_provisional }}</span>
                            </td>
                            <td class="text-xs leading-5">
                                <p v-for="c in row.corrections" :key="c.kind + c.id" :class="c.status === 'reverted' ? 'text-slate-500 line-through decoration-slate-300' : 'text-slate-800'">
                                    <span class="font-semibold">{{ c.at }}</span> {{ c.note }}<span v-if="c.reversion_reason" class="block no-underline">سبب التراجع: {{ c.reversion_reason }}</span>
                                </p>
                                <p v-for="note in row.notes" :key="note" class="text-amber-900">{{ note }}</p>
                                <p v-for="line in row.info || []" :key="line" class="text-sky-900">{{ line }}</p>
                                <p v-for="r in (row.requests || []).filter(r => r.status !== 'approved')" :key="r.kind + r.id" class="text-amber-800">⏳ {{ r.label }}</p>
                                <p v-for="a in row.hr_actions || []" :key="'a' + a.id" class="font-semibold text-red-800">⚑ {{ a.label }}</p>
                                <span v-if="!row.corrections.length && !row.notes.length && !(row.info || []).length && !(row.requests || []).length && !(row.hr_actions || []).length" class="text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="isOpen(row)" class="bg-white">
                            <td :colspan="hr ? 11 : 10" class="border-t border-slate-200 p-3"><AttendanceDayDetail :url="dayUrl(row.employee_id, row.work_date)" @changed="load(page, true)" /></td>
                        </tr>
                    </template>
                    <tr v-if="!loading && !rows.length"><td :colspan="hr ? 11 : 10" class="p-6 text-center text-slate-500">لا توجد نتائج للفلاتر المحددة.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Narrow screens: cards -->
        <div class="space-y-3 lg:hidden">
            <article v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-300 bg-white p-3 shadow-sm" :class="punctualityEdge[row.punctuality]">
                <button type="button" class="w-full space-y-2 text-right" :aria-expanded="isOpen(row)" @click="toggle(row)">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <strong class="text-navy-900">{{ hr ? row.employee_name : row.work_date }}</strong>
                        <span v-if="hr" class="text-xs text-slate-500" dir="ltr">{{ row.work_date }}</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold ring-1" :class="dayTone[row.day_state]">{{ row.day_label }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold ring-1" :class="punctualityTone[row.punctuality]">{{ row.punctuality_label }}</span>
                        <span v-if="row.early_minutes > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-bold text-red-700 ring-1 ring-red-200">خروج مبكر {{ minutesLabel(row.early_minutes) }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-slate-500">الدوام المقرر</dt><dd dir="ltr" class="text-right">{{ scheduleText(row) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">الدخول / الخروج</dt><dd dir="ltr" class="text-right">{{ row.actual_in || '—' }} / {{ row.actual_out || '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">مدة الحضور</dt><dd>{{ durationText(row) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">تأخير</dt><dd>{{ lateText(row) }}</dd></div>
                    </dl>
                    <p v-if="row.day_hint" class="text-xs text-slate-500">{{ row.day_hint }}</p>
                    <p v-for="note in row.notes" :key="note" class="text-xs text-amber-900">{{ note }}</p>
                    <p v-for="line in row.info || []" :key="line" class="text-xs text-sky-900">{{ line }}</p>
                    <p v-for="r in (row.requests || []).filter(r => r.status !== 'approved')" :key="r.kind + r.id" class="text-xs text-amber-800">⏳ {{ r.label }}</p>
                    <p v-for="a in row.hr_actions || []" :key="'a' + a.id" class="text-xs font-semibold text-red-800">⚑ {{ a.label }}</p>
                    <p v-for="c in row.corrections" :key="c.kind + c.id" class="text-xs" :class="c.status === 'reverted' ? 'text-slate-500' : 'text-slate-700'">{{ c.at }} — {{ c.note }}</p>
                </button>
                <AttendanceDayDetail v-if="isOpen(row)" class="mt-3" :url="dayUrl(row.employee_id, row.work_date)" @changed="load(page, true)" />
            </article>
            <p v-if="!loading && !rows.length" class="card text-center text-slate-500">لا توجد نتائج للفلاتر المحددة.</p>
        </div>

        <div v-if="hr && lastPage > 1" class="flex items-center gap-3">
            <button class="btn btn-secondary" type="button" :disabled="loading || page <= 1" @click="load(page - 1)">السابق</button>
            <button class="btn btn-secondary" type="button" :disabled="loading || page >= lastPage" @click="load(page + 1)">التالي</button>
        </div>
        <p class="text-xs leading-5 text-slate-500">
            الالتزام بالوقت يُحسب من بصمة الدخول المؤكدة فقط (ملتزم حتى الدقيقة 0، ضمن السماح حتى نهاية فترة السماح، ثم متأخر)، وهو مستقل عن اكتمال الدوام والخروج المبكر.
            «تأخير بإذن» يعني أن إذنًا معتمدًا غطّى التأخير دون تغيير وقت البصمة الفعلي؛ الطلبات قيد الاعتماد لا تُحتسب.
            «غير مسجل على جهاز البصمة» و«لا يوجد دوام مقرر» لا تُعد غيابًا. اضغط على أي سطر لعرض البصمات الأصلية والتصحيحات.
        </p>
    </section>
</template>
