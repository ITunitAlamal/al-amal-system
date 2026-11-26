<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, default: '' },
    navigation: { type: Array, default: () => [] },
    rule: { type: Object, default: null },
    employmentTerms: { type: Array, default: () => [] },
    contractTypes: { type: Array, default: () => [] },
    leaveTypes: { type: Array, default: () => [] },
});
const base = '/admin/leave-entitlement-rules';
const editing = computed(() => Boolean(props.rule?.id));
const dateOnly = value => value ? String(value).slice(0, 10) : '';
const today = new Date().toLocaleDateString('en-CA');
const isHistorical = computed(() => editing.value && dateOnly(props.rule.effective_from) <= today);
const form = useForm({
    code: props.rule?.code ?? '',
    name_ar: props.rule?.name_ar ?? '',
    employment_term_value_id: props.rule?.employment_term_value_id ?? '',
    contract_type_value_id: props.rule?.contract_type_value_id ?? '',
    leave_type_value_id: props.rule?.leave_type_value_id ?? '',
    min_service_months: props.rule?.min_service_months ?? 0,
    max_service_months: props.rule?.max_service_months ?? '',
    annual_entitlement_days: props.rule?.annual_entitlement_days ?? '',
    effective_from: dateOnly(props.rule?.effective_from),
    effective_until: dateOnly(props.rule?.effective_until),
    is_active: props.rule ? Boolean(props.rule.is_active) : true,
    notes: props.rule?.notes ?? '',
});
const noMasterData = computed(() => !props.employmentTerms.length || !props.leaveTypes.length);
function submit() {
    if (form.processing || noMasterData.value || isHistorical.value) return;
    form.transform(data => ({
        ...data,
        employment_term_value_id: Number(data.employment_term_value_id),
        contract_type_value_id: data.contract_type_value_id ? Number(data.contract_type_value_id) : null,
        leave_type_value_id: Number(data.leave_type_value_id),
        min_service_months: Number(data.min_service_months),
        max_service_months: data.max_service_months === '' || data.max_service_months === null ? null : Number(data.max_service_months),
        annual_entitlement_days: Number(data.annual_entitlement_days),
        effective_until: data.effective_until || null,
    }));
    const options = { preserveScroll: true };
    if (editing.value) form.put(`${base}/${props.rule.id}`, options);
    else form.post(base, options);
}


