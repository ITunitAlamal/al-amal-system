<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import AppDialog from '../../Components/AppDialog.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ runs: Array, waiting: Array, currencies: Array, canRecord: Boolean, canPublish: Boolean, canSeeLines: Boolean, userId: Number, externalSystem: String, maxRows: Number });

const error = ref('');
const message = ref('');
const busy = ref(false);
const errorText = e => Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر تنفيذ العملية';
const toneOf = state => ({ pending_approval: 'warning', pending_executive: 'warning', approved: 'info', published: 'success', returned: 'danger' }[state] || 'muted');
const lastMonth = (() => { const d = new Date(); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 7); })();

// Recording: upload → map columns → check → record.
const upload = ref(null);
const roles = ref({});
const keyType = ref('employee_number');
const meta = ref({ period: lastMonth, currency: props.currencies?.find(c => c.code === 'ILS')?.code || props.currencies?.[0]?.code || '', source_reference: '' });
const check = ref(null);
const columns = computed(() => upload.value?.columns || []);
const byRole = role => columns.value.filter(c => roles.value[c.index] === role).map(c => c.index);
const mapping = computed(() => ({
    token: upload.value?.token, format: upload.value?.format, sheet: upload.value?.sheet || null, header_row: upload.value?.headerRow,
    key_type: keyType.value, key: byRole('key')[0], net: byRole('net')[0], earnings: byRole('earning'), deductions: byRole('deduction'),
    labels: Object.fromEntries(columns.value.map(c => [c.index, c.label])), file_name: upload.value?.fileName,
}));
const mappingError = computed(() => {
    if (!upload.value) return '';
    if (byRole('key').length !== 1) return 'حدد عمودًا واحدًا لمفتاح الموظف.';
    if (byRole('net').length !== 1) return 'حدد عمودًا واحدًا لصافي الراتب.';
    if (!byRole('earning').length) return 'حدد عمود استحقاق واحدًا على الأقل.';
    return '';
});

