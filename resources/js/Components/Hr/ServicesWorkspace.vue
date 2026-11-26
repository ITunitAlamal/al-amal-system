<script setup>
import { arabicLatinDigitsLocale, formatDate as safeDate } from '../../Support/dates.js';
import { Deferred, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';

import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

// «الموارد البشرية والخدمات العامة»: read-only overview; every action opens the existing screen that performs it.
defineOptions({ inheritAttrs: false });
const props = defineProps({
    endpoint: { type: String, default: '/hr-services' },
    pages: { type: Object, default: () => ({}) },
    today: { type: String, required: true },
    filters: { type: Object, required: true },
    attendanceStatuses: { type: Object, default: () => ({}) },
    serviceFilters: { type: Object, default: () => ({}) },
    links: { type: Array, default: () => [] },
    kpis: { type: Object, default: null },
    actions: { type: Object, default: null },
    attendance: { type: Object, default: null },
    recentLeaves: { type: Object, default: null },
    services: { type: Object, default: null },
});

const form = reactive({ ...props.filters });
const pages = reactive({ attendance_page: 1, leaves_page: 1, services_page: 1, ...props.pages });
watch(() => props.filters, value => Object.assign(form, value));
watch(() => props.pages, value => Object.assign(pages, value));
const query = () => Object.fromEntries(Object.entries({ ...form, ...pages }).filter(([key, value]) => value !== '' && value !== null && !(key.endsWith('_page') && value === 1)));
function reload(only = undefined) {
    router.get(props.endpoint, query(), { preserveScroll: true, preserveState: true, ...(only ? { only: [...only, 'filters', 'pages'] } : {}) });
}
function changeDate(value) {
    form.date = value || props.today;
    Object.assign(pages, { attendance_page: 1, leaves_page: 1, services_page: 1 });
    reload();
}
function goPage(key, page, only) {
    pages[key] = page;
    reload([only]);
}
function filterServices(value) {
    form.services = value;
    pages.services_page = 1;
    reload(['services']);
}
function searchAttendance() {
    pages.attendance_page = 1;
    reload(['attendance']);
}

const dateTitle = computed(() => new Intl.DateTimeFormat(arabicLatinDigitsLocale, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(`${form.date}T00:00:00`)));
const formatDate = value => safeDate(value, { day: 'numeric', month: 'short', year: 'numeric' }, '—');
const formatTime = value => {
    if (!value) return '—';
    const text = String(value);
    return /[ T]/.test(text) ? text.slice(11, 16) : text.slice(0, 5);
};
const attendanceTone = code => ({ present: 'success', late: 'warning', early: 'warning', missing_punches: 'danger', incomplete_punches: 'danger', approved_leave: 'info', official_holiday: 'info', emergency_holiday: 'info', off: 'muted' }[code] || 'warning');
</script>

<template>
    <section aria-label="الموارد البشرية والخدمات العامة">
        <div class="space-y-6">
            <PageHeader eyebrow="قسم الموارد البشرية والخدمات العامة" title="الموارد البشرية والخدمات العامة"
                description="متابعة الموظفين والحضور والانصراف والإجازات والمهام والخدمات العامة والصيانة، والانتقال مباشرة إلى الإجراء المطلوب.">
                <template #meta>
                    <p class="mt-2 text-sm font-semibold text-navy-700">{{ dateTitle }}</p>
                </template>
                <label for="hr-date" class="sr-only">اليوم المعروض</label>
                <input id="hr-date" type="date" class="field w-auto" :max="today" :value="form.date" @change="changeDate($event.target.value)" />
                <button v-if="form.date !== today" type="button" class="btn btn-secondary btn-sm" @click="changeDate(today)">اليوم</button>
            </PageHeader>

            <nav v-if="links.length" aria-label="روابط الموارد البشرية" class="flex flex-wrap gap-2">
                <Link v-for="link in links" :key="link.url" :href="link.url" class="btn btn-secondary btn-sm">{{ link.label }}</Link>
            </nav>

            <Deferred data="kpis">
                <template #fallback>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" role="status" aria-label="جارٍ حساب المؤشرات">
                        <div v-for="index in 4" :key="index" class="card h-28 animate-pulse bg-slate-50"></div>
                    </div>
                </template>
                <template #rescue>
                    <p role="alert" class="alert-error">تعذر حساب المؤشرات الآن. <button type="button" class="underline" @click="reload(['kpis'])">أعد المحاولة</button></p>
                </template>
                <section aria-label="المؤشرات" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <component :is="card.url ? (card.url.startsWith('#') ? 'a' : Link) : 'div'" v-for="card in kpis?.main || []" :key="card.key"
                            :href="card.url || undefined" class="card relative overflow-hidden p-4 text-right" :class="card.url ? 'transition hover:border-navy-300' : ''">
                            <span class="absolute inset-y-0 right-0 w-1" :class="card.value === null ? 'bg-slate-200' : 'bg-navy-700'" aria-hidden="true"></span>
                            <span class="text-sm font-semibold text-slate-600">{{ card.label }}</span>
                            <strong class="mt-2 block font-display text-3xl text-navy-800">{{ card.value ?? '—' }}</strong>
                            <span class="mt-1 block text-xs leading-5" :class="card.value === null ? 'text-amber-700' : 'text-slate-500'">{{ card.detail }}</span>
                        </component>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <component :is="card.url ? Link : 'div'" v-for="card in kpis?.secondary || []" :key="card.key" :href="card.url || undefined"
                            class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-right" :class="card.url ? 'transition hover:border-navy-300' : ''">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-slate-700">{{ card.label }}</span>
                                <span class="block text-xs leading-5" :class="card.value === null ? 'text-amber-700' : 'text-slate-500'">{{ card.detail }}</span>
                            </span>
                            <strong class="font-display text-2xl text-navy-800">{{ card.value ?? '—' }}</strong>
                        </component>
                    </div>
                </section>
            </Deferred>

            <section aria-labelledby="hr-actions" class="space-y-3">
                <h2 id="hr-actions" class="text-lg font-bold text-navy-800">يحتاج إلى إجراء</h2>
                <Deferred data="actions">
                    <template #fallback>
                        <div class="grid gap-4 xl:grid-cols-3" role="status" aria-label="جارٍ تحميل ما يحتاج إلى إجراء">
                            <div v-for="index in 3" :key="index" class="card h-64 animate-pulse bg-slate-50"></div>
                        </div>
                    </template>
                    <template #rescue>
                        <p role="alert" class="alert-error">تعذر تحميل هذا القسم. <button type="button" class="underline" @click="reload(['actions'])">أعد المحاولة</button></p>
                    </template>
                    <div class="grid gap-4 xl:grid-cols-3">
                        <article class="card min-w-0">
                            <h3 class="card-title">طلبات الإجازات المعلقة</h3>
                            <EmptyState v-if="!actions?.leaves?.length" class="mt-3" title="لا توجد طلبات إجازة معلقة" />
                            <ul v-else class="mt-3 divide-y divide-slate-100">
                                <li v-for="(leave, index) in actions.leaves" :key="index" class="py-3 text-sm">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-navy-800">{{ leave.employee }} <span class="text-xs font-normal text-slate-500">· {{ leave.department || '—' }}</span></p>
                                            <p class="text-xs text-slate-600">{{ leave.type }} · {{ formatDate(leave.start_date) }} ← {{ formatDate(leave.end_date) }}<span v-if="leave.days"> · {{ leave.days }} يوم</span></p>
                                            <p class="text-xs text-slate-500">قُدّم {{ formatDate(leave.submitted_at) }} · {{ leave.step || leave.status }}<span v-if="leave.holders"> — لدى {{ leave.holders }}</span></p>
                                        </div>
                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            <StatusBadge v-if="leave.mine" label="بانتظار إجرائك" tone="warning" />
                                            <Link :href="leave.url" class="btn btn-secondary btn-sm">فتح الطلب</Link>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </article>

                        <article class="card min-w-0">
                            <h3 class="card-title">استثناءات الحضور</h3>
                            <p class="mt-1 text-xs text-slate-500">غياب البصمة يحتاج مراجعة ولا يُعد غيابًا ثابتًا؛ التصحيح من شاشة الحضور ويُسجَّل منفصلًا عن البصمات الأصلية.</p>
                            <EmptyState v-if="!actions?.attendance?.length" class="mt-3" title="لا توجد حالات حضور تحتاج مراجعة في هذا اليوم" />
                            <ul v-else class="mt-3 divide-y divide-slate-100">
                                <li v-for="(row, index) in actions.attendance" :key="index" class="flex items-start justify-between gap-2 py-3 text-sm">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-navy-800">{{ row.employee }} <span class="text-xs font-normal text-slate-500">· {{ row.department || '—' }}</span></p>
                                        <p class="text-xs text-slate-600">المتوقع {{ formatTime(row.expected_in) }}–{{ formatTime(row.expected_out) }} · الفعلي {{ formatTime(row.actual_in) }}–{{ formatTime(row.actual_out) }}</p>
                                        <p v-if="row.late_minutes || row.early_minutes" class="text-xs text-amber-700"><span v-if="row.late_minutes">تأخير {{ row.late_minutes }} د</span><span v-if="row.late_minutes && row.early_minutes"> · </span><span v-if="row.early_minutes">خروج مبكر {{ row.early_minutes }} د</span></p>
                                    </div>
                                    <StatusBadge :label="row.status" :tone="attendanceTone(row.status_code)" />
                                </li>
                            </ul>
                            <Link href="/admin/attendance" class="mt-3 inline-block text-sm font-semibold text-navy-700 underline">شاشة الحضور والانصراف</Link>
                        </article>

                        <article class="card min-w-0">
                            <h3 class="card-title">المهام المعلقة والمتأخرة</h3>
                            <p class="mt-1 text-xs text-slate-500">مهام موظفي الموارد البشرية والخدمات العامة في المعاملات الجارية، والمتأخرة أولًا.</p>
                            <EmptyState v-if="!actions?.tasks?.length" class="mt-3" title="لا توجد مهام مفتوحة" />
                            <ul v-else class="mt-3 divide-y divide-slate-100">
                                <li v-for="(task, index) in actions.tasks" :key="index" class="flex items-start justify-between gap-2 py-3 text-sm">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-navy-800">{{ task.title }}</p>
                                        <p class="text-xs text-slate-600"><span dir="ltr">{{ task.reference }}</span> · {{ task.assignee }}<span v-if="task.priority"> · الأولوية: {{ task.priority }}</span></p>
                                        <p class="text-xs text-slate-500">الموعد: {{ task.due_at ? formatDate(task.due_at) : 'بلا مهلة محددة' }}</p>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <StatusBadge :label="task.status" :tone="task.overdue ? 'danger' : 'info'" />
                                        <Link v-if="task.url" :href="task.url" class="btn btn-secondary btn-sm">فتح</Link>
                                    </div>
                                </li>
                            </ul>
                        </article>
                    </div>
                </Deferred>
            </section>

            <section id="services" class="card min-w-0 scroll-mt-24" aria-labelledby="hr-services-title">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 id="hr-services-title" class="card-title">طلبات الخدمات العامة والصيانة</h2>
                        <p class="mt-1 text-xs text-slate-500">المرحلة والمسؤول الحالي من مسار الطلب نفسه؛ لا يُغلق طلب بانتهاء موعده.</p>
                    </div>
                    <div class="flex flex-wrap gap-1" role="group" aria-label="تصفية الطلبات">
                        <button v-for="(label, key) in serviceFilters" :key="key" type="button" class="btn btn-sm" :class="form.services === key ? 'btn-primary' : 'btn-secondary'" :aria-pressed="form.services === key" @click="filterServices(key)">{{ label }}</button>
                    </div>
                </div>
                <Deferred data="services">
                    <template #fallback><div class="h-40 animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل الطلبات"></div></template>
                    <EmptyState v-if="!services?.data?.length" title="لا توجد طلبات ضمن هذا التصنيف" />
                    <div v-else class="overflow-x-auto">
                        <table class="data-table min-w-full">
                            <thead><tr><th scope="col">رقم الطلب</th><th scope="col">النوع</th><th scope="col">القسم الطالب</th><th scope="col">الموقع</th><th scope="col">الأولوية</th><th scope="col">المرحلة</th><th scope="col">المسؤول الحالي</th><th scope="col">الموعد</th><th scope="col">الحالة</th></tr></thead>
                            <tbody>
                                <tr v-for="row in services.data" :key="row.reference">
                                    <td dir="ltr" class="whitespace-nowrap text-right"><Link v-if="row.url" :href="row.url" class="font-semibold text-navy-700 underline">{{ row.reference }}</Link><span v-else>{{ row.reference }}</span></td>
                                    <td>{{ row.category }}</td><td>{{ row.department || '—' }}</td><td class="max-w-48 truncate" :title="row.location">{{ row.location || '—' }}</td>
                                    <td>{{ row.priority || '—' }}</td><td>{{ row.step }}</td><td>{{ row.holders || '—' }}</td>
                                    <td class="whitespace-nowrap">{{ row.due_at ? formatDate(row.due_at) : '—' }}</td>
                                    <td><StatusBadge :label="row.status" :tone="row.overdue ? 'danger' : row.status === 'مكتملة' ? 'success' : 'info'" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav v-if="services?.last_page > 1" aria-label="صفحات الطلبات" class="mt-4 flex items-center justify-between gap-3">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="services.current_page <= 1" @click="goPage('services_page', services.current_page - 1, 'services')">السابق</button>
                        <span class="text-sm text-slate-600">صفحة {{ services.current_page }} من {{ services.last_page }} — {{ services.total }} طلبًا</span>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="services.current_page >= services.last_page" @click="goPage('services_page', services.current_page + 1, 'services')">التالي</button>
                    </nav>
                </Deferred>
            </section>

            <section class="card min-w-0" aria-labelledby="hr-attendance-title">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="hr-attendance-title" class="card-title">الحضور اليومي</h2>
                        <p class="mt-1 text-xs text-slate-500">نتائج الحضور المحتسبة من البصمات وجداول الدوام والإجازات المعتمدة لليوم المحدد.</p>
                    </div>
                    <form class="flex flex-wrap gap-2" @submit.prevent="searchAttendance">
                        <label for="hr-search" class="sr-only">بحث عن موظف</label>
                        <input id="hr-search" v-model="form.q" class="field w-48" placeholder="اسم الموظف أو رقمه" />
                        <label for="hr-status" class="sr-only">الحالة</label>
                        <select id="hr-status" v-model="form.attendance_status" class="field w-44" @change="searchAttendance">
                            <option value="">كل الحالات</option>
                            <option v-for="(label, key) in attendanceStatuses" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
                    </form>
                </div>
                <Deferred data="attendance">
                    <template #fallback><div class="h-40 animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل الحضور"></div></template>
                    <EmptyState v-if="!attendance?.data?.length" title="لا توجد نتائج حضور لهذا اليوم" description="تظهر النتائج بعد مزامنة البصمات واحتسابها للموظفين المسندة لهم جداول دوام." />
                    <div v-else class="overflow-x-auto">
                        <table class="data-table min-w-full">
                            <thead><tr><th scope="col">الموظف</th><th scope="col">الرقم الوظيفي</th><th scope="col">القسم</th><th scope="col">وقت الحضور</th><th scope="col">وقت الانصراف</th><th scope="col">الحالة</th></tr></thead>
                            <tbody>
                                <tr v-for="(row, index) in attendance.data" :key="index">
                                    <td class="font-semibold text-navy-800">{{ row.employee }}</td><td dir="ltr" class="text-right">{{ row.employee_number || '—' }}</td><td>{{ row.department || '—' }}</td>
                                    <td>{{ formatTime(row.actual_in) }}</td><td>{{ formatTime(row.actual_out) }}</td>
                                    <td><StatusBadge :label="row.status" :tone="attendanceTone(row.status_code)" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav v-if="attendance?.last_page > 1" aria-label="صفحات الحضور" class="mt-4 flex items-center justify-between gap-3">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="attendance.current_page <= 1" @click="goPage('attendance_page', attendance.current_page - 1, 'attendance')">السابق</button>
                        <span class="text-sm text-slate-600">صفحة {{ attendance.current_page }} من {{ attendance.last_page }} — {{ attendance.total }} موظفًا</span>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="attendance.current_page >= attendance.last_page" @click="goPage('attendance_page', attendance.current_page + 1, 'attendance')">التالي</button>
                    </nav>
                </Deferred>
            </section>

            <section class="card min-w-0" aria-labelledby="hr-leaves-title">
                <h2 id="hr-leaves-title" class="card-title mb-4">أحدث طلبات الإجازات</h2>
                <Deferred data="recentLeaves">
                    <template #fallback><div class="h-40 animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل الإجازات"></div></template>
                    <EmptyState v-if="!recentLeaves?.data?.length" title="لا توجد طلبات إجازة بعد" />
                    <div v-else class="overflow-x-auto">
                        <table class="data-table min-w-full">
                            <thead><tr><th scope="col">الموظف</th><th scope="col">القسم</th><th scope="col">نوع الإجازة</th><th scope="col">الفترة</th><th scope="col">تاريخ التقديم</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
                            <tbody>
                                <tr v-for="(leave, index) in recentLeaves.data" :key="index">
                                    <td class="font-semibold text-navy-800">{{ leave.employee }}</td><td>{{ leave.department || '—' }}</td><td>{{ leave.type }}</td>
                                    <td class="whitespace-nowrap">{{ formatDate(leave.start_date) }} ← {{ formatDate(leave.end_date) }}</td>
                                    <td class="whitespace-nowrap">{{ formatDate(leave.submitted_at) }}</td>
                                    <td><StatusBadge :label="leave.status" tone="info" /><span v-if="leave.holders" class="block text-xs text-slate-500">لدى {{ leave.holders }}</span></td>
                                    <td><Link :href="leave.url" class="btn btn-secondary btn-sm">فتح</Link></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav v-if="recentLeaves?.last_page > 1" aria-label="صفحات الإجازات" class="mt-4 flex items-center justify-between gap-3">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="recentLeaves.current_page <= 1" @click="goPage('leaves_page', recentLeaves.current_page - 1, 'recentLeaves')">السابق</button>
                        <span class="text-sm text-slate-600">صفحة {{ recentLeaves.current_page }} من {{ recentLeaves.last_page }}</span>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="recentLeaves.current_page >= recentLeaves.last_page" @click="goPage('leaves_page', recentLeaves.current_page + 1, 'recentLeaves')">التالي</button>
                    </nav>
                </Deferred>
            </section>
        </div>
    </section>
</template>
