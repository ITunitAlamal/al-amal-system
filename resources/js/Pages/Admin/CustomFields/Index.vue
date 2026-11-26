<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    entities: { type: Object, default: () => ({}) },
    scopes: { type: Object, default: () => ({}) },
    steps: { type: Object, default: () => ({}) },
    selectedEntity: { type: String, required: true },
    selectedScope: { type: String, default: '' },
    fields: { type: Array, default: () => [] },
    fieldTypes: { type: Object, default: () => ({}) },
    visibilityOperators: { type: Array, default: () => [] },
    masterTypes: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success || '');
const operatorLabels = { equals: 'يساوي', not_equals: 'لا يساوي', filled: 'معبأ', empty: 'فارغ' };
const editingId = ref(null);
const showForm = ref(false);

const blank = {
    code: '', label_ar: '', label_en: '', help_text: '', field_type: 'text', master_data_type_id: null, step_code: '',
    is_required: false, is_sensitive: false, sort_order: 100, effective_from: '', effective_to: '', is_active: true,
    validation: { min: null, max: null, max_length: null },
    visible_when: { field: '', operator: 'equals', value: '' },
};
const form = useForm(JSON.parse(JSON.stringify(blank)));

function decode(json) {
    if (!json) return null;
    try { return typeof json === 'string' ? JSON.parse(json) : json; } catch { return null; }
}
function select(entity, scope) {
    router.get('/admin/custom-fields', { entity, scope }, { preserveState: false });
}
function openCreate() {
    editingId.value = null;
    Object.assign(form, JSON.parse(JSON.stringify(blank)));
    form.clearErrors();
    showForm.value = true;
}
function openEdit(field) {
    editingId.value = field.id;
    const validation = decode(field.validation) || {};
    const visibleWhen = decode(field.visible_when) || {};
    Object.assign(form, {
        code: field.code, label_ar: field.label_ar, label_en: field.label_en || '', help_text: field.help_text || '',
        field_type: field.field_type, master_data_type_id: field.master_data_type_id, step_code: field.step_code || '',
        is_required: Boolean(field.is_required), is_sensitive: Boolean(field.is_sensitive), sort_order: field.sort_order,
        effective_from: field.effective_from ? String(field.effective_from).slice(0, 10) : '',
        effective_to: field.effective_to ? String(field.effective_to).slice(0, 10) : '',
        is_active: Boolean(field.is_active),
        validation: { min: validation.min ?? null, max: validation.max ?? null, max_length: validation.max_length ?? null },
        visible_when: { field: visibleWhen.field || '', operator: visibleWhen.operator || 'equals', value: visibleWhen.value ?? '' },
    });
    form.clearErrors();
    showForm.value = true;
}
function save() {
    form.transform(data => {
        const payload = {
            label_ar: data.label_ar, label_en: data.label_en || null, help_text: data.help_text || null,
            is_required: data.is_required, is_sensitive: data.is_sensitive, sort_order: data.sort_order,
            effective_from: data.effective_from || null, effective_to: data.effective_to || null,
            validation: data.validation,
            visible_when: data.visible_when.field ? data.visible_when : null,
        };
        if (editingId.value) {
            payload.is_active = data.is_active;
        } else {
            Object.assign(payload, {
                entity_type: props.selectedEntity, scope_key: props.selectedScope, step_code: data.step_code || null,
                code: data.code, field_type: data.field_type, master_data_type_id: data.master_data_type_id,
            });
        }
        return payload;
    });
    const options = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editingId.value) form.put(`/admin/custom-fields/${editingId.value}`, options);
    else form.post('/admin/custom-fields', options);
}
const otherFields = computed(() => props.fields.filter(field => field.code !== form.code));
</script>

