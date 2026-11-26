<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

// Household = social/residential context only. Joining a household never grants guardianship.
const props = defineProps({ personId: String, households: Array, options: Object, canEdit: Boolean });
const mode = ref('');
const form = useForm({ action: 'create', reference_no: '', relation_value_id: null, members_count: null, dependents_count: null, earners_count: null, living_conditions: '' });
function submit() { form.action = mode.value; form.post(`/orphans/registry/persons/${props.personId}/household`, { preserveScroll: true, onSuccess: () => { mode.value = ''; form.reset(); } }); }
function leave(h) { if (window.confirm('إنهاء عضوية هذا الشخص في الأسرة؟ تبقى العضوية السابقة في السجل.')) router.post(`/orphans/registry/persons/${props.personId}/household`, { action: 'leave', membership_id: h.membership_id }, { preserveScroll: true }); }
</script>

<template>
    <section class="space-y-3">
        <ul class="space-y-2">
            <li v-for="h in households" :key="h.membership_id" class="rounded-xl border border-slate-200 p-3 text-sm">
                <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-navy-800">الأسرة <span class="font-mono" dir="ltr">{{ h.reference_no }}</span></span>
                    <StatusBadge v-if="h.ends_on" :label="`انتهت العضوية ${h.ends_on}`" tone="muted" /><span v-if="h.relation" class="text-slate-500">· {{ h.relation }}</span>
                    <a :href="`/orphans/households/${h.public_id}/case-study`" class="btn btn-sm btn-secondary ms-auto">دراسة الحالة{{ h.case_study ? ` (${h.case_study})` : '' }}</a>
                    <button v-if="canEdit && !h.ends_on" type="button" class="btn btn-sm btn-secondary" @click="leave(h)">إنهاء العضوية</button></div>
                <p class="mt-1 text-slate-700">جهة التواصل: {{ h.contact_name || 'غير محددة' }} · الأفراد: {{ h.members_count ?? 'غير متوفر' }} · المُعالون: {{ h.dependents_count ?? 'غير متوفر' }} · المعيلون: {{ h.earners_count ?? 'غير متوفر' }}</p>
                <p v-if="h.members.length" class="text-slate-600">الأعضاء الحاليون: {{ h.members.map(m => m.full_name + (m.relation ? ` (${m.relation})` : '')).join('، ') }}</p>
                <p v-if="h.addresses.length" class="text-slate-600">العنوان: {{ h.addresses[0].label }}</p>
            </li>
            <li v-if="!households.length" class="text-sm text-slate-500">غير مرتبط بأسرة. الأسرة سياق اجتماعي اختياري ولا تحدد الوصاية.</li>
        </ul>
        <div v-if="canEdit && !mode" class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-secondary" @click="mode = 'create'">إنشاء أسرة</button>
            <button type="button" class="btn btn-sm btn-secondary" @click="mode = 'join'">الانضمام لأسرة مسجلة</button>
        </div>
        <form v-if="mode" class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="submit">
            <label v-if="mode === 'join'" class="block text-sm">رقم الأسرة<input v-model="form.reference_no" class="field mt-1 font-mono" dir="ltr" placeholder="HH-2026-000001"></label>
            <label class="block text-sm">صلة القرابة داخل الأسرة<select v-model="form.relation_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select></label>
            <div v-if="mode === 'create'" class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">عدد الأفراد<input v-model.number="form.members_count" type="number" min="1" max="60" class="field mt-1"></label>
                <label class="text-sm">المُعالون<input v-model.number="form.dependents_count" type="number" min="0" max="60" class="field mt-1"></label>
                <label class="text-sm">المعيلون<input v-model.number="form.earners_count" type="number" min="0" max="60" class="field mt-1"></label>
                <label class="text-sm sm:col-span-3">ظروف المعيشة<textarea v-model="form.living_conditions" class="field mt-1" rows="2" maxlength="2000" /></label>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="mode = ''">إلغاء</button></div>
        </form>
    </section>
</template>
