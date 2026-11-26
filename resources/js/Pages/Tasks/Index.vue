<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { parseDate } from '../../Support/dates.js';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
});

const search = ref('');
const selectedKind = ref('');
const selectedStep = ref('');
const selectedDeadline = ref('');
const deadlineNow = ref(Date.now());
let deadlineRefreshTimer = null;

const kinds = computed(() => [...new Set(props.tasks.map(task => task.kind).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ar')));
const steps = computed(() => [...new Set(props.tasks.map(task => task.step).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ar')));
const filtersActive = computed(() => Boolean(search.value.trim() || selectedKind.value || selectedStep.value || selectedDeadline.value));

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
        const searchableText = [task.title, task.reference, task.requester, task.kind, task.step, task.detail]
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
            <PageHeader eyebrow="صندوق المهام" :title="`بانتظار إجرائي (${tasks.length})`" description="كل معاملة تنتظر إجراءك الآن، ومنها طلبات الإجازة، الأقرب موعدًا والمتأخر أولًا. لا تظهر هنا للاعتماد طلبات قدّمتها بنفسك." />
            <section class="card">
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
                        <li v-for="task in visibleTasks" :key="task.key" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 transition hover:border-navy-200" :class="task.overdue ? 'border-red-200 bg-red-50/40' : 'border-slate-100'">
                            <div class="min-w-0">
                                <p class="font-semibold text-navy-800">{{ task.title }}</p>
                                <p v-if="task.kind === 'طلب إجازة' && task.detail" class="mt-1 text-sm text-slate-700">{{ task.detail }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ task.kind }} · المرحلة: <strong class="text-navy-700">{{ task.step || '—' }}</strong>
                                    · <span dir="ltr">{{ task.reference || '—' }}</span>
                                    <template v-if="!task.ownRequest"> · مقدم الطلب: {{ task.requester }}</template>
                                    <template v-else> · <span class="font-semibold text-gold-700">طلبك مُعاد إليك</span></template>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge v-if="remaining(task)" :label="remaining(task).label" :tone="remaining(task).tone" />
                                <Link :href="task.url" class="btn btn-primary btn-sm">فتح واتخاذ إجراء</Link>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
