<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import GuardianPicker from './GuardianPicker.vue';
import { linkTones } from '../../Support/orphans.js';

// Direct child↔guardian relationships, each with its own status, evidence, dates and history.
const props = defineProps({ links: Array, orphanId: { type: String, default: null }, options: Object, abilities: Object, fromGuardian: Boolean });
const action = ref(null);
const picked = ref(null);
const blank = { decision: 'approve', note: '', starts_on: '', ends_on: '', authority_kind_value_id: null, legal_basis: '', reason: '', guardian_public_id: null,
    link_status: 'claimed', kinship_value_id: null, evidence_note: '', is_primary: false };
const form = useForm({ ...blank });
function open(kind, link = null) { action.value = { kind, link }; picked.value = null; form.defaults({ ...blank, starts_on: link?.starts_on || '', authority_kind_value_id: link?.authority_kind_value_id ?? null, legal_basis: link?.legal_basis || '' }); form.reset(); }
function submit() {
    const { kind, link } = action.value;
    const done = { preserveScroll: true, onSuccess: () => { action.value = null; } };
    form.guardian_public_id = picked.value?.public_id ?? null;
    if (kind === 'decide') form.post(`/orphans/registry/guardianships/${link.id}/decide`, done);
    if (kind === 'replace') form.post(`/orphans/registry/guardianships/${link.id}/replace`, done);
    if (kind === 'end') form.post(`/orphans/registry/guardianships/${link.id}/end`, done);
    if (kind === 'add') form.post(`/orphans/registry/orphans/${props.orphanId}/guardianships`, done);
}
const titles = { decide: 'البت في الوصاية لهذا الطفل', replace: 'استبدال الوصي', end: 'إنهاء الارتباط', add: 'إضافة وصي آخر' };
</script>

<template>
    <section class="space-y-3">
        <ul class="space-y-2">
            <li v-for="l in links" :key="l.id" :class="['rounded-xl border p-3 text-sm', l.effective ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200']">
                <div class="flex flex-wrap items-center gap-2">
                    <Link v-if="fromGuardian" :href="`/orphans/registry/orphans/${l.orphan_public_id}`" class="font-semibold text-blue-700 hover:underline">{{ l.orphan_name }}</Link>
                    <Link v-else :href="`/orphans/registry/guardians/${l.guardian_public_id}`" class="font-semibold text-blue-700 hover:underline">{{ l.guardian_name }}</Link>
                    <StatusBadge :label="l.status_label" :tone="linkTones[l.status]" /><StatusBadge v-if="l.is_primary" label="رئيسي" tone="info" />
                    <StatusBadge v-if="l.expired" label="انتهت مدة السريان — الوصول للبوابة موقوف" tone="danger" />
                    <span class="text-slate-500">{{ [l.kinship, l.authority_kind].filter(Boolean).join(' · ') }}</span>
                </div>
                <p class="mt-1 text-slate-600">السريان: {{ l.starts_on || '—' }} ← {{ l.ends_on || 'مفتوح' }}<span v-if="l.legal_basis"> · الأساس: {{ l.legal_basis }}</span><span v-if="l.evidence_note"> · الإثبات: {{ l.evidence_note }}</span></p>
                <p v-if="l.decided_at" class="text-xs text-slate-500">القرار: {{ l.decided_by_name }} · {{ l.decided_at?.slice(0, 16) }}<span v-if="l.decision_note"> · {{ l.decision_note }}</span></p>
                <p v-if="l.ended_reason" class="text-xs text-slate-500">سبب الإنهاء: {{ l.ended_reason }}</p>
                <div v-if="!fromGuardian && ['claimed', 'pending', 'approved'].includes(l.status) && abilities.edit" class="mt-2 flex flex-wrap gap-1">
                    <button v-if="abilities.decide_portal && l.status !== 'approved'" type="button" class="btn btn-sm btn-primary" @click="open('decide', l)">البت في الوصاية</button>
                    <button type="button" class="btn btn-sm btn-secondary" @click="open('replace', l)">استبدال الوصي</button>
                    <button type="button" class="btn btn-sm btn-secondary" @click="open('end', l)">إنهاء</button>
                </div>
            </li>
            <li v-if="!links.length" class="text-sm text-slate-500">{{ fromGuardian ? 'لا يوجد أيتام مرتبطون بهذا الوصي.' : 'لا يوجد ارتباط.' }}</li>
        </ul>
        <button v-if="!fromGuardian && abilities.edit && !action" type="button" class="btn btn-sm btn-secondary" @click="open('add')">إضافة وصي آخر</button>
        <form v-if="action" class="space-y-3 rounded-xl border border-blue-200 bg-blue-50/40 p-4" @submit.prevent="submit">
            <h4 class="font-semibold text-navy-800">{{ titles[action.kind] }}</h4>
            <template v-if="action.kind === 'decide'">
                <div class="flex gap-3 text-sm"><label class="flex items-center gap-1"><input v-model="form.decision" type="radio" value="approve"> اعتماد</label><label class="flex items-center gap-1"><input v-model="form.decision" type="radio" value="reject"> رفض</label></div>
                <div v-if="form.decision === 'approve'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">بداية السريان<input v-model="form.starts_on" type="date" class="field mt-1" required></label>
                    <label class="text-sm">نهاية السريان (اختياري)<input v-model="form.ends_on" type="date" class="field mt-1"></label>
                    <label class="text-sm">نوع السند<select v-model="form.authority_kind_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="a in options.authority" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                    <label class="text-sm">الأساس القانوني<input v-model="form.legal_basis" class="field mt-1" maxlength="300"></label>
                </div>
                <label class="block text-sm">{{ form.decision === 'reject' ? 'سبب الرفض (إلزامي)' : 'ملاحظة القرار' }}<textarea v-model="form.note" class="field mt-1" rows="2" maxlength="2000" /></label>
            </template>
            <template v-if="['replace', 'add'].includes(action.kind)">
                <GuardianPicker v-model="picked" label="الوصي الجديد" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">حالة الارتباط<select v-model="form.link_status" class="field mt-1"><option value="claimed">مُدّعاة</option><option value="pending">بانتظار التحقق</option><option v-if="abilities.decide_portal" value="approved">معتمدة</option></select></label>
                    <label class="text-sm">صلة القرابة<select v-model="form.kinship_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select></label>
                    <label class="text-sm">بداية السريان<input v-model="form.starts_on" type="date" class="field mt-1"></label>
                </div>
                <label class="block text-sm">الإثبات<textarea v-model="form.evidence_note" class="field mt-1" rows="2" maxlength="2000" /></label>
                <label v-if="action.kind === 'add'" class="flex items-center gap-2 text-sm"><input v-model="form.is_primary" type="checkbox"> وصي رئيسي</label>
            </template>
            <label v-if="['replace', 'end'].includes(action.kind)" class="block text-sm">السبب (إلزامي)<input v-model="form.reason" class="field mt-1" maxlength="300" required></label>
            <p v-if="action.kind === 'replace'" class="text-xs text-slate-600">يُنشأ الارتباط الجديد ويُنهى القديم في عملية واحدة، ويبقى القديم وسنده في السجل؛ الاستفادات السابقة لا تنتقل.</p>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing || (['replace', 'add'].includes(action.kind) && !picked)">تأكيد</button><button type="button" class="btn btn-secondary" @click="action = null">إلغاء</button></div>
        </form>
    </section>
</template>
