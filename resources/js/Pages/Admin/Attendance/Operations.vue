<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed, toRef } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PunchTimeReview from '../../../Components/Attendance/PunchTimeReview.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    canApproveImport: Boolean,
    decidableCorrections: { type: Array, default: () => [] },
    unmatched: { type: Array, default: () => [] },
    time_review: { type: Array, default: () => [] },
    sync_runs: { type: Array, default: () => [] },
    mappings: { type: Array, default: () => [] },
    grace_minutes: { type: Number, default: null },
    daily: { type: Array, default: () => [] },
    correction_punches: { type: Array, default: () => [] },
    corrections: { type: Array, default: () => [] },
});
const correctionForm = useForm({ corrected_direction: 'in', reason: 'اختيار خروج بدل دخول بالخطأ' });
const decisionForm = useForm({ decision: 'approve', review_note: '' });
const revokeForm = useForm({ reason: '' });
const correction_punches = toRef(props, 'correction_punches');
const searchPunch = ref('');
const displayPunches = computed(() => {
    const term = searchPunch.value.trim();
    return correction_punches.value.filter(p => !term || [p.first_name_ar, p.family_name_ar, p.employee_number].some(x => String(x ?? '').includes(term)));
});
function requestCorrection(p) {
    if (!window.confirm(`إرسال طلب تصحيح الحركة #${p.id} إلى ${correctionForm.corrected_direction === 'in' ? 'دخول' : 'خروج'}؟`)) return;
    correctionForm.post(`/admin/attendance/punches/${p.id}/correction`, { preserveScroll: true });
}
function decideCorrection(c, decision) {
    if (!window.confirm(`تأكيد ${decision === 'approve' ? 'اعتماد' : 'رفض'} التصحيح #${c.id}؟`)) return;
    decisionForm.decision = decision;
    decisionForm.post(`/admin/attendance/corrections/${c.id}/decision`, { preserveScroll: true });
}
function revokeCorrection(c) {
    const reason = window.prompt('سبب إلغاء التصحيح المعتمد (10 أحرف على الأقل):');
    if (!reason) return;
    revokeForm.reason = reason;
    revokeForm.post(`/admin/attendance/corrections/${c.id}/revoke`, { preserveScroll: true });
}
const review = useForm({ reason: '' });
function resolveUnmatched(event) {
    const reason = window.prompt('سبب المراجعة بعد تصحيح الرقم الوظيفي في ملف الموظف:');
    if (!reason) return;
    review.reason = reason;
    review.post(`/admin/attendance/raw-punches/${event.id}/resolve`, { preserveScroll: true });
}
const approval = useForm({ run_id: null, confirmed: false });
function approveImport(run) {
    if (!window.confirm(`اعتماد استيراد الحركات الخام للجهاز #${run.device_id}؟ المعاينة: ${run.received} مستلمة، ${run.inserted} جديدة، ${run.unmatched} غير مطابقة. لا يشمل الاعتماد احتساب الرواتب أو التأخير.`)) return;
    approval.run_id = run.id;
    approval.confirmed = true;
    approval.post(`/admin/attendance/devices/${run.device_id}/approve-import`, { preserveScroll: true });
}
const statusLabels = { success: 'ناجحة', failed: 'فاشلة', running: 'قيد التنفيذ', completed: 'مكتملة', present: 'حاضر', absent: 'غائب', late: 'متأخر' };
</script>

