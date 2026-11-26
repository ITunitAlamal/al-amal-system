<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import NavigationEditorRow from '../../../Components/NavigationEditorRow.vue';
import { menuIconPaths } from '../../../Support/menuIcons.js';
import { useUnsavedChanges } from '../../../Support/useUnsavedChanges.js';

const props = defineProps({
    items: { type: Array, default: () => [] },
    version: { type: String, required: true },
    roles: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    icons: { type: Array, default: () => [] },
});
const page = usePage();

// The draft: entries by id plus the order of the top level and of each group's links.
const entries = ref({});
const topOrder = ref([]);
const childOrder = ref({});
const saved = ref('');
function payload() {
    const list = [];
    const push = (id, parent) => {
        const entry = entries.value[id];
        list.push({ id: entry.id, parent_id: parent, label_ar: entry.label_ar.trim(), icon: entry.icon, is_active: entry.is_active, audience_roles: [...entry.audience_roles].sort() });
    };
    topOrder.value.forEach(id => {
        push(id, null);
        (childOrder.value[id] || []).forEach(child => push(child, id));
    });
    return list;
}
function load() {
    const map = {};
    const children = {};
    const top = [];
    props.items.forEach(item => { map[item.id] = { ...item, audience_roles: [...item.audience_roles] }; });
    props.items.forEach(item => {
        if (item.parent_id === null || !map[item.parent_id]) { top.push(item.id); map[item.id].parent_id = null; } else { (children[item.parent_id] ||= []).push(item.id); }
    });
    entries.value = map;
    topOrder.value = top;
    childOrder.value = children;
    saved.value = JSON.stringify(payload());
}
load();
watch(() => props.version, load);
const dirty = computed(() => JSON.stringify(payload()) !== saved.value);
useUnsavedChanges(() => dirty.value);

const groups = computed(() => topOrder.value.map(id => entries.value[id]).filter(entry => entry.is_group));
const siblings = id => (entries.value[id].parent_id === null ? topOrder.value : childOrder.value[entries.value[id].parent_id]);
function shift(id, delta) {
    const list = siblings(id);
    const index = list.indexOf(id);
    const target = index + delta;
    if (target < 0 || target >= list.length) { return; }
    [list[index], list[target]] = [list[target], list[index]];
}
function moveTo(id, value) {
    const parent = value === '' || value === null ? null : Number(value);
    const entry = entries.value[id];
    if (entry.parent_id === parent) { return; }
    const from = siblings(id);
    from.splice(from.indexOf(id), 1);
    entry.parent_id = parent;
    if (parent === null) { topOrder.value.push(id); } else { (childOrder.value[parent] ||= []).push(id); openCards.value = new Set([...openCards.value, parent]); }
}
function update(id, field, value) {
    entries.value[id][field] = value;
}

// Folding and search.
const openCards = ref(new Set());
const query = ref('');
const searching = computed(() => query.value.trim() !== '');
const matches = entry => !searching.value || [entry.label_ar, entry.code, entry.page || ''].some(text => text.includes(query.value.trim()));
const childrenOf = id => (childOrder.value[id] || []).map(child => entries.value[child]);
const shownChildren = id => childrenOf(id).filter(child => !searching.value || matches(child) || matches(entries.value[id]));
const shownTop = computed(() => topOrder.value.filter(id => matches(entries.value[id]) || (entries.value[id].is_group && shownChildren(id).length)));
const isOpen = id => searching.value || openCards.value.has(id);
function toggleCard(id) {
    const next = new Set(openCards.value);
    if (next.has(id)) { next.delete(id); } else { next.add(id); }
    openCards.value = next;
}
const allOpen = computed(() => groups.value.every(group => openCards.value.has(group.id)));
function toggleAll() {
    openCards.value = allOpen.value ? new Set() : new Set(groups.value.map(group => group.id));
}

