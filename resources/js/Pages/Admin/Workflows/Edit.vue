<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    definition: { type: Object, required: true },
    version: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    steps: { type: Array, default: () => [] },
    transitions: { type: Array, default: () => [] },
    problems: { type: Array, default: () => [] },
    // Points worth a look that do not stop publishing (e.g. every forward action depends on an optional field).
    warnings: { type: Array, default: () => [] },
    stepTypes: { type: Object, default: () => ({}) },
    assignmentTypes: { type: Object, default: () => ({}) },
    departmentScopes: { type: Object, default: () => ({}) },
    conditionOperators: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    delegationScopes: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success || '');
const editable = computed(() => props.version.status === 'draft');
const statusLabels = { draft: 'مسودة', published: 'منشور', retired: 'مؤرشف' };
const base = computed(() => `/admin/workflows/versions/${props.version.id}`);
const stepName = id => props.steps.find(step => step.id === id)?.name || '—';
const roleName = code => props.roles.find(role => role.code === code)?.name || code;
const suggestedActions = ['submit', 'approve', 'forward', 'assign', 'return', 'reject', 'complete', 'finish'];

function assignmentSummary(step) {
    if (step.step_type === 'end') return step.assignment_config?.outcome === 'rejected' ? 'تنتهي بالرفض' : 'تنتهي بالإنجاز';
    const config = step.assignment_config || {};
    const label = props.assignmentTypes[step.assignment_type] || 'غير محدد';
    if (['role', 'pick'].includes(step.assignment_type)) return `${label}: ${roleName(step.assigned_role_code)}`;
    if (step.assignment_type === 'department_head_of') return `${label}: ${props.departments.find(d => d.id === config.department_id)?.name || '؟'}`;
    if (step.assignment_type === 'user') return `${label}: ${props.users.find(u => u.id === config.user_id)?.name || '؟'}`;
    if (step.assignment_type === 'actor_of_step') return `${label}: ${props.steps.find(s => s.code === config.step_code)?.name || '؟'}`;
    return config.fallback_role_code ? `${label} (البديل: ${roleName(config.fallback_role_code)})` : label;
}
const delegationNote = step => (step.assignment_config?.delegation_scope ? `قابلة للتفويض: ${props.delegationScopes[step.assignment_config.delegation_scope] || step.assignment_config.delegation_scope}` : '');

// ---------- المراحل ----------
const stepEditingId = ref(null);
const showStepForm = ref(false);
const stepBlank = () => ({
    code: '', name: '', sort_order: (Math.max(0, ...props.steps.filter(s => s.step_type !== 'end').map(s => s.sort_order)) + 10),
    step_type: 'task', assignment_type: 'department_head', assigned_role_code: '', sla_hours: null,
    assignment_config: { department_id: null, user_id: null, department_scope: 'origin', escalate_to_role_code: '', fallback_role_code: '', fallback_department_id: null, requester_may_act: false, inform_role_codes: [], step_code: '', outcome: 'completed', delegation_scope: '' },
});
const stepForm = useForm(stepBlank());
function openStep(step = null) {
    stepEditingId.value = step?.id || null;
    Object.assign(stepForm, step ? {
        code: step.code, name: step.name, sort_order: step.sort_order, step_type: step.step_type,
        assignment_type: step.assignment_type || 'department_head', assigned_role_code: step.assigned_role_code || '',
        sla_hours: step.sla_hours, assignment_config: { ...stepBlank().assignment_config, ...(step.assignment_config || {}) },
    } : stepBlank());
    stepForm.clearErrors();
    showStepForm.value = true;
}
function saveStep() {
    stepForm.transform(data => {
        const payload = { ...data, assigned_role_code: data.assigned_role_code || null, sla_hours: data.sla_hours || null };
        const config = { ...data.assignment_config };
        if (data.step_type === 'end') {
            payload.assignment_type = null;
            payload.assignment_config = { outcome: config.outcome };
        } else {
            delete config.outcome;
            payload.assignment_config = config;
        }
        if (stepEditingId.value) delete payload.code;
        return payload;
    });
    const options = { preserveScroll: true, onSuccess: () => { showStepForm.value = false; } };
    if (stepEditingId.value) stepForm.put(`${base.value}/steps/${stepEditingId.value}`, options);
    else stepForm.post(`${base.value}/steps`, options);
}
function deleteStep(step) {
    if (!confirm(`حذف المرحلة «${step.name}» مع كل الإجراءات المرتبطة بها من المسودة؟`)) return;
    router.delete(`${base.value}/steps/${step.id}`, { preserveScroll: true });
}

