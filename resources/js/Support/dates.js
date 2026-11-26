// Reads every date/time shape the server sends (ISO, «YYYY-MM-DD HH:MM:SS», PostgreSQL «…+02» or «…+0230»
// offsets, microseconds) and never throws: an unreadable value gives null, so a page keeps rendering.

export const arabicLatinDigitsLocale = 'ar-u-nu-latn';

/** @returns {Date|null} */
export function parseDate(value) {
    if (value === null || value === undefined || value === '') return null;
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
    if (typeof value === 'number') return toValid(new Date(value));
    let text = String(value).trim().replace(' ', 'T');
    if (/T\d{2}:\d{2}/.test(text)) {
        // PostgreSQL short offsets after the time: +02 → +02:00, +0230 → +02:30 (a date alone is left as is).
        text = text.replace(/(:\d{2}(?:\.\d+)?)([+-]\d{2})$/, '$1$2:00').replace(/(:\d{2}(?:\.\d+)?)([+-]\d{2})(\d{2})$/, '$1$2:$3');
        // Fractions longer than milliseconds are not accepted everywhere.
        text = text.replace(/(\.\d{3})\d+/, '$1');
    }
    return toValid(new Date(text));
}

/** Formats using Arabic month names and Latin digits; returns the fallback for unreadable values. */
export function formatDate(value, options = { day: 'numeric', month: 'short', year: 'numeric' }, fallback = '') {
    const date = parseDate(value);
    if (!date) return value ? String(value) : fallback;
    try {
        return new Intl.DateTimeFormat(arabicLatinDigitsLocale, options).format(date);
    } catch {
        return String(value);
    }
}

export const formatDateTime = (value, fallback = '') => formatDate(value, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
}, fallback);

/** «YYYY-MM-DDTHH:MM» in local time for <input type="datetime-local">; empty when unreadable. */
export function toLocalInput(value) {
    const date = parseDate(value);
    if (!date) return '';
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toValid(date) {
    return Number.isNaN(date.getTime()) ? null : date;
}
