<script setup>
import ColumnPicker from '../../../Components/ColumnPicker.vue';
import ActiveFilters from '../../../Components/ActiveFilters.vue';
import RecordPreview from '../../../Components/RecordPreview.vue';
import CopyReference from '../../../Components/CopyReference.vue';
import { confirmRecordAction } from '../../../Support/actionConfirmation.js';
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, required: true },
    navigation: { type: Array, default: () => [] },
    employees: { type: Object, default: () => ({ data: [], links: [] }) },
    departments: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ q: '', status: '' }) },
    // The system administrator designates who records board of directors' decisions.
    canDesignateBoard: { type: Boolean, default: false },
    // Extra assignments beside the main role (e.g. head of a second department): system administrator only.
    canManageAssignments: { type: Boolean, default: false },
    assignableRoles: { type: Array, default: () => [] },
});

const assignment = useForm({ role_id: '', department_id: '', reason: '' });
const accountTarget = ref(null);
const accountAction = ref('password');
const showPassword = ref(false);
const accountForm = useForm({ password: '', password_confirmation: '', confirm: false });

function openAccountAction(employee, action) {
    accountForm.reset();
    accountForm.clearErrors();
    showPassword.value = false;
    accountAction.value = action;
    accountTarget.value = employee;
}

function closeAccountAction() {
    if (accountForm.processing) return;
    accountTarget.value = null;
    showPassword.value = false;
    accountForm.reset();
    accountForm.clearErrors();
}

function submitAccountAction() {
    if (accountForm.processing || !accountTarget.value) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            accountTarget.value = null;
            showPassword.value = false;
            accountForm.reset();
            accountForm.clearErrors();
        },
    };
    if (accountAction.value === 'password') {
        accountForm.transform(data => ({ password: data.password, password_confirmation: data.password_confirmation }))
            .put(`/admin/employees/${accountTarget.value.id}/password`, options);
    } else {
        accountForm.transform(data => ({ confirm: data.confirm }))
            .delete(`/admin/employees/${accountTarget.value.id}`, options);
    }
}
const removal = ref({ id: null, reason: '' });
const removalError = ref('');
const removingAssignment = ref(false);

function refreshEditing() {
    editing.value = (props.employees?.data ?? []).find(item => item.id === editing.value?.id) ?? editing.value;
}

