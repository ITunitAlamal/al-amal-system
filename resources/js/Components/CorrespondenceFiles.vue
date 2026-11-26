<script setup>
const props = defineProps({ modelValue: { type: Array, default: () => [] }, settings: Object, progress: Object, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
function add(files) { emit('update:modelValue', [...props.modelValue, ...Array.from(files || [])]); }
</script>
<template>
    <section class="rounded-lg border border-dashed p-4 space-y-2" @dragover.prevent @drop.prevent="!disabled && add($event.dataTransfer.files)">
        <label class="block">إضافة مرفقات متعددة أو سحبها هنا<input type="file" multiple :disabled="disabled" :accept="settings.allowed_extensions.map(x => '.' + x).join(',')" class="block mt-2" @change="add($event.target.files); $event.target.value = ''" /></label>
        <p class="text-sm text-slate-500">الحد: {{ settings.file_count }} ملفات، {{ settings.file_kb }} كيلوبايت للملف، {{ settings.total_kb }} كيلوبايت للمجموعة. الأنواع: {{ settings.allowed_extensions.join('، ') }}</p>
        <ul><li v-for="(file, index) in modelValue" :key="index" class="flex justify-between gap-3"><span>{{ file.name }} — {{ Math.ceil(file.size / 1024) }} كيلوبايت</span><button type="button" :disabled="disabled" @click="emit('update:modelValue', modelValue.filter((_, i) => i !== index))">إزالة قبل الإرسال</button></li></ul>
        <progress v-if="progress" :value="progress.percentage" max="100" class="w-full" aria-label="تقدم رفع مجموعة المرفقات">{{ progress.percentage }}%</progress>
        <p v-if="progress">رفع المجموعة {{ progress.percentage }}٪؛ تُحفظ الملفات مع الإجراء بعد اكتمال التحقق.</p>
    </section>
</template>