<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';

// «دوامي الشهري»: one line per employee (the employee sees their own; a head their staff; HR and the ED everyone),
// from the same daily ledger as the HR register. For follow-up only: nothing here is a sanction or a pay effect.
const props = defineProps({ month: String, lines: Array, ownId: Number, selected: Number, days: Array, departments: Array, department: Number, canRoster: Boolean });
const go = params => router.get('/attendance/monthly', { month: props.month, department: props.department || undefined, ...params }, { preserveScroll: true });
const current = computed(() => props.lines.find(l => l.employee_id === props.selected));
const hm = m => (m ? `${Math.floor(m / 60) ? `${Math.floor(m / 60)} س ` : ''}${m % 60} د` : '—');
const tone = s => ({ complete: 'text-emerald-700', absent: 'text-red-700', incomplete: 'text-amber-700' }[s] || 'text-slate-700');
</script>

<template>
    <AppLayout title="دوامي الشهري">
        <div class="mx-auto max-w-7xl space-y-4">
            <PageHeader eyebrow="خدماتي" title="الدوام الشهري" description="ملخص الحضور والتأخير والخروج المبكر والأذونات لكل شهر، من سجل الحضور نفسه. للمتابعة فقط، ولا يترتب عليه أي إجراء تلقائي." />
            <div class="card flex flex-wrap items-end gap-3">
                <label class="text-sm">الشهر<input type="month" class="field mt-1" dir="ltr" :value="month" @change="go({ month: $event.target.value, employee: selected || undefined })"></label>
                <label v-if="departments.length" class="text-sm">القسم<select class="field mt-1" :value="department || ''" @change="go({ department: $event.target.value || undefined })"><option value="">كل الأقسام</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
                <a v-if="canRoster" href="/attendance/shifts" class="btn btn-secondary ms-auto">جدول المناوبات</a>
            </div>

            <EmptyState v-if="!lines.length" title="لا توجد بيانات حضور لهذا الشهر" />
            <div v-else-if="lines.length > 1" class="card overflow-x-auto p-0">
                <table class="min-w-full text-sm">
                    <thead><tr class="bg-slate-50 text-slate-600">
                        <th class="p-2 text-start">الموظف</th><th>أيام مقررة</th><th>مكتمل</th><th>غياب</th><th>بصمة ناقصة</th><th>تأخير (أيام)</th><th>دقائق التأخير</th>
                        <th>خروج مبكر</th><th>دقائقه</th><th>بإذن</th><th>إجازة/عطلة</th><th>صافي الساعات</th><th>مراجعة</th>
                    </tr></thead>
                    <tbody class="tabular-nums">
                        <tr v-for="l in lines" :key="l.employee_id" class="cursor-pointer border-t border-slate-100 text-center hover:bg-slate-50" :class="l.employee_id === selected ? 'bg-amber-50' : ''" @click="go({ employee: l.employee_id })">
                            <td class="p-2 text-start"><span class="font-semibold text-navy-800">{{ l.name }}</span><span class="block text-xs text-slate-500">{{ l.department || '—' }}{{ l.not_enrolled ? ` · غير مسجل على الجهاز ${l.not_enrolled} يومًا` : '' }}</span></td>
                            <td>{{ l.scheduled }}</td><td class="text-emerald-700">{{ l.complete }}</td><td :class="l.absent ? 'font-bold text-red-700' : ''">{{ l.absent }}</td>
                            <td :class="l.incomplete ? 'text-amber-700' : ''">{{ l.incomplete }}</td><td>{{ l.late_days }}</td><td>{{ hm(l.late_minutes) }}</td>
                            <td>{{ l.early_days }}</td><td>{{ hm(l.early_minutes) }}</td><td>{{ l.excused_days }}</td><td>{{ l.leave_days }}</td><td>{{ l.net_hours }}</td><td>{{ l.review || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template v-if="current">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="card text-center"><p class="text-xs text-slate-500">أيام مقررة</p><p class="text-2xl font-bold tabular-nums">{{ current.scheduled }}</p></div>
                    <div class="card text-center"><p class="text-xs text-slate-500">دوام مكتمل</p><p class="text-2xl font-bold tabular-nums text-emerald-700">{{ current.complete }}</p></div>
                    <div class="card text-center"><p class="text-xs text-slate-500">غياب / بصمة ناقصة</p><p class="text-2xl font-bold tabular-nums text-red-700">{{ current.absent }} / {{ current.incomplete }}</p></div>
                    <div class="card text-center"><p class="text-xs text-slate-500">تأخير</p><p class="text-2xl font-bold tabular-nums text-amber-700">{{ current.late_days }}</p><p class="text-xs">{{ hm(current.late_minutes) }}</p></div>
                    <div class="card text-center"><p class="text-xs text-slate-500">خروج مبكر</p><p class="text-2xl font-bold tabular-nums">{{ current.early_days }}</p><p class="text-xs">{{ hm(current.early_minutes) }}</p></div>
                    <div class="card text-center"><p class="text-xs text-slate-500">صافي الساعات</p><p class="text-2xl font-bold tabular-nums">{{ current.net_hours }}</p></div>
                </div>
                <div class="card overflow-x-auto p-0">
                    <h2 class="card-title p-3">أيام {{ current.name }}</h2>
                    <table class="min-w-full text-sm">
                        <thead><tr class="bg-slate-50 text-slate-600"><th class="p-2 text-start">اليوم</th><th>الدوام المقرر</th><th>الدخول</th><th>الخروج</th><th>الحالة</th><th>التأخير</th><th>خروج مبكر</th><th>المصدر</th></tr></thead>
                        <tbody class="tabular-nums">
                            <tr v-for="d in days" :key="d.work_date" class="border-t border-slate-100 text-center">
                                <td class="p-2 text-start" dir="ltr">{{ d.work_date }}</td>
                                <td dir="ltr">{{ d.expected_in ? `${d.expected_in}–${d.expected_out}` : '—' }}</td><td dir="ltr">{{ d.actual_in || '—' }}</td><td dir="ltr">{{ d.actual_out || '—' }}</td>
                                <td :class="tone(d.day_state)">{{ d.day_label }}<span v-if="d.permissions?.length" class="block text-xs text-slate-500">{{ d.permissions.join('، ') }}</span></td>
                                <td>{{ d.punctuality === 'late' ? hm(d.chargeable_late_minutes) : (d.punctuality_label || '—') }}</td><td>{{ hm(d.early_minutes) }}</td>
                                <td class="text-xs text-slate-500">{{ d.shift_source || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-600">لتصحيح بصمة أو تسجيل إذن: «طلب إذن» من «طلباتي»، أو تواصل مع الموارد البشرية. التأخير المحسوب بعد مهلة السماح والأذونات المعتمدة.</p>
            </template>
        </div>
    </AppLayout>
</template>
