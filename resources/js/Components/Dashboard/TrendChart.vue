<script setup>
import { computed } from 'vue';
import { formatNumber, formatShortDate } from './format.js';

// Line chart of weekly windows (oldest on the right, newest on the left: the RTL reading direction). Series differ by
// colour and dash pattern; each week has a native tooltip with every series; the same numbers are in a table below.
// No data means a neutral explanation, never an empty or invented line.
const props = defineProps({ chart: { type: Object, required: true } });
const W = 640, H = 220, PAD = { top: 12, bottom: 30, start: 14, end: 44 };
const styles = [
    { stroke: 'var(--color-blue-600)', dash: '', dot: 'var(--color-blue-600)' },
    { stroke: 'var(--color-gold-600)', dash: '7 5', dot: 'var(--color-gold-600)' },
    { stroke: 'var(--color-navy-400)', dash: '2 4', dot: 'var(--color-navy-400)' },
];
const weeks = computed(() => props.chart.weeks || []);
const max = computed(() => {
    const top = Math.max(1, ...props.chart.series.flatMap(s => s.values));
    const step = 10 ** Math.floor(Math.log10(top));
    return Math.ceil(top / step) * step;
});
const step = computed(() => (W - PAD.start - PAD.end) / Math.max(1, weeks.value.length - 1));
// RTL: index 0 (oldest) sits at the right edge, before the y-axis labels.
const x = i => W - PAD.end - i * step.value;
const y = v => PAD.top + (H - PAD.top - PAD.bottom) * (1 - v / max.value);
const paths = computed(() => props.chart.series.map(s => s.values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ')));
const ticks = computed(() => [0, max.value / 2, max.value]);
const tooltip = i => `${formatShortDate(weeks.value[i])}: ${props.chart.series.map(s => `${s.label} ${formatNumber(s.values[i])}`).join('، ')}`;
const summary = computed(() => {
    const last = weeks.value.length - 1;
    return `${props.chart.title}. آخر أسبوع (حتى ${formatShortDate(weeks.value[last])}): ${props.chart.series.map(s => `${s.label} ${formatNumber(s.values[last])}`).join('، ')}. التفاصيل في الجدول.`;
});
const labelEvery = computed(() => (weeks.value.length > 8 ? 3 : 1));
</script>

<template>
    <figure class="card flex h-full flex-col gap-3">
        <figcaption class="space-y-1">
            <span class="card-title block">{{ chart.title }}</span>
            <span v-if="chart.definition" class="block text-xs leading-5 text-slate-600">{{ chart.definition }}</span>
        </figcaption>
        <div v-if="chart.unavailable" class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-600">
            {{ chart.unavailable }}
        </div>
        <template v-else>
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-700" aria-hidden="true">
                <li v-for="(s, k) in chart.series" :key="k" class="flex items-center gap-1.5">
                    <svg width="26" height="8"><line x1="1" y1="4" x2="25" y2="4" :stroke="styles[k % 3].stroke" stroke-width="2.5" :stroke-dasharray="styles[k % 3].dash" /></svg>{{ s.label }}
                </li>
            </ul>
            <svg :viewBox="`0 0 ${W} ${H}`" class="h-auto w-full" role="img" :aria-label="summary">
                <g v-for="t in ticks" :key="t">
                    <line :x1="PAD.start" :x2="W - PAD.end" :y1="y(t)" :y2="y(t)" stroke="var(--color-slate-200)" stroke-width="1" />
                    <text :x="W - PAD.end + 8" :y="y(t) + 4" font-size="11" fill="var(--color-slate-500)" text-anchor="start">{{ formatNumber(t) }}</text>
                </g>
                <text v-for="(w, i) in weeks" v-show="i % labelEvery === (weeks.length - 1) % labelEvery" :key="w" :x="x(i)" :y="H - 8" font-size="11" fill="var(--color-slate-500)" text-anchor="middle">{{ formatShortDate(w) }}</text>
                <path v-for="(d, k) in paths" :key="k" :d="d" fill="none" :stroke="styles[k % 3].stroke" stroke-width="2.5" :stroke-dasharray="styles[k % 3].dash" stroke-linejoin="round" stroke-linecap="round" />
                <g v-for="(s, k) in chart.series" :key="`p${k}`">
                    <circle v-for="(v, i) in s.values" :key="i" :cx="x(i)" :cy="y(v)" r="3" :fill="styles[k % 3].dot" />
                </g>
                <rect v-for="(w, i) in weeks" :key="`h${i}`" :x="x(i) - step / 2" :y="PAD.top" :width="step" :height="H - PAD.top - PAD.bottom" fill="transparent"><title>{{ tooltip(i) }}</title></rect>
            </svg>
            <details class="text-sm">
                <summary class="cursor-pointer text-xs font-semibold text-blue-700">عرض الأرقام في جدول</summary>
                <div class="aims-table-scroll mt-2">
                    <table class="data-table">
                        <thead><tr><th scope="col">الأسبوع المنتهي في</th><th v-for="(s, k) in chart.series" :key="k" scope="col">{{ s.label }}</th></tr></thead>
                        <tbody><tr v-for="(w, i) in weeks" :key="w"><th scope="row" class="font-normal">{{ formatShortDate(w) }}</th><td v-for="(s, k) in chart.series" :key="k" class="tabular-nums">{{ formatNumber(s.values[i]) }}{{ chart.unit ? ' ' + chart.unit : '' }}</td></tr></tbody>
                    </table>
                </div>
            </details>
        </template>
    </figure>
</template>
