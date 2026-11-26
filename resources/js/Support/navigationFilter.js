// Filter only the server-authorized tree; never synthesize routes from search text.
export function filterNavigation(items, query) {
    const normalize = value => String(value ?? '').normalize('NFKD').replace(/[\u064b-\u065f\u0670\u0640]/g, '').toLocaleLowerCase('ar').trim();
    const needle = normalize(query);
    if (!needle) return items;
    return items.flatMap(item => {
        if (normalize(item.label).includes(needle)) return [item];
        const children = filterNavigation(item.children || [], query).filter(child => !child.heading);
        return children.length ? [{ ...item, children }] : [];
    });
}
