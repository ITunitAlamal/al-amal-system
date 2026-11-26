<script setup>
// Accessible radio group (yes / no / unknown by default). "Unknown" is an answer, distinct from "not answered".
import { ynu } from '../../Support/registration.js';
const props = defineProps({ modelValue: { default: '' }, label: { type: String, required: true }, name: { type: String, required: true }, options: { type: Array, default: () => ynu }, errors: { type: Object, default: () => ({}) }, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
</script>
<template>
    <fieldset class="text-sm" :data-error-field="name" :disabled="disabled">
        <legend class="mb-1">{{ label }}</legend>
        <div class="flex flex-wrap gap-3">
            <label v-for="[value, text] in options" :key="value" class="flex items-center gap-1.5"><input type="radio" :name="name" :value="value" :checked="modelValue === value" @change="emit('update:modelValue', value)"> {{ text }}</label>
        </div>
        <span v-if="errors[name]" class="field-error" role="alert">{{ errors[name] }}</span>
    </fieldset>
</template>
