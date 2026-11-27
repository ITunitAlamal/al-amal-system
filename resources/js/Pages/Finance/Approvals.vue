<script setup>
import { ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({ queue: Array, board: Boolean, cancellable: { type: Array, default: () => [] } });
const items = ref(props.queue || []);
const busy = ref(false);
const message = ref('');
const error = ref('');

async function decide(item) {
    if (busy.value || !window.confirm('هل تؤكد الموافقة على هذه المعاملة؟')) return;
    busy.value = true;
    message.value = '';
    error.value = '';
    try {
        await axios.post(item.url);
        items.value = items.value.filter(entry => entry.url !== item.url);
        message.value = 'تم اعتماد المعاملة وتوثيقها.';
    } catch (exception) {
        error.value = Object.values(exception.response?.data?.errors || {}).flat().join(' — ') || exception.response?.data?.message || 'تعذر إتمام الموافقة';
    } finally {
        busy.value = false;
    }
}

// Purchase requests can also be rejected or returned to procurement, with a reason the server requires.
const notes = ref({});
async function decline(item, decision) {
    const note = (notes.value[item.url] || '').trim();
    if (busy.value || note.length < 5) { error.value = 'اكتب السبب (5 أحرف على الأقل).'; return; }
    busy.value = true;
    message.value = '';
    error.value = '';
    try {
        await axios.post(item.kind === 'payroll_run' ? `/finance/payroll-runs/${item.id}/return` : `/finance/purchases/${item.id}/decision/${decision}`,
            item.kind === 'payroll_run' ? { reason: note } : { note });
        items.value = items.value.filter(entry => entry.url !== item.url);
        message.value = decision === 'reject' ? 'رُفض الطلب ووُثّق السبب.' : 'أُعيد الطلب إلى المشتريات للتعديل.';
    } catch (exception) {
        error.value = Object.values(exception.response?.data?.errors || {}).flat().join(' — ') || exception.response?.data?.message || 'تعذر حفظ القرار';
    } finally {
        busy.value = false;
    }
}

// The executive director cancels an approved purchase that will not be delivered (or its undelivered remainder), with a reason.
const open = ref(props.cancellable || []);
const reasons = ref({});
async function cancel(item) {
    const reason = (reasons.value[item.id] || '').trim();
    if (busy.value || reason.length < 10) { error.value = 'اكتب سبب الإلغاء (10 أحرف على الأقل).'; return; }
    if (!window.confirm(item.partial ? 'تأكيد إلغاء المتبقي غير المورَّد من أمر الشراء؟' : 'تأكيد إلغاء طلب الشراء المعتمد؟')) return;
    busy.value = true;
    message.value = '';
    error.value = '';
    try {
        await axios.post(`/finance/purchases/${item.id}/cancel`, { reason });
        open.value = open.value.filter(entry => entry.id !== item.id);
        message.value = item.partial ? 'أُلغي المتبقي؛ يُفوتر الأمر ويُغلق على ما ورد فعلًا.' : 'أُلغي طلب الشراء ووُثّق السبب.';
    } catch (exception) {
        error.value = Object.values(exception.response?.data?.errors || {}).flat().join(' — ') || exception.response?.data?.message || 'تعذر الإلغاء';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppLayout title="موافقات مالية">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                eyebrow="قسم الحسابات"
                title="صندوق الموافقات المالية"
                :description="board ? 'أمين الصندوق عضو مجلس الإدارة: الموافقة على المشتريات فوق الحد المعتمد فقط، دون صلاحيات صرف.' : 'المعاملات التي تنتظر موافقتك حسب صلاحياتك.'"
            />
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <section class="card">
                <EmptyState v-if="!items.length" title="لا توجد معاملات تنتظر موافقتك" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="item in items" :key="item.url" class="space-y-2 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-navy-800">{{ item.label }} <span class="text-slate-400" dir="ltr">#{{ item.id }}</span></p>
                                <p v-if="item.amount != null" class="text-sm text-slate-600">المبلغ: {{ item.amount }}</p>
                            </div>
                            <button type="button" :disabled="busy" class="btn btn-primary" @click="decide(item)">موافقة</button>
                        </div>
                        <div v-if="item.kind === 'payroll_run'" class="flex flex-wrap items-end gap-2">
                            <label class="min-w-56 flex-1"><span class="field-label">سبب الإعادة</span><input v-model="notes[item.url]" class="field" maxlength="1000" /></label>
                            <button type="button" :disabled="busy" class="btn btn-secondary btn-sm" @click="decline(item, 'return')">إعادة لمسؤول الرواتب</button>
                        </div>
                        <div v-if="item.kind === 'purchase'" class="flex flex-wrap items-end gap-2">
                            <label class="min-w-56 flex-1"><span class="field-label">سبب الرفض أو الإعادة</span><input v-model="notes[item.url]" class="field" maxlength="2000" /></label>
                            <button type="button" :disabled="busy" class="btn btn-secondary btn-sm" @click="decline(item, 'return')">إعادة للمشتريات</button>
                            <button type="button" :disabled="busy" class="btn btn-danger btn-sm" @click="decline(item, 'reject')">رفض</button>
                        </div>
                    </li>
                </ul>
            </section>
            <section v-if="open.length" class="card space-y-3">
                <h2 class="card-title">مشتريات معتمدة لم يكتمل توريدها</h2>
                <p class="text-sm text-slate-600">إن تعذر التوريد يُلغى الطلب وأمره الصادر، أو يُلغى المتبقي من أمر استُلم جزء منه، مع ذكر السبب.</p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="item in open" :key="item.id" class="space-y-2 py-3">
                        <p class="font-semibold text-navy-800">طلب الشراء {{ item.number }} <span class="text-sm font-normal text-slate-600">— {{ item.amount }} {{ item.currency }} — {{ item.order ? (item.partial ? 'أمر ' + item.order + ': استلام جزئي' : 'أمر ' + item.order + ': لم يُستلم شيء') : 'لم يصدر أمر شراء' }}</span></p>
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="min-w-56 flex-1"><span class="field-label">سبب الإلغاء</span><input v-model="reasons[item.id]" class="field" maxlength="2000" /></label>
                            <button type="button" :disabled="busy" class="btn btn-danger btn-sm" @click="cancel(item)">{{ item.partial ? 'إلغاء المتبقي' : 'إلغاء الطلب' }}</button>
                        </div>
                    </li>
                </ul>
            </section>
            <p class="text-xs text-slate-500">يتحقق الخادم من الصلاحية وحد المبلغ والفصل بين المهام مجددًا عند الضغط؛ ظهور الزر لا يمنح الموافقة وحده.</p>
        </div>
    </AppLayout>
</template>
