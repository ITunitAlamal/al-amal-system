<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import DecisionDialog from '../../../Components/DecisionDialog.vue';
import { useDecision } from '../../../Support/useDecision.js';
import { jsonRequest } from '../../../Support/orphans.js';
import RegistrationSummary from '../../../Components/Registration/RegistrationSummary.vue';

// One workspace: applicant vs registry, files, each claimed child with matches, checklist, decisions, history.
// A family request also brings its reported snapshot, the reconciliation of its totals, and identity conflicts.
const props = defineProps({ application: Object, account: Object, guardian: Object, applicantMatches: Array, children: Array, checklistItems: Array, documents: Array, events: Array,
    abilities: Object, staff: Array, options: Object, me: Number, snapshot: { type: Object, default: null } });
const closed = computed(() => ['completed', 'rejected', 'withdrawn'].includes(props.application.status));
const { decision: confirmation, busy: decisionBusy, error: decisionError, ask, close: closeDecision, confirm: confirmDecision } = useDecision();
const checklist = useForm({ checklist: { ...props.application.checklist }, review_notes: props.application.review_notes || '' });
const childStatus = computed(() => Object.fromEntries(props.children.filter(c => c.card_key).map(c => [c.card_key, { status: c.status, label: c.status_label }])));
const assign = useForm({ user_id: props.application.assigned_to, due_on: props.application.due_on || '' });
const completion = useForm({ message: props.application.completion_request || '', children: [] });
const decision = ref(null);
const childForm = useForm({ decision: 'approve', note: '', starts_on: new Date().toISOString().slice(0, 10), ends_on: '', authority_kind_value_id: null, legal_basis: '', orphan_public_id: null, evidence_note: '', resolve_conflicts: false });
const revealed = ref('');
const tones = { pending: 'info', approved: 'success', rejected: 'danger', needs_completion: 'warning' };
const actions = { submitted: 'قُدّم الطلب', assigned: 'إسناد', checklist_saved: 'حفظ قائمة الفحص', account_approved: 'اعتماد الحساب', account_rejected: 'رفض الحساب', child_approved: 'اعتماد وصاية طفل',
    child_rejected: 'رفض ادعاء طفل', completion_requested: 'طلب استكمال', resubmitted: 'أعيد تقديم الطلب', completed: 'اكتمل البت', rejected: 'رُفض الطلب',
    draft_created: 'أُنشئت المسودة', document_attached: 'أُرفق مستند', document_removed: 'حُذف مرفق', draft_discarded: 'حُذفت المسودة' };
function openChild(child, kind) { decision.value = child.id; childForm.reset(); childForm.decision = kind; childForm.orphan_public_id = child.orphan_public_id; }
function submitChild(child) { childForm.post(`/orphans/reviews/${props.application.public_id}/children/${child.id}`, { preserveScroll: true, onSuccess: () => { decision.value = null; } }); }
function accountDecision(kind) {
    if (closed.value || !props.abilities.portal) return;
    ask({ title: kind === 'approve' ? 'اعتماد حساب البوابة' : 'رفض حساب البوابة',
        description: 'هذا القرار يخص الحساب فقط. تبقى علاقة الوصاية قرارًا مستقلًا لكل طفل.',
        required: kind === 'reject', danger: kind === 'reject',
        url: `/orphans/reviews/${props.application.public_id}/account`, data: { decision: kind } });
}
function rejectAll() {
    if (closed.value || !props.abilities.portal) return;
    ask({ title: 'رفض الطلب كاملًا', description: 'سيُسجل رفض الطلب وسببه. راجع قرارات الأطفال وأدلتهم قبل التأكيد.', required: true, danger: true,
        url: `/orphans/reviews/${props.application.public_id}/reject`, data: {} });
}
async function reveal() { try { revealed.value = (await jsonRequest(`/orphans/reviews/${props.application.public_id}/reveal-id`, {})).id_number; } catch (e) { revealed.value = e.message; } }
</script>

