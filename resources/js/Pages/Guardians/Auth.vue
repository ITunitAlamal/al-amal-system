<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import GuardianLayout from '../../Layouts/GuardianLayout.vue';
import PortalFooter from '../../Components/PortalFooter.vue';

const props = defineProps({ registering: Boolean, urls: Object });
const form = useForm({ first_name: '', father_name: '', grandfather_name: '', family_name: '', national_id: '', phone: '', password: '', password_confirmation: '', declaration: false, sms_consent: false });
function submit() {
    form.transform(data => (props.registering ? data : { phone: data.phone, password: data.password }))
        .post(props.registering ? props.urls.register : props.urls.login, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <GuardianLayout :title="registering ? 'تسجيل وصي جديد' : 'دخول الوصي'">
        <div class="mx-auto max-w-xl">
            <section class="card min-w-0 p-6 md:p-8">
                <h1 class="card-title text-xl">{{ registering ? 'إنشاء حساب في بوابة الأوصياء' : 'تسجيل الدخول إلى بوابة الأوصياء' }}</h1>
                <p v-if="registering" class="mt-2 text-sm leading-7 text-slate-600">بعد إنشاء الحساب تقدّم طلب تسجيل الأطفال الذين ترعاهم. يراجع القسم حسابك، ثم يقرر في وصايتك على كل طفل على حدة؛ لا يظهر لك ملف أي طفل قبل اعتماد وصايتك عليه.</p>
                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div v-if="form.hasErrors" role="alert" class="alert-error"><p v-for="(error, field) in form.errors" :key="field">{{ error }}</p></div>
                    <template v-if="registering">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-sm">الاسم الأول<input v-model="form.first_name" class="field mt-1" maxlength="100" required autocomplete="given-name"></label>
                            <label class="text-sm">اسم الأب<input v-model="form.father_name" class="field mt-1" maxlength="100" required></label>
                            <label class="text-sm">اسم الجد<input v-model="form.grandfather_name" class="field mt-1" maxlength="100"></label>
                            <label class="text-sm">اسم العائلة<input v-model="form.family_name" class="field mt-1" maxlength="100" required autocomplete="family-name"></label>
                        </div>
                        <label class="block text-sm">رقم الهوية (9 أرقام)<input v-model="form.national_id" class="field mt-1 font-mono" dir="ltr" inputmode="numeric" maxlength="20" autocomplete="off" required></label>
                    </template>
                    <label class="block text-sm">رقم الجوال<input v-model="form.phone" class="field mt-1 py-3" type="tel" dir="ltr" autocomplete="username" required></label>
                    <label class="block text-sm">كلمة المرور<input v-model="form.password" class="field mt-1 py-3" type="password" :autocomplete="registering ? 'new-password' : 'current-password'" required>
                        <span v-if="registering" class="mt-1 block text-xs text-slate-500">10 خانات على الأقل، تتضمن أحرفًا وأرقامًا.</span></label>
                    <label v-if="registering" class="block text-sm">تأكيد كلمة المرور<input v-model="form.password_confirmation" class="field mt-1" type="password" autocomplete="new-password" required></label>
                    <template v-if="registering">
                        <label class="flex items-start gap-2 text-sm"><input v-model="form.declaration" type="checkbox" class="mt-1" required> أقر بصحة هذه البيانات، وأوافق على أن يراجعها القسم. أعلم أن إنشاء الحساب لا يعني اعتماد الوصاية أو الاستفادة.</label>
                        <label class="flex items-start gap-2 text-sm"><input v-model="form.sms_consent" type="checkbox" class="mt-1"> أوافق على تلقي رسائل نصية من المعهد على هذا الرقم (اختياري).</label>
                    </template>
                    <p v-else class="text-sm"><Link :href="urls.forgot" class="font-semibold text-blue-700 hover:underline">نسيت كلمة المرور؟</Link></p>
                    <button type="submit" :disabled="form.processing" class="btn btn-primary w-full py-3 text-base">{{ form.processing ? 'جارٍ الإرسال…' : (registering ? 'إنشاء الحساب' : 'دخول') }}</button>
                </form>
                <p class="mt-6 text-center text-sm text-slate-600">{{ registering ? 'لديك حساب؟' : 'ليس لديك حساب؟' }}
                    <Link :href="registering ? urls.login : urls.register" class="font-semibold text-blue-700 hover:underline">{{ registering ? 'سجّل الدخول' : 'سجّل كوصي جديد' }}</Link></p>
            </section>
        </div>
        <template #footer><PortalFooter /></template>
    </GuardianLayout>
</template>
