<script setup>
import ColumnPicker from '../../../Components/ColumnPicker.vue';
import ActiveFilters from '../../../Components/ActiveFilters.vue';
import RecordPreview from '../../../Components/RecordPreview.vue';
import CopyReference from '../../../Components/CopyReference.vue';
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    employees: { type: Object, default: () => ({ data: [], links: [], total: 0, from: null, to: null }) },
    filters: { type: Object, default: () => ({ search: '', department: 0, state: '' }) },
    departments: { type: Array, default: () => [] },
    smsEnabled: { type: Boolean, default: false },
    accountsWithoutProfile: { type: Array, default: () => [] },
    // manage: HR management; prepare: HR staff, whose saves wait for the HR head; review: the HR head.
    can: { type: Object, default: () => ({ manage: true, prepare: false, review: false }) },
    fileChanges: { type: Object, default: null },
});

const page = usePage();
const search = ref(props.filters.search || '');
const department = ref(props.filters.department || 0);
const state = ref(props.filters.state || '');
watch(() => props.filters, value => { search.value = value.search || ''; department.value = value.department || 0; state.value = value.state || ''; });
const states = { '': 'كل الملفات', review: 'وثائق أو صور أو أسماء تحتاج مراجعة', imported: 'مستوردة من ملف الموظفين', no_account: 'بلا حساب دخول', draft: 'مسودة (غير مكتملة)', name_review: 'تقسيم الاسم بانتظار المراجعة', documents_expiring: 'وثائق منتهية أو قريبة من الانتهاء', documents_missing: 'ينقصها وثائق إلزامية' };
const query = () => Object.fromEntries(Object.entries({ search: search.value.trim(), department: department.value || '', state: state.value }).filter(([, v]) => v !== '' && v !== 0));
const exportUrl = computed(() => `/admin/employee-profiles/export.csv?${new URLSearchParams(query()).toString()}`);
const displayName = employee => employee.full_name_ar || [employee.first_name_ar, employee.family_name_ar].filter(Boolean).join(' ');
const busy = ref(false);
const rows = computed(() => props.employees?.data ?? []);
const total = computed(() => props.employees?.total ?? 0);
const success = computed(() => page.props.flash?.success ?? '');
const statusLabels = {
    active: 'على رأس عمله',
    inactive: 'غير نشط',
    on_leave: 'في إجازة',
    terminated: 'انتهت الخدمة',
};

function performSearch() {
    busy.value = true;
    router.get('/admin/employee-profiles', query(), {
        preserveState: true,
        replace: true,
        onFinish: () => { busy.value = false; },
    });
}
function clearSearch() {
    search.value = '';
    department.value = 0;
    state.value = '';
    performSearch();
}
function statusTone(status) {
    return { active: 'success', terminated: 'danger', inactive: 'muted' }[status] || 'warning';
}
function accountState(employee) {
    if (!employee.user_id) return ['بلا حساب دخول', 'muted'];
    if (employee.account_active) return ['مفعّل', 'success'];
    return employee.onboarding_status === 'pending_activation' ? ['بانتظار التفعيل', 'warning'] : ['معطّل', 'muted'];
}
const hiddenColumns = ref([]);
const columns = [{"key":"number","label":"الرقم الوظيفي","required":true},{"key":"name","label":"اسم الموظف","required":true},{"key":"department","label":"القسم","required":false},{"key":"job","label":"المسمى الوظيفي","required":false},{"key":"status","label":"الحالة","required":false},{"key":"account","label":"حساب الدخول","required":false},{"key":"actions","label":"الإجراءات","required":true}];
const preview = ref(null);
const activeFilters = computed(() => [props.filters.search && { key: 'search', label: `البحث: ${props.filters.search}` }, props.filters.department && { key: 'department', label: `القسم: ${props.departments.find(d => String(d.id) === String(props.filters.department))?.name || props.filters.department}` }, props.filters.state && { key: 'state', label: states[props.filters.state] || props.filters.state }].filter(Boolean));
function removeFilter(key) { search.value = props.filters.search || ''; department.value = props.filters.department || 0; state.value = props.filters.state || ''; if (key === 'search') search.value = ''; if (key === 'department') department.value = 0; if (key === 'state') state.value = ''; performSearch(); }
function clearFilters() { search.value = ''; department.value = 0; state.value = ''; performSearch(); }
watch(() => props.employees, () => { preview.value = null; });
</script>

