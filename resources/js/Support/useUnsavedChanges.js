import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

export function useUnsavedChanges(isDirty) {
    let stopBefore;
    const confirmLeave = () => !isDirty() || window.confirm('لديك تعديلات غير محفوظة. هل تريد المغادرة وفقدان هذه التعديلات؟');
    const beforeUnload = event => {
        if (!isDirty()) return;
        event.preventDefault();
        event.returnValue = '';
    };

    onMounted(() => {
        stopBefore = router.on('before', event => {
            const visit = event.detail.visit;
            if (event.defaultPrevented || visit.prefetch || visit.showProgress === false || visit.method !== 'get') return;
            if (!confirmLeave()) event.preventDefault();
        });
        window.addEventListener('beforeunload', beforeUnload);
    });
    onUnmounted(() => {
        stopBefore?.();
        window.removeEventListener('beforeunload', beforeUnload);
    });
    return { confirmLeave };
}