<template>
    <AppLayout :title="`مراجعة الطلب ${application.reference_no}`">
        <div class="mx-auto max-w-7xl space-y-5">
            <header class="card flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1">
                    <Link href="/orphans/reviews" class="text-sm text-blue-700 hover:underline">← الطلبات والمراجعات</Link>
                    <h1 class="text-2xl font-bold text-navy-800">طلب <span class="font-mono" dir="ltr">{{ application.reference_no }}</span></h1>
                    <p class="text-sm text-slate-600">قُدّم {{ application.submitted_at?.slice(0, 16) }} · المسؤول: {{ application.assignee || 'غير مسند' }}<span v-if="application.due_on"> · الاستحقاق {{ application.due_on }}</span></p>
                    <StatusBadge :label="application.status_label" tone="info" />
                </div>
                <form v-if="!closed && (abilities.assign || (abilities.portal && !application.assigned_to))" class="flex flex-wrap items-end gap-2" @submit.prevent="assign.post(`/orphans/reviews/${application.public_id}/assign`, { preserveScroll: true })">
                    <label v-if="abilities.assign" class="text-sm">إسناد إلى<select v-model="assign.user_id" class="field mt-1"><option :value="null">غير مسند</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label v-if="abilities.assign" class="text-sm">الاستحقاق<input v-model="assign.due_on" type="date" class="field mt-1"></label>
                    <button v-if="abilities.assign" type="submit" class="btn btn-secondary" :disabled="assign.processing">حفظ الإسناد</button>
                    <button v-else type="button" class="btn btn-primary" @click="router.post(`/orphans/reviews/${application.public_id}/assign`, { user_id: me }, { preserveScroll: true })">استلام الطلب</button>
                </form>
            </header>

            <div class="grid gap-5 lg:grid-cols-3">
                <section class="card space-y-3 lg:col-span-2">
                    <h2 class="card-title">مقدم الطلب مقارنة بالسجل</h2>
                    <div class="grid gap-4 sm:grid-cols-2 text-sm">
                        <div class="rounded-xl border border-slate-200 p-3"><h3 class="font-semibold text-navy-800">ما قدّمه في البوابة</h3>
                            <p class="mt-1">{{ [application.first_name, application.father_name, application.grandfather_name, application.family_name].filter(Boolean).join(' / ') || application.full_name }}</p>
                            <p>الهوية: <span class="font-mono" dir="ltr">{{ revealed || application.id_masked }}</span> <button v-if="abilities.identity && !revealed" type="button" class="btn btn-sm btn-secondary" @click="reveal">إظهار (يُسجَّل)</button></p>
                            <p>الجوال: <span dir="ltr">{{ application.phone }}</span></p><p v-if="application.applicant_note" class="mt-1 rounded bg-slate-50 p-2">{{ application.applicant_note }}</p></div>
                        <div class="rounded-xl border border-slate-200 p-3"><h3 class="font-semibold text-navy-800">السجل</h3>
                            <template v-if="guardian"><p class="mt-1"><Link :href="`/orphans/registry/guardians/${guardian.public_id}`" class="text-blue-700 hover:underline">{{ guardian.full_name }}</Link> <span class="font-mono text-xs" dir="ltr">{{ guardian.reference_no }}</span></p>
                                <p>الهوية: <span class="font-mono" dir="ltr">{{ guardian.id_masked }}</span> (مطابقة تامة)</p><p>الجوال المسجل: <span dir="ltr">{{ guardian.phone || '—' }}</span></p></template>
                            <template v-else><p class="mt-1 text-slate-600">لا يوجد وصي مسجل بنفس رقم الهوية؛ يُنشأ سجله عند الاعتماد.</p>
                                <p v-for="m in applicantMatches" :key="m.public_id" class="mt-1 text-gold-800">تشابه: {{ m.full_name }} ({{ m.reason }}) — {{ m.roles.join('، ') || 'دون ملف' }}</p></template></div>
                    </div>
                    <p v-if="application.source === 'staff'" class="rounded-xl bg-slate-50 p-3 text-sm">طلب أُدخل من القسم؛ لا يوجد حساب بوابة مرتبط به.</p>
                    <div v-else class="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm">
                        <span>حساب البوابة:</span><StatusBadge :label="{ pending: 'بانتظار الاعتماد', approved: 'معتمد', rejected: 'مرفوض', suspended: 'موقوف' }[account?.status] || 'غير معروف'" :tone="account?.status === 'approved' ? 'success' : 'info'" />
                        <template v-if="!closed && abilities.portal && account?.status === 'pending'">
                            <button type="button" class="btn btn-sm btn-primary" @click="accountDecision('approve')">اعتماد الحساب</button>
                            <button type="button" class="btn btn-sm btn-danger" @click="accountDecision('reject')">رفض الحساب</button></template>
                        <span class="text-xs text-slate-500">اعتماد الحساب لا يفتح ملف أي طفل.</span>
                    </div>
                </section>
                <section class="card space-y-3 lg:sticky lg:top-24 lg:self-start">
                    <h2 class="card-title">قائمة الفحص</h2>
                    <p v-if="!checklistItems.length" class="text-sm text-slate-500">لا توجد بنود؛ تُضاف من «ثوابت النظام» (قائمة فحص طلبات البوابة).</p>
                    <form v-else class="space-y-2" @submit.prevent="checklist.post(`/orphans/reviews/${application.public_id}/checklist`, { preserveScroll: true })">
                        <label v-for="item in checklistItems" :key="item.id" class="flex items-center gap-2 text-sm"><input v-model="checklist.checklist[item.id]" type="checkbox" :disabled="closed"> {{ item.name }}</label>
                        <button v-if="!closed" type="submit" class="btn btn-sm btn-secondary" :disabled="checklist.processing">حفظ</button></form>
                    <form v-if="abilities.portal" class="space-y-2" @submit.prevent="checklist.post(`/orphans/reviews/${application.public_id}/checklist`, { preserveScroll: true })">
                        <label class="field-label">ملاحظات المراجعة الإدارية<textarea v-model="checklist.review_notes" class="field mt-1 font-normal" rows="3" maxlength="5000" :disabled="closed" /></label>
                        <button v-if="!closed" type="submit" class="btn btn-sm btn-secondary" :disabled="checklist.processing">حفظ الملاحظات</button></form>
                    <template v-if="snapshot?.missingDocuments?.length">
                        <h2 class="card-title pt-2">مستندات ناقصة</h2>
                        <ul class="list-disc space-y-1 ps-5 text-sm text-gold-900"><li v-for="m in snapshot.missingDocuments" :key="`${m.target}-${m.type}`">{{ m.label }}</li></ul>
                        <p class="text-xs text-slate-500">بنود استكمال؛ تُطلب من الوصي عند الحاجة.</p>
                    </template>
                    <h2 class="card-title pt-2">المستندات</h2>
                    <ul class="space-y-1 text-sm"><li v-for="d in documents" :key="d.public_id"><a :href="`/orphans/registry/documents/${d.public_id}`" class="text-blue-700 hover:underline">{{ d.title || d.type || d.original_name }}</a></li>
                        <li v-if="!documents.length" class="text-slate-500">لا توجد مستندات مرفقة.</li></ul>
                </section>
            </div>

            <section v-if="snapshot" class="card space-y-3">
                <h2 class="card-title">بيانات طلب الأسرة كما قُدّمت (مُفادة، غير متحقق منها)</h2>
                <div v-if="snapshot.conflicts.length" class="alert-warning space-y-1" role="alert">
                    <p class="font-semibold">تعارضات هوية يجب حسمها قبل الاعتماد:</p>
                    <p v-for="(c, i) in snapshot.conflicts" :key="i">{{ c.label }}: {{ c.message }}</p>
                </div>
                <div v-if="snapshot.reconciliation.length" class="rounded-xl bg-gold-50 p-3 text-sm">
                    <p class="font-semibold text-gold-900">ملاحظات المطابقة</p>
                    <ul class="mt-1 list-disc space-y-1 ps-5"><li v-for="(n, i) in snapshot.reconciliation" :key="i">{{ n }}</li></ul>
                </div>
                <p v-if="!snapshot.canHealth" class="text-xs text-slate-500">البيانات الصحية مخفية لعدم امتلاكك قدرة «البيانات الصحية».</p>
                <RegistrationSummary :form="snapshot.form" :options="snapshot.options" :can-health="snapshot.canHealth" :child-status="childStatus" />
                <p class="text-xs text-slate-500">عند اعتماد أي طفل يُنشأ الأب والأم والأسرة والوصي مرة واحدة، ويُعاد استخدامهم للإخوة. والشخص المسجل سابقًا برقم هويته يُستخدم كما هو دون تغيير.</p>
            </section>

            <section class="card space-y-3">
                <h2 class="card-title">الأطفال المُدّعى رعايتهم — قرار مستقل لكل طفل</h2>
                <article v-for="c in children" :key="c.id" class="rounded-xl border border-slate-200 p-4 text-sm">
                    <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-navy-800">{{ c.full_name }}</span><span class="font-mono text-xs" dir="ltr">{{ c.id_masked }}</span>
                        <span class="text-slate-500">{{ c.date_of_birth || 'الميلاد غير متوفر' }} · {{ c.kinship || 'الصلة غير محددة' }}</span><StatusBadge :label="c.status_label" :tone="tones[c.status]" /></div>
                    <p v-if="c.guardian_name" class="mt-1">الوصي المذكور لهذا الطفل: <strong>{{ c.guardian_name }}</strong></p>
                    <p v-if="c.relationship_note" class="mt-1 text-slate-600">الأساس المُدّعى: {{ c.relationship_note }}</p>
                    <p v-if="c.orphan_reference" class="mt-1">ملف قائم بنفس الهوية: <Link :href="`/orphans/registry/orphans/${c.orphan_public_id}`" class="text-blue-700 hover:underline">{{ c.orphan_name }}</Link> <span class="font-mono text-xs" dir="ltr">{{ c.orphan_reference }}</span></p>
                    <p v-else class="mt-1 text-slate-600">لا يوجد ملف بنفس رقم الهوية؛ عند الاعتماد يُنشأ ملف جديد من بيانات الطلب.</p>
                    <p v-for="m in c.name_matches" :key="m.public_id" class="text-gold-800">تشابه في الاسم: {{ m.full_name }} ({{ m.roles.join('، ') || 'دون ملف' }}) — راجع قبل الإنشاء.</p>
                    <p v-if="c.other_guardians.length" class="text-gold-800">أوصياء آخرون مرتبطون: {{ c.other_guardians.map(o => o.full_name).join('، ') }}</p>
                    <p v-if="c.decision_note" class="text-slate-600">ملاحظة القرار: {{ c.decision_note }} · {{ c.decided_by_name }}</p>
                    <div v-if="!closed && abilities.portal && ['pending', 'needs_completion'].includes(c.status)" class="mt-2 flex gap-1">
                        <button type="button" class="btn btn-sm btn-primary" @click="openChild(c, 'approve')">اعتماد الوصاية</button><button type="button" class="btn btn-sm btn-danger" @click="openChild(c, 'reject')">رفض مع سبب</button></div>
                    <form v-if="decision === c.id" class="mt-3 grid gap-3 rounded-xl border border-blue-200 bg-blue-50/40 p-3 sm:grid-cols-2" @submit.prevent="submitChild(c)">
                        <template v-if="childForm.decision === 'approve'">
                            <label class="text-sm">بداية السريان<input v-model="childForm.starts_on" type="date" class="field mt-1" required></label>
                            <label class="text-sm">نهاية السريان<input v-model="childForm.ends_on" type="date" class="field mt-1"></label>
                            <label class="text-sm">نوع السند<select v-model="childForm.authority_kind_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="a in options.authority" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                            <label class="text-sm">الأساس القانوني<input v-model="childForm.legal_basis" class="field mt-1" maxlength="300"></label>
                            <label class="text-sm sm:col-span-2">الإثبات المعتمد<input v-model="childForm.evidence_note" class="field mt-1" maxlength="2000" placeholder="المستند الذي تحققت منه"></label>
                            <label v-if="snapshot?.conflicts?.length" class="flex items-start gap-2 text-sm text-gold-900 sm:col-span-2"><input v-model="childForm.resolve_conflicts" type="checkbox" class="mt-1"> راجعت تعارضات الهوية المذكورة، وأؤكد استخدام السجلات الموجودة كما هي.</label>
                        </template>
                        <label class="text-sm sm:col-span-2">{{ childForm.decision === 'reject' ? 'سبب الرفض (يظهر للوصي)' : 'ملاحظة' }}<textarea v-model="childForm.note" class="field mt-1" rows="2" maxlength="2000" :required="childForm.decision === 'reject'" /></label>
                        <p v-for="(e, k) in childForm.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
                        <div class="flex gap-2 sm:col-span-2"><button type="submit" class="btn btn-primary" :disabled="childForm.processing">تأكيد</button><button type="button" class="btn btn-secondary" @click="decision = null">إلغاء</button></div>
                    </form>
                </article>
            </section>

            <section v-if="!closed && abilities.portal" class="card space-y-3">
                <h2 class="card-title">طلب استكمال</h2>
                <form class="space-y-2" @submit.prevent="completion.post(`/orphans/reviews/${application.public_id}/completion`, { preserveScroll: true })">
                    <label class="field-label">ما المطلوب من الوصي؟<textarea v-model="completion.message" class="field mt-1 font-normal" rows="3" maxlength="2000" placeholder="رسالة واحدة واضحة بما يلزم استكماله" required /></label>
                    <div class="flex flex-wrap gap-3 text-sm"><label v-for="c in children.filter(x => x.status === 'pending')" :key="c.id" class="flex items-center gap-1"><input v-model="completion.children" type="checkbox" :value="c.id"> {{ c.full_name }}</label></div>
                    <p v-for="(e, k) in completion.errors" :key="k" class="field-error">{{ e }}</p>
                    <div class="flex gap-2"><button type="submit" class="btn btn-secondary" :disabled="completion.processing">إرسال طلب الاستكمال</button><button type="button" class="btn btn-danger" @click="rejectAll">رفض الطلب كاملًا</button></div>
                </form>
            </section>

            <section class="card"><h2 class="card-title">سجل القرارات</h2>
                <ul class="mt-2 space-y-1 text-sm text-slate-700"><li v-for="(e, i) in events" :key="i">{{ e.created_at?.slice(0, 16) }} · {{ e.actor || (e.actor_guardian_id ? 'الوصي' : 'النظام') }} · {{ actions[e.action] || e.action }}<span v-if="e.note"> — {{ e.note }}</span></li></ul></section>
        </div>
        <DecisionDialog :decision="confirmation" :busy="decisionBusy" :error="decisionError" @close="closeDecision" @confirm="confirmDecision" />
    </AppLayout>
</template>
