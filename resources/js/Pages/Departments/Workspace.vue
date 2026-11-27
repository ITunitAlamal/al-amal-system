<script setup>
import { formatDate } from '../../Support/dates.js';
import { computed, reactive, ref } from 'vue';
import { Deferred, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import DelegationsPanel from '../../Components/Organization/DelegationsPanel.vue';
import DepartmentBudgetSummary from '../../Components/Finance/DepartmentBudgetSummary.vue';

const props = defineProps({
    departments: { type: Array, required: true },
    selected: { type: Number, default: null },
    scopeNames: { type: Array, required: true },
    counts: { type: Object, required: true },
    staff: { type: Array, required: true },
    leaves: { type: Array, required: true },
    requests: { type: Array, required: true },
    received: { type: Array, required: true },
    kpiUrl: { type: String, default: null },
    units: { type: Array, default: () => [] },
    delegations: { type: Array, default: () => [] },
    delegationOptions: { type: Object, default: () => ({ permissions: [], scopes: [] }) },
    departmentBudgets: { type: Object, default: undefined },
});

const tabs = computed(() => [
    { key: 'staff', label: 'الفريق' },
    ...(props.units.length ? [{ key: 'units', label: 'الوحدات' }] : []),
    { key: 'leaves', label: 'الإجازات' },
    { key: 'requests', label: 'طلبات القسم' },
    { key: 'received', label: 'المهام لدى القسم' },
    { key: 'delegation', label: 'التفويض' },
]);

// Staff distribution over the department's functional units.
const assignForms = reactive({});
const formFor = unit => (assignForms[unit.id] ||= { employee_id: '', role: 'member', starts_on: new Date().toLocaleDateString('en-CA'), reason: '' });
const placeable = (department, unit) => department.staff.filter(person => !unit.members.some(member => member.employee_id === person.id));
function assign(unit) {
    const data = formFor(unit);
    router.post('/my-department/units/assignments', { unit_id: unit.id, ...data }, { preserveScroll: true, onSuccess: () => { delete assignForms[unit.id]; } });
}
function endAssignment(unit, member) {
    const reason = window.prompt(`سبب إنهاء توزيع ${member.name} على «${unit.name}»:`);
    if (reason && reason.trim().length >= 3) {
        router.post(`/my-department/units/assignments/${member.id}/end`, { reason }, { preserveScroll: true });
    }
}
const tab = ref('staff');
const department = ref(props.selected ?? '');
const number = value => new Intl.NumberFormat('ar-u-nu-latn').format(value ?? 0);
const date = value => formatDate(value, { year: 'numeric', month: 'short', day: 'numeric' }, '—');
const summary = computed(() => [
    { label: 'أفراد الفريق', value: props.counts.staff, note: `${number(props.counts.active_staff)} حساب نشط` },
    { label: 'إجازات بانتظار البت', value: props.counts.pending_leaves, note: `${number(props.counts.on_leave_today)} في إجازة اليوم` },
    { label: 'طلبات القسم المفتوحة', value: props.counts.open_requests, note: `${number(props.counts.overdue_requests)} متأخرة`, danger: props.counts.overdue_requests > 0 },
    { label: 'مهام لدى القسم', value: props.counts.received, note: `${number(props.counts.received_overdue)} متأخرة`, danger: props.counts.received_overdue > 0 },
]);

function changeDepartment() {
    router.get('/my-department', department.value ? { department_id: department.value } : {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="قسمي">
        <div class="space-y-6">
            <PageHeader title="قسمي" eyebrow="رئيس القسم" :description="`الأقسام المعروضة: ${scopeNames.join('، ')}. تظهر هنا سجلات أقسامك ووحداتها التابعة فقط، دون بيانات شخصية حساسة.`">
                <Link v-if="kpiUrl" :href="kpiUrl" class="btn btn-secondary">مؤشرات القسم</Link>
            </PageHeader>

            <Deferred data="departmentBudgets">
                <template #fallback><div class="card h-28 motion-safe:animate-pulse bg-slate-50" role="status" aria-label="جارٍ تحميل ميزانية القسم"></div></template>
                <template #rescue><p class="alert-error" role="alert">تعذر تحميل ميزانية القسم. <button type="button" class="underline" @click="router.reload({ only: ['departmentBudgets'] })">أعد المحاولة</button></p></template>
                <DepartmentBudgetSummary :summary="departmentBudgets" />
            </Deferred>

            <label v-if="departments.length > 1" class="field-label block max-w-sm">القسم
                <select v-model="department" class="field mt-1 w-full" @change="changeDepartment">
                    <option value="">كل أقسامي</option>
                    <option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
            </label>

            <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="ملخص القسم">
                <div v-for="item in summary" :key="item.label" class="card p-4">
                    <p class="text-sm font-semibold text-navy-800">{{ item.label }}</p>
                    <p class="mt-2 font-display text-3xl font-bold" :class="item.danger ? 'text-red-700' : 'text-navy-800'">{{ number(item.value) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ item.note }}</p>
                </div>
            </section>

            <div class="flex flex-wrap gap-2 border-b border-slate-200" role="tablist" aria-label="أقسام الصفحة">
                <button v-for="item in tabs" :key="item.key" type="button" role="tab" :aria-selected="tab === item.key"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold" :class="tab === item.key ? 'border-navy-700 text-navy-800' : 'border-transparent text-slate-500 hover:text-navy-700'"
                    @click="tab = item.key">{{ item.label }}</button>
            </div>

            <section v-if="tab === 'staff'" class="card" role="tabpanel" aria-label="الفريق">
                <div v-if="staff.length" class="overflow-x-auto">
                    <table class="data-table w-full text-sm">
                        <thead><tr><th scope="col">الاسم</th><th scope="col">الرقم الوظيفي</th><th scope="col">القسم</th><th scope="col">المسمى</th><th scope="col">الأدوار</th><th scope="col">الحساب</th><th scope="col">الملف</th></tr></thead>
                        <tbody>
                            <tr v-for="person in staff" :key="person.key">
                                <td class="font-semibold text-navy-800">
                                    <Link v-if="person.profile_id" :href="`/admin/employee-profiles/${person.profile_id}`" class="hover:underline">{{ person.name }}</Link>
                                    <template v-else>{{ person.name }}</template>
                                    <StatusBadge v-if="person.on_leave_today" label="في إجازة اليوم" tone="warning" />
                                </td>
                                <td dir="ltr" class="text-right">{{ person.employee_number || '—' }}</td>
                                <td>{{ person.department }}</td>
                                <td>{{ person.job_title || '—' }}</td>
                                <td>{{ person.roles.join('، ') || '—' }}</td>
                                <td><StatusBadge :label="person.account" :tone="person.active ? 'success' : 'muted'" /></td>
                                <td><StatusBadge :label="person.profile" :tone="person.profile === 'مكتمل' ? 'success' : 'warning'" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="لا يوجد موظفون مسجلون في هذا النطاق" />
                <p class="mt-3 text-xs text-slate-500">تعديل الملفات والحسابات من صلاحيات الموارد البشرية؛ هذه القائمة للاطلاع فقط.</p>
            </section>

            <div v-if="tab === 'units'" class="space-y-4" role="tabpanel" aria-label="الوحدات">
                <section v-for="department in units" :key="department.id" class="card space-y-4">
                    <h2 class="card-title">{{ department.name }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div v-for="unit in department.units" :key="unit.id" class="space-y-3 rounded-xl border border-slate-200 p-3">
                            <h3 class="font-bold text-navy-800">{{ unit.name }} <StatusBadge v-if="!unit.active" label="معطّلة" tone="muted" /></h3>
                            <p v-if="unit.responsibilities" class="whitespace-pre-line text-xs text-slate-500">{{ unit.responsibilities }}</p>
                            <ul v-if="unit.members.length" class="space-y-1 text-sm">
                                <li v-for="member in unit.members" :key="member.id" class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ member.name }}</span><span class="text-xs text-slate-500" dir="ltr">{{ member.number }}</span>
                                    <StatusBadge v-if="member.role === 'coordinator'" label="منسق الوحدة" tone="info" />
                                    <span class="text-xs text-slate-500">منذ {{ member.since }}</span>
                                    <button type="button" class="ms-auto text-xs text-red-700 hover:underline" @click="endAssignment(unit, member)">إنهاء</button>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-slate-500">لا موظفين موزعين بعد.</p>
                            <form v-if="unit.active && placeable(department, unit).length" class="grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2" @submit.prevent="assign(unit)">
                                <select v-model="formFor(unit).employee_id" class="field text-sm sm:col-span-2" required :aria-label="`موظف لوحدة ${unit.name}`">
                                    <option value="">إضافة موظف من القسم</option>
                                    <option v-for="person in placeable(department, unit)" :key="person.id" :value="person.id">{{ person.name }} — {{ person.number }}</option>
                                </select>
                                <select v-model="formFor(unit).role" class="field text-sm" aria-label="الدور في الوحدة"><option value="member">عضو</option><option value="coordinator">منسق الوحدة</option></select>
                                <input v-model="formFor(unit).starts_on" type="date" class="field text-sm" aria-label="تاريخ البدء" />
                                <input v-model="formFor(unit).reason" class="field text-sm sm:col-span-2" maxlength="1000" placeholder="سبب أو ملاحظة (اختياري)" />
                                <button type="submit" class="btn btn-sm btn-primary sm:col-span-2">إضافة إلى الوحدة</button>
                            </form>
                        </div>
                    </div>
                    <details v-if="department.history.length" class="text-sm">
                        <summary class="cursor-pointer font-semibold text-navy-800">السجل السابق</summary>
                        <ul class="mt-2 space-y-1 text-xs text-slate-600">
                            <li v-for="item in department.history" :key="item.id">{{ item.name }} — {{ item.unit }} ({{ item.role === 'coordinator' ? 'منسق' : 'عضو' }}) من {{ item.from }} إلى {{ item.to }}{{ item.reason ? `: ${item.reason}` : '' }}</li>
                        </ul>
                    </details>
                    <p class="text-xs text-slate-500">التوزيع على الوحدات تنظيم للعمل داخل القسم؛ لا يمنح صلاحية ولا ينقل الموظف إلى قسم آخر. النقل بين الأقسام من الموارد البشرية في ملف الموظف.</p>
                </section>
            </div>

            <div v-if="tab === 'delegation'" role="tabpanel" aria-label="التفويض">
                <DelegationsPanel :delegations="delegations" :options="delegationOptions" />
            </div>

            <section v-if="tab === 'leaves'" class="card" role="tabpanel" aria-label="الإجازات">
                <div v-if="leaves.length" class="overflow-x-auto">
                    <table class="data-table w-full text-sm">
                        <thead><tr><th scope="col">الموظف</th><th scope="col">النوع</th><th scope="col">من</th><th scope="col">إلى</th><th scope="col">الأيام</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
                        <tbody>
                            <tr v-for="leave in leaves" :key="leave.key">
                                <td class="font-semibold text-navy-800">{{ leave.employee }}</td><td>{{ leave.type || '—' }}</td>
                                <td>{{ date(leave.from) }}</td><td>{{ date(leave.to) }}</td><td>{{ leave.days === null ? '—' : number(leave.days) }}</td>
                                <td><StatusBadge :label="leave.status" :tone="leave.pending ? 'info' : (leave.today ? 'warning' : 'success')" /></td>
                                <td><Link v-if="leave.url" :href="leave.url" class="text-xs font-semibold text-blue-700 hover:underline">فتح</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="لا توجد إجازات قيد البت أو قائمة خلال 30 يومًا" />
            </section>

            <section v-if="tab === 'requests' || tab === 'received'" class="card" role="tabpanel" :aria-label="tab === 'requests' ? 'طلبات القسم' : 'المهام لدى القسم'">
                <p class="mb-3 text-xs leading-5 text-slate-500">{{ tab === 'requests'
                    ? 'طلبات مقدمة من أقسامك ولم تُغلق بعد، أينما كانت الآن.'
                    : 'مراحل مفتوحة يتولاها الآن موظفو أقسامك، لطلبات من أي قسم؛ الإجراء متاح لصاحب المهمة فقط.' }}</p>
                <div v-if="(tab === 'requests' ? requests : received).length" class="overflow-x-auto">
                    <table class="data-table w-full text-sm">
                        <thead><tr><th scope="col">المعاملة</th><th scope="col">النوع</th><th scope="col">القسم الأصلي</th><th scope="col">المرحلة الحالية</th><th scope="col">لدى</th><th scope="col">قُدمت</th><th scope="col">المهلة</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
                        <tbody>
                            <tr v-for="item in (tab === 'requests' ? requests : received)" :key="item.key">
                                <td dir="ltr" class="text-right font-semibold text-navy-800">{{ item.reference || '—' }}</td>
                                <td>{{ item.workflow }}</td><td>{{ item.origin || '—' }}</td><td>{{ item.step || '—' }}</td><td>{{ item.holders || '—' }}</td>
                                <td>{{ date(item.started_at) }}</td>
                                <td><StatusBadge v-if="item.overdue" label="متأخرة" tone="danger" /><span v-else>{{ item.due_at ? date(item.due_at) : 'بلا مهلة' }}</span></td>
                                <td><Link v-if="item.url" :href="item.url" class="text-xs font-semibold text-blue-700 hover:underline">فتح</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else :title="tab === 'requests' ? 'لا توجد طلبات مفتوحة من أقسامك' : 'لا توجد مهام مفتوحة لدى موظفي أقسامك'" />
                <p class="mt-3 text-xs text-slate-500">يظهر رابط «فتح» للمعاملات التي تملك صلاحية عرضها فقط.</p>
            </section>
        </div>
    </AppLayout>
</template>
