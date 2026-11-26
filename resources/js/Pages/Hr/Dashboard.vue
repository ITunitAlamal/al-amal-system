<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import ServicesWorkspace from '../../Components/Hr/ServicesWorkspace.vue';
const props = defineProps({
    pages: { type: Object, default: () => ({}) },
    today: { type: String, required: true },
    filters: { type: Object, required: true },
    attendanceStatuses: { type: Object, default: () => ({}) },
    serviceFilters: { type: Object, default: () => ({}) },
    links: { type: Array, default: () => [] },
    kpis: { type: Object, default: null },
    actions: { type: Object, default: null },
    attendance: { type: Object, default: null },
    recentLeaves: { type: Object, default: null },
    services: { type: Object, default: null },
});
</script>

<template>
    <AppLayout title="الموارد البشرية والخدمات العامة">
        <ServicesWorkspace v-bind="props" />
    </AppLayout>
</template>
