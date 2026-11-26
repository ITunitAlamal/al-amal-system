<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import EmptyState from '../../Components/EmptyState.vue';
import AppIcon from '../../Components/AppIcon.vue';

// «استيراد الكشوف»: private upload → dry run (counts only) → approval by another person → staging, resumable.
const props = defineProps({ ready: Boolean, batches: { type: Array, default: () => [] }, sheet: String, columns: Array, issueTypes: Array, userId: Number, maxKb: Number });
const batches = ref(props.batches);
const selectedId = ref(props.batches[0]?.public_id || null);
const selected = computed(() => batches.value.find((b) => b.public_id === selectedId.value) || null);
const file = ref(null);
const supersedes = ref('');
const busy = ref(false);
const error = ref('');
const message = ref('');
const approveNote = ref('');
const approveConfirm = ref(false);
const statusLabels = {
    uploaded: ['بانتظار التحليل', 'info'], analyzing: ['قيد التحليل', 'info'], analyzed: ['بانتظار الاعتماد', 'warning'], blocked: ['موانع في الأعمدة', 'danger'],
    approved: ['معتمد — بانتظار البدء', 'info'], staging: ['قيد الاستيراد المؤقت', 'info'], staged: ['اكتمل الاستيراد المؤقت', 'success'], failed: ['متعثر', 'danger'], cancelled: ['ملغى', 'muted'],
};
const severityLabels = { high: 'عالية', medium: 'متوسطة', low: 'منخفضة', info: 'معلومة' };
const typeLabel = (code) => props.issueTypes.find((t) => t.code === code.split(':')[0])?.label || code;
const reasonLabels = {
    long: 'أطول من 9 أرقام', short: 'أقصر من 9 أرقام', short_numeric: '8 أرقام في خلية رقمية (قد يكون صفر البداية سقط)', not_digits: 'تحتوي غير الأرقام', missing: 'فارغة',
    future_birth: 'ميلاد في المستقبل', father_death_long_before_birth: 'وفاة الأب قبل الميلاد بأكثر من 300 يوم', father_death_after_registration: 'وفاة الأب بعد تاريخ التسجيل',
    text_date_ambiguous: 'تاريخ نصي محتمل الترتيب', text_date_month_first: 'تاريخ نصي بترتيب الشهر أولًا', unreadable_date: 'تاريخ غير مقروء', total_differs: 'العدد الكلي ≠ الذكور + الإناث',
    not_a_count: 'عدد غير رقمي', parent_id_unusable: 'هوية أب أو أم غير صالحة للتجميع', child_in_multiple_households: 'الطفل في أكثر من أسرة',
};
const issueLabel = (key) => { const [type, reason] = key.split(':'); return typeLabel(type) + (reason ? ` — ${reasonLabels[reason] || reason}` : ''); };
const active = computed(() => batches.value.some((b) => ['uploaded', 'analyzing', 'approved', 'staging'].includes(b.status)));
let timer = null;

