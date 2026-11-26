<script setup>
import { arabicLatinDigitsLocale, parseDate } from '../Support/dates.js';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

/**
 * Separate top-bar feeds share display and audio controls, with independent sound preferences.
 * Mail reads the authorized inbox; opening a letter records reading through its existing page.
 */
const props = defineProps({ channel: { type: String, default: 'notifications' } });
const isMail = props.channel === 'mail';
const title = isMail ? 'البريد الداخلي' : 'الإشعارات';
const endpoint = isMail ? '/correspondence/recent' : '/notifications/recent';
const inboxUrl = '/correspondence?channel=internal&box=inbox';
const POLL_MS = isMail ? 15000 : 60000;
const SOUND_GAP_MS = 20000;
const PREFS_KEY = isMail ? 'aims.mail.prefs' : 'aims.notify.prefs';
const LAST_SOUND_KEY = isMail ? 'aims.mail.lastSoundAt' : 'aims.notify.lastSoundAt';
const SOUND_OPTIONS = [
    { id: 'chime', label: 'نغمة مزدوجة', frequencies: [880, 1174.66], wave: 'sine', spacing: 0.4, duration: 0.34, hold: 0.18, peak: 0.8 },
    { id: 'bell', label: 'جرس', frequencies: [1568], wave: 'triangle', spacing: 0, duration: 0.95, hold: 0.03, peak: 0.8 },
    { id: 'ping', label: 'تنبيه هادئ', frequencies: [740], wave: 'sine', spacing: 0, duration: 0.24, hold: 0.04, peak: 0.45 },
    { id: 'alert', label: 'تنبيه متكرر', frequencies: [1174.66, 1174.66, 1568], wave: 'triangle', spacing: 0.22, duration: 0.16, hold: 0.08, peak: 0.8 },
];

const page = usePage();
const open = ref(false);
const items = ref([]);
const unread = ref(isMail ? 0 : (page.props.notifications?.unread ?? 0));
const loading = ref(false);
const failed = ref(false);
const toast = ref(null);
const showSettings = ref(false);
const prefs = ref(readPrefs());
const root = ref(null);
const seen = new Set();
let pollTimer = null;
let toastTimer = null;
let audio = null;
let activeTones = [];
let primed = false;
let disposed = false;
let latestDelivery = 0;
let removeFinishListener;

watch(() => page.props.notifications?.unread, value => {
    if (!isMail && typeof value === 'number') unread.value = value;
});

function readPrefs() {
    const defaults = { sound: isMail, soundType: 'chime', volume: 1, quietFrom: '', quietTo: '' };
    try {
        const saved = { ...defaults, ...JSON.parse(window.localStorage.getItem(PREFS_KEY) || '{}') };
        if (!SOUND_OPTIONS.some(option => option.id === saved.soundType)) saved.soundType = defaults.soundType;
        return saved;
    } catch {
        return defaults;
    }
}
function savePrefs() {
    try {
        window.localStorage.setItem(PREFS_KEY, JSON.stringify(prefs.value));
    } catch {
        // Preferences are a convenience; ignore blocked storage.
    }
}

async function fetchRecent() {
    try {
        const response = await fetch(endpoint, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        return response.ok ? await response.json() : null;
    } catch {
        return null;
    }
}

async function poll() {
    if (disposed || loading.value || (!isMail && document.visibilityState !== 'visible')) return;
    loading.value = true;
    const data = await fetchRecent();
    loading.value = false;
    if (disposed) return;
    failed.value = !data;
    if (!data) return;
    unread.value = data.unread;
    items.value = data.items;
    const fresh = data.items.filter(item => !item.read && (isMail ? item.id > latestDelivery : !seen.has(item.id)));
    if (isMail) latestDelivery = Math.max(latestDelivery, ...data.items.map(item => item.id));
    data.items.forEach(item => seen.add(item.id));
    // The first poll only learns what already exists: no toast or sound for old notifications.
    if (!primed) {
        primed = true;
        return;
    }
    if (fresh.length) {
        showToast(fresh[0], fresh.length);
        playSound();
    }
}

function showToast(item, count) {
    toast.value = { ...item, more: count - 1 };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 7000);
}

function inQuietHours() {
    const { quietFrom, quietTo } = prefs.value;
    if (!quietFrom || !quietTo) return false;
    const now = new Date();
    const minutes = now.getHours() * 60 + now.getMinutes();
    const [fh, fm] = quietFrom.split(':').map(Number);
    const [th, tm] = quietTo.split(':').map(Number);
    const from = fh * 60 + fm;
    const to = th * 60 + tm;
    return from <= to ? minutes >= from && minutes < to : minutes >= from || minutes < to;
}

