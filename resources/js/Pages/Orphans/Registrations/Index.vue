<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import EmptyState from '../../../Components/EmptyState.vue';

defineProps({ rows: Array });
</script>

<template>
    <AppLayout title="طلبات تسجيل الأسر">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader eyebrow="إدارة الأيتام والأوصياء" title="طلبات تسجيل الأسر (مسودات القسم)" description="طلب واحد للأسرة بعدة أيتام. المسودة لا يراها المراجعون حتى تُرسل." />
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" @click="router.post('/orphans/registrations')">طلب تسجيل أسرة جديد</button>
                <Link href="/orphans/reviews" class="btn btn-secondary">الطلبات والمراجعات</Link>
            </div>
            <section class="card">
                <table v-if="rows.length" class="data-table">
                    <thead><tr><th>رقم الطلب</th><th>مقدم الطلب</th><th>الحالة</th><th>آخر حفظ</th><th>أنشأه</th><th></th></tr></thead>
                    <tbody><tr v-for="r in rows" :key="r.public_id">
                        <td class="font-mono" dir="ltr">{{ r.reference_no }}</td><td>{{ r.full_name }}</td><td>{{ r.status_label }}<span v-if="r.completion_request" class="block text-xs text-gold-800">{{ r.completion_request }}</span></td>
                        <td>{{ String(r.last_saved_at || '').slice(0, 16) }}</td><td>{{ r.creator }}</td>
                        <td><Link :href="`/orphans/registrations/${r.public_id}`" class="btn btn-sm btn-secondary">متابعة</Link></td>
                    </tr></tbody>
                </table>
                <EmptyState v-else title="لا توجد مسودات" description="ابدأ طلب تسجيل أسرة جديد؛ يمكن حفظه جزئيًا وإكماله لاحقًا." />
            </section>
        </div>
    </AppLayout>
</template>
