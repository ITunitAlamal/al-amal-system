<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ListToolbar from '../../Components/Orphans/ListToolbar.vue';
import ActionDisclosure from '../../Components/Orphans/ActionDisclosure.vue';
import RegistryPanel from '../../Components/Orphans/RegistryPanel.vue';
import RegistryEntryForm from '../../Components/Orphans/RegistryEntryForm.vue';

// «الأيتام والأوصياء»: the official registry, guardian-centred. Two views, one search, one «تصفية» pane, a side panel
// and the guardian-first «إضافة يتيم». Every list, filter and identity search is authorized on the server.
const props = defineProps({ can: Object, options: Object, statuses: Object, totals: Object, stagedRows: Number });

const blank = () => ({ q: '', child_id: '', guardian_id: '', cn1: '', cn2: '', cn3: '', cn4: '', gn1: '', gn2: '', gn3: '', gn4: '', name_mode: 'starts',
    sex: '', age_min: '', age_max: '', age_unknown: '', orphan_class: '', status: '', link: '', source: '', origin: '' });
const KEYS = Object.keys(blank());
const query0 = new URLSearchParams(location.search);
const view = ref(query0.get('view') === 'guardians' ? 'guardians' : 'children');
const page = ref(Number(query0.get('page')) || 1);
const filters = ref(Object.fromEntries(KEYS.map(k => [k, query0.get(k) ?? blank()[k]])));
const main = ref(filters.value.q || filters.value.child_id || filters.value.guardian_id || '');
const data = ref(null);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const applied = ref({});
const linkLabels = { approved: 'وصي معتمد', pending: 'بانتظار التحقق فقط', none: 'بلا أيتام مرتبطين' };
const sourceLabels = { staff: 'سجّله موظف', portal: 'من البوابة', registry: 'من السجل المدني' };
const labels = { q: 'الاسم', child_id: 'رقم هوية اليتيم', guardian_id: 'رقم هوية الوصي', cn1: 'اليتيم — الاسم الأول', cn2: 'اليتيم — اسم الأب', cn3: 'اليتيم — اسم الجد', cn4: 'اليتيم — اسم العائلة',
    gn1: 'الوصي — الاسم الأول', gn2: 'الوصي — اسم الأب', gn3: 'الوصي — اسم الجد', gn4: 'الوصي — اسم العائلة', sex: 'الجنس', age_min: 'العمر من', age_max: 'العمر إلى',
    age_unknown: 'تاريخ الميلاد غير محدد', orphan_class: 'نوع اليتم', status: 'حالة الملف', link: 'الوصاية', source: 'المصدر', origin: 'تسجيل الوصي' };
const chip = (k, v) => k.endsWith('_id') ? '•••••' + String(v).slice(-4) : k === 'orphan_class' ? (v === '__missing' ? 'غير مصنف' : props.options.orphan_classes.find(o => String(o.id) === String(v))?.name_ar)
    : k === 'status' ? props.statuses[v] : k === 'link' ? linkLabels[v] : k === 'source' || k === 'origin' ? sourceLabels[v] : { male: 'ذكر', female: 'أنثى', __missing: 'غير محدد', '1': 'نعم' }[v] ?? v;
const activeFilters = computed(() => Object.entries(applied.value).filter(([k, v]) => labels[k] && v !== '').map(([key, value]) => ({ key, label: `${labels[key]}: ${chip(key, value)}` })));
const presets = computed(() => view.value === 'children'
    ? [['بانتظار التحقق', { link: 'pending' }, 'أيتام بلا وصي معتمد بعد'], ['غير مصنفين', { orphan_class: '__missing' }, 'نوع اليتم غير محدد'], ['ميلاد غير محدد', { age_unknown: '1' }, '']]
    : [['ادعاءات بانتظار القرار', { link: 'pending' }, 'أوصياء لهم روابط بانتظار التحقق'], ['بلا أيتام', { link: 'none' }, 'أوصياء مسجلون بلا أي رابط']]);

