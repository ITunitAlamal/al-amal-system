<script setup>
import { formatDateTime, parseDate } from '../../Support/dates.js';
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

// «قائمة مهامي الشخصية»: private to the signed-in user; it never approves or closes official work.
const props = defineProps({
    tasks: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, default: () => ({}) },
    statusOptions: { type: Object, default: () => ({}) },
    recurrenceOptions: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});

const query = reactive({ ...props.filters });
const apply = (extra = {}) => router.get('/my/todo', Object.fromEntries(Object.entries({ ...query, ...extra }).filter(([, value]) => value !== '' && value !== null && value !== undefined)), { preserveScroll: true, preserveState: true });
const setFilter = key => { query.filter = key; apply({ page: undefined }); };

const blank = () => ({ title: '', notes: '', category_value_id: '', priority_value_id: '', status: 'open', progress: 0, due_at: '', remind_at: '', recurrence: 'none' });
const editing = ref(null);
const form = useForm(blank());
const local = value => (value ? String(value).replace(' ', 'T').slice(0, 16) : '');
function startCreate() {
    editing.value = 'new';
    form.defaults(blank());
    form.reset();
    form.clearErrors();
}
function startEdit(task) {
    editing.value = task.id;
    form.defaults({ title: task.title, notes: task.notes || '', category_value_id: task.category_value_id || '', priority_value_id: task.priority_value_id || '', status: task.status, progress: task.progress, due_at: local(task.due_at), remind_at: local(task.remind_at), recurrence: task.recurrence });
    form.reset();
    form.clearErrors();
}
function save() {
    form.transform(data => ({ ...data, category_value_id: data.category_value_id || null, priority_value_id: data.priority_value_id || null, due_at: data.due_at || null, remind_at: data.remind_at || null }));
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    editing.value === 'new' ? form.post('/my/todo', options) : form.put(`/my/todo/${editing.value}`, options);
}
const act = (task, action, data = {}) => router.post(`/my/todo/${task.id}/${action}`, data, { preserveScroll: true });
function remove(task) {
    if (confirm('حذف المهمة من قائمتك؟')) router.delete(`/my/todo/${task.id}`, { preserveScroll: true });
}

