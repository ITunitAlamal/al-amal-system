<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import GeographicAddress from '../GeographicAddress.vue';
import StatusBadge from '../StatusBadge.vue';
import { emptyAddress } from '../../Support/orphans.js';

// Original, current and displacement addresses as dated episodes; a new current address closes the previous one.
const props = defineProps({ personId: String, addresses: Array, options: Object, canEdit: Boolean, householdId: { type: String, default: null } });
const adding = ref(false);
const form = useForm({ kind: 'current', ...emptyAddress(), address_text: '', shelter_type_value_id: null, tenure_value_id: null, stability: '', services_access: '', starts_on: '', ends_on: '', household_public_id: props.householdId });
function submit() { form.post(`/orphans/registry/persons/${props.personId}/addresses`, { preserveScroll: true, onSuccess: () => { adding.value = false; form.reset(); } }); }
function close(address) {
    const ends = window.prompt('تاريخ نهاية هذه الفترة (YYYY-MM-DD):', new Date().toISOString().slice(0, 10));
    if (ends) router.post(`/orphans/registry/addresses/${address.id}/close`, { ends_on: ends }, { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-3">
        <ul class="space-y-2">
            <li v-for="a in addresses" :key="a.id" class="rounded-xl border border-slate-200 p-3 text-sm">
                <div class="flex flex-wrap items-center gap-2"><StatusBadge :label="a.kind_label" :tone="a.kind === 'displacement' ? 'warning' : 'info'" /><StatusBadge v-if="a.ends_on" label="منتهية" tone="muted" />
                    <span class="font-semibold text-navy-800">{{ a.label }}</span>
                    <button v-if="canEdit && !a.ends_on && a.kind !== 'current'" type="button" class="btn btn-sm btn-secondary ms-auto" @click="close(a)">إنهاء الفترة</button></div>
                <p class="mt-1 text-slate-600">{{ [a.address_text, a.shelter && `المأوى: ${a.shelter}`, a.tenure && `الحيازة: ${a.tenure}`, a.stability && `الاستقرار: ${a.stability}`, a.services_access].filter(Boolean).join(' · ') }}</p>
                <p class="text-xs text-slate-500">{{ a.starts_on || '—' }} ← {{ a.ends_on || 'حتى الآن' }}</p>
            </li>
            <li v-if="!addresses.length" class="text-sm text-slate-500">لا توجد عناوين مسجلة.</li>
        </ul>
        <button v-if="canEdit && !adding" type="button" class="btn btn-sm btn-secondary" @click="adding = true">إضافة عنوان أو فترة نزوح</button>
        <form v-if="adding" class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="submit">
            <label class="block text-sm">النوع<select v-model="form.kind" class="field mt-1"><option value="current">العنوان الحالي</option><option value="original">العنوان الأصلي</option><option value="displacement">نزوح</option></select></label>
            <GeographicAddress :model-value="form" :errors="form.errors" optional @update:model-value="Object.assign(form, $event)" />
            <label class="block text-sm">العنوان كما ورد (اختياري)<input v-model="form.address_text" class="field mt-1" maxlength="500"></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">نوع المأوى<select v-model="form.shelter_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="s in options.shelter" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                <label class="text-sm">الحيازة<select v-model="form.tenure_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="t in options.tenure" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                <label class="text-sm">الاستقرار<input v-model="form.stability" class="field mt-1" maxlength="100"></label>
                <label class="text-sm">الوصول للخدمات الأساسية<input v-model="form.services_access" class="field mt-1" maxlength="300"></label>
                <label class="text-sm">من تاريخ<input v-model="form.starts_on" type="date" class="field mt-1"></label>
                <label v-if="form.kind !== 'current'" class="text-sm">إلى تاريخ (اختياري)<input v-model="form.ends_on" type="date" class="field mt-1"></label>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="adding = false">إلغاء</button></div>
        </form>
    </section>
</template>
