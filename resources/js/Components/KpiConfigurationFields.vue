<script setup>
import { computed } from 'vue';

/**
 * Fields of one KPI version: registered source, measure, approved formula, unit, target and thresholds,
 * and the whitelisted sample filters of that source. Nothing here accepts an expression or an identifier.
 */
const props = defineProps({
    config: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    catalog: { type: Object, required: true },
    formulas: { type: Array, required: true },
    units: { type: Array, required: true },
    periods: { type: Array, required: true },
    workflows: { type: Array, required: true },
    subjectTypes: { type: Array, required: true },
    roles: { type: Array, required: true },
    caseKinds: { type: Array, required: true },
    currencies: { type: Array, required: true },
});

const source = computed(() => props.catalog[props.config.source] ?? props.catalog.unavailable);
const unavailable = computed(() => props.config.source === 'unavailable');
const measures = computed(() => Object.entries(source.value.measures ?? {}).map(([code, measure]) => ({ code, ...measure })));
const kind = computed(() => source.value.measures?.[props.config.numerator]?.kind ?? null);
const allowedFormulas = computed(() => props.formulas.filter(formula => !kind.value || formula.kinds.includes(kind.value)));
const ratio = computed(() => ['ratio', 'percentage'].includes(props.config.formula));
const denominators = computed(() => (source.value.ratios ?? []).filter(pair => pair[0] === props.config.numerator).map(pair => ({ code: pair[1], label: source.value.measures[pair[1]]?.label ?? pair[1] })));
const allowedUnits = computed(() => props.units.filter(unit => (props.formulas.find(formula => formula.code === props.config.formula)?.units ?? []).includes(unit.code)));
const filterOptions = key => ({
    workflow_code: props.workflows.map(item => ({ value: item.code, label: item.name })),
    subject_type: props.subjectTypes.map(item => ({ value: item.code, label: item.name })),
    step_role: props.roles.map(item => ({ value: item.code, label: item.name })),
    assignee_role: props.roles.map(item => ({ value: item.code, label: item.name })),
    case_kind: props.caseKinds.map(item => ({ value: item.code, label: item.name })),
    currency: props.currencies.map(item => ({ value: item.code, label: `${item.code} — ${item.name_ar}` })),
}[key] ?? []);
const error = key => props.errors[key];

