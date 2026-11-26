<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: {
        type: String,
        required: true,
    },
    departments: {
        type: Array,
        default: () => [],
    },
    navigation: {
        type: Array,
        default: () => [],
    },
    eligibleHeads: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const search = ref('');
const statusFilter = ref('all');
const modalOpen = ref(false);
const editingId = ref(null);
const toggleProcessing = ref(false);

const form = useForm({
    code: '',
    name: '',
    parent_id: '',
    head_user_id: '',
});

const successMessage = computed(
    () => page.props.flash?.success || ''
);

const operationError = computed(
    () => page.props.errors?.department || ''
);

const activeCount = computed(() =>
    props.departments.filter(d => d.is_active).length
);

const inactiveCount = computed(() =>
    props.departments.filter(d => !d.is_active).length
);

const filteredDepartments = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.departments.filter(department => {
        const matchesSearch =
            department.name.toLowerCase().includes(term) ||
            department.code.toLowerCase().includes(term) ||
            (department.parent_name || '')
                .toLowerCase()
                .includes(term) ||
            (department.head_name || '')
                .toLowerCase()
                .includes(term);

        const matchesStatus =
            statusFilter.value === 'all' ||
            (statusFilter.value === 'active' &&
                department.is_active) ||
            (statusFilter.value === 'inactive' &&
                !department.is_active);

        return matchesSearch && matchesStatus;
    });
});

const headOptions = computed(() =>
    props.eligibleHeads.filter(user =>
        Number(user.department_id) === Number(editingId.value)
    )
);

// Active departments, plus the edited department's current parent even if it was deactivated,
// so saving a name change never silently detaches the department from its parent.
const currentParentId = computed(() =>
    props.departments.find(department => department.id === editingId.value)?.parent_id ?? null
);
const parentOptions = computed(() =>
    props.departments.filter(department =>
        (department.is_active || department.id === currentParentId.value) &&
        department.id !== editingId.value
    )
);

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(department) {
    editingId.value = department.id;

    form.clearErrors();

    form.code = department.code;
    form.name = department.name;
    form.parent_id = department.parent_id ?? '';
    form.head_user_id = department.head_user_id ?? '';

    modalOpen.value = true;
}

