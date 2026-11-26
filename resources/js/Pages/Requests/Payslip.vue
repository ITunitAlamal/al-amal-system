<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ months: Array, linked: Boolean, requests: Array });
const form = useForm({ period: props.months?.[1] || props.months?.[0] || '', note: '' });
const monthName = value => {
    const [year, month] = String(value).split('-').map(Number);
    return new Intl.DateTimeFormat('ar', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
};
const submit = () => form.post('/requests/payslip', { preserveScroll: true, onSuccess: () => form.reset('note') });
</script>

<template>
    <AppLayout title="طلب قسيمة راتب">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader eyebrow="طلباتي" title="طلب قسيمة راتب" description="تصدر القسيمة من مسير الرواتب المعتمد والمنشور فقط. إن لم يُنشر مسير الشهر بعد، يبقى طلبك قائمًا ونُشعرك فور نشره." />

            <div v-if="!linked" class="alert-error" role="alert">حسابك غير مرتبط بملف موظف، فلا يمكن إصدار قسيمة باسمك. راجع الموارد البشرية لربط الحساب بملفك الوظيفي.</div>

            <section class="card space-y-4" aria-labelledby="payslip-form-title">
                <h2 id="payslip-form-title" class="card-title">طلب جديد</h2>
                <form class="grid gap-4 sm:grid-cols-3" @submit.prevent="submit">
                    <div>
                        <label for="payslip-period" class="field-label">الشهر</label>
                        <select id="payslip-period" v-model="form.period" class="field" required>
                            <option v-for="month in months" :key="month" :value="month">{{ monthName(month) }} ({{ month }})</option>
                        </select>
                        <p v-if="form.errors.period" class="field-error">{{ form.errors.period }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="payslip-note" class="field-label">ملاحظة (اختياري)</label>
                        <input id="payslip-note" v-model="form.note" class="field" maxlength="1000" placeholder="مثال: لتقديمها لجهة رسمية" />
                    </div>
                    <div class="sm:col-span-3 flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing || !linked">إرسال الطلب</button>
                        <p class="text-xs leading-5 text-slate-500">يُحدَّد الموظف من حسابك تلقائيًا. تكرار الطلب للشهر نفسه لا يُنشئ قسيمة ثانية.</p>
                    </div>
                </form>
            </section>

            <section class="card space-y-3" aria-labelledby="payslip-list-title">
                <h2 id="payslip-list-title" class="card-title">طلباتي السابقة</h2>
                <EmptyState v-if="!requests.length" title="لا توجد طلبات قسائم بعد" />
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
                                    <a v-if="request.slip" :href="`/payslips/${request.slip}`" target="_blank" rel="noopener" class="font-semibold text-navy-700 hover:underline">عرض القسيمة</a>
                                    <span v-else class="text-xs text-slate-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Link href="/requests" class="inline-block text-sm font-semibold text-navy-700 hover:underline">العودة إلى طلباتي</Link>
            </section>
        </div>
    </AppLayout>
</template>
