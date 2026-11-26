<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ page: { type: Object, required: true }, busy: Boolean });
</script>
<template>
    <nav v-if="page.total > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm" aria-label="صفحات النتائج">
        <p class="text-slate-600">عرض {{ page.from ?? 0 }}–{{ page.to ?? 0 }} من {{ page.total }} نتيجة</p>
        <div class="flex items-center gap-2">
            <Link v-if="page.prev_page_url && !busy" :href="page.prev_page_url" class="btn btn-sm btn-secondary" preserve-scroll>السابق</Link><span v-else class="btn btn-sm text-slate-400" aria-disabled="true">السابق</span>
            <span class="tabular-nums">{{ page.current_page }} / {{ page.last_page }}</span>
            <Link v-if="page.next_page_url && !busy" :href="page.next_page_url" class="btn btn-sm btn-secondary" preserve-scroll>التالي</Link><span v-else class="btn btn-sm text-slate-400" aria-disabled="true">التالي</span>
        </div>
    </nav>
</template>
