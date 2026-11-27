<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import CandidatePanel from '../../../Components/Registry/CandidatePanel.vue';
import DecisionDialog from '../../../Components/DecisionDialog.vue';
import ChangeHistory from '../../../Components/ChangeHistory.vue';
import { useDecision } from '../../../Support/useDecision.js';

const props = defineProps({ campaign: Object, lists: Array, current: Object, view: String, entries: Object, approval: Object, tab: String, changes: Array, items: Array, delivery: Object,
    reconciliations: Array, members: Array, duplicates: Array, failureReasons: Array, users: Array, abilities: Object });
const tab = ref(props.tab);
const panel = ref(null);
const { decision, busy: decisionBusy, error: decisionError, ask, close: closeDecision, confirm: confirmDecision } = useDecision();
const tabs = { list: 'الكشف', approval: 'الاعتماد', changes: 'التعديلات', delivery: 'التسليم والمخازن', access: 'التكليفات', duplicates: 'تنبيهات التكرار' };
const listTones = { draft: 'warning', pending_executive: 'info', approved: 'success', superseded: 'muted', rejected: 'danger' };
const stateLabels = { prepared: ['مُجهز', 'muted'], delivered: ['سُلّم', 'success'], undelivered: ['لم يُسلَّم', 'warning'], returned: ['مُرتجع', 'warning'] };
const isDraft = computed(() => props.current?.status === 'draft');
const isApproved = computed(() => props.current?.status === 'approved');
const go = extra => router.get(`/orphans/campaigns/${props.campaign.public_id}`, { tab: tab.value, list: props.current?.id, view: props.view, ...extra }, { preserveState: true, preserveScroll: true });
function edit(action, data) {
    const reason = window.prompt('سبب التعديل (يُسجَّل):'); if (!reason) return;
    let extra = {};
    if (action === 'quantity') { const q = window.prompt('الكمية الجديدة:', data.quantity); if (!q) return; extra = { quantity: q }; }
    router.post(`/orphans/campaigns/${props.campaign.public_id}/lists/${props.current.public_id}/edit`, { action, lock_version: props.current.lock_version, reason, ...data, ...extra }, { preserveScroll: true });
}
const listAction = (list, action, extra = {}) => router.post(`/orphans/campaigns/${props.campaign.public_id}/lists/${list.public_id}`, { action, ...extra }, { preserveScroll: true });
function submit() { const note = window.prompt('توصية رئيس القسم (اختياري):', ''); if (note === null) return; listAction(props.current, 'submit', { note }); }
// The preparer hands the draft to the head; only the head refers it, with the recommendation, to the executive director.
function requestReview() { const note = window.prompt('ملاحظة لرئيس القسم (اختياري):', ''); if (note === null) return; listAction(props.current, 'request_review', { note }); }
function decide(action) {
    if (!props.abilities.designated_executive || !props.approval) return;
    ask({ title: action === 'approve' ? 'اعتماد الكشف بقرار المدير التنفيذي' : 'رفض الكشف',
        description: `الإصدار ${props.approval.list.version}. ${props.approval.change ? 'هذا تعديل على كشف معتمد؛ راجع مقارنة التغييرات قبل القرار.' : 'هذا هو الكشف الرسمي الأول.'} الاعتماد لا يُسجل تسليمًا للمستفيدين.`,
        required: action === 'reject', danger: action === 'reject',
        url: `/orphans/campaigns/${props.campaign.public_id}/lists/${props.approval.list.public_id}`,
        data: { action, content_hash: props.approval.list.content_hash } });
}
const change = useForm({ reason: '', appeal_public_id: '' });
const member = useForm({ user_id: null, role: 'viewer', ends_on: '' });
const stock = useForm({ item_id: null, kind: 'issue', quantity: null });
const delivering = ref(null);
const deliveryForm = useForm({ entry_id: null, idempotency_key: '', kind: 'delivered', recipient_name: '', recipient_relation: 'guardian', proxy_note: '', identity_checked: false, failure_reason_value_id: null, note: '' });
function openDelivery(entry) { delivering.value = entry; deliveryForm.reset(); deliveryForm.entry_id = entry.id; deliveryForm.recipient_name = entry.recipient || ''; deliveryForm.idempotency_key = crypto.randomUUID(); }
function saveDelivery() { deliveryForm.post(`/orphans/campaigns/${props.campaign.public_id}/deliveries`, { preserveScroll: true, onSuccess: () => { delivering.value = null; } }); }
function correct(d) { const reason = window.prompt('سبب التصحيح (يبقى الحدث الأصلي محفوظًا):'); if (reason) router.post(`/orphans/campaigns/${props.campaign.public_id}/deliveries/${d.public_id}/correct`, { reason }, { preserveScroll: true }); }
function decideDuplicate(a, status) { const note = window.prompt('المبرر:'); if (note) router.post(`/orphans/campaigns/${props.campaign.public_id}/duplicates/${a.id}`, { status, note }, { preserveScroll: true }); }
const batchForm = useForm({ distributor_user_id: null, device_id: null, governorate_value_id: null });
const batchDevices = computed(() => (props.delivery.distributors || []).find(d => d.id === batchForm.distributor_user_id)?.devices || []);
function resolveEvent(e, decision) { const note = window.prompt(decision === 'confirm' ? 'أساس التأكيد:' : 'سبب الرفض:'); if (note) router.post(`/orphans/campaigns/${props.campaign.public_id}/field-events/${e.id}`, { decision, note }, { preserveScroll: true }); }
function reconcile() { const note = window.prompt('ملاحظات التسوية:', '') ?? null; if (note !== null) router.post(`/orphans/campaigns/${props.campaign.public_id}/reconcile`, { note }, { preserveScroll: true }); }
function closeCampaign() { const note = window.prompt('ملاحظة الإغلاق (إلزامية عند وجود فروقات):', ''); if (note !== null) router.post(`/orphans/campaigns/${props.campaign.public_id}/close`, { note }, { preserveScroll: true }); }
</script>

