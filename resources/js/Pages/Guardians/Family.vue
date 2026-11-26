<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { formatDate } from '../../Support/dates.js';

// «أسرتي»: the guardian's own data, the households and only the children whose guardianship was approved for this account.
defineProps({ linked: Boolean, account: Object, profile: Object, children: Array, households: Array });
const sexLabel = { male: 'ذكر', female: 'أنثى' };
const displacement = { displaced: 'نازحة', not_displaced: 'غير نازحة', returned: 'عادت إلى مكانها' };
</script>

<template>
    <PortalLayout title="أسرتي">
        <h1 class="text-2xl font-bold text-navy-800">أسرتي</h1>
        <EmptyState v-if="!linked" title="لم يُربط حسابك بسجل الأسرة بعد" description="بعد اعتماد القسم لحسابك ووصايتك تظهر هنا بياناتك وبيانات الأطفال المعتمدين لك.">
            <Link href="/guardian/requests" class="btn btn-primary">متابعة طلباتي</Link>
        </EmptyState>
        <template v-else>
            <section v-if="profile" class="card space-y-3 p-5" aria-labelledby="me-title">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="me-title" class="card-title">بياناتي</h2>
                    <Link href="/guardian/changes/new" class="btn btn-sm btn-secondary">طلب تعديل بياناتي</Link></div>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">الاسم</dt><dd class="font-semibold text-navy-900">{{ profile.full_name }}</dd></div>
                    <div><dt class="text-slate-500">رقم الهوية</dt><dd class="font-mono" dir="ltr">{{ profile.id_masked || '—' }}</dd></div>
                    <div><dt class="text-slate-500">الجوال</dt><dd dir="ltr" class="text-start">{{ profile.phone || '—' }}</dd></div>
                    <div><dt class="text-slate-500">السكن الحالي</dt><dd>{{ profile.address?.text || 'غير مسجل' }}</dd></div>
                </dl>
            </section>

            <section class="card space-y-3 p-5" aria-labelledby="kids-title">
                <h2 id="kids-title" class="card-title">الأطفال المعتمدون لك</h2>
                <ul v-if="children.length" class="grid gap-3 sm:grid-cols-2">
                    <li v-for="c in children" :key="c.public_id">
                        <Link :href="`/guardian/family/children/${c.public_id}`" class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 hover:border-blue-300 hover:bg-blue-50/40">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-navy-50 font-bold text-navy-700" aria-hidden="true">{{ c.full_name.slice(0, 1) }}</span>
                            <span class="min-w-0"><span class="block font-semibold text-navy-900">{{ c.full_name }}</span>
                                <span class="block text-xs text-slate-500">{{ sexLabel[c.sex] || '' }} · الميلاد: {{ formatDate(c.date_of_birth, undefined, 'غير متوفر') }}</span></span>
                            <span class="ms-auto text-slate-400" aria-hidden="true">←</span>
                        </Link>
                    </li>
                </ul>
                <EmptyState v-else title="لا يوجد أطفال معتمدون بعد" description="يظهر كل طفل هنا بعد اعتماد وصايتك عليه وحده؛ الطلب المقدم لا يكفي لعرض الملف." />
            </section>

            <section v-for="h in households" :key="h.reference_no" class="card space-y-2 p-5">
                <h2 class="card-title">الأسرة <span class="font-mono text-sm font-normal text-slate-500" dir="ltr">{{ h.reference_no }}</span></h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">السكن الحالي</dt><dd>{{ h.current?.text || 'غير مسجل' }}</dd></div>
                    <div v-if="h.original"><dt class="text-slate-500">السكن الأصلي</dt><dd>{{ h.original.text }}</dd></div>
                    <div v-if="h.displaced"><dt class="text-slate-500">حالة النزوح</dt><dd>{{ displacement[h.displaced] || h.displaced }}</dd></div>
                </dl>
            </section>
        </template>
    </PortalLayout>
</template>
