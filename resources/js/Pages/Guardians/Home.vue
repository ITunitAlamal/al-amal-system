<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import RequestTimeline from '../../Components/Portal/RequestTimeline.vue';
import { formatDate } from '../../Support/dates.js';

// «الرئيسية»: where the family request stands and what to do next. No numeric dashboard for a family.
const props = defineProps({ account: Object, actions: Array, childrenCount: Number, hasApplication: Boolean, notices: Array, current: Object });
const toneOf = { pending: 'info', approved: 'success', rejected: 'danger', suspended: 'muted' };
const canStart = computed(() => !['rejected', 'suspended'].includes(props.account.status));
function start() { router.post('/guardian/applications'); }
</script>

<template>
    <PortalLayout title="الرئيسية">
        <section class="card space-y-2 p-5">
            <h1 class="text-xl font-bold text-navy-800">مرحبًا {{ account.name }}</h1>
            <p class="flex flex-wrap items-center gap-2 text-sm text-slate-600">حالة الحساب: <StatusBadge :label="account.status_label" :tone="toneOf[account.status]" /></p>
        </section>

        <section class="card space-y-4 p-5" aria-labelledby="stage-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="stage-title" class="card-title">طلب تسجيل الأسرة</h2>
                <span v-if="current" class="font-mono text-sm text-slate-500" dir="ltr">{{ current.reference_no }}</span>
            </div>
            <template v-if="current">
                <RequestTimeline :status="current.status" :approved="current.status_label === 'معتمد'" />
                <p v-if="current.completion_request" class="rounded-lg bg-gold-50 p-3 text-sm font-semibold text-gold-900">مطلوب منك: {{ current.completion_request }}</p>
                <p v-if="current.rejection" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">سبب القرار: {{ current.rejection }}</p>
                <ul v-if="current.children.length" class="divide-y divide-slate-100 text-sm" aria-label="الأطفال في الطلب">
                    <li v-for="(c, i) in current.children" :key="i" class="flex flex-wrap items-center gap-2 py-2"><span class="font-semibold text-navy-900">{{ c.full_name }}</span><StatusBadge :label="c.status_label" :tone="{ approved: 'success', rejected: 'danger', needs_completion: 'warning' }[c.status] || 'info'" /><span v-if="c.note" class="text-slate-600">{{ c.note }}</span></li>
                </ul>
                <div class="flex flex-wrap gap-2">
                    <Link :href="`/guardian/registrations/${current.public_id}`" :class="['btn', current.editable ? 'btn-primary' : 'btn-secondary']">{{ current.status === 'draft' ? 'متابعة المسودة' : current.editable ? 'استكمال الطلب وإعادة إرساله' : 'عرض الطلب' }}</Link>
                    <Link href="/guardian/requests" class="btn btn-secondary">كل طلباتي</Link>
                </div>
            </template>
            <template v-else>
                <p class="text-sm leading-7 text-slate-600">ابدأ طلبًا واحدًا للأسرة، وفيه بطاقة لكل طفل ترعاه. تُحفظ المسودة تلقائيًا ويمكنك إكمالها لاحقًا.</p>
                <button v-if="canStart" type="button" class="btn btn-primary" @click="start">بدء طلب تسجيل الأسرة</button>
            </template>
        </section>

        <section v-if="actions.length" class="card space-y-3 p-5" aria-labelledby="actions-title">
            <h2 id="actions-title" class="card-title">مطلوب منك</h2>
            <ul class="space-y-2">
                <li v-for="(a, i) in actions" :key="i"><Link :href="a.url" :class="['flex items-center justify-between gap-3 rounded-xl border p-3 text-sm font-semibold', a.tone === 'warning' ? 'border-gold-200 bg-gold-50 text-gold-900' : 'border-blue-100 bg-blue-50 text-blue-900']">{{ a.label }}<span aria-hidden="true">←</span></Link></li>
            </ul>
        </section>

        <div class="grid gap-3 sm:grid-cols-2">
            <Link href="/guardian/family" class="card block p-5 hover:border-blue-300">
                <p class="text-sm text-slate-600">الأطفال المعتمدة وصايتك عليهم</p>
                <p class="mt-1 text-3xl font-bold tabular-nums text-navy-800">{{ childrenCount }}</p>
                <p class="mt-1 text-xs text-slate-500">يظهر الطفل هنا بعد اعتماد وصايتك عليه وحده، وما دامت سارية.</p>
            </Link>
            <section class="card p-5" aria-labelledby="notices-title">
                <div class="flex items-center justify-between gap-2"><h2 id="notices-title" class="card-title">آخر الإشعارات</h2><Link href="/guardian/notifications" class="text-sm font-semibold text-blue-700 hover:underline">الكل</Link></div>
                <ul v-if="notices.length" class="mt-2 space-y-2 text-sm">
                    <li v-for="n in notices" :key="n.id"><a :href="`/guardian/notifications/${n.id}/open`" :class="['block rounded-lg px-2 py-1.5 hover:bg-slate-50', n.read_at ? 'text-slate-600' : 'font-semibold text-navy-900']">{{ n.title }} <span class="block text-xs font-normal text-slate-500">{{ formatDate(n.created_at) }}</span></a></li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">لا توجد إشعارات بعد.</p>
            </section>
        </div>
    </PortalLayout>
</template>
