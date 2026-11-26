<script setup>
defineProps({ items: { type: Array, default: () => [] }, count: { type: Number, default: 0 } });
defineEmits(['remove', 'clear']);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-sm" aria-label="الفلاتر المطبقة">
        <span class="font-semibold text-navy-800" role="status">النتائج: {{ count }}</span>
        <button v-for="item in items" :key="item.key" type="button" class="inline-flex min-h-10 max-w-full items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 text-blue-900" :aria-label="`إزالة الفلتر: ${item.label}`" @click="$emit('remove', item.key)">
            <span class="break-words">{{ item.label }}</span><span aria-hidden="true">×</span>
        </button>
        <button v-if="items.length" type="button" class="btn btn-sm btn-secondary" @click="$emit('clear')">مسح الكل</button>
    </div>
</template>
