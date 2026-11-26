<script setup>
import PortalLayout from '../../Layouts/PortalLayout.vue';
import FamilyRegistrationForm from '../../Components/Registration/FamilyRegistrationForm.vue';

defineProps({ application: Object, form: Object, documents: Array, missingDocuments: Array, targets: Object, childStatus: Object, canHealth: Boolean, options: Object, urls: Object, account: Object, fieldRules: Object });
</script>

<template>
    <PortalLayout title="طلب تسجيل أسرة" back="/guardian/requests">
        <div class="space-y-4">
            <h1 class="text-2xl font-bold text-navy-800">طلب تسجيل أسرة</h1>
            <FamilyRegistrationForm :application="application" :form="form" :documents="documents" :missing-documents="missingDocuments" :child-status="childStatus"
                :can-health="canHealth" :options="options" :urls="urls" :account="account" :field-rules="fieldRules" portal />
        </div>
    </PortalLayout>
</template>
