<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Deferred, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import KpiCards from '../../Components/KpiCards.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { kpiQuery, kpiRequest } from '../../Support/kpiHttp';
import { kpiDateTime, kpiNumber, kpiValue } from '../../Support/kpiFormat.js';

const props = defineProps({
    filters: { type: Object, required: true },
    scope: { type: Object, required: true },
    cards: { type: Array, default: undefined },
    comparison: { type: Object, default: undefined },
    registered: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const form = reactive({
    from: props.filters.from, to: props.filters.to, department_id: props.filters.department_id ?? '',
    workflow_code: props.filters.workflow_code ?? '', currency: props.filters.currency || props.options.base_currency || '',
});
const loading = ref(false);

const today = () => new Date().toLocaleDateString('en-CA');
const iso = date => date.toLocaleDateString('en-CA');
const presets = [
    { label: 'الشهر الحالي', range: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth(), 1)), today()]; } },
    { label: 'الشهر السابق', range: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))]; } },
    { label: 'الربع الحالي', range: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), Math.floor(d.getMonth() / 3) * 3, 1)), today()]; } },
    { label: 'السنة حتى اليوم', range: () => [iso(new Date(new Date().getFullYear(), 0, 1)), today()] },
];

function query(extra = {}) {
    return Object.fromEntries(Object.entries({ ...form, ...extra }).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}
function apply(extra = {}) {
    closePanel();
    router.get(props.urls.index, query(extra), { preserveScroll: true, preserveState: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) });
}
function usePreset(preset) {
    [form.from, form.to] = preset.range();
    apply();
}
function refresh() {
    apply({ refresh: 1 });
}

const groups = computed(() => {
    const result = [];
    for (const card of props.cards ?? []) {
        let group = result.find(item => item.domain === card.domain);
        if (!group) result.push(group = { domain: card.domain, label: card.domain_label, cards: [] });
        group.cards.push(card);
    }
    return result;
});

// Panel: records, department split, trend or saved readings of one card.
const panel = ref(null);
const panelBusy = ref(false);
const panelError = ref('');
const panelHeading = ref(null);
const titles = { details: 'السجلات المحتسبة', breakdown: 'التوزيع حسب القسم', trend: 'الاتجاه الشهري', history: 'القراءات المحفوظة' };

async function open(kind, card, extra = {}) {
    panel.value = { kind, card, data: null, ...extra };
    panelError.value = '';
    panelBusy.value = true;
    await nextTick();
    panelHeading.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    panelHeading.value?.focus();
    try {
        const url = kind === 'details' ? card.urls.details : card.urls[kind];
        const params = query(kind === 'details' ? { component: extra.component, page: extra.page ?? 1 } : {});
        panel.value = { ...panel.value, data: await kpiRequest(`${url}?${kpiQuery(params)}`) };
    } catch (failure) {
        panelError.value = failure.message;
    } finally {
        panelBusy.value = false;
    }
}
function closePanel() {
    panel.value = null;
}
const trendMax = computed(() => Math.max(1, ...((panel.value?.data?.points ?? []).map(point => Math.abs(point.value ?? 0)))));

// Saved readings.
const reason = ref('');
const saving = ref(false);
const saveMessage = ref('');
const saveError = ref('');
async function saveReadings() {
    saving.value = true;
    saveMessage.value = saveError.value = '';
    try {
        const result = await kpiRequest(`${props.urls.snapshot}?${kpiQuery(query())}`, { reason: reason.value });
        saveMessage.value = `حُفظت ${kpiNumber(result.count)} قراءة جديدة بنطاقك وفلاترك الحالية. القراءات السابقة لم تتغير.`;
        reason.value = '';
    } catch (failure) {
        saveError.value = failure.message;
    } finally {
        saving.value = false;
    }
}

// Opening /kpi#kpi-CODE from the home page scrolls to that card once the figures arrive.
watch(() => props.cards, async cards => {
    if (!cards || !window.location.hash.startsWith('#kpi-')) return;
    await nextTick();
    document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ block: 'start' });
}, { immediate: true });
</script>

