<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

/**
 * Personnel documents: drag-and-drop or multi-file upload with a category and details per file, per-file status,
 * error and retry; versions, verification and archiving. Downloads go through the authorised, audited route.
 */
const props = defineProps({
    employeeId: { type: [Number, String], required: true },
    documents: { type: Object, default: () => ({ items: [], missing: [], scanNotice: '' }) },
    types: { type: Array, default: () => [] },
    qualifications: { type: Array, default: () => [] },
    canUpload: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
    canVerify: { type: Boolean, default: false },
    maxKb: { type: Number, default: 10240 },
});

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.docx';
const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx'];
const classes = { normal: ['عادية', 'muted'], confidential: ['سرية', 'warning'], identity: ['هوية — مقيدة', 'danger'] };
const verification = { pending: ['بانتظار التوثيق', 'warning'], verified: ['موثّقة', 'success'], rejected: ['مرفوضة', 'danger'] };
const today = new Date().toISOString().slice(0, 10);

const items = computed(() => props.documents?.items ?? []);
const current = computed(() => items.value.filter(d => d.is_current && !d.archived_at));
const older = computed(() => items.value.filter(d => !d.is_current || d.archived_at));
const showOlder = ref(false);
const queue = ref([]);
const dragging = ref(false);
const busy = ref(false);
let sequence = 0;

