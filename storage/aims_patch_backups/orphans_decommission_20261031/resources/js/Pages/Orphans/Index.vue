<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import ListToolbar from '../../Components/Orphans/ListToolbar.vue';
import RecordPreview from '../../Components/RecordPreview.vue';
import ActionDisclosure from '../../Components/Orphans/ActionDisclosure.vue';
import { householdResultsUrl, registryResultsUrl, recordWithResults } from '../../Support/orphanNavigation.js';
import { buildWorkItems, workItemUrl } from '../../Support/orphanWorkItems.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';
import GeographicAddress from '../../Components/GeographicAddress.vue';
import RuleFields from '../../Components/Orphans/RuleFields.vue';
import DepartmentInsights from '../../Components/Orphans/DepartmentInsights.vue';
import { emptyAddress, householdFields } from '../../Support/orphanFields';

// «لوحة القسم»: one search, 3–5 real numbers, the head of the single «يحتاج إلى إجراء» queue, and the approved files.
const props = defineProps({
    households: { type: Object, required: true },
    queues: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    householdRules: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    staged: { type: Object, default: null },
    insights: { type: Object, default: null },
});
const finder = ref('');
const finderView = ref('children');
function findRecord() { router.visit(`/orphans/records?view=${finderView.value}&q=${encodeURIComponent(finder.value.trim())}`); }
const kpis = computed(() => [
    { label: 'أطفال بملفات نشطة', value: props.counts.active_children, note: 'السجل المعتمد', href: '#approved-files' },
    { label: 'سجلات الكشف بانتظار التدقيق', value: props.staged?.pending_review, note: 'سجلات أولية، وليست ملفات معتمدة', href: '/orphans/records?review_status=pending_review' },
    { label: 'ملاحظات عالية الأولوية مفتوحة', value: props.staged?.high_open, note: 'سجلات الكشف', href: '/orphans/staging?queue=high' },
    { label: 'المكلّفة لي', value: props.staged?.mine, note: 'ملاحظات مفتوحة على سجلات الكشف', href: '/orphans/staging?queue=assigned' },
    { label: 'طلبات اعتماد مفتوحة', value: props.counts.open_applications, note: 'السجل المعتمد', href: '/orphans/staging?queue=applications' },
]);
// «يحتاج إلى إجراء»: the same single queue as «المراجعة والمتابعة»; the home shows its first items only.
const workItems = computed(() => buildWorkItems(props.staged, props.queues, { plans: props.can.plans }));
const page = usePage();
const resultsUrl = computed(() => registryResultsUrl(page.url));
const preview = ref(null);
const previewFields = computed(() => preview.value ? [
    { label: 'الملف', value: preview.value.reference_no },
    { label: 'الحالة', value: preview.value.status_label },
    { label: 'الوصي المسجل (لا يثبت الوصاية القانونية)', value: preview.value.guardian },
    { label: 'الأطفال', value: preview.value.children },
] : []);
const search = ref(props.filters.q || '');
const status = ref(props.filters.status || '');
const statusLabels = { draft: 'مسودة', registered: 'قيد الدراسة', active: 'نشطة', inactive: 'غير نشطة', closed: 'مغلقة' };
const activeFilters = computed(() => [
    ...(props.filters.q ? [{ key: 'q', label: `البحث: ${props.filters.q}` }] : []),
    ...(props.filters.status ? [{ key: 'status', label: `الحالة: ${statusLabels[props.filters.status]}` }] : []),
]);
function removeFilter(key) {
    router.get('/orphans', { q: key === 'q' ? undefined : props.filters.q, status: key === 'status' ? undefined : props.filters.status }, { preserveState: true });
}
function clearFilters() { router.get('/orphans', {}, { preserveState: true }); }
watch(() => props.filters, filters => { search.value = filters.q || ''; status.value = filters.status || ''; });
function filter() { router.get('/orphans', { q: search.value || undefined, status: status.value || undefined }, { preserveState: true }); }

