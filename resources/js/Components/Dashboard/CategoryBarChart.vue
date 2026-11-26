<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatNumber } from './format.js';

// Horizontal bars, one colour (categories are not ranked by colour). The value is written beside each bar, so the
// chart is also its own table; a row with a link opens the matching filtered records.
const props = defineProps({ chart: { type: Object, required: true } });
const max = computed(() => Math.max(1, ...props.chart.items.map(i => Number(i.value) || 0)));
const total = computed(() => props.chart.items.reduce((sum, i) => sum + (Number(i.value) || 0), 0));
</script>

<template>
    <figure class="card flex h-full flex-col gap-3">
        <figcaption class="space-y-1">
            <span class="card-title block">{{ chart.title }}</span>
            <span v-if="chart.definition" class="block text-xs leading-5 text-slate-600">{{ chart.definition }}</span>
        </figcaption>
        <p v-if="chart.unavailable" class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-600">{{ chart.unavailable }}</p>
        <ul v-else class="space-y-1" :aria-label="`${chart.title}: المجموع ${formatNumber(total)}`">
            <li v-for="item in chart.items" :key="item.label">
                <component :is="item.url ? Link : 'div'" :href="item.url || undefined"
                    :class="['grid grid-cols-[minmax(5rem,9rem)_1fr_auto] items-center gap-3 rounded-lg px-1 py-1.5 text-sm', item.url ? 'group hover:bg-slate-50' : '']"
                    :title="`${item.label}: ${formatNumber(item.value)} (${Math.round(item.value / total * 100)}%)`">
                    <span class="truncate text-navy-900">{{ item.label }}</span>
                    <span class="h-2.5 rounded-full bg-slate-100" aria-hidden="true">
                        <span class="block h-full rounded-full bg-blue-600 group-hover:bg-navy-700" :style="{ width: `${item.value / max * 100}%`, minWidth: item.value ? '4px' : '0' }"></span>
                    </span>
                    <span class="tabular-nums text-navy-900">{{ formatNumber(item.value) }}</span>
                </component>
            </li>
        </ul>
    </figure>
</template>
