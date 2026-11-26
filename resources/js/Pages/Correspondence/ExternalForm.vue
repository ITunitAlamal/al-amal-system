<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { createUuid } from '../../Support/uuid.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import CorrespondenceFiles from '../../Components/CorrespondenceFiles.vue';

const props = defineProps({ catalog: Object, settings: Object });
const idOf = (list, code) => list.find(item => item.code === code)?.id;
const form = useForm({
    creation_key: createUuid(),
    direction: 'incoming',
    external_party: '',
    external_reference: '',
    external_date: new Date().toLocaleDateString('en-CA'),
    type_value_id: idOf(props.catalog.types, 'letter') || props.catalog.types[0]?.id || '',
    classification_value_id: idOf(props.catalog.classifications, 'internal') || props.catalog.classifications[0]?.id || '',
    priority_value_id: idOf(props.catalog.priorities, 'normal') || props.catalog.priorities[0]?.id || '',
    subject: '',
    body: '',
    attachments: [],
});

function submit() {
    if (form.processing) return;
    form.transform(data => ({
        ...data,
        type_value_id: Number(data.type_value_id),
        classification_value_id: Number(data.classification_value_id),
        priority_value_id: Number(data.priority_value_id),
        external_reference: data.external_reference || null,
        body: data.body || null,
    }));
    form.post('/correspondence/external', { forceFormData: true });
}
</script>

<template>
    <AppLayout title="أرشفة مراسلة خارجية">
        <form class="mx-auto max-w-4xl space-y-5 pb-24 sm:pb-0" @submit.prevent="submit">
            <PageHeader title="أرشفة مراسلة خارجية" description="سجّل البريد الوارد أو الصادر واحفظ نسخته في الأرشيف. هذا السجل لا يُنشئ مهمة أو مسار إحالة داخليًا.">
                <Link href="/correspondence?channel=external&direction=incoming" class="btn btn-secondary">العودة للأرشيف الخارجي</Link>
            </PageHeader>

            <div v-if="Object.keys(form.errors).length" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert" aria-label="أخطاء التحقق">
                <p class="mb-2 font-bold">يرجى مراجعة الحقول التالية:</p>
                <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            </div>

            <section class="card space-y-5">
                <div class="border-b border-slate-100 pb-3">
                    <h2 class="font-bold text-navy-800">بيانات القيد الخارجي</h2>
                    <p class="mt-1 text-sm text-slate-500">المرفق اختياري. تُتاح السجلات لأعضاء قسم السكرتاريا ومدير النظام.</p>
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-sm font-semibold">الاتجاه</legend>
                    <div class="flex flex-wrap gap-3">
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-4 py-3 text-sm">
                            <input v-model="form.direction" type="radio" value="incoming" />
                            وارد خارجي
                        </label>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-4 py-3 text-sm">
                            <input v-model="form.direction" type="radio" value="outgoing" />
                            صادر خارجي
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-semibold">
                        {{ form.direction === 'incoming' ? 'الجهة المرسلة' : 'الجهة المرسل إليها' }}
                        <input v-model="form.external_party" class="field mt-1 w-full" required maxlength="255" />
                    </label>
                    <label class="block text-sm font-semibold">رقم المراسلة لدى الجهة
                        <input v-model="form.external_reference" class="field mt-1 w-full" maxlength="150" />
                    </label>
                    <label class="block text-sm font-semibold">تاريخ المراسلة
                        <input v-model="form.external_date" type="date" class="field mt-1 w-full" required />
                    </label>
                    <label class="block text-sm font-semibold">نوع المراسلة
                        <select v-model="form.type_value_id" class="field mt-1 w-full" required>
                            <option v-for="type in catalog.types" :key="type.id" :value="type.id">{{ type.name_ar }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold">تصنيف السرية
                        <select v-model="form.classification_value_id" class="field mt-1 w-full" required>
                            <option v-for="classification in catalog.classifications" :key="classification.id" :value="classification.id">{{ classification.name_ar }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold">الأولوية
                        <select v-model="form.priority_value_id" class="field mt-1 w-full" required>
                            <option v-for="priority in catalog.priorities" :key="priority.id" :value="priority.id">{{ priority.name_ar }}</option>
                        </select>
                    </label>
                </div>

                <label class="block text-sm font-semibold">الموضوع
                    <input v-model="form.subject" class="field mt-1 w-full" required maxlength="250" />
                </label>
                <label class="block text-sm font-semibold">ملخص اختياري
                    <textarea v-model="form.body" class="field mt-1 w-full leading-7" rows="4" maxlength="50000"></textarea>
                </label>
                <CorrespondenceFiles v-model="form.attachments" :settings="settings" :progress="form.progress" :disabled="form.processing" />
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ في الأرشيف' }}</button>
                <Link href="/correspondence?channel=external&direction=incoming" class="btn btn-secondary">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>
