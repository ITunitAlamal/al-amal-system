<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';
import ImportIcon from '../../../Components/Registry/ImportIcon.vue';
import Pagination from '../../../Components/Pagination.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import ChangeHistory from '../../../Components/ChangeHistory.vue';

const props = defineProps({ batch: Object, sheets: Array, sheet: String, headerRow: Number, preview: Object, suggested: Object, fields: Array, profiles: Array, rows: Object, category: String, categories: Object, abilities: Object,
    filter: String, filters: Object, outcomes: Object, policy: Object, issueLabels: Object, canReset: Boolean, backlog: Object, unmappedOptions: { type: Array, default: null }, canCheckOptions: Boolean });
const checkingOptions = ref(false);
function checkOptions() { checkingOptions.value = true; router.reload({ only: ['unmappedOptions'], onFinish: () => { checkingOptions.value = false; } }); }
const stages = ['رفع الملف', 'مطابقة الأعمدة', 'تحليل البيانات', 'المراجعة والتصحيح', 'المعاينة والاعتماد المبدئي', 'تنفيذ ومتابعة النتائج'];
const position = computed(() => ({ uploaded: 1, mapped: 1, analyzing: 2, reviewing: 3, approved: 5, executing: 5, partially_imported: 4, imported: 6, completed: 6, cancelled: 0, failed: 2 }[props.batch.status]));
const reviewable = computed(() => ['reviewing', 'partially_imported'].includes(props.batch.status));
const registry = computed(() => props.batch.purpose === 'registry');
const confirmCommit = ref(false);
function commit(scoped) {
    if (!confirmCommit.value) { window.alert('أكّد أولًا أن الاعتماد المبدئي ليس اعتمادًا قانونيًا للوصاية.'); return; }
    router.post(`/orphans/import/${props.batch.public_id}/approve`, { confirm: true, rows: scoped ? selected.value : null }, { preserveScroll: true, onSuccess: () => { selected.value = []; } });
}
function reanalyze() {
    const reason = props.canReset ? window.prompt('إعادة الفحص تمحو قرارات المراجعة والتصحيحات في هذه الدفعة. اكتب السبب للمتابعة:') : null;
    if (props.canReset && !reason) return;
    router.post(`/orphans/import/${props.batch.public_id}/reanalyze`, { reset: !!reason, reason }, { preserveScroll: true });
}
const go = params => router.get(`/orphans/import/${props.batch.public_id}`, params, { preserveScroll: true });
// Side drawer: provenance, family members in the file, inferred guardian, findings and the registry result.
const drawer = ref(null);
const drawerError = ref('');
async function openRow(r) {
    drawer.value = { loading: true, row_no: r.row_no };
    drawerError.value = '';
    try { drawer.value = (await window.axios.get(`/orphans/import/${props.batch.public_id}/rows/${r.id}`, { headers: { Accept: 'application/json' } })).data; }
    catch { drawerError.value = 'تعذر تحميل تفاصيل الصف. أعد المحاولة.'; }
}
const steps = computed(() => stages.map((label, i) => ({ label, state: props.batch.status === 'cancelled' ? 'cancelled' : (i < position.value ? 'done' : (i === position.value ? (props.batch.status === 'failed' ? 'rejected' : 'current') : 'upcoming')) })));
const initial = Object.keys(props.batch.mapping).length ? props.batch.mapping : props.suggested;
const mapping = useForm({ sheet: props.sheet, header_row: props.headerRow, mapping: { ...initial }, profile_name: '' });
const fieldLabel = key => props.fields.find(f => f.key === key)?.label || key;
function loadProfile(profile) {
    if (!profile) return;
    const byHeader = typeof profile.mapping === 'string' ? JSON.parse(profile.mapping) : profile.mapping;
    const norm = s => String(s).replace(/[\s\p{P}]+/gu, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').toLowerCase();
    mapping.mapping = Object.fromEntries(Object.entries(props.preview.headers).map(([i, h]) => [i, byHeader[norm(h)] || '']).filter(([, v]) => v));
}
const selected = ref([]);
const editing = ref(null);
const correction = useForm({ fields: {} });
const correctable = ['child_full_name', 'child_id', 'child_dob', 'child_sex', 'guardian_full_name', 'guardian_id', 'guardian_relation', 'father_full_name', 'mother_full_name', 'full_name', 'child_id', 'orphan_reference', 'household_reference', 'quantity', 'variant'];
function startEdit(row) { editing.value = row.id; correction.fields = Object.fromEntries(props.fields.filter(f => correctable.includes(f.key) && f.sensitivity !== 'health').map(f => [f.key, ''])); }
// Accepting needs a reason (kept in «سجل النظام»). A group accept leaves out conflicts and identity/guardian findings.
const decisionReason = ref('');
const decide = (decision, rows = selected.value) => router.post(`/orphans/import/${props.batch.public_id}/decide`, { rows, decision, reason: decisionReason.value || null },
    { preserveScroll: true, onSuccess: () => { selected.value = []; } });
const tones = { ready: 'success', needs_review: 'warning', conflict: 'danger', duplicate: 'muted', rejected: 'danger' };
const summary = computed(() => ({ categories: {}, actions: {}, issues: {}, executed: {}, preview: {}, official: {}, ...(props.batch.summary || {}) }));
const profile = computed(() => summary.value.profile || null);
const missingTop = computed(() => Object.entries(profile.value?.missing || {}).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]).slice(0, 12));
let timer;
const refreshing = ref(false);
const refreshError = ref(false);
function refresh() {
    if (refreshing.value || document.hidden) return;
    refreshing.value = true;
    router.reload({ only: ['batch', 'rows'],
        onSuccess: () => { refreshError.value = false; },
        onError: () => { refreshError.value = true; },
        onFinish: () => { refreshing.value = false; },
    });
}
watch(() => props.batch.status, status => {
    clearInterval(timer);
    if (['analyzing', 'executing'].includes(status)) timer = setInterval(refresh, 6000);
}, { immediate: true });
onUnmounted(() => clearInterval(timer));
const actionLabels = { create: 'إنشاء ملف جديد', update: 'ربط بملف قائم (إكمال الفراغات فقط)', propose: 'إضافة مقترحة لمسودة الكشف', report: 'تقرير مطابقة' };
const outcomeLabels = computed(() => props.outcomes || {});
const outcomeTone = o => ({ failed: 'danger', held_for_review: 'warning', skipped: 'muted' }[o] || 'success');
</script>