function closeModal() {
    if (form.processing) return;

    modalOpen.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submitForm() {
    const options = {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    };

    if (editingId.value !== null) {
        form.transform(data => ({
            code: data.code,
            name: data.name,
            parent_id: data.parent_id || null,
            head_user_id: data.head_user_id || null,
        })).put(
            `/admin/departments/${editingId.value}`,
            options
        );
    } else {
        // لا نرسل head_user_id عند الإنشاء؛ يمنعه Controller.
        form.transform(data => ({
            code: data.code,
            name: data.name,
            parent_id: data.parent_id || null,
        })).post('/admin/departments', options);
    }
}

function toggleDepartment(department) {
    if (toggleProcessing.value) return;

    const action = department.is_active
        ? 'تعطيل'
        : 'تفعيل';

    const confirmed = window.confirm(
        `هل تريد ${action} قسم "${department.name}"؟`
    );

    if (!confirmed) return;

    toggleProcessing.value = true;

    router.patch(
        `/admin/departments/${department.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggleProcessing.value = false;
            },
        }
    );
}
</script>

<template>
    <AppLayout title="الأقسام والإدارات">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="الهيكل التنظيمي" title="الأقسام والإدارات" description="أقسام جمعية معهد الأمل للأيتام وتبعيتها ورؤساؤها. رئيس القسم يستقبل طلبات موظفيه في مسارات العمل.">
                <button type="button" class="btn btn-primary" @click="openCreate">+ إضافة قسم جديد</button>
            </PageHeader>

            <p v-if="operationError" role="alert" class="alert-error">{{ operationError }}</p>

            <section class="grid gap-3 sm:grid-cols-3" aria-label="ملخص الأقسام">
                <div class="card"><p class="text-sm text-slate-500">إجمالي الأقسام</p><p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ departments.length }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">الأقسام النشطة</p><p class="mt-2 font-display text-3xl font-bold text-emerald-700">{{ activeCount }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">الأقسام غير النشطة</p><p class="mt-2 font-display text-3xl font-bold text-gold-700">{{ inactiveCount }}</p></div>
            </section>

            <section class="card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                    <h2 class="card-title">سجل الأقسام</h2>
                    <div class="flex flex-wrap gap-2">
                        <input v-model="search" type="search" placeholder="بحث بالاسم أو الرمز..." aria-label="البحث عن قسم" class="field w-64 max-w-full" />
                        <select v-model="statusFilter" aria-label="تصفية حسب الحالة" class="field w-auto">
                            <option value="all">جميع الحالات</option>
                            <option value="active">نشط</option>
                            <option value="inactive">غير نشط</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto p-4">
                    <table v-if="filteredDepartments.length" class="data-table min-w-[860px]">
                        <thead><tr><th>الرمز</th><th>القسم</th><th>القسم الرئيسي</th><th>رئيس القسم</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
                        <tbody>
                            <tr v-for="department in filteredDepartments" :key="department.id">
                                <td class="font-mono text-xs text-slate-500" dir="ltr">{{ department.code }}</td>
                                <td class="font-semibold text-navy-800">{{ department.name }}</td>
                                <td class="text-slate-500">{{ department.parent_name || 'قسم رئيسي' }}</td>
                                <td>
                                    <span v-if="department.head_name" class="text-slate-700">{{ department.head_name }}</span>
                                    <StatusBadge v-else label="لم يُعيّن" tone="warning" />
                                </td>
                                <td><StatusBadge :status="department.is_active ? 'active' : 'inactive'" /></td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" class="btn btn-sm btn-secondary" @click="openEdit(department)">تعديل</button>
                                        <button type="button" :disabled="toggleProcessing" :class="['btn btn-sm', department.is_active ? 'btn-danger' : 'btn-secondary text-emerald-700']" @click="toggleDepartment(department)">
                                            {{ department.is_active ? 'تعطيل' : 'تفعيل' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState v-else title="لا توجد أقسام مطابقة" description="غيّر عبارة البحث أو الحالة." />
                </div>
                <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">عدد النتائج: {{ filteredDepartments.length }}</p>
            </section>

            <div v-if="modalOpen" class="fixed inset-0 z-[100] flex items-center justify-center bg-navy-950/60 p-4">
                <section role="dialog" aria-modal="true" aria-labelledby="department-modal-title" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
                    <div class="mb-5 flex items-center justify-between">
                        <h2 id="department-modal-title" class="card-title text-xl">{{ editingId !== null ? 'تعديل بيانات القسم' : 'إضافة قسم جديد' }}</h2>
                        <button type="button" :disabled="form.processing" aria-label="إغلاق" class="rounded-lg px-3 py-1 text-xl text-slate-500 hover:bg-slate-100" @click="closeModal">×</button>
                    </div>

                    <form class="space-y-4" @submit.prevent="submitForm">
                        <div>
                            <label for="department-code" class="field-label">رمز القسم *</label>
                            <input id="department-code" v-model.trim="form.code" type="text" maxlength="50" required dir="ltr" autocomplete="off" class="field text-left" placeholder="PR" />
                            <p class="mt-1 text-xs text-slate-500">رمز قصير بحروف إنجليزية وأرقام دون مسافات، مثل HR أو FIN.</p>
                            <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label for="department-name" class="field-label">اسم القسم *</label>
                            <input id="department-name" v-model="form.name" type="text" maxlength="255" required class="field" placeholder="اسم القسم" />
                            <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label for="department-parent" class="field-label">القسم الرئيسي</label>
                            <select id="department-parent" v-model="form.parent_id" class="field">
                                <option value="">قسم رئيسي مستقل</option>
                                <option v-for="department in parentOptions" :key="department.id" :value="department.id">{{ department.name }}{{ department.is_active ? '' : ' (غير نشط)' }}</option>
                            </select>
                            <p v-if="form.errors.parent_id" class="field-error">{{ form.errors.parent_id }}</p>
                        </div>
                        <div v-if="editingId !== null">
                            <label for="department-head" class="field-label">رئيس القسم</label>
                            <select id="department-head" v-model="form.head_user_id" class="field">
                                <option value="">بدون رئيس قسم</option>
                                <option v-for="user in headOptions" :key="user.id" :value="user.id">{{ user.name }} — {{ user.employee_number }}</option>
                            </select>
                            <p v-if="headOptions.length === 0" class="mt-1 text-xs text-gold-700">لا يوجد موظف نشط يحمل دور «رئيس قسم» داخل هذا القسم. أسند الدور لأحد موظفيه من «حسابات الموظفين والأدوار» (ويمكن تعيينه رئيسًا من هناك مباشرة).</p>
                            <p v-if="form.errors.head_user_id" class="field-error">{{ form.errors.head_user_id }}</p>
                        </div>
                        <p v-else class="text-xs text-slate-500">يُعيّن رئيس القسم بعد إنشائه وإسناد دور رئيس القسم إلى أحد موظفيه.</p>

                        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                            <button type="button" :disabled="form.processing" class="btn btn-secondary" @click="closeModal">إلغاء</button>
                            <button type="submit" :disabled="form.processing" class="btn btn-primary">{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ البيانات' }}</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
