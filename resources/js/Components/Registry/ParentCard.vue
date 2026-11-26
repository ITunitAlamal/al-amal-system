<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import PersonFields from './PersonFields.vue';
import BasicPanel from './BasicPanel.vue';
import EconomicPanel from './EconomicPanel.vue';
import { emptyPerson, verificationLabels } from '../../Support/orphans.js';

// Father/mother mini-card; the detail panel edits the shared person record (siblings see the same data).
const props = defineProps({ relation: String, parent: { type: Object, default: null }, orphanId: String, options: Object, abilities: Object });
const open = ref(false);
const linking = ref(false);
const existing = ref(null);
const form = useForm({ ...emptyPerson(), person_public_id: null, status: props.relation === 'father' ? 'deceased' : '', event_date: '', evidence_note: '', basis: 'declared' });
const title = props.relation === 'father' ? 'الأب' : 'الأم';
function use(m) { existing.value = m; form.person_public_id = m.public_id; }
function submit() { form.post(`/orphans/registry/orphans/${props.orphanId}/parents/${props.relation}`, { preserveScroll: true, onSuccess: () => { linking.value = false; } }); }
</script>

<template>
    <article class="rounded-xl border border-slate-200 p-4 text-sm">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="font-semibold text-navy-800">{{ relation === 'father' ? 'بيانات الأب' : 'بيانات الأم' }}</h4>
            <div class="flex gap-1">
                <button v-if="parent" type="button" class="btn btn-sm btn-secondary" :aria-expanded="open" @click="open = !open">{{ open ? 'إخفاء التفاصيل' : 'التفاصيل' }}</button>
                <button v-if="abilities.edit" type="button" class="btn btn-sm btn-secondary" @click="linking = !linking">{{ parent ? 'ربط شخص آخر' : `ربط ${title}` }}</button>
            </div>
        </header>
        <div v-if="parent" class="mt-2 space-y-1">
            <p class="font-semibold">{{ parent.full_name }} <span class="font-mono text-xs text-slate-500" dir="ltr">{{ parent.id_masked }}</span></p>
            <div class="flex flex-wrap gap-1"><StatusBadge :label="parent.vital_label" :tone="parent.vital_status === 'deceased' ? 'muted' : 'info'" />
                <StatusBadge :label="`الارتباط: ${verificationLabels[parent.link_verification]}`" :tone="parent.link_verification === 'verified' ? 'success' : 'warning'" />
                <StatusBadge v-if="parent.is_guardian" label="وصي على هذا الطفل" tone="success" /><StatusBadge v-else-if="relation === 'mother'" label="ليست وصية مسجلة على هذا الطفل" tone="muted" />
                <span v-if="parent.siblings_count" class="text-xs text-slate-500">مرتبط بـ{{ parent.siblings_count }} يتيم آخر</span></div>
        </div>
        <p v-else class="mt-2 text-slate-500">غير مربوط بعد.</p>
        <div v-if="open && parent" class="mt-3 space-y-4 border-t border-slate-100 pt-3">
            <BasicPanel :person="parent" :options="options" :abilities="abilities" :events="parent.events" />
            <EconomicPanel :person-id="parent.public_id" :items="parent.economic" :options="options" :can-edit="abilities.edit" :allow-pre-death="parent.vital_status === 'deceased'"
                :title="relation === 'father' ? 'الوضع الاقتصادي (قبل الوفاة سياق تاريخي، لا دليل على حاجة اليتيم الحالية)' : 'الوضع الاقتصادي'" />
        </div>
        <form v-if="linking" class="mt-3 space-y-3 border-t border-slate-100 pt-3" @submit.prevent="submit">
            <div v-if="existing" class="alert-info">سيُربط الشخص المسجل «{{ existing.full_name }}» دون إنشاء سجل جديد.</div>
            <PersonFields v-else v-model="form" :errors="form.errors" :options="options" :who="title" :show-contact="relation === 'mother'" @use-existing="use" />
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">الحالة<select v-model="form.status" class="field mt-1"><option value="">دون تغيير</option><option value="alive">على قيد الحياة</option><option value="deceased">متوفى</option><option value="missing">مفقود</option><option value="unknown">غير معروفة</option></select></label>
                <label v-if="['deceased', 'missing'].includes(form.status)" class="text-sm">التاريخ كما أُفيد<input v-model="form.event_date" type="date" class="field mt-1"></label>
                <label class="text-sm">أساس الارتباط<select v-model="form.basis" class="field mt-1"><option value="declared">إفادة</option><option value="document">مستند</option></select></label>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="linking = false">إلغاء</button></div>
        </form>
    </article>
</template>