<template>
    <AppLayout title="مؤشرات الأداء">
        <div class="space-y-6" :aria-busy="loading">
            <PageHeader title="مؤشرات الأداء" eyebrow="لوحة القياس"
                :description="`أرقام محسوبة من السجلات الفعلية ضمن صلاحياتك الحالية: ${scope.label}. لا أهداف معتمدة ما لم يحددها صاحب الصلاحية.`">
                <button type="button" class="btn btn-secondary" :disabled="loading" @click="refresh">تحديث القيم الآن</button>
                <Link v-if="canManage" :href="urls.manage" class="btn btn-primary">إدارة المؤشرات</Link>
            </PageHeader>

            <form class="card space-y-4 p-4 sm:p-5" aria-label="فلاتر المؤشرات" @submit.prevent="apply()">
                <div class="flex flex-wrap gap-2">
                    <button v-for="preset in presets" :key="preset.label" type="button" class="btn btn-secondary btn-sm" :disabled="loading" @click="usePreset(preset)">{{ preset.label }}</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label class="field-label">من<input v-model="form.from" type="date" required :max="form.to" class="field mt-1 w-full"></label>
                    <label class="field-label">إلى<input v-model="form.to" type="date" required :min="form.from" :max="today()" class="field mt-1 w-full"></label>
                    <label class="field-label">القسم
                        <select v-model="form.department_id" class="field mt-1 w-full">
                            <option value="">كل نطاقي</option>
                            <option v-for="department in options.departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                        </select>
                    </label>
                    <label class="field-label">نوع المعاملة
                        <select v-model="form.workflow_code" class="field mt-1 w-full">
                            <option value="">كل الأنواع</option>
                            <option v-for="workflow in options.workflows" :key="workflow.code" :value="workflow.code">{{ workflow.name }}</option>
                        </select>
                    </label>
                    <label class="field-label">عملة المؤشرات المالية
                        <select v-model="form.currency" class="field mt-1 w-full">
                            <option v-for="currency in options.currencies" :key="currency.code" :value="currency.code">{{ currency.code }} — {{ currency.name_ar }}</option>
                        </select>
                    </label>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs leading-5 text-slate-500">اختيار قسم يعرض سجلاته مع وحداته التابعة. نوع المعاملة ينطبق على مؤشرات المعاملات فقط. المؤشرات اللحظية تقرأ حالة الآن مهما كانت الفترة. فلتر المواقع غير متاح: لا يوجد في النظام سجل مواقع تُربط به السجلات.</p>
                    <button class="btn btn-primary" :disabled="loading">{{ loading ? 'جارٍ التحميل…' : 'تطبيق' }}</button>
                </div>
                <ul v-if="errors.length" role="alert" class="alert-error space-y-1 text-sm"><li v-for="error in errors" :key="error">{{ error }}</li></ul>
            </form>

            <Deferred data="cards">
                <template #fallback>
                    <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3" role="status" aria-label="جارٍ حساب المؤشرات">
                        <div v-for="index in 6" :key="index" class="card h-56 animate-pulse bg-slate-50"></div>
                    </div>
                </template>
                <template #rescue>
                    <p role="alert" class="alert-error">تعذر حساب المؤشرات الآن. <button type="button" class="underline" @click="refresh">أعد المحاولة</button></p>
                </template>
                <div v-if="groups.length" class="space-y-8">
                    <section v-for="group in groups" :key="group.domain" :aria-labelledby="`domain-${group.domain}`" class="space-y-3">
                        <h2 :id="`domain-${group.domain}`" class="text-lg font-bold text-navy-800">{{ group.label }}</h2>
                        <KpiCards :cards="group.cards" :active-id="panel?.card.id ?? null"
                            @details="(card, component) => open('details', card, { component })"
                            @breakdown="card => open('breakdown', card)" @trend="card => open('trend', card)" @history="card => open('history', card)" />
                    </section>
                </div>
                <EmptyState v-else title="لا توجد مؤشرات مفعّلة ضمن نطاقك" description="يفعّل مدير النظام المؤشرات ويحدد من يراها من «إدارة المؤشرات»." />
            </Deferred>

            <section v-if="panel" class="card space-y-4 p-4 sm:p-5" aria-live="polite">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 ref="panelHeading" tabindex="-1" class="text-lg font-bold text-navy-800 outline-none">{{ titles[panel.kind] }}: {{ panel.card.title }}</h2>
                        <p class="text-xs text-slate-500">
                            <template v-if="panel.kind === 'details' && panel.data">{{ panel.data.measure }} · {{ kpiNumber(panel.data.total) }} سجل · النطاق: {{ panel.data.scope }}</template>
                            <template v-else>نفس النطاق والفلاتر المطبقة على البطاقة.</template>
                        </p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" @click="closePanel">إغلاق</button>
                </header>
                <p v-if="panelBusy" role="status" class="animate-pulse text-sm text-slate-500">جارٍ التحميل…</p>
                <p v-if="panelError" role="alert" class="alert-error text-sm">{{ panelError }}</p>

                <template v-if="panel.data && panel.kind === 'details'">
                    <div class="overflow-x-auto">
                        <table class="data-table w-full text-sm">
                            <thead><tr><th v-for="(label, key) in panel.data.columns" :key="key" scope="col">{{ label }}</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
                            <tbody>
                                <tr v-for="(row, index) in panel.data.rows" :key="index">
                                    <td v-for="(label, key) in panel.data.columns" :key="key">{{ row.cells[key] ?? '—' }}</td>
                                    <td><Link v-if="row.link" :href="row.link" class="text-xs font-semibold text-blue-700 hover:underline">فتح</Link></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-if="!panel.data.rows.length" title="لا توجد سجلات" />
                    <p class="text-xs text-slate-500">يظهر رابط «فتح» فقط للسجلات التي تملك صلاحية عرضها. السجلات خارج نطاقك لا تُرسل إلى الصفحة.</p>
                    <div v-if="panel.data.last_page > 1" class="flex items-center gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="panelBusy || panel.data.page <= 1" @click="open('details', panel.card, { component: panel.component, page: panel.data.page - 1 })">السابق</button>
                        <span class="text-sm">صفحة {{ kpiNumber(panel.data.page) }} من {{ kpiNumber(panel.data.last_page) }}</span>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="panelBusy || panel.data.page >= panel.data.last_page" @click="open('details', panel.card, { component: panel.component, page: panel.data.page + 1 })">التالي</button>
                    </div>
                </template>

                <template v-if="panel.data && panel.kind === 'breakdown'">
                    <div class="overflow-x-auto">
                        <table class="data-table w-full text-sm">
                            <thead><tr><th scope="col">القسم</th><th scope="col">القيمة</th><th scope="col">{{ panel.card.denominator_label ? 'البسط' : 'المقياس' }}</th><th v-if="panel.card.denominator_label" scope="col">المقام</th><th scope="col">العينة</th></tr></thead>
                            <tbody>
                                <tr v-for="row in panel.data.rows" :key="row.department">
                                    <td>{{ row.department }}</td><td class="font-semibold">{{ kpiValue(panel.card, row.value) }}</td>
                                    <td>{{ kpiNumber(row.numerator) }}</td><td v-if="panel.card.denominator_label">{{ kpiNumber(row.denominator) }}</td><td>{{ kpiNumber(row.sample_size) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-if="!panel.data.rows.length" title="لا توجد سجلات لتوزيعها" />
                    <p class="text-xs text-slate-500">أساس الإسناد: {{ panel.card.attribution }}.</p>
                </template>

                <template v-if="panel.data && panel.kind === 'trend'">
                    <p class="text-xs text-slate-500">{{ panel.data.note }}</p>
                    <ul v-if="panel.data.points.length" class="space-y-2">
                        <li v-for="point in panel.data.points" :key="point.from" class="grid grid-cols-[7rem_1fr_auto] items-center gap-3 text-sm">
                            <span class="text-slate-600">{{ point.label }}</span>
                            <span class="h-3 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                <span class="block h-full rounded-full bg-navy-700" :style="{ width: `${point.value === null ? 0 : Math.max(2, Math.abs(point.value) / trendMax * 100)}%` }"></span>
                            </span>
                            <span class="font-semibold text-navy-800" :title="point.reason ?? ''">{{ point.value === null ? 'غير متاح' : kpiValue(panel.card, point.value) }}</span>
                        </li>
                    </ul>
                    <button v-else type="button" class="btn btn-secondary btn-sm" @click="open('history', panel.card)">عرض القراءات المحفوظة</button>
                </template>

                <template v-if="panel.data && panel.kind === 'history'">
                    <div v-if="panel.data.data.length" class="overflow-x-auto">
                        <table class="data-table w-full text-sm">
                            <thead><tr><th scope="col">وقت الحفظ</th><th scope="col">الفترة</th><th scope="col">القيمة</th><th scope="col">الهدف وقتها</th><th scope="col">الإصدار</th><th scope="col">السبب</th><th scope="col">بواسطة</th></tr></thead>
                            <tbody>
                                <tr v-for="row in panel.data.data" :key="row.id">
                                    <td>{{ kpiDateTime(row.saved_at) }}</td><td>{{ row.from }} — {{ row.to }}</td>
                                    <td class="font-semibold">{{ kpiValue({ unit_code: row.unit_code, currency: row.currency }, row.value) }}</td>
                                    <td>{{ row.target === null ? 'غير معتمد' : kpiValue({ unit_code: row.unit_code, currency: row.currency }, row.target) }}</td>
                                    <td>{{ row.version }}</td><td>{{ row.reason }}</td><td>{{ row.saved_by }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-else title="لا توجد قراءات محفوظة بنطاقك وفلاترك الحالية" description="القراءة المحفوظة ثابتة لا تتغير، وتُحفظ شهريًا آليًا أو يدويًا من أسفل الصفحة." />
                </template>
            </section>

            <Deferred data="comparison">
                <template #fallback><div class="card h-40 animate-pulse bg-slate-50" role="status" aria-label="جارٍ حساب مقارنة الأقسام"></div></template>
                <section v-if="comparison" class="card space-y-3 p-4 sm:p-5" aria-labelledby="comparison-title">
                    <div>
                        <h2 id="comparison-title" class="text-lg font-bold text-navy-800">مقارنة الأقسام</h2>
                        <p class="text-xs leading-5 text-slate-500">الفترة {{ comparison.period.from }} — {{ comparison.period.to }}. {{ comparison.note }}</p>
                    </div>
                    <p v-if="!comparison.sla_configured" class="alert-warning text-xs">لا توجد مهلة (SLA) معتمدة لأي مرحلة، لذلك عمودا التأخر ونسبة الالتزام غير قابلين للقياس بعد.</p>
                    <div class="overflow-x-auto">
                        <table class="data-table w-full text-sm">
                            <thead><tr>
                                <th scope="col">القسم</th><th scope="col">طلبات مفتوحة مقدمة منه</th><th scope="col">مهام مفتوحة لديه</th><th scope="col">مهام متأخرة لديه</th>
                                <th scope="col">مهام أنجزها في الفترة</th><th scope="col">الالتزام بالمهلة</th><th scope="col">وسيط زمن المعالجة (ساعة)</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in comparison.rows" :key="row.department">
                                    <td class="font-semibold">{{ row.department }}</td><td>{{ kpiNumber(row.requests_open) }}</td><td>{{ kpiNumber(row.tasks_open) }}</td>
                                    <td :class="row.tasks_overdue ? 'font-bold text-red-700' : ''">{{ comparison.sla_configured ? kpiNumber(row.tasks_overdue) : '—' }}</td>
                                    <td>{{ kpiNumber(row.handled) }}</td>
                                    <td>{{ row.on_time_rate === null ? '—' : `${kpiNumber(row.on_time_rate)}٪ من ${kpiNumber(row.with_deadline)}` }}</td>
                                    <td>{{ kpiNumber(row.median_hours) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="comparison.unattributed_overdue" class="text-xs text-slate-500">مهام متأخرة غير منسوبة لقسم واحد (مسندة لأكثر من قسم أو لحساب بلا قسم): {{ kpiNumber(comparison.unattributed_overdue) }}.</p>
                </section>
            </Deferred>

            <details v-if="registered.length" class="card p-4 sm:p-5">
                <summary class="cursor-pointer font-bold text-navy-800">مؤشرات مسجلة غير متاحة بعد ({{ kpiNumber(registered.length) }})</summary>
                <p class="mt-2 text-xs text-slate-500">مسجلة في الكتالوج دون رقم، لأن الوحدة أو البيانات أو السياسة المعتمدة غير موجودة بعد. لا يُعرض لها رقم تقديري.</p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li v-for="item in registered" :key="item.code" class="rounded-lg border border-slate-100 p-3">
                        <p class="font-semibold text-navy-800">{{ item.title }} <span class="text-xs font-normal text-slate-500">· {{ item.domain }}</span></p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ item.reason }}</p>
                    </li>
                </ul>
            </details>

            <form class="card space-y-3 p-4 sm:p-5" aria-labelledby="save-title" @submit.prevent="saveReadings">
                <h2 id="save-title" class="font-bold text-navy-800">حفظ قراءة ثابتة</h2>
                <p class="text-xs leading-5 text-slate-500">تُحفظ القيم الحالية لكل المؤشرات المتاحة بنطاقك وفلاترك وفترتك كما هي، ولا تُعدّل القراءات السابقة. تُحفظ قراءة شهرية آليًا في أول كل شهر إذا كان مُجدول المهام يعمل على الخادم.</p>
                <div class="flex flex-wrap items-end gap-3">
                    <label class="field-label min-w-64 flex-1">سبب الحفظ<input v-model="reason" required minlength="5" maxlength="500" class="field mt-1 w-full" placeholder="مثل: مراجعة أداء الربع الثالث"></label>
                    <button class="btn btn-secondary" :disabled="saving || reason.trim().length < 5">{{ saving ? 'جارٍ الحفظ…' : 'حفظ القراءة' }}</button>
                </div>
                <p v-if="saveMessage" role="status" class="alert-success text-sm">{{ saveMessage }}</p>
                <p v-if="saveError" role="alert" class="alert-error text-sm">{{ saveError }}</p>
            </form>
        </div>
    </AppLayout>
</template>
