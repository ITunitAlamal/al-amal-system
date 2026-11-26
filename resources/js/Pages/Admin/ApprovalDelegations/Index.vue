<script setup>
import { formatDate } from '../../../Support/dates.js';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

const props = defineProps({
    canIssue: { type: Boolean, default: false },
    scopes: { type: Object, default: () => ({}) },
    delegations: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const today = new Date().toISOString().slice(0, 10);
const form = useForm({ scope_code: Object.keys(props.scopes)[0] || '', delegate_user_id: '', starts_on: today, ends_on: '', decision_reference: '', note: '' });
const issue = () => form.post('/admin/approval-delegations', { preserveScroll: true, onSuccess: () => form.reset('delegate_user_id', 'ends_on', 'decision_reference', 'note') });

const revoking = ref(null);
const revokeForm = useForm({ reason: '' });
function revoke(item) {
    revokeForm.post(`/admin/approval-delegations/${item.id}/revoke`, { preserveScroll: true, onSuccess: () => { revoking.value = null; revokeForm.reset(); } });
}

const states = { in_force: ['ساري', 'success'], scheduled: ['يبدأ لاحقًا', 'info'], revoked: ['منتهٍ / ملغى', 'muted'] };
const date = value => formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <AppLayout title="التفويضات الإدارية">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader eyebrow="المركزية والتفويض" title="التفويضات الإدارية" description="يصدر المدير التنفيذي تفويضًا محدد النطاق والمدة، فتنتقل المرحلة المفوَّضة إلى المفوَّض أثناء سريانه فقط، وتعود إليه عند إلغائه أو انتهائه." />

            <p class="alert-info text-sm">لا يسري أي تفويض إلا بقرار يصدره المدير التنفيذي هنا، ويُسجَّل باسمه ومرجع قراره. مدير النظام يطّلع ولا يصدر. يسري التفويض على المعاملات التي تصل إلى المرحلة المفوَّضة من تاريخ بدايته؛ ما وصل قبله يبقى لدى المدير التنفيذي.</p>

            <form v-if="canIssue" class="card space-y-4" @submit.prevent="issue">
                <h2 class="card-title">إصدار تفويض</h2>
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="delegation-scope" class="field-label">نطاق التفويض *</label>
                        <select id="delegation-scope" v-model="form.scope_code" required class="field">
                            <option v-for="(label, key) in scopes" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="form.errors.scope_code" class="field-error">{{ form.errors.scope_code }}</p>
                    </div>
                    <div>
                        <label for="delegation-user" class="field-label">المفوَّض *</label>
                        <select id="delegation-user" v-model="form.delegate_user_id" required class="field">
                            <option value="" disabled>اختر</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}{{ user.department ? ` — ${user.department}` : '' }}</option>
                        </select>
                        <p v-if="form.errors.delegate_user_id" class="field-error">{{ form.errors.delegate_user_id }}</p>
                    </div>
                    <div>
                        <label for="delegation-reference" class="field-label">رقم أو مرجع قرار التفويض *</label>
                        <input id="delegation-reference" v-model.trim="form.decision_reference" required minlength="2" maxlength="150" class="field" />
                        <p v-if="form.errors.decision_reference" class="field-error">{{ form.errors.decision_reference }}</p>
                    </div>
                    <div>
                        <label for="delegation-start" class="field-label">يسري من *</label>
                        <input id="delegation-start" v-model="form.starts_on" type="date" :min="today" required class="field" />
                        <p v-if="form.errors.starts_on" class="field-error">{{ form.errors.starts_on }}</p>
                    </div>
                    <div>
                        <label for="delegation-end" class="field-label">حتى (اختياري)</label>
                        <input id="delegation-end" v-model="form.ends_on" type="date" :min="form.starts_on || today" class="field" />
                        <p class="mt-1 text-[11px] text-slate-500">بلا تاريخ نهاية يبقى ساريًا حتى تلغيه.</p>
                        <p v-if="form.errors.ends_on" class="field-error">{{ form.errors.ends_on }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="delegation-note" class="field-label">ملاحظات</label>
                        <textarea id="delegation-note" v-model="form.note" rows="2" maxlength="2000" class="field" />
                    </div>
                </div>
                <button type="submit" :disabled="form.processing" class="btn btn-primary">إصدار التفويض</button>
            </form>

            <section class="card">
                <h2 class="card-title mb-3">سجل التفويضات</h2>
                <EmptyState v-if="!delegations.length" title="لا توجد تفويضات" description="القرار النهائي في المراحل القابلة للتفويض يبقى لدى المدير التنفيذي." />
                <ul v-else class="space-y-3">
                    <li v-for="item in delegations" :key="item.id" class="rounded-xl border border-slate-100 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-navy-800">{{ item.scope }}</p>
                                <p class="mt-1 text-sm text-slate-600">من <strong>{{ item.delegator }}</strong> إلى <strong>{{ item.delegate }}</strong></p>
                                <p class="mt-1 text-xs text-slate-500">من {{ date(item.startsOn) }} {{ item.endsOn ? `إلى ${date(item.endsOn)}` : 'حتى الإلغاء' }} · مرجع القرار: <span dir="auto">{{ item.reference }}</span></p>
                                <p v-if="item.note" class="mt-1 text-xs text-slate-500">{{ item.note }}</p>
                                <p v-if="item.status === 'revoked'" class="mt-1 text-xs text-slate-500">انتهى في {{ date(item.revokedAt) }}{{ item.revokedBy ? ` بواسطة ${item.revokedBy}` : '' }}: {{ item.revokeReason }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <StatusBadge :label="(states[item.status] || states.revoked)[0]" :tone="(states[item.status] || states.revoked)[1]" />
                                <button v-if="item.canRevoke && revoking !== item.id" type="button" class="btn btn-danger btn-sm" @click="revoking = item.id">إلغاء التفويض</button>
                            </div>
                        </div>
                        <form v-if="revoking === item.id" class="mt-3 space-y-2 border-t border-slate-100 pt-3" @submit.prevent="revoke(item)">
                            <label :for="`revoke-${item.id}`" class="field-label">سبب الإلغاء *</label>
                            <textarea :id="`revoke-${item.id}`" v-model="revokeForm.reason" rows="2" required minlength="5" maxlength="2000" class="field" />
                            <p v-if="revokeForm.errors.reason" class="field-error">{{ revokeForm.errors.reason }}</p>
                            <p class="text-xs text-slate-500">تعود المعاملات المفتوحة لدى المفوَّض في هذا النطاق إليك فورًا، مع تسجيل ذلك في سجلها.</p>
                            <div class="flex gap-2">
                                <button type="submit" :disabled="revokeForm.processing" class="btn btn-danger btn-sm">تأكيد الإلغاء</button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="revoking = null">تراجع</button>
                            </div>
                        </form>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
