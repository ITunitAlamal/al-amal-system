<script setup>
import { computed, ref } from 'vue';
import AppIcon from '../AppIcon.vue';

// مرفقات اختيارية تُرسل مع الطلب أو مع الإجراء في النموذج نفسه (خطوة واحدة).
// الخادم يتحقق نهائيًا من العدد والحجم ومن نوع الملف من محتواه؛ الفحص هنا لتنبيه المستخدم مبكرًا فقط.
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    // { files, file_kb, total_kb, accept, allowed } من RequestAttachments::limits()
    policy: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    progress: { type: Object, default: null },
    disabled: { type: Boolean, default: false },
    // الإجراء المختار يحيل الطلب إلى مقدمه: يفتح مقدم الطلب ما يُرسل معه.
    sharedWithRequester: { type: Boolean, default: false },
    inputId: { type: String, default: 'request-attachments' },
});
const emit = defineEmits(['update:modelValue']);

const notice = ref('');
const megabytes = kilobytes => String(Math.round((kilobytes / 1024) * 10) / 10);
const readable = bytes => (bytes >= 1048576 ? `${Math.round((bytes / 1048576) * 10) / 10} م.ب` : `${Math.max(1, Math.ceil(bytes / 1024))} ك.ب`);
const serverErrors = computed(() => Object.entries(props.errors || {})
    .filter(([key]) => key === 'attachments' || key.startsWith('attachments.'))
    .map(([, message]) => message));

function add(files) {
    notice.value = '';
    const next = [...props.modelValue];
    for (const file of Array.from(files || [])) {
        if (next.some(existing => existing.name === file.name && existing.size === file.size)) continue;
        if (file.size > props.policy.file_kb * 1024) {
            notice.value = `الملف «${file.name}» أكبر من ${megabytes(props.policy.file_kb)} م.ب ولم يُضف.`;
            continue;
        }
        if (next.length >= props.policy.files) {
            notice.value = `يمكن إرفاق ${props.policy.files} ملفات على الأكثر؛ لم تُضف بقية الملفات.`;
            break;
        }
        next.push(file);
    }
    emit('update:modelValue', next);
}
function remove(index) {
    notice.value = '';
    emit('update:modelValue', props.modelValue.filter((_, position) => position !== index));
}
</script>

<template>
    <div>
        <label :for="inputId" class="field-label flex items-center gap-1.5"><AppIcon name="paperclip" :size="16" /> مرفقات (اختياري)</label>
        <input :id="inputId" type="file" multiple :accept="policy.accept" :disabled="disabled" class="block w-full text-sm text-slate-600"
            @change="add($event.target.files); $event.target.value = ''" />
        <p class="mt-1 text-xs text-slate-500">حتى {{ policy.files }} ملفات، {{ megabytes(policy.file_kb) }} م.ب للملف: {{ policy.allowed }}. تُحفظ مع الإجراء نفسه.</p>
        <p v-if="sharedWithRequester" class="mt-1 text-xs font-semibold text-gold-800">تنبيه: هذا الإجراء يحيل الطلب إلى مقدمه، وسيتمكن من فتح المرفقات المرسلة معه. لا ترفق هنا ما لا يخصه (مثل سندات الصرف والعقود).</p>
        <ul v-if="modelValue.length" class="mt-2 space-y-1">
            <li v-for="(file, index) in modelValue" :key="`${file.name}-${file.size}`" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm">
                <span class="min-w-0 truncate">{{ file.name }} <span class="text-xs text-slate-400">({{ readable(file.size) }})</span></span>
                <button type="button" class="shrink-0 text-xs font-semibold text-red-700 hover:underline" :disabled="disabled" @click="remove(index)">إزالة</button>
            </li>
        </ul>
        <progress v-if="progress" :value="progress.percentage" max="100" class="mt-2 w-full" aria-label="تقدم رفع المرفقات">{{ progress.percentage }}%</progress>
        <p v-if="notice" class="mt-1 text-xs text-gold-800" role="status">{{ notice }}</p>
        <p v-for="(message, index) in serverErrors" :key="index" class="field-error">{{ message }}</p>
    </div>
</template>
