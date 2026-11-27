<script setup>
// يعرض الحقول الديناميكية التي يعرّفها مدير النظام، ويطبق شروط الظهور.
// القيم تُرسل للخادم تحت المفتاح fields.<code> وهو من يتحقق منها نهائيًا.
// قوائم الاختيار تأتي من «ثوابت النظام» النشطة فقط.
const props = defineProps({
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    // قيم محفوظة سابقًا تعتمد عليها شروط الظهور
    context: { type: Object, default: () => ({}) },
    // الإجراء المختار: الحقل المربوط بإجراءات محددة (visible_when.action_codes) يظهر معها فقط
    actionCode: { type: String, default: null },
    // إجراء يعيد الطلب أو يرفضه: حقول المرحلة الإلزامية لا تُشترط معه (كما في الخادم)، عدا المربوطة بهذا الإجراء نفسه
    sendsBack: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

function value(code) {
    return props.modelValue?.[code] ?? '';
}
// «تاريخ ووقت» يُحفظ بصيغة «YYYY-MM-DD HH:MM»، وحقل الإدخال يحتاج «YYYY-MM-DDTHH:MM».
function dateTimeValue(code) {
    return String(value(code)).replace(' ', 'T').slice(0, 16);
}
function update(code, newValue) {
    emit('update:modelValue', { ...props.modelValue, [code]: newValue });
}
function isVisible(field) {
    const rule = field.visible_when;
    if (rule?.action_codes?.length && !rule.action_codes.includes(props.actionCode)) return false;
    if (!rule || !rule.field) return true;
    const actual = props.modelValue?.[rule.field] ?? props.context?.[rule.field];
    const filled = actual !== undefined && actual !== null && actual !== '' && actual !== false;
    switch (rule.operator) {
        case 'filled': return filled;
        case 'empty': return !filled;
        case 'not_equals': return String(actual ?? '') !== String(rule.value ?? '');
        default: return String(actual ?? '') === String(rule.value ?? '');
    }
}
function isRequired(field) {
    return Boolean(field.required) && (!props.sendsBack || Boolean(field.visible_when?.action_codes?.length));
}
function error(code) {
    return props.errors?.[`fields.${code}`] || '';
}
</script>

<template>
    <div class="space-y-4">
        <template v-for="field in fields" :key="field.code">
            <div v-if="isVisible(field)">
                <label v-if="field.type !== 'boolean'" :for="`df-${field.code}`" class="field-label">
                    {{ field.label }}<span v-if="isRequired(field)" class="text-red-600"> *</span>
                </label>

                <input v-if="field.type === 'text'" :id="`df-${field.code}`" type="text" :value="value(field.code)"
                    :maxlength="field.validation?.max_length || 255" :required="isRequired(field)" :aria-invalid="Boolean(error(field.code))" class="field"
                    @input="update(field.code, $event.target.value)" />

                <textarea v-else-if="field.type === 'textarea'" :id="`df-${field.code}`" rows="3" :value="value(field.code)"
                    :maxlength="field.validation?.max_length || 5000" :required="isRequired(field)" :aria-invalid="Boolean(error(field.code))" class="field"
                    @input="update(field.code, $event.target.value)" />

                <input v-else-if="field.type === 'number'" :id="`df-${field.code}`" type="number" step="any" inputmode="decimal" dir="ltr" :value="value(field.code)"
                    :min="field.validation?.min" :max="field.validation?.max" :required="isRequired(field)" :aria-invalid="Boolean(error(field.code))" class="field"
                    @input="update(field.code, $event.target.value === '' ? null : Number($event.target.value))" />

                <input v-else-if="field.type === 'date'" :id="`df-${field.code}`" type="date" :value="value(field.code)"
                    :required="isRequired(field)" :aria-invalid="Boolean(error(field.code))" class="field" @input="update(field.code, $event.target.value)" />

                <input v-else-if="field.type === 'datetime'" :id="`df-${field.code}`" type="datetime-local" dir="ltr" :value="dateTimeValue(field.code)"
                    :required="isRequired(field)" :aria-invalid="Boolean(error(field.code))" class="field" @input="update(field.code, $event.target.value)" />

                <template v-else-if="field.type === 'select'">
                    <select :id="`df-${field.code}`" :value="value(field.code)" :required="isRequired(field)" :disabled="!field.options?.length"
                        :aria-invalid="Boolean(error(field.code))" class="field disabled:bg-slate-100"
                        @change="update(field.code, $event.target.value === '' ? null : Number($event.target.value))">
                        <option value="">{{ field.options?.length ? 'اختر...' : 'لا توجد قيم متاحة' }}</option>
                        <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <p v-if="!field.options?.length" class="mt-1 text-xs text-gold-800">قائمة هذا الحقل فارغة في «ثوابت النظام»؛ يضيف مدير النظام قيمها.</p>
                </template>

                <label v-else-if="field.type === 'boolean'" class="flex items-center gap-3 text-sm font-medium">
                    <input :id="`df-${field.code}`" type="checkbox" :checked="Boolean(modelValue?.[field.code])"
                        @change="update(field.code, $event.target.checked)" />
                    <span>{{ field.label }}<span v-if="isRequired(field)" class="text-red-600"> *</span></span>
                </label>

                <p v-if="field.help" class="mt-1 text-xs text-slate-500">{{ field.help }}</p>
                <p v-if="error(field.code)" class="field-error">{{ error(field.code) }}</p>
            </div>
        </template>
    </div>
</template>
