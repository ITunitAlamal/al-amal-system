<script setup>
import { useId } from 'vue';

defineProps({ modelValue: { type: String, required: true }, label: { type: String, required: true }, options: { type: Array, required: true } });
defineEmits(['update:modelValue']);
const id = useId();
</script>

<template>
    <label :for="id" class="flex flex-wrap items-center gap-2 text-sm font-semibold text-navy-800">
        <span>{{ label }}</span>
        <select :id="id" :value="modelValue" class="field w-full sm:max-w-sm" @change="$emit('update:modelValue', $event.target.value)">
            <option v-for="[key, title] in options" :key="key" :value="key">{{ title }}</option>
        </select>
    </label>
</template>
