<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import GeographicAddress from '../../../Components/GeographicAddress.vue';
import PersonFields from '../../../Components/Registry/PersonFields.vue';
import { emptyAddress, emptyPerson } from '../../../Support/orphans.js';

const props = defineProps({ options: Object });
const form = useForm({ person: emptyPerson(), person_public_id: null, existing: null, notes: '', address: emptyAddress() });
function useExisting(match) { form.person_public_id = match.public_id; form.existing = match; }
function submit() { form.transform(({ existing, ...data }) => data).post('/orphans/registry/guardians'); }
</script>

<template>
    <AppLayout title="إضافة وصي">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader eyebrow="إدارة الأيتام والأوصياء" title="إضافة وصي" description="يمكن تسجيل الوصي قبل ربط أي يتيم به. تسجيل الوصي لا يعني اعتماد وصايته على أي طفل." />
            <form class="card space-y-5" @submit.prevent="submit">
                <FormErrorSummary :errors="form.errors" />
                <div v-if="form.existing" class="alert-info flex flex-wrap items-center gap-2">
                    سيتم اعتماد الشخص المسجل «{{ form.existing.full_name }}» وصيًا دون إنشاء سجل مكرر.
                    <button type="button" class="btn btn-sm btn-secondary" @click="form.person_public_id = null; form.existing = null">إلغاء الاختيار</button>
                </div>
                <PersonFields v-else v-model="form.person" prefix="person." :errors="form.errors" :options="options" who="الوصي" @use-existing="useExisting" />
                <fieldset v-if="!form.existing"><legend class="field-label">العنوان الحالي (اختياري)</legend>
                    <GeographicAddress :model-value="form.address" :errors="form.errors" prefix="address." optional @update:model-value="Object.assign(form.address, $event)" /></fieldset>
                <label class="block text-sm">ملاحظات<textarea v-model="form.notes" class="field mt-1" rows="3" maxlength="2000" /></label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ الوصي' }}</button>
                    <Link href="/orphans/registry?view=guardians" class="btn btn-secondary">رجوع</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
