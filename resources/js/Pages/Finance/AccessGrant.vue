<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ grant: Object, canDecide: Boolean });
const note = ref('');
const busy = ref(false);
const error = ref('');
const message = ref('');
const tone = { pending_approval: 'warning', applied: 'success', rejected: 'danger' }[props.grant.state] || 'muted';

async function decide(decision) {
    if (decision === 'reject' && note.value.trim() === '') {
        error.value = 'اكتب سبب الرفض.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        message.value = (await axios.post(`/finance/access-grants/${props.grant.id}/decide`, { decision, note: note.value || null })).data.message;
        router.reload();
    } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر حفظ القرار';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppLayout title="طلب صلاحية مالية">
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader eyebrow="قسم الحسابات" :title="grant.what" :description="`للموظف: ${grant.user}`" />
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>

            <section class="card space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="card-title">تفاصيل الطلب</h2>
                    <StatusBadge :label="grant.stateLabel" :tone="tone" />
                </div>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">طلبه</dt><dd class="font-semibold">{{ grant.requestedBy }} ({{ grant.requestedAs }})</dd></div>
                    <div><dt class="text-slate-500">تاريخ الطلب</dt><dd class="font-semibold">{{ String(grant.createdAt).slice(0, 16) }}</dd></div>
                    <div><dt class="text-slate-500">المدة</dt><dd class="font-semibold">من {{ grant.validFrom }} {{ grant.validUntil ? `حتى ${grant.validUntil}` : 'دون تاريخ انتهاء' }}</dd></div>
                    <div><dt class="text-slate-500">التصنيف</dt><dd class="font-semibold">{{ grant.exception ? 'استثناء مؤقت من فصل المهام' : grant.sensitive ? 'صلاحية حساسة' : 'عادية' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">السبب</dt><dd class="font-semibold">{{ grant.reason }}</dd></div>
                </dl>
                <div>
                    <h3 class="mb-1 text-sm font-bold text-navy-800">الصلاحيات التي يمنحها</h3>
                    <ul class="list-disc space-y-0.5 pr-5 text-sm"><li v-for="p in grant.permissions" :key="p">{{ p }}</li></ul>
                </div>
                <div v-if="grant.conflicts.length" class="rounded-lg border border-gold-300 bg-gold-50 p-3 text-sm">
                    <strong class="text-gold-800">يجمع مهمتين متعارضتين:</strong>
                    <ul class="mt-1 list-disc pr-5"><li v-for="(pair, i) in grant.conflicts" :key="i">{{ pair[0] }} + {{ pair[1] }}</li></ul>
                    <p class="mt-1 text-xs text-slate-600">حتى مع الاعتماد، لا يتولى الموظف الخطوتين في المعاملة نفسها، وينتهي الاستثناء تلقائيًا في تاريخه.</p>
                </div>
                <div v-if="grant.decidedBy" class="text-sm"><span class="text-slate-500">القرار:</span> {{ grant.decidedBy }} · {{ String(grant.decidedAt).slice(0, 16) }}<span v-if="grant.decisionNote"> — {{ grant.decisionNote }}</span></div>
                <div v-if="grant.endedAt" class="text-sm text-slate-600">انتهى في {{ String(grant.endedAt).slice(0, 10) }}<span v-if="grant.endReason"> — {{ grant.endReason }}</span></div>
            </section>

            <section v-if="canDecide" class="card space-y-3" aria-labelledby="decide-title">
                <h2 id="decide-title" class="card-title">قرار المدير التنفيذي</h2>
                <label for="decision-note" class="field-label">ملاحظة القرار (إلزامية عند الرفض)</label>
                <textarea id="decision-note" v-model="note" rows="3" maxlength="1000" class="field"></textarea>
                <div class="flex flex-wrap gap-3">
                    <button type="button" class="btn btn-primary" :disabled="busy" @click="decide('approve')">اعتماد وتنفيذ</button>
                    <button type="button" class="btn btn-danger" :disabled="busy" @click="decide('reject')">رفض</button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
