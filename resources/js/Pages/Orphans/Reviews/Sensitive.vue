<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import DecisionDialog from '../../../Components/DecisionDialog.vue';
import { useDecision } from '../../../Support/useDecision.js';
import { jsonRequest } from '../../../Support/orphans.js';

// Sensitive changes staff made to approved files (name, identity, birth date, life status, parents, orphanhood class).
// Nothing changes until the head approves; the author never decides their own request (the server checks both).
defineProps({ rows: Object, status: String, abilities: Object });
const { decision, busy, error, ask, close, confirm } = useDecision();
const tabs = { pending: 'بانتظار القرار', approved: 'المعتمدة', rejected: 'المرفوضة', all: 'الكل' };
const tones = { pending: 'warning', approved: 'success', rejected: 'danger' };
// Identity numbers arrive masked; the full values come only on explicit request (logged on the server).
const revealed = reactive({});
async function reveal(row) {
    try { revealed[row.public_id] = await jsonRequest(`/orphans/reviews/sensitive/${row.public_id}/reveal-id`, {}); } catch (e) { revealed[row.public_id] = { error: e.message }; }
}
const shown = (row, c, side) => (c.field === 'id_number' && revealed[row.public_id] && !revealed[row.public_id].error ? revealed[row.public_id][side] : c[side]) ?? '—';

function act(row, action) {
    ask({
        title: action === 'approve' ? 'اعتماد التعديل الحساس' : 'رفض التعديل الحساس',
        description: action === 'approve' ? 'يُطبَّق التعديل على السجل فور الاعتماد، ويُحفظ في سجل النظام بالقيمة القديمة والجديدة.' : 'يبقى السجل كما هو، ويُبلَّغ صاحب الطلب بالسبب.',
        required: action === 'reject', danger: action === 'reject', url: `/orphans/reviews/sensitive/${row.public_id}`, data: { decision: action },
    });
}
</script>

<template>
    <AppLayout title="التعديلات الحساسة">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="الطلبات والمراجعات" title="التعديلات الحساسة"
                description="تعديلات الموظفين على الملفات المعتمدة: الاسم والهوية وتاريخ الميلاد والحالة الحياتية والأبوان وتصنيف اليتم. لا تُطبَّق إلا باعتماد رئيس القسم، ولا يعتمد صاحب التعديل تعديله." />

            <nav class="flex flex-wrap gap-2 text-sm" aria-label="حالة الطلبات">
                <button v-for="(label, key) in tabs" :key="key" type="button" :aria-current="status === key ? 'page' : undefined"
                    :class="['rounded-full px-3 py-1', status === key ? 'bg-blue-100 text-blue-900' : 'bg-slate-100']"
                    @click="router.get('/orphans/reviews/sensitive', { status: key }, { preserveScroll: true })">{{ label }}</button>
                <Link href="/orphans/reviews" class="rounded-full px-3 py-1 underline">الطلبات والمراجعات</Link>
            </nav>

            <EmptyState v-if="!rows.data.length" title="لا توجد طلبات" description="لا توجد تعديلات حساسة بهذه الحالة." />

            <ul v-else class="space-y-3">
                <li v-for="row in rows.data" :key="row.public_id" class="card space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-bold text-navy-800">{{ row.kind_label }} <span class="font-mono text-xs text-slate-500" dir="ltr">{{ row.reference_no }}</span></p>
                            <p class="text-sm text-slate-600">
                                <Link v-if="row.orphan_public_id" :href="`/orphans/registry/orphans/${row.orphan_public_id}`" class="text-blue-700 hover:underline">{{ row.subject }}<template v-if="row.orphan_reference"> ({{ row.orphan_reference }})</template></Link>
                                <Link v-else-if="row.guardian_public_id" :href="`/orphans/registry/guardians/${row.guardian_public_id}`" class="text-blue-700 hover:underline">{{ row.subject }}</Link>
                                <span v-else>{{ row.subject }}</span>
                                · طلبه {{ row.requested_by }} · {{ row.created_at?.slice(0, 16) }}</p>
                        </div>
                        <StatusBadge :label="row.status_label" :tone="tones[row.status] || 'muted'" />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-slate-600"><th class="text-start">الحقل</th><th class="text-start">القيمة الحالية</th><th class="text-start">القيمة المطلوبة</th></tr></thead>
                            <tbody><tr v-for="c in row.changes" :key="c.field" class="border-t border-slate-100">
                                <td class="py-1">{{ c.label }}</td><td class="text-slate-600" :dir="c.field === 'id_number' ? 'ltr' : undefined">{{ shown(row, c, 'before') }}</td>
                                <td class="font-semibold" :dir="c.field === 'id_number' ? 'ltr' : undefined">{{ shown(row, c, 'after') }}</td></tr></tbody>
                        </table>
                    </div>
                    <div v-if="row.can_reveal && !(revealed[row.public_id] && !revealed[row.public_id].error)" class="flex flex-wrap items-center gap-2 text-xs">
                        <button type="button" class="btn btn-sm btn-secondary" @click="reveal(row)">إظهار رقم الهوية كاملًا</button>
                        <span class="text-slate-500">يُسجَّل كل إظهار في سجل الوصول.</span>
                        <span v-if="revealed[row.public_id]?.error" class="field-error">{{ revealed[row.public_id].error }}</span>
                    </div>

                    <p v-if="row.decided_by" class="text-xs text-slate-500">القرار: {{ row.decided_by }} · {{ row.decided_at?.slice(0, 16) }}<template v-if="row.decision_note"> · {{ row.decision_note }}</template></p>

                    <div v-if="row.can_decide" class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-primary" @click="act(row, 'approve')">اعتماد وتطبيق</button>
                        <button type="button" class="btn btn-sm btn-danger" @click="act(row, 'reject')">رفض</button>
                    </div>
                    <p v-else-if="row.status === 'pending'" class="text-xs text-slate-500">بانتظار قرار رئيس القسم أو القائم بأعماله.</p>
                </li>
            </ul>

            <Pagination :page="rows" />
            <DecisionDialog :decision="decision" :busy="busy" :error="error" @close="close" @confirm="confirm" />
        </div>
    </AppLayout>
</template>
