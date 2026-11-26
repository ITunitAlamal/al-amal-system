import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

export function useDecision() {
    const decision = ref(null);
    const busy = ref(false);
    const error = ref('');
    function ask(options) { if (!busy.value) { error.value = ''; decision.value = options; } }
    function close() { if (!busy.value) decision.value = null; }
    function confirm(note) {
        if (busy.value || !decision.value) return;
        const current = decision.value;
        if (current.required && !note.trim()) { error.value = 'اكتب سبب القرار قبل التأكيد.'; return; }
        busy.value = true;
        error.value = '';
        router.post(current.url, { ...current.data, [current.field || 'note']: note }, {
            preserveScroll: true,
            onSuccess: () => { decision.value = null; },
            onError: errors => { error.value = Object.values(errors).flat().join('، ') || 'تعذر حفظ القرار.'; },
            onFinish: () => { busy.value = false; },
        });
    }
    return { decision, busy, error, ask, close, confirm };
}
