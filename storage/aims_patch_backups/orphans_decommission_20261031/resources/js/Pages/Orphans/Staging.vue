<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ActionDisclosure from '../../Components/Orphans/ActionDisclosure.vue';
import ListToolbar from '../../Components/Orphans/ListToolbar.vue';
import StagedRowFile from '../../Components/Orphans/StagedRowFile.vue';
import StagedFamilyView from '../../Components/Orphans/StagedFamilyView.vue';
import RegistryQueueList from '../../Components/Orphans/RegistryQueueList.vue';
import { buildWorkItems, registryQueueLabels } from '../../Support/orphanWorkItems.js';

// «المراجعة والمتابعة»: the single «يحتاج إلى إجراء» queue. Import issues (provisional source claims) and the approved
// registry's work lists are shown as one prioritized list; nothing here approves an orphan, a guardianship, portal
// access, a sponsorship or a payment. The server repeats every permission and masking rule.
const props = defineProps({ ready: Boolean, batch: Object, batches: Array, can: Object, issueTypes: Array, severities: Object, reviewStatuses: Object, decisions: Object,
    reviewers: Array, staged: Object, queues: Object, plans: Boolean });
const items = computed(() => buildWorkItems(props.staged, props.queues || {}, { plans: props.plans }));
// Fixed entries of the import tier, then the prioritized items.
const importViews = computed(() => props.batch ? [
    { queue: 'all', label: 'كل الملاحظات المفتوحة', count: props.staged?.types?.reduce((n, t) => n + t.count, 0) ?? null, tier: 'سجلات الكشف' },
    { queue: 'assigned', label: 'المكلّفة لي', count: props.staged?.mine ?? null, tier: 'سجلات الكشف' },
] : []);

const start = new URLSearchParams(location.search);
// Old links (?tab=issues&type=…&severity=…&assigned=me) keep working.
const legacyQueue = start.get('type') ? `issue:${start.get('type')}` : start.get('severity') === 'high' ? 'high' : start.get('assigned') === 'me' ? 'assigned' : null;
const firstItem = () => items.value.find(i => !i.href)?.queue || 'all';
const queue = ref(start.get('queue') || legacyQueue || firstItem());
const isImport = computed(() => ['all', 'high', 'assigned'].includes(queue.value) || queue.value.startsWith('issue:'));
const queueTitle = computed(() => [...importViews.value, ...items.value].find(i => i.queue === queue.value)?.label
    || (queue.value === 'high' ? 'ملاحظات عالية الأولوية' : registryQueueLabels[queue.value] || 'يحتاج إلى إجراء'));

const data = ref(null);
const busy = ref(false);
const error = ref('');
const message = ref('');
const page = ref(1);
const blankFilters = () => ({ q: '', national_id: '', governorate: '', sex: '', displaced: '', age_min: '', age_max: '', severity: '', assigned: '', status: 'open' });
const filters = ref({ ...blankFilters(), ...(start.get('status') ? { status: start.get('status') } : {}) });
const searchTerm = computed({
    get: () => filters.value.national_id || filters.value.q,
    set: value => {
        const exactId = props.can.sensitive && /^\d{9}$/.test(value.trim());
        filters.value.national_id = exactId ? value.trim() : '';
        filters.value.q = exactId ? '' : value;
    },
});
const applied = ref({});
const filterLabels = { q: 'الاسم', national_id: 'هوية مطابقة تامة', governorate: 'المحافظة', sex: 'الجنس', displaced: 'النزوح', age_min: 'العمر من', age_max: 'العمر إلى', severity: 'الأولوية', assigned: 'التكليف', status: 'حالة الملاحظة' };
const valueLabels = { male: 'ذكر', female: 'أنثى', missing: 'غير محدد', yes: 'نعم', no: 'لا', open: 'مفتوحة', follow_up: 'متابعة', resolved: 'محلولة', dismissed: 'مستبعدة', me: 'المكلفة لي', none: 'غير مكلفة' };
const activeFilters = computed(() => Object.entries(applied.value).filter(([k, v]) => filterLabels[k] && v !== '' && !(k === 'status' && v === 'open'))
    .map(([key, value]) => ({ key, label: `${filterLabels[key]}: ${key === 'national_id' ? '•••••' + String(value).slice(-4) : props.severities[value] || valueLabels[value] || value}` })));