async function chime(volume) {
    const level = Number.isFinite(volume) ? Math.min(1, Math.max(0, volume)) : 1;
    if (level === 0) return;
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        if (audio.state === 'suspended') await audio.resume();
        activeTones.forEach(tone => { tone.stop(); tone.disconnect(); });
        activeTones = [];
        const sound = SOUND_OPTIONS.find(option => option.id === prefs.value.soundType) || SOUND_OPTIONS[0];
        const start = audio.currentTime;
        sound.frequencies.forEach((frequency, index) => {
            const at = start + index * sound.spacing;
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();
            oscillator.type = sound.wave;
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(Math.max(0.001, level * sound.peak), at + 0.02);
            gain.gain.setValueAtTime(Math.max(0.001, level * sound.peak), at + sound.hold);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + sound.duration);
            oscillator.connect(gain).connect(audio.destination);
            oscillator.onended = () => {
                oscillator.disconnect();
                gain.disconnect();
                activeTones = activeTones.filter(tone => tone !== oscillator);
            };
            activeTones.push(oscillator);
            oscillator.start(at);
            oscillator.stop(at + sound.duration + 0.02);
        });
    } catch {
        // No audio support: the badge and toast still show the notification.
    }
}

function playSound() {
    if (!prefs.value.sound || inQuietHours()) return;
    try {
        const last = Number(window.localStorage.getItem(LAST_SOUND_KEY) || 0);
        if (Date.now() - last < SOUND_GAP_MS) return;
        window.localStorage.setItem(LAST_SOUND_KEY, String(Date.now()));
    } catch {
        // Without storage, fall back to this tab only.
    }
    chime(Number(prefs.value.volume));
}

function toggleSound() {
    prefs.value.sound = !prefs.value.sound;
    savePrefs();
    if (prefs.value.sound) chime(Number(prefs.value.volume)); // the click also unlocks audio in the browser
}

async function toggleMenu() {
    open.value = !open.value;
    if (open.value) {
        document.dispatchEvent(new CustomEvent('aims:feed-open', { detail: props.channel }));
        await poll();
    }
}

function openItem(item) {
    open.value = false;
    toast.value = null;
    if (isMail) router.visit(item.url);
    else router.post(`/notifications/${item.id}/open`);
}

function readAll() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, onSuccess: () => { unread.value = 0; items.value = items.value.map(item => ({ ...item, read: true })); } });
}

function when(value) {
    const date = parseDate(value);
    if (!date) return '';
    const minutes = Math.round((Date.now() - date.getTime()) / 60000);
    if (minutes < 1) return 'الآن';
    if (minutes < 60) return `قبل ${minutes} دقيقة`;
    if (minutes < 1440) return `قبل ${Math.round(minutes / 60)} ساعة`;
    return new Intl.DateTimeFormat(arabicLatinDigitsLocale, { day: 'numeric', month: 'short' }).format(date);
}

function onDocumentClick(event) {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
}
function onKey(event) {
    if (event.key === 'Escape') open.value = false;
}
function onVisible() {
    if (document.visibilityState === 'visible') poll();
}

function onFeedOpen(event) {
    if (event.detail !== props.channel) open.value = false;
}

function unlockAudio() {
    if (!prefs.value.sound || disposed) return;
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        if (audio.state === 'suspended') audio.resume().catch(() => {});
    } catch {
        // Audio is optional; the unread badge remains available.
    }
}

