<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmployeeFileView from '../../Components/Employee/EmployeeFileView.vue';

/**
 * «ملفي الوظيفي»: the signed-in employee's own file with the sections the employee may see. The employee may add
 * ordinary documents (certificates) and a new photo; both wait for HR verification or review.
 */
const props = defineProps({
    employee: { type: Object, default: null },
    sections: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
    qualifications: { type: Array, default: () => [] },
    placements: { type: Array, default: () => [] },
    photo: { type: Object, default: null },
    documents: { type: Object, default: null },
    documentTypes: { type: Array, default: () => [] },
    pay: { type: Object, default: null },
    bank: { type: Object, default: null },
    payChange: { type: Object, default: null },
    payChoices: { type: Object, default: null },
    leave: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    provenance: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    fileLimits: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success || '');
</script>

<template>
    <AppLayout title="ملفي الوظيفي">
        <div class="mx-auto max-w-6xl space-y-4">
            <PageHeader eyebrow="مساحتي" title="ملفي الوظيفي" description="بياناتك كما تحفظها الموارد البشرية. إن وجدت خطأ فراسل الموارد البشرية لتصحيحه؛ ويمكنك رفع شهاداتك لتوثيقها." />
            <p v-if="flash" class="alert-success">{{ flash }}</p>
            <EmptyState v-if="!employee" title="لا يوجد ملف موظف مرتبط بحسابك" description="تربط الموارد البشرية ملفك بحساب الدخول؛ راجعها إن كنت موظفًا." />
            <EmployeeFileView v-else v-bind="props" />
        </div>
    </AppLayout>
</template>
