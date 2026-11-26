<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

/**
 * Profile photo: the approved photo (or initials), upload by HR (immediate) or by the employee (waits for HR
 * review), review and removal. Images are served only through the authorised route.
 */
const props = defineProps({
    employeeId: { type: [Number, String], required: true },
    name: { type: String, default: '' },
    photo: { type: Object, default: null },
    canUpload: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    canRemove: { type: Boolean, default: false },
    maxKb: { type: Number, default: 2048 },
    selfService: { type: Boolean, default: false },
});

const initials = computed(() => String(props.name || '').trim().split(/\s+/).slice(0, 2).map(word => word.charAt(0)).join(' '));
const form = useForm({ photo: null });
const dragging = ref(false);
const reviewNote = ref('');
const reviewError = ref('');
const localError = ref('');

function pick(file) {
    localError.value = '';
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { localError.value = 'الصورة يجب أن تكون JPG أو PNG أو WEBP.'; return; }
    if (file.size > props.maxKb * 1024) { localError.value = `حجم الصورة أكبر من ${props.maxKb} ك.ب.`; return; }
    form.photo = file;
    form.post(`/employee-files/${props.employeeId}/photo`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
}
function drop(event) { dragging.value = false; pick(event.dataTransfer?.files?.[0]); }
function review(approve) {
    reviewError.value = '';
    if (!approve && !reviewNote.value.trim()) { reviewError.value = 'اكتب سبب الرفض.'; return; }
    router.post(`/employee-files/${props.employeeId}/photos/${props.photo.pending.id}/review`, { approve, note: reviewNote.value || null }, { preserveScroll: true, onSuccess: () => { reviewNote.value = ''; } });
}
function remove() {
    if (window.confirm('إزالة الصورة الحالية من الملف؟ تبقى محفوظة في السجل.')) router.delete(`/employee-files/${props.employeeId}/photo`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center gap-4">
            <img v-if="photo?.current" :src="photo.current.url" :alt="`صورة ${name}`" class="h-24 w-24 rounded-2xl border border-slate-200 object-cover" loading="lazy" />
            <div v-else class="flex h-24 w-24 items-center justify-center rounded-2xl bg-navy-50 text-2xl font-bold text-navy-700" aria-label="لا توجد صورة">{{ initials || '؟' }}</div>
            <div class="space-y-1 text-xs text-slate-500">
                <p v-if="!photo?.current">لا توجد صورة معتمدة.</p>
                <p v-if="photo?.pending && selfService" class="font-semibold text-gold-800">صورتك الجديدة بانتظار مراجعة الموارد البشرية.</p>
                <button v-if="canRemove && photo?.current" type="button" class="font-semibold text-red-700 hover:underline" @click="remove">إزالة الصورة</button>
            </div>
        </div>

        <div v-if="photo?.pending && canReview" class="rounded-xl border border-gold-200 bg-gold-50 p-3 text-sm">
            <p class="mb-2 font-semibold text-gold-900">صورة جديدة رفعها الموظف بانتظار المراجعة</p>
            <img :src="photo.pending.url" alt="الصورة المقترحة" class="mb-2 h-24 w-24 rounded-xl object-cover" loading="lazy" />
            <input v-model="reviewNote" class="field" maxlength="1000" placeholder="ملاحظة (إلزامية عند الرفض)" aria-label="ملاحظة المراجعة" />
            <p v-if="reviewError" class="field-error">{{ reviewError }}</p>
            <div class="mt-2 flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" @click="review(true)">اعتماد الصورة</button>
                <button type="button" class="btn btn-sm btn-secondary" @click="review(false)">رفض</button>
            </div>
        </div>

        <label v-if="canUpload" class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-5 text-center text-sm transition"
            :class="dragging ? 'border-navy-400 bg-navy-50' : 'border-slate-300 hover:bg-slate-50'"
            @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop">
            <span class="font-semibold text-navy-800">{{ form.processing ? 'جارٍ الرفع...' : (photo?.current ? 'استبدال الصورة' : 'رفع صورة شخصية') }}</span>
            <span class="mt-1 text-xs text-slate-500">اسحب الصورة هنا أو اخترها — JPG أو PNG أو WEBP حتى {{ maxKb }} ك.ب{{ selfService ? '، وتظهر بعد مراجعة الموارد البشرية' : '' }}.</span>
            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="form.processing" @change="pick($event.target.files[0]); $event.target.value = ''" />
        </label>
        <p v-if="localError || form.errors.photo" class="field-error">{{ localError || form.errors.photo }}</p>
    </div>
</template>
