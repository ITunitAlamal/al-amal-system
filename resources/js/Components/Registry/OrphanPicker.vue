<script setup>
import { ref } from 'vue';
import { jsonRequest } from '../../Support/orphans.js';

// Search orphan files (exact ID, reference or name) and emit the chosen one.
defineProps({ label: { type: String, default: 'البحث عن يتيم' } });
const emit = defineEmits(['pick']);
const term = ref('');
const results = ref([]);
const busy = ref(false);
async function search() {
    if (term.value.trim().length < 2) return;
    busy.value = true;
    try { results.value = (await jsonRequest(`/orphans/registry/find-orphans?q=${encodeURIComponent(term.value.trim())}`)).data; } finally { busy.value = false; }
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex gap-2"><input v-model="term" type="search" class="field" :placeholder="`${label}: الاسم أو ORP-… أو رقم الهوية كاملًا`" @keydown.enter.prevent="search">
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="search">بحث</button></div>
        <ul v-if="results.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
            <li v-for="r in results" :key="r.public_id" class="flex flex-wrap items-center gap-2 p-2"><span class="font-semibold">{{ r.full_name }}</span><span class="font-mono text-xs" dir="ltr">{{ r.reference_no }} · {{ r.id_masked }}</span>
                <button type="button" class="btn btn-sm btn-primary ms-auto" @click="emit('pick', r); results = []; term = ''">اختيار</button></li>
        </ul>
    </div>
</template>