onMounted(() => {
    poll();
    pollTimer = setInterval(poll, POLL_MS);
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKey);
    document.addEventListener('visibilitychange', onVisible);
    document.addEventListener('pointerdown', unlockAudio);
    document.addEventListener('keydown', unlockAudio);
    document.addEventListener('aims:feed-open', onFeedOpen);
    removeFinishListener = router.on('finish', () => poll());
});
onUnmounted(() => {
    disposed = true;
    removeFinishListener?.();
    clearInterval(pollTimer);
    clearTimeout(toastTimer);
    activeTones.forEach(tone => { tone.stop(); tone.disconnect(); });
    activeTones = [];
    audio?.close().catch(() => {});
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('visibilitychange', onVisible);
    document.removeEventListener('pointerdown', unlockAudio);
    document.removeEventListener('keydown', unlockAudio);
    document.removeEventListener('aims:feed-open', onFeedOpen);
});
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" class="relative rounded-lg p-2 text-navy-700 hover:bg-navy-50" :title="title" :aria-label="unread ? `${title}: ${unread} غير مقروء` : title" :aria-expanded="open" aria-haspopup="dialog" @click.stop="toggleMenu">
            <svg v-if="isMail" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></svg>
            <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" /></svg>
            <span v-if="unread" class="absolute -top-0.5 -left-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ unread > 99 ? '99+' : unread }}</span>
        </button>

        <div v-if="open" class="fixed inset-x-4 top-16 z-50 mt-2 max-h-[calc(100dvh-6rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-xl sm:absolute sm:inset-x-auto sm:left-0 sm:top-full sm:w-[22rem]" role="dialog" :aria-label="title" :aria-busy="loading">
            <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <p class="font-display font-bold text-navy-800">{{ title }}</p>
                <div class="flex items-center gap-1">
                    <button type="button" class="rounded-lg px-2 py-1 text-xs text-slate-600 hover:bg-slate-100" :aria-pressed="prefs.sound" @click="toggleSound">
                        {{ prefs.sound ? '🔔 الصوت مفعّل' : '🔕 الصوت متوقف' }}
                    </button>
                    <button type="button" class="rounded-lg px-2 py-1 text-xs text-slate-600 hover:bg-slate-100" :aria-expanded="showSettings" aria-label="إعدادات الصوت" @click="showSettings = !showSettings">⚙</button>
                </div>
            </div>
            <div v-if="showSettings" class="space-y-2 border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs">
                <label class="flex items-center gap-2"><span class="w-20 text-slate-600">نوع الصوت</span><select v-model="prefs.soundType" class="field min-w-0 flex-1 px-2 py-1 text-xs" @change="savePrefs"><option v-for="sound in SOUND_OPTIONS" :key="sound.id" :value="sound.id">{{ sound.label }}</option></select></label>
                <label class="flex items-center gap-2"><span class="w-20 text-slate-600">مستوى الصوت</span><input v-model="prefs.volume" type="range" min="0.1" max="1" step="0.1" class="flex-1" @change="savePrefs" /><span>{{ Math.round(Number(prefs.volume) * 100) }}٪</span></label>
                <button type="button" class="rounded-lg border border-slate-300 px-3 py-1 text-slate-700 hover:bg-white" @click="chime(Number(prefs.volume))">تجربة الصوت</button>
                <div class="flex items-center gap-2"><span class="w-20 text-slate-600">ساعات الهدوء</span>
                    <input v-model="prefs.quietFrom" type="time" class="field w-auto px-2 py-1 text-xs" aria-label="بداية الهدوء" @change="savePrefs" />
                    <span>—</span>
                    <input v-model="prefs.quietTo" type="time" class="field w-auto px-2 py-1 text-xs" aria-label="نهاية الهدوء" @change="savePrefs" />
                </div>
                <p class="text-slate-500">يُحفظ التفضيل على هذا الجهاز فقط. قد يتطلب تشغيل الصوت النقر داخل الصفحة أولًا.</p>
            </div>
            <p v-if="failed" class="px-4 py-3 text-center text-xs text-red-600">تعذر تحديث القائمة. <button type="button" class="underline" @click="poll">إعادة المحاولة</button></p>
            <ul v-if="items.length" class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                <li v-for="item in items" :key="item.id">
                    <button type="button" class="flex w-full gap-3 px-4 py-3 text-right hover:bg-navy-50/60" @click="openItem(item)">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.read ? 'bg-transparent' : 'bg-blue-600'" :aria-label="item.read ? undefined : 'غير مقروء'"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm" :class="item.read ? 'text-slate-600' : 'font-semibold text-navy-800'">{{ item.title }}</span>
                            <span v-if="item.body" class="mt-0.5 block truncate text-xs text-slate-500">{{ item.body }}</span>
                            <span class="mt-0.5 block text-[11px] text-slate-400">{{ when(item.createdAt) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
            <p v-else-if="loading" class="px-4 py-8 text-center text-sm text-slate-500">جارٍ تحميل {{ title }}…</p>
            <p v-else-if="!failed" class="px-4 py-8 text-center text-sm text-slate-500">{{ isMail ? 'لا توجد مراسلات واردة.' : 'لا توجد إشعارات.' }}</p>
            <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5 text-xs">
                <Link :href="isMail ? inboxUrl : '/notifications'" class="font-semibold text-blue-700 hover:underline" @click="open = false">{{ isMail ? 'عرض البريد الوارد' : 'عرض كل الإشعارات' }}</Link>
                <button v-if="unread && !isMail" type="button" class="text-slate-600 hover:text-navy-800" @click="readAll">تعليم الكل كمقروء</button>
            </div>
        </div>

        <div class="pointer-events-none fixed bottom-4 z-[70] w-[20rem] max-w-[calc(100vw-2rem)]" :class="isMail ? 'right-4' : 'left-4'" aria-live="polite" role="status">
            <div v-if="toast" class="pointer-events-auto rounded-2xl border border-navy-100 bg-white p-4 shadow-xl">
                <p class="text-xs font-semibold text-gold-700">{{ isMail ? 'مراسلة واردة جديدة' : 'إشعار جديد' }}<span v-if="toast.more"> (+{{ toast.more }})</span></p>
                <p class="mt-1 text-sm font-semibold text-navy-800">{{ toast.title }}</p>
                <p v-if="toast.body" class="mt-0.5 truncate text-xs text-slate-500">{{ toast.body }}</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" @click="openItem(toast)">فتح</button>
                    <button type="button" class="btn btn-sm btn-secondary" @click="toast = null">إخفاء</button>
                </div>
            </div>
        </div>
    </div>
</template>
