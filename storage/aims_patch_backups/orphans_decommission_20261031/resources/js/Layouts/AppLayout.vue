<script setup>
import { computed, ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ConnectionStatus from '../Components/ConnectionStatus.vue';
import NotificationBell from '../Components/NotificationBell.vue';
import QuickSearch from '../Components/QuickSearch.vue';
import { activeKey, keyOf, parseUrl } from '../Support/menuActive.js';
import { menuIconPaths } from '../Support/menuIcons.js';
import { filterNavigation } from '../Support/navigationFilter.js';
import { activateNavigationDrawer } from '../Support/navigationDrawer.js';
import { navigationIcon, navigationIconPaths } from '../Support/navigationIcons.js';

const props = defineProps({
    title: {
        type: String,
        default: 'مساحتي',
    },
    // Kept for older pages; the signed-in user's name comes from shared props.
    username: {
        type: String,
        default: '',
    },
    // Older pages still pass their own links. They are ignored: the one sidebar comes only from
    // «القائمة الرئيسية», so a link the administrator hid never reappears on some page.
    navigation: {
        type: Array,
        default: () => [],
    },
});

// The sidebar is built on the server from navigation_items and the user's permissions,
// and is managed from «إعدادات النظام ← القوائم الجانبية».
const page = usePage();
const displayName = computed(() => page.props.auth?.user?.name || props.username || '');

const menu = computed(() => {
    const tree = (page.props.menu || []).map(item => ({ ...item, children: [...(item.children || [])] }));
    const links = [];
    tree.forEach(item => {
        if (item.url) links.push(parseUrl(item.url));
        item.children.forEach(child => child.url && links.push(parseUrl(child.url)));
    });

    const best = activeKey(links, page.url);
    const isActive = url => Boolean(url) && best !== null && keyOf(parseUrl(url)) === best;

    return tree.map(item => ({
        ...item,
        active: isActive(item.url),
        children: item.children.map(child => ({ ...child, active: isActive(child.url) })),
    }));
});

// Opened groups and the menu's scroll position survive page changes within the browser tab.
const openGroups = ref(new Set());
const menuQuery = ref('');
const visibleMenu = computed(() => filterNavigation(menu.value, menuQuery.value));
const navElement = ref(null);
function isGroupOpen(item) {
    return Boolean(menuQuery.value.trim()) || openGroups.value.has(item.code) || item.children.some(child => child.active);
}
function toggleGroup(item) {
    const next = new Set(openGroups.value);
    if (next.has(item.code)) next.delete(item.code); else next.add(item.code);
    openGroups.value = next;
    sidebarCollapsed.value = false;
    try {
        window.sessionStorage.setItem('aims.openGroups', JSON.stringify([...next]));
    } catch {
        // Remembering open groups is a convenience only.
    }
}

const iconPaths = { ...menuIconPaths, ...navigationIconPaths };
function iconFor(item) {
    return navigationIcon(item, menuIconPaths);
}
function pendingCount(item) {
    const key = { my_tasks: 'tasks', official_correspondence: 'correspondence' }[item.code];
    const count = key ? Number(page.props.workCounters?.[key]) : 0;
    return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0;
}

const sidebarCollapsed = ref(false);
const mobileMenuOpen = ref(false);
const sidebarElement = ref(null);
const contentElement = ref(null);
const menuTrigger = ref(null);
const desktopMenu = ref(false);
let mediaQuery;
let releaseDrawer;
function updateMenuViewport() {
    desktopMenu.value = mediaQuery.matches;
    if (desktopMenu.value) closeMobileMenu();
}
watch(mobileMenuOpen, async open => {
    releaseDrawer?.();
    releaseDrawer = undefined;
    if (open) {
        await nextTick();
        if (mobileMenuOpen.value && sidebarElement.value && contentElement.value) {
            releaseDrawer = activateNavigationDrawer(sidebarElement.value, contentElement.value, menuTrigger.value, closeMobileMenu);
        }
    }
});
const userMenuOpen = ref(false);
const userMenuRoot = ref(null);
function closeUserMenu(event) {
    if (userMenuOpen.value && userMenuRoot.value && !userMenuRoot.value.contains(event.target)) userMenuOpen.value = false;
}
function onShellKeydown(event) {
    if (event.key === 'Escape' && userMenuOpen.value) {
        userMenuOpen.value = false;
        userMenuRoot.value?.querySelector('button')?.focus();
    }
}
const expanded = computed(() => !sidebarCollapsed.value || mobileMenuOpen.value);
const loggingOut = ref(false);
const showBackToTop = ref(false);
function updateScrollPosition() {
    showBackToTop.value = window.scrollY > 300;
}
function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
    });
}
let stopNavigationStart;
onMounted(() => {
    mediaQuery = window.matchMedia('(min-width: 1024px)');
    updateMenuViewport();
    mediaQuery.addEventListener('change', updateMenuViewport);
    updateScrollPosition();
    window.addEventListener('scroll', updateScrollPosition, { passive: true });
    try {
        sidebarCollapsed.value = window.localStorage.getItem('aims.sidebarCollapsed') === '1';
        openGroups.value = new Set(JSON.parse(window.sessionStorage.getItem('aims.openGroups') || '[]'));
        const top = Number(window.sessionStorage.getItem('aims.navScroll') || 0);
        if (navElement.value && top > 0) navElement.value.scrollTop = top;
    } catch {
        // Storage can be unavailable (private mode); the default layout is fine.
    }
    stopNavigationStart = router.on('start', () => {
        closeMobileMenu();
        userMenuOpen.value = false;
        try {
            window.sessionStorage.setItem('aims.navScroll', String(navElement.value?.scrollTop || 0));
        } catch {
            // Keeping the scroll position is a convenience only.
        }
    });
    document.addEventListener('click', closeUserMenu);
    document.addEventListener('keydown', onShellKeydown);
});
onUnmounted(() => {
    releaseDrawer?.();
    mediaQuery?.removeEventListener('change', updateMenuViewport);
    window.removeEventListener('scroll', updateScrollPosition);
    stopNavigationStart?.();
    document.removeEventListener('click', closeUserMenu);
    document.removeEventListener('keydown', onShellKeydown);
});
watch(sidebarCollapsed, value => {
    if (value) menuQuery.value = '';
    try {
        window.localStorage.setItem('aims.sidebarCollapsed', value ? '1' : '0');
    } catch {
        // Ignore: remembering the choice is a convenience only.
    }
});

