<script setup>
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { kpiQuery, kpiRequest } from '../../../Support/kpiHttp';

const props = defineProps({
    templates: { type: Array, required: true },
    components: { type: Object, required: true },
    sizes: { type: Object, required: true },
    layers: { type: Object, required: true },
    navigation: { type: Array, required: true },
    roles: { type: Array, required: true },
    departments: { type: Array, required: true },
    users: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const linkable = computed(() => props.navigation.filter(item => item.linkable));
const hideable = computed(() => props.navigation.filter(item => item.code !== 'dashboard'));
const editorTitle = ref(null);
const editing = ref(null);

const blankComponents = () => Object.fromEntries(Object.keys(props.components).map(key => [key, { visibility: '', order: '', size: '', options: {} }]));
const form = useForm({ scope_type: 'organization', scope_key: '', reason: '', include_subunits: false, components: blankComponents(),
    shortcuts_mode: 'inherit', shortcuts: [], hidden_mode: 'inherit', hidden_navigation: [], landing: '' });

async function open(template = null) {
    form.clearErrors();
    form.defaults({ scope_type: 'organization', scope_key: '', reason: '', include_subunits: false, components: blankComponents(),
        shortcuts_mode: 'inherit', shortcuts: [], hidden_mode: 'inherit', hidden_navigation: [], landing: '' });
    form.reset();
    editing.value = template ?? {};
    if (template) {
        const configuration = template.configuration ?? {};
        form.scope_type = template.scope_type;
        form.scope_key = template.scope_key;
        form.include_subunits = Boolean(configuration.include_subunits);
        for (const [key, settings] of Object.entries(configuration.components ?? {})) {
            if (!form.components[key]) continue;
            form.components[key] = { visibility: settings.visible === true ? 'show' : settings.visible === false ? 'hide' : '',
                order: settings.order ?? '', size: settings.size ?? '', options: { ...(settings.options ?? {}) } };
        }
        if (Array.isArray(configuration.shortcuts)) { form.shortcuts_mode = 'set'; form.shortcuts = [...configuration.shortcuts]; }
        if (Array.isArray(configuration.hidden_navigation)) { form.hidden_mode = 'set'; form.hidden_navigation = [...configuration.hidden_navigation]; }
        form.landing = configuration.landing ?? '';
    }
    await nextTick();
    editorTitle.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    editorTitle.value?.focus();
}

function save() {
    form.transform(data => ({
        scope_type: data.scope_type,
        scope_key: data.scope_type === 'organization' ? '' : String(data.scope_key ?? ''),
        reason: data.reason,
        configuration: {
            components: Object.fromEntries(Object.entries(data.components).map(([key, settings]) => [key, {
                visible: settings.visibility === 'show' ? true : settings.visibility === 'hide' ? false : null,
                order: settings.order === '' ? null : Number(settings.order),
                size: settings.size || null,
                options: Object.fromEntries(Object.entries(settings.options ?? {}).filter(([, value]) => value !== '' && value !== null)),
            }])),
            shortcuts: data.shortcuts_mode === 'set' ? data.shortcuts : null,
            hidden_navigation: data.hidden_mode === 'set' ? data.hidden_navigation : null,
            landing: data.landing || null,
            include_subunits: data.scope_type === 'department' ? data.include_subunits : false,
        },
    })).post(props.urls.save, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}

const targets = computed(() => ({
    role: props.roles.map(role => ({ value: role.code, label: role.name })),
    department: props.departments.map(department => ({ value: String(department.id), label: department.name + (department.is_active ? '' : ' (غير فعال)') })),
    user: props.users.map(person => ({ value: String(person.id), label: `${person.name}${person.employee_number ? ' — ' + person.employee_number : ''}${person.is_active ? '' : ' (موقوف)'}` })),
}[form.scope_type] ?? []));

// History and status changes ask for a reason, kept with the change.
const history = ref(null);
const pending = ref(null);
function act(kind, template, version = null) {
    pending.value = { kind, template, version, reason: '' };
}
function confirmAct() {
    const { kind, template, version, reason } = pending.value;
    const options = { preserveScroll: true, onSuccess: () => { pending.value = null; history.value = null; } };
    if (kind === 'restore') router.post(`/admin/dashboard-layouts/${template.id}/restore/${version}`, { reason }, options);
    else router.put(`/admin/dashboard-layouts/${template.id}/status`, { is_active: kind === 'activate', reason }, options);
}

// Preview: what a chosen user gets, from metadata only (no impersonation, no records).
const previewUser = ref('');
const preview = ref(null);
const previewError = ref('');
const previewBusy = ref(false);
async function runPreview() {
    if (!previewUser.value) return;
    previewBusy.value = true;
    previewError.value = '';
    preview.value = null;
    try { preview.value = await kpiRequest(`${props.urls.preview}?${kpiQuery({ user_id: previewUser.value })}`); }
    catch (failure) { previewError.value = failure.message; }
    finally { previewBusy.value = false; }
}
const summary = configuration => {
    const parts = [];
    const components = Object.entries(configuration?.components ?? {});
    if (components.length) parts.push(`${components.length} مكوّن`);
    if (Array.isArray(configuration?.shortcuts)) parts.push(`${configuration.shortcuts.length} اختصار`);
    if (Array.isArray(configuration?.hidden_navigation)) parts.push(`${configuration.hidden_navigation.length} رابط مخفي`);
    if (configuration?.landing) parts.push('صفحة بداية');
    return parts.join(' · ') || 'بلا تغييرات';
};
</script>

<template>
    <AppLayout title="تخصيص اللوحات">
        <div class="space-y-6">
            <PageHeader title="تخصيص اللوحات" eyebrow="إعدادات النظام"
                description="قوالب تحدد ما يظهر في «مساحتي» وترتيبه وحجمه، والاختصارات، وإخفاء روابط من القائمة، وصفحة البداية. الأولوية: الإعداد الافتراضي ← القالب العام ← قالب الدور ← قالب القسم ← تخصيص المستخدم. القالب يرتّب ويُخفي فقط ولا يمنح صلاحية: أي عنصر لا يملكه المستخدم لا يظهر له ولو أظهره القالب.">
                <button type="button" class="btn btn-primary" @click="open()">قالب جديد</button>
            </PageHeader>

            <section class="card space-y-3 p-4 sm:p-5" aria-labelledby="templates-title">
                <h2 id="templates-title" class="text-lg font-bold text-navy-800">القوالب المحفوظة</h2>
                <div v-if="templates.length" class="overflow-x-auto">
                    <table class="data-table w-full text-sm">
                        <thead><tr><th scope="col">الجهة</th><th scope="col">المستوى</th><th scope="col">المحتوى</th><th scope="col">الإصدار</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">إجراءات</span></th></tr></thead>
                        <tbody>
                            <tr v-for="template in templates" :key="template.id">
                                <td class="font-semibold text-navy-800">{{ template.label }}</td>
                                <td>{{ layers[template.scope_type] }}</td>
                                <td class="text-xs">{{ summary(template.configuration) }}</td>
                                <td>{{ template.current_version }}</td>
                                <td><StatusBadge :label="template.is_active ? 'مفعّل' : 'معطّل'" :tone="template.is_active ? 'success' : 'muted'" /></td>
                                <td class="flex flex-wrap gap-1">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="open(template)">تعديل</button>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="history = history === template.id ? null : template.id">السجل</button>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="act(template.is_active ? 'deactivate' : 'activate', template)">{{ template.is_active ? 'تعطيل' : 'تفعيل' }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="لا توجد قوالب بعد" description="بدون قوالب تظهر «مساحتي» بالإعداد الافتراضي الحالي لكل مستخدم بحسب صلاحياته." />

                <div v-for="template in templates.filter(item => item.id === history)" :key="`h${template.id}`" class="rounded-lg border border-slate-100 p-3">
                    <h3 class="font-semibold text-navy-800">سجل إصدارات: {{ template.label }}</h3>
                    <ol class="mt-2 space-y-2 text-xs leading-5">
                        <li v-for="version in template.history" :key="version.version" class="flex flex-wrap items-center justify-between gap-2 rounded bg-slate-50 p-2">
                            <span><strong>إصدار {{ version.version }}</strong> · {{ version.created_at }} · {{ version.created_by ?? '—' }} · {{ summary(version.configuration) }}<br><span class="text-slate-500">{{ version.reason }}</span></span>
                            <button v-if="version.version !== template.current_version" type="button" class="btn btn-secondary btn-sm" @click="act('restore', template, version.version)">استرجاع</button>
                            <StatusBadge v-else label="الحالي" tone="info" />
                        </li>
                    </ol>
                </div>

                <form v-if="pending" class="flex flex-wrap items-end gap-2 rounded-lg border border-gold-200 bg-gold-50 p-3" @submit.prevent="confirmAct">
                    <p class="w-full text-sm font-semibold text-navy-800">{{ pending.kind === 'restore' ? `استرجاع الإصدار ${pending.version} من «${pending.template.label}» كإصدار جديد` : (pending.kind === 'activate' ? `تفعيل «${pending.template.label}»` : `تعطيل «${pending.template.label}»`) }}</p>
                    <label class="field-label flex-1">السبب (يُسجل)<input v-model="pending.reason" required minlength="5" maxlength="500" class="field mt-1 w-full"></label>
                    <button class="btn btn-primary btn-sm" :disabled="pending.reason.trim().length < 5">تأكيد</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="pending = null">تراجع</button>
                </form>
            </section>

            <section v-if="editing" class="card space-y-5 p-4 sm:p-5" aria-labelledby="layout-editor-title">
                <header class="flex flex-wrap items-start justify-between gap-2">
                    <h2 id="layout-editor-title" ref="editorTitle" tabindex="-1" class="text-lg font-bold text-navy-800 outline-none">{{ editing.id ? `تعديل: ${editing.label}` : 'قالب جديد' }}</h2>
                    <button type="button" class="btn btn-secondary btn-sm" @click="editing = null">إغلاق</button>
                </header>

                <form class="space-y-5" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="field-label">المستوى
                            <select v-model="form.scope_type" :disabled="!!editing.id" class="field mt-1 w-full" @change="form.scope_key = ''">
                                <option v-for="(label, code) in layers" :key="code" :value="code">{{ label }}</option>
                            </select>
                        </label>
                        <label v-if="form.scope_type !== 'organization'" class="field-label sm:col-span-2">الجهة
                            <select v-model="form.scope_key" :disabled="!!editing.id" required class="field mt-1 w-full">
                                <option value="" disabled>اختر</option>
                                <option v-for="target in targets" :key="target.value" :value="target.value">{{ target.label }}</option>
                            </select>
                            <span v-if="form.errors.scope_key" class="field-error">{{ form.errors.scope_key }}</span>
                        </label>
                        <label v-if="form.scope_type === 'department'" class="flex items-center gap-2 text-sm sm:col-span-3"><input v-model="form.include_subunits" type="checkbox">يُطبق أيضًا على موظفي الوحدات التابعة لهذا القسم</label>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="font-semibold text-navy-800">مكوّنات «مساحتي»</legend>
                        <p class="text-xs text-slate-500">اتركه «يرث» ليأخذ قيمة المستوى الأدنى. الترتيب الأصغر يظهر أولًا. عمود «يتطلب» يوضح من يمكن أن يرى المكوّن أصلًا.</p>
                        <div class="overflow-x-auto">
                            <table class="data-table w-full text-sm">
                                <thead><tr><th scope="col">المكوّن</th><th scope="col">يتطلب</th><th scope="col">الإظهار</th><th scope="col">الترتيب</th><th scope="col">الحجم</th><th scope="col">خيارات</th></tr></thead>
                                <tbody>
                                    <tr v-for="(component, key) in components" :key="key">
                                        <td><span class="font-semibold text-navy-800">{{ component.label }}</span><br><span class="text-[11px] text-slate-500">{{ component.description }} (افتراضيًا: {{ component.visible ? 'يظهر' : 'مخفي' }}، {{ sizes[component.size] }})</span></td>
                                        <td class="text-xs">{{ component.requirement }}</td>
                                        <td>
                                            <span v-if="component.locked" class="text-xs text-slate-500">ثابت</span>
                                            <select v-else v-model="form.components[key].visibility" :aria-label="`إظهار ${component.label}`" class="field w-28">
                                                <option value="">يرث</option><option value="show">يظهر</option><option value="hide">يُخفى</option>
                                            </select>
                                        </td>
                                        <td><input v-model="form.components[key].order" type="number" min="0" max="1000" :placeholder="String(component.order)" :aria-label="`ترتيب ${component.label}`" class="field w-24"></td>
                                        <td>
                                            <select v-model="form.components[key].size" :aria-label="`حجم ${component.label}`" class="field w-32">
                                                <option value="">يرث</option>
                                                <option v-for="(label, size) in sizes" :key="size" :value="size">{{ label }}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <label v-for="(option, name) in component.options" :key="name" class="block text-xs">{{ option.label }}
                                                <input v-model="form.components[key].options[name]" type="number" :min="option.min" :max="option.max" class="field mt-1 w-24">
                                            </label>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-2 rounded-lg border border-slate-100 p-3">
                        <legend class="px-1 font-semibold text-navy-800">الاختصارات</legend>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input v-model="form.shortcuts_mode" type="radio" value="inherit">يرث</label>
                            <label class="flex items-center gap-2"><input v-model="form.shortcuts_mode" type="radio" value="set">قائمة هذا القالب</label>
                        </div>
                        <div v-if="form.shortcuts_mode === 'set'" class="flex flex-wrap gap-3">
                            <label v-for="item in linkable" :key="item.code" class="flex items-center gap-2 text-sm"><input v-model="form.shortcuts" type="checkbox" :value="item.code">{{ item.label }}</label>
                        </div>
                        <p class="text-xs text-slate-500">يظهر للمستخدم منها ما يملك فتحه فقط؛ فعّل مكوّن «اختصارات» ليظهر القسم.</p>
                    </fieldset>

                    <fieldset class="space-y-2 rounded-lg border border-slate-100 p-3">
                        <legend class="px-1 font-semibold text-navy-800">إخفاء روابط من القائمة الجانبية</legend>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input v-model="form.hidden_mode" type="radio" value="inherit">يرث</label>
                            <label class="flex items-center gap-2"><input v-model="form.hidden_mode" type="radio" value="set">قائمة هذا القالب</label>
                        </div>
                        <div v-if="form.hidden_mode === 'set'" class="flex flex-wrap gap-3">
                            <label v-for="item in hideable" :key="item.code" class="flex items-center gap-2 text-sm"><input v-model="form.hidden_navigation" type="checkbox" :value="item.code">{{ item.label }}</label>
                        </div>
                        <p class="text-xs text-slate-500">الإخفاء لا يمنع الصفحة نفسها؛ الصلاحية تبقى كما هي. لا يُخفى رابط الصفحة الرئيسية.</p>
                    </fieldset>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="field-label">صفحة البداية بعد الدخول
                            <select v-model="form.landing" class="field mt-1 w-full">
                                <option value="">يرث</option>
                                <option value="home">«مساحتي»</option>
                                <option v-for="item in linkable" :key="item.code" :value="item.code">{{ item.label }}</option>
                            </select>
                            <span class="mt-1 block text-xs text-slate-500">تُستخدم فقط إذا كان المستخدم يملك فتحها، وإلا يدخل إلى «مساحتي».</span>
                        </label>
                        <label class="field-label">سبب التغيير (يُسجل مع الإصدار)
                            <input v-model="form.reason" required minlength="5" maxlength="500" class="field mt-1 w-full">
                        </label>
                    </div>

                    <ul v-if="Object.keys(form.errors).length" role="alert" class="space-y-1 text-sm text-red-700"><li v-for="(error, field) in form.errors" :key="field">{{ error }}</li></ul>
                    <button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ كإصدار جديد' }}</button>
                </form>
            </section>

            <section class="card space-y-4 p-4 sm:p-5" aria-labelledby="preview-title">
                <div>
                    <h2 id="preview-title" class="text-lg font-bold text-navy-800">معاينة مستخدم</h2>
                    <p class="text-xs text-slate-500">تعرض ما يُطبق على المستخدم ولماذا، من الإعدادات والصلاحيات فقط: دون الدخول باسمه ودون عرض أي سجل من بياناته.</p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="runPreview">
                    <label class="field-label min-w-64 flex-1">المستخدم
                        <select v-model="previewUser" required class="field mt-1 w-full">
                            <option value="" disabled>اختر مستخدمًا</option>
                            <option v-for="person in users" :key="person.id" :value="person.id">{{ person.name }}{{ person.is_active ? '' : ' (موقوف)' }}</option>
                        </select>
                    </label>
                    <button class="btn btn-secondary" :disabled="previewBusy || !previewUser">{{ previewBusy ? 'جارٍ…' : 'عرض المعاينة' }}</button>
                </form>
                <p v-if="previewError" role="alert" class="alert-error text-sm">{{ previewError }}</p>

                <div v-if="preview" class="space-y-4" aria-live="polite">
                    <dl class="grid gap-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">القسم</dt><dd>{{ preview.user.department ?? '—' }}{{ preview.user.active ? '' : ' · الحساب موقوف' }}</dd></div>
                        <div><dt class="text-slate-500">رئيس لـ</dt><dd>{{ preview.user.heads.join('، ') || '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-slate-500">التكليفات</dt><dd>{{ preview.user.assignments.join(' · ') || '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-slate-500">القوالب المطبقة بالترتيب</dt><dd>{{ preview.applied.join(' ← ') || 'لا قوالب؛ الإعداد الافتراضي' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-slate-500">صفحة البداية</dt><dd>{{ preview.landing.url ?? '«مساحتي»' }}<span v-if="preview.landing.code && !preview.landing.url" class="text-xs text-gold-800"> (القالب حدد صفحة لا يملك فتحها، فيدخل إلى «مساحتي»)</span></dd></div>
                    </dl>
                    <div class="overflow-x-auto">
                        <table class="data-table w-full text-sm">
                            <thead><tr><th scope="col">المكوّن</th><th scope="col">الإعداد</th><th scope="col">مصدره</th><th scope="col">يظهر؟</th><th scope="col">السبب</th></tr></thead>
                            <tbody>
                                <tr v-for="item in preview.components" :key="item.key">
                                    <td>{{ item.label }}</td><td>{{ item.configured ? 'يظهر' : 'مخفي' }} · {{ sizes[item.size] }} · ترتيب {{ item.order }}</td><td class="text-xs">{{ item.source }}</td>
                                    <td><StatusBadge :label="item.shown ? 'يظهر' : 'لا يظهر'" :tone="item.shown ? 'success' : 'muted'" /></td><td class="text-xs">{{ item.reason }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="preview.shortcuts.allowed.length || preview.shortcuts.denied.length" class="text-sm">
                        <p class="font-semibold text-navy-800">الاختصارات ({{ preview.shortcuts.source }})</p>
                        <p>تظهر: {{ preview.shortcuts.allowed.map(item => item.label).join('، ') || '—' }}</p>
                        <p v-for="item in preview.shortcuts.denied" :key="item.code" class="text-xs text-slate-500">لا تظهر «{{ item.label }}»: {{ item.reason }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table w-full text-sm">
                            <thead><tr><th scope="col">رابط القائمة</th><th scope="col">الصلاحية</th><th scope="col">التفسير</th><th scope="col">مخفي بقالب</th></tr></thead>
                            <tbody>
                                <tr v-for="item in preview.menu" :key="item.code">
                                    <td :class="item.group ? 'font-semibold' : ''">{{ item.label }}</td>
                                    <td><StatusBadge :label="item.allowed ? 'مسموح' : 'غير مسموح'" :tone="item.allowed ? 'success' : 'muted'" /></td>
                                    <td class="text-xs">{{ item.reason }}</td>
                                    <td class="text-xs">{{ item.hidden_by_template ? `نعم (${preview.hidden_navigation.source})` : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