<template>
    <AppLayout :title="`الاستيراد الذكي ${batch.reference_no}`">
        <div class="mx-auto max-w-7xl space-y-5">
            <header class="card space-y-3">
                <Link href="/orphans/import" class="text-sm text-blue-700 hover:underline">← الاستيراد الذكي</Link>
                <div class="flex items-start gap-3"><ImportIcon :size="36" /><div>
                    <h1 class="text-xl font-bold text-navy-800">الدفعة <span class="font-mono" dir="ltr">{{ batch.reference_no }}</span> — {{ batch.original_name }}</h1>
                    <p class="text-sm text-slate-600">{{ batch.purpose_label }}<span v-if="batch.campaign"> · الحملة <Link :href="`/orphans/campaigns/${batch.campaign.public_id}`" class="text-blue-700 hover:underline">{{ batch.campaign.reference_no }}</Link></span> · بصمة الملف <span class="font-mono" dir="ltr">{{ batch.sha_short }}</span></p></div>
                    <StatusBadge class="ms-auto" :label="batch.status_label" tone="info" /></div>
                <StatusStepper :steps="steps" compact />
                <p v-if="batch.error" class="alert-error">{{ batch.error }}</p>
            </header>

            <section v-if="['uploaded', 'mapped'].includes(batch.status)" class="card space-y-4">
                <h2 class="card-title">2. مطابقة الأعمدة</h2>
                <div class="flex flex-wrap items-end gap-3">
                    <label v-if="sheets.length" class="text-sm">ورقة العمل<select v-model="mapping.sheet" class="field mt-1" @change="router.get(`/orphans/import/${batch.public_id}`, { sheet: mapping.sheet, header_row: mapping.header_row }, { preserveState: false })">
                        <option v-for="s in sheets" :key="s" :value="s">{{ s }}</option></select></label>
                    <label class="text-sm">صف العناوين<input v-model.number="mapping.header_row" type="number" min="1" max="50" class="field mt-1 max-w-24" @change="router.get(`/orphans/import/${batch.public_id}`, { sheet: mapping.sheet, header_row: mapping.header_row })"></label>
                    <label v-if="profiles.length" class="text-sm">قالب مطابقة محفوظ<select class="field mt-1" @change="loadProfile(profiles.find(p => p.id == $event.target.value))"><option value="">—</option><option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
                </div>
                <p class="text-xs text-slate-500">اقتُرحت المطابقة عند تطابق العنوان تمامًا فقط؛ الأعمدة الغامضة تبقى دون ربط حتى تختارها. الأعمدة الصحية تحتاج صلاحية خاصة.</p>
                <div class="overflow-x-auto"><table class="data-table min-w-[720px]"><thead><tr><th scope="col">العمود في الملف</th><th scope="col">أمثلة من الصفوف</th><th scope="col">الحقل في النظام</th></tr></thead>
                    <tbody><tr v-for="(header, index) in preview.headers" :key="index"><td class="font-semibold">{{ header }}</td>
                        <td class="max-w-xs text-xs text-slate-600">{{ preview.samples.map(s => s[index]).filter(Boolean).slice(0, 3).join(' · ') }}</td>
                        <td><select v-model="mapping.mapping[index]" class="field" :aria-label="`حقل العمود ${header}`"><option value="">— لا يُستورد —</option>
                            <option v-for="f in fields" :key="f.key" :value="f.key" :disabled="f.sensitivity === 'health' && !abilities.health">{{ f.label }}{{ f.sensitivity === 'health' ? ' (صحي مقيد)' : '' }}{{ f.sensitivity === 'identity' ? ' (هوية)' : '' }}</option></select></td></tr></tbody></table></div>
                <label class="block text-sm">حفظ المطابقة كقالب باسم (اختياري)<input v-model="mapping.profile_name" class="field mt-1 max-w-sm" maxlength="150"></label>
                <p v-for="(e, k) in mapping.errors" :key="k" class="field-error">{{ e }}</p>
                <div class="flex gap-2"><button type="button" class="btn btn-primary" :disabled="mapping.processing" @click="mapping.transform(d => ({ ...d, mapping: Object.fromEntries(Object.entries(d.mapping).filter(([, v]) => v)) })).post(`/orphans/import/${batch.public_id}/mapping`)">حفظ المطابقة وبدء الفحص الذكي</button>
                    <button type="button" class="btn btn-secondary" @click="router.post(`/orphans/import/${batch.public_id}/cancel`)">إلغاء الدفعة</button></div>
            </section>

            <section v-if="batch.status === 'failed'" class="card space-y-2">
                <h2 class="card-title">توقف الفحص بخطأ</h2>
                <p class="text-sm">حُفظ {{ batch.rows_processed }} صف قبل التوقف. «الاستئناف» يدقق الصفوف المحفوظة ويكمل من أول صف ناقص، ولا يُدرج صفًا مرتين.</p>
                <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-primary" @click="router.post(`/orphans/import/${batch.public_id}/resume`, {}, { preserveScroll: true })">استئناف الفحص</button>
                    <button type="button" class="btn btn-secondary" @click="router.post(`/orphans/import/${batch.public_id}/cancel`)">إلغاء الدفعة</button></div>
            </section>

            <section v-if="['analyzing', 'executing'].includes(batch.status)" class="card space-y-2">
                <h2 class="card-title">{{ batch.status === 'analyzing' ? '3. تحليل البيانات قيد العمل' : '6. الإدراج في السجل قيد العمل' }}</h2>
                <p class="text-sm">تمت معالجة {{ batch.rows_processed }} صف{{ batch.rows_total ? ` من ${batch.rows_total}` : '' }}. يستمر العمل في الخلفية ويمكن الاستئناف بأمان.</p>
                <p v-if="refreshError" class="alert-error" role="alert">تعذر تحديث حالة الدفعة. ستُعاد المحاولة تلقائيًا. <button type="button" class="underline" :disabled="refreshing" @click="refresh">تحديث الحالة الآن</button></p>
                <button type="button" class="btn btn-secondary" @click="router.post(`/orphans/import/${batch.public_id}/step`, {}, { preserveScroll: true })">متابعة الآن</button>
            </section>

            <section v-if="['reviewing', 'executing', 'partially_imported', 'imported', 'completed'].includes(batch.status)" class="space-y-4">
                <div v-if="profile" class="card space-y-3"><h2 class="card-title">3. تحليل البيانات — جودة المصدر (ورقة «{{ profile.sheet }}»)</h2>
                    <dl class="grid gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-slate-600">صفوف المصدر</dt><dd class="text-xl font-bold tabular-nums">{{ profile.unique.rows }}</dd></div>
                        <div><dt class="text-slate-600">أطفال فريدون بالهوية</dt><dd class="text-xl font-bold tabular-nums">{{ profile.unique.children_with_id }}</dd><dd class="text-xs text-slate-500">+ {{ profile.unique.children_without_id }} صف دون هوية صحيحة · {{ profile.unique.duplicate_child_ids }} هوية مكررة</dd></div>
                        <div><dt class="text-slate-600">أوصياء فريدون</dt><dd class="text-xl font-bold tabular-nums">{{ profile.unique.guardians }}</dd><dd class="text-xs text-slate-500">{{ profile.unique.rows_without_guardian }} صف بلا وصي · {{ profile.unique.inferred }} مستنتج من الإخوة</dd></div>
                        <div><dt class="text-slate-600">أسر فريدة</dt><dd class="text-xl font-bold tabular-nums">{{ profile.unique.families }}</dd><dd class="text-xs text-slate-500">{{ profile.unique.families_strong }} بهويتي الأب والأم · {{ profile.unique.rows_without_family }} صف دون دليل أسرة</dd></div>
                    </dl>
                    <details v-if="missingTop.length" class="text-sm"><summary class="cursor-pointer font-semibold text-navy-800">الحقول الفارغة في المصدر</summary>
                        <ul class="mt-2 grid gap-1 sm:grid-cols-3"><li v-for="[f, n] in missingTop" :key="f">{{ fieldLabel(f) }}: <span class="tabular-nums">{{ n }}</span></li></ul></details>
                    <p class="text-xs text-slate-500">الأسرة تُستنتج من هويات الوالدين فقط، لا من الجوال أو الشارع أو اللقب. القيم الفارغة تبقى «غير معروفة» ولا تُفترض.</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-5"><button v-for="(label, key) in categories" :key="key" type="button" :class="['card text-start', category === key ? 'border-blue-400' : '']"
                    @click="go(category === key ? {} : { category: key })">
                    <p class="text-sm text-slate-600">{{ label }}</p><p class="mt-1 text-2xl font-bold text-navy-800 tabular-nums">{{ summary.categories[key] || 0 }}</p></button></div>
                <div class="flex flex-wrap gap-2 text-sm"><button v-for="(label, key) in filters" :key="key" type="button" :class="['rounded-full px-3 py-1', filter === key ? 'bg-blue-100 text-blue-900' : 'bg-slate-100']" @click="go(filter === key ? {} : { filter: key })">{{ label }}</button>
                    <button v-if="category || filter" type="button" class="rounded-full px-3 py-1 underline" @click="go({})">كل الصفوف</button></div>
                <div v-if="Object.keys(summary.issues).length" class="card"><h2 class="card-title">أنواع الملاحظات</h2>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm"><button v-for="(n, code) in summary.issues" :key="code" type="button" class="rounded-full bg-slate-100 px-3 py-1" @click="go({ issue: code })">{{ issueLabels[code] || code }}: {{ n }}</button></div>
                    <a :href="`/orphans/import/${batch.public_id}/issues.csv`" class="btn btn-sm btn-secondary mt-3">تنزيل تقرير الملاحظات (CSV آمن)</a></div>

                <div v-if="reviewable" class="card space-y-3"><h2 class="card-title">5. المعاينة والاعتماد المبدئي</h2>
                    <p class="text-sm">جاهز للإدراج الآن: <strong class="tabular-nums">{{ summary.preview.rows || 0 }}</strong> صف
                        <span v-if="registry">({{ summary.preview.families || 0 }} أسرة + {{ summary.preview.single_rows || 0 }} طفل منفرد، {{ summary.preview.guardians || 0 }} وصي، {{ summary.preview.inferred || 0 }} بوصي مستنتج)</span>.
                        <span v-for="(n, a) in summary.actions" :key="a" class="ms-2">{{ actionLabels[a] || a }}: <strong>{{ n }}</strong></span></p>
                    <p class="text-sm">لا يُدرج: المرفوض ({{ summary.categories.rejected || 0 }})، والمكرر ({{ summary.categories.duplicate || 0 }})، و{{ summary.awaiting_review || 0 }} صف «يحتاج مراجعة» أو «تعارض» حتى يُقبل صراحة أو يُصحح.</p>
                    <p v-if="registry" class="alert-info text-sm">يُنشأ كل يتيم في «إدارة الأيتام والأوصياء» بحالة «معتمد مبدئيًا»، ويُعاد استخدام الأب والأم والوصي، وتُسجل وصاية «مُدّعاة» مستقلة لكل طفل. هذا ليس اعتمادًا قانونيًا: لا كفالة ولا ترشيح حملات ولا بوابة حتى تُعتمد الوصاية ثم الملف.</p>
                    <p v-else class="text-xs text-slate-500">الكشوف تصبح مسودات أو طلبات تعديل يعتمدها المدير التنفيذي؛ لا تُعدّ أي استفادة مُسلّمة.</p>
                    <label class="flex items-start gap-2 text-sm"><input v-model="confirmCommit" type="checkbox" class="mt-1"> أفهم أن الاعتماد المبدئي ليس اعتمادًا قانونيًا للوصاية ولا يمنح أي حق في البوابة.</label>
                    <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-primary" :disabled="!summary.preview.rows" @click="commit(false)">{{ registry ? 'اعتماد مبدئي وإدراج في السجل' : 'اعتماد التنفيذ' }}</button>
                        <button v-if="registry" type="button" class="btn btn-secondary" :disabled="!selected.length" @click="commit(true)">اعتماد مبدئي للمحدد وأسرهم ({{ selected.length }})</button>
                        <button v-if="batch.status === 'reviewing'" type="button" class="btn btn-secondary" @click="reanalyze">إعادة الفحص</button>
                        <button type="button" class="btn btn-secondary" @click="router.post(`/orphans/import/${batch.public_id}/cancel`)">{{ batch.status === 'partially_imported' ? 'إيقاف الدفعة' : 'إلغاء الدفعة' }}</button></div>
                    <details v-if="policy" class="text-sm"><summary class="cursor-pointer text-navy-800">سياسة التصنيف المطبقة</summary>
                        <ul class="mt-2 grid gap-1 sm:grid-cols-2"><li v-for="p in policy.configurable" :key="p.key">{{ p.label }} — {{ policy.levels[p.level] }}</li>
                            <li v-for="f in policy.fixed" :key="f.code">{{ f.label }} — {{ f.level }} (ثابت)</li></ul>
                        <p class="mt-1 text-xs text-slate-500">يغيّرها رئيس القسم من إعدادات الأيتام.</p></details></div>

                <div v-if="backlog?.rows?.length" class="card space-y-3"><h2 class="card-title">متابعة المراجعة</h2>
                    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-slate-600"><th class="text-start">التصنيف</th><th>الكل</th><th>بلا قرار</th><th>مقبول</th><th>متخطى</th><th>أُدرج</th><th>معلّق</th><th>متعذر</th></tr></thead>
                        <tbody class="tabular-nums"><tr v-for="b in backlog.rows" :key="b.category" class="border-t border-slate-100 text-center"><td class="py-1 text-start">{{ b.label }}</td><td>{{ b.total }}</td><td>{{ b.undecided }}</td><td>{{ b.accepted }}</td><td>{{ b.skipped }}</td><td>{{ b.imported }}</td><td>{{ b.held }}</td><td>{{ b.failed }}</td></tr></tbody></table></div>
                    <div v-if="backlog.failures.length"><h3 class="text-sm font-semibold text-navy-800">أسباب التعذر</h3>
                        <ul class="list-disc ps-5 text-sm"><li v-for="f in backlog.failures" :key="f.cause"><strong class="tabular-nums">{{ f.n }}</strong> — {{ f.cause }}</li></ul>
                        <p class="text-xs text-slate-500">الصف المتعذر لا يُكتب فوق أحد: افتحه بمرشح «لم يُدرج (معلّق أو فشل)» واختر الشخص الموجود أو صحّح الهوية، ثم أعد الإدراج.</p></div></div>

                <div v-if="canCheckOptions && !unmappedOptions" class="card flex flex-wrap items-center gap-3 text-sm">
                    <span>فحص كلمات القوائم في الملف (صلة القرابة، الحالة الاجتماعية، التعليم، الصحة…) مقابل «ثوابت النظام».</span>
                    <button type="button" class="btn btn-sm btn-secondary" :disabled="checkingOptions" @click="checkOptions">{{ checkingOptions ? 'جارٍ الفحص…' : 'عرض القيم غير المطابقة' }}</button>
                </div>
                <p v-else-if="unmappedOptions && !unmappedOptions.length" class="alert-info">كل كلمات القوائم في الملف تطابق قيمًا أو أسماء بديلة في «ثوابت النظام».</p>
                <details v-if="unmappedOptions?.length" class="card space-y-2" open><summary class="card-title cursor-pointer">قيم قوائم غير مطابقة ({{ unmappedOptions.length }})</summary>
                    <p class="text-sm text-slate-600">كلمات وردت في الملف ولا تطابق أي قيمة أو اسم بديل في «ثوابت النظام». تُحفظ كما وردت ولا تُخمَّن. لحلها: أضفها اسمًا بديلًا لقيمة موجودة أو قيمة جديدة من «ثوابت النظام»، ثم أعد الفحص.</p>
                    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-slate-600"><th class="text-start">الحقل</th><th class="text-start">القيمة كما وردت</th><th>عدد الصفوف</th></tr></thead>
                        <tbody><tr v-for="u in unmappedOptions" :key="`${u.field}|${u.value}`" class="border-t border-slate-100"><td class="py-1">{{ u.label }}</td><td>{{ u.value }}</td><td class="text-center tabular-nums">{{ u.rows }}</td></tr></tbody></table></div>
                </details>

                <div v-if="Object.keys(summary.executed).length" class="card space-y-2"><h2 class="card-title">6. نتائج الإدراج</h2>
                    <p class="text-sm"><span v-for="(n, o) in summary.executed" :key="o" class="me-4">{{ outcomeLabels[o] || o }}: <strong class="tabular-nums">{{ n }}</strong></span></p>
                    <p v-if="registry" class="text-sm text-slate-600">سجلات رسمية مرتبطة: {{ summary.official.orphans || 0 }} يتيم · {{ summary.official.guardians || 0 }} وصي · {{ summary.official.households || 0 }} أسرة.
                        <Link href="/orphans/registry?status=provisional" class="text-blue-700 hover:underline">عرض «المعتمد مبدئيًا» في السجل</Link></p></div>

                <section v-if="rows" class="card p-0">
                    <div v-if="reviewable" class="flex flex-wrap items-end gap-2 border-b border-slate-200 p-4"><span class="text-sm">المحدد: {{ selected.length }}</span>
                        <label class="min-w-56 flex-1 text-sm"><span class="field-label">سبب القبول (إلزامي للقبول)</span>
                            <input v-model="decisionReason" class="field" maxlength="500" placeholder="مثال: تحققت من تطابق اسم الأم في وثيقة الأسرة" /></label>
                        <button type="button" class="btn btn-sm btn-secondary" :disabled="!selected.length" @click="decide('accept')">قبول المحدد (للمراجعة/التعارض)</button>
                        <button type="button" class="btn btn-sm btn-secondary" :disabled="!selected.length" @click="decide('skip')">تخطي المحدد</button></div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="r in rows.data" :key="r.id" class="space-y-1 p-4 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <input v-if="reviewable && !r.orphan_public_id" v-model="selected" type="checkbox" :value="r.id" :aria-label="`تحديد الصف ${r.row_no}`">
                                <span class="font-mono text-xs" dir="ltr">#{{ r.row_no }}</span><StatusBadge :label="categories[r.category]" :tone="tones[r.category]" />
                                <span class="font-semibold">{{ r.normalized.child_full_name || r.normalized.full_name || [r.normalized.child_first_name, r.normalized.child_family_name].filter(Boolean).join(' ') || '—' }}</span>
                                <span v-if="r.normalized.child_id" class="font-mono text-xs" dir="ltr">{{ r.normalized.child_id }}</span>
                                <span v-if="r.normalized.guardian_full_name" class="text-slate-600">الوصي: {{ r.normalized.guardian_full_name }}</span>
                                <span class="text-slate-500">{{ actionLabels[r.proposed_action] }}</span>
                                <StatusBadge v-if="r.decision" :label="r.decision === 'accept' ? 'مقبول' : 'متخطى'" tone="muted" /><StatusBadge v-if="r.corrected" label="مصحح" tone="info" />
                                <StatusBadge v-if="r.outcome" :label="outcomeLabels[r.outcome]" :tone="outcomeTone(r.outcome)" />
                                <Link v-if="r.orphan_public_id" :href="`/orphans/registry/orphans/${r.orphan_public_id}`" class="text-blue-700 hover:underline">{{ r.orphan_reference }}<span v-if="r.orphan_status === 'provisional'"> · معتمد مبدئيًا</span></Link>
                                <span v-if="r.family_size > 1" class="text-xs text-slate-500">أسرة: {{ r.family_size }} صفوف</span>
                                <StatusBadge v-if="r.inference" :label="r.inference.confidence === 'high' ? 'وصي مستنتج من الإخوة' : 'وصي مقترح من الإخوة'" tone="info" />
                                <span class="ms-auto flex gap-2"><button type="button" class="btn btn-sm btn-secondary" @click="openRow(r)">التفاصيل</button>
                                    <button v-if="reviewable && !r.orphan_public_id" type="button" class="btn btn-sm btn-secondary" @click="startEdit(r)">تصحيح</button></span></div>
                            <ul class="ms-6 list-disc text-slate-700"><li v-for="(i, k) in r.issues" :key="k" :class="[i.severity === 'error' ? 'text-red-700' : '', i.resolution && i.resolution !== 'open' ? 'line-through text-slate-400' : '']">{{ i.message }}</li></ul>
                            <p v-if="r.matches?.child" class="ms-6 text-slate-600">مطابق للسجل: {{ r.matches.child.name }} {{ r.matches.child.orphan || '' }}</p>
                            <p v-if="r.diff && Object.keys(r.diff).length" class="ms-6 text-slate-600">الفروقات: <span v-for="(d, f) in r.diff" :key="f" class="me-2">{{ f }}: {{ d.current ?? 'فارغ' }} ← {{ d.proposed }}{{ d.applies ? '' : ' (لا يُطبق، القيمة موجودة)' }}</span></p>
                            <p v-if="r.message" class="ms-6 text-slate-600">{{ r.message }}</p>
                            <form v-if="editing === r.id" class="ms-6 grid gap-2 rounded-xl border border-blue-200 bg-blue-50/40 p-3 sm:grid-cols-3" @submit.prevent="correction.transform(d => ({ fields: Object.fromEntries(Object.entries(d.fields).filter(([, v]) => v !== '')) })).post(`/orphans/import/${batch.public_id}/rows/${r.id}/correct`, { preserveScroll: true, onSuccess: () => { editing = null; } })">
                                <FormErrorSummary :errors="correction.errors" class="sm:col-span-3" />
                                <label v-for="(v, key) in correction.fields" :key="key" class="text-xs">{{ fieldLabel(key) }}<input v-model="correction.fields[key]" class="field mt-1"></label>
                                <p class="text-xs text-slate-500 sm:col-span-3">اترك الحقل فارغًا للإبقاء على قيمته. التصحيح يمس الصف المرحلي فقط.</p>
                                <div class="flex gap-2 sm:col-span-3"><button type="submit" class="btn btn-sm btn-primary" :disabled="correction.processing">حفظ وإعادة الفحص</button><button type="button" class="btn btn-sm btn-secondary" @click="editing = null">إلغاء</button></div>
                            </form>
                        </li>
                    </ul>
                    <Pagination :page="rows" />
                </section>
            </section>

            <ChangeHistory subject-type="import_batches" :subject-id="batch.public_id" />

            <aside v-if="drawer" class="fixed inset-y-0 start-0 z-40 w-full max-w-md overflow-y-auto border-e border-slate-200 bg-white p-5 shadow-xl" role="dialog" aria-modal="true" :aria-label="`تفاصيل الصف ${drawer.row_no}`">
                <div class="flex items-center justify-between"><h2 class="card-title">الصف {{ drawer.row_no }}<span v-if="drawer.sheet" class="text-sm font-normal text-slate-500"> — ورقة «{{ drawer.sheet }}»</span></h2>
                    <button type="button" class="btn btn-sm btn-secondary" @click="drawer = null">إغلاق</button></div>
                <p v-if="drawerError" class="alert-error mt-3">{{ drawerError }}</p>
                <p v-else-if="drawer.loading" class="mt-3 text-sm text-slate-500" role="status">جارٍ التحميل…</p>
                <div v-else class="mt-3 space-y-4 text-sm">
                    <p>التصنيف: <strong>{{ drawer.category }}</strong> · دليل الأسرة: {{ drawer.family_basis }}</p>
                    <section><h3 class="font-semibold text-navy-800">اليتيم والأسرة كما في الملف</h3>
                        <p>{{ drawer.normalized.child_full_name }} <span class="font-mono text-xs" dir="ltr">{{ drawer.normalized.child_id }}</span> · الميلاد {{ drawer.normalized.child_dob || 'غير مذكور' }}</p>
                        <p>الأب: {{ drawer.normalized.father_full_name || 'غير مذكور' }} <span class="font-mono text-xs" dir="ltr">{{ drawer.normalized.father_id }}</span></p>
                        <p>الأم: {{ drawer.normalized.mother_full_name || 'غير مذكورة' }} <span class="font-mono text-xs" dir="ltr">{{ drawer.normalized.mother_id }}</span></p>
                        <p>الوصي: {{ drawer.normalized.guardian_full_name || 'غير مذكور' }} <span class="font-mono text-xs" dir="ltr">{{ drawer.normalized.guardian_id }}</span> · الصلة: {{ drawer.normalized.guardian_relation || '—' }}</p></section>
                    <section v-if="drawer.inference" class="rounded-lg bg-blue-50 p-3"><h3 class="font-semibold text-navy-800">وصي {{ drawer.inference.confidence === 'high' ? 'مستنتج' : 'مقترح' }} من الإخوة</h3>
                        <p>{{ drawer.inference.guardian_full_name }} <span class="font-mono text-xs" dir="ltr">{{ drawer.inference.guardian_id }}</span> · {{ drawer.inference.guardian_relation }}</p>
                        <p class="text-slate-600">{{ drawer.inference.rationale }}</p></section>
                    <section v-if="drawer.matches"><h3 class="font-semibold text-navy-800">مطابقات في السجل</h3>
                        <p v-if="drawer.matches.child">اليتيم: {{ drawer.matches.child.name }} {{ drawer.matches.child.orphan || '(بدور آخر)' }}</p>
                        <p v-if="drawer.matches.guardian">الوصي: {{ drawer.matches.guardian.name }}</p></section>
                    <section v-if="drawer.issues.length"><h3 class="font-semibold text-navy-800">الملاحظات</h3>
                        <ul class="list-disc ps-5"><li v-for="(i, k) in drawer.issues" :key="k" :class="i.resolution && i.resolution !== 'open' ? 'text-slate-400 line-through' : ''"><strong>{{ i.label }}:</strong> {{ i.message }}</li></ul></section>
                    <section v-if="drawer.family.length > 1"><h3 class="font-semibold text-navy-800">الإخوة في الملف ({{ drawer.family.length }})</h3>
                        <ul class="space-y-1"><li v-for="m in drawer.family" :key="m.id">#{{ m.row_no }} {{ m.child }} — {{ m.category }}<span v-if="m.outcome"> · {{ m.outcome }}</span><span class="text-slate-500"> · الوصي: {{ m.guardian || '—' }}</span></li></ul>
                        <div v-if="reviewable && !drawer.result" class="mt-2 space-y-2 rounded-lg border border-slate-200 p-3">
                            <label class="block"><span class="field-label">قرار لصفوف الأسرة — السبب</span><input v-model="decisionReason" class="field" maxlength="500" /></label>
                            <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-secondary" @click="decide('accept', drawer.family.map(m => m.id))">قبول صفوف الأسرة</button>
                                <button type="button" class="btn btn-sm btn-secondary" @click="decide('skip', drawer.family.map(m => m.id))">تخطي صفوف الأسرة</button></div>
                            <p class="text-xs text-slate-500">تُستبعد تلقائيًا الصفوف ذات التعارض أو ملاحظات الهوية والوصاية؛ تُقبل صفًا صفًا.</p></div></section>
                    <section v-if="drawer.result"><h3 class="font-semibold text-navy-800">النتيجة في السجل</h3>
                        <p>{{ drawer.result.outcome_label }}<span v-if="drawer.result.message"> — {{ drawer.result.message }}</span></p>
                        <p v-if="drawer.result.orphan_public_id"><Link :href="`/orphans/registry/orphans/${drawer.result.orphan_public_id}`" class="text-blue-700 hover:underline">ملف اليتيم {{ drawer.result.orphan_reference }}</Link>
                            <span v-if="drawer.result.orphan_status === 'provisional'"> (معتمد مبدئيًا)</span></p>
                        <p v-if="drawer.result.guardian_public_id"><Link :href="`/orphans/registry/guardians/${drawer.result.guardian_public_id}`" class="text-blue-700 hover:underline">الوصي {{ drawer.result.guardian_reference }}</Link>
                            <span v-if="drawer.result.household_reference"> · الأسرة {{ drawer.result.household_reference }}</span></p></section>
                    <p v-else-if="reviewable" class="text-slate-600">الإجراء المتاح: صحّح الصف (مثلًا أضف الوصي)، أو اقبله إن كان «يحتاج مراجعة»، أو تخطَّه.</p>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
