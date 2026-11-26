<script setup>
import { arabicLatinDigitsLocale, formatDate, parseDate } from '../Support/dates.js';
import { computed, nextTick, ref, watch } from 'vue';
import { Deferred, Link, router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import EmptyState from '../Components/EmptyState.vue';
import KpiCards from '../Components/KpiCards.vue';
import StatusBadge from '../Components/StatusBadge.vue';
import StatusStepper from '../Components/StatusStepper.vue';
import { entitlementText } from '../Support/leaveEntitlement.js';
import { prioritizeActions, taskDeadline, uniqueShortcuts } from '../Support/homePriorities.js';

const props = defineProps({
    executiveWorkspace: { type: Boolean, default: false },
    home: { type: Object, required: true },
    readiness: { type: Array, default: null },
    // Deferred blocks: undefined while loading, null when the block is not on this user's dashboard.
    kpis: { type: Object, default: undefined },
    todo: { type: Object, default: undefined },
    correspondence: { type: Object, default: undefined },
    calendar: { type: Array, default: undefined },
    activity: { type: Array, default: undefined },
    hrServices: { type: Array, default: undefined },
    rentals: { type: Object, default: undefined },
    // Blocks to show, in order and by section, resolved on the server from the templates and the user's own access.
    layout: { type: Array, default: () => [] },
    actionCenter: { type: Array, default: () => [] },
    shortcuts: { type: Array, default: () => [] },
    notifications: { type: Array, default: () => [] },
});

const number = value => new Intl.NumberFormat(arabicLatinDigitsLocale, { maximumFractionDigits: 2 }).format(value ?? 0);
const date = value => formatDate(value, { year: 'numeric', month: 'short', day: 'numeric' }, '—');
const dateTime = value => formatDate(value, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
const dayLabel = value => formatDate(value, { weekday: 'long', day: 'numeric', month: 'long' });
const pending = items => (items || []).filter(item => item.count > 0);
const ago = value => {
    const parsed = parseDate(value);
    if (!parsed) return '—';
    const minutes = Math.round((Date.now() - parsed.getTime()) / 60000);
    if (minutes < 1) return 'الآن';
    if (minutes < 60) return `قبل ${minutes} دقيقة`;
    if (minutes < 1440) return `قبل ${Math.round(minutes / 60)} ساعة`;
    return date(value);
};

// Width of a block on large screens; every block takes the full width on phones.
const sizeClass = { full: '', two_thirds: 'xl:col-span-8', half: 'md:col-span-6', third: 'md:col-span-6 xl:col-span-4' };
const personalDashboardBlockOrder = ['tasks', 'todo', 'my_requests', 'correspondence', 'leave_balances', 'calendar', 'notifications', 'activity', 'evaluation', 'profile', 'team'];
const dashboardLayout = computed(() => {
    if (props.executiveWorkspace) return props.layout;

    return props.layout
        .filter(block => personalDashboardBlockOrder.includes(block.key))
        .sort((first, second) => personalDashboardBlockOrder.indexOf(first.key) - personalDashboardBlockOrder.indexOf(second.key));
});
const has = key => dashboardLayout.value.some(block => block.key === key);
const priorityActions = computed(() => prioritizeActions(props.actionCenter));
const quietActions = computed(() => props.actionCenter.filter(item => Number(item.value) === 0));
const headerLinks = computed(() => [
    ...(has('correspondence') ? [{ code: 'home-correspondence', label: 'مراسلاتي', url: '/correspondence' }] : []),
    ...(has('tasks') ? [{ code: 'home-tasks', label: props.executiveWorkspace ? 'الاعتمادات والمهام المحالة إليّ' : 'مهامي الرسمية', url: '/tasks' }] : []),
]);
const roleShortcuts = computed(() => props.executiveWorkspace
    ? uniqueShortcuts(props.shortcuts, [...headerLinks.value.map(item => item.url), ...(has('todo') ? ['/my/todo'] : [])])
    : []);
const leadingShortcuts = computed(() => roleShortcuts.value.slice(0, 3));
const remainingShortcuts = computed(() => roleShortcuts.value.slice(3));
const taskView = ref('all');
const receivedAt = ref(new Date());
const refreshing = ref(false);
const refreshError = ref('');
watch(() => props.home, () => { receivedAt.value = new Date(); });
const receivedTime = computed(() => new Intl.DateTimeFormat(arabicLatinDigitsLocale, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(receivedAt.value));
const visibleTasks = computed(() => taskView.value === 'overdue'
    ? props.home.tasks.filter(task => taskDeadline(task)?.tone === 'danger') : props.home.tasks);
const sections = computed(() => [
    { key: 'executive', label: 'الاعتمادات والقرارات والمتابعة', note: 'المهام المحالة إليك حسب صلاحياتك، ومؤشرات الأداء للاطلاع.' },
    { key: 'base', label: 'مساحتي الأساسية', note: 'عملي وطلباتي وسجلاتي.' },
    { key: 'role', label: 'أعمال أدواري', note: 'تظهر حسب أدوارك وتكليفاتك ونطاق صلاحياتك فقط.' },
].map(section => ({ ...section, blocks: dashboardLayout.value.filter(block => (block.section || 'base') === section.key && !['welcome', 'action_center'].includes(block.key) && (block.key !== 'shortcuts' || remainingShortcuts.value.length > 0)) }))
    .filter(section => section.blocks.length));

const toneClass = { danger: 'border-red-200 bg-red-50 text-red-800', warning: 'border-gold-200 bg-gold-50 text-gold-900', info: 'border-blue-200 bg-blue-50 text-blue-900', muted: 'border-slate-200 bg-white text-slate-700' };
const openGaps = computed(() => (props.readiness ?? []).filter(check => !check.ok).length);

const stats = computed(() => [
    ...(!props.actionCenter.some(item => item.key === 'tasks') ? [
        { label: 'بانتظار إجرائي', value: props.home.stats.tasks, css: 'text-gold-700', bar: 'bg-gold-400', note: 'طلبات وإجازات تنتظر قرارك' },
        { label: 'متأخرة عن المهلة', value: props.home.stats.overdue, css: props.home.stats.overdue ? 'text-red-700' : 'text-slate-600', bar: props.home.stats.overdue ? 'bg-red-500' : 'bg-slate-200', note: 'من المهام المسندة إليك' },
    ] : []),
    { label: 'طلباتي الجارية', value: props.home.stats.open, css: 'text-blue-700', bar: 'bg-blue-600', note: 'لم يُبت فيها بعد' },
    { label: 'طلباتي المنجزة', value: props.home.stats.completed, css: 'text-navy-700', bar: 'bg-navy-700', note: 'مكتملة أو معتمدة' },
]);

const evaluationColor = computed(() => ({ success: 'text-emerald-600', info: 'text-blue-600', warning: 'text-gold-500', danger: 'text-red-600' }[props.home.evaluation?.tone] || 'text-slate-300'));
const evaluationNote = computed(() => ({ success: 'bg-emerald-50 text-emerald-800', info: 'bg-blue-50 text-blue-900', warning: 'bg-gold-50 text-gold-900', danger: 'bg-red-50 text-red-800' }[props.home.evaluation?.tone] || 'bg-slate-50 text-slate-600'));

function seniorityText(months) {
    if (months === null || months === undefined) return 'غير محددة';
    const years = Math.floor(months / 12);
    const rest = months % 12;
    return [years ? `${years} سنة` : '', rest ? `${rest} شهر` : ''].filter(Boolean).join(' و') || 'أقل من شهر';
}
function remaining(task) {
    return taskDeadline(task);
}

function refreshSummary() {
    if (refreshing.value || todoBusy.value) return;
    refreshing.value = true;
    refreshError.value = '';
    let succeeded = false;
    router.reload({
        only: ['home', 'actionCenter'],
        onSuccess: () => { succeeded = true; },
        onFinish: () => {
            refreshing.value = false;
            if (!succeeded) refreshError.value = 'تعذّر تحديث الملخص. البيانات المعروضة هي آخر نسخة مستلمة؛ حاول مجددًا.';
        },
    });
}

// Personal To-Do: private to the user; ticking an item never completes official work.
const quickTitle = ref('');
const quickDueDate = ref('');
const currentTime = new Date();
const quickDueHour = ref(String(currentTime.getHours() % 12 || 12).padStart(2, '0'));
const quickDueMinute = ref(String(currentTime.getMinutes()).padStart(2, '0'));
const quickDuePeriod = ref(currentTime.getHours() >= 12 ? 'pm' : 'am');
const quickDuePickerOpen = ref(false);
const quickDueHours = Array.from({ length: 12 }, (_, index) => String(index + 1).padStart(2, '0'));
const quickDueMinutes = Array.from({ length: 60 }, (_, minute) => String(minute).padStart(2, '0'));
const quickDue = computed(() => {
    if (!quickDueDate.value) return '';
    const hour = Number(quickDueHour.value) % 12 + (quickDuePeriod.value === 'pm' ? 12 : 0);

    return `${quickDueDate.value}T${String(hour).padStart(2, '0')}:${quickDueMinute.value}`;
});
const todoBusy = ref(false);
const reloadPersonal = () => ({ preserveScroll: true, only: ['todo', 'actionCenter', 'calendar', 'activity'], onFinish: () => { todoBusy.value = false; } });
function openQuickDuePicker() {
    quickDuePickerOpen.value = !quickDuePickerOpen.value;
}
function addTodo() {
    if (!quickTitle.value.trim()) return;
    todoBusy.value = true;
    router.post('/my/todo', { title: quickTitle.value.trim(), due_at: quickDue.value || null }, { ...reloadPersonal(), onSuccess: () => { quickTitle.value = ''; quickDueDate.value = ''; quickDuePickerOpen.value = false; } });
}
function completeTodo(item) {
    todoBusy.value = true;
    router.post(`/my/todo/${item.id}/complete`, {}, reloadPersonal());
}
const isLate = value => (parseDate(value)?.getTime() ?? Infinity) < Date.now();
async function focusTodo() {
    if (!has('todo')) { router.visit('/my/todo'); return; }
    await nextTick();
    const input = document.getElementById('todo-quick-title');
    if (!input) { router.visit('/my/todo'); return; }
    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
    input.focus({ preventScroll: true });
}
const goToStart = () => document.getElementById('start-options')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
</script>

<template>
    <AppLayout :title="executiveWorkspace ? 'لوحة المدير التنفيذي' : 'مساحتي'">
        <div class="space-y-3">
            <section v-if="executiveWorkspace && (actionCenter.length || leadingShortcuts.length || has('start_options') || has('todo'))" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3" aria-label="إجراءات سريعة وملخص التحديث">
                <p class="text-xs text-slate-500">آخر تحديث <bdi>{{ receivedTime }}</bdi></p>
                <nav aria-label="إجراءات سريعة" class="flex flex-wrap items-center gap-2">
                    <button v-if="has('start_options') && home.startOptions.length" type="button" class="btn btn-primary btn-sm" @click="goToStart">+ طلب جديد</button>
                    <button v-if="has('todo')" type="button" class="btn btn-secondary btn-sm" @click="focusTodo">+ مهمة شخصية</button>
                    <Link v-for="shortcut in headerLinks" :key="shortcut.code" :href="shortcut.url" class="btn btn-secondary btn-sm">{{ shortcut.label }}</Link>
                    <Link v-for="shortcut in leadingShortcuts" :key="shortcut.code" :href="shortcut.url" class="btn btn-secondary btn-sm">{{ shortcut.label }}</Link>
                    <button type="button" :disabled="refreshing || todoBusy" class="btn btn-secondary btn-sm" @click="refreshSummary">{{ refreshing ? 'جارٍ التحديث…' : 'تحديث الملخص' }}</button>
                </nav>
            </section>
            <p v-if="refreshError" role="alert" class="alert-error">{{ refreshError }}</p>

            <h1 class="text-lg font-bold text-navy-800">{{ executiveWorkspace ? 'ملخص الأعمال' : 'ملخص أعمالي' }}</h1>
            <section v-if="executiveWorkspace && priorityActions.length" aria-labelledby="home-action-center">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h2 id="home-action-center" class="text-base font-bold text-navy-800">يحتاج إجرائي الآن</h2>
                        <p class="text-xs text-slate-600">الأعمال المتأخرة أولًا، ثم بقية ما ينتظر إجراءك.</p>
                    </div>
                    <p class="text-xs text-slate-500">قد تظهر المراسلة ضمن المهام الرسمية أيضًا؛ الأعداد ليست مجموعًا مستقلًا.</p>
                </div>
                <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="item in priorityActions" :key="item.key">
                        <Link :href="item.url" class="flex h-full flex-col rounded-xl border p-2.5 transition hover:shadow-sm focus-visible:ring-2 focus-visible:ring-navy-400" :class="toneClass[item.value ? item.tone : 'muted']">
                            <span class="text-xs font-semibold leading-4">{{ item.label }}</span>
                            <strong class="mt-1 font-display text-xl">{{ number(item.value) }}</strong>
                            <span v-if="item.detail" class="text-[10px] leading-4 opacity-80">{{ item.detail }}</span>
                        </Link>
                    </li>
                </ul>
                <details v-if="quietActions.length" class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
                    <summary class="cursor-pointer rounded text-sm font-semibold text-slate-700">قوائم بلا إجراءات معلّقة ({{ number(quietActions.length) }})</summary>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Link v-for="item in quietActions" :key="item.key" :href="item.url" class="btn btn-secondary btn-sm">{{ item.label }} · {{ number(item.value) }}</Link>
                    </div>
                </details>
            </section>

            <section v-for="section in sections" :key="section.key" :aria-labelledby="`home-section-${section.key}`" class="space-y-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 pb-1.5">
                    <h2 :id="`home-section-${section.key}`" class="text-base font-bold text-navy-800">{{ section.label }}</h2>
                    <p class="text-xs text-slate-500">{{ section.note }}</p>
                </div>
                <div :class="executiveWorkspace ? 'grid grid-cols-12 items-start gap-2' : 'columns-1 gap-2 lg:columns-2 2xl:columns-3'">
                    <div v-for="block in section.blocks" :key="block.key" class="w-full" :class="executiveWorkspace ? `col-span-12 ${sizeClass[block.size]}` : 'mb-2 inline-block break-inside-avoid'">
                        <section v-if="block.key === 'readiness' && readiness" class="card" :class="openGaps ? 'border-gold-200' : 'border-emerald-200'">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h3 class="card-title">جاهزية الإعدادات الأساسية</h3>
                                    <p class="text-sm text-slate-500">توضح نواقص الأدوار والملفات والأرصدة. تشغيل كل قسم يحتاج أيضًا إلى التحقق من دورة عمله واعتمادها.</p>
                                </div>
                                <StatusBadge :label="openGaps ? `${number(openGaps)} بنود تحتاج إكمالًا` : 'اكتملت الفحوص الحالية للإعدادات'" :tone="openGaps ? 'warning' : 'success'" />
                            </div>
                            <ul class="divide-y divide-slate-100">
                                <li v-for="check in readiness" :key="check.code" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="flex min-w-0 gap-3">
                                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" :class="check.ok ? 'bg-emerald-100 text-emerald-700' : 'bg-gold-100 text-gold-800'" aria-hidden="true">{{ check.ok ? '✓' : '!' }}</span>
                                        <div class="min-w-0">
                                            <p class="font-semibold" :class="check.ok ? 'text-emerald-800' : 'text-navy-800'">{{ check.title }}<span class="sr-only">{{ check.ok ? ' (مكتمل)' : ' (يحتاج إكمالًا)' }}</span></p>
                                            <ul v-if="!check.ok" class="mt-1 space-y-0.5 text-sm text-slate-600"><li v-for="item in check.items" :key="item">{{ item }}</li></ul>
                                        </div>
                                    </div>
                                    <Link v-if="!check.ok && check.url" :href="check.url" class="btn btn-secondary btn-sm shrink-0 self-start">{{ check.action }}</Link>
                                </li>
                            </ul>
                        </section>

                        <section v-else-if="block.key === 'start_options' && home.startOptions.length" id="start-options" class="card scroll-mt-24">
                            <h3 class="card-title mb-3">طلب جديد</h3>
                            <div class="flex flex-wrap gap-2">
                                <template v-for="option in home.startOptions" :key="option.label">
                                    <Link v-if="option.url" :href="option.url" class="btn btn-primary">+ {{ option.label }}</Link>
                                    <span v-else class="btn cursor-not-allowed bg-slate-100 text-slate-400" :title="option.reason" aria-disabled="true">+ {{ option.label }}</span>
                                </template>
                            </div>
                            <p v-for="option in home.startOptions.filter(item => !item.url && item.reason)" :key="`reason-${option.label}`" class="mt-2 text-xs text-amber-700">{{ option.label }}: {{ option.reason }}</p>
                            <p class="mt-3 text-xs leading-5 text-slate-500">يُوجَّه طلبك لمسؤوله حسب المسار المعتمد، ولا يمكنك اعتماد طلبك بنفسك.</p>
                        </section>

                        <section v-else-if="block.key === 'stats'" class="grid grid-cols-2 gap-2" :class="stats.length > 2 ? 'xl:grid-cols-4' : ''" aria-label="ملخص طلباتي">
                            <div v-for="item in stats" :key="item.label" class="card relative overflow-hidden p-4 sm:p-5">
                                <span class="absolute inset-y-0 right-0 w-1" :class="item.bar" aria-hidden="true"></span>
                                <p class="text-sm font-semibold text-navy-800">{{ item.label }}</p>
                                <p class="mt-2 font-display text-3xl font-bold" :class="item.css">{{ number(item.value) }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ item.note }}</p>
                            </div>
                        </section>

                        <section v-else-if="block.key === 'shortcuts' && remainingShortcuts.length" class="card">
                            <h3 class="card-title mb-3">اختصارات</h3>
                            <div class="flex flex-wrap gap-2"><Link v-for="shortcut in remainingShortcuts" :key="shortcut.code" :href="shortcut.url" class="btn btn-secondary">{{ shortcut.label }}</Link></div>
                        </section>

                        <section v-else-if="block.key === 'tasks'" class="card h-full">
                            <div class="mb-4 flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                <div>
                                    <h3 class="card-title">{{ executiveWorkspace ? 'الاعتمادات والمهام المحالة إليّ' : 'مهامي الرسمية — بانتظار إجرائي' }}</h3>
                                    <p class="text-xs text-slate-500">تُنجز بإجراء المسار نفسه داخل المعاملة.</p>
                                </div>
                                <Link href="/tasks" class="text-xs font-semibold text-blue-700 hover:underline">كل المهام</Link>
                            </div>
                            <div v-if="home.tasks.length" class="mb-3 flex flex-wrap items-center gap-2" aria-label="تصفية المهام المعروضة">
                                <button type="button" :aria-pressed="taskView === 'all'" class="btn btn-sm" :class="taskView === 'all' ? 'btn-primary' : 'btn-secondary'" @click="taskView = 'all'">كل المعروض</button>
                                <button type="button" :aria-pressed="taskView === 'overdue'" class="btn btn-sm" :class="taskView === 'overdue' ? 'btn-primary' : 'btn-secondary'" @click="taskView = 'overdue'">المتأخرة فقط</button>
                                <span class="text-xs text-slate-600">يعرض الملخص {{ number(home.tasks.length) }} من {{ number(home.stats.tasks) }} مهمة</span>
                            </div>
                            <ul v-if="visibleTasks.length" class="space-y-3">
                                <li v-for="task in visibleTasks" :key="task.key" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4" :class="remaining(task)?.tone === 'danger' ? 'border-red-200 bg-red-50/60' : 'border-slate-200'">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-navy-800">{{ task.title }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ task.detail }} · مقدم الطلب: {{ task.requester }} · <span dir="ltr">{{ task.reference }}</span></p>
                                        <p v-if="task.dueAt" class="mt-1 text-xs text-slate-700">الموعد: {{ dateTime(task.dueAt) }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <StatusBadge v-if="remaining(task)" :label="remaining(task).label" :tone="remaining(task).tone" />
                                        <Link :href="task.url" class="btn btn-primary btn-sm">فتح واتخاذ إجراء</Link>
                                    </div>
                                </li>
                            </ul>
                            <EmptyState v-else :title="taskView === 'overdue' && home.tasks.length ? 'لا توجد مهام متأخرة ضمن المعروض' : 'لا توجد معاملات بانتظارك'" description="راجع قائمة المهام الكاملة للاطلاع على جميع المعاملات المتاحة لك." />
                        </section>

                        <Deferred v-else-if="block.key === 'todo' && todo !== null" data="todo">
                            <template #fallback><section class="card h-full animate-pulse" role="status" aria-label="جارٍ تحميل مهامي الشخصية"><div class="h-5 w-40 rounded bg-slate-100"></div><div class="mt-4 h-24 rounded bg-slate-50"></div></section></template>
                            <section class="card h-full" aria-labelledby="home-todo-title">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <h3 id="home-todo-title" class="card-title">قائمة مهامي الشخصية</h3>
                                    <Link href="/my/todo" class="text-xs font-semibold text-blue-700 hover:underline">كل مهامي</Link>
                                </div>
                                <p class="mb-3 text-xs text-slate-500">مفتوحة {{ number(todo?.open) }} · تستحق اليوم {{ number(todo?.today) }}<span v-if="todo?.overdue" class="font-semibold text-red-700"> · متأخرة {{ number(todo.overdue) }}</span></p>
                                <form class="mb-3 flex flex-nowrap items-center gap-2" @submit.prevent="addTodo">
                                    <label for="todo-quick-title" class="sr-only">عنوان المهمة الشخصية</label>
                                    <input id="todo-quick-title" v-model="quickTitle" maxlength="200" required class="field min-w-0 flex-1" placeholder="أضف مهمة شخصية..." />
                                    <div class="relative shrink-0">
                                        <button type="button" class="btn btn-secondary btn-sm" aria-label="اختيار موعد استحقاق" :aria-expanded="quickDuePickerOpen" aria-controls="todo-quick-due-picker" title="اختيار موعد استحقاق" @click="openQuickDuePicker">
                                            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="5" width="18" height="16" rx="2" />
                                                <path d="M16 3v4M8 3v4M3 10h18" />
                                            </svg>
                                        </button>
                                        <div v-if="quickDuePickerOpen" id="todo-quick-due-picker" class="absolute end-0 top-full z-20 mt-2 w-72 rounded-lg border border-slate-200 bg-white p-3 text-start shadow-lg" dir="rtl">
                                            <label for="todo-quick-due-date" class="field-label">موعد الاستحقاق</label>
                                            <input id="todo-quick-due-date" v-model="quickDueDate" type="date" class="field mt-1 w-full" />
                                            <div class="mt-3 grid grid-cols-3 gap-2">
                                                <div>
                                                    <label for="todo-quick-due-hour" class="field-label">الساعة</label>
                                                    <select id="todo-quick-due-hour" v-model="quickDueHour" class="field mt-1 w-full">
                                                        <option v-for="hour in quickDueHours" :key="hour" :value="hour">{{ hour }}</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="todo-quick-due-minute" class="field-label">الدقيقة</label>
                                                    <select id="todo-quick-due-minute" v-model="quickDueMinute" class="field mt-1 w-full">
                                                        <option v-for="minute in quickDueMinutes" :key="minute" :value="minute">{{ minute }}</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="todo-quick-due-period" class="field-label">الفترة</label>
                                                    <select id="todo-quick-due-period" v-model="quickDuePeriod" class="field mt-1 w-full">
                                                        <option value="am">صباحًا (ص)</option>
                                                        <option value="pm">مساءً (م)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-secondary btn-sm mt-3 w-full" @click="quickDuePickerOpen = false">تم</button>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm shrink-0" :disabled="todoBusy">إضافة</button>
                                </form>
                                <p v-if="quickDue" class="-mt-1 mb-3 text-xs text-slate-500">موعد الاستحقاق: {{ dateTime(quickDue) }}</p>
                                <ul v-if="todo?.items?.length" class="divide-y divide-slate-100">
                                    <li v-for="item in todo.items" :key="item.id" class="flex items-start gap-3 py-2">
                                        <input :id="`todo-${item.id}`" type="checkbox" class="mt-1 h-4 w-4" :disabled="todoBusy" @change="completeTodo(item)" />
                                        <label :for="`todo-${item.id}`" class="min-w-0 flex-1 text-sm">
                                            <span class="block font-semibold text-navy-800">{{ item.title }}</span>
                                            <span class="block text-xs" :class="isLate(item.due_at) ? 'font-semibold text-red-700' : 'text-slate-500'">{{ item.due_at ? (isLate(item.due_at) ? 'متأخرة · ' : '') + dateTime(item.due_at) : 'بلا موعد' }}<span v-if="item.priority"> · {{ item.priority }}</span></span>
                                        </label>
                                    </li>
                                </ul>
                                <EmptyState v-else title="لا توجد مهام شخصية مفتوحة" />
                                <p class="mt-2 text-[11px] text-slate-400">خاصة بك وحدك، ولا تُنجز معاملة رسمية.</p>
                            </section>
                        </Deferred>

                        <section v-else-if="block.key === 'my_requests'" class="card h-full">
                            <div class="mb-4 flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                <h3 class="card-title">طلباتي — أين وصلت؟</h3>
                                <Link href="/requests" class="text-xs font-semibold text-blue-700 hover:underline">كل طلباتي</Link>
                            </div>
                            <ul v-if="home.myRequests.length" class="space-y-3">
                                <li v-for="request in home.myRequests" :key="request.key" class="rounded-xl border border-slate-100 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-navy-800">{{ request.title }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ request.kind }} · <span dir="ltr">{{ request.reference }}</span> · {{ date(request.createdAt) }}</p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <StatusBadge :label="request.status" :tone="request.tone" />
                                            <Link :href="request.url" class="btn btn-secondary btn-sm">التفاصيل</Link>
                                        </div>
                                    </div>
                                    <StatusStepper v-if="request.steps.length" class="mt-3" :steps="request.steps" compact />
                                    <p v-if="request.summary?.where" class="mt-2 text-xs text-slate-500">
                                        الآن: <strong class="text-navy-800">{{ request.summary.where }}</strong>
                                        <span v-if="request.summary.next"> · التالي: {{ request.summary.next }}</span>
                                        <span v-if="request.summary.overdue" class="font-semibold text-red-700"> · تجاوزت المهلة</span>
                                    </p>
                                    <p v-if="request.summary?.reason" class="alert-warning mt-2 text-xs">السبب: {{ request.summary.reason }}</p>
                                </li>
                            </ul>
                            <EmptyState v-else title="لم تقدّم أي طلب بعد" />
                        </section>

                        <Deferred v-else-if="block.key === 'correspondence' && correspondence !== null" data="correspondence">
                            <template #fallback><section class="card h-full animate-pulse" role="status" aria-label="جارٍ تحميل مراسلاتي"><div class="h-5 w-32 rounded bg-slate-100"></div><div class="mt-4 h-24 rounded bg-slate-50"></div></section></template>
                            <section class="card h-full" aria-labelledby="home-correspondence-title">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <h3 id="home-correspondence-title" class="card-title">مراسلاتي</h3>
                                    <Link v-if="correspondence?.available" href="/correspondence" class="text-xs font-semibold text-blue-700 hover:underline">سجل المراسلات</Link>
                                </div>
                                <EmptyState v-if="!correspondence?.available" title="سجل المراسلات غير مفعّل بعد" />
                                <template v-else>
                                    <dl class="mb-3 grid grid-cols-2 gap-2 text-center text-xs">
                                        <div class="rounded-lg bg-gold-50 p-2"><dt class="text-gold-800"><Link href="/correspondence?box=needs_action">تنتظر إجرائي</Link></dt><dd class="text-base font-bold text-gold-900">{{ number(correspondence.pending) }}</dd></div>
                                        <div class="rounded-lg bg-blue-50 p-2"><dt class="text-blue-800"><Link href="/correspondence?box=needs_action&unread=1">غير مقروءة</Link></dt><dd class="text-base font-bold text-blue-900">{{ number(correspondence.unread) }}</dd></div>
                                        <div class="rounded-lg bg-red-50 p-2"><dt class="text-red-800"><Link href="/correspondence?box=needs_action&overdue=1">متأخرة لدي</Link></dt><dd class="text-base font-bold text-red-900">{{ number(correspondence.overdue) }}</dd></div>
                                        <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-600"><Link href="/correspondence?box=sent">الصادر</Link></dt><dd class="text-base font-bold text-navy-800">{{ number(correspondence.outgoing) }}</dd><dd>مفتوحة</dd></div>
                                    </dl>
                                    <ul v-if="correspondence.items.length" class="divide-y divide-slate-100">
                                        <li v-for="item in correspondence.items" :key="item.url">
                                            <Link :href="item.url" class="flex items-start gap-2 py-2 text-sm hover:text-navy-900">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.unread ? 'bg-blue-600' : 'bg-transparent'"><span v-if="item.unread" class="sr-only">غير مقروءة</span></span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate font-semibold text-navy-800">{{ item.subject }}</span>
                                                    <span class="block text-xs text-slate-500">{{ item.direction }} · <span dir="ltr">{{ item.reference }}</span> · {{ item.status }}<span v-if="item.due_at"> · الموعد {{ date(item.due_at) }}</span></span>
                                                </span>
                                            </Link>
                                        </li>
                                    </ul>
                                    <EmptyState v-else title="لا توجد مراسلات لك" />
                                </template>
                            </section>
                        </Deferred>

                        <section v-else-if="block.key === 'leave_balances'" class="card h-full">
                            <div class="mb-4 flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                <h3 class="card-title">رصيد إجازاتي {{ new Date().getFullYear() }}</h3>
                                <Link v-if="home.leaveBalances !== null" href="/leaves/balances" class="text-xs font-semibold text-blue-700 hover:underline">التفاصيل</Link>
                            </div>
                            <p v-if="home.leaveBasis" class="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-xs leading-6 text-slate-600">
                                يُحسب استحقاقك وفق: <strong>{{ home.leaveBasis.employmentTerm || 'بند توظيف غير محدد' }}</strong>
                                · <strong>{{ home.leaveBasis.contractType || 'نوع عقد غير محدد' }}</strong>
                                · أقدمية <strong>{{ seniorityText(home.leaveBasis.serviceMonths) }}</strong>
                            </p>
                            <EmptyState v-if="home.leaveBalances === null" title="لا ينطبق: حسابك غير مرتبط بملف موظف" description="راجع الموارد البشرية لإكمال ملفك." />
                            <EmptyState v-else-if="!home.leaveBalances.length" title="لا يوجد استحقاق إجازات معرّف لملفك بعد" />
                            <div v-else class="space-y-3">
                                <div v-for="balance in home.leaveBalances" :key="balance.type" class="rounded-xl border border-slate-100 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="font-semibold text-navy-800">{{ balance.type }}</p>
                                        <StatusBadge v-if="balance.entitlement && balance.entitlement.status !== 'compensatory'" :label="'المتوقع: ' + entitlementText(balance.entitlement).label" :tone="entitlementText(balance.entitlement).tone" />
                                    </div>
                                    <p v-if="balance.entitlement && entitlementText(balance.entitlement).note" class="mt-1 text-[11px] leading-5 text-slate-500">{{ entitlementText(balance.entitlement).note }}</p>
                                    <p v-if="!balance.openingRecorded" class="alert-warning mt-2 text-xs">لم تسجل الموارد البشرية رصيدك الافتتاحي لهذا العام بعد.</p>
                                    <dl v-else class="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
                                        <div class="rounded-lg bg-navy-50 p-2"><dt class="text-navy-600">المسجل</dt><dd class="mt-1 text-base font-bold text-navy-800">{{ number(balance.registered) }}</dd></div>
                                        <div class="rounded-lg bg-gold-50 p-2"><dt class="text-gold-700">قيد الاعتماد</dt><dd class="mt-1 text-base font-bold text-gold-800">{{ number(balance.reserved) }}</dd></div>
                                        <div class="rounded-lg bg-emerald-50 p-2"><dt class="text-emerald-700">المتاح</dt><dd class="mt-1 text-base font-bold text-emerald-700">{{ number(balance.available) }}</dd></div>
                                    </dl>
                                </div>
                            </div>
                        </section>

                        <Deferred v-else-if="block.key === 'calendar' && calendar !== null" data="calendar">
                            <template #fallback><section class="card h-full animate-pulse" role="status" aria-label="جارٍ تحميل التقويم"><div class="h-5 w-32 rounded bg-slate-100"></div><div class="mt-4 h-24 rounded bg-slate-50"></div></section></template>
                            <section class="card h-full" aria-labelledby="home-calendar-title">
                                <h3 id="home-calendar-title" class="card-title mb-1">التقويم الشخصي</h3>
                                <p class="mb-3 text-xs text-slate-500">الأسبوعان القادمان؛ يظهر مصدر كل موعد.</p>
                                <ul v-if="calendar?.length" class="space-y-2">
                                    <li v-for="(event, index) in calendar" :key="index" class="rounded-lg border border-slate-100 p-2 text-sm">
                                        <component :is="event.url ? Link : 'div'" :href="event.url || undefined" class="block">
                                            <span class="block text-xs font-semibold text-navy-700">{{ dayLabel(event.date) }}<span v-if="event.end && event.end !== event.date"> ← {{ dayLabel(event.end) }}</span></span>
                                            <span class="block text-navy-800">{{ event.title }}</span>
                                            <StatusBadge class="mt-1" :label="event.source" :tone="event.tone" />
                                        </component>
                                    </li>
                                </ul>
                                <EmptyState v-else title="لا مواعيد في الأسبوعين القادمين" />
                            </section>
                        </Deferred>

                        <section v-else-if="block.key === 'notifications'" class="card h-full" aria-labelledby="home-notifications-title">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <h3 id="home-notifications-title" class="card-title">الإشعارات والتذكيرات</h3>
                                <Link href="/notifications" class="text-xs font-semibold text-blue-700 hover:underline">كل الإشعارات والتفضيلات</Link>
                            </div>
                            <ul v-if="notifications.length" class="space-y-2 text-sm">
                                <li v-for="item in notifications" :key="item.id">
                                    <Link :href="`/notifications/${item.id}/open`" method="post" as="button" class="block w-full rounded-lg border border-slate-100 p-2 text-right hover:border-navy-200" :class="item.read ? '' : 'bg-blue-50/50'">
                                        <span class="block font-semibold text-navy-800">{{ item.title }}<span v-if="!item.read" class="sr-only"> (غير مقروء)</span></span>
                                        <span v-if="item.body" class="block text-xs text-slate-500">{{ item.body }}</span>
                                        <span class="block text-[11px] text-slate-400">{{ date(item.createdAt) }}</span>
                                    </Link>
                                </li>
                            </ul>
                            <EmptyState v-else title="لا توجد إشعارات" />
                        </section>

                        <Deferred v-else-if="block.key === 'activity' && activity !== null" data="activity">
                            <template #fallback><section class="card h-full animate-pulse" role="status" aria-label="جارٍ تحميل نشاطاتي"><div class="h-5 w-32 rounded bg-slate-100"></div><div class="mt-4 h-24 rounded bg-slate-50"></div></section></template>
                            <section class="card h-full" aria-labelledby="home-activity-title">
                                <h3 id="home-activity-title" class="card-title mb-3">آخر نشاطاتي</h3>
                                <ol v-if="activity?.length" class="space-y-2 border-r-2 border-navy-100 pr-3">
                                    <li v-for="(item, index) in activity" :key="index" class="text-sm">
                                        <component :is="item.url ? Link : 'div'" :href="item.url || undefined" class="block hover:text-navy-900">
                                            <span class="block font-semibold text-navy-800">{{ item.label }}</span>
                                            <span class="block text-xs text-slate-500">{{ item.target }} · {{ ago(item.at) }}</span>
                                        </component>
                                    </li>
                                </ol>
                                <EmptyState v-else title="لا توجد نشاطات بعد" />
                            </section>
                        </Deferred>

                        <section v-else-if="block.key === 'evaluation' && home.evaluation" class="card h-full" aria-labelledby="self-evaluation-title">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h3 id="self-evaluation-title" class="card-title">تقييمي الذاتي</h3>
                                    <p class="text-xs text-slate-500">{{ home.evaluation.month }} · يظهر لك وحدك</p>
                                </div>
                                <StatusBadge v-if="home.evaluation.trend !== null" :label="home.evaluation.trend >= 0 ? `▲ ${home.evaluation.trend}% عن الشهر السابق` : `▼ ${Math.abs(home.evaluation.trend)}% عن الشهر السابق`" :tone="home.evaluation.trend >= 0 ? 'success' : 'warning'" />
                            </div>
                            <div class="mt-4 flex items-center gap-5">
                                <div class="relative h-24 w-24 shrink-0" role="img" :aria-label="home.evaluation.rate === null ? 'لا توجد نسبة بعد' : `نسبة الإنجاز ${home.evaluation.rate}%`">
                                    <svg viewBox="0 0 36 36" class="h-24 w-24 -rotate-90" aria-hidden="true">
                                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3.2" class="text-slate-100" />
                                        <circle v-if="home.evaluation.rate !== null" cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" :stroke-dasharray="`${home.evaluation.rate} 100`" :class="evaluationColor" />
                                    </svg>
                                    <span class="absolute inset-0 flex items-center justify-center font-display text-xl font-bold text-navy-800">{{ home.evaluation.rate === null ? '—' : home.evaluation.rate + '%' }}</span>
                                </div>
                                <dl class="space-y-1.5 text-sm">
                                    <div class="flex gap-2"><dt class="text-slate-500">أنجزت</dt><dd class="font-semibold text-navy-800">{{ number(home.evaluation.done) }} مهمة</dd></div>
                                    <div class="flex gap-2"><dt class="text-slate-500">بانتظارك</dt><dd class="font-semibold text-navy-800">{{ number(home.evaluation.pending) }}</dd></div>
                                    <div v-if="home.evaluation.onTime !== null" class="flex gap-2"><dt class="text-slate-500">في الموعد</dt><dd class="font-semibold text-navy-800">{{ home.evaluation.onTime }}%</dd></div>
                                    <div v-if="home.evaluation.avgHours !== null" class="flex gap-2"><dt class="text-slate-500">متوسط الإنجاز</dt><dd class="font-semibold text-navy-800">{{ number(home.evaluation.avgHours) }} ساعة</dd></div>
                                </dl>
                            </div>
                            <p class="mt-4 rounded-lg px-3 py-2 text-sm" :class="evaluationNote">{{ home.evaluation.message }}</p>
                            <p class="mt-2 text-[11px] leading-5 text-slate-400">النسبة = ما أنجزته ÷ (ما أنجزته + ما ينتظرك) من المهام المسندة إليك هذا الشهر.</p>
                        </section>

                        <section v-else-if="block.key === 'profile'" class="card h-full">
                            <h3 class="card-title mb-4 border-b border-slate-100 pb-3">ملفي الوظيفي</h3>
                            <EmptyState v-if="!home.profile" title="لا يوجد ملف موظف مرتبط بحسابك" />
                            <dl v-else class="space-y-3 text-sm">
                                <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">الرقم الوظيفي</dt><dd class="font-medium" dir="ltr">{{ home.profile.employeeNumber || '—' }}</dd></div>
                                <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">المسمى</dt><dd class="font-medium">{{ home.profile.jobTitle || '—' }}</dd></div>
                                <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">بند التوظيف</dt><dd class="font-medium">{{ home.profile.employmentTerm || '—' }}</dd></div>
                                <div class="flex justify-between gap-2 border-b border-slate-100 pb-2"><dt class="text-slate-500">تاريخ بدء العمل</dt><dd class="font-medium">{{ date(home.profile.workStartDate) }}</dd></div>
                                <div class="flex justify-between gap-2"><dt class="text-slate-500">المسؤول المباشر</dt><dd class="font-medium">{{ home.profile.manager || '—' }}</dd></div>
                                <p v-if="!home.profile.profileComplete" class="alert-warning text-xs">ملفك لم يكتمل بعد لدى الموارد البشرية؛ بعض الخدمات كطلب الإجازة لن تتاح قبل اكتماله.</p>
                            </dl>
                        </section>

                        <section v-else-if="block.key === 'executive' && home.executive" class="card h-full">
                            <h3 class="card-title mb-3">مؤشرات الجمعية</h3>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-xl bg-blue-50 p-3"><p class="font-display text-2xl font-bold text-blue-800">{{ number(home.executive.inProgress) }}</p><p class="text-[11px] text-blue-800">قيد المعالجة</p></div>
                                <div class="rounded-xl p-3" :class="home.executive.overdue ? 'bg-red-50' : 'bg-slate-50'"><p class="font-display text-2xl font-bold" :class="home.executive.overdue ? 'text-red-700' : 'text-slate-400'">{{ number(home.executive.overdue) }}</p><p class="text-[11px] text-slate-600">متأخرة</p></div>
                                <div class="rounded-xl bg-emerald-50 p-3"><p class="font-display text-2xl font-bold text-emerald-700">{{ number(home.executive.completedThisMonth) }}</p><p class="text-[11px] text-emerald-800">أُنجزت هذا الشهر</p></div>
                            </div>
                            <ul v-if="home.executive.decisions.length" class="mt-3 space-y-2">
                                <li v-for="decision in home.executive.decisions" :key="decision.url"><Link :href="decision.url" class="alert-warning flex items-center justify-between gap-2 text-sm hover:opacity-90"><span>{{ decision.label }}</span><span aria-hidden="true">←</span></Link></li>
                            </ul>
                            <p v-else class="mt-3 text-xs text-slate-500">لا توجد قرارات سياسات بانتظارك. طلبات الاعتماد تظهر في «يحتاج إجرائي الآن».</p>
                        </section>

                        <section v-else-if="block.key === 'team' && home.team" class="card h-full">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="card-title">فريقي</h3>
                                <Link v-if="home.team.workspaceUrl" :href="home.team.workspaceUrl" class="text-xs font-semibold text-blue-700 hover:underline">فتح «قسمي»</Link>
                            </div>
                            <p class="mb-3 text-xs text-slate-500">{{ home.team.departments.join('، ') }}</p>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-xl bg-gold-50 p-3"><p class="font-display text-2xl font-bold text-gold-800">{{ number(home.team.pendingLeaves) }}</p><p class="text-[11px] text-gold-800">إجازات بانتظار المراجعة</p></div>
                                <div class="rounded-xl bg-blue-50 p-3"><p class="font-display text-2xl font-bold text-blue-800">{{ number(home.team.inProgress) }}</p><p class="text-[11px] text-blue-800">طلبات القسم الجارية</p></div>
                                <div class="rounded-xl p-3" :class="home.team.overdue ? 'bg-red-50' : 'bg-slate-50'"><p class="font-display text-2xl font-bold" :class="home.team.overdue ? 'text-red-700' : 'text-slate-400'">{{ number(home.team.overdue) }}</p><p class="text-[11px] text-slate-600">متأخرة</p></div>
                            </div>
                            <p class="mt-3 text-xs font-semibold text-slate-600">في إجازة اليوم</p>
                            <ul v-if="home.team.onLeaveToday.length" class="mt-1 space-y-1 text-sm">
                                <li v-for="person in home.team.onLeaveToday" :key="person.name" class="flex justify-between gap-2"><span>{{ person.name }}</span><span class="text-xs text-slate-500">حتى {{ person.until }}</span></li>
                            </ul>
                            <p v-else class="mt-1 text-sm text-slate-500">لا أحد من فريقك في إجازة اليوم.</p>
                        </section>

                        <Deferred v-else-if="block.key === 'hr_services' && hrServices !== null" data="hrServices">
                            <template #fallback><section class="card h-full animate-pulse" role="status" aria-label="جارٍ تحميل مؤشرات الموارد البشرية"><div class="h-5 w-48 rounded bg-slate-100"></div><div class="mt-4 h-24 rounded bg-slate-50"></div></section></template>
                            <section class="card h-full" aria-labelledby="home-hr-title">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <h3 id="home-hr-title" class="card-title">الموارد البشرية والخدمات العامة</h3>
                                    <Link href="/hr-services" class="text-xs font-semibold text-blue-700 hover:underline">اللوحة الكاملة</Link>
                                </div>
                                <dl class="grid grid-cols-2 gap-2 text-xs">
                                    <div v-for="card in hrServices || []" :key="card.key" class="rounded-lg border border-slate-100 p-2">
                                        <dt class="font-semibold text-slate-600">{{ card.label }}</dt>
                                        <dd class="mt-1 font-display text-xl font-bold text-navy-800">{{ card.value ?? '—' }}</dd>
                                        <dd class="text-[11px] leading-4" :class="card.value === null ? 'text-amber-700' : 'text-slate-500'">{{ card.detail }}</dd>
                                    </div>
                                </dl>
                            </section>
                        </Deferred>

                        <Deferred v-else-if="block.key === 'rentals' && rentals !== null" data="rentals">
                            <template #fallback><section class="card h-full animate-pulse" role="status" aria-label="جارٍ تحميل ملخص الأصول والإيجارات"><div class="h-5 w-48 rounded bg-slate-100"></div><div class="mt-4 h-24 rounded bg-slate-50"></div></section></template>
                            <section class="card h-full" aria-labelledby="home-rentals-title">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <h3 id="home-rentals-title" class="card-title">الأصول والمباني والإيجارات</h3>
                                    <Link href="/admin/rentals" class="text-xs font-semibold text-blue-700 hover:underline">فتح الوحدة</Link>
                                </div>
                                <template v-if="rentals?.registered">
                                    <dl class="grid grid-cols-4 gap-1 text-center">
                                        <div v-for="item in rentals.portfolio" :key="item.key" class="rounded-lg bg-slate-50 px-1 py-2">
                                            <dt class="text-[11px] text-slate-600">{{ item.label }}</dt>
                                            <dd class="font-display text-lg font-bold text-navy-800">{{ number(item.value) }}</dd>
                                        </div>
                                    </dl>
                                    <p v-if="rentals.unavailableUnits" class="mt-1 text-[11px] text-slate-500">{{ number(rentals.unavailableUnits) }} من الأعيان تحت الصيانة أو خارج الخدمة.</p>

                                    <h4 class="mt-3 text-xs font-semibold text-slate-600">{{ rentals.decisions.title }}</h4>
                                    <ul v-if="pending(rentals.decisions.items).length" class="mt-1 space-y-1 text-sm">
                                        <li v-for="item in pending(rentals.decisions.items)" :key="item.key">
                                            <Link :href="item.url" class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-2 py-1.5 hover:bg-slate-50"><span>{{ item.label }}</span><StatusBadge :label="number(item.count)" tone="warning" /></Link>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-1 text-sm text-slate-500">لا شيء بانتظار القرار.</p>

                                    <template v-if="rentals.preparation">
                                        <h4 class="mt-3 text-xs font-semibold text-slate-600">للإعداد والمتابعة</h4>
                                        <ul v-if="pending(rentals.preparation).length" class="mt-1 space-y-1 text-sm">
                                            <li v-for="item in pending(rentals.preparation)" :key="item.key">
                                                <Link :href="item.url" class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-2 py-1.5 hover:bg-slate-50"><span>{{ item.label }}</span><StatusBadge :label="number(item.count)" tone="info" /></Link>
                                            </li>
                                        </ul>
                                        <p v-else class="mt-1 text-sm text-slate-500">لا عقود أو معاملات تنتظر الإعداد.</p>
                                    </template>

                                    <h4 class="mt-3 text-xs font-semibold text-slate-600">تنبيهات</h4>
                                    <ul class="mt-1 space-y-1 text-sm">
                                        <li>
                                            <Link :href="rentals.expiring.url" class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-2 py-1.5 hover:bg-slate-50">
                                                <span>عقود تنتهي خلال {{ number(rentals.expiring.days) }} يومًا</span>
                                                <StatusBadge :label="rentals.expiring.count ? `${number(rentals.expiring.count)} (دون تجديد: ${number(rentals.expiring.withoutRenewal)})` : '0'" :tone="rentals.expiring.withoutRenewal ? 'warning' : 'muted'" />
                                            </Link>
                                        </li>
                                        <li>
                                            <Link :href="rentals.overdue.url" class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-2 py-1.5 hover:bg-slate-50">
                                                <span>استحقاقات متأخرة</span>
                                                <StatusBadge :label="rentals.overdue.dues ? `${number(rentals.overdue.dues)} في ${number(rentals.overdue.contracts)} عقد` : '0'" :tone="rentals.overdue.dues ? 'danger' : 'muted'" />
                                            </Link>
                                        </li>
                                    </ul>
                                    <ul v-if="rentals.expiring.items.length" class="mt-2 space-y-1 text-xs text-slate-600">
                                        <li v-for="contract in rentals.expiring.items" :key="contract.number" class="flex items-center justify-between gap-2">
                                            <span dir="ltr" class="font-mono">{{ contract.number }}</span>
                                            <span>ينتهي {{ date(contract.endsOn) }} (بعد {{ number(contract.daysLeft) }} يوم)<span v-if="!contract.renewal" class="text-amber-700"> · دون تجديد</span></span>
                                        </li>
                                    </ul>
                                </template>
                                <EmptyState v-else title="لم تُسجَّل مبانٍ أو أعيان بعد" description="تُضاف المباني من ثوابت النظام ثم الأعيان من «إدارة المباني»؛ بعدها تظهر هنا الأعيان والعقود والتنبيهات." />
                            </section>
                        </Deferred>

                        <section v-else-if="block.key === 'finance' && home.finance" class="card h-full">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <h3 class="card-title">الحسابات</h3>
                                <Link :href="home.finance.url" class="text-xs font-semibold text-blue-700 hover:underline">{{ home.finance.approver ? 'صندوق الاعتمادات' : 'فتح الحسابات' }}</Link>
                            </div>
                            <template v-if="home.finance.approver">
                                <ul v-if="home.finance.total" class="space-y-2 text-sm">
                                    <li v-for="(count, label) in home.finance.counts" :key="label" class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2"><span>{{ label }}</span><StatusBadge :label="number(count)" tone="warning" /></li>
                                </ul>
                                <EmptyState v-else title="لا توجد معاملات مالية بانتظار اعتمادك" />
                            </template>
                            <p v-else class="text-sm text-slate-600">تابع معاملاتك المالية حسب صلاحياتك من قسم الحسابات.</p>
                        </section>

                        <Deferred v-else-if="block.key === 'kpis' && kpis !== null" data="kpis">
                            <template #fallback>
                                <section class="card" role="status" aria-label="جارٍ حساب مؤشرات الأداء">
                                    <div class="h-5 w-48 animate-pulse rounded bg-slate-100"></div>
                                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><div v-for="index in 3" :key="index" class="h-36 animate-pulse rounded-xl bg-slate-50"></div></div>
                                </section>
                            </template>
                            <section v-if="kpis" class="card space-y-4" aria-labelledby="home-kpi-title">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h3 id="home-kpi-title" class="card-title">{{ kpis.title }}</h3>
                                        <p class="text-xs text-slate-500">{{ kpis.scope }} · من {{ kpis.period.from }} حتى {{ kpis.period.to }}</p>
                                    </div>
                                    <Link :href="kpis.url" class="text-xs font-semibold text-blue-700 hover:underline">كل المؤشرات والتفاصيل ←</Link>
                                </div>
                                <KpiCards v-if="kpis.cards.length" :cards="kpis.cards" compact :link="kpis.url" />
                                <EmptyState v-else title="لا توجد مؤشرات مختارة لهذه اللوحة" description="يحدد مدير النظام المؤشرات التي تظهر هنا من «إدارة مؤشرات الأداء»." />
                            </section>
                        </Deferred>
                    </div>
                </div>
            </section>

        </div>
    </AppLayout>
</template>
