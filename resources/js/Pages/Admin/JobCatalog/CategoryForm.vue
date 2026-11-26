<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    category: { type: Object, default: null },
});

const base = '/admin/job-catalog';
const editing = computed(() => Boolean(props.category?.id));
const form = useForm({
    code: props.category?.code ?? '',
    name_ar: props.category?.name_ar ?? '',
    name_en: props.category?.name_en ?? '',
    description: props.category?.description ?? '',
    sort_order: props.category?.sort_order ?? 0,
    is_active: props.category ? Boolean(props.category.is_active) : true,
});

function save() {
    if (form.processing) return;
    const options = { preserveScroll: true };
    if (editing.value) {
        form.put(`${base}/categories/${props.category.id}`, options);
    } else {
        form.post(`${base}/categories`, options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'تعديل فئة وظيفية' : 'إضافة فئة وظيفية'">
        <div class="mx-auto max-w-4xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <Link href="/admin/master-data" class="font-medium text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span>
                <Link :href="base" class="font-medium text-blue-700 hover:underline">الفئات والمسميات الوظيفية</Link>
                <span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">{{ editing ? 'تعديل فئة' : 'إضافة فئة' }}</span>
            </nav>

            <PageHeader eyebrow="ثوابت العمل" :title="editing ? 'تعديل الفئة الوظيفية' : 'إضافة فئة وظيفية جديدة'" description="تُستخدم الفئات لتجميع المسميات الوظيفية. لا تُمنح أي صلاحيات للنظام بمجرد إنشاء فئة." />

            <form class="space-y-6 card md:p-7" @submit.prevent="save" novalidate>
                <div v-if="form.hasErrors" role="alert" class="alert-error">
                    يرجى مراجعة الحقول المحددة أدناه وتصحيح الأخطاء.
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="category-code" class="field-label">رمز الفئة <span class="text-red-600">*</span></label>
                        <input id="category-code" v-model.trim="form.code" type="text" maxlength="100" dir="ltr" autocomplete="off" required
                            placeholder="technical" :aria-invalid="Boolean(form.errors.code)" :aria-describedby="form.errors.code ? 'category-code-error' : undefined"
                            class="field" />
                        <p class="mt-1 text-xs text-slate-500">حروف إنجليزية صغيرة، ويُسمح بالأرقام والشرطة السفلية. يبدأ بحرف.</p>
                        <p v-if="form.errors.code" id="category-code-error" class="field-error">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label for="category-sort" class="field-label">ترتيب العرض <span class="text-red-600">*</span></label>
                        <input id="category-sort" v-model.number="form.sort_order" type="number" min="0" step="1" required
                            :aria-invalid="Boolean(form.errors.sort_order)" class="field" />
                        <p v-if="form.errors.sort_order" class="field-error">{{ form.errors.sort_order }}</p>
                    </div>
                    <div>
                        <label for="category-name-ar" class="field-label">اسم الفئة بالعربية <span class="text-red-600">*</span></label>
                        <input id="category-name-ar" v-model.trim="form.name_ar" type="text" maxlength="255" required
                            placeholder="الوظائف الفنية" :aria-invalid="Boolean(form.errors.name_ar)"
                            class="field" />
                        <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                    </div>
                    <div>
                        <label for="category-name-en" class="field-label">اسم الفئة بالإنجليزية (اختياري)</label>
                        <input id="category-name-en" v-model.trim="form.name_en" type="text" maxlength="255" dir="ltr"
                            placeholder="Technical Jobs" :aria-invalid="Boolean(form.errors.name_en)"
                            class="field" />
                        <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                    </div>
                </div>

                <div>
                    <label for="category-description" class="field-label">الوصف (اختياري)</label>
                    <textarea id="category-description" v-model="form.description" rows="4" maxlength="5000"
                        placeholder="وصف الفئة والوظائف التابعة لها" :aria-invalid="Boolean(form.errors.description)"
                        class="field"></textarea>
                    <p v-if="form.errors.description" class="field-error">{{ form.errors.description }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-800">
                        <input v-model="form.is_active" type="checkbox" class="h-5 w-5" />
                        الفئة نشطة ويمكن استخدامها في التسكين الوظيفي
                    </label>
                    <p class="mt-2 text-xs leading-6 text-slate-600">عند تعطيل الفئة، تُحفظ بياناتها وسجلاتها السابقة. لا يمكن تعطيل فئة ما دامت تحتوي مسميات وظيفية نشطة.</p>
                    <p v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
                    <button type="submit" :disabled="form.processing"
                        class="btn btn-primary">
                        {{ form.processing ? 'جارٍ الحفظ...' : (editing ? 'حفظ التعديلات' : 'إضافة الفئة') }}
                    </button>
                    <Link :href="base" class="btn btn-secondary">إلغاء والعودة</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
