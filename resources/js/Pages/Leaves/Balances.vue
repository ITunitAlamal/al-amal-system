<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { entitlementText } from '../../Support/leaveEntitlement.js';

const props = defineProps({
    username: String, navigation: Array, managed: Boolean, isAdmin: Boolean, employee: Object, basis: Object,
    year: Number, currentYear: Number, items: Array, types: Array, employees: Array,
});
const selected = ref(props.employee?.id || '');
const year = ref(props.year || new Date().getFullYear());
const filter = () => router.get('/leaves/balances', { employee_id: selected.value, year: year.value });
const grant = useForm({ employee_id: props.employee?.id, leave_type_value_id: '', confirmed: false });
const adjust = useForm({ employee_id: props.employee?.id, leave_type_value_id: '', balance_year: props.year, amount_days: '', reason: '', confirmed: false });
const opening = useForm({ employee_id: props.employee?.id, leave_type_value_id: '', balance_year: props.currentYear, annual_entitlement_days: '', opening_balance_days: '', notes: '' });

const missingOpening = computed(() => (props.items || []).filter(item => !item.balance.opening_recorded));
const canRecordOpening = computed(() => props.managed && props.employee && props.year === props.currentYear && missingOpening.value.length > 0);
const seniority = computed(() => {
    if (!props.basis || props.basis.serviceMonths === null) return '—';
    const years = props.basis.serviceYears;
    const months = props.basis.serviceRemainderMonths;
    return [years ? `${years} سنة` : '', months ? `${months} شهر` : ''].filter(Boolean).join(' و') || 'أقل من شهر';
});

