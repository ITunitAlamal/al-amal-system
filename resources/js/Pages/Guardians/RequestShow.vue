<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import RequestTimeline from '../../Components/Portal/RequestTimeline.vue';
import { formatDateTime } from '../../Support/dates.js';

// One family request: stage timeline, each child's own outcome and the public-safe history (no internal notes).
defineProps({ application: Object, events: Array });
const tone = s => ({ approved: 'success', rejected: 'danger', needs_completion: 'warning' }[s] || 'info');
</script>

<template>
    <PortalLayout :title="`الطلب ${application.reference_no}`" back="/guardian/requests">
        <section class="card space-y-4 p-5">
            <div class="flex flex-wrap items-center gap-2"><h1 class="text-xl font-bold text-navy-800">طلب تسجيل الأسرة</h1><span class="font-mono text-slate-500" dir="ltr">{{ application.reference_no }}</span><StatusBadge :label="application.status_label" :tone="tone(application.status)" /></div>
            <RequestTimeline :status="application.status" :approved="application.status_label === 'معتمد'" />
            <p v-if="application.completion_request" class="rounded-lg bg-gold-50 p-3 text-sm font-semibold text-gold-900">مطلوب استكمال: {{ application.completion_request }}</p>
            <Link :href="`/guardian/registrations/${application.public_id}`" :class="['btn', application.editable ? 'btn-primary' : 'btn-secondary']">{{ application.editable ? 'فتح الطلب للاستكمال' : 'عرض الطلب' }}</Link>
        </section>
        <section class="card space-y-2 p-5">
            <h2 class="card-title">الأطفال في الطلب</h2>
            <ul class="divide-y divide-slate-100 text-sm"><li v-for="(c, i) in application.children" :key="i" class="flex flex-wrap items-center gap-2 py-2"><span class="font-semibold">{{ c.full_name }}</span><StatusBadge :label="c.status_label" :tone="tone(c.status)" /><span v-if="c.note" class="text-slate-600">{{ c.note }}</span></li></ul>
        </section>
        <section class="card space-y-2 p-5">
            <h2 class="card-title">سجل الطلب</h2>
            <ol v-if="events.length" class="space-y-3 border-s-2 border-slate-200 ps-4">
                <li v-for="(e, i) in events" :key="i" class="relative text-sm"><span class="absolute -start-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-blue-600" aria-hidden="true"></span>
                    <p class="font-semibold text-navy-900">{{ e.label }}<span v-if="e.child"> — {{ e.child }}</span></p><p class="text-xs text-slate-500">{{ formatDateTime(e.date) }}</p>
                    <p v-if="e.message" class="mt-1 rounded-lg bg-slate-50 p-2">{{ e.message }}</p></li>
            </ol>
            <p v-else class="text-sm text-slate-500">لا توجد أحداث بعد.</p>
        </section>
    </PortalLayout>
</template>
