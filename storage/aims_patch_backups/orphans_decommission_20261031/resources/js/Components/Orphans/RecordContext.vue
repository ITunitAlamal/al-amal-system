<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    resultsUrl: { type: String, required: true },
    householdUrl: { type: String, default: '' },
    householdReference: { type: String, default: '' },
    label: { type: String, required: true },
});
</script>

<template>
    <nav aria-label="مسار الملف" class="flex flex-wrap items-center gap-3 text-sm">
        <Link :href="resultsUrl" class="btn btn-secondary btn-sm focus-visible:outline-2 focus-visible:outline-offset-2">
            العودة إلى النتائج
        </Link>
        <Link v-if="householdUrl" :href="householdUrl" class="inline-flex items-center gap-2 font-semibold text-blue-700 hover:underline">
            ملف الأسرة {{ householdReference }}
        </Link>
        <span aria-current="page" class="text-slate-600">{{ label }}</span>
    </nav>
</template>
