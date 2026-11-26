<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    title: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
});

const base = '/admin/job-catalog';
const editing = computed(() => Boolean(props.title?.id));
const form = useForm({
    job_category_id: props.title?.job_category_id ?? '',
    code: props.title?.code ?? '',
    name_ar: props.title?.name_ar ?? '',
    name_en: props.title?.name_en ?? '',
    description: props.title?.description ?? '',
    sort_order: props.title?.sort_order ?? 0,
    is_active: props.title ? Boolean(props.title.is_active) : true,
});

const selectedCategory = computed(() =>
    props.categories.find(category => String(category.id) === String(form.job_category_id)),
);

function save() {
    if (form.processing) return;
    const options = { preserveScroll: true };
    if (editing.value) {
        form.put(`${base}/titles/${props.title.id}`, options);
    } else {
        form.post(`${base}/titles`, options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'تعديل مسمى وظيفي' : 'إضافة مسمى وظيفي'">
        <div class="mx-auto max-w-4xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <Link href="/admin/master-data" class="font-medium text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span>
                <Link :href="base" class="font-medium text-blue-700 hover:underline">الفئات والمسميات الوظيفية</Link>
                <span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">{{ editing ? 'تعديل مسمى' : 'إضافة مسمى' }}</span>
            </nav>

            <PageHeader eyebrow="ثوابت العمل" :title="editing ? 'تعديل المسمى الوظيفي' : 'إضافة مسمى وظيفي جديد'" description="يتبع كل مسمى فئة وظيفية واحدة. المسمى الوظيفي لا يمنح صلاحيات دخول أو موافقات تلقائيًا." />

            <form class="space-y-6 card md:p-7" @submit.prevent="save" novalidate>
                <div v-if="form.hasErrors" role="alert" class="alert-error">
                    يرجى مراجعة الحقول المحددة وتصحيح الأخطاء.
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="title-category" class="field-label">الفئة الوظيفية <span class="text-red-600">*</span></label>
                        <select id="title-category" v-model="form.job_category_id" required :aria-invalid="Boolean(form.errors.job_category_id)"
                            class="field">
                            <option value="" disabled>اختر الفئة الوظيفية</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">
                                {{ category.name_ar }}{{ category.is_active === false || category.is_active === 0 ? ' (غير نشطة)' : '' }}
                            </option>
                        </select>
                        <p v-if="form.errors.job_category_id" class="field-error">{{ form.errors.job_category_id }}</p>
                        <p v-else-if="selectedCategory && (selectedCategory.is_active === false || selectedCategory.is_active === 0)" class="mt-1 text-sm text-gold-700">
                            الفئة الحالية غير نشطة؛ لا يمكن حفظ مسمى نشط تحتها. اختر فئة نشطة أو عطّل المسمى.
                        </p>
                        <p v-else-if="categories.length === 0" class="mt-1 text-sm text-gold-700">
                            لا توجد فئات وظيفية نشطة. <Link :href="`${base}/categories/create`" class="font-semibold underline">أضف فئة وظيفية أولًا</Link>.
                        </p>
                    </div>

                    <div>
                        <label for="title-code" class="field-label">رمز المسمى <span class="text-red-600">*</span></label>
                        <input id="title-code" v-model.trim="form.code" type="text" maxlength="100" dir="ltr" autocomplete="off" required
                            placeholder="maintenance_technician" :aria-invalid="Boolean(form.errors.code)"
                            class="field" />
                        <p class="mt-1 text-xs text-slate-500">حروف إنجليزية صغيرة، وأرقام وشرطة سفلية، ويبدأ الرمز بحرف.</p>
                        <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label for="title-sort" class="field-label">ترتيب العرض <span class="text-red-600">*</span></label>
                        <input id="title-sort" v-model.number="form.sort_order" type="number" min="0" step="1" required
                            :aria-invalid="Boolean(form.errors.sort_order)"
                            class="field" />
                        <p v-if="form.errors.sort_order" class="field-error">{{ form.errors.sort_order }}</p>
                    </div>
                    <div>
                        <label for="title-name-ar" class="field-label">اسم المسمى بالعربية <span class="text-red-600">*</span></label>
                        <input id="title-name-ar" v-model.trim="form.name_ar" type="text" maxlength="255" required placeholder="فني صيانة"
                            :aria-invalid="Boolean(form.errors.name_ar)"
                            class="field" />
                        <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                    </div>
                    <div>
                        <label for="title-name-en" class="field-label">اسم المسمى بالإنجليزية (اختياري)</label>
                        <input id="title-name-en" v-model.trim="form.name_en" type="text" maxlength="255" dir="ltr" placeholder="Maintenance Technician"
                            :aria-invalid="Boolean(form.errors.name_en)"
                            class="field" />
                        <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                    </div>
                </div>

                <div>
                    <label for="title-description" class="field-label">الوصف (اختياري)</label>
                    <textarea id="title-description" v-model="form.description" rows="4" maxlength="5000"
                        placeholder="وصف طبيعة الوظيفة" :aria-invalid="Boolean(form.errors.description)"
                        class="field"></textarea>
                    <p v-if="form.errors.description" class="field-error">{{ form.errors.description }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-800">
                        <input v-model="form.is_active" type="checkbox" class="h-5 w-5" />
                        المسمى الوظيفي نشط
                    </label>
                    <p class="mt-2 text-xs leading-6 text-slate-600">التعطيل يمنع اختيار المسمى في التسكين الجديد مستقبلًا، دون حذف السجلات التاريخية.</p>
                    <p v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
                    <button type="submit" :disabled="form.processing || categories.length === 0"
                        class="btn btn-primary">
                        {{ form.processing ? 'جارٍ الحفظ...' : (editing ? 'حفظ التعديلات' : 'إضافة المسمى') }}
                    </button>
                    <Link :href="base" class="btn btn-secondary">إلغاء والعودة</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
