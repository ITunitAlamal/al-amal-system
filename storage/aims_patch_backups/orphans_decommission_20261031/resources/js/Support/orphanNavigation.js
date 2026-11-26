const queueKeys = ['households', 'mine', 'followups', 'applications', 'changes', 'visits', 'emergencies', 'referrals', 'documents'];

// Only registry navigation state can be carried through record links.
export function registryResultsUrl(value) {
    if (typeof value !== 'string' || !/^\/orphans(?:[?#]|$)/.test(value)) return '/orphans';
    const source = new URL(value, 'https://aims.invalid');
    if (source.hash && !/^#household-[a-zA-Z0-9-]{1,80}$/.test(source.hash)) return '/orphans';
    const query = new URLSearchParams();
    const search = source.searchParams.get('q');
    const status = source.searchParams.get('status');
    const page = source.searchParams.get('page');
    const tab = source.searchParams.get('tab');
    if (search?.trim()) query.set('q', search.trim().slice(0, 40));
    if (['draft', 'registered', 'active', 'inactive', 'closed'].includes(status)) query.set('status', status);
    if (/^[1-9]\d{0,6}$/.test(page || '')) query.set('page', page);
    if (queueKeys.includes(tab) && tab !== 'households') query.set('tab', tab);
    return `/orphans${query.size ? `?${query}` : ''}${source.hash}`;
}

export function householdResultsUrl(currentUrl, publicId) {
    const results = registryResultsUrl(currentUrl).split('#')[0];
    return registryResultsUrl(`${results}#household-${publicId}`);
}

export function recordResultsUrl(currentUrl) {
    const url = new URL(currentUrl, 'https://aims.invalid');
    return registryResultsUrl(url.searchParams.get('return_to'));
}

export function recordWithResults(path, resultsUrl) {
    return `${path}?${new URLSearchParams({ return_to: registryResultsUrl(resultsUrl) })}`;
}

export function queueUrl(currentUrl, tab) {
    const url = new URL(registryResultsUrl(currentUrl), 'https://aims.invalid');
    if (tab === 'households' || !queueKeys.includes(tab)) url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    return `${url.pathname}${url.search}`;
}