const creating = ref(false);
const form = useForm({ displaced: false, original_address: emptyAddress(), current_address: emptyAddress() });
const fields = computed({ get: () => form.data(), set: value => Object.keys(value).forEach(key => { form[key] = value[key]; }) });
function patch(kind, value) { form[kind] = { ...form[kind], ...value }; }
function create() { form.post('/orphans/households', { preserveScroll: true }); }
const tone = { draft: 'warning', registered: 'info', active: 'success', inactive: 'muted', closed: 'muted' };
</script>

<template>
    <AppLayout title="لوحة القسم">
        <div class="space-y-4">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="لوحة القسم" description="الملف لا يُفعّل ولا يُرقّم إلا بقرار المدير التنفيذي بعد توصية رئيس القسم.">
                <button v-if="can.records" type="button" class="btn btn-primary btn-sm" :aria-expanded="creating" @click="creating = !creating">ملف أسرة جديد</button>
                <ActionDisclosure>
                        <Link href="/admin/orphans/delegations" class="text-sm text-blue-700 hover:underline">صلاحيات القسم (التفويضات)</Link>
                        <Link href="/orphans/scoring" class="text-sm text-blue-700 hover:underline">قواعد التقييم الاجتماعي</Link>
                        <Link v-if="can.plans" href="/orphans/visit-plans" class="text-sm text-blue-700 hover:underline">خطط الزيارات</Link>
                </ActionDisclosure>
            </PageHeader>

            <!-- Department home: one search, a few real numbers, one work list. -->
            <form class="card flex flex-wrap items-end gap-2" role="search" @submit.prevent="findRecord">
                <label class="w-full min-w-0 sm:w-auto sm:flex-1"><span class="field-label">البحث في السجلات</span>
                    <input v-model="finder" type="search" maxlength="100" class="field" placeholder="اسم اليتيم أو الوصي كاملًا أو جزءًا منه، أو رقم الهوية، أو #رقم الصف في الكشف" /></label>
                <label><span class="field-label">في</span><select v-model="finderView" class="field"><option value="children">الأيتام</option><option value="guardians">الأوصياء</option></select></label>
                <button class="btn btn-primary btn-sm" :disabled="!finder.trim()">بحث</button>
            </form>
            <dl class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5" aria-label="أرقام القسم">
                <div v-for="k in kpis" :key="k.label" class="card">
                    <dt class="text-xs text-slate-500">{{ k.label }}</dt>
                    <dd class="mt-1 text-2xl font-bold tabular-nums text-navy-800">{{ k.value ?? 'غير متوفر' }}</dd>
                    <dd class="text-xs text-slate-500">{{ k.note }}</dd>
                    <dd><a v-if="k.href.startsWith('#')" :href="k.href" class="mt-1 inline-block text-xs font-semibold text-blue-700 hover:underline">عرض</a>
                        <Link v-else :href="k.href" class="mt-1 inline-block text-xs font-semibold text-blue-700 hover:underline">عرض</Link></dd>
                </div>
            </dl>
            <DepartmentInsights v-if="insights" :insights="insights" />
            <section class="card space-y-2" aria-labelledby="work-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="work-title" class="card-title">يحتاج إلى إجراء</h2>
                    <Link v-if="workItems.length" href="/orphans/staging" class="text-sm font-semibold text-blue-700 hover:underline">عرض الكل ({{ workItems.length }}) في «المراجعة والمتابعة»</Link>
                </div>
                <p class="text-xs text-slate-500">مرتبة حسب الأولوية. «سجلات الكشف» بيانات أولية مستوردة، و«الملفات المعتمدة» من السجل المعتمد؛ لا تُجمع أعدادهما معًا.</p>
                <p v-if="!workItems.length" class="text-sm text-slate-600">لا توجد أعمال مفتوحة ضمن صلاحياتك.</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="item in workItems.slice(0, 6)" :key="item.queue" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                        <StatusBadge :label="item.tag" :tone="item.tone" />
                        <span class="min-w-0 flex-1"><span class="font-semibold text-navy-800">{{ item.label }}</span><span class="block text-xs text-slate-500">{{ item.tier }}{{ item.note ? ` · ${item.note}` : '' }}</span></span>
                        <strong class="tabular-nums">{{ item.count }}</strong>
                        <Link :href="workItemUrl(item)" class="btn btn-secondary btn-sm">فتح</Link>
                    </li>
                </ul>
            </section>

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

            <section id="approved-files" class="card space-y-3 scroll-mt-24" aria-labelledby="approved-title">
                <h2 id="approved-title" class="card-title">الملفات المعتمدة</h2>
                <p class="text-xs text-slate-500">ملفات الأسر في السجل المعتمد فقط. سجلات الكشف المستورد في «الأيتام والأوصياء».</p>
                <ListToolbar v-model="search" label="رقم ملف الأسرة أو الطفل أو الوصي أو هوية الطفل" :maxlength="40" :count="households.total" :filters="activeFilters" @apply="filter" @remove="removeFilter" @clear="clearFilters">
                    <template #quick><label><span class="field-label">حالة ملف الأسرة</span><select v-model="status" class="field"><option value="">كل الحالات</option><option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option></select></label></template>
                </ListToolbar>
                <div class="aims-table-scroll overflow-x-auto">
                    <table class="aims-responsive-table w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الملف</th><th>العائلة</th><th>الوصي</th><th>الأطفال</th><th>الحالة</th><th>مراجعة</th></tr></thead>
                        <tbody>
                            <tr v-for="h in households.data" :id="`household-${h.public_id}`" :key="h.public_id" class="scroll-mt-24 border-t border-slate-100">
                                <td data-label="الملف" class="py-2"><Link :href="recordWithResults(`/orphans/households/${h.public_id}`, householdResultsUrl(resultsUrl, h.public_id))" class="font-semibold text-blue-700 hover:underline">{{ h.reference_no }}</Link></td>
                                <td data-label="العائلة">{{ h.family_name }}<span v-if="h.displaced" class="ms-1 text-xs text-amber-700">(نازحة)</span><span v-if="h.emergency" class="ms-1 rounded-full bg-red-100 px-2 text-xs font-semibold text-red-800">طوارئ</span></td>
                                <td data-label="الوصي">{{ h.guardian || '—' }}</td>
                                <td data-label="الأطفال">{{ h.active_children }} نشط / {{ h.children }}</td>
                                <td data-label="الحالة"><StatusBadge :label="h.status_label" :tone="tone[h.status]" /></td>
                                <td data-label="مراجعة"><button type="button" class="btn btn-secondary btn-sm" :aria-label="`معاينة ملف ${h.reference_no}`" @click="preview = h">معاينة</button></td>
                            </tr>
                            <tr v-if="!households.data.length"><td colspan="6" class="py-4 text-center text-slate-500">لا توجد ملفات مطابقة.</td></tr>
                        </tbody>
                    </table>
                </div>
                <nav aria-label="صفحات ملفات الأسر" class="flex flex-wrap gap-2">
                    <template v-for="link in households.links" :key="link.label">
                        <Link v-if="link.url" :href="link.url" class="btn btn-secondary btn-sm" :class="{ 'btn-primary': link.active }" :aria-current="link.active ? 'page' : undefined" v-html="link.label" />
                        <span v-else class="btn btn-secondary btn-sm opacity-50" aria-disabled="true" v-html="link.label" />
                    </template>
                </nav>
            </section>
            <RecordPreview :title="preview ? `ملف ${preview.reference_no}` : ''" :fields="previewFields" :href="preview ? recordWithResults(`/orphans/households/${preview.public_id}`, householdResultsUrl(resultsUrl, preview.public_id)) : ''" @close="preview = null" />
        </div>
    </AppLayout>
</template>
