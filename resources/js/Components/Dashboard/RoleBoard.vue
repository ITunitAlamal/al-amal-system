<script setup>
import { Link } from '@inertiajs/vue3';
import AppIcon from '../AppIcon.vue';
import ImportIcon from '../Registry/ImportIcon.vue';
import MetricCard from './MetricCard.vue';
import TrendChart from './TrendChart.vue';
import CategoryBarChart from './CategoryBarChart.vue';
import ActionTable from './ActionTable.vue';
import { formatDateTime } from '../../Support/dates.js';

// One role dashboard: the role's decision, the hero indicator first (top right in RTL) with 2–3 supporting ones,
// a two-thirds trend beside a one-third breakdown, and the actionable table below. Every part comes from the server.
defineProps({ board: { type: Object, required: true } });
</script>

<template>
    <section class="space-y-4" :aria-labelledby="`board-${board.key}`">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 :id="`board-${board.key}`" class="text-lg font-bold text-navy-800">{{ board.title }}</h2>
                <p class="mt-0.5 text-sm text-slate-600">{{ board.decision }}</p>
                <p class="mt-1 text-xs text-slate-500">النطاق: {{ board.scope }}<span v-if="board.updated_at"> · حُدّث <bdi>{{ formatDateTime(board.updated_at) }}</bdi></span></p>
            </div>
            <nav v-if="board.actions?.length" class="flex flex-wrap gap-2" aria-label="إجراءات سريعة">
                <Link v-for="a in board.actions" :key="a.url" :href="a.url" class="btn btn-sm btn-secondary">
                    <ImportIcon v-if="a.icon === 'import'" /><AppIcon v-else :name="a.icon || 'folder'" :size="16" /> {{ a.label }}</Link>
            </nav>
        </div>
        <p v-for="(n, i) in board.notes || []" :key="i" class="alert-info text-sm">{{ n }}</p>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="المؤشرات">
            <MetricCard :metric="board.hero" hero />
            <MetricCard v-for="(k, i) in board.kpis" :key="i" :metric="k" />
        </div>

        <div class="grid gap-3 lg:grid-cols-3">
            <div class="lg:col-span-2"><TrendChart :chart="board.trend" /></div>
            <CategoryBarChart :chart="board.bars" />
        </div>

        <ActionTable :table="board.table" />

        <details v-if="board.definitions && Object.keys(board.definitions).length" class="rounded-xl border border-slate-200 bg-white px-4 text-sm">
            <summary class="cursor-pointer font-semibold text-navy-800">تعريف المؤشرات ومصدر الأرقام</summary>
            <dl class="space-y-3 pb-4"><div v-for="(text, label) in board.definitions" :key="label"><dt class="font-semibold">{{ label }}</dt><dd class="mt-1 leading-6 text-slate-600">{{ text }}</dd></div></dl>
        </details>
    </section>
</template>
