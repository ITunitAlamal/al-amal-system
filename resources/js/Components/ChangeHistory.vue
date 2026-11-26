<script setup>
import { ref } from 'vue';
import axios from 'axios';
import StatusBadge from './StatusBadge.vue';

// «سجل التغييرات» of one record, from «سجل النظام». Loaded on demand; shown only to readers the server allows
// (a refusal hides the panel instead of showing an error). No payloads: what happened, who, when, outcome.
const props = defineProps({ subjectType: { type: String, required: true }, subjectId: { type: String, required: true } });
const state = ref('idle');
const rows = ref([]);
const tone = { success: 'success', failure: 'warning', denied: 'danger' };
async function load() {
    state.value = 'loading';
    try {
        rows.value = (await axios.get('/admin/audit-logs/history', { params: { subject_type: props.subjectType, subject_id: props.subjectId } })).data.rows;
        state.value = 'ready';
    } catch (e) {
        state.value = [403, 404].includes(e.response?.status) ? 'hidden' : 'error';
    }
}
</script>

<template>
    <details v-if="state !== 'hidden'" class="card" @toggle="$event.target.open && state === 'idle' && load()">
        <summary class="cursor-pointer font-semibold text-navy-800">سجل التغييرات</summary>
        <p v-if="state === 'loading'" class="mt-3 text-sm text-slate-500" role="status">جارٍ التحميل…</p>
        <p v-else-if="state === 'error'" class="mt-3 text-sm text-red-700" role="alert">تعذر تحميل السجل. <button type="button" class="underline" @click="load">إعادة المحاولة</button></p>
        <template v-else-if="state === 'ready'">
            <ol v-if="rows.length" class="mt-3 space-y-2 border-s-2 border-slate-200 ps-4 text-sm">
                <li v-for="r in rows" :key="r.event_id"><span class="font-semibold text-navy-900">{{ r.action_label }}</span>
                    <StatusBadge v-if="r.outcome !== 'success'" class="ms-1" :label="r.outcome_label" :tone="tone[r.outcome]" />
                    <span class="block text-xs text-slate-500">{{ r.actor }} · <bdi dir="ltr">{{ r.at }}</bdi></span></li>
            </ol>
            <p v-else class="mt-3 text-sm text-slate-500">لا توجد أحداث مسجلة لهذا السجل.</p>
        </template>
    </details>
</template>
