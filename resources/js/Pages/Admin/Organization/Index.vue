<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import DelegationsPanel from '../../../Components/Organization/DelegationsPanel.vue';

// The approved tree, structural proposals for the executive director, the archiving review and delegation.
const props = defineProps({
    tree: { type: Array, default: () => [] },
    types: { type: Object, default: () => ({}) },
    changeLabels: { type: Object, default: () => ({}) },
    statusLabels: { type: Object, default: () => ({}) },
    changes: { type: Array, default: () => [] },
    inventory: { type: Array, default: () => [] },
    policy: { type: Array, default: () => [] },
    delegations: { type: Array, default: () => [] },
    delegationOptions: { type: Object, default: () => ({ permissions: [], scopes: [] }) },
    eligibleHeads: { type: [Object, Array], default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});
const page = usePage();
const tabs = computed(() => [
    { key: 'tree', label: 'الشجرة' },
    { key: 'changes', label: `المقترحات${pending.value.length ? ` (${pending.value.length})` : ''}` },
    ...(props.can.manage || props.can.executive ? [{ key: 'review', label: 'مراجعة الأرشفة' }] : []),
    { key: 'delegation', label: 'التفويض' },
]);
const tab = ref(new URLSearchParams(String(page.url).split('?')[1] || '').get('tab') || 'tree');
watch(tab, value => router.replace({ url: `/admin/organization${value === 'tree' ? '' : `?tab=${value}`}`, preserveState: true, preserveScroll: true }));

const statusTone = { suggested: 'muted', in_review: 'info', returned: 'warning', rejected: 'danger', withdrawn: 'muted', applied: 'success' };
const categoryLabels = { approved: ['معتمد', 'success'], unit: ['وحدة وظيفية', 'info'], retire_candidate: ['مرشح للأرشفة', 'warning'], needs_review: ['يحتاج قرارًا', 'warning'], archived: ['مؤرشف', 'muted'] };
const pending = computed(() => props.changes.filter(change => ['in_review', 'returned'].includes(change.status)));
const suggested = computed(() => props.changes.filter(change => change.status === 'suggested'));
const decided = computed(() => props.changes.filter(change => ['applied', 'rejected'].includes(change.status)));
const describe = c => ({
    create_department: `إنشاء القسم المستقل «${c.proposed_name}»`,
    create_unit: `إنشاء «${c.proposed_name}» تحت «${c.parent}»`,
    convert_to_unit: `تحويل «${c.node}» إلى وحدة تحت «${c.parent}»`,
    rename: `تسمية «${c.node}» «${c.proposed_name}»`,
    retire: `أرشفة «${c.node}»`,
    reparent: c.parent ? `نقل «${c.node}» إلى «${c.parent}»` : `جعل «${c.node}» قسمًا رئيسيًا`,
    reactivate: `إعادة تفعيل «${c.node}»`,
}[c.type] || c.type_label);

// Tree and the selected node.
const selectedId = ref(props.tree[0]?.id ?? null);
const node = computed(() => props.tree.find(item => item.id === selectedId.value) || null);
const owner = item => (item.node_type === 'unit' ? item.parent_id : item.id);
const canEdit = item => Boolean(item) && (props.can.manage || (props.can.headed || []).includes(owner(item)));
const descendants = id => {
    const found = new Set([id]);
    let added = true;
    while (added) {
        added = false;
        props.tree.forEach(item => { if (item.parent_id !== null && found.has(item.parent_id) && !found.has(item.id)) { found.add(item.id); added = true; } });
    }
    return found;
};
const parentChoices = computed(() => {
    if (!node.value) { return []; }
    const excluded = descendants(node.value.id);
    return props.tree.filter(item => item.node_type !== 'unit' && item.is_active && !excluded.has(item.id));
});
const changeTypes = computed(() => {
    if (!node.value) { return []; }
    const types = node.value.node_type === 'unit' ? ['rename', 'reparent', 'retire'] : ['create_unit', 'rename', 'reparent', 'retire'];
    if (props.can.manage) { types.unshift('create_department'); }
    if (props.can.manage && node.value.node_type === 'department' && !props.tree.some(item => item.parent_id === node.value.id)) { types.push('convert_to_unit'); }
    if (!node.value.is_active) { types.push('reactivate'); }
    return node.value.evidence ? types.filter(type => type !== 'retire') : types;
});

const headForm = useForm({ head_user_id: null });
const headChoices = computed(() => (node.value ? props.eligibleHeads[node.value.id] || [] : []));
function saveHead() {
    headForm.put(`/admin/organization/nodes/${node.value.id}/head`, { preserveScroll: true });
}
const details = useForm({ description: '', responsibilities: '', effective_from: '' });
const proposal = useForm({ change_type: '', department_id: null, parent_id: '', proposed_name: '', proposed_code: '', description: '', responsibilities: '', rationale: '' });
watch(node, value => {
    headForm.defaults({ head_user_id: value?.head_user_id ?? null });
    headForm.reset();
    headForm.clearErrors();
    details.defaults({ description: value?.description || '', responsibilities: value?.responsibilities || '', effective_from: value?.effective_from ? String(value.effective_from).slice(0, 10) : '' });
    details.reset();
    details.clearErrors();
    proposal.reset();
    proposal.clearErrors();
}, { immediate: true });
function saveDetails() {
    details.transform(data => ({ ...data, effective_from: data.effective_from || null })).put(`/admin/organization/nodes/${node.value.id}/details`, { preserveScroll: true });
}
function propose() {
    const type = proposal.change_type;
    proposal.transform(data => ({
        change_type: type,
        department_id: ['create_unit', 'create_department'].includes(type) ? null : node.value.id,
        parent_id: type === 'create_unit' ? node.value.id : (['reparent', 'convert_to_unit'].includes(type) ? (data.parent_id || null) : null),
        proposed_name: ['create_unit', 'create_department', 'rename', 'convert_to_unit'].includes(type) ? data.proposed_name : null,
        proposed_code: type === 'create_department' ? data.proposed_code : null,
        description: ['create_unit', 'create_department'].includes(type) ? data.description : null,
        responsibilities: ['create_unit', 'create_department'].includes(type) ? data.responsibilities : null,
        rationale: data.rationale,
    })).post('/admin/organization/changes', { preserveScroll: true });
}

// Suggestions and archiving review.
function submitSuggestion(change) {
    const rationale = window.prompt(`مبرر تقديم «${change.proposed_name}» للمدير التنفيذي (اختياري):`, change.rationale);
    if (rationale !== null) { router.post(`/admin/organization/changes/${change.id}/submit`, { rationale }, { preserveScroll: true }); }
}
function dismiss(change) {
    if (window.confirm(`استبعاد اقتراح «${change.proposed_name}»؟`)) { router.post(`/admin/organization/changes/${change.id}/dismiss`, {}, { preserveScroll: true }); }
}
function proposeRetire(row) {
    const rationale = window.prompt(`مبرر أرشفة «${row.name}» (10 أحرف على الأقل):`);
    if (rationale && rationale.trim().length >= 10) {
        router.post('/admin/organization/changes', { change_type: 'retire', department_id: row.id, rationale }, { preserveScroll: true });
    }
}

// Delegation policy (the executive director decides).
const policyRows = ref(props.policy.map(row => ({ ...row })));
watch(() => props.policy, value => { policyRows.value = value.map(row => ({ ...row })); });
function savePolicy(row) {
    router.put(`/admin/organization/delegation-policy/${row.id}`, { is_active: row.is_active, max_days: row.max_days, requires_executive: row.requires_executive }, { preserveScroll: true });
}
const references = refs => Object.entries(refs).map(([key, count]) => `${key.split('.')[0]}: ${count}`).join('، ');
</script>

<template>
    <AppLayout title="الهيكل التنظيمي">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="الموظفون" title="الهيكل التنظيمي" description="الشجرة المعتمدة: مكاتب تنفيذية وأقسام ووحداتها الوظيفية. أي تعديل في الهيكل يُرفع مقترحًا ويُطبَّق عند اعتماد المدير التنفيذي، ويبقى في سجله." />

            <div class="flex flex-wrap gap-1 border-b border-slate-200" role="tablist" aria-label="أقسام الصفحة">
                <button v-for="item in tabs" :key="item.key" type="button" role="tab" :aria-selected="tab === item.key" class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold"
                    :class="tab === item.key ? 'border-navy-700 text-navy-800' : 'border-transparent text-slate-500 hover:text-navy-700'" @click="tab = item.key">{{ item.label }}</button>
            </div>

            <!-- Tree -->
            <div v-if="tab === 'tree'" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_26rem]">
                <section class="card p-3">
                    <ul class="space-y-0.5" role="tree" aria-label="الهيكل التنظيمي">
                        <li v-for="item in tree" :key="item.id" role="treeitem" :aria-selected="selectedId === item.id">
                            <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-right text-sm hover:bg-slate-50" :class="selectedId === item.id ? 'bg-navy-50' : ''"
                                :style="{ paddingInlineStart: `${0.5 + item.depth * 1.5}rem` }" @click="selectedId = item.id">
                                <span class="font-semibold" :class="item.is_active ? 'text-navy-800' : 'text-slate-400'">{{ item.name }}</span>
                                <StatusBadge v-if="item.node_type !== 'department'" :label="item.type_label" :tone="item.node_type === 'unit' ? 'info' : 'success'" />
                                <StatusBadge v-if="!item.is_active" label="معطّل" tone="muted" />
                                <span class="ms-auto whitespace-nowrap text-xs text-slate-500">{{ item.node_type === 'unit' ? `${item.unit_members} موزعون` : `${item.staff} موظف` }}</span>
                            </button>
                        </li>
                    </ul>
                </section>

                <aside v-if="node" class="space-y-4">
                    <section class="card space-y-2 text-sm">
                        <h2 class="card-title">{{ node.name }}</h2>
                        <p class="text-slate-500">{{ node.type_label }} · <span dir="ltr">{{ node.code }}</span>{{ node.is_active ? '' : ' · معطّل' }}</p>
                        <p><span class="text-slate-500">الرئيس:</span> {{ node.head || 'لم يُعيَّن' }}</p>
                        <p v-if="node.evidence"><span class="text-slate-500">سند الاعتماد:</span> {{ node.evidence }}</p>
                        <p v-if="node.description" class="whitespace-pre-line">{{ node.description }}</p>
                        <p v-if="node.responsibilities" class="whitespace-pre-line"><span class="text-slate-500">المسؤوليات:</span> {{ node.responsibilities }}</p>
                    </section>

                    <form v-if="can.manage && node.node_type !== 'unit'" class="card space-y-2" @submit.prevent="saveHead">
                        <h3 class="font-bold text-navy-800">رئيس {{ node.node_type === 'office' ? 'المكتب' : 'القسم' }}</h3>
                        <select v-model="headForm.head_user_id" class="field w-full" aria-label="الرئيس">
                            <option :value="null">— بلا رئيس —</option>
                            <option v-for="person in headChoices" :key="person.id" :value="person.id">{{ person.name }}</option>
                        </select>
                        <p v-if="!headChoices.length" class="text-xs text-slate-500">لا يحمل أحد دور «رئيس قسم» في هذا القسم بعد؛ أسنده من «حسابات الموظفين والأدوار» ثم عيّنه هنا.</p>
                        <p v-for="(message, key) in headForm.errors" :key="key" class="text-sm text-red-700" role="alert">{{ message }}</p>
                        <button type="submit" class="btn btn-secondary btn-sm" :disabled="headForm.processing || !headForm.isDirty">حفظ الرئيس</button>
                    </form>

                    <form v-if="canEdit(node)" class="card space-y-2" @submit.prevent="saveDetails">
                        <h3 class="font-bold text-navy-800">الوصف والمسؤوليات</h3>
                        <textarea v-model="details.description" rows="2" maxlength="2000" class="field w-full" placeholder="وصف مختصر"></textarea>
                        <textarea v-model="details.responsibilities" rows="3" maxlength="4000" class="field w-full" placeholder="المسؤوليات الرئيسية"></textarea>
                        <label class="block text-sm">يسري من<input v-model="details.effective_from" type="date" class="field mt-1 w-full" /></label>
                        <button type="submit" class="btn btn-secondary btn-sm" :disabled="details.processing || !details.isDirty">حفظ</button>
                    </form>

                    <form v-if="canEdit(node) && changeTypes.length" class="card space-y-2" @submit.prevent="propose">
                        <h3 class="font-bold text-navy-800">اقتراح تعديل على الهيكل</h3>
                        <select v-model="proposal.change_type" class="field w-full" required>
                            <option value="">اختر التعديل</option>
                            <option v-for="type in changeTypes" :key="type" :value="type">{{ type === 'create_unit' ? 'إنشاء وحدة وظيفية تحته' : changeLabels[type] }}</option>
                        </select>
                        <input v-if="['create_unit', 'create_department', 'rename', 'convert_to_unit'].includes(proposal.change_type)" v-model="proposal.proposed_name" class="field w-full" maxlength="255"
                            :required="proposal.change_type !== 'convert_to_unit'" :placeholder="proposal.change_type === 'create_department' ? 'اسم القسم المستقل' : (proposal.change_type === 'create_unit' ? 'اسم الوحدة' : (proposal.change_type === 'rename' ? 'الاسم الجديد' : 'اسم الوحدة (اختياري)'))" />
                        <input v-if="proposal.change_type === 'create_department'" v-model="proposal.proposed_code" required maxlength="50" pattern="[A-Za-z0-9_-]+" dir="ltr" aria-label="رمز القسم" placeholder="SECRETARIAT" />
                        <select v-if="['reparent', 'convert_to_unit'].includes(proposal.change_type)" v-model="proposal.parent_id" class="field w-full" :required="proposal.change_type === 'convert_to_unit' || node.node_type === 'unit'">
                            <option value="">{{ node.node_type === 'unit' || proposal.change_type === 'convert_to_unit' ? 'اختر الجهة التي يتبعها' : 'قسم رئيسي (بلا تبعية)' }}</option>
                            <option v-for="item in parentChoices" :key="item.id" :value="item.id">{{ item.type_label }}: {{ item.name }}</option>
                        </select>
                        <template v-if="['create_unit', 'create_department'].includes(proposal.change_type)">
                            <textarea v-model="proposal.description" rows="2" maxlength="2000" class="field w-full" placeholder="الوصف (اختياري)"></textarea>
                            <textarea v-model="proposal.responsibilities" rows="2" maxlength="4000" class="field w-full" placeholder="مسؤولياتها (اختياري)"></textarea>
                        </template>
                        <textarea v-if="proposal.change_type" v-model="proposal.rationale" rows="3" minlength="10" maxlength="4000" class="field w-full" required placeholder="المبرر (10 أحرف على الأقل)"></textarea>
                        <p v-for="(message, key) in proposal.errors" :key="key" class="text-sm text-red-700" role="alert">{{ message }}</p>
                        <button v-if="proposal.change_type" type="submit" class="btn btn-primary btn-sm" :disabled="proposal.processing">رفع المقترح إلى المدير التنفيذي</button>
                    </form>
                </aside>
            </div>

            <!-- Proposals -->
            <div v-if="tab === 'changes'" class="space-y-4">
                <section class="card">
                    <h2 class="card-title">بانتظار القرار</h2>
                    <ul v-if="pending.length" class="mt-2 divide-y divide-slate-100">
                        <li v-for="change in pending" :key="change.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                            <Link :href="`/admin/organization/changes/${change.id}`" class="font-semibold text-blue-700 hover:underline">{{ describe(change) }}</Link>
                            <StatusBadge :label="change.status_label" :tone="statusTone[change.status]" />
                            <span class="text-xs text-slate-500">{{ change.proposer }} · {{ String(change.created_at).slice(0, 10) }} · {{ change.reference_no }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-500">لا مقترحات بانتظار القرار.</p>
                </section>
                <section class="card">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="card-title">وحدات مقترحة بانتظار التقديم</h2>
                        <button v-if="can.manage" type="button" class="btn btn-sm btn-secondary" @click="router.post('/admin/organization/suggestions', {}, { preserveScroll: true })">تحديث الاقتراحات</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">اقتراحات لا أثر لها حتى يقدّمها مخوّل ويعتمدها المدير التنفيذي.</p>
                    <ul v-if="suggested.length" class="mt-2 divide-y divide-slate-100">
                        <li v-for="change in suggested" :key="change.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                            <span class="font-semibold text-navy-800">{{ describe(change) }}</span>
                            <button type="button" class="text-blue-700 hover:underline" @click="submitSuggestion(change)">تقديم للاعتماد</button>
                            <button v-if="can.manage" type="button" class="text-slate-500 hover:underline" @click="dismiss(change)">استبعاد</button>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-500">لا اقتراحات.</p>
                </section>
                <section class="card">
                    <h2 class="card-title">سجل التعديلات</h2>
                    <ul v-if="decided.length" class="mt-2 divide-y divide-slate-100">
                        <li v-for="change in decided" :key="change.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                            <Link :href="`/admin/organization/changes/${change.id}`" class="text-navy-800 hover:underline">{{ describe(change) }}</Link>
                            <StatusBadge :label="change.status_label" :tone="statusTone[change.status]" />
                            <span class="text-xs text-slate-500">{{ change.authority === 'owner_decision' ? 'قرار المالك' : (change.decider || '') }} · {{ String(change.applied_at || change.decided_at || '').slice(0, 10) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-500">لا تعديلات مسجلة.</p>
                </section>
            </div>

            <!-- Archiving review -->
            <section v-if="tab === 'review'" class="card">
                <h2 class="card-title">مراجعة الأقسام قبل الأرشفة</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">قراءة فقط: المراجع من قاعدة البيانات نفسها، والعوائق عمل حي يمنع الأرشفة. القسم المعتمد بوثيقة أو قرار لا يُقترح أرشفته، والأرشفة لا تحذف السجل.</p>
                <div class="aims-table-scroll mt-3">
                    <table class="data-table w-full text-sm">
                        <thead><tr><th scope="col">القسم</th><th scope="col">التصنيف</th><th scope="col">السند أو الملاحظة</th><th scope="col">المراجع</th><th scope="col">العوائق</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                        <tbody>
                            <tr v-for="row in inventory" :key="row.id" :class="row.archived ? 'opacity-60' : ''">
                                <td><span class="font-semibold">{{ row.name }}</span> <span class="text-xs text-slate-500" dir="ltr">{{ row.code }}</span><span v-if="row.parent" class="block text-xs text-slate-500">تحت {{ row.parent }}</span></td>
                                <td><StatusBadge :label="categoryLabels[row.category][0]" :tone="categoryLabels[row.category][1]" /></td>
                                <td class="text-xs">{{ row.evidence || row.retired_reason || '—' }}</td>
                                <td class="text-xs">{{ references(row.references) || 'لا مراجع' }}</td>
                                <td class="text-xs">{{ row.blockers.join('، ') || '—' }}</td>
                                <td><button v-if="['retire_candidate', 'needs_review', 'unit'].includes(row.category) && !row.blockers.length" type="button" class="text-sm text-blue-700 hover:underline" @click="proposeRetire(row)">اقتراح الأرشفة</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Delegation -->
            <div v-if="tab === 'delegation'" class="space-y-4">
                <section class="card">
                    <h2 class="card-title">الصلاحيات القابلة للتفويض</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">لا يُفوَّض إلا ما يفعّله المدير التنفيذي هنا، ولمدة لا تتجاوز الحد. «للمدير التنفيذي فقط» يعني أن رؤساء الأقسام لا يفوّضونها.</p>
                    <div class="aims-table-scroll mt-3">
                        <table class="data-table w-full text-sm">
                            <thead><tr><th scope="col">الصلاحية</th><th scope="col">ملاحظة</th><th scope="col">أقصى مدة (يوم)</th><th scope="col">للمدير التنفيذي فقط</th><th scope="col">مفعّلة</th><th v-if="can.executive" scope="col"><span class="sr-only">حفظ</span></th></tr></thead>
                            <tbody>
                                <tr v-for="row in policyRows" :key="row.id">
                                    <td>{{ row.name }} <span class="block text-xs text-slate-500" dir="ltr">{{ row.permission_code }}</span></td>
                                    <td class="text-xs">{{ row.note }}</td>
                                    <td><input v-model.number="row.max_days" type="number" min="1" max="365" class="field w-20" :disabled="!can.executive" /></td>
                                    <td><input v-model="row.requires_executive" type="checkbox" :disabled="!can.executive" :aria-label="`للمدير التنفيذي فقط: ${row.name}`" /></td>
                                    <td><input v-model="row.is_active" type="checkbox" :disabled="!can.executive" :aria-label="`مفعّلة: ${row.name}`" /></td>
                                    <td v-if="can.executive"><button type="button" class="btn btn-sm btn-secondary" @click="savePolicy(row)">حفظ</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="!can.executive" class="mt-2 text-xs text-slate-500">تعديل السياسة للمدير التنفيذي.</p>
                </section>
                <DelegationsPanel :delegations="delegations" :options="delegationOptions" />
            </div>
        </div>
    </AppLayout>
</template>
