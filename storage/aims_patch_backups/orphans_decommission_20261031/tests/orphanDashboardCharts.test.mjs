import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const require = createRequire(import.meta.url);
const vueUrl = pathToFileURL(require.resolve('vue/dist/vue.runtime.esm-bundler.js')).href;
const linkStub = `import { h } from ${JSON.stringify(vueUrl)}; export const Link = { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) };`;
const dataUrl = code => `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
function componentUrl(file) {
    const descriptor = parse(fs.readFileSync(file, 'utf8')).descriptor;
    const compiled = compileScript(descriptor, { id: file, inlineTemplate: true }).content;
    return dataUrl(compiled.replace(/from (['"])([^'"]+)\1/g, (match, quote, source) => {
        if (source === 'vue') return `from ${JSON.stringify(vueUrl)}`;
        if (source === '@inertiajs/vue3') return `from ${JSON.stringify(dataUrl(linkStub))}`;
        if (source.endsWith('.vue')) return `from ${JSON.stringify(componentUrl(path.resolve(path.dirname(file), source)))}`;
        return match;
    }));
}
const { default: Chart } = await import(componentUrl('resources/js/Components/Orphans/DistributionChart.vue'));
const { default: Insights } = await import(componentUrl('resources/js/Components/Orphans/DepartmentInsights.vue'));

test('chart shows counts, proportional bars and scoped links without relying on color', async () => {
    const html = await renderToString(createSSRApp(Chart, { rows: [
        { label: 'غزة', count: 6, href: '/orphans/records?batch=test&governorate=gaza' },
        { label: 'غير محدد', count: 2 },
    ] }));
    assert.match(html, /width:100%/);
    assert.match(html, /width:33\.333/);
    assert.match(html, /batch=test&amp;governorate=gaza/);
    assert.ok(html.includes(new Intl.NumberFormat('ar', { style: 'percent', maximumFractionDigits: 1 }).format(0.75)));
    assert.match(html, /غير محدد/);
});

test('empty chart explains missing data and never renders invalid percentages', async () => {
    const html = await renderToString(createSSRApp(Chart, { rows: [] }));
    assert.match(html, /لا توجد بيانات/);
    assert.doesNotMatch(html, /NaN|Infinity/);
});

test('department panel hides unauthorized sponsorships and explains unavailable staging', async () => {
    const html = await renderToString(createSSRApp(Insights, { insights: { approved_geography: [], staged: null, sponsorships: null } }));
    assert.doesNotMatch(html, /حالات الكفالات/);
    assert.match(html, /لا توجد دفعة مستوردة/);
    assert.match(html, /الأطفال النشطون في السجل المعتمد/);
});
