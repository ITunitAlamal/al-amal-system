<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';

// Visit plans of the department: a period's visits distributed among the researchers by the head.
const props = defineProps({
    plans: { type: Array, default: () => [] },
    canManage: Boolean,
    policy: { type: Object, default: () => ({}) },
});
const creating = ref(props.canManage && props.plans.length === 0);
const form = useForm({ title: '', starts_on: '', ends_on: '', notes: '' });
function create() { form.post('/orphans/visit-plans', { preserveScroll: true }); }
const tone = { upcoming: 'info', current: 'warning', ended: 'muted' };
</script>

<template>
    <AppLayout title="خطط الزيارات الميدانية">
        <div class="space-y-4">
            <Link href="/orphans" class="text-sm font-semibold text-blue-700 hover:underline">→ ملفات الأيتام والأسر</Link>
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="خطط الزيارات الميدانية"
                description="يضع رئيس القسم خطة لفترة ويوزع زياراتها على الباحثين. كل زيارة تمر بتقرير الباحث وإقراره ثم مراجعة رئيس القسم كالمعتاد.">
                <button v-if="canManage" type="button" class="btn btn-primary btn-sm" @click="creating = !creating">خطة جديدة</button>
            </PageHeader>

            <form v-if="creating && canManage" class="card space-y-3" data-error-scope @submit.prevent="create">
                <h2 class="card-title">خطة زيارات جديدة</h2>
                <FormErrorSummary :errors="form.errors" />
                <div class="grid gap-3 text-sm sm:grid-cols-3">
                    <label class="block sm:col-span-3"><span class="field-label">عنوان الخطة *</span><input v-model="form.title" class="field" maxlength="200" required placeholder="مثال: زيارات متابعة شهر نوفمبر — خانيونس"></label>
                    <label class="block"><span class="field-label">من *</span><input v-model="form.starts_on" type="date" class="field" required></label>
                    <label class="block"><span class="field-label">إلى *</span><input v-model="form.ends_on" type="date" class="field" required></label>
                    <p class="self-end text-xs text-slate-500">ثلاثة أشهر كحد أقصى.</p>
                    <label class="block sm:col-span-3"><span class="field-label">ملاحظات</span><textarea v-model="form.notes" class="field" rows="2" maxlength="2000"></textarea></label>
                </div>
                <button class="btn btn-primary btn-sm" :disabled="form.processing">إنشاء الخطة ثم اختيار الأسر</button>
            </form>

            <section class="card">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الخطة</th><th>الفترة</th><th>الحالة</th><th>الزيارات</th><th>المعتمدة</th></tr></thead>
                        <tbody>
                            <tr v-for="p in plans" :key="p.public_id" class="border-t border-slate-100">
                                <td class="py-2"><Link :href="`/orphans/visit-plans/${p.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ p.reference_no }} — {{ p.title }}</Link></td>
                                <td><span dir="ltr">{{ p.starts_on }}</span> – <span dir="ltr">{{ p.ends_on }}</span></td>
                                <td><StatusBadge :label="p.state_label" :tone="tone[p.state]" /></td>
                                <td>{{ p.visits }}</td>
                                <td>{{ p.reviewed }}<span v-if="p.visits" class="text-xs text-slate-500"> ({{ Math.round(p.reviewed * 100 / p.visits) }}٪)</span></td>
                            </tr>
                            <tr v-if="!plans.length"><td colspan="5" class="py-4 text-center text-slate-500">لا توجد خطط بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="text-xs text-slate-500">متابعة الحالات: يُذكَّر المسؤول قبل الموعد بـ{{ policy.reminder }} أيام، ويُنبَّه رئيس القسم بعد تأخرها {{ policy.escalation }} أيام.
                دورية المراجعة الافتراضية: {{ policy.interval ? policy.interval + ' شهر' : 'غير مقررة (يحددها المدير التنفيذي)' }}.</p>
        </div>
    </AppLayout>
</template>
