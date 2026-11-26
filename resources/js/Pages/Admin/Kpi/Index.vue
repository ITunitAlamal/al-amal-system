<script setup>
import { computed, nextTick, ref } from 'vue';
import { Deferred, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import KpiCards from '../../../Components/KpiCards.vue';
import KpiConfigurationFields from '../../../Components/KpiConfigurationFields.vue';
import { kpiRequest } from '../../../Support/kpiHttp';
import { kpiNumber, kpiValue } from '../../../Support/kpiFormat.js';

const props = defineProps({
    definitions: { type: Array, required: true },
    readiness: { type: Object, default: undefined },
    catalog: { type: Object, required: true },
    formulas: { type: Array, required: true },
    units: { type: Array, required: true },
    periods: { type: Array, required: true },
    departments: { type: Array, required: true },
    roles: { type: Array, required: true },
    workflows: { type: Array, required: true },
    subjectTypes: { type: Array, required: true },
    caseKinds: { type: Array, required: true },
    currencies: { type: Array, required: true },
    domains: { type: Object, required: true },
    audiences: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const today = new Date().toLocaleDateString('en-CA');
const search = ref('');
const domain = ref('');
const visible = computed(() => props.definitions.filter(definition => (!domain.value || definition.domain === domain.value)
    && (!search.value || `${definition.code} ${definition.current?.title ?? ''}`.toLowerCase().includes(search.value.toLowerCase()))));
const totals = computed(() => ({
    all: props.definitions.length,
    active: props.definitions.filter(item => item.is_active).length,
    unavailable: props.definitions.filter(item => item.current?.source === 'unavailable').length,
    executive: props.definitions.filter(item => item.is_active && item.on_executive_home).length,
    department: props.definitions.filter(item => item.is_active && item.on_department_home).length,
}));
const departmentName = id => props.departments.find(item => item.id === id)?.name ?? '—';
const sourceLabel = code => props.catalog[code]?.label ?? code;
const formulaLabel = code => props.formulas.find(item => item.code === code)?.label ?? code;

const readinessBadge = (definition) => {
    const state = props.readiness?.[definition.id];
    if (definition.current?.source === 'unavailable') return { label: 'غير متاح (تسجيل فقط)', tone: 'muted' };
    if (!state) return null;
    if (state.status === 'measured') return { label: 'يُحسب', tone: 'success' };
    if (state.status === 'empty') return { label: 'لا سجلات بعد', tone: 'info' };
    return { label: 'يحتاج إعدادًا', tone: 'warning' };
};

// One editor at a time: settings (applies now), a new version (from a date), or a new definition.
const editor = ref(null);
const editorTitle = ref(null);
const blankConfig = () => ({ title: '', title_en: '', description: '', source: 'workflow_requests', formula: 'count', numerator: 'open', denominator: null,
    unit_value_id: props.units.find(unit => unit.code === 'count')?.id ?? '', period_value_id: props.periods[0]?.id ?? '', polarity: 'higher',
    target: null, warning_threshold: null, critical_threshold: null, filters: {}, params: {}, unavailable_reason: '' });
const copyConfig = config => ({ ...blankConfig(), ...config, filters: { ...(config?.filters ?? {}) }, params: { ...(config?.params ?? {}) } });
const settingsOf = definition => ({
    domain: definition?.domain ?? 'institution', owner_department_id: definition?.owner_department_id ?? null, audience: definition?.audience ?? 'executive',
    viewer_department_ids: [...(definition?.viewer_department_ids ?? [])], visible_role_codes: [...(definition?.visible_role_codes ?? [])],
    on_executive_home: definition?.on_executive_home ?? false, on_department_home: definition?.on_department_home ?? false,
    is_active: definition?.is_active ?? false, sort_order: definition?.sort_order ?? (props.definitions.length + 1) * 10,
});
const settingsForm = useForm(settingsOf(null));
const versionForm = useForm({ configuration: blankConfig(), effective_from: today, change_reason: '', expected_version: null });
const createForm = useForm({ code: '', ...settingsOf(null), configuration: blankConfig() });

async function edit(mode, definition = null) {
    preview.value = null;
    previewError.value = '';
    editor.value = { mode, definition };
    if (mode === 'settings') {
        settingsForm.defaults(settingsOf(definition));
        settingsForm.reset();
        settingsForm.clearErrors();
    } else if (mode === 'version') {
        versionForm.defaults({ configuration: copyConfig(definition.current), effective_from: today, change_reason: '', expected_version: definition.latest_version });
        versionForm.reset();
        versionForm.clearErrors();
    } else {
        createForm.defaults({ code: '', ...settingsOf(null), configuration: blankConfig() });
        createForm.reset();
        createForm.clearErrors();
    }
    await nextTick();
    editorTitle.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    editorTitle.value?.focus();
}
function close() {
    editor.value = null;
}
const done = { preserveScroll: true, onSuccess: () => close() };
function saveSettings() {
    settingsForm.put(editor.value.definition.urls.settings, done);
}
function saveVersion() {
    versionForm.post(editor.value.definition.urls.version, done);
}
function saveDefinition() {
    createForm.post(props.urls.store, done);
}
const activeForm = computed(() => ({ settings: settingsForm, version: versionForm, create: createForm }[editor.value?.mode]));

// Live preview of the configuration being edited (institution-wide, current month, nothing stored).
const preview = ref(null);
const previewError = ref('');
const previewBusy = ref(false);
async function runPreview() {
    previewBusy.value = true;
    previewError.value = '';
    preview.value = null;
    try {
        preview.value = await kpiRequest(props.urls.preview, { configuration: activeForm.value.configuration });
    } catch (failure) {
        previewError.value = failure.message;
    } finally {
        previewBusy.value = false;
    }
}

const history = ref(null);
</script>

<template>
    <AppLayout title="إدارة مؤشرات الأداء">
        <div class="space-y-6">
            <PageHeader title="إدارة مؤشرات الأداء" eyebrow="إعدادات النظام"
                description="المصادر والمعادلات مسجلة في النظام ولا تُكتب يدويًا. الظهور والترتيب يُطبقان فورًا، والمعادلة والهدف والعتبات تتغير بإصدار جديد يبدأ من اليوم أو بعده؛ الإصدارات والقراءات السابقة تبقى كما هي. كل تغيير يُسجل في سجل التدقيق.">
                <Link :href="urls.dashboard" class="btn btn-secondary">لوحة المؤشرات</Link>
                <Link :href="urls.constants" class="btn btn-secondary">الوحدات والفترات في الثوابت</Link>
                <button type="button" class="btn btn-primary" @click="edit('create')">مؤشر جديد</button>
            </PageHeader>

            <section class="grid grid-cols-2 gap-3 sm:grid-cols-5" aria-label="ملخص الكتالوج">
                <div class="card p-4"><p class="text-xs text-slate-500">المسجلة</p><p class="font-display text-2xl font-bold text-navy-800">{{ kpiNumber(totals.all) }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">المفعّلة</p><p class="font-display text-2xl font-bold text-emerald-700">{{ kpiNumber(totals.active) }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">غير متاحة (تسجيل فقط)</p><p class="font-display text-2xl font-bold text-slate-500">{{ kpiNumber(totals.unavailable) }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">في لوحة الإدارة العليا</p><p class="font-display text-2xl font-bold text-navy-800">{{ kpiNumber(totals.executive) }}</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">في لوحات رؤساء الأقسام</p><p class="font-display text-2xl font-bold text-navy-800">{{ kpiNumber(totals.department) }}</p></div>
            </section>

            <section v-if="editor" class="card space-y-4 p-4 sm:p-5" aria-labelledby="kpi-editor-title">
                <header class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 id="kpi-editor-title" ref="editorTitle" tabindex="-1" class="text-lg font-bold text-navy-800 outline-none">
                            {{ editor.mode === 'create' ? 'مؤشر جديد' : `${editor.mode === 'settings' ? 'الظهور والترتيب' : 'إصدار جديد'}: ${editor.definition.current?.title ?? editor.definition.code}` }}
                        </h2>
                        <p v-if="editor.definition" class="text-xs text-slate-500" dir="ltr">{{ editor.definition.code }}</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" @click="close">إغلاق</button>
                </header>

                <form v-if="editor.mode !== 'version'" class="space-y-4" @submit.prevent="editor.mode === 'create' ? saveDefinition() : saveSettings()">
                    <template v-for="form in [editor.mode === 'create' ? createForm : settingsForm]" :key="editor.mode">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label v-if="editor.mode === 'create'" class="field-label">الرمز الثابت<input v-model="form.code" required maxlength="100" dir="ltr" pattern="[A-Z][A-Z0-9_]{2,99}" class="field mt-1 w-full"><span v-if="form.errors.code" class="field-error">{{ form.errors.code }}</span></label>
                            <label class="field-label">المجال
                                <select v-model="form.domain" class="field mt-1 w-full"><option v-for="(label, code) in domains" :key="code" :value="code">{{ label }}</option></select>
                            </label>
                            <label class="field-label">القسم المالك
                                <select v-model="form.owner_department_id" class="field mt-1 w-full">
                                    <option :value="null">لا يوجد (مؤشر مؤسسي)</option>
                                    <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}{{ department.is_active ? '' : ' (غير فعال)' }}</option>
                                </select>
                                <span v-if="form.errors.owner_department_id" class="field-error">{{ form.errors.owner_department_id }}</span>
                            </label>
                            <label class="field-label">من يرى المؤشر
                                <select v-model="form.audience" class="field mt-1 w-full"><option v-for="(label, code) in audiences" :key="code" :value="code">{{ label }}</option></select>
                            </label>
                            <label class="field-label">ترتيب الظهور<input v-model.number="form.sort_order" type="number" min="0" max="100000" required class="field mt-1 w-full"></label>
                        </div>
                        <fieldset class="rounded-lg border border-slate-100 p-3">
                            <legend class="px-1 text-sm font-semibold text-navy-800">أقسام إضافية ترى كامل المجال (مثل الحسابات لمؤشرات التحصيل)</legend>
                            <div class="mt-2 flex flex-wrap gap-3">
                                <label v-for="department in departments.filter(item => item.is_active)" :key="department.id" class="flex items-center gap-2 text-sm">
                                    <input v-model="form.viewer_department_ids" type="checkbox" :value="department.id">{{ department.name }}</label>
                            </div>
                        </fieldset>
                        <fieldset class="rounded-lg border border-slate-100 p-3">
                            <legend class="px-1 text-sm font-semibold text-navy-800">حصر الظهور في أدوار معينة (اختياري؛ يضيّق ولا يوسّع)</legend>
                            <div class="mt-2 flex flex-wrap gap-3">
                                <label v-for="role in roles" :key="role.code" class="flex items-center gap-2 text-sm"><input v-model="form.visible_role_codes" type="checkbox" :value="role.code">{{ role.name }}</label>
                            </div>
                        </fieldset>
                        <div class="flex flex-wrap gap-5">
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox">مفعّل</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.on_executive_home" type="checkbox">يظهر في لوحة الإدارة العليا (الصفحة الرئيسية)</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.on_department_home" type="checkbox">يظهر في لوحة رئيس القسم (الصفحة الرئيسية)</label>
                        </div>
                        <p class="text-xs leading-5 text-slate-500">الظهور في اللوحة لا يمنح صلاحية: يظهر المؤشر فقط لمن يحق له رؤيته حسب الجمهور والقسم المالك، وتُحسب أرقامه بنطاقه. يظهر لرئيس القسم حتى 5 مؤشرات وللإدارة العليا حتى 6، بحسب الترتيب.</p>
                        <KpiConfigurationFields v-if="editor.mode === 'create'" :config="form.configuration" :errors="form.errors" :catalog="catalog" :formulas="formulas" :units="units" :periods="periods"
                            :workflows="workflows" :subject-types="subjectTypes" :roles="roles" :case-kinds="caseKinds" :currencies="currencies" />
                        <p v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ' }}</button>
                            <button v-if="editor.mode === 'create'" type="button" class="btn btn-secondary" :disabled="previewBusy" @click="runPreview">{{ previewBusy ? 'جارٍ الحساب…' : 'معاينة على البيانات الحالية' }}</button>
                        </div>
                    </template>
                </form>

                <form v-else class="space-y-4" @submit.prevent="saveVersion">
                    <KpiConfigurationFields :config="versionForm.configuration" :errors="versionForm.errors" :catalog="catalog" :formulas="formulas" :units="units" :periods="periods"
                        :workflows="workflows" :subject-types="subjectTypes" :roles="roles" :case-kinds="caseKinds" :currencies="currencies" />
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="field-label">يبدأ سريانه في<input v-model="versionForm.effective_from" type="date" :min="today" required class="field mt-1 w-full"><span v-if="versionForm.errors.effective_from" class="field-error">{{ versionForm.errors.effective_from }}</span></label>
                        <label class="field-label sm:col-span-2">سبب التغيير (يُسجل مع الإصدار)<input v-model="versionForm.change_reason" required minlength="5" maxlength="500" class="field mt-1 w-full" placeholder="مثل: اعتماد هدف 90٪ بقرار المدير التنفيذي رقم …"><span v-if="versionForm.errors.change_reason" class="field-error">{{ versionForm.errors.change_reason }}</span></label>
                    </div>
                    <p v-if="versionForm.errors.expected_version" class="field-error">{{ versionForm.errors.expected_version }}</p>
                    <p class="text-xs leading-5 text-slate-500">لا يُعدّل الإصدار الحالي ولا يُحذف؛ يُغلق عند بداية الإصدار الجديد. الفترات التي تمتد عبر التغيير تُحسب بالإصدار الساري في نهايتها مع تنبيه في البطاقة.</p>
                    <div class="flex flex-wrap gap-2">
                        <button class="btn btn-primary" :disabled="versionForm.processing">{{ versionForm.processing ? 'جارٍ الحفظ…' : 'حفظ الإصدار' }}</button>
                        <button type="button" class="btn btn-secondary" :disabled="previewBusy" @click="runPreview">{{ previewBusy ? 'جارٍ الحساب…' : 'معاينة على البيانات الحالية' }}</button>
                    </div>
                </form>

                <p v-if="previewError" role="alert" class="alert-error text-sm">{{ previewError }}</p>
                <div v-if="preview" class="space-y-2" aria-live="polite">
                    <p class="text-xs text-slate-500">معاينة على بيانات المؤسسة كلها للشهر الحالي. لا شيء يُحفظ.</p>
                    <KpiCards :cards="[{ ...preview, id: 0 }]" :actions="false" />
                </div>
            </section>

            <section class="card space-y-4 p-4 sm:p-5" aria-labelledby="catalog-title">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="catalog-title" class="text-lg font-bold text-navy-800">الكتالوج</h2>
                    <div class="flex flex-wrap gap-3">
                        <label class="field-label">بحث<input v-model="search" class="field mt-1 w-56" placeholder="الاسم أو الرمز"></label>
                        <label class="field-label">المجال
                            <select v-model="domain" class="field mt-1 w-48"><option value="">الكل</option><option v-for="(label, code) in domains" :key="code" :value="code">{{ label }}</option></select>
                        </label>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table w-full text-sm">
                        <thead><tr>
                            <th scope="col">المؤشر</th><th scope="col">المصدر والمعادلة</th><th scope="col">المالك ومن يراه</th><th scope="col">اللوحات</th>
                            <th scope="col">الجاهزية اليوم</th><th scope="col">الإصدار والهدف</th><th scope="col"><span class="sr-only">إجراءات</span></th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="definition in visible" :key="definition.id" :class="definition.is_active ? '' : 'opacity-70'">
                                <td>
                                    <p class="font-semibold text-navy-800">{{ definition.current?.title ?? definition.code }}</p>
                                    <p class="text-[11px] text-slate-500" dir="ltr">{{ definition.code }}</p>
                                    <StatusBadge :label="definition.is_active ? 'مفعّل' : 'معطّل'" :tone="definition.is_active ? 'success' : 'muted'" />
                                </td>
                                <td class="text-xs">{{ sourceLabel(definition.current?.source) }}<br><span class="text-slate-500">{{ formulaLabel(definition.current?.formula) }}</span></td>
                                <td class="text-xs">{{ definition.owner_department_id ? departmentName(definition.owner_department_id) : 'مؤسسي' }}<br><span class="text-slate-500">{{ audiences[definition.audience] }}</span></td>
                                <td class="text-xs">
                                    <span v-if="definition.on_executive_home" class="block">الإدارة العليا</span>
                                    <span v-if="definition.on_department_home" class="block">رؤساء الأقسام</span>
                                    <span v-if="!definition.on_executive_home && !definition.on_department_home" class="text-slate-400">صفحة المؤشرات فقط</span>
                                </td>
                                <td class="max-w-64 text-xs">
                                    <Deferred data="readiness">
                                        <template #fallback><span class="inline-block h-4 w-20 animate-pulse rounded bg-slate-100"></span></template>
                                        <template v-if="readinessBadge(definition)">
                                            <StatusBadge :label="readinessBadge(definition).label" :tone="readinessBadge(definition).tone" />
                                            <p v-if="readiness?.[definition.id]?.status === 'measured'" class="mt-1 font-semibold text-navy-800">
                                                {{ kpiValue({ unit_code: readiness[definition.id].unit_code, currency: readiness[definition.id].currency }, readiness[definition.id].value) }}</p>
                                            <p v-else class="mt-1 leading-5 text-slate-500">{{ definition.current?.source === 'unavailable' ? definition.current.unavailable_reason : readiness?.[definition.id]?.reason }}</p>
                                        </template>
                                    </Deferred>
                                </td>
                                <td class="text-xs">إصدار {{ definition.current_version ?? '—' }}<br>
                                    <span class="text-slate-500">الهدف: {{ definition.current?.target ?? 'غير معتمد' }}</span>
                                    <button v-if="definition.versions.length" type="button" class="block text-blue-700 hover:underline" @click="history = history === definition.id ? null : definition.id">السجل ({{ definition.versions.length }})</button>
                                </td>
                                <td>
                                    <div class="flex flex-col gap-1">
                                        <button type="button" class="btn btn-secondary btn-sm" @click="edit('settings', definition)">الظهور والتفعيل</button>
                                        <button type="button" class="btn btn-secondary btn-sm" @click="edit('version', definition)">إصدار جديد</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-for="definition in visible.filter(item => item.id === history)" :key="`h${definition.id}`" class="rounded-lg border border-slate-100 p-3">
                    <h3 class="font-semibold text-navy-800">سجل إصدارات {{ definition.current?.title }}</h3>
                    <ol class="mt-2 space-y-2 text-xs leading-5">
                        <li v-for="version in definition.versions" :key="version.id" class="rounded bg-slate-50 p-2">
                            <span class="font-semibold">إصدار {{ version.version }}</span> · من {{ version.effective_from }} {{ version.effective_until ? `حتى ${version.effective_until} (غير مشمول)` : '· ساري' }}
                            · {{ formulaLabel(version.configuration?.formula) }} · الهدف {{ version.configuration?.target ?? 'غير معتمد' }}
                            · تنبيه {{ version.configuration?.warning_threshold ?? '—' }} · حرجة {{ version.configuration?.critical_threshold ?? '—' }}
                            <span class="block text-slate-500">{{ version.change_reason }}{{ version.created_by ? ` — ${version.created_by}` : '' }} · {{ version.created_at }}</span>
                        </li>
                    </ol>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
