<script setup>
defineProps({ steps: { type: Array, required: true }, modelValue: { type: Number, default: 0 }, disabled: Boolean, maxStep: { type: Number, default: Infinity } });
defineEmits(['update:modelValue']);
</script>
<template>
    <nav class="aims-form-steps" aria-label="خطوات إدخال البيانات">
        <p class="mb-3 text-sm font-semibold text-navy-800" aria-live="polite">الخطوة {{ modelValue + 1 }} من {{ steps.length }}: {{ steps[modelValue] }}</p>
        <ol class="flex gap-2 overflow-x-auto pb-2">
            <li v-for="(label, index) in steps" :key="label" class="min-w-36 flex-1">
                <button type="button" class="flex h-full w-full items-center gap-2 rounded-xl border px-3 py-3 text-start text-sm" :class="index === modelValue ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-blue-300'" :aria-current="index === modelValue ? 'step' : undefined" :disabled="disabled || index > maxStep" @click="$emit('update:modelValue', index)">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-current text-xs" aria-hidden="true">{{ index + 1 }}</span><span>{{ label }}</span>
                </button>
            </li>
        </ol>
        <p class="mt-1 text-xs text-slate-500">يمكن الرجوع إلى الخطوات لتعديل البيانات قبل الإرسال.</p>
    </nav>
</template>
