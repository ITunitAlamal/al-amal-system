import { arabicLatinDigitsLocale } from '../../Support/dates.js';

// Numbers in Arabic text with Latin digits (the system's convention), with thousands separators.
const numbers = new Intl.NumberFormat(arabicLatinDigitsLocale);
export const formatNumber = value => (value === null || value === undefined || value === '' ? '—' : numbers.format(Number(value)));

const shortDate = new Intl.DateTimeFormat(arabicLatinDigitsLocale, { day: 'numeric', month: 'short' });
export const formatShortDate = iso => { try { return shortDate.format(new Date(`${iso}T12:00:00`)); } catch { return iso; } };
