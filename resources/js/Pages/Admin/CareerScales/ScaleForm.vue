<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    scale: { type: Object, default: null },
});

const base = '/admin/career-scales';
const editing = computed(() => Boolean(props.scale?.id));
const form = useForm({
    code: props.scale?.code ?? '',
    name_ar: props.scale?.name_ar ?? '',
    name_en: props.scale?.name_en ?? '',
    description: props.scale?.description ?? '',
    sort_order: props.scale?.sort_order ?? 0,
    is_active: props.scale ? Boolean(props.scale.is_active) : true,
});

function save() {
    if (form.processing) return;
    const options = { preserveScroll: true };
    if (editing.value) {
        form.put(`${base}/${props.scale.id}`, options);
    } else {
        form.post(base, options);
    }
}
</script>

<template>
    <AppLayout
        :title="editing ? 'تعديل السلم الوظيفي' : 'إضافة سلم وظيفي'"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <Link href="/admin/master-data" class="font-medium text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span>
                <Link :href="base" class="font-medium text-blue-700 hover:underline">السلالم والدرجات الوظيفية</Link>
                <span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">{{ editing ? 'تعديل سلم' : 'إضافة سلم' }}</span>
            </nav>

            <PageHeader eyebrow="ثوابت العمل" :title="editing ? 'تعديل السلم الوظيفي' : 'إضافة سلم وظيفي جديد'" description="السلم الوظيفي يجمع درجات وظيفية متعددة. بعد حفظ السلم، يمكن إضافة درجات ونطاقات رواتب اختيارية لكل درجة." />

            <form
                class="space-y-6 card md:p-7"
                novalidate
                @submit.prevent="save"
            >
                <div v-if="form.hasErrors" role="alert" class="alert-error">
                    يرجى مراجعة الحقول المحددة وتصحيح الأخطاء قبل الحفظ.
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="scale-code" class="field-label">رمز السلم <span class="text-red-600">*</span></label>
                        <input
                            id="scale-code"
                            v-model.trim="form.code"
                            type="text"
                            dir="ltr"
                            maxlength="100"
                            autocomplete="off"
                            required
                            placeholder="general_scale"
                            :aria-invalid="Boolean(form.errors.code)"
                            :aria-describedby="form.errors.code ? 'scale-code-error' : undefined"
                            class="field"
                        />
                        <p class="mt-1 text-xs text-slate-500">يبدأ بحرف إنجليزي صغير، ويسمح بالأرقام والشرطة السفلية.</p>
                        <p v-if="form.errors.code" id="scale-code-error" class="field-error">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label for="scale-sort" class="field-label">ترتيب العرض <span class="text-red-600">*</span></label>
                        <input
                            id="scale-sort"
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                            step="1"
                            required
                            :aria-invalid="Boolean(form.errors.sort_order)"
                            class="field"
                        />
                        <p v-if="form.errors.sort_order" class="field-error">{{ form.errors.sort_order }}</p>
                    </div>
                    <div>
                        <label for="scale-name-ar" class="field-label">اسم السلم بالعربية <span class="text-red-600">*</span></label>
                        <input
                            id="scale-name-ar"
                            v-model.trim="form.name_ar"
                            type="text"
                            maxlength="255"
                            required
                            placeholder="السلم الوظيفي العام"
                            :aria-invalid="Boolean(form.errors.name_ar)"
                            class="field"
                        />
                        <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                    </div>
                    <div>
                        <label for="scale-name-en" class="field-label">اسم السلم بالإنجليزية (اختياري)</label>
                        <input
                            id="scale-name-en"
                            v-model.trim="form.name_en"
                            type="text"
                            dir="ltr"
                            maxlength="255"
                            placeholder="General Career Scale"
                            :aria-invalid="Boolean(form.errors.name_en)"
                            class="field"
                        />
                        <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                    </div>
                </div>

                <div>
                    <label for="scale-description" class="field-label">الوصف (اختياري)</label>
                    <textarea
                        id="scale-description"
                        v-model="form.description"
                        rows="4"
                        maxlength="5000"
                        placeholder="وصف السلم الوظيفي ونطاق استخدامه"
                        :aria-invalid="Boolean(form.errors.description)"
                        class="field"
                    ></textarea>
                    <p v-if="form.errors.description" class="field-error">{{ form.errors.description }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-800">
                        <input v-model="form.is_active" type="checkbox" class="h-5 w-5" />
                        السلم نشط ومتاح للاستخدام
                    </label>
                    <p class="mt-2 text-xs leading-6 text-slate-600">
                        لا يمكن تعطيل سلم وظيفي يحتوي على درجات نشطة. تعطيل السلم لا يحذف بياناته أو درجاته السابقة.
                    </p>
                    <p v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="btn btn-primary"
                    >
                        {{ form.processing ? 'جارٍ الحفظ...' : (editing ? 'حفظ التعديلات' : 'إضافة السلم') }}
                    </button>
                    <Link :href="base" class="btn btn-secondary">
                        إلغاء والعودة
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