<template>
    <AppLayout title="الحقول الديناميكية" :username="page.props.auth?.user?.name || ''">
        <div class="mx-auto max-w-6xl space-y-5" dir="rtl">
            <PageHeader eyebrow="إعدادات النظام / النماذج" title="الحقول الديناميكية" description="أضف حقولًا إلى النماذج دون برمجة. الرمز والنوع ثابتان بعد الإنشاء حتى لا يتغير معنى البيانات المحفوظة.">
                <button type="button" class="btn btn-primary" @click="openCreate">+ حقل جديد</button>
            </PageHeader>


            <section class="grid gap-4 card md:grid-cols-2">
                <div>
                    <label class="field-label">النموذج</label>
                    <select :value="selectedEntity" class="field" @change="select($event.target.value, '')">
                        <option v-for="(label, key) in entities" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">النوع</label>
                    <select :value="selectedScope" class="field" @change="select(selectedEntity, $event.target.value)">
                        <option v-for="(label, key) in scopes" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">حقول «جميع الأنواع» تظهر في كل الطلبات، وحقول نوع محدد تظهر في طلباته فقط.</p>
                </div>
            </section>

            <section v-if="showForm" class="card sm:p-6">
                <h2 class="mb-4 font-bold text-slate-800">{{ editingId ? 'تعديل حقل' : 'حقل جديد' }}</h2>
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
                    <template v-if="!editingId">
                        <div>
                            <label class="field-label">الرمز (بالإنجليزية) *</label>
                            <input v-model="form.code" required pattern="[a-z][a-z0-9_]*" dir="ltr" class="field text-left" />
                            <p class="mt-1 text-xs text-slate-500">يُستخدم في شروط مسارات العمل، مثل amount أو stock_status.</p>
                            <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label class="field-label">نوع الحقل *</label>
                            <select v-model="form.field_type" class="field">
                                <option v-for="(label, key) in fieldTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div v-if="form.field_type === 'select'">
                            <label class="field-label">القائمة من ثوابت النظام *</label>
                            <select v-model="form.master_data_type_id" required class="field">
                                <option :value="null" disabled>اختر نوع الثابت</option>
                                <option v-for="type in masterTypes" :key="type.id" :value="type.id">{{ type.name_ar }}</option>
                            </select>
                            <p v-if="form.errors.master_data_type_id" class="field-error">{{ form.errors.master_data_type_id }}</p>
                        </div>
                        <div v-if="Object.keys(steps).length">
                            <label class="field-label">متى يُعبأ؟</label>
                            <select v-model="form.step_code" class="field">
                                <option value="">عند تقديم الطلب</option>
                                <option v-for="(label, code) in steps" :key="code" :value="code">أثناء مرحلة: {{ label }}</option>
                            </select>
                        </div>
                    </template>
                    <div>
                        <label class="field-label">الاسم بالعربية *</label>
                        <input v-model="form.label_ar" required maxlength="200" class="field" />
                        <p v-if="form.errors.label_ar" class="field-error">{{ form.errors.label_ar }}</p>
                    </div>
                    <div>
                        <label class="field-label">الاسم بالإنجليزية</label>
                        <input v-model="form.label_en" maxlength="200" dir="ltr" class="field text-left" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="field-label">نص المساعدة</label>
                        <input v-model="form.help_text" maxlength="500" class="field" />
                    </div>
                    <div v-if="form.field_type === 'number'" class="grid grid-cols-2 gap-3">
                        <div><label class="field-label">أقل قيمة</label><input v-model.number="form.validation.min" type="number" step="any" class="field" /></div>
                        <div><label class="field-label">أكبر قيمة</label><input v-model.number="form.validation.max" type="number" step="any" class="field" /><p v-if="form.errors['validation.max']" class="field-error">{{ form.errors['validation.max'] }}</p></div>
                    </div>
                    <div v-if="['text', 'textarea'].includes(form.field_type)">
                        <label class="field-label">أقصى عدد أحرف</label>
                        <input v-model.number="form.validation.max_length" type="number" min="1" class="field" />
                    </div>
                    <div class="flex flex-wrap items-center gap-6 md:col-span-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_required" type="checkbox" /> إلزامي</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_sensitive" type="checkbox" /> حساس (لا يظهر إلا للمخولين بالكامل)</label>
                        <label v-if="editingId" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> مفعل</label>
                    </div>
                    <fieldset class="rounded-xl border border-slate-200 p-4 md:col-span-2">
                        <legend class="px-2 text-sm font-medium">شرط الظهور (اختياري)</legend>
                        <div class="grid gap-3 md:grid-cols-3">
                            <select v-model="form.visible_when.field" class="rounded-xl border border-slate-200 p-3">
                                <option value="">يظهر دائمًا</option>
                                <option v-for="field in otherFields" :key="field.id" :value="field.code">عندما {{ field.label_ar }}</option>
                            </select>
                            <select v-if="form.visible_when.field" v-model="form.visible_when.operator" class="rounded-xl border border-slate-200 p-3">
                                <option v-for="op in visibilityOperators" :key="op" :value="op">{{ operatorLabels[op] || op }}</option>
                            </select>
                            <input v-if="form.visible_when.field && ['equals', 'not_equals'].includes(form.visible_when.operator)" v-model="form.visible_when.value" placeholder="القيمة (لقوائم الثوابت: رقم القيمة)" class="rounded-xl border border-slate-200 p-3" />
                        </div>
                        <p v-if="form.errors['visible_when.field']" class="field-error">{{ form.errors['visible_when.field'] }}</p>
                    </fieldset>
                    <div><label class="field-label">ترتيب العرض</label><input v-model.number="form.sort_order" type="number" min="0" required class="field" /></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="field-label">يسري من</label><input v-model="form.effective_from" type="date" class="field" /></div>
                        <div><label class="field-label">ينتهي في</label><input v-model="form.effective_to" type="date" class="field" /><p v-if="form.errors.effective_to" class="field-error">{{ form.errors.effective_to }}</p></div>
                    </div>
                    <div v-if="Object.keys(form.errors).length" role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 md:col-span-2">راجع الحقول المشار إليها قبل الحفظ.</div>
                    <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-4 md:col-span-2">
                        <button type="submit" :disabled="form.processing" class="btn btn-primary">{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ' }}</button>
                        <button type="button" class="btn btn-secondary" @click="showForm = false">إلغاء</button>
                    </div>
                </form>
            </section>

            <section class="card">
                <div v-if="!fields.length" class="rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-500">لا توجد حقول لهذا النوع بعد.</div>
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-[640px]">
                        <thead><tr><th>الحقل</th><th>النوع</th><th>متى يُعبأ</th><th>خصائص</th><th>الإجراء</th></tr></thead>
                        <tbody>
                            <tr v-for="field in fields" :key="field.id">
                                <td><span class="font-medium">{{ field.label_ar }}</span><span class="block text-xs text-slate-500" dir="ltr">{{ field.code }}</span></td>
                                <td>{{ fieldTypes[field.field_type] || field.field_type }}</td>
                                <td>{{ field.step_code ? (steps[field.step_code] || field.step_code) : 'عند التقديم' }}</td>
                                <td class="space-x-1 space-x-reverse">
                                    <span v-if="field.is_required" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700">إلزامي</span>
                                    <span v-if="field.is_sensitive" class="rounded-full bg-gold-50 px-2 py-0.5 text-xs text-gold-700">حساس</span>
                                    <span v-if="!field.is_active" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">معطل</span>
                                </td>
                                <td><button type="button" class="text-blue-700 hover:underline" @click="openEdit(field)">تعديل</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
