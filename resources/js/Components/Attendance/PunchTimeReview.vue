<script setup>
// HR-20: punches whose wall time a daylight-saving change repeated or skipped; whoever decides confirms the instant
// (PunchTimeReview::queue). Used on the operations page and under «تصحيح البصمات».
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ events: { type: Array, default: () => [] } });
const timeChoice = ref({});
const timeDirection = ref({});
// Each select opens on the reading stored at import.
watch(() => props.events, events => {
    events.forEach(event => { timeChoice.value[event.id] ??= event.readings.find(r => r.stored)?.value; });
}, { immediate: true });
const timeForm = useForm({ occurred_at: '', direction: null, reason: '' });
function resolveTime(event) {
    const value = timeChoice.value[event.id] || event.readings.find(r => r.stored)?.value;
    const reading = event.readings.find(r => r.value === value);
    if (!reading) return;
    const reason = window.prompt(`تأكيد وقت الحركة #${event.id}: ${reading.label}. اكتب سبب التأكيد (5 أحرف على الأقل):`);
    if (!reason) return;
    timeForm.occurred_at = reading.value;
    timeForm.direction = timeDirection.value[event.id] || null;
    timeForm.reason = reason;
    timeForm.post(`/admin/attendance/raw-punches/${event.id}/resolve-time`, { preserveScroll: true });
}
</script>

<template>
    <section class="card space-y-3">
        <h2 class="card-title">حركات بوقت ملتبس عند تغيير التوقيت — بانتظار تأكيد الوقت</h2>
        <p>وقت الجهاز تكرر أو سقط بتغيير التوقيت الصيفي، فلا تُحتسب الحركة حتى يُؤكَّد وقتها الفعلي. اختيار القراءة الأخرى يسجل حركة بذلك الوقت مع حفظ حركة الجهاز الأصلية دليلًا.</p>
        <p v-if="!events.length">لا توجد حركات بانتظار تأكيد الوقت.</p>
        <div v-for="event in events" :key="event.id" class="flex flex-wrap items-center gap-2 text-sm">
            <span>#{{ event.id }} — الجهاز #{{ event.device_id }} — {{ event.employee || `المعرّف ${event.raw_user_id}` }} — {{ event.device_local_time }} ({{ event.device_timezone }})</span>
            <template v-if="event.decidable">
                <select v-model="timeChoice[event.id]" class="form-input" :aria-label="`وقت الحركة #${event.id}`">
                    <option v-for="reading in event.readings" :key="reading.value" :value="reading.value">{{ reading.label }}{{ reading.stored ? ' — كما قُرئ عند الاستيراد' : '' }}</option>
                </select>
                <select v-if="!event.direction_known" v-model="timeDirection[event.id]" class="form-input" :aria-label="`اتجاه الحركة #${event.id}`">
                    <option :value="undefined">الاتجاه (عند اختيار القراءة الأخرى)</option><option value="in">دخول</option><option value="out">خروج</option>
                </select>
                <button class="btn btn-secondary" :disabled="timeForm.processing" @click="resolveTime(event)">تأكيد الوقت</button>
            </template>
            <span v-else class="text-xs text-slate-500">يؤكده رئيس قسم الموارد البشرية، أو المدير التنفيذي إن كانت الحركة لرئيس القسم.</span>
        </div>
        <p v-for="(error, key) in timeForm.errors" :key="key" class="field-error">{{error}}</p>
    </section>
</template>
