<script setup>
import { useId } from 'vue';
import ActiveFilters from '../ActiveFilters.vue';

defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, required: true },
    placeholder: { type: String, default: '' },
    count: { type: Number, default: null },
    filters: { type: Array, default: () => [] },
    busy: Boolean,
    maxlength: { type: Number, default: 100 },
});
defineEmits(['update:modelValue', 'apply', 'remove', 'clear']);
const searchId = useId();
</script>

<template>
    <section class="space-y-3" aria-label="البحث والتصفية">
        <form class="space-y-3" @submit.prevent="$emit('apply')">
            <div class="flex flex-wrap items-end gap-2">
                <label :for="searchId" class="w-full min-w-0 sm:w-auto sm:min-w-64 sm:flex-1">
                    <span class="field-label">{{ label }}</span>
                    <input :id="searchId" :value="modelValue" type="search" :maxlength="maxlength" :placeholder="placeholder" class="field" @input="$emit('update:modelValue', $event.target.value)">
                </label>
                <slot name="quick" />
                <button class="btn btn-primary btn-sm" :disabled="busy">تصفية</button>
                <slot name="actions" />
            </div>
            <details v-if="$slots.advanced" class="rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-800 focus-visible:outline-2">تصفية متقدمة</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><slot name="advanced" /></div>
            </details>
        </form>
        <ActiveFilters v-if="count !== null" :count="count" :items="filters" @remove="$emit('remove', $event)" @clear="$emit('clear')" />
        <div v-else-if="filters.length" class="flex flex-wrap gap-2 text-sm" aria-label="الفلاتر المطبقة">
            <button v-for="item in filters" :key="item.key" type="button" class="btn btn-secondary btn-sm" :aria-label="`إزالة الفلتر: ${item.label}`" @click="$emit('remove', item.key)">{{ item.label }} ×</button>
            <button type="button" class="btn btn-secondary btn-sm" @click="$emit('clear')">مسح الكل</button>
        </div>
    </section>
</template>
