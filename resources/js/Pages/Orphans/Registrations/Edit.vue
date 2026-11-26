<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import FamilyRegistrationForm from '../../../Components/Registration/FamilyRegistrationForm.vue';

defineProps({ application: Object, form: Object, documents: Array, missingDocuments: Array, targets: Object, childStatus: Object, canHealth: Boolean, options: Object, urls: Object, fieldRules: Object });
</script>

<template>
    <AppLayout title="طلب تسجيل أسرة">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="إدارة الأيتام والأوصياء" title="طلب تسجيل أسرة" description="إدخال من القسم نيابة عن الأسرة. بعد الإرسال يدخل الطلب «الطلبات والمراجعات»، ويُبت في كل طفل ووصايته على حدة." />
            <p v-if="!canHealth" class="alert-info">لا تملك قدرة «البيانات الصحية»؛ قسم الصحة مخفي، وما أدخله غيرك فيه يبقى محفوظًا كما هو.</p>
            <FamilyRegistrationForm :application="application" :form="form" :documents="documents" :missing-documents="missingDocuments" :child-status="childStatus"
                :can-health="canHealth" :options="options" :urls="urls" :field-rules="fieldRules" />
        </div>
    </AppLayout>
</template>
