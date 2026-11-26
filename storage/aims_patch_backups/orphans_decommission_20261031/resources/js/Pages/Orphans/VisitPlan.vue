<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// One visit plan: progress per researcher, its visits, and (for the head) adding families and distributing them.
const props = defineProps({
    plan: { type: Object, required: true },
    progress: { type: Object, required: true },
    candidates: { type: Array, default: () => [] },
    researchers: { type: Array, default: () => [] },
    visitTypes: { type: Array, default: () => [] },
    canManage: Boolean,
});
const tone = { upcoming: 'info', current: 'warning', ended: 'muted' };
const visitTone = { planned: 'info', submitted: 'warning', returned: 'warning', reviewed: 'success', cancelled: 'muted' };
const canAssign = computed(() => props.canManage && props.plan.state !== 'ended');

// Families are pre-selected when their follow-up falls due within the plan period.
const rows = reactive(Object.fromEntries(props.candidates.map(c => [c.public_id, { selected: c.due_in_plan, researcher_id: null, scheduled_on: '' }])));
const chosen = ref(props.researchers.map(r => r.id));
const filter = ref('');
const shown = computed(() => {
    const term = filter.value.trim();
    return term ? props.candidates.filter(c => `${c.reference_no} ${c.family_name || ''}`.includes(term)) : props.candidates;
});
const selectedCount = computed(() => Object.values(rows).filter(r => r.selected).length);
function selectAll(value) { shown.value.forEach(c => { rows[c.public_id].selected = value; }); }
const form = useForm({ researchers: [], rows: [], visit_type_value_id: null, reason: '' });
function assign() {
    form.transform(data => ({
        ...data,
        researchers: chosen.value,
        rows: Object.entries(rows).filter(([, r]) => r.selected).map(([household, r]) => ({
            household, researcher_id: r.researcher_id && chosen.value.includes(r.researcher_id) ? r.researcher_id : null, scheduled_on: r.scheduled_on || null,
        })),
    })).post(`/orphans/visit-plans/${props.plan.public_id}/visits`, { preserveScroll: true });
}
const researcherName = id => props.researchers.find(r => r.id === id)?.name;
</script>

