<script setup>
import { ref } from 'vue';
import { menuIconPaths } from '../Support/menuIcons.js';

// One editable line of «القائمة الرئيسية»: a group header or a link.
const props = defineProps({
    entry: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    icons: { type: Array, default: () => [] },
    first: Boolean,
    last: Boolean,
    hiddenReason: { type: String, default: '' },
    previewName: { type: String, default: '' },
    childCount: { type: Number, default: null },
});
const emit = defineEmits(['up', 'down', 'move', 'update']);
const picking = ref(false);
const audienceOpen = ref(false);
const roleName = code => props.roles.find(role => role.code === code)?.name || code;

function pick(icon) {
    emit('update', 'icon', icon);
    picking.value = false;
}
function toggleRole(code, on) {
    const next = new Set(props.entry.audience_roles);
    if (on) { next.add(code); } else { next.delete(code); }
    emit('update', 'audience_roles', [...next]);
}
</script>

<template>
    <div :class="entry.is_active ? '' : 'opacity-60'">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex flex-col">
                <button type="button" class="rounded px-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30" :disabled="first" :aria-label="`تحريك «${entry.label_ar}» لأعلى`" @click="emit('up')">▲</button>
                <button type="button" class="rounded px-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30" :disabled="last" :aria-label="`تحريك «${entry.label_ar}» لأسفل`" @click="emit('down')">▼</button>
            </div>

            <div class="relative">
                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-navy-700 hover:border-navy-300" :aria-label="`تغيير أيقونة «${entry.label_ar}»`" :aria-expanded="picking" @click="picking = !picking">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="menuIconPaths[entry.icon] || menuIconPaths.folder" /></svg>
                </button>
                <div v-if="picking" class="absolute right-0 z-30 mt-1 grid w-64 grid-cols-6 gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                    <button v-for="icon in icons" :key="icon" type="button" class="flex h-9 items-center justify-center rounded-lg hover:bg-navy-50" :class="icon === entry.icon ? 'bg-navy-100 text-navy-800' : 'text-slate-600'" :aria-label="icon" @click="pick(icon)">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="menuIconPaths[icon]" /></svg>
                    </button>
                </div>
            </div>

            <input :value="entry.label_ar" class="field min-w-[11rem] flex-1" :class="entry.is_group ? 'font-bold' : ''" maxlength="150" required :aria-label="`اسم «${entry.label_ar}»`" @input="emit('update', 'label_ar', $event.target.value)" />

            <label class="flex items-center gap-1.5 text-sm" :title="entry.locked ? 'يبقى ظاهرًا دائمًا' : ''">
                <input type="checkbox" :checked="entry.is_active" :disabled="entry.locked" @change="emit('update', 'is_active', $event.target.checked)" /> ظاهر
            </label>

            <select v-if="!entry.is_group" :value="entry.parent_id ?? ''" class="field w-48 text-sm" :aria-label="`مكان «${entry.label_ar}»`" @change="emit('move', $event.target.value)">
                <option value="">رابط مباشر (بلا مجموعة)</option>
                <option v-for="group in groups" :key="group.id" :value="group.id">ضمن: {{ group.label_ar }}</option>
            </select>

            <button type="button" class="btn btn-sm btn-secondary" :disabled="entry.locked" :aria-expanded="audienceOpen" @click="audienceOpen = !audienceOpen">
                {{ entry.audience_roles.length ? `أدوار محددة (${entry.audience_roles.length})` : 'لكل المخوّلين' }}
            </button>
            <slot />
        </div>

        <p class="mt-1 ps-16 text-xs leading-5 text-slate-500">
            <template v-if="entry.is_group">مجموعة · {{ childCount }} رابط</template>
            <template v-else><span dir="ltr">{{ entry.page }}</span> · يظهر لـ: {{ entry.rule }}</template>
            <template v-if="entry.audience_roles.length"> · وفقط: {{ entry.audience_roles.map(roleName).join('، ') }}</template>
            <span v-if="hiddenReason" class="block text-amber-700">لا يراه {{ previewName }}: {{ hiddenReason }}</span>
            <button v-if="entry.default_label && entry.label_ar !== entry.default_label" type="button" class="text-blue-700 hover:underline" @click="emit('update', 'label_ar', entry.default_label)">استعادة الاسم «{{ entry.default_label }}»</button>
        </p>

        <div v-if="audienceOpen" class="mt-2 ms-16 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs leading-5 text-slate-600">اختر الأدوار التي يظهر لها هذا {{ entry.is_group ? 'القسم من القائمة' : 'الرابط' }}. بلا اختيار يظهر لكل من تسمح له صلاحية الصفحة. التقييد يُخفي فقط ولا يمنح صلاحية.</p>
            <div class="mt-2 grid gap-1 sm:grid-cols-2">
                <label v-for="role in roles" :key="role.code" class="flex items-center gap-2 text-sm">
                    <input type="checkbox" :checked="entry.audience_roles.includes(role.code)" @change="toggleRole(role.code, $event.target.checked)" /> {{ role.name }}
                </label>
            </div>
        </div>
    </div>
</template>
