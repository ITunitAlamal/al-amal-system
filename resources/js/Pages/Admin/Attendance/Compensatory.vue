<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';

const props = defineProps({ emergency_holidays: Array, compensatory_work: Array, official_holidays: Array, employees: Array, departments: Array, canApprove: Boolean, canRegisterHoliday: Boolean, canRegisterWork: Boolean });
const holiday = useForm({ holiday_date: '', name: '', reason: '', employee_id: '', department_id: '' });
const work = useForm({ employee_id: '', work_date: '', reason: '' });
const submit = (form, path) => form.post(path, { preserveScroll: true, onSuccess: () => form.reset() });
function decision(kind, id, value) {
    if (!window.confirm(value === 'approved' ? 'تأكيد الاعتماد؟' : 'تأكيد الرفض؟')) return;
    router.post(`/admin/attendance/compensatory/${kind}/${id}/decision`, { decision: value }, { preserveScroll: true });
}
function credit(id) {
    if (!window.confirm('إضافة يوم تعويضي كامل بعد التحقق من البصمات والموافقة؟')) return;
    router.post(`/admin/attendance/compensatory/work/${id}/credit`, {}, { preserveScroll: true });
}
function name(id) {
    const employee = props.employees.find(x => Number(x.id) === Number(id));
    return employee ? `${employee.first_name_ar} ${employee.family_name_ar} (#${employee.employee_number})` : `#${id}`;
}
</script>
<template>
    <AppLayout title="العطل والعمل التعويضي">
        <div class="mx-auto max-w-6xl space-y-6">
            <PageHeader eyebrow="الحضور والانصراف" title="العطل والإجازات التعويضية" description="العطل الطارئة، وتكليف العمل في أيام العطل، ومنح الأيام التعويضية بعد الاعتماد.">
                <Link href="/admin/attendance" class="btn btn-secondary">العودة للحضور والانصراف</Link>
            </PageHeader>

            <section class="card space-y-4">
                <h2 class="card-title">طلب عطلة طارئة</h2>
                <p class="text-sm text-slate-600">يسجّلها رئيس قسم الموارد البشرية، ويعتمدها المدير التنفيذي وحده.</p>
                <form v-if="canRegisterHoliday" class="grid gap-3 md:grid-cols-2" @submit.prevent="submit(holiday, '/admin/attendance/compensatory/holidays')">
                    <div>
                        <label for="holiday-date" class="field-label">التاريخ *</label>
                        <input id="holiday-date" v-model="holiday.holiday_date" type="date" required class="field" />
                        <p v-if="holiday.errors.holiday_date" class="field-error">{{ holiday.errors.holiday_date }}</p>
                    </div>
                    <div>
                        <label for="holiday-name" class="field-label">اسم العطلة *</label>
                        <input id="holiday-name" v-model="holiday.name" required maxlength="255" class="field" />
                        <p v-if="holiday.errors.name" class="field-error">{{ holiday.errors.name }}</p>
                    </div>
                    <div>
                        <label for="holiday-employee" class="field-label">الموظف (اختياري)</label>
                        <select id="holiday-employee" v-model="holiday.employee_id" class="field"><option value="">عام / حسب القسم</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ name(e.id) }}</option></select>
                    </div>
                    <div>
                        <label for="holiday-department" class="field-label">القسم (اختياري)</label>
                        <select id="holiday-department" v-model="holiday.department_id" class="field"><option value="">كل الجمعية</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                    </div>
                    <div class="md:col-span-2">
                        <label for="holiday-reason" class="field-label">سبب العطلة *</label>
                        <textarea id="holiday-reason" v-model="holiday.reason" required minlength="5" rows="2" class="field" />
                        <p v-if="holiday.errors.reason" class="field-error">{{ holiday.errors.reason }}</p>
                    </div>
                    <div class="md:col-span-2"><button type="submit" :disabled="holiday.processing" class="btn btn-primary">إرسال العطلة للاعتماد</button></div>
                </form>
                <h3 class="text-sm font-bold text-navy-800">طلبات العطل</h3>
                <EmptyState v-if="!emergency_holidays.length" title="لا توجد طلبات عطل طارئة" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="h in emergency_holidays" :key="h.id" class="space-y-2 py-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold text-navy-800">{{ h.name }}</span>
                            <span class="text-slate-500" dir="ltr">{{ h.holiday_date }}</span>
                            <StatusBadge :status="h.status" />
                            <template v-if="canApprove && h.status === 'pending'">
                                <button type="button" class="btn btn-primary btn-sm" @click="decision('holidays', h.id, 'approved')">اعتماد</button>
                                <button type="button" class="btn btn-danger btn-sm" @click="decision('holidays', h.id, 'rejected')">رفض</button>
                            </template>
                        </div>
                        <StatusStepper :steps="h.steps" compact />
                    </li>
                </ul>
            </section>

            <section class="card space-y-4">
                <h2 class="card-title">تكليف عمل في يوم عطلة / إجازة تعويضية</h2>
                <p class="alert-info">العمل المعتمد في عطلة رسمية أو يوم راحة أسبوعية يمنح يومًا تعويضيًا كاملًا بغض النظر عن ساعات العمل، بعد اعتماد التكليف وإثبات بصمتي الحضور والانصراف. لا يُمنح اليوم مرتين لنفس التكليف.</p>
                <p class="text-sm text-slate-600">تسجّله الموارد البشرية، ويعتمده المدير التنفيذي ويمنح اليوم التعويضي.</p>
                <form v-if="canRegisterWork" class="grid gap-3 md:grid-cols-2" @submit.prevent="submit(work, '/admin/attendance/compensatory/work')">
                    <div>
                        <label for="work-employee" class="field-label">الموظف *</label>
                        <select id="work-employee" v-model="work.employee_id" required class="field"><option value="">اختر</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ name(e.id) }}</option></select>
                        <p v-if="work.errors.employee_id" class="field-error">{{ work.errors.employee_id }}</p>
                    </div>
                    <div>
                        <label for="work-date" class="field-label">تاريخ العمل *</label>
                        <input id="work-date" v-model="work.work_date" type="date" required class="field" />
                        <p v-if="work.errors.work_date" class="field-error">{{ work.errors.work_date }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="work-reason" class="field-label">سبب التكليف *</label>
                        <textarea id="work-reason" v-model="work.reason" required minlength="5" rows="2" class="field" />
                        <p v-if="work.errors.reason" class="field-error">{{ work.errors.reason }}</p>
                    </div>
                    <div class="md:col-span-2"><button type="submit" :disabled="work.processing" class="btn btn-primary">إرسال التكليف للاعتماد</button></div>
                </form>
                <EmptyState v-if="!compensatory_work.length" title="لا توجد تكليفات عمل في أيام العطل" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="w in compensatory_work" :key="w.id" class="space-y-2 py-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold text-navy-800">{{ name(w.employee_id) }}</span>
                            <span class="text-slate-500" dir="ltr">{{ w.work_date }}</span>
                            <StatusBadge :status="w.status" />
                            <template v-if="canApprove && w.status === 'pending'">
                                <button type="button" class="btn btn-primary btn-sm" @click="decision('work', w.id, 'approved')">اعتماد</button>
                                <button type="button" class="btn btn-danger btn-sm" @click="decision('work', w.id, 'rejected')">رفض</button>
                            </template>
                            <button v-if="canApprove && w.status === 'approved' && !w.credited_at" type="button" class="btn btn-gold btn-sm" @click="credit(w.id)">منح يوم تعويضي</button>
                        </div>
                        <StatusStepper :steps="w.steps" compact />
                    </li>
                </ul>
            </section>

            <section class="card">
                <h2 class="card-title mb-3">العطل الرسمية المسجلة</h2>
                <EmptyState v-if="!official_holidays.length" title="لا توجد عطل رسمية مسجلة" description="تُضاف العطل الرسمية من إعدادات الإجازات." />
                <ul v-else class="divide-y divide-slate-100 text-sm">
                    <li v-for="h in official_holidays" :key="h.id" class="flex gap-3 py-2"><span dir="ltr" class="text-slate-500">{{ h.holiday_date }}</span><span>{{ h.name_ar }}</span></li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