<template>
    <AppLayout :title="`خطة الزيارات ${plan.reference_no}`">
        <div class="space-y-4">
            <Link href="/orphans/visit-plans" class="text-sm font-semibold text-blue-700 hover:underline">→ خطط الزيارات</Link>
            <PageHeader eyebrow="خطة زيارات ميدانية" :title="`${plan.reference_no} — ${plan.title}`" :description="`من ${plan.starts_on} إلى ${plan.ends_on} — أعدها: ${plan.author}`">
                <template #meta><div class="mt-2"><StatusBadge :label="plan.state_label" :tone="tone[plan.state]" /></div></template>
            </PageHeader>
            <p v-if="plan.notes" class="card whitespace-pre-line text-sm">{{ plan.notes }}</p>

            <div class="grid gap-3 sm:grid-cols-5">
                <div class="card"><p class="text-xs text-slate-500">الزيارات</p><p class="text-2xl font-bold">{{ progress.totals.total }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">معتمدة</p><p class="text-2xl font-bold">{{ progress.totals.reviewed }} <span class="text-sm font-normal text-slate-500">({{ progress.totals.completion }}٪)</span></p></div>
                <div class="card"><p class="text-xs text-slate-500">بانتظار المراجعة</p><p class="text-2xl font-bold">{{ progress.totals.submitted + progress.totals.returned }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">لم تُنفذ بعد</p><p class="text-2xl font-bold">{{ progress.totals.planned }}</p></div>
                <div class="card"><p class="text-xs text-slate-500">متأخرة عن موعدها</p><p class="text-2xl font-bold" :class="{ 'text-red-700': progress.totals.overdue }">{{ progress.totals.overdue }}</p></div>
            </div>

            <section class="card space-y-2">
                <h2 class="card-title">التقدم حسب الباحث</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الباحث</th><th>الزيارات</th><th>لم تُنفذ</th><th>مرفوعة</th><th>معادة</th><th>معتمدة</th><th>متأخرة</th><th>الإنجاز</th></tr></thead>
                        <tbody>
                            <tr v-for="r in progress.researchers" :key="r.id" class="border-t border-slate-100">
                                <td class="py-2 font-semibold">{{ r.name }}</td><td>{{ r.total }}</td><td>{{ r.planned }}</td><td>{{ r.submitted }}</td><td>{{ r.returned }}</td>
                                <td>{{ r.reviewed }}</td><td :class="{ 'font-semibold text-red-700': r.overdue }">{{ r.overdue }}</td><td>{{ r.completion }}٪</td>
                            </tr>
                            <tr v-if="!progress.researchers.length"><td colspan="8" class="py-4 text-center text-slate-500">لم تُوزع زيارات بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="progress.visits.length" class="card space-y-2">
                <h2 class="card-title">زيارات الخطة</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="v in progress.visits" :key="v.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><Link :href="`/orphans/visits/${v.id}`" class="font-semibold text-blue-700 hover:underline">{{ v.reference_no }}</Link> —
                            <Link :href="`/orphans/households/${v.household_public_id}`" class="hover:underline">ملف {{ v.household }}</Link> — {{ v.researcher }} — <span dir="ltr">{{ v.scheduled_on }}</span>
                            <span v-if="v.cancel_reason" class="text-xs text-slate-500"> (سبب الإلغاء: {{ v.cancel_reason }})</span></span>
                        <StatusBadge :label="v.overdue ? 'متأخرة عن موعدها' : v.status_label" :tone="v.overdue ? 'danger' : visitTone[v.status]" />
                    </li>
                </ul>
                <p v-if="canManage" class="text-xs text-slate-500">لإعادة إسناد زيارة لم يبدأ تقريرها أو إلغائها افتح الزيارة.</p>
            </section>

            <form v-if="canAssign" class="card space-y-3" data-error-scope @submit.prevent="assign">
                <h2 class="card-title">إضافة أسر للخطة وتوزيعها</h2>
                <FormErrorSummary :errors="form.errors" />
                <p class="text-xs text-slate-500">تظهر هنا ملفات الأسر قيد الدراسة أو النشطة التي ليس لها زيارة جارية، وتُقدَّم التي تحل متابعتها خلال فترة الخطة.
                    الصف بلا باحث يوزَّع تلقائيًا: يبقى مع من يتابع الأسرة ما دام نصيبه لا يتجاوز حصته العادلة، وإلا يذهب للأقل عبئًا. الصف بلا موعد يأخذ موعد المتابعة داخل الفترة.</p>
                <fieldset class="space-y-1 text-sm">
                    <legend class="field-label">الباحثون المشاركون *</legend>
                    <p v-if="!researchers.length" class="text-amber-700">لا يوجد في القسم باحث يملك تفويض الزيارات.</p>
                    <label v-for="r in researchers" :key="r.id" class="me-4 inline-flex items-center gap-2"><input v-model="chosen" type="checkbox" :value="r.id"> {{ r.name }} <span class="text-xs text-slate-500">(زيارات مفتوحة: {{ r.open_visits }})</span></label>
                </fieldset>
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <label class="block"><span class="field-label">نوع الزيارة</span><select v-model="form.visit_type_value_id" class="field"><option :value="null">—</option><option v-for="o in visitTypes" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                    <label class="block"><span class="field-label">سبب الزيارة (للأسر بلا متابعة مفتوحة)</span><input v-model="form.reason" class="field" maxlength="1000" placeholder="يُستخدم عنوان الخطة إن تُرك فارغًا"></label>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <input v-model="filter" class="field max-w-xs" maxlength="60" placeholder="بحث برقم الملف أو اسم العائلة" aria-label="بحث في الأسر">
                    <button type="button" class="btn btn-secondary btn-sm" @click="selectAll(true)">تحديد الظاهر</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="selectAll(false)">إلغاء التحديد</button>
                    <span class="text-slate-500">المختار: {{ selectedCount }}</span>
                </div>
                <div class="max-h-[32rem] overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-white text-right text-xs text-slate-500"><tr><th class="py-2"><span class="sr-only">اختيار</span></th><th>الملف</th><th>المتابعة</th><th>آخر زيارة معتمدة</th><th>الباحث</th><th>الموعد</th></tr></thead>
                        <tbody>
                            <tr v-for="c in shown" :key="c.public_id" class="border-t border-slate-100">
                                <td class="py-2"><input v-model="rows[c.public_id].selected" type="checkbox" :aria-label="`اختيار الملف ${c.reference_no}`"></td>
                                <td>{{ c.reference_no }} — {{ c.family_name }}</td>
                                <td><template v-if="c.due_on"><StatusBadge :label="c.due_on" :tone="c.due_in_plan ? 'warning' : 'muted'" /> <span class="text-xs text-slate-500">{{ c.assignee }}</span></template><span v-else class="text-xs text-slate-500">لا توجد</span></td>
                                <td><span dir="ltr">{{ c.last_visit || '—' }}</span></td>
                                <td><select v-model="rows[c.public_id].researcher_id" class="field" :aria-label="`باحث الملف ${c.reference_no}`"><option :value="null">تلقائي</option>
                                    <option v-for="id in chosen" :key="id" :value="id">{{ researcherName(id) }}</option></select></td>
                                <td><input v-model="rows[c.public_id].scheduled_on" type="date" class="field" :min="plan.starts_on" :max="plan.ends_on" :aria-label="`موعد زيارة الملف ${c.reference_no}`"></td>
                            </tr>
                            <tr v-if="!shown.length"><td colspan="6" class="py-4 text-center text-slate-500">لا توجد أسر متاحة للإضافة.</td></tr>
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-primary btn-sm" :disabled="form.processing || !selectedCount || !chosen.length">إنشاء {{ selectedCount }} زيارة وتوزيعها</button>
            </form>
        </div>
    </AppLayout>
</template>
