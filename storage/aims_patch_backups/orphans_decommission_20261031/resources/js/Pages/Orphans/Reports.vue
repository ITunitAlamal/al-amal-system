<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';

// «التقارير والإحصائيات»: both tiers' counts side by side, each with its denominator (never added together), and the
// distribution of the staged rows by one classification with drill-down to «الأيتام والأوصياء». Counts are queried
// live within the viewer's permissions.
const props = defineProps({ ready: Boolean, batch: Object, staged: Object, approved: Object, can: Object, categoryFields: Array, severities: Object });
const childStatus = { applicant: 'متقدم', pending_activation: 'بانتظار التفعيل', active: 'نشط', inactive: 'غير نشط', closed: 'مغلق' };
const approvedChildren = computed(() => Object.values(props.approved.children || {}).reduce((n, v) => n + Number(v), 0));
const field = ref('governorate');
const dist = ref(null);
const busy = ref(false);
const error = ref('');
const message = ref('');
const sexLabel = s => ({ male: 'ذكر', female: 'أنثى' }[s] || 'غير محدد');
const idStatusLabels = { format_ok: 'صيغة 9 أرقام', missing: 'فارغة', short: 'أقصر من 9', short_numeric: '8 أرقام (قد يكون صفر البداية سقط)', long: 'أطول من 9', not_digits: 'غير رقمية' };
// Drill-down: the matching filter of «الأيتام والأوصياء».
const drillKey = { governorate: 'governorate', city: 'city', guardian_relation: 'relation', school_grade: 'grade', academic_performance: 'performance', health_status: 'health', child_sex: 'sex', is_displaced: 'displaced', child_id_status: 'id_status', mother_status: 'mother_status' };
function drillUrl(raw) {
    const key = drillKey[field.value];
    if (!key) return null;
    const value = field.value === 'child_sex' ? (raw || 'missing') : field.value === 'is_displaced' ? (raw === true ? 'yes' : raw === false ? 'no' : 'missing') : (raw ?? '__missing');
    return `/orphans/records?view=children&${new URLSearchParams({ [key]: value })}`;
}
function text(v) {
    if (field.value === 'child_sex') return sexLabel(v.raw);
    if (field.value === 'child_id_status') return idStatusLabels[v.raw] || v.raw;
    return v.value ?? 'غير محدد';
}
function errorText(e) { return Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر إتمام العملية'; }
async function load() {
    if (!props.batch) return;
    busy.value = true; error.value = '';
    try { dist.value = (await axios.get('/orphans/staging/data/categories', { params: { batch: props.batch.public_id, field: field.value } })).data; }
    catch (e) { dist.value = null; error.value = errorText(e); } finally { busy.value = false; }
}
async function setAlias(row, valueId) {
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post('/orphans/staging/aliases', { field: field.value, source_key: row.raw, value_id: valueId || null }); message.value = 'حُفظ الربط بثوابت النظام؛ القيمة الأصلية محفوظة كما هي.'; await load(); }
    catch (e) { error.value = errorText(e); } finally { busy.value = false; }
}
watch(field, load);
onMounted(load);
</script>

