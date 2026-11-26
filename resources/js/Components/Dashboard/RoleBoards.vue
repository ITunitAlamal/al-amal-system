<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import RoleBoard from './RoleBoard.vue';

// Tabs when the user holds several roles (e.g. a head who is also system administrator). The chosen tab is a
// per-browser convenience only. A board that failed shows a retry; the others keep working.
const props = defineProps({ boards: { type: Array, required: true }, storageKey: { type: String, default: 'aims.board' } });
const read = () => { try { return localStorage.getItem(props.storageKey); } catch { return null; } };
const active = ref(read());
const current = computed(() => props.boards.find(b => b.key === active.value) || props.boards[0]);
watch(active, key => { try { localStorage.setItem(props.storageKey, key); } catch { /* storage blocked */ } });
const retrying = ref(false);
function retry() { retrying.value = true; router.reload({ only: ['boards'], onFinish: () => { retrying.value = false; } }); }
function keyNav(event, index) {
    const step = { ArrowLeft: 1, ArrowRight: -1 }[event.key]; // RTL: left moves to the next tab
    if (!step) return;
    const next = props.boards[(index + step + props.boards.length) % props.boards.length];
    active.value = next.key;
    document.getElementById(`tab-${next.key}`)?.focus();
}
</script>

<template>
    <div v-if="boards.length" class="space-y-4">
        <div v-if="boards.length > 1" class="flex gap-1 overflow-x-auto rounded-xl bg-white p-1 ring-1 ring-slate-200" role="tablist" aria-label="لوحات أدواري">
            <button v-for="(b, i) in boards" :id="`tab-${b.key}`" :key="b.key" type="button" role="tab" :aria-selected="current.key === b.key" :tabindex="current.key === b.key ? 0 : -1"
                :class="['whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold', current.key === b.key ? 'bg-navy-700 text-white' : 'text-slate-600 hover:bg-slate-50']"
                @click="active = b.key" @keydown="keyNav($event, i)">{{ b.title }}</button>
        </div>
        <div role="tabpanel" :aria-labelledby="boards.length > 1 ? `tab-${current.key}` : undefined">
            <div v-if="current.error" class="alert-error flex flex-wrap items-center gap-3" role="alert">
                {{ current.error }}
                <button type="button" class="btn btn-sm btn-secondary" :disabled="retrying" @click="retry">{{ retrying ? 'جارٍ إعادة التحميل…' : 'إعادة المحاولة' }}</button>
            </div>
            <RoleBoard v-else :board="current" />
        </div>
    </div>
</template>