function applyInspection(data) {
    upload.value = { ...upload.value, ...data };
    roles.value = Object.fromEntries((data.columns || []).map(c => [c.index, c.suggest]));
    check.value = null;
}
async function sendFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    busy.value = true;
    error.value = '';
    try {
        const body = new FormData();
        body.append('file', file);
        const { data } = await axios.post('/finance/payroll-runs/upload', body);
        upload.value = data;
        applyInspection(data);
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
        event.target.value = '';
    }
}
async function changeSheet() {
    busy.value = true;
    try {
        applyInspection((await axios.post('/finance/payroll-runs/inspect', { token: upload.value.token, format: upload.value.format, sheet: upload.value.sheet })).data);
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}
async function analyze() {
    busy.value = true;
    error.value = '';
    try {
        check.value = (await axios.post('/finance/payroll-runs/analyze', mapping.value)).data;
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}
async function record() {
    busy.value = true;
    error.value = '';
    try {
        message.value = (await axios.post('/finance/payroll-runs', { ...mapping.value, ...meta.value })).data.message;
        upload.value = null;
        check.value = null;
        router.reload({ only: ['runs', 'waiting'] });
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}

// Four steps: record → independent review → executive director's approval → publish. Nobody acts twice in a row.
async function review(run) {
    if (!confirm(`إرسال مسير ${run.period} لاعتماد المدير التنفيذي بعد مراجعته؟`)) return;
    await post(`/finance/payroll-runs/${run.id}/review`, {});
}
async function authorizeRun(run) {
    if (!confirm(`اعتماد مسير ${run.period} (${run.employees} موظفًا)؟`)) return;
    await post(`/finance/payroll-runs/${run.id}/authorize`, {});
}
async function publish(run) {
    if (!confirm(`نشر مسير ${run.period} وإصدار ${run.employees} قسيمة؟ المسير المنشور نهائي.`)) return;
    await post(`/finance/payroll-runs/${run.id}/publish`, {});
}
async function returnRun(run) {
    const reason = prompt('سبب إعادة المسير للتصحيح (5 أحرف على الأقل)') || '';
    if (reason.trim().length < 5) return;
    await post(`/finance/payroll-runs/${run.id}/return`, { reason });
}
async function post(url, body) {
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
        message.value = (await axios.post(url, body)).data.message;
        router.reload({ only: ['runs', 'waiting'] });
    } catch (e) {
        error.value = errorText(e);
    } finally {
        busy.value = false;
    }
}

const linesOf = ref(null);
const lines = ref([]);
async function openLines(run) {
    linesOf.value = run;
    lines.value = [];
    try {
        lines.value = (await axios.get(`/finance/payroll-runs/${run.id}`)).data.lines;
    } catch (e) {
        error.value = errorText(e);
    }
}
</script>

<template>
    <AppLayout title="مسير الرواتب والقسائم">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader eyebrow="قسم الحسابات" title="مسير الرواتب والقسائم"
                :description="`يُدخَل المسير المعتمد في «${externalSystem}» كما هو، ثم يراجعه شخص آخر وينشره فتصدر قسائم الموظفين. لا صرف ولا ترحيل من هنا، والمسير المنشور نهائي؛ التصحيح في مسير لاحق.`" />
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>

            <section v-if="waiting.length" class="alert-warning text-sm" role="status">
                طلبات قسائم بانتظار المسير:
                <span v-for="(w, i) in waiting" :key="w.period">{{ i ? '، ' : '' }}{{ w.period }} ({{ w.count }})</span>
            </section>

            <section class="card space-y-3" aria-labelledby="runs-title">
                <h2 id="runs-title" class="card-title">المسيرات</h2>
                <EmptyState v-if="!runs.length" title="لم يُسجَّل أي مسير رواتب بعد" :description="canRecord ? 'ابدأ بإدخال مسير الشهر المعتمد من الأسفل.' : 'يُدخل مسؤول الرواتب المسير المعتمد، ثم يُراجَع ويُنشر.'" />
                <div v-else class="overflow-x-auto">
                    <table class="data-table min-w-full text-sm">
                        <thead><tr><th scope="col">الشهر</th><th scope="col">العملة</th><th scope="col">الموظفون</th><th scope="col">الاستحقاقات</th><th scope="col">الخصومات</th><th scope="col">الصافي</th><th scope="col">الحالة</th><th scope="col">أدخله</th><th scope="col">نشره</th><th scope="col"><span class="sr-only">إجراءات</span></th></tr></thead>
                        <tbody>
                            <tr v-for="run in runs" :key="run.id">
                                <td dir="ltr" class="text-right">{{ run.period }}</td>
                                <td>{{ run.currency }}</td>
                                <td>{{ run.employees }}</td>
                                <td dir="ltr" class="text-left">{{ run.gross ?? '—' }}</td>
                                <td dir="ltr" class="text-left">{{ run.deductions ?? '—' }}</td>
                                <td dir="ltr" class="text-left font-semibold">{{ run.net }}</td>
                                <td><StatusBadge :label="run.stateLabel" :tone="toneOf(run.state)" /><span v-if="run.returnedReason" class="block text-xs text-red-700">{{ run.returnedReason }}</span></td>
                                <td>{{ run.recordedBy }}</td>
                                <td>{{ run.publishedBy || '—' }}<span v-if="run.reviewedBy" class="block text-xs text-slate-500">راجعه: {{ run.reviewedBy }}</span><span v-if="run.approvedBy" class="block text-xs text-slate-500">اعتمده: {{ run.approvedBy }}</span></td>
                                <td class="whitespace-nowrap">
                                    <button v-if="canSeeLines" type="button" class="text-xs font-semibold text-navy-700 hover:underline" @click="openLines(run)">الأسطر</button>
                                    <span v-if="['pending_approval', 'approved'].includes(run.state) && run.recordedById === userId" class="ms-2 text-xs text-slate-500">أدخلته أنت؛ يراجعه غيرك</span>
                                    <template v-else>
                                        <template v-if="canPublish && run.state === 'pending_approval'">
                                            <button type="button" class="ms-2 text-xs font-semibold text-green-700 hover:underline" :disabled="busy" @click="review(run)">مراجعة وإرسال للاعتماد</button>
                                            <button type="button" class="ms-2 text-xs font-semibold text-red-700 hover:underline" :disabled="busy" @click="returnRun(run)">إعادة</button>
                                        </template>
                                        <template v-if="canAuthorize && run.state === 'pending_executive' && run.reviewedById !== userId">
                                            <button type="button" class="ms-2 text-xs font-semibold text-green-700 hover:underline" :disabled="busy" @click="authorizeRun(run)">اعتماد</button>
                                            <button type="button" class="ms-2 text-xs font-semibold text-red-700 hover:underline" :disabled="busy" @click="returnRun(run)">إعادة</button>
                                        </template>
                                        <button v-if="canPublish && run.state === 'approved'" type="button" class="ms-2 text-xs font-semibold text-green-700 hover:underline" :disabled="busy" @click="publish(run)">نشر القسائم</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!canSeeLines" class="text-xs text-slate-500">تعرض المتابعة الإجماليات فقط؛ رواتب الأفراد لمسؤول الرواتب ومن يراجع المسير.</p>
            </section>

            <section v-if="canRecord" class="card space-y-4" aria-labelledby="record-title">
                <h2 id="record-title" class="card-title">إدخال مسير معتمد</h2>
                <div>
                    <label for="payroll-file" class="field-label">ملف المسير من «{{ externalSystem }}» (xlsx أو csv، حتى {{ maxRows }} سطر)</label>
                    <input id="payroll-file" type="file" accept=".xlsx,.csv" class="field" :disabled="busy" @change="sendFile" />
                </div>

                <template v-if="upload">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div v-if="upload.sheets?.length > 1">
                            <label for="payroll-sheet" class="field-label">الورقة</label>
                            <select id="payroll-sheet" v-model="upload.sheet" class="field" @change="changeSheet"><option v-for="s in upload.sheets" :key="s" :value="s">{{ s }}</option></select>
                        </div>
                        <div>
                            <label for="payroll-key" class="field-label">مفتاح ربط الموظف</label>
                            <select id="payroll-key" v-model="keyType" class="field"><option value="employee_number">الرقم الوظيفي</option><option value="national_id">الرقم الوطني (للربط فقط، لا يُخزَّن في المسير)</option></select>
                        </div>
                        <p class="text-xs leading-5 text-slate-500 sm:col-span-3">صف العناوين: {{ upload.headerRow }}. حدد دور كل عمود؛ الأعمدة المهملة (الاسم، البنك، الهوية…) لا تُخزَّن.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-full text-sm">
                            <thead><tr><th scope="col">العمود في الملف</th><th scope="col">دوره</th></tr></thead>
                            <tbody>
                                <tr v-for="c in columns" :key="c.index">
                                    <td>{{ c.label }}</td>
                                    <td>
                                        <label class="sr-only" :for="`col-${c.index}`">دور العمود {{ c.label }}</label>
                                        <select :id="`col-${c.index}`" v-model="roles[c.index]" class="field py-1 text-sm" @change="check = null">
                                            <option value="ignore">مهمل</option><option value="key">مفتاح الموظف</option><option value="earning">استحقاق</option><option value="deduction">خصم</option><option value="net">صافي الراتب</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="mappingError" class="text-sm font-semibold text-red-700">{{ mappingError }}</p>
                    <button type="button" class="btn btn-secondary" :disabled="busy || !!mappingError" @click="analyze">فحص الملف</button>

                    <div v-if="check" class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm" role="status" aria-live="polite">
                        <p><strong>{{ check.count }}</strong> سطرًا صالحًا · الاستحقاقات {{ check.totals.gross }} · الخصومات {{ check.totals.deductions }} · الصافي <strong>{{ check.totals.net }}</strong></p>
                        <div v-if="check.errorCount" class="text-red-800">
                            <p class="font-semibold">{{ check.errorCount }} سطرًا غير صالح — لا يُسجَّل مسير ناقص. صحّحها في «{{ externalSystem }}» وأعد الرفع:</p>
                            <ul class="mt-1 max-h-56 list-disc overflow-y-auto pr-5"><li v-for="e in check.errors" :key="e.row">السطر {{ e.row }}: {{ e.message }}</li></ul>
                        </div>
                        <div v-else class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="payroll-period" class="field-label">شهر المسير</label>
                                <input id="payroll-period" v-model="meta.period" type="month" class="field" />
                            </div>
                            <div>
                                <label for="payroll-currency" class="field-label">العملة</label>
                                <select id="payroll-currency" v-model="meta.currency" class="field"><option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name_ar }}</option></select>
                            </div>
                            <div>
                                <label for="payroll-ref" class="field-label">مرجع اعتماد المسير في البرنامج</label>
                                <input id="payroll-ref" v-model="meta.source_reference" class="field" maxlength="120" placeholder="رقم المسير أو قرار اعتماده" />
                            </div>
                            <div class="sm:col-span-3">
                                <button type="button" class="btn btn-primary" :disabled="busy || meta.source_reference.trim().length < 3 || !meta.period" @click="record">تسجيل المسير وإرساله للمراجعة</button>
                            </div>
                        </div>
                    </div>
                </template>
            </section>

            <Link href="/finance" class="inline-block text-sm font-semibold text-navy-700 hover:underline">العودة إلى الحسابات</Link>
        </div>

        <AppDialog :open="!!linesOf" drawer :title="linesOf ? `أسطر مسير ${linesOf.period} (${linesOf.currency})` : ''" @close="linesOf = null">
            <div class="overflow-x-auto">
                <table class="data-table min-w-full text-sm">
                    <thead><tr><th scope="col">الرقم الوظيفي</th><th scope="col">الموظف</th><th scope="col">الاستحقاقات</th><th scope="col">الخصومات</th><th scope="col">الصافي</th><th scope="col">القسيمة</th></tr></thead>
                    <tbody>
                        <tr v-for="l in lines" :key="l.id">
                            <td dir="ltr" class="text-right">{{ l.number }}</td><td>{{ l.name }}</td>
                            <td dir="ltr" class="text-left">{{ l.gross }}</td><td dir="ltr" class="text-left">{{ l.deductions }}</td><td dir="ltr" class="text-left font-semibold">{{ l.net }}</td>
                            <td><a v-if="l.slip" :href="`/payslips/${l.slip}`" target="_blank" rel="noopener" class="text-xs font-semibold text-navy-700 hover:underline">{{ l.slip }}</a><span v-else class="text-xs text-slate-500">بعد النشر</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppDialog>
    </AppLayout>
</template>
