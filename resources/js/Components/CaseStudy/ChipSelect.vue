<script setup>
import { computed, ref } from 'vue';

// Multi-select as removable chips with a search box; values are reference ids from «ثوابت النظام».
const props = defineProps({ modelValue: { type: Array, default: () => [] }, options: { type: Array, default: () => [] }, label: String, disabled: Boolean, name: String });
const emit = defineEmits(['update:modelValue']);
const query = ref('');
const selected = computed(() => (props.modelValue || []).map(Number));
const chosen = computed(() => props.options.filter(o => selected.value.includes(Number(o.id))));
const available = computed(() => props.options.filter(o => !selected.value.includes(Number(o.id)) && !o.name.endsWith('(قيمة موقوفة)') && (!query.value || o.name.includes(query.value.trim()))));
const toggle = id => emit('update:modelValue', selected.value.includes(Number(id)) ? selected.value.filter(v => v !== Number(id)) : [...selected.value, Number(id)]);
</script>

<template>
    <div class="space-y-2" :data-error-field="name">
        <div v-if="chosen.length" class="flex flex-wrap gap-1.5">
            <button v-for="o in chosen" :key="o.id" type="button" class="rounded-full bg-navy-100 px-2.5 py-0.5 text-sm text-navy-800" :disabled="disabled" :aria-label="`إزالة ${o.name}`" @click="toggle(o.id)">{{ o.name }} <span aria-hidden="true">×</span></button>
        </div>
        <template v-if="!disabled">
            <input v-if="options.length > 8" v-model="query" type="search" class="field" :aria-label="`بحث في ${label}`" placeholder="ابحث…">
            <div class="flex flex-wrap gap-1.5">
                <button v-for="o in available" :key="o.id" type="button" class="rounded-full border border-slate-300 px-2.5 py-0.5 text-sm hover:bg-slate-50" @click="toggle(o.id)">+ {{ o.name }}</button>
            </div>
        </template>
        <p v-else-if="!chosen.length" class="text-sm text-slate-500">لا يوجد</p>
    </div>
</template>
