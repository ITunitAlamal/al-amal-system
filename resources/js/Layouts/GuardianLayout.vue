<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';

defineProps({ title: { type: String, default: 'بوابة الأوصياء' } });
const page = usePage();
</script>

<template>
    <div dir="rtl" class="aims-shell flex min-h-screen flex-col bg-canvas text-slate-800">
        <a href="#guardian-main" class="skip-link">تجاوز إلى المحتوى</a>
        <Head :title="title" />
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-4">
                <Link href="/guardian" class="flex items-center gap-3">
                    <img src="/images/brand/logo-mark.png" alt="شعار معهد الأمل للأيتام" class="h-12 w-auto">
                    <span>
                        <strong class="block font-display text-navy-800">معهد الأمل للأيتام</strong>
                        <span class="text-sm text-emerald-700">بوابة المستفيدين</span>
                    </span>
                </Link>
                <div v-if="page.props.guardian" class="flex items-center gap-3 text-sm">
                    <span class="hidden font-semibold text-navy-800 sm:inline">{{ page.props.guardian.name }}</span>
                    <button type="button" class="btn btn-secondary btn-sm" @click="router.post('/guardian/logout')">تسجيل الخروج</button>
                </div>
                <Link v-else href="/guardian/login" class="text-sm font-semibold text-navy-700 hover:underline">تسجيل الدخول</Link>
            </div>
            <div class="h-1 bg-gradient-to-l from-navy-700 via-blue-600 to-gold-400" aria-hidden="true"></div>
        </header>
        <main id="guardian-main" tabindex="-1" class="mx-auto w-full max-w-5xl flex-1 space-y-4 px-3 py-5 sm:px-5 sm:py-8">
            <p v-if="page.props.flash?.success" role="status" class="alert-success">{{ page.props.flash.success }}</p>
            <slot />
        </main>
        <slot name="footer">
            <footer class="mx-auto w-full max-w-5xl px-5 py-6 text-sm text-slate-500">تظهر للوصي ملفات الأطفال المرتبطة بوصايته المعتمدة فقط.</footer>
        </slot>
    </div>
</template>