const toWestern = t => String(t).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
function params() {
    const p = { page: page.value };
    for (const [k, v] of Object.entries(filters.value)) if (v !== '' && !(k === 'name_mode' && v === 'starts')) p[k] = v;
    return p;
}
function syncUrl(query) {
    const url = new URLSearchParams({ view: view.value, ...Object.fromEntries(Object.entries(query).filter(([k, v]) => !(k === 'page' && v === 1))) });
    history.replaceState(history.state, '', `${location.pathname}?${url}`);
}
function errorText(e) { return Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر إتمام العملية'; }
let latest = 0;
async function load() {
    const id = ++latest;
    const query = params();
    busy.value = true; error.value = '';
    try {
        const { data: d } = await axios.get(`/orphans/registry/${view.value}`, { params: query });
        if (id === latest) { data.value = d; applied.value = { ...filters.value }; syncUrl(query); }
    } catch (e) { if (id === latest) { data.value = null; error.value = errorText(e); } }
    finally { if (id === latest) busy.value = false; }
}
function applyMain() {
    const text = toWestern(main.value.trim());
    const idKey = view.value === 'children' ? 'child_id' : 'guardian_id';
    if (/^\d{4,20}$/.test(text)) {
        if (!props.can.sensitive) { error.value = 'البحث برقم الهوية يحتاج صلاحية الاطلاع على البيانات الحساسة.'; return false; }
        Object.assign(filters.value, { q: '', [idKey]: text });
    } else Object.assign(filters.value, { q: main.value.trim(), [idKey]: '' });
    return true;
}
function search() { if (applyMain()) { page.value = 1; load(); } }
let timer;
watch(main, value => {
    clearTimeout(timer);
    if (toWestern(value.trim()) === (filters.value.q || filters.value.child_id || filters.value.guardian_id || '')) return;
    timer = setTimeout(search, 450);
});
function removeFilter(key) { filters.value[key] = blank()[key]; if (['q', 'child_id', 'guardian_id'].includes(key)) main.value = ''; page.value = 1; load(); }
function reset() { filters.value = blank(); main.value = ''; page.value = 1; load(); }
function preset(values) { filters.value = { ...blank(), ...values }; main.value = ''; page.value = 1; load(); }
function switchView(next) { if (view.value === next) return; view.value = next; page.value = 1; data.value = null; panel.value = []; filters.value.link = ''; search(); }

// Side panel with a short back stack; the list and its filters stay as they are.
const panel = ref([]);
const aside = ref(null);
const current = computed(() => panel.value[panel.value.length - 1] || null);
async function openPanel(kind, id) {
    busy.value = true; error.value = '';
    try {
        const { data: d } = await axios.get(`/orphans/registry/${kind === 'child' ? 'children' : 'guardians'}/${id}`);
        panel.value = [...panel.value.slice(-4), { kind, id, data: d }];
        await nextTick(); aside.value?.focus();
    } catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function refreshPanel() {
    const item = current.value;
    if (item) item.data = (await axios.get(`/orphans/registry/${item.kind === 'child' ? 'children' : 'guardians'}/${item.id}`)).data;
    load();
}
const entry = ref(null);
function startEntry(mode, presetGuardian = null) { entry.value = { mode, presetGuardian }; panel.value = []; notice.value = ''; nextTick(() => aside.value?.focus()); }
function saved(result) { entry.value = null; notice.value = result.message; load(); openPanel(result.kind === 'child' ? 'child' : 'guardian', result.id); }
function onKey(e) { if (e.key === 'Escape') { if (entry.value) entry.value = null; else if (panel.value.length) panel.value = []; } }
const sexLabel = s => ({ male: 'ذكر', female: 'أنثى' }[s] || 'غير محدد');
const linkTone = { approved: 'success', pending: 'warning' };
onMounted(() => {
    document.addEventListener('keydown', onKey);
    if (main.value && /^[\d٠-٩۰-۹]+$/.test(main.value.trim())) applyMain();
    load();
});
onUnmounted(() => { document.removeEventListener('keydown', onKey); clearTimeout(timer); });
</script>

<template>
    <AppLayout title="الأيتام والأوصياء">
        <div class="space-y-4">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="الأيتام والأوصياء" description="سجل واحد بعرضين. لكل يتيم وصي شرعي مرتبط به مباشرة؛ الرابط «بانتظار التحقق» حتى يُعتمد لهذا اليتيم وحده. الأسرة للبيانات الاجتماعية ولا تعني وصاية.">
                <ActionDisclosure v-if="can.records" label="إضافة جديد" v-slot="{ close }">
                    <button type="button" class="btn btn-primary btn-sm" @click="close(); startEntry('child')">إضافة يتيم</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="close(); startEntry('guardian')">إضافة وصي</button>
                </ActionDisclosure>
                <ActionDisclosure>
                    <Link href="/orphans/staging" class="text-sm text-blue-700 hover:underline">المراجعة والمتابعة</Link>
                    <Link href="/orphans/reports" class="text-sm text-blue-700 hover:underline">التقارير والإحصائيات</Link>
                    <Link v-if="can.imports" href="/orphans/imports" class="text-sm text-blue-700 hover:underline">استيراد الكشوف</Link>
                </ActionDisclosure>
            </PageHeader>
            <p class="text-xs text-slate-500">في السجل: {{ totals.children }} يتيمًا · {{ totals.guardians }} وصيًا · روابط معتمدة {{ totals.approved_links }} · بانتظار التحقق {{ totals.pending_links }}<template v-if="stagedRows"> · {{ stagedRows }} صفًا مستوردًا بانتظار التدقيق في «المراجعة والمتابعة» (ليست ضمن هذه الأعداد)</template></p>
            <div v-if="notice" class="alert-success" role="status">{{ notice }}</div>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="العرض">
                <button v-for="[key, label] in [['children', 'الأيتام'], ['guardians', 'الأوصياء']]" :key="key" type="button" class="btn btn-sm" :class="view === key ? 'btn-primary' : 'btn-secondary'" :aria-pressed="view === key" @click="switchView(key)">{{ label }}</button>
            </div>

            <ListToolbar v-model="main" class="card" :label="view === 'children' ? 'بحث باسم اليتيم أو رقم هويته' : 'بحث باسم الوصي أو رقم هويته'" placeholder="الاسم كاملًا أو جزء منه، أو رقم الهوية"
                :busy="busy" :count="typeof data?.total === 'number' ? data.total : null" :filters="activeFilters" @apply="search" @remove="removeFilter" @clear="reset">
                <template #advanced>
                    <fieldset class="space-y-2 sm:col-span-2 lg:col-span-3">
                        <legend class="text-sm font-bold text-navy-800">رقم الهوية — مطابقة تامة</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label><span class="field-label">رقم هوية اليتيم</span><input v-model="filters.child_id" class="field" dir="ltr" inputmode="numeric" maxlength="20" autocomplete="off" :disabled="!can.sensitive" /></label>
                            <label><span class="field-label">رقم هوية الوصي</span><input v-model="filters.guardian_id" class="field" dir="ltr" inputmode="numeric" maxlength="20" autocomplete="off" :disabled="!can.sensitive" /></label>
                        </div>
                        <p class="text-xs text-slate-500">{{ can.sensitive ? 'يُطابق الرقم كما سُجّل حرفًا بحرف؛ الأرقام العربية مقبولة، ولا يُضاف صفر ولا يُحذف.' : 'البحث برقم الهوية يحتاج صلاحية الاطلاع على البيانات الحساسة.' }}</p>
                    </fieldset>
                    <fieldset v-for="[prefix, title] in [['cn', 'الاسم الرباعي لليتيم'], ['gn', 'الاسم الرباعي للوصي']]" :key="prefix" class="space-y-2 sm:col-span-2 lg:col-span-3">
                        <legend class="text-sm font-bold text-navy-800">{{ title }}</legend>
                        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <label v-for="[n, label] in [[1, 'الاسم الأول'], [2, 'اسم الأب'], [3, 'اسم الجد'], [4, 'اسم العائلة']]" :key="n"><span class="field-label">{{ label }}</span><input v-model="filters[prefix + n]" class="field" maxlength="60" autocomplete="off" /></label>
                        </div>
                    </fieldset>
                    <label><span class="field-label">مطابقة مقاطع الاسم</span><select v-model="filters.name_mode" class="field"><option value="starts">يبدأ بـ</option><option value="exact">مطابق تمامًا</option></select></label>
                    <p class="text-xs text-slate-500 lg:col-span-2">اسم العائلة يطابق أيًّا من كلماته. الاسم المسجل بلا اسم جد لا يطابق «اسم الجد». تُوحَّد الهمزات والتاء المربوطة عند البحث فقط.</p>
                    <template v-if="view === 'children'">
                        <label><span class="field-label">نوع اليتم</span><select v-model="filters.orphan_class" class="field"><option value="">الكل</option><option value="__missing">غير مصنف</option><option v-for="o in options.orphan_classes" :key="o.id" :value="String(o.id)">{{ o.name_ar }}</option></select></label>
                        <label><span class="field-label">الوصاية</span><select v-model="filters.link" class="field"><option value="">الكل</option><option value="approved">وصي معتمد</option><option value="pending">بانتظار التحقق فقط</option></select></label>
                        <label><span class="field-label">حالة الملف</span><select v-model="filters.status" class="field"><option value="">الكل</option><option v-for="(label, code) in statuses" :key="code" :value="code">{{ label }}</option></select></label>
                        <label><span class="field-label">الجنس</span><select v-model="filters.sex" class="field"><option value="">الكل</option><option value="male">ذكر</option><option value="female">أنثى</option><option value="__missing">غير محدد</option></select></label>
                        <div class="grid grid-cols-2 gap-2"><label><span class="field-label">العمر من</span><input v-model="filters.age_min" type="number" min="0" max="40" class="field" /></label><label><span class="field-label">إلى</span><input v-model="filters.age_max" type="number" min="0" max="40" class="field" /></label></div>
                        <label><span class="field-label">المصدر</span><select v-model="filters.source" class="field"><option value="">الكل</option><option value="staff">سجّله موظف</option><option value="portal">من البوابة</option><option value="registry">من السجل المدني</option></select></label>
                    </template>
                    <template v-else>
                        <label><span class="field-label">الأيتام المرتبطون</span><select v-model="filters.link" class="field"><option value="">الكل</option><option value="pending">ادعاءات بانتظار القرار</option><option value="none">بلا أيتام مرتبطين</option></select></label>
                        <label><span class="field-label">تسجيل الوصي</span><select v-model="filters.origin" class="field"><option value="">الكل</option><option value="staff">سجّله موظف</option><option value="portal">من البوابة</option></select></label>
                    </template>
                    <p class="text-xs text-slate-500 sm:col-span-2 lg:col-span-3">التصفية بالمحافظة والمدينة تُتاح بعد تسجيل عناوين الأيتام في السجل الرسمي؛ عناوين الأسر للبيانات الاجتماعية.</p>
                </template>
            </ListToolbar>

            <div class="flex flex-wrap items-center gap-2 text-sm" aria-label="عروض جاهزة">
                <span class="text-slate-500">عروض جاهزة:</span>
                <button v-for="[label, values, hint] in presets" :key="label" type="button" class="btn btn-secondary btn-sm" :title="hint" @click="preset(values)">{{ label }}</button>
            </div>
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <p v-if="busy && !data" class="text-sm text-slate-500" role="status">جارٍ التحميل...</p>

            <section v-if="data" class="card min-w-0 space-y-3" :aria-busy="busy">
                <p class="text-sm text-slate-600" aria-live="polite">{{ data.total }} {{ view === 'children' ? 'يتيمًا' : 'وصيًا' }}</p>
                <EmptyState v-if="!data.data.length" :title="totals.children || totals.guardians ? 'لا توجد نتائج مطابقة' : 'السجل فارغ'"
                    :description="totals.children || totals.guardians ? 'غيّر البحث أو أزل بعض الفلاتر.' : (can.records ? 'ابدأ بـ«إضافة جديد»: الوصي أولًا ثم اليتيم.' : 'لا توجد سجلات بعد.')" />
                <div v-else class="overflow-x-auto">
                    <table v-if="view === 'children'" class="data-table min-w-full text-sm">
                        <thead><tr><th scope="col">اليتيم</th><th scope="col">العمر</th><th scope="col">نوع اليتم</th><th scope="col">الوصي</th><th scope="col">حالة الملف</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                        <tbody><tr v-for="r in data.data" :key="r.id" :class="current?.kind === 'child' && current.id === r.id ? 'bg-gold-50' : ''">
                            <td><span class="font-semibold text-navy-800">{{ r.name }}</span><span dir="ltr" class="block text-right text-xs text-slate-500">{{ r.national_id || 'بلا رقم هوية' }}</span></td>
                            <td class="tabular-nums">{{ r.age !== null ? `${r.age} سنة` : 'غير محدد' }}<span class="block text-xs text-slate-500">{{ sexLabel(r.sex) }}</span></td>
                            <td>{{ r.orphan_class || 'غير مصنف' }}</td>
                            <td><template v-if="r.guardian">{{ r.guardian.name }}<span class="block"><StatusBadge :label="r.guardian.status === 'approved' ? 'معتمدة' : 'بانتظار التحقق'" :tone="linkTone[r.guardian.status]" /></span>
                                <span v-if="r.claims > 1" class="block text-xs text-red-700">{{ r.claims }} ادعاءات وصاية</span></template></td>
                            <td>{{ r.status_label }}</td>
                            <td><button type="button" class="btn btn-secondary btn-sm" @click="openPanel('child', r.id)">عرض الملف</button></td>
                        </tr></tbody>
                    </table>
                    <table v-else class="data-table min-w-full text-sm">
                        <thead><tr><th scope="col">الوصي</th><th scope="col">التسجيل</th><th scope="col">أيتام معتمدون</th><th scope="col">بانتظار التحقق</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                        <tbody><tr v-for="g in data.data" :key="g.id" :class="current?.kind === 'guardian' && current.id === g.id ? 'bg-gold-50' : ''">
                            <td><span class="font-semibold text-navy-800">{{ g.name }}</span><span class="block text-xs text-slate-500">{{ g.reference_no || '' }} <span dir="ltr">{{ g.national_id }}</span></span></td>
                            <td class="text-xs">{{ g.origin === 'staff' ? 'سجّله موظف' : 'من البوابة' }}<span class="block text-slate-500">{{ g.portal ? 'حساب بوابة مفعّل' : 'بلا حساب بوابة مفعّل' }}</span></td>
                            <td class="tabular-nums">{{ g.approved }}</td>
                            <td class="tabular-nums"><span :class="g.pending ? 'font-semibold text-gold-800' : ''">{{ g.pending }}</span></td>
                            <td><button type="button" class="btn btn-secondary btn-sm" @click="openPanel('guardian', g.id)">عرض الأيتام</button></td>
                        </tr></tbody>
                    </table>
                </div>
                <nav v-if="data.last_page > 1" class="flex items-center justify-between gap-3" aria-label="الصفحات">
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || page <= 1" @click="page--; load()">السابق</button>
                    <span class="text-sm text-slate-600">صفحة {{ data.current_page }} من {{ data.last_page }}</span>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || page >= data.last_page" @click="page++; load()">التالي</button>
                </nav>
            </section>

            <aside v-if="current || entry" ref="aside" tabindex="-1" class="fixed inset-y-0 left-0 z-40 w-full max-w-2xl overflow-y-auto border-r border-slate-200 bg-white p-5 shadow-2xl" role="dialog" aria-modal="false" aria-label="المعاينة والإدخال" dir="rtl">
                <RegistryEntryForm v-if="entry" :mode="entry.mode" :options="options" :can="can" :preset-guardian="entry.presetGuardian" @saved="saved" @cancel="entry = null" />
                <template v-else>
                    <button v-if="panel.length > 1" type="button" class="mb-3 text-sm font-semibold text-blue-700 hover:underline" @click="panel = panel.slice(0, -1)">← رجوع</button>
                    <RegistryPanel :kind="current.kind" :data="current.data" :can="can" @close="panel = []" @changed="refreshPanel"
                        @open-child="openPanel('child', $event)" @open-guardian="openPanel('guardian', $event)" @add-child="startEntry('child', $event)" />
                </template>
            </aside>
        </div>
    </AppLayout>
</template>
