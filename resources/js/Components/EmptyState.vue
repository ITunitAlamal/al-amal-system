<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col items-center rounded-2xl border border-dashed border-navy-200 bg-navy-50/50 px-5 py-10 text-center">
        <span class="flex h-12 w-12 items-center justify-center rounded-2xl border border-navy-100 bg-white text-navy-500" aria-hidden="true">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 5h16v15H4zM8 2v6m8-6v6M8 12h8m-8 4h5" /></svg>
        </span>
        <p class="mt-4 text-sm font-semibold text-navy-800">{{ title }}</p>
        <p v-if="description" class="mt-2 max-w-md text-sm leading-6 text-slate-600">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
