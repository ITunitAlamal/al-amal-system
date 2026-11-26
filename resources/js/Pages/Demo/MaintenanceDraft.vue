<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { formatDateTime } from '../../Support/dates.js';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    username: {
        type: String,
        required: true,
    },
    navigation: {
        type: Array,
        default: () => [],
    },
});

const STORAGE_KEY = 'aims.maintenance.demo.draft.key';
const API_BASE = '/form-drafts/';
const AUTOSAVE_DELAY = 2000;

const title = ref('');
const details = ref('');
const draftKey = ref('');
const version = ref(0);
const status = ref('loading');
const lastSaved = ref('');
const errorMessage = ref('');
const dirty = ref(false);

let saveTimer = null;
let saving = false;
let ready = false;
let disposed = false;
let requestController = null;

function newDraftKey() {
    return window.crypto.randomUUID();
}

function getDraftKey() {
    let key = localStorage.getItem(STORAGE_KEY);

    if (!key) {
        key = newDraftKey();
        localStorage.setItem(STORAGE_KEY, key);
    }

    return key;
}

function csrfToken() {
    return document.querySelector(
        'meta[name="csrf-token"]'
    )?.getAttribute('content') ?? '';
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(options.body ? {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            } : {}),
        },
        ...options,
    });

    if (response.status === 401 ||
        response.status === 419) {
        throw new Error(
            'انتهت جلسة الدخول. سجّل الدخول مجددًا قبل الحفظ.'
        );
    }

    if (response.redirected &&
        response.url.includes('/login')) {
        throw new Error(
            'انتهت جلسة الدخول. سجّل الدخول مجددًا.'
        );
    }

    const contentType =
        response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        throw new Error('استجابة الخادم غير متوقعة.');
    }

    const data = await response.json();

    return { response, data };
}

async function loadDraft() {
    status.value = 'loading';

    try {
        draftKey.value = getDraftKey();

        const { response, data } = await fetchJson(
            API_BASE + draftKey.value
        );

        if (!response.ok) {
            throw new Error(
                data.message || 'فشل تحميل المسودة.'
            );
        }

        title.value = data.payload?.title || '';
        details.value = data.payload?.details || '';
        version.value = data.version || 0;
        lastSaved.value = data.last_saved_at || '';

        dirty.value = false;
        status.value = data.exists ? 'saved' : 'new';
        ready = true;
    } catch (error) {
        status.value = 'error';
        errorMessage.value = error.message;
    }
}

function scheduleSave() {
    if (!ready || disposed) return;

    dirty.value = true;
    status.value = 'unsaved';

    clearTimeout(saveTimer);

    saveTimer = window.setTimeout(
        saveDraft,
        AUTOSAVE_DELAY
    );
}

watch([title, details], scheduleSave);

async function saveDraft() {
    clearTimeout(saveTimer);

    if (!ready || disposed || !dirty.value ||
        saving || status.value === 'conflict') {
        return;
    }

    if (!title.value.trim()) {
        status.value = 'unsaved';
        errorMessage.value =
            'أدخل عنوان الطلب حتى يمكن حفظ المسودة.';
        return;
    }

    if (!navigator.onLine) {
        status.value = 'offline';
        return;
    }

    saving = true;
    status.value = 'saving';
    errorMessage.value = '';

    const sentTitle = title.value;
    const sentDetails = details.value;
    const sentVersion = version.value;

    requestController = new AbortController();

    try {
        const { response, data } = await fetchJson(
            API_BASE + draftKey.value,
            {
                method: 'PUT',
                signal: requestController.signal,
                body: JSON.stringify({
                    version: sentVersion,
                    payload: {
                        title: sentTitle,
                        details: sentDetails,
                    },
                }),
            }
        );

        if (response.status === 409) {
            status.value = 'conflict';
            errorMessage.value =
                'توجد نسخة أحدث على الخادم. لا تُعد إرسال ' +
                'البيانات قبل مراجعة التعارض.';
            return;
        }

        if (!response.ok) {
            throw new Error(
                data.message || 'فشلت عملية الحفظ.'
            );
        }

        version.value = data.version;
        lastSaved.value = data.last_saved_at || '';

        const changedDuringSave =
            title.value !== sentTitle ||
            details.value !== sentDetails;

        dirty.value = changedDuringSave;
        status.value = changedDuringSave
            ? 'unsaved'
            : 'saved';

    } catch (error) {
        if (!disposed) {
            status.value = navigator.onLine
                ? 'error'
                : 'offline';

            errorMessage.value =
                error.message || 'تعذر الاتصال بالخادم.';
        }
    } finally {
        saving = false;
        requestController = null;

        if (dirty.value && !disposed &&
            status.value === 'unsaved') {
            clearTimeout(saveTimer);
            saveTimer = window.setTimeout(
                saveDraft,
                AUTOSAVE_DELAY
            );
        }
    }
}