async function refresh() {
    try {
        const { data } = await axios.get('/orphans/imports/batches');
        batches.value = data.batches;
        if (!selectedId.value && data.batches.length) selectedId.value = data.batches[0].public_id;
    } catch (e) { error.value = e.response?.data?.message || 'تعذر تحديث الدفعات'; }
}
function errorText(e) { return Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر إتمام العملية'; }
async function upload() {
    if (!file.value) return;
    busy.value = true; error.value = ''; message.value = '';
    try {
        const body = new FormData();
        body.append('file', file.value);
        if (supersedes.value) body.append('supersedes_id', supersedes.value);
        const { data } = await axios.post('/orphans/imports', body);
        message.value = 'رُفع الملف إلى التخزين الخاص. يبدأ التحليل تلقائيًا خلال دقيقة، ولا يُكتب أي سجل قبل الاعتماد.';
        file.value = null;
        await refresh();
        selectedId.value = data.public_id;
    } catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
async function act(url, body = {}) {
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post(url, body); message.value = 'تم حفظ الإجراء وتسجيله.'; approveNote.value = ''; approveConfirm.value = false; await refresh(); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
function cancelBatch(b) { const reason = prompt('سبب إلغاء الدفعة (10 أحرف على الأقل)') || ''; if (reason.trim().length >= 10) act(`/orphans/imports/${b.public_id}/cancel`, { reason }); }
const percent = (b) => (b.data_rows ? Math.min(100, Math.round((b.staged_rows / b.data_rows) * 100)) : 0);
const p = computed(() => selected.value?.profile || null);
const populated = (key) => p.value?.populated?.[key] ?? 0;
const sortedIssues = computed(() => Object.entries(p.value?.issues || {}).sort((a, b) => b[1] - a[1]));
onMounted(() => { timer = setInterval(() => { if (active.value) refresh(); }, 10000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <AppLayout title="استيراد الكشوف">
        <div class="space-y-5">
            <Link href="/orphans/staging" class="text-sm font-semibold text-blue-700 hover:underline">→ تدقيق البيانات</Link>
            <PageHeader eyebrow="قسم الكفالات وشؤون الأيتام" title="استيراد الكشوف"
                :description="`يُقرأ من الملف ورقة «${sheet}» فقط. كل ما يُستورد سجلات أولية من المصدر بانتظار التدقيق: لا يثبت يتمًا ولا وصاية ولا يمنح دخول البوابة ولا كفالة ولا صرفًا.`" />

            <div v-if="!ready" class="card space-y-2 border-gold-300" role="status">
                <h2 class="card-title">لم تُفعَّل جداول الاستيراد بعد</h2>
                <p class="text-sm text-slate-600">تُفعَّل بعد موافقة صريحة على خطة الجداول ونسخة احتياطية مُتحقق من استعادتها. حتى ذلك الحين لا يُرفع أي ملف ولا يُكتب أي سجل.</p>
            </div>

            <template v-else>
                <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
                <div v-if="message" class="alert-success" role="status">{{ message }}</div>

                <section class="card space-y-4" aria-labelledby="upload-title">
                    <h2 id="upload-title" class="card-title flex items-center gap-2"><AppIcon name="file-spreadsheet" />رفع كشف جديد</h2>
                    <p class="text-sm text-slate-600">يُحفظ الملف في تخزين خاص غير منشور، ويُرفض الملف نفسه إذا رُفع مرة ثانية (بصمة SHA-256). الحد الأقصى {{ Math.round(maxKb / 1024) }} م.ب.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="field-label">ملف XLSX</span>
                            <input type="file" accept=".xlsx" class="field" @change="file = $event.target.files[0] || null" /></label>
                        <label class="block"><span class="field-label">نسخة معدّلة من دفعة سابقة (اختياري)</span>
                            <select v-model="supersedes" class="field"><option value="">ليست نسخة معدّلة</option><option v-for="b in batches" :key="b.id" :value="b.id">{{ b.file_name }} — {{ b.created_at?.slice(0, 10) }}</option></select></label>
                    </div>
                    <button type="button" class="btn btn-primary" :disabled="busy || !file" @click="upload">رفع الملف</button>
                </section>

                <section class="card min-w-0">
                    <div class="mb-3 flex items-center justify-between gap-2"><h2 class="card-title">دفعات الاستيراد</h2><button type="button" class="btn btn-secondary btn-sm inline-flex items-center gap-1" :disabled="busy" @click="refresh"><AppIcon name="refresh" :size="16" />تحديث</button></div>
                    <EmptyState v-if="!batches.length" title="لا توجد دفعات بعد" description="ارفع الكشف لبدء التحليل التجريبي." />
                    <div v-else class="overflow-x-auto">
                        <table class="data-table min-w-full">
                            <thead><tr><th scope="col">الملف</th><th scope="col">الحالة</th><th scope="col">الصفوف</th><th scope="col">التقدم</th><th scope="col">رفعه / اعتمده</th><th scope="col"><span class="sr-only">إجراءات</span></th></tr></thead>
                            <tbody>
                                <tr v-for="b in batches" :key="b.public_id" :class="b.public_id === selectedId ? 'bg-gold-50' : ''">
                                    <td><button type="button" class="text-right font-semibold text-navy-800 hover:underline" @click="selectedId = b.public_id">{{ b.file_name }}</button><div class="text-xs text-slate-400" dir="ltr">{{ b.sha }}…</div></td>
                                    <td><StatusBadge :label="statusLabels[b.status]?.[0] || b.status" :tone="statusLabels[b.status]?.[1] || 'muted'" /><div v-if="b.failure" class="mt-1 text-xs text-red-700">{{ b.failure }}</div></td>
                                    <td class="tabular-nums">{{ b.data_rows ?? '—' }}</td>
                                    <td class="min-w-36"><div v-if="['staging', 'staged', 'approved', 'failed'].includes(b.status)" class="space-y-1"><div class="h-2 rounded-full bg-slate-100" role="progressbar" :aria-valuenow="percent(b)" aria-valuemin="0" aria-valuemax="100"><div class="h-2 rounded-full bg-navy-600" :style="{ width: percent(b) + '%' }"></div></div><span class="text-xs text-slate-500">{{ b.staged_rows }} / {{ b.data_rows }}</span></div><span v-else class="text-xs text-slate-400">—</span></td>
                                    <td class="text-xs">{{ b.uploader }}<template v-if="b.approver"> / {{ b.approver }}</template></td>
                                    <td><div class="flex flex-wrap gap-1">
                                        <button v-if="b.status === 'failed'" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="act(`/orphans/imports/${b.public_id}/retry`)">استئناف</button>
                                        <button v-if="['uploaded', 'analyzed', 'blocked', 'approved', 'failed'].includes(b.status)" type="button" class="btn btn-secondary btn-sm text-red-700" :disabled="busy" @click="cancelBatch(b)">إلغاء</button>
                                        <Link v-if="b.status === 'staged'" :href="`/orphans/staging?batch=${b.public_id}`" class="btn btn-primary btn-sm">فتح التدقيق</Link>
                                    </div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="active" class="mt-2 text-xs text-slate-500" role="status">يُعالَج في الخلفية ويُحدَّث هنا كل 10 ثوانٍ؛ عند الانقطاع يستأنف من آخر صف محفوظ.</p>
                </section>

                <template v-if="selected">
                    <section v-if="selected.blockers?.length" class="card border-red-200" role="alert">
                        <h2 class="card-title text-red-800">موانع تمنع الاستيراد</h2>
                        <ul class="mt-2 list-disc pr-5 text-sm"><li v-for="(b, i) in selected.blockers" :key="i">{{ b }}</li></ul>
                    </section>

                    <section v-if="p" class="card space-y-4">
                        <div><h2 class="card-title">تقرير التحليل التجريبي</h2><p class="mt-1 text-xs text-slate-500">أعداد فقط بلا أسماء أو هويات. لكل رقم مقامه: صفوف الكشف ≠ هويات الأطفال المميزة ≠ الأسر المرشحة ≠ الأوصياء المدّعون.</p></div>
                        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div v-for="[label, value, note] in [
                                ['صفوف الكشف', p.rows, 'كل صف بيانات'],
                                ['هويات طفل بصيغة 9 أرقام', p.child_id_format_ok, 'صفوف؛ الصيغة ليست تحققًا من السجل المدني'],
                                ['هويات طفل مميزة', p.distinct.child_ids, `مكررة: ${p.distinct.repeated_child_ids} (صفوف إضافية ${p.distinct.extra_rows_for_repeated_ids})`],
                                ['صفوف بلا هوية طفل صالحة', p.rows - p.child_id_format_ok, 'تُراجع يدويًا ولا تُستبعد'],
                                ['أسر مرشحة (هوية الأب + الأم)', p.distinct.households, `صفوف بهويتي والدين صالحتين: ${p.both_parent_ids_ok}`],
                                ['أسر بأكثر من وصي مدّعى', p.distinct.households_multiple_guardians, 'أسرة مرشحة'],
                                ['أوصياء مدّعون مميزون', p.distinct.guardian_ids, 'هوية وصي بصيغة 9 أرقام'],
                                ['أوصياء في أكثر من أسرة', p.distinct.guardians_multiple_households, `بأسماء مختلفة: ${p.distinct.guardians_name_variants}`],
                            ]" :key="label" class="rounded-xl border border-slate-200 p-3">
                                <dt class="text-xs text-slate-500">{{ label }}</dt><dd class="mt-1 font-display text-2xl text-navy-800 tabular-nums">{{ value }}</dd><dd class="text-xs text-slate-400">{{ note }}</dd>
                            </div>
                        </dl>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="overflow-x-auto">
                                <h3 class="mb-2 text-sm font-bold text-navy-800">الملاحظات المتوقعة (عدد)</h3>
                                <table class="data-table min-w-full text-sm"><tbody><tr v-for="[key, n] in sortedIssues" :key="key"><td>{{ issueLabel(key) }}</td><td class="tabular-nums">{{ n }}</td></tr></tbody></table>
                            </div>
                            <div class="space-y-3 text-sm">
                                <h3 class="text-sm font-bold text-navy-800">الجنس وأولوية الصفوف</h3>
                                <p>ذكر {{ p.sex.male }} · أنثى {{ p.sex.female }} · <strong>غير محدد {{ p.sex.missing }}</strong> (لا يُستنتج من الاسم)</p>
                                <ul class="space-y-1"><li v-for="(n, s) in p.severity_rows" :key="s">{{ s === 'none' ? 'بلا ملاحظات' : 'أعلى أولوية ' + (severityLabels[s] || s) }}: <span class="tabular-nums">{{ n }}</span></li></ul>
                                <p class="text-xs text-slate-500">القيم المميزة: {{ Object.entries(p.lookup_distinct || {}).map(([k, n]) => `${columns.find((c) => c.key === k)?.header || k}: ${n}`).join(' · ') }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="selected.header_map" class="card min-w-0 space-y-2">
                        <h2 class="card-title">مطابقة الأعمدة ({{ columns.length }} عمودًا)</h2>
                        <p class="text-xs text-slate-500">تُطابق الأعمدة بعنوانها، ويُقارن الحرف بالمخطط الموثق. الأعمدة المحمية تُخزَّن مشفرة ولا تظهر إلا لحاملي صلاحية البيانات الحساسة.</p>
                        <div class="overflow-x-auto">
                            <table class="data-table min-w-full text-sm">
                                <thead><tr><th scope="col">الحرف</th><th scope="col">العنوان في الملف</th><th scope="col">المعنى في النظام</th><th scope="col">مملوء</th><th scope="col">حماية</th></tr></thead>
                                <tbody><tr v-for="c in columns" :key="c.key">
                                    <td dir="ltr" class="text-right">{{ selected.header_map.map?.[c.key] || '—' }}<span v-if="selected.header_map.map?.[c.key] && selected.header_map.map[c.key] !== c.letter" class="text-red-700"> (متوقع {{ c.letter }})</span></td>
                                    <td>{{ c.header }}</td><td class="text-slate-600">{{ c.note }}</td><td class="tabular-nums">{{ p ? populated(c.key) : '—' }}</td><td>{{ c.protected ? 'مشفّر' : '—' }}</td>
                                </tr></tbody>
                            </table>
                        </div>
                    </section>

                    <section v-if="selected.status === 'analyzed'" class="card space-y-3 border-gold-300">
                        <h2 class="card-title">اعتماد الاستيراد المؤقت</h2>
                        <p class="text-sm text-slate-600">يكتب الاعتماد صفوف الكشف وملاحظاتها في جداول التدقيق المؤقتة فقط، على دفعات قابلة للاستئناف. لا يُنشئ ملف يتيم معتمدًا ولا وصاية ولا حساب بوابة ولا كفالة ولا صرفًا.</p>
                        <p v-if="selected.uploaded_by === userId" class="alert-info text-sm">رفعت هذا الملف بنفسك؛ يعتمده شخص آخر مفوض.</p>
                        <template v-else>
                            <label class="block"><span class="field-label">ملاحظة الاعتماد <span class="text-red-600">*</span></span><textarea v-model="approveNote" rows="2" class="field" placeholder="مثال: راجعت تقرير التحليل وأوافق على الاستيراد المؤقت للتدقيق" /></label>
                            <label class="flex items-start gap-2 text-sm"><input v-model="approveConfirm" type="checkbox" class="mt-1" /><span>أقرّ بأن الصفوف المستوردة ادعاءات من المصدر بانتظار التدقيق، وأنها لا تثبت يتمًا أو وصاية أو استحقاقًا.</span></label>
                            <button type="button" class="btn btn-gold" :disabled="busy || approveNote.trim().length < 10 || !approveConfirm" @click="act(`/orphans/imports/${selected.public_id}/approve`, { note: approveNote, confirm: approveConfirm })">اعتماد الاستيراد المؤقت</button>
                        </template>
                    </section>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
