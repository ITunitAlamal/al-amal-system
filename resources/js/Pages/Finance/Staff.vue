<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ role: String, staff: Array, templates: Array, catalog: Array, pending: Array, sod: Array, exceptionMaxDays: Number, uncovered: { type: Array, default: () => [] } });

const selectedId = ref(props.staff?.[0]?.id || null);
const detail = ref(null);
const loading = ref(false);
const loadError = ref('');
const message = ref('');
const error = ref('');
const busy = ref(false);

const today = new Date().toISOString().slice(0, 10);
const form = ref({ kind: 'specialization', code: '', is_primary: false, valid_from: today, valid_until: '', reason: '', exception: false });
const preview = ref(null);

const selected = computed(() => props.staff.find(person => person.id === selectedId.value));
const options = computed(() => form.value.kind === 'specialization'
    ? props.templates.filter(t => t.assignable).map(t => ({ code: t.code, label: t.label, sensitive: t.sensitive }))
    : props.catalog.flatMap(area => area.permissions.filter(p => p.assignable).map(p => ({ code: p.code, label: `${area.name} — ${p.label}${p.inactive ? ' (معطّلة في الدورة المستندية)' : ''}`, sensitive: p.sensitive }))));
const toneOf = state => ({ pending_approval: 'warning', applied: 'success', rejected: 'danger', ended: 'muted', expired: 'muted', cancelled: 'muted' }[state] || 'muted');
const errorText = e => Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر تنفيذ العملية';

async function load() {
    detail.value = null;
    loadError.value = '';
    if (!selectedId.value) return;
    loading.value = true;
    try {
        detail.value = (await axios.get(`/finance/staff/${selectedId.value}`)).data;
    } catch (e) {
        loadError.value = errorText(e);
    } finally {
        loading.value = false;
    }
}
watch(selectedId, () => { preview.value = null; form.value.code = ''; load(); }, { immediate: true });
watch(() => form.value.kind, () => { form.value.code = ''; preview.value = null; });

async function runPreview() {
    preview.value = null;
    if (!selectedId.value || !form.value.code) return;
    try {
        preview.value = (await axios.post('/finance/staff/preview', { user_id: selectedId.value, kind: form.value.kind, code: form.value.code })).data;
        if (!preview.value.conflicts.length) form.value.exception = false;
    } catch (e) {
        preview.value = { error: errorText(e), adds: [], conflicts: [] };
    }
}
watch(() => form.value.code, runPreview);

async function submit() {
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
        const { data } = await axios.post('/finance/staff/grants', { user_id: selectedId.value, ...form.value, valid_until: form.value.valid_until || null });
        message.value = data.message;
        form.value = { ...form.value, code: '', reason: '', exception: false, valid_until: '' };
        preview.value = null;
        router.reload({ only: ['staff', 'pending'] });
        await load();
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}

async function act(url, body, confirmText) {
    const reason = prompt(confirmText) || '';
    if (reason.trim().length < 5) return;
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
        message.value = (await axios.post(url, { ...body, reason })).data.message;
        router.reload({ only: ['staff', 'pending'] });
        await load();
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}
const endTemplate = a => act(`/finance/staff/${selectedId.value}/end`, { specialization: a.code }, `سبب إنهاء «${a.label}» (5 أحرف على الأقل)`);
const withdraw = p => act(`/finance/staff/${selectedId.value}/withdraw`, { code: p.code }, `سبب سحب «${p.label}» فورًا (5 أحرف على الأقل)`);
</script>

