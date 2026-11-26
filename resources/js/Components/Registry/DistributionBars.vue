<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

// Single-series horizontal bars. Each row is a link to the registry with the same filter (same count), and the
// numeric value is always written next to the bar, so the chart doubles as its own table.
const props = defineProps({ title: String, rows: Array, definition: String, base: { type: String, default: '/orphans/registry' }, filters: { type: Object, default: () => ({}) } });
const count = row => Number.isFinite(Number(row.count)) ? Number(row.count) : 0;
const max = computed(() => Math.max(1, ...props.rows.map(count)));
const total = computed(() => props.rows.reduce((sum, row) => sum + count(row), 0));
const href = row => Object.keys(row.filter || {}).length ? `${props.base}?${new URLSearchParams({ ...props.filters, ...row.filter })}` : null;
const hasLinks = computed(() => props.rows.some(row => href(row)));
const muted = row => ['unknown', 'outside'].includes(row.key);
</script>

<template>
    <figure class="card space-y-3">
        <figcaption class="flex items-start justify-between gap-2">
            <span class="card-title">{{ title }}</span>
        </figcaption>
        <p v-if="definition" class="text-xs leading-6 text-slate-600">{{ definition }}</p>
        <p v-if="!total" class="text-sm text-slate-500">لا توجد بيانات لهذا التوزيع ضمن النطاق الحالي.</p>
        <ul v-else class="space-y-1.5">
            <li v-for="row in rows" :key="row.key">
                <component :is="href(row) ? Link : 'div'" :href="href(row) || undefined" class="group grid grid-cols-[minmax(6rem,9rem)_1fr_auto] items-center gap-3 rounded-lg px-1 py-1.5 text-sm hover:bg-slate-50"
                    :aria-label="`${row.label}: ${row.count} من ${total}`" :title="`${row.label}: ${row.count} (${Math.round(row.count / total * 100)}%)`">
                    <span :class="['truncate', muted(row) ? 'text-slate-500' : 'text-navy-900']">{{ row.label }}</span>
                    <span class="h-2.5 rounded-full bg-slate-100" aria-hidden="true">
                        <span :class="['block h-full rounded-full transition-[width]', muted(row) ? 'bg-slate-400' : 'bg-blue-600 group-hover:bg-navy-700']" :style="{ width: `${row.count / max * 100}%`, minWidth: row.count ? '4px' : '0' }" />
                    </span>
                    <span class="tabular-nums text-navy-900">{{ row.count }}</span>
                </component>
            </li>
        </ul>
        <p v-if="hasLinks && total" class="text-xs text-slate-500">اختر صفًا لفتح السجل ضمن الفلاتر الموضحة.</p>
    </figure>
</template>