const selectedIssues = ref([]);
const assignee = ref('');
const detail = ref(null);
const tree = ref(null);
const household = ref(null);
const householdRef = ref(null);
const severityTone = { high: 'danger', medium: 'warning', low: 'info', info: 'muted' };

function queueParams() {
    if (queue.value === 'high') return { severity: 'high' };
    if (queue.value === 'assigned') return { assigned: 'me' };
    if (queue.value.startsWith('issue:')) return { type: queue.value.slice(6) };
    return {};
}
function errorText(e) { return Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر إتمام العملية'; }
function syncUrl() {
    const url = new URLSearchParams({ queue: queue.value });
    if (start.get('batch')) url.set('batch', start.get('batch'));
    history.replaceState(history.state, '', `${location.pathname}?${url}`);
}
let latest = 0;
async function load() {
    syncUrl();
    if (!isImport.value || !props.ready || !props.batch) return;
    const id = ++latest;
    const query = { batch: props.batch.public_id, page: page.value, ...Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== '')), ...queueParams() };
    busy.value = true; error.value = '';
    try {
        const { data: d } = await axios.get('/orphans/staging/data/issues', { params: query });
        if (id === latest) { data.value = d; applied.value = { ...filters.value }; }
    } catch (e) { if (id === latest) { data.value = null; error.value = errorText(e); } }
    finally { if (id === latest) busy.value = false; }
}
function choose(item) {
    if (item.href) { router.visit(item.href); return; }
    queue.value = item.queue; page.value = 1; data.value = null; selectedIssues.value = []; load();
}
function search() { page.value = 1; load(); }
function reset() { filters.value = blankFilters(); search(); }
function removeFilter(key) { filters.value[key] = blankFilters()[key] ?? ''; search(); }

