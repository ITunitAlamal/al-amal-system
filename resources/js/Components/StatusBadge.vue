<script setup>
import { computed } from 'vue';

/**
 * A status pill. Pass a known status code (it picks label and tone), or an explicit label and tone.
 */
const props = defineProps({
    status: { type: String, default: '' },
    label: { type: String, default: '' },
    tone: { type: String, default: '' },
});

const known = {
    draft: ['مسودة', 'warning'],
    submitted: ['مُرسل', 'info'],
    pending: ['قيد الانتظار', 'info'],
    in_progress: ['قيد المعالجة', 'info'],
    manager_approved: ['بانتظار الاعتماد', 'info'],
    pending_registration: ['معتمد — بانتظار التسجيل', 'info'],
    approved: ['معتمد', 'success'],
    completed: ['مكتمل', 'success'],
    returned: ['مُعاد للتعديل', 'warning'],
    rejected: ['مرفوض', 'danger'],
    cancelled: ['ملغى', 'muted'],
    active: ['نشط', 'success'],
    inactive: ['موقوف', 'muted'],
    published: ['منشور', 'success'],
    retired: ['مؤرشف', 'muted'],
    pending_review: ['بانتظار المراجعة', 'info'],
    pending_approval: ['بانتظار الاعتماد', 'info'],
    waiting: ['بالانتظار', 'warning'],
    needs_information: ['بحاجة إلى استكمال', 'warning'],
    implemented: ['منفّذ', 'success'],
    open: ['مفتوح', 'info'],
    rented: ['مؤجرة', 'navy'],
    vacant: ['شاغرة', 'success'],
    reserved: ['محجوزة', 'warning'],
    unavailable: ['غير متاحة', 'muted'],
    expired: ['منتهٍ', 'muted'],
    terminated: ['مُنهى', 'muted'],
    sent: ['مُرسل', 'info'],
    in_review: ['قيد الدراسة والقرار', 'info'],
    waitlisted: ['قائمة الانتظار', 'warning'],
    contracted: ['تم التعاقد', 'success'],
    renewed: ['جُدِّد', 'muted'],
    superseded: ['استُبدل بملحق', 'muted'],
    closed: ['مغلق', 'success'],
    posted: ['مُرحّلة', 'success'],
    issued: ['صادر', 'info'],
    maintenance: ['تحت الصيانة', 'warning'],
    suspended: ['موقوفة', 'muted'],
    out_of_service: ['غير قابلة للتأجير', 'muted'],
    paid: ['مسدد', 'success'],
    partial: ['مسدد جزئيًا', 'warning'],
    unpaid: ['غير مسدد', 'info'],
    overdue: ['متأخر', 'danger'],
    reversed: ['معكوس', 'muted'],
};

const tones = {
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    info: 'bg-blue-50 text-blue-800 ring-blue-200',
    warning: 'bg-gold-50 text-gold-800 ring-gold-200',
    danger: 'bg-red-50 text-red-700 ring-red-200',
    muted: 'bg-slate-100 text-slate-600 ring-slate-200',
    navy: 'bg-navy-50 text-navy-800 ring-navy-200',
};

const text = computed(() => props.label || known[props.status]?.[0] || props.status || '—');
const css = computed(() => tones[props.tone || known[props.status]?.[1] || 'muted'] || tones.muted);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold leading-5 ring-1 ring-inset" :class="css">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
        {{ text }}
    </span>
</template>
