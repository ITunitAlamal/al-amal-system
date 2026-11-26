<script setup>
import { formatDate } from '../../Support/dates.js';
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';

const props = defineProps({
    requests: { type: Object, default: () => ({ data: [], links: [], total: 0 }) },
    filters: { type: Object, default: () => ({}) },
    typeOptions: { type: Array, default: () => [] },
    statusOptions: { type: Object, default: () => ({}) },
    startable: { type: Array, default: () => [] },
    canRequestLeave: { type: Boolean, default: false },
});

const form = reactive({
    q: props.filters.q ?? '',
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const filtered = computed(() => Object.values(props.filters || {}).some(value => value !== null && value !== ''));
const items = computed(() => props.requests.data ?? []);

function apply() {
    const query = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== null));
    router.get('/requests', query, { preserveState: true, preserveScroll: true, replace: true });
}
function clear() {
    Object.assign(form, { q: '', type: '', status: '', from: '', to: '' });
    router.get('/requests', {}, { preserveState: true, preserveScroll: true, replace: true });
}
function formattedDate(value) {
    return formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' }, '—');
}
</script>

<template>
    <AppLayout title="طلباتي">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="طلباتي" title="طلباتي — أين وصلت؟" description="كل طلب قدّمته، ومرحلته الحالية، ومن ينتظر إجراءه. افتح الطلب لترى مراحله كاملة." />

            <section class="card">
                <h2 class="card-title mb-3">طلب جديد</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Link v-if="canRequestLeave" href="/leaves/create" class="group flex items-center justify-between rounded-xl border border-navy-100 bg-navy-50/50 p-4 font-semibold text-navy-800 transition hover:border-gold-300 hover:bg-gold-50">
                        <span>طلب إجازة</span>
                        <span class="text-gold-600 transition group-hover:-translate-x-1" aria-hidden="true">←</span>
                    </Link>
                    <Link
                        v-for="workflow in startable"
                        :key="workflow.code"
                        :href="`/requests/new/${workflow.code}`"
                        class="group flex items-center justify-between rounded-xl border border-navy-100 bg-navy-50/50 p-4 font-semibold text-navy-800 transition hover:border-gold-300 hover:bg-gold-50"
                    >
                        <span>{{ workflow.name }}</span>
                        <span class="text-gold-600 transition group-hover:-translate-x-1" aria-hidden="true">←</span>
                    </Link>
                    <Link href="/requests/payslip" class="group flex items-center justify-between rounded-xl border border-navy-100 bg-navy-50/50 p-4 font-semibold text-navy-800 transition hover:border-gold-300 hover:bg-gold-50">
                        <span>طلب قسيمة راتب</span>
                        <span class="text-gold-600 transition group-hover:-translate-x-1" aria-hidden="true">←</span>
                    </Link>
                </div>
                <p class="mt-3 text-xs leading-5 text-slate-500">أنواع الطلبات تأتي من الإعدادات؛ أي نوع جديد ينشره مدير النظام يظهر هنا تلقائيًا. يُوجَّه طلبك لمسؤوله حسب المسار، ولا يمكنك اعتماد طلبك بنفسك.</p>
            </section>

            <section class="card space-y-4">
                <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6" role="search" aria-label="تصفية طلباتي" @submit.prevent="apply">
                    <div class="lg:col-span-2">
                        <label for="filter-q" class="field-label">رقم الطلب أو عنوانه</label>
                        <input id="filter-q" v-model.trim="form.q" type="search" maxlength="100" class="field" placeholder="مثال: LV-2026 أو إجازة" />
                    </div>
                    <div>
                        <label for="filter-type" class="field-label">النوع</label>
                        <select id="filter-type" v-model="form.type" class="field">
                            <option value="">كل الأنواع</option>
                            <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="filter-status" class="field-label">الحالة</label>
                        <select id="filter-status" v-model="form.status" class="field">
                            <option value="">كل الحالات</option>
                            <option v-for="(label, key) in statusOptions" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="filter-from" class="field-label">من تاريخ</label>
                        <input id="filter-from" v-model="form.from" type="date" class="field" />
                    </div>
                    <div>
                        <label for="filter-to" class="field-label">إلى تاريخ</label>
                        <input id="filter-to" v-model="form.to" type="date" :min="form.from || undefined" class="field" />
                    </div>
                    <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-6">
                        <button type="submit" class="btn btn-primary">تطبيق</button>
                        <button v-if="filtered" type="button" class="btn btn-secondary" @click="clear">مسح التصفية</button>
                        <span class="ms-auto self-center text-xs text-slate-500" aria-live="polite">{{ requests.total ?? items.length }} طلب</span>
                    </div>
                </form>

                <EmptyState v-if="!items.length" :title="filtered ? 'لا توجد طلبات مطابقة' : 'لم تقدّم أي طلب بعد'" :description="filtered ? 'غيّر معايير التصفية أو امسحها.' : 'اختر نوع الطلب من الأعلى للبدء.'" />
                <ul v-else class="space-y-3">
                    <li v-for="item in items" :key="item.key" class="rounded-xl border border-slate-100 p-4 transition hover:border-navy-200">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <Link :href="item.url" class="font-semibold text-navy-800 hover:text-blue-700 hover:underline">{{ item.title }}</Link>
                                <p class="mt-1 text-xs text-slate-500">{{ item.kind }} · <span dir="ltr">{{ item.reference || '—' }}</span> · {{ formattedDate(item.createdAt) }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge :label="item.status" :tone="item.tone" />
                                <Link :href="item.url" class="btn btn-secondary btn-sm">التفاصيل</Link>
                            </div>
                        </div>
                        <StatusStepper v-if="item.steps.length" class="mt-3" :steps="item.steps" compact />
                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                            <div><dt class="inline text-slate-500">الآن: </dt><dd class="inline font-semibold text-navy-800">{{ item.summary.where || '—' }}</dd></div>
                            <div>
                                <dt class="inline text-slate-500">التالي: </dt><dd class="inline font-semibold text-navy-800">{{ item.summary.next || '—' }}</dd>
                                <span v-if="item.summary.overdue" class="ms-2 font-semibold text-red-700">· تجاوزت المهلة</span>
                            </div>
                        </dl>
                        <p v-if="item.summary.reason" class="alert-warning mt-2 text-xs">السبب: {{ item.summary.reason }}</p>
                    </li>
                </ul>

                <nav v-if="(requests.links || []).length > 3" class="flex flex-wrap gap-1" aria-label="الصفحات">
                    <template v-for="(link, index) in requests.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-scroll :class="['btn btn-sm', link.active ? 'btn-primary' : 'btn-secondary']" v-html="link.label" />
                        <span v-else class="btn btn-sm text-slate-400" v-html="link.label" />
                    </template>
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
