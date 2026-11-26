<script setup>
import { ref, watch } from 'vue';
import AppDialog from './AppDialog.vue';
const props = defineProps({ decision: { type: Object, default: null }, busy: Boolean, error: { type: String, default: '' } });
defineEmits(['close', 'confirm']);
const note = ref('');
watch(() => props.decision, () => { note.value = ''; });
</script>
<template>
    <AppDialog :open="!!decision" :title="decision?.title || 'تأكيد القرار'" :description="decision?.description || ''" :busy="busy" @close="$emit('close')">
        <form v-if="decision" class="space-y-4" @submit.prevent="$emit('confirm', note)">
            <label class="field-label">{{ decision.required ? 'سبب القرار (مطلوب)' : 'ملاحظة القرار (اختياري)' }}<textarea v-model="note" class="field mt-2 font-normal" rows="4" :maxlength="decision.maxLength || 2000" :required="decision.required" :disabled="busy" /></label>
            <p v-if="error" class="alert-error" role="alert">{{ error }}</p>
            <div class="flex flex-wrap gap-2"><button type="submit" class="btn" :class="decision.danger ? 'btn-danger' : 'btn-primary'" :disabled="busy">{{ busy ? 'جارٍ حفظ القرار…' : (decision.confirmLabel || 'تأكيد القرار') }}</button><button type="button" class="btn btn-secondary" :disabled="busy" @click="$emit('close')">إلغاء</button></div>
        </form>
    </AppDialog>
</template>
