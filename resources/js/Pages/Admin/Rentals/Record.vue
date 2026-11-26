<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';

const props = defineProps({
    type: String, record: Object, context: Object, documents: Array, progress: Object, workflow: Object,
    options: Object, labels: Object, can: Object, editor: Object,
});

const money = n => Number(n || 0).toLocaleString('ar-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const field = 'block text-sm font-medium text-slate-700';
const r = computed(() => props.record);
const statusLabels = computed(() => ({ application: props.labels.applications, contract: props.labels.contracts, case: props.labels.cases, settlement: props.labels.settlements })[props.type]);
const title = computed(() => ({
    application: `طلب استئجار — ${props.context.tenant?.name ?? ''}`,
    contract: `${props.labels.kinds[r.value.kind] ?? 'عقد'} ${r.value.number}`,
    case: `إنهاء وإخلاء العقد ${props.context.contract?.number ?? ''}`,
    settlement: `تسوية على العقد ${props.context.contract?.number ?? ''}`,
})[props.type]);
const optionName = (type, id) => (props.options[type] || []).find(o => o.id === Number(id))?.name ?? '—';
const hasDoc = code => props.documents.some(d => d.type_code === code);

/* Workflow actions, the same way every AIMS transaction acts. */
const actions = computed(() => props.workflow?.actions ?? []);
const selected = ref(null);
const chosen = computed(() => actions.value.find(a => a.id === selected.value) || null);
const actionForm = useForm({ transition_id: null, revision: props.workflow?.revision ?? 1, comment: '' });
watch(() => props.workflow?.revision, value => { actionForm.revision = value ?? 1; });
function act() {
    if (!chosen.value) return;
    actionForm.transition_id = chosen.value.id;
    actionForm.post(`/workflows/${props.workflow.publicId}/act`, { preserveScroll: true, onSuccess: () => { selected.value = null; actionForm.reset('comment'); } });
}
function submitDraft() {
    if (confirm('إرسال الطلب إلى مسار الاعتماد؟ بعد الإرسال لا يُعدَّل إلا إذا أُعيد إليك.')) router.post(`/admin/rentals/records/${props.type}/${r.value.id}/submit`, {}, { preserveScroll: true });
}

/* Documents */
const upload = useForm({ file: null, document_type_value_id: null, title: '', supersedes_id: null });
const currentDocs = computed(() => props.documents.filter(d => d.current));
function sendFile() {
    upload.post(`/admin/rentals/files/${props.type}/${r.value.id}`, { preserveScroll: true, forceFormData: true, onSuccess: () => upload.reset() });
}

/* Special steps */
const reading = useForm({ unit_id: '', contract_id: props.type === 'contract' ? r.value.id : props.context.contract?.id, case_id: props.type === 'case' ? r.value.id : '',
    purpose: props.type === 'case' ? 'eviction' : 'handover', meter_kind: 'كهرباء', reading: '', read_on: '', photo: null });
const outcome = useForm({ outcome_unit_status: r.value.outcome_unit_status || 'vacant', damages: r.value.damages || '' });
const posting = useForm({ posting_reference: '', posting_date: '', comment: '' });

/* Editing a draft or a returned request */
const edit = useForm(props.type === 'contract'
    ? { number: r.value.number, tenant_id: r.value.tenant_id, kind: r.value.kind, application_id: r.value.application_id ?? '', parent_contract_id: r.value.parent_contract_id ?? '',
        issued_on: r.value.issued_on ?? '', starts_on: r.value.starts_on, ends_on: r.value.ends_on, currency_code: r.value.currency_code, frequency: r.value.frequency,
        total_rent: r.value.total_rent, auto_renew: !!r.value.auto_renew, renewal_clause: r.value.renewal_clause ?? '', terms: r.value.terms ?? '', change_reason: r.value.change_reason ?? '',
        items: (props.context.units || []).map(u => ({ unit_id: u.unit_id, rent_share: u.rent_share, rent_per_m2: u.rent_per_m2 ?? '' })),
        services: (props.context.services || []).map(s => ({ service_value_id: s.service_value_id, billing: s.billing, amount: s.amount ?? '', notes: s.notes ?? '' })) }
    : props.type === 'application' ? { tenant_id: r.value.tenant_id, unit_id: r.value.unit_id ?? '', purpose: r.value.purpose ?? '', study: r.value.study ?? '' }
        : props.type === 'case' ? { termination_reason_value_id: r.value.termination_reason_value_id, departure_on: r.value.departure_on, reason: r.value.reason ?? '', details: r.value.details ?? '' }
            : { type_value_id: r.value.type_value_id, effect: r.value.effect, amount: r.value.amount, reason: r.value.reason });
const customDue = useForm({ due_on: '', amount: '' });
const removeDue = id => { if (confirm('حذف هذا الاستحقاق من مسودة العقد؟')) router.delete(`/admin/rentals/dues/${id}`, { preserveScroll: true }); };
const purposes = { handover: 'عند التسليم', periodic: 'دورية', eviction: 'عند الإخلاء' };
const outcomeLabels = { vacant: 'شاغرة', maintenance: 'تحت الصيانة', reserved: 'محجوزة', suspended: 'موقوفة' };
</script>

<template>
<AppLayout :title="title">
<div dir="rtl" class="mx-auto max-w-6xl space-y-5">
    <PageHeader eyebrow="ملف الإيجارات" :title="title" :description="workflow ? `رقم المعاملة ${workflow.referenceNo}` : 'مسودة لم تُرسل بعد'">
        <Link :href="`/admin/rentals?tab=${{ application: 'applications', contract: 'contracts', case: 'cases', settlement: 'settlements' }[type]}`" class="btn btn-secondary">العودة إلى القائمة</Link>
    </PageHeader>

    <section class="card space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :label="statusLabels[record.status] || record.status" :status="record.status" />
            <button v-if="can.submit" type="button" class="btn btn-primary" @click="submitDraft">{{ { application: 'إرسال إلى المدير التنفيذي', contract: 'رفع العقد للاعتماد', case: 'إحالة للفحص', settlement: 'إرسال إلى الحسابات' }[type] }}</button>
        </div>
        <StatusStepper v-if="progress" :steps="progress.steps" :summary="progress.summary" />
        <p v-else class="text-sm text-slate-500">المسودة لم تدخل مسار العمل؛ أكمل البيانات والمستندات ثم أرسلها.</p>
    </section>

    <!-- The step the viewer holds now -->
    <section v-if="actions.length" class="card space-y-3" aria-labelledby="act-title">
        <h2 id="act-title" class="card-title">الإجراء المطلوب منك</h2>
        <p v-if="type === 'contract' && workflow?.step === 'signing'" class="text-sm text-slate-600">قبل «تفعيل العقد» أرفق النسخة الموقعة ومحضر التسليم أدناه، وسجّل قراءة العداد عند التسليم.</p>
        <p v-if="type === 'case' && workflow?.step === 'handover'" class="text-sm text-slate-600">قبل الإتمام سجّل محضر الاستلام (حالة العين بعد الإخلاء) وقراءة العداد أو أرفق محضر الإخلاء.</p>
        <div class="flex flex-wrap gap-2">
            <button v-for="a in actions" :key="a.id" type="button" class="btn btn-sm" :class="selected === a.id ? 'btn-primary' : 'btn-secondary'" @click="selected = a.id; actionForm.clearErrors()">{{ a.name }}</button>
        </div>
        <form v-if="chosen" class="space-y-2" @submit.prevent="act">
            <label for="act-comment" :class="field">ملاحظة{{ chosen.requires_comment ? ' (مطلوبة)' : '' }}</label>
            <textarea id="act-comment" v-model="actionForm.comment" rows="3" maxlength="5000" :required="chosen.requires_comment" class="field"></textarea>
            <p v-for="(message, key) in actionForm.errors" :key="key" class="field-error">{{ message }}</p>
            <button class="btn btn-primary" :disabled="actionForm.processing">تنفيذ: {{ chosen.name }}</button>
        </form>
    </section>

    <!-- Accounts: posting a settlement -->
    <form v-if="can.post" class="card grid gap-2 md:grid-cols-3" @submit.prevent="posting.post(`/admin/rentals/settlements/${record.id}/post`, { preserveScroll: true })">
        <h2 class="card-title md:col-span-3">تسجيل الترحيل من البرنامج المحاسبي</h2>
        <input v-model="posting.posting_reference" class="field" required placeholder="رقم القيد أو السند" dir="ltr">
        <input v-model="posting.posting_date" type="date" class="field" required aria-label="تاريخ الترحيل">
        <input v-model="posting.comment" class="field" placeholder="ملاحظة (اختياري)">
        <p v-for="(message, key) in posting.errors" :key="key" class="field-error md:col-span-3">{{ message }}</p>
        <div><button class="btn btn-primary" :disabled="posting.processing">تم الترحيل</button></div>
    </form>

    <!-- Officer: handover record of a termination -->
    <form v-if="can.outcome" class="card grid gap-2 md:grid-cols-3" @submit.prevent="outcome.post(`/admin/rentals/cases/${record.id}/outcome`, { preserveScroll: true })">
        <h2 class="card-title md:col-span-3">محضر الاستلام</h2>
        <label :class="field">حالة العين بعد الإخلاء<select v-model="outcome.outcome_unit_status" class="field mt-1"><option v-for="(label, key) in outcomeLabels" :key="key" :value="key">{{ label }}</option></select></label>
        <textarea v-model="outcome.damages" class="field md:col-span-2" rows="2" placeholder="الأضرار والالتزامات إن وجدت"></textarea>
        <div><button class="btn btn-secondary" :disabled="outcome.processing">حفظ المحضر</button></div>
    </form>

    <!-- Readings at handover or eviction -->
    <form v-if="can.manage && ((type === 'contract' && ['approved', 'active'].includes(record.status)) || (type === 'case' && record.status === 'approved'))"
        class="card grid gap-2 md:grid-cols-3" @submit.prevent="reading.post('/admin/rentals/readings', { preserveScroll: true, forceFormData: true, onSuccess: () => reading.reset('reading', 'read_on', 'photo') })">
        <h2 class="card-title md:col-span-3">قراءة عداد {{ purposes[reading.purpose] }}</h2>
        <select v-model="reading.unit_id" class="field" required aria-label="العين"><option value="">العين</option><option v-for="u in context.units" :key="u.unit_id" :value="u.unit_id">{{ u.property }} / {{ u.code }} {{ u.name }}</option></select>
        <input v-model="reading.meter_kind" class="field" required placeholder="نوع العداد">
        <input v-model="reading.reading" type="number" min="0" step="0.001" class="field" required placeholder="القراءة">
        <input v-model="reading.read_on" type="date" class="field" required aria-label="تاريخ القراءة">
        <label :class="field">صورة العداد<input type="file" accept="image/*,.pdf" class="field mt-1" @input="reading.photo = $event.target.files[0]"></label>
        <p v-for="(message, key) in reading.errors" :key="key" class="field-error md:col-span-3">{{ message }}</p>
        <div><button class="btn btn-secondary" :disabled="reading.processing">حفظ القراءة</button></div>
    </form>

    <!-- Details -->
    <section class="card space-y-3">
        <h2 class="card-title">البيانات</h2>
        <dl v-if="context.tenant" class="grid gap-2 text-sm md:grid-cols-3">
            <div><dt class="text-slate-500">المستأجر</dt><dd class="font-semibold">{{ context.tenant.name }}</dd></div>
            <div><dt class="text-slate-500">الممثل</dt><dd>{{ context.tenant.representative || '—' }}</dd></div>
            <div><dt class="text-slate-500">الهاتف</dt><dd dir="ltr" class="text-right">{{ context.tenant.phone || '—' }} {{ context.tenant.phone2 ? ' / ' + context.tenant.phone2 : '' }}</dd></div>
        </dl>
        <template v-if="type === 'application'">
            <p class="text-sm"><b>العين المقترحة:</b> {{ context.unit ? `${context.unit.property} / ${context.unit.code} ${context.unit.name} (${labels.units[context.unit.availability]})` : 'لم تُحدد' }}</p>
            <p class="whitespace-pre-line text-sm"><b>الغرض:</b> {{ record.purpose || '—' }}</p>
            <p class="whitespace-pre-line text-sm"><b>الدراسة والتوصية:</b> {{ record.study || '—' }}</p>
            <p v-if="record.decision_reason" class="text-sm"><b>سبب القرار:</b> {{ record.decision_reason }}</p>
            <p v-for="c in context.contracts" :key="c.id" class="text-sm">العقد: <Link :href="`/admin/rentals/records/contract/${c.id}`" class="text-blue-700 hover:underline">{{ c.number }}</Link></p>
        </template>
        <template v-if="type === 'contract'">
            <dl class="grid gap-2 text-sm md:grid-cols-4">
                <div><dt class="text-slate-500">المدة</dt><dd>{{ record.starts_on }} ← {{ record.ends_on }}</dd></div>
                <div><dt class="text-slate-500">تاريخ التحرير</dt><dd>{{ record.issued_on || '—' }}</dd></div>
                <div><dt class="text-slate-500">الدفعة</dt><dd>{{ money(record.total_rent) }} {{ record.currency_code }} ({{ { monthly: 'شهري', quarterly: 'ربع سنوي', semiannual: 'نصف سنوي', annual: 'سنوي', custom: 'جدول مخصص' }[record.frequency] }})</dd></div>
                <div><dt class="text-slate-500">التجديد التلقائي</dt><dd>{{ record.auto_renew ? 'نعم' : 'لا' }}</dd></div>
            </dl>
            <p v-if="context.parent" class="text-sm">العقد الأصلي: <Link :href="`/admin/rentals/records/contract/${context.parent.id}`" class="text-blue-700 hover:underline">{{ context.parent.number }}</Link> — {{ labels.contracts[context.parent.status] }}</p>
            <p v-if="record.change_reason" class="text-sm"><b>سبب التجديد أو التعديل:</b> {{ record.change_reason }}</p>
            <p v-for="c in context.children" :key="c.id" class="text-sm">{{ labels.kinds[c.kind] }} لاحق: <Link :href="`/admin/rentals/records/contract/${c.id}`" class="text-blue-700 hover:underline">{{ c.number }}</Link> — {{ labels.contracts[c.status] }}</p>
            <div class="overflow-x-auto">
                <table class="data-table"><thead><tr><th>العين</th><th>المساحة</th><th>الحصة</th><th>إيجار المتر</th><th>الإشغال</th></tr></thead>
                    <tbody><tr v-for="u in context.units" :key="u.unit_id"><td>{{ u.property }} / {{ u.code }} {{ u.name }}</td><td>{{ u.area_m2 || '—' }}</td><td>{{ money(u.rent_share) }}</td><td>{{ u.rent_per_m2 ? money(u.rent_per_m2) : '—' }}</td><td>{{ u.occupies ? `${u.starts_on} ← ${u.ends_on}` : '—' }}</td></tr></tbody>
                </table>
            </div>
            <p v-if="context.services.length" class="text-sm"><b>الخدمات:</b> <span v-for="(s, i) in context.services" :key="i">{{ s.name }} ({{ s.billing === 'included' ? 'مرصدة ضمن العقد' : `منفصلة ${money(s.amount)} لكل دفعة` }}){{ i < context.services.length - 1 ? '، ' : '' }}</span></p>
            <p v-if="record.terms" class="whitespace-pre-line text-sm"><b>الشروط:</b> {{ record.terms }}</p>
        </template>
        <template v-if="type === 'case'">
            <dl class="grid gap-2 text-sm md:grid-cols-3">
                <div><dt class="text-slate-500">العقد</dt><dd><Link :href="`/admin/rentals/records/contract/${context.contract.id}`" class="text-blue-700 hover:underline">{{ context.contract.number }}</Link> ({{ labels.contracts[context.contract.status] }})</dd></div>
                <div><dt class="text-slate-500">سبب الإنهاء</dt><dd>{{ context.reason }}</dd></div>
                <div><dt class="text-slate-500">تاريخ المغادرة</dt><dd>{{ record.departure_on }}</dd></div>
            </dl>
            <p class="whitespace-pre-line text-sm"><b>البيان:</b> {{ record.reason }}</p>
            <p v-if="record.outcome_unit_status" class="text-sm"><b>محضر الاستلام:</b> العين {{ outcomeLabels[record.outcome_unit_status] }}{{ record.damages ? ' — ' + record.damages : '' }}</p>
            <p v-for="m in context.readings" :key="m.id" class="text-sm">قراءة {{ purposes[m.purpose] }}: {{ m.meter_kind }} {{ m.reading }} ({{ m.read_on }})</p>
        </template>
        <template v-if="type === 'settlement'">
            <dl class="grid gap-2 text-sm md:grid-cols-4">
                <div><dt class="text-slate-500">النوع</dt><dd>{{ context.type }}</dd></div>
                <div><dt class="text-slate-500">الأثر</dt><dd>{{ labels.effects[record.effect] }}</dd></div>
                <div><dt class="text-slate-500">المبلغ</dt><dd class="font-semibold">{{ money(record.amount) }} {{ record.currency_code }}</dd></div>
                <div><dt class="text-slate-500">الترحيل</dt><dd dir="ltr" class="text-right">{{ record.posting_reference ? `${record.posting_reference} (${record.posting_date})` : '—' }}</dd></div>
            </dl>
            <p class="whitespace-pre-line text-sm"><b>السبب:</b> {{ record.reason }}</p>
            <div v-if="context.due" class="overflow-x-auto">
                <table class="data-table"><thead><tr><th>الاستحقاق</th><th>المستحق الأصلي</th><th>المقبوض</th><th>التسويات (−/+)</th><th>المتبقي</th></tr></thead>
                    <tbody><tr><td>{{ context.due.due_on }}</td><td>{{ money(context.due.amount) }}</td><td>{{ money(context.due.paid) }}</td><td>{{ money(context.due.reduced) }} / {{ money(context.due.increased) }}</td><td class="font-semibold">{{ money(context.due.remaining) }}</td></tr></tbody>
                </table>
            </div>
        </template>
    </section>

    <!-- Contract balances -->
    <section v-if="type === 'contract' && context.dues.length" class="card space-y-3 overflow-x-auto">
        <h2 class="card-title">الاستحقاقات والأرصدة</h2>
        <p v-for="t in context.totals" :key="t.currency" class="text-sm">{{ t.currency }}: المستحق {{ money(t.amount) }} · المقبوض {{ money(t.paid) }} · التخفيضات {{ money(t.reduced) }} · المتبقي <b>{{ money(t.remaining) }}</b> · متأخر {{ money(t.overdue) }}</p>
        <table class="data-table">
            <thead><tr><th>الاستحقاق</th><th>البند</th><th>المستحق</th><th>المقبوض</th><th>التسويات (−/+)</th><th>المتبقي</th><th>الحالة</th><th v-if="can.edit && record.frequency === 'custom'"></th></tr></thead>
            <tbody><tr v-for="d in context.dues" :key="d.id">
                <td>{{ d.due_on }}</td><td>{{ d.kind === 'service' ? optionName('rental_services', d.service_value_id) : 'إيجار' }}</td><td>{{ money(d.amount) }}</td><td>{{ money(d.paid) }}</td>
                <td>{{ money(d.reduced) }} / {{ money(d.increased) }}</td><td class="font-semibold">{{ money(d.remaining) }}</td>
                <td><StatusBadge :status="d.overdue ? 'overdue' : d.payment_state" /><p v-if="d.cancel_reason" class="text-[11px] text-slate-500">{{ d.cancel_reason }}</p></td>
                <td v-if="can.edit && record.frequency === 'custom'"><button type="button" class="btn btn-secondary btn-sm" @click="removeDue(d.id)">حذف</button></td>
            </tr></tbody>
        </table>
        <h3 v-if="context.receipts.length" class="text-sm font-bold">القبض المسجل من الحسابات</h3>
        <ul class="space-y-1 text-sm">
            <li v-for="x in context.receipts" :key="x.id" :class="x.reversed_at ? 'text-slate-400 line-through' : ''">{{ x.due_on }}: {{ money(x.amount) }} {{ x.currency_code }} — سند <span dir="ltr">{{ x.accounting_reference }}</span> ({{ x.external_system }}) — {{ x.recorded_by }}<span v-if="x.reversed_at"> — معكوس: {{ x.reversal_reason }}</span></li>
        </ul>
    </section>

    <form v-if="type === 'contract' && can.edit && record.frequency === 'custom'" class="card grid gap-2 md:grid-cols-3" @submit.prevent="customDue.post(`/admin/rentals/contracts/${record.id}/dues`, { preserveScroll: true, onSuccess: () => customDue.reset() })">
        <h2 class="card-title md:col-span-3">استحقاقات الجدول المخصص</h2>
        <input v-model="customDue.due_on" type="date" class="field" required aria-label="تاريخ الاستحقاق">
        <input v-model="customDue.amount" type="number" min="0.01" step="0.01" class="field" required placeholder="المبلغ">
        <button class="btn btn-secondary" :disabled="customDue.processing">إضافة استحقاق</button>
        <p v-for="(message, key) in customDue.errors" :key="key" class="field-error md:col-span-3">{{ message }}</p>
    </form>

    <section v-if="type === 'contract' && (context.maintenance.length || context.cases.length || context.settlements.length)" class="card space-y-2 text-sm">
        <h2 class="card-title">المرتبط بالعقد</h2>
        <p v-for="k in context.cases" :key="`k${k.id}`">إنهاء وإخلاء: <Link :href="`/admin/rentals/records/case/${k.id}`" class="text-blue-700 hover:underline">#{{ k.id }}</Link> — {{ labels.cases[k.status] }}</p>
        <p v-for="s in context.settlements" :key="`s${s.id}`">تسوية: <Link :href="`/admin/rentals/records/settlement/${s.id}`" class="text-blue-700 hover:underline">#{{ s.id }}</Link> — {{ money(s.amount) }} {{ s.currency_code }} — {{ labels.settlements[s.status] }}</p>
        <p v-for="m in context.maintenance" :key="m.public_id">صيانة: <Link :href="m.url" class="text-blue-700 hover:underline" dir="ltr">{{ m.reference_no }}</Link> — {{ m.step || m.status }}</p>
    </section>

    <!-- Documents -->
    <section class="card space-y-3" aria-labelledby="docs-title">
        <h2 id="docs-title" class="card-title">المستندات</h2>
        <p v-if="type === 'contract'" class="text-xs text-slate-500">مطلوب للتفعيل: <span :class="hasDoc('signed_contract') ? 'text-emerald-700' : 'text-gold-800'">العقد الموقع {{ hasDoc('signed_contract') ? '✓' : '✗' }}</span> · <span :class="hasDoc('handover_record') ? 'text-emerald-700' : 'text-gold-800'">محضر التسليم {{ hasDoc('handover_record') ? '✓' : '✗' }}</span></p>
        <ul v-if="documents.length" class="divide-y divide-slate-100 text-sm">
            <li v-for="d in documents" :key="d.id" class="flex flex-wrap items-center gap-2 py-2" :class="d.current ? '' : 'opacity-60'">
                <a :href="`/admin/rentals/documents/${d.id}`" class="font-semibold text-blue-700 hover:underline">{{ d.title }}</a>
                <span class="text-xs text-slate-500">{{ d.type || 'بلا تصنيف' }} · الإصدار {{ d.version }}{{ d.current ? '' : ' (استُبدل)' }} · {{ d.uploaded_by }} · {{ d.created_at }}</span>
            </li>
        </ul>
        <EmptyState v-else title="لا توجد مستندات" />
        <form v-if="can.upload" class="grid gap-2 md:grid-cols-4" @submit.prevent="sendFile">
            <input type="file" class="field md:col-span-2" required aria-label="الملف" @input="upload.file = $event.target.files[0]">
            <select v-model="upload.document_type_value_id" class="field" aria-label="نوع المستند"><option :value="null">نوع المستند</option><option v-for="o in options.rental_document_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            <input v-model="upload.title" class="field" required placeholder="عنوان المستند">
            <select v-model="upload.supersedes_id" class="field md:col-span-2" aria-label="إصدار جديد من"><option :value="null">مستند جديد (ليس إصدارًا لسابق)</option><option v-for="d in currentDocs" :key="d.id" :value="d.id">إصدار جديد من: {{ d.title }}</option></select>
            <p v-for="(message, key) in upload.errors" :key="key" class="field-error md:col-span-4">{{ message }}</p>
            <div><button class="btn btn-secondary" :disabled="upload.processing">رفع</button></div>
        </form>
    </section>

    <!-- Editing a draft or returned request -->
    <form v-if="editor && can.edit" class="card space-y-3" @submit.prevent="edit.put(`/admin/rentals/records/${type}/${record.id}`, { preserveScroll: true })">
        <h2 class="card-title">تعديل الطلب</h2>
        <template v-if="type === 'application'">
            <div class="grid gap-2 md:grid-cols-2">
                <select v-model="edit.tenant_id" class="field" required aria-label="المستأجر"><option v-for="t in editor.tenants" :key="t.id" :value="t.id">{{ t.name }}</option></select>
                <select v-model="edit.unit_id" class="field" aria-label="العين"><option value="">بدون عين محددة</option><option v-for="u in editor.units" :key="u.id" :value="u.id">{{ u.property }} / {{ u.code }} {{ u.name }} — {{ labels.units[u.availability] }}</option></select>
            </div>
            <textarea v-model="edit.purpose" class="field" rows="2" placeholder="الغرض"></textarea>
            <textarea v-model="edit.study" class="field" rows="4" placeholder="الدراسة والتوصية"></textarea>
        </template>
        <template v-if="type === 'contract'">
            <div class="grid gap-2 md:grid-cols-3">
                <label :class="field">رقم العقد<input v-model="edit.number" class="field mt-1" required dir="ltr"></label>
                <label :class="field">المستأجر<select v-model="edit.tenant_id" class="field mt-1" required><option v-for="t in editor.tenants" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                <label :class="field">تاريخ التحرير<input v-model="edit.issued_on" type="date" class="field mt-1"></label>
                <label :class="field">البداية<input v-model="edit.starts_on" type="date" class="field mt-1" required></label>
                <label :class="field">النهاية<input v-model="edit.ends_on" type="date" class="field mt-1" required></label>
                <label :class="field">العملة<select v-model="edit.currency_code" class="field mt-1"><option v-for="c in editor.currencies" :key="c" :value="c">{{ c }}</option></select></label>
                <label :class="field">الدورية<select v-model="edit.frequency" class="field mt-1"><option value="monthly">شهري</option><option value="quarterly">ربع سنوي</option><option value="semiannual">نصف سنوي</option><option value="annual">سنوي</option><option value="custom">جدول مخصص</option></select></label>
                <label :class="field">قيمة الدفعة<input v-model="edit.total_rent" type="number" min="0.01" step="0.01" class="field mt-1" required></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="edit.auto_renew" type="checkbox"> بند تجديد تلقائي</label>
            </div>
            <textarea v-if="edit.kind !== 'new'" v-model="edit.change_reason" class="field" rows="2" required placeholder="سبب التجديد أو التعديل"></textarea>
            <textarea v-model="edit.terms" class="field" rows="3" placeholder="الشروط"></textarea>
            <div v-for="(item, i) in edit.items" :key="i" class="flex flex-wrap gap-2">
                <select v-model="item.unit_id" class="field flex-1" required aria-label="العين"><option v-for="u in editor.units" :key="u.id" :value="u.id">{{ u.property }} / {{ u.code }} {{ u.name }} — {{ labels.units[u.availability] }}</option></select>
                <input v-model="item.rent_share" type="number" min="0.01" step="0.01" class="field w-36" required placeholder="الحصة">
                <input v-model="item.rent_per_m2" type="number" min="0" step="0.01" class="field w-36" placeholder="إيجار المتر">
                <button type="button" class="btn btn-secondary btn-sm" :disabled="edit.items.length === 1" @click="edit.items.splice(i, 1)">حذف</button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="edit.items.push({ unit_id: '', rent_share: '', rent_per_m2: '' })">+ عين</button>
            <div v-for="(service, i) in edit.services" :key="`s${i}`" class="flex flex-wrap gap-2">
                <select v-model="service.service_value_id" class="field flex-1" required aria-label="الخدمة"><option v-for="o in options.rental_services" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <select v-model="service.billing" class="field w-48" aria-label="الاحتساب"><option value="included">مرصدة ضمن العقد</option><option value="separate">منفصلة لكل دفعة</option></select>
                <input v-if="service.billing === 'separate'" v-model="service.amount" type="number" min="0.01" step="0.01" class="field w-36" required placeholder="القيمة">
                <button type="button" class="btn btn-secondary btn-sm" @click="edit.services.splice(i, 1)">حذف</button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="edit.services.push({ service_value_id: '', billing: 'included', amount: '', notes: '' })">+ خدمة</button>
        </template>
        <template v-if="type === 'case'">
            <div class="grid gap-2 md:grid-cols-2">
                <select v-model="edit.termination_reason_value_id" class="field" required aria-label="سبب الإنهاء"><option v-for="o in options.rental_termination_reasons" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <input v-model="edit.departure_on" type="date" class="field" required aria-label="تاريخ المغادرة">
            </div>
            <textarea v-model="edit.reason" class="field" rows="2" required placeholder="البيان"></textarea>
            <textarea v-model="edit.details" class="field" rows="2" placeholder="تفاصيل"></textarea>
        </template>
        <template v-if="type === 'settlement'">
            <div class="grid gap-2 md:grid-cols-3">
                <select v-model="edit.type_value_id" class="field" required aria-label="النوع"><option v-for="o in options.rental_settlement_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                <select v-model="edit.effect" class="field" aria-label="الأثر"><option v-for="(label, key) in labels.effects" :key="key" :value="key">{{ label }}</option></select>
                <input v-model="edit.amount" type="number" min="0.01" step="0.01" class="field" required aria-label="المبلغ">
            </div>
            <textarea v-model="edit.reason" class="field" rows="2" required placeholder="السبب"></textarea>
        </template>
        <p v-for="(message, key) in edit.errors" :key="key" class="field-error">{{ message }}</p>
        <button class="btn btn-primary" :disabled="edit.processing">حفظ التعديل</button>
    </form>
</div>
</AppLayout>
</template>
