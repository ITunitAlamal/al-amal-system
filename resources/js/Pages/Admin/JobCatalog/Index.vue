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
    categories: { type: Array, default: () => [] },
    titles: { type: Array, default: () => [] },
});

const page = usePage();
const base = '/admin/job-catalog';
const search = ref('');
const categoryFilter = ref('all');
const statusFilter = ref('all');
const tab = ref('categories');

const successMessage = computed(() => page.props.flash?.success || '');
const normalizedSearch = computed(() => search.value.trim().toLocaleLowerCase());
const categoryCounts = computed(() => props.titles.reduce((counts, title) => {
    const id = String(title.job_category_id);
    counts[id] = (counts[id] || 0) + 1;
    return counts;
}, {}));
const visibleCategories = computed(() => props.categories.filter(category => {
    const text = [category.code, category.name_ar, category.name_en].join(' ').toLocaleLowerCase();
    const matchesStatus = statusFilter.value === 'all' ||
        (statusFilter.value === 'active' ? Boolean(category.is_active) : !category.is_active);
    return text.includes(normalizedSearch.value) && matchesStatus;
}));
const visibleTitles = computed(() => props.titles.filter(title => {
    const text = [title.code, title.name_ar, title.name_en, title.category_name].join(' ').toLocaleLowerCase();
    const matchesCategory = categoryFilter.value === 'all' || String(title.job_category_id) === categoryFilter.value;
    const matchesStatus = statusFilter.value === 'all' ||
        (statusFilter.value === 'active' ? Boolean(title.is_active) : !title.is_active);
    return text.includes(normalizedSearch.value) && matchesCategory && matchesStatus;
}));

function selectTab(next) {
    tab.value = next;
    search.value = '';
    statusFilter.value = 'all';
    categoryFilter.value = 'all';
}
</script>

<template>
    <AppLayout title="الفئات والمسميات الوظيفية">
        <div class="mx-auto max-w-7xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <Link href="/admin/master-data" class="font-semibold text-blue-700 hover:underline">ثوابت النظام</Link>
                <span aria-hidden="true">←</span>
                <span>ثوابت العمل</span>
                <span aria-hidden="true">←</span>
                <span class="font-semibold text-navy-800">الفئات والمسميات</span>
            </nav>

            <PageHeader eyebrow="ثوابت العمل" title="الفئات والمسميات الوظيفية" description="المسمى يتبع فئة واحدة، ولا يمنح صلاحيات في النظام تلقائيًا.">
                <Link :href="tab === 'categories' ? `${base}/categories/create` : `${base}/titles/create`" class="btn btn-primary">
                    {{ tab === 'categories' ? '+ إضافة فئة وظيفية' : '+ إضافة مسمى وظيفي' }}
                </Link>
            </PageHeader>

            <section class="grid gap-3 sm:grid-cols-3" aria-label="ملخص ثوابت العمل">
                <div class="card"><p class="text-sm text-slate-500">الفئات الوظيفية</p><p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ categories.length }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">المسميات الوظيفية</p><p class="mt-2 font-display text-3xl font-bold text-navy-800">{{ titles.length }}</p></div>
                <div class="card"><p class="text-sm text-slate-500">المسميات النشطة</p><p class="mt-2 font-display text-3xl font-bold text-emerald-700">{{ titles.filter(x => x.is_active).length }}</p></div>
            </section>

            <section class="card p-0">
                <div class="flex flex-wrap gap-2 border-b border-slate-100 p-4" role="tablist" aria-label="أقسام ثوابت العمل">
                    <button type="button" role="tab" :aria-selected="tab === 'categories'" @click="selectTab('categories')" :class="['btn', tab === 'categories' ? 'btn-primary' : 'btn-secondary']">الفئات الوظيفية ({{ categories.length }})</button>
                    <button type="button" role="tab" :aria-selected="tab === 'titles'" @click="selectTab('titles')" :class="['btn', tab === 'titles' ? 'btn-primary' : 'btn-secondary']">المسميات الوظيفية ({{ titles.length }})</button>
                </div>

                <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto_auto]">
                    <div>
                        <label for="jc-search" class="field-label">بحث</label>
                        <input id="jc-search" v-model="search" type="search" placeholder="الاسم أو الرمز" class="field" />
                    </div>
                    <div v-if="tab === 'titles'" class="sm:min-w-48">
                        <label for="jc-category" class="field-label">الفئة الوظيفية</label>
                        <select id="jc-category" v-model="categoryFilter" class="field">
                            <option value="all">جميع الفئات</option>
                            <option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name_ar }}</option>
                        </select>
                    </div>
                    <div class="sm:min-w-40">
                        <label for="jc-status" class="field-label">الحالة</label>
                        <select id="jc-status" v-model="statusFilter" class="field">
                            <option value="all">الكل</option>
                            <option value="active">نشط</option>
                            <option value="inactive">غير نشط</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto px-4 pb-4">
                    <template v-if="tab === 'categories'">
                        <table v-if="visibleCategories.length" class="data-table min-w-[600px]">
                            <thead><tr><th>الفئة الوظيفية</th><th>الرمز</th><th>عدد المسميات</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                            <tbody>
                                <tr v-for="category in visibleCategories" :key="category.id">
                                    <td class="font-semibold text-navy-800">{{ category.name_ar }}<span v-if="category.name_en" class="mt-0.5 block text-xs font-normal text-slate-500">{{ category.name_en }}</span></td>
                                    <td class="font-mono text-xs text-slate-500" dir="ltr">{{ category.code }}</td>
                                    <td>{{ categoryCounts[String(category.id)] || 0 }}</td>
                                    <td><StatusBadge :label="category.is_active ? 'نشطة' : 'معطلة'" :tone="category.is_active ? 'success' : 'muted'" /></td>
                                    <td><Link :href="`${base}/categories/${category.id}/edit`" class="btn btn-sm btn-secondary">تعديل</Link></td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else title="لا توجد فئات مطابقة" description="غيّر عوامل التصفية أو أضف فئة وظيفية جديدة." />
                    </template>
                    <template v-else>
                        <table v-if="visibleTitles.length" class="data-table min-w-[680px]">
                            <thead><tr><th>المسمى الوظيفي</th><th>الرمز</th><th>الفئة</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                            <tbody>
                                <tr v-for="title in visibleTitles" :key="title.id">
                                    <td class="font-semibold text-navy-800">{{ title.name_ar }}<span v-if="title.name_en" class="mt-0.5 block text-xs font-normal text-slate-500">{{ title.name_en }}</span></td>
                                    <td class="font-mono text-xs text-slate-500" dir="ltr">{{ title.code }}</td>
                                    <td class="text-slate-700">{{ title.category_name }}</td>
                                    <td><StatusBadge :label="title.is_active ? 'نشط' : 'معطل'" :tone="title.is_active ? 'success' : 'muted'" /></td>
                                    <td><Link :href="`${base}/titles/${title.id}/edit`" class="btn btn-sm btn-secondary">تعديل</Link></td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else title="لا توجد مسميات مطابقة" description="أضف فئة وظيفية أولًا، ثم أضف المسمى." />
                    </template>
                </div>
            </section>
            <p class="text-xs text-slate-500">الإضافة والتعديل يتمان في صفحات مستقلة، ولا تُحذف الفئات أو المسميات المستخدمة في السجلات السابقة.</p>
        </div>
    </AppLayout>
</template>
