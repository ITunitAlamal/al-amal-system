// Shared look of attendance readings (status colors always come with a text label).

export const punctualityTone = {
    on_time: 'bg-emerald-100 text-emerald-800 ring-emerald-300',
    grace: 'bg-yellow-100 text-yellow-900 ring-yellow-300',
    late: 'bg-red-100 text-red-800 ring-red-300',
    excused: 'bg-sky-100 text-sky-900 ring-sky-300',
    flexible: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    unknown: 'bg-slate-100 text-slate-600 ring-slate-300',
};

export const dayTone = {
    no_duty: 'bg-slate-100 text-slate-600 ring-slate-200',
    exempt: 'bg-cyan-50 text-cyan-800 ring-cyan-200',
    not_enrolled: 'bg-orange-50 text-orange-800 ring-orange-200',
    working: 'bg-sky-100 text-sky-800 ring-sky-300',
    awaiting_exit: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    complete: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    incomplete: 'bg-orange-100 text-orange-800 ring-orange-300',
    absent: 'bg-red-50 text-red-700 ring-red-200',
    leave: 'bg-violet-50 text-violet-800 ring-violet-200',
    holiday: 'bg-slate-100 text-slate-700 ring-slate-200',
    rest_day: 'bg-slate-100 text-slate-600 ring-slate-200',
    review: 'bg-amber-100 text-amber-900 ring-amber-300',
    not_started: 'bg-slate-50 text-slate-500 ring-slate-200',
};

/** Row accent on the start (right in RTL) edge, by arrival punctuality. */
export const punctualityEdge = {
    on_time: 'border-s-4 border-s-emerald-500',
    grace: 'border-s-4 border-s-yellow-400',
    late: 'border-s-4 border-s-red-500',
    excused: 'border-s-4 border-s-sky-400',
    flexible: 'border-s-4 border-s-indigo-300',
    unknown: 'border-s-4 border-s-slate-200',
};

export const filterOptions = [
    ['', 'كل الحالات'],
    ['on_time', 'ملتزم'], ['grace', 'ضمن السماح'], ['late', 'متأخر'], ['excused', 'تأخير بإذن'], ['unknown', 'غير محسوم'],
    ['working', 'قيد العمل'], ['awaiting_exit', 'بانتظار الخروج'], ['complete', 'دوام مكتمل'], ['incomplete', 'بصمة ناقصة'],
    ['absent', 'غائب'], ['review', 'تحتاج مراجعة'], ['early', 'خروج مبكر'], ['corrected', 'فيها تصحيح'],
    ['permission', 'فيها طلب إذن'], ['swap', 'فيها تبديل مناوبة'],
    ['leave', 'إجازة'], ['holiday', 'عطلة رسمية'], ['rest_day', 'يوم راحة'], ['not_started', 'لم يبدأ الدوام'],
    ['no_duty', 'لا يوجد دوام مقرر'], ['exempt', 'معفى من البصمة'], ['not_enrolled', 'غير مسجل على جهاز البصمة'], ['flexible', 'دوام مرن'],
];

/** Roster cell look by slot status (always shown with its text). */
export const rosterTone = {
    work: 'bg-emerald-50 text-emerald-900 ring-emerald-200',
    off: 'bg-slate-100 text-slate-500 ring-slate-200',
    no_duty: 'bg-slate-50 text-slate-500 ring-slate-200',
    no_punch: 'bg-cyan-50 text-cyan-800 ring-cyan-200',
    leave: 'bg-violet-50 text-violet-800 ring-violet-200',
    holiday: 'bg-slate-200 text-slate-700 ring-slate-300',
    exception: 'bg-amber-50 text-amber-900 ring-amber-200',
    invalid: 'bg-red-50 text-red-800 ring-red-200',
    none: 'bg-white text-slate-400 ring-slate-200',
};

/** Arabic label of a request or action status. */
export const requestStatusTone = {
    submitted: 'bg-amber-100 text-amber-900', awaiting_counterpart: 'bg-amber-100 text-amber-900', pending_approval: 'bg-amber-100 text-amber-900',
    in_review: 'bg-amber-100 text-amber-900', draft: 'bg-slate-100 text-slate-700',
    approved: 'bg-emerald-100 text-emerald-800', issued: 'bg-red-100 text-red-800',
    rejected: 'bg-red-50 text-red-700', counterpart_declined: 'bg-red-50 text-red-700',
    cancelled: 'bg-slate-200 text-slate-600', revoked: 'bg-slate-200 text-slate-600', reversed: 'bg-slate-200 text-slate-600',
};

export function minutesLabel(value) {
    if (value === null || value === undefined) return '—';
    const minutes = Number(value);
    if (minutes < 60) return `${minutes} د`;
    return `${Math.floor(minutes / 60)} س ${minutes % 60} د`;
}

/** Today in the institute's time zone (YYYY-MM-DD). */
export function localToday(timeZone = 'Asia/Gaza') {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' })
        .formatToParts(new Date()).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
}

export async function getJson(url, signal) {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) {
        let message = '';
        try { message = (await response.json())?.message || ''; } catch { /* not JSON */ }
        if (response.status === 403) message = 'ليس لديك صلاحية الاطلاع على هذه البيانات.';
        throw new Error(message || `تعذّر تحميل البيانات (${response.status}).`);
    }
    return response.json();
}

/** Downloads an .xlsx through fetch so that a server error is shown instead of saving a broken file. */
export async function downloadWorkbook(url) {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
    const type = response.headers.get('Content-Type') || '';
    if (!response.ok || !type.includes('spreadsheetml')) {
        let message = '';
        try { message = (await response.json())?.message || ''; } catch { /* not JSON */ }
        throw new Error(message || `تعذّر تصدير الملف (${response.status}).`);
    }
    const blob = await response.blob();
    if (blob.size < 100) throw new Error('وصل ملف فارغ؛ لم يُحفظ.');
    const disposition = response.headers.get('Content-Disposition') || '';
    const name = decodeURIComponent((disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]) || (disposition.match(/filename="?([^";]+)"?/i)?.[1]) || 'AIMS_Attendance.xlsx');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 10000);
}
