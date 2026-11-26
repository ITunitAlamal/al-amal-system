<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatDateTime } from '../../Support/dates.js';

// Account settings, separate from the five areas: phone confirmation and SMS consent.
const props = defineProps({ account: Object, smsAvailable: Boolean, codeSent: Boolean, errors: Object });
const verify = useForm({ code: '' });
</script>

<template>
    <PortalLayout title="الملف والتفضيلات" back="/guardian">
        <h1 class="text-2xl font-bold text-navy-800">الملف والتفضيلات</h1>
        <section class="card space-y-3 p-5">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">الاسم</dt><dd class="font-semibold">{{ account.name }}</dd></div>
                <div><dt class="text-slate-500">حالة الحساب</dt><dd><StatusBadge :label="account.status_label" :tone="account.status === 'approved' ? 'success' : 'info'" /></dd></div>
                <div><dt class="text-slate-500">الجوال</dt><dd dir="ltr" class="text-start">{{ account.phone || '—' }} <StatusBadge :label="account.phone_verified ? 'مؤكد' : 'غير مؤكد'" :tone="account.phone_verified ? 'success' : 'warning'" /></dd></div>
                <div><dt class="text-slate-500">آخر دخول</dt><dd>{{ formatDateTime(account.last_login_at, '—') }}</dd></div>
            </dl>
            <Link href="/guardian/password" class="btn btn-sm btn-secondary">تغيير كلمة المرور</Link>
        </section>

        <section v-if="!account.phone_verified" class="card space-y-3 p-5">
            <h2 class="card-title">تأكيد رقم الجوال</h2>
            <p class="text-sm text-slate-600">يلزم تأكيد الرقم لاستعادة كلمة المرور.</p>
            <template v-if="smsAvailable">
                <button type="button" class="btn btn-secondary" @click="router.post('/guardian/profile/phone-code', {}, { preserveScroll: true })">إرسال رمز التأكيد</button>
                <form v-if="codeSent || errors?.code" class="flex flex-col gap-2 sm:flex-row" @submit.prevent="verify.post('/guardian/profile/verify-phone', { preserveScroll: true })">
                    <label class="sr-only" for="otp">رمز التأكيد</label>
                    <input id="otp" v-model="verify.code" inputmode="numeric" autocomplete="one-time-code" class="field sm:w-48" dir="ltr" maxlength="10" placeholder="الرمز" :aria-invalid="!!(verify.errors.code || errors?.code)">
                    <button type="submit" class="btn btn-primary" :disabled="verify.processing">تأكيد</button>
                </form>
                <p v-if="verify.errors.code || errors?.code" class="text-sm text-red-700">{{ verify.errors.code || errors.code }}</p>
            </template>
            <p v-else class="text-sm text-slate-600">خدمة الرسائل غير متاحة حاليًا؛ راجع القسم لتأكيد الرقم.</p>
        </section>

        <section class="card space-y-3 p-5">
            <h2 class="card-title">الرسائل النصية</h2>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" class="h-5 w-5" :checked="account.sms_consent" @change="router.put('/guardian/profile/preferences', { sms_consent: $event.target.checked }, { preserveScroll: true })">
                أوافق على استلام رسائل نصية بالقرارات والمواعيد</label>
        </section>
    </PortalLayout>
</template>
