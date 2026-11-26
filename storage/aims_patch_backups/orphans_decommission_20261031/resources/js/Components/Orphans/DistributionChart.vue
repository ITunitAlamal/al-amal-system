<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ rows: { type: Array, default: () => [] }, unit: { type: String, default: 'سجل' }, color: { type: String, default: 'blue' } });
const total = computed(() => props.rows.reduce((sum, row) => sum + row.count, 0));
const largest = computed(() => Math.max(1, ...props.rows.map(row => row.count)));
const colors = { blue: 'bg-blue-600', teal: 'bg-teal-600', amber: 'bg-amber-500' };
const number = value => new Intl.NumberFormat('ar').format(value);
const percent = value => total.value ? new Intl.NumberFormat('ar', { style: 'percent', maximumFractionDigits: 1 }).format(value / total.value) : '—';
</script>

<template>
    <div>
        <p v-if="!total" class="rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">لا توجد بيانات لعرض المخطط.</p>
        <ul v-else class="space-y-4" :aria-label="`التوزيع من إجمالي ${number(total)} ${unit}`">
            <li v-for="row in rows" :key="row.key ?? row.label" class="space-y-1.5">
                <div class="flex items-start justify-between gap-3 text-sm">
                    <Link v-if="row.href" :href="row.href" class="min-w-0 break-words font-medium text-blue-800 underline decoration-blue-200 underline-offset-4 hover:decoration-blue-600">{{ row.label }}</Link>
                    <span v-else class="min-w-0 break-words font-medium text-slate-700">{{ row.label }}</span>
                    <span class="shrink-0 tabular-nums"><strong class="text-slate-900">{{ number(row.count) }}</strong> <span class="text-xs text-slate-500">{{ unit }} · {{ percent(row.count) }}</span></span>
                </div>
                <div aria-hidden="true" class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full" :class="row.label === 'غير محدد' ? 'bg-slate-400' : colors[color] || colors.blue" :style="{ width: `${row.count / largest * 100}%` }" />
                </div>
            </li>
        </ul>
    </div>
</template>