<template>
    <AppLayout title="عمليات الحضور والأجهزة">
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHeader eyebrow="الحضور والانصراف" title="عمليات الحضور والأجهزة" description="متابعة المزامنة وربط معرّفات الأجهزة ونتائج الحضور اليومية.">
                <Link href="/admin/attendance" class="btn btn-secondary">إدارة الحضور والأجهزة</Link>
            </PageHeader>
            <section class="card space-y-3">
                <h2 class="card-title">تصحيح اتجاه البصمة — طلب وقرار رئيس قسم الموارد البشرية</h2>
                <p>بيانات جهاز البصمة الأصلية محفوظة دون تعديل. ابحث باسم الموظف ضمن أحدث 100 حركة، ثم قدم طلب تصحيح مبررًا.</p>
                <input v-model="searchPunch" class="form-input w-full" placeholder="ابحث باسم الموظف" aria-label="البحث باسم الموظف" />
                <div class="flex flex-wrap gap-3 items-center">
                    <label>الاتجاه المقترح
                        <select v-model="correctionForm.corrected_direction" class="form-input"><option value="in">دخول</option><option value="out">خروج</option></select>
                    </label>
                    <label class="flex-1">سبب الطلب
                        <input v-model="correctionForm.reason" class="form-input w-full" maxlength="1000" aria-label="سبب التصحيح" />
                    </label>
                </div>
                <p v-for="(error, key) in correctionForm.errors" :key="key" class="field-error">{{error}}</p>
                <div class="max-h-72 overflow-auto">
                    <table class="w-full text-right text-sm"><thead><tr><th>الموظف</th><th>وقت الحركة</th><th>الاتجاه الأصلي</th><th>الإجراء</th></tr></thead>
                    <tbody><tr v-for="p in displayPunches" :key="p.id"><td>{{p.first_name_ar}} {{p.family_name_ar}}</td><td>{{p.occurred_at}}</td><td>{{p.direction}}</td><td><button class="btn btn-secondary" :disabled="correctionForm.processing" @click="requestCorrection(p)">طلب تصحيح</button></td></tr></tbody></table>
                </div>
                <h3 class="font-semibold">طلبات التصحيح (آخر 100)</h3>
                <label class="block">ملاحظات قرار الإدارة (اختيارية)<textarea v-model="decisionForm.review_note" class="form-input w-full" maxlength="1000" rows="2" placeholder="ملاحظات الاعتماد أو الرفض"></textarea></label>
                <p v-for="(error, key) in decisionForm.errors" :key="key" class="field-error">{{error}}</p>
                <p v-for="(error, key) in revokeForm.errors" :key="key" class="field-error">{{error}}</p>
                <div class="max-h-80 overflow-auto"><table class="w-full text-right text-sm"><thead><tr><th>رقم</th><th>البصمة</th><th>الأصلي</th><th>المطلوب</th><th>الحالة</th><th>السبب</th><th>قرار الإدارة</th></tr></thead>
                    <tbody><tr v-for="c in corrections" :key="c.id"><td>{{c.id}}</td><td>{{c.punch_id}}</td><td>{{c.original_direction}}</td><td>{{c.corrected_direction}}</td><td>{{c.status}}</td><td>{{c.reason}}</td><td>
                        <template v-if="decidableCorrections.includes(c.id) && c.status === 'pending'"><button class="btn btn-secondary" :disabled="decisionForm.processing" @click="decideCorrection(c,'approve')">اعتماد</button> <button class="btn btn-secondary" :disabled="decisionForm.processing" @click="decideCorrection(c,'reject')">رفض</button></template>
                        <button v-if="decidableCorrections.includes(c.id) && c.status === 'approved'" class="btn btn-secondary" :disabled="revokeForm.processing" @click="revokeCorrection(c)">إلغاء الاعتماد</button>
                        <span v-else-if="c.status === 'pending' && !decidableCorrections.includes(c.id)" class="text-xs text-slate-500">يقرره رئيس قسم الموارد البشرية، أو المدير التنفيذي إن كان رئيس القسم مقدمه أو صاحب البصمة.</span>
                    </td></tr></tbody></table></div>
            </section>
            <section class="card space-y-3">
                <h2 class="card-title">الحركات غير المطابقة — أول 100 للمراجعة</h2>
                <p>طابق المعرّف مع الرقم الوظيفي الأصلي دون حذف الأصفار. صحح البيانات عبر إدارة الموظفين؛ لا تُنشأ حسابات تلقائيًا.</p>
                <p v-if="!unmatched.length">لا توجد حركات غير مطابقة.</p>
                <div v-for="event in unmatched" :key="event.id">الجهاز #{{event.device_id}} — المعرّف {{event.raw_user_id}} — {{event.device_local_time}} ({{event.device_timezone}}) <button class="btn btn-secondary" :disabled="review.processing" @click="resolveUnmatched(event)">إعادة المطابقة بعد التصحيح</button></div>
                <p v-for="(error, key) in review.errors" :key="key" class="field-error">{{error}}</p>
                <p v-for="(error, key) in approval.errors" :key="key" class="field-error">{{error}}</p>
            </section>
            <PunchTimeReview :events="time_review" />
            <section class="card">
                <h2 class="card-title">فترة السماح بالتأخير</h2>
                <p>{{ grace_minutes === null ? 'لم تُعتمد فترة السماح بعد.' : `${grace_minutes} دقيقة` }}</p>
            </section>
            <section class="card space-y-3">
                <h2 class="card-title">آخر 30 عملية مزامنة</h2>
                <EmptyState v-if="!sync_runs.length" title="لا توجد عمليات مزامنة مسجلة" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead><tr><th>رقم الجهاز</th><th>الحالة</th><th>وقت البدء</th><th>المستلمة</th><th>الجديدة / المتوقعة</th><th>المكررة</th><th>غير المطابقة</th><th>الفاشلة</th><th>الإجراء</th></tr></thead>
                        <tbody><tr v-for="run in sync_runs" :key="run.id"><td>{{ run.device_id }}</td><td>{{ statusLabels[run.status] || run.status }}</td><td>{{ run.started_at }}</td><td>{{ run.received }}</td><td>{{ run.inserted }}</td><td>{{ run.duplicates ?? '—' }}</td><td>{{ run.unmatched ?? '—' }}</td><td>{{ run.failed ?? '—' }}</td><td><span v-if="run.dry_run">معاينة فقط</span><button v-if="canApproveImport && run.dry_run && run.status==='success'" class="btn btn-secondary" :disabled="approval.processing" @click="approveImport(run)">اعتماد الاستيراد الخام</button></td></tr></tbody>
                    </table>
                </div>
            </section>
            <section class="card space-y-3">
                <h2 class="card-title">ربط الموظفين بالأجهزة</h2>
                <EmptyState v-if="!mappings.length" title="لا توجد معرّفات أجهزة مرتبطة بالموظفين" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead><tr><th>رقم الجهاز</th><th>معرّف الموظف</th><th>معرّفه على الجهاز</th></tr></thead>
                        <tbody><tr v-for="mapping in mappings" :key="mapping.id"><td>{{ mapping.device_id }}</td><td>{{ mapping.employee_id }}</td><td>{{ mapping.device_user_id }}</td></tr></tbody>
                    </table>
                </div>
            </section>
            <section class="card space-y-3">
                <h2 class="card-title">آخر 100 نتيجة حضور يومية</h2>
                <EmptyState v-if="!daily.length" title="لا توجد نتائج حضور يومية مسجلة" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead><tr><th>اليوم</th><th>معرّف الموظف</th><th>الحالة</th><th>التأخير بالدقائق</th><th>الخروج المبكر بالدقائق</th></tr></thead>
                        <tbody><tr v-for="day in daily" :key="day.id"><td>{{ day.work_date }}</td><td>{{ day.employee_id }}</td><td>{{ statusLabels[day.status] || day.status }}</td><td>{{ day.late_minutes ?? '—' }}</td><td>{{ day.early_minutes ?? '—' }}</td></tr></tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
