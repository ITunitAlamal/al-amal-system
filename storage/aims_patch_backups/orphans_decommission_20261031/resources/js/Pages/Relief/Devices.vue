<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

// Field devices: approve a newly registered device, revoke it, or report it lost (sessions end at its next contact).
defineProps({ devices: { type: Array, default: () => [] }, serverKey: { type: String, default: '' } });
function act(device, action) {
    let reason = '';
    if (action !== 'approve') {
        reason = window.prompt(action === 'lost' ? 'وصف الفقد (يُسجل):' : 'سبب الإلغاء:') || '';
        if (reason.trim().length < 5) return;
    } else if (!window.confirm(`اعتماد الجهاز «${device.name}» للموظف ${device.user}؟ تأكد من بصمة المفتاح الظاهرة على الجهاز: ${device.fingerprint}`)) return;
    router.post(`/relief/devices/${device.id}/${action}`, { reason }, { preserveScroll: true });
}
const tone = { pending: 'warning', approved: 'success', revoked: 'muted', lost: 'danger' };
</script>

<template>
    <AppLayout title="أجهزة التوزيع الميداني">
        <div class="space-y-4">
            <PageHeader eyebrow="الحملات الإغاثية" title="أجهزة التوزيع الميداني" :description="`مفتاح توقيع الخادم: ${serverKey}. الإلغاء يصل الجهاز عند أول اتصال؛ قبل ذلك تبقى صلاحية العمل دون اتصال حتى انتهائها (168 ساعة كحد أقصى).`" />
            <section class="card overflow-x-auto">
                <table class="w-full text-sm"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">الجهاز</th><th>الموظف</th><th>بصمة المفتاح</th><th>آخر اتصال / مزامنة</th><th>الحالة</th><th></th></tr></thead>
                    <tbody><tr v-for="d in devices" :key="d.id" class="border-t border-slate-100">
                        <td class="py-2">{{ d.name }}<span class="block text-xs text-slate-500">{{ d.platform }} {{ d.app_version }}</span></td><td>{{ d.user }}</td><td dir="ltr" class="font-mono text-xs">{{ d.fingerprint }}</td>
                        <td class="text-xs" dir="ltr">{{ d.last_seen_at ? String(d.last_seen_at).slice(0, 16) : '—' }} / {{ d.last_sync_at ? String(d.last_sync_at).slice(0, 16) : '—' }}</td>
                        <td><StatusBadge :label="d.status_label" :tone="tone[d.status]" /><span v-if="d.revoke_reason" class="block text-xs text-slate-500">{{ d.revoke_reason }}</span></td>
                        <td class="whitespace-nowrap"><button v-if="d.status === 'pending'" type="button" class="btn btn-primary btn-sm" @click="act(d, 'approve')">اعتماد</button>
                            <template v-if="['pending', 'approved'].includes(d.status)"><button type="button" class="ms-1 btn btn-secondary btn-sm" @click="act(d, 'revoke')">إلغاء</button>
                                <button type="button" class="ms-1 btn btn-secondary btn-sm" @click="act(d, 'lost')">مفقود</button></template></td></tr>
                        <tr v-if="!devices.length"><td colspan="6" class="py-3 text-slate-500">لا توجد أجهزة؛ يسجل الموزع جهازه بتسجيل الدخول من تطبيق التوزيع.</td></tr></tbody></table>
            </section>
        </div>
    </AppLayout>
</template>
