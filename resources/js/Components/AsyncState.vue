<script setup>
defineProps({ loading: Boolean, error: { type: String, default: '' } });
defineEmits(['retry']);
</script>
<template>
    <div v-if="error" class="alert-error space-y-3" role="alert"><p>{{ error }}</p><button type="button" class="btn btn-secondary" @click="$emit('retry')">إعادة المحاولة</button></div>
    <div v-else-if="loading" class="space-y-4" role="status" aria-live="polite"><p class="text-sm text-slate-600">جارٍ تحميل البيانات…</p><div aria-hidden="true" class="space-y-3 motion-safe:animate-pulse"><div class="h-5 w-2/3 rounded bg-slate-200"></div><div class="h-20 rounded-xl bg-slate-100"></div><div class="h-20 rounded-xl bg-slate-100"></div></div></div>
</template>
