<script setup>
import { formatDateTime } from '../Support/dates.js';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * The one progress indicator for every AIMS transaction. Steps are built on the server from the
 * real workflow (or saved evidence); this component only draws them.
 *
 * steps: [{ label, state, owner?, startedAt?, completedAt?, dueAt?, overdue?, attempt?, note?, meta? }]
 *   state: done | current | upcoming | returned | rejected | cancelled | skipped | pending
 * summary (optional): { status, tone, where, next, dueAt, overdue, partial, reason }
 * compact: one-line chips for lists. vertical: force the vertical timeline.
 */
const props = defineProps({
    steps: { type: Array, default: () => [] },
    summary: { type: Object, default: null },
    compact: { type: Boolean, default: false },
    vertical: { type: Boolean, default: false },
});

const STATES = {
    done: { text: 'مكتملة', circle: 'bg-navy-700 text-white ring-navy-700', label: 'text-navy-800', line: 'bg-navy-700', icon: '✓' },
    current: { text: 'المرحلة الحالية', circle: 'bg-gold-400 text-navy-900 ring-gold-200 ring-4', label: 'font-bold text-navy-900', line: 'bg-gradient-to-l from-navy-700 to-slate-200', icon: null },
    upcoming: { text: 'لم تبدأ', circle: 'bg-white text-slate-400 ring-slate-300', label: 'text-slate-400', line: 'bg-slate-200', icon: null },
    returned: { text: 'أُعيدت', circle: 'bg-gold-100 text-gold-800 ring-gold-400', label: 'font-semibold text-gold-800', line: 'bg-gold-300', icon: '↺' },
    rejected: { text: 'مرفوضة', circle: 'bg-red-600 text-white ring-red-600', label: 'font-bold text-red-700', line: 'bg-red-200', icon: '✕' },
    cancelled: { text: 'ملغاة', circle: 'bg-slate-400 text-white ring-slate-400', label: 'text-slate-500 line-through', line: 'bg-slate-200', icon: '⊘' },
    skipped: { text: 'متجاوزة', circle: 'bg-white text-slate-400 ring-slate-300', label: 'text-slate-400', line: 'bg-slate-200', icon: '–' },
    pending: { text: 'معلّقة', circle: 'bg-blue-50 text-blue-700 ring-blue-300', label: 'font-semibold text-blue-800', line: 'bg-blue-200', icon: '⏸' },
};
const look = state => STATES[state] || STATES.upcoming;
const tones = { success: 'border-emerald-200 bg-emerald-50', info: 'border-blue-100 bg-blue-50', warning: 'border-gold-200 bg-gold-50', danger: 'border-red-200 bg-red-50', muted: 'border-slate-200 bg-slate-50' };

const narrow = ref(false);
let query = null;
const onQuery = event => { narrow.value = event.matches; };
onMounted(() => {
    query = window.matchMedia('(max-width: 767px)');
    narrow.value = query.matches;
    query.addEventListener?.('change', onQuery);
});
onUnmounted(() => query?.removeEventListener?.('change', onQuery));

const useVertical = computed(() => props.vertical || narrow.value || props.steps.length > 7);
const open = ref(null);
const toggle = index => { open.value = open.value === index ? null : index; };
const hasDetails = step => Boolean(step.owner || step.startedAt || step.completedAt || step.dueAt || step.note || step.meta || (step.attempt && step.attempt > 1));
const currentIndex = computed(() => props.steps.findIndex(step => step.state === 'current'));

const format = value => {
    return formatDateTime(value);
};
</script>

