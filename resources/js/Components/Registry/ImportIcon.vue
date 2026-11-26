<script setup>
// «الاستيراد الذكي»: a sheet with a down arrow and a small sparkle accent (inline SVG, inherits text colour, RTL-neutral).
defineProps({ size: { type: [Number, String], default: 18 } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="shrink-0 text-blue-700">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M8 13h3M8 17h3" /><path d="M15 12v6M12.5 15.5 15 18l2.5-2.5" />
        <path d="M19.5 1.5v2M18.5 2.5h2" class="text-gold-500" stroke="currentColor" />
    </svg>
</template>
