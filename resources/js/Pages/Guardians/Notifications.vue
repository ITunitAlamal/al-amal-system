<script setup>
import { Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import Pagination from '../../Components/Pagination.vue';
import { formatDateTime } from '../../Support/dates.js';

defineProps({ rows: Object, filter: String, kinds: Object });
const filters = { all: 'الكل', unread: 'غير المقروءة', action: 'مطلوب منك إجراء', decision: 'قرارات', aid: 'مساعدات' };
</script>

<template>
    <PortalLayout title="الإشعارات">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold text-navy-800">الإشعارات</h1>
            <button type="button" class="btn btn-sm btn-secondary" @click="router.post('/guardian/notifications/read-all', {}, { preserveScroll: true })">تعليم الكل كمقروء</button>
        </div>
        <nav class="flex gap-1 overflow-x-auto" aria-label="تصفية الإشعارات">
            <Link v-for="(label, key) in filters" :key="key" :href="key === 'all' ? '/guardian/notifications' : `/guardian/notifications?filter=${key}`" :aria-current="filter === key ? 'page' : undefined"
                :class="['whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-semibold ring-1', filter === key ? 'bg-navy-700 text-white ring-navy-700' : 'bg-white text-slate-600 ring-slate-200']">{{ label }}</Link>
        </nav>
        <ul v-if="rows.data.length" class="card divide-y divide-slate-100 p-0">
            <li v-for="n in rows.data" :key="n.id">
                <a :href="`/guardian/notifications/${n.id}/open`" class="flex items-start gap-3 p-4 hover:bg-slate-50">
                    <span :class="['mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full', n.read_at ? 'bg-slate-200' : 'bg-gold-500']" :aria-label="n.read_at ? 'مقروء' : 'غير مقروء'"></span>
                    <span class="min-w-0"><span :class="['block text-sm', n.read_at ? 'text-slate-700' : 'font-semibold text-navy-900']">{{ n.title }}</span>
                        <span v-if="n.body" class="block text-sm text-slate-600">{{ n.body }}</span>
                        <span class="block text-xs text-slate-500">{{ kinds[n.kind] || '' }} · {{ formatDateTime(n.created_at) }}</span></span>
                </a>
            </li>
        </ul>
        <EmptyState v-else :title="filter === 'all' ? 'لا توجد إشعارات' : 'لا توجد إشعارات مطابقة للتصفية'">
            <Link v-if="filter !== 'all'" href="/guardian/notifications" class="btn btn-sm btn-secondary">عرض الكل</Link>
        </EmptyState>
        <Pagination v-if="rows.links && rows.last_page > 1" :links="rows.links" />
    </PortalLayout>
</template>
