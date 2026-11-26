<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatNumber } from './format.js';

// One indicator. `better` says which direction is favourable ('up' / 'down' / null = neutral), so a rise in overdue work
// reads as unfavourable. Direction is written in words and an arrow, never colour alone. Without a comparison the
// card says so instead of drawing an arrow.
const props = defineProps({ metric: { type: Object, required: true }, hero: Boolean });
const m = computed(() => props.metric);
const change = computed(() => {
    const c = m.value.comparison;
    if (!c || c.current === undefined || c.current === null) return null;
    const delta = c.current - c.previous;
    const direction = delta > 0 ? 'up' : delta < 0 ? 'down' : 'same';
    const verdict = direction === 'same' || !m.value.better ? 'neutral' : direction === m.value.better ? 'good' : 'bad';
    return { ...c, delta, direction, verdict };
});
const tone = { good: 'text-emerald-700', bad: 'text-red-700', neutral: 'text-slate-600' };
const arrow = { up: '▲', down: '▼', same: '■' };
const words = { up: 'أعلى', down: 'أقل', same: 'دون تغيير' };
const verdictWord = { good: 'تحسن', bad: 'يحتاج انتباهًا', neutral: '' };
</script>

<template>
    <component :is="m.url ? Link : 'div'" :href="m.url || undefined"
        :class="['card flex h-full flex-col gap-1.5', hero ? 'border-s-4 border-s-blue-600 sm:col-span-2' : '', m.url ? 'transition-colors hover:border-blue-300 focus-visible:outline-2 focus-visible:outline-blue-600' : '']">
        <p :class="['text-sm font-semibold', hero ? 'text-navy-800' : 'text-slate-600']">{{ m.label }}</p>
        <template v-if="m.unavailable">
            <p class="text-2xl font-bold text-slate-400" aria-hidden="true">—</p>
            <p class="text-xs leading-5 text-slate-500"><span class="font-semibold">غير متاح:</span> {{ m.unavailable }}</p>
        </template>
        <template v-else>
            <p :class="['font-bold tabular-nums text-navy-900', hero ? 'text-4xl' : 'text-3xl']">{{ formatNumber(m.value) }}<span v-if="m.unit" class="ms-1 text-base font-semibold text-slate-500">{{ m.unit }}</span></p>
            <p v-if="change" :class="['text-xs leading-5', tone[change.verdict]]">
                <span aria-hidden="true">{{ arrow[change.direction] }}</span>
                {{ change.label }}: <bdi class="font-semibold tabular-nums">{{ formatNumber(change.current) }}</bdi> مقابل <bdi class="tabular-nums">{{ formatNumber(change.previous) }}</bdi> في الأسبوع السابق
                <span v-if="change.direction !== 'same'">({{ words[change.direction] }}<span v-if="verdictWord[change.verdict]"> — {{ verdictWord[change.verdict] }}</span>)</span>
            </p>
            <p v-else-if="hero" class="text-xs text-slate-500">لا تتوفر بيانات مقارنة.</p>
            <p v-if="m.detail" class="text-xs leading-5 text-slate-600">{{ m.detail }}</p>
        </template>
    </component>
</template>
