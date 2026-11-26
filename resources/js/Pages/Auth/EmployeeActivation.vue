<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import PortalFooter from '../../Components/PortalFooter.vue';

const props = defineProps({
    employeeNumber: { type: String, default: '' },
});

const page = usePage();
const form = useForm({
    employee_number: props.employeeNumber || '',
    code: '',
    password: '',
    password_confirmation: '',
});
const resendForm = useForm({ employee_number: props.employeeNumber || '' });
const showPassword = ref(false);
const showConfirmation = ref(false);
const isValidCode = computed(() => /^\d{6}$/.test(form.code));
const passwordLongEnough = computed(() => form.password.length >= 12);
const passwordsMatch = computed(() => form.password.length > 0 && form.password === form.password_confirmation);
const canSubmit = computed(() => !form.processing && form.employee_number.trim() !== '' && isValidCode.value && passwordLongEnough.value && passwordsMatch.value);
const inputClass = 'field py-3';

function activate() {
    if (!canSubmit.value) return;
    form.post('/activate-account', {
        preserveScroll: true,
        onSuccess: () => form.reset('code', 'password', 'password_confirmation'),
    });
}

function resend() {
    if (resendForm.processing || !form.employee_number.trim()) return;
    resendForm.employee_number = form.employee_number.trim();
    resendForm.post('/activate-account/resend', {
        preserveScroll: true,
        onSuccess: () => {
            form.clearErrors('code');
            form.code = '';
        },
    });
}
</script>

<template>
    <div dir="rtl" class="flex min-h-screen flex-col bg-canvas">
    <Head title="تفعيل حساب الموظف" />
    <main class="flex flex-1 items-center justify-center px-4 py-10 text-slate-800">
        <div class="w-full max-w-xl">
            <header class="mb-7 text-center">
                <img src="/images/brand/logo.png" alt="شعار معهد الأمل للأيتام" class="mx-auto mb-4 h-28 w-auto">
                <p class="eyebrow">نظام الإدارة المؤسسية</p>
                <h1 class="mt-2 text-2xl font-bold text-navy-800">تفعيل حساب الموظف</h1>
                <p class="mt-3 text-sm leading-7 text-slate-600">أدخل الرمز المرسل إلى جوالك الأساسي، ثم اختر كلمة المرور التي ستستخدمها مع رقمك الوظيفي.</p>
            </header>

            <section class="card sm:p-8">
                <div v-if="page.props.flash?.success" role="status" class="alert-success mb-5">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="form.hasErrors" role="alert" class="alert-error mb-5">يرجى مراجعة الأخطاء الظاهرة أسفل الحقول.</div>

                <form class="space-y-5" @submit.prevent="activate">
                    <div>
                        <label for="employee-number" class="field-label">الرقم الوظيفي <span class="text-red-600">*</span></label>
                        <input id="employee-number" v-model.trim="form.employee_number" :class="inputClass" dir="ltr" autocomplete="username" required maxlength="50" placeholder="EMP-0100" :aria-invalid="Boolean(form.errors.employee_number)" />
                        <p v-if="form.errors.employee_number" class="field-error">{{ form.errors.employee_number }}</p>
                    </div>

                    <div>
                        <label for="activation-code" class="field-label">رمز التفعيل (6 أرقام) <span class="text-red-600">*</span></label>
                        <input id="activation-code" v-model="form.code" :class="inputClass" dir="ltr" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="000000" :aria-invalid="Boolean(form.errors.code)" />
                        <p class="mt-1 text-xs text-slate-500">صلاحية الرمز خمس دقائق، ولا يمكن استخدامه أكثر من مرة.</p>
                        <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
                    </div>

                    <div>
                        <label for="new-password" class="field-label">كلمة المرور الجديدة <span class="text-red-600">*</span></label>
                        <div class="relative">
                            <input id="new-password" v-model="form.password" :type="showPassword ? 'text' : 'password'" :class="inputClass + ' pl-20'" autocomplete="new-password" minlength="12" maxlength="128" required :aria-invalid="Boolean(form.errors.password)" />
                            <button type="button" class="absolute inset-y-0 left-2 px-3 text-sm font-medium text-slate-600" @click="showPassword = !showPassword">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button>
                        </div>
                        <p class="mt-1 text-xs" :class="passwordLongEnough ? 'text-emerald-700' : 'text-slate-500'">{{ passwordLongEnough ? '✓' : '○' }} 12 حرفًا على الأقل</p>
                        <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label for="confirm-password" class="field-label">تأكيد كلمة المرور <span class="text-red-600">*</span></label>
                        <div class="relative">
                            <input id="confirm-password" v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" :class="inputClass + ' pl-20'" autocomplete="new-password" minlength="12" maxlength="128" required />
                            <button type="button" class="absolute inset-y-0 left-2 px-3 text-sm font-medium text-slate-600" @click="showConfirmation = !showConfirmation">{{ showConfirmation ? 'إخفاء' : 'إظهار' }}</button>
                        </div>
                        <p v-if="form.password_confirmation" class="mt-1 text-xs" :class="passwordsMatch ? 'text-emerald-700' : 'text-gold-800'">{{ passwordsMatch ? 'كلمتا المرور متطابقتان' : 'كلمتا المرور غير متطابقتين' }}</p>
                        <p v-if="form.errors.password_confirmation" class="field-error">{{ form.errors.password_confirmation }}</p>
                    </div>

                    <button type="submit" :disabled="!canSubmit" class="btn btn-primary w-full py-3 text-base">
                        {{ form.processing ? 'جارٍ التحقق والتفعيل...' : 'تفعيل الحساب وحفظ كلمة المرور' }}
                    </button>
                </form>

                <div class="mt-6 border-t border-slate-200 pt-5">
                    <p class="mb-3 text-sm text-slate-600">لم يصلك الرمز أو انتهت صلاحيته؟ يمكنك طلب رمز جديد إلى الجوال المسجل لدى الموارد البشرية.</p>
                    <button type="button" :disabled="resendForm.processing || !form.employee_number.trim()" class="btn btn-secondary" @click="resend">
                        {{ resendForm.processing ? 'جارٍ طلب الرمز...' : 'إعادة إرسال رمز التفعيل' }}
                    </button>
                    <p v-if="resendForm.errors.employee_number" class="field-error">{{ resendForm.errors.employee_number }}</p>
                    <p class="mt-2 text-xs text-slate-500">لتقليل الرسائل المتكررة، توجد حدود لعدد طلبات إعادة الإرسال.</p>
                </div>
            </section>

            <p class="mt-5 text-center text-sm"><Link href="/login" class="font-semibold text-blue-700 hover:underline">العودة إلى تسجيل الدخول</Link></p>
        </div>
    </main>
    <PortalFooter />
    </div>
</template>
