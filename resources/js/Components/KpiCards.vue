<script setup>
import { Link } from '@inertiajs/vue3';
import StatusBadge from './StatusBadge.vue';
import { kpiAmount, kpiDateTime, kpiNumber, kpiStatusLabel, kpiTone, kpiToneBar, kpiToneText, kpiValue } from '../Support/kpiFormat.js';

/**
 * KPI cards. Interactive cards (the KPI page) offer records, department split, trend and saved readings;
 * compact cards (home page) show the figure and link to the KPI page.
 */
defineProps({
    cards: { type: Array, required: true },
    compact: { type: Boolean, default: false },
    link: { type: String, default: null },
    activeId: { type: Number, default: null },
    actions: { type: Boolean, default: true },
});
defineEmits(['details', 'breakdown', 'trend', 'history']);

const toneBadge = { danger: 'danger', warning: 'warning', success: 'success', navy: 'info', muted: 'muted' };
</script>

<template>
    <div class="grid gap-4" :class="compact ? 'sm:grid-cols-2 xl:grid-cols-3' : 'md:grid-cols-2 2xl:grid-cols-3'">
        <article v-for="card in cards" :id="`kpi-${card.code}`" :key="card.id" class="card relative flex scroll-mt-24 flex-col gap-3 overflow-hidden p-4 sm:p-5"
            :class="activeId === card.id ? 'ring-2 ring-blue-300' : ''" :aria-labelledby="`kpi-title-${card.id}`">
            <span class="absolute inset-y-0 right-0 w-1" :class="kpiToneBar[kpiTone(card)]" aria-hidden="true"></span>
            <header class="flex items-start justify-between gap-2">
                <h3 :id="`kpi-title-${card.id}`" class="text-sm font-bold leading-6 text-navy-800">{{ card.title }}</h3>
                <StatusBadge v-if="kpiStatusLabel(card)" :label="kpiStatusLabel(card)" :tone="toneBadge[kpiTone(card)]" />
            </header>

            <p class="font-display text-3xl font-bold leading-none" :class="kpiToneText[kpiTone(card)]">
                {{ card.status === 'unavailable' ? 'غير متاح' : kpiValue(card) }}
            </p>

            <p v-if="card.reason" class="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">{{ card.reason }}</p>
            <p v-for="note in card.notes ?? []" :key="note" class="rounded-lg bg-gold-50 px-3 py-2 text-xs leading-5 text-gold-900">{{ note }}</p>

            <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                <div class="col-span-2 flex flex-wrap gap-1"><dt class="text-slate-500">الهدف:</dt>
                    <dd class="font-semibold text-navy-800">{{ card.target === null ? 'غير معتمد' : kpiValue(card, card.target) }}
                        <span class="font-normal text-slate-500">({{ card.polarity === 'higher' ? 'الأعلى أفضل' : 'الأقل أفضل' }})</span></dd>
                </div>
                <template v-if="!compact">
                    <div v-if="card.numerator_label" class="col-span-2 flex flex-wrap gap-1"><dt class="text-slate-500">{{ card.denominator_label ? 'البسط' : 'المقياس' }}:</dt>
                        <dd><span class="font-semibold text-navy-800">{{ kpiAmount(card, card.numerator) }}</span> · {{ card.numerator_label }}</dd></div>
                    <div v-if="card.denominator_label" class="col-span-2 flex flex-wrap gap-1"><dt class="text-slate-500">المقام:</dt>
                        <dd><span class="font-semibold text-navy-800">{{ kpiAmount(card, card.denominator) }}</span> · {{ card.denominator_label }}</dd></div>
                    <div class="flex gap-1"><dt class="text-slate-500">حجم العينة:</dt><dd class="font-semibold text-navy-800">{{ kpiNumber(card.sample_size) }}</dd></div>
                    <div class="flex gap-1"><dt class="text-slate-500">النطاق:</dt><dd class="font-semibold text-navy-800">{{ card.scope_label ?? '—' }}</dd></div>
                </template>
            </dl>

            <p class="text-[11px] leading-5 text-slate-500">
                <template v-if="card.snapshot">قراءة لحظية لحالة الآن</template>
                <template v-else-if="card.period">الفترة {{ card.period.from }} — {{ card.period.to }}</template>
                <template v-if="card.currency"> · العملة {{ card.currency }}</template>
                <template v-if="card.computed_at"> · آخر حساب {{ kpiDateTime(card.computed_at) }}</template>
            </p>

            <details v-if="!compact" class="text-xs leading-6 text-slate-600">
                <summary class="cursor-pointer font-semibold text-blue-700">التعريف وطريقة الحساب</summary>
                <div class="mt-2 space-y-1.5">
                    <p v-if="card.description">{{ card.description }}</p>
                    <p><span class="font-semibold">المعادلة:</span> {{ card.formula_label }}{{ card.denominator_label ? ` = ${card.numerator_label} ÷ ${card.denominator_label}` : '' }}</p>
                    <p><span class="font-semibold">المصدر:</span> {{ card.source_label }}</p>
                    <p v-if="card.attribution"><span class="font-semibold">الإسناد للأقسام:</span> {{ card.attribution }}</p>
                    <p><span class="font-semibold">الشمول والاستبعاد:</span> {{ card.basis }}</p>
                    <p v-if="card.lineage?.length"><span class="font-semibold">الجداول:</span> <span dir="ltr" class="font-mono text-[11px]">{{ card.lineage.join(' · ') }}</span></p>
                    <p v-if="card.warning_threshold !== null || card.critical_threshold !== null">
                        <span class="font-semibold">العتبات:</span> تنبيه {{ kpiValue(card, card.warning_threshold) }} · حرجة {{ kpiValue(card, card.critical_threshold) }}</p>
                    <p v-if="card.version"><span class="font-semibold">الإصدار:</span> {{ card.version }} (ساري من {{ card.version_from }}) · رمز {{ card.code }}</p>
                </div>
            </details>

            <div v-if="!compact && actions" class="mt-auto flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                <button type="button" class="btn btn-secondary btn-sm" :disabled="!card.can_drill" @click="$emit('details', card, 'numerator')">السجلات</button>
                <button v-if="card.denominator_label" type="button" class="btn btn-secondary btn-sm" :disabled="!card.can_drill" @click="$emit('details', card, 'denominator')">سجلات المقام</button>
                <button v-if="card.sliceable" type="button" class="btn btn-secondary btn-sm" :disabled="!card.can_drill" @click="$emit('breakdown', card)">حسب القسم</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="$emit('trend', card)">الاتجاه</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="$emit('history', card)">القراءات المحفوظة</button>
            </div>
            <Link v-else-if="link" :href="`${link}#kpi-${card.code}`" class="mt-auto text-xs font-semibold text-blue-700 hover:underline">التفاصيل وطريقة الحساب ←</Link>
        </article>
    </div>
</template>
