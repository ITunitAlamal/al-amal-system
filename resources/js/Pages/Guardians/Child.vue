<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import { formatDate } from '../../Support/dates.js';

// One child, reachable only through an approved guardianship of this account (checked by PortalScope on the server).
const props = defineProps({ child: Object, parents: Object, guardianship: Object, education: Array, residence: Object, groups: Object });
const relation = { father: 'الأب', mother: 'الأم' };
</script>

<template>
    <PortalLayout :title="child.full_name" back="/guardian/family">
        <section class="card space-y-3 p-5">
            <h1 class="text-xl font-bold text-navy-800">{{ child.full_name }}</h1>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">رقم الملف</dt><dd class="font-mono" dir="ltr">{{ child.reference_no }}</dd></div>
                <div><dt class="text-slate-500">رقم الهوية</dt><dd class="font-mono" dir="ltr">{{ child.id_masked || '—' }}</dd></div>
                <div><dt class="text-slate-500">تاريخ الميلاد</dt><dd>{{ formatDate(child.date_of_birth, undefined, 'غير متوفر') }}</dd></div>
                <div v-if="child.place_of_birth"><dt class="text-slate-500">مكان الميلاد</dt><dd>{{ child.place_of_birth }}</dd></div>
                <div v-if="guardianship"><dt class="text-slate-500">وصايتك</dt><dd>معتمدة منذ {{ formatDate(guardianship.starts_on, undefined, '—') }}{{ guardianship.ends_on ? ' حتى ' + formatDate(guardianship.ends_on) : '' }}</dd></div>
            </dl>
        </section>

        <section class="card space-y-2 p-5">
            <h2 class="card-title">الوالدان</h2>
            <ul class="space-y-1 text-sm"><li v-for="(p, key) in parents" :key="key"><span class="text-slate-500">{{ relation[key] || key }}:</span> {{ p.name }} · {{ p.status }}</li></ul>
            <p v-if="!Object.keys(parents).length" class="text-sm text-slate-500">لا توجد بيانات مسجلة.</p>
        </section>

        <section class="card space-y-2 p-5">
            <h2 class="card-title">التعليم</h2>
            <ul v-if="education.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="(e, i) in education" :key="i" class="py-2">{{ [e.academic_year, e.school_name, e.stage, e.grade].filter(Boolean).join(' · ') }} <span v-if="e.status" class="text-slate-500">— {{ e.status }}</span></li>
            </ul>
            <p v-else class="text-sm text-slate-500">لا توجد بيانات تعليم مسجلة.</p>
        </section>

        <section class="card space-y-2 p-5">
            <h2 class="card-title">السكن</h2>
            <p class="text-sm">الحالي: {{ residence.current?.text || 'غير مسجل' }}</p>
            <p v-if="residence.original" class="text-sm">الأصلي: {{ residence.original.text }}</p>
        </section>

        <section class="card space-y-3 p-5">
            <h2 class="card-title">طلب تعديل</h2>
            <p class="text-sm text-slate-600">لا يتغير السجل الرسمي إلا بعد مراجعة القسم واعتماده.</p>
            <div class="flex flex-wrap gap-2"><Link v-for="(label, key) in groups" :key="key" :href="`/guardian/changes/new?child=${child.public_id}&group=${key}`" class="btn btn-sm btn-secondary">{{ label }}</Link></div>
        </section>
    </PortalLayout>
</template>
