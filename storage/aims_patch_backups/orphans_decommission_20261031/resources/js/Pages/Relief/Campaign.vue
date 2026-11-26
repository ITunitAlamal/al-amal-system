<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import SectionSelect from '../../Components/Orphans/SectionSelect.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';

// One relief campaign: approval, items, priority rules, frozen roster, executive exceptions, members, field batches,
// held field results, reconciliation and closure. Beneficiary identities show only to the department and the ED.
const props = defineProps({
    campaign: { type: Object, required: true }, items: { type: Array, default: () => [] }, approval: { type: Object, default: null },
    closureFlow: { type: Object, default: null }, ruleset: { type: Object, default: null }, roster: { type: Object, default: null },
    entries: { type: Array, default: () => [] }, exceptions: { type: Array, default: () => [] }, members: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] }, held: { type: Array, default: () => [] }, reserved: { type: Array, default: () => [] },
    distributors: { type: Array, default: () => [] }, stats: { type: Object, default: () => ({}) }, reconciliations: { type: Array, default: () => [] },
    closureBlockers: { type: Array, default: () => [] }, options: { type: Object, default: null }, can: { type: Object, default: () => ({}) },
});
const page = usePage();
const tab = ref(new URLSearchParams(String(page.url).split('?')[1] || '').get('tab') || 'overview');
const tabs = computed(() => [['overview', 'نظرة عامة'], ['items', 'البنود'], ['roster', 'الكشف'], ['exceptions', 'الاستثناءات'], ['members', 'التكليفات'],
    ['field', 'التوزيع الميداني'], ['closure', 'التسوية والإغلاق']]);
const base = `/relief/campaigns/${props.campaign.public_id}`;
const post = (action, data = {}, confirmText = null) => { if (confirmText && !window.confirm(confirmText)) return; router.post(`${base}/${action}`, data, { preserveScroll: true }); };
const draft = computed(() => props.campaign.status === 'draft');
const itemForm = useForm({ item_id: null, label: '', kind: 'in_kind', stock_item_id: null, unit: 'طرد', quantity_per_unit: 1, unit_value: null, planned_total: null });
const memberForm = useForm({ user_id: null, role: 'outreach', starts_on: new Date().toISOString().slice(0, 10), ends_on: props.campaign.ends_on, reason: '', may_view_pseudonymous: false });
const exceptionForm = useForm({ entry: null, unit_child_id: null, unit_household_id: null, category_value_id: null, rule_violated: '', explanation: '', staff_recommendation: '', proposed_items: {}, budget_impact: null, stock_impact: '' });
const batchForm = useForm({ distributor_user_id: null, device_id: null, allocations: [] });
const reconcileForm = useForm({ kind: 'stock', reference: '', outcome: 'reconciled', notes: '' });
const candidates = computed(() => props.entries.filter(e => e.decision !== 'selected'));
const ask = text => window.prompt(text);
const devices = computed(() => props.distributors.find(d => d.id === batchForm.distributor_user_id)?.devices || []);
function sendException() {
    const entry = props.entries.find(e => e.id === exceptionForm.entry);
    exceptionForm.transform(d => ({ ...d, unit_child_id: entry?.unit_type === 'child' ? entry.child_id : null, unit_household_id: entry?.household_id ?? null })).post(`${base}/exception`, { preserveScroll: true });
}
function resolve(event, confirm) {
    const note = window.prompt(confirm ? 'أساس التأكيد (يُسجل):' : 'سبب الرفض (يُسجل):');
    if (!note || note.trim().length < 5) return;
    post('resolve', { event_id: event.id, confirm, note });
}
const tone = { draft: 'warning', in_review: 'info', approved: 'info', active: 'success', suspended: 'muted', closing: 'warning', closed: 'muted', rejected: 'danger' };
const decisionTone = { selected: 'success', waitlist: 'warning', excluded: 'muted' };
const allocationLabels = { reserved: 'محجوز', assigned: 'مسند لدفعة', delivered: 'مُسلَّم (مؤكد)', partially_delivered: 'تسليم جزئي', not_delivered: 'لم يُسلَّم', conflict: 'تعارض', cancelled: 'ملغى', returned: 'مرتجع' };
</script>

