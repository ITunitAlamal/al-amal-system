<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';
import { careCategories, sources, verificationLabels } from '../../Support/orphans.js';

// Support needs. Clinical details are stored encrypted and shown only to holders of «health».
const props = defineProps({ personId: String, items: Array, options: Object, canEdit: Boolean, clinical: Boolean });
const adding = ref(false);
const form = useForm({ category: 'health', need_value_id: null, summary: '', clinical_details: '', source: 'caregiver', verification: 'reported', follow_up_on: '', access_barrier: '' });
function submit() { form.post(`/orphans/registry/persons/${props.personId}/care`, { preserveScroll: true, onSuccess: () => { adding.value = false; form.reset(); } }); }
const needOptions = () => ['disability', 'assistive_device'].includes(form.category) ? props.options.disability : props.options.health;
</script>

<template>
    <section class="space-y-3">
        <p class="text-xs text-slate-500">تُسجل الاحتياجات العامة اللازمة للخدمة فقط؛ لا يُتخذ أي قرار أهلية صحي تلقائيًا.</p>
        <ul class="space-y-2">
            <li v-for="c in items" :key="c.id" class="rounded-xl border border-slate-200 p-3 text-sm">
                <div class="flex flex-wrap items-center gap-2"><StatusBadge :label="careCategories[c.category]" tone="info" /><StatusBadge v-if="!c.is_active" label="مغلق" tone="muted" />
                    <StatusBadge :label="verificationLabels[c.verification]" :tone="c.verification === 'verified' ? 'success' : 'warning'" /><span class="text-slate-500">{{ sources[c.source] }} · {{ c.recorded_by_name }}</span>
                    <button v-if="canEdit && c.is_active" type="button" class="btn btn-sm btn-secondary ms-auto" @click="router.post(`/orphans/registry/care/${c.id}/close`, {}, { preserveScroll: true })">إغلاق</button></div>
                <p class="mt-1 text-slate-700">{{ [c.need, c.summary].filter(Boolean).join(' · ') || '—' }}</p>
                <p v-if="c.follow_up_on" class="text-xs text-slate-500">موعد المتابعة: {{ c.follow_up_on }}</p>
                <p v-if="c.clinical_details" class="mt-1 rounded-lg bg-red-50 p-2 text-red-900">تفاصيل سريرية (مقيدة): {{ c.clinical_details }}</p>
                <p v-else-if="c.has_clinical" class="mt-1 text-xs text-slate-400">توجد تفاصيل سريرية مقيدة.</p>
            </li>
            <li v-if="!items.length" class="text-sm text-slate-500">لا توجد احتياجات مسجلة (غير متوفر).</li>
        </ul>
        <button v-if="canEdit && !adding" type="button" class="btn btn-sm btn-secondary" @click="adding = true">إضافة احتياج</button>
        <form v-if="adding" class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">الفئة<select v-model="form.category" class="field mt-1"><option v-for="(label, key) in careCategories" :key="key" :value="key">{{ label }}</option></select></label>
                <label class="text-sm">التصنيف<select v-model="form.need_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="o in needOptions()" :key="o.id" :value="o.id">{{ o.name }}</option></select></label>
                <label class="text-sm sm:col-span-2">ملخص الاحتياج (دون تشخيص)<input v-model="form.summary" class="field mt-1" maxlength="300"></label>
                <label v-if="clinical" class="text-sm sm:col-span-2">تفاصيل سريرية (اختياري، مقيدة ومشفرة)<textarea v-model="form.clinical_details" class="field mt-1" rows="2" maxlength="5000" /></label>
                <label class="text-sm">المصدر<select v-model="form.source" class="field mt-1"><option value="self">إفادة ذاتية</option><option value="caregiver">مقدم الرعاية</option><option value="clinician">تقرير مختص</option><option value="document">مستند</option></select></label>
                <label class="text-sm">درجة التحقق<select v-model="form.verification" class="field mt-1"><option value="reported">مُفاد</option><option value="verified">متحقق منه</option></select></label>
                <label class="text-sm">موعد المتابعة<input v-model="form.follow_up_on" type="date" class="field mt-1"></label>
                <label class="text-sm">عائق الوصول للرعاية<input v-model="form.access_barrier" class="field mt-1" maxlength="300"></label>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="adding = false">إلغاء</button></div>
        </form>
    </section>
</template>
