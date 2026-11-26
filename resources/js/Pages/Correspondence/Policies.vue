<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
const props = defineProps({ policies: Array, settings: Object, departments: Array, types: Array, assignments: Array });
const today = new Date().toLocaleDateString('en-CA');
const form = useForm({ ...props.settings, kind: 'settings', starts_on: today, ends_on: '', approval_reference: '', is_active: true });
const cross = useForm({ kind: 'cross_head', starts_on: today, ends_on: '', approval_reference: '', is_active: true, from_department_id: '', to_department_id: '', type_value_id: '', purpose: '' });
const appointment = useForm({ assignment: '', starts_on: today, ends_on: '', decision_reference: '' });
const escalation = useForm({ kind: 'escalation', starts_on: today, ends_on: '', approval_reference: '', is_active: false, head_after_hours: '', executive_after_hours: '' });
const policyKinds = { settings: 'الترقيم والمرفقات والقالب', cross_head: 'استثناء الإحالة', escalation: 'تصعيد المتابعة' };
const escalationActive = computed(() => {
    const latestByKey = new Map();
    for (const policy of props.policies) {
        if (policy.kind === 'escalation' && policy.starts_on <= today && !latestByKey.has(policy.policy_key)) {
            latestByKey.set(policy.policy_key, policy);
        }
    }

    return [...latestByKey.values()].some(policy => policy.is_active && (!policy.ends_on || policy.ends_on >= today));
});
</script>
<template>
    <AppLayout title="سياسات المراسلات">
        <div dir="rtl" class="mx-auto max-w-screen-2xl space-y-6">
            <PageHeader title="سياسات المراسلات والتكليفات" description="تُحفظ كل سياسة بإصدار مستقل ومرجع اعتماد. حفظ إصدار جديد لا يغيّر القرارات السابقة ولا يضيف أدوارًا أو صلاحيات اعتماد." />
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link href="/correspondence" class="btn btn-secondary">العودة إلى المراسلات</Link>
                <p class="text-sm text-slate-500">يُسجل النظام منشئ كل إصدار ومرجع اعتماده في سجل التدقيق.</p>
            </div>
            <p v-if="!settings.confirmed" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="status">حدود المرفقات الحالية تحفظية وتحتاج اعتمادًا إداريًا؛ لا تمثل سياسة معتمدة بعد.</p>

            <section>
                <div class="mb-4">
                    <h2 class="text-lg font-bold text-navy-900">إعدادات السجل والتداول</h2>
                    <p class="mt-1 text-sm text-slate-500">إصدارات الترقيم والملفات والقالب التنظيمي للمراسلة.</p>
                </div>
                <form class="card space-y-5" @submit.prevent="form.post('/correspondence/policies')">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <label class="block text-sm font-semibold">بادئة الرقم
                            <input v-model="form.prefix" class="form-input mt-1 w-full" required />
                        </label>
                        <label class="block text-sm font-semibold">بداية السريان
                            <input v-model="form.starts_on" type="date" class="form-input mt-1 w-full" required />
                        </label>
                    </div>
                    <fieldset class="rounded-xl border border-slate-200 p-4">
                        <legend class="px-2 text-sm font-bold text-navy-800">حدود المرفقات</legend>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="text-sm">عدد الملفات<input v-model="form.file_count" type="number" class="form-input mt-1 w-full" min="1" max="25" required /></label>
                            <label class="text-sm">حجم الملف بالكيلوبايت<input v-model="form.file_kb" type="number" class="form-input mt-1 w-full" min="1" required /></label>
                            <label class="text-sm">إجمالي المجموعة بالكيلوبايت<input v-model="form.total_kb" type="number" class="form-input mt-1 w-full" min="1" required /></label>
                        </div>
                        <fieldset class="mt-4">
                            <legend class="mb-2 text-sm font-semibold">الأنواع المسموحة</legend>
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                <label v-for="ext in ['pdf','png','jpg','jpeg','txt']" :key="ext" class="inline-flex items-center gap-2 text-sm"><input v-model="form.allowed_extensions" type="checkbox" :value="ext" /> {{ ext }}</label>
                            </div>
                        </fieldset>
                    </fieldset>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm font-semibold">ترويسة الكتاب<input v-model="form.letterhead" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm font-semibold">بيان المصدر<input v-model="form.signature_label" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm font-semibold">مرجع اعتماد السياسة<input v-model="form.approval_reference" class="form-input mt-1 w-full" required /></label>
                    </div>
                    <div v-if="Object.keys(form.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                        <p class="font-bold">تعذر حفظ الإصدار:</p><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        <p class="text-sm text-slate-500">حفظ السياسة ينشئ إصدارًا جديدًا ولا يستبدل سجل الإصدارات السابقة.</p>
                        <button class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ إصدار السياسة' }}</button>
                    </div>
                </form>
            </section>

            <section class="grid items-start gap-5 xl:grid-cols-2">
                <form class="card space-y-4" @submit.prevent="cross.post('/correspondence/policies')">
                    <div>
                        <h2 class="font-bold text-navy-900">استثناء إحالة بين رئيسَي قسم</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">الاستثناء اتجاه واحد ومراسلات داخلية لنوع محدد؛ لا يمنح اعتمادًا على أعمال قسم آخر.</p>
                    </div>
                    <div v-if="Object.keys(cross.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert"><p v-for="(error, key) in cross.errors" :key="key">{{ error }}</p></div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm font-semibold">من القسم<select v-model="cross.from_department_id" class="form-input mt-1 w-full" required><option value="">اختر</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
                        <label class="block text-sm font-semibold">إلى القسم<select v-model="cross.to_department_id" class="form-input mt-1 w-full" required><option value="">اختر</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></label>
                        <label class="block text-sm font-semibold lg:col-span-2">نوع الكتاب<select v-model="cross.type_value_id" class="form-input mt-1 w-full" required><option value="">اختر</option><option v-for="t in types" :key="t.id" :value="t.id">{{ t.name_ar }}</option></select></label>
                        <label class="block text-sm font-semibold lg:col-span-2">الغرض<textarea v-model="cross.purpose" class="form-input mt-1 w-full" rows="3" required maxlength="1000"></textarea></label>
                        <label class="block text-sm font-semibold lg:col-span-2">مرجع الاعتماد<input v-model="cross.approval_reference" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm">بداية السريان<input v-model="cross.starts_on" type="date" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm">نهاية السريان<input v-model="cross.ends_on" type="date" class="form-input mt-1 w-full" required /></label>
                    </div>
                    <label class="flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-sm"><input v-model="cross.is_active" class="mt-1" type="checkbox" /> الاستثناء نشط؛ إلغاء التحديد ينشئ إصدار تعطيل.</label>
                    <button class="btn btn-primary" :disabled="cross.processing">{{ cross.processing ? 'جارٍ الحفظ…' : 'حفظ إصدار الاستثناء' }}</button>
                </form>

                <form class="card space-y-4" @submit.prevent="appointment.put(`/correspondence/appointments/${appointment.assignment}`)">
                    <div>
                        <h2 class="font-bold text-navy-900">سريان تكليف قائم للمراسلات</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">يُنشأ تعيين الدور من إدارة المستخدمين أولًا. لا تُمنح أدوار جديدة هنا؛ هذه التواريخ تحدد صلاحية التكليف في المراسلات.</p>
                    </div>
                    <div v-if="Object.keys(appointment.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert"><p v-for="(error, key) in appointment.errors" :key="key">{{ error }}</p></div>
                    <label class="block text-sm font-semibold">التكليف
                        <select v-model="appointment.assignment" class="form-input mt-1 w-full" required><option value="">اختر التكليف</option><option v-for="a in assignments" :key="a.id" :value="a.id">{{ a.name }} — {{ a.role_name }} — {{ a.department_name }} — {{ a.starts_on }} / {{ a.ends_on }}</option></select>
                    </label>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm">بداية السريان<input v-model="appointment.starts_on" type="date" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm">نهاية السريان<input v-model="appointment.ends_on" type="date" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm font-semibold lg:col-span-2">مرجع قرار التكليف<input v-model="appointment.decision_reference" class="form-input mt-1 w-full" required /></label>
                    </div>
                    <button class="btn btn-primary" :disabled="appointment.processing">{{ appointment.processing ? 'جارٍ الحفظ…' : 'حفظ سريان التكليف' }}</button>
                </form>

                <form class="card space-y-4" @submit.prevent="escalation.post('/correspondence/policies')">
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-bold text-navy-900">تصعيد المتابعة المتأخرة</h2>
                            <StatusBadge :label="escalationActive ? 'مفعّلة' : 'معطّلة'" :tone="escalationActive ? 'success' : 'muted'" />
                        </div>
                        <p class="mt-1 text-sm leading-6 text-slate-600">معطل افتراضيًا حتى اعتماد المدد. يصل التنبيه للمخولين بقراءة المراسلة فقط، دون نصها أو مرفقاتها.</p>
                    </div>
                    <div v-if="Object.keys(escalation.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert"><p v-for="(error, key) in escalation.errors" :key="key">{{ error }}</p></div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="block text-sm">ساعات حتى تنبيه رئيس القسم<input v-model="escalation.head_after_hours" type="number" min="1" max="8760" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm">ساعات حتى تنبيه المدير التنفيذي<input v-model="escalation.executive_after_hours" type="number" min="2" max="17520" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm font-semibold lg:col-span-2">مرجع الاعتماد<input v-model="escalation.approval_reference" class="form-input mt-1 w-full" required /></label>
                        <label class="block text-sm">بداية السريان<input v-model="escalation.starts_on" type="date" class="form-input mt-1 w-full" required /></label>
                    </div>
                    <label class="flex items-center gap-2 rounded-lg bg-slate-50 p-3 text-sm"><input v-model="escalation.is_active" type="checkbox" /> تفعيل سياسة التصعيد المعتمدة</label>
                    <button class="btn btn-primary" :disabled="escalation.processing">{{ escalation.processing ? 'جارٍ الحفظ…' : 'حفظ إصدار التصعيد' }}</button>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="font-bold text-navy-900">سجل إصدارات السياسات</h2>
                        <p class="mt-1 text-sm text-slate-500">آخر {{ policies.length }} إصدارًا؛ تبقى الإصدارات السابقة محفوظة للتدقيق.</p>
                    </div>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-start text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-600"><tr><th class="px-4 py-3 text-start">السياسة</th><th class="px-4 py-3 text-start">الإصدار</th><th class="px-4 py-3 text-start">السريان</th><th class="px-4 py-3 text-start">مرجع الاعتماد</th><th class="px-4 py-3 text-start">الحالة</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in policies" :key="p.id" class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-800">{{ policyKinds[p.kind] || p.policy_key }}</td>
                                <td class="px-4 py-3 tabular-nums">#{{ p.version }}</td>
                                <td class="px-4 py-3 tabular-nums">{{ p.starts_on }} — {{ p.ends_on || 'مستمر' }}</td>
                                <td class="px-4 py-3">{{ p.approval_reference || 'إعداد تحفظي' }}</td>
                                <td class="px-4 py-3"><StatusBadge :label="p.is_active ? 'نشطة' : 'معطّلة'" :tone="p.is_active ? 'success' : 'muted'" /></td>
                            </tr>
                            <tr v-if="!policies.length"><td colspan="5" class="px-4 py-8 text-center text-slate-500">لا توجد إصدارات سياسات مسجلة.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
