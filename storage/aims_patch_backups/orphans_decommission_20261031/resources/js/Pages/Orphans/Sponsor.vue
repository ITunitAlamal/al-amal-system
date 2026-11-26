<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// One sponsor: details, agreements, sponsorships and the manual contact log (AIMS sends nothing to sponsors).
const props = defineProps({
    sponsor: { type: Object, required: true },
    fund: { type: Object, default: null },
    sponsorships: { type: Array, default: () => [] },
    agreements: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});
const form = useForm({ contacted_on: new Date().toISOString().slice(0, 10), channel: 'phone', direction: 'outgoing', subject: '', summary: '', outcome: '', follow_up_on: '' });
function record() { form.post(`/orphans/sponsors/${props.sponsor.public_id}/contacts`, { preserveScroll: true, onSuccess: () => form.reset('subject', 'summary', 'outcome', 'follow_up_on') }); }
function done(contact) { router.post(`/orphans/sponsor-contacts/${contact.id}/done`, {}, { preserveScroll: true }); }
const lineTone = { draft: 'warning', pending_approval: 'info', active: 'success', paused: 'muted', ended: 'muted', rejected: 'danger' };
</script>

<template>
    <AppLayout :title="`الكافل ${sponsor.donor_no}`">
        <div class="space-y-4">
            <Link href="/orphans/sponsorships?tab=sponsors" class="text-sm font-semibold text-blue-700 hover:underline">→ الكفلاء</Link>
            <PageHeader eyebrow="ملف كافل" :title="sponsor.name" :description="`${sponsor.donor_no} — ${sponsor.kind_label}${sponsor.country ? ' — ' + sponsor.country : ''}`">
                <template #meta><div class="mt-2 flex gap-1"><StatusBadge :status="sponsor.status" /><StatusBadge v-if="sponsor.confidential" label="سري" tone="warning" /><StatusBadge v-if="fund" :label="`صندوق ${fund.fund_no}`" tone="info" /></div></template>
            </PageHeader>
            <section v-if="sponsor.phone || sponsor.email || sponsor.preferences" class="card grid gap-2 text-sm sm:grid-cols-3">
                <p v-if="sponsor.phone"><span class="text-slate-500">الهاتف:</span> <span dir="ltr">{{ sponsor.phone }}</span></p>
                <p v-if="sponsor.email"><span class="text-slate-500">البريد:</span> <span dir="ltr">{{ sponsor.email }}</span></p>
                <p v-if="sponsor.address"><span class="text-slate-500">العنوان:</span> {{ sponsor.address }}</p>
                <p v-if="sponsor.preferences" class="sm:col-span-3"><span class="text-slate-500">التفضيلات المعتمدة:</span> {{ sponsor.preferences }}</p>
            </section>

            <section class="card space-y-2 text-sm">
                <h2 class="card-title">سجل الاتصال</h2>
                <p class="text-xs text-slate-500">تدوين للاتصال اليدوي فقط؛ لا يرسل النظام أي رسالة للكافل.</p>
                <form v-if="can.manage" class="grid gap-3 border-b border-slate-100 pb-3 sm:grid-cols-4" data-error-scope @submit.prevent="record">
                    <FormErrorSummary :errors="form.errors" class="sm:col-span-4" />
                    <label class="block"><span class="field-label">التاريخ *</span><input v-model="form.contacted_on" type="date" class="field" required></label>
                    <label class="block"><span class="field-label">الوسيلة *</span><select v-model="form.channel" class="field"><option v-for="(label, key) in options.channels" :key="key" :value="key">{{ label }}</option></select></label>
                    <label class="block"><span class="field-label">الاتجاه *</span><select v-model="form.direction" class="field"><option v-for="(label, key) in options.directions" :key="key" :value="key">{{ label }}</option></select></label>
                    <label class="block"><span class="field-label">موعد متابعة</span><input v-model="form.follow_up_on" type="date" class="field"></label>
                    <label class="block sm:col-span-4"><span class="field-label">الموضوع *</span><input v-model="form.subject" class="field" maxlength="200" required></label>
                    <label class="block sm:col-span-2"><span class="field-label">الملخص *</span><textarea v-model="form.summary" class="field" rows="2" maxlength="3000" required></textarea></label>
                    <label class="block sm:col-span-2"><span class="field-label">النتيجة</span><textarea v-model="form.outcome" class="field" rows="2" maxlength="2000"></textarea></label>
                    <div class="sm:col-span-4"><button class="btn btn-primary btn-sm" :disabled="form.processing">تسجيل الاتصال</button></div>
                </form>
                <ul class="divide-y divide-slate-100">
                    <li v-for="c in contacts" :key="c.id" class="space-y-1 py-2">
                        <p class="flex flex-wrap items-center gap-2"><strong>{{ c.subject }}</strong><span class="text-xs text-slate-500"><span dir="ltr">{{ c.contacted_on }}</span> — {{ c.channel_label }} — {{ c.direction_label }} — {{ c.recorder }}</span>
                            <StatusBadge v-if="c.follow_up_on && !c.follow_up_done" :label="`متابعة ${c.follow_up_on}`" :tone="c.follow_up_late ? 'danger' : 'warning'" />
                            <StatusBadge v-else-if="c.follow_up_done" label="أُنجزت المتابعة" tone="success" /></p>
                        <p class="whitespace-pre-line text-slate-700">{{ c.summary }}</p>
                        <p v-if="c.outcome" class="text-xs text-emerald-800">النتيجة: {{ c.outcome }}</p>
                        <button v-if="c.can_close" type="button" class="btn btn-secondary btn-sm" @click="done(c)">إنجاز المتابعة</button>
                    </li>
                    <li v-if="!contacts.length" class="py-2 text-slate-500">لا توجد اتصالات مسجلة.</li>
                </ul>
            </section>

            <section class="card space-y-2 text-sm">
                <h2 class="card-title">الاتفاقيات</h2>
                <ul class="divide-y divide-slate-100">
                    <li v-for="a in agreements" :key="a.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/orphans/agreements/${a.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ a.agreement_no }}</Link> — {{ a.kind_label }} — <span dir="ltr">{{ a.starts_on }} – {{ a.ends_on || '…' }}</span></span>
                        <span class="flex gap-1"><StatusBadge :label="a.status_label" :tone="a.status === 'signed' ? 'success' : 'muted'" /><StatusBadge v-if="a.renewal_due" label="حان التجديد" tone="danger" /></span>
                    </li>
                    <li v-if="!agreements.length" class="py-2 text-slate-500">لا توجد اتفاقيات.</li>
                </ul>
                <h2 class="card-title pt-2">الكفالات</h2>
                <ul class="divide-y divide-slate-100">
                    <li v-for="s in sponsorships" :key="s.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/orphans/sponsorships/${s.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ s.sponsorship_no }}</Link> — {{ s.beneficiary }} — <span dir="ltr">{{ s.amount }} {{ s.currency }}</span> ({{ s.frequency }})</span>
                        <StatusBadge :label="s.status_label" :tone="lineTone[s.status] || 'muted'" />
                    </li>
                    <li v-if="!sponsorships.length" class="py-2 text-slate-500">لا توجد كفالات.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
