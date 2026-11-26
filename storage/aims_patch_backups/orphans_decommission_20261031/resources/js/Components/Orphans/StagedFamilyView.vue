<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import StatusBadge from '../StatusBadge.vue';

// «الوصي ← الأسر ← الأيتام» or «الأسرة ← الأب والأم ← الأطفال ← الأوصياء». Every link shown here is a claim from the
// source sheet with its own review state; none is an approved guardianship. Two households are never merged because
// they share a guardian.
const props = defineProps({ tree: { type: Object, default: null }, household: { type: Object, default: null }, reviewStatuses: { type: Object, required: true },
    can: { type: Object, default: () => ({}) }, decisions: { type: Object, default: () => ({}) } });
const emit = defineEmits(['open-row', 'open-tree', 'open-household', 'close', 'changed']);

// Siblings: the shared fact «عضوية الأسرة» is reviewed once and recorded for each child the reviewer ticks (none is
// ticked by default), each as its own audited decision. Identity and guardianship stay per child in «ملف اليتيم».
const members = ref([]);
const memberForm = ref({ decision: '', note: '' });
const saving = ref(false);
const memberError = ref('');
const memberMessage = ref('');
watch(() => props.household, () => { members.value = []; memberForm.value = { decision: '', note: '' }; memberError.value = ''; });
async function decideMembers() {
    if (!members.value.length || !confirm(`تسجيل قرار «عضوية الأسرة» لـ ${members.value.length} طفل بالدليل نفسه؟ لكل طفل قرار مستقل في سجل التدقيق، ولا يُعتمد أي ملف أو وصاية.`)) return;
    saving.value = true; memberError.value = ''; memberMessage.value = '';
    let done = 0;
    try {
        for (const id of members.value) {
            await axios.post(`/orphans/staging/rows/${id}/decision`, { kind: 'household', decision: memberForm.value.decision, note: memberForm.value.note });
            done++;
        }
        memberMessage.value = `سُجّل القرار لـ ${done} طفل.`;
    } catch (e) {
        memberError.value = `سُجّل لـ ${done} طفل ثم توقف: ${e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(' — ') || 'تعذر الحفظ'}`;
    } finally { saving.value = false; if (done) emit('changed'); }
}
const claimLabel = { pending: 'علاقة أولية — غير متحقق منها', supported: 'مدعومة بدليل — بانتظار التحقق الرسمي', rejected: 'غير صحيحة', needs_evidence: 'تحتاج دليلًا' };
const claimTone = { pending: 'warning', supported: 'info', rejected: 'danger', needs_evidence: 'warning' };
const reviewTone = { pending_review: 'warning', in_review: 'info', needs_followup: 'danger', reviewed: 'success' };
const sexLabel = s => ({ male: 'ذكر', female: 'أنثى' }[s] || 'الجنس غير محدد');
// Guardian → orphans directly; the household is shown as an attribute of each child, never as the path to them.
const orphans = computed(() => (props.tree?.households || []).flatMap(h => h.children.map(c => ({ ...c, household: h }))));
</script>

