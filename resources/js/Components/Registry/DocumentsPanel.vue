<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

// Documents with type, validity and review state; restricted ones need «health».
const props = defineProps({ personId: String, items: Array, options: Object, canEdit: Boolean, canReview: Boolean, canRestrict: Boolean, history: { type: Array, default: () => [] } });
const adding = ref(false);
const form = useForm({ file: null, document_type_value_id: null, title: '', issued_on: '', expires_on: '', is_restricted: false });
function submit() { form.post(`/orphans/registry/persons/${props.personId}/documents`, { forceFormData: true, preserveScroll: true, onSuccess: () => { adding.value = false; form.reset(); } }); }
const tones = { pending: 'info', accepted: 'success', rejected: 'danger' };
const reviewLabels = { pending: 'بانتظار المراجعة', accepted: 'مقبول', rejected: 'مرفوض' };
</script>

<template>
    <section class="space-y-4">
        <ul class="space-y-2">
            <li v-for="d in items" :key="d.public_id" class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm">
                <a :href="`/orphans/registry/documents/${d.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ d.title || d.type || d.original_name }}</a>
                <StatusBadge :label="reviewLabels[d.review_status]" :tone="tones[d.review_status]" /><StatusBadge v-if="d.expired" label="منتهي الصلاحية" tone="danger" /><StatusBadge v-if="d.is_restricted" label="مقيد" tone="warning" />
                <span class="text-slate-500">{{ d.type }} · {{ d.expires_on ? `ينتهي ${d.expires_on}` : 'بلا تاريخ انتهاء' }}</span>
                <span v-if="canReview && d.review_status === 'pending'" class="ms-auto flex gap-1">
                    <button type="button" class="btn btn-sm btn-secondary" @click="router.post(`/orphans/registry/documents/${d.public_id}/review`, { review_status: 'accepted' }, { preserveScroll: true })">قبول</button>
                    <button type="button" class="btn btn-sm btn-danger" @click="router.post(`/orphans/registry/documents/${d.public_id}/review`, { review_status: 'rejected' }, { preserveScroll: true })">رفض</button>
                </span>
            </li>
            <li v-if="!items.length" class="text-sm text-slate-500">لا توجد مستندات.</li>
        </ul>
        <button v-if="canEdit && !adding" type="button" class="btn btn-sm btn-secondary" @click="adding = true">رفع مستند</button>
        <form v-if="adding" class="space-y-3 rounded-xl border border-slate-200 p-4" @submit.prevent="submit">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="field" @input="form.file = $event.target.files[0]">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">نوع المستند<select v-model="form.document_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="t in options.documents" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                <label class="text-sm">العنوان<input v-model="form.title" class="field mt-1" maxlength="200"></label>
                <label class="text-sm">تاريخ الإصدار<input v-model="form.issued_on" type="date" class="field mt-1"></label>
                <label class="text-sm">تاريخ الانتهاء<input v-model="form.expires_on" type="date" class="field mt-1"></label>
            </div>
            <label v-if="canRestrict" class="flex items-center gap-2 text-sm"><input v-model="form.is_restricted" type="checkbox"> مستند مقيد (صحي/سري)</label>
            <p v-for="(e, k) in form.errors" :key="k" class="field-error">{{ e }}</p>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary" :disabled="form.processing || !form.file">رفع</button><button type="button" class="btn btn-secondary" @click="adding = false">إلغاء</button></div>
        </form>
        <div v-if="history.length" class="text-sm">
            <h4 class="font-semibold text-navy-800">سجل التغييرات</h4>
            <ul class="mt-1 space-y-1 text-slate-700"><li v-for="(h, i) in history" :key="i">{{ h.created_at?.slice(0, 16) }} · {{ h.actor || 'النظام' }} · {{ h.action }}</li></ul>
        </div>
    </section>
</template>
