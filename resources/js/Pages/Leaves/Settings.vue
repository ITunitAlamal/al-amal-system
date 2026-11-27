<script setup>
import { computed } from 'vue';
import { arabicLatinDigitsLocale } from '../../Support/dates.js';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({
    username: String, navigation: Array, settings: Object, holidays: Array, launched: Boolean,
    canConfigure: { type: Boolean, default: false }, canDecideProrata: { type: Boolean, default: false }, prorata: { type: Object, default: () => ({}) },
    approvalModes: { type: Object, default: () => ({}) },
    leaveWorkflows: { type: Array, default: () => [] },
    workflowsUrl: { type: String, default: '/admin/workflows' },
    delegationsUrl: { type: String, default: '/admin/approval-delegations' },
    openRequests: { type: Number, default: 0 },
});
const original = props.settings || {};
const initialDays = typeof original.working_weekdays === 'string' ? JSON.parse(original.working_weekdays) : (original.working_weekdays || []);
const defaultCode = code => props.leaveWorkflows.find(item => item.code === code && item.published)?.code || '';
const form = useForm({
    counting_mode: original.counting_mode || '',
    approval_mode: original.approval_mode || '',
    employee_workflow_code: original.employee_workflow_code || defaultCode('LEAVE_EMPLOYEE'),
    head_workflow_code: original.head_workflow_code || defaultCode('LEAVE_HEAD'),
    executive_workflow_code: original.executive_workflow_code || defaultCode('LEAVE_EXECUTIVE'),
    grant_mode: original.grant_mode || '',
    working_weekdays: initialDays,
    requests_enabled: Boolean(original.requests_enabled),
});
const publishedWorkflows = computed(() => props.leaveWorkflows.filter(item => item.published));
const modeChanged = computed(() => form.approval_mode !== (original.approval_mode || ''));
const holiday = useForm({ holiday_date: '', name_ar: '' });
const prorataForm = useForm({
    prorata_monthly_rate_days: original.prorata_monthly_rate_days ?? '',
    prorata_min_service_months: original.prorata_min_service_months ?? '',
    prorata_rounding: original.prorata_rounding || '',
    prorata_notes: original.prorata_notes || '',
});
const decision = useForm({ decision: 'approve', note: '' });
const submit = () => form.put('/admin/leaves/settings', { preserveScroll: true });
const addHoliday = () => holiday.post('/admin/leaves/holidays', { preserveScroll: true, onSuccess: () => holiday.reset() });
const proposeProrata = () => prorataForm.put('/admin/leaves/prorata', { preserveScroll: true });
function decide(value) {
    decision.decision = value;
    decision.post('/admin/leaves/prorata/decision', { preserveScroll: true, onSuccess: () => decision.reset('note') });
}
function removeHoliday(item) {
    if (window.confirm(`حذف العطلة «${item.name_ar}»؟`)) {
        router.delete(`/admin/leaves/holidays/${item.id}`, { preserveScroll: true });
    }
}
const weekdays = ['الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];
const prorataStates = { not_defined: ['غير معرّفة', 'muted'], proposed: ['بانتظار قرار المدير التنفيذي', 'warning'], approved: ['معتمدة', 'success'] };
const date = value => (value ? new Intl.DateTimeFormat(arabicLatinDigitsLocale, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : '');
</script>

<template>
    <AppLayout title="إعدادات الإجازات" :navigation="navigation">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link href="/leaves" class="text-sm font-semibold text-blue-700 hover:underline">→ الإجازات</Link>
            <PageHeader eyebrow="الإجازات" title="إعدادات تشغيل وحدة الإجازات">
                <Link href="/admin/leave-policy-corrections/1" class="btn btn-secondary">السياسة المعتمدة 15 / 21 / 30 يومًا</Link>
            </PageHeader>
            <p class="alert-warning">هذه خيارات تحتاج اعتماد الجمعية. ترك الخيارات فارغة وعدم تفعيل الطلبات يمنع أي احتساب أو صرف غير معتمد.</p>
            <p v-if="!launched" class="alert-warning">سياسة الإجازات لم تُفعّل بعد؛ لن يسمح النظام بتشغيل الطلبات.</p>

            <form class="card space-y-4" @submit.prevent="submit">
                <fieldset :disabled="!canConfigure" class="space-y-4">
                    <div>
                        <label for="counting-mode" class="field-label">طريقة احتساب أيام الإجازة</label>
                        <select id="counting-mode" v-model="form.counting_mode" class="field" required><option value="">بانتظار الاعتماد</option><option value="calendar">الأيام التقويمية (تشمل نهاية الأسبوع)</option></select>
                    </div>
                    <fieldset v-if="form.counting_mode === 'working'" class="rounded-xl border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold">أيام العمل الرسمية</legend>
                        <div class="flex flex-wrap gap-3"><label v-for="(day, index) in weekdays" :key="index" class="flex items-center gap-2 text-sm"><input v-model="form.working_weekdays" type="checkbox" :value="index + 1" />{{ day }}</label></div>
                    </fieldset>
                    <fieldset class="space-y-3 rounded-xl border border-slate-200 p-4">
                        <legend class="px-2 text-sm font-semibold text-navy-800">مسار الاعتماد</legend>
                        <label v-for="(label, key) in approvalModes" :key="key" class="flex items-start gap-3 rounded-lg p-2 text-sm" :class="form.approval_mode === key ? 'bg-navy-50' : ''">
                            <input v-model="form.approval_mode" type="radio" name="approval_mode" :value="key" class="mt-1" required />
                            <span>{{ label }}<span v-if="key === 'workflow'" class="mt-1 block text-xs text-slate-500">يتبع مركزية قرار المدير التنفيذي. يصبح رئيس الموارد البشرية معتمدًا نهائيًا لإجازات الموظفين فقط بتفويض رسمي يصدره المدير التنفيذي من «التفويضات الإدارية». لا يعتمد أحد طلبه بنفسه.</span></span>
                        </label>
                        <div v-if="form.approval_mode === 'workflow'" class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="employee-workflow" class="field-label">مسار إجازات الموظفين</label>
                                <select id="employee-workflow" v-model="form.employee_workflow_code" class="field" required>
                                    <option value="" disabled>اختر مسارًا منشورًا</option>
                                    <option v-for="item in publishedWorkflows" :key="item.code" :value="item.code">{{ item.name }}</option>
                                </select>
                                <p v-if="form.errors.employee_workflow_code" class="field-error">{{ form.errors.employee_workflow_code }}</p>
                            </div>
                            <div>
                                <label for="head-workflow" class="field-label">مسار إجازات رؤساء الأقسام</label>
                                <select id="head-workflow" v-model="form.head_workflow_code" class="field" required>
                                    <option value="" disabled>اختر مسارًا منشورًا</option>
                                    <option v-for="item in publishedWorkflows" :key="item.code" :value="item.code">{{ item.name }}</option>
                                </select>
                                <p v-if="form.errors.head_workflow_code" class="field-error">{{ form.errors.head_workflow_code }}</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="executive-workflow" class="field-label">مسار إجازة المدير التنفيذي (بقرار مجلس الإدارة)</label>
                                <select id="executive-workflow" v-model="form.executive_workflow_code" class="field" required>
                                    <option value="" disabled>اختر مسارًا منشورًا</option>
                                    <option v-for="item in publishedWorkflows" :key="item.code" :value="item.code">{{ item.name }}</option>
                                </select>
                                <p class="mt-1 text-xs text-slate-500">يسجّل قرار المجلس في النظام من يحمل صفة «أمين سر مجلس الإدارة»، مع رقم القرار وتاريخه.</p>
                                <p v-if="form.errors.executive_workflow_code" class="field-error">{{ form.errors.executive_workflow_code }}</p>
                            </div>
                            <p v-if="!publishedWorkflows.length" class="alert-warning text-xs sm:col-span-2">لا يوجد مسار إجازات منشور بعد. راجعه وانشره من <Link :href="workflowsUrl" class="font-semibold underline">مسارات العمل</Link>.</p>
                            <p class="text-xs text-slate-500 sm:col-span-2">تُعدَّل مراحل كل مسار وأسماؤها ومهلها من <Link :href="workflowsUrl" class="font-semibold text-blue-700 hover:underline">مسارات العمل</Link>، وتسري على الطلبات الجديدة فقط. التفويض من <Link :href="delegationsUrl" class="font-semibold text-blue-700 hover:underline">التفويضات الإدارية</Link>.</p>
                        </div>
                        <p v-if="modeChanged && openRequests" class="alert-warning text-xs">توجد {{ openRequests }} طلبات قيد المعالجة؛ لا يمكن تغيير مسار الاعتماد قبل إنهائها أو إلغائها.</p>
                        <p v-if="form.errors.approval_mode" class="field-error">{{ form.errors.approval_mode }}</p>
                    </fieldset>
                    <div>
                        <label for="grant-mode" class="field-label">طريقة إثبات الرصيد السنوي</label>
                        <select id="grant-mode" v-model="form.grant_mode" class="field"><option value="">غير معتمدة؛ لا إثبات سنوي</option><option value="full_year">دفعة سنوية كاملة بإجراء إداري صريح</option></select>
                    </div>
                    <label class="flex items-center gap-3 text-sm"><input v-model="form.requests_enabled" type="checkbox" :disabled="!launched" /> السماح بتقديم الطلبات (بعد تفعيل السياسة فقط)</label>
                    <button v-if="canConfigure" type="submit" :disabled="form.processing" class="btn btn-primary">حفظ الإعدادات</button>
                </fieldset>
                <p v-if="!canConfigure" class="text-xs text-slate-500">تُعرض للاطلاع؛ تعديل الإعدادات من صلاحية مدير النظام.</p>
            </form>

            <section class="card space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="card-title">الاحتساب النسبي لمن تقل خدمتهم عن الشريحة الأولى</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-500">يُعرض للموظف استحقاق تقديري فقط بعد اعتماد المدير التنفيذي للمعدل وشروط الأهلية والتقريب وفق لائحة الجمعية. لا يُضاف أي يوم إلى الرصيد تلقائيًا.</p>
                    </div>
                    <StatusBadge :label="(prorataStates[prorata.status] || prorataStates.not_defined)[0]" :tone="(prorataStates[prorata.status] || prorataStates.not_defined)[1]" />
                </div>
                <p v-if="prorata.proposedBy" class="text-xs text-slate-500">اقترحها: {{ prorata.proposedBy }} · {{ date(prorata.proposedAt) }}<span v-if="prorata.approvedBy"> — اعتمدها: {{ prorata.approvedBy }} · {{ date(prorata.approvedAt) }}</span></p>

                <form class="space-y-3" @submit.prevent="proposeProrata">
                    <fieldset :disabled="!canConfigure" class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="prorata-rate" class="field-label">المعدل (يوم لكل شهر خدمة)</label>
                            <input id="prorata-rate" v-model="prorataForm.prorata_monthly_rate_days" type="number" min="0.001" max="31" step="0.001" required class="field" />
                        </div>
                        <div>
                            <label for="prorata-min" class="field-label">الأهلية: حد أدنى للخدمة (شهر)</label>
                            <input id="prorata-min" v-model="prorataForm.prorata_min_service_months" type="number" min="0" max="120" class="field" placeholder="بلا حد أدنى" />
                        </div>
                        <div>
                            <label for="prorata-rounding" class="field-label">التقريب</label>
                            <select id="prorata-rounding" v-model="prorataForm.prorata_rounding" required class="field">
                                <option value="" disabled>اختر</option>
                                <option v-for="(label, key) in prorata.roundingOptions" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <label for="prorata-notes" class="field-label">المرجع في لائحة الجمعية</label>
                            <textarea id="prorata-notes" v-model="prorataForm.prorata_notes" rows="2" required minlength="5" maxlength="2000" class="field" />
                        </div>
                    </fieldset>
                    <p v-for="(error, key) in prorataForm.errors" :key="key" class="field-error">{{ error }}</p>
                    <button v-if="canConfigure" type="submit" :disabled="prorataForm.processing" class="btn btn-secondary">حفظ وإرسال للمدير التنفيذي</button>
                </form>

                <div v-if="canDecideProrata && prorata.status === 'proposed'" class="rounded-xl border-2 border-gold-300 p-4">
                    <h3 class="font-bold text-navy-800">قرار المدير التنفيذي</h3>
                    <label for="prorata-decision-note" class="field-label mt-3">ملاحظة (إلزامية عند الإعادة)</label>
                    <textarea id="prorata-decision-note" v-model="decision.note" rows="2" maxlength="2000" class="field" />
                    <p v-for="(error, key) in decision.errors" :key="key" class="field-error">{{ error }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" :disabled="decision.processing" class="btn btn-primary" @click="decide('approve')">اعتماد السياسة</button>
                        <button type="button" :disabled="decision.processing" class="btn btn-secondary" @click="decide('return')">إعادة لمدير النظام</button>
                    </div>
                </div>
            </section>

            <section v-if="canConfigure" class="card space-y-3">
                <h2 class="card-title">العطل الرسمية</h2>
                <p class="text-sm text-slate-600">يُستثنى التاريخ المسجل من أيام الإجازة فقط في وضع «أيام العمل»، ويظهر في الحضور عطلةً رسمية. تُضاف العطل وتُحذف في أي وقت ويُعاد احتساب حضور ذلك اليوم، ما لم تشمل التاريخَ إجازةٌ تُحتسب بأيام العمل. لا توجد عطل مفترضة أو مستوردة تلقائيًا.</p>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="addHoliday">
                    <div><label for="holiday-date" class="field-label">التاريخ</label><input id="holiday-date" v-model="holiday.holiday_date" type="date" required class="field" /></div>
                    <div class="min-w-48 flex-1"><label for="holiday-name" class="field-label">اسم العطلة</label><input id="holiday-name" v-model="holiday.name_ar" required class="field" /></div>
                    <button type="submit" :disabled="holiday.processing" class="btn btn-primary">إضافة عطلة</button>
                </form>
                <p v-if="holiday.errors.holiday_date || holiday.errors.name_ar" class="field-error">{{ holiday.errors.holiday_date || holiday.errors.name_ar }}</p>
                <EmptyState v-if="!holidays?.length" title="لا توجد عطل مسجلة" />
                <table v-else class="data-table">
                    <thead><tr><th>التاريخ</th><th>العطلة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="item in holidays" :key="item.id">
                            <td dir="ltr" class="text-right">{{ item.holiday_date }}</td>
                            <td>{{ item.name_ar }}</td>
                            <td><button type="button" class="btn btn-danger btn-sm" @click="removeHoliday(item)">حذف</button></td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