<template>
    <!-- Compact: one line of chips for lists and cards. -->
    <ol v-if="compact" class="flex flex-wrap items-center gap-y-1.5 text-xs" aria-label="مراحل الطلب">
        <li v-for="(step, index) in steps" :key="index" class="flex items-center">
            <span v-if="index" class="mx-1 h-px w-4 sm:w-6" :class="look(steps[index - 1].state).line" aria-hidden="true"></span>
            <span class="flex items-center gap-1.5 rounded-full py-0.5 pe-2 ps-0.5" :class="step.state === 'current' ? 'bg-gold-50' : ''" :aria-current="step.state === 'current' ? 'step' : undefined">
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ring-1" :class="look(step.state).circle" aria-hidden="true">{{ look(step.state).icon || index + 1 }}</span>
                <span :class="look(step.state).label">{{ step.label }}</span>
                <span class="sr-only">({{ look(step.state).text }})</span>
            </span>
        </li>
    </ol>

    <div v-else class="space-y-4">
        <div v-if="summary" class="grid gap-2 rounded-xl border p-3 text-sm sm:grid-cols-3" :class="tones[summary.tone] || tones.muted" role="status">
            <div><p class="text-xs text-slate-500">أين طلبي الآن؟</p><p class="mt-0.5 font-semibold text-navy-800">{{ summary.where || summary.status }}</p></div>
            <div><p class="text-xs text-slate-500">الإجراء التالي</p><p class="mt-0.5 font-semibold text-navy-800">{{ summary.next || '—' }}</p></div>
            <div>
                <p class="text-xs text-slate-500">المهلة</p>
                <p class="mt-0.5 font-semibold" :class="summary.overdue ? 'text-red-700' : 'text-navy-800'">
                    <template v-if="summary.overdue">تجاوزت المهلة</template>
                    <template v-else-if="summary.dueAt">حتى {{ format(summary.dueAt) }}</template>
                    <template v-else>بلا مهلة محددة</template>
                </p>
            </div>
            <p v-if="summary.reason" class="rounded-lg bg-white/70 p-2 text-xs leading-5 text-slate-700 sm:col-span-3"><span class="font-semibold">السبب:</span> {{ summary.reason }}</p>
            <p v-if="summary.partial" class="text-xs text-gold-800 sm:col-span-3">سجل هذه المعاملة جزئي: تُعرض المراحل الموثقة فقط ولا تُفترض مراحل مكتملة.</p>
        </div>

        <!-- Horizontal stepper (desktop, short paths). -->
        <ol v-if="!useVertical" class="flex overflow-x-auto pb-1" aria-label="مراحل الطلب">
            <li v-for="(step, index) in steps" :key="index" class="relative flex min-w-[5.5rem] flex-1 flex-col items-center px-1 text-center sm:min-w-[7.5rem]">
                <span v-if="index" class="absolute top-[0.9375rem] h-0.5 w-full" style="inset-inline-start: -50%;" :class="look(steps[index - 1].state).line" aria-hidden="true"></span>
                <button type="button" class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold ring-2 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-300" :class="[look(step.state).circle, hasDetails(step) ? 'cursor-pointer' : 'cursor-default']"
                    :aria-current="step.state === 'current' ? 'step' : undefined" :aria-expanded="hasDetails(step) ? open === index : undefined"
                    :aria-label="`${step.label}: ${look(step.state).text}`" :title="`${step.label} — ${look(step.state).text}`" @click="hasDetails(step) && toggle(index)">
                    {{ look(step.state).icon || index + 1 }}
                </button>
                <span class="mt-2 text-xs leading-5" :class="look(step.state).label">{{ step.label }}<span v-if="step.attempt > 1" class="text-[10px] text-slate-500"> (المحاولة {{ step.attempt }})</span></span>
                <span v-if="step.owner || step.meta" class="mt-0.5 text-[11px] leading-4 text-slate-500">{{ step.owner || step.meta }}</span>
                <span v-if="step.overdue" class="mt-0.5 text-[11px] font-semibold text-red-700">متأخرة</span>
            </li>
        </ol>
        <div v-if="!useVertical && open !== null && steps[open]" class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-sm">
            <p class="font-semibold text-navy-800">{{ steps[open].label }} — {{ look(steps[open].state).text }}</p>
            <dl class="mt-2 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">
                <div v-if="steps[open].owner"><dt class="inline text-slate-400">المسؤول: </dt><dd class="inline">{{ steps[open].owner }}</dd></div>
                <div v-if="steps[open].startedAt"><dt class="inline text-slate-400">البداية: </dt><dd class="inline">{{ format(steps[open].startedAt) }}</dd></div>
                <div v-if="steps[open].completedAt"><dt class="inline text-slate-400">النهاية: </dt><dd class="inline">{{ format(steps[open].completedAt) }}</dd></div>
                <div v-if="steps[open].dueAt"><dt class="inline text-slate-400">المهلة: </dt><dd class="inline" :class="steps[open].overdue ? 'text-red-700' : ''">{{ format(steps[open].dueAt) }}</dd></div>
            </dl>
            <p v-if="steps[open].note" class="mt-2 rounded-lg bg-white p-2 text-xs leading-5 text-slate-700">السبب: {{ steps[open].note }}</p>
        </div>

        <!-- Vertical timeline (phones, long or branching paths). -->
        <ol v-if="useVertical" class="relative space-y-1" aria-label="مراحل الطلب">
            <li v-for="(step, index) in steps" :key="index" class="relative flex gap-3 pb-3" :aria-current="step.state === 'current' ? 'step' : undefined">
                <span v-if="index < steps.length - 1" class="absolute top-8 bottom-0 w-0.5" style="inset-inline-start: 0.9375rem;" :class="look(step.state).line" aria-hidden="true"></span>
                <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold ring-2" :class="look(step.state).circle" aria-hidden="true">{{ look(step.state).icon || index + 1 }}</span>
                <div class="min-w-0 flex-1 pt-1">
                    <button type="button" class="flex w-full flex-wrap items-center gap-2 text-right" :class="hasDetails(step) ? 'cursor-pointer' : 'cursor-default'" :aria-expanded="hasDetails(step) ? open === index || step.state === 'current' : undefined" @click="hasDetails(step) && toggle(index)">
                        <span class="text-sm" :class="look(step.state).label">{{ step.label }}</span>
                        <span class="rounded-full bg-slate-100 px-2 text-[10px] text-slate-600">{{ look(step.state).text }}</span>
                        <span v-if="step.attempt > 1" class="text-[10px] text-slate-500">المحاولة {{ step.attempt }}</span>
                        <span v-if="step.overdue" class="text-[10px] font-semibold text-red-700">متأخرة</span>
                    </button>
                    <div v-if="open === index || step.state === 'current' || (index === currentIndex)" class="mt-1 space-y-0.5 text-xs text-slate-500">
                        <p v-if="step.owner || step.meta">{{ step.owner || step.meta }}</p>
                        <p v-if="step.startedAt || step.completedAt"><span v-if="step.startedAt">من {{ format(step.startedAt) }}</span><span v-if="step.completedAt"> إلى {{ format(step.completedAt) }}</span></p>
                        <p v-if="step.dueAt" :class="step.overdue ? 'font-semibold text-red-700' : ''">المهلة: {{ format(step.dueAt) }}</p>
                        <p v-if="step.note" class="rounded-lg bg-slate-50 p-2 text-slate-700">السبب: {{ step.note }}</p>
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>
