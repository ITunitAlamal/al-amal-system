<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import StatusStepper from '../../../Components/StatusStepper.vue';
import { computed } from 'vue';
const props = defineProps({ username: String, departmentName: String, departmentHeadName: String, organizationUrl: String, canRequest: Boolean, isAdmin: Boolean, employees: Array, capabilities: Object, requests: Object, grants: Array, requestUrl: String });
// Presentation only: the existing capabilities shown in a few plain groups. Each checkbox is still one existing
// capability, requested by the head and implemented by the system administrator; nothing is granted as a bundle.
const capabilityGroups = computed(() => {
    const groups = [
        ['عرض السجلات', ['view'], ''],
        ['مراجعة وتعديل البيانات', ['records', 'review', 'verify', 'visits'], 'تشمل «المراجعة وإعادة الاستكمال» تصدير القوائم بالتصفية الحالية.'],
        ['اعتماد المعاملات', ['approve', 'reject'], ''],
        ['إدارة الكفالات والحملات', ['sponsorships', 'campaigns'], ''],
        ['صلاحيات خاصة — تُمنح صراحة فقط', ['sensitive', 'imports'], '«الاطلاع على البيانات … كاملة» وحدها تتيح البحث برقم الهوية وإظهار الهويات في التصدير.'],
    ];
    const known = groups.flatMap(([, codes]) => codes);
    const other = Object.keys(props.capabilities || {}).filter(code => !known.includes(code));
    return [...groups, ...(other.length ? [['أخرى', other, '']] : [])]
        .map(([title, codes, note]) => ({ title, note, codes: codes.filter(code => props.capabilities?.[code]) })).filter(g => g.codes.length);
});
const grantsByEmployee = computed(() => Object.values((props.grants || []).reduce((all, g) => {
    (all[g.name] ||= { name: g.name, codes: [] }).codes.push(g.capability);
    return all;
}, {})));
const page = usePage();
const form = useForm({ user_id: '', operation: 'grant', capabilities: ['view'], reason: '' });
const decision = useForm({ decision: 'implement', note: '' });
const states = { pending: 'بانتظار مدير النظام', implemented: 'منفذ', rejected: 'مرفوض' };
const navigation = [{ label: 'لوحة التحكم', url: '/ui-preview', icon: '▦' }];
function decide(item, action) {
    decision.decision = action;
    decision.post(item.decision_url, { preserveScroll: true, onSuccess: () => decision.reset() });
}
</script>
<template>
    <AppLayout title="تفويضات الكفالات والأيتام">
        <div class="space-y-6">
            <PageHeader eyebrow="الكفالات والأيتام" title="تفويضات الكفالات والأيتام" description="يرشح رئيس القسم موظفًا من قسمه ويحدد صلاحياته، ثم ينفذ مدير النظام الطلب. تسري الصلاحيات بعد التنفيذ فقط.">
                <template #meta><p class="mt-1 text-sm text-slate-500">{{ departmentName || 'لم يُفعّل قسم الكفالات والأيتام في الهيكل الإداري بعد.' }}</p></template>
            </PageHeader>
            <p v-if="departmentName && !departmentHeadName" class="rounded-xl bg-gold-50 p-4 text-sm leading-7 text-gold-900">لم يعيّن رئيس لهذا القسم بعد. يلزم تعيين رئيس القسم وإسناد دور رئيس القسم له قبل إرسال طلبات التفويض. <Link v-if="organizationUrl" :href="organizationUrl" class="font-semibold underline">إدارة الهيكل الإداري</Link></p>
            <form v-if="canRequest" @submit.prevent="form.post(requestUrl, { preserveScroll: true, onSuccess: () => form.reset() })" class="space-y-5 rounded-xl border bg-white p-6">
                <h2 class="text-lg font-bold">طلب تعيين صلاحيات الموظف</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="text-sm">الموظف<select v-model="form.user_id" required class="field mt-2"><option value="" disabled>اختر موظفًا من القسم</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.name }} {{ employee.employee_number ? '— ' + employee.employee_number : '' }}</option></select></label>
                    <label class="text-sm">العملية<select v-model="form.operation" class="field mt-2"><option value="grant">منح صلاحيات</option><option value="revoke">سحب صلاحيات</option></select></label>
                </div>
                <fieldset class="space-y-3"><legend class="mb-1 text-sm font-semibold">الصلاحيات المطلوبة</legend>
                    <div v-for="group in capabilityGroups" :key="group.title" class="rounded-lg border border-slate-200 p-3">
                        <p class="text-sm font-bold text-navy-800">{{ group.title }}</p>
                        <div class="mt-2 flex flex-wrap gap-5"><label v-for="code in group.codes" :key="code" class="flex items-center gap-2 text-sm"><input v-model="form.capabilities" type="checkbox" :value="code" />{{ capabilities[code] }}</label></div>
                        <p v-if="group.note" class="mt-1 text-xs text-slate-500">{{ group.note }}</p>
                    </div>
                </fieldset>
                <p class="text-sm text-slate-500">أرفق العرض عند المنح. سحب العرض يوقف جميع صلاحيات هذا الموظف في إدارة الأوصياء. يمكن طلب تفويض لرئيس القسم نفسه، وينفذه مدير آخر.</p>
                <label class="block text-sm">مبرر الطلب<textarea v-model="form.reason" required minlength="5" maxlength="2000" rows="3" class="field mt-2" /></label>
                <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
                <button :disabled="form.processing" class="btn btn-primary">إرسال إلى مدير النظام</button>
            </form>
            <section class="card p-6">
                <h2 class="mb-4 text-lg font-bold">الصلاحيات النافذة للموظفين الحاليين</h2>
                <p v-if="!grants.length" class="text-sm text-slate-500">لم تنفذ تفويضات نافذة حتى الآن.</p>
                <ul v-else class="divide-y divide-slate-100 text-sm">
                    <li v-for="employee in grantsByEmployee" :key="employee.name" class="py-2"><span class="font-semibold text-navy-800">{{ employee.name }}</span>
                        <span class="block text-slate-600">{{ capabilityGroups.map(g => ({ title: g.title, items: g.codes.filter(c => employee.codes.includes(c)) })).filter(g => g.items.length).map(g => `${g.title}: ${g.items.map(c => capabilities[c]).join('، ')}`).join(' · ') }}</span></li>
                </ul>
            </section>
            <section class="space-y-5 rounded-xl border bg-white p-6">
                <h2 class="text-lg font-bold">سجل طلبات التفويض</h2>
                <p v-if="!requests.data.length" class="text-sm text-slate-500">لا توجد طلبات تفويض بعد.</p>
                <label v-if="isAdmin && requests.data.some(item => item.can_decide)" class="block text-sm">ملاحظة مدير النظام للقرار التالي (إلزامية للرفض)<textarea v-model="decision.note" rows="2" maxlength="2000" class="field mt-2" /></label>
                <p v-for="(error, key) in decision.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
                <article v-for="item in requests.data" :key="item.id" class="space-y-3 rounded-xl border p-5 text-sm">
                    <div class="flex flex-wrap justify-between gap-3"><h3 class="text-sm font-bold text-navy-800">#{{ item.id }} — {{ item.employee_name }}</h3><StatusBadge :label="states[item.status] || item.status" :tone="{ pending: 'info', implemented: 'success', rejected: 'danger' }[item.status] || 'muted'" /></div>
                    <StatusStepper :steps="item.steps" compact />
                    <p>رئيس القسم: {{ item.head_name }} · {{ item.operation === 'grant' ? 'منح' : 'سحب' }}: {{ item.capabilities.map(code => capabilities[code]).join('، ') }}</p>
                    <p class="leading-7">المبرر: {{ item.reason }}</p>
                    <p class="text-slate-500">تاريخ الطلب: {{ item.created_at }}</p>
                    <p v-if="item.admin_name" class="text-slate-600">منفذ القرار: {{ item.admin_name }} · {{ item.handled_at }}</p>
                    <p v-if="item.decision_note" class="leading-7">ملاحظة مدير النظام: {{ item.decision_note }}</p>
                    <div v-if="item.can_decide" class="flex flex-wrap gap-3"><button @click="decide(item, 'implement')" :disabled="decision.processing" class="btn btn-primary">تنفيذ الطلب كما هو</button><button @click="decide(item, 'reject')" :disabled="decision.processing" class="btn btn-danger">رفض الطلب</button></div>
                </article>
                <nav class="flex gap-5 text-sm text-blue-800"><Link v-if="requests.prev_page_url" :href="requests.prev_page_url">السابق</Link><Link v-if="requests.next_page_url" :href="requests.next_page_url">التالي</Link></nav>
            </section>
        </div>
    </AppLayout>
</template>
