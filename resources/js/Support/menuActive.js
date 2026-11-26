/**
 * Which sidebar link is the current page. A link is its path plus the query parameters it names
 * (/finance?view=orders opens one Finance section). The most specific match wins: a link whose parameters all
 * match beats one without parameters, then the longest path — so /admin/attendance/compensatory does not also
 * light up /admin/attendance, and /finance?view=orders lights «أوامر الشراء» only.
 */
export function parseUrl(url) {
    const [pathPart, query = ''] = String(url || '').replace(/^https?:\/\/[^/]+/, '').split('#')[0].split('?');
    return { path: pathPart.replace(/\/$/, '') || '/', params: Object.fromEntries(new URLSearchParams(query)) };
}

export function keyOf({ path, params }) {
    return `${path}?${Object.keys(params).sort().map(key => `${key}=${params[key]}`).join('&')}`;
}

/** The key of the best-matching link for the current address, or null when none contains it. */
export function activeKey(links, currentUrl) {
    const current = parseUrl(currentUrl);
    let best = null;
    let bestScore = -1;
    links.forEach(link => {
        const pathMatches = current.path === link.path || current.path.startsWith(link.path + '/');
        const paramsMatch = Object.entries(link.params).every(([key, value]) => current.params[key] === value);
        const score = link.path.length + Object.keys(link.params).length * 1000;
        if (pathMatches && paramsMatch && score > bestScore) {
            bestScore = score;
            best = keyOf(link);
        }
    });

    return best;
}
