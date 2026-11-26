<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import AppDialog from '../../../Components/AppDialog.vue';

// «سجل النظام»: the server applies the reader's scope to every list, detail and export; filters only narrow it.
const props = defineProps({ rows: Array, cursor: Object, filters: Object, stats: Object, chain: Object, scope: Object, options: Object });
const form = reactive({ ...props.filters });
const advanced = ref(['severity', 'sensitivity', 'origin', 'department', 'correlation'].some(k => props.filters[k]));
const outcomeTone = { success: 'success', failure: 'warning', denied: 'danger' };
const severityTone = { info: 'muted', notice: 'info', warning: 'warning', critical: 'danger' };
const labels = { q: 'بحث', module: 'الوحدة', action: 'الإجراء', outcome: 'النتيجة', severity: 'الأهمية', sensitivity: 'الحساسية', origin: 'المصدر', department: 'القسم', correlation: 'رقم الطلب المرتبط', date_from: 'من', date_to: 'إلى' };
const valueLabel = (key, value) => ({ module: props.options.modules, outcome: props.options.outcomes, severity: props.options.severities, sensitivity: props.options.sensitivities, origin: props.options.origins }[key]?.[value]
    ?? (key === 'action' ? props.options.actions.find(a => a.value === value)?.label : key === 'department' ? props.options.departments.find(d => String(d.id) === String(value))?.name : value) ?? value);
const active = computed(() => Object.entries(props.filters).filter(([, v]) => v !== null && v !== ''));
const clean = values => Object.fromEntries(Object.entries(values).filter(([, v]) => v !== null && v !== ''));
function apply() { router.get('/admin/audit-logs', clean(form), { preserveState: true, preserveScroll: true }); }
function clearAll() { Object.keys(form).forEach(k => { form[k] = null; }); apply(); }
function drop(key) { form[key] = null; apply(); }
const exportUrl = computed(() => `/admin/audit-logs/export?${new URLSearchParams(clean(props.filters))}`);

const drawer = ref(null);
const loading = ref(false);
const error = ref('');
async function open(row) {
    drawer.value = { ...row };
    loading.value = true;
    error.value = '';
    try { drawer.value = (await axios.get(`/admin/audit-logs/${row.event_id}`)).data; }
    catch (e) { error.value = e.response?.status === 404 ? 'الحدث غير موجود ضمن نطاقك.' : e.response?.status === 403 ? 'لا تملك صلاحية عرض هذا الحدث.' : 'تعذر تحميل التفاصيل؛ أعد المحاولة.'; }
    finally { loading.value = false; }
}
function related(item) { open({ event_id: item.event_id, action_label: item.label, at: item.at }); }
function byCorrelation(id) { drawer.value = null; form.correlation = id; advanced.value = true; apply(); }
</script>

