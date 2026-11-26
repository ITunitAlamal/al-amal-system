<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import { sources, verificationLabels } from '../../Support/orphans.js';

// Dated economic assessments (bands, not exact amounts). "pre_death" is historical context for a deceased father.
const props = defineProps({ personId: String, items: Array, options: Object, canEdit: Boolean, allowPreDeath: Boolean, householdId: { type: String, default: null }, title: { type: String, default: '' } });
const adding = ref(false);
const form = useForm({ period: props.allowPreDeath ? 'pre_death' : 'current', reference_date: '', period_from: '', period_to: '', employment_status_value_id: null, occupation: '', sector: '',
    income_band_value_id: null, income_source_value_id: null, economic_status_value_id: null, dependents_count: null, earners_count: null, obligations: '', notes: '',
    source: 'self_reported', verification: 'reported', household_public_id: props.householdId });
function submit() { form.post(`/orphans/registry/persons/${props.personId}/economic`, { preserveScroll: true, onSuccess: () => { adding.value = false; form.reset(); } }); }
const empty = list => !list || !list.length;
</script>

<template>
    <section class="space-y-3">
        <h4 v-if="title" class="font-semibold text-navy-800">{{ title }}</h4>
        <ul class="space-y-2">
            <li v-for="e in items" :key="e.id" class="rounded-xl border border-slate-200 p-3 text-sm">
                <div class="flex flex-wrap items-center gap-2"><StatusBadge :label="e.period_label" :tone="e.period === 'pre_death' ? 'muted' : 'info'" /><StatusBadge :label="verificationLabels[e.verification]" :tone="e.verification === 'verified' ? 'success' : 'warning'" />
                    <span class="text-slate-500">{{ e.reference_date || e.created_at?.slice(0, 10) }} · {{ sources[e.source] }} · {{ e.assessed_by_name }}</span></div>
                <p class="mt-1 text-slate-700">{{ [e.employment_status, e.occupation, e.sector, e.income_band && `الدخل: ${e.income_band}`, e.income_source, e.economic_status,
                    e.dependents_count != null && `المُعالون: ${e.dependents_count}`, e.earners_count != null && `المعيلون: ${e.earners_count}`].filter(Boolean).join(' · ') || 'لا تفاصيل' }}</p>
                <p v-if="e.notes" class="text-slate-600">{{ e.notes }}</p>
            </li>
            <li v-if="!items.length" class="text-sm text-slate-500">لا يوجد تقييم اقتصادي مسجل. غياب المعلومة لا يعني عدم الحاجة.</li>
        </ul>
        <button v-if="canEdit && !adding" type="button" class="btn btn-sm btn-secondary" @click="adding = true">إضافة تقييم اقتصادي</button>
        <form v-if="adding" class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">الفترة<select v-model="form.period" class="field mt-1"><option value="current">الوضع الحالي</option><option v-if="allowPreDeath" value="pre_death">قبل الوفاة (تاريخي)</option></select></label>
                <label class="text-sm">تاريخ المعلومة<input v-model="form.reference_date" type="date" class="field mt-1"></label>
                <label class="text-sm">الحالة الوظيفية<select v-model="form.employment_status_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="o in options.employment" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <span v-if="empty(options.employment)" class="text-xs text-slate-500">أضف القيم من «ثوابت النظام».</span></label>
                <label class="text-sm">المهنة<input v-model="form.occupation" class="field mt-1" maxlength="200"></label>
                <label class="text-sm">القطاع<input v-model="form.sector" class="field mt-1" maxlength="150"></label>
                <label class="text-sm">فئة الدخل<select v-model="form.income_band_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="o in options.income_bands" :key="o.id" :value="o.id">{{ o.name }}</option></select>
                    <span v-if="empty(options.income_bands)" class="text-xs text-slate-500">أضف الفئات من «ثوابت النظام».</span></label>
                <label class="text-sm">مصدر الدخل<select v-model="form.income_source_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="o in options.income_sources" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                <label class="text-sm">الوضع الاقتصادي<select v-model="form.economic_status_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="o in options.economic" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                <label class="text-sm">عدد المُعالين<input v-model.number="form.dependents_count" type="number" min="0" max="60" class="field mt-1"></label>
                <label class="text-sm">عدد المعيلين<input v-model.number="form.earners_count" type="number" min="0" max="60" class="field mt-1"></label>
                <label class="text-sm">المصدر<select v-model="form.source" class="field mt-1"><option value="self_reported">إفادة ذاتية</option><option value="caregiver">إفادة مقدم الرعاية</option><option value="document">مستند</option><option value="field_visit">زيارة ميدانية</option></select></label>
                <label class="text-sm">درجة التحقق<select v-model="form.verification" class="field mt-1"><option value="reported">مُفاد</option><option value="verified">متحقق منه</option></select></label>
            </div>
            <label class="block text-sm">الالتزامات القائمة<textarea v-model="form.obligations" class="field mt-1" rows="2" maxlength="1000" /></label>
            <label class="block text-sm">ملاحظات<textarea v-model="form.notes" class="field mt-1" rows="2" maxlength="2000" /></label>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="adding = false">إلغاء</button></div>
        </form>
    </section>
</template>
