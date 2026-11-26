<script setup>
import { computed } from 'vue';

// Public-safe stages of a family request. «بحاجة إلى استكمال» is shown as the current step when the department asks for it.
const props = defineProps({ status: { type: String, required: true }, approved: Boolean });
const stages = computed(() => {
    const decided = ['completed', 'rejected'].includes(props.status);
    const rank = { draft: 0, submitted: 1, under_review: 2, resubmitted: 2, needs_completion: 2, completed: 3, rejected: 3 }[props.status] ?? 0;
    const last = props.status === 'rejected' || (props.status === 'completed' && !props.approved) ? 'صدر القرار: غير معتمد' : decided ? 'صدر القرار: معتمد' : 'القرار';
    return [
        { label: 'مسودة', hint: 'تُحفظ تلقائيًا' },
        { label: 'تم التقديم', hint: 'وصل الطلب إلى القسم' },
        { label: props.status === 'needs_completion' ? 'مطلوب استكمال منك' : 'قيد المراجعة', hint: props.status === 'needs_completion' ? 'أكمل المطلوب وأعد الإرسال' : 'يراجع القسم البيانات' },
        { label: last, hint: 'تُعتمد الوصاية لكل طفل على حدة' },
    ].map((s, i) => ({ ...s, state: i < rank || (decided && i === rank) ? 'done' : i === rank ? (props.status === 'needs_completion' ? 'attention' : 'current') : 'next' }));
});
const dot = { done: 'bg-emerald-600 text-white', current: 'bg-blue-600 text-white ring-4 ring-blue-100', attention: 'bg-gold-500 text-white ring-4 ring-gold-100', next: 'bg-slate-200 text-slate-500' };
const word = { done: 'منجزة', current: 'المرحلة الحالية', attention: 'تحتاج إجراءك', next: 'لاحقة' };
</script>

<template>
    <ol class="grid gap-3 sm:grid-cols-4" aria-label="مراحل الطلب">
        <li v-for="(s, i) in stages" :key="i" class="flex items-start gap-3 sm:flex-col sm:items-center sm:text-center" :aria-current="['current', 'attention'].includes(s.state) ? 'step' : undefined">
            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold', dot[s.state]]" aria-hidden="true">{{ s.state === 'done' ? '✓' : i + 1 }}</span>
            <span><span :class="['block text-sm font-semibold', s.state === 'next' ? 'text-slate-500' : 'text-navy-900']">{{ s.label }}</span>
                <span class="block text-xs text-slate-500">{{ s.hint }}<span class="sr-only"> — {{ word[s.state] }}</span></span></span>
        </li>
    </ol>
</template>