// Bulk creation: one employment term, all approved leave types in each seniority tier.
const newEntitlements = () => props.leaveTypes.map(type => ({
    leave_type_value_id: Number(type.id),
    annual_entitlement_days: '',
}));
const newTier = (fromYears = '', toYears = '') => ({
    from_years: fromYears,
    to_years: toYears,
    entitlements: newEntitlements(),
});
const bulk = useForm({
    employment_term_value_id: '',
    contract_type_value_id: '',
    effective_from: '',
    effective_until: '',
    notes: '',
    tiers: [newTier(0, '')],
});
const bulkError = computed(() => {
    if (!bulk.employment_term_value_id) return 'اختر بند التوظيف.';
    if (!bulk.effective_from) return 'حدد تاريخ بداية سريان السياسة.';
    if (bulk.effective_until && bulk.effective_until <= bulk.effective_from) return 'تاريخ انتهاء السياسة يجب أن يلي تاريخ البداية.';
    if (!bulk.tiers.length) return 'أضف شريحة أقدمية واحدة على الأقل.';
    const segments = [];
    for (let i = 0; i < bulk.tiers.length; i++) {
        const tier = bulk.tiers[i];
        const lo = Number(tier.from_years);
        const hi = tier.to_years === '' || tier.to_years === null ? null : Number(tier.to_years);
        if (tier.from_years === '' || !Number.isInteger(lo) || lo < 0 || lo > 100) return `راجع بداية الشريحة ${i + 1}.`;
        if (hi !== null && (!Number.isInteger(hi) || hi <= lo || hi > 101)) return `راجع نهاية الشريحة ${i + 1}.`;
        if (tier.entitlements.length !== props.leaveTypes.length) return 'لم يتم تحميل جميع أنواع الإجازات.';
        for (const row of tier.entitlements) {
            const num = Number(row.annual_entitlement_days);
            if (row.annual_entitlement_days === '' || row.annual_entitlement_days === null || !Number.isFinite(num) || num < 0 || num > 999999.99 || !/^\d+(?:\.\d{1,2})?$/.test(String(row.annual_entitlement_days))) {
                const type = props.leaveTypes.find(x => Number(x.id) === row.leave_type_value_id);
                return `أدخل عدد أيام صحيحًا لـ${type?.name_ar || 'الإجازة'} في الشريحة ${i + 1} (يمكن إدخال صفر).`;
            }
        }
        segments.push({ lo, hi });
    }
    for (let i = 0; i < segments.length; i++) for (let j = i + 1; j < segments.length; j++) {
        const a = segments[i], b = segments[j];
        if ((a.hi === null || b.lo < a.hi) && (b.hi === null || a.lo < b.hi)) return 'شرائح سنوات الخدمة متداخلة. يجب ألا تتداخل الفترات.';
    }
    return '';
});
function addBulkTier() {
    if (bulk.tiers.length >= 30) return;
    const last = bulk.tiers[bulk.tiers.length - 1];
    bulk.tiers.push(newTier(last?.to_years ?? '', ''));
}
function submitBulk() {
    if (bulk.processing || noMasterData.value || bulkError.value) return;
    bulk.clearErrors();
    bulk.transform(data => ({
        employment_term_value_id: Number(data.employment_term_value_id),
        contract_type_value_id: data.contract_type_value_id ? Number(data.contract_type_value_id) : null,
        effective_from: data.effective_from,
        effective_until: data.effective_until || null,
        notes: data.notes || null,
        tiers: data.tiers.map(tier => ({
            min_service_months: Number(tier.from_years) * 12,
            max_service_months: tier.to_years === '' || tier.to_years === null ? null : Number(tier.to_years) * 12,
            entitlements: tier.entitlements.map(item => ({
                leave_type_value_id: Number(item.leave_type_value_id),
                annual_entitlement_days: Number(item.annual_entitlement_days),
            })),
        })),
    }));
    bulk.post(`${base}/bulk`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="editing ? 'تعديل سياسة إجازات' : 'إضافة سياسة إجازات'">
        <div v-if="editing" class="mx-auto max-w-4xl space-y-5">
            <nav aria-label="مسار التنقل" class="flex flex-wrap gap-2 text-sm text-slate-600">
                <Link href="/admin/master-data" class="font-semibold text-blue-700 hover:underline">ثوابت النظام</Link><span aria-hidden="true">←</span>
                <Link :href="base" class="font-semibold text-blue-700 hover:underline">سياسات الإجازات</Link><span aria-hidden="true">←</span>
                <span>{{ editing ? 'تعديل سياسة' : 'إضافة سياسة' }}</span>
            </nav>
            <PageHeader eyebrow="ثوابت الموارد البشرية" :title="editing ? 'تعديل سياسة استحقاق' : 'إضافة سياسة استحقاق جديدة'" description="تطبّق السياسة على الموظفين بحسب بند التوظيف ونوع الإجازة والأقدمية المحسوبة من تاريخ مباشرة العمل. لا تُغيّر الأرصدة السابقة تلقائيًا." />
            <div v-if="noMasterData" role="alert" class="alert-warning">يجب اعتماد قيم بند التوظيف ونوع الإجازة أولًا في ثوابت النظام؛ لا يمكن حفظ سياسة بدونها.</div>
            <div v-if="isHistorical" role="alert" class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">بدأ سريان هذه السياسة، ولذلك أصبحت محفوظة كسجل تاريخي لا يمكن تعديله. لإجراء تغيير، أنشئ سياسة جديدة بتاريخ مستقبلي.</div>
            <form class="space-y-6 card md:p-7" @submit.prevent="submit" novalidate>
                <div v-if="form.hasErrors" role="alert" class="alert-error">تعذّر الحفظ. راجع الأخطاء الموضحة بجانب الحقول أدناه.</div>
                <fieldset :disabled="isHistorical || form.processing" class="grid gap-5 md:grid-cols-2 disabled:opacity-65">
                    <div>
                        <label for="policy-code" class="field-label">رمز السياسة *</label>
                        <input id="policy-code" v-model.trim="form.code" dir="ltr" maxlength="100" required placeholder="annual_permanent_0_59" class="field" />
                        <p class="mt-1 text-xs text-slate-500">حروف إنجليزية صغيرة وأرقام وشرطة سفلية، ويبدأ بحرف.</p>
                        <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label for="policy-name" class="field-label">اسم السياسة *</label>
                        <input id="policy-name" v-model.trim="form.name_ar" maxlength="255" required class="field" />
                        <p v-if="form.errors.name_ar" class="field-error">{{ form.errors.name_ar }}</p>
                    </div>
                    <div>
                        <label for="policy-term" class="field-label">بند التوظيف *</label>
                        <select id="policy-term" v-model="form.employment_term_value_id" required class="field">
                            <option value="">اختر بند التوظيف</option>
                            <option v-for="term in employmentTerms" :key="term.id" :value="term.id">{{ term.name_ar }}</option>
                        </select>
                        <p v-if="form.errors.employment_term_value_id" class="field-error">{{ form.errors.employment_term_value_id }}</p>
                    </div>
                    <div>
                        <label for="policy-contract" class="field-label">نوع العقد</label>
                        <select id="policy-contract" v-model="form.contract_type_value_id" class="field">
                            <option value="">جميع أنواع العقود</option>
                            <option v-for="type in contractTypes" :key="type.id" :value="type.id">{{ type.name_ar }}</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">القاعدة الخاصة بنوع عقد تتقدم على القاعدة العامة لجميع العقود.</p>
                        <p v-if="form.errors.contract_type_value_id" class="field-error">{{ form.errors.contract_type_value_id }}</p>
                    </div>
                    <div>
                        <label for="policy-type" class="field-label">نوع الإجازة *</label>
                        <select id="policy-type" v-model="form.leave_type_value_id" required class="field">
                            <option value="">اختر نوع الإجازة</option>
                            <option v-for="type in leaveTypes" :key="type.id" :value="type.id">{{ type.name_ar }}</option>
                        </select>
                        <p v-if="form.errors.leave_type_value_id" class="field-error">{{ form.errors.leave_type_value_id }}</p>
                    </div>
                    <div>
                        <label for="min-months" class="field-label">الأقدمية من (شهر شامل) *</label>
                        <input id="min-months" v-model.number="form.min_service_months" type="number" min="0" step="1" required class="field" />
                        <p v-if="form.errors.min_service_months" class="field-error">{{ form.errors.min_service_months }}</p>
                    </div>
                    <div>
                        <label for="max-months" class="field-label">الأقدمية إلى (شهر غير شامل)</label>
                        <input id="max-months" v-model="form.max_service_months" type="number" min="1" step="1" placeholder="اتركه فارغًا بدون حد أعلى" class="field" />
                        <p v-if="form.errors.max_service_months" class="field-error">{{ form.errors.max_service_months }}</p>
                    </div>
                    <div>
                        <label for="entitlement" class="field-label">الاستحقاق السنوي بالأيام *</label>
                        <input id="entitlement" v-model="form.annual_entitlement_days" type="number" min="0" max="999999.99" step="0.01" required class="field" />
                        <p v-if="form.errors.annual_entitlement_days" class="field-error">{{ form.errors.annual_entitlement_days }}</p>
                    </div>
                    <div class="flex items-center gap-3 pt-6">
                        <input id="policy-active" v-model="form.is_active" type="checkbox" class="h-5 w-5 rounded border-slate-300" />
                        <label for="policy-active" class="text-sm font-semibold">السياسة نشطة</label>
                        <p v-if="form.errors.is_active" class="text-sm text-red-700">{{ form.errors.is_active }}</p>
                    </div>
                    <div>
                        <label for="effective-from" class="field-label">تاريخ بداية السريان (شامل) *</label>
                        <input id="effective-from" v-model="form.effective_from" type="date" required class="field" />
                        <p v-if="form.errors.effective_from" class="field-error">{{ form.errors.effective_from }}</p>
                    </div>
                    <div>
                        <label for="effective-until" class="field-label">تاريخ نهاية السريان (غير شامل)</label>
                        <input id="effective-until" v-model="form.effective_until" type="date" class="field" />
                        <p v-if="form.errors.effective_until" class="field-error">{{ form.errors.effective_until }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="policy-notes" class="field-label">ملاحظات</label>
                        <textarea id="policy-notes" v-model="form.notes" rows="3" maxlength="10000" class="field"></textarea>
                        <p v-if="form.errors.notes" class="field-error">{{ form.errors.notes }}</p>
                    </div>
                </fieldset>
                <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                    <button v-if="!isHistorical" type="submit" :disabled="form.processing || noMasterData" class="btn btn-primary disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'جارٍ الحفظ...' : editing ? 'حفظ التعديلات' : 'إضافة السياسة' }}</button>
                    <Link :href="base" class="btn btn-secondary">العودة للقائمة</Link>
                </div>
            </form>
        </div>

        <div v-else class="mx-auto max-w-6xl space-y-6">
            <nav aria-label="مسار التنقل" class="flex flex-wrap gap-2 text-sm text-slate-600">
                <Link href="/admin/master-data" class="font-semibold text-blue-700 hover:underline">ثوابت النظام</Link><span aria-hidden="true">←</span>
                <Link :href="base" class="font-semibold text-blue-700 hover:underline">سياسات الإجازات</Link><span aria-hidden="true">←</span>
                <span>إضافة سياسة لبند توظيف</span>
            </nav>
            <PageHeader eyebrow="ثوابت الموارد البشرية" title="إضافة استحقاقات الإجازات حسب سنوات الخدمة" description="اختر بند التوظيف مرة واحدة، ثم أدخل عدد أيام كل نوع إجازة لكل شريحة من سنوات الخدمة. جميع الأنواع مأخوذة من ثوابت النظام دون تعديل عليها." />
            <div v-if="noMasterData" role="alert" class="rounded-xl border border-gold-300 bg-gold-50 p-4 text-sm text-gold-900">
                بنود التوظيف أو أنواع الإجازات غير موجودة ضمن الثوابت النشطة. اعتمد هذه القيم في ثوابت النظام قبل حفظ سياسة جديدة.
            </div>
            <form class="space-y-6" novalidate @submit.prevent="submitBulk">
                <section class="card md:p-7">
                    <h2 class="card-title">1. بيانات السياسة</h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="bulk-term" class="field-label">بند التوظيف *</label>
                            <select id="bulk-term" v-model="bulk.employment_term_value_id" required class="field">
                                <option value="">اختر بند التوظيف</option>
                                <option v-for="term in employmentTerms" :key="term.id" :value="term.id">{{ term.name_ar }}</option>
                            </select>
                            <p v-if="bulk.errors.employment_term_value_id" class="field-error">{{ bulk.errors.employment_term_value_id }}</p>
                        </div>
                        <div>
                            <label for="bulk-contract" class="field-label">نوع العقد</label>
                            <select id="bulk-contract" v-model="bulk.contract_type_value_id" class="field">
                                <option value="">جميع أنواع العقود</option>
                                <option v-for="type in contractTypes" :key="type.id" :value="type.id">{{ type.name_ar }}</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">اتركه «جميع أنواع العقود» ما لم تختلف الاستحقاقات حسب العقد.</p>
                            <p v-if="bulk.errors.contract_type_value_id" class="field-error">{{ bulk.errors.contract_type_value_id }}</p>
                        </div>
                        <div>
                            <label for="bulk-from" class="field-label">بداية سريان السياسة *</label>
                            <input id="bulk-from" v-model="bulk.effective_from" type="date" required class="field" />
                            <p v-if="bulk.errors.effective_from" class="field-error">{{ bulk.errors.effective_from }}</p>
                        </div>
                        <div>
                            <label for="bulk-until" class="field-label">نهاية سريان السياسة (اختياري)</label>
                            <input id="bulk-until" v-model="bulk.effective_until" type="date" class="field" />
                            <p class="mt-1 text-xs text-slate-500">اتركه فارغًا إذا استمرت السياسة دون تاريخ انتهاء.</p>
                            <p v-if="bulk.errors.effective_until" class="field-error">{{ bulk.errors.effective_until }}</p>
                        </div>
                        <div>
                            <label for="bulk-notes" class="field-label">ملاحظات السياسة (اختياري)</label>
                            <textarea id="bulk-notes" v-model="bulk.notes" rows="2" maxlength="10000" class="field"></textarea>
                            <p v-if="bulk.errors.notes" class="field-error">{{ bulk.errors.notes }}</p>
                        </div>
                    </div>
                </section>
                <section class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="card-title">2. شرائح سنوات الخدمة والاستحقاقات</h2>
                            <p class="mt-1 text-sm text-slate-600">النهاية غير شاملة. مثال: من 0 إلى أقل من 5، ثم من 5 إلى أقل من 10. فارغ = فما فوق.</p>
                        </div>
                        <button type="button" :disabled="bulk.processing || bulk.tiers.length >= 30" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold disabled:opacity-50" @click="addBulkTier">+ إضافة شريحة</button>
                    </div>
                    <div v-for="(tier, tierIndex) in bulk.tiers" :key="tierIndex" class="card md:p-6">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <h3 class="font-bold text-navy-800">شريحة الأقدمية {{ tierIndex + 1 }}</h3>
                            <button v-if="bulk.tiers.length > 1" type="button" :disabled="bulk.processing" class="text-sm font-semibold text-red-700 hover:underline" @click="bulk.tiers.splice(tierIndex, 1)">حذف هذه الشريحة</button>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label :for="`tier-from-${tierIndex}`" class="field-label">من سنة خدمة (شاملة) *</label>
                                <input :id="`tier-from-${tierIndex}`" v-model="tier.from_years" type="number" min="0" max="100" step="1" required class="field" />
                                <p v-if="bulk.errors[`tiers.${tierIndex}.min_service_months`]" class="field-error">{{ bulk.errors[`tiers.${tierIndex}.min_service_months`] }}</p>
                            </div>
                            <div>
                                <label :for="`tier-to-${tierIndex}`" class="field-label">إلى أقل من سنة (فارغ = فما فوق)</label>
                                <input :id="`tier-to-${tierIndex}`" v-model="tier.to_years" type="number" min="1" max="101" step="1" placeholder="بدون حد أعلى" class="field" />
                                <p v-if="bulk.errors[`tiers.${tierIndex}.max_service_months`]" class="field-error">{{ bulk.errors[`tiers.${tierIndex}.max_service_months`] }}</p>
                            </div>
                        </div>
                        <div class="mt-5 overflow-x-auto">
                            <table class="w-full min-w-[460px] border-separate border-spacing-0 text-right text-sm">
                                <thead><tr class="bg-slate-100 text-slate-700"><th class="rounded-r-lg px-3 py-3">نوع الإجازة</th><th class="rounded-l-lg px-3 py-3">الاستحقاق السنوي (بالأيام) *</th></tr></thead>
                                <tbody>
                                    <tr v-for="(item, leaveIndex) in tier.entitlements" :key="item.leave_type_value_id" class="border-b border-slate-100">
                                        <td class="border-b border-slate-100 px-3 py-3 font-medium text-slate-800">{{ leaveTypes.find(x => Number(x.id) === Number(item.leave_type_value_id))?.name_ar ?? 'نوع غير معروف' }}</td>
                                        <td class="border-b border-slate-100 px-3 py-3">
                                            <input v-model="item.annual_entitlement_days" type="number" min="0" step="0.01" placeholder="اكتب عدد الأيام (0 عند عدم الاستحقاق)" :aria-label="`استحقاق ${leaveTypes.find(x => Number(x.id) === Number(item.leave_type_value_id))?.name_ar ?? 'الإجازة'} للشريحة ${tierIndex + 1}`" class="field" />
                                            <p v-if="bulk.errors[`tiers.${tierIndex}.entitlements.${leaveIndex}.annual_entitlement_days`]" class="field-error">{{ bulk.errors[`tiers.${tierIndex}.entitlements.${leaveIndex}.annual_entitlement_days`] }}</p>
                                            <p v-if="bulk.errors[`tiers.${tierIndex}.entitlements.${leaveIndex}.leave_type_value_id`]" class="field-error">{{ bulk.errors[`tiers.${tierIndex}.entitlements.${leaveIndex}.leave_type_value_id`] }}</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="!leaveTypes.length" class="mt-2 text-sm text-gold-800">لا توجد أنواع إجازات نشطة في ثوابت النظام.</p>
                            <p class="mt-2 text-xs text-slate-500">إذا لم يستحق الموظف نوعًا معينًا ضمن الشريحة، اكتب صفرًا صراحةً بدل ترك الحقل فارغًا.</p>
                        </div>
                    </div>
                </section>
                <div v-if="bulkError && !noMasterData" role="alert" class="alert-warning">{{ bulkError }}</div>
                <div v-if="bulk.hasErrors" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <p class="font-semibold">تعذر حفظ السياسة. راجع البيانات والرسائل التالية:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1"><li v-for="(message, key) in bulk.errors" :key="key">{{ message }}</li></ul>
                </div>
                <div class="flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                    <button type="submit" :disabled="bulk.processing || noMasterData || Boolean(bulkError)" class="btn btn-primary">{{ bulk.processing ? 'جارٍ الحفظ...' : 'حفظ جميع الاستحقاقات' }}</button>
                    <Link :href="base" class="btn btn-secondary">العودة للقائمة</Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
