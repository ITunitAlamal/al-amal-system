<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

const props = defineProps({ username: String, navigation: Array, preview: Object });
const form = useForm({ go_live_date: '', confirmed: false });
const submit = () => form.post(`/admin/leave-policy-corrections/${props.preview.correction_id}/apply`);
</script>

<template>
    <AppLayout title="سياسة الإجازات">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link href="/admin/leaves/settings" class="text-sm font-semibold text-blue-700 hover:underline">→ إعدادات الإجازات</Link>
            <PageHeader eyebrow="الإجازات" title="سياسة الإجازات السنوية المعتمدة">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                        <StatusBadge :label="preview.status === 'approved_pending_launch' ? 'معتمدة بانتظار الإطلاق' : 'مفعّلة'" :tone="preview.status === 'approved_pending_launch' ? 'warning' : 'success'" />
                        <span v-if="preview.go_live_date">تاريخ الإطلاق: <span dir="ltr">{{ preview.go_live_date }}</span></span>
                    </div>
                </template>
            </PageHeader>

            <section class="card space-y-4">
                <table class="data-table">
                    <thead><tr><th>من شهر خدمة</th><th>إلى شهر (غير شامل)</th><th>الأيام سنويًا</th></tr></thead>
                    <tbody>
                        <tr v-for="tier in preview.tiers" :key="tier.min_service_months">
                            <td>{{ tier.min_service_months }}</td>
                            <td>{{ tier.max_service_months ?? 'بلا حد' }}</td>
                            <td class="font-bold text-navy-800">{{ tier.annual_entitlement_days }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-sm text-slate-600">التفعيل لا يضيف رصيدًا ولا يخصم أيامًا. الاستحقاق قبل سنة غير محدد حتى اعتماد سياسة مستقلة.</p>
                <p v-if="!preview.ready_for_activation && preview.status === 'approved_pending_launch'" class="alert-error">التهيئة غير جاهزة؛ راجع القواعد والإعدادات الموجودة.</p>
            </section>

            <form v-if="preview.status === 'approved_pending_launch'" class="card space-y-4" @submit.prevent="submit">
                <h2 class="card-title">تفعيل السياسة في يوم إطلاق النظام فقط</h2>
                <div>
                    <label for="go-live" class="field-label">تاريخ الإطلاق الرسمي</label>
                    <input id="go-live" v-model="form.go_live_date" type="date" class="field" required />
                </div>
                <label class="flex items-start gap-3 text-sm"><input v-model="form.confirmed" type="checkbox" class="mt-1" />أؤكد أن اليوم هو موعد الإطلاق الرسمي وأنني مخوّل بالتفعيل.</label>
                <button type="submit" :disabled="form.processing || !form.confirmed || !preview.ready_for_activation" class="btn btn-primary">تفعيل السياسة المعتمدة</button>
            </form>
        </div>
    </AppLayout>
</template>
