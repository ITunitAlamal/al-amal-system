<script setup>
import { nextTick, onUnmounted, ref } from 'vue';

const props = defineProps({
    value: { type: [String, Number], default: '' },
    label: { type: String, default: 'الرقم المرجعي' },
});
const message = ref('');
const copying = ref(false);
const manualValue = ref('');
const manualInput = ref(null);
let timer;
let disposed = false;

async function copy() {
    if (copying.value || props.value === '' || props.value === null) return;
    const value = String(props.value);
    copying.value = true;
    clearTimeout(timer);
    message.value = '';
    manualValue.value = '';
    try {
        await navigator.clipboard.writeText(value);
        if (disposed) return;
        message.value = 'تم النسخ';
        timer = setTimeout(() => { message.value = ''; }, 2000);
    } catch {
        if (disposed) return;
        manualValue.value = value;
        message.value = 'تعذّر النسخ التلقائي؛ انسخ الرقم المحدد يدويًا.';
        await nextTick();
        manualInput.value?.focus();
        manualInput.value?.select();
    } finally {
        copying.value = false;
    }
}

onUnmounted(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5" dir="rtl">
        <span dir="auto" class="font-mono tabular-nums">{{ value }}</span>
        <button v-if="value !== '' && value !== null" type="button" :disabled="copying" :aria-label="`نسخ ${label}`" :title="`نسخ ${label}`" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-navy-600 hover:bg-navy-50 disabled:opacity-50" @click.stop="copy">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>
        </button>
        <span role="status" aria-live="polite" class="text-xs font-sans text-navy-700">{{ message }}</span>
        <input v-if="manualValue" ref="manualInput" :value="manualValue" readonly :aria-label="`${label} للنسخ اليدوي`" class="field w-44 font-mono text-xs" dir="auto" @focus="$event.target.select()">
    </span>
</template>
