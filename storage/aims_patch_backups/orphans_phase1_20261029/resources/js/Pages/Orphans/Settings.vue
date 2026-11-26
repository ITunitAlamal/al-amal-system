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
    can: { type: Object, default: () => ({}) },
});
const policy = useForm({
    eligibility_max_age: props.settings.eligibility_max_age, eligibility_age_status: props.settings.eligibility_age_status,
    manual_evidence_review_enabled: props.settings.manual_evidence_review_enabled, sponsorship_max_active_per_child: props.settings.sponsorship_max_active_per_child,
    campaign_cooling_off_days: props.settings.campaign_cooling_off_days, sponsorship_grace_days: props.settings.sponsorship_grace_days ?? 30, field_offline_max_hours: props.settings.field_offline_max_hours,
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
                </div>
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
        </div>
    </AppLayout>
</template>
