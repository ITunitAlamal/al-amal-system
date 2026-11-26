// «يحتاج إلى إجراء»: one prioritized queue built from the staged import's open issues and the approved registry's
// work lists. Used by «لوحة القسم» (top items) and «المراجعة والمتابعة» (all items). The two tiers are labelled and
// never added together; a bounded registry list is shown as «N+».
export const registryQueueLabels = {
    mine: 'مهامي الميدانية', emergencies: 'حالات الطوارئ', applications: 'طلبات الاعتماد', changes: 'طلبات التعديل',
    documents: 'مستندات منتهية أو قريبة الانتهاء', followups: 'متابعة الحالات', visits: 'الزيارات', referrals: 'الإحالات المفتوحة',
};
const limits = { applications: 50, changes: 50, visits: 50, documents: 100 };
const severityTag = { high: ['عالية', 'danger'], medium: ['متوسطة', 'warning'], low: ['منخفضة', 'info'] };

function registryCount(queues, key) {
    if (key === 'mine') return (queues.mine?.visits?.length || 0) + (queues.mine?.followups?.length || 0);
    return Array.isArray(queues[key]) ? queues[key].length : 0;
}

export function buildWorkItems(staged, queues = {}, { plans = false } = {}) {
    const issue = t => ({ queue: `issue:${t.type}`, label: t.label, count: t.count, tier: 'سجلات الكشف', tag: severityTag[t.severity]?.[0] || t.severity,
        tone: severityTag[t.severity]?.[1] || 'muted', note: `غير مكلّفة: ${t.unassigned} · ${t.recommendation}` });
    const types = staged?.types || [];
    const registry = Object.keys(registryQueueLabels).filter(k => plans || k !== 'followups').map(k => [k, registryCount(queues, k)]).filter(([, n]) => n)
        .map(([k, n]) => ({ queue: k, label: registryQueueLabels[k], count: limits[k] && n >= limits[k] ? `${n}+` : n, tier: 'الملفات المعتمدة',
            tag: k === 'emergencies' ? 'عاجل' : 'متابعة', tone: k === 'emergencies' ? 'danger' : 'info', note: '' }));
    return [
        ...registry.filter(i => i.queue === 'emergencies' || i.queue === 'mine'),
        ...types.filter(t => t.severity === 'high').map(issue),
        ...(staged?.placeholders ? [{ queue: 'placeholder', label: 'هوية شكلية (رقم مكرر مثل 000000000)', count: staged.placeholders, tier: 'سجلات الكشف', tag: 'عالية', tone: 'danger',
            note: 'تحتاج رقم هوية صحيحًا بدليل؛ القيمة الأصلية لا تُعدَّل', href: '/orphans/records?id_placeholder=1' }] : []),
        ...registry.filter(i => i.queue !== 'emergencies' && i.queue !== 'mine'),
        ...types.filter(t => t.severity !== 'high').map(issue),
    ];
}

export const workItemUrl = item => item.href || `/orphans/staging?queue=${encodeURIComponent(item.queue)}`;
