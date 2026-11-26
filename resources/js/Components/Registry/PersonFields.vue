<script setup>
import { onUnmounted, ref } from 'vue';
import { jsonRequest } from '../../Support/orphans.js';
import { createLatestRequest } from '../../Support/latestRequest.js';
import Req from '../Registration/Req.vue';

// Structured person input: four-part name as entered, identity as a string, minimal required facts.
// Possible duplicates are looked up when the name or ID is filled; nothing is merged automatically.
const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    prefix: { type: String, default: '' },
    options: { type: Object, default: () => ({}) },
    who: { type: String, default: '' },
    showContact: { type: Boolean, default: true },
    showDob: { type: Boolean, default: true },
    except: { type: String, default: '' },
    // The duplicate lookup is a staff endpoint; the guardian portal turns it off.
    lookup: { type: Boolean, default: true },
    // Reasons for an absent identity number (registration form); shown only while the number is empty.
    idReasons: { type: Object, default: null },
});
const emit = defineEmits(['use-existing']);
const matches = ref([]);
const checking = ref(false);
const lookupError = ref('');
const lookup = createLatestRequest((url, signal) => jsonRequest(url, null, 'GET', { signal }), state => {
    matches.value = state.data?.matches || [];
    checking.value = state.loading;
    lookupError.value = state.error;
});
const field = name => `${props.prefix}${name}`;
const error = name => props.errors[field(name)];
let timer;
function check() {
    if (!props.lookup) return;
    clearTimeout(timer);
    lookup.cancel();
    matches.value = [];
    lookupError.value = '';
    timer = setTimeout(async () => {
        const p = props.modelValue;
        if (!p.id_number && !(p.first_name && p.family_name)) { checking.value = false; return; }
            const query = new URLSearchParams(Object.entries({ id_number: p.id_number, first_name: p.first_name, father_name: p.father_name, grandfather_name: p.grandfather_name,
                family_name: p.family_name, date_of_birth: p.date_of_birth, except: props.except }).filter(([, v]) => v));
        await lookup.run(`/orphans/registry/duplicates?${query}`);
    }, 450);
}
onUnmounted(() => { clearTimeout(timer); lookup.cancel(); });
</script>

