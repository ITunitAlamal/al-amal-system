<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import ImportIcon from '../../../Components/Registry/ImportIcon.vue';
import Pagination from '../../../Components/Pagination.vue';

const props = defineProps({ batches: Object, purposes: Object, purpose: { type: String, default: null }, campaigns: Array });
const form = useForm({ file: null, purpose: props.purpose && props.purposes[props.purpose] ? props.purpose : 'registry', campaign_id: null, encoding: 'UTF-8' });
// Checked in the browser first, so a too-large file is refused at once instead of after a long upload.
const tooBig = ref(null);
function pick(file) { form.file = file || null; tooBig.value = file && file.size > 64 * 1048576 ? (file.size / 1048576).toFixed(1) : null; }
const tones = { reviewing: 'warning', completed: 'success', failed: 'danger', cancelled: 'muted', executing: 'info', analyzing: 'info' };
</script>

<template>
    <AppLayout title="الاستيراد الذكي">
        <div class="mx-auto max-w-6xl space-y-5">
            <header class="card flex items-start gap-4">
                <ImportIcon :size="44" />
                <div><p class="eyebrow">شؤون الأيتام والكفالات</p><h1 class="text-2xl font-bold text-navy-800">الاستيراد الذكي — استيراد البيانات والكشوفات</h1>
                    <p class="mt-1 text-sm leading-7 text-slate-600">استيراد بيانات الأيتام والأوصياء والكشوفات مع التحقق المسبق واكتشاف الأخطاء والتكرار. لا يُكتب شيء في السجل قبل المراجعة والاعتماد، والوصاية المستوردة ادعاء لا إثبات، وكشوف الحملات تُسجل مسودات تحتاج اعتماد المدير التنفيذي.</p></div>
            </header>
            <form class="card grid gap-3 sm:grid-cols-2" @submit.prevent="!tooBig && form.post('/orphans/import', { forceFormData: true })">
                <h2 class="card-title sm:col-span-2">1. رفع الملف</h2>
                <label class="text-sm sm:col-span-2">غرض الاستيراد<select v-model="form.purpose" class="field mt-1"><option v-for="(label, key) in purposes" :key="key" :value="key">{{ label }}</option></select></label>
                <label v-if="['campaign_candidates', 'delivery_reconciliation'].includes(form.purpose)" class="text-sm">الحملة المستهدفة<select v-model="form.campaign_id" class="field mt-1" required><option :value="null">اختر</option><option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.reference_no }} — {{ c.title }}</option></select></label>
                <label class="text-sm">الملف (XLSX أو CSV، حتى 64 ميغابايت)<input type="file" accept=".xlsx,.csv" class="field mt-1" required :aria-invalid="!!tooBig" @input="pick($event.target.files[0])">
                    <span v-if="tooBig" class="field-error mt-1 block">حجم الملف {{ tooBig }} ميغابايت والحد 64 ميغابايت؛ قسّم الملف إلى أجزاء.</span></label>
                <label v-if="form.file?.name?.toLowerCase().endsWith('.csv')" class="text-sm">ترميز CSV<select v-model="form.encoding" class="field mt-1"><option>UTF-8</option><option>Windows-1256</option><option>ISO-8859-6</option></select></label>
                <p class="text-xs text-slate-500 sm:col-span-2">ملفات PDF والصور لا تُحوّل إلى سجلات. لكشف «قيم 22.xlsx» تُستورد ورقة «جميع المستفيدين» فقط.</p>
                <p v-for="(e, k) in form.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
                <div class="space-y-2 sm:col-span-2"><p v-if="form.progress" class="text-sm text-blue-800" role="status">جارٍ رفع الملف: {{ form.progress.percentage }}٪</p><progress v-if="form.progress" :value="form.progress.percentage" max="100" class="w-full" aria-label="تقدم رفع ملف الاستيراد" /><button type="submit" class="btn btn-primary" :disabled="form.processing || !form.file">{{ form.processing ? 'جارٍ الرفع…' : 'رفع ومتابعة' }}</button></div>
            </form>
            <section class="card p-0"><h2 class="card-title p-4">الدفعات</h2>
                <div class="overflow-x-auto px-4 pb-4">
                    <table v-if="batches.data.length" class="data-table"><thead><tr><th scope="col">الدفعة</th><th scope="col">الغرض</th><th scope="col">الملف</th><th scope="col">الصفوف</th><th scope="col">الحالة</th></tr></thead>
                        <tbody><tr v-for="b in batches.data" :key="b.public_id"><td><Link :href="`/orphans/import/${b.public_id}`" class="font-mono text-blue-700 hover:underline" dir="ltr">{{ b.reference_no }}</Link><span class="block text-xs text-slate-500">{{ b.uploaded_by }} · {{ b.created_at?.slice(0, 16) }}</span></td>
                            <td class="text-sm">{{ b.purpose }}<span v-if="b.campaign" class="block text-xs text-slate-500">{{ b.campaign }}</span></td><td class="text-sm">{{ b.original_name }}</td><td class="tabular-nums">{{ b.rows_total }}</td>
                            <td><StatusBadge :label="b.status_label" :tone="tones[b.status] || 'muted'" /></td></tr></tbody></table>
                    <EmptyState v-else title="لا توجد دفعات استيراد بعد" />
                </div><Pagination :page="batches" /></section>
        </div>
    </AppLayout>
</template>
