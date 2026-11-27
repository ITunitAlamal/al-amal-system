<script setup>
import { formatDateTime } from '../../Support/dates.js';
import AppIcon from '../AppIcon.vue';

// مرفقات إجراء واحد في سجل الطلب: الاسم والحجم ومن أرفقها ومتى، مع رابط تنزيل يتحقق الخادم من صلاحيته في كل مرة.
// files: [{ public_id, filename, file_size, created_at, mine, uploaded_by_name, url }] — ما يحق للمشاهد فتحه فقط.
defineProps({ files: { type: Array, default: () => [] } });

const readable = bytes => (bytes >= 1048576 ? `${Math.round((bytes / 1048576) * 10) / 10} م.ب` : `${Math.max(1, Math.ceil(bytes / 1024))} ك.ب`);
</script>

<template>
    <div v-if="files.length">
        <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-600"><AppIcon name="paperclip" :size="14" /> المرفقات ({{ files.length }})</p>
        <ul class="space-y-2">
            <li v-for="file in files" :key="file.public_id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                <span class="min-w-0">
                    <a :href="file.url" class="break-all font-semibold text-blue-700 hover:underline" download>{{ file.filename }}</a>
                    <span class="mt-0.5 block text-xs text-slate-500">
                        {{ readable(file.file_size) }}
                        <template v-if="file.mine"> · أرفقته أنت</template>
                        <template v-else-if="file.uploaded_by_name"> · {{ file.uploaded_by_name }}</template>
                        · {{ formatDateTime(file.created_at) }}
                    </span>
                </span>
                <a :href="file.url" class="btn btn-secondary btn-sm shrink-0" download><AppIcon name="file-down" :size="14" /> تنزيل</a>
            </li>
        </ul>
    </div>
</template>