<template>
    <AppLayout :title="`الحملة ${campaign.reference_no}`">
        <div class="space-y-4">
            <Link href="/relief/campaigns" class="text-sm font-semibold text-blue-700 hover:underline">→ الحملات الإغاثية</Link>
            <PageHeader eyebrow="حملة إغاثية — قسم الكفالات" :title="`${campaign.title}`" :description="`${campaign.reference_no} — ${campaign.starts_on} ← ${campaign.ends_on} — وحدة الاستفادة: ${campaign.unit_label}`">
                <template #meta><div class="mt-2"><StatusBadge :label="campaign.status_label" :tone="tone[campaign.status]" /></div></template>
                <button v-if="can.prepare && draft && !campaign.workflow_instance_id" type="button" class="btn btn-primary btn-sm" @click="post('submit')">رفع للاعتماد</button>
                <button v-if="(can.head || can.manager) && campaign.status === 'approved'" type="button" class="btn btn-primary btn-sm" @click="post('activate', {}, 'بدء الحملة؟')">بدء الحملة</button>
                <button v-if="can.executive && campaign.status === 'active'" type="button" class="btn btn-secondary btn-sm" @click="post('suspend', { reason: ask('سبب الإيقاف:') })">إيقاف</button>
                <button v-if="can.executive && campaign.status === 'suspended'" type="button" class="btn btn-secondary btn-sm" @click="post('resume', { reason: ask('سبب الاستئناف:') })">استئناف</button>
            </PageHeader>
            <SectionSelect v-model="tab" :options="tabs" label="أقسام الحملة" />

            <template v-if="tab === 'overview'">
                <section v-if="approval" class="card"><StatusStepper :steps="approval.progress.steps" :summary="approval.progress.summary" /></section>
                <WorkflowActions v-if="approval" :workflow="approval.workflow" title="قرار اعتماد الحملة" />
                <section class="grid gap-3 sm:grid-cols-4">
                    <div class="card"><p class="text-xs text-slate-500">تسليم مؤكد (وحدات)</p><p class="text-2xl font-bold">{{ stats.confirmed_units }}</p></div>
                    <div class="card"><p class="text-xs text-slate-500">محجوز/مسند</p><p class="text-2xl font-bold">{{ (stats.allocations?.reserved || 0) + (stats.allocations?.assigned || 0) }}</p></div>
                    <div class="card"><p class="text-xs text-slate-500">لم يُسلّم / تعارض</p><p class="text-2xl font-bold">{{ (stats.allocations?.not_delivered || 0) + (stats.allocations?.conflict || 0) }}</p></div>
                    <div class="card"><p class="text-xs text-slate-500">دفعات بانتظار الرفع</p><p class="text-2xl font-bold">{{ stats.pending_sync }}</p></div>
                </section>
                <section class="card space-y-2 text-sm">
                    <p class="text-xs text-slate-500">الأرقام من الأحداث المؤكدة على الخادم فقط؛ التسليمات المسجلة دون اتصال ولم تُرفع لا تُحتسب. المال من الحسابات (للاطلاع)، والمخزون من حركات المخازن.</p>
                    <p><span class="text-slate-500">الطاقة الاستيعابية:</span> {{ campaign.capacity ?? 'غير محددة' }} — <span class="text-slate-500">مدير الحملة:</span> {{ campaign.manager }}</p>
                    <p v-if="campaign.fund"><span class="text-slate-500">التمويل المخصص:</span> {{ campaign.fund.name }} — مستلم {{ campaign.fund.received }} / ملتزم {{ campaign.fund.committed }} / مصروف {{ campaign.fund.spent }} {{ campaign.fund.currency }}</p>
                    <p><span class="text-slate-500">صرف مخزني مرتبط بالحملة:</span> {{ stats.stock_issued }}</p>
                    <p v-if="stats.reach?.length"><span class="text-slate-500">الوصول الجغرافي (تسليم مؤكد):</span> <span v-for="r in stats.reach" :key="r.area" class="me-2">{{ r.area }}: {{ r.units }}</span></p>
                    <p v-if="campaign.status_reason"><span class="text-slate-500">ملاحظة الحالة:</span> {{ campaign.status_reason }}</p>
                    <p><span class="text-slate-500">الأهلية:</span> {{ campaign.eligibility.min_age ?? '—' }}–{{ campaign.eligibility.max_age ?? '—' }} سنة{{ campaign.eligibility.displaced_only ? '، للنازحين فقط' : '' }}{{ campaign.eligibility.orphan_classes?.length ? '، تصنيفات محددة' : '' }}{{ campaign.eligibility.governorates?.length ? '، نطاق جغرافي محدد' : '' }}</p>
                </section>
            </template>

            <section v-else-if="tab === 'items'" class="card space-y-3">
                <table class="w-full text-sm"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">البند</th><th>النوع</th><th>لكل مستفيد</th><th>الكلي المخطط</th><th>قيمة الوحدة</th><th></th></tr></thead>
                    <tbody><tr v-for="i in items" :key="i.id" class="border-t border-slate-100"><td class="py-2">{{ i.label }}<span v-if="i.stock_item" class="block text-xs text-slate-500">صنف مخزني: {{ i.stock_item }}</span></td>
                        <td>{{ i.kind }}</td><td>{{ i.quantity_per_unit }} {{ i.unit }}</td><td>{{ i.planned_total }}</td><td>{{ i.unit_value ?? '—' }}</td>
                        <td><button v-if="can.prepare && draft" type="button" class="text-xs text-red-700 underline" @click="post('remove-item', { item_id: i.id })">حذف</button></td></tr></tbody></table>
                <form v-if="can.prepare && draft" class="grid gap-2 border-t border-slate-100 pt-3 text-sm sm:grid-cols-4" @submit.prevent="itemForm.post(`${base}/item`, { preserveScroll: true, onSuccess: () => itemForm.reset() })">
                    <input v-model="itemForm.label" class="field sm:col-span-2" placeholder="البند (مثل طرد غذائي)" required maxlength="200">
                    <select v-model="itemForm.kind" class="field"><option value="in_kind">عيني</option><option value="cash">نقدي (تصرفه الحسابات)</option><option value="voucher">قسيمة</option><option value="service">خدمة</option></select>
                    <select v-model="itemForm.stock_item_id" class="field"><option :value="null">دون ربط مخزني</option><option v-for="s in options?.stock_items || []" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    <input v-model="itemForm.unit" class="field" placeholder="الوحدة" required maxlength="30">
                    <input v-model.number="itemForm.quantity_per_unit" type="number" min="0.01" step="0.01" class="field" placeholder="لكل مستفيد" required>
                    <input v-model.number="itemForm.planned_total" type="number" min="0.01" step="0.01" class="field" placeholder="الكمية الكلية المخططة" required>
                    <input v-model.number="itemForm.unit_value" type="number" min="0" step="0.01" class="field" placeholder="قيمة الوحدة">
                    <p v-for="(m, k) in itemForm.errors" :key="k" class="field-error sm:col-span-4">{{ m }}</p>
                    <div><button class="btn btn-primary btn-sm" :disabled="itemForm.processing">إضافة البند</button></div>
                </form>
                <p v-else class="text-xs text-slate-500">البنود ثابتة بعد رفع الحملة للاعتماد.</p>
            </section>

            <template v-else-if="tab === 'roster'">
                <section class="card space-y-2 text-sm">
                    <p><span class="text-slate-500">قواعد أولوية الحملة:</span> {{ ruleset ? `${ruleset.name} — إصدار ${ruleset.version} (${ruleset.status === 'approved' ? 'معتمدة' : 'غير معتمدة'})` : 'لا توجد' }}
                        <Link v-if="can.prepare || can.executive" :href="`${base}/rulesets`" class="ms-2 text-blue-700 underline">إدارة القواعد</Link></p>
                    <p class="text-xs text-slate-500">شروط الأهلية بوابات مستقلة؛ التقييم يرتب المؤهلين فقط ولا يقرر وحده. لكل مراجعة بصمة مجمّدة، ولا يتغير كشف معتمد بتغير البيانات أو القواعد لاحقًا.</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="can.prepare && ['approved', 'active'].includes(campaign.status)" type="button" class="btn btn-primary btn-sm" @click="post('roster', {}, 'إعداد مراجعة جديدة للكشف بالقواعد المعتمدة؟')">إعداد/تحديث مسودة الكشف</button>
                        <button v-if="can.prepare && roster?.status === 'draft' && !roster.workflow_instance_id" type="button" class="btn btn-secondary btn-sm" @click="post('roster-submit', { roster_id: roster.id })">رفع الكشف للقرار</button>
                    </div>
                </section>
                <template v-if="roster">
                    <section v-if="roster.progress" class="card"><StatusStepper :steps="roster.progress.steps" :summary="roster.progress.summary" /></section>
                    <WorkflowActions v-if="roster.workflow" :workflow="roster.workflow" title="قرار الكشف" />
                    <section class="card space-y-2 text-sm">
                        <p class="font-semibold">المراجعة {{ roster.revision }} — {{ roster.status_label }} — مختار {{ roster.selected_count }}، انتظار {{ roster.waitlist_count }}، مستبعد {{ roster.excluded_count }} (من {{ roster.candidate_count }}؛ الطاقة {{ roster.capacity ?? 'غير محددة' }})</p>
                        <p class="text-xs text-slate-500" dir="ltr">sha256 {{ roster.snapshot_hash }}</p>
                        <div v-if="can.pseudonyms" class="overflow-x-auto">
                            <table class="w-full text-xs"><thead class="text-right text-slate-500"><tr><th class="py-1">الترتيب</th><th>المرجع</th><th v-if="can.identities">المستفيد</th><th>المنطقة</th><th>الأهلية</th><th v-if="can.identities">التقييم</th><th>القرار</th><th>السبب</th></tr></thead>
                                <tbody><tr v-for="e in entries" :key="e.id" class="border-t border-slate-100 align-top">
                                    <td class="py-1">{{ e.rank ?? '—' }}<span v-if="e.rank_area" class="text-slate-400"> ({{ e.rank_area }} محليًا)</span></td><td dir="ltr">{{ e.pseudonym }}</td>
                                    <td v-if="can.identities">{{ e.name }} <span class="text-slate-400">{{ e.reference }}</span></td><td>{{ e.area || '—' }}</td><td>{{ e.eligibility }}</td>
                                    <td v-if="can.identities">{{ e.score ?? '—' }}</td><td><StatusBadge :label="{ selected: 'مختار', waitlist: 'انتظار', excluded: 'مستبعد' }[e.decision]" :tone="decisionTone[e.decision]" /></td>
                                    <td>{{ e.reason }}</td></tr></tbody></table>
                        </div>
                        <p v-else class="text-slate-500">تظهر لك الأرقام المجمعة فقط وفق تكليفك؛ لا تُعرض مراجع المستفيدين.</p>
                    </section>
                </template>
            </template>

            <template v-else-if="tab === 'exceptions'">
                <section class="card space-y-1 text-sm">
                    <p class="text-xs text-slate-500">كل انحراف عن قواعد الحملة يقرره المدير التنفيذي شخصيًا وحصريًا (لا تفويض، ولا لرئيس القسم أو مدير النظام). لا يدخل المستثنى أي دفعة ميدانية قبل إعادة اعتماد الكشف.</p>
                    <p v-for="x in exceptions" :key="x.id"><Link :href="`/relief/exceptions/${x.id}`" class="font-semibold text-blue-700 hover:underline">{{ x.reference_no }}</Link> — {{ x.category }} — {{ x.status_label }}</p>
                    <p v-if="!exceptions.length" class="text-slate-500">لا توجد طلبات استثناء.</p>
                </section>
                <form v-if="can.prepare && can.identities && ['approved', 'active'].includes(campaign.status)" class="card space-y-3 text-sm" @submit.prevent="sendException">
                    <h2 class="card-title">طلب استثناء لمستفيد</h2>
                    <label class="block"><span class="field-label">المستفيد (من الكشف الحالي غير المختارين)</span>
                        <select v-model="exceptionForm.entry" class="field" required><option :value="null">اختر</option><option v-for="e in candidates" :key="e.id" :value="e.id">{{ e.pseudonym }} — {{ e.name }} ({{ e.decision }})</option></select></label>
                    <label class="block"><span class="field-label">فئة الانحراف *</span><select v-model="exceptionForm.category_value_id" class="field" required><option :value="null">اختر</option><option v-for="c in options?.exception_categories || []" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                    <label class="block"><span class="field-label">القاعدة المخالفة *</span><input v-model="exceptionForm.rule_violated" class="field" maxlength="1000" required></label>
                    <label class="block"><span class="field-label">الشرح والأدلة *</span><textarea v-model="exceptionForm.explanation" class="field" rows="3" maxlength="3000" required></textarea></label>
                    <label class="block"><span class="field-label">توصية الموظف *</span><textarea v-model="exceptionForm.staff_recommendation" class="field" rows="2" maxlength="2000" required></textarea></label>
                    <div class="grid gap-2 sm:grid-cols-3"><label v-for="i in items" :key="i.id"><span class="field-label">{{ i.label }} ({{ i.unit }})</span><input v-model.number="exceptionForm.proposed_items[i.id]" type="number" min="0" step="0.01" class="field"></label></div>
                    <div class="grid gap-2 sm:grid-cols-2"><label><span class="field-label">الأثر على الميزانية</span><input v-model.number="exceptionForm.budget_impact" type="number" min="0" step="0.01" class="field"></label>
                        <label><span class="field-label">الأثر على المخزون</span><input v-model="exceptionForm.stock_impact" class="field" maxlength="1000"></label></div>
                    <p v-for="(m, k) in exceptionForm.errors" :key="k" class="field-error">{{ m }}</p>
                    <button class="btn btn-primary btn-sm" :disabled="exceptionForm.processing">تقديم الطلب</button>
                </form>
            </template>

            <template v-else-if="tab === 'members'">
                <section class="card text-sm">
                    <p class="text-xs text-slate-500">تكليف محدد المدة في هذه الحملة فقط؛ لا يمنح اطلاعًا على ملفات الأطفال. الموزعون يكلفهم رئيس القسم وحده.</p>
                    <ul class="mt-2 divide-y divide-slate-100"><li v-for="m in members" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 py-2" :class="m.revoked_at ? 'opacity-60' : ''">
                        <span>{{ m.name }} — {{ m.role_label }} — {{ m.starts_on }} ← {{ m.ends_on }}<span v-if="m.may_view_pseudonymous"> — يرى المراجع المستعارة</span><span v-if="m.revoked_at"> (منتهٍ)</span></span>
                        <button v-if="!m.revoked_at && (can.head || (can.admin && m.role !== 'field_distributor'))" type="button" class="text-xs text-red-700 underline" @click="post('member-revoke', { member_id: m.id, reason: ask('سبب الإنهاء:') || 'إنهاء التكليف' })">إنهاء</button></li></ul>
                </section>
                <form v-if="can.head || can.admin" class="card grid gap-2 text-sm sm:grid-cols-3" @submit.prevent="memberForm.post(`${base}/member`, { preserveScroll: true, onSuccess: () => memberForm.reset('user_id', 'reason') })">
                    <select v-model="memberForm.user_id" class="field" required><option :value="null">الموظف</option><option v-for="u in options?.staff || []" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                    <select v-model="memberForm.role" class="field"><option value="outreach">التواصل والإعلام</option><option value="media">المحتوى الإعلامي</option><option value="coordinator">منسق</option><option value="reviewer">مراجع</option><option v-if="can.head" value="field_distributor">موزع ميداني</option></select>
                    <label class="flex items-center gap-2"><input v-model="memberForm.may_view_pseudonymous" type="checkbox"> يرى المراجع المستعارة</label>
                    <input v-model="memberForm.starts_on" type="date" class="field" required><input v-model="memberForm.ends_on" type="date" class="field" required>
                    <input v-model="memberForm.reason" class="field" placeholder="سبب التكليف ونطاقه" required maxlength="1000">
                    <p v-for="(m, k) in memberForm.errors" :key="k" class="field-error sm:col-span-3">{{ m }}</p>
                    <div><button class="btn btn-primary btn-sm" :disabled="memberForm.processing">تكليف</button></div>
                </form>
            </template>

            <template v-else-if="tab === 'field'">
                <section class="card overflow-x-auto text-sm">
                    <table class="w-full"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الدفعة</th><th>الموزع / الجهاز</th><th>المستفيدون</th><th>مؤكد / معلق</th><th>صلاحية دون اتصال</th><th>الحالة</th><th></th></tr></thead>
                        <tbody><tr v-for="b in batches" :key="b.id" class="border-t border-slate-100"><td class="py-2">{{ b.reference_no }}<span v-if="b.manifest_version" class="block text-xs text-slate-500">بيان {{ b.manifest_version }}</span></td>
                            <td>{{ b.distributor }}<span class="block text-xs text-slate-500">{{ b.device }}</span></td><td>{{ b.allocations }}</td><td>{{ b.confirmed }} / {{ b.held }}</td>
                            <td class="text-xs" dir="ltr">{{ b.offline_expires_at ? String(b.offline_expires_at).slice(0, 16) : '—' }}</td><td>{{ b.status_label }}</td>
                            <td class="whitespace-nowrap"><template v-if="can.head">
                                <button v-if="['assigned', 'downloaded', 'returned'].includes(b.status)" type="button" class="text-xs text-red-700 underline" @click="post('batch-revoke', { batch_id: b.id, reason: ask('سبب إلغاء الدفعة:') })">إلغاء</button>
                                <button v-if="['downloaded', 'returned'].includes(b.status)" type="button" class="ms-2 text-xs text-blue-700 underline" @click="post('batch-close', { batch_id: b.id })">إغلاق</button></template></td></tr>
                            <tr v-if="!batches.length"><td colspan="7" class="py-3 text-slate-500">لا توجد دفعات.</td></tr></tbody></table>
                </section>
                <form v-if="can.head && campaign.status === 'active'" class="card space-y-2 text-sm" @submit.prevent="batchForm.post(`${base}/batch`, { preserveScroll: true, onSuccess: () => batchForm.reset() })">
                    <h2 class="card-title">إسناد دفعة لموزع (رئيس القسم وحده)</h2>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <select v-model="batchForm.distributor_user_id" class="field" required><option :value="null">الموزع المكلف</option><option v-for="d in distributors" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                        <select v-model="batchForm.device_id" class="field" required><option :value="null">{{ devices.length ? 'الجهاز المعتمد' : 'لا يوجد جهاز معتمد لهذا الموزع' }}</option><option v-for="d in devices" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                    </div>
                    <div class="max-h-64 overflow-y-auto rounded-lg border border-slate-200 p-2">
                        <label v-for="a in reserved.filter(r => r.status === 'reserved')" :key="a.public_id" class="me-3 inline-flex items-center gap-1"><input v-model="batchForm.allocations" type="checkbox" :value="a.public_id"> <span dir="ltr">{{ a.pseudonym }}</span> ({{ a.area }})</label>
                        <p v-if="!reserved.some(r => r.status === 'reserved')" class="text-slate-500">لا يوجد مستفيدون محجوزون غير مسندين في الكشف المعتمد.</p>
                    </div>
                    <p v-for="(m, k) in batchForm.errors" :key="k" class="field-error">{{ m }}</p>
                    <button class="btn btn-primary btn-sm" :disabled="batchForm.processing || !batchForm.allocations.length">إسناد ({{ batchForm.allocations.length }})</button>
                </form>
                <section v-if="reserved.some(r => r.status === 'not_delivered') && can.head" class="card text-sm">
                    <h2 class="card-title">لم يُسلّم — إعادة محاولة لنفس المستفيد</h2>
                    <p v-for="a in reserved.filter(r => r.status === 'not_delivered')" :key="a.public_id"><span dir="ltr">{{ a.pseudonym }}</span> ({{ a.area }}) <button type="button" class="text-xs text-blue-700 underline" @click="post('requeue', { allocation_id: a.id })">إعادة للحجز</button></p>
                    <p class="text-xs text-slate-500">استبدال مستفيد بآخر استثناء يقرره المدير التنفيذي.</p>
                </section>
                <section v-if="held.length" class="card text-sm">
                    <h2 class="card-title">نتائج ميدانية معلقة أو مرفوضة</h2>
                    <div v-for="e in held" :key="e.id" class="border-t border-slate-100 py-2">
                        <p>{{ e.batch }} — {{ e.kind }} — {{ e.status_label }} — {{ String(e.occurred_at).slice(0, 16) }}</p><p class="text-xs text-amber-800">{{ e.status_reason }}</p>
                        <div v-if="can.head && e.status === 'conflict_held'" class="mt-1 flex gap-2"><button type="button" class="btn btn-primary btn-sm" @click="resolve(e, true)">تأكيد بعد المراجعة</button><button type="button" class="btn btn-secondary btn-sm" @click="resolve(e, false)">رفض</button></div>
                    </div>
                </section>
            </template>

            <template v-else>
                <section class="card space-y-2 text-sm">
                    <h2 class="card-title">شروط الإغلاق</h2>
                    <ul v-if="closureBlockers.length" class="list-inside list-disc text-amber-800"><li v-for="b in closureBlockers" :key="b">{{ b }}</li></ul>
                    <p v-else-if="['active', 'suspended'].includes(campaign.status)" class="text-emerald-700">الأدلة مكتملة؛ يمكن طلب الإغلاق.</p>
                    <button v-if="(can.manager || can.head) && ['active', 'suspended'].includes(campaign.status) && !closureBlockers.length" type="button" class="btn btn-primary btn-sm" @click="post('close', {}, 'رفع طلب إغلاق الحملة للقرار؟')">طلب الإغلاق</button>
                    <h3 class="font-semibold pt-2">التسويات المسجلة من الحسابات/المخازن</h3>
                    <p v-for="(r, i) in reconciliations" :key="i">{{ r.kind === 'stock' ? 'مخزنية' : 'مالية' }} — {{ r.reference }} — {{ r.outcome === 'reconciled' ? 'مطابقة' : 'فرق' }} ({{ r.confirmed_units }} وحدة) — {{ r.name }} {{ String(r.created_at).slice(0, 10) }}<span v-if="r.notes"> — {{ r.notes }}</span></p>
                    <p v-if="!reconciliations.length" class="text-slate-500">لا توجد تسويات.</p>
                </section>
                <form v-if="can.reconcile" class="card grid gap-2 text-sm sm:grid-cols-4" @submit.prevent="reconcileForm.post(`${base}/reconcile`, { preserveScroll: true })">
                    <select v-model="reconcileForm.kind" class="field"><option value="stock">تسوية مخزنية</option><option value="finance">تسوية مالية</option></select>
                    <input v-model="reconcileForm.reference" class="field" placeholder="مرجع السند/الحركة" required maxlength="120">
                    <select v-model="reconcileForm.outcome" class="field"><option value="reconciled">مطابقة</option><option value="discrepancy">يوجد فرق</option></select>
                    <input v-model="reconcileForm.notes" class="field" placeholder="ملاحظات (إلزامية عند الفرق)" maxlength="2000">
                    <p v-for="(m, k) in reconcileForm.errors" :key="k" class="field-error sm:col-span-4">{{ m }}</p>
                    <div><button class="btn btn-primary btn-sm" :disabled="reconcileForm.processing">تسجيل التسوية</button></div>
                </form>
                <section v-if="closureFlow" class="card"><StatusStepper :steps="closureFlow.progress.steps" :summary="closureFlow.progress.summary" /></section>
                <WorkflowActions v-if="closureFlow" :workflow="closureFlow.workflow" title="قرار الإغلاق" />
            </template>
        </div>
    </AppLayout>
</template>
