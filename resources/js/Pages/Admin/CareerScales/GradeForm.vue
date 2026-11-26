<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    grade: { type: Object, default: null },
    scales: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const base = '/admin/career-scales';
const editing = computed(() => Boolean(props.grade?.id));
const availableScales = computed(() => props.scales.filter(scale => Boolean(scale.is_active) || Number(scale.id) === Number(props.grade?.career_scale_id)));
const availableCurrencies = computed(() => props.currencies.filter(currency => Boolean(currency.is_active) || currency.code === props.grade?.currency_code));
const form = useForm({
    career_scale_id: props.grade?.career_scale_id ?? '',
    code: props.grade?.code ?? '',
    name_ar: props.grade?.name_ar ?? '',
    name_en: props.grade?.name_en ?? '',
    description: props.grade?.description ?? '',
    grade_level: props.grade?.grade_level ?? '',
    sort_order: props.grade?.sort_order ?? 0,
    salary_min: props.grade?.salary_min ?? '',
    salary_max: props.grade?.salary_max ?? '',
    currency_code: props.grade?.currency_code ?? '',
    is_active: props.grade ? Boolean(props.grade.is_active) : true,
});
const hasSalary = computed(() => form.salary_min !== '' && form.salary_min !== null || form.salary_max !== '' && form.salary_max !== null);
const selectedScale = computed(() => props.scales.find(scale => Number(scale.id) === Number(form.career_scale_id)));
const chosenCurrency = computed(() => props.currencies.find(currency => currency.code === form.currency_code));

function save() {
    if (form.processing) return;
    form.transform(data => ({
        ...data,
        career_scale_id: data.career_scale_id === '' ? null : Number(data.career_scale_id),
        grade_level: data.grade_level === '' || data.grade_level === null ? null : Number(data.grade_level),
        sort_order: data.sort_order === '' ? null : Number(data.sort_order),
        salary_min: data.salary_min === '' ? null : data.salary_min,
        salary_max: data.salary_max === '' ? null : data.salary_max,
        currency_code: data.currency_code === '' ? null : data.currency_code,
        name_en: data.name_en === '' ? null : data.name_en,
        description: data.description === '' ? null : data.description,
    }));
    const options = { preserveScroll: true };
    if (editing.value) {
        form.put(`${base}/grades/${props.grade.id}`, options);
    } else {
        form.post(`${base}/grades`, options);
    }
}

const fieldClass = 'field';
</script>

