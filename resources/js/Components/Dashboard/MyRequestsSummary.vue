<script setup>
import { Link } from '@inertiajs/vue3';
import AppIcon from '../AppIcon.vue';
import StatusBadge from '../StatusBadge.vue';

/**
 * «طلباتي» on «مساحتي» as one foldable block: the user's latest requests, where each stands, and the way to
 * all of them. A request that waits for its author (returned, or to verify) offers the action instead of a view.
 */
defineProps({
    requests: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    open: { type: Boolean, default: false },
});

// Same rule as «طلباتي» (Requests/Index): TransactionTracker's "awaitsMe" first, the status for older rows.
const waitsForMe = request => request.awaitsMe ?? ['returned', 'verify'].includes(request.statusKey);
</script>

<template>
    <details class="card" :open="open">
        <summary class="cursor-pointer rounded text-lg font-bold text-navy-800 focus-visible:ring-2 focus-visible:ring-navy-400">
            طلباتي
            <span class="text-sm font-normal text-slate-600">— الجارية {{ stats.open ?? 0 }} · المنجزة {{ stats.completed ?? 0 }}</span>
        </summary>
        <ul v-if="requests.length" class="mt-3 divide-y divide-slate-100">
            <li v-for="request in requests" :key="request.key" class="py-2 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <p class="flex min-w-0 flex-wrap items-center gap-2">
                        <StatusBadge :label="request.status" :tone="request.tone" />
                        <span v-if="request.reference" dir="ltr" class="text-xs font-semibold text-slate-600">{{ request.reference }}</span>
                        <span class="font-semibold text-navy-800">{{ request.title }}</span>
                    </p>
                    <Link v-if="waitsForMe(request)" :href="request.url" class="btn btn-primary btn-sm"><AppIcon name="send" :size="16" /> اتخاذ إجراء</Link>
                    <Link v-else :href="request.url" class="btn btn-secondary btn-sm"><AppIcon name="file-text" :size="16" /> عرض</Link>
                </div>
                <p v-if="request.summary?.where" class="mt-1 text-xs text-slate-500">
                    الآن: {{ request.summary.where }}<template v-if="request.summary.next"> · التالي: {{ request.summary.next }}</template>
                </p>
            </li>
        </ul>
        <p v-else class="mt-3 text-sm text-slate-600">لم تقدّم أي طلب بعد.</p>
        <Link href="/requests" class="btn btn-secondary btn-sm mt-3"><AppIcon name="clipboard-list" :size="16" /> كل طلباتي وطلب جديد</Link>
    </details>
</template>
