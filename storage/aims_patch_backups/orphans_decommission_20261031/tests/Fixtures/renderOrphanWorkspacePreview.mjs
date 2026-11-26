// Generates a static, explicitly synthetic preview from the real Vue templates. No application or database is booted.
import fs from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as navigation from '../../resources/js/Support/orphanNavigation.js';

let fixtureId = 0;
function component(path) {
    const source = fs.readFileSync(`resources/js/${path}`, 'utf8');
    const descriptor = parse(source).descriptor;
    const bindings = compileScript(descriptor, { id: path }).bindings;
    const keys = Object.keys(bindings);
    const code = compileTemplate({ source: descriptor.template.content, filename: path, id: path }).code
        .replace(/import \{([^}]+)\} from "vue"/g, (match, imports) => `const {${imports.replace(/\bas\b/g, ':')}} = Vue;`)
        .replace('export function render', 'function render');
    const renderContext = { Vue };
    vm.createContext(renderContext);
    vm.runInContext(`${code}\nglobalThis.render = render;`, renderContext);

    return {
        props: keys.filter(key => bindings[key] === 'props'),
        setup(supplied) {
            let defaults;
            const context = {
                ...Vue, ...navigation,
                defineProps(schema) {
                    defaults = Object.fromEntries(Object.entries(schema).map(([key, rule]) => [key,
                        supplied[key] !== undefined ? supplied[key] : typeof rule.default === 'function' ? rule.default() : rule.default,
                    ]));
                    return defaults;
                },
                defineEmits: () => () => {}, onMounted: () => {}, onUnmounted: () => {}, watch: () => {},
                useId: () => `fixture-${++fixtureId}`, usePage: () => ({ url: '/orphans?page=2' }), router: {},
                useForm: values => ({ ...values, errors: {}, data: () => values }), emptyAddress: () => ({}),
            };
            vm.createContext(context);
            const declarations = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
            vm.runInContext(`${declarations}\nglobalThis.api = {${keys.map(key => `${JSON.stringify(key)}: typeof ${key} !== 'undefined' ? ${key} : undefined`).join(',')}};`, context);
            return { ...context.api, ...defaults };
        },
        render: renderContext.render,
    };
}

const components = {
    AppLayout: { template: '<main class="space-y-4"><slot /></main>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    PageHeader: component('Components/PageHeader.vue'), StatusBadge: component('Components/StatusBadge.vue'),
    RecordContext: component('Components/Orphans/RecordContext.vue'), SectionSelect: component('Components/Orphans/SectionSelect.vue'),
    ListToolbar: component('Components/Orphans/ListToolbar.vue'), ActiveFilters: component('Components/ActiveFilters.vue'),
    ActionDisclosure: component('Components/Orphans/ActionDisclosure.vue'), RecordPreview: { template: '<div></div>' },
    FormErrorSummary: { template: '<div></div>' }, RuleFields: { template: '<div></div>' }, GeographicAddress: { template: '<div></div>' },
    DocumentsChecklist: { template: '<div></div>' },
};
async function render(path, props) {
    const app = Vue.createSSRApp(component(path), props);
    for (const [name, definition] of Object.entries(components)) app.component(name, definition);
    return renderToString(app);
}

const registry = await render('Pages/Orphans/Index.vue', {
    households: { data: [{ public_id: 'synthetic-family', reference_no: 'TEST-FAMILY', family_name: 'Synthetic household fixture', guardian: null,
        children: 1, active_children: 0, status: 'draft', status_label: 'Fixture' }], total: 1, current_page: 2, links: [{ label: '2', url: '/orphans?page=2', active: true }] },
    queues: {}, counts: { active_children: 0, pending_children: 1, households: 1, open_applications: 0 }, filters: {},
    can: { records: true, visits: true, plans: true }, householdRules: {}, options: {},
});
const child = await render('Pages/Orphans/Child.vue', {
    child: { public_id: 'synthetic-child', full_name: 'Synthetic child fixture', file_no: null, status: 'applicant', status_label: 'Fixture',
        verification: 'Pending verification', date_of_birth: null, age: null },
    household: { public_id: 'synthetic-family', reference_no: 'TEST-FAMILY' }, can: { sensitive: false, records: false }, sponsorship: null,
});
const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
const css = [...new Set(Object.values(manifest).flatMap(entry => entry.file.endsWith('.css') ? [entry.file] : entry.css || []))];
const html = `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIMS UX V2 synthetic preview</title>
${css.map(path => `<link rel="stylesheet" href="../public/build/${path}">`).join('')}
<style>body{background:#f8fafc}.fixture-note{direction:ltr;text-align:left;background:#fff7ed;border:2px solid #ea580c;padding:1rem;font:16px system-ui}.fixture-screen{max-width:1280px;margin:2rem auto;padding:1rem;scroll-margin-top:1rem}h2.fixture-title{font:700 20px system-ui;margin-bottom:1rem;direction:ltr;text-align:left}</style></head><body>
<div class="fixture-note">SYNTHETIC TEST FIXTURE / STATIC PREVIEW. No real people, identities, permissions or operational counts. Controls illustrate compiled Vue templates; no backend actions run here. The global sidebar is omitted from this isolated preview.</div>
<section id="registry" class="fixture-screen"><h2 class="fixture-title">Registry workspace — synthetic fixture</h2>${registry}</section><hr>
<section id="child" class="fixture-screen"><h2 class="fixture-title">Child profile — synthetic fixture</h2>${child}</section></body></html>`;
fs.writeFileSync('docs/orphans-ux-preview.html', html);
process.stdout.write('Wrote synthetic preview from actual Vue templates.\n');
