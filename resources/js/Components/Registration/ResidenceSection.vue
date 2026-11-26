<script setup>
import { computed, inject } from 'vue';
import AddressBlock from './AddressBlock.vue';
import Choice from './Choice.vue';
import FieldError from './FieldError.vue';
import Req from './Req.vue';

const rules = inject('fieldRules', null);
import { blankMove } from '../../Support/registration.js';

// Original and current residence are kept apart: a displacement update never overwrites the original address.
const props = defineProps({ form: Object, options: Object });
const r = computed(() => props.form.residence);
const e = computed(() => props.form.errors);
// Crowding indicator from the declared household size; shown only, never stored.
const roomRatio = computed(() => { const rooms = Number(r.value.current.rooms_count); const members = Number(props.form.household?.declared_members); return rooms > 0 && members > 0 ? (members / rooms).toFixed(1) : null; });
function removeMove(i) { if (window.confirm('حذف هذه الحركة من سجل النزوح؟')) r.value.history.splice(i, 1); }
</script>

<template>
    <section class="card space-y-5">
        <h2 class="card-title" tabindex="-1" data-step-heading>العنوان والسكن والنزوح</h2>
        <AddressBlock :model-value="r.original" :errors="e" prefix="residence.original." legend="عنوان السكن الأصلي (قبل أي نزوح)" />
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="text-sm">نوع السكن الأصلي<select v-model="r.original.housing_type_value_id" data-error-field="residence.original.housing_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="v in options.original_housing_types || []" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
            <label class="text-sm">حالة السكن الأصلي<select v-model="r.original.building_condition_value_id" data-error-field="residence.original.building_condition_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="v in options.building_conditions || []" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
        </div>
        <Choice v-model="r.displaced" label="هل الأسرة نازحة؟" name="residence.displaced" :errors="e" />
        <div v-if="r.displaced === 'yes'" class="grid gap-3 sm:grid-cols-3">
            <label class="text-sm">تاريخ بدء النزوح<input v-model="r.displaced_since" type="date" data-error-field="residence.displaced_since" class="field mt-1"></label>
            <label class="text-sm">عدد مرات النزوح<input v-model="r.displacement_count" type="number" min="0" max="100" data-error-field="residence.displacement_count" class="field mt-1" dir="ltr"></label>
            <label class="text-sm">نوع المأوى الحالي<select v-model="r.shelter_type_value_id" data-error-field="residence.shelter_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="s in options.shelter" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
            <label class="text-sm">تاريخ آخر انتقال<input v-model="r.last_moved_on" type="date" data-error-field="residence.last_moved_on" class="field mt-1"><FieldError :errors="e" name="residence.last_moved_on" /></label>
        </div>
        <AddressBlock :model-value="r.current" :errors="e" prefix="residence.current." :legend="r.displaced === 'yes' ? 'مكان السكن الحالي (مكان النزوح)' : 'عنوان السكن الحالي (اتركه فارغًا إن كان نفس الأصلي)'" />
        <FieldError :errors="e" name="residence.current.governorate_value_id" />
        <label v-if="rules?.required('residence.location_missing_reason') || r.location_missing_reason" class="block text-sm sm:w-2/3">لم يُحدد أي عنوان — سبب عدم التحديد<Req k="residence.location_missing_reason" /><input v-model="r.location_missing_reason" data-error-field="residence.location_missing_reason" class="field mt-1" maxlength="300" placeholder="مثال: الأسرة تتنقل بين مراكز الإيواء؛ يُحدد في الزيارة"><FieldError :errors="e" name="residence.location_missing_reason" /></label>
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="text-sm">صفة السكن الحالي<select v-model="r.current.dwelling_condition_value_id" data-error-field="residence.current.dwelling_condition_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="v in options.dwelling_conditions || []" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
            <label class="text-sm">حيازة السكن الحالي<select v-model="form.household.tenure_value_id" data-error-field="household.tenure_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="t in options.tenure" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
            <label class="text-sm">استقرار المأوى<select v-model="r.current.stability" data-error-field="residence.current.stability" class="field mt-1"><option value="">غير محدد</option><option v-for="(label, key) in options.shelter_stability" :key="key" :value="key">{{ label }}</option></select></label>
            <label class="text-sm">حالة المسكن الحالي<input v-model="r.current.housing_condition" data-error-field="residence.current.housing_condition" class="field mt-1" maxlength="200"></label>
            <label class="text-sm">عدد الغرف<input v-model="r.current.rooms_count" type="number" min="0" max="100" data-error-field="residence.current.rooms_count" class="field mt-1" dir="ltr"><span v-if="roomRatio" class="text-xs text-slate-500">نحو {{ roomRatio }} فرد في الغرفة</span></label>
            <label class="text-sm">مساحة المسكن (م²)<input v-model="r.current.building_area_sqm" type="number" min="1" step="0.01" data-error-field="residence.current.building_area_sqm" class="field mt-1" dir="ltr"></label>
            <label class="text-sm">نوع المبنى<select v-model="r.current.building_type_value_id" data-error-field="residence.current.building_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="v in options.building_types || []" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
            <label class="text-sm">حالة المبنى<select v-model="r.current.building_condition_value_id" data-error-field="residence.current.building_condition_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="v in options.building_conditions || []" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
            <label class="text-sm">حالة الأثاث<select v-model="r.current.furnishing_condition_value_id" data-error-field="residence.current.furnishing_condition_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="v in options.furnishing_conditions || []" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
            <label class="text-sm">إمكانية الوصول والتواصل<input v-model="r.contact_accessibility" data-error-field="residence.contact_accessibility" class="field mt-1" maxlength="200" placeholder="مثال: يصعب الوصول؛ التواصل عبر جار"></label>
            <label class="text-sm">احتياجات سكن ميسّر (إن وجدت)<input v-model="r.accessibility_needs" data-error-field="residence.accessibility_needs" class="field mt-1" maxlength="500" placeholder="مثال: منحدر لكرسي متحرك"></label>
            <label class="text-sm">مصدر معلومات السكن<select v-model="r.source" data-error-field="residence.source" class="field mt-1"><option value="">غير محدد</option><option v-for="(label, key) in options.info_sources" :key="key" :value="key">{{ label }}</option></select></label>
        </div>
        <Choice v-model="r.hosted" label="هل الأسرة مستضافة لدى أسرة أخرى أو في سكن مؤقت؟" name="residence.hosted" :errors="e" />
        <label v-if="r.hosted === 'yes'" class="block text-sm sm:w-1/2">لدى من تقيم الأسرة؟<input v-model="r.host_note" data-error-field="residence.host_note" class="field mt-1" maxlength="300" placeholder="مثال: لدى أسرة الخال"><FieldError :errors="e" name="residence.host_note" /></label>
        <p class="text-xs text-slate-500">الإيجار يُدخل في «بيانات الأسرة». عنوان الأم أو الوصي إن اختلف يُدخل في قسمه، ولا يُفترض أنهم يسكنون مع الأسرة.</p>

        <details v-if="r.displaced === 'yes'" class="rounded-xl border border-slate-200 p-4" :open="r.history.length > 0">
            <summary class="cursor-pointer font-semibold text-navy-800">سجل حركات النزوح السابقة (اختياري)</summary>
            <div v-for="(move, i) in r.history" :key="i" class="mt-3 space-y-3 rounded-lg border border-slate-200 p-3">
                <AddressBlock :model-value="move" :errors="e" :prefix="`residence.history.${i}.`" :legend="`الحركة ${i + 1}`" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">تاريخ الانتقال إليه<input v-model="move.moved_on" type="date" class="field mt-1"></label>
                    <label class="text-sm">تاريخ المغادرة<input v-model="move.left_on" type="date" :data-error-field="`residence.history.${i}.left_on`" class="field mt-1"><FieldError :errors="e" :name="`residence.history.${i}.left_on`" /></label>
                    <label class="text-sm">سبب الانتقال<input v-model="move.reason" class="field mt-1" maxlength="300"></label>
                </div>
                <button type="button" class="btn btn-sm btn-danger" @click="removeMove(i)">حذف الحركة</button>
            </div>
            <button v-if="r.history.length < 20" type="button" class="btn btn-sm btn-secondary mt-3" @click="r.history.push(blankMove())">إضافة حركة نزوح</button>
        </details>
    </section>
</template>