<template>
    <AppLayout :title="editing ? 'تعديل الدرجة الوظيفية' : 'إضافة درجة وظيفية'">
        <div class="mx-auto max-w-4xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <Link href="/admin/master-data" class="font-medium text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span>
                <Link :href="base" class="font-medium text-blue-700 hover:underline">السلالم والدرجات الوظيفية</Link>
                <span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">{{ editing ? 'تعديل درجة' : 'إضافة درجة' }}</span>
            </nav>

            <PageHeader eyebrow="ثوابت العمل" :title="editing ? 'تعديل الدرجة الوظيفية' : 'إضافة درجة وظيفية جديدة'" description="تُربط الدرجة بسلم وظيفي واحد. يمكن تحديد نطاق راتب وعملة اختياريين دون تعديل رواتب الموظفين الفعلية." />

            <div v-if="!availableScales.length" class="alert-warning">
                لا توجد سلالم وظيفية نشطة. <Link :href="`${base}/create`" class="font-semibold underline">أضف سلمًا وظيفيًا أولًا</Link>.
            </div>

            <form class="space-y-6 card md:p-7" novalidate @submit.prevent="save">
                <div v-if="form.hasErrors" role="alert" class="alert-error">
                    تعذّر حفظ الدرجة. يرجى تصحيح الحقول المبيّنة أدناه.
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="grade-scale" class="field-label">السلم الوظيفي <span class="text-red-600">*</span></label>
                        <select id="grade-scale" v-model="form.career_scale_id" required :aria-invalid="Boolean(form.errors.career_scale_id)" :class="fieldClass">
                            <option value="" disabled>اختر السلم الوظيفي</option>
                            <option v-for="scale in availableScales" :key="scale.id" :value="scale.id">{{ scale.name_ar }} ({{ scale.code }}){{ !scale.is_active ? ' — غير نشط' : '' }}</option>
                        </select>
                        <p v-if="selectedScale && !selectedScale.is_active" class="mt-1 text-xs text-gold-800">هذا السلم غير نشط. لا يمكن إنشاء درجة نشطة تحته.</p>
                        <p v-if="form.errors.career_scale_id" class="field-error">{{ form.errors.career_scale_id }}</p>
                    </div>
                    <div>
                        <label for="grade-code" class="field-label">رمز الدرجة <span class="text-red-600">*</span></label>
                        <input id="grade-code" v-model.trim="form.code" type="text" dir="ltr" maxlength="100" autocomplete="off" placeholder="grade_01" required :aria-invalid="Boolean(form.errors.code)" :class="fieldClass" />
                        <p class="mt-1 text-xs text-slate-500">يبدأ بحرف إنجليزي صغير. الرمز فريد داخل السلم المحدد.</p>
                        <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label for="grade-name-ar" class="field-label">اسم الدرجة بالعربية <span class="text-red-600">*</span></label>
                        <input id="grade-name-ar" v-model.trim="form.name_ar" type="text" maxlength="255" required placeholder="الدرجة الأولى" :aria-invalid="Boolean(form.errors.name_ar)" :class="fieldClass" />
                        <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                    </div>
                    <div>
                        <label for="grade-name-en" class="field-label">اسم الدرجة بالإنجليزية (اختياري)</label>
                        <input id="grade-name-en" v-model.trim="form.name_en" type="text" dir="ltr" maxlength="255" placeholder="Grade One" :aria-invalid="Boolean(form.errors.name_en)" :class="fieldClass" />
                        <p v-if="form.errors.name_en" class="field-error">{{ form.errors.name_en }}</p>
                    </div>
                    <div>
                        <label for="grade-level" class="field-label">رقم المستوى (اختياري)</label>
                        <input id="grade-level" v-model="form.grade_level" type="number" min="0" step="1" placeholder="1" :aria-invalid="Boolean(form.errors.grade_level)" :class="fieldClass" />
                        <p v-if="form.errors.grade_level" class="field-error">{{ form.errors.grade_level }}</p>
                    </div>
                    <div>
                        <label for="grade-sort" class="field-label">ترتيب العرض <span class="text-red-600">*</span></label>
                        <input id="grade-sort" v-model="form.sort_order" type="number" min="0" step="1" required :aria-invalid="Boolean(form.errors.sort_order)" :class="fieldClass" />
                        <p v-if="form.errors.sort_order" class="field-error">{{ form.errors.sort_order }}</p>
                    </div>
                </div>

                <section class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div>
                        <h2 class="card-title">نطاق الراتب الاسترشادي (اختياري)</h2>
                        <p class="mt-1 text-xs leading-6 text-slate-600">يمكن ترك الحدين فارغين. عند إدخال أي حد يجب اختيار عملة نشطة. لا تتحول هذه القيم إلى راتب الموظف تلقائيًا.</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label for="salary-min" class="field-label">الحد الأدنى</label>
                            <input id="salary-min" v-model="form.salary_min" type="number" min="0" step="0.01" placeholder="0.00" :aria-invalid="Boolean(form.errors.salary_min)" :class="fieldClass" />
                            <p v-if="form.errors.salary_min" class="field-error">{{ form.errors.salary_min }}</p>
                        </div>
                        <div>
                            <label for="salary-max" class="field-label">الحد الأعلى</label>
                            <input id="salary-max" v-model="form.salary_max" type="number" min="0" step="0.01" placeholder="0.00" :aria-invalid="Boolean(form.errors.salary_max)" :class="fieldClass" />
                            <p v-if="form.errors.salary_max" class="field-error">{{ form.errors.salary_max }}</p>
                        </div>
                        <div>
                            <label for="salary-currency" class="field-label">العملة <span v-if="hasSalary" class="text-red-600">*</span></label>
                            <select id="salary-currency" v-model="form.currency_code" :required="hasSalary" :aria-invalid="Boolean(form.errors.currency_code)" :class="fieldClass">
                                <option value="">بدون عملة</option>
                                <option v-for="currency in availableCurrencies" :key="currency.code" :value="currency.code">{{ currency.name_ar }} ({{ currency.code }}){{ !currency.is_active ? ' — غير نشطة' : '' }}</option>
                            </select>
                            <p v-if="chosenCurrency && !chosenCurrency.is_active && hasSalary" class="mt-1 text-xs text-gold-800">اختر عملة نشطة لحفظ نطاق الراتب.</p>
                            <p v-if="form.errors.currency_code" class="field-error">{{ form.errors.currency_code }}</p>
                        </div>
                    </div>
                </section>

                <div>
                    <label for="grade-description" class="field-label">الوصف (اختياري)</label>
                    <textarea id="grade-description" v-model="form.description" rows="3" maxlength="5000" :aria-invalid="Boolean(form.errors.description)" :class="fieldClass" placeholder="وصف الدرجة وشروط استخدامها"></textarea>
                    <p v-if="form.errors.description" class="field-error">{{ form.errors.description }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-800">
                        <input v-model="form.is_active" type="checkbox" class="h-5 w-5" />
                        الدرجة نشطة ومتاحة للاستخدام
                    </label>
                    <p class="mt-2 text-xs leading-6 text-slate-600">تعطيل الدرجة لا يحذف سجلها أو يعدّل بيانات ورواتب الموظفين الموجودين.</p>
                    <p v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
                    <button type="submit" :disabled="form.processing || !availableScales.length" class="btn btn-primary">
                        {{ form.processing ? 'جارٍ الحفظ...' : editing ? 'حفظ التعديلات' : 'إضافة الدرجة' }}
                    </button>
                    <Link :href="base" class="btn btn-secondary">إلغاء والعودة</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