<template>
    <section v-if="tree" class="space-y-3" aria-labelledby="family-tree-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="family-tree-title" class="card-title">الوصي وأيتامه</h2>
            <button type="button" class="btn btn-secondary btn-sm" @click="emit('close')">العودة إلى النتائج</button>
        </div>
        <p class="text-sm"><strong>{{ tree.guardian.names.join(' / ') || '—' }}</strong> <span dir="ltr" class="text-slate-500">{{ tree.guardian.national_id }}</span>
            <span class="mr-2"><StatusBadge label="وصي مدّعى في الكشف — ليست وصاية قانونية" tone="warning" /></span></p>
        <p class="text-xs text-slate-500">الصلات المذكورة في الكشف: {{ Object.entries(tree.guardian.relations).map(([k, n]) => `${k} (${n})`).join('، ') || '—' }}</p>
        <h3 class="text-sm font-bold text-navy-800">الأيتام المرتبطون بهذا الوصي ({{ orphans.length }})</h3>
        <p class="text-xs text-slate-500">لكل يتيم علاقة مستقلة بوصيه وحالة تحقق خاصة بها؛ الانتماء إلى أسرة لا يعني وصاية ولا يمنح دخول البوابة.</p>
        <ul class="divide-y divide-slate-100">
            <li v-for="c in orphans" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span class="min-w-0">{{ c.name || '—' }} <span dir="ltr" class="text-xs text-slate-400">{{ c.national_id }}</span> · {{ sexLabel(c.sex) }} · {{ c.dob || 'الميلاد غير محدد' }}
                    <span class="block text-xs text-slate-500">الصلة: {{ c.relation || 'غير محددة' }} · الأسرة: الأب {{ c.household.father_name || '—' }} / الأم {{ c.household.mother_name || '—' }}</span></span>
                <span class="flex flex-wrap items-center gap-2"><StatusBadge :label="claimLabel[c.claim]" :tone="claimTone[c.claim]" /><button type="button" class="btn btn-secondary btn-sm" @click="emit('open-row', c.id)">عرض الملف</button></span>
            </li>
        </ul>
        <details class="rounded-xl border border-slate-200 p-3 text-sm">
            <summary class="cursor-pointer font-semibold text-navy-800">الأسر المرتبطة — للبيانات الاجتماعية والسكنية ({{ tree.households.length }})</summary>
            <ul class="mt-2 divide-y divide-slate-100">
                <li v-for="(h, i) in tree.households" :key="i" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>الأب {{ h.father_name || '—' }} / الأم {{ h.mother_name || '—' }} — {{ h.children.length }} طفل
                        <span v-if="h.other_guardians" class="mr-2 text-xs text-red-700">أوصياء آخرون مدّعون: {{ h.other_guardians }}</span>
                        <span v-if="!h.ref" class="mr-2 text-xs text-red-700">هوية أب/أم غير صالحة للتجميع</span></span>
                    <button v-if="h.ref" type="button" class="btn btn-secondary btn-sm" @click="emit('open-household', h.ref)">عرض الأسرة</button>
                </li>
            </ul>
        </details>
    </section>

    <section v-else-if="household" class="space-y-3" aria-labelledby="family-household-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="family-household-title" class="card-title">الأسرة ← الأب والأم ← الأطفال ← الأوصياء المدّعى بهم</h2>
            <button type="button" class="btn btn-secondary btn-sm" @click="emit('close')">العودة إلى النتائج</button>
        </div>
        <div class="grid gap-3 text-sm sm:grid-cols-2">
            <div class="rounded-xl bg-slate-50 p-3"><span class="text-slate-500">الأب:</span> {{ household.father.name || '—' }} <span dir="ltr" class="text-xs">{{ household.father.national_id }}</span><div class="text-xs text-slate-500">تاريخ الوفاة كما في الكشف: {{ household.father.death_date || 'غير محدد' }}</div></div>
            <div class="rounded-xl bg-slate-50 p-3"><span class="text-slate-500">الأم:</span> {{ household.mother.name || '—' }} <span dir="ltr" class="text-xs">{{ household.mother.national_id }}</span><div class="text-xs text-slate-500">{{ household.mother.status || 'الحالة غير محددة' }}</div></div>
        </div>
        <h3 class="text-sm font-bold text-navy-800">الأطفال ({{ household.children.length }})</h3>
        <ul class="divide-y divide-slate-100 text-sm">
            <li v-for="c in household.children" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <span class="min-w-0">{{ c.name || '—' }} <span dir="ltr" class="text-xs text-slate-400">{{ c.national_id }}</span> · {{ c.dob || 'الميلاد غير محدد' }} · الوصي المدّعى: {{ c.guardian_name || '—' }}</span>
                <span class="flex flex-wrap items-center gap-2"><StatusBadge :label="reviewStatuses[c.review_status]" :tone="reviewTone[c.review_status]" /><button type="button" class="btn btn-secondary btn-sm" @click="emit('open-row', c.id)">عرض الملف</button></span>
            </li>
        </ul>
        <form v-if="can.verify && household.children.length > 1" class="space-y-2 rounded-xl border border-slate-200 p-3 text-sm" @submit.prevent="decideMembers">
            <h3 class="font-bold text-navy-800">مراجعة الأسرة مرة واحدة: عضوية الأسرة</h3>
            <p class="text-xs text-slate-500">اختر الأطفال الذين يثبت الدليل انتماءهم لهذه الأسرة. يُسجَّل قرار مستقل لكل طفل تختاره؛ البيانات الشخصية وادعاء الوصي يُراجعان لكل طفل في ملفه.</p>
            <fieldset class="flex flex-wrap gap-3"><legend class="sr-only">الأطفال</legend>
                <label v-for="c in household.children" :key="c.id" class="flex items-center gap-1"><input v-model="members" type="checkbox" :value="c.id" />{{ c.name || `صف ${c.row}` }}</label>
            </fieldset>
            <div class="grid gap-2 sm:grid-cols-2">
                <label><span class="field-label">القرار</span><select v-model="memberForm.decision" required class="field"><option value="">اختر</option><option v-for="(text, code) in decisions" :key="code" :value="code">{{ text }}</option></select></label>
                <label><span class="field-label">الدليل أو مرجع الوثيقة <span class="text-red-600">*</span></span><input v-model="memberForm.note" required minlength="10" maxlength="2000" class="field" /></label>
            </div>
            <div v-if="memberError" class="alert-error" role="alert">{{ memberError }}</div>
            <div v-if="memberMessage" class="alert-success" role="status">{{ memberMessage }}</div>
            <button class="btn btn-primary btn-sm" :disabled="saving || !members.length || !memberForm.decision || memberForm.note.trim().length < 10">تسجيل القرار لـ {{ members.length }} طفل</button>
        </form>
        <h3 class="text-sm font-bold text-navy-800">الأوصياء المدّعى بهم ({{ household.guardians.length }})</h3>
        <p v-if="household.guardians.length > 1" class="text-xs text-red-700">أكثر من وصي مدّعى لهذه الأسرة — كل ادعاء يُراجع وحده.</p>
        <ul class="space-y-1 text-sm">
            <li v-for="(g, i) in household.guardians" :key="i">{{ g.names.join(' / ') || '—' }} <span dir="ltr" class="text-xs text-slate-400">{{ g.national_id }}</span> — {{ g.relations.join('، ') || 'الصلة غير محددة' }} — {{ g.children }} طفل
                <button v-if="g.ref" type="button" class="mr-2 text-xs font-semibold text-blue-700 hover:underline" @click="emit('open-tree', g.ref)">أسر هذا الوصي</button></li>
        </ul>
    </section>
</template>