<template>
    <AppLayout title="سجل النظام">
        <div class="mx-auto max-w-7xl space-y-4">
            <PageHeader eyebrow="الإعدادات والصلاحيات" title="سجل النظام"
                :description="scope.level === 'all' ? 'سجل موحد للإجراءات المهمة في كل وحدات النظام: من نفّذ، وعلى أي سجل، والنتيجة. لا يُعدّل ولا يُحذف.' : 'إجراءات موظفي قسمك ووحداته، دون الأحداث المقيدة.'">
                <a :href="exportUrl" class="btn btn-secondary">تصدير CSV</a>
            </PageHeader>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="ملخص">
                <div class="card"><p class="text-sm text-slate-600">أحداث آخر 24 ساعة</p><p class="mt-1 text-3xl font-bold tabular-nums text-navy-900">{{ stats.day.toLocaleString('ar-u-nu-latn') }}</p></div>
                <button type="button" class="card text-start hover:border-blue-300" @click="form.outcome = 'denied'; apply()"><p class="text-sm text-slate-600">رفض أو فشل خلال 7 أيام</p><p class="mt-1 text-3xl font-bold tabular-nums" :class="stats.refused ? 'text-red-700' : 'text-navy-900'">{{ stats.refused }}</p></button>
                <button type="button" class="card text-start hover:border-blue-300" @click="form.severity = 'critical'; advanced = true; apply()"><p class="text-sm text-slate-600">أحداث حرجة خلال 7 أيام</p><p class="mt-1 text-3xl font-bold tabular-nums" :class="stats.critical ? 'text-red-700' : 'text-navy-900'">{{ stats.critical }}</p></button>
                <div v-if="chain" class="card"><p class="text-sm text-slate-600">سلامة السجل</p>
                    <p class="mt-1 text-sm font-semibold" :class="chain.verified && !chain.verified.ok ? 'text-red-700' : 'text-navy-900'">{{ chain.verified ? (chain.verified.ok ? 'السلسلة سليمة' : 'السلسلة منقطعة — راجع مدير النظام') : 'لم يُجرَ تحقق بعد' }}</p>
                    <p class="text-xs text-slate-500">مختوم: {{ chain.sealed }} · بانتظار الختم: {{ chain.unsealed }}<span v-if="chain.verified"> · آخر تحقق {{ chain.verified.at }}</span></p></div>
            </section>

            <form class="card space-y-3" role="search" aria-label="تصفية السجل" @submit.prevent="apply">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label class="text-sm lg:col-span-2">بحث (المستخدم، الإجراء، المرجع، معرف الحدث)<input v-model="form.q" type="search" class="field mt-1" maxlength="100"></label>
                    <label class="text-sm">الوحدة<select v-model="form.module" class="field mt-1"><option :value="null">الكل</option><option v-for="(l, k) in options.modules" :key="k" :value="k">{{ l }}</option></select></label>
                    <label class="text-sm">من تاريخ<input v-model="form.date_from" type="date" class="field mt-1"></label>
                    <label class="text-sm">إلى تاريخ<input v-model="form.date_to" type="date" class="field mt-1"></label>
                    <label class="text-sm lg:col-span-2">الإجراء<select v-model="form.action" class="field mt-1"><option :value="null">الكل</option><option v-for="a in options.actions" :key="a.value" :value="a.value">{{ a.label }}</option></select></label>
                    <label class="text-sm">النتيجة<select v-model="form.outcome" class="field mt-1"><option :value="null">الكل</option><option v-for="(l, k) in options.outcomes" :key="k" :value="k">{{ l }}</option></select></label>
                    <template v-if="advanced">
                        <label class="text-sm">الأهمية<select v-model="form.severity" class="field mt-1"><option :value="null">الكل</option><option v-for="(l, k) in options.severities" :key="k" :value="k">{{ l }}</option></select></label>
                        <label class="text-sm">الحساسية<select v-model="form.sensitivity" class="field mt-1"><option :value="null">الكل</option><option v-for="(l, k) in options.sensitivities" :key="k" :value="k">{{ l }}</option></select></label>
                        <label class="text-sm">المصدر<select v-model="form.origin" class="field mt-1"><option :value="null">الكل</option><option v-for="(l, k) in options.origins" :key="k" :value="k">{{ l }}</option></select></label>
                        <label v-if="options.departments.length" class="text-sm">القسم<select v-model="form.department" class="field mt-1"><option :value="null">الكل</option><option v-for="d in options.departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option></select></label>
                        <label class="text-sm">رقم الطلب المرتبط<input v-model="form.correlation" class="field mt-1" dir="ltr" maxlength="64"></label>
                    </template>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="btn btn-primary">تطبيق</button>
                    <button type="button" class="btn btn-secondary" :aria-expanded="advanced" @click="advanced = !advanced">{{ advanced ? 'إخفاء الفلاتر الإضافية' : 'فلاتر إضافية' }}</button>
                    <button v-if="active.length" type="button" class="btn btn-secondary" @click="clearAll">مسح الكل</button>
                </div>
                <ul v-if="active.length" class="flex flex-wrap gap-2" aria-label="الفلاتر المطبقة">
                    <li v-for="[k, v] in active" :key="k"><button type="button" class="rounded-full bg-navy-50 px-3 py-1 text-xs font-semibold text-navy-800 ring-1 ring-navy-100 hover:bg-navy-100" :aria-label="`إزالة فلتر ${labels[k]}`" @click="drop(k)">{{ labels[k] }}: {{ valueLabel(k, v) }} ×</button></li>
                </ul>
            </form>

            <section class="card space-y-3" aria-label="الأحداث">
                <div v-if="rows.length" class="aims-table-scroll">
                    <table class="data-table aims-responsive-table">
                        <thead><tr><th scope="col">التاريخ والوقت</th><th scope="col">المستخدم / المصدر</th><th scope="col">القسم</th><th scope="col">الإجراء</th><th scope="col">السجل المتأثر</th><th scope="col">النتيجة</th><th scope="col"><span class="sr-only">التفاصيل</span></th></tr></thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.event_id">
                                <td data-label="التاريخ والوقت" class="whitespace-nowrap tabular-nums" dir="ltr">{{ r.at }}</td>
                                <td data-label="المستخدم / المصدر">{{ r.actor }}<span class="block text-xs text-slate-500">{{ r.origin_label }}</span></td>
                                <td data-label="القسم">{{ r.department || '—' }}</td>
                                <td data-label="الإجراء">{{ r.action_label }}<span class="block text-xs text-slate-500">{{ r.module_label }}</span></td>
                                <td data-label="السجل المتأثر">{{ r.subject_ref || (r.subject_label ? `${r.subject_label} #${r.subject_id ?? ''}` : '—') }}</td>
                                <td data-label="النتيجة"><span class="flex flex-wrap gap-1"><StatusBadge :label="r.outcome_label" :tone="outcomeTone[r.outcome]" /><StatusBadge v-if="['warning', 'critical'].includes(r.severity)" :label="r.severity_label" :tone="severityTone[r.severity]" /></span></td>
                                <td data-label="التفاصيل"><button type="button" class="btn btn-sm btn-secondary" @click="open(r)">التفاصيل</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else :title="active.length ? 'لا توجد نتائج مطابقة للفلاتر' : 'لا توجد أحداث ضمن نطاقك'">
                    <button v-if="active.length" type="button" class="btn btn-sm btn-secondary" @click="clearAll">مسح الفلاتر</button>
                </EmptyState>
                <nav v-if="cursor.next || cursor.prev" class="flex items-center justify-between gap-2" aria-label="صفحات السجل">
                    <Link v-if="cursor.prev" :href="cursor.prev" class="btn btn-sm btn-secondary" preserve-scroll>الأحدث</Link><span v-else></span>
                    <Link v-if="cursor.next" :href="cursor.next" class="btn btn-sm btn-secondary" preserve-scroll>الأقدم</Link>
                </nav>
            </section>
        </div>

        <AppDialog :open="!!drawer" drawer :title="drawer?.action_label || 'تفاصيل الحدث'" @close="drawer = null">
            <div v-if="drawer" class="space-y-4 text-sm">
                <p v-if="error" class="alert-error" role="alert">{{ error }}</p>
                <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2">
                    <dt class="text-slate-500">الوقت</dt><dd dir="ltr" class="text-start tabular-nums">{{ drawer.at }}</dd>
                    <dt class="text-slate-500">المنفّذ</dt><dd>{{ drawer.actor }} <span class="text-slate-500">({{ drawer.origin_label }})</span></dd>
                    <dt class="text-slate-500">القسم</dt><dd>{{ drawer.department || '—' }}</dd>
                    <dt class="text-slate-500">الوحدة</dt><dd>{{ drawer.module_label }}</dd>
                    <dt class="text-slate-500">الإجراء</dt><dd>{{ drawer.action_label }} <code class="text-xs text-slate-500" dir="ltr">{{ drawer.action }}</code></dd>
                    <dt class="text-slate-500">السجل المتأثر</dt><dd>{{ drawer.subject_ref || drawer.subject_label || '—' }} <span v-if="drawer.subject_id" class="text-slate-500">#{{ drawer.subject_id }}</span>
                        <Link v-if="drawer.link" :href="drawer.link" class="ms-2 font-semibold text-blue-700 hover:underline">فتح السجل</Link></dd>
                    <dt class="text-slate-500">النتيجة</dt><dd><StatusBadge :label="drawer.outcome_label" :tone="outcomeTone[drawer.outcome]" /> <span v-if="drawer.reason_code" class="text-xs text-slate-500" dir="ltr">{{ drawer.reason_code }}</span></dd>
                    <dt class="text-slate-500">الأهمية / الحساسية</dt><dd>{{ drawer.severity_label }} · {{ drawer.sensitivity_label }}</dd>
                </dl>
                <p v-if="loading" class="text-slate-500" role="status">جارٍ تحميل التفاصيل…</p>
                <template v-else-if="!error && drawer.request">
                    <section>
                        <h3 class="font-semibold text-navy-800">التفاصيل المسجلة</h3>
                        <p v-if="drawer.details_note" class="mt-1 rounded-lg bg-slate-50 p-3 text-slate-600">{{ drawer.details_note }}</p>
                        <pre v-else-if="drawer.details && Object.keys(drawer.details).length" class="mt-1 max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs leading-5" dir="ltr">{{ JSON.stringify(drawer.details, null, 2) }}</pre>
                        <p v-else class="mt-1 text-slate-500">لا توجد تفاصيل إضافية.</p>
                    </section>
                    <section>
                        <h3 class="font-semibold text-navy-800">الطلب</h3>
                        <dl class="mt-1 grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-xs">
                            <dt class="text-slate-500">المسار</dt><dd dir="ltr" class="text-start">{{ drawer.request.method }} {{ drawer.request.route || '—' }}</dd>
                            <dt class="text-slate-500">العنوان</dt><dd dir="ltr" class="text-start">{{ drawer.request.ip || '—' }}</dd>
                            <dt class="text-slate-500">رقم الطلب المرتبط</dt><dd dir="ltr" class="text-start">{{ drawer.request.correlation_id || '—' }}
                                <button v-if="drawer.request.correlation_id" type="button" class="ms-2 text-blue-700 hover:underline" @click="byCorrelation(drawer.request.correlation_id)">كل أحداثه</button></dd>
                            <dt class="text-slate-500">الختم</dt><dd>{{ drawer.integrity.sealed ? `مختوم (التسلسل ${drawer.integrity.seq})` : 'بانتظار الختم' }}</dd>
                        </dl>
                    </section>
                    <section v-if="drawer.related.length">
                        <h3 class="font-semibold text-navy-800">أحداث الطلب نفسه</h3>
                        <ul class="mt-1 space-y-1"><li v-for="x in drawer.related" :key="x.event_id"><button type="button" class="text-blue-700 hover:underline" @click="related(x)">{{ x.label }}</button> <span class="text-xs text-slate-500" dir="ltr">{{ x.at }}</span></li></ul>
                    </section>
                </template>
            </div>
        </AppDialog>
    </AppLayout>
</template>
