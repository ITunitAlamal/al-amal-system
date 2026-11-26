<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

/**
 * Review of one import batch: column mapping (every column mapped or explicitly ignored), reference values, batch
 * options, validation and per-row actions, then the confirmed commit, the reconciliation and the undo.
 */
const props = defineProps({
    batch: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    choices: { type: Object, default: () => ({}) },
    references: { type: Object, default: () => ({}) },
    rows: { type: Object, default: () => ({ data: [], total: 0, page: 1, pages: 1 }) },
    filter: { type: String, default: 'all' },
    issueCatalog: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const base = computed(() => `/admin/employee-imports/${props.batch.id}`);
const flash = computed(() => page.props.flash?.success || '');
const errors = computed(() => page.props.errors || {});
const summary = computed(() => props.batch.summary || {});
const statuses = { staged: ['بانتظار ربط الأعمدة', 'warning'], validated: ['جاهزة للمراجعة والتنفيذ', 'info'], committing: ['قيد التنفيذ', 'info'], committed: ['نُفذت', 'success'],
    failed: ['توقفت؛ يمكن المتابعة بعد إعادة التحقق', 'danger'], cancelled: ['ملغاة', 'muted'], reverted: ['تُراجع عنها', 'muted'] };
const rowStatus = { valid: ['سليم', 'success'], warning: ['تنبيه', 'warning'], error: ['خطأ', 'danger'], pending: ['لم يُفحص', 'muted'] };
const actionLabels = { insert: 'إنشاء ملف جديد', skip: 'تخطي (الموجود يبقى كما هو)', update: 'تحديث حقول مختارة', reject: 'رفض الصف' };
const resultLabels = { pending: 'لم يُنفذ', inserted: 'أُنشئ', updated: 'حُدّث', skipped: 'تُخطي', rejected: 'رُفض', failed: 'تعذر', reverted: 'تُراجع عنه', revert_blocked: 'لم يُتراجع (عُدّل بعد الاستيراد)' };
const levelTone = { error: 'text-red-700', field: 'text-gold-800', warning: 'text-gold-800', info: 'text-slate-500' };
const matchKeys = { nid: 'رقم الهوية', emp: 'الرقم الوظيفي', att: 'رقم البصمة' };
const matchLabel = type => String(type || '').split('+').map(key => matchKeys[key] || key).join(' + ');
const fieldLabel = key => props.fields.find(f => f.key === key)?.ar || key;

/* 1. Columns */
const columnMap = reactive(Object.fromEntries(props.columns.map(c => [c.column, c.field ?? ''])));
watch(() => props.columns, list => list.forEach(c => { columnMap[c.column] = c.field ?? ''; }));
const duplicates = computed(() => {
    const seen = {};
    Object.values(columnMap).filter(f => f && f !== 'ignore').forEach(f => { seen[f] = (seen[f] || 0) + 1; });
    return Object.keys(seen).filter(f => seen[f] > 1);
});
const unresolved = computed(() => Object.values(columnMap).filter(f => !f).length);
function saveColumns() {
    router.put(`${base.value}/columns`, { map: Object.fromEntries(Object.entries(columnMap).map(([column, field]) => [column, field || null])) }, { preserveScroll: true });
}

/* 2. Reference values */
const decisions = reactive({});
const remember = reactive({});
function resetDecisions() {
    Object.entries(props.references || {}).forEach(([field, group]) => {
        decisions[field] = Object.fromEntries(group.values.map(v => [v.key, '']));
        remember[field] = remember[field] ?? true;
    });
}
resetDecisions();
watch(() => props.references, resetDecisions);
const howLabel = { decision: ['قرار في هذه الدفعة', 'info'], alias: ['قرار محفوظ سابقًا', 'info'], exact: ['مطابقة تامة', 'success'], none: ['غير مربوط', 'warning'] };
const optionLabel = (field, id) => props.references[field]?.options.find(o => o.id === id)?.label || '';
function saveValues(field) {
    const choices = Object.fromEntries(Object.entries(decisions[field] || {}).filter(([, v]) => v !== '').map(([key, v]) => [key, v === 'none' ? null : Number(v)]));
    if (!Object.keys(choices).length) return;
    router.put(`${base.value}/values`, { field, choices, remember: Boolean(remember[field]) }, { preserveScroll: true });
}
function createValue(field, value) {
    const name = window.prompt('اسم القيمة الجديدة في ثوابت النظام:', value.source);
    if (name && name.trim()) router.post(`${base.value}/values/create`, { field, key: value.key, name: name.trim() }, { preserveScroll: true });
}

/* 3. Options */
const optionsForm = useForm({
    as_of_date: props.options.as_of_date || '', currency: props.options.currency || '', percent_rule: props.options.percent_rule || 'review',
    zero_salary_unknown: Boolean(props.options.zero_salary_unknown), employee_number_from_attendance: Boolean(props.options.employee_number_from_attendance),
    leave_type_value_id: props.options.leave_type_value_id || '', post_opening_balances: Boolean(props.options.post_opening_balances),
});
function saveOptions() {
    optionsForm.transform(data => ({ ...data, as_of_date: data.as_of_date || null, currency: data.currency || null, leave_type_value_id: data.leave_type_value_id || null }))
        .put(`${base.value}/options`, { preserveScroll: true });
}
const today = new Date().toISOString().slice(0, 10);

/* 4. Preview */
const filters = { all: 'الكل', valid: 'سليم', warning: 'تنبيه', error: 'خطأ', insert: 'إنشاء', update: 'تحديث', skip: 'تخطي', reject: 'رفض', matched: 'مطابق لموجود' };
function goto(filter, pageNumber = 1) {
    router.get(base.value, { filter, page: pageNumber }, { preserveScroll: true, preserveState: true, only: ['rows', 'filter'] });
}
const expanded = ref(null);
const rowGroups = reactive({});
watch(() => props.rows, list => list.data.forEach(r => { rowGroups[r.id] = [...(r.groups || [])]; }), { immediate: true });
function setAction(row, action) {
    if (action === 'update') { expanded.value = row.id; return; }
    router.put(`${base.value}/rows/${row.id}`, { action, groups: [] }, { preserveScroll: true, preserveState: true });
}
function applyUpdate(row) {
    router.put(`${base.value}/rows/${row.id}`, { action: 'update', groups: rowGroups[row.id] || [] }, { preserveScroll: true, preserveState: true });
}
const valueLabels = { full_name: 'الاسم كما ورد', first_name_ar: 'الاسم الأول (مقترح)', father_name_ar: 'الأب (مقترح)', grandfather_name_ar: 'الجد (مقترح)', family_name_ar: 'العائلة (مقترح)',
    phone: 'الجوال الأساسي', alternative_phone: 'الجوال الثانوي', landline_phone: 'الهاتف الأرضي', address: 'العنوان', bank_account: 'الحساب البنكي', leave_consistency: 'اتساق الإجازات' };
const valueLabel = key => valueLabels[key] || fieldLabel(key.replace(/_source$/, ''));

/* 5. Commit, undo */
const commitForm = useForm({ confirm: false });
const revertForm = useForm({ reason: '' });
const confirmCancel = () => { if (window.confirm('إلغاء الدفعة دون أي أثر على الملفات؟')) router.post(`${base.value}/cancel`); };
const issueEntries = computed(() => Object.entries(summary.value.issues || {}).sort((a, b) => b[1] - a[1]));
</script>

<template>
    <AppLayout :title="`دفعة استيراد #${batch.id}`">
        <div class="mx-auto max-w-7xl space-y-5">
            <Link href="/admin/employee-imports" class="text-sm font-semibold text-blue-700 hover:underline">→ دفعات الاستيراد</Link>
            <PageHeader eyebrow="استيراد الموظفين" :title="`الدفعة #${batch.id} — ${batch.file_name}`"
                :description="`الورقة «${batch.sheet_name}» · صف العناوين ${batch.header_row} · ${batch.data_rows} صف بيانات`">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <StatusBadge :label="(statuses[batch.status] || [batch.status])[0]" :tone="(statuses[batch.status] || [null, 'muted'])[1]" />
                        <span v-if="batch.same_file?.length" class="text-gold-800">الملف نفسه استُورد سابقًا في الدفعة #{{ batch.same_file.join('، #') }}؛ الصفوف المطابقة تُتخطى افتراضيًا.</span>
                    </div>
                </template>
                <a :href="`${base}/issues.csv`" class="btn btn-secondary">تقرير المشكلات (CSV)</a>
                <button v-if="can.edit && batch.status !== 'failed'" type="button" class="btn btn-secondary" @click="confirmCancel">إلغاء الدفعة</button>
            </PageHeader>
            <p v-if="flash" class="alert-success">{{ flash }}</p>
            <p v-if="batch.failure" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ batch.failure }}</p>
            <p v-for="(message, key) in errors" :key="key" class="field-error">{{ message }}</p>

            <!-- 1. Columns -->
            <section class="card space-y-3" id="columns">
                <h2 class="card-title">1. ربط الأعمدة ({{ columns.length }} عمودًا)</h2>
                <p class="text-sm text-slate-600">كل عمود يُربط بحقل أو يُتجاهل صراحة؛ لا يُهمل عمود دون قرار. «م» مؤشر الصف في الملف ولا يُعد معرّفًا للموظف.</p>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[720px]">
                        <thead><tr><th>العمود</th><th>العنوان في الملف</th><th>الحقل في AIMS</th><th>يُحفظ في</th></tr></thead>
                        <tbody>
                            <tr v-for="c in columns" :key="c.column" :class="!columnMap[c.column] ? 'bg-gold-50' : ''">
                                <td class="font-mono" dir="ltr">{{ c.letter }}</td>
                                <td class="font-semibold">{{ c.header }}</td>
                                <td>
                                    <select v-model="columnMap[c.column]" class="field" :disabled="!can.edit" :aria-label="`حقل العمود ${c.letter}`">
                                        <option value="">— لم يُحدد —</option>
                                        <option v-for="f in fields" :key="f.key" :value="f.key">{{ f.ar }}{{ f.confidential ? ' (سري)' : '' }}</option>
                                        <option value="ignore">تجاهل هذا العمود صراحة</option>
                                    </select>
                                    <p v-if="c.suggested && c.suggested !== columnMap[c.column]" class="text-xs text-slate-500">المقترح: {{ fieldLabel(c.suggested) }}</p>
                                </td>
                                <td class="text-xs text-slate-600">{{ columnMap[c.column] === 'ignore' ? 'لا شيء (تجاهل موثّق في الدفعة)' : (fields.find(f => f.key === columnMap[c.column])?.target || '—') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="duplicates.length" class="field-error">حقول مربوطة بأكثر من عمود: {{ duplicates.map(fieldLabel).join('، ') }}</p>
                <p v-if="unresolved" class="text-sm text-gold-800">{{ unresolved }} عمود بلا قرار؛ لا يمكن التنفيذ قبل حسمها.</p>
                <button v-if="can.edit" type="button" class="btn btn-primary btn-sm" :disabled="duplicates.length > 0" @click="saveColumns">حفظ الربط وإعادة التحقق</button>
            </section>

            <!-- 2. Reference values -->
            <section class="card space-y-4" id="values">
                <h2 class="card-title">2. القيم المرجعية</h2>
                <p class="text-sm text-slate-600">كل قيمة نصية تُربط بقيمة معتمدة أو تُترك «بدون ربط» فيُحفظ نصها كما ورد ويبقى الحقل المعتمد فارغًا. الأقسام تُربط بأقسام الهيكل القائمة فقط ولا تُنشأ من هنا؛ والمدارس والوحدات ليست أقسامًا ما لم تكن في الهيكل.</p>
                <p v-if="!Object.keys(references).length" class="text-sm text-slate-500">لا توجد أعمدة مرجعية مربوطة.</p>
                <details v-for="(group, field) in references" :key="field" class="rounded-xl border border-slate-200 p-3" :open="group.values.some(v => v.how === 'none')">
                    <summary class="cursor-pointer font-semibold text-navy-800">{{ group.label }} — {{ group.values.length }} قيمة، غير مربوطة: {{ group.values.filter(v => v.how === 'none').length }}</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="data-table min-w-[720px]">
                            <thead><tr><th>القيمة كما وردت</th><th>الصفوف</th><th>الربط الحالي</th><th>قرارك</th></tr></thead>
                            <tbody>
                                <tr v-for="v in group.values" :key="v.key">
                                    <td class="font-semibold">{{ v.source }}</td>
                                    <td>{{ v.count }}</td>
                                    <td class="text-xs"><StatusBadge :label="howLabel[v.how][0]" :tone="howLabel[v.how][1]" /> {{ v.target_id ? optionLabel(field, v.target_id) : (v.how === 'none' ? '' : 'بدون ربط') }}</td>
                                    <td>
                                        <select v-model="decisions[field][v.key]" class="field" :disabled="!can.edit" :aria-label="`ربط ${v.source}`">
                                            <option value="">— إبقاء الربط الحالي —</option>
                                            <option value="none">بدون ربط (حفظ النص فقط)</option>
                                            <option v-for="o in group.options" :key="o.id" :value="o.id">{{ o.label }}</option>
                                        </select>
                                        <button v-if="group.can_create && can.edit" type="button" class="mt-1 text-xs font-semibold text-blue-700 hover:underline" @click="createValue(field, v)">إضافة كقيمة جديدة في ثوابت النظام</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="can.edit" class="mt-2 flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2 text-sm"><input v-model="remember[field]" type="checkbox" /> تذكّر هذه القرارات للاستيرادات اللاحقة</label>
                        <button type="button" class="btn btn-primary btn-sm" @click="saveValues(field)">حفظ قرارات «{{ group.label }}»</button>
                    </div>
                    <p v-if="!group.options.length" class="mt-2 text-xs text-gold-800">لا توجد قيم معتمدة لهذا الحقل بعد{{ field === 'department' ? '؛ تُضاف الأقسام من «الهيكل الإداري».' : (field === 'job_title' ? '؛ تُضاف المسميات من «الفئات والمسميات الوظيفية».' : '.') }}</p>
                </details>
            </section>

            <!-- 3. Options -->
            <section class="card space-y-3" id="options">
                <h2 class="card-title">3. خيارات الدفعة</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="text-sm">تاريخ الأساس (تصح عنده الرواتب والأرصدة والحسابات) *
                        <input v-model="optionsForm.as_of_date" type="date" :max="today" class="field mt-1" :disabled="!can.edit" />
                        <span class="mt-1 block text-xs text-slate-500">الملف لا يذكر تاريخًا؛ حدده من مصدر الكشف. يُوثّق في كل لقطة وسجل.</span>
                    </label>
                    <label class="text-sm">عملة الرواتب *
                        <select v-model="optionsForm.currency" class="field mt-1" :disabled="!can.edit"><option value="">اختر</option><option v-for="c in choices.currencies" :key="c" :value="c">{{ c }}</option></select>
                    </label>
                    <label class="text-sm">نوع الإجازة الذي تخصه أعمدة الأرصدة
                        <select v-model="optionsForm.leave_type_value_id" class="field mt-1" :disabled="!can.edit"><option value="">اختر</option><option v-for="t in choices.leaveTypes" :key="t.id" :value="t.id">{{ t.name_ar }}</option></select>
                    </label>
                </div>
                <fieldset class="rounded-xl border border-slate-200 p-3 text-sm" :disabled="!can.edit">
                    <legend class="px-1 font-semibold">النسب بين 0 و1 (مثل 0.15) في أعمدة النسب</legend>
                    <label class="flex items-start gap-2"><input v-model="optionsForm.percent_rule" type="radio" value="review" class="mt-1" /> مراجعة يدوية: تُترك فارغة مع تنبيه (الافتراضي)</label>
                    <label class="flex items-start gap-2"><input v-model="optionsForm.percent_rule" type="radio" value="fraction" class="mt-1" /> كسر عشري: 0.15 تعني 15% (تُضرب ×100، وتُسجل كتحويل)</label>
                    <label class="flex items-start gap-2"><input v-model="optionsForm.percent_rule" type="radio" value="literal" class="mt-1" /> كما هي: 0.15 تعني 0.15%</label>
                    <p class="mt-1 text-xs text-slate-500">القيم الأكبر من 1 تُعد نسبة مئوية كما هي، والخلايا المنسقة نسبة مئوية في Excel تُحوّل تلقائيًا. القيمة الأصلية تبقى في سجل الدفعة.</p>
                </fieldset>
                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2"><input v-model="optionsForm.zero_salary_unknown" type="checkbox" class="mt-1" :disabled="!can.edit" /> الراتب الأساسي أو الجملة = 0 تعني «غير معروف» (تُحفظ فارغة لا صفرًا)</label>
                    <label class="flex items-start gap-2"><input v-model="optionsForm.employee_number_from_attendance" type="checkbox" class="mt-1" :disabled="!can.edit" /> اعتماد رقم جهاز البصمة رقمًا وظيفيًا للملفات الجديدة (يُحفظ رقم البصمة أيضًا في حقله المستقل)</label>
                    <label class="flex items-start gap-2"><input v-model="optionsForm.post_opening_balances" type="checkbox" class="mt-1" :disabled="!can.edit" /> تسجيل «رصيد الإجازات حتى تاريخه» رصيدًا افتتاحيًا للسنة الحالية — فقط إن كان تاريخ الأساس في السنة الحالية، والرصيد غير سالب ومتسق، ولا يوجد رصيد افتتاحي أو حركات لنفس السنة</label>
                </div>
                <p v-for="(message, key) in optionsForm.errors" :key="key" class="field-error">{{ message }}</p>
                <button v-if="can.edit" type="button" class="btn btn-primary btn-sm" :disabled="optionsForm.processing" @click="saveOptions">حفظ الخيارات وإعادة التحقق</button>
            </section>

            <!-- 4. Preview -->
            <section class="card space-y-4" id="preview">
                <h2 class="card-title">4. التحقق والمعاينة</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3 text-sm"><p class="text-slate-500">حالة الصفوف</p><p class="font-semibold">سليم {{ summary.status?.valid ?? 0 }} · تنبيه {{ summary.status?.warning ?? 0 }} · خطأ {{ summary.status?.error ?? 0 }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3 text-sm"><p class="text-slate-500">الإجراءات</p><p class="font-semibold">إنشاء {{ summary.actions?.insert ?? 0 }} · تحديث {{ summary.actions?.update ?? 0 }} · تخطي {{ summary.actions?.skip ?? 0 }} · رفض {{ summary.actions?.reject ?? 0 }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3 text-sm"><p class="text-slate-500">مطابق لملفات قائمة</p><p class="font-semibold">{{ summary.matched ?? 0 }}</p></div>
                    <div class="rounded-xl bg-gold-50 p-3 text-sm"><p class="text-gold-900">بيانات حساسة ستُكتب</p><p class="font-semibold">هوية {{ summary.confidential?.identity ?? 0 }} · رواتب {{ summary.confidential?.pay ?? 0 }} · بنوك {{ summary.confidential?.bank ?? 0 }} · إجازات {{ summary.confidential?.leave ?? 0 }} · عناوين {{ summary.confidential?.address ?? 0 }}</p></div>
                </div>
                <details v-if="issueEntries.length" class="text-sm">
                    <summary class="cursor-pointer font-semibold text-navy-800">المشكلات والتحويلات حسب النوع ({{ issueEntries.length }})</summary>
                    <ul class="mt-2 space-y-1">
                        <li v-for="[code, count] in issueEntries" :key="code" :class="levelTone[issueCatalog[code]?.level] || ''">{{ count }} × {{ issueCatalog[code]?.message || code }}</li>
                    </ul>
                </details>

                <div class="flex flex-wrap gap-1">
                    <button v-for="(label, key) in filters" :key="key" type="button" class="rounded-lg px-3 py-1.5 text-sm" :class="filter === key ? 'bg-navy-700 text-white' : 'bg-slate-100 text-slate-700'" @click="goto(key)">{{ label }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[980px]">
                        <thead><tr><th>الصف</th><th>الموظف</th><th>الحالة</th><th>المطابقة</th><th>المشكلات</th><th>الإجراء</th><th>النتيجة</th></tr></thead>
                        <tbody>
                            <template v-for="r in rows.data" :key="r.id">
                                <tr>
                                    <td>{{ r.row }}</td>
                                    <td>
                                        <p class="font-semibold text-navy-800">{{ r.values.full_name || '—' }}</p>
                                        <p class="text-xs text-slate-500">بصمة <span dir="ltr">{{ r.values.attendance_number || '—' }}</span> · هوية <span dir="ltr">{{ r.values.national_id || '—' }}</span></p>
                                        <p class="text-xs text-slate-500">{{ r.values.department_source || '' }}{{ r.values.job_title_source ? ' — ' + r.values.job_title_source : '' }}</p>
                                        <button type="button" class="text-xs text-blue-700 hover:underline" @click="expanded = expanded === r.id ? null : r.id">{{ expanded === r.id ? 'إخفاء التفاصيل' : 'كل القيم' }}</button>
                                    </td>
                                    <td><StatusBadge :label="(rowStatus[r.status] || [r.status])[0]" :tone="(rowStatus[r.status] || [null, 'muted'])[1]" /></td>
                                    <td class="text-xs"><template v-if="r.match"><Link :href="`/admin/employee-profiles/${r.match.id}`" class="text-blue-700 hover:underline">{{ r.match.name }}</Link><br><span dir="ltr">{{ r.match.number }}</span> · {{ matchLabel(r.match.type) }}</template><span v-else>—</span></td>
                                    <td class="max-w-72 text-xs">
                                        <p v-for="(issue, i) in r.issues" :key="i" :class="levelTone[issue.level]">{{ issue.field_label }}: {{ issue.message }}</p>
                                    </td>
                                    <td class="text-xs">
                                        <select v-if="r.allowed.length > 1 && can.edit" :value="r.action" class="field" :aria-label="`إجراء الصف ${r.row}`" @change="setAction(r, $event.target.value)">
                                            <option v-for="a in r.allowed" :key="a" :value="a">{{ actionLabels[a] }}</option>
                                        </select>
                                        <span v-else>{{ actionLabels[r.action] }}</span>
                                        <p v-if="r.action === 'update'" class="mt-1 text-slate-500">{{ r.groups.map(g => choices.updateGroups[g]).join('، ') }}</p>
                                    </td>
                                    <td class="text-xs">{{ resultLabels[r.result] || r.result }}<Link v-if="r.employee_id" :href="`/admin/employee-profiles/${r.employee_id}`" class="block text-blue-700 hover:underline">الملف</Link></td>
                                </tr>
                                <tr v-if="expanded === r.id">
                                    <td colspan="7" class="bg-slate-50">
                                        <div v-if="r.match && can.edit && r.allowed.includes('update')" class="mb-3 rounded-lg border border-slate-200 bg-white p-3 text-sm">
                                            <p class="mb-2 font-semibold">تحديث حقول مختارة للملف القائم (القيم الفارغة لا تمحو المحفوظ):</p>
                                            <label v-for="(label, key) in choices.updateGroups" :key="key" class="flex items-start gap-2"><input v-model="rowGroups[r.id]" type="checkbox" :value="key" class="mt-1" /> {{ label }}</label>
                                            <button type="button" class="btn btn-primary btn-sm mt-2" :disabled="!rowGroups[r.id]?.length" @click="applyUpdate(r)">اعتماد التحديث لهذا الصف</button>
                                        </div>
                                        <dl class="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                                            <div v-for="(value, key) in r.values" :key="key"><dt class="inline text-slate-500">{{ valueLabel(key) }}:</dt> <dd class="inline font-semibold">{{ value }}</dd></div>
                                        </dl>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div v-if="rows.pages > 1" class="flex flex-wrap items-center gap-2 text-sm">
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="rows.page <= 1" @click="goto(filter, rows.page - 1)">السابق</button>
                    <span>صفحة {{ rows.page }} من {{ rows.pages }} ({{ rows.total }} صفًا)</span>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="rows.page >= rows.pages" @click="goto(filter, rows.page + 1)">التالي</button>
                </div>
            </section>

            <!-- 5. Commit, reconciliation, undo -->
            <section class="card space-y-3" id="commit">
                <h2 class="card-title">5. التنفيذ والتقرير</h2>
                <template v-if="can.commit">
                    <p class="text-sm text-slate-600">سيُنشأ {{ summary.actions?.insert ?? 0 }} ملفًا ويُحدّث {{ summary.actions?.update ?? 0 }} ويُتخطى {{ summary.actions?.skip ?? 0 }} ويُرفض {{ summary.actions?.reject ?? 0 }}. التنفيذ على دفعات، وكل دفعة تُلغى كاملة إن تعذر جزء منها، ويمكن المتابعة لاحقًا دون تكرار. لا تُنشأ حسابات دخول ولا إشعارات ولا حركات رواتب.</p>
                    <label class="flex items-start gap-2 text-sm"><input v-model="commitForm.confirm" type="checkbox" class="mt-1" /> راجعت الملخص والصفوف والبيانات الحساسة التي ستُكتب، وأريد التنفيذ.</label>
                    <p v-for="(message, key) in commitForm.errors" :key="key" class="field-error">{{ message }}</p>
                    <button type="button" class="btn btn-primary" :disabled="commitForm.processing || !commitForm.confirm || unresolved > 0" @click="commitForm.post(`${base}/commit`, { preserveScroll: true })">
                        {{ commitForm.processing ? 'جارٍ التنفيذ...' : (batch.status === 'failed' ? 'متابعة التنفيذ' : 'تنفيذ الاستيراد') }}
                    </button>
                </template>
                <div v-if="['committed', 'reverted', 'failed'].includes(batch.status)" class="rounded-xl bg-slate-50 p-3 text-sm">
                    <p class="font-semibold">تقرير المطابقة</p>
                    <p>صفوف الملف {{ summary.rows }} · أُنشئ {{ summary.results?.inserted }} · حُدّث {{ summary.results?.updated }} · تُخطي {{ summary.results?.skipped }} · رُفض {{ summary.results?.rejected }} · لم يُنفذ {{ summary.results?.pending }}</p>
                    <p>مطابق لملفات قائمة {{ summary.matched }} · أعمدة مربوطة {{ summary.columns?.mapped }} · متجاهلة صراحة {{ summary.columns?.ignored }} · بلا قرار {{ summary.columns?.unresolved?.length ?? 0 }}</p>
                    <p v-if="summary.results?.reverted || summary.results?.revert_blocked">تُراجع عن {{ summary.results.reverted }} · بقي دون تراجع {{ summary.results.revert_blocked }} (عُدّل بعد الاستيراد ويحتاج تصحيحًا يدويًا)</p>
                    <p v-if="batch.revert_reason" class="text-xs text-slate-500">سبب التراجع: {{ batch.revert_reason }}</p>
                </div>
                <div v-if="can.revert" class="space-y-2 border-t border-slate-200 pt-3">
                    <p class="text-sm text-slate-600">التراجع يحذف الملفات التي أنشأتها الدفعة ولم تُعدّل بعدها، ويعيد القيم التي غيّرتها إن لم تتغير منذئذ. ما عُدّل أو رُبط بحساب أو وثائق يبقى ويُذكر في التقرير.</p>
                    <textarea v-model="revertForm.reason" rows="2" maxlength="2000" class="field" placeholder="سبب التراجع (إلزامي)"></textarea>
                    <p v-for="(message, key) in revertForm.errors" :key="key" class="field-error">{{ message }}</p>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="revertForm.processing || revertForm.reason.trim().length < 10" @click="revertForm.post(`${base}/revert`, { preserveScroll: true })">التراجع عن الدفعة</button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
