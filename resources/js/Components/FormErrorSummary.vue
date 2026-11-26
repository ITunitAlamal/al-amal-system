<script setup>
import { nextTick, ref } from 'vue';

defineProps({ errors: { type: Object, default: () => ({}) } });
const emit = defineEmits(['select']);
const summary = ref(null);

async function selectField(field) {
    emit('select', field);
    await nextTick();
    const scope = summary.value?.closest('form, [data-error-scope]');
    if (!scope) return;
    const targets = [...scope.querySelectorAll('[data-error-field]')];
    const target = targets.find(element => element.dataset.errorField === field && !element.disabled)
        || targets.find(element => element.dataset.errorField.startsWith(`${field}.`) && !element.disabled);
    if (!target) return;
    target.focus({ preventScroll: true });
    target.scrollIntoView({ block: 'center', behavior: 'instant' });
}
</script>

<template>
    <div v-if="Object.keys(errors).length" ref="summary" class="alert-error" role="alert" tabindex="-1">
        <p class="font-semibold">تعذّر الحفظ. راجع الملاحظات التالية:</p>
        <ul class="mt-2 list-inside list-disc space-y-1">
            <li v-for="(message, field) in errors" :key="field">
                <button type="button" class="rounded text-start underline decoration-red-300 underline-offset-4 hover:decoration-red-700" @click="selectField(field)">{{ Array.isArray(message) ? message.join('، ') : message }}</button>
            </li>
        </ul>
        <p class="mt-2 text-xs">اضغط على الملاحظة للوصول إلى الحقل المرتبط بها، إن وُجد.</p>
    </div>
</template>