// ---------- الإجراءات ----------
const transitionEditingId = ref(null);
const showTransitionForm = ref(false);
const transitionBlank = () => ({ from_step_id: null, to_step_id: null, action_code: 'approve', name: '', requires_comment: false, is_active: true, rows: [] });
const transitionForm = useForm(transitionBlank());

function conditionsToRows(conditions) {
    const rows = [];
    Object.entries(conditions || {}).forEach(([field, expectation]) => {
        if (Array.isArray(expectation)) rows.push({ field, operator: 'in', value: expectation.join(', ') });
        else if (expectation !== null && typeof expectation === 'object') {
            Object.entries(expectation).forEach(([operator, value]) => rows.push({ field, operator, value: Array.isArray(value) ? value.join(', ') : String(value) }));
        } else rows.push({ field, operator: 'eq', value: String(expectation) });
    });
    return rows;
}
function parseValue(raw) {
    const text = String(raw ?? '').trim();
    if (text === 'true') return true;
    if (text === 'false') return false;
    return text !== '' && !Number.isNaN(Number(text)) ? Number(text) : text;
}
function rowsToConditions(rows) {
    const conditions = {};
    rows.filter(row => row.field).forEach(row => {
        let value;
        if (['in', 'not_in'].includes(row.operator)) value = String(row.value).split(',').map(parseValue).filter(v => v !== '');
        else if (['filled', 'empty'].includes(row.operator)) value = true;
        else value = parseValue(row.value);
        conditions[row.field] = { ...(conditions[row.field] || {}), [row.operator]: value };
    });
    return Object.keys(conditions).length ? conditions : null;
}
function conditionSummary(conditions) {
    return conditionsToRows(conditions).map(row => `${row.field} ${props.conditionOperators[row.operator] || row.operator} ${['filled', 'empty'].includes(row.operator) ? '' : row.value}`).join(' و ');
}
function openTransition(transition = null) {
    transitionEditingId.value = transition?.id || null;
    Object.assign(transitionForm, transition ? {
        from_step_id: transition.from_step_id, to_step_id: transition.to_step_id, action_code: transition.action_code,
        name: transition.name, requires_comment: Boolean(transition.requires_comment), is_active: Boolean(transition.is_active),
        rows: conditionsToRows(transition.conditions),
    } : transitionBlank());
    transitionForm.clearErrors();
    showTransitionForm.value = true;
}
function onActionChange() {
    if (['reject', 'return'].includes(transitionForm.action_code)) transitionForm.requires_comment = true;
}
function saveTransition() {
    transitionForm.transform(({ rows, ...data }) => ({ ...data, conditions: rowsToConditions(rows) }));
    const options = { preserveScroll: true, onSuccess: () => { showTransitionForm.value = false; } };
    if (transitionEditingId.value) transitionForm.put(`${base.value}/transitions/${transitionEditingId.value}`, options);
    else transitionForm.post(`${base.value}/transitions`, options);
}
function deleteTransition(transition) {
    if (!confirm(`حذف الإجراء «${transition.name}» من المسودة؟`)) return;
    router.delete(`${base.value}/transitions/${transition.id}`, { preserveScroll: true });
}

