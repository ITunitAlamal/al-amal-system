<script setup>
import { ref } from 'vue';
import PageHeader from '../../../resources/js/Components/PageHeader.vue';
import FormSteps from '../../../resources/js/Components/FormSteps.vue';
import AppDialog from '../../../resources/js/Components/AppDialog.vue';
import AsyncState from '../../../resources/js/Components/AsyncState.vue';
import EmptyState from '../../../resources/js/Components/EmptyState.vue';
import PersonFields from '../../../resources/js/Components/Registry/PersonFields.vue';
const open = ref(false);
const step = ref(0);
const person = ref({});
</script>
<template>
    <main class="aims-shell mx-auto max-w-7xl space-y-5 p-4 sm:p-6">
        <PageHeader title="تسجيل يتيم ومراجعة بياناته" eyebrow="بيئة اختبار الواجهة" description="بيانات هذه الصفحة للاختبار فقط؛ لا تتصل بسجلات المؤسسة.">
            <button id="preview" class="btn btn-primary" @click="open = true">معاينة التفاصيل</button>
            <button class="btn btn-secondary">العودة إلى السجل</button>
        </PageHeader>
        <section class="card"><FormSteps v-model="step" :steps="['الوصي', 'بيانات الطفل', 'العلاقة القانونية', 'الوالدان', 'المراجعة والإرسال']" /></section>
        <section class="card"><PersonFields :model-value="person" :options="{}" /></section>
        <section class="card"><EmptyState title="لا توجد نتائج مطابقة" description="عدّل شروط البحث ثم أعد المحاولة." /></section>
        <AppDialog :open="open" title="معاينة بيانات السجل" description="اختبار التركيز والإغلاق واستعادة موضع المستخدم" drawer @close="open = false">
            <AsyncState :loading="true" />
            <button id="inside" class="btn btn-primary">إجراء داخل المعاينة</button>
        </AppDialog>
    </main>
</template>
