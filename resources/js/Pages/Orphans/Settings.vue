<script setup>
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ capabilities: Object, grants: Array, staff: Array, settings: Array, canGrant: Boolean, department: Object, devices: Array, serverKey: Object, canManageDevices: Boolean, importPolicy: Object });
const policy = useForm({ levels: Object.fromEntries((props.importPolicy?.configurable || []).map(p => [p.key, p.level])) });
function device(d, action) { const reason = action === 'approve' ? null : window.prompt('سبب الإلغاء:'); if (action !== 'approve' && !reason) return; router.post(`/orphans/settings/devices/${d.id}`, { action, reason }, { preserveScroll: true }); }
const deviceLabels = { pending: ['بانتظار الاعتماد', 'warning'], approved: ['معتمد', 'success'], revoked: ['ملغى', 'muted'], lost: ['مفقود', 'danger'] };
const grant = useForm({ user_id: null, capabilities: [], starts_on: '', ends_on: '', note: '' });
const values = useForm(Object.fromEntries(props.settings.map(s => [s.key, s.value])));
function revoke(row) { if (window.confirm(`سحب قدرة «${props.capabilities[row.capability]}» من ${row.name}؟`)) router.delete(`/orphans/settings/capabilities/${row.id}`, { preserveScroll: true }); }
</script>

