<script setup>
import { Link } from '@inertiajs/vue3';
import { arabicLatinDigitsLocale } from '../../Support/dates.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';

defineProps({
    username: String,
    navigation: Array,
    requests: Object,
    canManage: Boolean,
    canCreate: Boolean,
    isAdmin: Boolean,
    hrNotifications: Array,
});

const statusLabels = { draft: 'مسودة', submitted: 'بانتظار المراجعة', manager_approved: 'بانتظار الاعتماد النهائي', pending_registration: 'معتمدة — بانتظار التسجيل', returned: 'مُعاد للتعديل', approved: 'معتمدة ومسجلة', rejected: 'مرفوضة', cancelled: 'ملغاة' };
const statusTones = { draft: 'warning', submitted: 'info', manager_approved: 'info', pending_registration: 'info', returned: 'warning', approved: 'success', rejected: 'danger', cancelled: 'muted' };
const eventLabels = { submitted: 'طلب جديد', approved: 'تم الاعتماد', rejected: 'تم الرفض', cancelled: 'تم الإلغاء' };
const formattedDate = value => (value ? new Intl.DateTimeFormat(arabicLatinDigitsLocale, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : '—');
</script>

<template>
    <AppLayout title="الإجازات" :navigation="navigation">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="الإجازات" title="طلبات الإجازات" description="تُحفظ الطلبات مسودة أولًا، ولا تُخصم الأيام من الرصيد إلا بعد الاعتماد.">
                <Link href="/leaves/balances" class="btn btn-secondary">الأرصدة والاستحقاقات</Link>
                <Link v-if="isAdmin" href="/admin/leaves/settings" class="btn btn-secondary">إعدادات الإجازات</Link>
                <Link v-if="canCreate" href="/leaves/create" class="btn btn-primary">+ طلب إجازة</Link>
            </PageHeader>

            <section v-if="hrNotifications?.length" class="card">
                <h2 class="card-title mb-3">إشعارات الموارد البشرية</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="(notification, index) in hrNotifications" :key="index" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            <Link :href="`/leaves/${notification.leave_request_id}`" class="font-semibold text-blue-700 hover:underline">طلب #{{ notification.leave_request_id }}</Link>
                            — {{ eventLabels[notification.event] || notification.event }}
                        </span>
                        <span class="text-xs text-slate-500">{{ formattedDate(notification.created_at) }}</span>
                    </li>
                </ul>
            </section>

            <section class="card">
                <EmptyState v-if="!requests.data?.length" title="لا توجد طلبات إجازة" />
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-[820px]">
                        <thead><tr><th>الرقم</th><th>الموظف</th><th>النوع</th><th>الفترة</th><th>الأيام</th><th>الحالة</th><th></th></tr></thead>
                        <tbody>
                            <template v-for="row in requests.data" :key="row.id">
                                <tr>
                                    <td dir="ltr" class="text-right">{{ row.reference_no || `#${row.id}` }}</td>
                                    <td>{{ row.first_name_ar }} {{ row.family_name_ar }} <span class="text-xs text-slate-400" dir="ltr">{{ row.employee_number }}</span></td>
                                    <td>{{ row.leave_type_name }}</td>
                                    <td>{{ formattedDate(row.start_date) }} ← {{ formattedDate(row.end_date) }}</td>
                                    <td>{{ row.requested_days ?? '—' }}</td>
                                    <td>
                                        <StatusBadge :label="statusLabels[row.status] || row.status" :tone="statusTones[row.status] || 'muted'" />
                                        <p v-if="row.where" class="mt-1 text-[11px] text-slate-500">{{ row.where }}</p>
                                    </td>
                                    <td><Link :href="`/leaves/${row.id}`" class="btn btn-secondary btn-sm">عرض</Link></td>
                                </tr>
                                <tr v-if="row.steps?.length" class="border-t-0">
                                    <td colspan="7" class="pt-0"><StatusStepper :steps="row.steps" compact /></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <nav v-if="(requests.links || []).length > 3" class="mt-4 flex flex-wrap gap-1" aria-label="الصفحات">
                    <template v-for="(link, index) in requests.links" :key="index">
                        <Link v-if="link.url" :href="link.url" :class="['btn btn-sm', link.active ? 'btn-primary' : 'btn-secondary']" v-html="link.label" />
                        <span v-else class="btn btn-sm text-slate-400" v-html="link.label" />
                    </template>
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
