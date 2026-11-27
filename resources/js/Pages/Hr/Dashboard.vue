<script setup>
import { computed } from 'vue';
import { Deferred, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import AppIcon from '../../Components/AppIcon.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import Pagination from '../../Components/Pagination.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FollowUpList from '../../Components/Hr/FollowUpList.vue';
import { formatDate } from '../../Support/dates.js';

/**
 * «متابعة القسم»: the requests of the unified department (HR, general services and maintenance) in one list. The
 * filters live in the address (type, holder, status), so a link from the sidebar or the way back from a request
 * opens the same list. The head acts from here; the executive director and the system administrator read it.
 */
const props = defineProps({
    department: { type: String, default: '' },
    readOnly: { type: Boolean, default: false },
    filters: { type: Object, required: true },
    typeOptions: { type: Object, default: () => ({}) },
    holderOptions: { type: Object, default: () => ({}) },
    // «جاهز للإسناد»: a part of "head" opened from the head's home card; shown as a chip only while chosen.
    readyLabel: { type: String, default: 'جاهز للإسناد' },
    statusOptions: { type: Object, default: () => ({}) },
    // Where a service or maintenance request stands (HR requirements §5), with its numbers in summary.stages.
    stageOptions: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    requests: { type: Object, default: () => ({ data: [], total: 0 }) },
    links: { type: Array, default: () => [] },
    // Loaded after the page.
    tasks: { type: Array, default: undefined },
    team: { type: Array, default: undefined },
    documents: { type: Object, default: undefined },
    recentLeaves: { type: Object, default: undefined },
});

const closed = computed(() => props.filters.status === 'closed');
const filtered = computed(() => props.filters.types.length > 0 || Boolean(props.filters.holder) || Boolean(props.filters.stage) || props.filters.status !== 'open');
// The holder numbers count the open requests of the chosen types: shown while the list is that same set.
const holderCounts = computed(() => props.filters.status === 'open');

function address(change = {}) {
    const next = { ...props.filters, ...change };
    const query = { type: next.types.join(','), holder: next.status === 'closed' ? '' : next.holder, stage: next.status === 'closed' ? '' : next.stage, status: next.status === 'open' ? '' : next.status };
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value));
}
function apply(change) {
    router.get('/hr-services', address(change), { preserveScroll: true, preserveState: true });
}
const typeChosen = key => props.filters.types.includes(key);
// One type at a time; choosing the only chosen type again shows every type.
const chooseType = key => apply({ types: props.filters.types.length === 1 && typeChosen(key) ? [] : [key] });
const chooseHolder = key => apply({ holder: props.filters.holder === key ? null : key });
const chooseStage = key => apply({ stage: props.filters.stage === key ? null : key });
function leavesPage(page) {
    router.get('/hr-services', { ...address(), leaves_page: page }, { preserveScroll: true, preserveState: true, only: ['recentLeaves'] });
}
const day = value => formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' }, '—');
</script>