<template>
    <AppLayout title="موظفو القسم وصلاحياتهم">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="قسم الحسابات" title="موظفو القسم وصلاحياتهم"
                :description="role === 'head' ? 'تُسنِد لموظفي الحسابات ووحداتها قوالب الأدوار وصلاحياتها. لا يُسند أحد لنفسه، والصلاحيات الحساسة والاستثناءات من فصل المهام يقررها المدير التنفيذي.' : 'إسناد اختصاصات موظفي الحسابات. صلاحيتك التقنية لا تمنح قرار الصلاحيات الحساسة؛ يقررها المدير التنفيذي.'" />
            <p class="text-sm text-slate-600">المشتريات والمخازن والرواتب والصرف والقبض اختصاصات مستقلة داخل قسم الحسابات. يمكن جمع عدة اختصاصات وصلاحيات لموظف واحد؛ الإسناد الجديد يُضاف إلى إسناداته الحالية.</p>
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>

            <section v-if="uncovered.length" class="alert-warning space-y-2" aria-labelledby="uncovered-title">
                <h2 id="uncovered-title" class="font-bold">خطوات بلا مسؤول ({{ uncovered.length }})</h2>
                <p class="text-sm">لا يستطيع أحد تنفيذ هذه الخطوات اليوم، فيتوقف عندها كل طلب يصلها. الإسناد قرار صريح لا يتم تلقائيًا.</p>
                <ul class="list-disc space-y-1 ps-5 text-sm">
                    <li v-for="step in uncovered" :key="step.permission">{{ step.label }} <span class="text-slate-600">— يُسندها: {{ step.assigned_by }}</span></li>
                </ul>
            </section>

            <section v-if="pending.length" class="card space-y-3" aria-labelledby="pending-title">
                <h2 id="pending-title" class="card-title">بانتظار قرار المدير التنفيذي</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="grant in pending" :key="grant.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><strong class="text-navy-800">{{ grant.user }}</strong> — {{ grant.what }}<span v-if="grant.validUntil" class="text-slate-500"> · حتى {{ grant.validUntil }}</span></span>
                        <Link :href="`/finance/access-grants/${grant.id}`" class="font-semibold text-navy-700 hover:underline">التفاصيل</Link>
                    </li>
                </ul>
            </section>

            <div class="grid gap-5 lg:grid-cols-3">
                <section class="card space-y-3 lg:col-span-1" aria-labelledby="staff-title">
                    <h2 id="staff-title" class="card-title">موظفو القسم</h2>
                    <EmptyState v-if="!staff.length" title="لا يوجد موظفون نشطون في الحسابات غيرك" />
                    <ul v-else class="space-y-2" role="listbox" aria-label="اختر موظفًا">
                        <li v-for="person in staff" :key="person.id">
                            <button type="button" role="option" :aria-selected="person.id === selectedId"
                                class="w-full rounded-xl border p-3 text-right transition"
                                :class="person.id === selectedId ? 'border-gold-400 bg-gold-50' : 'border-slate-200 hover:border-navy-200'"
                                @click="selectedId = person.id">
                                <span class="block font-semibold text-navy-800">{{ person.name }}</span>
                                <span class="block text-xs text-slate-500">{{ person.department }}</span>
                                <span class="mt-1 block text-xs text-slate-600">{{ person.templates.map(t => t.label + (t.until ? ` (حتى ${t.until})` : '')).join('، ') || 'لا اختصاص' }}</span>
                                <span v-if="person.conflicts" class="mt-1 inline-block rounded bg-gold-100 px-2 py-0.5 text-xs font-semibold text-gold-800">{{ person.conflicts }} تعارض في فصل المهام</span>
                            </button>
                        </li>
                    </ul>
                </section>

                <div class="space-y-5 lg:col-span-2">
                    <section v-if="selected" class="card space-y-4" aria-labelledby="detail-title">
                        <h2 id="detail-title" class="card-title">{{ selected.name }}</h2>
                        <p v-if="loading" class="text-sm text-slate-500">جارٍ التحميل…</p>
                        <div v-if="loadError" class="alert-error" role="alert">{{ loadError }}</div>
                        <template v-if="detail">
                            <div v-if="detail.conflicts.length" class="rounded-lg border border-gold-300 bg-gold-50 p-3 text-sm" role="status">
                                <strong class="text-gold-800">تعارضات قائمة في فصل المهام:</strong>
                                <ul class="mt-1 list-disc space-y-0.5 pr-5"><li v-for="(pair, i) in detail.conflicts" :key="i">{{ pair[0] }} + {{ pair[1] }}</li></ul>
                                <p class="mt-1 text-xs text-slate-600">يمنع النظام الشخص نفسه من الخطوتين في المعاملة نفسها.</p>
                            </div>
                            <div>
                                <h3 class="mb-2 text-sm font-bold text-navy-800">الاختصاصات</h3>
                                <ul class="divide-y divide-slate-100 text-sm">
                                    <li v-for="a in detail.assignments" :key="a.code" class="flex flex-wrap items-center justify-between gap-2 py-2" :class="a.active ? '' : 'opacity-60'">
                                        <span>{{ a.label }}{{ a.primary ? ' · أساسي' : '' }}{{ a.until ? ` · حتى ${a.until}` : '' }}{{ a.active ? '' : ' · منتهٍ' }}</span>
                                        <button v-if="a.active" type="button" class="text-xs font-semibold text-red-700 hover:underline" :disabled="busy" @click="endTemplate(a)">إنهاء</button>
                                    </li>
                                    <li v-if="!detail.assignments.length" class="py-2 text-slate-500">لا اختصاص — لا تسري أي صلاحية مالية.</li>
                                </ul>
                            </div>
                            <div>
                                <h3 class="mb-2 text-sm font-bold text-navy-800">الصلاحيات النافذة اليوم</h3>
                                <ul class="flex flex-wrap gap-2 text-xs">
                                    <li v-for="p in detail.effective" :key="p.code" class="flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-3 py-1">
                                        {{ p.label }}
                                        <button type="button" class="font-bold text-red-700" :aria-label="`سحب ${p.label}`" :disabled="busy" @click="withdraw(p)">×</button>
                                    </li>
                                    <li v-if="!detail.effective.length" class="text-slate-500">لا شيء</li>
                                </ul>
                            </div>
                            <details class="text-sm">
                                <summary class="cursor-pointer font-semibold text-navy-700">سجل الإسناد ({{ detail.history.length }})</summary>
                                <ul class="mt-2 divide-y divide-slate-100">
                                    <li v-for="g in detail.history" :key="g.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <span>{{ g.what }} — {{ g.requestedBy }} ({{ g.requestedAs }}) · {{ String(g.createdAt).slice(0, 10) }}</span>
                                        <StatusBadge :label="g.stateLabel" :tone="toneOf(g.state)" />
                                    </li>
                                </ul>
                            </details>
                        </template>
                    </section>

                    <section v-if="selected" class="card space-y-4" aria-labelledby="grant-title">
                        <h2 id="grant-title" class="card-title">إسناد جديد</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <fieldset>
                                <legend class="field-label">النوع</legend>
                                <div class="flex gap-4 text-sm">
                                    <label class="flex items-center gap-2"><input v-model="form.kind" type="radio" value="specialization" />قالب دور</label>
                                    <label class="flex items-center gap-2"><input v-model="form.kind" type="radio" value="permission" />صلاحية مفردة</label>
                                </div>
                            </fieldset>
                            <div>
                                <label for="grant-code" class="field-label">{{ form.kind === 'specialization' ? 'القالب' : 'الصلاحية' }}</label>
                                <select id="grant-code" v-model="form.code" class="field">
                                    <option value="">اختر</option>
                                    <option v-for="o in options" :key="o.code" :value="o.code">{{ o.label }}{{ o.sensitive ? ' — حساسة' : '' }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="grant-from" class="field-label">يبدأ في</label>
                                <input id="grant-from" v-model="form.valid_from" type="date" :min="today" class="field" />
                            </div>
                            <div>
                                <label for="grant-until" class="field-label">ينتهي في {{ form.exception ? '(إلزامي للاستثناء)' : '(اختياري)' }}</label>
                                <input id="grant-until" v-model="form.valid_until" type="date" :min="form.valid_from || today" class="field" />
                            </div>
                            <div class="sm:col-span-2">
                                <label for="grant-reason" class="field-label">السبب ومرجع التكليف</label>
                                <input id="grant-reason" v-model="form.reason" class="field" maxlength="1000" placeholder="مثال: تكليف إداري رقم ..." />
                            </div>
                        </div>
                        <label v-if="form.kind === 'specialization'" class="flex items-center gap-2 text-sm"><input v-model="form.is_primary" type="checkbox" />اختصاص أساسي</label>

                        <div v-if="preview" class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm" role="status" aria-live="polite">
                            <p v-if="preview.error" class="font-semibold text-red-700">{{ preview.error }}</p>
                            <template v-else>
                                <p><strong>ما سيُضاف:</strong> {{ preview.adds.map(a => a.label + (a.sensitive ? ' (حساسة)' : '')).join('، ') || 'لا جديد — يملكها من قبل' }}</p>
                                <div v-if="preview.conflicts.length" class="text-gold-900">
                                    <strong>تعارض مع فصل المهام:</strong>
                                    <ul class="list-disc pr-5"><li v-for="(pair, i) in preview.conflicts" :key="i">{{ pair[0] }} + {{ pair[1] }}</li></ul>
                                    <label class="mt-2 flex items-start gap-2"><input v-model="form.exception" type="checkbox" class="mt-1" /><span>أطلبه استثناءً مؤقتًا (حتى {{ exceptionMaxDays }} يومًا) بقرار المدير التنفيذي</span></label>
                                </div>
                                <p v-if="preview.needsApproval" class="font-semibold text-navy-800">يُرسل إلى المدير التنفيذي ولا يسري قبل قراره.</p>
                                <p v-else class="text-green-800">يسري فور الحفظ، ويُشعَر الموظف ويُسجَّل في سجل النظام.</p>
                            </template>
                        </div>

                        <button type="button" class="btn btn-primary"
                            :disabled="busy || !form.code || form.reason.trim().length < 5 || preview?.error || (preview?.conflicts?.length && (!form.exception || !form.valid_until))"
                            @click="submit">{{ preview?.needsApproval ? 'إرسال للمدير التنفيذي' : 'حفظ الإسناد' }}</button>
                    </section>
                </div>
            </div>

            <details class="card text-sm">
                <summary class="cursor-pointer card-title">مصفوفة فصل المهام في المعاملة الواحدة</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="data-table min-w-full">
                        <thead><tr><th scope="col">المرحلة</th><th scope="col">الخطوة</th><th scope="col">لا يتولاها من تولّى</th></tr></thead>
                        <tbody><tr v-for="row in sod" :key="row[0]"><td>{{ row[0] }}</td><td>{{ row[1] }}</td><td>{{ row[2] }}</td></tr></tbody>
                    </table>
                </div>
            </details>
        </div>
    </AppLayout>
</template>
