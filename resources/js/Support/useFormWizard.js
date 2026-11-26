import { nextTick, ref } from 'vue';
import { useUnsavedChanges } from './useUnsavedChanges.js';

export function useFormWizard(form, stepForError) {
    const step = ref(0);
    const scope = ref(null);
    useUnsavedChanges(() => form.isDirty && !form.processing && !form.wasSuccessful);
    async function move(index) {
        if (form.processing) return;
        // Check only controls on the displayed step. The server still validates the whole payload.
        if (index > step.value) {
            const invalid = [...(scope.value?.querySelectorAll('input, select, textarea') || [])]
                .find(control => control.getClientRects().length && !control.checkValidity());
            if (invalid) { invalid.reportValidity(); return; }
        }
        step.value = index;
        await nextTick();
        const heading = scope.value?.querySelector('[data-step-heading]');
        heading?.focus({ preventScroll: true });
        heading?.scrollIntoView({ block: 'start', behavior: 'instant' });
    }
    async function selectError(field) {
        step.value = stepForError(field);
        await nextTick();
        const control = [...(scope.value?.querySelectorAll('[data-error-field]') || [])]
            .find(element => element.dataset.errorField === field);
        if (control) {
            let parent = control.parentElement;
            while (parent && parent !== scope.value) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
            control.focus();
        }
    }
    const onError = errors => selectError(Object.keys(errors)[0] || '');
    return { step, scope, move, selectError, onError };
}
