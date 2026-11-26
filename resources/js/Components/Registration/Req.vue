<script setup>
import { computed, inject } from 'vue';

// Red asterisk only while the field is required on submission (rules from the server). Outside the registration
// form there is no provider and nothing is shown.
const props = defineProps({ k: { type: String, required: true } });
const rules = inject('fieldRules', null);
const on = computed(() => !!rules?.required(props.k));
</script>

<template>
    <span v-if="on" class="ms-0.5 text-red-600" title="مطلوب عند الإرسال"><span aria-hidden="true">*</span><span class="sr-only">(مطلوب عند الإرسال)</span></span>
</template>
