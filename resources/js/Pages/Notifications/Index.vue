<script setup>
import { formatDateTime, parseDate } from '../../Support/dates.js';
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    notifications: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    unread: { type: Number, default: 0 },
    preferences: { type: Array, default: () => [] },
});

// Types the user receives; notices of work waiting for them cannot be muted.
const showPreferences = ref(false);
const preferenceForm = useForm({ muted: props.preferences.filter(item => item.muted).map(item => item.code) });
const toggleType = item => {
    if (item.mandatory) return;
    const muted = new Set(preferenceForm.muted);
    muted.has(item.code) ? muted.delete(item.code) : muted.add(item.code);
    preferenceForm.muted = [...muted];
};
const savePreferences = () => preferenceForm.put('/notifications/preferences', { preserveScroll: true });

const open = item => router.post(`/notifications/${item.id}/open`);
const readAll = () => router.post('/notifications/read-all', {}, { preserveScroll: true });
const when = value => {
    return parseDate(value) ? formatDateTime(value) : '';
};
</script>

<template>
    <AppLayout title="إشعاراتي">
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader eyebrow="مساحتي" title="إشعاراتي" description="الإشعارات محفوظة حتى تقرأها. فتح الإشعار ينقلك إلى المعاملة إذا كانت ضمن صلاحيتك.">
                <button v-if="unread" type="button" class="btn btn-secondary" @click="readAll">تعليم الكل كمقروء ({{ unread }})</button>
            </PageHeader>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex gap-2" role="tablist" aria-label="تصفية الإشعارات">
                    <Link href="/notifications" :class="['btn btn-sm', filter === 'all' ? 'btn-primary' : 'btn-secondary']" role="tab" :aria-selected="filter === 'all'">الكل</Link>
                    <Link href="/notifications?filter=unread" :class="['btn btn-sm', filter === 'unread' ? 'btn-primary' : 'btn-secondary']" role="tab" :aria-selected="filter === 'unread'">غير المقروءة</Link>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" :aria-expanded="showPreferences" @click="showPreferences = !showPreferences">أنواع الإشعارات التي تصلني</button>
            </div>

            <section v-if="showPreferences" class="card space-y-3" aria-labelledby="notification-preferences-title">
                <div>
                    <h2 id="notification-preferences-title" class="card-title">أنواع الإشعارات التي تصلني</h2>
                    <p class="mt-1 text-xs text-slate-500">أوقف ما لا تحتاجه. إشعارات العمل المسند إليك (مهمة جديدة، إعادة طلب، تأخر، تكليف بمراسلة، تفويض) إلزامية ولا يمكن إيقافها. إعدادات الصوت من جرس الإشعارات أعلى الصفحة وتُحفظ على هذا الجهاز.</p>
                </div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="item in preferences" :key="item.code" class="flex items-start justify-between gap-3 py-2">
                        <label :for="`pref-${item.code}`" class="min-w-0 text-sm">
                            <span class="block font-semibold text-navy-800">{{ item.label }}<span v-if="item.mandatory" class="mr-2 text-xs font-normal text-slate-500">(إلزامي)</span></span>
                            <span v-if="item.description" class="block text-xs text-slate-500">{{ item.description }}</span>
                        </label>
                        <input :id="`pref-${item.code}`" type="checkbox" class="mt-1 h-4 w-4" :checked="!preferenceForm.muted.includes(item.code)" :disabled="item.mandatory" @change="toggleType(item)" />
                    </li>
                </ul>
                <button type="button" class="btn btn-primary btn-sm" :disabled="preferenceForm.processing" @click="savePreferences">حفظ التفضيلات</button>
            </section>

            <section class="card p-0">
                <EmptyState v-if="!notifications.data.length" class="m-4" :title="filter === 'unread' ? 'لا توجد إشعارات غير مقروءة' : 'لا توجد إشعارات بعد'" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="item in notifications.data" :key="item.id">
                        <button type="button" class="flex w-full gap-3 px-5 py-4 text-right hover:bg-navy-50/50" @click="open(item)">
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="item.read ? 'bg-slate-200' : 'bg-blue-600'" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block" :class="item.read ? 'text-slate-700' : 'font-semibold text-navy-800'">{{ item.title }}<span v-if="!item.read" class="sr-only"> (غير مقروء)</span></span>
                                <span v-if="item.body" class="mt-0.5 block text-sm text-slate-500">{{ item.body }}</span>
                                <span class="mt-1 block text-xs text-slate-400">{{ when(item.createdAt) }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <nav v-if="notifications.prev_page_url || notifications.next_page_url" class="flex gap-2" aria-label="الصفحات">
                <Link v-if="notifications.prev_page_url" :href="notifications.prev_page_url" class="btn btn-secondary btn-sm">السابق</Link>
                <Link v-if="notifications.next_page_url" :href="notifications.next_page_url" class="btn btn-secondary btn-sm">التالي</Link>
            </nav>
        </div>
    </AppLayout>
</template>
