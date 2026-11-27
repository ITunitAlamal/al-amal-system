<script setup>
import { computed, defineAsyncComponent, ref } from 'vue';
import { Deferred, Link, router } from '@inertiajs/vue3';
import AppIcon from '../AppIcon.vue';
import PageHeader from '../PageHeader.vue';
import StatusBadge from '../StatusBadge.vue';
import WaitingTasks from '../Dashboard/WaitingTasks.vue';
import MyRequestsSummary from '../Dashboard/MyRequestsSummary.vue';
import DepartmentBudgetSummary from '../Finance/DepartmentBudgetSummary.vue';
import FollowUpList from './FollowUpList.vue';
import { formatDate, formatDateTime } from '../../Support/dates.js';
import { waitingBadge } from '../../Support/waitingTime.js';

// The day detail of the attendance ledger, loaded only when the head opens a case.
const AttendanceDayDetail = defineAsyncComponent(() => import('../Attendance/AttendanceDayDetail.vue'));

/**
 * The home page of the head of the unified department (HrHomeDashboard), in reading order: ① «بانتظار إجرائي»
 * (the same list and number as «مهامي»), ② «جاهز للإسناد» (a marked part of ①), ③ «متابعة أعمال القسم» (what
 * does not wait for the head), ④ «حضور اليوم وإجازاته» and the department's budget, ⑤ «طلباتي», ⑥ the department's
 * links. The blocks arrive after the page; on small screens they stack in the same order.
 */
const props = defineProps({
    today: { type: String, required: true },
    hrActions: { type: Object, default: undefined },
    hrFollowUp: { type: Object, default: undefined },
    hrSummary: { type: Object, default: undefined },
    departmentBudgets: { type: Object, default: undefined },
    myRequests: { type: Object, default: undefined },
    links: { type: Array, default: () => [] },
});

