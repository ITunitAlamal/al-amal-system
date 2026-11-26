<script setup>
import { onUnmounted, ref, useId } from 'vue';
import { jsonRequest } from '../../Support/orphans.js';
import { createLatestRequest } from '../../Support/latestRequest.js';

// Find a registered guardian by exact ID or name. Selection only; linking happens on save.
const props = defineProps({ modelValue: { type: Object, default: null }, label: { type: String, default: 'البحث عن وصي مسجل' } });
const emit = defineEmits(['update:modelValue']);
const term = ref('');
const results = ref([]);
const busy = ref(false);
const failure = ref('');
const inputId = useId();
const searched = ref(false);
const lookup = createLatestRequest((url, signal) => jsonRequest(url, null, 'GET', { signal }), state => {
    results.value = state.data?.data || [];
    busy.value = state.loading;
    failure.value = state.error;
});
onUnmounted(() => lookup.cancel());
async function search() {
    if (term.value.trim().length < 2) return;
    searched.value = true;
    await lookup.run(`/orphans/registry/find-guardians?q=${encodeURIComponent(term.value.trim())}`);
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="modelValue" class="flex flex-wrap items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm">
            <strong class="text-navy-800">{{ modelValue.full_name }}</strong><span class="font-mono" dir="ltr">{{ modelValue.id_masked }}</span><span class="text-slate-600">{{ modelValue.reference_no }}</span>
            <button type="button" class="btn btn-sm btn-secondary ms-auto" @click="emit('update:modelValue', null)">تغيير</button>
        </div>
        <template v-else>
            <label :for="inputId" class="field-label">{{ label }}</label>
            <div class="flex gap-2" :aria-busy="busy">
                <input :id="inputId" v-model="term" data-error-field="guardian_public_id" type="search" class="field" placeholder="رقم الهوية كاملًا أو الاسم" autocomplete="off" @keydown.enter.prevent="search">
                <button type="button" class="btn btn-secondary" :disabled="busy || term.trim().length < 2" @click="search">{{ busy ? 'جارٍ البحث…' : 'بحث' }}</button>
            </div>
            <p v-if="failure" class="field-error" role="alert">{{ failure }} <button type="button" class="underline" @click="search">إعادة المحاولة</button></p>
            <ul v-if="results.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
                <li v-for="r in results" :key="r.public_id" class="flex flex-wrap items-center gap-2 p-2">
                    <span class="font-semibold text-navy-800">{{ r.full_name }}</span><span class="font-mono" dir="ltr">{{ r.id_masked }}</span><span class="text-slate-500">{{ r.reference_no }}</span>
                    <button type="button" class="btn btn-sm btn-primary ms-auto" @click="emit('update:modelValue', r); results = []">اختيار</button>
                </li>
            </ul>
            <p v-else-if="searched && !busy && !failure" class="text-xs text-slate-500" role="status">لا توجد نتائج مطابقة؛ جرّب رقم الهوية كاملًا أو جزءًا من الاسم.</p>
        </template>
    </div>
</template>
