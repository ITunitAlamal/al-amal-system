<script setup>
// Renders form fields from the versioned rules the admin manages: required / optional / conditional / hidden.
// The server applies the same rules again; this only shows the right fields and marks.
const props = defineProps({
    fields: { type: Array, required: true },
    modelValue: { type: Object, required: true },
    rules: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    prefix: { type: String, default: '' },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue']);

function optionFlags(field, id) {
    return (props.options[field] || []).find(option => Number(option.id) === Number(id))?.flags || {};
}
function holds(when) {
    const value = props.modelValue[when.field];
    if ('eq' in when) return value === when.eq || String(value) === String(when.eq);
    if (when.filled) return value !== null && value !== undefined && value !== '';
    if (when.meta) return Boolean(optionFlags(when.field, value)[when.meta]);
    if (when.in) return when.in.map(String).includes(String(value));
    return false;
}
function rule(field) { return props.rules[field.key] || { req: field.required ? 'required' : 'optional' }; }
function visible(field) {
    const r = rule(field);
    return r.req !== 'hidden' && (r.req !== 'conditional' || holds(r.when || {}));
}
function required(field) { const r = rule(field); return r.req === 'required' || r.req === 'conditional'; }
function set(key, value) { emit('update:modelValue', { ...props.modelValue, [key]: value }); }
function toggle(key, code, checked) {
    const list = new Set(props.modelValue[key] || []);
    checked ? list.add(code) : list.delete(code);
    set(key, [...list]);
}
const error = field => props.errors[`${props.prefix}${field.key}`] || props.errors[field.key];
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <template v-for="field in fields" :key="field.key">
            <div v-if="visible(field)" :class="field.wide || field.type === 'textarea' || field.type === 'multi' ? 'sm:col-span-2' : ''">
                <label v-if="field.type === 'boolean'" class="flex items-center gap-2 text-sm">
                    <input type="checkbox" :data-error-field="`${prefix}${field.key}`" :checked="Boolean(modelValue[field.key])" :disabled="disabled" @change="set(field.key, $event.target.checked)">
                    <span>{{ field.label }}</span>
                </label>
                <fieldset v-else-if="field.type === 'multi'" class="text-sm">
                    <legend class="field-label">{{ field.label }}<span v-if="required(field)" class="text-red-700"> *</span></legend>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="option in options[field.options || field.key] || []" :key="option.code" class="flex items-center gap-1">
                            <input type="checkbox" :checked="(modelValue[field.key] || []).includes(option.code)" :disabled="disabled" @change="toggle(field.key, option.code, $event.target.checked)">
                            {{ option.name }}
                        </label>
                    </div>
                </fieldset>
                <label v-else class="block text-sm">
                    <span class="field-label">{{ field.label }}<span v-if="required(field)" class="text-red-700"> *</span></span>
                    <select v-if="field.type === 'select'" :data-error-field="`${prefix}${field.key}`" class="field" :value="modelValue[field.key] ?? ''" :required="required(field)" :disabled="disabled"
                        @change="set(field.key, $event.target.value === '' ? null : (field.raw ? $event.target.value : Number($event.target.value)))">
                        <option value="">اختر</option>
                        <option v-for="option in field.choices || options[field.options || field.key] || []" :key="option.id ?? option.value" :value="option.id ?? option.value">{{ option.name ?? option.label }}</option>
                    </select>
                    <textarea v-else-if="field.type === 'textarea'" :data-error-field="`${prefix}${field.key}`" class="field" rows="3" :maxlength="field.max || 1000" :value="modelValue[field.key] ?? ''"
                        :required="required(field)" :disabled="disabled" @input="set(field.key, $event.target.value)"></textarea>
                    <input v-else :data-error-field="`${prefix}${field.key}`" class="field" :type="field.type || 'text'" :maxlength="field.max" :min="field.min" :step="field.step"
                        :dir="field.ltr ? 'ltr' : null" :value="modelValue[field.key] ?? ''" :required="required(field)" :disabled="disabled"
                        @input="set(field.key, field.type === 'number' ? ($event.target.value === '' ? null : Number($event.target.value)) : $event.target.value)">
                    <span v-if="field.help" class="mt-1 block text-xs text-slate-500">{{ field.help }}</span>
                </label>
                <p v-if="error(field)" class="field-error" role="alert">{{ Array.isArray(error(field)) ? error(field)[0] : error(field) }}</p>
            </div>
        </template>
    </div>
</template>
