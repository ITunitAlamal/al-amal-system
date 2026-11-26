<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { formatDate } from '../../Support/dates.js';

// «طلباتي»: family requests, change requests and appeals of this account only, with public-safe statuses.
const props = defineProps({ applications: Array, changes: Array, appeals: Array, children: Array, linked: Boolean, appealKinds: Object, idempotencyKey: String, canStart: Boolean });
const tab = ref('applications');
const tabs = { applications: 'طلبات التسجيل', changes: 'طلبات التعديل', appeals: 'الاعتراضات' };
const tone = s => ({ approved: 'success', completed: 'success', accepted: 'success', rejected: 'danger', needs_completion: 'warning', needs_info: 'warning', draft: 'muted' }[s] || 'info');
const appeal = useForm({ kind: 'eligibility', child: '', reason: '', idempotency_key: props.idempotencyKey });
const replies = ref({});
const appealOpen = ref(false);
function sendAppeal() { appeal.post('/guardian/appeals', { preserveScroll: true, onSuccess: () => { appeal.reset('reason'); appealOpen.value = false; } }); }
function respond(c) { router.post(`/guardian/changes/${c.public_id}/respond`, { note: replies.value[c.public_id] || '' }, { preserveScroll: true }); }
function start() { router.post('/guardian/applications'); }
</script>

<template>
    <PortalLayout title="طلباتي">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold text-navy-800">طلباتي</h1>
            <button v-if="canStart" type="button" class="btn btn-primary" @click="start">طلب تسجيل أسرة جديد</button>
        </div>
        <div class="flex gap-1 overflow-x-auto rounded-xl bg-white p-1 ring-1 ring-slate-200" role="tablist" aria-label="أنواع الطلبات">
            <button v-for="(label, key) in tabs" :key="key" type="button" role="tab" :aria-selected="tab === key" :class="['whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold', tab === key ? 'bg-navy-700 text-white' : 'text-slate-600 hover:bg-slate-50']" @click="tab = key">
                {{ label }} <span class="tabular-nums opacity-80">({{ { applications, changes, appeals }[key].length }})</span></button>
        </div>

        <section v-if="tab === 'applications'" class="space-y-3" role="tabpanel">
            <article v-for="a in applications" :key="a.public_id" class="card space-y-2 p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2"><span class="font-mono" dir="ltr">{{ a.reference_no }}</span><StatusBadge :label="a.status_label" :tone="tone(a.status)" />
                    <span class="text-slate-500">{{ formatDate(a.submitted_at || a.last_saved_at) }}</span>
                    <span class="ms-auto flex gap-2"><Link :href="`/guardian/requests/${a.public_id}`" class="btn btn-sm btn-secondary">المراحل</Link>
                        <Link :href="`/guardian/registrations/${a.public_id}`" :class="['btn btn-sm', a.editable ? 'btn-primary' : 'btn-secondary']">{{ a.status === 'draft' ? 'متابعة المسودة' : a.editable ? 'استكمال' : 'عرض' }}</Link></span></div>
                <p v-if="a.completion_request" class="rounded-lg bg-gold-50 p-3 font-semibold text-gold-900">مطلوب استكمال: {{ a.completion_request }}</p>
                <p v-if="a.rejection" class="rounded-lg bg-red-50 p-3 text-red-800">سبب القرار: {{ a.rejection }}</p>
                <ul v-if="a.children.length" class="space-y-1"><li v-for="(c, i) in a.children" :key="i">{{ c.full_name }} — <StatusBadge :label="c.status_label" :tone="tone(c.status)" /><span v-if="c.note" class="text-slate-600"> · {{ c.note }}</span></li></ul>
            </article>
            <EmptyState v-if="!applications.length" title="لا توجد طلبات تسجيل" description="ابدأ طلبًا واحدًا للأسرة وفيه بطاقة لكل طفل." />
        </section>

        <section v-else-if="tab === 'changes'" class="space-y-3" role="tabpanel">
            <Link v-if="linked" href="/guardian/changes/new" class="btn btn-secondary">طلب تعديل بياناتي</Link>
            <article v-for="c in changes" :key="c.public_id" class="card space-y-2 p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2"><span class="font-mono" dir="ltr">{{ c.reference_no }}</span><span class="font-semibold">{{ c.group }} — {{ c.subject }}</span>
                    <StatusBadge :label="c.status_label" :tone="tone(c.status)" /><span class="text-slate-500">{{ formatDate(c.created_at) }}</span>
                    <span v-if="c.applied" class="text-emerald-700">طُبّق على السجل</span></div>
                <p v-if="c.response" class="rounded-lg bg-slate-50 p-3">رد القسم: {{ c.response }}</p>
                <form v-if="c.status === 'needs_info'" class="flex flex-col gap-2 sm:flex-row" @submit.prevent="respond(c)">
                    <label class="sr-only" :for="`reply-${c.public_id}`">الاستكمال المطلوب</label>
                    <input :id="`reply-${c.public_id}`" v-model="replies[c.public_id]" class="field flex-1" maxlength="2000" placeholder="اكتب الاستكمال المطلوب" required>
                    <button type="submit" class="btn btn-primary btn-sm">إرسال</button></form>
            </article>
            <EmptyState v-if="!changes.length" title="لا توجد طلبات تعديل" :description="linked ? 'يمكنك طلب تعديل بياناتك أو بيانات طفل من «أسرتي».' : 'يتاح طلب التعديل بعد اعتماد حسابك.'" />
        </section>

        <section v-else class="space-y-3" role="tabpanel">
            <div v-if="linked" class="card space-y-3 p-4">
                <button type="button" class="btn btn-secondary" :aria-expanded="appealOpen" @click="appealOpen = !appealOpen">تقديم اعتراض</button>
                <form v-if="appealOpen" class="space-y-3" @submit.prevent="sendAppeal">
                    <p class="text-xs text-slate-600">يراجع القسم الاعتراض، ولا يغيّر الاعتراض أي كشف معتمد بنفسه.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">نوع الاعتراض<select v-model="appeal.kind" class="field mt-1"><option v-for="(label, key) in appealKinds" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="text-sm">يخص<select v-model="appeal.child" class="field mt-1"><option value="">بياناتي</option><option v-for="c in children" :key="c.public_id" :value="c.public_id">{{ c.name }}</option></select></label>
                    </div>
                    <label class="block text-sm">السبب (10 أحرف على الأقل)<textarea v-model="appeal.reason" rows="3" class="field mt-1" maxlength="3000" :aria-invalid="!!appeal.errors.reason"></textarea>
                        <span v-if="appeal.errors.reason" class="mt-1 block text-red-700">{{ appeal.errors.reason }}</span></label>
                    <button type="submit" class="btn btn-primary" :disabled="appeal.processing">{{ appeal.processing ? 'جارٍ الإرسال…' : 'إرسال الاعتراض' }}</button>
                </form>
            </div>
            <article v-for="a in appeals" :key="a.reference_no" class="card space-y-1 p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2"><span class="font-mono" dir="ltr">{{ a.reference_no }}</span><span class="font-semibold">{{ a.kind }} — {{ a.subject }}</span>
                    <StatusBadge :label="a.status_label" :tone="tone(a.status)" /><span class="text-slate-500">{{ formatDate(a.created_at) }}</span></div>
                <p class="text-slate-600">{{ a.reason }}</p>
                <p v-if="a.response" class="rounded-lg bg-slate-50 p-3">رد القسم: {{ a.response }}</p>
            </article>
            <EmptyState v-if="!appeals.length" title="لا توجد اعتراضات" />
        </section>
    </PortalLayout>
</template>
