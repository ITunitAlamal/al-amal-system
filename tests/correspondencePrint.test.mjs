import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../resources/js/Pages/Correspondence/Print.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);

test('official letter renders untrusted text as text without injecting HTML', async () => {
    const attack = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
    const app = createSSRApp({
        template: descriptor.template.content,
        components: { Head: { render: () => null }, Link: { setup: (_, { slots }) => () => h('a', slots.default?.()) } },
        data: () => ({
            document: {
                reference: 'AIMS/TEST/1', date: '2026-10-27', subject: attack, body: attack, sender: attack,
                sender_department: attack, recipients: [attack], issuer: attack, type: attack, status: 'issued',
            },
            letterhead: attack, files: [{ original_filename: attack, document_version: 1 }], editionFiles: [],
            history: [], appendix: [], printAt: { name: attack, date: '2026-10-27', time: '14:05:01' },
            backUrl: '/correspondence', statuses: { issued: 'صادرة' }, taskStatuses: {}, actions: {},
        }),
        methods: { print() {} },
    });
    const html = await renderToString(app);
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(!html.includes('<script>'));
    assert.ok(!html.includes('<img src=x'));
    assert.ok(!html.includes('appendix-title'));
    assert.ok(html.includes('/images/brand/logo.png'));
    assert.match(html, /<button[^>]*aria-label="طباعة المراسلة"[^>]*title="طباعة المراسلة"[^>]*>/);
    assert.match(html, /<button[^>]*aria-label="طباعة المراسلة"[^>]*>[\s\S]*?<svg aria-hidden="true"/);
});

test('correspondence details print link keeps its destination and accessible printer icon', () => {
    const showSource = readFileSync(new URL('../resources/js/Pages/Correspondence/Show.vue', import.meta.url), 'utf8');
    const showTemplate = parse(showSource).descriptor.template.content;
    assert.match(showTemplate, /:href="`\/correspondence\/\$\{letter\.public_id\}\/print`"/);
    assert.match(showTemplate, /aria-label="طباعة المراسلة"\s+title="طباعة المراسلة"/);
    assert.match(showTemplate, /aria-label="طباعة المراسلة"[^>]*>[\s\S]*?<svg aria-hidden="true"/);
    assert.match(showTemplate, /طباعة ملحق التدقيق الداخلي/);
});

test('official letter has safe A4 pagination and a non-overlapping repeated print footer', () => {
    const css = descriptor.styles.map(style => style.content).join('\n');
    assert.match(css, /@page\s*\{\s*size:\s*A4 portrait/);
    assert.match(css, /margin:\s*14mm 16mm 22mm/);
    assert.match(css, /content:\s*counter\(page\)\s*" من "\s*counter\(pages\)/);
    assert.match(css, /@media print[\s\S]*\.print-controls(?:,\s*\.print-preview-footer)?\s*\{\s*display:\s*none/);
    assert.doesNotMatch(css, /position:\s*fixed/);
    assert.doesNotMatch(css, /break-before:\s*page/);
    assert.match(descriptor.scriptSetup.content, /@bottom-left\s*\{\s*content:/);
    assert.match(css, /box-decoration-break:\s*clone/);
    assert.match(css, /orphans:\s*3;\s*widows:\s*3/);
    assert.match(css, /\.letter-table thead\s*\{\s*display:\s*table-header-group/);
    assert.match(css, /\.letter-table tr\s*\{\s*break-inside:\s*auto/);
    assert.ok(!/height:\s*297mm/.test(css));
    assert.match(descriptor.template.content, /<thead><tr><th scope="col">التاريخ<\/th><th scope="col">من<\/th><th scope="col">إلى<\/th>/);
    assert.match(descriptor.template.content, /aria-label="طباعة المراسلة"[^>]*@click="print"/);
    assert.match(descriptor.scriptSetup.content, /const print = \(\) => window\.print\(\)/);
});

test('reference-style tables retain complete approved text, recipients and routing attachments', async () => {
    const body = 'نص النسخة المعتمدة\n'.repeat(100) + 'نهاية المراسلة';
    const note = 'توجيه طويل\n'.repeat(80) + '<script>غير موثوق</script>';
    const html = await renderToString(createSSRApp({
        template: descriptor.template.content,
        components: { Head: { render: () => null }, Link: { setup: (_, { slots }) => () => h('a', slots.default?.()) } },
        data: () => ({
            document: {
                reference: 'AIMS/TEST/2', date: '2026-10-29', subject: 'موضوع الاختبار', body,
                sender: 'مرسل الاختبار', recipients: ['المستلم الأول', 'المستلم الثاني'], status: 'issued',
                edition_version: 2, edition_approver: 'معتمد النسخة', edition_approved_at: '2026-10-29',
            },
            letterhead: 'ترويسة المؤسسة', files: [{ original_filename: 'original.pdf', document_version: 1 }],
            editionFiles: [{ original_filename: 'edition.pdf', document_version: 2 }],
            history: [{ sequence: 1, created_at: '2026-10-29 10:00:00', from: 'جهة أولى', to: 'جهة ثانية', action: 'forward', note,
                attachments: [{ original_filename: 'forward.pdf', document_version: 3 }] }],
            appendix: [], printAt: { name: 'مستخدم الاختبار', date: '2026-10-29', time: '10:30:00' },
            backUrl: '/correspondence', statuses: { issued: 'صادرة' }, taskStatuses: {}, actions: { forward: 'إحالة' },
        }),
        methods: { print() {} },
    }));
    assert.ok(html.includes(body));
    for (const value of ['المستلم الأول', 'المستلم الثاني', 'معتمد النسخة', 'original.pdf', 'edition.pdf', 'forward.pdf', 'جهة أولى', 'جهة ثانية']) {
        assert.ok(html.includes(value), value);
    }
    assert.match(html, /<table[^>]*aria-labelledby="routing-title"/);
    assert.ok(html.includes('&lt;script&gt;غير موثوق&lt;/script&gt;'));
    assert.ok(!html.includes('<script>'));
    assert.ok(!html.includes('appendix-title'));
});

test('replacement comparison escapes both editions and offers no approval control for a pending board text', async () => {
    const editionSource = readFileSync(new URL('../resources/js/Pages/Correspondence/Edition.vue', import.meta.url), 'utf8');
    const template = parse(editionSource).descriptor.template.content;
    const attack = '<img src=x onerror="alert(1)">';
    const app = createSSRApp({
        template,
        components: {
            AppLayout: { setup: (_, { slots }) => () => h('div', slots.default?.()) },
            Link: { setup: (_, { slots }) => () => h('a', slots.default?.()) },
            StatusBadge: { render: () => null }, StatusStepper: { render: () => null },
        },
        data: () => ({
            edition: { version: 1, status: 'pending', origin_role: 'board', subject: attack, body: attack, reason: attack },
            differences: { before: { subject: attack, body: attack } },
            progress: { steps: [], summary: {} }, actions: [], attachments: [], backUrl: '/correspondence',
            labels: { pending: 'قيد الاعتماد' }, tones: { pending: 'warning' },
        }),
    });
    const html = await renderToString(app);
    assert.ok(html.includes('&lt;img'));
    assert.ok(!html.includes('<img src=x'));
    assert.ok(html.includes('dir="rtl"'));
    assert.ok(html.includes('role="status"'));
    assert.ok(!html.includes('<form'));
});
