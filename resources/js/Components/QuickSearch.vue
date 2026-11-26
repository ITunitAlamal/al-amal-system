<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Top-bar quick search (Ctrl+K or «/»). Results come from /search, which only returns
 * what the signed-in user may open.
 */
const open = ref(false);
const term = ref('');
const groups = ref([]);
const loading = ref(false);
const active = ref(0);
const input = ref(null);
let timer = null;
let requestId = 0;

const flat = computed(() => groups.value.flatMap(group => group.items));

watch(term, value => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        groups.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = setTimeout(search, 250);
});

async function search() {
    const current = ++requestId;
    try {
        const response = await fetch(`/search?q=${encodeURIComponent(term.value.trim())}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (current !== requestId) return;
        groups.value = response.ok ? (await response.json()).groups : [];
        active.value = 0;
    } catch {
        groups.value = [];
    } finally {
        if (current === requestId) loading.value = false;
    }
}

async function show() {
    open.value = true;
    await nextTick();
    input.value?.focus();
}
function hide() {
    open.value = false;
    term.value = '';
    groups.value = [];
}
function go(item) {
    hide();
    router.visit(item.url);
}
function onInputKey(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, flat.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter' && flat.value[active.value]) {
        event.preventDefault();
        go(flat.value[active.value]);
    } else if (event.key === 'Escape') {
        hide();
    }
}
function onGlobalKey(event) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        show();
    } else if (event.key === '/' && !typing) {
        event.preventDefault();
        show();
    }
}
const indexOf = item => flat.value.indexOf(item);

onMounted(() => document.addEventListener('keydown', onGlobalKey));
onUnmounted(() => {
    document.removeEventListener('keydown', onGlobalKey);
    clearTimeout(timer);
});
</script>

<template>
    <div>
        <button type="button" class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 hover:border-navy-200 hover:text-navy-700 md:w-64" aria-label="بحث سريع" @click="show">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
            <span class="hidden md:inline">بحث سريع…</span>
            <kbd class="ms-auto hidden rounded border border-slate-200 bg-white px-1.5 text-[10px] text-slate-400 md:inline" dir="ltr">Ctrl K</kbd>
        </button>

        <div v-if="open" class="fixed inset-0 z-[80] flex items-start justify-center bg-navy-950/40 p-0 sm:p-4 sm:pt-24" @click.self="hide">
            <div class="flex h-full w-full flex-col overflow-hidden bg-white shadow-2xl sm:h-auto sm:max-w-xl sm:rounded-2xl" role="dialog" aria-modal="true" aria-label="بحث سريع">
                <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
                    <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input ref="input" v-model="term" type="search" class="flex-1 border-0 bg-transparent p-1 text-base focus:ring-0" placeholder="ابحث في الصفحات، طلباتك، مهامك…" aria-label="عبارة البحث" role="combobox" :aria-expanded="flat.length > 0" aria-controls="quick-search-results" autocomplete="off" @keydown="onInputKey" />
                    <button type="button" class="rounded-lg px-2 py-1 text-xs text-slate-500 hover:bg-slate-100" @click="hide">إغلاق</button>
                </div>
                <div id="quick-search-results" class="max-h-[70vh] flex-1 overflow-y-auto p-2" role="listbox">
                    <p v-if="term.trim().length < 2" class="px-3 py-6 text-center text-sm text-slate-400">اكتب حرفين على الأقل.</p>
                    <p v-else-if="loading" class="px-3 py-6 text-center text-sm text-slate-400">جارٍ البحث…</p>
                    <p v-else-if="!flat.length" class="px-3 py-6 text-center text-sm text-slate-500">لا توجد نتائج ضمن ما يتاح لك.</p>
                    <template v-else>
                        <div v-for="group in groups" :key="group.label" class="mb-2">
                            <p class="px-3 pb-1 pt-2 text-xs font-semibold text-gold-700">{{ group.label }}</p>
                            <button v-for="item in group.items" :key="item.url + item.title" type="button" role="option" :aria-selected="indexOf(item) === active"
                                :class="['flex w-full flex-col items-start rounded-xl px-3 py-2 text-right', indexOf(item) === active ? 'bg-navy-50' : 'hover:bg-slate-50']"
                                @mouseenter="active = indexOf(item)" @click="go(item)">
                                <span class="text-sm font-semibold text-navy-800">{{ item.title }}</span>
                                <span v-if="item.subtitle" class="text-xs text-slate-500" dir="auto">{{ item.subtitle }}</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
