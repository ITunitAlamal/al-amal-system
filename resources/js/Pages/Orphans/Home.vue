<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import AppIcon from '../../Components/AppIcon.vue';
import ImportIcon from '../../Components/Registry/ImportIcon.vue';
import DistributionBars from '../../Components/Registry/DistributionBars.vue';
import RoleBoards from '../../Components/Dashboard/RoleBoards.vue';
import BoardSkeleton from '../../Components/Dashboard/BoardSkeleton.vue';
import { Deferred } from '@inertiajs/vue3';

const props = defineProps({ mode: String, kpis: Object, charts: Object, actions: Array, workload: Array, mine: Array, recent: Array, quick: Array, definitions: Object, abilities: Object, name: String, boards: { type: Array, default: undefined } });
const oversight = computed(() => props.mode === 'oversight');
</script>

<template>
    <AppLayout title="شؤون الأيتام والكفالات">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="الرئيسية" :description="oversight ? 'نظرة إشرافية على القسم وما يحتاج إلى قرار.' : 'مهامك اليوم وما يحتاج إلى إجراء منك.'">
                <Link v-for="q in quick" :key="q.url" :href="q.url" class="btn btn-secondary" :title="q.hint || q.label"><ImportIcon v-if="q.icon === 'import'" /><AppIcon v-else :name="q.icon" /> {{ q.label }}</Link>
            </PageHeader>

            <p v-if="name" class="text-sm text-slate-600">مساحة عمل {{ name }}</p>
            <Deferred data="boards">
                <template #fallback><BoardSkeleton /></template>
                <RoleBoards v-if="boards && boards.length" :boards="boards" storage-key="aims.orphans.board" />
                <EmptyState v-else title="لا توجد لوحة لدورك في القسم" description="تظهر هنا لوحة القسم بعد إسنادك إلى إحدى وحداته." />
            </Deferred>

            <DistributionBars v-if="oversight && charts" title="التوزيع حسب الفئات العمرية" :rows="charts.age" :definition="definitions.age" />

            <section v-if="workload && workload.length" class="card space-y-3">
                <h2 class="card-title">توزيع العمل ومتابعة الموظفين</h2>
                <p class="text-xs text-slate-500">أرقام فعلية لكل موظف دون ترتيب؛ متوسط الإنجاز يخص طلبات البوابة المنجزة (بالأيام). اضغط على الاسم لفتح ملفاته.</p>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[700px]">
                        <thead><tr><th scope="col">الموظف</th><th scope="col">طلبات مفتوحة</th><th scope="col">طلبات أُنجزت (30 يومًا)</th><th scope="col">متوسط الإنجاز</th><th scope="col">ملفات مسندة</th><th scope="col">زيارات مجدولة</th><th scope="col">متأخرة</th></tr></thead>
                        <tbody><tr v-for="w in workload" :key="w.id">
                            <td><Link :href="`/orphans/registry?assigned=${w.id}`" class="text-blue-700 hover:underline">{{ w.name }}</Link></td>
                            <td class="tabular-nums">{{ w.applications_open }}</td><td class="tabular-nums">{{ w.applications_done }}</td><td class="tabular-nums">{{ w.avg_days ?? '—' }}</td>
                            <td class="tabular-nums">{{ w.files }} <span class="text-xs text-slate-500">(ناقص {{ w.files_incomplete }})</span></td><td class="tabular-nums">{{ w.visits_scheduled }}</td>
                            <td><StatusBadge :label="String(w.overdue)" :tone="w.overdue ? 'danger' : 'muted'" /></td></tr></tbody>
                    </table>
                </div>
            </section>

            <section v-if="recent.length" class="card space-y-2">
                <h2 class="card-title">آخر ما تعاملت معه</h2>
                <ul class="space-y-1 text-sm"><li v-for="(r, i) in recent" :key="i"><Link v-if="r.url" :href="r.url" class="text-blue-700 hover:underline">{{ r.name || 'سجل' }}</Link><span v-else>{{ r.name || 'سجل' }}</span>
                    <span class="text-slate-500"> · {{ r.action }} · {{ r.at?.slice(0, 16) }}</span></li></ul>
            </section>
        </div>
    </AppLayout>
</template>