<template>
    <AppLayout title="التقارير والإحصائيات">
        <div class="space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="التقارير والإحصائيات" description="أعداد السجل المعتمد وسجلات الكشف المستورد منفصلة؛ لكل عدد تعريفه، ولا تُجمع الفئتان معًا.">
                <Link href="/orphans/records" class="btn btn-secondary btn-sm">قوائم مفصلة وتصدير</Link>
            </PageHeader>
            <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
            <div v-if="message" class="alert-success" role="status">{{ message }}</div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="card space-y-3" aria-labelledby="approved-title">
                    <h2 id="approved-title" class="card-title">السجل المعتمد</h2>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">أطفال في السجل المعتمد</dt><dd class="text-2xl font-bold tabular-nums">{{ approvedChildren }}</dd>
                            <dd class="text-xs text-slate-500">{{ Object.entries(approved.children || {}).map(([s, n]) => `${childStatus[s] || s}: ${n}`).join(' · ') || 'لا توجد ملفات بعد' }}</dd></div>
                        <div><dt class="text-slate-500">ملفات الأسر</dt><dd class="text-2xl font-bold tabular-nums">{{ approved.households }}</dd></div>
                        <div><dt class="text-slate-500">علاقات وصاية معتمدة نافذة</dt><dd class="text-2xl font-bold tabular-nums">{{ approved.guardianships }}</dd><dd class="text-xs text-slate-500">علاقة لكل طفل مع وصيه</dd></div>
                        <div><dt class="text-slate-500">كفالات نشطة</dt><dd class="text-2xl font-bold tabular-nums">{{ approved.sponsorships ?? 'غير متوفر' }}</dd></div>
                    </dl>
                </section>
                <section class="card space-y-3" aria-labelledby="staged-title">
                    <h2 id="staged-title" class="card-title">سجلات الكشف المستورد — غير معتمدة</h2>
                    <EmptyState v-if="!staged" title="لا توجد دفعة مستوردة" />
                    <dl v-else class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">صفوف الكشف</dt><dd class="text-2xl font-bold tabular-nums">{{ staged.source_rows }}</dd><dd class="text-xs text-slate-500">كل صف في الدفعة «{{ batch.file_name }}»</dd></div>
                        <div><dt class="text-slate-500">أطفال مميزون بالهوية</dt><dd class="text-2xl font-bold tabular-nums">{{ staged.provisional_children }}</dd><dd class="text-xs text-slate-500">هوية بصيغة صالحة؛ بلا هوية صالحة: {{ staged.rows_without_usable_id }} صف</dd></div>
                        <div><dt class="text-slate-500">أوصياء مدّعون</dt><dd class="text-2xl font-bold tabular-nums">{{ staged.claimed_guardians }}</dd><dd class="text-xs text-slate-500">هوية وصي مميزة؛ ادعاء من المصدر</dd></div>
                        <div><dt class="text-slate-500">أسر مرشحة</dt><dd class="text-2xl font-bold tabular-nums">{{ staged.provisional_households }}</dd><dd class="text-xs text-slate-500">زوج هوية أب وأم؛ ليست أسرة مثبتة</dd></div>
                        <div><dt class="text-slate-500">بانتظار التدقيق</dt><dd class="text-2xl font-bold tabular-nums">{{ staged.pending_review }}</dd><dd class="text-xs text-slate-500">صفوف</dd></div>
                        <div><dt class="text-slate-500">صفوف بملاحظة وصاية مفتوحة</dt><dd class="text-2xl font-bold tabular-nums">{{ staged.guardianship_conflicts }}</dd></div>
                    </dl>
                </section>
            </div>

            <section v-if="batch" class="card space-y-3" aria-labelledby="dist-title">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="dist-title" class="card-title">التوزيع حسب التصنيف — سجلات الكشف</h2>
                    <label><span class="field-label">التصنيف</span><select v-model="field" class="field"><option v-for="f in categoryFields.filter(f => !f.sensitive || can.sensitive)" :key="f.key" :value="f.key">{{ f.label }}</option></select></label>
                </div>
                <p v-if="dist" class="text-xs text-slate-500">{{ dist.denominators }}<template v-if="!dist.master_type"> لا يرتبط هذا الحقل بثوابت النظام.</template><template v-else-if="!dist.options?.length"> لا توجد قيم في «ثوابت النظام» لهذا التصنيف بعد.</template></p>
                <p v-if="busy && !dist" class="text-sm text-slate-500" role="status">جارٍ التحميل...</p>
                <div v-if="dist?.values?.length" class="overflow-x-auto">
                    <table class="data-table min-w-full text-sm">
                        <thead><tr><th scope="col">{{ dist.label }} (كما في الكشف)</th><th scope="col">صفوف</th><th scope="col">أطفال مميزون</th><th scope="col">تمت مراجعتها</th><th scope="col">في ثوابت النظام</th></tr></thead>
                        <tbody><tr v-for="v in dist.values" :key="String(v.raw)">
                            <td><Link v-if="drillUrl(v.raw)" :href="drillUrl(v.raw)" class="text-blue-700 hover:underline">{{ text(v) }}</Link><span v-else>{{ text(v) }}</span></td>
                            <td class="tabular-nums">{{ v.rows }}</td><td class="tabular-nums">{{ v.children }}</td><td class="tabular-nums">{{ v.reviewed }}</td>
                            <td><select v-if="can.imports && dist.options?.length && typeof v.raw === 'string'" class="field py-1 text-sm" :value="dist.options.find(o => o.name_ar === v.alias)?.id || ''" :aria-label="`ربط ${v.value}`" :disabled="busy" @change="setAlias(v, $event.target.value)"><option value="">غير مرتبطة</option><option v-for="o in dist.options" :key="o.id" :value="o.id">{{ o.name_ar }}</option></select><span v-else class="text-xs text-slate-500">{{ v.alias || '—' }}</span></td>
                        </tr></tbody>
                    </table>
                </div>
                <EmptyState v-else-if="dist" title="لا توجد قيم" />
            </section>
        </div>
    </AppLayout>
</template>
