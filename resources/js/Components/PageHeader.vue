<script setup>
defineProps({
    title: { type: String, required: true },
    eyebrow: { type: String, default: '' },
    description: { type: String, default: '' },
});
</script>

<template>
    <header class="aims-page-header flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1 border-s-4 border-gold-400 ps-4">
            <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
            <h1 class="mt-1 text-xl font-bold leading-relaxed text-navy-800 sm:text-2xl">{{ title }}</h1>
            <p v-if="description" class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">{{ description }}</p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.default" class="aims-page-actions flex flex-wrap items-center gap-2 sm:max-w-md sm:justify-end">
            <slot />
        </div>
    </header>
</template>
