<script setup>
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { formatDateTime } from '../../../Support/dates.js';

/**
 * «تعديلات ملفات الموظفين»: new files and changes HR staff prepared. The HR head approves one (it is written to the
 * file as it stands) or returns it with a note; the preparer corrects, resends or withdraws their own.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    scope: { type: String, default: 'open' },
    selected: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});

const page = usePage();
const base = '/admin/employee-file-changes';
const flash = computed(() => page.props.flash?.success || '');
const errors = computed(() => Object.values(page.props.errors || {}));
const dateTime = value => (value ? formatDateTime(value) : '—');
const open = id => router.get(base, { ...(props.scope === 'all' ? { scope: 'all' } : {}), change: id }, { preserveState: true, preserveScroll: true });
const setScope = scope => router.get(base, scope === 'all' ? { scope } : {}, { preserveState: true });

const approveForm = useForm({ revision: 0 });
const returnForm = useForm({ revision: 0, note: '' });
const withdrawForm = useForm({});
function approve() {
    approveForm.revision = props.selected.revision;
    approveForm.post(`${base}/${props.selected.id}/approve`, { preserveScroll: true });
}
function sendBack() {
    returnForm.revision = props.selected.revision;
    returnForm.post(`${base}/${props.selected.id}/return`, { preserveScroll: true, onSuccess: () => returnForm.reset('note') });
}
function withdraw() {
    if (!window.confirm('سحب هذا التعديل؟ لن يُغيَّر شيء في ملف الموظف.')) return;
    withdrawForm.post(`${base}/${props.selected.id}/withdraw`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="تعديلات ملفات الموظفين">
        <div class="mx-auto max-w-6xl space-y-4">
            <Link href="/admin/employee-profiles" class="text-sm font-semibold text-blue-700 hover:underline">→ ملفات الموظفين</Link>
            <PageHeader eyebrow="الموارد البشرية" title="تعديلات ملفات الموظفين"
                :description="can.review ? 'ما أعده موظفو الموارد البشرية من ملفات جديدة أو تعديلات؛ لا يظهر شيء منها في الملف قبل اعتمادك.'
                    : (can.prepare ? 'ما أرسلته إلى رئيس الموارد البشرية للاعتماد، وقراره فيه.' : 'ما أعده موظفو الموارد البشرية وقرار رئيس القسم فيه (للاطلاع).')" />
            <p v-if="flash" class="alert-success">{{ flash }}</p>
            <div v-if="errors.length" role="alert" class="alert-error space-y-1"><p v-for="(message, i) in errors" :key="i">{{ message }}</p></div>

            <section v-if="selected" class="card space-y-4" aria-labelledby="change-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="change-title" class="card-title">{{ selected.kindLabel }}: {{ selected.employee || '—' }}
                        <span class="font-mono text-sm font-normal text-slate-500" dir="ltr">{{ selected.employeeNumber }}</span></h2>
                    <StatusBadge :label="selected.statusLabel" :tone="selected.tone" />
                </div>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">أعده</dt><dd class="font-semibold">{{ selected.preparedBy }}</dd></div>
                    <div><dt class="text-slate-500">أُرسل في</dt><dd>{{ dateTime(selected.submittedAt) }}</dd></div>
                    <div v-if="selected.reviewedBy"><dt class="text-slate-500">قرار رئيس القسم</dt><dd>{{ selected.reviewedBy }} — {{ dateTime(selected.reviewedAt) }}</dd></div>
                    <div v-if="selected.note" class="sm:col-span-3"><dt class="text-slate-500">ملاحظة الإعادة</dt><dd class="whitespace-pre-line font-semibold text-gold-900">{{ selected.note }}</dd></div>
                </dl>
                <p v-if="selected.employeeId" class="text-sm"><Link :href="`/admin/employee-profiles/${selected.employeeId}`" class="font-semibold text-blue-700 hover:underline">فتح ملف الموظف</Link></p>
                <p v-if="selected.linksAccount" class="alert-info text-sm">يُربط الملف بحساب الدخول القائم بالرقم الوظيفي نفسه، دون تغيير كلمة مروره أو صلاحياته.</p>

                <div v-if="selected.fields.length" class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th scope="col">البيان</th><th v-if="selected.kind === 'update'" scope="col">في الملف الآن</th><th scope="col">{{ selected.kind === 'update' ? 'بعد الاعتماد' : 'القيمة' }}</th></tr></thead>
                        <tbody><tr v-for="(field, i) in selected.fields" :key="i">
                            <td>{{ field.label }}</td>
                            <td v-if="selected.kind === 'update'" class="text-slate-600">{{ field.before }}</td>
                            <td class="font-semibold">{{ field.after }}</td>
                        </tr></tbody>
                    </table>
                </div>
                <ul v-if="selected.rows.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm">
                    <li v-for="(row, i) in selected.rows" :key="i" class="space-y-1 p-3">
                        <p><StatusBadge :label="row.label" :tone="row.kind === 'new' ? 'info' : 'warning'" /> <span class="text-slate-700">{{ row.summary }}</span></p>
                        <p v-for="(c, j) in row.changes" :key="j" class="text-xs text-slate-600">{{ c.label }}: {{ c.before }} ← <strong class="text-navy-800">{{ c.after }}</strong></p>
                    </li>
                </ul>

                <div v-if="selected.can.approve" class="space-y-3 border-t border-slate-200 pt-4">
                    <p class="text-xs text-slate-500">يُفحص التعديل عند الاعتماد مقابل الملف كما هو الآن؛ إن تغيّرت البيانات نفسها بعد إعداده يُرفض الاعتماد وتعيده إلى مُعِدّه.</p>
                    <button type="button" class="btn btn-primary" :disabled="approveForm.processing" @click="approve">{{ approveForm.processing ? 'جارٍ الاعتماد...' : 'اعتماد وتثبيت في الملف' }}</button>
                    <form class="space-y-2" @submit.prevent="sendBack">
                        <label for="return-note" class="field-label">إعادة للاستكمال: ما الناقص أو الخطأ؟</label>
                        <textarea id="return-note" v-model="returnForm.note" rows="2" maxlength="2000" class="field" required></textarea>
                        <p v-if="returnForm.errors.note" class="field-error">{{ returnForm.errors.note }}</p>
                        <button type="submit" class="btn btn-secondary btn-sm" :disabled="returnForm.processing || returnForm.note.trim().length < 3">إعادة إلى مُعِدّه</button>
                    </form>
                </div>
                <div v-if="selected.can.edit || selected.can.withdraw" class="flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                    <Link v-if="selected.can.edit && selected.editUrl" :href="selected.editUrl" class="btn btn-primary btn-sm">{{ selected.status === 'returned' ? 'استكمال وإعادة الإرسال' : 'تعديل قبل الاعتماد' }}</Link>
                    <button v-if="selected.can.withdraw" type="button" class="btn btn-secondary btn-sm" :disabled="withdrawForm.processing" @click="withdraw">سحب التعديل</button>
                </div>
            </section>

            <section class="card p-0">
                <div class="flex flex-wrap gap-2 border-b border-slate-200 p-4">
                    <button type="button" class="btn btn-sm" :class="scope === 'open' ? 'btn-primary' : 'btn-secondary'" @click="setScope('open')">بانتظار القرار أو معادة</button>
                    <button type="button" class="btn btn-sm" :class="scope === 'all' ? 'btn-primary' : 'btn-secondary'" @click="setScope('all')">آخر 100</button>
                </div>
                <div class="overflow-x-auto p-4">
                    <table v-if="rows.length" class="aims-responsive-table data-table">
                        <thead><tr><th scope="col">النوع</th><th scope="col">الموظف</th><th scope="col">أعده</th><th scope="col">أُرسل</th><th scope="col">الحالة</th><th scope="col"><span class="sr-only">عرض</span></th></tr></thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id" :class="selected?.id === row.id ? 'bg-navy-50' : ''">
                                <td data-label="النوع">{{ row.kindLabel }}</td>
                                <td data-label="الموظف" class="font-semibold text-navy-800">{{ row.employee || '—' }} <span class="font-mono text-xs font-normal text-slate-500" dir="ltr">{{ row.employeeNumber }}</span></td>
                                <td data-label="أعده">{{ row.preparedBy }}</td>
                                <td data-label="أُرسل">{{ dateTime(row.submittedAt) }}</td>
                                <td data-label="الحالة"><StatusBadge :label="row.statusLabel" :tone="row.tone" /></td>
                                <td data-label="عرض"><button type="button" class="btn btn-sm btn-secondary" @click="open(row.id)">عرض</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState v-else :title="scope === 'open' ? 'لا توجد تعديلات بانتظار القرار' : 'لا توجد تعديلات بعد'"
                        description="يظهر هنا ما يعده موظفو الموارد البشرية من ملفات جديدة أو تعديلات حتى يبت فيه رئيس القسم." />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