const typeOf = id => props.types.find(t => Number(t.id) === Number(id));
const size = bytes => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} م.ب` : `${Math.max(1, Math.round(bytes / 1024))} ك.ب`);
const date = value => (value ? String(value).slice(0, 10) : '—');

function add(files, supersedes = null) {
    for (const file of Array.from(files || [])) {
        const extension = String(file.name.split('.').pop() || '').toLowerCase();
        queue.value.push({
            key: ++sequence, file, name: file.name, size: file.size, supersedes: supersedes?.id ?? null,
            type: supersedes?.document_type_value_id ?? '', title: supersedes?.title ?? file.name.replace(/\.[^.]+$/, '').slice(0, 255),
            description: '', issuer: supersedes?.issuer ?? '', issued: '', expires: '', qualification: '',
            status: !EXTENSIONS.includes(extension) ? 'failed' : (file.size > props.maxKb * 1024 ? 'failed' : 'waiting'),
            error: !EXTENSIONS.includes(extension) ? 'نوع الملف غير مقبول (PDF أو صورة أو DOCX).' : (file.size > props.maxKb * 1024 ? `الحجم أكبر من ${props.maxKb} ك.ب.` : ''),
            blocked: !EXTENSIONS.includes(extension) || file.size > props.maxKb * 1024,
        });
    }
}
function drop(event) { dragging.value = false; add(event.dataTransfer?.files); }
function discard(item) { queue.value = queue.value.filter(entry => entry.key !== item.key); }

function upload(item) {
    if (!item.type || !item.title.trim()) {
        item.status = 'failed';
        item.error = 'اختر نوع الوثيقة واكتب عنوانها.';
        return Promise.resolve(false);
    }
    item.status = 'uploading';
    item.error = '';
    return new Promise(resolve => {
        let settled = false;
        router.post(`/employee-files/${props.employeeId}/documents`, {
            file: item.file, document_type_value_id: item.type, title: item.title.trim(), description: item.description || null,
            qualification_id: item.qualification || null, issuer: item.issuer || null, issued_on: item.issued || null, expires_on: item.expires || null,
            supersedes_id: item.supersedes,
        }, {
            forceFormData: true, preserveScroll: true, preserveState: true,
            onSuccess: () => { settled = true; item.status = 'done'; resolve(true); },
            onError: errors => { settled = true; item.status = 'failed'; item.error = Object.values(errors).join(' ') || 'تعذر الرفع.'; resolve(false); },
            onFinish: () => { if (!settled) { item.status = 'failed'; item.error = 'انقطع الاتصال أو رُفض الطلب؛ أعد المحاولة.'; resolve(false); } },
        });
    });
}
async function uploadAll() {
    busy.value = true;
    for (const item of queue.value.filter(entry => !entry.blocked && ['waiting', 'failed'].includes(entry.status))) {
        await upload(item);
    }
    busy.value = false;
    queue.value = queue.value.filter(entry => entry.status !== 'done');
}

function verify(document, status) {
    let note = null;
    if (status === 'rejected') {
        note = window.prompt('سبب رفض الوثيقة:');
        if (!note || !note.trim()) return;
    }
    router.post(`/employee-files/${props.employeeId}/documents/${document.id}/verify`, { status, note }, { preserveScroll: true });
}
function archive(document) {
    const reason = window.prompt('سبب أرشفة الوثيقة (تبقى محفوظة في السجل):');
    if (reason && reason.trim().length >= 5) router.post(`/employee-files/${props.employeeId}/documents/${document.id}/archive`, { reason }, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <p v-if="documents?.missing?.length" class="rounded-lg border border-gold-200 bg-gold-50 p-3 text-sm text-gold-900">وثائق مطلوبة غير مرفوعة: {{ documents.missing.join('، ') }}</p>

        <div v-if="current.length" class="overflow-x-auto">
            <table class="data-table min-w-[820px]">
                <thead><tr><th>الوثيقة</th><th>النوع والسرية</th><th>الإصدار</th><th>التوثيق</th><th>الإصدار/الانتهاء</th><th>رفعها</th><th>إجراءات</th></tr></thead>
                <tbody>
                    <tr v-for="d in current" :key="d.id">
                        <td>
                            <a :href="d.download" class="font-semibold text-blue-700 hover:underline">{{ d.title }}</a>
                            <p class="text-xs text-slate-500">{{ d.original_name }} · {{ size(d.size_bytes) }}<span v-if="d.qualification"> · مؤهل: {{ d.qualification }}</span></p>
                            <p v-if="d.description" class="text-xs text-slate-500">{{ d.description }}</p>
                        </td>
                        <td>{{ d.type }}<br><StatusBadge :label="(classes[d.classification] || [d.classification])[0]" :tone="(classes[d.classification] || [null, 'muted'])[1]" /></td>
                        <td>{{ d.version_no }}</td>
                        <td>
                            <StatusBadge :label="(verification[d.verification_status] || [d.verification_status])[0]" :tone="(verification[d.verification_status] || [null, 'muted'])[1]" />
                            <p v-if="d.verification_note" class="text-xs text-slate-500">{{ d.verification_note }}</p>
                        </td>
                        <td class="text-xs" dir="ltr">{{ date(d.issued_on) }} → <span :class="d.expires_on && d.expires_on < today ? 'font-bold text-red-700' : ''">{{ date(d.expires_on) }}</span></td>
                        <td class="text-xs">{{ d.uploaded_by }}<br><span dir="ltr">{{ date(d.created_at) }}</span></td>
                        <td>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <template v-if="canVerify && d.verification_status === 'pending'">
                                    <button type="button" class="font-semibold text-emerald-700 hover:underline" @click="verify(d, 'verified')">توثيق</button>
                                    <button type="button" class="font-semibold text-red-700 hover:underline" @click="verify(d, 'rejected')">رفض</button>
                                </template>
                                <label v-if="canManage" class="cursor-pointer font-semibold text-blue-700 hover:underline">إصدار جديد<input type="file" :accept="ACCEPT" class="sr-only" @change="add($event.target.files, d); $event.target.value = ''" /></label>
                                <button v-if="canManage" type="button" class="font-semibold text-slate-600 hover:underline" @click="archive(d)">أرشفة</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="text-sm text-slate-500">لا توجد وثائق مرفوعة{{ canUpload ? ' بعد.' : ' يمكنك الاطلاع عليها.' }}</p>

        <div v-if="older.length">
            <button type="button" class="text-sm font-semibold text-blue-700 hover:underline" @click="showOlder = !showOlder">{{ showOlder ? 'إخفاء' : 'عرض' }} الإصدارات السابقة والمؤرشفة ({{ older.length }})</button>
            <ul v-if="showOlder" class="mt-2 space-y-1 text-xs text-slate-600">
                <li v-for="d in older" :key="d.id"><a :href="d.download" class="text-blue-700 hover:underline">{{ d.title }}</a> — {{ d.type }} · الإصدار {{ d.version_no }}{{ d.archived_at ? ` · مؤرشفة: ${d.archive_reason || ''}` : ' · استُبدلت' }}</li>
            </ul>
        </div>

        <div v-if="canUpload" class="space-y-3 border-t border-slate-200 pt-4">
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-6 text-center text-sm transition"
                :class="dragging ? 'border-navy-400 bg-navy-50' : 'border-slate-300 hover:bg-slate-50'"
                @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop">
                <span class="font-semibold text-navy-800">اسحب الوثائق هنا أو اخترها (عدة ملفات معًا)</span>
                <span class="mt-1 text-xs text-slate-500">PDF أو JPG أو PNG أو WEBP أو DOCX، حتى {{ maxKb }} ك.ب للملف. حدّد نوع كل ملف قبل الرفع.</span>
                <input type="file" multiple :accept="ACCEPT" class="sr-only" @change="add($event.target.files); $event.target.value = ''" />
            </label>
            <p class="text-xs text-slate-500">{{ documents?.scanNotice }}</p>

            <article v-for="item in queue" :key="item.key" class="rounded-xl border p-3" :class="item.status === 'failed' ? 'border-red-200 bg-red-50' : 'border-slate-200'">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="font-semibold text-navy-800">{{ item.name }} <span class="text-xs font-normal text-slate-500">({{ size(item.size) }}{{ item.supersedes ? ' — إصدار جديد' : '' }})</span></span>
                    <span class="text-xs" :class="{ 'text-slate-500': item.status === 'waiting', 'text-blue-700': item.status === 'uploading', 'text-emerald-700': item.status === 'done', 'text-red-700': item.status === 'failed' }">
                        {{ { waiting: 'بانتظار الرفع', uploading: 'جارٍ الرفع...', done: 'رُفع', failed: 'تعذر الرفع' }[item.status] }}
                    </span>
                </div>
                <div v-if="!item.blocked && item.status !== 'done'" class="grid gap-2 md:grid-cols-3">
                    <select v-model="item.type" class="field" :disabled="Boolean(item.supersedes)" aria-label="نوع الوثيقة">
                        <option value="">نوع الوثيقة *</option>
                        <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <input v-model="item.title" class="field" maxlength="255" placeholder="عنوان الوثيقة *" aria-label="عنوان الوثيقة" />
                    <input v-model="item.issuer" class="field" maxlength="255" placeholder="جهة الإصدار" aria-label="جهة الإصدار" />
                    <label class="text-xs text-slate-600">تاريخ الإصدار<input v-model="item.issued" type="date" :max="today" class="field mt-1" /></label>
                    <label class="text-xs text-slate-600">تاريخ الانتهاء (إن وجد)<input v-model="item.expires" type="date" :min="item.issued || undefined" class="field mt-1" /></label>
                    <select v-if="typeOf(item.type)?.qualification && qualifications.length" v-model="item.qualification" class="field self-end" aria-label="المؤهل المرتبط">
                        <option value="">ربط بمؤهل (اختياري)</option>
                        <option v-for="q in qualifications" :key="q.id" :value="q.id">{{ q.qualification_name }}{{ q.specialization ? ' — ' + q.specialization : '' }}</option>
                    </select>
                    <textarea v-model="item.description" rows="2" maxlength="2000" class="field md:col-span-3" placeholder="وصف أو ملاحظة (اختياري)" aria-label="وصف الوثيقة"></textarea>
                </div>
                <p v-if="item.error" class="field-error">{{ item.error }}</p>
                <div class="mt-2 flex gap-3 text-xs">
                    <button v-if="item.status === 'failed' && !item.blocked" type="button" class="font-semibold text-blue-700 hover:underline" :disabled="busy" @click="upload(item)">إعادة المحاولة</button>
                    <button v-if="item.status !== 'uploading'" type="button" class="font-semibold text-slate-600 hover:underline" @click="discard(item)">إزالة من القائمة</button>
                </div>
            </article>
            <button v-if="queue.some(i => !i.blocked && ['waiting', 'failed'].includes(i.status))" type="button" class="btn btn-primary" :disabled="busy" @click="uploadAll">
                {{ busy ? 'جارٍ الرفع...' : 'رفع الملفات' }}
            </button>
        </div>
    </div>
</template>