<template>
    <AppLayout title="متابعة القسم">
        <div class="space-y-5">
            <PageHeader :eyebrow="department" title="متابعة القسم"
                :description="readOnly ? 'للاطلاع: طلبات القسم وأين يقف كل منها. الإجراء على كل طلب لصاحب مرحلته.' : 'طلبات القسم في قائمة واحدة: ما لديك، وما أسندته، وما في الميدان، وما ينتظر جهة أخرى.'">
                <Link href="/ui-preview" class="btn btn-secondary btn-sm">العودة إلى مساحتي</Link>
            </PageHeader>

            <section id="services" class="card scroll-mt-24" aria-labelledby="follow-up-title">
                <h2 id="follow-up-title" class="card-title">طلبات القسم</h2>
                <div class="mt-3 space-y-3">
                    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="نوع الطلب">
                        <span class="text-xs font-semibold text-slate-600">النوع</span>
                        <button type="button" class="btn btn-sm" :class="filters.types.length ? 'btn-secondary' : 'btn-primary'" :aria-pressed="!filters.types.length" @click="apply({ types: [] })">كل الأنواع</button>
                        <button v-for="(label, key) in typeOptions" :key="key" type="button" class="btn btn-sm" :class="typeChosen(key) ? 'btn-primary' : 'btn-secondary'"
                            :aria-pressed="typeChosen(key)" @click="chooseType(key)">{{ label }}</button>
                    </div>
                    <div v-if="!closed" class="flex flex-wrap items-center gap-2" role="group" aria-label="لدى من">
                        <span class="text-xs font-semibold text-slate-600">لدى</span>
                        <button type="button" class="btn btn-sm" :class="filters.holder ? 'btn-secondary' : 'btn-primary'" :aria-pressed="!filters.holder" @click="apply({ holder: null })">
                            الكل<template v-if="holderCounts"> {{ summary.total }}</template>
                        </button>
                        <button v-for="(label, key) in holderOptions" :key="key" type="button" class="btn btn-sm" :class="filters.holder === key ? 'btn-primary' : 'btn-secondary'"
                            :aria-pressed="filters.holder === key" @click="chooseHolder(key)">{{ label }}<template v-if="holderCounts"> {{ summary[key] }}</template></button>
                        <button v-if="filters.holder === 'ready'" type="button" class="btn btn-sm btn-primary" aria-pressed="true" @click="apply({ holder: null })">{{ readyLabel }}</button>
                    </div>
                    <div v-if="!closed" class="flex flex-wrap items-center gap-2" role="group" aria-label="مرحلة طلبات الخدمات والصيانة">
                        <span class="text-xs font-semibold text-slate-600">المرحلة</span>
                        <button type="button" class="btn btn-sm" :class="filters.stage ? 'btn-secondary' : 'btn-primary'" :aria-pressed="!filters.stage" @click="apply({ stage: null })">كل المراحل</button>
                        <button v-for="(label, key) in stageOptions" :key="key" type="button" class="btn btn-sm" :class="filters.stage === key ? 'btn-primary' : 'btn-secondary'"
                            :aria-pressed="filters.stage === key" @click="chooseStage(key)">{{ label }}<template v-if="holderCounts"> {{ summary.stages?.[key] ?? 0 }}</template></button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="حالة الطلب">
                        <span class="text-xs font-semibold text-slate-600">الحالة</span>
                        <button v-for="(label, key) in statusOptions" :key="key" type="button" class="btn btn-sm" :class="filters.status === key ? 'btn-primary' : 'btn-secondary'"
                            :aria-pressed="filters.status === key" @click="apply({ status: key })">{{ label }}</button>
                    </div>
                </div>

                <p class="mt-4 text-sm text-slate-600" role="status">
                    النتائج: <strong class="text-navy-800">{{ requests.total }}</strong>
                    <button v-if="filtered" type="button" class="ms-2 font-semibold text-blue-700 hover:underline" @click="apply({ types: [], holder: null, stage: null, status: 'open' })">مسح التصفية</button>
                </p>
                <EmptyState v-if="!requests.total && !filtered" class="mt-3" title="لا توجد أعمال لدى القسم الآن" description="يظهر هنا كل طلب يصل إلى مرحلة من مراحل القسم، ويبقى ظاهرًا حتى يُغلق." />
                <EmptyState v-else-if="!requests.total" class="mt-3" title="لا توجد نتائج مطابقة للتصفية" description="غيّر النوع أو الجهة أو الحالة، أو امسح التصفية لعرض كل أعمال القسم المفتوحة." />
                <template v-else>
                    <FollowUpList class="mt-3" :rows="requests.data" extended caption="طلبات القسم" />
                    <Pagination class="mt-3" :page="requests" />
                </template>
            </section>

            <section class="card" aria-labelledby="team-title">
                <h2 id="team-title" class="card-title">فريق القسم وملفات الموظفين</h2>
                <Deferred data="team">
                    <template #fallback><div class="mt-3 h-24 animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل فريق القسم"></div></template>
                    <p v-if="documents" class="mt-2 text-sm leading-7 text-slate-700">
                        <component :is="documents.expiring_url ? Link : 'span'" :href="documents.expiring_url || undefined" class="hover:underline">وثائق منتهية<template v-if="documents.days !== null"> أو تنتهي خلال {{ documents.days }} يومًا</template>
                            <strong :class="documents.expired + (documents.expiring || 0) ? 'text-gold-800' : 'text-navy-800'">{{ documents.expired + (documents.expiring || 0) }}</strong></component>
                        <template v-if="documents.days === null"> (لم تُحدَّد مدة التنبيه قبل الانتهاء)</template>
                        · <template v-if="documents.missing === null">لم يُحدَّد نوع وثيقة إلزامي في «ثوابت النظام»</template>
                        <component v-else :is="documents.missing_url ? Link : 'span'" :href="documents.missing_url || undefined" class="hover:underline">ملفات ينقصها وثائق إلزامية
                            <strong :class="documents.missing ? 'text-gold-800' : 'text-navy-800'">{{ documents.missing }}</strong></component>
                    </p>
                    <p v-if="!team?.length" class="mt-3 text-sm text-slate-600">لا توجد مهام مفتوحة لدى موظفي القسم.</p>
                    <table v-else class="mt-3 w-full text-right text-sm">
                        <caption class="sr-only">المهام المفتوحة لكل موظف في القسم</caption>
                        <thead><tr class="bg-navy-50 text-xs font-semibold text-navy-800"><th scope="col" class="rounded-s-lg px-3 py-2">الموظف</th><th scope="col" class="px-3 py-2">مهام مفتوحة</th><th scope="col" class="px-3 py-2">متأخرة</th><th scope="col" class="rounded-e-lg px-3 py-2">أقدمها منذ</th></tr></thead>
                        <tbody>
                            <tr v-for="member in team" :key="member.name" class="border-t border-slate-100">
                                <td class="px-3 py-2 font-semibold text-navy-800">{{ member.name }}</td>
                                <td class="px-3 py-2">{{ member.open }}</td>
                                <td class="px-3 py-2" :class="member.overdue ? 'font-semibold text-red-700' : ''">{{ member.overdue }}</td>
                                <td class="px-3 py-2">{{ day(member.oldest) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </Deferred>
            </section>

            <section class="card" aria-labelledby="people-tasks-title">
                <h2 id="people-tasks-title" class="card-title">مهام موظفي القسم</h2>
                <Deferred data="tasks">
                    <template #fallback><div class="mt-3 h-32 animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل مهام موظفي القسم"></div></template>
                    <p v-if="!tasks?.length" class="mt-3 text-sm text-slate-600">لا توجد مهام مفتوحة لدى موظفي القسم.</p>
                    <ul v-else class="mt-3 divide-y divide-slate-100">
                        <li v-for="(task, index) in tasks" :key="index" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 py-2 text-sm">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <StatusBadge :label="task.status" :tone="task.overdue ? 'danger' : 'info'" />
                                    <span dir="ltr" class="text-xs font-semibold text-slate-600">{{ task.reference }}</span>
                                    <span class="font-semibold text-navy-800">{{ task.title }}</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-600">لدى: {{ task.assignee }}<template v-if="task.priority"> · الأولوية: {{ task.priority }}</template> · المهلة: {{ task.due_at ? day(task.due_at) : 'بلا مهلة' }}</p>
                            </div>
                            <Link v-if="task.url" :href="task.url" class="btn btn-secondary btn-sm"><AppIcon name="file-text" :size="16" /> عرض</Link>
                        </li>
                    </ul>
                </Deferred>
            </section>

            <section class="card" aria-labelledby="leaves-title">
                <h2 id="leaves-title" class="card-title">أحدث طلبات الإجازات</h2>
                <Deferred data="recentLeaves">
                    <template #fallback><div class="mt-3 h-32 animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل طلبات الإجازات"></div></template>
                    <p v-if="!recentLeaves?.data?.length" class="mt-3 text-sm text-slate-600">لا توجد طلبات إجازة بعد.</p>
                    <ul v-else class="mt-3 divide-y divide-slate-100">
                        <li v-for="(leave, index) in recentLeaves.data" :key="index" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 py-2 text-sm">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <StatusBadge :label="leave.status" tone="info" />
                                    <span class="font-semibold text-navy-800">{{ leave.employee }}</span>
                                    <span v-if="leave.department" class="text-xs text-slate-500">{{ leave.department }}</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-600">
                                    {{ leave.type }} · {{ day(leave.start_date) }} ← {{ day(leave.end_date) }} · قُدّم {{ day(leave.submitted_at) }}<template v-if="leave.holders"> · لدى {{ leave.holders }}</template>
                                </p>
                            </div>
                            <Link :href="leave.url" class="btn btn-secondary btn-sm"><AppIcon name="file-text" :size="16" /> عرض</Link>
                        </li>
                    </ul>
                    <nav v-if="recentLeaves?.last_page > 1" aria-label="صفحات الإجازات" class="mt-3 flex items-center justify-between gap-3 text-sm">
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="recentLeaves.current_page <= 1" @click="leavesPage(recentLeaves.current_page - 1)">السابق</button>
                        <span class="text-slate-600">صفحة {{ recentLeaves.current_page }} من {{ recentLeaves.last_page }}</span>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="recentLeaves.current_page >= recentLeaves.last_page" @click="leavesPage(recentLeaves.current_page + 1)">التالي</button>
                    </nav>
                </Deferred>
            </section>

            <nav v-if="links.length" class="card" aria-labelledby="department-links-title">
                <h2 id="department-links-title" class="card-title">روابط القسم</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Link v-for="link in links" :key="link.url" :href="link.url" class="btn btn-secondary btn-sm"><AppIcon :name="link.icon || 'folder'" :size="16" /> {{ link.label }}</Link>
                </div>
            </nav>
        </div>
    </AppLayout>
</template>