function pickOpeningType(event) {
    opening.leave_type_value_id = event.target.value;
    const item = (props.items || []).find(entry => String(entry.type_id) === String(event.target.value));
    if (item?.entitlement?.status === 'defined' && opening.annual_entitlement_days === '') {
        opening.annual_entitlement_days = item.entitlement.annual_days;
    }
}
function submitOpening() {
    opening.employee_id = props.employee.id;
    opening.post('/admin/leaves/opening', { preserveScroll: true, onSuccess: () => opening.reset('leave_type_value_id', 'annual_entitlement_days', 'opening_balance_days', 'notes') });
}
function submitGrant() {
    grant.employee_id = props.employee.id;
    grant.post('/admin/leaves/grant', { preserveScroll: true });
}
function submitAdjust() {
    adjust.employee_id = props.employee.id;
    adjust.balance_year = year.value;
    adjust.post('/admin/leaves/adjust', { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="أرصدة الإجازات" :navigation="navigation">
        <div class="mx-auto max-w-5xl space-y-5">
            <Link href="/leaves" class="text-sm font-semibold text-blue-700 hover:underline">→ طلبات الإجازات</Link>
            <PageHeader eyebrow="الإجازات" title="أرصدة واستحقاقات الإجازات" description="الرصيد المتاح = الرصيد الافتتاحي + الحركات المعتمدة − حجوزات الطلبات قيد الاعتماد. الاستحقاق المتوقع للعرض والمراجعة فقط ولا يُضاف للرصيد تلقائيًا.">
                <a v-if="managed" :href="`/admin/leaves/report.csv?year=${year}`" class="btn btn-secondary">تصدير تقرير الأرصدة CSV ↓</a>
            </PageHeader>

            <form class="card flex flex-wrap items-end gap-3" @submit.prevent="filter">
                <div v-if="managed" class="min-w-64 flex-1">
                    <label for="balance-employee" class="field-label">الموظف</label>
                    <select id="balance-employee" v-model="selected" class="field">
                        <option value="">اختر موظفًا</option>
                        <option v-for="item in employees" :key="item.id" :value="item.id">{{ item.employee_number }} — {{ item.first_name_ar }} {{ item.family_name_ar }}</option>
                    </select>
                </div>
                <div>
                    <label for="balance-year" class="field-label">السنة</label>
                    <input id="balance-year" v-model="year" type="number" min="2000" max="2200" class="field w-28" />
                </div>
                <button type="submit" class="btn btn-primary">عرض</button>
            </form>

            <template v-if="employee">
                <section class="card">
                    <h2 class="card-title">{{ employee.first_name_ar }} {{ employee.family_name_ar }} <span class="text-sm font-normal text-slate-500" dir="ltr">{{ employee.employee_number }}</span></h2>
                    <p class="mt-1 text-xs text-slate-500">أساس الاستحقاق: بند التوظيف ونوع العقد والأقدمية من تاريخ مباشرة العمل الفعلي.</p>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                        <div class="rounded-xl bg-navy-50 p-3"><dt class="text-xs text-navy-600">بند التوظيف</dt><dd class="mt-1 font-semibold text-navy-800">{{ basis?.employmentTerm || 'غير محدد' }}</dd></div>
                        <div class="rounded-xl bg-navy-50 p-3"><dt class="text-xs text-navy-600">نوع العقد</dt><dd class="mt-1 font-semibold text-navy-800">{{ basis?.contractType || 'غير محدد' }}</dd></div>
                        <div class="rounded-xl bg-navy-50 p-3"><dt class="text-xs text-navy-600">نظام الدوام</dt><dd class="mt-1 font-semibold text-navy-800">{{ basis?.workSchedule || 'غير محدد' }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">تاريخ مباشرة العمل (أساس الأقدمية)</dt><dd class="mt-1 font-semibold" dir="ltr">{{ basis?.workStartDate || '—' }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">تاريخ التعيين الرسمي</dt><dd class="mt-1 font-semibold" dir="ltr">{{ basis?.hireDate || '—' }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-xs text-slate-500">الأقدمية حتى اليوم</dt><dd class="mt-1 font-semibold">{{ seniority }}</dd></div>
                    </dl>
                    <p v-if="!basis?.contractType" class="alert-warning mt-3 text-xs">نوع العقد غير محدد في ملف الموظف؛ تُطبق عليه القواعد العامة لبند التوظيف فقط.</p>
                </section>

                <section class="card">
                    <h2 class="card-title mb-4">الأرصدة لسنة {{ year }}</h2>
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[900px]">
                            <thead><tr><th>الإجازة</th><th>الاستحقاق المتوقع (للعرض)</th><th>افتتاحي</th><th>صافي الحركات</th><th>متبقٍ</th><th>محجوز</th><th>متاح</th></tr></thead>
                            <tbody>
                                <tr v-for="item in items" :key="item.type_id">
                                    <td class="font-semibold text-navy-800">{{ item.name }}</td>
                                    <td class="max-w-xs text-xs leading-5">
                                        <StatusBadge :label="entitlementText(item.entitlement).label" :tone="entitlementText(item.entitlement).tone" />
                                        <p v-if="entitlementText(item.entitlement).note" class="mt-1 text-slate-500">{{ entitlementText(item.entitlement).note }}</p>
                                    </td>
                                    <td>{{ item.balance.opening }} <span v-if="!item.balance.opening_recorded" class="text-xs text-gold-700">(غير مسجل)</span></td>
                                    <td>{{ item.balance.movements }}</td>
                                    <td>{{ item.balance.remaining }}</td>
                                    <td>{{ item.balance.reserved }}</td>
                                    <td class="font-bold text-emerald-700">{{ item.balance.available }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <form v-if="canRecordOpening" class="card space-y-3" @submit.prevent="submitOpening">
                    <h2 class="card-title">تسجيل رصيد افتتاحي — {{ currentYear }}</h2>
                    <p class="text-xs leading-5 text-slate-500">يُدخل يدويًا من المستندات المعتمدة حسب بند التوظيف، ويُسجل مرة واحدة لكل نوع وسنة. التصحيح اللاحق يتم بتسوية موثقة ولا يُعدّل السجل الأصلي.</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="opening-type" class="field-label">نوع الإجازة <span class="text-red-600">*</span></label>
                            <select id="opening-type" :value="opening.leave_type_value_id" required class="field" @change="pickOpeningType">
                                <option value="">اختر</option>
                                <option v-for="item in missingOpening" :key="item.type_id" :value="item.type_id">{{ item.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="opening-entitlement" class="field-label">الاستحقاق السنوي (يوم) <span class="text-red-600">*</span></label>
                            <input id="opening-entitlement" v-model="opening.annual_entitlement_days" type="number" min="0" max="366" step="0.5" dir="ltr" required class="field" />
                        </div>
                        <div>
                            <label for="opening-balance" class="field-label">الرصيد الافتتاحي (يوم) <span class="text-red-600">*</span></label>
                            <input id="opening-balance" v-model="opening.opening_balance_days" type="number" min="0" max="9999" step="0.5" dir="ltr" required class="field" />
                        </div>
                    </div>
                    <div>
                        <label for="opening-notes" class="field-label">مرجع المستند المعتمد <span class="text-red-600">*</span></label>
                        <input id="opening-notes" v-model="opening.notes" required minlength="5" maxlength="2000" class="field" placeholder="مثال: كشف أرصدة الموارد البشرية المعتمد بتاريخ ..." />
                    </div>
                    <p v-for="(error, key) in opening.errors" :key="key" class="field-error">{{ error }}</p>
                    <button type="submit" :disabled="opening.processing" class="btn btn-primary">تسجيل الرصيد الافتتاحي</button>
                </form>
            </template>
            <EmptyState v-else-if="managed" title="اختر موظفًا لعرض أرصدته" />
            <EmptyState v-else title="حسابك غير مرتبط بملف موظف" description="راجع الموارد البشرية لإكمال ملفك." />

            <div v-if="isAdmin && employee" class="grid gap-5 md:grid-cols-2">
                <form class="card space-y-3" @submit.prevent="submitGrant">
                    <h2 class="card-title">إثبات استحقاق سنوي كامل</h2>
                    <p class="text-sm text-slate-600">يعمل فقط إذا اعتُمدت آلية الدفعة السنوية وفُعّلت السياسة. لا يقبل التكرار.</p>
                    <div>
                        <label for="grant-type" class="field-label">نوع الإجازة <span class="text-red-600">*</span></label>
                        <select id="grant-type" v-model="grant.leave_type_value_id" required class="field"><option value="">اختر</option><option v-for="type in types" :key="type.id" :value="type.id">{{ type.name_ar }}</option></select>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="grant.confirmed" type="checkbox" /> أؤكد إثبات رصيد هذه السنة</label>
                    <p v-for="(error, key) in grant.errors" :key="key" class="field-error">{{ error }}</p>
                    <button type="submit" :disabled="grant.processing || !grant.confirmed" class="btn btn-primary">إثبات الرصيد</button>
                </form>
                <form class="card space-y-3" @submit.prevent="submitAdjust">
                    <h2 class="card-title">تسوية موثقة</h2>
                    <div>
                        <label for="adjust-type" class="field-label">نوع الإجازة <span class="text-red-600">*</span></label>
                        <select id="adjust-type" v-model="adjust.leave_type_value_id" required class="field"><option value="">اختر</option><option v-for="type in types" :key="type.id" :value="type.id">{{ type.name_ar }}</option></select>
                    </div>
                    <div>
                        <label for="adjust-days" class="field-label">عدد الأيام <span class="text-red-600">*</span></label>
                        <input id="adjust-days" v-model="adjust.amount_days" required type="number" step="0.5" dir="ltr" placeholder="موجب للإضافة، سالب للخصم" class="field" />
                    </div>
                    <div>
                        <label for="adjust-reason" class="field-label">سبب التسوية ومرجعها <span class="text-red-600">*</span></label>
                        <textarea id="adjust-reason" v-model="adjust.reason" required minlength="12" maxlength="5000" rows="3" class="field" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="adjust.confirmed" type="checkbox" /> أؤكد التسوية وتوثيقها</label>
                    <p v-for="(error, key) in adjust.errors" :key="key" class="field-error">{{ error }}</p>
                    <button type="submit" :disabled="adjust.processing || !adjust.confirmed" class="btn btn-primary">حفظ التسوية</button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
