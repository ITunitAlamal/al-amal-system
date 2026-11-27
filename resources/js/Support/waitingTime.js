import { parseDate } from './dates.js';

/** «منذ N يوم»: how long something has waited for its present holder; null when the time is unreadable. */
export function waitedFor(since, now = Date.now()) {
    const from = parseDate(since);
    if (!from) return null;
    const hours = Math.floor((now - from.getTime()) / 36e5);
    if (hours < 1) return 'منذ أقل من ساعة';
    return hours < 24 ? `منذ ${hours} س` : `منذ ${Math.floor(hours / 24)} يوم`;
}

/**
 * The badge of a waiting record. «متأخر» only for a real deadline that has passed (the server's "overdue", or a due
 * time reached while the page is open); a record without one shows its age instead.
 */
export function waitingBadge(item, now = Date.now()) {
    const due = parseDate(item.dueAt ?? item.due_at);
    if (item.overdue || (due && due.getTime() <= now)) return { label: 'متأخر', tone: 'danger' };
    const waited = waitedFor(item.since, now);
    return waited ? { label: waited, tone: 'muted' } : null;
}
