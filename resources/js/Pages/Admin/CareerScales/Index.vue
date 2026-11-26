<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    scales: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
});

const page = usePage();
const base = '/admin/career-scales';
const tab = ref('scales');
const search = ref('');
const status = ref('all');
const scaleFilter = ref('all');

const success = computed(() => page.props.flash?.success ?? '');
const filteredScales = computed(() => props.scales.filter(s => {
    const q = search.value.trim().toLocaleLowerCase();
    const matchesText = [s.name_ar, s.name_en, s.code].filter(Boolean).join(' ').toLocaleLowerCase().includes(q);
    const matchesStatus = status.value === 'all' || Boolean(s.is_active) === (status.value === 'active');
    return matchesText && matchesStatus;
}));
const filteredGrades = computed(() => props.grades.filter(g => {
    const q = search.value.trim().toLocaleLowerCase();
    const matchesText = [g.name_ar, g.name_en, g.code, g.scale_name].filter(Boolean).join(' ').toLocaleLowerCase().includes(q);
    const matchesStatus = status.value === 'all' || Boolean(g.is_active) === (status.value === 'active');
    const matchesScale = scaleFilter.value === 'all' || String(g.career_scale_id) === scaleFilter.value;
    return matchesText && matchesStatus && matchesScale;
}));
const totalActiveGrades = computed(() => props.grades.filter(g => g.is_active).length);

function switchTab(value) {
    tab.value = value;
    search.value = '';
    status.value = 'all';
    scaleFilter.value = 'all';
}

function salaryRange(grade) {
    if (grade.salary_min === null && grade.salary_max === null) return 'غير محدد';
    const min = grade.salary_min === null ? '—' : Number(grade.salary_min).toLocaleString('ar-u-nu-latn');
    const max = grade.salary_max === null ? '—' : Number(grade.salary_max).toLocaleString('ar-u-nu-latn');
    return `${min} – ${max} ${grade.currency_code ?? ''}`.trim();
}
</script>

<template>
    <AppLayout title="السلالم والدرجات الوظيفية">
        <div class="mx-auto max-w-7xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <Link href="/admin/master-data" class="font-semibold text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span>
                <span>ثوابت العمل</span>
                <span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">السلالم والدرجات</span>
            </nav>

            <PageHeader eyebrow="ثوابت العمل" title="السلالم والدرجات الوظيفية" description="السلالم ودرجاتها ونطاقات الرواتب المرجعية. تعديل نطاق الراتب لا يغيّر رواتب الموظفين تلقائيًا.">
                <Link :href="tab === 'scales' ? `${base}/create` : `${base}/grades/create`" class="btn btn-primary">
                    {{ tab === 'scales' ? '+ إضافة سلم وظيفي' : '+ إضافة درجة وظيفية' }}
                </Link>
            </PageHeader>

            <section class="grid gap-3 sm:grid-cols-3" aria-label="ملخص السلالم والدرجات">
                <div class="card"><p class="text-sm text-slate-500">إجمالي السلالم</p><p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ scales.length }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">إجمالي الدرجات</p><p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ grades.length }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">الدرجات النشطة</p><p class="mt-2 font-display text-3xl font-bold text-emerald-700">{{ totalActiveGrades }}</p></div>
            </section>

            <section class="card p-0">
                <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4" role="tablist">
                    <button type="button" role="tab" :aria-selected="tab === 'scales'" @click="switchTab('scales')" :class="['btn', tab === 'scales' ? 'btn-primary' : 'btn-secondary']">السلالم الوظيفية ({{ scales.length }})</button>
                    <button type="button" role="tab" :aria-selected="tab === 'grades'" @click="switchTab('grades')" :class="['btn', tab === 'grades' ? 'btn-primary' : 'btn-secondary']">الدرجات الوظيفية ({{ grades.length }})</button>
                </div>

                <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto_auto]">
                    <div>
                        <label for="cs-search" class="field-label">بحث</label>
                        <input id="cs-search" v-model="search" type="search" placeholder="الاسم أو الرمز" class="field" />
                    </div>
                    <div v-if="tab === 'grades'" class="sm:min-w-48">
                        <label for="cs-scale" class="field-label">السلم الوظيفي</label>
                        <select id="cs-scale" v-model="scaleFilter" class="field">
                            <option value="all">جميع السلالم</option>
                            <option v-for="scale in scales" :key="scale.id" :value="String(scale.id)">{{ scale.name_ar }}</option>
                        </select>
                    </div>
                    <div class="sm:min-w-40">
                        <label for="cs-status" class="field-label">الحالة</label>
                        <select id="cs-status" v-model="status" class="field">
                            <option value="all">الكل</option>
                            <option value="active">نشط</option>
                            <option value="inactive">غير نشط</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto px-4 pb-4">
                    <template v-if="tab === 'scales'">
                        <table v-if="filteredScales.length" class="data-table min-w-[640px]">
                            <thead><tr><th>السلم الوظيفي</th><th>الرمز</th><th>الدرجات</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                            <tbody>
                                <tr v-for="scale in filteredScales" :key="scale.id">
                                    <td class="font-semibold text-navy-800">{{ scale.name_ar }}<span v-if="scale.name_en" class="mt-0.5 block text-xs font-normal text-slate-500">{{ scale.name_en }}</span></td>
                                    <td class="font-mono text-xs text-slate-500" dir="ltr">{{ scale.code }}</td>
                                    <td>{{ scale.grades_count ?? grades.filter(g => g.career_scale_id === scale.id).length }}</td>
                                    <td><StatusBadge :label="scale.is_active ? 'نشط' : 'معطل'" :tone="scale.is_active ? 'success' : 'muted'" /></td>
                                    <td><Link :href="`${base}/${scale.id}/edit`" class="btn btn-sm btn-secondary">تعديل</Link></td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else title="لا توجد سلالم مطابقة" description="غيّر عوامل التصفية أو أضف سلمًا وظيفيًا جديدًا." />
                    </template>
                    <template v-else>
                        <table v-if="filteredGrades.length" class="data-table min-w-[820px]">
                            <thead><tr><th>الدرجة</th><th>الرمز</th><th>السلم الوظيفي</th><th>المستوى</th><th>نطاق الراتب المرجعي</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                            <tbody>
                                <tr v-for="grade in filteredGrades" :key="grade.id">
                                    <td class="font-semibold text-navy-800">{{ grade.name_ar }}<span v-if="grade.name_en" class="mt-0.5 block text-xs font-normal text-slate-500">{{ grade.name_en }}</span></td>
                                    <td class="font-mono text-xs text-slate-500" dir="ltr">{{ grade.code }}</td>
                                    <td>{{ grade.scale_name }}</td>
                                    <td>{{ grade.grade_level ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ salaryRange(grade) }}</td>
                                    <td><StatusBadge :label="grade.is_active ? 'نشطة' : 'معطلة'" :tone="grade.is_active ? 'success' : 'muted'" /></td>
                                    <td><Link :href="`${base}/grades/${grade.id}/edit`" class="btn btn-sm btn-secondary">تعديل</Link></td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else title="لا توجد درجات مطابقة" description="أضف سلمًا وظيفيًا أولًا، ثم أضف درجاته." />
                    </template>
                </div>
            </section>
            <p class="text-xs text-slate-500">الإضافة والتعديل يتمان في صفحات مستقلة، ولا تُغيّر هذه العمليات الرواتب الفعلية للموظفين.</p>
        </div>
    </AppLayout>
</template>
