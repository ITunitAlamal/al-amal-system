<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({ modelValue: { type: Object, required: true }, errors: { type: Object, default: () => ({}) }, prefix: { type: String, default: '' }, optional: Boolean });
const emit = defineEmits(['update:modelValue']);
const fields = ['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id'];
const snapshots = ['governorate', 'city', 'area', 'neighborhood'];
const labels = ['المحافظة', 'المدينة / البلدة / القرية / المخيم', 'الحي / المنطقة', 'المنطقة الفرعية'];
const options = ref([[], [], [], []]);
const loading = ref(false);
const failure = ref('');
const replacing = ref(false);
let revision = 0;
// The beneficiary portal has its own geography route behind the guardian guard; the staff one needs a staff session.
const endpoint = () => (typeof window !== 'undefined' && window.location.pathname.startsWith('/guardian') ? '/guardian/reference/geography' : '/reference/geography');
const legacy = computed(() => !replacing.value && !!props.modelValue.id && !fields.some(field => props.modelValue[field]) && !!props.modelValue.governorate && !props.modelValue.address_other);
const messages = computed(() => Object.entries(props.errors).filter(([key]) => key.startsWith(props.prefix) && [...fields, 'address_details', 'address_other'].includes(key.slice(props.prefix.length))).map(([, value]) => value));

async function load() {
    const current = ++revision;
    loading.value = true;
    failure.value = '';
    try {
        const lists = await Promise.all(fields.map(async (field, level) => {
            const parent = level ? props.modelValue[fields[level - 1]] : null;
            if (level && !parent) return [];
            const response = await fetch(`${endpoint()}${parent ? `?parent_id=${Number(parent)}` : ''}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('تعذر تحميل العناوين؛ أعد المحاولة.');
            return (await response.json()).data;
        }));
        if (current === revision) options.value = lists;
    } catch (error) { if (current === revision) failure.value = error.message; }
    finally { if (current === revision) loading.value = false; }
}
watch(() => fields.map(field => props.modelValue[field] ?? '').join(','), load, { immediate: true });
function select(level, value) {
    const other = value === 'other';
    const patch = { address_other: other };
    fields.forEach((field, index) => {
        if (index >= level) {
            patch[field] = index === level && value && !other ? Number(value) : null;
            patch[snapshots[index]] = index === level && patch[field] ? options.value[index].find(node => Number(node.id) === patch[field])?.name_ar ?? '' : (other && index < 2 ? 'غير مدرج' : '');
        }
    });
    emit('update:modelValue', patch);
}
function visible(level) {
    return level === 0 || (fields.slice(0, level).every(field => props.modelValue[field]) && (level === 1 || options.value[level].length > 0 || props.modelValue[fields[level]]));
}
function selected(level) {
    if (props.modelValue[fields[level]]) return props.modelValue[fields[level]];
    return props.modelValue.address_other && fields.slice(0, level).every(field => props.modelValue[field]) ? 'other' : '';
}
</script>

<template>
    <div class="space-y-3" :aria-busy="loading">
        <div v-if="legacy" class="rounded-lg border border-slate-200 p-3 text-sm">
            <p>عنوان محفوظ: {{ snapshots.map(field => modelValue[field]).filter(Boolean).join(' — ') }}</p>
            <p class="mt-1 text-xs text-slate-500">يبقى النص السابق محفوظًا حتى تختار استبداله بعنوان من القوائم.</p>
            <button type="button" class="mt-2 text-blue-700 underline" @click="replacing = true">اختيار عنوان جديد</button>
        </div>
        <template v-else>
            <div class="grid gap-3 sm:grid-cols-2">
                <template v-for="(field, level) in fields" :key="field">
                    <label v-if="visible(level)" class="block text-sm">
                        <span class="field-label">{{ labels[level] }}</span>
                        <select :data-error-field="`${prefix}${field}`" :value="selected(level)" class="field" :disabled="loading" @change="select(level, $event.target.value)">
                            <option value="">{{ optional && level === 0 ? 'بدون عنوان' : 'اختر' }}</option>
                            <option v-if="modelValue[field] && !options[level].some(node => Number(node.id) === Number(modelValue[field]))" :value="modelValue[field]">{{ modelValue[snapshots[level]] || 'اختيار محفوظ' }} (سابق)</option>
                            <option v-for="node in options[level]" :key="node.id" :value="node.id">{{ node.name_ar }}</option>
                            <option value="other">غير مدرج / أخرى</option>
                        </select>
                    </label>
                </template>
            </div>
            <label class="flex items-center gap-2 text-sm"><input :data-error-field="`${prefix}address_other`" type="checkbox" :checked="!!modelValue.address_other" @change="emit('update:modelValue', { address_other: $event.target.checked })">العنوان غير مدرج بالكامل</label>
        </template>
        <label class="block text-sm"><span class="field-label">{{ modelValue.address_other ? 'وصف العنوان غير المدرج (مطلوب)' : 'تفاصيل إضافية' }}</span><input :data-error-field="`${prefix}address_details`" :value="modelValue.address_details ?? ''" :required="!!modelValue.address_other" maxlength="1000" class="field" @input="emit('update:modelValue', { address_details: $event.target.value })"></label>
        <p v-if="loading" role="status" class="text-xs text-slate-500">جارٍ تحميل خيارات العنوان…</p>
        <p v-if="failure" role="alert" class="text-sm text-red-700">{{ failure }} <button type="button" class="underline" @click="load">إعادة المحاولة</button></p>
        <p v-for="message in messages" :key="message" role="alert" class="field-error">{{ message }}</p>
    </div>
</template>
