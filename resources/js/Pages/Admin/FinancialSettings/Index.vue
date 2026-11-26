<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    username: { type: String, required: true },
    navigation: { type: Array, default: () => [] },
});

const loading = ref(true);
const savingCurrency = ref(false);
const savingLimit = ref(false);
const error = ref('');
const success = ref('');
const fieldErrors = ref({});

const currencies = ref([]);
const baseCurrency = ref('');
const savedBaseCurrency = ref('');
const limits = ref([]);

const authorities = [
    { code: 'executive_director', label: 'المدير التنفيذي' },
    { code: 'treasurer', label: 'أمين الصندوق' },
    { code: 'board_chairman', label: 'رئيس مجلس الإدارة' },
];

const actions = [
    { code: 'approve', label: 'اعتماد مالي' },
    { code: 'disburse', label: 'تنفيذ صرف' },
];

const form = reactive({
    authority_code: 'executive_director',
    action_code: 'approve',
    currency: 'ILS',
    limit_amount: '',
    is_active: false,
    decision_reference: '',
    change_reason: '',
});

const activeCurrencies = computed(() =>
    currencies.value.filter(currency => currency.is_active)
);

const selectedCurrency = computed(() =>
    currencies.value.find(currency => currency.code === form.currency)
);

const matchingLimit = computed(() =>
    limits.value.find(limit =>
        limit.authority_code === form.authority_code &&
        limit.action_code === form.action_code &&
        limit.currency === form.currency
    )
);

function clearMessages() {
    error.value = '';
    success.value = '';
    fieldErrors.value = {};
}

function showError(exception) {
    fieldErrors.value = exception.response?.data?.errors || {};
    error.value =
        exception.response?.data?.message ||
        'تعذر تنفيذ العملية. يرجى المحاولة مجددًا.';
}

function amountFromMinorUnits(amount, decimals) {
    if (amount === null || amount === undefined) return '';

    const factor = 10 ** decimals;
    const value = Number(amount);

    if (!Number.isSafeInteger(value)) return '';

    return (value / factor).toFixed(decimals);
}

function loadSelectedLimit() {
    const record = matchingLimit.value;

    form.limit_amount = record
        ? amountFromMinorUnits(
            record.limit_minor_units,
            selectedCurrency.value?.decimal_places ?? 2
        )
        : '';

    form.is_active = record ? Boolean(record.is_active) : false;
    form.decision_reference = record?.decision_reference || '';
    form.change_reason = '';

    clearMessages();
}

async function loadSettings() {
    loading.value = true;
    clearMessages();

    try {
        const response = await axios.get('/admin/financial-settings');

        currencies.value = response.data.currencies || [];
        limits.value = response.data.financial_limits || [];

        baseCurrency.value = response.data.base_currency || '';
        savedBaseCurrency.value = baseCurrency.value;

        if (!currencies.value.some(c => c.code === form.currency)) {
            form.currency = baseCurrency.value;
        }

        loadSelectedLimit();
    } catch (exception) {
        showError(exception);
    } finally {
        loading.value = false;
    }
}

async function saveBaseCurrency() {
    if (savingCurrency.value) return;

    savingCurrency.value = true;
    clearMessages();

    try {
        const response = await axios.put(
            '/admin/financial-settings/base-currency',
            { base_currency_code: baseCurrency.value }
        );

        savedBaseCurrency.value = response.data.base_currency_code;
        success.value = response.data.message;
    } catch (exception) {
        showError(exception);
    } finally {
        savingCurrency.value = false;
    }
}

async function saveLimit() {
    if (savingLimit.value) return;

    savingLimit.value = true;
    clearMessages();

    try {
        const response = await axios.put(
            '/admin/financial-settings/limits',
            {
                authority_code: form.authority_code,
                action_code: form.action_code,
                currency: form.currency,
                limit_amount: form.limit_amount || null,
                is_active: form.is_active,
                decision_reference: form.decision_reference || null,
                change_reason: form.change_reason,
            }
        );

        success.value = response.data.message;
        limits.value = limits.value.filter(item =>
            !(
                item.authority_code === form.authority_code &&
                item.action_code === form.action_code &&
                item.currency === form.currency
            )
        );

        limits.value.push(response.data.financial_limit);
        loadSelectedLimit();
        success.value = response.data.message;
    } catch (exception) {
        showError(exception);
    } finally {
        savingLimit.value = false;
    }
}

onMounted(loadSettings);
</script>

