<script setup>
// Geography from «ثوابت النظام» (governorate → city → area → neighbourhood), plus street and nearest landmark.
import GeographicAddress from '../GeographicAddress.vue';
const props = defineProps({ modelValue: { type: Object, required: true }, errors: { type: Object, default: () => ({}) }, prefix: { type: String, required: true }, legend: String });
</script>
<template>
    <fieldset class="space-y-3">
        <legend v-if="legend" class="field-label">{{ legend }}</legend>
        <GeographicAddress :model-value="modelValue" :errors="errors" :prefix="prefix" optional @update:model-value="Object.assign(modelValue, $event)" />
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm">الشارع<input v-model="modelValue.street" :data-error-field="`${prefix}street`" class="field mt-1" maxlength="200"></label>
            <label class="text-sm">أقرب معلم أو مسجد<input v-model="modelValue.landmark" :data-error-field="`${prefix}landmark`" class="field mt-1" maxlength="200"></label>
        </div>
    </fieldset>
</template>
