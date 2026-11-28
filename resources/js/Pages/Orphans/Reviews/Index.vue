<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import DecisionDialog from '../../../Components/DecisionDialog.vue';
import { useDecision } from '../../../Support/useDecision.js';

const props = defineProps({ tab: String, filters: Object, applications: Object, appeals: Object, statuses: Object, counts: Object, abilities: Object, staff: Array });
const q = ref(props.filters.q || '');
const busy = ref(false);
const { decision, busy: decisionBusy, error: decisionError, ask, close, confirm } = useDecision();
function go(extra = {}) {
    if (busy.value) return;
    busy.value = true;
    router.get('/orphans/reviews', Object.fromEntries(Object.entries({ tab: props.tab, ...props.filters, q: q.value, ...extra, page: 1 }).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, onFinish: () => { busy.value = false; } });
}
const appeal = useForm({ kind: 'campaign_omission', subject: 'orphan', subject_public_id: '', campaign_id: null, reason: '', evidence_note: '' });
const filing = ref(false);
const kinds = { eligibility: 'الأهلية', campaign_omission: 'عدم الإدراج في حملة', other: 'أخرى' };
const appealStatus = { open: ['مفتوح', 'info'], under_review: ['قيد المراجعة', 'warning'], accepted: ['مقبول', 'success'], rejected: ['مرفوض', 'danger'] };
// Who may decide each appeal comes from the server (a.can_decide / a.conflict / a.can_escalate): the head, or the
// person the head assigned it to (the executive director after escalation); never the preparer of the contested list.
function decide(a, action) {
    ask({ title: { accept: 'قبول الاعتراض', reject: 'رفض الاعتراض', review: 'بدء مراجعة الاعتراض', escalate: 'تصعيد الاعتراض إلى المدير التنفيذي' }[action],
        description: action === 'escalate' ? 'يُسند الاعتراض إلى المدير التنفيذي ليفصل فيه؛ اكتب سبب التصعيد.' : 'قرار الاعتراض لا يغيّر الكشف المعتمد للحملة؛ تعديل الكشف يحتاج طلبًا مستقلًا واعتماد المدير التنفيذي.',
        required: action !== 'review', danger: action === 'reject', url: `/orphans/reviews/appeals/${a.public_id}`, data: { action } });
}
function assignAppeal(a, id) { router.post(`/orphans/reviews/appeals/${a.public_id}`, { action: 'assign', user_id: id ? Number(id) : null }, { preserveScroll: true }); }
</script>

