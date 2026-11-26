import { arabicLatinDigitsLocale, formatDateTime } from './dates.js';

// Display helpers shared by the KPI pages and the home summary.
const plain = new Intl.NumberFormat(arabicLatinDigitsLocale, { maximumFractionDigits: 2 });
const money = new Intl.NumberFormat(arabicLatinDigitsLocale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function kpiNumber(value) {
    return value === null || value === undefined ? '—' : plain.format(value);
}

/** The figure in its unit: 35.7٪, 3.6 ساعة, 1,800.00 ILS… or a dash when there is no value. */
export function kpiValue(card, value = card.value) {
    if (value === null || value === undefined) return '—';
    switch (card.unit_code) {
        case 'percent': return `${plain.format(value)}٪`;
        case 'money': return `${money.format(value)}${card.currency ? ` ${card.currency}` : ''}`;
        case 'hours': return `${plain.format(value)} ساعة`;
        case 'days': return `${plain.format(value)} يوم`;
        default: return plain.format(value);
    }
}

/** Numerator and denominator amounts: money keeps its currency, counts stay plain. */
export function kpiAmount(card, value) {
    if (value === null || value === undefined) return '—';
    return card.numerator_kind === 'money' ? `${money.format(value)}${card.currency ? ` ${card.currency}` : ''}` : plain.format(value);
}

export function kpiTone(card) {
    if (card.status === 'unavailable') return 'muted';
    if (card.threshold_status === 'critical') return 'danger';
    if (card.threshold_status === 'warning') return 'warning';
    if (card.threshold_status === 'ok' || card.target_met === true) return 'success';
    return 'navy';
}

export const kpiToneText = {
    danger: 'text-red-700', warning: 'text-gold-700', success: 'text-emerald-700', navy: 'text-navy-800', muted: 'text-slate-400',
};

export const kpiToneBar = {
    danger: 'bg-red-500', warning: 'bg-gold-400', success: 'bg-emerald-500', navy: 'bg-navy-700', muted: 'bg-slate-200',
};

export function kpiStatusLabel(card) {
    if (card.status === 'unavailable') return 'غير متاح';
    if (card.status === 'empty') return 'لا سجلات';
    if (card.threshold_status === 'critical') return 'حرج';
    if (card.threshold_status === 'warning') return 'تنبيه';
    if (card.target_met === true) return 'حقق الهدف';
    if (card.target_met === false) return 'دون الهدف';
    return null;
}

export function kpiDateTime(value) {
    return formatDateTime(value, '—');
}
