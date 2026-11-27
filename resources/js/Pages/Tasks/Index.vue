<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatDateTime, parseDate } from '../../Support/dates.js';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import AsyncState from '../../Components/AsyncState.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

/**
 * «مهامي»: everything that waits for the user's action (the same list the sidebar badge counts), and «أنجزتها»,
 * the requests they acted on that are no longer with them. The second list is read when its tab is opened.
 */
const props = defineProps({
    tasks: { type: Array, default: () => [] },
    // /tasks?type=leave: only the leave requests among the tasks (a link from the HR head's home page).
    leaveOnly: { type: Boolean, default: false },
    done: { type: Array, default: null },
});
const waitingTitle = computed(() => `${props.leaveOnly ? 'إجازات تنتظر إجرائي' : 'بانتظار إجرائي'} (${props.tasks.length})`);

const view = ref('waiting');
const doneLoading = ref(false);
const doneError = ref('');
function showDone() {
    view.value = 'done';
    if (props.done !== null || doneLoading.value) return;
    doneLoading.value = true;
    doneError.value = '';
    router.reload({
        only: ['done'],
        onFinish: () => {
            doneLoading.value = false;
            if (props.done === null) doneError.value = 'تعذّر تحميل قائمة ما أنجزته.';
        },
    });
}

const search = ref('');
const selectedKind = ref('');
const selectedStep = ref('');
const selectedDeadline = ref('');
const deadlineNow = ref(Date.now());
let deadlineRefreshTimer = null;

