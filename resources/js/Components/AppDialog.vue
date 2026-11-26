<script setup>
import { nextTick, onUnmounted, ref, useId, watch } from 'vue';

const props = defineProps({ open: Boolean, title: { type: String, required: true }, description: { type: String, default: '' }, drawer: Boolean, busy: Boolean });
const emit = defineEmits(['close']);
const dialog = ref(null);
const heading = useId();
let trigger;
let previousOverflow;
let locked = false;
let disposed = false;
function restore() {
    if (!locked) return;
    document.body.style.overflow = previousOverflow;
    locked = false;
    if (trigger?.isConnected) trigger.focus({ preventScroll: true });
}
watch(() => props.open, async open => {
    await nextTick();
    if (disposed || !dialog.value) return;
    if (open && !dialog.value.open) {
        trigger = document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        locked = true;
        dialog.value.showModal();
    } else if (!open && dialog.value.open) {
        dialog.value.close();
        restore();
    }
}, { immediate: true });
function close() { if (!props.busy) emit('close'); }
function closed() { restore(); if (props.open) emit('close'); }
onUnmounted(() => { disposed = true; dialog.value?.close(); restore(); });
</script>

<template>
    <dialog ref="dialog" :class="drawer ? 'aims-record-preview aims-dialog-drawer' : 'aims-dialog'" :aria-labelledby="heading" :aria-describedby="description ? `${heading}-description` : undefined" :aria-busy="busy" dir="rtl" @cancel.prevent="close" @close="closed" @click="event => event.target === dialog && close()">
        <div class="flex min-h-full flex-col">
            <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white p-5">
                <div class="min-w-0"><h2 :id="heading" class="card-title break-words">{{ title }}</h2><p v-if="description" :id="`${heading}-description`" class="mt-2 text-sm leading-6 text-slate-600">{{ description }}</p></div>
                <button type="button" autofocus class="btn btn-secondary shrink-0" aria-label="إغلاق النافذة" :disabled="busy" @click="close">إغلاق</button>
            </header>
            <div class="flex-1 p-5"><slot /></div>
            <footer v-if="$slots.footer" class="sticky bottom-0 border-t border-slate-200 bg-white p-5"><slot name="footer" /></footer>
        </div>
    </dialog>
</template>