<template>
    <div class="space-y-4">
        <fieldset>
            <legend class="field-label">الاسم كما في الوثيقة {{ who ? `(${who})` : '' }}</legend>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-sm">الاسم الأول<Req :k="field('first_name')" /><input v-model="modelValue.first_name" :data-error-field="field('first_name')" :aria-invalid="!!error('first_name')" class="field mt-1" maxlength="100" @change="check"><span v-if="error('first_name')" class="field-error">{{ error('first_name') }}</span></label>
                <label class="text-sm">اسم الأب<input v-model="modelValue.father_name" :data-error-field="field('father_name')" :aria-invalid="!!error('father_name')" class="field mt-1" maxlength="100" @change="check"></label>
                <label class="text-sm">اسم الجد<input v-model="modelValue.grandfather_name" :data-error-field="field('grandfather_name')" :aria-invalid="!!error('grandfather_name')" class="field mt-1" maxlength="100" @change="check"></label>
                <label class="text-sm">اسم العائلة<input v-model="modelValue.family_name" :data-error-field="field('family_name')" :aria-invalid="!!error('family_name')" class="field mt-1" maxlength="100" @change="check"><span v-if="error('family_name')" class="field-error">{{ error('family_name') }}</span></label>
            </div>
            <details class="mt-2 text-sm">
                <summary class="cursor-pointer text-blue-700">أجزاء إضافية أو اسم كامل كما ورد في المصدر</summary>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <label>أسماء إضافية<input v-model="modelValue.extra_names" :data-error-field="field('extra_names')" :aria-invalid="!!error('extra_names')" class="field mt-1" maxlength="200"></label>
                    <label>الاسم الكامل كما ورد (إن لم يُقسَّم)<input v-model="modelValue.full_name" :data-error-field="field('full_name')" :aria-invalid="!!error('full_name')" class="field mt-1" maxlength="400"><span v-if="error('full_name')" class="field-error">{{ error('full_name') }}</span></label>
                    <label>الاسم بالإنجليزية (اختياري)<input v-model="modelValue.name_en" :data-error-field="field('name_en')" :aria-invalid="!!error('name_en')" class="field mt-1" dir="ltr" maxlength="300"></label>
                </div>
            </details>
            <p v-if="error('full_name') && !modelValue.full_name" class="field-error">{{ error('full_name') }}</p>
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-sm">نوع الوثيقة
                <select v-model="modelValue.id_type_value_id" :data-error-field="field('id_type_value_id')" :aria-invalid="!!error('id_type_value_id')" class="field mt-1"><option :value="null">الهوية الفلسطينية (افتراضي)</option><option v-for="t in options.id_types || []" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            </label>
            <label class="text-sm">رقم الهوية / الوثيقة<input v-model="modelValue.id_number" :data-error-field="field('id_number')" :aria-invalid="!!error('id_number')" class="field mt-1 font-mono" dir="ltr" inputmode="numeric" maxlength="40" autocomplete="off" @change="check">
                <span v-if="error('id_number')" class="field-error">{{ error('id_number') }}</span>
                <span v-else class="mt-1 block text-xs text-slate-500">يُحفظ كما هو بالأصفار البادئة؛ صحة الصيغة لا تعني التحقق من السجل المدني.</span>
            </label>
            <label v-if="idReasons && !modelValue.id_number" class="text-sm">سبب عدم توفر الرقم<Req :k="field('id_missing_reason')" />
                <select v-model="modelValue.id_missing_reason" :data-error-field="field('id_missing_reason')" :aria-invalid="!!error('id_missing_reason')" class="field mt-1"><option value="">اختر السبب</option><option v-for="(label, key) in idReasons" :key="key" :value="key">{{ label }}</option></select>
                <span v-if="error('id_missing_reason')" class="field-error">{{ error('id_missing_reason') }}</span>
            </label>
            <label class="text-sm">الجنس
                <select v-model="modelValue.sex" :data-error-field="field('sex')" :aria-invalid="!!error('sex')" class="field mt-1"><option value="">غير محدد</option><option value="male">ذكر</option><option value="female">أنثى</option></select>
            </label>
            <label v-if="showDob" class="text-sm">تاريخ الميلاد<input v-model="modelValue.date_of_birth" :data-error-field="field('date_of_birth')" :aria-invalid="!!error('date_of_birth')" type="date" class="field mt-1" @change="check"><span v-if="error('date_of_birth')" class="field-error">{{ error('date_of_birth') }}</span></label>
        </div>
        <div v-if="showContact" class="grid gap-3 sm:grid-cols-3">
            <label class="text-sm">رقم الجوال<input v-model="modelValue.phone" :data-error-field="field('phone')" :aria-invalid="!!error('phone')" class="field mt-1" dir="ltr" inputmode="tel" maxlength="16"><span v-if="error('phone')" class="field-error">{{ error('phone') }}</span></label>
            <label class="text-sm">جوال إضافي<input v-model="modelValue.alt_phone" :data-error-field="field('alt_phone')" :aria-invalid="!!error('alt_phone')" class="field mt-1" dir="ltr" inputmode="tel" maxlength="16"></label>
            <label class="text-sm">الجنسية
                <select v-model="modelValue.nationality_value_id" :data-error-field="field('nationality_value_id')" :aria-invalid="!!error('nationality_value_id')" class="field mt-1"><option :value="null">غير محددة</option><option v-for="n in options.nationalities || []" :key="n.id" :value="n.id">{{ n.name }}</option></select>
            </label>
        </div>
        <div v-if="checking" class="text-xs text-slate-500" role="status">جارٍ البحث عن سجلات مشابهة…</div>
        <p v-if="lookupError" class="alert-warning" role="status">تعذر فحص السجلات المشابهة. <button type="button" class="underline" @click="check">إعادة الفحص</button></p>
        <div v-if="matches.length" class="alert-warning space-y-2" role="alert">
            <p class="font-semibold">سجلات قد تكون للشخص نفسه — راجعها قبل الحفظ (لا يتم أي دمج تلقائي):</p>
            <ul class="space-y-1 text-sm">
                <li v-for="m in matches" :key="m.public_id" class="flex flex-wrap items-center gap-2">
                    <strong>{{ m.full_name }}</strong><span class="font-mono" dir="ltr">{{ m.id_masked }}</span><span v-if="m.dob">· {{ m.dob }}</span>
                    <span class="text-slate-600">· {{ m.reason }}</span><span v-if="m.roles.length" class="text-slate-600">· {{ m.roles.join('، ') }}</span>
                    <button type="button" class="btn btn-sm btn-secondary" @click="emit('use-existing', m)">استخدام هذا السجل</button>
                </li>
            </ul>
        </div>
    </div>
</template>