const kinds = computed(() => [...new Set(props.tasks.map(task => task.kind).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ar')));
const steps = computed(() => [...new Set(props.tasks.map(task => task.step).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ar')));
const filtersActive = computed(() => Boolean(search.value.trim() || selectedKind.value || selectedStep.value || selectedDeadline.value));

// «متأخرة» only for a real deadline that has passed: the server sets task.overdue from the request's own deadline,
// and a due time may be reached while the page is open. A task without a deadline is never late; it shows its age.
function deadlineCategory(task) {
    const dueDate = parseDate(task.dueAt);
    if (task.overdue || (dueDate && dueDate.getTime() <= deadlineNow.value)) return 'overdue';
    if (!dueDate) return 'no_deadline';
    return dueDate.getTime() - deadlineNow.value <= 24 * 60 * 60 * 1000 ? 'due_soon' : 'on_track';
}

const deadlineSummary = computed(() => ({
    total: props.tasks.length,
    overdue: props.tasks.filter(task => deadlineCategory(task) === 'overdue').length,
    dueSoon: props.tasks.filter(task => deadlineCategory(task) === 'due_soon').length,
    noDeadline: props.tasks.filter(task => deadlineCategory(task) === 'no_deadline').length,
}));

const visibleTasks = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('ar');

    return props.tasks.filter(task => {
        const searchableText = [task.title, task.reference, task.requester, task.kind, task.step, task.detail, task.ownLabel, task.assignedBy]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase('ar');

        return (!query || searchableText.includes(query))
            && (!selectedKind.value || task.kind === selectedKind.value)
            && (!selectedStep.value || task.step === selectedStep.value)
            && (!selectedDeadline.value || deadlineCategory(task) === selectedDeadline.value);
    });
});

function applyDeadlineFilter(filter) {
    selectedDeadline.value = selectedDeadline.value === filter ? '' : filter;
}

function clearFilters() {
    search.value = '';
    selectedKind.value = '';
    selectedStep.value = '';
    selectedDeadline.value = '';
}

function remaining(task) {
    if (task.overdue) return { label: 'تجاوزت المهلة', tone: 'danger' };
    if (!task.dueAt) return null;
    const deadline = parseDate(task.dueAt);
    if (!deadline) return null;
    const hours = Math.round((deadline.getTime() - deadlineNow.value) / 36e5);
    const span = value => (Math.abs(value) >= 24 ? `${Math.round(Math.abs(value) / 24)} يوم` : `${Math.abs(value)} س`);
    if (hours < 0) return { label: `متأخر ${span(hours)}`, tone: 'danger' };
    return { label: `باقي ${span(hours)}`, tone: hours < 24 ? 'warning' : 'muted' };
}

// How long the task has waited for the user, shown when it has no deadline.
function age(task) {
    const since = parseDate(task.since);
    if (!since) return null;
    const hours = Math.floor((deadlineNow.value - since.getTime()) / 36e5);
    if (hours < 1) return 'منذ أقل من ساعة';
    return hours < 24 ? `منذ ${hours} س` : `منذ ${Math.floor(hours / 24)} يوم`;
}

function badge(task) {
    const deadline = remaining(task);
    if (deadline) return deadline;
    const waited = age(task);
    return waited ? { label: waited, tone: 'muted' } : null;
}

onMounted(() => {
    deadlineRefreshTimer = window.setInterval(() => {
        deadlineNow.value = Date.now();
    }, 60000);
});

onBeforeUnmount(() => {
    if (deadlineRefreshTimer !== null) window.clearInterval(deadlineRefreshTimer);
});
</script>

<template>
    <AppLayout title="مهامي">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="صندوق المهام" :title="view === 'done' ? 'أنجزتها' : waitingTitle"
                :description="view === 'done'
                    ? 'آخر الطلبات التي اتخذت فيها إجراءً ولم تعد لديك، الأحدث أولًا. تفتحها للاطلاع فقط.'
                    : leaveOnly
                        ? 'طلبات الإجازة التي تنتظر إجراءك الآن، الأقرب موعدًا والمتأخر أولًا. بقية مهامك في «كل مهامي».'
                        : 'كل ما ينتظر إجراءك الآن في مكان واحد: الطلبات والإجازات والمراسلات وأعمال القسم. ما له مهلة أولًا، ثم الأقدم انتظارًا. العدد هنا هو نفسه في القائمة الجانبية.'">
                <Link v-if="leaveOnly" href="/tasks" class="btn btn-secondary">كل مهامي</Link>
            </PageHeader>
            <div class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1" role="tablist" aria-label="أقسام مهامي">
                <button type="button" role="tab" class="rounded-lg px-3 py-2 text-sm font-semibold transition" :class="view === 'waiting' ? 'bg-navy-700 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    :aria-selected="view === 'waiting'" @click="view = 'waiting'">{{ waitingTitle }}</button>
                <button type="button" role="tab" class="rounded-lg px-3 py-2 text-sm font-semibold transition" :class="view === 'done' ? 'bg-navy-700 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    :aria-selected="view === 'done'" @click="showDone">أنجزتها</button>
            </div>
            <section v-if="view === 'done'" class="card space-y-4">
                <AsyncState v-if="doneLoading || doneError" :loading="doneLoading" :error="doneLoading ? '' : doneError" @retry="showDone" />
                <EmptyState v-else-if="!(done || []).length" title="لا توجد طلبات أنجزت فيها مرحلة" description="يظهر هنا كل طلب اتخذت فيه إجراءً ثم انتقل إلى غيرك أو أُغلق." />
                <template v-else>
                    <ul class="space-y-3">
                        <li v-for="item in done" :key="item.key" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 p-4 transition hover:border-navy-200">
                            <div class="min-w-0">
                                <p class="font-semibold text-navy-800">{{ item.title }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ item.kind }} · <span dir="ltr">{{ item.reference || '—' }}</span>
                                    · إجراؤك: <strong class="text-navy-700">{{ item.action }}</strong><template v-if="item.step"> في مرحلة «{{ item.step }}»</template>
                                    · {{ formatDateTime(item.actedAt, '—') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge :label="item.status" :tone="item.tone" />
                                <Link :href="item.url" class="btn btn-secondary btn-sm">عرض</Link>
                            </div>
                        </li>
                    </ul>
                    <p class="text-xs text-slate-500">تُعرض آخر 50 طلبًا ما زال يحق لك فتحها. طلباتك أنت تجدها في «طلباتي».</p>
                </template>
            </section>
            <section v-else class="card">
                <EmptyState v-if="!tasks.length" title="لا توجد مهام بانتظارك" description="ستظهر هنا الطلبات التي تصل إلى مرحلة أنت مسؤول عنها." />
                <div v-else class="space-y-5">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="ملخص أولوية المهام">
                        <button type="button" class="rounded-xl border p-4 text-start transition hover:border-navy-300 hover:shadow-sm" :class="!selectedDeadline ? 'border-navy-300 bg-navy-50/60 ring-1 ring-navy-200' : 'border-slate-200 bg-white'" :aria-pressed="!selectedDeadline" @click="applyDeadlineFilter('')">
                            <span class="text-sm font-semibold text-slate-600">كل المهام</span>
                            <span class="mt-2 block text-2xl font-bold tabular-nums text-navy-800">{{ deadlineSummary.total }}</span>
                        </button>
                        <button type="button" class="rounded-xl border p-4 text-start transition hover:border-red-300 hover:shadow-sm" :class="selectedDeadline === 'overdue' ? 'border-red-300 bg-red-50 ring-1 ring-red-200' : 'border-red-100 bg-red-50/40'" :aria-pressed="selectedDeadline === 'overdue'" @click="applyDeadlineFilter('overdue')">
                            <span class="text-sm font-semibold text-red-800">متأخرة</span>
                            <span class="mt-2 block text-2xl font-bold tabular-nums text-red-700">{{ deadlineSummary.overdue }}</span>
                        </button>
                        <button type="button" class="rounded-xl border p-4 text-start transition hover:border-amber-300 hover:shadow-sm" :class="selectedDeadline === 'due_soon' ? 'border-amber-300 bg-amber-50 ring-1 ring-amber-200' : 'border-amber-100 bg-amber-50/40'" :aria-pressed="selectedDeadline === 'due_soon'" @click="applyDeadlineFilter('due_soon')">
                            <span class="text-sm font-semibold text-amber-900">تستحق خلال 24 ساعة</span>
                            <span class="mt-2 block text-2xl font-bold tabular-nums text-amber-800">{{ deadlineSummary.dueSoon }}</span>
                        </button>
                        <button type="button" class="rounded-xl border p-4 text-start transition hover:border-slate-300 hover:shadow-sm" :class="selectedDeadline === 'no_deadline' ? 'border-slate-300 bg-slate-100 ring-1 ring-slate-200' : 'border-slate-200 bg-slate-50'" :aria-pressed="selectedDeadline === 'no_deadline'" @click="applyDeadlineFilter('no_deadline')">
                            <span class="text-sm font-semibold text-slate-600">دون موعد محدد</span>
                            <span class="mt-2 block text-2xl font-bold tabular-nums text-slate-700">{{ deadlineSummary.noDeadline }}</span>
                        </button>
                    </div>
                    <form class="grid gap-3 rounded-xl bg-slate-50 p-4 lg:grid-cols-4" role="search" @submit.prevent>
                        <label class="block text-sm font-semibold lg:col-span-2">
                            بحث في العنوان أو الرقم أو مقدم الطلب
                            <input v-model="search" type="search" class="form-input mt-1 w-full" placeholder="اكتب كلمة أو رقمًا..." />
                        </label>
                        <label class="block text-sm font-semibold">
                            نوع الطلب
                            <select v-model="selectedKind" class="form-input mt-1 w-full">
                                <option value="">كل الأنواع</option>
                                <option v-for="kind in kinds" :key="kind" :value="kind">{{ kind }}</option>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold">
                            المرحلة
                            <select v-model="selectedStep" class="form-input mt-1 w-full">
                                <option value="">كل المراحل</option>
                                <option v-for="step in steps" :key="step" :value="step">{{ step }}</option>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold lg:col-span-2">
                            حالة المهلة
                            <select v-model="selectedDeadline" class="form-input mt-1 w-full">
                                <option value="">كل المهل</option>
                                <option value="overdue">متأخرة</option>
                                <option value="due_soon">تستحق خلال 24 ساعة</option>
                                <option value="on_track">أكثر من 24 ساعة</option>
                                <option value="no_deadline">دون موعد محدد</option>
                            </select>
                        </label>
                        <div class="flex items-end justify-between gap-3 lg:col-span-2">
                            <p class="pb-2 text-sm text-slate-600" aria-live="polite">عرض {{ visibleTasks.length }} من {{ tasks.length }} مهمة</p>
                            <button v-if="filtersActive" type="button" class="btn btn-secondary btn-sm" @click="clearFilters">مسح الفلاتر</button>
                        </div>
                    </form>
                    <EmptyState
                        v-if="!visibleTasks.length"
                        title="لا توجد نتائج مطابقة"
                        description="جرّب تغيير كلمات البحث أو خيارات التصفية."
                    />
                    <ul v-else class="space-y-3">
                        <li v-for="task in visibleTasks" :key="task.key" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 transition hover:border-navy-200" :class="deadlineCategory(task) === 'overdue' ? 'border-red-200 bg-red-50/40' : 'border-slate-100'">
                            <div class="min-w-0">
                                <p class="font-semibold text-navy-800">{{ task.title }}</p>
                                <!-- A workflow task's detail repeats its type and step; every other source says something of its own. -->
                                <p v-if="task.detail && !task.key.startsWith('wf-')" class="mt-1 text-sm text-slate-700">{{ task.detail }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ task.kind }} · المرحلة: <strong class="text-navy-700">{{ task.step || '—' }}</strong>
                                    · <span dir="ltr">{{ task.reference || '—' }}</span>
                                    <template v-if="task.ownLabel"> · <span class="font-semibold text-gold-700">{{ task.ownLabel }}</span></template>
                                    <template v-else-if="task.requester"> · {{ task.personLabel || 'مقدم الطلب' }}: {{ task.requester }}</template>
                                    <template v-if="task.assignedBy"> · <span class="font-semibold text-navy-700">أسندها إليك: {{ task.assignedBy }}</span></template>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge v-if="badge(task)" :label="badge(task).label" :tone="badge(task).tone" />
                                <Link :href="task.url" class="btn btn-primary btn-sm">اتخاذ إجراء</Link>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
