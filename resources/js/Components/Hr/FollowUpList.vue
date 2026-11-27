<script setup>
import { Link } from '@inertiajs/vue3';
import AppIcon from '../AppIcon.vue';
import StatusBadge from '../StatusBadge.vue';
import { formatDate, formatDateTime } from '../../Support/dates.js';
import { waitedFor } from '../../Support/waitingTime.js';

/**
 * Rows of «متابعة القسم» (DepartmentFollowUp): on wide screens a table, on narrow ones each request is a block that
 * starts with its status, its identity and its action. A row without "url" is a request the viewer may not open:
 * it shows what the department's list shows anyway and no link.
 */
defineProps({
    rows: { type: Array, required: true },
    // The follow-up page also shows who asked and from which department.
    extended: { type: Boolean, default: false },
    caption: { type: String, default: 'أعمال القسم' },
});

const day = value => formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' }, '—');
// A closed request shows when it was closed; an open one how long it has been with its present holder.
const since = row => (row.open ? waitedFor(row.since) : day(row.since)) || '—';
</script>

<template>
    <table class="w-full text-right text-sm">
        <caption class="sr-only">{{ caption }}</caption>
        <thead class="hidden lg:table-header-group">
            <tr class="bg-navy-50 text-xs font-semibold text-navy-800">
                <th scope="col" class="rounded-s-lg px-3 py-2">المرجع</th>
                <th scope="col" class="px-3 py-2">النوع</th>
                <th scope="col" class="px-3 py-2">المرحلة</th>
                <th scope="col" class="px-3 py-2">لدى</th>
                <th v-if="extended" scope="col" class="px-3 py-2">مقدم الطلب</th>
                <th v-if="extended" scope="col" class="px-3 py-2">القسم</th>
                <th scope="col" class="px-3 py-2">منذ</th>
                <th scope="col" class="px-3 py-2">المهلة</th>
                <th scope="col" class="rounded-e-lg px-3 py-2"><span class="sr-only">الإجراء</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.key" class="flex flex-col gap-1 border-t border-slate-100 py-3 first:border-t-0 lg:table-row lg:py-0">
                <td class="order-1 lg:px-3 lg:py-3">
                    <span class="flex flex-wrap items-center gap-2">
                        <StatusBadge :label="row.status" :tone="row.tone" />
                        <StatusBadge v-if="row.urgent" label="عاجل" tone="warning" />
                        <span dir="ltr" class="font-semibold text-navy-800">{{ row.reference || '—' }}</span>
                    </span>
                    <span v-if="row.title" class="mt-1 block text-slate-700">{{ row.title }}</span>
                </td>
                <td class="order-3 lg:px-3 lg:py-3"><span class="text-slate-500 lg:hidden">النوع: </span>{{ row.kind }}</td>
                <td class="order-3 lg:px-3 lg:py-3"><span class="text-slate-500 lg:hidden">المرحلة: </span>{{ row.step || '—' }}</td>
                <td class="order-3 lg:px-3 lg:py-3">
                    <span class="text-slate-500 lg:hidden">لدى: </span>{{ row.holder || '—' }}
                    <span v-if="row.assigned_by" class="block text-xs text-slate-500">أسندها: {{ row.assigned_by }}</span>
                    <span v-if="row.field_work" class="block text-xs font-semibold text-navy-700">العامل: {{ row.worker || 'غير مسجل' }}</span>
                </td>
                <td v-if="extended" class="order-3 lg:px-3 lg:py-3"><span class="text-slate-500 lg:hidden">مقدم الطلب: </span>{{ row.requester || '—' }}</td>
                <td v-if="extended" class="order-3 lg:px-3 lg:py-3"><span class="text-slate-500 lg:hidden">القسم: </span>{{ row.department || '—' }}</td>
                <td class="order-3 whitespace-nowrap lg:px-3 lg:py-3"><span class="text-slate-500 lg:hidden">{{ row.open ? 'لدى صاحبها ' : 'أُغلقت: ' }}</span>{{ since(row) }}</td>
                <td class="order-3 whitespace-nowrap lg:px-3 lg:py-3" :class="row.overdue ? 'font-semibold text-red-700' : ''">
                    <span class="text-slate-500 lg:hidden">المهلة: </span>{{ row.due_at ? day(row.due_at) : (row.open ? 'بلا مهلة' : '—') }}
                    <span v-if="row.appointment" class="block text-xs">الموعد المحدد: <bdi>{{ formatDateTime(row.appointment) }}</bdi></span>
                </td>
                <!-- The last column on wide screens; second in the block on narrow ones (the row is a column of cells there). -->
                <td class="order-2 lg:px-3 lg:py-3">
                    <span class="flex flex-wrap gap-2 pt-1 lg:justify-end lg:pt-0">
                        <Link v-if="row.url && row.mine" :href="row.url" class="btn btn-primary btn-sm"><AppIcon :name="row.field_work ? 'circle-check' : 'send'" :size="16" /> {{ row.field_work ? 'توثيق الإنجاز' : 'اتخاذ إجراء' }}</Link>
                        <Link v-else-if="row.url" :href="row.url" class="btn btn-secondary btn-sm"><AppIcon name="file-text" :size="16" /> عرض</Link>
                        <Link v-if="row.assignment_url" :href="row.assignment_url" class="btn btn-secondary btn-sm"><AppIcon name="undo" :size="16" /> سحب الإسناد</Link>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>
</template>
