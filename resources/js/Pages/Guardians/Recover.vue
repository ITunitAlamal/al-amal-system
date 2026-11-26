<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import GuardianLayout from '../../Layouts/GuardianLayout.vue';
import PortalFooter from '../../Components/PortalFooter.vue';

// Password recovery. The first step answers the same way whatever the input (no account enumeration); a code is
// sent only to an already-verified phone when SMS is enabled. Otherwise the department resets it manually.
const props = defineProps({ step: String, smsAvailable: Boolean, phone: { type: String, default: null } });
const request = useForm({ national_id: '', phone: '' });
const reset = useForm({ code: '', password: '', password_confirmation: '' });
</script>

<template>
    <GuardianLayout title="استعادة كلمة المرور">
        <div class="mx-auto max-w-xl">
            <section class="card space-y-4 p-6">
                <h1 class="card-title text-xl">استعادة كلمة المرور</h1>
                <template v-if="step === 'request'">
                    <p class="text-sm leading-7 text-slate-600">أدخل رقم هويتك ورقم الجوال المسجل في حسابك.</p>
                    <p v-if="!smsAvailable" class="alert-info">الرسائل النصية غير متاحة حاليًا. لإعادة تعيين كلمة المرور راجع القسم بهويتك، ويُصدر لك كلمة مرور مؤقتة.</p>
                    <form class="space-y-3" @submit.prevent="request.post('/guardian/forgot-password')">
                        <div v-if="request.hasErrors" role="alert" class="alert-error"><p v-for="(e, k) in request.errors" :key="k">{{ e }}</p></div>
                        <label class="block text-sm">رقم الهوية<input v-model="request.national_id" class="field mt-1 font-mono" dir="ltr" inputmode="numeric" maxlength="20" autocomplete="off" required></label>
                        <label class="block text-sm">رقم الجوال<input v-model="request.phone" class="field mt-1" type="tel" dir="ltr" autocomplete="tel" maxlength="16" required></label>
                        <button type="submit" class="btn btn-primary w-full py-3" :disabled="request.processing">{{ request.processing ? 'جارٍ الإرسال…' : 'إرسال رمز الاستعادة' }}</button>
                    </form>
                </template>
                <template v-else>
                    <p class="text-sm leading-7 text-slate-600">أدخل الرمز الذي وصلك على <span dir="ltr">{{ phone }}</span>، ثم كلمة المرور الجديدة.</p>
                    <form class="space-y-3" @submit.prevent="reset.post('/guardian/reset-password', { onFinish: () => reset.reset('password', 'password_confirmation') })">
                        <div v-if="reset.hasErrors" role="alert" class="alert-error"><p v-for="(e, k) in reset.errors" :key="k">{{ e }}</p></div>
                        <label class="block text-sm">الرمز (6 أرقام)<input v-model="reset.code" class="field mt-1 text-center font-mono text-lg tracking-widest" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
                        <label class="block text-sm">كلمة المرور الجديدة<input v-model="reset.password" class="field mt-1" type="password" autocomplete="new-password" required><span class="mt-1 block text-xs text-slate-500">10 خانات على الأقل، تتضمن أحرفًا وأرقامًا.</span></label>
                        <label class="block text-sm">تأكيد كلمة المرور<input v-model="reset.password_confirmation" class="field mt-1" type="password" autocomplete="new-password" required></label>
                        <button type="submit" class="btn btn-primary w-full py-3" :disabled="reset.processing">تغيير كلمة المرور</button>
                    </form>
                    <button type="button" class="text-sm text-blue-700 hover:underline" @click="router.post('/guardian/forgot-password/restart')">لم يصلك الرمز؟ ابدأ من جديد</button>
                </template>
                <p class="text-center text-sm"><Link href="/guardian/login" class="font-semibold text-blue-700 hover:underline">العودة لتسجيل الدخول</Link></p>
            </section>
        </div>
        <template #footer><PortalFooter /></template>
    </GuardianLayout>
</template>