const publishing = ref(false);
function publish() {
    if (!confirm('نشر هذا الإصدار؟ ستستخدمه الطلبات الجديدة فورًا، وتكمل الطلبات الجارية على إصدارها السابق.')) return;
    publishing.value = true;
    router.post(`${base.value}/publish`, {}, { preserveScroll: true, onFinish: () => { publishing.value = false; } });
}
const errors = computed(() => page.props.errors || {});
const input = 'field';
</script>

<template>
    <AppLayout :title="`مسار: ${definition.name}`">
        <div class="mx-auto max-w-6xl space-y-5">
            <Link href="/admin/workflows" class="inline-block text-sm font-semibold text-blue-700 hover:underline">→ جميع المسارات</Link>
            <PageHeader eyebrow="محرر مسار العمل" :title="definition.name">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <StatusBadge :status="version.status" :label="`الإصدار ${version.version_number} · ${statusLabels[version.status] || version.status}`" :tone="{ draft: 'warning', published: 'success' }[version.status] || 'muted'" />
                        <span v-if="!editable" class="text-xs text-slate-500">للقراءة فقط</span>
                        <StatusBadge v-if="definition.subject_type && definition.subject_type !== 'aims_request'" :label="`مرتبط بـ: ${definition.subject_label}`" tone="navy" />
                    </div>
                    <div v-if="versions.length > 1" class="mt-3 flex flex-wrap gap-1.5">
                        <Link v-for="other in versions" :key="other.id" :href="`/admin/workflows/versions/${other.id}`"
                            :class="['btn btn-sm', other.id === version.id ? 'btn-primary' : 'btn-secondary']">
                            إ{{ other.version_number }} · {{ statusLabels[other.status] }}
                        </Link>
                    </div>
                </template>
                <button v-if="editable" type="button" :disabled="problems.length > 0 || publishing" class="btn btn-gold" @click="publish">
                    {{ publishing ? 'جارٍ النشر...' : 'نشر الإصدار' }}
                </button>
            </PageHeader>

            <div v-if="errors.version || errors.publish" class="alert-error" role="alert">{{ errors.version || errors.publish }}</div>

            <section v-if="editable && problems.length" class="alert-warning">
                <h2 class="font-bold">قبل النشر ({{ problems.length }})</h2>
                <ul class="mt-2 list-disc space-y-1 pr-5"><li v-for="problem in problems" :key="problem">{{ problem }}</li></ul>
            </section>
            <p v-else-if="editable" class="alert-success">المسودة مكتملة وجاهزة للنشر.</p>
            <section v-if="editable && warnings.length" class="alert-info">
                <h2 class="font-bold">تنبيهات لا تمنع النشر ({{ warnings.length }})</h2>
                <ul class="mt-2 list-disc space-y-1 pr-5"><li v-for="warning in warnings" :key="warning">{{ warning }}</li></ul>
            </section>

            <!-- المراحل -->
            <section class="card">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="card-title">المراحل</h2>
                    <button v-if="editable" type="button" class="btn btn-primary" @click="openStep()">+ مرحلة</button>
                </div>
                <form v-if="showStepForm" class="mb-5 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-3" @submit.prevent="saveStep">
                    <div v-if="!stepEditingId"><label class="field-label">الرمز *</label><input v-model="stepForm.code" required pattern="[a-z][a-z0-9_]*" dir="ltr" :class="input" /><p v-if="stepForm.errors.code" class="field-error">{{ stepForm.errors.code }}</p></div>
                    <div><label class="field-label">اسم المرحلة *</label><input v-model="stepForm.name" required :class="input" /></div>
                    <div><label class="field-label">الترتيب *</label><input v-model.number="stepForm.sort_order" type="number" min="0" required :class="input" /><p v-if="stepForm.errors.sort_order" class="field-error">{{ stepForm.errors.sort_order }}</p></div>
                    <div><label class="field-label">نوع المرحلة</label><select v-model="stepForm.step_type" :class="input"><option v-for="(label, key) in stepTypes" :key="key" :value="key">{{ label }}</option></select></div>
                    <template v-if="stepForm.step_type !== 'end'">
                        <div><label class="field-label">المسؤول عنها</label><select v-model="stepForm.assignment_type" :class="input"><option v-for="(label, key) in assignmentTypes" :key="key" :value="key">{{ label }}</option></select></div>
                        <div v-if="['role', 'pick'].includes(stepForm.assignment_type)"><label class="field-label">الدور *</label><select v-model="stepForm.assigned_role_code" required :class="input"><option value="" disabled>اختر</option><option v-for="role in roles" :key="role.code" :value="role.code">{{ role.name }}</option></select></div>
                        <div v-if="['role', 'pick'].includes(stepForm.assignment_type)"><label class="field-label">نطاق الدور</label><select v-model="stepForm.assignment_config.department_scope" :class="input"><option v-for="(label, key) in departmentScopes" :key="key" :value="key">{{ label }}</option></select></div>
                        <div v-if="stepForm.assignment_type === 'department_head_of' || (['role', 'pick'].includes(stepForm.assignment_type) && stepForm.assignment_config.department_scope === 'specific')">
                            <label class="field-label">القسم *</label><select v-model="stepForm.assignment_config.department_id" required :class="input"><option :value="null" disabled>اختر</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                        </div>
                        <div v-if="stepForm.assignment_type === 'user'"><label class="field-label">المستخدم *</label><select v-model="stepForm.assignment_config.user_id" required :class="input"><option :value="null" disabled>اختر</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                        <div v-if="stepForm.assignment_type === 'actor_of_step'"><label class="field-label">منفذ المرحلة *</label><select v-model="stepForm.assignment_config.step_code" required :class="input"><option value="" disabled>اختر</option><option v-for="s in steps.filter(s => s.code !== stepForm.code && s.step_type !== 'end')" :key="s.code" :value="s.code">{{ s.name }}</option></select></div>
                        <div v-if="!['requester', 'pick'].includes(stepForm.assignment_type)"><label class="field-label">إن لم يوجد مؤهل يُحال إلى</label><select v-model="stepForm.assignment_config.fallback_role_code" :class="input"><option value="">لا أحد (يتوقف الطلب برسالة)</option><option v-for="role in roles" :key="role.code" :value="role.code">{{ role.name }}</option></select></div>
                        <div v-if="!['requester', 'pick'].includes(stepForm.assignment_type)"><label class="field-label">ثم إلى رئيس قسم</label><select v-model="stepForm.assignment_config.fallback_department_id" :class="input"><option :value="null">لا أحد</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
                        <div>
                            <label class="field-label">للعلم عند وصول الطلب (دون اعتماد)</label>
                            <select v-model="stepForm.assignment_config.inform_role_codes" multiple size="3" :class="input"><option v-for="role in roles" :key="role.code" :value="role.code">{{ role.name }}</option></select>
                        </div>
                        <label v-if="stepForm.assignment_type !== 'requester'" class="flex items-start gap-2 text-sm md:col-span-3">
                            <input v-model="stepForm.assignment_config.requester_may_act" type="checkbox" class="mt-1" />
                            <span>مرحلة تشغيلية يجوز أن ينفذها مقدم الطلب نفسه (تصنيف أو مخزن أو تنفيذ). اتركها فارغة لمراحل المراجعة والاعتماد: لا يعتمد أحد طلبه بنفسه.</span>
                        </label>
                        <div><label class="field-label">المهلة بالساعات</label><input v-model.number="stepForm.sla_hours" type="number" min="1" :class="input" placeholder="بدون مهلة" /></div>
                        <div v-if="stepForm.sla_hours"><label class="field-label">عند التأخر يُضاف</label><select v-model="stepForm.assignment_config.escalate_to_role_code" :class="input"><option value="">لا أحد (تنبيه فقط)</option><option v-for="role in roles" :key="role.code" :value="role.code">{{ role.name }}</option></select></div>
                        <div v-if="stepForm.assignment_type !== 'requester' && Object.keys(delegationScopes).length">
                            <label class="field-label">قابلة للتفويض الإداري</label>
                            <select v-model="stepForm.assignment_config.delegation_scope" :class="input"><option value="">لا (لا تُفوَّض)</option><option v-for="(label, key) in delegationScopes" :key="key" :value="key">{{ label }}</option></select>
                            <p class="mt-1 text-[11px] leading-4 text-slate-500">تنتقل المرحلة إلى المفوَّض فقط أثناء سريان تفويض يصدره المدير التنفيذي لهذا النطاق.</p>
                        </div>
                    </template>
                    <div v-else><label class="field-label">نتيجة المعاملة</label><select v-model="stepForm.assignment_config.outcome" :class="input"><option value="completed">إنجاز</option><option value="rejected">رفض</option></select></div>
                    <div v-if="Object.keys(stepForm.errors).length" class="alert-error md:col-span-3">{{ Object.values(stepForm.errors).join(' · ') }}</div>
                    <div class="flex gap-2 md:col-span-3">
                        <button type="submit" :disabled="stepForm.processing" class="btn btn-primary">حفظ المرحلة</button>
                        <button type="button" class="btn btn-secondary" @click="showStepForm = false">إلغاء</button>
                    </div>
                </form>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[640px]">
                        <thead><tr><th>#</th><th>المرحلة</th><th>النوع</th><th>المسؤول</th><th>المهلة</th><th v-if="editable" class="p-3">الإجراء</th></tr></thead>
                        <tbody>
                            <tr v-for="step in steps" :key="step.id">
                                <td class="text-slate-400">{{ step.sort_order }}</td>
                                <td><span class="font-medium">{{ step.name }}</span><span class="block text-xs text-slate-400" dir="ltr">{{ step.code }}</span></td>
                                <td>{{ stepTypes[step.step_type] || step.step_type }}</td>
                                <td>{{ assignmentSummary(step) }}<span v-if="delegationNote(step)" class="mt-0.5 block text-[11px] text-gold-700">{{ delegationNote(step) }}</span></td>
                                <td>{{ step.sla_hours ? `${step.sla_hours} ساعة` : '—' }}</td>
                                <td v-if="editable" class="whitespace-nowrap"><div class="flex gap-1.5"><button type="button" class="btn btn-sm btn-secondary" @click="openStep(step)">تعديل</button><button type="button" class="btn btn-sm btn-danger" @click="deleteStep(step)">حذف</button></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- الإجراءات -->
            <section class="card">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="card-title">الإجراءات (الانتقالات)</h2>
                        <p class="text-xs text-slate-500">كل إجراء زر يظهر للمسؤول في مرحلته وينقل الطلب إلى مرحلة أخرى، بشروط اختيارية على حقول الطلب.</p>
                    </div>
                    <button v-if="editable" type="button" class="btn btn-primary" @click="openTransition()">+ إجراء</button>
                </div>
                <form v-if="showTransitionForm" class="mb-5 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-3" @submit.prevent="saveTransition">
                    <div><label class="field-label">من مرحلة *</label><select v-model="transitionForm.from_step_id" required :class="input"><option :value="null" disabled>اختر</option><option v-for="s in steps.filter(s => s.step_type !== 'end')" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                    <div><label class="field-label">إلى مرحلة *</label><select v-model="transitionForm.to_step_id" required :class="input"><option :value="null" disabled>اختر</option><option v-for="s in steps.filter(s => s.step_type !== 'start')" :key="s.id" :value="s.id">{{ s.name }}</option></select><p v-if="transitionForm.errors.to_step_id" class="field-error">{{ transitionForm.errors.to_step_id }}</p></div>
                    <div><label class="field-label">نص الزر *</label><input v-model="transitionForm.name" required :class="input" placeholder="اعتماد وإحالة" /></div>
                    <div>
                        <label class="field-label">رمز الإجراء *</label>
                        <input v-model="transitionForm.action_code" required list="wf-actions" pattern="[a-z][a-z0-9_]*" dir="ltr" :class="input" @change="onActionChange" />
                        <datalist id="wf-actions"><option v-for="action in suggestedActions" :key="action" :value="action" /></datalist>
                    </div>
                    <label class="flex items-center gap-2 pt-5 text-sm"><input v-model="transitionForm.requires_comment" type="checkbox" /> يتطلب كتابة السبب</label>
                    <label class="flex items-center gap-2 pt-5 text-sm"><input v-model="transitionForm.is_active" type="checkbox" /> مفعل</label>
                    <fieldset class="rounded-xl border border-slate-200 bg-white p-3 md:col-span-3">
                        <legend class="px-2 text-xs font-medium">الشروط (كلها يجب أن تتحقق) — تعتمد على رموز الحقول الديناميكية</legend>
                        <div v-for="(row, index) in transitionForm.rows" :key="index" class="mb-2 grid gap-2 md:grid-cols-4">
                            <input v-model="row.field" placeholder="رمز الحقل، مثل amount" dir="ltr" pattern="[a-z][a-z0-9_]*" :class="input" />
                            <select v-model="row.operator" :class="input"><option v-for="(label, key) in conditionOperators" :key="key" :value="key">{{ label }}</option></select>
                            <input v-if="!['filled', 'empty'].includes(row.operator)" v-model="row.value" :placeholder="['in', 'not_in'].includes(row.operator) ? 'قيم مفصولة بفواصل' : 'القيمة'" :class="input" />
                            <span v-else></span>
                            <button type="button" class="text-sm text-red-700 hover:underline" @click="transitionForm.rows.splice(index, 1)">إزالة</button>
                        </div>
                        <button type="button" class="text-sm font-semibold text-emerald-700 hover:underline" @click="transitionForm.rows.push({ field: '', operator: 'eq', value: '' })">+ شرط</button>
                        <p v-if="transitionForm.errors.conditions" class="field-error">{{ transitionForm.errors.conditions }}</p>
                    </fieldset>
                    <div v-if="Object.keys(transitionForm.errors).length" class="alert-error md:col-span-3">{{ Object.values(transitionForm.errors).join(' · ') }}</div>
                    <div class="flex gap-2 md:col-span-3">
                        <button type="submit" :disabled="transitionForm.processing" class="btn btn-primary">حفظ الإجراء</button>
                        <button type="button" class="btn btn-secondary" @click="showTransitionForm = false">إلغاء</button>
                    </div>
                </form>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[640px]">
                        <thead><tr><th>من</th><th>الزر</th><th>إلى</th><th>الشروط</th><th v-if="editable" class="p-3">الإجراء</th></tr></thead>
                        <tbody>
                            <tr v-for="t in transitions" :key="t.id" :class="t.is_active ? '' : 'opacity-50'">
                                <td>{{ stepName(t.from_step_id) }}</td>
                                <td><span class="font-medium">{{ t.name }}</span><span class="block text-xs text-slate-400" dir="ltr">{{ t.action_code }}<span v-if="t.requires_comment"> · يتطلب سببًا</span></span></td>
                                <td>{{ stepName(t.to_step_id) }}</td>
                                <td class="text-xs" dir="ltr">{{ conditionSummary(t.conditions) || '—' }}</td>
                                <td v-if="editable" class="whitespace-nowrap"><div class="flex gap-1.5"><button type="button" class="btn btn-sm btn-secondary" @click="openTransition(t)">تعديل</button><button type="button" class="btn btn-sm btn-danger" @click="deleteTransition(t)">حذف</button></div></td>
                            </tr>
                            <tr v-if="!transitions.length"><td colspan="5" class="py-6 text-center text-slate-500">لا توجد إجراءات بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
