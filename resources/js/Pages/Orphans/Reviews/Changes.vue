<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import DecisionDialog from '../../../Components/DecisionDialog.vue';
import { useDecision } from '../../../Support/useDecision.js';

// Change requests the guardians send from the portal. Phone, address and school apply on approval; the other groups
// are applied by staff from the orphan's or household's file after approval.
const props = defineProps({ rows: Object, status: String, abilities: Object });
const { decision, busy, error, ask, close, confirm } = useDecision();
const tabs = { open: 'المفتوحة', approved: 'المعتمدة', rejected: 'المرفوضة', all: 'الكل' };
const tones = { submitted: 'info', under_review: 'warning', needs_info: 'warning', approved: 'success', rejected: 'danger' };
const open = s => ['submitted', 'under_review', 'needs_info'].includes(s);
const show = v => (v === null || v === undefined || v === '') ? '—' : (typeof v === 'object' ? Object.values(v).filter(Boolean).join('، ') : String(v));

function act(row, action) {
    ask({
        title: { review: 'استلام الطلب للمراجعة', needs_info: 'طلب استكمال من الوصي', approve: 'اعتماد طلب التعديل', reject: 'رفض طلب التعديل' }[action],
        description: action === 'approve'
            ? (row.auto ? 'يُطبَّق التعديل على السجل فور الاعتماد، ويُسجَّل في سجل النظام.' : 'الاعتماد لا يغيّر السجل تلقائيًا؛ طبّق التعديل من ملف اليتيم أو الأسرة.')
            : 'يصل الرد إلى الوصي في البوابة.',
        required: ['reject', 'needs_info'].includes(action), danger: action === 'reject', field: 'public_note',
        url: `/orphans/reviews/changes/${row.public_id}`, data: { action },
    });
}
</script>

<template>
    <AppLayout title="طلبات تعديل البيانات">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="الطلبات والمراجعات" title="طلبات تعديل البيانات"
                description="ما يطلب الأوصياء تعديله من البوابة. يُعرض المقترح بجانب القيمة الحالية، والقرار يُبلَّغ للوصي ويُحفظ في سجل النظام." />

            <nav class="flex flex-wrap gap-2 text-sm" aria-label="حالة الطلبات">
                <button v-for="(label, key) in tabs" :key="key" type="button" :aria-current="status === key ? 'page' : undefined"
                    :class="['rounded-full px-3 py-1', status === key ? 'bg-blue-100 text-blue-900' : 'bg-slate-100']"
                    @click="router.get('/orphans/reviews/changes', { status: key }, { preserveScroll: true })">{{ label }}</button>
                <Link href="/orphans/reviews" class="rounded-full px-3 py-1 underline">طلبات الانضمام والاعتراضات</Link>
            </nav>

            <EmptyState v-if="!rows.data.length" title="لا توجد طلبات" description="لا توجد طلبات تعديل بهذه الحالة." />

            <ul v-else class="space-y-3">
                <li v-for="row in rows.data" :key="row.public_id" class="card space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-bold text-navy-800">{{ row.group_label }} <span class="font-mono text-xs text-slate-500" dir="ltr">{{ row.reference_no }}</span></p>
                            <p class="text-sm text-slate-600">من حساب {{ row.account }}<template v-if="row.child"> · الطفل:
                                <Link v-if="row.orphan_public_id" :href="`/orphans/registry/orphans/${row.orphan_public_id}`" class="text-blue-700 hover:underline">{{ row.child }} ({{ row.orphan_reference }})</Link></template>
                                · {{ row.created_at?.slice(0, 10) }}</p>
                        </div>
                        <StatusBadge :label="row.status_label" :tone="tones[row.status] || 'muted'" />
                    </div>

                    <p v-if="row.reason" class="text-sm"><span class="text-slate-600">سبب الوصي:</span> {{ row.reason }}</p>

                    <p v-if="row.restricted" class="alert-warning text-sm">طلب صحي: تفاصيله لمن يملك صلاحية المعلومات الصحية فقط.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-slate-600"><th class="text-start">الحقل</th><th class="text-start">الحالي</th><th class="text-start">المقترح</th></tr></thead>
                            <tbody><tr v-for="(value, key) in row.proposed" :key="key" class="border-t border-slate-100">
                                <td class="py-1">{{ key }}</td><td class="text-slate-600">{{ show(row.current?.[key]) }}</td><td class="font-semibold">{{ show(value) }}</td></tr></tbody>
                        </table>
                    </div>

                    <p v-if="row.documents.length" class="text-sm">المرفقات: <span v-for="d in row.documents" :key="d.public_id" class="me-2">{{ d.original_name }}</span></p>
                    <p v-if="row.public_note" class="text-sm"><span class="text-slate-600">الرد على الوصي:</span> {{ row.public_note }}</p>
                    <p v-if="row.decided_by" class="text-xs text-slate-500">القرار: {{ row.decided_by }} · {{ row.decided_at?.slice(0, 16) }}<template v-if="row.applied_at"> · طُبق {{ row.applied_at.slice(0, 16) }}</template></p>

                    <div v-if="abilities?.edit && open(row.status)" class="flex flex-wrap gap-2">
                        <button v-if="row.status === 'submitted'" type="button" class="btn btn-sm btn-secondary" @click="act(row, 'review')">استلام للمراجعة</button>
                        <button type="button" class="btn btn-sm btn-primary" @click="act(row, 'approve')">اعتماد</button>
                        <button type="button" class="btn btn-sm btn-secondary" @click="act(row, 'needs_info')">طلب استكمال</button>
                        <button type="button" class="btn btn-sm btn-danger" @click="act(row, 'reject')">رفض</button>
                    </div>
                </li>
            </ul>

            <Pagination :page="rows" />
            <DecisionDialog :decision="decision" :busy="busy" :error="error" @close="close" @confirm="confirm" />
        </div>
    </AppLayout>
</template>
