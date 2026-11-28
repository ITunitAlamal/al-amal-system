<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';

// Read-only list of father/mother links that cannot all be true (mostly from earlier imports). Nothing is changed
// here: each case is corrected from the orphan file, and the correction is a sensitive change the head approves.
defineProps({ kind: String, kinds: Object, counts: Object, rows: Object });
const fileUrl = (orphan) => `/orphans/registry/orphans/${orphan.public_id}`;
</script>

<template>
    <AppLayout title="روابط الأبوين المشكوك فيها">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="الطلبات والمراجعات" title="روابط الأبوين المشكوك فيها"
                description="روابط أب وأم في السجل لا يمكن أن تصح كلها، وأغلبها من استيراد سابق طابق رقم هوية بشخص آخر. القائمة للاطلاع فقط ولا يتغير منها أي سجل: افتح ملف اليتيم وصحّح الأب أو الأم، فيُحفظ التصحيح تعديلًا حساسًا يعتمده رئيس القسم. إذا كان الأب والأم مقلوبين في الملف نفسه فاختر الأم المسجلة أبًا (أو العكس)، فيُبدَّلان في هذا الملف وفي ملفات الأشقاء التي تحمل الأبوين أنفسهم معًا، ويرى رئيس القسم هذه الملفات قبل الاعتماد. إذا كان أحدهما مربوطًا بالصفة الأخرى في ملف آخر لا يحمل الأبوين أنفسهم فلا يتغير شيء، وتظهر أرقام تلك الملفات لتصحيحها أولًا." />

            <nav class="flex flex-wrap gap-2 text-sm" aria-label="نوع الحالة">
                <button v-for="(label, key) in kinds" :key="key" type="button" :aria-current="kind === key ? 'page' : undefined"
                    :class="['rounded-full px-3 py-1', kind === key ? 'bg-blue-100 text-blue-900' : 'bg-slate-100']"
                    @click="router.get('/orphans/reviews/parent-links', { kind: key }, { preserveScroll: true })">{{ label }} ({{ counts[key] ?? 0 }})</button>
                <Link href="/orphans/reviews/sensitive" class="rounded-full px-3 py-1 underline">التعديلات الحساسة</Link>
            </nav>

            <EmptyState v-if="!rows.data.length" title="لا توجد حالات" description="لا توجد روابط من هذا النوع في السجل." />

            <ul v-else-if="kind === 'birth_after_death'" class="space-y-3">
                <li v-for="row in rows.data" :key="row.orphan.public_id" class="card flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <Link :href="fileUrl(row.orphan)" class="font-bold text-blue-700 hover:underline">{{ row.orphan.name }}</Link>
                        <span class="ms-2 font-mono text-xs text-slate-500" dir="ltr">{{ row.orphan.reference_no }}</span>
                        <p class="text-sm text-slate-600">الأب المربوط: {{ row.father }} · الميلاد بعد الوفاة المسجلة بـ {{ row.days_after }} يومًا</p>
                    </div>
                    <StatusBadge :label="row.orphan.status" tone="muted" />
                </li>
            </ul>

            <ul v-else class="space-y-3">
                <li v-for="(row, index) in rows.data" :key="index" class="card space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-bold text-navy-800">{{ row.person.name }}
                            <span class="text-sm font-normal text-slate-600">· الجنس: {{ row.person.sex }} · الحالة: {{ row.person.vital }}</span></p>
                        <Link v-if="row.own_file" :href="fileUrl(row.own_file)" class="text-sm text-blue-700 hover:underline">له ملف يتيم {{ row.own_file.reference_no }}</Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-slate-600"><th class="text-start">مربوط بصفة</th><th class="text-start">ملف اليتيم</th><th class="text-start">حالة الملف</th><th class="text-start">المصدر</th></tr></thead>
                            <tbody><tr v-for="link in row.links" :key="link.orphan.public_id + link.relation" class="border-t border-slate-100">
                                <td class="py-1 font-semibold">{{ link.relation }}</td>
                                <td><Link :href="fileUrl(link.orphan)" class="text-blue-700 hover:underline">{{ link.orphan.name }}</Link>
                                    <span class="ms-1 font-mono text-xs text-slate-500" dir="ltr">{{ link.orphan.reference_no }}</span></td>
                                <td>{{ link.orphan.status }}</td>
                                <td>{{ link.by_import ? 'استيراد' : 'إدخال يدوي' }}</td></tr></tbody>
                        </table>
                    </div>
                </li>
            </ul>

            <Pagination :page="rows" />
        </div>
    </AppLayout>
</template>
