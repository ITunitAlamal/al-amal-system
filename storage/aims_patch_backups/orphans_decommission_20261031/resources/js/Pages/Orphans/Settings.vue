<script setup>
import { reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

// Policy values decided by the executive director, technical values set by the system admin, and the versioned
// field rules and document requirements.
const props = defineProps({
    settings: { type: Object, required: true },
    formVersion: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    requirements: { type: Array, default: () => [] },
    labels: { type: Object, default: () => ({}) },
    retention: { type: Array, default: () => [] },
    retentionOptions: { type: Object, default: () => ({ starts: {}, actions: {} }) },
    can: { type: Object, default: () => ({}) },
});
// Retention per data category (executive director): a recorded policy for review; nothing is deleted automatically.
const retentionRows = reactive(Object.fromEntries(props.retention.map(row => [row.id, { retention_months: row.retention_months, starts_from: row.starts_from, action_after: row.action_after, legal_basis: row.legal_basis || '' }])));
const retentionErrors = reactive({});
function saveRetention(row) {
    router.put(`/orphans/settings/retention/${row.id}`, retentionRows[row.id], { preserveScroll: true, onError: errors => { retentionErrors[row.id] = Object.values(errors).join(' — '); }, onSuccess: () => { retentionErrors[row.id] = ''; } });
}
const policy = useForm({
    eligibility_max_age: props.settings.eligibility_max_age, eligibility_age_status: props.settings.eligibility_age_status,
    manual_evidence_review_enabled: props.settings.manual_evidence_review_enabled, sponsorship_max_active_per_child: props.settings.sponsorship_max_active_per_child,
    campaign_cooling_off_days: props.settings.campaign_cooling_off_days, sponsorship_grace_days: props.settings.sponsorship_grace_days ?? 30,
    document_expiry_alert_days: props.settings.document_expiry_alert_days ?? 30, field_offline_max_hours: props.settings.field_offline_max_hours,
    followup_interval_months: props.settings.followup_interval_months ?? null, followup_reminder_days: props.settings.followup_reminder_days ?? 7,
    followup_escalation_days: props.settings.followup_escalation_days ?? 7,
    field_session_hours: props.settings.field_session_hours, field_lock_minutes: props.settings.field_lock_minutes,
});
const rules = reactive(JSON.parse(JSON.stringify(props.formVersion.rules)));
const formNotes = useForm({ rules: {}, notes: '' });
function publish() { formNotes.rules = rules; formNotes.post('/orphans/settings/forms', { preserveScroll: true }); }
const forms = { child: 'الطفل', household: 'الأسرة', father: 'الأب', mother: 'الأم', guardian: 'الوصي', education: 'التعليم', health: 'الصحة' };
const targets = { child: 'الطفل', household: 'الأسرة', guardian: 'الوصي', father: 'الأب', mother: 'الأم' };
</script>

<template>
    <AppLayout title="إعدادات قسم الكفالات">
        <div class="space-y-4">
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="الإعدادات والسياسات" description="قيم السياسة قرار للمدير التنفيذي؛ القيم الفنية لمدير النظام؛ كل تغيير مسجل." />
            <form class="card space-y-3" @submit.prevent="policy.put('/orphans/settings/policy', { preserveScroll: true })">
                <h2 class="card-title">السياسات (المدير التنفيذي)</h2>
                <div class="grid gap-3 sm:grid-cols-2 text-sm">
                    <label><span class="field-label">حد العمر للأهلية (سنة)</span><input v-model.number="policy.eligibility_max_age" type="number" min="1" max="30" class="field" :disabled="!can.policy"></label>
                    <label><span class="field-label">حالة اعتماد حد العمر</span><select v-model="policy.eligibility_age_status" class="field" :disabled="!can.policy"><option value="pending_approval">قاعدة سابقة بانتظار الاعتماد</option><option value="approved">معتمد</option></select></label>
                    <label class="flex items-center gap-2 sm:col-span-2"><input v-model="policy.manual_evidence_review_enabled" type="checkbox" :disabled="!can.policy"> السماح بالتحقق اليدوي الموثق عند عدم توفر السجل المدني (قرار المالك الحالي: غير مسموح)</label>
                    <label><span class="field-label">أقصى عدد كفالات نشطة للطفل (فارغ = بلا حد)</span><input v-model.number="policy.sponsorship_max_active_per_child" type="number" min="1" class="field" :disabled="!can.policy"></label>
                    <label><span class="field-label">فترة التهدئة بين مساعدات الحملات (يوم، فارغ = غير مقررة)</span><input v-model.number="policy.campaign_cooling_off_days" type="number" min="1" class="field" :disabled="!can.policy"></label>
                    <label><span class="field-label">مهلة اعتبار الكفالة «غير منتظمة» بعد الاستحقاق (يوم؛ للعرض فقط)</span><input v-model.number="policy.sponsorship_grace_days" type="number" min="0" max="365" class="field" :disabled="!can.policy"></label>
                    <label v-if="settings.document_expiry_alert_days !== undefined"><span class="field-label">التنبيه قبل انتهاء المستند (يوم؛ تنبيه فقط)</span><input v-model.number="policy.document_expiry_alert_days" type="number" min="1" max="365" class="field" :disabled="!can.policy"></label>
                </div>
                <template v-if="settings.followup_reminder_days !== undefined">
                    <h3 class="font-semibold text-sm">متابعة الحالات</h3>
                    <div class="grid gap-3 sm:grid-cols-3 text-sm">
                        <label><span class="field-label">دورية مراجعة الملف النشط (شهر؛ فارغ = غير مقررة)</span><input v-model.number="policy.followup_interval_months" type="number" min="1" max="24" class="field" :disabled="!can.policy"></label>
                        <label><span class="field-label">تذكير المسؤول قبل الموعد (يوم)</span><input v-model.number="policy.followup_reminder_days" type="number" min="1" max="60" class="field" :disabled="!can.policy"></label>
                        <label><span class="field-label">تصعيد لرئيس القسم بعد التأخر (يوم)</span><input v-model.number="policy.followup_escalation_days" type="number" min="1" max="90" class="field" :disabled="!can.policy"></label>
                    </div>
                    <p class="text-xs text-slate-500">يحدد رئيس القسم دورية مختلفة للحالة الحساسة من ملف الأسرة. بلا دورية مقررة لا تُجدول المراجعة التالية تلقائيًا إلا من تاريخ يوصي به تقرير الزيارة.</p>
                </template>
                <h2 class="card-title pt-2">جهاز التوزيع الميداني (مدير النظام)</h2>
                <div class="grid gap-3 sm:grid-cols-3 text-sm">
                    <label><span class="field-label">أقصى عمل دون اتصال (ساعة، حتى 168)</span><input v-model.number="policy.field_offline_max_hours" type="number" min="1" max="168" class="field" :disabled="!can.technical"></label>
                    <label><span class="field-label">صلاحية الجلسة المتصلة (ساعة)</span><input v-model.number="policy.field_session_hours" type="number" min="1" max="24" class="field" :disabled="!can.technical"></label>
                    <label><span class="field-label">قفل الجهاز بعد خمول (دقيقة)</span><input v-model.number="policy.field_lock_minutes" type="number" min="1" max="60" class="field" :disabled="!can.technical"></label>
                </div>
                <p v-for="(m, k) in policy.errors" :key="k" class="field-error">{{ m }}</p>
                <button v-if="can.policy || can.technical" class="btn btn-primary btn-sm" :disabled="policy.processing">حفظ</button>
            </form>

            <section class="card space-y-3">
                <h2 class="card-title">قواعد الحقول — الإصدار {{ formVersion.version }}</h2>
                <p class="text-xs text-slate-500">الحقول الأساسية للهوية والسلامة مقفلة. التعديل ينشر إصدارًا جديدًا ويبقي الطلبات السابقة على إصدارها.</p>
                <details v-for="(fields, form) in rules" :key="form" class="rounded-lg border border-slate-200 p-3 text-sm">
                    <summary class="cursor-pointer font-semibold">{{ forms[form] || form }}</summary>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label v-for="(rule, field) in fields" :key="field" class="flex items-center justify-between gap-2">
                            <span>{{ labels[field] || field }}</span>
                            <select v-model="rule.req" class="field w-36" :disabled="!can.forms"><option value="required">إلزامي</option><option value="optional">اختياري</option><option v-if="rule.when" value="conditional">شرطي</option><option value="hidden">مخفي</option></select>
                        </label>
                    </div>
                </details>
                <div v-if="can.forms" class="flex flex-wrap gap-2"><input v-model="formNotes.notes" class="field flex-1" maxlength="1000" placeholder="سبب التعديل (إلزامي)"><button type="button" class="btn btn-primary btn-sm" :disabled="formNotes.processing" @click="publish">نشر إصدار جديد</button></div>
                <p v-for="(m, k) in formNotes.errors" :key="k" class="field-error">{{ m }}</p>
            </section>

            <section class="card space-y-2">
                <h2 class="card-title">متطلبات المستندات</h2>
                <table class="w-full text-sm"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">المستند</th><th>يخص</th><th>الشرط</th><th>الإلزام</th><th>مفعّل</th></tr></thead>
                    <tbody><tr v-for="r in requirements" :key="r.id" class="border-t border-slate-100">
                        <td class="py-2">{{ r.type }}</td><td>{{ targets[r.applies_to] }}</td><td class="text-xs">{{ r.note || '—' }}</td>
                        <td><select :value="r.requirement" class="field w-28" :disabled="!can.forms" @change="router.put(`/orphans/settings/requirements/${r.id}`, { requirement: $event.target.value, is_active: r.is_active }, { preserveScroll: true })"><option value="required">مطلوب</option><option value="optional">اختياري</option></select></td>
                        <td><input type="checkbox" :checked="r.is_active" :disabled="!can.forms" @change="router.put(`/orphans/settings/requirements/${r.id}`, { requirement: r.requirement, is_active: $event.target.checked }, { preserveScroll: true })"></td>
                    </tr></tbody></table>
            </section>

            <section v-if="retention.length" class="card space-y-2">
                <h2 class="card-title">سياسة الاحتفاظ بالبيانات (المدير التنفيذي)</h2>
                <p class="text-xs text-slate-500">مدة الاحتفاظ لكل فئة بيانات وسندها. هي سياسة مسجلة تُستخدم لمراجعة السجلات، ولا يحذف النظام أو يخفي أي سجل آليًا. المدة الفارغة تعني أنها لم تُقرر بعد.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">فئة البيانات</th><th>المدة (شهر)</th><th>تُحسب</th><th>بعد انقضائها</th><th>السند أو المرجع</th><th></th></tr></thead>
                        <tbody><tr v-for="r in retention" :key="r.id" class="border-t border-slate-100 align-top">
                            <td class="py-2"><span class="font-semibold">{{ r.label }}</span>
                                <span v-if="r.retention_months === null" class="block text-xs text-amber-700">لم تُقرر</span>
                                <span v-else-if="r.updated_by" class="block text-xs text-slate-500">{{ r.updated_by }}</span>
                                <span v-if="retentionErrors[r.id]" class="field-error block">{{ retentionErrors[r.id] }}</span></td>
                            <td><input v-model.number="retentionRows[r.id].retention_months" type="number" min="1" max="1200" class="field w-24" :disabled="!can.policy" :aria-label="`مدة الاحتفاظ: ${r.label}`"></td>
                            <td><select v-model="retentionRows[r.id].starts_from" class="field w-36" :disabled="!can.policy"><option v-for="(label, key) in retentionOptions.starts" :key="key" :value="key">{{ label }}</option></select></td>
                            <td><select v-model="retentionRows[r.id].action_after" class="field w-40" :disabled="!can.policy"><option v-for="(label, key) in retentionOptions.actions" :key="key" :value="key">{{ label }}</option></select></td>
                            <td><input v-model="retentionRows[r.id].legal_basis" class="field min-w-[12rem]" maxlength="1000" :disabled="!can.policy" placeholder="مطلوب عند تحديد مدة"></td>
                            <td><button v-if="can.policy" type="button" class="btn btn-secondary btn-sm" @click="saveRetention(r)">حفظ</button></td>
                        </tr></tbody></table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
