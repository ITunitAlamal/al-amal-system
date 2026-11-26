<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

const props = defineProps({
    events: { type: Array, default: () => [] },
    gateway: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const drafts = reactive(Object.fromEntries(props.events.map(event => [event.id, {
    in_app_enabled: Boolean(event.in_app_enabled),
    sms_enabled: Boolean(event.sms_enabled),
    sms_template: event.sms_template || '',
}])));
const errors = reactive({});
const saving = reactive({});

function save(event) {
    saving[event.id] = true;
    errors[event.id] = null;
    router.put(`/admin/notification-settings/${event.id}`, drafts[event.id], {
        preserveScroll: true,
        onError: problems => { errors[event.id] = Object.values(problems).join(' '); },
        onFinish: () => { saving[event.id] = false; },
    });
}
</script>

<template>
    <AppLayout title="إعدادات الإشعارات">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader eyebrow="إعدادات النظام" title="الإعدادات المركزية للإشعارات" description="لكل حدث: إشعار داخل النظام، ورسالة SMS اختيارية بنص ثابت لا يتضمن أي تفاصيل حساسة. كل تعديل يُسجّل في سجل نشاط النظام." />

            <section class="grid gap-3 sm:grid-cols-4">
                <div class="card">
                    <p class="text-sm text-slate-500">بوابة SMS</p>
                    <p class="mt-2"><StatusBadge :label="gateway.enabled ? 'مفعّلة' : 'معطّلة'" :tone="gateway.enabled ? 'success' : 'muted'" /></p>
                </div>
                <div class="card"><p class="text-sm text-slate-500">رصيد الرسائل</p><p class="mt-2 font-display text-2xl font-bold text-navy-800">{{ gateway.balance ?? '—' }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">إشعارات آخر 30 يومًا</p><p class="mt-2 font-display text-2xl font-bold text-navy-800">{{ stats.sent30 ?? 0 }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">SMS مرسلة / متعثرة</p><p class="mt-2 font-display text-2xl font-bold text-navy-800">{{ stats.sms30 ?? 0 }} <span class="text-base text-red-700">/ {{ stats.smsFailed30 ?? 0 }}</span></p></div>
            </section>
            <p v-if="gateway.error" class="alert-warning">تعذر الاستعلام عن رصيد الرسائل: {{ gateway.error }}</p>

            <section class="space-y-3">
                <article v-for="event in events" :key="event.id" class="card space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 class="card-title">{{ event.label_ar }}</h2>
                            <p class="mt-1 text-xs text-slate-500">{{ event.description }}</p>
                        </div>
                        <code class="text-[11px] text-slate-400" dir="ltr">{{ event.event_code }}</code>
                    </div>
                    <div class="flex flex-wrap gap-5 text-sm">
                        <label class="flex items-center gap-2"><input v-model="drafts[event.id].in_app_enabled" type="checkbox" /> إشعار داخل النظام</label>
                        <label class="flex items-center gap-2"><input v-model="drafts[event.id].sms_enabled" type="checkbox" /> رسالة SMS</label>
                    </div>
                    <div v-if="drafts[event.id].sms_enabled">
                        <label :for="`sms-${event.id}`" class="field-label">نص الرسالة (ثابت، بلا أسماء أو تفاصيل)</label>
                        <input :id="`sms-${event.id}`" v-model="drafts[event.id].sms_template" maxlength="300" class="field" />
                        <p class="mt-1 text-xs text-slate-400">{{ drafts[event.id].sms_template.length }} / 300</p>
                    </div>
                    <p v-if="errors[event.id]" class="field-error">{{ errors[event.id] }}</p>
                    <button type="button" :disabled="saving[event.id]" class="btn btn-sm btn-primary" @click="save(event)">حفظ</button>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
