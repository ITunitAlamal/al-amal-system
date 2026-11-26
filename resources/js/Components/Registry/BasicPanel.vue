<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import PersonFields from './PersonFields.vue';
import { idFormatTones, jsonRequest, verificationLabels } from '../../Support/orphans.js';

// Basic data of a person (view, edit, identity reveal/verification, dated life status).
const props = defineProps({ person: Object, options: Object, abilities: Object, events: { type: Array, default: () => [] }, showStatus: { type: Boolean, default: true } });
const editing = ref(false);
const pick = p => Object.fromEntries(['first_name', 'father_name', 'grandfather_name', 'family_name', 'extra_names', 'full_name', 'name_en', 'id_type_value_id', 'sex', 'date_of_birth',
    'dob_status', 'nationality_value_id', 'place_of_birth', 'phone', 'alt_phone', 'email', 'notes'].map(k => [k, p[k] ?? (k.endsWith('_id') ? null : '')]));
const form = useForm({ ...pick(props.person), id_number: '' });
const revealed = ref('');
const statusForm = useForm({ status: 'deceased', event_date: '', place: '', cause: '', evidence_note: '', verification: 'reported' });
const verifyForm = useForm({ source: '' });
const failure = ref('');
function save() {
    form.transform(data => { const out = { ...data }; if (!out.id_number) delete out.id_number; return out; })
        .put(`/orphans/registry/persons/${props.person.public_id}`, { preserveScroll: true, onSuccess: () => { editing.value = false; } });
}
async function reveal() {
    failure.value = '';
    try { revealed.value = (await jsonRequest(`/orphans/registry/persons/${props.person.public_id}/reveal-id`, {})).id_number || 'غير متوفر'; } catch (e) { failure.value = e.message; }
}
const rows = p => [['الاسم الأول', p.first_name], ['اسم الأب', p.father_name], ['اسم الجد', p.grandfather_name], ['اسم العائلة', p.family_name], ['الاسم الكامل', p.full_name],
    ['الجنس', p.sex_label], ['تاريخ الميلاد', p.date_of_birth ? `${p.date_of_birth} (${{ declared: 'مُفاد', verified: 'متحقق', estimated: 'تقديري' }[p.dob_status]})` : null],
    ['العمر', p.age], ['الجنسية', p.nationality], ['مكان الميلاد', p.place_of_birth], ['الجوال', p.phone], ['جوال إضافي', p.alt_phone], ['الحالة', p.vital_label]];
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-slate-600">رقم الهوية:</span><span class="font-mono" dir="ltr">{{ revealed || person.id_masked }}</span>
            <StatusBadge :label="person.id_format_label" :tone="idFormatTones[person.id_format]" />
            <StatusBadge v-if="person.id_verified" :label="`تحقق يدوي: ${person.id_verification_source}`" tone="success" />
            <button v-if="abilities.identity && !revealed && person.id_format !== 'missing'" type="button" class="btn btn-sm btn-secondary" @click="reveal">إظهار الرقم (يُسجَّل)</button>
            <span v-if="failure" class="field-error">{{ failure }}</span>
        </div>
        <dl v-if="!editing" class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="[label, value] in rows(person)" :key="label"><dt class="text-xs text-slate-500">{{ label }}</dt><dd class="text-navy-900">{{ value ?? 'غير متوفر' }}</dd></div>
        </dl>
        <form v-else class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="save">
            <PersonFields v-model="form" :errors="form.errors" :options="options" :except="person.public_id" />
            <p class="text-xs text-slate-500">اترك رقم الهوية فارغًا للإبقاء على الرقم الحالي؛ تغيير الرقم يلغي أي تحقق سابق.</p>
            <label class="block text-sm">ملاحظات<textarea v-model="form.notes" class="field mt-1" rows="2" maxlength="3000" /></label>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="editing = false">إلغاء</button></div>
        </form>
        <div v-if="abilities.edit" class="flex flex-wrap gap-2">
            <button v-if="!editing" type="button" class="btn btn-sm btn-secondary" @click="editing = true">تعديل البيانات الأساسية</button>
            <button v-if="showStatus" type="button" class="btn btn-sm btn-secondary" @click="statusForm.reset(); $refs.status.showModal()">تسجيل حالة (وفاة / فقد / حياة)</button>
        </div>
        <form v-if="abilities.portal && ['valid_format', 'other_type'].includes(person.id_format) && !person.id_verified" class="flex flex-wrap items-end gap-2" @submit.prevent="verifyForm.post(`/orphans/registry/persons/${person.public_id}/verify-id`, { preserveScroll: true })">
            <label class="text-sm">توثيق الهوية يدويًا — المصدر<input v-model="verifyForm.source" class="field mt-1" maxlength="200" placeholder="مثال: صورة الهوية الأصلية بتاريخ…"></label>
            <button type="submit" class="btn btn-sm btn-secondary" :disabled="!verifyForm.source || verifyForm.processing">تسجيل التحقق</button>
            <span v-if="verifyForm.errors.source" class="field-error">{{ verifyForm.errors.source }}</span>
        </form>
        <dialog ref="status" class="w-full max-w-lg rounded-2xl p-0 backdrop:bg-navy-900/30">
            <form class="space-y-3 p-5" @submit.prevent="statusForm.post(`/orphans/registry/persons/${person.public_id}/status`, { preserveScroll: true, onSuccess: () => $refs.status.close() })">
                <h3 class="card-title">تسجيل حالة بتاريخها</h3>
                <label class="block text-sm">الحالة<select v-model="statusForm.status" class="field mt-1"><option value="alive">على قيد الحياة</option><option value="deceased">متوفى</option><option value="missing">مفقود</option><option value="unknown">غير معروفة</option></select></label>
                <label class="block text-sm">تاريخ الحدث<input v-model="statusForm.event_date" type="date" class="field mt-1"></label>
                <label class="block text-sm">المكان (اختياري)<input v-model="statusForm.place" class="field mt-1" maxlength="200"></label>
                <label v-if="abilities.health" class="block text-sm">السبب (مقيد؛ فقط عند الحاجة)<input v-model="statusForm.cause" class="field mt-1" maxlength="500"></label>
                <label class="block text-sm">الإثبات<textarea v-model="statusForm.evidence_note" class="field mt-1" rows="2" maxlength="1000" placeholder="رقم شهادة الوفاة أو الجهة المصدرة…" /></label>
                <label class="block text-sm">درجة التحقق<select v-model="statusForm.verification" class="field mt-1"><option value="reported">مُفاد (غير متحقق)</option><option v-if="abilities.portal" value="verified">متحقق منه بمستند</option></select></label>
                <p v-for="(e, k) in statusForm.errors" :key="k" class="field-error">{{ e }}</p>
                <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="statusForm.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="$refs.status.close()">إلغاء</button></div>
            </form>
        </dialog>
        <div v-if="events.length" class="space-y-1 text-sm">
            <h4 class="font-semibold text-navy-800">سجل الحالة</h4>
            <p v-for="e in events" :key="e.id" class="text-slate-700">{{ e.status_label }} · {{ e.event_date || 'تاريخ غير محدد' }} · {{ verificationLabels[e.verification] }}<span v-if="e.evidence_note"> · {{ e.evidence_note }}</span><span v-if="e.cause"> · السبب: {{ e.cause }}</span><span v-else-if="e.has_cause" class="text-slate-400"> · السبب مقيد</span></p>
        </div>
    </section>
</template>
