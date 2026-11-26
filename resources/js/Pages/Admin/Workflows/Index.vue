<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';

defineProps({
    definitions: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success || '');
const statusLabels = { draft: 'مسودة', published: 'منشور', retired: 'مؤرشف' };
const statusCss = { draft: 'bg-gold-50 text-gold-800 ring-1 ring-gold-200', published: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200', retired: 'bg-slate-100 text-slate-500 ring-1 ring-slate-200' };

const showCreate = ref(false);
const createForm = useForm({ code: '', name: '', description: '', is_user_startable: true, reference_prefix: '' });
function create() {
    createForm.transform(data => ({ ...data, reference_prefix: data.reference_prefix || null, description: data.description || null }))
        .post('/admin/workflows/definitions');
}

const editingId = ref(null);
const editForm = useForm({ name: '', description: '', is_user_startable: false, reference_prefix: '', is_active: true });
function openEdit(definition) {
    editingId.value = definition.id;
    Object.assign(editForm, {
        name: definition.name, description: definition.description || '', is_user_startable: Boolean(definition.is_user_startable),
        reference_prefix: definition.reference_prefix || '', is_active: Boolean(definition.is_active),
    });
    editForm.clearErrors();
}
function saveEdit() {
    editForm.transform(data => ({ ...data, reference_prefix: data.reference_prefix || null, description: data.description || null }))
        .put(`/admin/workflows/definitions/${editingId.value}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}
function openDraft(definition) {
    router.post(`/admin/workflows/definitions/${definition.id}/draft`);
}
</script>

<template>
    <AppLayout title="مسارات العمل">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="إعدادات النظام" title="مسارات العمل" description="كل مسار له إصدارات: تعدّل المسودة ثم تنشرها. الطلبات الجارية تكمل على الإصدار الذي بدأت به.">
                <button type="button" class="btn btn-primary" @click="showCreate = !showCreate">+ مسار جديد</button>
            </PageHeader>


            <section v-if="showCreate" class="card sm:p-6">
                <h2 class="card-title mb-4">مسار جديد</h2>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="create">
                    <div>
                        <label class="field-label">الرمز (بالإنجليزية) *</label>
                        <input v-model="createForm.code" required pattern="[A-Za-z][A-Za-z0-9_]*" dir="ltr" class="field text-left" placeholder="SERVICE_REQUEST" />
                        <p v-if="createForm.errors.code" class="field-error">{{ createForm.errors.code }}</p>
                    </div>
                    <div>
                        <label class="field-label">اسم نوع الطلب *</label>
                        <input v-model="createForm.name" required maxlength="200" class="field" placeholder="طلب خدمة عامة" />
                        <p v-if="createForm.errors.name" class="field-error">{{ createForm.errors.name }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="field-label">الوصف (يظهر لمقدم الطلب)</label>
                        <textarea v-model="createForm.description" rows="2" class="field" />
                    </div>
                    <div>
                        <label class="field-label">بادئة رقم المعاملة</label>
                        <input v-model="createForm.reference_prefix" maxlength="20" pattern="[A-Z0-9-]+" dir="ltr" class="field text-left" placeholder="SRV" />
                        <p v-if="createForm.errors.reference_prefix" class="field-error">{{ createForm.errors.reference_prefix }}</p>
                    </div>
                    <label class="flex items-center gap-2 pt-6 text-sm"><input v-model="createForm.is_user_startable" type="checkbox" /> يظهر للموظفين في «طلباتي» بعد النشر</label>
                    <div class="flex gap-3 md:col-span-2">
                        <button type="submit" :disabled="createForm.processing" class="btn btn-primary">إنشاء كمسودة</button>
                        <button type="button" class="btn btn-secondary" @click="showCreate = false">إلغاء</button>
                    </div>
                </form>
            </section>

            <section v-for="definition in definitions" :key="definition.id" class="card">
                <div v-if="editingId !== definition.id" class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="card-title">{{ definition.name }}</h2>
                        <p class="mt-1 text-xs text-slate-400" dir="ltr">{{ definition.code }}</p>
                        <p v-if="definition.description" class="mt-2 text-sm text-slate-600">{{ definition.description }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <StatusBadge :label="definition.is_active ? 'نشط' : 'معطل'" :tone="definition.is_active ? 'success' : 'muted'" />
                            <StatusBadge v-if="definition.is_user_startable" label="متاح للموظفين" tone="info" />
                            <StatusBadge v-if="definition.is_module" :label="`مرتبط بـ: ${definition.subject_label}`" tone="navy" />
                        </div>
                        <p v-if="definition.is_module" class="mt-2 text-xs text-slate-500">يبدأ هذا المسار من شاشة وحدته (لا يظهر في «طلباتي» كنوع مستقل)، ويُختار في إعدادات الوحدة.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary" @click="openEdit(definition)">تعديل البيانات</button>
                        <button type="button" class="btn btn-primary" @click="openDraft(definition)">
                            {{ definition.versions.some(v => v.status === 'draft') ? 'فتح المسودة' : 'إنشاء مسودة للتعديل' }}
                        </button>
                    </div>
                </div>
                <form v-else class="grid gap-4 md:grid-cols-2" @submit.prevent="saveEdit">
                    <div><label class="field-label">الاسم *</label><input v-model="editForm.name" required class="field" /></div>
                    <div><label class="field-label">بادئة الرقم</label><input v-model="editForm.reference_prefix" dir="ltr" class="field text-left" /><p v-if="editForm.errors.reference_prefix" class="field-error">{{ editForm.errors.reference_prefix }}</p></div>
                    <div class="md:col-span-2"><label class="field-label">الوصف</label><textarea v-model="editForm.description" rows="2" class="field" /></div>
                    <label v-if="!definition.is_module" class="flex items-center gap-2 text-sm"><input v-model="editForm.is_user_startable" type="checkbox" /> متاح للموظفين في «طلباتي»</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="editForm.is_active" type="checkbox" /> نشط (الإيقاف يمنع الطلبات الجديدة فقط)</label>
                    <div class="flex gap-3 md:col-span-2">
                        <button type="submit" :disabled="editForm.processing" class="btn btn-primary">حفظ</button>
                        <button type="button" class="btn btn-secondary" @click="editingId = null">إلغاء</button>
                    </div>
                </form>

                <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    <Link v-for="version in definition.versions" :key="version.id" :href="`/admin/workflows/versions/${version.id}`"
                        :class="['rounded-lg px-3 py-1.5 text-xs font-semibold hover:opacity-80', statusCss[version.status] || 'bg-slate-100']">
                        الإصدار {{ version.version_number }} · {{ statusLabels[version.status] || version.status }}
                    </Link>
                </div>
            </section>

            <EmptyState v-if="!definitions.length" title="لا توجد مسارات عمل بعد" description="أنشئ مسارًا جديدًا، ثم عرّف مراحله وانقل المسودة إلى النشر." />
        </div>
    </AppLayout>
</template>
