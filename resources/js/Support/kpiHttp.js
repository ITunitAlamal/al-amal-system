export async function kpiRequest(url, data = null) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (data !== null) {
        headers['Content-Type'] = 'application/json';
        const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
        if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
    }
    const response = await fetch(url, {
        method: data === null ? 'GET' : 'POST', credentials: 'same-origin', headers,
        ...(data === null ? {} : { body: JSON.stringify(data) }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(payload.errors ?? {}).flat().join(' • ') || payload.message || 'تعذر إتمام الطلب. أعد تحميل الصفحة ثم حاول مجددًا.');
    }
    return payload;
}

export function kpiQuery(filters) {
    return new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value != null)).toString();
}
