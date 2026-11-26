<script setup>
import PortalLayout from '../../Layouts/PortalLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { formatDate } from '../../Support/dates.js';

// «مساعداتي وكفالاتي»: only confirmed deliveries (benefit records), aid ready for pickup from an approved list, and
// sponsorships when the department chose to show them. A nomination is never shown as aid.
defineProps({ linked: Boolean, delivered: Array, ready: Array, sponsorships: Array });
</script>

<template>
    <PortalLayout title="مساعداتي وكفالاتي">
        <h1 class="text-2xl font-bold text-navy-800">مساعداتي وكفالاتي</h1>
        <EmptyState v-if="!linked" title="تظهر المساعدات بعد اعتماد حسابك" description="بعد اعتماد القسم لحسابك ووصايتك تظهر هنا المساعدات المسلّمة فعلًا والمستحقة للاستلام." />
        <template v-else>
            <section v-if="ready.length" class="card space-y-3 border-gold-200 p-5" aria-labelledby="ready-title">
                <h2 id="ready-title" class="card-title">جاهزة للاستلام</h2>
                <ul class="space-y-2 text-sm">
                    <li v-for="(r, i) in ready" :key="i" class="rounded-xl bg-gold-50 p-3"><p class="font-semibold text-gold-900">{{ r.campaign }} — {{ r.for }}<span v-if="r.item"> · {{ r.item }}</span></p>
                        <p v-if="r.instructions" class="mt-1 text-slate-700">{{ r.instructions }}</p><p v-if="r.until" class="mt-1 text-xs text-slate-500">حتى {{ formatDate(r.until) }}</p></li>
                </ul>
            </section>

            <section class="card space-y-3 p-5" aria-labelledby="delivered-title">
                <h2 id="delivered-title" class="card-title">مساعدات استلمتها</h2>
                <div v-if="delivered.length" class="aims-table-scroll">
                    <table class="data-table aims-responsive-table">
                        <thead><tr><th scope="col">التاريخ</th><th scope="col">المساعدة</th><th scope="col">لـ</th><th scope="col">الكمية أو المبلغ</th><th scope="col">المستلم</th><th scope="col">المرجع</th></tr></thead>
                        <tbody><tr v-for="d in delivered" :key="d.public_id">
                            <td data-label="التاريخ">{{ formatDate(d.date) }}</td><td data-label="المساعدة">{{ d.category }}<span v-if="d.description" class="block text-xs text-slate-500">{{ d.description }}</span></td>
                            <td data-label="لـ">{{ d.for }}</td><td data-label="الكمية أو المبلغ" class="tabular-nums">{{ d.quantity || d.amount || '—' }}</td><td data-label="المستلم">{{ d.recipient || '—' }}</td>
                            <td data-label="المرجع" class="font-mono" dir="ltr">{{ d.reference || '—' }}</td></tr></tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-500">لا توجد مساعدات مسلّمة مسجلة بعد. يظهر هنا ما سُلّم فعلًا فقط.</p>
            </section>

            <section v-if="sponsorships.length" class="card space-y-2 p-5" aria-labelledby="sp-title">
                <h2 id="sp-title" class="card-title">الكفالات</h2>
                <ul class="text-sm"><li v-for="(s, i) in sponsorships" :key="i">{{ s.for }} — مكفول منذ {{ formatDate(s.since, undefined, '—') }}</li></ul>
            </section>
        </template>
    </PortalLayout>
</template>