<template>
    <AppLayout
        title="إعدادات العملات والصلاحيات المالية"
    >
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="الشؤون المالية" title="العملات والصلاحيات المالية" description="العملات الأساسية وسقوف الصلاحيات للمناصب المخوّلة." />

            <div class="alert-warning">
                تنفيذ الموافقات المالية والصرف معطّل حاليًا.
                حفظ السقوف لا يمنح أي مستخدم صلاحية تنفيذ اعتماد أو صرف.
            </div>

            <div
                v-if="error"
                role="alert"
                class="alert-error"
            >
                {{ error }}

                <ul v-if="Object.keys(fieldErrors).length" class="mt-2 list-inside list-disc">
                    <li
                        v-for="(messages, field) in fieldErrors"
                        :key="field"
                    >
                        {{ Array.isArray(messages) ? messages.join('، ') : messages }}
                    </li>
                </ul>
            </div>

            <div
                v-if="success"
                role="status"
                class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"
            >
                {{ success }}
            </div>

            <div v-if="loading" class="rounded-xl bg-white p-8 text-center">
                جارٍ تحميل الإعدادات...
            </div>

            <template v-else>
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 text-lg font-bold text-slate-800">
                        العملة الأساسية للمؤسسة
                    </h2>

                    <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                        <label class="block">
                            <span class="field-label">
                                اختر العملة الأساسية
                            </span>

                            <select
                                v-model="baseCurrency"
                                class="field"
                            >
                                <option
                                    v-for="currency in activeCurrencies"
                                    :key="currency.code"
                                    :value="currency.code"
                                >
                                    {{ currency.name_ar }} ({{ currency.code }})
                                </option>
                            </select>
                        </label>

                        <button
                            type="button"
                            :disabled="savingCurrency || baseCurrency === savedBaseCurrency"
                            class="btn btn-primary"
                            @click="saveBaseCurrency"
                        >
                            {{ savingCurrency ? 'جارٍ الحفظ...' : 'حفظ العملة الأساسية' }}
                        </button>
                    </div>

                    <p class="mt-3 text-xs text-slate-500">
                        تغيير العملة الأساسية لا يحوّل السقوف المالية أو المعاملات السابقة.
                    </p>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-5 text-lg font-bold text-slate-800">
                        تحديد سقف الصلاحية المالية
                    </h2>

                    <form class="space-y-4" @submit.prevent="saveLimit">
                        <div class="grid gap-4 md:grid-cols-3">
                            <label class="block">
                                <span class="field-label">المنصب</span>
                                <select
                                    v-model="form.authority_code"
                                    class="field"
                                    @change="loadSelectedLimit"
                                >
                                    <option
                                        v-for="authority in authorities"
                                        :key="authority.code"
                                        :value="authority.code"
                                    >
                                        {{ authority.label }}
                                    </option>
                                </select>
                            </label>

                            <label class="block">
                                <span class="field-label">نوع الصلاحية</span>
                                <select
                                    v-model="form.action_code"
                                    class="field"
                                    @change="loadSelectedLimit"
                                >
                                    <option
                                        v-for="action in actions"
                                        :key="action.code"
                                        :value="action.code"
                                    >
                                        {{ action.label }}
                                    </option>
                                </select>
                            </label>

                            <label class="block">
                                <span class="field-label">العملة</span>
                                <select
                                    v-model="form.currency"
                                    class="field"
                                    @change="loadSelectedLimit"
                                >
                                    <option
                                        v-for="currency in activeCurrencies"
                                        :key="currency.code"
                                        :value="currency.code"
                                    >
                                        {{ currency.name_ar }} ({{ currency.code }})
                                    </option>
                                </select>
                            </label>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="field-label">
                                    السقف المالي ({{ form.currency }})
                                </span>

                                <input
                                    v-model.trim="form.limit_amount"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="مثال: 500.00"
                                    class="field"
                                />
                            </label>

                            <label class="block">
                                <span class="field-label">
                                    مرجع قرار التفويض
                                </span>

                                <input
                                    v-model.trim="form.decision_reference"
                                    type="text"
                                    placeholder="رقم القرار أو مرجعه"
                                    class="field"
                                />
                            </label>
                        </div>

                        <label class="flex items-center gap-3">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="h-5 w-5"
                            />
                            <span class="text-sm font-medium">
                                تفعيل سجل هذا السقف المالي
                            </span>
                        </label>

                        <label class="block">
                            <span class="field-label">
                                سبب الإضافة أو التعديل
                            </span>

                            <textarea
                                v-model.trim="form.change_reason"
                                rows="3"
                                required
                                minlength="5"
                                placeholder="أدخل سبب التعديل..."
                                class="field"
                            />
                        </label>

                        <button
                            type="submit"
                            :disabled="savingLimit"
                            class="btn btn-primary"
                        >
                            {{ savingLimit ? 'جارٍ الحفظ...' : 'حفظ السقف المالي' }}
                        </button>
                    </form>
                </section>

                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="p-5 text-lg font-bold text-slate-800">
                        السقوف المالية المسجلة
                    </h2>

                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th>المنصب</th>
                                    <th>الصلاحية</th>
                                    <th>السقف</th>
                                    <th>الحالة</th>
                                    <th>مرجع القرار</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    v-for="limit in limits"
                                    :key="limit.id"
                                    class="border-t border-slate-200"
                                >
                                    <td>
                                        {{ authorities.find(a => a.code === limit.authority_code)?.label || limit.authority_code }}
                                    </td>
                                    <td>
                                        {{ actions.find(a => a.code === limit.action_code)?.label || limit.action_code }}
                                    </td>
                                    <td class="p-3" dir="ltr">
                                        {{
                                            limit.is_unlimited
                                                ? 'غير محدود'
                                                : amountFromMinorUnits(
                                                    limit.limit_minor_units,
                                                    currencies.find(c => c.code === limit.currency)?.decimal_places ?? 2
                                                )
                                        }}
                                        {{ limit.currency }}
                                    </td>
                                    <td>
                                        {{ limit.is_active ? 'سجل مفعّل' : 'معطّل' }}
                                    </td>
                                    <td>
                                        {{ limit.decision_reference || '—' }}
                                    </td>
                                </tr>

                                <tr v-if="!limits.length">
                                    <td colspan="5" class="p-6 text-center text-slate-500">
                                        لم تُسجل أي سقوف مالية بعد.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