function addAssignment() {
    assignment.post(`/admin/employees/${editing.value.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => { assignment.reset(); refreshEditing(); },
    });
}

function removeAssignment(item) {
    if (removingAssignment.value) return;
    if (!confirmRecordAction('إزالة تكليف إضافي', `${editing.value.name} — ${editing.value.employee_number}`, `سيُزال تكليف «${item.role}» في «${item.department}».\nستُراجع صلاحيات الموظف المرتبطة بهذا التكليف وفق قواعد النظام.\nالسبب: ${removal.value.reason}`)) return;
    removalError.value = '';
    removingAssignment.value = true;
    router.delete(`/admin/employees/${editing.value.id}/assignments/${item.id}`, {
        data: { reason: removal.value.reason },
        preserveScroll: true,
        onSuccess: () => { removal.value = { id: null, reason: '' }; refreshEditing(); },
        onError: errors => { removalError.value = Object.values(errors).join(' '); },
        onFinish: () => { removingAssignment.value = false; },
    });
}

const search = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.status ?? '');
watch(() => [props.filters.q, props.filters.status], ([q, status]) => {
    search.value = q ?? '';
    statusFilter.value = status ?? '';
});
const modalOpen = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    email: '',
    employee_number: '',
    department_id: '',
    role_id: '',
    password: '',
    password_confirmation: '',
    is_active: true,
    make_department_head: false,
    board_secretary: false,
});

const records = computed(() => props.employees?.data ?? []);
const isEditing = computed(() => editing.value !== null);
const hasDepartments = computed(() => props.departments.length > 0);
const hasRoles = computed(() => props.roles.length > 0);
const selectedRole = computed(() => props.roles.find(role => String(role.id) === String(form.role_id)) ?? null);
const selectedDepartment = computed(() => props.departments.find(department => String(department.id) === String(form.department_id)) ?? null);
const choosingHead = computed(() => selectedRole.value?.code === 'department_head');
const alreadyHead = computed(() => isEditing.value && Number(selectedDepartment.value?.head_user_id) === Number(editing.value.id));
// Only a department without a head can get one from this form; replacing a head is done on the departments page.
const canAppointHead = computed(() => choosingHead.value && Boolean(selectedDepartment.value) && !selectedDepartment.value.head_user_id);

function applyFilters() {
    router.get('/admin/employees', { ...(search.value.trim() ? { q: search.value.trim() } : {}), ...(statusFilter.value ? { status: statusFilter.value } : {}) }, { preserveState: true, replace: true });
}

function openCreate() {
    form.reset();
    form.clearErrors();
    editing.value = null;
    modalOpen.value = true;
}

function openEdit(employee) {
    form.reset();
    form.clearErrors();
    editing.value = employee;
    form.name = employee.name ?? '';
    form.email = employee.email ?? '';
    form.employee_number = employee.employee_number ?? '';
    form.department_id = employee.department_id ?? '';
    form.role_id = employee.role_id ?? '';
    form.is_active = Boolean(employee.is_active);
    form.make_department_head = false;
    form.board_secretary = Boolean(employee.board_secretary);
    modalOpen.value = true;
}

function closeModal() {
    if (form.processing) return;
    modalOpen.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function submitForm() {
    if (form.processing) return;
    const options = { preserveScroll: true, onSuccess: () => closeModal() };
    const appointHead = canAppointHead.value && form.make_department_head;
    if (isEditing.value) {
        form.transform(data => ({
            name: data.name,
            email: data.email,
            employee_number: data.employee_number,
            department_id: data.department_id,
            role_id: data.role_id,
            is_active: data.is_active,
            make_department_head: appointHead,
            ...(props.canDesignateBoard ? { board_secretary: data.board_secretary } : {}),
        })).put(`/admin/employees/${editing.value.id}`, options);
    } else {
        form.transform(data => ({
            name: data.name,
            email: data.email,
            employee_number: data.employee_number,
            department_id: data.department_id,
            role_id: data.role_id,
            password: data.password,
            password_confirmation: data.password_confirmation,
            make_department_head: appointHead,
            ...(props.canDesignateBoard ? { board_secretary: data.board_secretary } : {}),
        })).post('/admin/employees', options);
    }
}

function selectRole() {
    form.make_department_head = canAppointHead.value;
}
const hiddenColumns = ref([]);
const columns = [{"key":"name","label":"الموظف","required":true},{"key":"number","label":"الرقم الوظيفي","required":true},{"key":"department","label":"القسم","required":false},{"key":"role","label":"الدور","required":false},{"key":"status","label":"الحالة","required":false},{"key":"actions","label":"الإجراءات","required":true}];
const preview = ref(null);
const activeFilters = computed(() => [props.filters.q && { key: 'q', label: `البحث: ${props.filters.q}` }, props.filters.status && { key: 'status', label: ({ active: 'نشط', inactive: 'معطّل', pending: 'بانتظار التفعيل' })[props.filters.status] || props.filters.status }].filter(Boolean));
function removeFilter(key) { search.value = props.filters.q || ''; statusFilter.value = props.filters.status || ''; if (key === 'q') search.value = ''; if (key === 'status') statusFilter.value = ''; applyFilters(); }
function clearFilters() { search.value = ''; statusFilter.value = ''; applyFilters(); }
watch(() => props.employees, () => { preview.value = null; });
</script>

<template>
    <AppLayout title="حسابات المستخدمين">
        <div class="mx-auto max-w-7xl space-y-5">
        <PageHeader eyebrow="الموارد البشرية" title="حسابات المستخدمين" description="حسابات الدخول للموظفين: القسم والدور الوظيفي وحالة الحساب. بيانات الموظف التفصيلية في «ملفات الموظفين».">
            <Link href="/admin/employee-profiles" class="btn btn-secondary">ملفات الموظفين</Link>
            <button type="button" :disabled="!hasDepartments || !hasRoles" class="btn btn-primary" @click="openCreate">+ إضافة حساب</button>
        </PageHeader>

        <div v-if="!hasDepartments || !hasRoles" class="alert-warning">
            لا يمكن إضافة حساب قبل توفر قسم نشط ودور وظيفي متاح.
        </div>
        <p class="alert-info text-sm leading-7">للموظف الجديد أضف «ملف موظف»: يُنشأ حسابه تلقائيًا ويفعّله الموظف برمز SMS. هذه الصفحة لتعديل الأدوار والأقسام، وللحسابات التي تحتاج كلمة مرور مؤقتة يغيّرها صاحبها عند أول دخول.</p>

        <section class="overflow-hidden card p-0">
            <form class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 p-5" @submit.prevent="applyFilters">
                <h2 class="card-title">قائمة الحسابات</h2>
                <div class="flex flex-wrap gap-2">
                    <input v-model="search" type="search" aria-label="البحث عن موظف" placeholder="الاسم أو الرقم الوظيفي أو البريد" class="field w-64 max-w-full" />
                    <select v-model="statusFilter" aria-label="تصفية الحالة" class="field w-auto" @change="applyFilters">
                        <option value="">جميع الحالات</option>
                        <option value="active">نشط</option>
                        <option value="pending">بانتظار التفعيل</option>
                        <option value="inactive">معطّل</option>
                    </select>
                    <button type="submit" class="btn btn-secondary">بحث</button>
                </div>
            </form>
<div class="space-y-3 p-4"><ActiveFilters :items="activeFilters" :count="employees.total ?? records.length" @remove="removeFilter" @clear="clearFilters" /><ColumnPicker v-model="hiddenColumns" table="employee-accounts" :columns="columns" /><p class="mt-2 text-xs leading-6 text-slate-600 sm:hidden">على الهاتف تظهر البيانات الأساسية؛ استخدم «معاينة» لعرض بقية التفاصيل.</p></div>
            <div class="overflow-x-auto">
                <table role="table" class="aims-responsive-table data-table min-w-[900px]">
                    <thead>
                        <tr>
                            <th scope="col">الموظف</th>
                            <th scope="col">الرقم الوظيفي</th>
                            <th scope="col" v-if="!hiddenColumns.includes('department')">القسم</th>
                            <th scope="col" v-if="!hiddenColumns.includes('role')">الدور</th>
                            <th scope="col" v-if="!hiddenColumns.includes('status')">الحالة</th>
                            <th scope="col">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="employee in records" :key="employee.id">
                            <td data-label="الموظف">
                                <div class="font-semibold">{{ employee.name }}</div>
                                <div class="mt-1 text-xs text-slate-500" dir="ltr">{{ employee.email }}</div>
                            </td>
                            <td data-label="الرقم الوظيفي"><CopyReference :value="employee.employee_number" label="الرقم الوظيفي" /></td>
                            <td data-label="القسم" data-mobile-secondary="true" v-if="!hiddenColumns.includes('department')">{{ employee.department_name ?? 'غير محدد' }}</td>
                            <td data-label="الدور" data-mobile-secondary="true" v-if="!hiddenColumns.includes('role')">
                                <span>{{ employee.role_name ?? 'غير محدد' }}</span>
                                <StatusBadge v-if="employee.heads_own_department" class="ms-1" label="رئيس القسم" tone="navy" />
                                <StatusBadge v-else-if="employee.role_code === 'department_head'" class="ms-1" label="غير معيّن رئيسًا للقسم" tone="warning" />
                                <StatusBadge v-if="employee.board_secretary" class="ms-1" label="أمين سر مجلس الإدارة" tone="info" />
                                <ul v-if="employee.assignments?.some(item => item.role_code !== 'board_secretary')" class="mt-1 space-y-0.5 text-xs text-slate-500">
                                    <li v-for="item in employee.assignments.filter(item => item.role_code !== 'board_secretary')" :key="item.id">
                                        + {{ item.role }} — {{ item.department }}<span v-if="item.heads_it" class="font-semibold text-navy-700"> (رئيس القسم)</span>
                                    </li>
                                </ul>
                            </td>
                            <td data-label="الحالة" v-if="!hiddenColumns.includes('status')">
                                <StatusBadge v-if="employee.pending_activation" label="بانتظار التفعيل" tone="warning" />
                                <StatusBadge v-else :status="employee.is_active ? 'active' : 'inactive'" />
                            </td>
                            <td data-label="الإجراءات">
 <button type="button" class="btn btn-sm btn-secondary" @click="preview = employee" :aria-label="`معاينة: ${employee.name}`">معاينة</button>
 <button v-if="employee.can_edit" type="button" class="btn btn-sm btn-secondary" @click="openEdit(employee)">تعديل</button>
 <button v-if="employee.can_reset_password" type="button" class="btn btn-sm btn-secondary" @click="openAccountAction(employee, 'password')">تغيير كلمة المرور</button>
 <button v-if="employee.can_delete" type="button" class="btn btn-sm btn-danger" @click="openAccountAction(employee, 'delete')">حذف الحساب</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="records.length === 0" class="m-4" title="لا توجد حسابات مطابقة" description="غيّر عبارة البحث أو الحالة." />
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 p-5 text-xs text-slate-500">
                <span>الإجمالي: {{ employees.total ?? records.length }} — الصفحة {{ employees.current_page ?? 1 }} من {{ employees.last_page ?? 1 }}</span>
                <div class="flex flex-wrap gap-2">
                    <template v-for="(link, index) in (employees.links ?? [])" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-scroll
                            :class="['rounded-lg border px-3 py-2', link.active ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-200 text-slate-700 hover:bg-slate-100']">
                            {{ index === 0 ? 'السابق' : index === employees.links.length - 1 ? 'التالي' : link.label }}
                        </Link>
                        <span v-else class="rounded-lg border border-slate-100 px-3 py-2 text-slate-300">
                            {{ index === 0 ? 'السابق' : index === employees.links.length - 1 ? 'التالي' : link.label }}
                        </span>
                    </template>
                </div>
            </div>
        </section>

        <div v-if="modalOpen" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-4">
            <section role="dialog" aria-modal="true" aria-labelledby="employee-modal-title"
                class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-6">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <h3 id="employee-modal-title" class="card-title text-xl">{{ isEditing ? 'تعديل حساب موظف' : 'إضافة حساب دخول' }}</h3>
                    <button type="button" :disabled="form.processing" aria-label="إغلاق" class="rounded-lg px-3 py-1 text-xl hover:bg-slate-100" @click="closeModal">×</button>
                </div>
                <form class="space-y-4" novalidate @submit.prevent="submitForm">
                    <p class="text-sm leading-6 text-slate-700">الحقول المعلّمة بـ * مطلوبة. البريد الإلكتروني اختياري.</p>
                    <div>
                        <label for="employee-name" class="field-label">اسم الموظف <span class="text-red-600">*</span></label>
                        <input id="employee-name" v-model="form.name" required maxlength="255" class="field" />
                        <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="employee-number" class="field-label">الرقم الوظيفي <span class="text-red-600">*</span></label>
                            <input id="employee-number" v-model.trim="form.employee_number" required maxlength="50" dir="ltr" autocomplete="off" aria-describedby="employee-number-hint" class="field text-left text-sm" />
                            <p id="employee-number-hint" class="mt-1 text-xs text-slate-600">اسم الدخول: أرقام وحروف إنجليزية دون مسافات.</p>
                            <p v-if="form.errors.employee_number" class="field-error">{{ form.errors.employee_number }}</p>
                        </div>
                        <div>
                            <label for="employee-email" class="field-label">البريد الإلكتروني (اختياري)</label>
                            <input id="employee-email" v-model.trim="form.email" type="email" maxlength="255" dir="ltr" class="field text-left text-sm" />
                            <p v-if="form.errors.email" class="field-error">{{ form.errors.email }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="employee-department" class="field-label">القسم <span class="text-red-600">*</span></label>
                            <select id="employee-department" v-model="form.department_id" required class="field" @change="selectRole">
                                <option value="" disabled>اختر القسم</option>
                                <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                            </select>
                            <p v-if="form.errors.department_id" class="field-error">{{ form.errors.department_id }}</p>
                        </div>
                        <div>
                            <label for="employee-role" class="field-label">الدور الوظيفي <span class="text-red-600">*</span></label>
                            <select id="employee-role" v-model="form.role_id" required class="field" @change="selectRole">
                                <option value="" disabled>اختر الدور</option>
                                <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                            </select>
                            <p v-if="selectedRole?.hint" class="mt-1 text-xs text-gold-800">{{ selectedRole.hint }}</p>
                            <p v-if="form.errors.role_id" class="field-error">{{ form.errors.role_id }}</p>
                        </div>
                    </div>
                    <div v-if="choosingHead && selectedDepartment" class="rounded-xl border border-blue-100 bg-blue-50 p-3 text-sm">
                        <p v-if="alreadyHead">هو رئيس قسم «{{ selectedDepartment.name }}» حاليًا.</p>
                        <label v-else-if="canAppointHead" class="flex items-start gap-2">
                            <input v-model="form.make_department_head" type="checkbox" class="mt-1" />
                            <span>تعيينه رئيسًا لقسم «{{ selectedDepartment.name }}». رئيس القسم هو من تصله طلبات موظفيه للمراجعة والتوصية.</span>
                        </label>
                        <p v-else>رئيس القسم الحالي: <strong>{{ selectedDepartment.head_name }}</strong>. يحمل الدور دون رئاسة القسم، ويُستبدل الرئيس من صفحة «الأقسام» عند الحاجة.</p>
                        <p v-if="form.errors.make_department_head" class="field-error">{{ form.errors.make_department_head }}</p>
                    </div>
                    <div v-if="canDesignateBoard" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
                        <label class="flex items-start gap-2">
                            <input v-model="form.board_secretary" type="checkbox" class="mt-1" />
                            <span><strong>أمين سر مجلس الإدارة</strong> — يسجّل في النظام قرارات المجلس، ومنها اعتماد إجازة المدير التنفيذي، مع رقم القرار وتاريخه. تُضاف هذه الصفة إلى دوره الأساسي ولا تغيّره.</span>
                        </label>
                        <p v-if="form.errors.board_secretary" class="field-error">{{ form.errors.board_secretary }}</p>
                    </div>
                    <template v-if="!isEditing">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="employee-password" class="field-label">كلمة المرور المؤقتة <span class="text-red-600">*</span></label>
                                <input id="employee-password" v-model="form.password" required type="password" minlength="10" maxlength="128" autocomplete="new-password" aria-describedby="employee-password-hint" class="field" />
                                <p id="employee-password-hint" class="mt-1 text-xs text-slate-600">من 10 إلى 128 حرفًا. يغيّرها الموظف عند أول دخول.</p>
                            </div>
                            <div>
                                <label for="employee-password-confirmation" class="field-label">تأكيد كلمة المرور <span class="text-red-600">*</span></label>
                                <input id="employee-password-confirmation" v-model="form.password_confirmation" required type="password" minlength="10" maxlength="128" autocomplete="new-password" class="field" />
                            </div>
                        </div>
                        <p class="text-xs text-slate-500">10 أحرف على الأقل. يُطلب من الموظف تغييرها عند أول دخول.</p>
                        <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
                        <p v-if="form.errors.password_confirmation" class="field-error">{{ form.errors.password_confirmation }}</p>
                    </template>
                    <p v-if="isEditing && editing.pending_activation" class="rounded-xl bg-gold-50 p-3 text-sm text-gold-900">
                        الحساب بانتظار التفعيل، ولا يُفعَّل من هنا. <Link href="/admin/employee-profiles" class="font-semibold underline">من ملف الموظف</Link>: أرسل رمز SMS، أو يفعّله مدير النظام مباشرة بكلمة مرور مؤقتة.
                    </p>
                    <label v-else-if="isEditing" class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4" />
                        الحساب نشط
                    </label>
                    <p v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</p>
                    <section v-if="isEditing" class="space-y-3 rounded-xl border border-slate-200 p-3 text-sm" aria-labelledby="extra-assignments-title">
                        <h4 id="extra-assignments-title" class="font-semibold text-navy-800">التكليفات الإضافية</h4>
                        <p class="text-xs leading-5 text-slate-500">تبقى كما هي عند حفظ التعديلات. منها رئاسة قسم آخر: أضف دور «رئيس قسم» في ذلك القسم هنا، ثم عيّنه رئيسًا من صفحة «الأقسام».</p>
                        <ul v-if="editing.assignments?.length" class="space-y-2">
                            <li v-for="item in editing.assignments" :key="item.id" class="rounded-lg bg-slate-50 p-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span>{{ item.role }} — {{ item.department }}<span v-if="item.heads_it" class="font-semibold text-navy-700"> (رئيس القسم)</span></span>
                                    <span v-if="item.managed_by" class="text-xs text-slate-500">يُدار من {{ item.managed_by }}</span>
                                    <button v-else-if="canManageAssignments && !item.heads_it && removal.id !== item.id" type="button" class="btn btn-sm btn-secondary" @click="removal = { id: item.id, reason: '' }">إزالة</button>
                                </div>
                                <div v-if="removal.id === item.id" class="mt-2 flex flex-wrap items-end gap-2">
                                    <label class="field-label flex-1">سبب الإزالة<input v-model="removal.reason" minlength="5" maxlength="500" class="field mt-1" @keydown.enter.prevent /></label>
                                    <button type="button" class="btn btn-sm btn-danger" :disabled="removingAssignment || removal.reason.trim().length < 5" @click="removeAssignment(item)">{{ removingAssignment ? 'جارٍ الإزالة…' : 'تأكيد الإزالة' }}</button>
                                    <button type="button" class="btn btn-sm btn-secondary" @click="removal = { id: null, reason: '' }">تراجع</button>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-xs text-slate-500">لا توجد تكليفات إضافية.</p>
                        <p v-if="removalError" class="field-error">{{ removalError }}</p>
                        <div v-if="canManageAssignments" class="grid gap-2 sm:grid-cols-3">
                            <select v-model="assignment.role_id" aria-label="الدور الإضافي" class="field">
                                <option value="" disabled>الدور</option>
                                <option v-for="role in assignableRoles" :key="role.id" :value="role.id">{{ role.name }}</option>
                            </select>
                            <select v-model="assignment.department_id" aria-label="قسم التكليف" class="field">
                                <option value="" disabled>القسم</option>
                                <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                            </select>
                            <input v-model="assignment.reason" aria-label="سبب التكليف" placeholder="سبب التكليف (يُسجل)" maxlength="500" class="field" @keydown.enter.prevent />
                            <button type="button" class="btn btn-sm btn-secondary sm:col-span-3" :disabled="assignment.processing || !assignment.role_id || !assignment.department_id || assignment.reason.trim().length < 5" @click="addAssignment">إضافة التكليف</button>
                            <p v-for="(message, key) in assignment.errors" :key="key" class="field-error sm:col-span-3">{{ message }}</p>
                        </div>
                    </section>
                    <div class="flex flex-wrap justify-end gap-3 pt-4">
                        <button type="button" :disabled="form.processing" class="btn btn-secondary" @click="closeModal">إلغاء</button>
                        <button type="submit" :disabled="form.processing || !hasDepartments || !hasRoles" class="btn btn-primary">
                            {{ form.processing ? 'جارٍ الحفظ...' : (isEditing ? 'حفظ التعديلات' : 'إضافة الحساب') }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
        </div>
    <RecordPreview :title="preview ? preview.name : ''" :fields="preview ? [{ label: 'الرقم الوظيفي', value: preview.employee_number }, { label: 'البريد الإلكتروني', value: preview.email }, { label: 'القسم', value: preview.department_name }, { label: 'الدور', value: preview.role_name }, { label: 'الحالة', value: preview.pending_activation ? 'بانتظار التفعيل' : preview.is_active ? 'نشط' : 'معطل' }] : []" :href="preview ? '' : ''" @close="preview = null" />
        <div v-if="accountTarget" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4" @click.self="closeAccountAction" @keydown.esc="closeAccountAction">
            <section role="dialog" aria-modal="true" aria-labelledby="account-action-title" class="card w-full max-w-lg space-y-4">
                <h2 id="account-action-title" class="card-title">{{ accountAction === 'password' ? 'تغيير كلمة المرور' : 'حذف حساب الدخول' }}</h2>
                <p class="text-sm font-semibold">{{ accountTarget.name }} — {{ accountTarget.employee_number }}</p>
                <form class="space-y-4" @submit.prevent="submitAccountAction">
                    <template v-if="accountAction === 'password'">
                        <p class="text-sm text-slate-600">كلمة المرور الحالية غير قابلة للعرض. أدخل كلمة مؤقتة جديدة وسلّمها لصاحب الحساب؛ سيُطلب منه تغييرها عند الدخول وستُنهي هذه العملية جلساته السابقة.</p>
                        <label class="block text-sm">كلمة المرور الجديدة
                            <input v-model="accountForm.password" :type="showPassword ? 'text' : 'password'" required minlength="10" maxlength="128" autocomplete="new-password" class="field mt-1" autofocus />
                        </label>
                        <label class="block text-sm">تأكيد كلمة المرور
                            <input v-model="accountForm.password_confirmation" :type="showPassword ? 'text' : 'password'" required minlength="10" maxlength="128" autocomplete="new-password" class="field mt-1" />
                        </label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="showPassword" type="checkbox" /> إظهار كلمة المرور الجديدة</label>
                        <p v-if="!accountTarget.is_active" class="text-sm text-gold-800">الحساب غير نشط؛ تغيير كلمة المرور لا يفعّله.</p>
                    </template>
                    <template v-else>
                        <p class="text-sm text-red-700">سيُحذف حساب الدخول نهائيًا وتُنهي جلساته. يبقى ملف الموظف وسجلاته محفوظة دون حساب مرتبط. الحساب المرتبط بمعاملات أو تكليفات قائمة يتطلب تسويتها أولًا.</p>
                        <label class="flex items-start gap-2 text-sm"><input v-model="accountForm.confirm" type="checkbox" required class="mt-1" /> أؤكد حذف حساب هذا المستخدم نهائيًا.</label>
                    </template>
                    <p v-for="(message, key) in accountForm.errors" :key="key" class="field-error" role="alert">{{ message }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-secondary" :disabled="accountForm.processing" @click="closeAccountAction">إلغاء</button>
                        <button type="submit" :class="['btn', accountAction === 'password' ? 'btn-primary' : 'btn-danger']" :disabled="accountForm.processing || (accountAction === 'delete' && !accountForm.confirm)">
                            {{ accountForm.processing ? 'جارٍ التنفيذ…' : accountAction === 'password' ? 'حفظ كلمة المرور' : 'حذف نهائي' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>

