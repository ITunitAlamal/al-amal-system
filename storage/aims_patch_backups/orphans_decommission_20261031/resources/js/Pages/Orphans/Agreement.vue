<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// One sponsorship agreement: its terms, its sponsorship lines (each approved on its own), the text and its signing.
const props = defineProps({
    agreement: { type: Object, required: true },
    text: { type: String, default: null },
    lines: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    options: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
const base = `/orphans/agreements/${props.agreement.public_id}`;
const tone = { draft: 'warning', signed: 'success', renewed: 'muted', ended: 'muted', cancelled: 'muted' };
const lineTone = { draft: 'warning', pending_approval: 'info', active: 'success', paused: 'muted', ended: 'muted', rejected: 'danger', cancelled: 'muted' };

const editForm = useForm({ template_id: props.agreement.template_id, starts_on: props.agreement.starts_on, ends_on: props.agreement.ends_on || '',
    renewal_alert_days: props.agreement.renewal_alert_days, notes: props.agreement.notes || '' });
function save() { editForm.put(base, { preserveScroll: true }); }

const addingLines = ref(false);
const linesForm = useForm({ references: '', beneficiary_type: props.agreement.kind === 'family' ? 'household' : 'child', type_value_id: null, amount: '', currency: 'ILS',
    frequency_value_id: null, purpose_value_id: null, savings_amount: '', starts_on: props.agreement.starts_on, ends_on: props.agreement.ends_on || '', conditions: '' });
const purpose = computed(() => (props.options?.sponsorship_purposes || []).find(p => p.id === linesForm.purpose_value_id)?.flags || {});
const split = computed(() => purpose.value.expenses && purpose.value.savings);
function addLines() { linesForm.post(`${base}/lines`, { preserveScroll: true, onSuccess: () => { addingLines.value = false; linesForm.reset('references'); } }); }
function submitLines() { router.post(`${base}/submit`, {}, { preserveScroll: true }); }

const signForm = useForm({ signed_on: new Date().toISOString().slice(0, 10) });
function sign() {
    if (!confirm('تسجيل توقيع الكافل؟ يُحفظ نص الاتفاقية كما هو الآن ولا يتغير بعدها.')) return;
    signForm.post(`${base}/sign`, { preserveScroll: true });
}
function renew() { router.post(`${base}/renew`); }
function close() {
    const reason = prompt(props.agreement.status === 'signed' ? 'سبب إنهاء الاتفاقية:' : 'سبب إلغاء المسودة:');
    if (reason) router.post(`${base}/close`, { reason }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="`اتفاقية ${agreement.agreement_no}`">
        <div class="space-y-4">
            <Link href="/orphans/sponsorships?tab=agreements" class="text-sm font-semibold text-blue-700 hover:underline">→ الاتفاقيات</Link>
            <PageHeader eyebrow="اتفاقية كفالة" :title="`${agreement.agreement_no} — ${agreement.kind_label}`"
                :description="`الكافل: ${agreement.sponsor.name} (${agreement.sponsor.donor_no})${agreement.fund ? ' — الصندوق ' + agreement.fund.fund_no : ''}`">
                <template #meta><div class="mt-2"><StatusBadge :label="agreement.status_label" :tone="tone[agreement.status]" /></div></template>
                <Link :href="`/orphans/sponsors/${agreement.sponsor.public_id}`" class="btn btn-secondary btn-sm">ملف الكافل</Link>
                <a v-if="text" :href="`${base}/print`" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">طباعة النص</a>
            </PageHeader>

            <section class="card grid gap-2 text-sm sm:grid-cols-3">
                <p><span class="text-slate-500">الفترة:</span> <span dir="ltr">{{ agreement.starts_on }}</span> – <span dir="ltr">{{ agreement.ends_on || 'مفتوحة' }}</span></p>
                <p><span class="text-slate-500">التنبيه قبل الانتهاء:</span> {{ agreement.renewal_alert_days }} يومًا</p>
                <p><span class="text-slate-500">القالب:</span> {{ agreement.template ? `${agreement.template.title} (إصدار ${agreement.template.version})` : 'لم يُختر' }}</p>
                <p v-if="agreement.signed_on"><span class="text-slate-500">وُقّعت في:</span> <span dir="ltr">{{ agreement.signed_on }}</span></p>
                <p v-if="agreement.renews"><span class="text-slate-500">تجديد للاتفاقية:</span> <Link :href="`/orphans/agreements/${agreement.renews.public_id}`" class="text-blue-700 hover:underline" dir="ltr">{{ agreement.renews.agreement_no }}</Link></p>
                <p v-if="agreement.renewal"><span class="text-slate-500">جُددت بـ:</span> <Link :href="`/orphans/agreements/${agreement.renewal.public_id}`" class="text-blue-700 hover:underline" dir="ltr">{{ agreement.renewal.agreement_no }}</Link></p>
                <p v-if="agreement.status_reason" class="sm:col-span-3"><span class="text-slate-500">السبب:</span> {{ agreement.status_reason }}</p>
                <p v-if="agreement.notes" class="sm:col-span-3"><span class="text-slate-500">ملاحظات:</span> {{ agreement.notes }}</p>
                <div class="flex flex-wrap gap-2 sm:col-span-3">
                    <button v-if="can.renew" type="button" class="btn btn-secondary btn-sm" @click="renew">تجديد الاتفاقية</button>
                    <button v-if="can.end || can.cancel" type="button" class="btn btn-secondary btn-sm" @click="close">{{ can.end ? 'إنهاء الاتفاقية' : 'إلغاء المسودة' }}</button>
                </div>
            </section>

            <form v-if="can.edit" class="card space-y-3" data-error-scope @submit.prevent="save">
                <h2 class="card-title">بيانات المسودة</h2>
                <FormErrorSummary :errors="editForm.errors" />
                <div class="grid gap-3 text-sm sm:grid-cols-4">
                    <label class="block sm:col-span-2"><span class="field-label">القالب المعتمد</span>
                        <select v-model="editForm.template_id" class="field"><option :value="null">—</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.title }} (إصدار {{ t.version }})</option></select></label>
                    <label class="block"><span class="field-label">البداية *</span><input v-model="editForm.starts_on" type="date" class="field" required></label>
                    <label class="block"><span class="field-label">النهاية</span><input v-model="editForm.ends_on" type="date" class="field"></label>
                    <label class="block"><span class="field-label">التنبيه قبل الانتهاء (يوم)</span><input v-model.number="editForm.renewal_alert_days" type="number" min="1" max="180" class="field"></label>
                    <label class="block sm:col-span-3"><span class="field-label">ملاحظات</span><input v-model="editForm.notes" class="field" maxlength="2000"></label>
                </div>
                <button class="btn btn-secondary btn-sm" :disabled="editForm.processing">حفظ</button>
            </form>

            <section class="card space-y-2 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="card-title">الكفالات ضمن الاتفاقية</h2>
                    <span class="flex gap-2"><button v-if="can.lines && options" type="button" class="btn btn-secondary btn-sm" @click="addingLines = !addingLines">إضافة مستفيدين</button>
                        <button v-if="can.submit" type="button" class="btn btn-primary btn-sm" @click="submitLines">رفع المسودات للاعتماد</button></span>
                </div>
                <p class="text-xs text-slate-500">كل كفالة تُعتمد وحدها (توصية رئيس القسم ثم قرار المدير التنفيذي)، ولها استحقاقاتها وسندات الحسابات الخاصة بها. الاتفاقية تجمعها فقط.</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="l in lines" :key="l.public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/orphans/sponsorships/${l.public_id}`" class="font-semibold text-blue-700 hover:underline" dir="ltr">{{ l.sponsorship_no }}</Link> — {{ l.beneficiary }} — <span dir="ltr">{{ l.amount }} {{ l.currency }}</span> ({{ l.frequency }})</span>
                        <StatusBadge :label="l.status_label" :tone="lineTone[l.status] || 'muted'" />
                    </li>
                    <li v-if="!lines.length" class="py-2 text-slate-500">لا توجد كفالات بعد.</li>
                </ul>
                <form v-if="addingLines" class="space-y-3 border-t border-slate-100 pt-3" data-error-scope @submit.prevent="addLines">
                    <FormErrorSummary :errors="linesForm.errors" />
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label v-if="agreement.kind === 'fund'" class="block"><span class="field-label">المستفيد</span><select v-model="linesForm.beneficiary_type" class="field"><option value="child">أطفال</option><option value="household">أسر</option></select></label>
                        <label class="block sm:col-span-3"><span class="field-label">{{ linesForm.beneficiary_type === 'household' ? 'أرقام ملفات الأسر النشطة' : 'أرقام ملفات الأطفال النشطة' }} * (سطر أو فاصلة بين كل رقم)</span>
                            <textarea v-model="linesForm.references" dir="ltr" class="field" rows="3" required></textarea></label>
                        <label class="block"><span class="field-label">نوع الكفالة</span><select v-model="linesForm.type_value_id" class="field"><option :value="null">—</option><option v-for="o in options.sponsorship_types" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block"><span class="field-label">المبلغ لكل مستفيد لكل دورة *</span><input v-model="linesForm.amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">العملة *</span><select v-model="linesForm.currency" class="field"><option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                        <label class="block"><span class="field-label">الدورية *</span><select v-model="linesForm.frequency_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.sponsorship_frequencies" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label class="block"><span class="field-label">الغرض *</span><select v-model="linesForm.purpose_value_id" class="field" required><option :value="null">اختر</option><option v-for="o in options.sponsorship_purposes" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                        <label v-if="split" class="block"><span class="field-label">منه للادخار *</span><input v-model="linesForm.savings_amount" inputmode="decimal" dir="ltr" class="field" required></label>
                        <label class="block"><span class="field-label">البداية *</span><input v-model="linesForm.starts_on" type="date" class="field" required></label>
                        <label class="block"><span class="field-label">النهاية</span><input v-model="linesForm.ends_on" type="date" class="field"></label>
                    </div>
                    <button class="btn btn-primary btn-sm" :disabled="linesForm.processing">إنشاء مسودات الكفالات</button>
                </form>
            </section>

            <section class="card space-y-2 text-sm">
                <h2 class="card-title">نص الاتفاقية</h2>
                <p v-if="agreement.status === 'draft' && text" class="text-xs text-amber-800">معاينة من القالب بالقيم الحالية. يُحفظ النص نهائيًا عند تسجيل التوقيع.</p>
                <p v-if="agreement.rendered_hash" class="text-xs text-slate-500" dir="ltr">SHA-256: {{ agreement.rendered_hash }}</p>
                <div v-if="text" class="whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-4 leading-8">{{ text }}</div>
                <p v-else class="text-slate-500">اختر قالبًا معتمدًا لعرض النص.</p>
                <form v-if="can.sign" class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3" data-error-scope @submit.prevent="sign">
                    <label class="block"><span class="field-label">تاريخ توقيع الكافل</span><input v-model="signForm.signed_on" type="date" class="field" required></label>
                    <button class="btn btn-primary btn-sm" :disabled="signForm.processing">تسجيل التوقيع</button>
                    <p v-for="(m, k) in signForm.errors" :key="k" class="field-error w-full">{{ m }}</p>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
