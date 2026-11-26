<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

defineProps({ label: { type: String, default: 'المزيد' } });
const root = ref(null);
const trigger = ref(null);
function close(restoreFocus = false) {
    if (!root.value?.open) return;
    root.value.open = false;
    if (restoreFocus) trigger.value?.focus();
}
function outside(event) { if (!root.value?.contains(event.target)) close(); }
onMounted(() => document.addEventListener('click', outside));
onUnmounted(() => document.removeEventListener('click', outside));
</script>

<template>
    <details ref="root" class="relative" @keydown.esc.stop.prevent="close(true)">
        <summary ref="trigger" class="btn btn-secondary btn-sm cursor-pointer focus-visible:outline-2">{{ label }}</summary>
        <div class="absolute end-0 z-20 mt-2 grid w-64 max-w-[calc(100vw-3rem)] gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
            <slot :close="close" />
        </div>
    </details>
</template>
