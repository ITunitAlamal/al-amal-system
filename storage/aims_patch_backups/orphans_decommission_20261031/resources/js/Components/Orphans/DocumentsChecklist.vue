<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

// Required and optional documents per owner, with upload (new version), review and a short-lived signed view link.
const props = defineProps({
    items: { type: Array, default: () => [] },
    canUpload: Boolean,
    canVerify: Boolean,
});
const tone = { missing: 'danger', pending: 'info', accepted: 'success', rejected: 'warning', expired: 'danger' };
const labels = { missing: 'غير مرفوع', pending: 'بانتظار المراجعة', accepted: 'مقبول', rejected: 'مرفوض', expired: 'منتهي الصلاحية' };
const target = ref(null);
const upload = useForm({ owner_type: '', owner_id: null, document_type_value_id: null, file: null, document_no: '', issued_on: '', expires_on: '' });
function start(item) {
    target.value = `${item.owner_type}:${item.owner_id}:${item.type_id}`;
    upload.reset();
    upload.clearErrors();
    Object.assign(upload, { owner_type: item.owner_type, owner_id: item.owner_id, document_type_value_id: item.type_id });
}
function send() {
    upload.post('/orphans/documents', { forceFormData: true, preserveScroll: true, onSuccess: () => { target.value = null; } });
}
function review(item, decision) {
    const note = decision === 'rejected' ? window.prompt('سبب الرفض (يصل لمقدم المستند):') : '';
    if (decision === 'rejected' && (!note || note.trim().length < 5)) return;
    router.post(`/orphans/documents/${item.document.id}/review`, { decision, note }, { preserveScroll: true });
}
async function open(item) {
    const response = await fetch(`/orphans/documents/${item.document.id}/link`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) { window.alert('لا تملك صلاحية عرض هذا المستند.'); return; }
    window.open((await response.json()).url, '_blank', 'noopener');
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-right text-xs text-slate-500"><tr><th class="py-2">المستند</th><th>يخص</th><th>الإلزام</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
                <template v-for="item in items" :key="`${item.owner_type}:${item.owner_id}:${item.type_id}`">
                    <tr class="border-t border-slate-100 align-top">
                        <td class="py-2"><span class="font-semibold">{{ item.type }}</span><span v-if="item.note" class="block text-xs text-slate-500">{{ item.note }}</span></td>
                        <td>{{ item.owner }}</td>
                        <td>{{ item.requirement === 'required' ? 'مطلوب' : 'اختياري' }}</td>
                        <td>
                            <StatusBadge :label="labels[item.status]" :tone="tone[item.status]" />
                            <span v-if="item.document?.rejection_reason" class="block text-xs text-red-700">{{ item.document.rejection_reason }}</span>
                            <span v-if="item.document" class="block text-xs text-slate-500">نسخة {{ item.document.version }}</span>
                            <span v-if="item.document?.expires_on" class="block text-xs" :class="item.status === 'expired' ? 'font-semibold text-red-700' : item.document.expiring ? 'font-semibold text-amber-700' : 'text-slate-500'">
                                {{ item.status === 'expired' ? 'انتهت في' : item.document.expiring ? 'تنتهي قريبًا في' : 'سارية حتى' }} <span dir="ltr">{{ item.document.expires_on }}</span></span>
                        </td>
                        <td class="space-x-1 space-x-reverse whitespace-nowrap">
                            <button v-if="item.document" type="button" class="btn btn-secondary btn-sm" @click="open(item)">عرض</button>
                            <button v-if="canUpload" type="button" class="btn btn-secondary btn-sm" @click="start(item)">{{ item.document ? 'رفع نسخة جديدة' : 'رفع' }}</button>
                            <template v-if="canVerify && item.status === 'pending'">
                                <button type="button" class="btn btn-primary btn-sm" @click="review(item, 'accepted')">قبول</button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="review(item, 'rejected')">رفض</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="target === `${item.owner_type}:${item.owner_id}:${item.type_id}`">
                        <td colspan="5" class="pb-3">
                            <form class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-4" @submit.prevent="send">
                                <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" class="field sm:col-span-2" required @input="upload.file = $event.target.files[0]">
                                <input v-model="upload.document_no" class="field" maxlength="60" placeholder="رقم المستند (اختياري)">
                                <label class="block text-xs"><span class="field-label">تاريخ الإصدار</span><input v-model="upload.issued_on" type="date" class="field"></label>
                                <label class="block text-xs"><span class="field-label">تاريخ الانتهاء (إن وُجد)</span><input v-model="upload.expires_on" type="date" class="field"></label>
                                <p v-for="(message, key) in upload.errors" :key="key" class="field-error sm:col-span-4">{{ message }}</p>
                                <div class="sm:col-span-4"><button class="btn btn-primary btn-sm" :disabled="upload.processing">رفع (PDF أو صورة حتى 10 ميجابايت، يُشفّر عند الحفظ)</button>
                                    <button type="button" class="btn btn-secondary btn-sm" @click="target = null">إلغاء</button></div>
                            </form>
                        </td>
                    </tr>
                </template>
                <tr v-if="!items.length"><td colspan="5" class="py-3 text-slate-500">لا توجد متطلبات مستندات بعد؛ أضف الوالدين والأطفال والوصي أولًا.</td></tr>
            </tbody>
        </table>
    </div>
</template>