// Live preview for one user; unsaved names, order, visibility and role restrictions are applied here.
const previewUser = ref(page.props.auth?.user?.id || props.users[0]?.id || null);
const preview = ref(null);
const previewError = ref('');
async function loadPreview() {
    if (!previewUser.value) { preview.value = null; return; }
    try {
        const response = await fetch(`/admin/navigation/preview/${previewUser.value}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) { throw new Error(); }
        preview.value = await response.json();
        previewError.value = '';
    } catch {
        preview.value = null;
        previewError.value = 'تعذر تحميل المعاينة.';
    }
}
onMounted(loadPreview);
watch(previewUser, loadPreview);
watch(() => props.version, loadPreview);
const previewName = computed(() => props.users.find(user => user.id === previewUser.value)?.name || '');
const inAudience = entry => !entry.audience_roles.length || entry.audience_roles.some(code => (preview.value?.roles || []).includes(code));
function hiddenReason(entry) {
    if (!preview.value) { return ''; }
    const info = preview.value.entries[entry.code];
    if (!info) { return 'احفظ ثم أعد التحميل لمعرفة ظهوره.'; }
    if (!info.allowed) { return info.reason; }
    if (!inAudience(entry)) { return 'مقصور على أدوار لا يحملها.'; }
    if (entry.code === 'startable_workflows' && !preview.value.workflows) { return 'لا توجد أنواع طلبات يستطيع بدأها.'; }
    return '';
}
const shows = entry => entry.is_active && hiddenReason(entry) === '';
const previewMenu = computed(() => {
    if (!preview.value) { return []; }
    const menu = [];
    topOrder.value.forEach(id => {
        const entry = entries.value[id];
        if (!shows(entry)) { return; }
        const kids = childrenOf(id).filter(shows);
        if (!entry.is_group || kids.length) { menu.push({ entry, kids }); }
    });
    return menu;
});
const linkLabel = entry => (entry.code === 'startable_workflows' && preview.value ? `${entry.label_ar} (${preview.value.workflows})` : entry.label_ar);

// Server actions.
const saving = ref(false);
function save() {
    router.put('/admin/navigation/layout', { version: props.version, items: payload() }, {
        preserveScroll: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
}
const newGroup = ref({ open: false, label_ar: '', icon: 'folder' });
function createGroup() {
    router.post('/admin/navigation/groups', { label_ar: newGroup.value.label_ar, icon: newGroup.value.icon }, {
        preserveScroll: true,
        onSuccess: () => { newGroup.value = { open: false, label_ar: '', icon: 'folder' }; },
    });
}
function deleteGroup(entry) {
    if (window.confirm(`حذف المجموعة «${entry.label_ar}»؟`)) {
        router.delete(`/admin/navigation/groups/${entry.id}`, { preserveScroll: true });
    }
}
function resetDefaults() {
    if (window.confirm('استعادة التنظيم الافتراضي؟ تعود أسماء الروابط ومجموعاتها وترتيبها وظهورها إلى الإعداد الأصلي، وتُلغى قيود الأدوار. تبقى المجموعات التي أضفتها فارغة.')) {
        router.post('/admin/navigation/reset', {}, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="القائمة الرئيسية">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="الإعدادات والصلاحيات" title="القائمة الرئيسية" description="تحكم في ما تعرضه القائمة الجانبية: أظهر أو أخفِ، غيّر الاسم والأيقونة، رتّب بالأسهم، انقل الرابط إلى مجموعة، أو اقصره على أدوار محددة. القائمة لا تمنح صلاحية؛ كل صفحة تبقى محمية بصلاحيتها.">
                <button type="button" class="btn btn-secondary" :disabled="dirty" :title="dirty ? 'احفظ التغييرات أولًا' : ''" @click="newGroup.open = !newGroup.open">+ مجموعة جديدة</button>
                <button type="button" class="btn btn-secondary" @click="resetDefaults">استعادة التنظيم الافتراضي</button>
            </PageHeader>

            <div v-if="dirty" class="sticky top-20 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gold-300 bg-gold-50 px-4 py-3 shadow-sm">
                <p class="text-sm font-semibold text-navy-800">لديك تغييرات غير محفوظة. المعاينة تعرضها قبل الحفظ.</p>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'جارٍ الحفظ…' : 'حفظ التغييرات' }}</button>
                    <button type="button" class="btn btn-secondary" :disabled="saving" @click="load">تراجع</button>
                </div>
            </div>

            <form v-if="newGroup.open && !dirty" class="card flex flex-wrap items-end gap-3" @submit.prevent="createGroup">
                <label class="text-sm font-semibold">اسم المجموعة
                    <input v-model="newGroup.label_ar" class="field mt-1 w-64" required maxlength="150" placeholder="مثل: الخدمات العامة" />
                </label>
                <div class="text-sm font-semibold">الأيقونة
                    <div class="mt-1 flex flex-wrap gap-1">
                        <button v-for="icon in icons" :key="icon" type="button" class="flex h-9 w-9 items-center justify-center rounded-lg border" :class="newGroup.icon === icon ? 'border-navy-600 bg-navy-50 text-navy-800' : 'border-slate-200 text-slate-600'" :aria-label="icon" @click="newGroup.icon = icon">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="menuIconPaths[icon]" /></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">إضافة</button>
            </form>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <section class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="query" class="field w-64" placeholder="ابحث عن رابط أو مجموعة" aria-label="بحث في القائمة" />
                        <button type="button" class="btn btn-sm btn-secondary" @click="toggleAll">{{ allOpen ? 'طي كل المجموعات' : 'فتح كل المجموعات' }}</button>
                    </div>

                    <template v-for="(id, index) in shownTop" :key="id">
                        <div v-if="entries[id].is_group" class="card space-y-3 p-4">
                            <div class="flex items-start gap-2">
                                <button type="button" class="mt-2 rounded p-1 text-slate-500 hover:bg-slate-100" :aria-expanded="isOpen(id)" :aria-label="isOpen(id) ? 'طي المجموعة' : 'فتح المجموعة'" @click="toggleCard(id)">
                                    <svg class="h-4 w-4 transition-transform" :class="isOpen(id) ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="menuIconPaths['chevron-down']" /></svg>
                                </button>
                                <NavigationEditorRow class="flex-1" :entry="entries[id]" :roles="roles" :icons="icons" :first="index === 0" :last="index === shownTop.length - 1"
                                    :child-count="childrenOf(id).length" :hidden-reason="entries[id].is_active ? hiddenReason(entries[id]) : ''" :preview-name="previewName"
                                    @up="shift(id, -1)" @down="shift(id, 1)" @update="(field, value) => update(id, field, value)">
                                    <button v-if="entries[id].custom && !childrenOf(id).length" type="button" class="text-sm text-red-700 hover:underline" :disabled="dirty" @click="deleteGroup(entries[id])">حذف</button>
                                </NavigationEditorRow>
                            </div>
                            <div v-if="isOpen(id)" class="space-y-3 border-r-2 border-slate-100 pr-4 ms-8">
                                <NavigationEditorRow v-for="(child, childIndex) in shownChildren(id)" :key="child.id" :entry="child" :groups="groups" :roles="roles" :icons="icons"
                                    :first="childIndex === 0" :last="childIndex === shownChildren(id).length - 1" :hidden-reason="child.is_active ? hiddenReason(child) : ''" :preview-name="previewName"
                                    @up="shift(child.id, -1)" @down="shift(child.id, 1)" @move="value => moveTo(child.id, value)" @update="(field, value) => update(child.id, field, value)" />
                                <p v-if="!childrenOf(id).length" class="text-sm text-slate-500">مجموعة فارغة. انقل إليها روابط من قائمة «مكان» في أي رابط.</p>
                            </div>
                        </div>
                        <div v-else class="card p-4">
                            <NavigationEditorRow :entry="entries[id]" :groups="groups" :roles="roles" :icons="icons" :first="index === 0" :last="index === shownTop.length - 1"
                                :hidden-reason="entries[id].is_active ? hiddenReason(entries[id]) : ''" :preview-name="previewName"
                                @up="shift(id, -1)" @down="shift(id, 1)" @move="value => moveTo(id, value)" @update="(field, value) => update(id, field, value)" />
                        </div>
                    </template>
                    <p v-if="!shownTop.length" class="card text-sm text-slate-500">لا نتائج لهذا البحث.</p>
                </section>

                <aside class="lg:sticky lg:top-24 lg:self-start">
                    <div class="overflow-hidden rounded-2xl bg-gradient-to-b from-navy-800 to-navy-950 text-white shadow-sm">
                        <div class="space-y-2 border-b border-white/10 p-4">
                            <p class="text-sm font-bold">معاينة القائمة</p>
                            <label class="block text-xs text-navy-100">كما يراها
                                <select v-model="previewUser" class="mt-1 w-full rounded-lg border-0 bg-white/10 px-2 py-1.5 text-sm text-white">
                                    <option v-for="user in users" :key="user.id" :value="user.id" class="text-slate-800">{{ user.name }}</option>
                                </select>
                            </label>
                            <p v-if="previewError" class="text-xs text-red-200" role="alert">{{ previewError }}</p>
                        </div>
                        <nav class="max-h-[70vh] space-y-1 overflow-y-auto p-3 text-sm" aria-label="معاينة القائمة">
                            <template v-for="node in previewMenu" :key="node.entry.id">
                                <p class="flex items-center gap-2 rounded-lg px-2 py-1.5" :class="node.entry.is_group ? 'mt-2 font-bold text-white' : 'text-navy-100'">
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="menuIconPaths[node.entry.icon] || menuIconPaths.folder" /></svg>
                                    {{ linkLabel(node.entry) }}
                                </p>
                                <p v-for="kid in node.kids" :key="kid.id" class="flex items-center gap-2 py-1 ps-8 pe-2 text-navy-100">
                                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="menuIconPaths[kid.icon] || menuIconPaths.folder" /></svg>
                                    {{ linkLabel(kid) }}
                                </p>
                            </template>
                            <p v-if="preview && !previewMenu.length" class="text-xs text-navy-200">لا يرى هذا المستخدم أي رابط.</p>
                        </nav>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-500">تعرض المعاينة تغييراتك قبل الحفظ. سبب اختفاء أي رابط عن هذا المستخدم يظهر تحت الرابط نفسه.</p>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
