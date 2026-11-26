<script setup>
import { nextTick, onUnmounted, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ title: { type: String, default: '' }, fields: { type: Array, default: () => [] }, href: { type: String, default: '' } });
const emit = defineEmits(['close']);
const dialog = ref(null);
const headingId = useId();
let previousFocus;
watch(() => props.title, async title => {
    await nextTick();
    if (!dialog.value) return;
    if (title && !dialog.value.open) {
        previousFocus = document.activeElement;
        dialog.value.showModal();
    } else if (!title && dialog.value.open) dialog.value.close();
});
function closed() {
    emit('close');
    if (previousFocus?.isConnected) previousFocus.focus();
}
function closeOnBackdrop(event) {
    if (event.target === dialog.value) dialog.value.close();
}
onUnmounted(() => { if (dialog.value?.open) dialog.value.close(); });
</script>

<template>
    <dialog ref="dialog" class="aims-record-preview" :aria-labelledby="headingId" dir="rtl" @close="closed" @click="closeOnBackdrop">
        <section class="flex min-h-full flex-col gap-5 p-6">
            <header class="flex items-start justify-between gap-4">
                <h2 :id="headingId" class="card-title break-words">{{ title }}</h2>
                <button type="button" autofocus class="btn btn-secondary shrink-0" aria-label="إغلاق المعاينة" @click="dialog.close()">إغلاق</button>
            </header>
            <p class="text-sm text-slate-600">معاينة مختصرة من بيانات القائمة الحالية.</p>
            <dl class="divide-y divide-slate-200">
                <div v-for="field in fields" :key="field.label" class="py-3">
                    <dt class="text-sm text-slate-600">{{ field.label }}</dt>
                    <dd class="mt-1 break-words font-semibold text-navy-900">{{ field.value === null || field.value === undefined || field.value === '' ? 'غير متوفر' : field.value }}</dd>
                </div>
            </dl>
            <Link v-if="href" :href="href" class="btn btn-primary mt-auto" @click="dialog.close()">فتح السجل الكامل</Link>
        </section>
    </dialog>
</template>
