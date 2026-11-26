<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

// Actionable records of a board: text filter, "late only", column sorting and paging on the rows the server already
// scoped for this user (at most a few dozen). Cards on phones (aims-responsive-table), the action stays visible.
const props = defineProps({ table: { type: Object, required: true } });
const PER_PAGE = 10;
const query = ref('');
const lateOnly = ref(false);
const sortKey = ref('');
const sortDir = ref(1);
const page = ref(1);
const hasLate = computed(() => props.table.rows.some(r => r.overdue));
const filtered = computed(() => {
    const q = query.value.trim();
    let rows = props.table.rows.filter(r => (!lateOnly.value || r.overdue) && (!q || Object.values(r.cells).some(v => String(v ?? '').includes(q)) || (r.status?.label || '').includes(q)));
    if (sortKey.value) rows = [...rows].sort((a, b) => String(a.cells[sortKey.value] ?? '').localeCompare(String(b.cells[sortKey.value] ?? ''), 'ar', { numeric: true }) * sortDir.value);
    return rows;
});
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)));
const shown = computed(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));
watch([query, lateOnly], () => { page.value = 1; });
function sort(key) { sortDir.value = sortKey.value === key ? -sortDir.value : 1; sortKey.value = key; }
const ariaSort = key => (sortKey.value === key ? (sortDir.value === 1 ? 'ascending' : 'descending') : 'none');
</script>

<template>
    <section class="card space-y-3" :aria-label="table.title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="card-title">{{ table.title }} <span class="text-sm font-normal text-slate-500">({{ table.rows.length }})</span></h3>
            <Link v-if="table.more" :href="table.more" class="text-sm font-semibold text-blue-700 hover:underline">عرض الكل</Link>
        </div>
        <div v-if="table.rows.length > 5" class="flex flex-wrap items-center gap-3">
            <label class="sr-only" :for="`q-${table.title}`">بحث في الجدول</label>
            <input :id="`q-${table.title}`" v-model="query" type="search" class="field max-w-xs" placeholder="بحث في الجدول">
            <label v-if="hasLate" class="flex items-center gap-2 text-sm"><input v-model="lateOnly" type="checkbox" class="h-4 w-4"> المتأخرة فقط</label>
        </div>
        <p v-if="!table.rows.length" class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">{{ table.empty }}</p>
        <div v-else-if="!filtered.length" class="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
            لا توجد نتائج مطابقة للفلاتر.
            <button type="button" class="btn btn-sm btn-secondary" @click="query = ''; lateOnly = false">مسح الفلاتر</button>
        </div>
        <div v-else class="aims-table-scroll">
            <table class="data-table aims-responsive-table">
                <thead><tr>
                    <th v-for="c in table.columns" :key="c.key" scope="col" :aria-sort="ariaSort(c.key)">
                        <button type="button" class="font-semibold hover:text-blue-700" @click="sort(c.key)">{{ c.label }}<span v-if="sortKey === c.key" aria-hidden="true"> {{ sortDir === 1 ? '▲' : '▼' }}</span></button></th>
                    <th scope="col">الحالة</th><th scope="col"><span class="sr-only">الإجراء</span></th>
                </tr></thead>
                <tbody>
                    <tr v-for="(r, i) in shown" :key="i" :class="r.overdue ? 'bg-red-50/40' : ''">
                        <td v-for="c in table.columns" :key="c.key" :data-label="c.label" class="max-w-xs break-words">{{ r.cells[c.key] ?? '—' }}</td>
                        <td data-label="الحالة"><StatusBadge v-if="r.status" :label="r.status.label" :tone="r.status.tone" /></td>
                        <td data-label="الإجراء"><Link v-if="r.url" :href="r.url" class="btn btn-sm btn-secondary whitespace-nowrap">{{ r.action || 'فتح' }}</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav v-if="pages > 1" class="flex items-center justify-between gap-2 text-sm" aria-label="صفحات الجدول">
            <button type="button" class="btn btn-sm btn-secondary" :disabled="page === 1" @click="page--">السابق</button>
            <span class="tabular-nums text-slate-600">صفحة {{ page }} من {{ pages }}</span>
            <button type="button" class="btn btn-sm btn-secondary" :disabled="page === pages" @click="page++">التالي</button>
        </nav>
    </section>
</template>