<template>
    <AppLayout title="إعدادات قسم الأيتام والكفالات">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader eyebrow="الإعدادات والصلاحيات" title="إعدادات قسم الأيتام والكفالات" description="قدرات موظفي القسم وسياساته. اعتماد كشوف الحملات وتعديلها حصري للمدير التنفيذي ولا يُمنح هنا." />
            <p v-if="!department.configured" class="alert-error">قسم «شؤون الأيتام والكفالات» (ORPHANS) غير موجود أو غير نشط في الهيكل التنظيمي.</p>
            <p v-else class="alert-info">رئيس القسم الحالي: {{ department.heads.length ? department.heads.join('، ') : 'غير معيّن (الإدارة تتم عبر مدير النظام)' }}</p>

            <section class="card space-y-4">
                <h2 class="card-title">قدرات الموظفين</h2>
                <p class="text-sm text-slate-600">موظفو القسم يطلعون على السجل ويحدّثونه؛ القدرات التالية إضافية وتُمنح صراحة لكل موظف.</p>
                <form v-if="canGrant" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2" @submit.prevent="grant.post('/orphans/settings/capabilities', { preserveScroll: true, onSuccess: () => grant.reset() })">
                    <label class="text-sm">الموظف<select v-model="grant.user_id" class="field mt-1" required><option :value="null">اختر موظفًا من القسم</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <fieldset class="text-sm sm:row-span-2"><legend class="field-label">القدرات</legend>
                        <label v-for="(label, key) in capabilities" :key="key" class="flex items-center gap-2 py-0.5"><input v-model="grant.capabilities" type="checkbox" :value="key"> {{ label }}</label></fieldset>
                    <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">من<input v-model="grant.starts_on" type="date" class="field mt-1"></label><label class="text-sm">إلى<input v-model="grant.ends_on" type="date" class="field mt-1"></label></div>
                    <label class="text-sm sm:col-span-2">ملاحظة<input v-model="grant.note" class="field mt-1" maxlength="500"></label>
                    <p v-for="(e, k) in grant.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
                    <div class="sm:col-span-2"><button type="submit" class="btn btn-primary" :disabled="grant.processing || !grant.user_id || !grant.capabilities.length">منح</button></div>
                </form>
                <EmptyState v-if="!staff.length" title="لا يوجد موظفون في القسم بعد" description="أسند الموظفين إلى القسم أو إحدى وحداته من «الهيكل التنظيمي» ثم امنحهم القدرات هنا." />
                <table v-if="grants.length" class="data-table">
                    <thead><tr><th scope="col">الموظف</th><th scope="col">القدرة</th><th scope="col">المدة</th><th scope="col">منحها</th><th scope="col"><span class="sr-only">إجراء</span></th></tr></thead>
                    <tbody><tr v-for="g in grants" :key="g.id"><td>{{ g.name }}</td><td>{{ capabilities[g.capability] }}</td><td>{{ g.starts_on || '—' }} ← {{ g.ends_on || 'مفتوحة' }}</td><td>{{ g.granted_by_name }}</td>
                        <td><button v-if="canGrant" type="button" class="btn btn-sm btn-danger" @click="revoke(g)">سحب</button></td></tr></tbody>
                </table>
            </section>

            <section class="card space-y-3">
                <h2 class="card-title">أجهزة التوزيع الميداني</h2>
                <p class="text-sm text-slate-600">بصمة مفتاح الخادم للتثبيت في تطبيق التوزيع: <span class="font-mono" dir="ltr">{{ serverKey.key_id }}</span>. يُسجَّل الجهاز عند أول دخول للموزع ويبقى بانتظار اعتماد رئيس القسم.</p>
                <ul class="divide-y divide-slate-100 text-sm"><li v-for="d in devices" :key="d.id" class="flex flex-wrap items-center gap-2 py-2">
                    <span class="font-semibold">{{ d.name }}</span><span class="text-slate-500">{{ d.user_name }} · <span class="font-mono" dir="ltr">{{ d.fingerprint }}</span> · آخر مزامنة {{ d.last_sync_at?.slice(0, 16) || '—' }}</span>
                    <StatusBadge :label="deviceLabels[d.status][0]" :tone="deviceLabels[d.status][1]" />
                    <span v-if="canManageDevices" class="ms-auto flex gap-1"><button v-if="d.status === 'pending'" type="button" class="btn btn-sm btn-primary" @click="device(d, 'approve')">اعتماد</button>
                        <template v-if="['pending', 'approved'].includes(d.status)"><button type="button" class="btn btn-sm btn-secondary" @click="device(d, 'revoke')">إلغاء</button><button type="button" class="btn btn-sm btn-danger" @click="device(d, 'lost')">مفقود</button></template></span></li>
                    <li v-if="!devices.length" class="text-slate-500">لا توجد أجهزة مسجلة.</li></ul>
            </section>

            <section class="card space-y-4">
                <h2 class="card-title">سياسات القسم</h2>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="values.put('/orphans/settings', { preserveScroll: true })">
                    <label v-for="s in settings" :key="s.key" class="text-sm">{{ s.label }}
                        <input v-if="s.type === 'integer'" v-model.number="values[s.key]" type="number" class="field mt-1" :placeholder="'غير محدد'">
                        <select v-else v-model="values[s.key]" class="field mt-1"><option :value="true">نعم</option><option :value="false">لا</option></select>
                        <span v-if="s.value === null" class="mt-1 block text-xs text-gold-800">لم يُحدد بعد؛ لا يُطبَّق حد حتى يقرره صاحب الصلاحية.</span>
                    </label>
                    <p v-for="(e, k) in values.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
                    <div class="sm:col-span-2"><button type="submit" class="btn btn-primary" :disabled="values.processing">حفظ</button></div>
                </form>
                <p class="text-xs text-slate-500">الفئات العمرية وأنواع الوثائق وصلات القرابة وفئات الدخل وقائمة فحص الطلبات تُدار من «ثوابت النظام».</p>
            </section>

            <section v-if="importPolicy" class="card space-y-4">
                <h2 class="card-title">سياسة الاستيراد الذكي</h2>
                <p class="text-sm text-slate-600">ماذا تفعل كل ملاحظة بالصف قبل «الاعتماد المبدئي». «معلومة فقط» لا تمنع الصف، و«يحتاج مراجعة» ينتظر قرار موظف، و«يمنع حتى الحل» يجعله تعارضًا.</p>
                <form class="space-y-3" @submit.prevent="policy.put('/orphans/settings/import-policy', { preserveScroll: true })">
                    <div class="overflow-x-auto"><table class="data-table min-w-[560px]"><thead><tr><th scope="col">الملاحظة</th><th scope="col">الإجراء</th><th scope="col">الافتراضي</th></tr></thead>
                        <tbody><tr v-for="p in importPolicy.configurable" :key="p.key"><td>{{ p.label }}</td>
                            <td><select v-model="policy.levels[p.key]" class="field" :disabled="!canGrant" :aria-label="`إجراء: ${p.label}`"><option v-for="(label, level) in importPolicy.levels" :key="level" :value="level">{{ label }}</option></select></td>
                            <td class="text-xs text-slate-500">{{ importPolicy.levels[p.default] }}</td></tr></tbody></table></div>
                    <p v-for="(e, k) in policy.errors" :key="k" class="field-error">{{ e }}</p>
                    <button v-if="canGrant" type="submit" class="btn btn-primary" :disabled="policy.processing">حفظ سياسة الاستيراد</button>
                </form>
                <details class="text-sm"><summary class="cursor-pointer font-semibold text-navy-800">قواعد ثابتة لا تُعدّل (حماية السجل)</summary>
                    <ul class="mt-2 grid gap-1 sm:grid-cols-2"><li v-for="f in importPolicy.fixed" :key="f.code">{{ f.label }} — <span class="text-slate-600">{{ f.level }}</span></li></ul></details>
            </section>
        </div>
    </AppLayout>
</template>
