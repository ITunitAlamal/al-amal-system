<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import OrphanPicker from '../../../Components/Registry/OrphanPicker.vue';

const props = defineProps({ sponsorship: Object, beneficiaries: Array, dues: Array, benefits: Array, labels: Object, abilities: Object });
const s = props.sponsorship;
const household = ref('');
const act = (action, extra = {}) => router.post(`/orphans/aid/sponsorships/${s.public_id}`, { action, ...extra }, { preserveScroll: true });
function setStatus(status) { const reason = ['suspended', 'ended'].includes(status) ? window.prompt('السبب:') : null; if (['suspended', 'ended'].includes(status) && !reason) return; act('status', { status, reason }); }
function endLine(line) { const reason = window.prompt('سبب إنهاء استفادة هذا المستفيد:'); if (reason) act('end_line', { line_id: line.id, reason }); }
const dueStatus = { planned: ['مخطط', 'muted'], referred: ['مُحال للحسابات', 'info'], cancelled: ['ملغى', 'muted'] };
</script>

<template>
    <AppLayout :title="`الكفالة ${s.reference_no}`">
        <div class="mx-auto max-w-6xl space-y-5">
            <header class="card space-y-2">
                <Link href="/orphans/aid" class="text-sm text-blue-700 hover:underline">← الكفالات والمساعدات</Link>
                <h1 class="text-2xl font-bold text-navy-800">كفالة <span class="font-mono" dir="ltr">{{ s.reference_no }}</span></h1>
                <p class="text-sm text-slate-600">الكافل: {{ s.sponsor_name }} · {{ labels.coverage[s.coverage] }} · {{ s.amount }} {{ s.currency }} كل {{ s.period_months }} شهر · {{ s.starts_on }} ← {{ s.ends_on || 'مفتوحة' }}<span v-if="s.program"> · {{ s.program }}</span></p>
                <div class="flex flex-wrap items-center gap-2"><StatusBadge :label="labels.sponsorship[s.effective_status]" tone="info" /><span v-if="s.status_reason" class="text-sm text-slate-600">{{ s.status_reason }}</span>
                    <template v-if="abilities.assign">
                        <button v-if="['draft', 'suspended'].includes(s.status)" type="button" class="btn btn-sm btn-primary" @click="setStatus('active')">تفعيل</button>
                        <button v-if="s.status === 'active'" type="button" class="btn btn-sm btn-secondary" @click="setStatus('partial')">تعليم كتغطية جزئية</button>
                        <button v-if="['active', 'partial'].includes(s.status)" type="button" class="btn btn-sm btn-secondary" @click="setStatus('suspended')">إيقاف</button>
                        <button v-if="s.status !== 'ended'" type="button" class="btn btn-sm btn-danger" @click="setStatus('ended')">إنهاء</button></template></div>
            </header>
            <section class="card space-y-3"><h2 class="card-title">المستفيدون</h2>
                <ul class="divide-y divide-slate-100 text-sm"><li v-for="b in beneficiaries" :key="b.id" class="flex flex-wrap items-center gap-2 py-2">
                    <Link v-if="b.orphan_public_id" :href="`/orphans/registry/orphans/${b.orphan_public_id}?tab=aid`" class="text-blue-700 hover:underline">{{ b.full_name }}</Link><span v-else class="font-mono" dir="ltr">{{ b.household_reference }} (أسرة)</span>
                    <span class="text-slate-500">منذ {{ b.starts_on }}<span v-if="b.ends_on"> حتى {{ b.ends_on }} — {{ b.ended_reason }}</span></span><span v-if="b.share_amount" class="text-slate-500">· الحصة {{ b.share_amount }}</span>
                    <button v-if="!b.ends_on && abilities.sponsorships" type="button" class="btn btn-sm btn-secondary ms-auto" @click="endLine(b)">إنهاء</button></li></ul>
                <div v-if="abilities.sponsorships && s.status !== 'ended'" class="grid gap-3 sm:grid-cols-2">
                    <OrphanPicker label="إضافة يتيم" @pick="p => act('add_orphan', { orphan_public_id: p.public_id })" />
                    <form class="flex gap-2" @submit.prevent="act('add_household', { household_reference: household })"><input v-model="household" class="field font-mono" dir="ltr" placeholder="HH-…"><button type="submit" class="btn btn-secondary">إضافة أسرة</button></form>
                </div>
            </section>
            <section class="card space-y-3">
                <div class="flex items-center justify-between"><h2 class="card-title">الاستحقاقات والتحصيل من الحسابات</h2>
                    <button v-if="abilities.sponsorships && ['active', 'partial'].includes(s.status)" type="button" class="btn btn-sm btn-secondary" @click="act('schedule')">تحديث الجدول</button></div>
                <p class="text-xs text-slate-500">الاستحقاق التزام من الكافل؛ «المُحصَّل» يُقرأ من سندات الحسابات المنفذة المخصصة لهذا المستحق، ولا يُسجَّل هنا.</p>
                <table v-if="dues.length" class="data-table"><thead><tr><th scope="col">الفترة</th><th scope="col">المبلغ</th><th scope="col">الحالة</th><th scope="col">مرجع الحسابات</th><th scope="col">المُحصَّل</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                    <tbody><tr v-for="d in dues" :key="d.id"><td>{{ d.period_start }}</td><td class="tabular-nums">{{ d.amount }} {{ d.currency }}</td><td><StatusBadge :label="dueStatus[d.status][0]" :tone="dueStatus[d.status][1]" /></td>
                        <td class="font-mono text-xs" dir="ltr">{{ d.receivable || '—' }}</td><td class="tabular-nums">{{ d.collected ?? 'غير متاح' }}</td>
                        <td><button v-if="d.status === 'planned' && abilities.sponsorships && ['active', 'partial'].includes(s.status)" type="button" class="btn btn-sm btn-primary" @click="act('refer', { due_id: d.id })">إحالة للحسابات</button></td></tr></tbody></table>
                <p v-else class="text-sm text-slate-500">لا توجد استحقاقات؛ تُنشأ عند تفعيل الكفالة.</p>
            </section>
            <section class="card space-y-2"><h2 class="card-title">استفادات مؤكدة مرتبطة بهذه الكفالة</h2>
                <ul class="space-y-1 text-sm"><li v-for="b in benefits" :key="b.public_id">{{ b.delivered_on }} · {{ b.description }} · {{ b.recipient_name }} · <span class="font-mono" dir="ltr">{{ b.fulfillment_ref }}</span></li></ul>
                <p v-if="!benefits.length" class="text-sm text-slate-500">لا توجد.</p></section>
        </div>
    </AppLayout>
</template>