<template>
    <AppLayout :title="`الحملة ${campaign.reference_no}`">
        <div class="mx-auto max-w-7xl space-y-5">
            <header class="card space-y-2">
                <Link href="/orphans/campaigns" class="text-sm text-blue-700 hover:underline">← الحملات الإغاثية</Link>
                <h1 class="text-2xl font-bold text-navy-800">{{ campaign.title }}</h1>
                <p class="text-sm text-slate-600"><span class="font-mono" dir="ltr">{{ campaign.reference_no }}</span> · {{ campaign.aid_type || 'نوع غير محدد' }} · {{ { orphan: 'لكل يتيم', household: 'لكل أسرة', guardian: 'لكل وصي' }[campaign.beneficiary_unit] }}<span v-if="campaign.donor_name"> · المانح: {{ campaign.donor_name }}</span></p>
                <div class="flex flex-wrap items-center gap-2"><StatusBadge :label="campaign.status_label" tone="info" />
                    <Link v-if="abilities.campaigns && ['draft', 'list_draft'].includes(campaign.status)" :href="`/orphans/campaigns/${campaign.public_id}/wizard`" class="btn btn-sm btn-secondary">متابعة الإعداد</Link></div>
            </header>
            <nav class="flex gap-2 overflow-x-auto pb-2" aria-label="أقسام الحملة"><button v-for="(label, key) in tabs" :key="key" type="button" :class="['btn btn-sm shrink-0', tab === key ? 'btn-primary' : 'btn-secondary']" :aria-pressed="tab === key" @click="tab = key">{{ label }}<span v-if="key === 'approval' && approval" class="ms-1">•<span class="sr-only">بانتظار القرار</span></span></button></nav>
            <p v-if="isApproved" class="alert-info">الكشف المعتمد مقفل — الإصدار {{ current.version }}. أي تعديل لاحق يُقدّم بطلب مسبب ويعتمده المدير التنفيذي قبل أن يصبح نافذًا.</p>

            <section v-if="tab === 'list'" class="card space-y-3 p-0">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4">
                    <label class="text-sm font-semibold">الإصدار<select :value="current?.id" class="field mt-1 font-normal" @change="go({ list: $event.target.value })"><option v-for="l in lists" :key="l.id" :value="l.id">الإصدار {{ l.version }} — {{ l.status_label }}</option></select></label>
                    <StatusBadge v-if="current" :label="current.status === 'draft' && current.base_list_id ? 'تعديل قيد الإعداد' : (current.status === 'pending_executive' && current.base_list_id ? 'تعديل بانتظار موافقة المدير التنفيذي' : lists.find(l => l.id === current.id)?.status_label)" :tone="listTones[current?.status]" />
                    <span v-if="current" class="text-sm text-slate-500">{{ current.entry_count }} سطر</span>
                    <div class="ms-auto flex flex-wrap gap-1">
                        <button v-for="(label, key) in { primary: 'الكشف الأساسي', reserve: 'الاحتياط', review: 'للمراجعة/مستبعدون' }" :key="key" type="button" :class="['btn btn-sm', view === key ? 'btn-primary' : 'btn-secondary']" @click="go({ view: key, page: 1 })">{{ label }}</button>
                    </div>
                </div>
                <div v-if="current && isDraft && (abilities.decide || abilities.prepare)" class="flex flex-wrap items-center gap-2 px-4">
                    <button v-if="abilities.decide" type="button" class="btn btn-primary" @click="submit">رفع للمدير التنفيذي بتوصيتك</button>
                    <button v-else type="button" class="btn btn-primary" @click="requestReview">رفع لرئيس القسم للمراجعة</button>
                    <span class="text-xs text-slate-500">{{ abilities.decide ? 'بعد الرفع يُقفل الكشف للمراجعة؛ الاعتماد حصري للمدير التنفيذي.' : 'يراجع رئيس القسم الكشف ويرفعه بتوصيته للمدير التنفيذي؛ يبقى مسودة حتى ذلك.' }}</span>
                    <span v-if="current.review_requested_at" class="text-xs text-gold-800">رُفع لمراجعة رئيس القسم في {{ current.review_requested_at }}</span></div>
                <div v-if="current?.status === 'pending_executive' && abilities.decide" class="px-4"><button type="button" class="btn btn-sm btn-secondary" @click="listAction(current, 'withdraw')">سحب للتعديل</button></div>
                <div class="overflow-x-auto px-4 pb-4">
                    <EmptyState v-if="!entries || !entries.data.length" title="لا توجد أسطر في هذا العرض" description="تُنشأ مسودة الكشف من خطوة «المراجعة والمحاكاة»." />
                    <table v-else class="data-table min-w-[900px]">
                        <thead><tr><th scope="col">المستفيد</th><th v-if="view !== 'review'" scope="col">المستلم (الوصي)</th><th scope="col">المنطقة</th><th scope="col">الدرجة</th><th scope="col">السبب</th>
                            <th v-if="view !== 'review'" scope="col">آخر استفادة مماثلة</th><th v-if="view !== 'review'" scope="col">التخصيص</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">إجراءات</span></th></tr></thead>
                        <tbody><tr v-for="e in entries.data" :key="e.id || e.candidate_id">
                            <td><button type="button" class="text-start font-semibold text-blue-700 hover:underline" @click="panel = e.candidate_id">{{ e.beneficiary }}</button>
                                <StatusBadge v-if="e.duplicates?.length" class="ms-1" label="تنبيه تكرار" tone="warning" /></td>
                            <td v-if="view !== 'review'">{{ e.recipient || '—' }}</td><td>{{ e.area || 'غير محدد' }}</td><td class="tabular-nums">{{ e.score ?? '—' }}</td>
                            <td class="max-w-xs text-xs text-slate-600">{{ view === 'review' ? e.reasons.join(' · ') : e.reason }}</td>
                            <td v-if="view !== 'review'" class="text-xs">{{ e.last_benefit || 'لا يوجد' }}</td><td v-if="view !== 'review'" class="text-xs">{{ e.item || 'دون صنف' }} × {{ Number(e.quantity) }}</td>
                            <td><StatusBadge v-if="view === 'review'" :label="e.outcome_label" tone="muted" /><StatusBadge v-else-if="isApproved && e.kind === 'primary'" :label="stateLabels[e.state][0]" :tone="stateLabels[e.state][1]" />
                                <StatusBadge v-else :label="e.verified === false ? 'بانتظار التحقق' : 'متحقق'" :tone="e.verified === false ? 'warning' : 'success'" /></td>
                            <td><div class="flex flex-wrap gap-1">
                                <template v-if="isDraft && abilities.prepare">
                                    <template v-if="view === 'review'"><button v-if="e.outcome !== 'ineligible'" type="button" class="btn btn-sm btn-secondary" @click="edit('add', { candidate_id: e.candidate_id, kind: 'primary' })">إضافة للكشف</button></template>
                                    <template v-else><button type="button" class="btn btn-sm btn-secondary" @click="edit('move', { entry_id: e.id, kind: e.kind === 'primary' ? 'reserve' : 'primary' })">{{ e.kind === 'primary' ? 'إلى الاحتياط' : 'إلى الأساسي' }}</button>
                                        <button type="button" class="btn btn-sm btn-secondary" @click="edit('quantity', { entry_id: e.id, quantity: e.quantity })">الكمية</button>
                                        <button type="button" class="btn btn-sm btn-danger" @click="edit('remove', { entry_id: e.id })">حذف</button></template>
                                </template>
                                <button v-if="isApproved && e.kind === 'primary' && abilities.deliver && ['prepared', 'undelivered'].includes(e.state) && ['approved', 'distributing', 'reconciling'].includes(campaign.status)" type="button" class="btn btn-sm btn-primary" @click="openDelivery(e)">تسجيل التسليم</button>
                            </div></td></tr></tbody>
                    </table>
                </div>
                <footer v-if="entries?.last_page > 1" class="flex flex-wrap gap-1 border-t border-slate-200 p-4"><template v-for="(l, i) in entries.links" :key="i"><Link v-if="l.url" :href="l.url" preserve-state preserve-scroll :class="['rounded-lg border px-3 py-2 text-sm', l.active ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-200']" v-html="l.label" /></template></footer>
                <form v-if="delivering" class="mx-4 mb-4 grid gap-3 rounded-xl border border-blue-200 bg-blue-50/40 p-4 sm:grid-cols-3" @submit.prevent="saveDelivery">
                    <h3 class="font-semibold text-navy-800 sm:col-span-3">تسجيل تسليم: {{ delivering.beneficiary }}</h3>
                    <label class="text-sm">النتيجة<select v-model="deliveryForm.kind" class="field mt-1"><option value="delivered">سُلّم</option><option value="undelivered">لم يُسلَّم</option><option value="returned">مُرتجع</option></select></label>
                    <template v-if="deliveryForm.kind === 'delivered'">
                        <label class="text-sm">المستلم الفعلي<input v-model="deliveryForm.recipient_name" class="field mt-1" required></label>
                        <label class="text-sm">صفته<select v-model="deliveryForm.recipient_relation" class="field mt-1"><option value="guardian">الوصي المعتمد</option><option value="beneficiary">المستفيد نفسه</option><option value="proxy">وكيل مفوض</option></select></label>
                        <label v-if="deliveryForm.recipient_relation === 'proxy'" class="text-sm sm:col-span-3">بيان التفويض<input v-model="deliveryForm.proxy_note" class="field mt-1" required></label>
                        <label class="flex items-center gap-2 text-sm sm:col-span-3"><input v-model="deliveryForm.identity_checked" type="checkbox"> تحققت من هوية المستلم</label>
                    </template>
                    <label v-else class="text-sm">السبب<select v-model="deliveryForm.failure_reason_value_id" class="field mt-1"><option :value="null">—</option><option v-for="r in failureReasons" :key="r.id" :value="r.id">{{ r.name }}</option></select></label>
                    <label class="text-sm sm:col-span-3">ملاحظة<input v-model="deliveryForm.note" class="field mt-1"></label>
                    <p v-for="(er, k) in deliveryForm.errors" :key="k" class="field-error sm:col-span-3">{{ er }}</p>
                    <div class="flex gap-2 sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="deliveryForm.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="delivering = null">إلغاء</button></div>
                </form>
            </section>

            <section v-if="tab === 'approval'" class="card space-y-4">
                <EmptyState v-if="!approval" title="لا يوجد كشف بانتظار القرار" description="اعتماد الكشف الأول وأي تعديل لاحق حصري للمدير التنفيذي." />
                <template v-else>
                    <h2 class="card-title">{{ approval.change ? 'طلب تعديل على الكشف المعتمد' : 'الكشف الأول' }} — الإصدار {{ approval.list.version }}</h2>
                    <p class="text-sm text-slate-600">أعدّه {{ approval.list.prepared_name }} · رُفع {{ approval.list.submitted_at?.slice(0, 16) }}<span v-if="approval.list.recommended_name"> · توصية رئيس القسم ({{ approval.list.recommended_name }}): {{ approval.list.recommendation_note || 'دون ملاحظة' }}</span></p>
                    <div v-if="approval.change" class="space-y-2 rounded-xl border border-gold-200 bg-gold-50/40 p-3 text-sm">
                        <p><strong>المبرر:</strong> {{ approval.change.reason }}</p>
                        <p>قبل: أساسي {{ approval.change.diff.before.primary }} · احتياط {{ approval.change.diff.before.reserve }} — بعد: أساسي {{ approval.change.diff.after.primary }} · احتياط {{ approval.change.diff.after.reserve }}</p>
                        <p v-if="approval.change.diff.delivered_affected" class="font-semibold text-red-700">يمسّ التعديل {{ approval.change.diff.delivered_affected }} مستفيدًا سُلّموا فعلًا؛ التسليم السابق لا يُلغى بهذا التعديل.</p>
                        <details open><summary class="cursor-pointer font-semibold">الإضافات ({{ approval.change.diff.added.length }})</summary><ul class="ms-5 list-disc"><li v-for="(a, i) in approval.change.diff.added" :key="i">{{ a.unit }} — {{ a.kind === 'primary' ? 'أساسي' : 'احتياط' }} × {{ a.quantity }}</li></ul></details>
                        <details open><summary class="cursor-pointer font-semibold">الحذف ({{ approval.change.diff.removed.length }})</summary><ul class="ms-5 list-disc"><li v-for="(a, i) in approval.change.diff.removed" :key="i">{{ a.unit }}<span v-if="a.already_delivered" class="text-red-700"> (سُلّم مسبقًا)</span></li></ul></details>
                        <details open><summary class="cursor-pointer font-semibold">التغييرات ({{ approval.change.diff.changed.length }})</summary><ul class="ms-5 list-disc"><li v-for="(a, i) in approval.change.diff.changed" :key="i">{{ a.unit }}: {{ a.from }} ← {{ a.to }}</li></ul></details>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div><h3 class="font-semibold text-navy-800">الكميات والمخزون</h3><table class="data-table mt-1"><thead><tr><th scope="col">الصنف</th><th scope="col">المخطط</th><th scope="col">في الكشف</th><th scope="col">المتاح</th></tr></thead>
                            <tbody><tr v-for="(i, k) in approval.items" :key="k"><td>{{ i.label }}</td><td>{{ i.planned }}</td><td>{{ i.listed }}</td><td :class="i.available !== null && i.available < i.listed ? 'text-red-700' : ''">{{ i.available ?? 'غير مرتبط' }}</td></tr></tbody></table></div>
                        <div><h3 class="font-semibold text-navy-800">التوزيع الجغرافي والحصص</h3><ul class="mt-1 space-y-1 text-sm"><li v-for="r in approval.regions" :key="r.label">{{ r.label }}: {{ r.n }}<span v-if="approval.quotas.find(q => q.label === r.label)"> / حصة {{ approval.quotas.find(q => q.label === r.label).quantity }}</span></li></ul>
                            <p class="mt-2 text-sm">تنبيهات تكرار مفتوحة: {{ approval.duplicates }} · مُستثناة أو معالجة: {{ approval.duplicate_decisions?.length || 0 }}</p>
                            <details v-if="approval.duplicate_decisions?.length" class="mt-1 text-sm"><summary class="cursor-pointer font-semibold">تنبيهات التكرار المُبتّ فيها</summary>
                                <ul class="ms-5 list-disc"><li v-for="d in approval.duplicate_decisions" :key="d.id"><span class="font-mono text-xs" dir="ltr">{{ d.unit_key }}</span> — {{ d.detail }} — <b>{{ d.status === 'exception_granted' ? 'استثناء' : 'معالج' }}</b> ({{ d.decided_name || '—' }}): {{ d.decision_note }}</li></ul></details><p v-if="approval.rules" class="text-sm">نمط الترشيح: {{ { general: 'عام', scored: 'بالنقاط', hybrid: 'مختلط' }[approval.rules.mode] }} · إصدار القواعد {{ approval.rules.version }}</p></div>
                    </div>
                    <p class="font-mono text-xs text-slate-400" dir="ltr">content {{ approval.list.content_hash?.slice(0, 16) }}…</p>
                    <p v-if="!approval.list.recommended_by" class="alert-warning">لم يراجع رئيس القسم هذا الإصدار ولم يوصِ به؛ لا يُعتمد قبل أن يسحبه رئيس القسم ويعيد رفعه بتوصيته.</p>
                    <div v-if="abilities.designated_executive" class="flex flex-wrap gap-2"><button v-if="approval.list.recommended_by" type="button" class="btn btn-primary" @click="decide('approve')">{{ approval.change ? 'اعتماد التعديل' : 'اعتماد الكشف' }}</button><button type="button" class="btn btn-danger" @click="decide('reject')">رفض</button></div>
                    <p v-else class="alert-info">بانتظار قرار المدير التنفيذي. لا يملك غيره صلاحية الاعتماد.</p>
                </template>
            </section>

            <section v-if="tab === 'changes'" class="card space-y-3">
                <form v-if="lists.some(l => l.status === 'approved') && abilities.prepare && !lists.some(l => ['draft', 'pending_executive'].includes(l.status))" class="space-y-2" @submit.prevent="change.post(`/orphans/campaigns/${campaign.public_id}/changes`)">
                    <h2 class="card-title">طلب تعديل</h2>
                    <textarea v-model="change.reason" class="field" rows="2" maxlength="2000" placeholder="مبرر التعديل (إضافة/حذف/استبدال/كميات/حصص)" required />
                    <input v-model="change.appeal_public_id" class="field font-mono" dir="ltr" placeholder="معرّف الاعتراض المرتبط (اختياري)">
                    <p v-for="(e, k) in change.errors" :key="k" class="field-error">{{ e }}</p>
                    <button type="submit" class="btn btn-primary" :disabled="change.processing">طلب تعديل</button>
                    <p class="text-xs text-slate-500">يُنشأ إصدار مسودة جديد؛ الكشف المعتمد لا يتغير حتى يعتمد المدير التنفيذي التعديل.</p>
                </form>
                <ul class="divide-y divide-slate-100 text-sm"><li v-for="r in changes" :key="r.id" class="space-y-1 py-2">
                    <p class="flex flex-wrap items-center gap-2"><span>الإصدار {{ r.version }}</span><StatusBadge :label="{ draft: 'قيد الإعداد', pending: 'بانتظار موافقة المدير التنفيذي', approved: 'معتمد', rejected: 'مرفوض', withdrawn: 'مسحوب' }[r.status]" :tone="{ approved: 'success', rejected: 'danger', pending: 'info' }[r.status] || 'muted'" />
                        <span class="text-slate-500">{{ r.requested_name }} · {{ r.created_at?.slice(0, 16) }}</span></p>
                    <p>{{ r.reason }}</p><p v-if="r.diff" class="text-slate-600">+{{ r.diff.added.length }} / −{{ r.diff.removed.length }} / تغيير {{ r.diff.changed.length }}</p><p v-if="r.decision_note" class="text-slate-600">القرار: {{ r.decision_note }}</p></li>
                    <li v-if="!changes.length" class="text-slate-500">لا توجد طلبات تعديل.</li></ul>
            </section>

            <section v-if="tab === 'delivery'" class="space-y-4">
                <div class="card space-y-2">
                    <h2 class="card-title">حالة التسليم{{ delivery.available ? ` (الإصدار المعتمد ${delivery.version})` : '' }}</h2>
                    <p v-if="!delivery.available" class="text-sm text-slate-500">لا يوجد كشف معتمد؛ الاعتماد ليس تسليمًا ولا يُسجَّل تسليم قبله.</p>
                    <div v-else class="grid gap-3 sm:grid-cols-5 text-sm"><p>الإجمالي: <strong>{{ delivery.total }}</strong></p><p>مُجهز: {{ delivery.prepared }}</p><p>سُلّم: {{ delivery.delivered }}</p><p>لم يُسلَّم: {{ delivery.undelivered }}</p><p>مُرتجع: {{ delivery.returned }}</p></div>
                </div>
                <div class="card space-y-2"><h2 class="card-title">المخازن (الصرف والإرجاع عبر سجل المخازن)</h2>
                    <table class="data-table"><thead><tr><th scope="col">الصنف</th><th scope="col">المخطط</th><th scope="col">المصروف (صافي)</th><th scope="col">المتاح في المخزن</th></tr></thead>
                        <tbody><tr v-for="i in items" :key="i.id"><td>{{ i.label }}{{ i.variant ? ` — ${i.variant}` : '' }}</td><td>{{ Number(i.total_quantity) }}</td><td>{{ i.issued }}</td><td>{{ i.available ?? 'غير مرتبط بالمخازن' }}</td></tr></tbody></table>
                    <form v-if="abilities.stock && ['approved', 'distributing', 'reconciling'].includes(campaign.status)" class="flex flex-wrap items-end gap-2" @submit.prevent="stock.post(`/orphans/campaigns/${campaign.public_id}/stock`, { preserveScroll: true })">
                        <label class="text-sm">الصنف<select v-model="stock.item_id" class="field mt-1" required><option :value="null">اختر</option><option v-for="i in items" :key="i.id" :value="i.id">{{ i.label }}{{ i.variant ? ` — ${i.variant}` : '' }}</option></select></label>
                        <label class="text-sm">العملية<select v-model="stock.kind" class="field mt-1"><option value="issue">صرف للتوزيع</option><option value="return">إرجاع للمخزن</option></select></label>
                        <label class="text-sm">الكمية<input v-model="stock.quantity" type="number" min="0.001" step="0.001" class="field mt-1" required></label>
                        <button type="submit" class="btn btn-secondary" :disabled="stock.processing">تسجيل</button><span v-for="(e, k) in stock.errors" :key="k" class="field-error">{{ e }}</span></form>
                </div>
                <div v-if="delivery.available" class="card space-y-3"><h2 class="card-title">التوزيع الميداني دون اتصال</h2>
                    <form v-if="abilities.assign || abilities.campaigns" class="flex flex-wrap items-end gap-2" @submit.prevent="batchForm.post(`/orphans/campaigns/${campaign.public_id}/batches`, { preserveScroll: true, onSuccess: () => batchForm.reset() })">
                        <label class="text-sm">الموزع<select v-model="batchForm.distributor_user_id" class="field mt-1" required><option :value="null">اختر</option><option v-for="d in delivery.distributors" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
                        <label class="text-sm">الجهاز المعتمد<select v-model="batchForm.device_id" class="field mt-1" required><option :value="null">اختر</option><option v-for="dv in batchDevices" :key="dv.id" :value="dv.id">{{ dv.name }}</option></select></label>
                        <button type="submit" class="btn btn-secondary" :disabled="batchForm.processing">إنشاء دفعة (المتاحون غير المسلّمين)</button>
                        <span v-for="(e, k) in batchForm.errors" :key="k" class="field-error">{{ e }}</span></form>
                    <p v-if="!delivery.distributors?.length" class="text-xs text-slate-500">كلّف موزعًا بدور «تسليم» من تبويب التكليفات، ويعتمد رئيس القسم جهازه من الإعدادات.</p>
                    <ul class="divide-y divide-slate-100 text-sm"><li v-for="b in delivery.batches" :key="b.public_id" class="flex flex-wrap items-center gap-2 py-1.5"><span class="font-mono" dir="ltr">{{ b.reference_no }}</span>
                        <StatusBadge :label="{ assigned: 'مُسندة', downloaded: 'نُزّلت', returned: 'أُعيدت', revoked: 'ملغاة' }[b.status]" tone="info" /><span>{{ b.distributor }} · {{ b.device }} · {{ b.entries }} مستفيد</span>
                        <span v-if="b.offline_expires_at" class="text-slate-500">صلاحية العمل حتى {{ b.offline_expires_at.slice(0, 16) }}</span>
                        <button v-if="abilities.assign && b.status !== 'revoked'" type="button" class="btn btn-sm btn-secondary ms-auto" @click="router.post(`/orphans/campaigns/${campaign.public_id}/batches/${b.public_id}/revoke`, {}, { preserveScroll: true })">إلغاء</button></li></ul>
                    <template v-if="delivery.held?.length"><h3 class="font-semibold text-gold-800">أحداث ميدانية معلقة أو مرفوضة</h3>
                        <ul class="divide-y divide-slate-100 text-sm"><li v-for="e in delivery.held" :key="e.id" class="flex flex-wrap items-center gap-2 py-1.5">
                            <StatusBadge :label="e.status === 'conflict_held' ? 'معلق لتعارض' : 'مرفوض'" :tone="e.status === 'conflict_held' ? 'warning' : 'danger'" /><span>{{ e.reference_no }} · {{ e.distributor }} · {{ e.occurred_at?.slice(0, 16) }}</span>
                            <span class="min-w-0 flex-1 text-slate-600">{{ e.status_reason }}</span>
                            <span v-if="e.status === 'conflict_held' && abilities.head" class="flex gap-1"><button type="button" class="btn btn-sm btn-primary" @click="resolveEvent(e, 'confirm')">تأكيد</button><button type="button" class="btn btn-sm btn-danger" @click="resolveEvent(e, 'reject')">رفض</button></span></li></ul></template>
                </div>
                <div v-if="delivery.available" class="card space-y-2"><h2 class="card-title">آخر أحداث التسليم</h2>
                    <ul class="divide-y divide-slate-100 text-sm"><li v-for="d in delivery.recent" :key="d.public_id" class="flex flex-wrap items-center gap-2 py-1.5">
                        <StatusBadge :label="{ delivered: 'سُلّم', undelivered: 'لم يُسلَّم', returned: 'مُرتجع', correction: 'تصحيح' }[d.kind]" :tone="d.kind === 'delivered' ? 'success' : 'muted'" />
                        <span class="font-mono text-xs" dir="ltr">{{ d.unit_key }}</span><span>{{ d.recipient_name }}</span><span class="text-slate-500">{{ d.occurred_at?.slice(0, 16) }} · {{ d.recorded_by_name }}</span>
                        <button v-if="abilities.decide && d.kind !== 'correction'" type="button" class="btn btn-sm btn-secondary ms-auto" @click="correct(d)">تصحيح</button></li></ul></div>
                <div class="card space-y-2"><h2 class="card-title">التسوية والإغلاق</h2>
                    <div v-for="r in reconciliations" :key="r.id" class="text-sm"><p class="text-slate-500">{{ r.created_at?.slice(0, 16) }} — {{ r.note || 'دون ملاحظة' }}</p>
                        <ul class="ms-5 list-disc"><li v-for="(s, i) in r.summary" :key="i">{{ s.item }}: مصروف {{ s.issued }} · مُسلّم {{ s.delivered }} · مُرتجع {{ s.returned }} · فرق {{ s.variance }}</li></ul></div>
                    <div class="flex gap-2"><button v-if="abilities.assign || abilities.campaigns" type="button" class="btn btn-secondary" :disabled="!['distributing', 'reconciling', 'approved'].includes(campaign.status)" @click="reconcile">تسجيل التسوية</button>
                        <button v-if="abilities.decide" type="button" class="btn btn-primary" :disabled="campaign.status !== 'reconciling'" @click="closeCampaign">إغلاق الحملة</button></div></div>
            </section>

            <section v-if="tab === 'access'" class="card space-y-3">
                <h2 class="card-title">التكليف بهذه الحملة فقط</h2>
                <p class="text-sm text-slate-600">مسؤول العلاقات العامة أو موظفو التوزيع يرون هذه الحملة دون الوصول العام لملفات الأيتام.</p>
                <form v-if="abilities.assign || abilities.executive" class="flex flex-wrap items-end gap-2" @submit.prevent="member.post(`/orphans/campaigns/${campaign.public_id}/members`, { preserveScroll: true, onSuccess: () => member.reset() })">
                    <label class="text-sm">المستخدم<select v-model="member.user_id" class="field mt-1" required><option :value="null">اختر</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                    <label class="text-sm">الدور<select v-model="member.role" class="field mt-1"><option value="viewer">اطلاع</option><option value="staff">إعداد الكشف</option><option value="distributor">تسليم</option></select></label>
                    <label class="text-sm">حتى<input v-model="member.ends_on" type="date" class="field mt-1"></label><button type="submit" class="btn btn-secondary">تكليف</button></form>
                <ul class="text-sm"><li v-for="m in members" :key="m.id" class="flex items-center gap-2 py-1">{{ m.name }} · {{ { viewer: 'اطلاع', staff: 'إعداد الكشف', distributor: 'تسليم' }[m.role] }} · حتى {{ m.ends_on || 'مفتوح' }}
                    <button v-if="abilities.assign" type="button" class="btn btn-sm btn-secondary" @click="router.delete(`/orphans/campaigns/${campaign.public_id}/members/${m.id}`, { preserveScroll: true })">سحب</button></li></ul>
            </section>

            <section v-if="tab === 'duplicates'" class="card space-y-2">
                <h2 class="card-title">تنبيهات المساعدة المماثلة</h2><p class="text-sm text-slate-600">التنبيه لا يستبعد أحدًا تلقائيًا؛ يُعالج بحذف المستفيد من الكشف الأساسي، أو يمنح رئيس القسم استثناءً بمبرر يراه المدير التنفيذي عند الاعتماد.</p>
                <ul class="divide-y divide-slate-100 text-sm"><li v-for="a in duplicates" :key="a.id" class="flex flex-wrap items-center gap-2 py-2"><span class="font-mono text-xs" dir="ltr">{{ a.unit_key }}</span><span>{{ a.detail }}</span>
                    <StatusBadge :label="{ open: 'مفتوح', exception_granted: 'استثناء ممنوح', resolved: 'معالج' }[a.status]" :tone="a.status === 'open' ? 'warning' : 'muted'" />
                    <span v-if="a.decision_note" class="text-xs text-slate-500">— {{ a.decision_note }}</span>
                    <span v-if="a.status === 'open' && (abilities.prepare || abilities.decide)" class="ms-auto flex gap-1"><button v-if="abilities.decide" type="button" class="btn btn-sm btn-secondary" @click="decideDuplicate(a, 'exception_granted')">استثناء</button><button type="button" class="btn btn-sm btn-secondary" @click="decideDuplicate(a, 'resolved')">معالج</button></span></li>
                    <li v-if="!duplicates.length" class="text-slate-500">لا توجد تنبيهات.</li></ul>
            </section>
            <ChangeHistory subject-type="campaigns" :subject-id="campaign.public_id" />
        </div>
        <CandidatePanel :campaign="campaign.public_id" :candidate="panel" @close="panel = null" />
        <DecisionDialog :decision="decision" :busy="decisionBusy" :error="decisionError" @close="closeDecision" @confirm="confirmDecision" />
    </AppLayout>
</template>
