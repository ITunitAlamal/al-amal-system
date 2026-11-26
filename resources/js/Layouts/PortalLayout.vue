<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

// Authenticated beneficiary shell: compact header with a profile menu, five areas as top links on wide screens
// and a fixed bottom bar on phones. No ERP sidebar, no heavy assets.
defineProps({ title: { type: String, default: 'بوابة المستفيدين' }, back: { type: String, default: '' } });
const page = usePage();
const menu = ref(false);
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const setOnline = () => { online.value = navigator.onLine; };
onMounted(() => { window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline); });
onBeforeUnmount(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });
const path = computed(() => page.url.split('?')[0]);
const areas = [
    { href: '/guardian', label: 'الرئيسية', icon: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z', exact: true },
    { href: '/guardian/family', label: 'أسرتي', icon: 'M16 11a4 4 0 1 0-8 0M4 21a8 8 0 0 1 16 0M12 7a3 3 0 1 0 0-.01' },
    { href: '/guardian/requests', label: 'طلباتي', icon: 'M8 4h8l3 3v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zM9 12h6M9 16h6' },
    { href: '/guardian/aid', label: 'مساعداتي', full: 'مساعداتي وكفالاتي', icon: 'M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.5-7 10-7 10z' },
    { href: '/guardian/notifications', label: 'الإشعارات', icon: 'M6 16V11a6 6 0 1 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0', badge: true },
];
const active = area => (area.exact ? path.value === area.href : path.value.startsWith(area.href)) || (area.href === '/guardian/requests' && path.value.startsWith('/guardian/registrations'));
const unread = computed(() => page.props.guardian?.unread || 0);
</script>

<template>
    <div dir="rtl" class="aims-shell flex min-h-screen flex-col bg-canvas pb-20 text-slate-800 md:pb-0">
        <a href="#portal-main" class="skip-link">تجاوز إلى المحتوى</a>
        <Head :title="title" />
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-2.5">
                <Link href="/guardian" class="flex min-w-0 items-center gap-2" aria-label="بوابة المستفيدين — الرئيسية">
                    <img src="/images/brand/icon-180.png" alt="" width="36" height="36" class="h-9 w-9 shrink-0" aria-hidden="true">
                    <span class="min-w-0"><strong class="block truncate font-display text-sm text-navy-800">معهد الأمل للأيتام</strong><span class="block text-xs text-emerald-700">بوابة المستفيدين</span></span>
                </Link>
                <nav class="ms-auto hidden items-center gap-1 md:flex" aria-label="أقسام البوابة">
                    <Link v-for="a in areas" :key="a.href" :href="a.href" :aria-current="active(a) ? 'page' : undefined"
                        :class="['relative rounded-lg px-3 py-2 text-sm font-semibold', active(a) ? 'bg-emerald-50 text-emerald-800' : 'text-slate-600 hover:bg-slate-50']">
                        {{ a.full || a.label }}<span v-if="a.badge && unread" class="ms-1 rounded-full bg-gold-500 px-1.5 text-xs text-white">{{ unread }}</span></Link>
                </nav>
                <div class="relative ms-auto md:ms-2">
                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-full bg-navy-50 font-bold text-navy-800" :aria-expanded="menu" aria-haspopup="menu" aria-label="قائمة الحساب" @click="menu = !menu">{{ (page.props.guardian?.name || '؟').slice(0, 1) }}</button>
                    <div v-if="menu" class="absolute end-0 top-12 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" role="menu" @keydown.esc="menu = false">
                        <p class="truncate px-3 py-2 text-sm font-semibold text-navy-800">{{ page.props.guardian?.name }}</p>
                        <Link href="/guardian/profile" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50" role="menuitem">الملف والتفضيلات</Link>
                        <Link href="/guardian/password" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50" role="menuitem">تغيير كلمة المرور</Link>
                        <button type="button" class="block w-full rounded-lg px-3 py-2 text-start text-sm text-red-700 hover:bg-red-50" role="menuitem" @click="router.post('/guardian/logout')">تسجيل الخروج</button>
                    </div>
                </div>
            </div>
            <div class="h-0.5 bg-gradient-to-l from-navy-700 via-emerald-500 to-blue-500" aria-hidden="true"></div>
        </header>
        <p v-if="!online" class="bg-gold-100 px-4 py-2 text-center text-sm text-gold-900" role="status">لا يوجد اتصال بالإنترنت. لن يُحفظ شيء حتى يعود الاتصال.</p>
        <main id="portal-main" tabindex="-1" class="mx-auto w-full max-w-5xl flex-1 space-y-4 px-3 py-4 sm:px-5 sm:py-6">
            <Link v-if="back" :href="back" class="inline-flex items-center gap-1 text-sm font-semibold text-blue-700 hover:underline">→ رجوع</Link>
            <p v-if="page.props.flash?.success" role="status" class="alert-success">{{ page.props.flash.success }}</p>
            <slot />
        </main>
        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] md:hidden" aria-label="أقسام البوابة">
            <Link v-for="a in areas" :key="a.href" :href="a.href" :aria-current="active(a) ? 'page' : undefined"
                :class="['relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-semibold', active(a) ? 'text-emerald-700' : 'text-slate-500']">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="a.icon" /></svg>
                {{ a.label }}<span v-if="a.badge && unread" class="absolute end-[22%] top-1 h-2.5 w-2.5 rounded-full bg-gold-500" aria-label="إشعارات غير مقروءة"></span>
            </Link>
        </nav>
    </div>
</template>
