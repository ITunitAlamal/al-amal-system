<script setup>
import { menuIconPaths } from '../Support/menuIcons.js';

// One icon set for the whole application (the sidebar's). Decorative: always pair it with visible text.
defineProps({ name: { type: String, required: true }, size: { type: [Number, String], default: 18 } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="shrink-0">
        <path :d="menuIconPaths[name] || menuIconPaths.folder" />
    </svg>
</template>
