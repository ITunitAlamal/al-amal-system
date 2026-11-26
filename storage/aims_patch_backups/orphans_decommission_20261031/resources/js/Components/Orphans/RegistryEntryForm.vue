<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import FormErrorSummary from '../FormErrorSummary.vue';

// «إضافة يتيم» (the guardian comes first: an existing guardian found by ID, or a new one in the same step) and
// «إضافة وصي» (a guardian may be registered alone). The server writes guardian, child and link in one transaction.
const props = defineProps({ mode: { type: String, required: true }, options: { type: Object, required: true }, can: { type: Object, required: true },
    presetGuardian: { type: Object, default: null } });
const emit = defineEmits(['saved', 'cancel']);
const blankName = () => ({ first_name: '', father_name: '', grandfather_name: '', family_name: '' });
const step = ref(props.mode === 'child' && !props.presetGuardian ? 'guardian' : props.mode === 'child' ? 'child' : 'guardian');
const lookupId = ref('');
const match = ref(props.presetGuardian ? { guardian: props.presetGuardian } : null);
const searched = ref(Boolean(props.presetGuardian));
const guardian = ref({ ...blankName(), national_id: '', phone: '' });
const child = ref({ ...blankName(), national_id: '', date_of_birth: '', sex: '', orphan_class_value_id: '', kinship: '', authority_kind: '', evidence_note: '' });
const busy = ref(false);
const errors = ref({});
const message = ref('');
const nameParts = [['first_name', 'الاسم الأول'], ['father_name', 'اسم الأب'], ['grandfather_name', 'اسم الجد'], ['family_name', 'اسم العائلة']];
const toWestern = t => String(t).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();
watch(lookupId, () => { searched.value = false; match.value = null; });

