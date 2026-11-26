// Turns the server's entitlement explanation into a label, a badge tone and a short note.
// Shared by the balances page and «مساحتي» so both read the same way.

import { arabicLatinDigitsLocale } from './dates.js';

const number = value => new Intl.NumberFormat(arabicLatinDigitsLocale, { maximumFractionDigits: 2 }).format(value ?? 0);

const months = value => {
    if (value === null || value === undefined) return '';
    const years = Math.floor(value / 12);
    const rest = value % 12;
    return [years ? `${years} سنة` : '', rest ? `${rest} شهر` : ''].filter(Boolean).join(' و') || 'أقل من شهر';
};

export function entitlementText(entitlement) {
    if (!entitlement) {
        return { label: 'غير محدد', tone: 'muted', note: '' };
    }

    switch (entitlement.status) {
        case 'defined':
            return {
                label: `${number(entitlement.annual_days)} يوم سنويًا`,
                tone: 'info',
                note: `${entitlement.rule_name || 'وفق السياسة السارية'}${entitlement.contract_specific ? ' (خاصة بنوع العقد)' : ''} · أقدمية ${months(entitlement.service_months)}`,
            };
        case 'below_first_tier': {
            const prorata = entitlement.prorata || {};
            if (prorata.status === 'estimated') {
                return {
                    label: `تقديري ${number(prorata.days)} يوم`,
                    tone: 'warning',
                    note: `احتساب نسبي معتمد: ${number(prorata.rate)} يوم لكل شهر خدمة · للعرض فقط ولا يُضاف للرصيد تلقائيًا.`,
                };
            }
            if (prorata.status === 'not_eligible_yet') {
                return { label: 'قبل الأهلية', tone: 'muted', note: `يبدأ الاحتساب النسبي بعد ${prorata.min_months} شهر خدمة.` };
            }
            return {
                label: 'خدمة أقل من الشريحة الأولى',
                tone: 'muted',
                note: `تبدأ القاعدة المعتمدة بعد ${months(entitlement.first_tier_months)}؛ الاحتساب النسبي بانتظار اعتماد المدير التنفيذي.`,
            };
        }
        case 'missing_employment_data':
            return { label: 'بيانات التوظيف ناقصة', tone: 'warning', note: 'يلزم بند التوظيف وتاريخ مباشرة العمل في ملف الموظف.' };
        case 'not_started':
            return { label: 'لم يباشر العمل بعد', tone: 'muted', note: '' };
        case 'not_launched':
            return { label: 'السياسة غير مفعلة', tone: 'muted', note: 'لم تُطلق سياسة الإجازات بعد.' };
        case 'policy_undefined':
            return { label: 'لا توجد قاعدة مطابقة', tone: 'muted', note: 'لا توجد سياسة معتمدة لبند التوظيف ونوع العقد والأقدمية الحالية.' };
        default:
            return { label: 'غير محدد', tone: 'muted', note: '' };
    }
}
