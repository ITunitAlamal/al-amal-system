<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import HomeWorkspace from '../../Components/Hr/HomeWorkspace.vue';

defineProps({ today: String, hrSummary: Object, hrActions: Object, hrFollowUp: Object, departmentBudgets: Object, myRequests: Object, links: { type: Array, default: () => [] } });
</script>

<template>
    <AppLayout title="مساحتي">
        <HomeWorkspace :today="today" :hr-summary="hrSummary" :hr-actions="hrActions" :hr-follow-up="hrFollowUp" :department-budgets="departmentBudgets"
            :my-requests="myRequests" :links="links" />
    </AppLayout>
</template>