function retrySave() {
    if (saving || status.value === 'conflict') return;

    if (!ready) {
        loadDraft();
        return;
    }

    if (dirty.value) {
        saveDraft();
    }
}

function handleOnline() {
    if (dirty.value && status.value !== 'conflict') {
        saveDraft();
    }
}

function preventAccidentalClose(event) {
    if (dirty.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => {
    loadDraft();

    window.addEventListener('online', handleOnline);
    window.addEventListener(
        'beforeunload',
        preventAccidentalClose
    );
});

onUnmounted(() => {
    disposed = true;
    clearTimeout(saveTimer);
    requestController?.abort();

    window.removeEventListener('online', handleOnline);
    window.removeEventListener(
        'beforeunload',
        preventAccidentalClose
    );
});
</script>

<template>
    <AppLayout
        title="تجربة الحفظ التلقائي"
        :username="username"
        :navigation="navigation"
    >
        <div class="mx-auto max-w-3xl">
            <div class="mb-6">
                <p class="text-sm font-semibold text-gold-400">
                    AIMS / اختبار المسودات
                </p>

                <h2 class="mt-2 text-2xl font-bold text-navy-700">
                    مسودة طلب صيانة
                </h2>

                <p class="mt-2 text-sm text-slate-500">
                    نموذج تجريبي لا ينشئ طلب صيانة رسميًا.
                </p>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div
                    role="status"
                    aria-live="polite"
                    class="mb-6 rounded-xl bg-slate-50 p-4 text-sm"
                >
                    <span v-if="status === 'loading'">
                        جارٍ استعادة المسودة...
                    </span>

                    <span
                        v-else-if="status === 'saved'"
                        class="font-semibold text-emerald-700"
                    >
                        ✓ تم حفظ المسودة على الخادم
                    </span>

                    <span v-else-if="status === 'saving'">
                        جارٍ الحفظ...
                    </span>

                    <span v-else-if="status === 'new'">
                        مسودة جديدة — لم تُحفظ بعد
                    </span>

                    <span
                        v-else-if="status === 'conflict'"
                        class="font-semibold text-red-700"
                    >
                        تعارض بين نسختين من المسودة
                    </span>

                    <span
                        v-else-if="status === 'offline'"
                        class="font-semibold text-red-700"
                    >
                        الاتصال منقطع — التعديلات غير محفوظة
                    </span>

                    <span
                        v-else
                        class="font-semibold text-gold-700"
                    >
                        توجد تعديلات غير محفوظة
                    </span>

                    <p
                        v-if="lastSaved"
                        class="mt-2 text-xs text-slate-500"
                    >
                        آخر حفظ مؤكد:
                        {{ formatDateTime(lastSaved) }}
                    </p>
                </div>

                <div
                    v-if="errorMessage"
                    role="alert"
                    class="mb-5 rounded-lg bg-red-50 p-3 text-sm text-red-700"
                >
                    {{ errorMessage }}
                </div>

                <fieldset
                    :disabled="!ready || status === 'conflict'"
                    class="space-y-5 disabled:opacity-60"
                >
                    <div>
                        <label
                            for="request-title"
                            class="mb-2 block text-sm font-semibold"
                        >
                            عنوان طلب الصيانة
                        </label>

                        <input
                            id="request-title"
                            v-model="title"
                            maxlength="150"
                            type="text"
                            placeholder="مثال: صيانة إنارة غرفة"
                            class="w-full rounded-xl border border-slate-200 p-3 outline-none focus:border-gold-400"
                        />
                    </div>

                    <div>
                        <label
                            for="request-details"
                            class="mb-2 block text-sm font-semibold"
                        >
                            تفاصيل الطلب
                        </label>

                        <textarea
                            id="request-details"
                            v-model="details"
                            maxlength="2000"
                            rows="6"
                            placeholder="اكتب تفاصيل العطل..."
                            class="w-full resize-y rounded-xl border border-slate-200 p-3 outline-none focus:border-gold-400"
                        ></textarea>
                    </div>
                </fieldset>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button
                        type="button"
                        :disabled="saving || status === 'conflict'"
                        class="rounded-xl bg-navy-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        @click="retrySave"
                    >
                        حفظ الآن / إعادة المحاولة
                    </button>
                </div>

                <p class="mt-5 text-xs leading-6 text-slate-500">
                    لا تغلق المتصفح أثناء وجود تعديلات غير محفوظة.
                    لا يحتوي هذا الاختبار على بيانات شخصية،
                    ولا يُنشئ طلبًا رسميًا أو موافقة إدارية.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
