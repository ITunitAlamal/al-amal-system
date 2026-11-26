<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import AppIcon from '../../../Components/AppIcon.vue';
import ImportIcon from '../../../Components/Registry/ImportIcon.vue';

const props = defineProps({ rows: Object, statuses: Object, filters: Object, abilities: Object, importUrl: { type: String, default: null } });
const tones = { draft: 'muted', list_draft: 'warning', pending_executive: 'info', approved: 'success', distributing: 'success', reconciling: 'info', closed: 'muted', cancelled: 'danger' };
const units = { orphan: 'لكل يتيم', household: 'لكل أسرة', guardian: 'لكل وصي' };
</script>

<template>
    <AppLayout title="الحملات الإغاثية">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="الحملات الإغاثية" description="الاستهداف والترشيح المفسَّر والكشوف. اعتماد الكشف الأول وأي تعديل عليه حصري للمدير التنفيذي، والاعتماد لا يعني التسليم.">
                <Link v-if="abilities.campaigns" href="/orphans/campaigns/create" class="btn btn-primary"><AppIcon name="plus" /> حملة جديدة</Link>
                <Link v-if="importUrl && abilities.import" :href="importUrl" class="btn btn-secondary" title="استيراد كشوف مرشحين أو حصص مناطق أو كميات المقاسات كمسودة للمراجعة"><ImportIcon /> الاستيراد الذكي</Link>
            </PageHeader>
            <section class="card p-0">
                <div class="flex flex-wrap gap-3 border-b border-slate-200 p-4"><label class="text-sm font-semibold">الحالة<select :value="filters.status || ''" class="field mt-1 font-normal" @change="router.get('/orphans/campaigns', $event.target.value ? { status: $event.target.value } : {})">
                    <option value="">الكل</option><option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option></select></label></div>
                <div class="overflow-x-auto p-4">
                    <table v-if="rows.data.length" class="data-table min-w-[760px]">
                        <thead><tr><th scope="col">الحملة</th><th scope="col">النوع</th><th scope="col">الوحدة</th><th scope="col">الفترة</th><th scope="col">المستفيدون المعتمدون</th><th scope="col">الحالة</th></tr></thead>
                        <tbody><tr v-for="c in rows.data" :key="c.public_id">
                            <td><Link :href="`/orphans/campaigns/${c.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ c.title }}</Link><span class="block font-mono text-xs text-slate-500" dir="ltr">{{ c.reference_no }}</span></td>
                            <td>{{ c.aid_type || '—' }}<span v-if="c.donor_name" class="block text-xs text-slate-500">{{ c.donor_name }}</span></td><td>{{ units[c.beneficiary_unit] }}</td>
                            <td class="text-sm">{{ c.starts_on || '—' }} ← {{ c.ends_on || '—' }}</td><td class="tabular-nums">{{ c.approved_entries ?? '—' }}</td>
                            <td><StatusBadge :label="statuses[c.status]" :tone="tones[c.status]" /><StatusBadge v-if="c.pending_changes" class="ms-1" label="تعديل بانتظار موافقة المدير التنفيذي" tone="warning" /></td></tr></tbody>
                    </table>
                    <EmptyState v-else title="لا توجد حملات" description="ابدأ بإنشاء حملة في أربع خطوات: الأساسيات، الاستهداف، معايير الترشيح، المراجعة والمحاكاة." />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
