<script setup>
import { Link } from '@inertiajs/vue3';
import AppDialog from '../AppDialog.vue';
import AsyncState from '../AsyncState.vue';
import { useRemotePreview } from '../../Support/useRemotePreview.js';
import StatusBadge from '../StatusBadge.vue';
import { linkTones, sectionLabels, sectionStates } from '../../Support/orphans.js';

// Side preview of a registry row: opens without leaving the list (slide-over on small screens).
const props = defineProps({ target: { type: Object, default: null } });
const emit = defineEmits(['close']);
const { data, failure, loading, retry } = useRemotePreview(() => props.target ? `/orphans/registry/preview/${props.target.type}/${props.target.public_id}` : null);
const fileUrl = d => d.type === 'guardian' ? `/orphans/registry/guardians/${d.public_id}` : `/orphans/registry/orphans/${d.public_id}`;
</script>

<template>
    <AppDialog :open="!!target" :title="data?.full_name || target?.name || 'معاينة السجل'" description="معاينة مختصرة؛ تبقى نتائج البحث والفلاتر كما هي عند الإغلاق." drawer @close="emit('close')">
                <div v-if="target" class="mb-4">
                    <p class="eyebrow">{{ target.type === 'guardian' ? 'معاينة الوصي' : 'معاينة ملف اليتيم' }}</p>
                    <p v-if="data" class="mt-1 text-sm text-slate-600"><span class="font-mono" dir="ltr">{{ data.reference_no }}</span> · <span class="font-mono" dir="ltr">{{ data.id_masked }}</span> · {{ data.id_format_label }}</p>
                </div>
            <div class="space-y-4 text-sm">
                <AsyncState :loading="loading" :error="failure" @retry="retry" />
                <template v-if="data?.type === 'guardian'">
                    <p v-if="data.name_parts.length" class="text-slate-700">الاسم الرباعي: {{ data.name_parts.join(' / ') }}</p>
                    <dl class="grid grid-cols-2 gap-2">
                        <div><dt class="text-xs text-slate-500">الجوال</dt><dd dir="ltr" class="text-end font-mono">{{ data.phone || 'غير متوفر' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">حساب البوابة</dt><dd>{{ data.portal ? 'مرتبط' : 'لا يوجد' }}</dd></div>
                    </dl>
                    <section><h3 class="font-semibold text-navy-800">العناوين</h3>
                        <p v-for="a in data.addresses" :key="a.id" class="mt-1">{{ a.kind_label }}: {{ a.label }}</p>
                        <p v-if="!data.addresses.length" class="text-slate-500">غير متوفر</p></section>
                    <section><h3 class="font-semibold text-navy-800">الأيتام المرتبطون ({{ data.children.length }})</h3>
                        <ul class="mt-1 space-y-1">
                            <li v-for="c in data.children" :key="c.id" class="flex flex-wrap items-center gap-2">
                                <Link :href="`/orphans/registry/orphans/${c.orphan_public_id}`" class="text-blue-700 hover:underline">{{ c.orphan_name }}</Link>
                                <StatusBadge :label="c.status_label" :tone="linkTones[c.status]" /><StatusBadge v-if="c.expired" label="انتهت مدة الوصاية" tone="danger" />
                            </li>
                        </ul>
                        <p v-if="!data.children.length" class="text-slate-500">لا يوجد أيتام مرتبطون بعد.</p></section>
                    <p v-if="data.notes" class="rounded-lg bg-slate-50 p-2">{{ data.notes }}</p>
                </template>
                <template v-else-if="data">
                    <dl class="grid grid-cols-2 gap-2">
                        <div><dt class="text-xs text-slate-500">الجنس</dt><dd>{{ data.sex_label }}</dd></div>
                        <div><dt class="text-xs text-slate-500">العمر</dt><dd>{{ data.age ?? 'غير متوفر' }}<span v-if="data.date_of_birth" class="text-slate-500"> ({{ data.date_of_birth }})</span></dd></div>
                        <div><dt class="text-xs text-slate-500">حالة الملف</dt><dd>{{ data.status_label }}</dd></div>
                        <div><dt class="text-xs text-slate-500">اكتمال الملف</dt><dd>{{ data.completeness }}%</dd></div>
                    </dl>
                    <div class="flex flex-wrap gap-1"><StatusBadge v-for="(state, key) in data.sections" :key="key" :label="`${sectionLabels[key]}: ${sectionStates[state][0]}`" :tone="sectionStates[state][1]" /></div>
                    <section><h3 class="font-semibold text-navy-800">الأوصياء</h3>
                        <ul class="mt-1 space-y-1"><li v-for="g in data.guardians" :key="g.id" class="flex flex-wrap items-center gap-2">
                            <Link :href="`/orphans/registry/guardians/${g.guardian_public_id}`" class="text-blue-700 hover:underline">{{ g.guardian_name }}</Link>
                            <StatusBadge :label="g.status_label" :tone="linkTones[g.status]" /><span v-if="g.is_primary" class="text-xs text-slate-500">رئيسي</span>
                        </li></ul></section>
                    <section><h3 class="font-semibold text-navy-800">العنوان</h3>
                        <p v-for="a in data.addresses" :key="a.id" class="mt-1">{{ a.kind_label }}: {{ a.label }}</p>
                        <p v-if="!data.addresses.length" class="text-slate-500">غير متوفر</p></section>
                </template>
                <section v-if="data?.benefits"><h3 class="font-semibold text-navy-800">ملخص الاستفادة (تسليمات مؤكدة)</h3>
                    <p class="mt-1">{{ data.benefits.text }}</p></section>
            </div>
            <template v-if="data" #footer><Link :href="fileUrl(data)" class="btn btn-primary w-full">فتح الملف الكامل</Link></template>
    </AppDialog>
</template>
