<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    username: { type: String, default: '' },
    isRequired: { type: Boolean, default: false },
});

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const showCurrent = ref(false);
const showNew = ref(false);
const showConfirmation = ref(false);

const passwordChecks = computed(() => ({
    minLength: form.password.length >= 12,
    upperAndLower: /[a-z]/.test(form.password) && /[A-Z]/.test(form.password),
    number: /[0-9]/.test(form.password),
    symbol: /[^\p{L}\p{N}\s]/u.test(form.password),
    confirmation: Boolean(form.password_confirmation) && form.password === form.password_confirmation,
}));

function submit() {
    if (form.processing) return;

    form.put('/change-password', {
        preserveScroll: true,
        onFinish: () => {
            // Do not clear a failed form's password fields automatically.
            // The user can correct the field identified by the server.
        },
    });
}
</script>

<template>
    <main dir="rtl" class="flex min-h-screen items-center justify-center bg-canvas px-4 py-10 text-slate-800">
        <div class="w-full max-w-xl">
            <header class="mb-6 text-center">
                <img src="/images/brand/logo.png" alt="شعار معهد الأمل للأيتام" class="mx-auto mb-4 h-28 w-auto">
                <p class="eyebrow">نظام الإدارة المؤسسية</p>
                <h1 class="mt-2 text-2xl font-bold text-navy-800">{{ isRequired ? 'تغيير كلمة المرور عند أول دخول' : 'تغيير كلمة المرور' }}</h1>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    مرحبًا {{ username }}.
                    <template v-if="isRequired"> لحماية حسابك، يجب تعيين كلمة مرور جديدة قبل متابعة استخدام النظام.</template>
                    <template v-else> يمكنك تحديث كلمة مرور حسابك من هذه الصفحة.</template>
                </p>
            </header>

            <form class="card space-y-5 sm:p-8" @submit.prevent="submit">
                <div v-if="form.hasErrors" role="alert" class="alert-error">
                    تعذّر حفظ كلمة المرور. يرجى مراجعة الرسائل أسفل الحقول.
                </div>

                <div>
                    <label for="current-password" class="field-label">كلمة المرور الحالية <span class="text-red-700">*</span></label>
                    <div class="relative">
                        <input id="current-password" v-model="form.current_password" :type="showCurrent ? 'text' : 'password'"
                            autocomplete="current-password" required :aria-invalid="Boolean(form.errors.current_password)"
                            :aria-describedby="form.errors.current_password ? 'current-password-error' : undefined"
                            class="field py-3 pl-20" />
                        <button type="button" class="absolute inset-y-0 left-2 px-3 text-sm font-semibold text-slate-600 hover:text-navy-800"
                            :aria-label="showCurrent ? 'إخفاء كلمة المرور الحالية' : 'إظهار كلمة المرور الحالية'" @click="showCurrent = !showCurrent">
                            {{ showCurrent ? 'إخفاء' : 'إظهار' }}
                        </button>
                    </div>
                    <p v-if="form.errors.current_password" id="current-password-error" class="field-error">{{ form.errors.current_password }}</p>
                </div>

                <div>
                    <label for="new-password" class="field-label">كلمة المرور الجديدة <span class="text-red-700">*</span></label>
                    <div class="relative">
                        <input id="new-password" v-model="form.password" :type="showNew ? 'text' : 'password'"
                            autocomplete="new-password" required minlength="12" :aria-invalid="Boolean(form.errors.password)"
                            :aria-describedby="form.errors.password ? 'new-password-error' : 'password-hints'"
                            class="field py-3 pl-20" />
                        <button type="button" class="absolute inset-y-0 left-2 px-3 text-sm font-semibold text-slate-600 hover:text-navy-800"
                            :aria-label="showNew ? 'إخفاء كلمة المرور الجديدة' : 'إظهار كلمة المرور الجديدة'" @click="showNew = !showNew">
                            {{ showNew ? 'إخفاء' : 'إظهار' }}
                        </button>
                    </div>
                    <p v-if="form.errors.password" id="new-password-error" class="field-error">{{ form.errors.password }}</p>
                    <div id="password-hints" class="mt-3 rounded-xl bg-slate-50 p-3 text-xs leading-6 text-slate-600">
                        <p class="mb-1 font-semibold">شروط كلمة المرور:</p>
                        <p :class="passwordChecks.minLength ? 'text-emerald-700' : ''">{{ passwordChecks.minLength ? '✓' : '○' }} 12 خانة على الأقل</p>
                        <p :class="passwordChecks.upperAndLower ? 'text-emerald-700' : ''">{{ passwordChecks.upperAndLower ? '✓' : '○' }} حرف إنجليزي كبير وحرف صغير</p>
                        <p :class="passwordChecks.number ? 'text-emerald-700' : ''">{{ passwordChecks.number ? '✓' : '○' }} رقم واحد على الأقل</p>
                        <p :class="passwordChecks.symbol ? 'text-emerald-700' : ''">{{ passwordChecks.symbol ? '✓' : '○' }} رمز خاص واحد على الأقل</p>
                    </div>
                </div>

                <div>
                    <label for="confirm-password" class="field-label">تأكيد كلمة المرور الجديدة <span class="text-red-700">*</span></label>
                    <div class="relative">
                        <input id="confirm-password" v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'"
                            autocomplete="new-password" required :aria-invalid="Boolean(form.errors.password_confirmation)"
                            class="field py-3 pl-20" />
                        <button type="button" class="absolute inset-y-0 left-2 px-3 text-sm font-semibold text-slate-600 hover:text-navy-800"
                            :aria-label="showConfirmation ? 'إخفاء تأكيد كلمة المرور' : 'إظهار تأكيد كلمة المرور'" @click="showConfirmation = !showConfirmation">
                            {{ showConfirmation ? 'إخفاء' : 'إظهار' }}
                        </button>
                    </div>
                    <p v-if="form.errors.password_confirmation" class="field-error">{{ form.errors.password_confirmation }}</p>
                    <p v-if="form.password_confirmation" class="mt-1 text-xs" :class="passwordChecks.confirmation ? 'text-emerald-700' : 'text-gold-800'">
                        {{ passwordChecks.confirmation ? 'كلمتا المرور متطابقتان' : 'كلمتا المرور غير متطابقتين' }}
                    </p>
                </div>

                <button type="submit" :disabled="form.processing" class="btn btn-primary w-full py-3 text-base">
                    {{ form.processing ? 'جارٍ حفظ كلمة المرور...' : 'حفظ كلمة المرور والمتابعة' }}
                </button>

                <p v-if="isRequired" class="text-center text-xs text-slate-500">لا يمكن تجاوز هذه الخطوة قبل تحديث كلمة المرور.</p>
                <p v-else class="text-center text-sm"><Link href="/dashboard" class="font-semibold text-blue-700 hover:underline">العودة إلى مساحتي</Link></p>
            </form>
        </div>
    </main>
</template>