function closeMobileMenu() {
    mobileMenuOpen.value = false;
}
function logout() {
    if (loggingOut.value) return;
    loggingOut.value = true;
    router.post('/logout', {}, {
        onFinish: () => {
            loggingOut.value = false;
        },
    });
}

// Messages from the server, shown in one place for every page.
const successMessage = ref('');
const errorMessages = computed(() => Object.values(page.props.errors || {}).flat().filter(Boolean));
watch(() => page.props.flash?.success, value => {
    successMessage.value = value || '';
}, { immediate: true });
</script>

<template>
    <div dir="rtl" class="aims-shell min-h-screen bg-canvas font-sans text-slate-800">
        <a href="#main-content" class="skip-link" v-if="!mobileMenuOpen">تجاوز القائمة إلى المحتوى</a>
        <Head :title="title" />
        <button
            v-if="showBackToTop && !mobileMenuOpen"
            type="button"
            class="fixed bottom-5 left-5 z-40 flex h-12 w-12 items-center justify-center rounded-full border border-gold-300 bg-navy-800 text-white shadow-lg transition-colors hover:bg-navy-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-gold-500 print:hidden"
            aria-label="العودة إلى أعلى الصفحة"
            title="العودة إلى أعلى الصفحة"
            @click="scrollToTop"
        >
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 19V5m-7 7 7-7 7 7" />
            </svg>
        </button>
        <svg aria-hidden="true" class="absolute h-0 w-0 overflow-hidden">
            <defs><symbol v-for="(path, name) in iconPaths" :id="`aims-icon-${name}`" :key="name" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="path" /></symbol></defs>
        </svg>

        <div v-if="mobileMenuOpen" class="fixed inset-0 z-40 bg-navy-950/50 lg:hidden" @click="closeMobileMenu"></div>

        <aside
            id="main-navigation"
            ref="sidebarElement"
            tabindex="-1"
            :inert="!desktopMenu && !mobileMenuOpen"
            :role="mobileMenuOpen ? 'dialog' : undefined"
            :aria-modal="mobileMenuOpen ? true : undefined"
            aria-label="القائمة الرئيسية"
            :class="[
                'fixed inset-y-0 right-0 z-50 flex flex-col bg-gradient-to-b from-navy-800 to-navy-950 text-white transition-all duration-200',
                sidebarCollapsed ? 'lg:w-20' : 'lg:w-72',
                mobileMenuOpen ? 'w-72 translate-x-0' : 'w-72 translate-x-full lg:translate-x-0',
            ]"
        >
            <div class="relative flex h-20 shrink-0 items-center gap-3 px-4">
                <Link href="/dashboard" class="flex min-w-0 items-center gap-3" title="مساحتي">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-1 shadow-sm">
                        <img src="/images/brand/icon-180.png" alt="شعار معهد الأمل للأيتام" class="h-full w-full object-contain">
                    </span>
                    <span v-if="expanded" class="min-w-0">
                        <span class="block truncate font-display text-[0.95rem] font-bold leading-6">معهد الأمل للأيتام</span>
                        <span class="block text-xs text-navy-200">نظام الإدارة المؤسسية</span>
                    </span>
                </Link>
                <button type="button" class="ms-auto rounded-lg p-2 text-navy-100 hover:bg-white/10 lg:hidden" aria-label="إغلاق القائمة" @click="closeMobileMenu">
                    <svg class="h-5 w-5" aria-hidden="true"><use href="#aims-icon-close" /></svg>
                </button>
                <!-- The sun arc from the logo, as a thin gold rule. -->
                <span class="absolute inset-x-4 bottom-0 h-px bg-gradient-to-l from-transparent via-gold-400/70 to-transparent" aria-hidden="true"></span>
            </div>

            <div v-if="expanded" class="px-3 pt-3">
                <label for="navigation-search" class="sr-only">بحث في القائمة الرئيسية</label>
                <input id="navigation-search" v-model="menuQuery" type="search" placeholder="بحث في القائمة…" class="min-h-11 w-full px-3 text-sm" autocomplete="off" />
            </div>
            <nav ref="navElement" aria-label="القائمة الرئيسية" class="aims-sidebar-nav flex-1 space-y-1 overflow-y-auto px-3 py-3">
                <p v-if="!visibleMenu.length" role="status" class="px-3 py-4 text-sm text-navy-100">لا توجد عناصر مطابقة ضمن قائمتك.</p>
                <template v-for="item in visibleMenu" :key="item.code">
                    <div v-if="item.children.length && !item.url">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-right text-sm text-navy-100 transition-colors hover:bg-white/10 hover:text-white"
                            :class="isGroupOpen(item) ? 'bg-white/5 text-white' : ''"
                            :aria-expanded="isGroupOpen(item)"
                            :aria-controls="`aims-menu-${item.code}`"
                            :title="item.label"
                            :aria-label="item.label"
                            @click="toggleGroup(item)"
                        >
                            <svg class="h-5 w-5 shrink-0" aria-hidden="true"><use :href="`#aims-icon-${iconFor(item)}`" /></svg>
                            <span v-if="expanded" class="flex-1 font-semibold">{{ item.label }}</span>
                            <svg v-if="expanded" class="h-4 w-4 transition-transform duration-150" :class="isGroupOpen(item) ? 'rotate-180' : ''" aria-hidden="true"><use href="#aims-icon-chevron-down" /></svg>
                        </button>
                        <div v-if="isGroupOpen(item) && expanded" :id="`aims-menu-${item.code}`" class="mr-5 mt-1 space-y-0.5 border-r border-white/10 pr-3">
                            <template v-for="child in item.children" :key="child.code">
                                <p v-if="child.heading" class="px-3 pb-0.5 pt-2.5 text-[11px] font-bold text-navy-300">{{ child.label }}</p>
                                <Link
                                    v-else-if="child.url"
                                    :href="child.url"
                                    :aria-current="child.active ? 'page' : undefined"
                                    :class="['relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-white/10 hover:text-white', child.active ? 'bg-white/10 font-semibold text-white' : 'text-navy-100']"
                                    @click="closeMobileMenu"
                                >
                                    <span v-if="child.active" class="absolute -right-[0.8rem] top-1.5 bottom-1.5 w-0.5 rounded-full bg-gold-400" aria-hidden="true"></span>
                                    <svg v-if="item.code !== 'orphans_group'" class="h-4 w-4 shrink-0" aria-hidden="true"><use :href="`#aims-icon-${iconFor(child)}`" /></svg>
                                    <span class="min-w-0 flex-1">{{ child.label }}</span>
                                    <span v-if="pendingCount(child)" class="rounded-full bg-white/15 px-2 text-xs tabular-nums"><span class="sr-only">بانتظار إجرائي: </span>{{ pendingCount(child) }}</span>
                                </Link>
                                <div v-else class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm text-navy-300" aria-disabled="true">
                                    <svg class="h-4 w-4 shrink-0" aria-hidden="true"><use :href="`#aims-icon-${iconFor(child)}`" /></svg>
                                    <span class="flex-1">{{ child.label }}</span>
                                    <span class="rounded-full bg-white/10 px-1.5 text-[10px]">قريبًا</span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <Link
                        v-else-if="item.url"
                        :href="item.url"
                        :title="item.label"
                        :aria-label="item.label"
                        :aria-current="item.active ? 'page' : undefined"
                        :class="['relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors', item.active ? 'bg-white/10 font-semibold text-white' : 'text-navy-100 hover:bg-white/10 hover:text-white']"
                        @click="closeMobileMenu"
                    >
                        <span v-if="item.active" class="absolute right-0 top-2 bottom-2 w-1 rounded-l-full bg-gold-400" aria-hidden="true"></span>
                        <svg class="h-5 w-5 shrink-0" aria-hidden="true"><use :href="`#aims-icon-${iconFor(item)}`" /></svg>
                        <span v-if="expanded" class="min-w-0 flex-1">{{ item.label }}</span>
                        <span v-if="expanded && pendingCount(item)" class="rounded-full bg-white/15 px-2 text-xs tabular-nums"><span class="sr-only">بانتظار إجرائي: </span>{{ pendingCount(item) }}</span>
                    </Link>
                    <div v-else class="flex cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-navy-300" aria-disabled="true">
                        <svg class="h-5 w-5 shrink-0" aria-hidden="true"><use :href="`#aims-icon-${iconFor(item)}`" /></svg>
                        <span v-if="expanded" class="flex-1">{{ item.label }}</span>
                        <span v-if="expanded" class="rounded-full bg-white/10 px-1.5 text-[10px]">قريبًا</span>
                    </div>
                </template>
            </nav>

            <div class="hidden border-t border-white/10 p-3 lg:block">
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-xl p-2.5 text-sm text-navy-100 hover:bg-white/10 hover:text-white"
                    :title="sidebarCollapsed ? 'توسيع القائمة' : 'طي القائمة'"
                    :aria-label="sidebarCollapsed ? 'توسيع القائمة' : 'طي القائمة'"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <svg class="h-5 w-5" :class="sidebarCollapsed ? 'rotate-180' : ''" aria-hidden="true"><use href="#aims-icon-panel-collapse" /></svg>
                    <span v-if="!sidebarCollapsed">طي القائمة</span>
                </button>
            </div>
        </aside>

        <div ref="contentElement" :class="['min-w-0 flex min-h-screen flex-col transition-all duration-200', sidebarCollapsed ? 'lg:mr-20' : 'lg:mr-72']">
            <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 md:h-[4.5rem] md:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button ref="menuTrigger" type="button" class="min-h-11 min-w-11 shrink-0 rounded-lg border border-slate-200 p-2 text-navy-700 lg:hidden" aria-label="فتح القائمة" aria-controls="main-navigation" :aria-expanded="mobileMenuOpen" @click="mobileMenuOpen = true">
                        <svg class="h-5 w-5" aria-hidden="true"><use href="#aims-icon-menu" /></svg>
                    </button>
                    <div class="min-w-0">
                        <p class="hidden text-xs text-slate-400 sm:block">معهد الأمل للأيتام</p>
                        <p class="truncate font-display text-base font-bold text-navy-800 sm:text-lg">{{ title }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-1 sm:gap-2">
                    <QuickSearch />
                    <Link v-if="page.props.workCounters" href="/tasks" class="relative hidden rounded-lg px-2 py-1.5 text-xs font-semibold text-navy-700 hover:bg-navy-50 sm:inline-flex sm:items-center sm:gap-1"
                        :aria-label="`مهامي الرسمية: ${page.props.workCounters.tasks} بانتظار إجرائي`">
                        مهامي<span class="rounded-full px-1.5 text-[11px]" :class="page.props.workCounters.tasks ? 'bg-gold-500 text-white' : 'bg-slate-100 text-slate-500'">{{ page.props.workCounters.tasks }}</span>
                    </Link>
                    <ConnectionStatus />
                    <NotificationBell channel="mail" />
                    <NotificationBell />
                    <div ref="userMenuRoot" class="relative">
                        <button type="button" class="flex items-center gap-2 rounded-xl p-1 hover:bg-navy-50" :aria-expanded="userMenuOpen" aria-haspopup="true" :aria-label="`قائمة المستخدم: ${displayName}`" @click.stop="userMenuOpen = !userMenuOpen">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-navy-700 font-display text-sm font-bold text-white ring-2 ring-gold-300" aria-hidden="true">{{ displayName.charAt(0) || 'أ' }}</span>
                            <span class="hidden max-w-36 truncate text-sm font-semibold text-navy-800 lg:block">{{ displayName }}</span>
                        </button>
                        <div v-if="userMenuOpen" class="absolute left-0 top-full z-50 mt-2 w-60 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                            <div class="border-b border-slate-100 px-4 py-3">
                                <p class="truncate text-sm font-semibold text-navy-800">{{ displayName }}</p>
                                <p v-if="page.props.auth?.user?.employee_number" class="text-xs text-slate-500" dir="ltr">{{ page.props.auth.user.employee_number }}</p>
                            </div>
                            <nav class="py-1 text-sm" aria-label="قائمة المستخدم">
                                <Link href="/dashboard" class="block px-4 py-2 text-slate-700 hover:bg-navy-50" @click="userMenuOpen = false">مساحتي</Link>
                                <Link href="/notifications" class="block px-4 py-2 text-slate-700 hover:bg-navy-50" @click="userMenuOpen = false">إشعاراتي</Link>
                                <Link href="/change-password" class="block px-4 py-2 text-slate-700 hover:bg-navy-50" @click="userMenuOpen = false">تغيير كلمة المرور</Link>
                                <button type="button" :disabled="loggingOut" class="flex w-full items-center gap-2 border-t border-slate-100 px-4 py-2 text-right text-red-700 hover:bg-red-50" @click="logout">
                                    <svg class="h-4 w-4" aria-hidden="true"><use href="#aims-icon-logout" /></svg>
                                    {{ loggingOut ? 'جارٍ الخروج...' : 'تسجيل الخروج' }}
                                </button>
                            </nav>
                        </div>
                    </div>
                </div>
            </header>

            <main id="main-content" tabindex="-1" class="min-w-0 mx-auto w-full max-w-[100rem] flex-1 space-y-4 p-3 sm:p-5 xl:p-6">
                <div v-if="successMessage" class="alert-success flex items-start justify-between gap-3" role="status">
                    <span>{{ successMessage }}</span>
                    <button type="button" class="shrink-0 text-emerald-700 hover:text-emerald-900" aria-label="إخفاء الرسالة" @click="successMessage = ''">
                        <svg class="h-4 w-4" aria-hidden="true"><use href="#aims-icon-close" /></svg>
                    </button>
                </div>
                <div v-if="errorMessages.length" class="alert-error" role="alert">
                    <p class="font-semibold">تعذّر إتمام العملية:</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        <li v-for="message in errorMessages.slice(0, 4)" :key="message">{{ message }}</li>
                    </ul>
                </div>
                <slot />
            </main>

            <footer class="flex items-center justify-center gap-2 px-5 pb-6 text-xs text-slate-400">
                <span class="h-px w-8 bg-gold-300" aria-hidden="true"></span>
                معهد الأمل للأيتام · منذ 1949
                <span class="h-px w-8 bg-gold-300" aria-hidden="true"></span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.skip-link { position: fixed; top: .5rem; right: .5rem; z-index: 100; padding: .75rem 1rem; background: #fff; color: #233756; border: 2px solid #324f7c; border-radius: .5rem; transform: translateY(-200%); }
.skip-link:focus { transform: translateY(0); }
.aims-sidebar-nav :is(a, button) { min-height: 44px; overflow-wrap: anywhere; }
.aims-sidebar-nav svg { width: 20px; height: 20px; }
.aims-sidebar-nav :is(a, button):focus-visible { outline: 2px solid #ebcb66; outline-offset: -2px; }
.aims-shell > aside { max-width: calc(100vw - 2rem); }
.aims-shell header :deep(button), .aims-shell > aside button { min-height: 44px; min-width: 44px; }
.aims-shell main { overflow-wrap: anywhere; }
@media (prefers-reduced-motion: reduce) {
    .aims-shell > aside, .aims-shell > div { transition: none; }
}
</style>

