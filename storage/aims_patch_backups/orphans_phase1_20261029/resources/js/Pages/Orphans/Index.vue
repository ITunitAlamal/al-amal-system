<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';
import GeographicAddress from '../../Components/GeographicAddress.vue';
import RuleFields from '../../Components/Orphans/RuleFields.vue';
import { emptyAddress, householdFields } from '../../Support/orphanFields';

// Family files and the department's work queues (applications, change requests, visits).
const props = defineProps({
    households: { type: Object, required: true },
    queues: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    householdRules: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
});
const tab = ref(props.filters.tab || 'households');
const tabs = computed(() => [
    { key: 'households', label: 'ملفات الأسر' },
    { key: 'applications', label: `طلبات الاعتماد (${props.queues.applications?.length || 0})` },
    { key: 'changes', label: `طلبات التعديل (${props.queues.changes?.length || 0})` },
    { key: 'visits', label: `الزيارات (${props.queues.visits?.length || 0})` },
]);
const search = ref(props.filters.q || '');
const status = ref(props.filters.status || '');
function filter() { router.get('/orphans', { q: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }

const creating = ref(false);
const form = useForm({ displaced: false, original_address: emptyAddress(), current_address: emptyAddress() });
const fields = computed({ get: () => form.data(), set: value => Object.keys(value).forEach(key => { form[key] = value[key]; }) });
function patch(kind, value) { form[kind] = { ...form[kind], ...value }; }
function create() { form.post('/orphans/households', { preserveScroll: true }); }
const tone = { draft: 'warning', registered: 'info', active: 'success', inactive: 'muted', closed: 'muted' };
</script>

<template>
    <AppLayout title="ملفات الأيتام والأسر">
        <div class="space-y-4">
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="ملفات الأيتام والأسر" description="الملف لا يُفعّل ولا يُرقّم إلا بقرار المدير التنفيذي بعد توصية رئيس القسم.">
                <Link href="/orphans/scoring" class="btn btn-secondary btn-sm">قواعد التقييم الاجتماعي</Link>
                <Link v-if="can.sponsorships || can.executive" href="/orphans/sponsorships" class="btn btn-secondary btn-sm">الكفالات والتبرعات</Link>
                <button v-if="can.records" type="button" class="btn btn-primary btn-sm" @click="creating = !creating">ملف أسرة جديد</button>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-4">
                <div class="card"><p class="text-xs text-slate-500">أطفال بملفات نشطة</p><p class="text-2xl font-bold">{{ counts.active_children }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">أطفال قيد الدراسة</p><p class="text-2xl font-bold">{{ counts.pending_children }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">ملفات الأسر</p><p class="text-2xl font-bold">{{ counts.households }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">طلبات اعتماد مفتوحة</p><p class="text-2xl font-bold">{{ counts.open_applications }}</p></div>
            </div>

            <form v-if="creating" class="card space-y-4" data-error-scope @submit.prevent="create">
                <h2 class="card-title">ملف أسرة جديد</h2>
                <FormErrorSummary :errors="form.errors" />
                <RuleFields v-model="fields" :fields="householdFields" :rules="householdRules" :options="options" :errors="form.errors" />
                <div class="grid gap-4 lg:grid-cols-2">
                    <fieldset class="space-y-2"><legend class="font-semibold">العنوان الأصلي *</legend>
                        <GeographicAddress :model-value="form.original_address" prefix="original_address." :errors="form.errors" @update:model-value="patch('original_address', $event)" /></fieldset>
                    <fieldset class="space-y-2"><legend class="font-semibold">عنوان السكن/النزوح الحالي *</legend>
                        <GeographicAddress :model-value="form.current_address" prefix="current_address." :errors="form.errors" @update:model-value="patch('current_address', $event)" /></fieldset>
                </div>
                <button class="btn btn-primary" :disabled="form.processing">إنشاء الملف</button>
            </form>

            <div class="flex flex-wrap gap-2" role="tablist">
                <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" class="btn btn-sm" :class="tab === t.key ? 'btn-primary' : 'btn-secondary'" @click="tab = t.key">{{ t.label }}</button>
            </div>

            <section v-if="tab === 'households'" class="card space-y-3">
                <form class="flex flex-wrap gap-2" @submit.prevent="filter">
                    <input v-model="search" class="field max-w-xs" maxlength="40" placeholder="رقم ملف الأسرة أو الطفل أو الوصي أو رقم الهوية">
                    <select v-model="status" class="field max-w-[12rem]"><option value="">كل الحالات</option><option value="draft">مسودة</option><option value="registered">قيد الدراسة</option><option value="active">نشطة</option><option value="closed">مغلقة</option></select>
                    <button class="btn btn-secondary btn-sm">بحث</button>
                </form>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الملف</th><th>العائلة</th><th>الوصي</th><th>الأطفال</th><th>الحالة</th></tr></thead>
                        <tbody>
                            <tr v-for="h in households.data" :key="h.public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="`/orphans/households/${h.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ h.reference_no }}</Link></td>
                                <td>{{ h.family_name }}<span v-if="h.displaced" class="ms-1 text-xs text-amber-700">(نازحة)</span></td>
                                <td>{{ h.guardian || '—' }}</td>
                                <td>{{ h.active_children }} نشط / {{ h.children }}</td>
                                <td><StatusBadge :label="h.status_label" :tone="tone[h.status]" /></td>
                            </tr>
                            <tr v-if="!households.data.length"><td colspan="5" class="py-4 text-center text-slate-500">لا توجد ملفات مطابقة.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex gap-2"><Link v-for="link in households.links" :key="link.label" :href="link.url || ''" class="btn btn-secondary btn-sm" :class="{ 'opacity-50 pointer-events-none': !link.url, 'btn-primary': link.active }" v-html="link.label" /></div>
            </section>

            <section v-else-if="tab === 'applications'" class="card">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="a in queues.applications" :key="a.id" class="flex items-center justify-between py-2">
                        <Link :href="`/orphans/applications/${a.id}`" class="font-semibold text-blue-700 hover:underline">{{ a.reference_no }} — ملف {{ a.household }}</Link>
                        <StatusBadge :label="a.status_label" tone="info" />
                    </li>
                    <li v-if="!queues.applications?.length" class="py-3 text-slate-500">لا توجد طلبات مفتوحة.</li>
                </ul>
            </section>
            <section v-else-if="tab === 'changes'" class="card">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="c in queues.changes" :key="c.id" class="flex items-center justify-between py-2">
                        <Link :href="`/orphans/changes/${c.id}`" class="font-semibold text-blue-700 hover:underline">{{ c.reference_no }} — {{ c.category_label }}</Link>
                        <StatusBadge :label="c.status_label" tone="info" />
                    </li>
                    <li v-if="!queues.changes?.length" class="py-3 text-slate-500">لا توجد طلبات تعديل مفتوحة.</li>
                </ul>
            </section>
            <section v-else class="card">
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="v in queues.visits" :key="v.id" class="flex items-center justify-between py-2">
                        <Link :href="`/orphans/visits/${v.id}`" class="font-semibold text-blue-700 hover:underline">{{ v.reference_no }} — ملف {{ v.household }} — {{ v.scheduled_on }} ({{ v.researcher }})</Link>
                        <StatusBadge :label="v.status_label" tone="info" />
                    </li>
                    <li v-if="!queues.visits?.length" class="py-3 text-slate-500">لا توجد زيارات مفتوحة.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