const history = ref({});
const historyLabels = { created: 'أُنشئت', updated: 'عُدلت', completed: 'أُنجزت', reopened: 'أُعيد فتحها', archived: 'أُرشفت', restored: 'أُعيدت من الأرشيف', deleted: 'حُذفت', recurred: 'أُنشئت تكرارًا' };
async function toggleHistory(task) {
    if (history.value[task.id]) { delete history.value[task.id]; return; }
    try {
        const response = await fetch(`/my/todo/${task.id}/history`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        history.value[task.id] = response.ok ? (await response.json()).data : [];
    } catch {
        history.value[task.id] = [];
    }
}

const when = value => formatDateTime(value);
const late = task => task.status !== 'done' && Boolean(task.due_at) && (parseDate(task.due_at)?.getTime() ?? Infinity) < Date.now();
const priorityTone = code => ({ urgent: 'danger', high: 'warning', normal: 'info', low: 'muted' }[code] || 'muted');
</script>

<template>
    <AppLayout title="قائمة مهامي الشخصية">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader eyebrow="مساحتي" title="قائمة مهامي الشخصية"
                description="مهام خاصة بك وحدك مع مواعيدها وتذكيراتها. لا يطلع عليها رئيسك ولا غيره، ولا تُنجز بها أي معاملة رسمية: المعاملات تُنجز من «مهامي الرسمية».">
                <button type="button" class="btn btn-primary" @click="startCreate">+ مهمة جديدة</button>
            </PageHeader>

            <dl class="grid grid-cols-3 gap-3 text-center">
                <div class="card p-3"><dt class="text-xs text-slate-500">مفتوحة</dt><dd class="font-display text-2xl font-bold text-navy-800">{{ summary.open ?? 0 }}</dd></div>
                <div class="card p-3"><dt class="text-xs text-slate-500">تستحق اليوم</dt><dd class="font-display text-2xl font-bold text-blue-700">{{ summary.today ?? 0 }}</dd></div>
                <div class="card p-3"><dt class="text-xs text-slate-500">متأخرة</dt><dd class="font-display text-2xl font-bold" :class="summary.overdue ? 'text-red-700' : 'text-slate-400'">{{ summary.overdue ?? 0 }}</dd></div>
            </dl>

            <section v-if="editing" class="card" aria-labelledby="todo-form-title">
                <h2 id="todo-form-title" class="card-title mb-4">{{ editing === 'new' ? 'مهمة جديدة' : 'تعديل المهمة' }}</h2>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                    <div class="sm:col-span-2">
                        <label for="todo-title" class="field-label">العنوان <span class="text-red-600">*</span></label>
                        <input id="todo-title" v-model="form.title" required maxlength="200" class="field" />
                        <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="todo-notes" class="field-label">ملاحظات</label>
                        <textarea id="todo-notes" v-model="form.notes" rows="2" maxlength="5000" class="field" />
                    </div>
                    <div><label for="todo-category" class="field-label">التصنيف</label><select id="todo-category" v-model="form.category_value_id" class="field"><option value="">بلا تصنيف</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                    <div><label for="todo-priority" class="field-label">الأولوية</label><select id="todo-priority" v-model="form.priority_value_id" class="field"><option value="">بلا أولوية</option><option v-for="item in priorities" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                    <div><label for="todo-due" class="field-label">موعد الاستحقاق</label><input id="todo-due" v-model="form.due_at" type="datetime-local" class="field" /><p v-if="form.errors.due_at" class="field-error">{{ form.errors.due_at }}</p></div>
                    <div><label for="todo-remind" class="field-label">تذكير (يصلك وحدك)</label><input id="todo-remind" v-model="form.remind_at" type="datetime-local" class="field" /></div>
                    <div><label for="todo-recurrence" class="field-label">التكرار</label><select id="todo-recurrence" v-model="form.recurrence" class="field"><option v-for="(label, key) in recurrenceOptions" :key="key" :value="key">{{ label }}</option></select><p v-if="form.errors.recurrence" class="field-error">{{ form.errors.recurrence }}</p></div>
                    <div><label for="todo-status" class="field-label">الحالة</label><select id="todo-status" v-model="form.status" class="field"><option v-for="(label, key) in statusOptions" :key="key" :value="key">{{ label }}</option></select></div>
                    <div class="sm:col-span-2"><label for="todo-progress" class="field-label">نسبة التقدم: {{ form.progress }}٪</label><input id="todo-progress" v-model.number="form.progress" type="range" min="0" max="100" step="10" class="w-full" /></div>
                    <div class="flex gap-2 sm:col-span-2">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button>
                        <button type="button" class="btn btn-secondary" @click="editing = null">إلغاء</button>
                    </div>
                </form>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap gap-1" role="group" aria-label="تصفية المهام">
                    <button v-for="(label, key) in filterOptions" :key="key" type="button" class="btn btn-sm" :class="query.filter === key ? 'btn-primary' : 'btn-secondary'" :aria-pressed="query.filter === key" @click="setFilter(key)">{{ label }}</button>
                </div>
                <form class="flex flex-wrap gap-2" @submit.prevent="apply({ page: undefined })">
                    <label for="todo-search" class="sr-only">بحث</label>
                    <input id="todo-search" v-model="query.q" class="field w-44" placeholder="بحث في العنوان والملاحظات" />
                    <label for="todo-sort" class="sr-only">الترتيب</label>
                    <select id="todo-sort" v-model="query.sort" class="field w-36" @change="apply()"><option value="due">حسب الموعد</option><option value="priority">حسب الأولوية</option><option value="created">الأحدث إضافة</option></select>
                    <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
                </form>
            </div>

            <section class="card p-0">
                <EmptyState v-if="!tasks.data.length" class="m-4" title="لا توجد مهام في هذا التصنيف" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="task in tasks.data" :key="task.id" class="px-4 py-3">
                        <div class="flex flex-wrap items-start gap-3">
                            <input :id="`task-${task.id}`" type="checkbox" class="mt-1 h-4 w-4" :checked="task.status === 'done'" :disabled="!!task.archived_at"
                                :aria-label="task.status === 'done' ? `إعادة فتح: ${task.title}` : `إنجاز: ${task.title}`" @change="act(task, task.status === 'done' ? 'reopen' : 'complete')" />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold" :class="task.status === 'done' ? 'text-slate-400 line-through' : 'text-navy-800'">{{ task.title }}</p>
                                <p v-if="task.notes" class="mt-0.5 whitespace-pre-wrap text-sm text-slate-600">{{ task.notes }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                    <StatusBadge :label="statusOptions[task.status]" :tone="task.status === 'done' ? 'success' : 'info'" />
                                    <StatusBadge v-if="task.priority" :label="task.priority" :tone="priorityTone(task.priority_code)" />
                                    <span v-if="task.category" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{{ task.category }}</span>
                                    <span v-if="task.due_at" :class="late(task) ? 'font-semibold text-red-700' : 'text-slate-500'">{{ late(task) ? 'متأخرة · ' : '' }}الموعد {{ when(task.due_at) }}</span>
                                    <span v-if="task.remind_at" class="text-slate-500">· تذكير {{ when(task.remind_at) }}</span>
                                    <span v-if="task.recurrence !== 'none'" class="text-slate-500">· {{ recurrenceOptions[task.recurrence] }}</span>
                                    <span v-if="task.status !== 'done' && task.progress" class="text-slate-500">· {{ task.progress }}٪</span>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <button v-if="!task.archived_at" type="button" class="btn btn-secondary btn-sm" @click="startEdit(task)">تعديل</button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="act(task, 'archive', { archived: !task.archived_at })">{{ task.archived_at ? 'استعادة' : 'أرشفة' }}</button>
                                <button type="button" class="btn btn-secondary btn-sm" :aria-expanded="!!history[task.id]" @click="toggleHistory(task)">السجل</button>
                                <button type="button" class="btn btn-danger btn-sm" @click="remove(task)">حذف</button>
                            </div>
                        </div>
                        <ol v-if="history[task.id]" class="mt-2 space-y-1 border-r-2 border-slate-200 pr-3 text-xs text-slate-600">
                            <li v-for="(event, index) in history[task.id]" :key="index">{{ historyLabels[event.action] || event.action }} · {{ when(event.created_at) }}</li>
                            <li v-if="!history[task.id].length">لا يوجد سجل.</li>
                        </ol>
                    </li>
                </ul>
            </section>

            <nav v-if="tasks.last_page > 1" aria-label="صفحات المهام" class="flex items-center justify-between gap-3">
                <button type="button" class="btn btn-secondary btn-sm" :disabled="tasks.current_page <= 1" @click="apply({ page: tasks.current_page - 1 })">السابق</button>
                <span class="text-sm text-slate-600">صفحة {{ tasks.current_page }} من {{ tasks.last_page }} — {{ tasks.total }} مهمة</span>
                <button type="button" class="btn btn-secondary btn-sm" :disabled="tasks.current_page >= tasks.last_page" @click="apply({ page: tasks.current_page + 1 })">التالي</button>
            </nav>
        </div>
    </AppLayout>
</template>
