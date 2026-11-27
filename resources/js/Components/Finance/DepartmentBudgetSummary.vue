<script setup>
import { Link } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import { arabicLatinDigitsLocale, formatDate } from '../../Support/dates.js';

defineProps({ summary: { type: Object, default: null } });

const money = value => value === null || value === undefined ? '—' : new Intl.NumberFormat(arabicLatinDigitsLocale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(value);
const date = value => formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' }, '—');
const month = value => value ? formatDate(`${value}-01T12:00:00`, { month: 'long', year: 'numeric' }, '—') : '—';
const overspent = item => item.configured && Number(item.remaining) < 0;
</script>

<template>
    <section v-if="summary?.items?.length" class="card min-w-0 space-y-4" aria-label="ميزانية القسم">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="card-title">ميزانية القسم <span class="text-sm font-normal text-slate-500">{{ month(summary.period) }}</span></h2>
                <p class="mt-1 text-xs leading-6 text-slate-500">المخصص الشهري من الحسابات، ومصروف الشهر محسوب تلقائيًا من الصرف المسجل والمنفذ في النظام. لكل عملة رصيد مستقل.</p>
            </div>
            <Link v-if="summary.manageUrl" :href="summary.manageUrl" class="btn btn-secondary btn-sm">إدارة ميزانيات الأقسام</Link>
        </div>
        <div class="space-y-3">
            <article v-for="item in summary.items" :key="`${item.departmentId}-${item.currency || 'unconfigured'}`" class="min-w-0 rounded-xl border p-3 sm:p-4" :class="overspent(item) ? 'border-red-200 bg-red-50/50' : 'border-slate-200 bg-slate-50/50'">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-navy-800">{{ item.department }} <bdi v-if="item.configured" class="text-xs font-normal text-slate-500">{{ item.currency }}</bdi></h3>
                    <StatusBadge v-if="overspent(item)" label="تجاوز الميزانية" tone="danger" />
                </div>
                <p v-if="!item.configured" class="mt-2 text-sm text-slate-600">لم تُدخل ميزانية القسم لهذا الشهر.</p>
                <template v-else>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div><dt class="text-xs text-slate-500">المخصص</dt><dd class="mt-1 break-words text-lg font-bold tabular-nums text-navy-800"><bdi>{{ money(item.allocated) }} {{ item.currency }}</bdi></dd></div>
                        <div><dt class="text-xs text-slate-500">المصروف</dt><dd class="mt-1 break-words text-lg font-bold tabular-nums text-slate-700"><bdi>{{ money(item.spent) }} {{ item.currency }}</bdi></dd></div>
                        <div><dt class="text-xs text-slate-500">المتبقي المتاح</dt><dd class="mt-1 break-words text-lg font-bold tabular-nums" :class="overspent(item) ? 'text-red-700' : 'text-emerald-700'"><bdi>{{ money(item.remaining) }} {{ item.currency }}</bdi></dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-slate-500">المصروف حتى {{ date(item.asOf) }}<span v-if="item.updatedAt"> · آخر تحديث للمخصص {{ date(item.updatedAt) }}</span></p>
                </template>
            </article>
        </div>
    </section>
</template>