<template>
    <AppLayout title="الطلبات والمراجعات">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="الطلبات والمراجعات" description="طلبات بوابة الأوصياء في طابور واحد قابل للإسناد، والاعتراضات. اعتماد الحساب منفصل عن اعتماد الوصاية لكل طفل." />
            <div class="flex flex-wrap gap-4">
                <Link href="/orphans/reviews/changes" class="inline-block text-sm font-semibold text-blue-700 hover:underline">طلبات تعديل البيانات من البوابة ←</Link>
                <Link href="/orphans/reviews/sensitive" class="inline-block text-sm font-semibold text-blue-700 hover:underline">التعديلات الحساسة على الملفات المعتمدة<template v-if="counts.sensitive"> ({{ counts.sensitive }})</template> ←</Link>
                <Link v-if="abilities.decide" href="/orphans/reviews/parent-links" class="inline-block text-sm font-semibold text-blue-700 hover:underline">روابط الأبوين المشكوك فيها ←</Link>
            </div>
            <div class="grid gap-3 sm:grid-cols-4">
                <button type="button" class="card text-start" @click="go({ tab: 'applications', status: '', assigned: '' })"><p class="text-sm text-slate-600">طلبات مفتوحة</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ counts.open }}</p></button>
                <button type="button" class="card text-start" @click="go({ tab: 'applications', assigned: 'me' })"><p class="text-sm text-slate-600">مسندة إليّ</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ counts.mine }}</p></button>
                <button type="button" class="card text-start" @click="go({ tab: 'applications', assigned: 'none' })"><p class="text-sm text-slate-600">غير مسندة</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ counts.unassigned }}</p></button>
                <button type="button" class="card text-start" @click="go({ tab: 'appeals', status: '' })"><p class="text-sm text-slate-600">اعتراضات مفتوحة</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ counts.appeals }}</p></button>
            </div>
            <nav class="flex gap-2" aria-label="طوابير المراجعة"><button type="button" :class="['btn', tab === 'applications' ? 'btn-primary' : 'btn-secondary']" :aria-pressed="tab === 'applications'" @click="go({ tab: 'applications', status: '', assigned: '' })">طلبات البوابة</button>
                <button type="button" :class="['btn', tab === 'appeals' ? 'btn-primary' : 'btn-secondary']" :aria-pressed="tab === 'appeals'" @click="go({ tab: 'appeals', status: '', assigned: '' })">الاعتراضات</button></nav>

            <section class="card p-0" :aria-busy="busy">
                <form class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4" @submit.prevent="go()">
                    <label v-if="tab === 'applications'" class="min-w-0 flex-1 text-sm font-semibold">بحث<input v-model="q" type="search" class="field mt-1 font-normal" placeholder="رقم الطلب APP-… أو اسم مقدم الطلب أو رقم هويته كاملًا"></label>
                    <label class="text-sm font-semibold">الحالة<select :value="filters.status || 'open'" class="field mt-1 font-normal" @change="go({ status: $event.target.value })">
                        <option value="open">المفتوحة</option><option value="all">الكل</option>
                        <template v-if="tab === 'applications'"><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option></template>
                        <template v-else><option v-for="(s, key) in appealStatus" :key="key" :value="key">{{ s[0] }}</option></template></select></label>
                    <label class="text-sm font-semibold">الإسناد<select :value="filters.assigned || ''" class="field mt-1 font-normal" @change="go({ assigned: $event.target.value })"><option value="">الكل</option><option value="me">مسندة إليّ</option><option v-if="tab === 'applications'" value="none">غير مسندة</option></select></label>
                    <button v-if="tab === 'applications'" type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'جارٍ البحث…' : 'بحث' }}</button>
                    <button v-if="tab === 'appeals' && abilities.edit" type="button" class="btn btn-primary" @click="filing = !filing">تسجيل اعتراض</button>
                </form>

                <template v-if="tab === 'applications'">
                    <div class="overflow-x-auto p-4">
                        <table v-if="applications.data.length" class="aims-responsive-table data-table min-w-[720px]">
                            <thead><tr><th scope="col">الطلب</th><th scope="col">مقدم الطلب</th><th scope="col">الأطفال</th><th scope="col">الحالة</th><th scope="col">المسؤول</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
                            <tbody><tr v-for="a in applications.data" :key="a.public_id">
                                <td data-label="الطلب"><span class="font-mono" dir="ltr">{{ a.reference_no }}</span><span class="block text-xs text-slate-500">{{ a.submitted_at?.slice(0, 10) }}</span></td>
                                <td data-label="مقدم الطلب">{{ a.full_name }} <span class="font-mono text-xs text-slate-500" dir="ltr">{{ a.id_masked }}</span></td>
                                <td data-label="الأطفال">{{ a.children_count }} <span class="text-xs text-slate-500">(معتمد {{ a.approved_count }})</span></td>
                                <td data-label="الحالة"><StatusBadge :label="a.status_label" :tone="a.status === 'needs_completion' ? 'warning' : (a.status === 'resubmitted' ? 'info' : 'muted')" /><StatusBadge v-if="a.overdue" class="ms-1" label="متأخر" tone="danger" /></td>
                                <td data-label="المسؤول">{{ a.assignee || 'غير مسند' }}<span v-if="a.due_on" class="block text-xs text-slate-500">الاستحقاق {{ a.due_on }}</span></td>
                                <td data-label="فتح"><Link :href="`/orphans/reviews/${a.public_id}`" class="btn btn-sm btn-primary">مراجعة</Link></td>
                            </tr></tbody>
                        </table>
                        <EmptyState v-else title="لا توجد طلبات مطابقة" description="تصل الطلبات من بوابة الأوصياء بعد إنشاء الوصي حسابه وتقديم طلبه." />
                    </div>
                    <Pagination :page="applications" :busy="busy" />
                </template>

                <template v-else>
                    <form v-if="filing" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2" @submit.prevent="appeal.post('/orphans/reviews/appeals', { preserveScroll: true, onSuccess: () => { filing = false; appeal.reset(); } })">
                        <label class="text-sm">نوع الاعتراض<select v-model="appeal.kind" class="field mt-1"><option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="text-sm">صاحب الاعتراض<select v-model="appeal.subject" class="field mt-1"><option value="orphan">يتيم</option><option value="guardian">وصي</option></select></label>
                        <label class="text-sm">المعرّف العام لصاحب الملف<input v-model="appeal.subject_public_id" class="field mt-1 font-mono" dir="ltr" placeholder="يُنسخ من رابط الملف" required></label>
                        <label class="text-sm">رقم الحملة (اختياري)<input v-model.number="appeal.campaign_id" type="number" class="field mt-1"></label>
                        <label class="text-sm sm:col-span-2">السبب<textarea v-model="appeal.reason" class="field mt-1" rows="2" maxlength="3000" required /></label>
                        <label class="text-sm sm:col-span-2">الأدلة<textarea v-model="appeal.evidence_note" class="field mt-1" rows="2" maxlength="2000" /></label>
                        <p v-for="(e, k) in appeal.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
                        <div class="sm:col-span-2"><button type="submit" class="btn btn-primary" :disabled="appeal.processing">تسجيل</button></div>
                    </form>
                    <ul class="divide-y divide-slate-100 p-4">
                        <li v-for="a in appeals.data" :key="a.public_id" class="space-y-1 py-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2"><span class="font-mono" dir="ltr">{{ a.reference_no }}</span><StatusBadge :label="appealStatus[a.status][0]" :tone="appealStatus[a.status][1]" />
                                <span class="font-semibold">{{ kinds[a.kind] }}</span> · <Link :href="a.orphan_public_id ? `/orphans/registry/orphans/${a.orphan_public_id}` : `/orphans/registry/guardians/${a.guardian_public_id}`" class="text-blue-700 hover:underline">{{ a.subject_name }}</Link>
                                <span v-if="a.campaign_id" class="text-slate-500">· حملة #{{ a.campaign_id }}</span><span class="text-slate-500">· {{ a.assignee || 'غير مسند' }}</span></div>
                            <p class="text-slate-700">{{ a.reason }}</p><p v-if="a.decision_note" class="text-slate-600">القرار: {{ a.decision_note }}</p>
                            <p v-if="a.conflict" class="text-xs text-gold-800">{{ a.conflict }}</p>
                            <p v-if="a.escalated" class="text-xs text-slate-500">صُعّد إلى المدير التنفيذي.</p>
                            <div v-if="['open', 'under_review'].includes(a.status)" class="flex flex-wrap items-center gap-1">
                                <button v-if="a.status === 'open' && abilities.edit" type="button" class="btn btn-sm btn-secondary" @click="decide(a, 'review')">بدء المراجعة</button>
                                <template v-if="a.can_decide"><button type="button" class="btn btn-sm btn-primary" @click="decide(a, 'accept')">قبول</button><button type="button" class="btn btn-sm btn-danger" @click="decide(a, 'reject')">رفض</button></template>
                                <button v-if="a.can_escalate && !a.escalated" type="button" class="btn btn-sm btn-secondary" @click="decide(a, 'escalate')">تصعيد للمدير التنفيذي</button>
                                <label v-if="abilities.assign && !a.escalated" class="text-xs">إسناد إلى<select :value="a.assigned_to ?? ''" class="field ms-1 inline-block w-auto py-1 text-xs" @change="assignAppeal(a, $event.target.value)">
                                    <option value="">غير مسند</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label></div>
                        </li>
                        <li v-if="!appeals.data.length"><EmptyState title="لا توجد اعتراضات" description="قبول الاعتراض لا يعدّل أي كشف حملة معتمد؛ التعديل يمر بطلب تعديل يعتمده المدير التنفيذي." /></li>
                    </ul>
                    <Pagination :page="appeals" :busy="busy" />
                </template>
            </section>
        </div>
        <DecisionDialog :decision="decision" :busy="decisionBusy" :error="decisionError" @close="close" @confirm="confirm" />
    </AppLayout>
</template>
