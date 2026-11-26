<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    mode: { type: String, default: 'create' },
    draft: { type: Object, default: null },
});

const page = usePage();

const isEditing = computed(() => props.mode === 'edit' && !!props.draft?.public_id);

const form = useForm({
    title: props.draft?.title ?? '',
    description: props.draft?.description ?? '',
    location: props.draft?.location ?? '',
    fault_type: props.draft?.fault_type ?? '',
    priority: props.draft?.priority ?? 'normal',
});

const priorityOptions = [
    { value: 'low', label: 'منخفضة' },
    { value: 'normal', label: 'عادية' },
    { value: 'high', label: 'مرتفعة' },
    { value: 'urgent', label: 'عاجلة' },
];

const navigation = [
    {
        label: 'لوحة التحكم',
        url: '/ui-preview',
        icon: '▦',
        active: false,
    },
];

const successMessage = computed(() => page.props.flash?.success || '');

watch(
    () => props.draft,
    (draft) => {
        if (!draft) return;

        form.defaults({
            title: draft.title ?? '',
            description: draft.description ?? '',
            location: draft.location ?? '',
            fault_type: draft.fault_type ?? '',
            priority: draft.priority ?? 'normal',
        });

        form.reset();
    }
);

function saveDraft() {
    if (form.processing) return;

    if (isEditing.value) {
        form.put(
            `/maintenance/requests/${props.draft.public_id}`,
            {
                preserveScroll: true,
            }
        );

        return;
    }

    form.post('/maintenance/requests', {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout title="طلب صيانة">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader eyebrow="الصيانة" :title="isEditing ? 'تعديل مسودة طلب صيانة' : 'إنشاء طلب صيانة'" description="أدخل تفاصيل المشكلة وموقعها ثم احفظ الطلب كمسودة.">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <StatusBadge status="draft" />
                        <span v-if="isEditing">معرّف المسودة: <span dir="ltr" class="break-all">{{ draft.public_id }}</span></span>
                    </div>
                </template>
            </PageHeader>

            <p class="alert-info">لإرسال طلب صيانة إلى مساره الكامل استخدم <Link href="/requests/new/MAINTENANCE" class="font-semibold underline">طلب صيانة جديد من «طلباتي»</Link>. هذه الشاشة تحفظ مسودات فقط.</p>

            <div v-if="form.errors.department_id || form.errors.status" role="alert" class="alert-error">
                <p v-if="form.errors.department_id">{{ form.errors.department_id }}</p>
                <p v-if="form.errors.status">{{ form.errors.status }}</p>
            </div>

            <form class="card space-y-5 sm:p-6" @submit.prevent="saveDraft">
                <h2 class="card-title">بيانات العطل</h2>
                <div class="grid gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="maintenance-title" class="field-label">عنوان طلب الصيانة <span class="text-red-600">*</span></label>
                        <input id="maintenance-title" v-model="form.title" type="text" maxlength="255" required placeholder="مثال: إصلاح تسرب مياه في الطابق الأول" :aria-invalid="!!form.errors.title" class="field" />
                        <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label for="maintenance-location" class="field-label">موقع العطل <span class="text-red-600">*</span></label>
                        <input id="maintenance-location" v-model="form.location" type="text" maxlength="255" required placeholder="مثال: مبنى الإدارة - الطابق الأول" :aria-invalid="!!form.errors.location" class="field" />
                        <p v-if="form.errors.location" class="field-error">{{ form.errors.location }}</p>
                    </div>
                    <div>
                        <label for="maintenance-fault-type" class="field-label">نوع العطل <span class="text-red-600">*</span></label>
                        <select id="maintenance-fault-type" v-model="form.fault_type" required :aria-invalid="!!form.errors.fault_type" class="field">
                            <option value="" disabled>اختر نوع العطل</option>
                            <option value="electrical">كهرباء</option>
                            <option value="plumbing">سباكة ومياه</option>
                            <option value="air_conditioning">تكييف وتهوية</option>
                            <option value="carpentry">نجارة</option>
                            <option value="construction">مبانٍ وإنشاءات</option>
                            <option value="equipment">معدات وأجهزة</option>
                            <option value="other">أخرى</option>
                        </select>
                        <p v-if="form.errors.fault_type" class="field-error">{{ form.errors.fault_type }}</p>
                    </div>
                    <div>
                        <label for="maintenance-priority" class="field-label">الأولوية المقترحة <span class="text-red-600">*</span></label>
                        <select id="maintenance-priority" v-model="form.priority" required :aria-invalid="!!form.errors.priority" class="field">
                            <option v-for="option in priorityOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">يراجع القسم المختص درجة الأولوية عند معالجة الطلب.</p>
                        <p v-if="form.errors.priority" class="field-error">{{ form.errors.priority }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="maintenance-description" class="field-label">وصف المشكلة <span class="text-red-600">*</span></label>
                        <textarea id="maintenance-description" v-model="form.description" rows="6" maxlength="10000" required placeholder="اشرح طبيعة العطل، ومتى ظهر، وتأثيره على العمل..." :aria-invalid="!!form.errors.description" class="field resize-y leading-7"></textarea>
                        <div class="mt-1 flex justify-between gap-3 text-xs text-slate-500">
                            <p v-if="form.errors.description" class="field-error mt-0">{{ form.errors.description }}</p>
                            <p v-else>قدّم وصفًا واضحًا لتسهيل دراسة الطلب.</p>
                            <span dir="ltr">{{ form.description.length }} / 10000</span>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5">
                    <Link href="/ui-preview" class="btn btn-secondary">العودة إلى مساحتي</Link>
                    <button type="submit" :disabled="form.processing" class="btn btn-primary">
                        {{ form.processing ? 'جارٍ الحفظ...' : isEditing ? 'حفظ التعديلات' : 'حفظ كمسودة' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
