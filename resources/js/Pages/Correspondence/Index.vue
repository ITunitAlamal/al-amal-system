<script setup>
import { formatDate } from '../../Support/dates.js';
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ records: Object, counts: Object, filters: Object, isAdmin: Boolean, canCreate: Boolean, canCreateExternalArchive: Boolean, canSeeDecisions: Boolean });
const page = usePage();
const channels = computed(() => [
    { id: 'internal', label: 'المراسلات الداخلية', count: props.counts?.all || 0 },
    ...(props.canCreateExternalArchive ? [{ id: 'external', label: 'المراسلات الخارجية', count: (props.counts?.external_incoming || 0) + (props.counts?.external_outgoing || 0) }] : []),
]);
const boxes = { inbox: 'الوارد', sent: 'الصادر', drafts: 'المسودات', needs_action: 'تنتظر إجرائي', forwarded: 'أحلتها', returned: 'أُعيدت إليّ', overdue: 'متأخرة لديّ', in_progress: 'قيد المتابعة', archive: 'المنتهية / الأرشيف', all: 'الكل' };
const labels = { draft: 'مسودة', issued: 'مرسلة', processing: 'قيد المتابعة', processed: 'أُنجزت', closed: 'منتهية', archived: 'مؤرشفة', withdrawn: 'مسحوبة' };
const tones = { draft: 'warning', issued: 'info', processing: 'info', processed: 'success', closed: 'success', archived: 'muted', withdrawn: 'muted' };
const empty = { inbox: 'لا توجد مراسلات واردة', needs_action: 'لا توجد مراسلات تنتظر إجراءك', in_progress: 'لا توجد مراسلات قيد المتابعة', sent: 'لم ترسل مراسلات بعد', drafts: 'لا توجد مسودات', archive: 'لا توجد مراسلات منتهية', all: 'لا توجد مراسلات' };
const defaults = () => ({ status: '', from: '', to: '', unread: false, overdue: false });
const search = ref(props.filters.q || '');
const selected = ref({ ...defaults(), ...props.filters });
const optionsOpen = ref(false);
const filtersExpanded = ref(Boolean(props.filters.status || props.filters.from || props.filters.to || props.filters.unread || props.filters.overdue));
const activeFilters = computed(() => [
    props.filters.q ? { key: 'q', label: `بحث: ${props.filters.q}` } : null,
    props.filters.status ? { key: 'status', label: `الحالة: ${labels[props.filters.status] || props.filters.status}` } : null,
    props.filters.from ? { key: 'from', label: `من: ${props.filters.from}` } : null,
    props.filters.to ? { key: 'to', label: `إلى: ${props.filters.to}` } : null,
    props.filters.unread ? { key: 'unread', label: 'غير مقروءة' } : null,
    props.filters.overdue ? { key: 'overdue', label: 'متأخرة' } : null,
].filter(Boolean));
watch(() => props.filters, value => {
    search.value = value.q || '';
    selected.value = { ...defaults(), ...value };
    filtersExpanded.value = Boolean(value.status || value.from || value.to || value.unread || value.overdue);
});
const date = value => formatDate(value, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

function open(box = props.filters.box) {
    router.get('/correspondence', {
        box, channel: props.filters.channel || 'internal', direction: props.filters.direction || 'incoming',
        q: search.value || undefined, status: selected.value.status || undefined,
        from: selected.value.from || undefined, to: selected.value.to || undefined,
        unread: selected.value.unread ? 1 : undefined, overdue: selected.value.overdue ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true });
}

function selectFolder(channel, direction) {
    optionsOpen.value = false;
    selected.value = defaults();
    filtersExpanded.value = false;
    router.get('/correspondence', {
        channel, direction, box: channel === 'external' ? 'all' : (direction === 'outgoing' ? 'sent' : 'inbox'),
        q: search.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function removeFilter(key) {
    const query = {
        box: props.filters.box || 'inbox',
        channel: props.filters.channel || 'internal',
        direction: props.filters.direction || 'incoming',
    };
    for (const filter of activeFilters.value) {
        if (filter.key !== key) {
            query[filter.key] = props.filters[filter.key];
        }
    }
    query[key] = '';
    if (key === 'q') {
        search.value = '';
    } else if (key in selected.value) {
        selected.value[key] = defaults()[key];
    }
    router.get('/correspondence', query, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    search.value = '';
    selected.value = defaults();
    filtersExpanded.value = false;
    router.get('/correspondence', {
        channel: props.filters.channel || 'internal', direction: props.filters.direction || 'incoming',
        box: props.filters.channel === 'external' ? 'all' : (props.filters.direction === 'outgoing' ? 'sent' : 'inbox'),
    }, { preserveState: true, preserveScroll: true });
}

const emptyTitle = computed(() => {
    if (props.filters.channel === 'external') {
        return props.filters.direction === 'outgoing' ? 'لا توجد مراسلات خارجية صادرة في الأرشيف' : 'لا توجد مراسلات خارجية واردة في الأرشيف';
    }

    return empty[props.filters.box] || empty.all;
});
</script>

<template>
    <AppLayout title="المراسلات الرسمية">
        <div class="mx-auto max-w-screen-2xl space-y-5">
            <PageHeader title="المراسلات الرسمية" :description="canCreateExternalArchive ? 'اختر المراسلات الداخلية لمتابعة الإحالات، أو الخارجية للاطلاع على البريد المؤرشف.' : 'تابع مراسلاتك الداخلية والإحالات الواردة والصادرة.'">
                <Link v-if="canCreate" href="/correspondence/create" class="btn btn-primary">مراسلة جديدة</Link>
                <Link v-if="canCreateExternalArchive" href="/correspondence/external/create" class="btn btn-secondary">تسجيل بريد خارجي</Link>
                <Link v-if="canSeeDecisions" href="/correspondence/decisions" class="btn btn-secondary">سجل قرارات المجلس</Link>
                <Link v-if="isAdmin" href="/correspondence/policies" class="btn btn-secondary">السياسات</Link>
            </PageHeader>

            <p v-if="!canCreate" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                إنشاء المراسلات يتطلب تكليفًا وظيفيًا ساريًا ضمن قسم نشط. راجع مسؤول الهيكل الإداري لتسجيل التكليف المناسب؛ صلاحية مدير النظام وحدها لا تمنح حق إنشاء المراسلات.
            </p>

            <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4 lg:p-5">
                <div class="flex flex-col gap-4">
                    <nav class="flex flex-wrap gap-2" aria-label="نوع المراسلات">
                        <button v-for="channel in channels" :key="channel.id"
                            type="button" class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold transition"
                            :class="filters.channel === channel.id ? 'bg-navy-800 text-white shadow-sm' : 'border border-slate-200 text-slate-700 hover:bg-slate-50'"
                            :aria-current="filters.channel === channel.id ? 'page' : undefined" @click="selectFolder(channel.id, filters.direction || 'incoming')">
                            {{ channel.label }}
                            <span class="rounded-full px-2 py-0.5 text-xs tabular-nums" :class="filters.channel === channel.id ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600'">{{ channel.count }}</span>
                        </button>
                    </nav>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <nav class="flex gap-2" aria-label="اتجاه المراسلات">
                            <button v-for="direction in [{ id: 'incoming', label: 'الوارد', internalBox: 'inbox' }, { id: 'outgoing', label: 'الصادر', internalBox: 'sent' }]" :key="direction.id"
                                type="button" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition"
                                :class="filters.direction === direction.id ? 'bg-blue-50 text-blue-800 ring-1 ring-blue-200' : 'text-slate-600 hover:bg-slate-100'"
                                :aria-current="filters.direction === direction.id ? 'page' : undefined" @click="selectFolder(filters.channel || 'internal', direction.id)">
                                {{ direction.label }}
                                <span class="rounded-full bg-white px-2 py-0.5 text-xs tabular-nums text-slate-600">{{ filters.channel === 'external' ? (counts?.[`external_${direction.id}`] || 0) : (counts?.[`internal_${direction.id}`] || 0) }}</span>
                            </button>
                        </nav>
                        <div v-if="filters.channel !== 'external'" class="relative">
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                                :aria-expanded="optionsOpen" aria-haspopup="true" @click="optionsOpen = !optionsOpen">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5 fill-current"><circle cx="12" cy="5" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="12" cy="19" r="1.8" /></svg>
                                خيارات أخرى
                            </button>
                            <div v-if="optionsOpen" class="absolute end-0 z-20 mt-2 w-60 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" role="menu" aria-label="حالات المراسلات">
                                <button v-for="box in ['drafts', 'needs_action', 'forwarded', 'returned', 'overdue', 'in_progress', 'archive', 'all']" :key="box"
                                    type="button" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm hover:bg-slate-50"
                                    :class="filters.box === box ? 'font-bold text-navy-800' : 'text-slate-700'" role="menuitem" @click="open(box); optionsOpen = false">
                                    {{ boxes[box] }}
                                    <span class="text-xs tabular-nums text-slate-500">{{ counts?.[box] || 0 }}</span>
                                </button>
                            </div>
                        </div>
                        <p v-else class="text-sm text-slate-500">الأرشيف الخارجي للحفظ والاطلاع فقط، دون إحالات أو مهام متابعة.</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <form class="flex w-full gap-2 sm:max-w-xl" role="search" @submit.prevent="open()">
                            <label class="sr-only" for="correspondence-search">البحث في المراسلات</label>
                            <input id="correspondence-search" v-model="search" class="field min-w-0 flex-1" placeholder="الموضوع أو الرقم أو صاحب الإجراء" maxlength="150" />
                            <button class="btn btn-secondary shrink-0">بحث</button>
                        </form>
                        <button type="button" class="inline-flex items-center justify-center gap-2 self-start rounded-lg px-3 py-2 text-sm font-semibold text-navy-800 hover:bg-navy-50 sm:self-auto"
                            :aria-expanded="filtersExpanded" aria-controls="correspondence-filters" @click="filtersExpanded = !filtersExpanded">
                            فلاتر متقدمة
                            <span class="rounded-full bg-navy-100 px-2 py-0.5 text-xs tabular-nums">{{ activeFilters.length }}</span>
                            <span aria-hidden="true">{{ filtersExpanded ? '−' : '+' }}</span>
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="activeFilters.length" class="flex flex-wrap items-center gap-2" aria-label="الفلاتر المطبقة">
                <span class="text-sm font-semibold text-slate-600">تصفية حسب:</span>
                <span v-for="filter in activeFilters" :key="filter.key" class="inline-flex items-center gap-2 rounded-full border border-navy-200 bg-navy-50 px-3 py-1 text-xs font-medium text-navy-800">
                    {{ filter.label }}
                    <button type="button" class="rounded-full text-navy-700 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-700"
                        :aria-label="`إزالة الفلتر: ${filter.label}`" @click="removeFilter(filter.key)">×</button>
                </span>
                <button type="button" class="text-xs font-semibold text-blue-700 underline underline-offset-2" @click="clearFilters">مسح الكل</button>
            </section>

            <form v-if="filtersExpanded" id="correspondence-filters" class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="open()">
                <div v-if="Object.keys(page.props.errors || {}).length" class="sm:col-span-2 lg:col-span-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                    <p v-for="(error, key) in page.props.errors" :key="key">{{ error }}</p>
                </div>
                <label class="text-sm font-semibold text-slate-700">الحالة
                    <select v-model="selected.status" class="field mt-1 block w-full">
                        <option value="">كل الحالات</option>
                        <option v-for="(label, status) in labels" :key="status" :value="status">{{ label }}</option>
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">من تاريخ<input v-model="selected.from" type="date" class="field mt-1 block w-full" /></label>
                <label class="text-sm font-semibold text-slate-700">إلى تاريخ<input v-model="selected.to" type="date" class="field mt-1 block w-full" :min="selected.from || undefined" /></label>
                <label class="flex items-center gap-2 self-end rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><input v-model="selected.unread" type="checkbox" /> غير مقروءة لدي</label>
                <label class="flex items-center gap-2 self-end rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><input v-model="selected.overdue" type="checkbox" /> متأخرة لدي</label>
                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                    <button class="btn btn-primary">تطبيق الفلاتر</button>
                    <button type="button" class="btn btn-secondary" @click="clearFilters">مسح الفلاتر</button>
                </div>
            </form>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                    <h2 class="font-bold text-navy-800">{{ filters.channel === 'external' ? `المراسلات الخارجية — ${filters.direction === 'outgoing' ? 'الصادر' : 'الوارد'}` : (boxes[filters.box] || boxes.all) }}</h2>
                    <p class="text-sm text-slate-500">عدد النتائج: <span class="font-semibold tabular-nums text-slate-700">{{ records.total }}</span></p>
                </div>
                <div v-if="records.data.length" class="aims-table-scroll" role="region" aria-label="المراسلات" tabindex="0">
                    <table class="aims-responsive-table data-table w-full text-sm lg:table-fixed">
                        <colgroup class="hidden lg:table-column-group"><col class="w-[34%]" /><col class="w-[16%]" /><col class="w-[18%]" /><col class="w-[20%]" /><col class="w-[12%]" /></colgroup>
                        <thead><tr><th scope="col">الموضوع والرقم</th><th scope="col">من</th><th scope="col">المسؤول الحالي</th><th scope="col">الحالة والمتابعة</th><th scope="col">آخر تحديث</th></tr></thead>
                        <tbody>
                            <tr v-for="row in records.data" :key="row.public_id" class="transition-colors hover:bg-slate-50" :class="row.overdue ? 'bg-red-50/40 hover:bg-red-50' : ''">
                                <td data-label="الموضوع والرقم" class="lg:px-4 lg:py-4">
                                    <Link :href="`/correspondence/${row.public_id}`" class="break-words text-blue-700 hover:underline" :class="row.unread ? 'font-bold' : 'font-semibold'">
                                        <span v-if="row.unread" class="me-1 inline-block h-2 w-2 rounded-full bg-blue-600" role="img" aria-label="غير مقروءة"></span>{{ row.subject }}
                                    </Link>
                                    <p class="mt-1 text-xs text-slate-500" dir="ltr">{{ row.reference || 'مسودة' }}</p>
                                    <p v-if="row.channel === 'external'" class="mt-1 text-xs text-slate-600">
                                        {{ row.direction === 'incoming' ? 'من' : 'إلى' }} {{ row.external_party }}
                                        <span v-if="row.external_reference"> · رقم الجهة: {{ row.external_reference }}</span>
                                        <span v-if="row.external_date"> · تاريخ المراسلة: {{ row.external_date }}</span>
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        <StatusBadge v-if="row.channel === 'external'" :label="row.direction === 'incoming' ? 'وارد خارجي' : 'صادر خارجي'" tone="muted" />
                                        <StatusBadge v-if="row.unread" label="غير مقروءة" tone="info" />
                                        <StatusBadge v-if="row.priority_code === 'urgent'" label="عاجل" tone="danger" />
                                    </div>
                                </td>
                                <td data-label="من" class="break-words">{{ row.author_name || '—' }}</td>
                                <td data-label="المسؤول الحالي" class="break-words">{{ row.task_status === 'open' ? (row.holder_name || '—') : '—' }}</td>
                                <td data-label="الحالة والمتابعة">
                                    <StatusBadge :status="row.status" :label="labels[row.status] || row.status" :tone="tones[row.status] || 'muted'" />
                                    <p v-if="row.overdue" class="mt-1 text-sm font-semibold text-red-700">متأخرة — {{ date(row.due_at) }}</p>
                                    <p v-else-if="row.due_at && row.task_status === 'open'" class="mt-1 text-xs text-slate-500">موعد المتابعة: {{ date(row.due_at) }}</p>
                                </td>
                                <td data-label="آخر تحديث" class="whitespace-nowrap text-xs text-slate-600">{{ date(row.updated_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else :title="filters.q ? 'لا نتائج لهذا البحث' : emptyTitle" description="جرّب تغيير كلمات البحث أو إزالة بعض الفلاتر.">
                    <button v-if="activeFilters.length" type="button" class="btn btn-secondary" @click="clearFilters">مسح البحث والفلاتر</button>
                    <Link v-else-if="canCreate" href="/correspondence/create" class="btn btn-primary">إنشاء مراسلة جديدة</Link>
                </EmptyState>
            </section>

            <nav v-if="records.last_page > 1" class="flex items-center justify-center gap-3" aria-label="صفحات المراسلات">
                <Link v-if="records.prev_page_url" :href="records.prev_page_url" class="btn btn-secondary">السابق</Link>
                <span class="text-sm tabular-nums text-slate-600">صفحة {{ records.current_page }} من {{ records.last_page }}</span>
                <Link v-if="records.next_page_url" :href="records.next_page_url" class="btn btn-secondary">التالي</Link>
            </nav>
        </div>
    </AppLayout>
</template>
