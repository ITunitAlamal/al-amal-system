<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import StatusStepper from '../../Components/StatusStepper.vue';
import WorkflowActions from '../../Components/WorkflowActions.vue';

// Versioned scoring rules (general social score, or a campaign's priority). Values are proposals until the executive
// director approves the version; an approved version is frozen and a change becomes a new version.
const props = defineProps({
    rulesets: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    factors: { type: Array, default: () => [] },
    missing: { type: Object, default: () => ({}) },
    kind: { type: String, default: 'social' },
    campaign: { type: Object, default: null },
    canDraft: Boolean,
    progress: { type: Object, default: null },
    workflow: { type: Object, default: null },
});
const catalog = computed(() => Object.fromEntries(props.factors.map(f => [f.code, f])));
const editing = ref(!props.selected && false);
const form = useForm({
    kind: props.kind, campaign_id: props.campaign?.id ?? null, ruleset_id: props.selected?.editable ? props.selected.id : null,
    name: props.selected?.name || '', notes: props.selected?.notes || '', tie_breakers: props.selected?.tie_breakers || [],
    factors: JSON.parse(JSON.stringify(props.selected?.factors || [])),
});
const editable = computed(() => props.canDraft && (!props.selected || props.selected.editable || editing.value));
function addFactor(code) {
    if (!code || form.factors.some(f => f.code === code)) return;
    const type = catalog.value[code].type;
    form.factors.push({ code, weight: 1, cap: null, missing: 'zero', bands: type === 'range' ? [{ min: null, max: null, points: 0 }] : type === 'boolean' ? [{ value: true, points: 0 }] : [] });
}
function addBand(factor) { factor.bands.push(catalog.value[factor.code].type === 'range' ? { min: null, max: null, points: 0 } : { value: catalog.value[factor.code].type === 'boolean' ? false : '', points: 0 }); }
function save() { form.post('/orphans/scoring', { preserveScroll: true }); }
function newVersion() { editing.value = true; form.ruleset_id = null; form.name = `${props.selected.name} (إصدار جديد)`; }
const base = computed(() => (props.campaign ? `/relief/campaigns/${props.campaign.public_id}` : '/orphans/scoring'));
</script>

