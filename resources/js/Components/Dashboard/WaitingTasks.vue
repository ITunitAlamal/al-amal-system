<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '../AppIcon.vue';
import EmptyState from '../EmptyState.vue';
import StatusBadge from '../StatusBadge.vue';
import { waitingBadge } from '../../Support/waitingTime.js';

/**
 * «بانتظار إجرائي» at the top of «مساحتي»: the first tasks of «مهامي», in the same order, under the same count.
 * "ready" and "field" are parts of that count (the head of the department only), never numbers to add to it.
 */
const props = defineProps({
    waiting: { type: Object, required: true },
});

const parts = computed(() => [
    ...(props.waiting.ready ? [`جاهز للإسناد ${props.waiting.ready}`] : []),
    ...(props.waiting.field ? [`توثيق عمل ميداني ${props.waiting.field}`] : []),
]);

// Who the task concerns and how it reached the user. A workflow task's detail repeats its type and step, so it is
// shown only for the other sources (a document, a correction…), which say something of their own.
function facts(task) {
    const strong = 'font-semibold text-navy-700';
    return [
        task.fieldWork ? { text: `العامل: ${task.worker || 'غير مسجل'}`, css: strong } : null,
        !task.fieldWork && task.readyToAssign ? { text: 'جاهز للإسناد', css: strong } : null,
        task.ownLabel ? { text: task.ownLabel, css: 'font-semibold text-gold-700' }
            : task.requester ? { text: `${task.personLabel || 'مقدم الطلب'}: ${task.requester}`, css: '' } : null,
        task.assignedBy ? { text: `أسندها إليك: ${task.assignedBy}`, css: strong } : null,
        task.detail && !task.key.startsWith('wf-') ? { text: task.detail, css: '' } : null,
    ].filter(Boolean);
}
</script>

<template>
    <section class="card" aria-labelledby="waiting-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="waiting-title" class="text-lg font-bold text-navy-800">
                    <Link href="/tasks" class="rounded hover:underline focus-visible:ring-2 focus-visible:ring-navy-400">بانتظار إجرائي ({{ waiting.total }})</Link>
                </h2>
                <p v-if="parts.length || waiting.overdue" class="mt-1 text-sm text-slate-600">
                    <template v-if="parts.length">منها: {{ parts.join(' · ') }}</template>
                    <template v-if="parts.length && waiting.overdue"> · </template>
                    <span v-if="waiting.overdue" class="font-semibold text-red-700">تجاوز مهلته {{ waiting.overdue }}</span>
                </p>
            </div>
            <Link href="/tasks" class="btn btn-secondary btn-sm"><AppIcon name="inbox" :size="16" /> فتح مهامي</Link>
        </div>

        <EmptyState v-if="!waiting.items.length" class="mt-4" title="لا توجد مهام بانتظارك" description="ستظهر هنا الطلبات التي تصل إلى مرحلة أنت مسؤول عنها." />
        <ul v-else class="mt-4 space-y-2">
            <li v-for="task in waiting.items" :key="task.key" class="rounded-xl border p-3" :class="task.overdue ? 'border-red-200 bg-red-50/40' : 'border-slate-200'">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <p class="flex min-w-0 flex-wrap items-center gap-2">
                        <StatusBadge v-if="waitingBadge(task)" :label="waitingBadge(task).label" :tone="waitingBadge(task).tone" />
                        <span v-if="task.reference" dir="ltr" class="text-xs font-semibold text-slate-600">{{ task.reference }}</span>
                        <span class="font-semibold text-navy-800">{{ task.title }}</span>
                    </p>
                    <Link :href="task.url" class="btn btn-primary btn-sm shrink-0"><AppIcon :name="task.fieldWork ? 'circle-check' : 'send'" :size="16" /> {{ task.actionLabel }}</Link>
                </div>
                <p class="mt-2 text-xs leading-6 text-slate-600">
                    {{ task.kind }} · المرحلة: <strong class="text-navy-700">{{ task.step || '—' }}</strong>
                    <template v-if="task.next"> · الإجراء التالي: {{ task.next }}</template>
                </p>
                <p v-if="facts(task).length" class="text-xs leading-6 text-slate-500">
                    <template v-for="(fact, index) in facts(task)" :key="index">
                        <template v-if="index"> · </template><span :class="fact.css">{{ fact.text }}</span>
                    </template>
                </p>
            </li>
        </ul>
        <p v-if="waiting.total > waiting.items.length" class="mt-3 text-xs text-slate-600">
            يعرض أول {{ waiting.items.length }} من {{ waiting.total }}؛
            <Link href="/tasks" class="font-semibold text-blue-700 hover:underline">البقية في «مهامي»</Link>
        </p>
    </section>
</template>
