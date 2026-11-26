<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// One sponsorship: terms, approval stepper, versions, dues and what Accounts recorded (collections, payouts, savings).
const props = defineProps({
    sponsorship: { type: Object, required: true },
    sponsor: { type: Object, required: true },
    figures: { type: Object, default: null },
    schedules: { type: Array, default: () => [] },
    collections: { type: Array, default: () => [] },
    disbursements: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    agreement: { type: Object, default: null },
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
    options: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
const s = computed(() => props.sponsorship);
const base = `/orphans/sponsorships/${props.sponsorship.public_id}`;
const tone = { draft: 'warning', pending_approval: 'info', active: 'success', paused: 'muted', ended: 'muted', rejected: 'danger', cancelled: 'muted', superseded: 'muted' };
const scheduleTone = { expected: 'info', partial: 'warning', collected: 'success', waived: 'muted', cancelled: 'muted' };
const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const form = useForm({ sponsor_id: s.value.sponsor_id, beneficiary_type: s.value.beneficiary_type, beneficiary_reference: s.value.beneficiary_reference, type_value_id: s.value.type_value_id,
    amount: s.value.amount, currency: s.value.currency, frequency_value_id: s.value.frequency_value_id, purpose_value_id: s.value.purpose_value_id, savings_amount: s.value.savings_amount,
    starts_on: s.value.starts_on, ends_on: s.value.ends_on, conditions: s.value.conditions, source: s.value.source });
const purpose = computed(() => (props.options?.sponsorship_purposes || []).find(p => p.id === form.purpose_value_id)?.flags || {});
const split = computed(() => purpose.value.expenses && purpose.value.savings);
const editing = ref(false);
function save() { form.put(base, { preserveScroll: true, onSuccess: () => { editing.value = false; } }); }
function act(action, needsReason = false, extra = {}) {
    let reason;
    if (needsReason) {
        reason = prompt('اذكر السبب:');
        if (!reason) return;
    }
    router.post(`${base}/${action}`, { reason, ...extra }, { preserveScroll: true });
}
function end() {
    const endsOn = prompt('تاريخ انتهاء الكفالة (YYYY-MM-DD)، فارغ = اليوم:') ?? null;
    if (endsOn === null) return;
    act('end', true, { ends_on: endsOn || undefined });
}
</script>

<template>
    <AppLayout :title="`الكفالة ${sponsorship.sponsorship_no}`">
        <div class="mx-auto max-w-6xl space-y-4">
            <Link href="/orphans/sponsorships" class="text-sm font-semibold text-blue-700 hover:underline">→ الكفالات</Link>
            <PageHeader :eyebrow="sponsorship.is_amendment ? `تعديل كفالة — الإصدار ${sponsorship.version}` : 'كفالة'" :title="`${sponsorship.sponsorship_no} — ${sponsorship.beneficiary}`"
                description="الاعتماد للمدير التنفيذي بعد توصية رئيس القسم. التحصيل والصرف والادخار من سجلات الحسابات فقط.">
                <template #meta><div class="mt-2 flex flex-wrap gap-2"><StatusBadge :label="sponsorship.status_label" :tone="tone[sponsorship.status]" /><StatusBadge v-if="sponsorship.regularity && ['active', 'paused'].includes(sponsorship.status)" :label="`الانتظام: ${sponsorship.regularity}`" tone="muted" /></div></template>
                <button v-if="sponsorship.editable && !editing" type="button" class="btn btn-secondary btn-sm" @click="editing = true">تعديل المسودة</button>
                <button v-if="sponsorship.editable && !sponsorship.submitted" type="button" class="btn btn-primary btn-sm" @click="act('submit')">رفع للتوصية والاعتماد</button>
                <button v-if="sponsorship.editable && !sponsorship.submitted" type="button" class="btn btn-secondary btn-sm" @click="act('cancel', true)">إلغاء المسودة</button>
                <button v-if="can.amend" type="button" class="btn btn-secondary btn-sm" @click="act('amend')">تعديل بإصدار جديد</button>
                <button v-if="can.head && sponsorship.status === 'active'" type="button" class="btn btn-secondary btn-sm" @click="act('pause', true)">إيقاف مؤقت</button>
                <button v-if="can.head && sponsorship.status === 'paused'" type="button" class="btn btn-secondary btn-sm" @click="act('resume', true)">استئناف</button>
                <button v-if="can.head && ['active', 'paused'].includes(sponsorship.status)" type="button" class="btn btn-secondary btn-sm" @click="end">إنهاء الكفالة</button>
            </PageHeader>

            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>
            <WorkflowActions :workflow="workflow" />

            <section class="card">
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">الكافل</dt><dd>{{ sponsor.name }} <span dir="ltr" class="text-xs text-slate-500">{{ sponsor.donor_no }}</span></dd></div>
                    <div><dt class="text-slate-500">المستفيد</dt><dd>
                        <Link v-if="sponsorship.child_public_id" :href="`/orphans/children/${sponsorship.child_public_id}`" class="text-blue-700 hover:underline">{{ sponsorship.beneficiary }}</Link>
                        <Link v-else :href="`/orphans/households/${sponsorship.household_public_id}`" class="text-blue-700 hover:underline">{{ sponsorship.beneficiary }}</Link></dd></div>
                    <div><dt class="text-slate-500">نوع الكفالة</dt><dd>{{ sponsorship.type || '—' }}</dd></div>
                    <div><dt class="text-slate-500">المبلغ لكل دورة</dt><dd>{{ money(sponsorship.amount) }} <span dir="ltr">{{ sponsorship.currency }}</span> — {{ sponsorship.frequency }}</dd></div>
                    <div><dt class="text-slate-500">الغرض</dt><dd>{{ sponsorship.purpose }} (مصاريف {{ money(sponsorship.expense_amount) }} / ادخار {{ money(sponsorship.savings_amount) }})</dd></div>
                    <div><dt class="text-slate-500">المدة</dt><dd>من {{ sponsorship.starts_on }} {{ sponsorship.ends_on ? `حتى ${sponsorship.ends_on}` : '(مفتوحة)' }}</dd></div>
                    <div v-if="sponsorship.conditions" class="sm:col-span-2"><dt class="text-slate-500">الشروط</dt><dd>{{ sponsorship.conditions }}</dd></div>
                    <div v-if="sponsorship.source"><dt class="text-slate-500">المصدر</dt><dd>{{ sponsorship.source }}</dd></div>
                    <div><dt class="text-slate-500">أعدّها</dt><dd>{{ sponsorship.created_by }}</dd></div>
                    <div v-if="sponsorship.approved_by"><dt class="text-slate-500">اعتمدها</dt><dd>{{ sponsorship.approved_by }} — {{ String(sponsorship.approved_at).slice(0, 10) }}</dd></div>
                    <div v-if="sponsorship.status_reason" class="sm:col-span-3"><dt class="text-slate-500">ملاحظة الحالة</dt><dd>{{ sponsorship.status_reason }}</dd></div>
                    <div v-if="sponsor.phone || sponsor.email"><dt class="text-slate-500">تواصل الكافل</dt><dd dir="ltr" class="text-right">{{ sponsor.phone }} {{ sponsor.email }}</dd></div>
                </dl>
                <p v-if="agreement" class="mt-3 text-sm"><span class="text-slate-500">ضمن الاتفاقية:</span>
                    <Link :href="`/orphans/agreements/${agreement.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ agreement.agreement_no }}</Link></p>
                <p v-if="versions.length" class="mt-3 text-sm"><span class="text-slate-500">الإصدارات:</span>
                    <Link v-for="v in versions" :key="v.public_id" :href="`/orphans/sponsorships/${v.public_id}`" class="me-3 text-blue-700 hover:underline">{{ v.sponsorship_no }} ({{ v.status_label }})</Link></p>
            </section>

            <form v-if="editing && options" class="card space-y-3" data-error-scope @submit.prevent="save">
                <h2 class="card-title">تعديل المسودة</h2>
                <FormErrorSummary :errors="form.errors" />
                <div class="grid gap-3 text-sm sm:grid-cols-3">
                    <label class="block"><span class="field-label">المبلغ لكل دورة *</span><input v-model="form.amount" inputmode="decimal" dir="ltr" class="field" required></label>
                    <label class="block"><span class="field-label">العملة *</span><select v-model="form.currency" class="field"><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                    <label class="block"><span class="field-label">نوع الكفالة</span><select v-model="form.type_value_id" class="field"><option :value="null">—</option><option v-for="o in options.sponsorship_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label class="block"><span class="field-label">الدورية *</span><select v-model="form.frequency_value_id" class="field"><option v-for="o in options.sponsorship_frequencies" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label class="block"><span class="field-label">الغرض *</span><select v-model="form.purpose_value_id" class="field"><option v-for="o in options.sponsorship_purposes" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label v-if="split" class="block"><span class="field-label">منه للادخار *</span><input v-model="form.savings_amount" inputmode="decimal" dir="ltr" class="field" required></label>
                    <label class="block"><span class="field-label">{{ sponsorship.is_amendment ? 'تاريخ سريان التعديل *' : 'تاريخ البداية *' }}</span><input v-model="form.starts_on" type="date" class="field" required></label>
                    <label class="block"><span class="field-label">تاريخ النهاية</span><input v-model="form.ends_on" type="date" class="field"></label>
                    <label class="block"><span class="field-label">المصدر/المرجع</span><input v-model="form.source" class="field" maxlength="200"></label>
                    <label class="block sm:col-span-3"><span class="field-label">الشروط</span><textarea v-model="form.conditions" class="field" rows="2" maxlength="2000"></textarea></label>
                </div>
                <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary btn-sm" @click="editing = false">إلغاء</button></div>
            </form>

            <section v-if="figures" class="card">
                <h2 class="card-title mb-2">الموقف المالي (من سجلات الحسابات) — <span dir="ltr">{{ sponsorship.currency }}</span></h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-slate-500">المستحق حتى اليوم</dt><dd class="font-semibold">{{ money(figures.due) }}</dd></div>
                    <div><dt class="text-slate-500">المحصّل</dt><dd class="font-semibold">{{ money(figures.collected) }}</dd></div>
                    <div><dt class="text-slate-500">المتبقي المستحق</dt><dd class="font-semibold">{{ money(figures.outstanding) }} <span v-if="figures.overdue" class="text-xs text-red-700">({{ figures.overdue }} متأخرة)</span></dd></div>
                    <div><dt class="text-slate-500">صافي ما قُيد للادخار</dt><dd class="font-semibold">{{ money(figures.savings) }}</dd></div>
                    <div><dt class="text-slate-500">المصروف للأسرة</dt><dd class="font-semibold">{{ money(figures.disbursed) }}</dd></div>
                    <div><dt class="text-slate-500">محصّل للمصاريف لم يُصرف</dt><dd class="font-semibold">{{ money(figures.available) }}</dd></div>
                </dl>
            </section>

            <section v-if="schedules.length" class="card overflow-x-auto">
                <h2 class="card-title mb-2">جدول الاستحقاقات</h2>
                <table class="w-full text-sm">
                    <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الفترة</th><th>الاستحقاق</th><th>المحصّل</th><th>المتبقي</th><th>منه ادخار</th><th>الحالة</th></tr></thead>
                    <tbody><tr v-for="d in schedules" :key="d.id" class="border-t border-slate-100">
                        <td class="py-2">{{ d.period_start }}</td><td>{{ money(d.expected) }}</td><td>{{ money(d.collected) }}</td><td>{{ money(d.remaining) }}</td><td>{{ money(d.savings_part) }}</td>
                        <td><StatusBadge :label="d.status_label" :tone="scheduleTone[d.status]" /></td></tr></tbody>
                </table>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="card space-y-2 text-sm">
                    <h2 class="card-title">التحصيلات المسجلة</h2>
                    <p v-for="c in collections" :key="c.id" :class="c.reversed_at ? 'text-slate-400 line-through' : ''">
                        <span dir="ltr" class="font-mono">{{ c.accounting_reference }}</span> — {{ money(c.amount) }} {{ c.currency }} — فترة {{ c.period_start }} — سند {{ c.posted_on }} ({{ c.recorded_by }})<span v-if="c.reversed_at"> — معكوس: {{ c.reversal_reason }}</span></p>
                    <p v-if="!collections.length" class="text-slate-500">لم تسجل الحسابات أي تحصيل بعد.</p>
                </section>
                <section class="card space-y-2 text-sm">
                    <h2 class="card-title">الصرف للأسرة</h2>
                    <p v-for="x in disbursements" :key="x.id" :class="x.status === 'reversed' ? 'text-slate-400 line-through' : ''">
                        <span dir="ltr" class="font-mono">{{ x.accounting_reference }}</span> — {{ money(x.amount) }} {{ x.currency }} — {{ x.executed_on }} ({{ x.recorded_by }})<span v-if="x.status === 'reversed'"> — معكوس: {{ x.reversal_reason }}</span></p>
                    <p v-if="!disbursements.length" class="text-slate-500">لا يوجد صرف مسجل.</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
