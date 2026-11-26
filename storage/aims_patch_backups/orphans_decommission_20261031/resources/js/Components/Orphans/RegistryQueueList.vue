<script setup>
import { Link } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

// One work list of the approved registry inside «المراجعة والمتابعة» (moved from the department home unchanged).
defineProps({ queue: { type: String, required: true }, queues: { type: Object, required: true } });
</script>

<template>
    <section class="space-y-2 text-sm">
        <p class="text-xs text-slate-600">قائمة عمل من الملفات المعتمدة، محدودة بالسجلات المحمّلة. افتح الملف للاطلاع على التفاصيل والإجراء المتاح.</p>
        <ul v-if="queue === 'applications'" class="divide-y divide-slate-100">
            <li v-for="a in queues.applications" :key="a.id" class="flex items-center justify-between py-2">
                <Link :href="`/orphans/applications/${a.id}`" class="font-semibold text-blue-700 hover:underline">{{ a.reference_no }} — ملف {{ a.household }}</Link>
                <StatusBadge :label="a.status_label" tone="info" />
            </li>
            <li v-if="!queues.applications?.length" class="py-3 text-slate-500">لا توجد طلبات مفتوحة.</li>
        </ul>
        <ul v-else-if="queue === 'changes'" class="divide-y divide-slate-100">
            <li v-for="c in queues.changes" :key="c.id" class="flex items-center justify-between py-2">
                <Link :href="`/orphans/changes/${c.id}`" class="font-semibold text-blue-700 hover:underline">{{ c.reference_no }} — {{ c.category_label }}</Link>
                <StatusBadge :label="c.status_label" tone="info" />
            </li>
            <li v-if="!queues.changes?.length" class="py-3 text-slate-500">لا توجد طلبات تعديل مفتوحة.</li>
        </ul>
        <template v-else-if="queue === 'emergencies'">
            <p class="text-xs text-slate-500">تنبيه لرئيس القسم فقط؛ لا يبدأ الوسم صرفًا ولا موافقة. يرفعه رئيس القسم من ملف الأسرة.</p>
            <ul class="divide-y divide-slate-100">
                <li v-for="f in queues.emergencies" :key="f.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <Link :href="`/orphans/households/${f.household_public_id}`" class="font-semibold text-red-800 hover:underline">ملف {{ f.household }} — {{ f.reason }}</Link>
                    <span class="text-xs text-slate-500">{{ f.raised_by }} — <span dir="ltr">{{ f.raised_at?.slice(0, 16) }}</span></span>
                </li>
                <li v-if="!queues.emergencies?.length" class="py-3 text-slate-500">لا توجد حالات طوارئ قائمة.</li>
            </ul>
        </template>
        <ul v-else-if="queue === 'referrals'" class="divide-y divide-slate-100">
            <li v-for="r in queues.referrals" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <Link :href="`/orphans/households/${r.household_public_id}`" class="font-semibold text-blue-700 hover:underline">ملف {{ r.household }} — إلى {{ r.referred_to }}</Link>
                <span class="text-xs text-slate-500">المسؤول: {{ r.responsible }} — منذ <span dir="ltr">{{ r.referred_on }}</span></span>
            </li>
            <li v-if="!queues.referrals?.length" class="py-3 text-slate-500">لا توجد إحالات مفتوحة.</li>
        </ul>
        <template v-else-if="queue === 'documents'">
            <p class="text-xs text-slate-500">المستند المنتهي لا يُقبل إثباتًا حتى تُرفع نسخة سارية.</p>
            <ul class="divide-y divide-slate-100">
                <li v-for="d in queues.documents" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <Link v-if="d.household_public_id" :href="`/orphans/households/${d.household_public_id}`" class="font-semibold text-blue-700 hover:underline">{{ d.type }} ({{ d.owner }}) — ملف {{ d.household }}</Link>
                    <span v-else>{{ d.type }} ({{ d.owner }})</span>
                    <StatusBadge :label="(d.stage === 'expired' ? 'انتهى في ' : 'ينتهي في ') + d.expires_on" :tone="d.stage === 'expired' ? 'danger' : 'warning'" />
                </li>
                <li v-if="!queues.documents?.length" class="py-3 text-slate-500">لا توجد مستندات منتهية أو قريبة الانتهاء.</li>
            </ul>
        </template>
        <template v-else-if="queue === 'mine'">
            <h3 class="font-bold text-navy-800">زياراتي المسندة</h3>
            <ul class="divide-y divide-slate-100">
                <li v-for="v in queues.mine?.visits || []" :key="v.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <Link :href="`/orphans/visits/${v.id}`" class="font-semibold text-blue-700 hover:underline">{{ v.reference_no }} — ملف {{ v.household }} — <span dir="ltr">{{ v.scheduled_on }}</span></Link>
                    <StatusBadge :label="v.overdue ? 'متأخرة عن موعدها' : v.status_label" :tone="v.overdue ? 'danger' : (v.status === 'returned' ? 'warning' : 'info')" />
                </li>
                <li v-if="!queues.mine?.visits?.length" class="py-2 text-slate-500">لا توجد زيارات تنتظر تقريرك.</li>
            </ul>
            <h3 class="pt-2 font-bold text-navy-800">متابعات مسندة إليّ</h3>
            <ul class="divide-y divide-slate-100">
                <li v-for="f in queues.mine?.followups || []" :key="f.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <Link :href="`/orphans/households/${f.household_public_id}`" class="font-semibold text-blue-700 hover:underline">ملف {{ f.household }} — {{ f.reason }}</Link>
                    <StatusBadge :label="(f.overdue ? 'متأخرة منذ ' : 'موعدها ') + f.due_on" :tone="f.overdue ? 'danger' : 'warning'" />
                </li>
                <li v-if="!queues.mine?.followups?.length" class="py-2 text-slate-500">لا توجد متابعات مفتوحة باسمك.</li>
            </ul>
        </template>
        <template v-else-if="queue === 'followups'">
            <p class="text-xs text-slate-500">لكل ملف متابعة مفتوحة واحدة بموعد ومسؤول. تقرير الزيارة المعتمد يُنجزها ويجدول التالية.</p>
            <ul class="divide-y divide-slate-100">
                <li v-for="f in queues.followups" :key="f.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <Link :href="`/orphans/households/${f.household_public_id}`" class="font-semibold text-blue-700 hover:underline">ملف {{ f.household }} — {{ f.reason }}</Link>
                    <span class="flex flex-wrap items-center gap-2 text-xs text-slate-500">المسؤول: {{ f.assignee }}
                        <StatusBadge :label="(f.overdue ? 'متأخرة منذ ' : 'موعدها ') + f.due_on" :tone="f.overdue ? 'danger' : 'info'" />
                        <StatusBadge v-if="f.escalated" label="صُعّدت لرئيس القسم" tone="danger" /></span>
                </li>
                <li v-if="!queues.followups?.length" class="py-2 text-slate-500">لا توجد متابعات مفتوحة.</li>
            </ul>
            <template v-if="queues.unscheduled?.length">
                <h3 class="pt-2 font-bold text-navy-800">ملفات بلا متابعة مجدولة ({{ queues.unscheduled.length }})</h3>
                <ul class="divide-y divide-slate-100">
                    <li v-for="h in queues.unscheduled" :key="h.household_public_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <Link :href="`/orphans/households/${h.household_public_id}`" class="font-semibold text-blue-700 hover:underline">{{ h.household }} — {{ h.family_name }}</Link>
                        <span class="text-xs text-slate-500">{{ h.status_label }} — آخر زيارة معتمدة: <span dir="ltr">{{ h.last_visit || 'لا توجد' }}</span></span>
                    </li>
                </ul>
            </template>
        </template>
        <ul v-else class="divide-y divide-slate-100">
            <li v-for="v in queues.visits" :key="v.id" class="flex items-center justify-between py-2">
                <Link :href="`/orphans/visits/${v.id}`" class="font-semibold text-blue-700 hover:underline">{{ v.reference_no }} — ملف {{ v.household }} — {{ v.scheduled_on }} ({{ v.researcher }})</Link>
                <StatusBadge :label="v.status_label" tone="info" />
            </li>
            <li v-if="!queues.visits?.length" class="py-3 text-slate-500">لا توجد زيارات مفتوحة.</li>
        </ul>
    </section>
</template>
