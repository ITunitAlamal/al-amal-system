<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    username: String,
    navigation: Array,
    employee: Object,
    types: { type: Array, default: () => [] },
    settings: Object,
    existing: { type: Object, default: null },
    balances: { type: Object, default: () => ({}) },
});

const form = useForm({
    leave_type_value_id: props.existing?.leave_type_value_id || '',
    start_date: props.existing?.start_date?.slice(0, 10) || '',
    end_date: props.existing?.end_date?.slice(0, 10) || '',
    reason: props.existing?.reason || '',
});

const today = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
const balance = computed(() => props.balances?.[form.leave_type_value_id] ?? null);
const countingLabel = computed(() => ({ calendar: 'أيام تقويمية (تشمل العطل)', working: 'أيام عمل فقط (دون العطل الرسمية والراحة الأسبوعية)' })[props.settings?.counting_mode] ?? null);
const submit = () => (props.existing ? form.put(`/leaves/${props.existing.id}`, { preserveScroll: true }) : form.post('/leaves', { preserveScroll: true }));
</script>

<template>
    <AppLayout title="طلب إجازة" :navigation="navigation">
        <div class="mx-auto max-w-3xl space-y-5">
            <Link href="/leaves" class="text-sm font-semibold text-blue-700 hover:underline">→ طلبات الإجازات</Link>
            <PageHeader eyebrow="الإجازات" :title="existing ? (existing.status === 'returned' ? `تعديل الطلب المُعاد #${existing.id}` : `تعديل مسودة #${existing.id}`) : 'طلب إجازة جديد'"
                :description="existing?.status === 'returned' ? 'عدّل ما طُلب منك، ثم ارجع إلى صفحة الطلب وأعد إرساله ليكمل مساره. لا تُخصم أيام قبل الاعتماد النهائي.' : 'يُحفظ الطلب مسودة أولًا، ولا تُخصم أيام من رصيدك حتى يُعتمد.'" />

            <form class="card space-y-5 p-6" novalidate @submit.prevent="submit">
                <div>
                    <label for="leave-type" class="field-label">نوع الإجازة <span class="text-red-600">*</span></label>
                    <select id="leave-type" v-model="form.leave_type_value_id" required class="field" :aria-invalid="Boolean(form.errors.leave_type_value_id)">
                        <option value="">اختر نوع الإجازة</option>
                        <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name_ar }}</option>
                    </select>
                    <p v-if="!types.length" class="mt-1 text-xs text-gold-800">لا توجد أنواع إجازات نشطة. راجع الموارد البشرية.</p>
                    <p v-if="balance" class="mt-1 text-xs" :class="balance.available > 0 ? 'text-slate-600' : 'text-gold-800'">
                        رصيدك المتاح لسنة {{ balance.year }}: <strong>{{ balance.available }}</strong> يومًا<span v-if="balance.reserved > 0"> (بعد حجز {{ balance.reserved }} يومًا لطلبات قيد الاعتماد)</span>.
                        <template v-if="!balance.opening_recorded && balance.available <= 0"> لم يُسجَّل لك رصيد افتتاحي بعد؛ راجع الموارد البشرية.</template>
                    </p>
                    <p v-if="form.errors.leave_type_value_id" class="field-error">{{ form.errors.leave_type_value_id }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="leave-from" class="field-label">من <span class="text-red-600">*</span></label>
                        <input id="leave-from" v-model="form.start_date" type="date" required :min="today" class="field" :aria-invalid="Boolean(form.errors.start_date)" />
                        <p v-if="form.errors.start_date" class="field-error">{{ form.errors.start_date }}</p>
                    </div>
                    <div>
                        <label for="leave-to" class="field-label">إلى <span class="text-red-600">*</span></label>
                        <input id="leave-to" v-model="form.end_date" type="date" required :min="form.start_date || today" class="field" :aria-invalid="Boolean(form.errors.end_date)" />
                        <p v-if="form.errors.end_date" class="field-error">{{ form.errors.end_date }}</p>
                    </div>
                </div>
                <p v-if="countingLabel" class="text-xs text-slate-500">تُحتسب الأيام: {{ countingLabel }}. الطلب لسنة واحدة؛ إذا امتدت الإجازة إلى سنة جديدة فقدّم طلبًا لكل سنة.</p>
                <div>
                    <label for="leave-reason" class="field-label">السبب <span class="text-red-600">*</span></label>
                    <textarea id="leave-reason" v-model="form.reason" required rows="4" minlength="5" maxlength="5000" class="field" :aria-invalid="Boolean(form.errors.reason)" />
                    <p v-if="form.errors.reason" class="field-error">{{ form.errors.reason }}</p>
                </div>
                <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                    <button type="submit" :disabled="form.processing" class="btn btn-primary px-6 py-2.5">{{ form.processing ? 'جارٍ الحفظ...' : (existing ? 'حفظ التعديلات' : 'حفظ المسودة') }}</button>
                    <Link :href="existing ? `/leaves/${existing.id}` : '/leaves'" class="btn btn-secondary px-6 py-2.5">إلغاء</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