<template>
    <AppLayout title="ملفات الموظفين">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="الموارد البشرية" title="ملفات الموظفين الشاملة" description="إدارة بيانات الموظفين الشخصية والوظيفية. حسابات الدخول وصلاحياتها تُدار بشكل منفصل.">
                <Link href="/admin/employee-profiles/create"
                    class="btn btn-primary">
                    + إضافة ملف موظف
                </Link>
                <Link v-if="fileChanges" :href="fileChanges.url" class="btn btn-secondary">
                    {{ can.manage ? 'تعديلات بانتظار الاعتماد' : 'تعديلاتي المرسلة للاعتماد' }}
                    <span v-if="fileChanges.count" class="ms-1 rounded-full bg-gold-100 px-2 text-xs font-bold text-gold-900">{{ fileChanges.count }}</span>
                </Link>
                <template v-if="can.manage">
                    <Link href="/admin/employee-imports" class="btn btn-secondary">استيراد الموظفين</Link>
                    <a :href="exportUrl" class="btn btn-secondary">تصدير القائمة (CSV)</a>
                </template>
            </PageHeader>
            <p v-if="can.prepare" class="alert-info leading-7">ما تضيفه أو تعدّله من ملفات يُرسل إلى رئيس الموارد البشرية للاعتماد، ولا يظهر في الملف قبل اعتماده.</p>

            <section class="grid gap-3 md:grid-cols-2" aria-label="ملخص ملفات الموظفين">
                <div class="card">
                    <p class="text-sm text-slate-600">إجمالي الملفات المطابقة</p>
                    <p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ total }}</p>
                </div>
                <div class="card">
                    <p class="text-sm text-slate-600">الملفات في الصفحة الحالية</p>
                    <p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ rows.length }}</p>
                </div>
            </section>

            <section class="card p-0">
                <form class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4" @submit.prevent="performSearch">
                    <label class="min-w-0 w-full flex-1 sm:min-w-56 text-sm font-semibold text-slate-700">
                        البحث عن موظف
                        <input v-model="search" type="search" autocomplete="off" placeholder="الرقم الوظيفي أو رقم البصمة أو الاسم أو رقم الهوية أو الجوال"
                            class="field mt-1.5 font-normal" />
                    </label>
                    <label class="min-w-44 text-sm font-semibold text-slate-700">
                        القسم
                        <select v-model="department" class="field mt-1.5 font-normal"><option :value="0">كل الأقسام</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                    </label>
                    <label class="min-w-52 text-sm font-semibold text-slate-700">
                        حالة الملف
                        <select v-model="state" class="field mt-1.5 font-normal"><option v-for="(label, key) in states" :key="key" :value="key">{{ label }}</option></select>
                    </label>
                    <button type="submit" :disabled="busy" class="btn btn-primary">{{ busy ? 'جارٍ البحث...' : 'بحث' }}</button>
                    <button v-if="search || filters.search || filters.department || filters.state" type="button" @click="clearSearch" class="btn btn-secondary">مسح</button>
                </form>
