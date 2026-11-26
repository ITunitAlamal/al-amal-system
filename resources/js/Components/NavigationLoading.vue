<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useListFilters } from '../Support/useListFilters.js';

useListFilters();

const visits = ref(new Map());
const visible = ref(false);
const busy = computed(() => visits.value.size > 0);
const message = ref('جارٍ تحميل الصفحة…');
const minimumVisibleDuration = 650;
let visibleSince = 0;
let delay;
let hideDelay;
let stopStart;
let stopFinish;
const slow = ref(false);
let slowTimer;

onMounted(() => {
    stopStart = router.on('start', ({ detail: { visit } }) => {
        if (visit.showProgress === false || visit.prefetch) return;
        clearTimeout(hideDelay);
        visits.value.set(visit.id, visit.method.toLowerCase());
        if (!slowTimer) slowTimer = setTimeout(() => { slow.value = true; }, 8000);
        message.value = [...visits.value.values()].some(method => method !== 'get')
            ? 'جارٍ تنفيذ العملية…' : 'جارٍ تحميل الصفحة…';
        if (!visible.value && !delay) {
            delay = setTimeout(() => {
                delay = undefined;
                visible.value = busy.value;
                if (visible.value) visibleSince = performance.now();
            }, 250);
        }
    });
    stopFinish = router.on('finish', ({ detail: { visit } }) => {
        if (!visits.value.delete(visit.id)) return;
        if (!busy.value) {
            clearTimeout(slowTimer);
            slowTimer = undefined;
            slow.value = false;
            clearTimeout(delay);
            delay = undefined;
            const remaining = minimumVisibleDuration - (performance.now() - visibleSince);
            if (visible.value && remaining > 0) {
                hideDelay = setTimeout(() => { visible.value = false; }, remaining);
            } else {
                visible.value = false;
            }
        }
    });
});

onUnmounted(() => {
    stopStart?.();
    stopFinish?.();
    clearTimeout(delay);
    clearTimeout(hideDelay);
    clearTimeout(slowTimer);
});
</script>

<template>
    <div>
        <div :aria-busy="busy"><slot /></div>
        <div class="pointer-events-none fixed inset-x-0 top-0 z-[100]" dir="rtl" role="status" aria-live="polite" aria-atomic="true">
            <template v-if="visible">
                <div class="relative h-1 overflow-hidden bg-gold-100" aria-hidden="true">
                    <div class="aims-navigation-track h-full w-1/3 bg-gold-400"></div>
                </div>
                <div class="relative mx-auto mt-4 flex w-fit max-w-[90vw] items-center gap-3 rounded-2xl border border-gold-200 bg-white px-5 py-3 font-sans text-sm font-semibold text-navy-800 shadow-lg">
                    <span class="h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-navy-100 border-t-blue-600 motion-reduce:animate-none" aria-hidden="true"></span>
                    <span>{{ slow ? 'الاتصال يستغرق وقتًا أطول؛ انتظر نتيجة الطلب قبل تكرار الإجراء.' : message }}</span>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.aims-navigation-track { animation: aims-progress-slide 1.15s ease-in-out infinite; }
@media (prefers-reduced-motion: reduce) {
    .aims-navigation-track { animation: none; width: 100%; }
}
</style>
