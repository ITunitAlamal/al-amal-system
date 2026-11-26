<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AddressBlock from '../../Components/Registration/AddressBlock.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// A request to change an official record. The key makes a repeated submission (lost connection) a no-op on the server.
const props = defineProps({ subject: String, child: Object, groups: Object, group: String, options: Object, idempotencyKey: String });
const form = useForm({
    child: props.child?.public_id || '', field_group: props.groups[props.group] ? props.group : Object.keys(props.groups)[0],
    proposed: { phone: '', street: '', landmark: '', moved_on: '', school_name: '', stage_value_id: '', status_value_id: '', grade: '', academic_year: '', details: '' },
    reason: '', file: null, idempotency_key: props.idempotencyKey,
});
const kind = computed(() => ['phone', 'current_address', 'school'].includes(form.field_group) ? form.field_group : 'details');
const fieldError = key => form.errors[key] || form.errors[`proposed.${key}`];
function submit() {
    form.transform(data => ({ ...data, proposed: Object.fromEntries(Object.entries(data.proposed).filter(([, v]) => v !== '' && v !== null)) }))
        .post('/guardian/changes', { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <PortalLayout title="طلب تعديل" :back="child ? `/guardian/family/children/${child.public_id}` : '/guardian/family'">
        <h1 class="text-2xl font-bold text-navy-800">طلب تعديل {{ child ? 'بيانات ' + child.name : 'بياناتي' }}</h1>
        <form class="card space-y-4 p-5" novalidate @submit.prevent="submit">
            <FormErrorSummary :errors="form.errors" />
            <label class="block text-sm font-semibold">نوع التعديل <span class="text-red-700" aria-hidden="true">*</span>
                <select v-model="form.field_group" class="field mt-1" required data-error-field="field_group"><option v-for="(label, key) in groups" :key="key" :value="key">{{ label }}</option></select></label>

            <label v-if="kind === 'phone'" class="block text-sm font-semibold">رقم الجوال الجديد <span class="text-red-700" aria-hidden="true">*</span>
                <input v-model="form.proposed.phone" type="tel" inputmode="tel" class="field mt-1" dir="ltr" maxlength="16" data-error-field="phone" :aria-invalid="!!fieldError('phone')">
                <span v-if="fieldError('phone')" class="mt-1 block text-sm text-red-700">{{ fieldError('phone') }}</span></label>

            <template v-else-if="kind === 'current_address'">
                <AddressBlock :model-value="form.proposed" :errors="form.errors" prefix="" legend="السكن الحالي الجديد" />
                <label class="block text-sm">تاريخ الانتقال<input v-model="form.proposed.moved_on" type="date" class="field mt-1"></label>
            </template>

            <div v-else-if="kind === 'school'" class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm sm:col-span-2">اسم المدرسة<input v-model="form.proposed.school_name" class="field mt-1" maxlength="200" data-error-field="school_name">
                    <span v-if="fieldError('school_name')" class="mt-1 block text-sm text-red-700">{{ fieldError('school_name') }}</span></label>
                <label class="text-sm">المرحلة<select v-model="form.proposed.stage_value_id" class="field mt-1"><option value="">—</option><option v-for="o in options.stages" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                <label class="text-sm">حالة الالتحاق<select v-model="form.proposed.status_value_id" class="field mt-1"><option value="">—</option><option v-for="o in options.enrollment" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                <label class="text-sm">الصف<input v-model="form.proposed.grade" class="field mt-1" maxlength="40"></label>
                <label class="text-sm">العام الدراسي<input v-model="form.proposed.academic_year" class="field mt-1" maxlength="20" placeholder="2026/2027" dir="ltr"></label>
            </div>

            <label v-else class="block text-sm font-semibold">التعديل المطلوب <span class="text-red-700" aria-hidden="true">*</span>
                <textarea v-model="form.proposed.details" rows="4" class="field mt-1" maxlength="3000" data-error-field="details" :aria-invalid="!!fieldError('details')"></textarea>
                <span v-if="fieldError('details')" class="mt-1 block text-sm text-red-700">{{ fieldError('details') }}</span></label>

            <label class="block text-sm">السبب (اختياري)<textarea v-model="form.reason" rows="2" class="field mt-1" maxlength="2000"></textarea></label>
            <label class="block text-sm">مرفق داعم (اختياري: PDF أو صورة حتى 10MB)
                <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block text-sm" data-error-field="file" @change="form.file = $event.target.files[0] || null">
                <span v-if="form.errors.file" class="mt-1 block text-sm text-red-700">{{ form.errors.file }}</span></label>
            <p v-if="form.progress" class="text-sm text-slate-600" role="status">جارٍ الرفع: {{ form.progress.percentage }}%</p>
            <p class="text-xs text-slate-500">لا يتغير السجل الرسمي قبل مراجعة القسم. إن انقطع الاتصال أثناء الإرسال أعد المحاولة؛ لن يتكرر الطلب.</p>
            <div class="aims-form-actions"><button type="submit" class="btn btn-primary" :disabled="form.processing" :aria-busy="form.processing">{{ form.processing ? 'جارٍ الإرسال…' : 'إرسال الطلب' }}</button></div>
        </form>
    </PortalLayout>
</template>