<div class="space-y-3 p-4"><ActiveFilters :items="activeFilters" :count="total" @remove="removeFilter" @clear="clearFilters" /><ColumnPicker v-model="hiddenColumns" table="employee-profiles" :columns="columns" /><p class="mt-2 text-xs leading-6 text-slate-600 sm:hidden">على الهاتف تظهر البيانات الأساسية؛ استخدم «معاينة» لعرض بقية التفاصيل.</p></div>
                <p v-if="can.manage" class="border-b border-slate-100 px-4 py-2 text-xs text-slate-500">التصدير يشمل البيانات الوظيفية والحالة فقط؛ لا يُصدَّر رقم الهوية ولا الهواتف ولا العناوين ولا الرواتب ولا الحسابات البنكية، ويُسجل كل تصدير في سجل التدقيق.</p>

                <div class="overflow-x-auto p-4">
                    <table role="table" v-if="rows.length" class="aims-responsive-table data-table min-w-[750px]">
                        <thead>
                            <tr>
                                <th scope="col">الرقم الوظيفي</th>
                                <th scope="col">اسم الموظف</th>
                                <th scope="col" v-if="!hiddenColumns.includes('department')">القسم</th>
                                <th scope="col" v-if="!hiddenColumns.includes('job')">المسمى الوظيفي</th>
                                <th scope="col" v-if="!hiddenColumns.includes('status')">الحالة</th>
                                <th scope="col" v-if="!hiddenColumns.includes('account')">حساب الدخول</th>
                                <th scope="col">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="employee in rows" :key="employee.id">
                                <td data-label="الرقم الوظيفي"><CopyReference :value="employee.employee_number" label="الرقم الوظيفي" /></td>
                                <td data-label="اسم الموظف" class="font-semibold text-navy-800">
                                    {{ displayName(employee) }}
                                    <span v-if="employee.attendance_number" class="ms-1 font-mono text-xs font-normal text-slate-500" dir="ltr">({{ employee.attendance_number }})</span>
                                    <StatusBadge v-if="employee.import_batch_id" class="ms-1" label="مستورد" tone="info" />
                                    <StatusBadge v-if="employee.name_review_status === 'suggested'" class="ms-1" label="الاسم بانتظار المراجعة" tone="warning" />
                                </td>
                                <td data-label="القسم" data-mobile-secondary="true" v-if="!hiddenColumns.includes('department')" class="text-slate-700">{{ employee.department_name || (employee.department_source ? `«${employee.department_source}» (غير مربوط)` : 'غير محدد') }}</td>
                                <td data-label="المسمى الوظيفي" data-mobile-secondary="true" v-if="!hiddenColumns.includes('job')" class="text-slate-700">{{ employee.job_title_name || (employee.job_title_source ? `«${employee.job_title_source}»` : 'غير محدد') }}</td>
                                <td data-label="الحالة" v-if="!hiddenColumns.includes('status')"><StatusBadge :label="statusLabels[employee.employment_status] || employee.employment_status" :tone="statusTone(employee.employment_status)" /></td>
                                <td data-label="حساب الدخول" data-mobile-secondary="true" v-if="!hiddenColumns.includes('account')"><StatusBadge :label="accountState(employee)[0]" :tone="accountState(employee)[1]" /></td>
                                <td data-label="الإجراءات"><div class="flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-secondary" @click="preview = employee" :aria-label="`معاينة: ${displayName(employee)}`">معاينة</button>
                                    <Link :href="`/admin/employee-profiles/${employee.id}`" class="btn btn-sm btn-secondary">عرض</Link>
                                    <Link :href="`/admin/employee-profiles/${employee.id}/edit`" class="btn btn-sm btn-secondary">تعديل</Link>
                                </div></td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState v-else :title="filters.search ? 'لا توجد نتائج تطابق البحث' : 'لا توجد ملفات موظفين بعد'" :description="filters.search ? 'جرّب الرقم الوظيفي أو جزءًا من الاسم.' : 'أضف ملف الموظف أولًا؛ طلبات الإجازة تحتاج ملفًا نشطًا مرتبطًا بحساب الدخول.'">
                        <Link v-if="!filters.search" href="/admin/employee-profiles/create" class="btn btn-primary">+ إضافة ملف موظف</Link>
                    </EmptyState>
                </div>

                <footer v-if="employees.links?.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-sm">
                    <p class="text-slate-600">عرض {{ employees.from ?? 0 }}–{{ employees.to ?? 0 }} من {{ total }}</p>
                    <nav aria-label="صفحات النتائج" class="flex flex-wrap gap-1">
                        <template v-for="(link, index) in employees.links" :key="index">
                            <Link v-if="link.url" :href="link.url" preserve-scroll :aria-current="link.active ? 'page' : undefined"
                                :class="['rounded-lg border px-3 py-2', link.active ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-200 text-slate-700 hover:bg-slate-50']"
                                v-html="link.label" />
                            <span v-else class="rounded-lg border border-slate-100 px-3 py-2 text-slate-400" v-html="link.label" />
                        </template>
                    </nav>
                </footer>
            </section>
            <section v-if="accountsWithoutProfile.length" class="card border-gold-200">
                <h2 class="card-title">حسابات دخول بلا ملف موظف ({{ accountsWithoutProfile.length }})</h2>
                <p class="mt-1 text-sm text-slate-600">هؤلاء يدخلون النظام لكن لا ملف موظف لهم، فلا تظهر أرصدتهم ولا يستطيعون طلب إجازة. أكمل ملف كل منهم؛ يُربط الملف بحسابه دون تغيير كلمة مروره.</p>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    <li v-for="account in accountsWithoutProfile" :key="account.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><strong class="text-navy-800">{{ account.name }}</strong> <span class="font-mono text-slate-500" dir="ltr">{{ account.employee_number }}</span> <span class="text-slate-500">· {{ account.department_name || 'بلا قسم' }}</span></span>
                        <Link v-if="account.profile_id" :href="`/admin/employee-profiles/${account.profile_id}`" class="btn btn-sm btn-secondary">ربط بالملف القائم</Link>
                        <Link v-else :href="`/admin/employee-profiles/create?account=${account.id}`" class="btn btn-sm btn-secondary">إكمال الملف</Link>
                    </li>
                </ul>
            </section>

            <p class="alert-info leading-7">
                عند اعتماد ملف موظف جديد يُنشأ له حساب دخول برقمه الوظيفي، ويصله رمز التفعيل برسالة SMS على الجوال الأساسي ليختار كلمة مروره بنفسه.
                <template v-if="!smsEnabled"> خدمة SMS معطّلة حاليًا، فتبقى الحسابات الجديدة بانتظار التفعيل حتى تشغيلها.</template>
            </p>
        </div>
    <RecordPreview :title="preview ? displayName(preview) : ''" :fields="preview ? [{ label: 'الرقم الوظيفي', value: preview.employee_number }, { label: 'القسم', value: preview.department_name || preview.department_source }, { label: 'المسمى الوظيفي', value: preview.job_title_name || preview.job_title_source }, { label: 'الحالة', value: statusLabels[preview.employment_status] || preview.employment_status }, { label: 'حساب الدخول', value: accountState(preview)[0] }] : []" :href="preview ? `/admin/employee-profiles/${preview.id}` : ''" @close="preview = null" />
    </AppLayout>
</template>
