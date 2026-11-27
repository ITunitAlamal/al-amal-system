<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ months: { type: Array, default: () => [] }, linked: Boolean, requests: { type: Array, default: () => [] } });
const form = useForm({ period: props.months?.[0] || '' });
const monthName = value => {
    const [year, month] = String(value).split('-').map(Number);
    return new Intl.DateTimeFormat('ar', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
};
const submit = () => form.post('/requests/payslip', { preserveScroll: true });
</script>

<template>
    <AppLayout title="إصدار قسيمة راتب (فيشة)">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader eyebrow="طلباتي" title="إصدار قسيمة راتب (فيشة)" description="اختر الشهر وافتح قسيمتك فورًا للطباعة أو الحفظ PDF. إصدار قسيمتك خدمة ذاتية لا تحتاج موافقة أو اعتمادًا من أحد.">
                <Link href="/requests" class="btn btn-secondary">العودة إلى طلباتي</Link>
            </PageHeader>

            <div v-if="!linked" class="alert-error" role="alert">حسابك غير مرتبط بملف موظف، فلا يمكن إصدار قسيمة باسمك. راجع الموارد البشرية لربط الحساب بملفك الوظيفي.</div>
            <div v-else-if="!months.length" class="alert-info" role="status">لا تتوفر بيانات راتب لك حاليًا. ستظهر الأشهر هنا عند توفر بياناتها، ويمكنك إصدار القسيمة مباشرة دون طلب اعتماد.</div>

            <section class="card space-y-4" aria-labelledby="payslip-form-title">
                <h2 id="payslip-form-title" class="card-title">إصدار مباشر</h2>
                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label for="payslip-period" class="field-label">الشهر</label>
                        <select id="payslip-period" v-model="form.period" class="field" required :disabled="form.processing || !linked || !months.length">
                            <option v-if="!months.length" value="">لا توجد أشهر متاحة</option>
                            <option v-for="month in months" :key="month" :value="month">{{ monthName(month) }} ({{ month }})</option>
                        </select>
                        <p v-if="form.errors.period" class="field-error">{{ form.errors.period }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing || !linked || !form.period" :aria-busy="form.processing">{{ form.processing ? 'جارٍ فتح القسيمة…' : 'إصدار وفتح قسيمتي' }}</button>
                        <p class="text-xs leading-5 text-slate-500">تظهر الأشهر التي تتوفر لها بيانات راتبك فقط. تُفتح القسيمة مباشرة، دون إرسال الطلب إلى أي موظف.</p>
                    </div>
                </form>
            </section>

            <section class="card space-y-3" aria-labelledby="payslip-list-title">
                <h2 id="payslip-list-title" class="card-title">قسائمي السابقة</h2>
                <EmptyState v-if="!requests.length" title="لم تُصدر قسيمة بعد" />
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-full text-sm">
                        <thead><tr><th scope="col">رقم الطلب</th><th scope="col">الشهر</th><th scope="col">الحالة</th><th scope="col">تاريخ الطلب</th><th scope="col"><span class="sr-only">الإجراء</span></th></tr></thead>
                        <tbody>
                            <tr v-for="request in requests" :key="request.reference">
                                <td dir="ltr" class="text-right font-mono text-xs">{{ request.reference }}</td>
                                <td>{{ monthName(request.period) }}</td>
                                <td><StatusBadge :label="request.stateLabel" :tone="request.tone" /></td>
                                <td>{{ request.createdAt?.slice(0, 10) }}</td>
                                <td>
                                    <a v-if="request.slip" :href="`/payslips/${request.slip}`" target="_blank" rel="noopener" class="font-semibold text-navy-700 hover:underline">عرض وطباعة قسيمتي</a>
                                    <span v-else class="text-xs text-slate-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
