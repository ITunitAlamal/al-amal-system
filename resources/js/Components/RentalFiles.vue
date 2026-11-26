<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

/**
 * Documents of one asset, unit or tenant in the rentals file: the list (with versions) and an upload form.
 * Files are served only through the authorised download route.
 */
const props = defineProps({
    owner: { type: String, required: true },
    ownerId: { type: [Number, String], required: true },
    documents: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    canUpload: { type: Boolean, default: false },
});

const mine = computed(() => props.documents.filter(d => d.owner_type === props.owner && Number(d.owner_id) === Number(props.ownerId)));
const superseded = computed(() => new Set(mine.value.map(d => Number(d.supersedes_id)).filter(Boolean)));
const form = useForm({ file: null, document_type_value_id: null, title: '', supersedes_id: null });
function send() {
    form.post(`/admin/rentals/files/${props.owner}/${props.ownerId}`, { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <div class="space-y-2">
        <h4 class="text-sm font-bold">المستندات والمخططات</h4>
        <ul v-if="mine.length" class="space-y-1 text-xs">
            <li v-for="d in mine" :key="d.id" :class="superseded.has(Number(d.id)) ? 'opacity-60' : ''">
                <a :href="`/admin/rentals/documents/${d.id}`" class="font-semibold text-blue-700 hover:underline">{{ d.title }}</a>
                — {{ d.type || 'بلا تصنيف' }} · الإصدار {{ d.version }}{{ superseded.has(Number(d.id)) ? ' (استُبدل)' : '' }}
            </li>
        </ul>
        <p v-else class="text-xs text-slate-500">لا توجد مستندات.</p>
        <form v-if="canUpload" class="grid gap-2" @submit.prevent="send">
            <input type="file" class="field" required :aria-label="`ملف لـ ${owner}`" @input="form.file = $event.target.files[0]">
            <select v-model="form.document_type_value_id" class="field" aria-label="نوع المستند"><option :value="null">نوع المستند</option><option v-for="o in types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            <input v-model="form.title" class="field" required placeholder="عنوان المستند">
            <select v-if="mine.length" v-model="form.supersedes_id" class="field" aria-label="إصدار جديد من"><option :value="null">مستند جديد</option><option v-for="d in mine.filter(x => !superseded.has(Number(x.id)))" :key="d.id" :value="d.id">إصدار جديد من: {{ d.title }}</option></select>
            <p v-for="(message, key) in form.errors" :key="key" class="field-error">{{ message }}</p>
            <button class="btn btn-secondary btn-sm" :disabled="form.processing">رفع</button>
        </form>
    </div>
</template>
