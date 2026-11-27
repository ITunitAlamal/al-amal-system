<script setup>
import { formatDate } from '../../Support/dates.js';
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import Pagination from '../../Components/Pagination.vue';

// «سجل قرارات مجلس الإدارة»: the recorded Board decisions the viewer may open; each row opens the decision letter.
const props = defineProps({ records: Object, counts: Object, filters: Object, departments: Array });
const states = [{ id: '', label: 'الكل' }, { id: 'open', label: 'قيد المتابعة' }, { id: 'overdue', label: 'متأخرة' }, { id: 'closed', label: 'مغلقة' }];
const search = ref(props.filters.q || '');
const department = ref(props.filters.department_id || '');
const date = value => formatDate(value, { month: 'short', day: 'numeric' });

function open(state = props.filters.state || '') {
    router.get('/correspondence/decisions', { q: search.value || undefined, state: state || undefined, department_id: department.value || undefined }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="سجل قرارات مجلس الإدارة">
        <div class="mx-auto max-w-screen-2xl space-y-5">
            <PageHeader title="سجل قرارات مجلس الإدارة" description="قرارات المجلس المسجلة برقمها وتاريخها وجلستها، والأقسام المكلفة بتنفيذها وحالة متابعتها. يُسجَّل القرار من مراسلة «تبليغ قرار مجلس الإدارة».">
                <Link href="/correspondence" class="btn btn-secondary">المراسلات</Link>
            </PageHeader>

            <p v-if="!records" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">السجل غير مفعّل بعد.</p>

            <template v-else>
                <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
                    <nav class="flex flex-wrap gap-2" aria-label="حالة المتابعة">
                        <button v-for="state in states" :key="state.id" type="button" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition"
                            :class="(filters.state || '') === state.id ? 'bg-navy-800 text-white' : 'border border-slate-200 text-slate-700 hover:bg-slate-50'"
                            :aria-current="(filters.state || '') === state.id ? 'page' : undefined" @click="open(state.id)">
                            {{ state.label }}
                            <span v-if="state.id" class="rounded-full px-2 py-0.5 text-xs tabular-nums" :class="(filters.state || '') === state.id ? 'bg-white/15' : 'bg-slate-100 text-slate-600'">{{ counts?.[state.id] || 0 }}</span>
                        </button>
                    </nav>
                    <form class="mt-3 flex flex-col gap-2 sm:flex-row" role="search" @submit.prevent="open()">
                        <label class="sr-only" for="decision-search">البحث في القرارات</label>
                        <input id="decision-search" v-model="search" class="field min-w-0 flex-1" placeholder="رقم القرار أو الجلسة أو الموضوع" maxlength="150" />
                        <label class="sr-only" for="decision-department">القسم المكلف</label>
                        <select id="decision-department" v-model="department" class="field sm:w-64">
                            <option value="">كل الأقسام</option>
                            <option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                        <button class="btn btn-secondary shrink-0">بحث</button>
                    </form>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div v-if="records.data.length" class="aims-table-scroll" role="region" aria-label="قرارات المجلس" tabindex="0">
                        <table class="aims-responsive-table data-table w-full text-sm">
                            <thead><tr><th scope="col">القرار</th><th scope="col">الجلسة</th><th scope="col">الموضوع</th><th scope="col">الأقسام المكلفة</th><th scope="col">المتابعة</th></tr></thead>
                            <tbody>
                                <tr v-for="row in records.data" :key="row.public_id" :class="row.overdue ? 'bg-red-50/40' : ''">
                                    <td data-label="القرار"><span class="font-semibold text-navy-800">{{ row.decision_number }}</span><p class="mt-1 text-xs text-slate-500">{{ row.decision_date }}</p></td>
                                    <td data-label="الجلسة">{{ row.meeting_number || '—' }}<p v-if="row.meeting_date" class="mt-1 text-xs text-slate-500">{{ row.meeting_date }}</p></td>
                                    <td data-label="الموضوع" class="break-words">
                                        <Link :href="`/correspondence/${row.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ row.subject }}</Link>
                                        <p class="mt-1 text-xs text-slate-500" dir="ltr">{{ row.reference }}</p>
                                    </td>
                                    <td data-label="الأقسام المكلفة" class="break-words">{{ row.departments || 'لم يُكلَّف قسم بعد' }}</td>
                                    <td data-label="المتابعة">
                                        <StatusBadge :label="row.closed_at ? 'مغلق' : (row.stage || 'قيد المتابعة')" :tone="row.closed_at ? 'success' : (row.overdue ? 'danger' : 'info')" />
                                        <p v-if="!row.closed_at && row.task_status === 'open' && row.holder_name" class="mt-1 text-xs text-slate-600">لدى {{ row.holder_name }}</p>
                                        <p v-if="row.overdue" class="mt-1 text-xs font-semibold text-red-700">متأخر — {{ date(row.due_at) }}</p>
                                        <p v-else-if="!row.closed_at && row.due_at && row.task_status === 'open'" class="mt-1 text-xs text-slate-500">الموعد: {{ date(row.due_at) }}</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-else title="لا توجد قرارات في هذا العرض" description="تظهر هنا قرارات المجلس التي تطّلع عليها بحسب تداولها المعتمد." />
                    <Pagination :page="records" />
                </section>
            </template>
        </div>
    </AppLayout>
</template>
