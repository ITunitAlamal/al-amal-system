// Real HR component with isolated fixture data; no application server or database.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

const root = process.cwd();
const artifacts = path.join(root, 'storage/app/hr-home-browser');
await mkdir(artifacts, { recursive: true });
const fixture = {
    today: '2026-11-26',
    departmentBudgets: { period: '2026-11', manageUrl: null, items: [
        { departmentId: 1, department: 'الموارد البشرية والخدمات العامة والصيانة', configured: true, currency: 'ILS', allocated: '100.30', spent: '120.31', remaining: '-20.01', asOf: '2026-11-27', updatedAt: '2026-11-26' },
        { departmentId: 1, department: 'الموارد البشرية والخدمات العامة والصيانة', configured: true, currency: 'USD', allocated: '0.00', spent: '0.00', remaining: '0.00', asOf: '2026-11-27' },
        { departmentId: 2, department: 'قسم إضافي', configured: false, currency: null, allocated: null, spent: null, remaining: null },
    ] },
    hrSummary: { updatedAt: '2026-11-26T10:00:00+02:00', cards: [
        { key: 'leaves', label: 'إجازات تنتظر إجرائي', value: 2, detail: 'طلبات مسندة إليك للمراجعة أو التسجيل', url: '/tasks?type=leave' },
        { key: 'attendance', label: 'الحضور اليوم', value: 25, detail: 'من أصل 30 يوم دوام مجدول · حالات تحتاج مراجعة: 3', url: '/admin/attendance' },
        { key: 'services', label: 'طلبات الخدمات والصيانة المفتوحة', value: 6, detail: 'المتأخرة: 1 · العاجلة والطارئة: 2', url: '/hr-services#services' },
        { key: 'staff_files', label: 'ملفات موظفين تحتاج مراجعة', value: 4, detail: 'وثائق أو صور أو أسماء تنتظر المراجعة؛ كل موظف يُحسب مرة واحدة', url: '/admin/employee-profiles?state=review' },
    ] },
    hrActions: { total: 12, data: Array.from({ length: 7 }, (_, id) => ({ key: `task-${id}`, title: `طلب اختبار ${id + 1}`, kind: 'إجازة موظف', reference: `LV-${id}`, step: 'تسجيل الإجازة وخصم الرصيد', requester: 'موظف الاختبار', dueAt: '2026-11-27', overdue: id === 0, action: 'تسجيل الإجازة', url: `/leaves/${id}` })) },
    hrFollowUp: { total: 8, data: Array.from({ length: 5 }, (_, id) => ({ reference: `MNT-${id}`, category: 'صيانة داخلية', step: 'فحص توفر المواد في المخزن', holders: 'المسؤول الحالي', department: 'قسم الاختبار', location: 'موقع الاختبار ذو الوصف الطويل لتجربة الالتفاف على الهاتف', priority: 'عاجل', status: 'قيد المعالجة', overdue: false, url: id ? `/workflows/${id}` : null })) },
};
const server = await createServer({
    configFile: false, root, publicDir: path.join(root, 'public'),
    cacheDir: path.join(artifacts, 'vite-cache'),
    optimizeDeps: { include: ['vue'], exclude: ['@inertiajs/vue3'], noDiscovery: true },
    plugins: [vue(), tailwind(), {
        name: 'hr-home-fixture',
        enforce: 'pre',
        resolveId(id) {
            if (id === '@inertiajs/vue3') return '\0hr-inertia';
            if (id === 'virtual:hr-home-fixture') return '\0hr-fixture';
        },
        load(id) {
            if (id === '\0hr-inertia') return `
                import { defineComponent, h, ref } from 'vue';
                window.__hrMode = ref('loaded');
                window.__hrReloads = [];
                export const Link = defineComponent({ inheritAttrs: false, setup(_, { attrs, slots }) { return () => h('a', attrs, slots.default?.()); } });
                export const Deferred = defineComponent({ props: { data: [String, Array] }, inheritAttrs: false, setup(_, { slots }) { return () => window.__hrMode.value === 'loading' ? slots.fallback?.() : slots.default?.(); } });
                export const router = { reload(options) { window.__hrReloads.push(options.only); window.__hrFinish = success => { if (success) options.onSuccess(); options.onFinish(); }; } };
            `;
            if (id === '\0hr-fixture') return `
                import { createApp, h, reactive } from 'vue';
                import HomeWorkspace from '/resources/js/Components/Hr/HomeWorkspace.vue';
                import '/resources/css/aims.css';
                window.__hrProps = reactive(${JSON.stringify(fixture)});
                createApp({ render: () => h(HomeWorkspace, window.__hrProps) }).mount('#app');
            `;
        },
        configureServer(vite) {
            vite.middlewares.use((request, response, next) => {
                if (request.url !== '/') return next();
                response.setHeader('Content-Type', 'text/html; charset=utf-8');
                response.end('<!doctype html><html lang="ar" dir="rtl"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HR fixture</title><body><main id="app" style="padding:16px;max-width:1280px;margin:auto"></main><script type="module" src="/@vite/client"></script><script type="module" src="/@id/virtual:hr-home-fixture"></script></body></html>');
            });
        },
    }],
    server: { host: '127.0.0.1', port: 5199, strictPort: true, watch: { ignored: ['**/storage/**', '**/vendor/**', '**/.git/**'] } },
});
await server.listen();
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=9239', `--user-data-dir=${path.join(artifacts, 'chrome-profile')}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
let socket;
try {
    let targets;
    for (let attempt = 0; attempt < 60; attempt++) {
        try { targets = await (await fetch('http://127.0.0.1:9239/json')).json(); if (targets.some(target => target.type === 'page')) break; } catch {}
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    const target = targets?.find(target => target.type === 'page');
    assert.ok(target, 'Chrome is available');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let sequence = 0;
    const waiting = new Map();
    const errors = [];
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
        if (message.id) { const pending = waiting.get(message.id); waiting.delete(message.id); message.error ? pending.reject(message.error) : pending.resolve(message.result); }
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; waiting.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })); });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
        return result.result.value;
    };
    await send('Runtime.enable');
    await send('Page.enable');
    await send('Page.navigate', { url: 'http://127.0.0.1:5199/' });
    for (let attempt = 0; attempt < 120; attempt++) {
        if (await evaluate("!!document.querySelector('#hr-home-actions')")) break;
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    assert.ok(await evaluate("!!document.querySelector('#hr-home-actions')"), 'HR component mounted');
    await evaluate('document.fonts.ready.then(() => true)');
    assert.equal(await evaluate("document.querySelectorAll('section[aria-label=\"ملخص القسم اليوم\"] > a').length"), 4);
    assert.equal(await evaluate("document.querySelector('#hr-home-actions').closest('section').querySelectorAll('li').length"), 7);
    assert.equal(await evaluate("document.querySelector('#hr-home-follow-up').closest('section').querySelectorAll('li').length"), 5);
    assert.equal(await evaluate("document.querySelector('#hr-home-follow-up').closest('section').querySelector('li').querySelector('a')"), null, 'Unavailable request has no detail link');
    assert.equal(await evaluate("document.querySelectorAll('table').length"), 0, 'No full registers on home');
    assert.equal(await evaluate("document.querySelector('section[aria-label=\"ميزانية القسم\"]').querySelectorAll('article').length"), 3, 'Currencies and missing budgets remain separate');
    assert.ok(await evaluate("document.querySelector('section[aria-label=\"ميزانية القسم\"] h2').textContent.includes('نوفمبر 2026')"), 'Budget summary displays the selected month in Arabic');
    assert.ok(await evaluate("!document.querySelector('section[aria-label=\"ميزانية القسم\"]').textContent.includes('undefined')"), 'Monthly summary has no missing period text');
    assert.ok(await evaluate("document.querySelector('section[aria-label=\"ميزانية القسم\"]').textContent.includes('تجاوز الميزانية')"));
    assert.ok(await evaluate("document.querySelector('section[aria-label=\"ميزانية القسم\"]').textContent.includes('لم تُدخل ميزانية القسم')"));
    assert.equal(await evaluate("document.querySelector('section[aria-label=\"ميزانية القسم\"]').querySelector('a')"), null, 'Ordinary heads have no management action');
    for (const width of [320, 768, 1440]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
        await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
        assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `No page overflow at ${width}px`);
        assert.equal(await evaluate('getComputedStyle(document.documentElement).direction'), 'rtl');
        const screenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(path.join(artifacts, `hr-home-${width}.png`), Buffer.from(screenshot.data, 'base64'));
    }
    const updated = await evaluate("document.querySelector('.aims-page-header').textContent");
    await evaluate("document.querySelector('.aims-page-header button').click(); document.querySelector('.aims-page-header button').click()");
    assert.equal(await evaluate('window.__hrReloads.length'), 1, 'Refresh prevents overlapping requests');
    assert.deepEqual(await evaluate('window.__hrReloads[0]'), ['hrSummary', 'hrActions', 'hrFollowUp', 'departmentBudgets']);
    await evaluate('window.__hrFinish(false); new Promise(resolve => requestAnimationFrame(resolve))');
    assert.ok(await evaluate("document.querySelector('[role=alert]').textContent.includes('تعذّر التحديث')"));
    assert.equal(await evaluate("document.querySelector('.aims-page-header').textContent"), updated, 'Failed refresh retains last update');
    await evaluate("document.querySelector('.aims-page-header button').click(); window.__hrFinish(true); new Promise(resolve => requestAnimationFrame(resolve))");
    assert.equal(await evaluate("document.querySelector('[role=alert]')"), null);
    await evaluate("window.__hrProps.hrSummary = null; window.__hrProps.hrActions = null; window.__hrProps.hrFollowUp = null; window.__hrMode.value = 'loading'; new Promise(resolve => requestAnimationFrame(resolve))");
    assert.equal(await evaluate("document.querySelectorAll('[role=status]').length"), 4, 'Each section has a loading state');
    assert.deepEqual(errors, [], 'No uncaught browser errors');
    console.log('HR home: four cards, bounded lists, permissions, RTL at 320/768/1440px, loading and refresh states passed.');
} finally {
    socket?.close();
    chrome.kill();
    await server.close();
}
