<script setup>
defineProps({ errors: { type: Object, default: () => ({}) }, name: { type: String, required: true } });
</script>
<template><span v-if="errors[name]" class="field-error" role="alert">{{ errors[name] }}</span></template>