<template>
    <AppLayout :title="kind === 'social' ? 'قواعد التقييم الاجتماعي' : 'قواعد أولوية الحملة'">
        <div class="space-y-4">
            <Link :href="campaign ? base : (selected ? '/orphans/scoring' : '/orphans')" class="text-sm font-semibold text-blue-700 hover:underline">→ {{ campaign ? `الحملة ${campaign.reference_no}` : (selected ? 'قواعد التقييم' : 'ملفات الأيتام') }}</Link>
            <PageHeader :eyebrow="kind === 'social' ? 'التقييم الاجتماعي العام لملف اليتيم' : `أولوية الحملة ${campaign?.title || ''}`" :title="selected ? `${selected.name} — إصدار ${selected.version}` : 'إصدارات قواعد التقييم'"
                description="التقييم يرتب المراجعة ولا يمنح أو يرفض بذاته. الأوزان والتدرجات مقترحات حتى يعتمدها المدير التنفيذي، والإصدار المعتمد لا يُعدّل ولا يغير التقييمات والكشوف السابقة.">
                <template v-if="selected" #meta><div class="mt-2"><StatusBadge :label="selected.status_label" :tone="selected.status === 'approved' ? 'success' : 'info'" /></div></template>
            </PageHeader>
            <section v-if="progress" class="card"><StatusStepper :steps="progress.steps" :summary="progress.summary" /></section>

            <section v-if="!selected" class="card space-y-2 text-sm">
                <p v-for="r in rulesets" :key="r.id"><Link :href="`/orphans/scoring/${r.id}`" class="font-semibold text-blue-700 hover:underline">إصدار {{ r.version }} — {{ r.name }}</Link> — {{ r.status_label }}<span v-if="r.approver"> — اعتمده {{ r.approver }}</span></p>
                <p v-if="!rulesets.length" class="text-slate-500">لا توجد إصدارات بعد. لم تُعتمد أي أوزان؛ أنشئ مسودة وارفعها للقرار.</p>
                <button v-if="canDraft" type="button" class="btn btn-primary btn-sm" @click="editing = true">مسودة إصدار جديد</button>
            </section>

            <form v-if="editable && (selected || editing)" class="card space-y-4" @submit.prevent="save">
                <label class="block text-sm"><span class="field-label">اسم الإصدار *</span><input v-model="form.name" class="field" maxlength="200" required></label>
                <div v-for="(factor, i) in form.factors" :key="factor.code" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex flex-wrap items-end gap-2">
                        <p class="font-semibold">{{ catalog[factor.code]?.label }}</p>
                        <label>الوزن <input v-model.number="factor.weight" type="number" step="0.01" min="0" max="100" class="field w-24"></label>
                        <label>الحد الأعلى <input v-model.number="factor.cap" type="number" step="0.01" min="0" class="field w-24"></label>
                        <label>البيانات الناقصة <select v-model="factor.missing" class="field"><option v-for="(label, key) in missing" :key="key" :value="key">{{ label }}</option></select></label>
                        <button type="button" class="btn btn-secondary btn-sm" @click="form.factors.splice(i, 1)">حذف العامل</button>
                    </div>
                    <div v-for="(band, b) in factor.bands" :key="b" class="flex flex-wrap items-center gap-2">
                        <template v-if="catalog[factor.code]?.type === 'range'">من <input v-model.number="band.min" type="number" class="field w-24"> إلى أقل من <input v-model.number="band.max" type="number" class="field w-24"></template>
                        <select v-else-if="catalog[factor.code]?.type === 'boolean'" v-model="band.value" class="field w-28"><option :value="true">نعم</option><option :value="false">لا</option></select>
                        <select v-else v-model="band.value" class="field w-56"><option v-for="v in catalog[factor.code]?.values || []" :key="v.code" :value="v.code">{{ v.name }}</option></select>
                        النقاط <input v-model.number="band.points" type="number" step="0.01" class="field w-24">
                        <button type="button" class="text-xs text-red-700 underline" @click="factor.bands.splice(b, 1)">حذف</button>
                    </div>
                    <button type="button" class="text-xs text-blue-700 underline" @click="addBand(factor)">إضافة تدرج</button>
                </div>
                <select class="field max-w-sm" @change="addFactor($event.target.value); $event.target.value = ''"><option value="">إضافة عامل…</option><option v-for="f in factors" :key="f.code" :value="f.code" :disabled="form.factors.some(x => x.code === f.code)">{{ f.label }}</option></select>
                <label class="block text-sm"><span class="field-label">ترتيب كسر التعادل (رموز العوامل، ثم رقم الملف)</span>
                    <select v-model="form.tie_breakers" multiple class="field h-24"><option v-for="f in form.factors" :key="f.code" :value="f.code">{{ catalog[f.code]?.label }}</option><option value="file_no">رقم الملف (الأقدم أولًا)</option></select></label>
                <label class="block text-sm"><span class="field-label">المبررات والمصادر</span><textarea v-model="form.notes" class="field" rows="3" maxlength="2000"></textarea></label>
                <p v-for="(m, k) in form.errors" :key="k" class="field-error">{{ m }}</p>
                <button class="btn btn-primary btn-sm" :disabled="form.processing || !form.factors.length">حفظ المسودة</button>
            </form>

            <section v-else-if="selected" class="card space-y-2 text-sm">
                <div v-for="factor in selected.factors" :key="factor.code" class="rounded-lg border border-slate-100 p-2">
                    <p class="font-semibold">{{ catalog[factor.code]?.label }} — الوزن {{ factor.weight }}<span v-if="factor.cap !== null">، الحد {{ factor.cap }}</span> — {{ missing[factor.missing] }}</p>
                    <p v-for="(band, b) in factor.bands" :key="b" class="text-xs">{{ 'min' in band ? `${band.min ?? '−∞'} إلى < ${band.max ?? '∞'}` : (band.value === true ? 'نعم' : band.value === false ? 'لا' : (catalog[factor.code]?.values.find(v => v.code === band.value)?.name || band.value)) }}: {{ band.points }} نقطة</p>
                </div>
                <p v-if="selected.factors_hash" class="text-xs text-slate-500" dir="ltr">sha256 {{ selected.factors_hash }}</p>
                <p v-if="selected.decision_note"><span class="text-slate-500">ملاحظة القرار:</span> {{ selected.decision_note }}</p>
                <button v-if="canDraft && selected.status === 'approved'" type="button" class="btn btn-secondary btn-sm" @click="newVersion">إعداد إصدار جديد منه</button>
            </section>
            <button v-if="selected?.can_submit && canDraft" type="button" class="btn btn-primary btn-sm" @click="router.post(`/orphans/scoring/${selected.id}/submit`, {}, { preserveScroll: true })">رفع للقرار</button>
            <WorkflowActions :workflow="workflow" />
        </div>
    </AppLayout>
</template>