const todayLabel = computed(() => formatDate(props.today, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
const refreshing = ref(false);
const refreshError = ref('');
function refresh() {
    if (refreshing.value) return;
    refreshing.value = true;
    refreshError.value = '';
    let succeeded = false;
    router.reload({
        only: ['hrActions', 'hrFollowUp', 'hrSummary', 'departmentBudgets', 'myRequests'],
        onSuccess: () => { succeeded = true; },
        onFinish: () => {
            refreshing.value = false;
            if (!succeeded) refreshError.value = 'تعذّر التحديث. البيانات المعروضة هي آخر نسخة مستلمة؛ حاول مجددًا.';
        },
    });
}

// ③ «متابعة أعمال القسم»: one kind at a time; the first kind that has work opens.
const kinds = [
    { key: 'assigned', label: 'إسناد رقمي', empty: 'لا توجد مراحل مسندة إلى موظف الآن.' },
    { key: 'field', label: 'في الميدان', empty: 'لا يوجد عمل ميداني بانتظار التوثيق.' },
    { key: 'other', label: 'بانتظار جهة أخرى', empty: 'لا توجد طلبات للقسم لدى جهة أخرى الآن.' },
];
const chosen = ref(null);
const kind = computed(() => chosen.value ?? kinds.find(item => props.hrFollowUp?.summary?.[item.key] > 0)?.key ?? 'assigned');
const kindInfo = computed(() => kinds.find(item => item.key === kind.value));
const kindRows = computed(() => props.hrFollowUp?.lists?.[kind.value] ?? []);
const kindCount = computed(() => props.hrFollowUp?.summary?.[kind.value] ?? 0);

// ④ «حضور اليوم»: one case open at a time, in the ledger's own day detail.
const openCase = ref(null);
const caseKey = row => `${row.employee_id}|${row.work_date}`;
const toggleCase = row => { openCase.value = openCase.value === caseKey(row) ? null : caseKey(row); };
const reloadDay = () => router.reload({ only: ['hrSummary'] });
const time = value => {
    if (!value) return '—';
    const text = String(value);
    return /[ T]/.test(text) ? text.slice(11, 16) : text.slice(0, 5);
};
const attendanceTone = code => ({ missing_punches: 'danger', incomplete_punches: 'danger' }[code] || 'warning');
</script>

<template>
    <div class="space-y-4">
        <PageHeader title="مساحتي" description="ما ينتظر إجراءك أولًا، ثم ما تتابعه لدى فريق القسم.">
            <template #meta>
                <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    <time :datetime="today">{{ todayLabel }}</time>
                    <span v-if="hrSummary?.updatedAt">آخر تحديث <bdi>{{ formatDateTime(hrSummary.updatedAt) }}</bdi></span>
                </p>
            </template>
            <Link href="/requests" class="btn btn-primary btn-sm"><AppIcon name="plus" :size="16" /> طلب جديد</Link>
            <Link href="/ui-preview?workspace=personal" class="btn btn-secondary btn-sm">خدماتي الشخصية</Link>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="refreshing" :aria-busy="refreshing" @click="refresh"><AppIcon name="refresh" :size="16" /> {{ refreshing ? 'جارٍ التحديث…' : 'تحديث' }}</button>
        </PageHeader>
        <p v-if="refreshError" class="alert-error" role="alert">{{ refreshError }}</p>

        <!-- ① and ② -->
        <Deferred data="hrActions">
            <template #fallback><div class="card h-48 motion-safe:animate-pulse bg-slate-50" role="status" aria-label="جارٍ تحميل ما ينتظر إجراءك"></div></template>
            <template #rescue><p class="alert-error" role="alert">تعذر تحميل مهامك. <button type="button" class="underline" @click="refresh">أعد المحاولة</button></p></template>
            <div v-if="hrActions" class="space-y-4">
                <WaitingTasks :waiting="hrActions" />
                <section v-if="hrActions.ready" class="card" aria-labelledby="ready-title">
                    <h2 id="ready-title" class="text-lg font-bold text-navy-800">جاهز للإسناد ({{ hrActions.ready }} من {{ hrActions.total }})</h2>
                    <p class="mt-1 text-sm text-slate-600">جزء من «بانتظار إجرائي»: مراحل القسم التي ما زالت لديك ويمكن إسنادها.</p>
                    <ul class="mt-4 space-y-2">
                        <li v-for="item in hrActions.readyItems" :key="item.key" class="rounded-xl border border-slate-200 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                                <p class="flex min-w-0 flex-wrap items-center gap-2">
                                    <StatusBadge v-if="waitingBadge(item)" :label="waitingBadge(item).label" :tone="waitingBadge(item).tone" />
                                    <StatusBadge v-if="item.urgent" label="عاجل" tone="warning" />
                                    <span dir="ltr" class="text-xs font-semibold text-slate-600">{{ item.reference }}</span>
                                    <span class="font-semibold text-navy-800">{{ item.title }}</span>
                                </p>
                                <span class="flex flex-wrap gap-2">
                                    <Link :href="item.assignUrl" class="btn btn-primary btn-sm"><AppIcon name="user-plus" :size="16" /> إسناد إلى موظف</Link>
                                    <Link v-if="item.fieldUrl" :href="item.fieldUrl" class="btn btn-secondary btn-sm"><AppIcon name="wrench" :size="16" /> تسجيل تكليف ميداني</Link>
                                </span>
                            </div>
                            <p class="mt-2 text-xs leading-6 text-slate-600">
                                {{ item.kind }} · المرحلة: <strong class="text-navy-700">{{ item.step }}</strong>
                                <template v-if="item.requester"> · مقدم الطلب: {{ item.requester }}</template>
                                <template v-if="item.department"> · {{ item.department }}</template>
                            </p>
                        </li>
                    </ul>
                    <p v-if="hrActions.ready > hrActions.readyItems.length" class="mt-3 text-xs text-slate-600">
                        يعرض أول {{ hrActions.readyItems.length }} من {{ hrActions.ready }}؛ <Link href="/hr-services?holder=ready" class="font-semibold text-blue-700 hover:underline">البقية في «متابعة القسم»</Link>
                    </p>
                    <p v-if="hrActions.candidates.length" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs leading-6 text-slate-700">
                        <strong class="text-navy-800">المؤهلون الآن:</strong>
                        {{ hrActions.candidates.map(person => `${person.name} (${person.open_tasks} لديه)`).join(' · ') }}
                    </p>
                </section>
            </div>
        </Deferred>

        <!-- ③ -->
        <section class="card" aria-labelledby="follow-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="follow-title" class="text-lg font-bold text-navy-800">متابعة أعمال القسم</h2>
                    <p class="mt-1 text-sm text-slate-600">ما لا ينتظر قرارك: لدى موظف، في الميدان، أو لدى جهة أخرى.</p>
                </div>
                <Link href="/hr-services" class="btn btn-secondary btn-sm"><AppIcon name="chart" :size="16" /> فتح متابعة القسم</Link>
            </div>
            <Deferred data="hrFollowUp">
                <template #fallback><div class="mt-4 h-32 motion-safe:animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل متابعة أعمال القسم"></div></template>
                <template #rescue><p class="alert-error mt-4" role="alert">تعذر تحميل المتابعة. <button type="button" class="underline" @click="refresh">أعد المحاولة</button></p></template>
                <template v-if="hrFollowUp">
                    <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="من يحمل العمل">
                        <button v-for="item in kinds" :key="item.key" type="button" class="btn btn-sm" :class="kind === item.key ? 'btn-primary' : 'btn-secondary'"
                            :aria-pressed="kind === item.key" @click="chosen = item.key">{{ item.label }} {{ hrFollowUp.summary[item.key] }}</button>
                    </div>
                    <p v-if="kind === 'field' && kindCount" class="mt-3 text-xs text-slate-600">هذه المراحل لديك أيضًا في «بانتظار إجرائي»؛ تُعرض هنا للمتابعة ولا تُضاف إلى أي مجموع.</p>
                    <p v-if="!kindRows.length" class="mt-4 rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-600">{{ kindInfo.empty }}</p>
                    <FollowUpList v-else class="mt-3" :rows="kindRows" :caption="`متابعة أعمال القسم: ${kindInfo.label}`" />
                    <p v-if="kindCount > kindRows.length" class="mt-3 text-xs text-slate-600">
                        يعرض أول {{ kindRows.length }} من {{ kindCount }}؛ <Link :href="`/hr-services?holder=${kind}`" class="font-semibold text-blue-700 hover:underline">البقية في «متابعة القسم»</Link>
                    </p>
                </template>
            </Deferred>
        </section>

        <!-- ④ -->
        <section class="card" aria-labelledby="day-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h2 id="day-title" class="text-lg font-bold text-navy-800">حضور اليوم وإجازاته</h2>
                <Link href="/admin/attendance" class="btn btn-secondary btn-sm"><AppIcon name="calendar" :size="16" /> الحضور والانصراف</Link>
            </div>
            <Deferred data="hrSummary">
                <template #fallback><div class="mt-4 h-24 motion-safe:animate-pulse rounded-xl bg-slate-50" role="status" aria-label="جارٍ تحميل حضور اليوم"></div></template>
                <template #rescue><p class="alert-error mt-4" role="alert">تعذر تحميل حضور اليوم. <button type="button" class="underline" @click="refresh">أعد المحاولة</button></p></template>
                <template v-if="hrSummary">
                    <p class="mt-2 text-sm leading-7 text-slate-700">
                        <template v-if="hrSummary.scheduled !== null">الحضور <strong class="text-navy-800">{{ hrSummary.attended }} من {{ hrSummary.scheduled }}</strong></template>
                        <template v-else>لم تُحتسب نتائج حضور اليوم بعد</template>
                        · تحتاج مراجعة <strong :class="hrSummary.review ? 'text-gold-800' : 'text-navy-800'">{{ hrSummary.review }}</strong>
                        · في إجازة <strong class="text-navy-800">{{ hrSummary.on_leave }}</strong>
                        · <Link :href="hrSummary.leave_tasks_url" class="hover:underline">إجازات تنتظر إجرائي <strong class="text-navy-800">{{ hrSummary.leave_tasks }}</strong></Link>
                        <template v-if="hrSummary.staff_files !== undefined">
                            · <Link v-if="hrSummary.staff_files_url" :href="hrSummary.staff_files_url" class="hover:underline">ملفات موظفين تحتاج مراجعة <strong :class="hrSummary.staff_files ? 'text-gold-800' : 'text-navy-800'">{{ hrSummary.staff_files }}</strong></Link>
                            <template v-else>ملفات موظفين تحتاج مراجعة <strong class="text-navy-800">{{ hrSummary.staff_files }}</strong></template>
                        </template>
                        <template v-if="hrSummary.file_changes_url">
                            · <Link :href="hrSummary.file_changes_url" class="hover:underline">ملفات أعدها موظفو القسم بانتظار اعتمادك <strong :class="hrSummary.file_changes ? 'text-gold-800' : 'text-navy-800'">{{ hrSummary.file_changes }}</strong></Link>
                        </template>
                        <template v-if="hrSummary.documents">
                            · <component :is="hrSummary.documents.expiring_url ? Link : 'span'" :href="hrSummary.documents.expiring_url || undefined" class="hover:underline">وثائق منتهية<template v-if="hrSummary.documents.days !== null"> أو تنتهي خلال {{ hrSummary.documents.days }} يومًا</template>
                                <strong :class="hrSummary.documents.expired + (hrSummary.documents.expiring || 0) ? 'text-gold-800' : 'text-navy-800'">{{ hrSummary.documents.expired + (hrSummary.documents.expiring || 0) }}</strong></component>
                            <template v-if="hrSummary.documents.missing !== null">
                                · <component :is="hrSummary.documents.missing_url ? Link : 'span'" :href="hrSummary.documents.missing_url || undefined" class="hover:underline">ملفات ينقصها وثائق إلزامية
                                    <strong :class="hrSummary.documents.missing ? 'text-gold-800' : 'text-navy-800'">{{ hrSummary.documents.missing }}</strong></component>
                            </template>
                        </template>
                    </p>
                    <template v-if="hrSummary.cases.length">
                        <h3 class="mt-3 text-sm font-semibold text-navy-800">{{ hrSummary.review > hrSummary.cases.length ? `أول ${hrSummary.cases.length} حالات تحتاج مراجعة` : 'حالات تحتاج مراجعة' }}</h3>
                        <ul class="mt-2 divide-y divide-slate-100">
                            <li v-for="row in hrSummary.cases" :key="caseKey(row)" class="py-2 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                                    <p class="flex min-w-0 flex-wrap items-center gap-2">
                                        <StatusBadge :label="row.status" :tone="attendanceTone(row.status_code)" />
                                        <span class="font-semibold text-navy-800">{{ row.employee }}</span>
                                        <span v-if="row.department" class="text-xs text-slate-500">{{ row.department }}</span>
                                    </p>
                                    <button type="button" class="btn btn-secondary btn-sm" :aria-expanded="openCase === caseKey(row)" @click="toggleCase(row)">
                                        <AppIcon name="calendar" :size="16" /> {{ openCase === caseKey(row) ? 'إغلاق يوم الموظف' : 'فتح يوم الموظف' }}
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-slate-600">
                                    المتوقع {{ time(row.expected_in) }}–{{ time(row.expected_out) }} · الفعلي {{ time(row.actual_in) }}–{{ time(row.actual_out) }}
                                    <template v-if="row.late_minutes"> · تأخير {{ row.late_minutes }} د</template>
                                    <template v-if="row.early_minutes"> · خروج مبكر {{ row.early_minutes }} د</template>
                                </p>
                                <AttendanceDayDetail v-if="openCase === caseKey(row)" class="mt-2" :url="`/admin/attendance/employee-ledger/${row.employee_id}/${row.work_date}`" @changed="reloadDay" />
                            </li>
                        </ul>
                    </template>
                    <p v-else-if="hrSummary.scheduled !== null" class="mt-2 text-sm text-slate-600">لا توجد حالات حضور تحتاج مراجعة اليوم.</p>
                </template>
            </Deferred>
        </section>

        <Deferred data="departmentBudgets">
            <template #fallback><div class="card h-20 motion-safe:animate-pulse bg-slate-50" role="status" aria-label="جارٍ تحميل ميزانية القسم"></div></template>
            <template #rescue><p class="alert-error" role="alert">تعذر تحميل ميزانية القسم. <button type="button" class="underline" @click="refresh">أعد المحاولة</button></p></template>
            <DepartmentBudgetSummary :summary="departmentBudgets" />
        </Deferred>

        <!-- ⑤ -->
        <Deferred data="myRequests">
            <template #fallback><div class="card h-14 motion-safe:animate-pulse bg-slate-50" role="status" aria-label="جارٍ تحميل طلباتي"></div></template>
            <MyRequestsSummary v-if="myRequests" :requests="myRequests.latest" :stats="myRequests" />
        </Deferred>

        <!-- ⑥ -->
        <nav v-if="links.length" class="card" aria-labelledby="links-title">
            <h2 id="links-title" class="text-lg font-bold text-navy-800">روابط القسم</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                <Link v-for="link in links" :key="link.url" :href="link.url" class="btn btn-secondary btn-sm"><AppIcon :name="link.icon || 'folder'" :size="16" /> {{ link.label }}</Link>
            </div>
        </nav>
    </div>
</template>
