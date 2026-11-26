<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    rules: { type: Object, default: () => ({ data: [], links: [], total: 0 }) },
    employmentTerms: { type: Array, default: () => [] },
    contractTypes: { type: Array, default: () => [] },
    leaveTypes: { type: Array, default: () => [] },
});
const page = usePage();
const base = '/admin/leave-entitlement-rules';
const query = ref('');
const status = ref('all');
const termNames = computed(() => new Map(props.employmentTerms.map(x => [Number(x.id), x.name_ar])));
const typeNames = computed(() => new Map(props.leaveTypes.map(x => [Number(x.id), x.name_ar])));
const contractNames = computed(() => new Map(props.contractTypes.map(x => [Number(x.id), x.name_ar])));
const contractLabel = rule => rule.contract_type_value_id ? (contractNames.value.get(Number(rule.contract_type_value_id)) ?? `#${rule.contract_type_value_id}`) : 'جميع العقود';
const items = computed(() => (props.rules?.data ?? []).filter(rule => {
    const searchable = [rule.code, rule.name_ar, termNames.value.get(Number(rule.employment_term_value_id)), typeNames.value.get(Number(rule.leave_type_value_id))].join(' ').toLowerCase();
    const qOk = searchable.includes(query.value.trim().toLowerCase());
    const sOk = status.value === 'all' || Boolean(rule.is_active) === (status.value === 'active');
    return qOk && sOk;
}));
const fmt = value => value ? String(value).slice(0, 10) : 'بلا حد';
const days = value => Number(value ?? 0).toLocaleString('ar-u-nu-latn', { maximumFractionDigits: 2 });
const months = value => value === null || value === undefined ? 'مفتوح' : `${Number(value).toLocaleString('ar-u-nu-latn')} شهر`;
const canEdit = rule => Boolean(rule.effective_from) && String(rule.effective_from).slice(0, 10) > new Date().toLocaleDateString('en-CA');
</script>

<template>
    <AppLayout title="سياسات استحقاق الإجازات">
        <div class="mx-auto max-w-7xl space-y-5">
            <nav class="flex flex-wrap gap-2 text-sm text-slate-600" aria-label="مسار التنقل">
                <Link href="/admin/master-data" class="font-semibold text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span><span>الموارد البشرية</span><span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">سياسات الإجازات</span>
            </nav>
            <PageHeader eyebrow="ثوابت الموارد البشرية" title="سياسات استحقاق الإجازات" description="قواعد الاستحقاق السنوي وفق بند التوظيف، ونوع الإجازة، وأقدمية الموظف. إضافة السياسات لا تغيّر الأرصدة التاريخية تلقائيًا.">
                <Link href="/admin/leave-policy-corrections/1" class="btn btn-gold">تصحيح السياسة المعتمدة</Link>
                <Link href="/leaves" class="btn btn-secondary">طلبات الإجازات</Link>
                <Link :href="`${base}/create`" class="btn btn-primary">+ إضافة سياسة</Link>
            </PageHeader>
            <div v-if="!employmentTerms.length || !leaveTypes.length" role="status" class="alert-warning">
                بعض الثوابت المطلوبة غير متوفرة: يرجى اعتماد بنود التوظيف وأنواع الإجازات من ثوابت النظام قبل إضافة سياسة. لن ننشئ قيمًا افتراضية.
            </div>
            <section class="card">
                <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_auto]">
                    <div>
                        <label for="ler-search" class="field-label">بحث في الصفحة الحالية</label>
                        <input id="ler-search" v-model="query" type="search" placeholder="الرمز، الاسم، البند، النوع" class="field" />
                    </div>
                    <div class="sm:min-w-40">
                        <label for="ler-status" class="field-label">الحالة</label>
                        <select id="ler-status" v-model="status" class="field">
                            <option value="all">الكل</option><option value="active">نشطة</option><option value="inactive">معطلة</option>
                        </select>
                    </div>
                </div>
                <EmptyState v-if="!items.length" title="لا توجد سياسات مطابقة" description="غيّر عوامل التصفية أو أضف سياسة استحقاق جديدة." />
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-[990px]">
                        <thead>
                            <tr><th>السياسة</th><th>بند التوظيف</th><th>نوع العقد</th><th>نوع الإجازة</th><th>الأقدمية بالأشهر</th><th>الأيام/سنة</th><th>السريان</th><th>الحالة</th><th>الإجراء</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="rule in items" :key="rule.id">
                                <td><strong class="block text-navy-800">{{ rule.name_ar }}</strong><span dir="ltr" class="text-xs text-slate-500">{{ rule.code }}</span></td>
                                <td>{{ termNames.get(Number(rule.employment_term_value_id)) ?? `#${rule.employment_term_value_id} (غير نشط أو غير متاح)` }}</td>
                                <td class="text-slate-600">{{ contractLabel(rule) }}</td>
                                <td>{{ typeNames.get(Number(rule.leave_type_value_id)) ?? `#${rule.leave_type_value_id} (غير نشط أو غير متاح)` }}</td>
                                <td>من {{ months(rule.min_service_months) }} إلى {{ months(rule.max_service_months) }}</td>
                                <td class="font-semibold">{{ days(rule.annual_entitlement_days) }}</td>
                                <td><span dir="ltr">{{ fmt(rule.effective_from) }}</span> — <span dir="ltr">{{ fmt(rule.effective_until) }}</span></td>
                                <td><StatusBadge :label="rule.is_active ? 'نشطة' : 'معطلة'" :tone="rule.is_active ? 'success' : 'muted'" /></td>
                                <td><Link v-if="canEdit(rule)" :href="`${base}/${rule.id}/edit`" class="btn btn-sm btn-secondary">تعديل</Link><span v-else class="text-xs text-slate-500">بدأ سريانها</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
                    <span>إجمالي السياسات: {{ rules.total ?? 0 }}</span>
                    <div class="flex flex-wrap gap-2">
                        <template v-for="(link, i) in rules.links ?? []" :key="i">
                            <Link v-if="link.url" :href="link.url" :preserve-scroll="true" class="rounded-lg border px-3 py-2" :class="link.active ? 'border-navy-700 bg-navy-700 text-white' : 'border-slate-200 text-slate-700'" v-html="link.label" />
                            <span v-else class="rounded-lg border border-slate-100 px-3 py-2 text-slate-400" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
