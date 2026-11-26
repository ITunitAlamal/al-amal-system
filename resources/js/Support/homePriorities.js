import { parseDate } from './dates.js';

export function prioritizeActions(items) {
    const rank = { danger: 0, warning: 1, info: 2, muted: 3 };
    return items.filter(item => Number(item.value) > 0)
        .sort((a, b) => (rank[a.tone] ?? 3) - (rank[b.tone] ?? 3) || Number(b.value) - Number(a.value));
}

export function uniqueShortcuts(items, excluded = []) {
    const seen = new Set(excluded);
    return items.filter(item => {
        if (!item.url || seen.has(item.url)) return false;
        seen.add(item.url);
        return true;
    });
}

export function taskDeadline(task, now = Date.now()) {
    if (task.overdue) return { label: 'متأخرة', tone: 'danger' };
    if (!task.dueAt) return null;
    const deadline = parseDate(task.dueAt)?.getTime();
    if (!Number.isFinite(deadline)) return null;
    const hours = (deadline - now) / 36e5;
    if (hours < 0) return { label: 'متأخرة', tone: 'danger' };
    if (hours < 1) return { label: 'تستحق خلال أقل من ساعة', tone: 'warning' };
    if (hours < 24) return { label: `باقي ${Math.ceil(hours)} س`, tone: 'warning' };
    return { label: `باقي ${Math.ceil(hours / 24)} يوم`, tone: 'muted' };
}
