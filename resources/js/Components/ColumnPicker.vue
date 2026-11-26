<script setup>
import { onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { columnStorageKey, normalizeHiddenColumns } from '../Support/tableColumns.js';

const props = defineProps({ table: String, columns: Array, modelValue: Array });
const emit = defineEmits(['update:modelValue']);
const page = usePage();
function load() {
    let hidden = [];
    try {
        const key = columnStorageKey(page.props.auth?.user?.id, props.table);
        if (key) hidden = normalizeHiddenColumns(JSON.parse(localStorage.getItem(key) || '[]'), props.columns);
    } catch { /* Storage is optional; all columns remain available. */ }
    emit('update:modelValue', hidden);
}
function update(hidden) {
    const safe = normalizeHiddenColumns(hidden, props.columns);
    emit('update:modelValue', safe);
    try {
        const key = columnStorageKey(page.props.auth?.user?.id, props.table);
        if (key) localStorage.setItem(key, JSON.stringify(safe));
    } catch { /* Keep the current selection even when storage is unavailable. */ }
}
onMounted(load);
watch(() => page.props.auth?.user?.id, load);
</script>

<template>
    <details class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
        <summary class="cursor-pointer rounded font-semibold text-navy-800">الأعمدة الظاهرة</summary>
        <fieldset class="mt-3 flex flex-wrap gap-x-5 gap-y-3">
            <legend class="sr-only">اختيار أعمدة الجدول</legend>
            <label v-for="column in columns" :key="column.key" class="flex min-h-10 items-center gap-2 text-slate-700">
                <input type="checkbox" :checked="!modelValue.includes(column.key)" :disabled="column.required" @change="update($event.target.checked ? modelValue.filter(key => key !== column.key) : [...modelValue, column.key])">
                {{ column.label }}<span v-if="column.required" class="text-xs text-slate-600">(أساسي)</span>
            </label>
        </fieldset>
        <button type="button" class="btn btn-sm btn-secondary mt-3" @click="update([])">استعادة جميع الأعمدة</button>
    </details>
</template>