async function openRow(id) {
    busy.value = true; error.value = '';
    try { detail.value = (await axios.get(`/orphans/staging/rows/${id}`)).data; setTimeout(() => document.getElementById('row-detail')?.scrollIntoView({ behavior: 'smooth' }), 50); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function openTree(key) {
    busy.value = true;
    try { tree.value = (await axios.get(`/orphans/staging/guardians/${key}`, { params: { batch: props.batch.public_id } })).data; household.value = null; }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function openHousehold(key) {
    if (!key) return;
    householdRef.value = key; busy.value = true;
    try { household.value = (await axios.get(`/orphans/staging/households/${key}`, { params: { batch: props.batch.public_id } })).data; tree.value = null; }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function post(url, body, done = 'تم حفظ الإجراء وتسجيله.') {
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post(url, body, { params: { batch: props.batch.public_id } }); message.value = done; return true; }
    catch (e) { error.value = errorText(e); return false; } finally { busy.value = false; }
}
async function settle(issue, status) {
    const note = prompt(status === 'resolved' ? 'ملاحظة الحل (10 أحرف على الأقل)' : status === 'dismissed' ? 'سبب الاستبعاد (10 أحرف على الأقل)' : 'ملاحظة المتابعة (10 أحرف على الأقل)') || '';
    if (note.trim().length < 10) return;
    if (await post(`/orphans/staging/issues/${issue.id}`, { status, note })) { if (detail.value) openRow(detail.value.id); load(); }
}
async function assign() {
    if (!selectedIssues.value.length || !confirm(`تكليف ${selectedIssues.value.length} ملاحظة؟ التكليف لا يعتمد أي بيانات.`)) return;
    if (await post('/orphans/staging/issues/assign', { ids: selectedIssues.value, assignee: assignee.value || null })) { selectedIssues.value = []; load(); }
}
const changeBatch = (id) => router.visit(`/orphans/staging?batch=${id}&queue=${encodeURIComponent(queue.value)}`);
onMounted(() => {
    load();
    const row = Number(start.get('row'));
    if (row) openRow(row);
});
</script>

<template>
    <AppLayout title="المراجعة والمتابعة">
        <div class="space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="المراجعة والمتابعة" description="كل ما يحتاج إلى إجراء في قائمة واحدة مرتبة حسب الأولوية: ملاحظات سجلات الكشف المستورد وقوائم عمل الملفات المعتمدة.">
                <Link href="/orphans/records" class="btn btn-secondary btn-sm">البحث في الأيتام والأوصياء</Link>
                <ActionDisclosure>
                    <Link href="/admin/orphans/guardians" class="text-sm text-blue-700 hover:underline">طلبات الأوصياء</Link>
                    <Link v-if="plans" href="/orphans/visit-plans" class="text-sm text-blue-700 hover:underline">خطط الزيارات</Link>
                    <Link v-if="can.imports" href="/orphans/imports" class="text-sm text-blue-700 hover:underline">استيراد الكشوف</Link>
                </ActionDisclosure>
            </PageHeader>
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>

            <div class="grid gap-4 lg:grid-cols-[19rem_minmax(0,1fr)]">
                <nav class="card h-fit space-y-1 p-3" aria-label="يحتاج إلى إجراء">
                    <h2 class="px-2 pb-1 text-sm font-bold text-navy-800">يحتاج إلى إجراء</h2>
                    <p v-if="!items.length && !importViews.length" class="px-2 text-sm text-slate-500">لا توجد أعمال مفتوحة ضمن صلاحياتك.</p>
                    <button v-for="item in [...importViews, ...items]" :key="item.queue" type="button" :aria-current="queue === item.queue ? 'true' : undefined"
                        class="flex w-full items-start gap-2 rounded-lg px-2 py-2 text-right text-sm hover:bg-slate-50" :class="queue === item.queue ? 'bg-gold-50 font-semibold' : ''" @click="choose(item)">
                        <span class="min-w-0 flex-1"><span class="block text-navy-800">{{ item.label }}</span><span class="block text-xs font-normal text-slate-500">{{ item.tier }}<template v-if="item.tag"> · {{ item.tag }}</template></span></span>
                        <span class="tabular-nums">{{ item.count ?? '—' }}</span>
                    </button>
                </nav>

                <div class="min-w-0 space-y-4">
                    <h2 class="text-lg font-bold text-navy-800" aria-live="polite">{{ queueTitle }}</h2>
                    <template v-if="isImport">
                        <div v-if="!ready" class="card text-sm text-slate-600" role="status">لم تُفعَّل جداول الاستيراد بعد.</div>
                        <EmptyState v-else-if="!batch" title="لا توجد دفعة مستوردة بعد" description="بعد اعتماد دفعة واكتمال استيرادها المؤقت تظهر ملاحظاتها هنا." />
                        <template v-else>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label v-if="batches.length > 1" class="flex items-center gap-2 text-sm"><span class="text-slate-500">الدفعة</span>
                                    <select class="field py-1" :value="batch.public_id" @change="changeBatch($event.target.value)"><option v-for="b in batches" :key="b.public_id" :value="b.public_id">{{ b.file_name }} — {{ b.completed_at?.slice(0, 10) }}</option></select></label>
                                <span class="rounded-full bg-gold-100 px-3 py-1 text-xs font-semibold text-gold-800">سجلات الكشف — بيانات أولية غير معتمدة</span>
                            </div>
                            <ListToolbar v-model="searchTerm" class="card" :label="can.sensitive ? 'بحث بالاسم (يبدأ بـ) أو رقم هوية من 9 أرقام (مطابقة تامة)' : 'بحث بالاسم (يبدأ بـ)'" placeholder="الطفل أو الأب أو الأم أو الوصي" :busy="busy" :count="typeof data?.total === 'number' ? data.total : null" :filters="activeFilters" @apply="search" @remove="removeFilter" @clear="reset">
                                <template #advanced>
                                    <label><span class="field-label">الحالة</span><select v-model="filters.status" class="field"><option value="open">مفتوحة</option><option value="follow_up">متابعة</option><option value="resolved">محلولة</option><option value="dismissed">مستبعدة</option></select></label>
                                    <label v-if="queue !== 'high'"><span class="field-label">الأولوية</span><select v-model="filters.severity" class="field"><option value="">الكل</option><option v-for="(label, code) in severities" :key="code" :value="code">{{ label }}</option></select></label>
                                    <label v-if="queue !== 'assigned'"><span class="field-label">التكليف</span><select v-model="filters.assigned" class="field"><option value="">الكل</option><option value="me">المكلفة لي</option><option value="none">غير مكلفة</option></select></label>
                                    <label><span class="field-label">المحافظة</span><input v-model="filters.governorate" class="field" placeholder="كما في الكشف" /></label>
                                    <label><span class="field-label">الجنس</span><select v-model="filters.sex" class="field"><option value="">الكل</option><option value="male">ذكر</option><option value="female">أنثى</option><option value="missing">غير محدد</option></select></label>
                                    <div class="grid grid-cols-2 gap-2"><label><span class="field-label">العمر من</span><input v-model="filters.age_min" type="number" min="0" max="40" class="field" /></label><label><span class="field-label">إلى</span><input v-model="filters.age_max" type="number" min="0" max="40" class="field" /></label></div>
                                </template>
                            </ListToolbar>
                            <p v-if="busy && !data" class="text-sm text-slate-500" role="status">جارٍ التحميل...</p>
                            <section v-if="data" class="card min-w-0 space-y-3" :aria-busy="busy">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm text-slate-600">{{ data.total ?? 0 }} ملاحظة</p>
                                    <div v-if="can.review" class="flex flex-wrap items-center gap-2">
                                        <select v-model="assignee" class="field py-1 text-sm" aria-label="المكلف"><option value="">إلغاء التكليف</option><option v-for="r in reviewers" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                                        <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || !selectedIssues.length" @click="assign">تكليف المحدد ({{ selectedIssues.length }})</button>
                                    </div>
                                </div>
                                <EmptyState v-if="!data.data?.length" title="لا توجد ملاحظات مطابقة" />
                                <div v-else class="overflow-x-auto">
                                    <table class="data-table min-w-full text-sm">
                                        <thead><tr><th scope="col"><span class="sr-only">تحديد</span></th><th scope="col">الأولوية</th><th scope="col">الملاحظة</th><th scope="col">الطفل</th><th scope="col">المكلف</th><th scope="col"><span class="sr-only">إجراءات</span></th></tr></thead>
                                        <tbody><tr v-for="i in data.data" :key="i.id">
                                            <td><input v-model="selectedIssues" type="checkbox" :value="i.id" :aria-label="`تحديد الملاحظة ${i.id}`" /></td>
                                            <td><StatusBadge :label="severities[i.severity]" :tone="severityTone[i.severity]" /></td>
                                            <td><span class="font-semibold">{{ i.label }}</span><div class="text-xs text-slate-500">{{ i.recommendation }}</div></td>
                                            <td>{{ i.child_name || '—' }}<span dir="ltr" class="block text-right text-xs text-slate-400">{{ i.national_id }} · صف {{ i.row }}</span></td>
                                            <td class="text-xs">{{ i.assignee || '—' }}</td>
                                            <td><div class="flex flex-wrap gap-1"><button type="button" class="btn btn-secondary btn-sm" @click="openRow(i.row_id)">مقارنة ومراجعة</button>
                                                <ActionDisclosure v-if="can.review && ['open', 'follow_up'].includes(i.status)" v-slot="{ close }">
                                                    <button type="button" class="btn btn-secondary btn-sm" @click="close(); settle(i, 'resolved')">حل</button>
                                                    <button type="button" class="btn btn-secondary btn-sm" @click="close(); settle(i, 'follow_up')">متابعة</button>
                                                    <button type="button" class="btn btn-secondary btn-sm" @click="close(); settle(i, 'dismissed')">استبعاد</button>
                                                </ActionDisclosure></div></td>
                                        </tr></tbody>
                                    </table>
                                </div>
                                <nav v-if="data.last_page > 1" class="flex items-center justify-between gap-3" aria-label="الصفحات">
                                    <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || page <= 1" @click="page--; load()">السابق</button>
                                    <span class="text-sm text-slate-600">صفحة {{ data.current_page }} من {{ data.last_page }}</span>
                                    <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || page >= data.last_page" @click="page++; load()">التالي</button>
                                </nav>
                            </section>
                        </template>
                    </template>
                    <div v-else class="card"><RegistryQueueList :queue="queue" :queues="queues || {}" /></div>

                    <section v-if="tree || household" class="card">
                        <StagedFamilyView :tree="tree" :household="household" :review-statuses="reviewStatuses" :can="can" :decisions="decisions"
                            @changed="householdRef && openHousehold(householdRef)" @close="tree = null; household = null" @open-row="openRow" @open-tree="openTree" @open-household="openHousehold" />
                    </section>
                    <section v-if="detail" id="row-detail" class="card">
                        <StagedRowFile :detail="detail" :can="can" :severities="severities" :decisions="decisions"
                            @close="detail = null" @changed="openRow(detail.id); load()" @open-row="openRow" @open-household="openHousehold" @open-tree="openTree" />
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
