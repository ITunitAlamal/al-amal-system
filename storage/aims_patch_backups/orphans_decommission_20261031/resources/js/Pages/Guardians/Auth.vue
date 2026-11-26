<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import GuardianLayout from '../../Layouts/GuardianLayout.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import PortalFooter from '../../Components/PortalFooter.vue';

const props = defineProps({ registering: Boolean, urls: Object, registryAvailable: Boolean });
const form = useForm({ name: '', national_id: '', phone: '', password: '', password_confirmation: '', deceased_national_id: '', relationship: '' });
function submit() {
    form.transform(data => (props.registering ? data : { phone: data.phone, password: data.password }))
        .post(props.registering ? props.urls.register : props.urls.login, { onFinish: () => form.reset('password', 'password_confirmation') });
}
const journey = [
    { label: 'تسجيل الحساب وبيانات المتوفى', state: 'current' },
    { label: 'التحقق من الأهلية', state: 'upcoming' },
    { label: 'مراجعة المشرف', state: 'upcoming' },
    { label: 'ربط الأطفال المؤهلين', state: 'upcoming' },
];
</script>

<template>
    <GuardianLayout :title="registering ? 'تسجيل وصي جديد' : 'دخول الوصي'">
        <div class="grid gap-8 lg:grid-cols-2">
            <section class="min-w-0 space-y-6 py-4">
                <span class="inline-block rounded-full bg-gold-100 px-4 py-1.5 text-sm font-semibold text-gold-900">رعاية تبدأ بمعلومات موثوقة</span>
                <h1 class="text-3xl font-bold leading-relaxed text-navy-800">{{ registering ? 'سجّل طلبك وتابع مراجعته من مكان واحد' : 'مرحبًا بك في بوابة الأوصياء' }}</h1>
                <p class="leading-8 text-slate-600">نراجع أهلية الوصي أولًا، ثم يراجع المشرف الطلب. بعد الاعتماد تُضاف ملفات الأطفال المؤهلين دون سن 18 سنة تلقائيًا من البيانات المتحقق منها.</p>
                <div class="card"><StatusStepper :steps="journey" /></div>
                <p v-if="!registryAvailable" class="alert-warning leading-7">خدمة التحقق الخارجي غير متاحة حاليًا. يمكنك حفظ طلبك ومتابعته، وسيبقى بانتظار التحقق دون اعتماد أو إضافة أطفال إلى حين توفر الخدمة.</p>
            </section>
            <section class="card min-w-0 p-6 md:p-8">
                <h2 class="card-title text-xl">{{ registering ? 'إنشاء حساب وتقديم الطلب' : 'تسجيل الدخول' }}</h2>
                <form class="mt-6 space-y-5" @submit.prevent="submit">
                    <div v-if="form.hasErrors" role="alert" class="alert-error"><p v-for="(error, field) in form.errors" :key="field">{{ error }}</p></div>
                    <template v-if="registering">
                        <div><label for="g-name" class="field-label">اسم الوصي كاملًا</label><input id="g-name" v-model="form.name" class="field py-3" maxlength="200" autocomplete="name" required></div>
                        <div><label for="g-id" class="field-label">رقم هوية الوصي</label><input id="g-id" v-model="form.national_id" class="field py-3" maxlength="40" dir="ltr" autocomplete="off" required></div>
                    </template>
                    <div><label for="g-phone" class="field-label">رقم الجوال</label><input id="g-phone" v-model="form.phone" class="field py-3" type="tel" dir="ltr" autocomplete="username" required></div>
                    <div>
                        <label for="g-password" class="field-label">كلمة المرور</label>
                        <input id="g-password" v-model="form.password" class="field py-3" type="password" :autocomplete="registering ? 'new-password' : 'current-password'" :minlength="registering ? 12 : undefined" required>
                        <p v-if="registering" class="mt-1 text-xs text-slate-500">12 خانة على الأقل، تتضمن أحرفًا وأرقامًا.</p>
                    </div>
                    <template v-if="registering">
                        <div><label for="g-password2" class="field-label">تأكيد كلمة المرور</label><input id="g-password2" v-model="form.password_confirmation" class="field py-3" type="password" autocomplete="new-password" required></div>
                        <div class="space-y-5 border-t border-slate-100 pt-5">
                            <h3 class="text-sm font-bold text-navy-800">بيانات طلب الوصاية</h3>
                            <div><label for="g-deceased" class="field-label">رقم هوية المتوفى</label><input id="g-deceased" v-model="form.deceased_national_id" class="field py-3" maxlength="40" dir="ltr" autocomplete="off" required></div>
                            <div><label for="g-relationship" class="field-label">صفتك وعلاقتك بالمتوفى أو الأطفال</label><textarea id="g-relationship" v-model="form.relationship" class="field" maxlength="200" rows="2" placeholder="وضّح صلتك والأساس الذي تتقدم بموجبه بطلب الوصاية" required /></div>
                        </div>
                    </template>
                    <button type="submit" :disabled="form.processing" class="btn btn-primary w-full py-3 text-base">{{ form.processing ? 'جارٍ الإرسال…' : (registering ? 'إنشاء الحساب وحفظ الطلب' : 'دخول') }}</button>
                </form>
                <p class="mt-6 text-center text-sm text-slate-600">{{ registering ? 'لديك حساب؟' : 'ليس لديك حساب؟' }} <Link :href="registering ? urls.login : urls.register" class="font-semibold text-blue-700 hover:underline">{{ registering ? 'سجّل الدخول' : 'سجّل كوصي جديد' }}</Link></p>
            </section>
        </div>
        <template #footer><PortalFooter /></template>
    </GuardianLayout>
</template>
