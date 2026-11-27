<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

/**
 * «استيراد الموظفين»: upload an XLSX or CSV, then review it step by step. Nothing reaches employee files before an
 * explicit, confirmed commit; no login accounts, notifications or payroll entries are created.
 */
const props = defineProps({
    batches: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
    fileSettings: { type: Object, default: null },
    phpUploadKb: { type: Number, default: 2048 },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success || '');
const statuses = { staged: ['بانتظار ربط الأعمدة', 'warning'], validated: ['جاهزة للمراجعة', 'info'], committing: ['قيد التنفيذ', 'info'], committed: ['نُفذت', 'success'],
    failed: ['توقفت (يمكن المتابعة)', 'danger'], cancelled: ['ملغاة', 'muted'], reverted: ['تُراجع عنها', 'muted'] };
const upload = useForm({ file: null });
const dragging = ref(false);
const localError = ref('');
const showFields = ref(false);
function pick(file) {
    localError.value = '';
    if (!file) return;
    const extension = String(file.name.split('.').pop() || '').toLowerCase();
    if (!['xlsx', 'csv'].includes(extension)) { localError.value = 'الملف يجب أن يكون XLSX أو CSV.'; return; }
    if (file.size > props.limits.maxKb * 1024) { localError.value = `حجم الملف أكبر من ${props.limits.maxKb} ك.ب.`; return; }
    upload.file = file;
    upload.post('/admin/employee-imports', { forceFormData: true });
}
function drop(event) { dragging.value = false; pick(event.dataTransfer?.files?.[0]); }

const settings = useForm({ ...(props.fileSettings || {}) });
function saveSettings() {
    settings.transform(data => ({ photo_max_kb: data.photo_max_kb, photo_min_px: data.photo_min_px, photo_max_px: data.photo_max_px, document_max_kb: data.document_max_kb,
        employee_quota_mb: data.employee_quota_mb, self_photo_upload: data.self_photo_upload, self_view_pay: Boolean(data.self_view_pay),
        document_expiry_alert_days: data.document_expiry_alert_days === '' || data.document_expiry_alert_days === undefined ? null : data.document_expiry_alert_days }))
        .put('/admin/employee-imports/settings', { preserveScroll: true });
}
const date = value => (value ? String(value).slice(0, 16) : '—');
</script>

<template>
    <AppLayout title="استيراد الموظفين">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="الموارد البشرية" title="استيراد الموظفين" description="استيراد مرحلي آمن من ملف Excel أو CSV: قراءة، ثم ربط الأعمدة والقيم، ثم تحقق ومعاينة، ثم تنفيذ صريح قابل للتراجع.">
                <a href="/admin/employee-imports/template" class="btn btn-secondary">تنزيل النموذج الرسمي (XLSX)</a>
                <Link href="/admin/employee-profiles" class="btn btn-secondary">ملفات الموظفين</Link>
            </PageHeader>
            <p v-if="flash" class="alert-success">{{ flash }}</p>

            <section class="card space-y-3">
                <h2 class="card-title">رفع ملف جديد</h2>
                <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-8 text-center transition"
                    :class="dragging ? 'border-navy-400 bg-navy-50' : 'border-slate-300 hover:bg-slate-50'"
                    @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop">
                    <span class="font-semibold text-navy-800">{{ upload.processing ? 'جارٍ قراءة الملف...' : 'اسحب ملف الموظفين هنا أو اختره' }}</span>
                    <span class="mt-1 text-xs text-slate-500">XLSX أو CSV حتى {{ limits.maxKb }} ك.ب و{{ limits.maxRows }} صف. تُقرأ الورقة الأولى ويُكتشف صف العناوين تلقائيًا (ولو سبقه صف فارغ).</span>
                    <input type="file" accept=".xlsx,.csv" class="sr-only" :disabled="upload.processing" @change="pick($event.target.files[0]); $event.target.value = ''" />
                </label>
                <p v-if="localError || upload.errors.file" class="field-error">{{ localError || upload.errors.file }}</p>
                <ul class="list-inside list-disc text-xs leading-6 text-slate-600">
                    <li>لا يُحفظ الملف المرفوع؛ تُرحّل صفوفه مشفّرة للمراجعة مع رقم الصف والبصمة الرقمية لكل صف.</li>
                    <li>الإجراء الافتراضي للموظف الموجود «تخطي»؛ لا تحديث شامل أعمى، ولا تُمحى قيمة محفوظة بقيمة فارغة.</li>
                    <li>لا تُنشأ حسابات دخول ولا تُرسل إشعارات ولا تُنشأ حركات رواتب أو قيود أو موافقات.</li>
                </ul>
            </section>

            <section class="card">
                <h2 class="card-title mb-3">الدفعات السابقة</h2>
                <EmptyState v-if="!batches.length" title="لا توجد دفعات بعد" />
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-[760px]">
                        <thead><tr><th>#</th><th>الملف</th><th>الصفوف</th><th>الحالة</th><th>النتيجة</th><th>رفعها</th><th>التاريخ</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="b in batches" :key="b.id">
                                <td>{{ b.id }}</td>
                                <td class="max-w-56 truncate" :title="b.file_name">{{ b.file_name }}</td>
                                <td>{{ b.data_rows }}</td>
                                <td><StatusBadge :label="(statuses[b.status] || [b.status])[0]" :tone="(statuses[b.status] || [null, 'muted'])[1]" /></td>
                                <td class="text-xs">
                                    <template v-if="b.summary?.results && ['committed', 'reverted', 'failed'].includes(b.status)">
                                        أُنشئ {{ b.summary.results.inserted }} · حُدّث {{ b.summary.results.updated }} · تُخطي {{ b.summary.results.skipped }} · رُفض {{ b.summary.results.rejected }}<template v-if="b.summary.results.reverted"> · تُراجع {{ b.summary.results.reverted }}</template>
                                    </template>
                                    <template v-else-if="b.summary?.status">سليم {{ b.summary.status.valid }} · تنبيه {{ b.summary.status.warning }} · خطأ {{ b.summary.status.error }}</template>
                                </td>
                                <td>{{ b.created_by }}</td>
                                <td class="text-xs" dir="ltr">{{ date(b.committed_at || b.created_at) }}</td>
                                <td><Link :href="`/admin/employee-imports/${b.id}`" class="btn btn-sm btn-secondary">فتح</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <button type="button" class="card-title text-start hover:underline" @click="showFields = !showFields">أعمدة النموذج وأين تُحفظ ({{ fields.length }}) {{ showFields ? '▲' : '▼' }}</button>
                <table v-if="showFields" class="data-table mt-3">
                    <thead><tr><th>العمود</th><th>English</th><th>يُحفظ في</th></tr></thead>
                    <tbody><tr v-for="f in fields" :key="f.key"><td class="font-semibold">{{ f.ar }}</td><td dir="ltr" class="text-left text-xs">{{ f.en }}</td><td class="text-xs">{{ f.target }}</td></tr></tbody>
                </table>
            </section>

            <section v-if="fileSettings" class="card space-y-3">
                <h2 class="card-title">إعدادات صور الموظفين ووثائقهم (مدير النظام)</h2>
                <div class="grid gap-3 md:grid-cols-3">
                    <label class="text-sm">أقصى حجم للصورة (ك.ب)<input v-model.number="settings.photo_max_kb" type="number" min="50" max="20480" class="field mt-1" /></label>
                    <label class="text-sm">أقل بُعد للصورة (بكسل)<input v-model.number="settings.photo_min_px" type="number" min="50" max="2000" class="field mt-1" /></label>
                    <label class="text-sm">أكبر بُعد للصورة (بكسل)<input v-model.number="settings.photo_max_px" type="number" min="500" max="12000" class="field mt-1" /></label>
                    <label class="text-sm">أقصى حجم للوثيقة (ك.ب)<input v-model.number="settings.document_max_kb" type="number" min="100" max="51200" class="field mt-1" /></label>
                    <label class="text-sm">مساحة ملفات الموظف (م.ب)<input v-model.number="settings.employee_quota_mb" type="number" min="10" max="10240" class="field mt-1" /></label>
                    <label class="text-sm">التنبيه قبل انتهاء الوثيقة (يوم)<input v-model.number="settings.document_expiry_alert_days" type="number" min="1" max="365" class="field mt-1" placeholder="فارغ: المنتهية فقط" /></label>
                    <label class="text-sm">رفع الموظف لصورته<select v-model="settings.self_photo_upload" class="field mt-1"><option value="review">مسموح بمراجعة الموارد البشرية</option><option value="disabled">غير مسموح</option></select></label>
                    <label class="flex items-center gap-2 text-sm md:col-span-3"><input v-model="settings.self_view_pay" type="checkbox" /> يرى الموظف راتبه وعلاواته في «ملفي الوظيفي» (الحساب البنكي يظهر له مقنّعًا دائمًا)</label>
                </div>
                <p class="text-xs text-slate-500">حد الخادم الحالي للرفع {{ phpUploadKb }} ك.ب (upload_max_filesize/post_max_size)؛ الحد الفعلي هو الأصغر بينه وبين الإعداد.</p>
                <p v-for="(message, key) in settings.errors" :key="key" class="field-error">{{ message }}</p>
                <button type="button" class="btn btn-primary btn-sm" :disabled="settings.processing" @click="saveSettings">حفظ الإعدادات</button>
            </section>
        </div>
    </AppLayout>
</template>
