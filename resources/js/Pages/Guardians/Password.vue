<script setup>
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';

defineProps({ forced: Boolean });
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
function submit() { form.put('/guardian/password', { onFinish: () => form.reset() }); }
</script>

<template>
    <PortalLayout title="تغيير كلمة المرور">
        <section class="card mx-auto max-w-lg space-y-4">
            <h1 class="card-title text-xl">تغيير كلمة المرور</h1>
            <p v-if="forced" class="alert-warning">تستخدم كلمة مرور مؤقتة أصدرها القسم. غيّرها الآن لمتابعة استخدام البوابة.</p>
            <form class="space-y-3" @submit.prevent="submit">
                <div v-if="form.hasErrors" role="alert" class="alert-error"><p v-for="(e, k) in form.errors" :key="k">{{ e }}</p></div>
                <label class="block text-sm">{{ forced ? 'كلمة المرور المؤقتة' : 'كلمة المرور الحالية' }}<input v-model="form.current_password" class="field mt-1" type="password" autocomplete="current-password" required></label>
                <label class="block text-sm">كلمة المرور الجديدة<input v-model="form.password" class="field mt-1" type="password" autocomplete="new-password" required><span class="mt-1 block text-xs text-slate-500">10 خانات على الأقل، تتضمن أحرفًا وأرقامًا.</span></label>
                <label class="block text-sm">تأكيد كلمة المرور الجديدة<input v-model="form.password_confirmation" class="field mt-1" type="password" autocomplete="new-password" required></label>
                <button type="submit" class="btn btn-primary w-full py-3" :disabled="form.processing">حفظ كلمة المرور</button>
            </form>
        </section>
    </PortalLayout>
</template>
