<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DynamicFields from '../../Components/DynamicFields.vue';
import PageHeader from '../../Components/PageHeader.vue';
import RequestAttachmentsInput from '../../Components/Workflow/RequestAttachmentsInput.vue';

const props = defineProps({
    definition: { type: Object, required: true },
    fields: { type: Array, default: () => [] },
    pickOptions: { type: Array, default: () => [] },
    hasDepartment: { type: Boolean, default: false },
    attachmentPolicy: { type: Object, default: null },
    // A draft saved on the old maintenance screen ({title, description, fields}); null otherwise.
    initial: { type: Object, default: null },
    // «طلب ملحق»: the maintenance request this one is opened from ({publicId, referenceNo, title}); null otherwise.
    parent: { type: Object, default: null },
});

const form = useForm({
    title: props.initial?.title ?? '', description: props.initial?.description ?? '', fields: { ...(props.initial?.fields ?? {}) },
    picked_user_id: null, attachments: [],
    ...(props.parent ? { parent: props.parent.publicId, addendum_required: true } : {}),
});
function submit() {
    form.post(`/requests/new/${props.definition.code}`);
}
</script>

<template>
    <AppLayout :title="definition.name">
        <div class="mx-auto max-w-3xl space-y-5">
            <Link href="/requests" class="text-sm font-semibold text-blue-700 hover:underline">→ طلباتي</Link>
            <PageHeader eyebrow="طلب جديد" :title="definition.name" :description="definition.description || ''" />

            <div v-if="initial" class="alert-info" role="status">نُقل نص مسودتك المحفوظة في شاشة الصيانة السابقة إلى هذا النموذج. راجعه وأكمل الحقول المطلوبة ثم أرسل الطلب.</div>
            <div v-if="!hasDepartment" class="alert-warning">حسابك غير مرتبط بقسم، لذلك لا يمكن توجيه الطلب لمسؤوله. راجع الموارد البشرية.</div>
            <div v-if="parent" class="alert-info space-y-2" role="status">
                <p>طلب ملحق بالطلب <strong>{{ parent.referenceNo }}</strong> — {{ parent.title }}. اكتب المشكلة الجديدة وسببها والأثر على مدة العمل؛ تُحدَّد احتياجاته وتكلفته ويُعتمد في مساره الخاص، ولا يتغير الطلب الأصلي.</p>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input v-model="form.addendum_required" type="checkbox" />
                    لازم لإغلاق الطلب الأصلي (لا يُغلق الأصل قبل معالجة هذا الملحق أو قرار فصل)
                </label>
            </div>

            <form class="card space-y-5 p-6" @submit.prevent="submit">
                <div>
                    <label for="req-title" class="field-label">عنوان الطلب <span class="text-red-600">*</span></label>
                    <input id="req-title" v-model="form.title" required maxlength="200" class="field" />
                    <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label for="req-description" class="field-label">الوصف والتفاصيل</label>
                    <textarea id="req-description" v-model="form.description" rows="4" maxlength="10000" class="field" />
                    <p v-if="form.errors.description" class="field-error">{{ form.errors.description }}</p>
                </div>

                <DynamicFields v-model="form.fields" :fields="fields" :errors="form.errors" />

                <div v-if="pickOptions.length">
                    <label for="req-pick" class="field-label">المسؤول عن المرحلة التالية <span class="text-red-600">*</span></label>
                    <select id="req-pick" v-model="form.picked_user_id" required class="field">
                        <option :value="null" disabled>اختر</option>
                        <option v-for="option in pickOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
                    </select>
                    <p v-if="form.errors.picked_user_id" class="field-error">{{ form.errors.picked_user_id }}</p>
                </div>

                <RequestAttachmentsInput v-if="attachmentPolicy" v-model="form.attachments" :policy="attachmentPolicy" :errors="form.errors"
                    :progress="form.progress" :disabled="form.processing" input-id="req-attachments" />

                <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                    <button type="submit" :disabled="form.processing || !hasDepartment" class="btn btn-primary px-6 py-2.5">{{ form.processing ? 'جارٍ الإرسال...' : 'إرسال الطلب' }}</button>
                    <Link href="/requests" class="btn btn-secondary px-6 py-2.5">إلغاء</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
