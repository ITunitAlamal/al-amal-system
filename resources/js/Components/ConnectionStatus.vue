<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const status = ref('checking');
const lastConnected = ref(null);
const checking = ref(false);
const responseTime = ref(null);
const labels = {
    checking: 'جارٍ فحص الاتصال بالخادم',
    online: 'متصل بالخادم',
    offline: 'الشبكة غير متصلة',
    unavailable: 'تعذّر الوصول إلى الخادم',
    session: 'انتهت جلسة تسجيل الدخول',
};
const label = computed(() => labels[status.value]);
const details = computed(() => [
    label.value,
    lastConnected.value ? `آخر اتصال ناجح: ${lastConnected.value}` : '',
    status.value === 'online' && responseTime.value !== null ? `زمن استجابة الفحص: ${responseTime.value} مللي ثانية` : '',
    status.value === 'session' ? 'تسجيل الدخول مجددًا' : checking.value ? 'جارٍ الفحص…' : 'اضغط لإعادة الفحص',
].filter(Boolean).join('\n'));

let intervalId = null;
let currentController = null;
let disposed = false;

const CHECK_INTERVAL = 30000;
const REQUEST_TIMEOUT = 6000;

async function checkConnection() {
    if (currentController || disposed) return;
    if (!navigator.onLine) {
        status.value = 'offline';
        responseTime.value = null;
        return;
    }

    const controller = new AbortController();
    currentController = controller;
    checking.value = true;
    const started = performance.now();

    const timeoutId = window.setTimeout(() => {
        controller.abort();
    }, REQUEST_TIMEOUT);

    try {
        const response = await fetch('/connection-status', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            cache: 'no-store',
            redirect: 'manual',
            signal: controller.signal,
        });
        if (disposed) return;
        responseTime.value = null;

        if (
            response.status === 401 ||
            response.status === 419 ||
            response.type === 'opaqueredirect'
        ) {
            status.value = 'session';
            return;
        }

        if (!response.ok) {
            status.value = 'unavailable';
            return;
        }

        const data = await response.json();
        if (disposed) return;

        if (data.status === 'online') {
            status.value = 'online';
            responseTime.value = Math.round(performance.now() - started);

            lastConnected.value = new Date().toLocaleTimeString(
                'ar-PS',
                {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                }
            );
        } else {
            status.value = 'unavailable';
        }
    } catch {
        if (!disposed) {
            status.value = navigator.onLine ? 'unavailable' : 'offline';
            responseTime.value = null;
        }
    } finally {
        window.clearTimeout(timeoutId);

        if (currentController === controller) {
            currentController = null;
            checking.value = false;
        }
    }
}

function handleOffline() {
    status.value = 'offline';
    responseTime.value = null;
    currentController?.abort();
}

function handleVisibilityChange() {
    if (!document.hidden) {
        checkConnection();
    }
}

onMounted(() => {
    checkConnection();

    intervalId = window.setInterval(() => {
        if (!document.hidden) {
            checkConnection();
        }
    }, CHECK_INTERVAL);

    window.addEventListener('online', checkConnection);
    window.addEventListener('offline', handleOffline);

    document.addEventListener(
        'visibilitychange',
        handleVisibilityChange
    );
});

onUnmounted(() => {
    disposed = true;
    if (intervalId !== null) {
        window.clearInterval(intervalId);
    }

    currentController?.abort();

    window.removeEventListener('online', checkConnection);
    window.removeEventListener('offline', handleOffline);

    document.removeEventListener(
        'visibilitychange',
        handleVisibilityChange
    );
});
</script>

<template>
    <div class="inline-flex shrink-0 print:hidden">
        <component
            :is="status === 'session' ? 'a' : 'button'"
            :href="status === 'session' ? '/login' : undefined"
            :type="status === 'session' ? undefined : 'button'"
            :disabled="status !== 'session' && checking"
            :title="details"
            :aria-label="details"
            class="flex h-10 w-10 items-center justify-center rounded-lg hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-700 disabled:cursor-wait"
            :class="status === 'online' ? 'text-emerald-700' : status === 'checking' ? 'text-slate-500' : status === 'session' ? 'text-gold-700' : 'text-red-700'"
            @click="status !== 'session' && checkConnection()"
        >
            <svg class="h-5 w-5" :class="checking ? 'animate-pulse motion-reduce:animate-none' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 8.5a16 16 0 0 1 20 0M5 12a11 11 0 0 1 14 0M8.5 15.5a5.5 5.5 0 0 1 7 0" />
                <circle cx="12" cy="19" r="1" fill="currentColor" stroke="none" />
                <path v-if="status === 'offline' || status === 'unavailable'" d="m3 3 18 18" />
                <path v-if="status === 'session'" d="M12 5v5m0 3h.01" />
            </svg>
        </component>
        <span role="status" aria-live="polite" aria-atomic="true" class="sr-only">{{ label }}</span>
    </div>
</template>