function fail(e) {
    errors.value = e.response?.data?.errors || { form: [e.response?.data?.message || 'تعذر الحفظ'] };
}
async function lookup() {
    busy.value = true; errors.value = {};
    try {
        match.value = (await axios.get('/orphans/registry/lookup', { params: { national_id: toWestern(lookupId.value) } })).data.match;
        searched.value = true;
        if (!match.value) guardian.value.national_id = toWestern(lookupId.value);
    } catch (e) { fail(e); } finally { busy.value = false; }
}
const guardianReady = computed(() => match.value || (guardian.value.first_name && guardian.value.father_name && guardian.value.family_name && guardian.value.national_id));
async function saveGuardian() {
    busy.value = true; errors.value = {};
    try { const { data } = await axios.post('/orphans/registry/guardians', guardian.value); emit('saved', { kind: 'guardian', id: data.id, message: `سُجّل الوصي ${data.reference_no}. لا يملك حساب بوابة بعد.` }); }
    catch (e) { fail(e); } finally { busy.value = false; }
}
async function saveChild(approve) {
    if (approve && !confirm('اعتماد رابط الوصاية لهذا اليتيم الآن؟ يُسجَّل اسمك كمعتمد، ويُمنح الوصي الاطلاع على ملف هذا اليتيم وحده في البوابة إن كان له حساب.')) return;
    busy.value = true; errors.value = {};
    const body = { ...child.value, approve, ...(match.value ? { guardian_id: match.value.guardian.id } : { guardian: guardian.value }) };
    for (const k of ['orphan_class_value_id', 'sex', 'kinship', 'authority_kind', 'national_id']) if (body[k] === '') delete body[k];
    if (body.national_id) body.national_id = toWestern(body.national_id);
    try { const { data } = await axios.post('/orphans/registry/children', body); emit('saved', { kind: 'child', id: data.id, message: approve ? 'سُجّل اليتيم واعتُمد رابط الوصاية.' : 'سُجّل اليتيم ورابط الوصاية «بانتظار التحقق».' }); }
    catch (e) { fail(e); } finally { busy.value = false; }
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="entry-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="entry-title" class="card-title">{{ mode === 'child' ? 'إضافة يتيم' : 'إضافة وصي' }}</h2>
            <button type="button" class="btn btn-secondary btn-sm" @click="emit('cancel')">إلغاء</button>
        </div>
        <ol v-if="mode === 'child'" class="flex flex-wrap gap-2 text-xs" aria-label="الخطوات">
            <li :class="step === 'guardian' ? 'font-bold text-navy-800' : 'text-slate-500'">١. الوصي الشرعي</li><li class="text-slate-300">←</li>
            <li :class="step === 'child' ? 'font-bold text-navy-800' : 'text-slate-500'">٢. بيانات اليتيم ودليل الوصاية</li>
        </ol>
        <FormErrorSummary :errors="errors" />

        <!-- Step 1: the guardian (existing by exact ID, or new) -->
        <template v-if="step === 'guardian'">
            <p v-if="mode === 'child'" class="text-sm text-slate-600">لا يُسجَّل يتيم بلا وصي. ابحث برقم هوية الوصي أولًا؛ إن لم يكن مسجلًا أدخل بياناته هنا ويُحفظ مع اليتيم في خطوة واحدة.</p>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="lookup">
                <label class="min-w-0 flex-1"><span class="field-label">رقم هوية الوصي</span><input v-model="lookupId" required maxlength="20" dir="ltr" inputmode="numeric" autocomplete="off" class="field" /></label>
                <button class="btn btn-secondary btn-sm" :disabled="busy || !lookupId.trim()">بحث</button>
            </form>
            <div v-if="searched && match" class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm">
                <p><strong>{{ match.guardian.name }}</strong> — {{ match.guardian.reference_no || 'بلا رقم' }} · <span dir="ltr">{{ match.guardian.national_id }}</span></p>
                <p class="text-xs text-slate-600">وصي مسجل: {{ match.guardian.approved }} رابط معتمد، {{ match.guardian.pending }} بانتظار التحقق. لا يُنشأ سجل مكرر.</p>
            </div>
            <form v-else-if="searched" class="space-y-3" @submit.prevent="mode === 'child' ? (step = 'child') : saveGuardian()">
                <p class="text-sm text-slate-600">لا يوجد وصي بهذا الرقم. أدخل بيانات الوصي:</p>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <label v-for="[key, label] in nameParts" :key="key"><span class="field-label">{{ label }}<span v-if="key !== 'grandfather_name'" class="text-red-600"> *</span></span>
                        <input v-model="guardian[key]" :required="key !== 'grandfather_name'" maxlength="60" class="field" autocomplete="off" /></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label><span class="field-label">رقم هوية الوصي</span><input v-model="guardian.national_id" required maxlength="20" dir="ltr" class="field" /></label>
                    <label><span class="field-label">رقم الجوال (اختياري)</span><input v-model="guardian.phone" maxlength="16" dir="ltr" inputmode="tel" class="field" /></label>
                </div>
                <p class="text-xs text-slate-500">التسجيل لا يثبت الوصاية الشرعية ولا ينشئ حساب بوابة.</p>
                <button class="btn btn-primary btn-sm" :disabled="busy || !guardianReady">{{ mode === 'child' ? 'التالي: بيانات اليتيم' : 'حفظ الوصي' }}</button>
            </form>
            <button v-if="mode === 'child' && match" type="button" class="btn btn-primary btn-sm" @click="step = 'child'">التالي: بيانات اليتيم</button>
        </template>

        <!-- Step 2: the child and the evidence of this child's guardianship -->
        <form v-else class="space-y-3" @submit.prevent="saveChild(false)">
            <p class="rounded-lg bg-slate-50 p-2 text-sm">الوصي: <strong>{{ match ? match.guardian.name : [guardian.first_name, guardian.father_name, guardian.grandfather_name, guardian.family_name].filter(Boolean).join(' ') }}</strong>
                <span v-if="!match" class="text-xs text-slate-500"> (وصي جديد يُحفظ مع اليتيم)</span>
                <button v-if="!presetGuardian" type="button" class="mr-2 text-xs font-semibold text-blue-700 hover:underline" @click="step = 'guardian'">تغيير</button></p>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <label v-for="[key, label] in nameParts" :key="key"><span class="field-label">{{ label }}<span v-if="key !== 'grandfather_name'" class="text-red-600"> *</span></span>
                    <input v-model="child[key]" :required="key !== 'grandfather_name'" maxlength="60" class="field" autocomplete="off" /></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label><span class="field-label">رقم هوية اليتيم</span><input v-model="child.national_id" maxlength="20" dir="ltr" inputmode="numeric" class="field" /></label>
                <label><span class="field-label">تاريخ الميلاد <span class="text-red-600">*</span></span><input v-model="child.date_of_birth" type="date" required class="field" /></label>
                <label><span class="field-label">الجنس</span><select v-model="child.sex" class="field"><option value="">غير محدد</option><option value="male">ذكر</option><option value="female">أنثى</option></select></label>
                <label><span class="field-label">نوع اليتم</span><select v-model="child.orphan_class_value_id" class="field"><option value="">غير مصنف</option><option v-for="o in options.orphan_classes" :key="o.id" :value="o.id">{{ o.name_ar }}</option></select></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="field-label">صلة الوصي باليتيم</span><select v-model="child.kinship" class="field"><option value="">غير محددة</option><option v-for="o in options.kinships" :key="o.id" :value="o.code || o.name_ar">{{ o.name_ar }}</option></select></label>
                <label><span class="field-label">صفة التمثيل القانوني</span><select v-model="child.authority_kind" class="field"><option value="">غير محددة</option><option v-for="o in options.authority_kinds" :key="o.id" :value="o.code || o.name_ar">{{ o.name_ar }}</option></select></label>
            </div>
            <label class="block"><span class="field-label">دليل الوصاية لهذا اليتيم أو مرجع الوثيقة <span class="text-red-600">*</span></span>
                <textarea v-model="child.evidence_note" required minlength="10" maxlength="2000" rows="2" class="field" placeholder="مثال: حجة وصاية رقم … صادرة عن …" /></label>
            <p class="text-xs text-slate-500">يُسجَّل رابط الوصاية لهذا اليتيم وحده «بانتظار التحقق». التسجيل لا يمنح استحقاقًا ولا دخول البوابة.</p>
            <div class="flex flex-wrap gap-2">
                <button class="btn btn-primary btn-sm" :disabled="busy">حفظ وإرسال للمراجعة</button>
                <button v-if="can.approve" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="saveChild(true)">حفظ واعتماد الوصاية</button>
            </div>
        </form>
    </section>
</template>
