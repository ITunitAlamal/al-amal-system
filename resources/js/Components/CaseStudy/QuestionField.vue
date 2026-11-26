<script setup>
import { computed } from 'vue';
import ChipSelect from './ChipSelect.vue';

// One question of the case study, rendered from the server's questionnaire. «أخرى» reveals its explanation.
// A repeat question renders its columns per row with this same component.
const props = defineProps({ q: { type: Object, required: true }, holder: { type: Object, required: true }, path: { type: String, required: true },
    options: { type: Object, default: () => ({}) }, persons: { type: Array, default: () => [] }, errors: { type: Object, default: () => ({}) },
    disabled: Boolean, required: Boolean });
const list = computed(() => props.options[props.q.list] || []);
const value = computed({ get: () => props.holder[props.q.key], set: v => { props.holder[props.q.key] = v; } });
const otherId = computed(() => list.value.find(o => o.code === 'other')?.id);
const choseOther = computed(() => !!props.q.other && otherId.value && [].concat(value.value ?? []).map(Number).includes(Number(otherId.value)));
const error = key => props.errors[`answers.${key}`];
const personKnown = computed(() => props.persons.some(p => p.key === value.value));
const personMode = computed({ get: () => (personKnown.value || !value.value ? (value.value || '') : '__other'), set: v => { value.value = v === '__other' ? ' ' : v; } });
function addRow() { value.value = [...(value.value || []), {}]; }
function removeRow(i) { const rows = [...(value.value || [])]; rows.splice(i, 1); value.value = rows; }
</script>

<template>
    <div :class="q.type === 'repeat' || q.type === 'textarea' || q.type === 'multi' ? 'sm:col-span-2 lg:col-span-3' : ''" :data-error-field="`answers.${path}`">
        <template v-if="q.type === 'repeat'">
            <p class="field-label">{{ q.label }}<span v-if="required" class="ms-0.5 text-red-600" title="مطلوب عند الإرسال">*</span></p>
            <div v-for="(row, i) in value || []" :key="i" class="mb-2 rounded-lg border border-slate-200 p-3">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <QuestionField v-for="c in q.columns" :key="c.key" :q="c" :holder="row" :path="`${path}.${i}.${c.key}`" :options="options" :persons="persons" :errors="errors" :disabled="disabled" />
                </div>
                <button v-if="!disabled" type="button" class="btn btn-sm btn-danger mt-2" @click="removeRow(i)">حذف الصف</button>
            </div>
            <button v-if="!disabled && (value || []).length < 30" type="button" class="btn btn-sm btn-secondary" @click="addRow">+ إضافة صف</button>
            <p v-if="error(path)" class="field-error" role="alert">{{ error(path) }}</p>
        </template>
        <label v-else class="block text-sm">
            <span>{{ q.label }}<span v-if="required" class="ms-0.5 text-red-600" title="مطلوب عند الإرسال">*</span></span>
            <select v-if="q.type === 'single'" v-model="value" class="field mt-1" :disabled="disabled"><option :value="undefined">غير محدد</option><option v-for="o in list" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            <select v-else-if="q.type === 'choice'" v-model="value" class="field mt-1" :disabled="disabled"><option :value="undefined">لم يُسأل بعد</option><option v-for="(text, key) in q.options" :key="key" :value="key">{{ text }}</option></select>
            <span v-else-if="q.type === 'multi'" class="mt-1 block"><ChipSelect v-model="value" :options="list" :label="q.label" :disabled="disabled" :name="`answers.${path}`" /></span>
            <textarea v-else-if="q.type === 'textarea'" v-model="value" class="field mt-1" rows="3" maxlength="3000" :disabled="disabled" />
            <input v-else-if="q.type === 'money'" v-model="value" type="number" min="0" step="0.01" dir="ltr" class="field mt-1" :disabled="disabled">
            <input v-else-if="q.type === 'number'" v-model="value" type="number" min="0" max="1000" dir="ltr" class="field mt-1" :disabled="disabled">
            <input v-else-if="q.type === 'date'" v-model="value" type="date" class="field mt-1" :disabled="disabled">
            <template v-else-if="q.type === 'person'">
                <select v-model="personMode" class="field mt-1" :disabled="disabled"><option value="">اختر</option><option v-for="p in persons" :key="p.key" :value="p.key">{{ p.name }}</option><option value="__other">اسم غير مسجل…</option></select>
                <input v-if="personMode === '__other'" v-model="value" class="field mt-1" maxlength="150" placeholder="اكتب الاسم" :disabled="disabled">
            </template>
            <input v-else v-model="value" class="field mt-1" maxlength="300" :disabled="disabled">
            <span v-if="error(path)" class="field-error" role="alert">{{ error(path) }}</span>
        </label>
        <label v-if="choseOther" class="mt-2 block text-sm">وضّح «أخرى»<span class="ms-0.5 text-red-600">*</span>
            <input v-model="holder[`${q.key}_other`]" class="field mt-1" maxlength="200" :disabled="disabled" :data-error-field="`answers.${path}_other`">
            <span v-if="error(`${path}_other`)" class="field-error" role="alert">{{ error(`${path}_other`) }}</span>
        </label>
    </div>
</template>