function changeSource() {
    const first = measures.value[0];
    props.config.numerator = first?.code ?? null;
    props.config.denominator = null;
    props.config.filters = {};
    props.config.params = {};
    changeNumerator();
}
function changeNumerator() {
    if (!allowedFormulas.value.some(formula => formula.code === props.config.formula)) props.config.formula = allowedFormulas.value[0]?.code ?? 'count';
    changeFormula();
}
function changeFormula() {
    if (ratio.value && !denominators.value.some(item => item.code === props.config.denominator)) props.config.denominator = denominators.value[0]?.code ?? null;
    if (!ratio.value) props.config.denominator = null;
    if (!allowedUnits.value.some(unit => unit.id === props.config.unit_value_id)) props.config.unit_value_id = allowedUnits.value[0]?.id ?? '';
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="field-label">اسم المؤشر<input v-model="config.title" required maxlength="200" class="field mt-1 w-full"><span v-if="error('title')" class="field-error">{{ error('title') }}</span></label>
            <label class="field-label">الاسم الإنجليزي (اختياري)<input v-model="config.title_en" maxlength="200" dir="ltr" class="field mt-1 w-full"></label>
            <label class="field-label">مصدر البيانات المعتمد
                <select v-model="config.source" class="field mt-1 w-full" @change="changeSource">
                    <option v-for="(item, code) in catalog" :key="code" :value="code">{{ item.label }}</option>
                </select>
                <span v-if="error('source')" class="field-error">{{ error('source') }}</span>
            </label>
        </div>
        <p class="rounded-lg bg-slate-50 p-3 text-xs leading-6 text-slate-600">{{ source.basis }}</p>

        <label v-if="unavailable" class="field-label block">سبب عدم التوافر (يظهر لمن يرى المؤشر)
            <textarea v-model="config.unavailable_reason" required maxlength="1000" rows="2" class="field mt-1 w-full"></textarea>
            <span v-if="error('unavailable_reason')" class="field-error">{{ error('unavailable_reason') }}</span>
        </label>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="field-label">{{ ratio ? 'البسط' : 'المقياس' }}
                <select v-model="config.numerator" class="field mt-1 w-full" @change="changeNumerator">
                    <option v-for="measure in measures" :key="measure.code" :value="measure.code">{{ measure.label }}{{ measure.snapshot ? ' (لحظي)' : '' }}</option>
                </select>
                <span v-if="error('numerator')" class="field-error">{{ error('numerator') }}</span>
            </label>
            <label class="field-label">المعادلة
                <select v-model="config.formula" class="field mt-1 w-full" @change="changeFormula">
                    <option v-for="formula in allowedFormulas" :key="formula.code" :value="formula.code">{{ formula.label }}</option>
                </select>
                <span v-if="error('formula')" class="field-error">{{ error('formula') }}</span>
            </label>
            <label v-if="ratio" class="field-label">المقام
                <select v-model="config.denominator" class="field mt-1 w-full">
                    <option v-for="item in denominators" :key="item.code" :value="item.code">{{ item.label }}</option>
                </select>
                <span v-if="error('denominator')" class="field-error">{{ error('denominator') }}</span>
            </label>
            <label class="field-label">الوحدة
                <select v-model="config.unit_value_id" class="field mt-1 w-full">
                    <option v-for="unit in allowedUnits" :key="unit.id" :value="unit.id">{{ unit.name_ar }}</option>
                </select>
                <span v-if="error('unit_value_id')" class="field-error">{{ error('unit_value_id') }}</span>
            </label>
        </div>

        <div v-if="!unavailable && (Object.keys(source.filters ?? {}).length || Object.keys(source.params ?? {}).length)" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label v-for="(label, key) in source.filters" :key="key" class="field-label">{{ label }} (عينة ثابتة)
                <select v-model="config.filters[key]" class="field mt-1 w-full">
                    <option value="">{{ key === 'currency' ? 'يختارها المستخدم (العملة الأساسية افتراضيًا)' : 'الكل' }}</option>
                    <option v-for="option in filterOptions(key)" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>
            <label v-for="(param, key) in source.params" :key="key" class="field-label">{{ param.label }}
                <input v-model.number="config.params[key]" type="number" required :min="param.min" :max="param.max" class="field mt-1 w-full">
            </label>
            <p v-if="error('filters') || error('params')" class="field-error sm:col-span-2 lg:col-span-4">{{ error('filters') || error('params') }}</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="field-label">دورية القياس
                <select v-model="config.period_value_id" class="field mt-1 w-full">
                    <option v-for="period in periods" :key="period.id" :value="period.id">{{ period.name_ar }}</option>
                </select>
            </label>
            <label class="field-label">اتجاه التحسن
                <select v-model="config.polarity" class="field mt-1 w-full"><option value="higher">الأعلى أفضل</option><option value="lower">الأقل أفضل</option></select>
            </label>
            <label class="field-label">الهدف (فارغ = غير معتمد)<input v-model="config.target" type="number" step="any" class="field mt-1 w-full"><span v-if="error('target')" class="field-error">{{ error('target') }}</span></label>
            <label class="field-label">عتبة التنبيه<input v-model="config.warning_threshold" type="number" step="any" class="field mt-1 w-full"></label>
            <label class="field-label">العتبة الحرجة<input v-model="config.critical_threshold" type="number" step="any" class="field mt-1 w-full"><span v-if="error('critical_threshold')" class="field-error">{{ error('critical_threshold') }}</span></label>
        </div>
        <p class="text-xs leading-5 text-slate-500">الأهداف والعتبات قرار إداري: تُترك فارغة حتى يعتمدها صاحب الصلاحية، ولا يقترح النظام أرقامًا. مع «الأعلى أفضل»: الحرجة ≤ التنبيه ≤ الهدف، وبالعكس مع «الأقل أفضل».</p>

        <label class="field-label block">الوصف (يظهر في البطاقة)
            <textarea v-model="config.description" maxlength="2000" rows="2" class="field mt-1 w-full"></textarea>
        </label>
    </div>
</template>
