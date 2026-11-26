'use strict';

// HTTPS client of the AIMS field API. Plain HTTP is accepted only for a local test server (localhost); every request
// carries the session token and the device fingerprint, and times out instead of hanging.
class Api {
    constructor(baseUrl) {
        const url = new URL(baseUrl);
        const local = ['localhost', '127.0.0.1', '::1'].includes(url.hostname);
        if (url.protocol !== 'https:' && !local) throw new Error('عنوان الخادم يجب أن يبدأ بـ https://');
        this.base = `${url.origin}${url.pathname.replace(/\/$/, '')}/api/field/v1`;
        this.token = null;
        this.fingerprint = null;
    }

    async request(method, path, body = null, timeoutMs = 20_000) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetch(`${this.base}${path}`, {
                method, signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                    ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}), ...(this.fingerprint ? { 'X-Device-Fingerprint': this.fingerprint } : {}) },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok && response.status !== 202) {
                const message = data.message || Object.values(data.errors || {}).flat()[0] || `تعذر الاتصال (${response.status})`;
                const error = new Error(message);
                error.status = response.status;
                throw error;
            }
            return { status: response.status, data };
        } catch (error) {
            if (error.name === 'AbortError') {
                const timeout = new Error('انتهت مهلة الاتصال؛ البيانات محفوظة وستُرفع لاحقًا.');
                timeout.status = 0;
                throw timeout;
            }
            if (error.status === undefined) error.status = 0;
            throw error;
        } finally {
            clearTimeout(timer);
        }
    }

    server() { return this.request('GET', '/server'); }
    signIn(body) { return this.request('POST', '/session', body); }
    signOut() { return this.request('DELETE', '/session'); }
    status() { return this.request('GET', '/status'); }
    batches() { return this.request('GET', '/batches'); }
    manifest(batchId) { return this.request('POST', `/batches/${encodeURIComponent(batchId)}/manifest`, {}, 60_000); }
    sync(events) { return this.request('POST', '/sync', { events }, 60_000); }
}

module.exports = { Api };
