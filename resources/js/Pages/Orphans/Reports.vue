<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import DistributionBars from '../../Components/Registry/DistributionBars.vue';
import ImportIcon from '../../Components/Registry/ImportIcon.vue';
import DecisionDialog from '../../Components/DecisionDialog.vue';
import { appliedRegistryFilters, reportExportUrl, reportFiltersChanged } from '../../Support/reportContext.js';

const props = defineProps({ filters: Object, data: Object, donor: Object, options: Object, campaigns: Array, sponsors: Array, abilities: Object, importUrl: String });
const f = ref({ governorate: '', age_band: '', class: '', sex: '', from: '', to: '', campaign: '', sponsor: '', ...props.filters });
watch(() => props.filters, filters => { f.value = { governorate: '', age_band: '', class: '', sex: '', from: '', to: '', campaign: '', sponsor: '', ...filters }; });
const busy = ref(false);
const pendingFilters = computed(() => reportFiltersChanged(f.value, props.filters));
const apply = () => {
    if (busy.value) return;
    busy.value = true;
    router.get('/orphans/reports', Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '' && v != null)), { preserveScroll: true, onFinish: () => { busy.value = false; } });
};
const exportDecision = ref(null);
function download(report, format) {
    if (report === 'registry') {
        exportDecision.value = { title: 'تصدير قائمة الأيتام', description: 'سيُصدّر السجل وفق الفلاتر المطبقة على التقرير المعروض. يُسجّل غرض التصدير في سجل التدقيق.', required: true, confirmLabel: 'تصدير القائمة', report, format };
    } else window.location.href = reportExportUrl(props.filters, report, format);
}
function confirmExport(purpose) {
    if (!purpose.trim() || !exportDecision.value) return;
    window.location.href = reportExportUrl(props.filters, exportDecision.value.report, exportDecision.value.format, purpose);
    exportDecision.value = null;
}
const bars = list => list.map(r => ({ key: String(r.k ?? r.key ?? r.label), label: r.label, count: Number(r.n ?? r.count), filter: r.filter || {} }));
const pct = v => v === null || v === undefined ? 'غير متاح' : `${v}%`;
const qualityLabels = { valid_id: 'هوية صحيحة الصيغة', dob: 'تاريخ الميلاد', sex: 'الجنس', address: 'العنوان الحالي', both_parents: 'الأب والأم مربوطان', approved_guardian: 'وصاية معتمدة' };
const linkLabels = { claimed: 'مُدّعاة', pending: 'بانتظار التحقق', approved: 'معتمدة', rejected: 'مرفوضة', ended: 'منتهية' };
const exportOpen = ref(false);
const registryContext = computed(() => appliedRegistryFilters(props.filters));
</script>

<template>
    <AppLayout title="التقارير والإحصائيات">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader eyebrow="شؤون الأيتام والكفالات" title="التقارير والإحصائيات" description="أرقام حقيقية من السجل؛ المستفيدون الفريدون وعمليات التسليم والوحدات تُعرض منفصلة، والكفالة التزام لا قبض، والاعتماد ليس تسليمًا.">
                <div v-if="abilities.export" class="relative"><button type="button" class="btn btn-primary" :aria-expanded="exportOpen" @click="exportOpen = !exportOpen">تصدير</button>
                    <div v-if="exportOpen" class="absolute end-0 z-20 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-lg" @click="exportOpen = false">
                        <button type="button" class="block w-full rounded-lg px-3 py-2 text-start hover:bg-slate-50" @click="download('summary', 'xlsx')">الملخص (Excel)</button>
                        <button type="button" class="block w-full rounded-lg px-3 py-2 text-start hover:bg-slate-50" @click="download('summary', 'csv')">الملخص (CSV)</button>
                        <button type="button" class="block w-full rounded-lg px-3 py-2 text-start hover:bg-slate-50" @click="download('donor', 'xlsx')">تقرير المانح المجمّع (Excel)</button>
                        <button type="button" class="block w-full rounded-lg px-3 py-2 text-start hover:bg-slate-50" @click="download('registry', 'xlsx')">قائمة الأيتام المفلترة (بيانات شخصية، Excel)</button>
                        <button type="button" class="block w-full rounded-lg px-3 py-2 text-start hover:bg-slate-50" onclick="window.print()">طباعة</button></div></div>
                <Link v-if="abilities.import" :href="importUrl" class="btn btn-secondary"><ImportIcon /> الاستيراد الذكي</Link>
            </PageHeader>
            <form class="card grid gap-3 sm:grid-cols-4" :aria-busy="busy" @submit.prevent="apply">
                <label class="text-sm">المحافظة<select v-model="f.governorate" class="field mt-1"><option value="">الكل</option><option v-for="g in options.governorates" :key="g.id" :value="g.id">{{ g.name_ar }}</option><option value="unknown">غير محددة</option></select></label>
                <label class="text-sm">الفئة العمرية<select v-model="f.age_band" class="field mt-1"><option value="">الكل</option><option v-for="b in options.age_bands" :key="b.id" :value="b.id">{{ b.name }}</option></select></label>
                <label class="text-sm">فئة اليتم<select v-model="f.class" class="field mt-1"><option value="">الكل</option><option v-for="c in options.classes" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                <label class="text-sm">الجنس<select v-model="f.sex" class="field mt-1"><option value="">الكل</option><option value="male">ذكر</option><option value="female">أنثى</option><option value="unknown">غير محدد</option></select></label>
                <label class="text-sm">من<input v-model="f.from" type="date" class="field mt-1"></label><label class="text-sm">إلى<input v-model="f.to" type="date" class="field mt-1"></label>
                <label class="text-sm">تقرير مانح: حملة<select v-model="f.campaign" class="field mt-1"><option value="">—</option><option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.reference_no }}</option></select></label>
                <label class="text-sm">أو كافل<select v-model="f.sponsor" class="field mt-1"><option value="">—</option><option v-for="s in sponsors" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-4"><button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'جارٍ تحديث التقرير…' : 'تطبيق الفلاتر' }}</button><p v-if="pendingFilters" class="text-sm text-gold-800" role="status">لديك تغييرات لم تُطبق؛ الأرقام والتصدير يعكسان التقرير المعروض حاليًا.</p></div>
            </form>
            <p class="text-sm text-slate-600">نطاق التقرير المعروض: {{ filters.from || 'من بداية السجل' }} — {{ filters.to || 'حتى آخر البيانات المتاحة' }}. المصدر: سجلات النظام؛ التسليمات المؤكدة منفصلة عن الترشيحات.</p>

            <section class="grid gap-3 sm:grid-cols-4">
                <div class="card"><p class="text-sm text-slate-600">ملفات الأيتام القائمة</p><p class="mt-1 text-2xl font-bold text-navy-800 tabular-nums">{{ data.registry.orphans }}</p><p class="text-xs text-slate-500">معتمد {{ data.registry.approved }} · معتمد مبدئيًا {{ data.registry.provisional }} · مكتمل {{ data.registry.complete }}</p></div>
                <div class="card"><p class="text-sm text-slate-600">الأوصياء النشطون — على مستوى القسم</p><p class="mt-1 text-2xl font-bold text-navy-800 tabular-nums">{{ data.registry.guardians }}</p><p class="text-xs text-slate-500"><span v-for="(n, s) in data.registry.links" :key="s" class="me-2">{{ linkLabels[s] }} {{ n }}</span></p></div>
                <div class="card"><p class="text-sm text-slate-600">الأيتام المكفولون — على مستوى القسم</p><p class="mt-1 text-2xl font-bold text-navy-800 tabular-nums">{{ data.sponsorship.orphans }}</p><p class="text-xs text-slate-500"><template v-if="!Object.keys(registryContext).length">التغطية {{ pct(data.sponsorship.rate) }} · </template>أسر {{ data.sponsorship.households }} · كفالات {{ data.sponsorship.sponsorships }}</p><p v-if="Object.keys(registryContext).length" class="mt-1 text-xs text-slate-500">لا تُحسب نسبة تغطية لهذه الفلاتر؛ عدد المكفولين المعروض عام.</p></div>
                <div class="card"><p class="text-sm text-slate-600">الاستفادة المؤكدة في الفترة</p><p class="mt-1 text-2xl font-bold text-navy-800 tabular-nums">{{ data.benefits.unique_orphans }}</p>
                    <p class="text-xs text-slate-500">يتيم فريد · أسر {{ data.benefits.unique_households }} · عمليات {{ data.benefits.transactions }} · وحدات {{ data.benefits.units }}</p></div>
            </section>
            <section class="grid gap-4 lg:grid-cols-2">
                <DistributionBars title="التوزيع الجغرافي" :filters="registryContext" :rows="bars(data.distribution.geography.map(g => ({ ...g, filter: { governorate: g.k ?? 'unknown' } })))" definition="عدد الملفات القائمة ضمن فلاتر السجل، حسب محافظة العنوان الحالي." />
                <DistributionBars title="التوزيع العمري العام للقسم" :rows="data.distribution.age" definition="عدد الملفات على مستوى القسم، حسب الفئات العمرية في ثوابت النظام؛ لا يتغير بفلاتر هذا التقرير." />
                <DistributionBars title="حسب فئة اليتم" :filters="registryContext" :rows="bars(data.distribution.class.map(g => ({ ...g, filter: { class: g.k ?? 'unknown' } })))" definition="عدد الملفات ضمن فلاتر السجل حسب فئة اليتم المسجلة." />
                <div class="card space-y-2"><h3 class="card-title">جودة البيانات (من الملفات المفلترة)</h3>
                    <ul class="space-y-1 text-sm"><li v-for="(v, k) in data.quality" :key="k" class="flex justify-between"><span>{{ qualityLabels[k] }}</span><span class="tabular-nums">{{ pct(v) }}</span></li></ul></div>
            </section>
            <section class="card space-y-2"><h3 class="card-title">الحملات: المرشحون والمعتمدون والمسلّم والمخزون</h3>
                <div class="overflow-x-auto"><table class="data-table min-w-[900px]"><thead><tr><th scope="col">الحملة</th><th scope="col">المرشحون (آخر محاكاة)</th><th scope="col">المعتمد</th><th scope="col">مستفيدون سُلّموا</th><th scope="col">عمليات التسليم</th><th scope="col">الوحدات المسلمة</th><th scope="col">المصروف/المرتجع/الفرق</th></tr></thead>
                    <tbody><tr v-for="c in data.campaigns" :key="c.public_id"><td><Link :href="`/orphans/campaigns/${c.public_id}`" class="text-blue-700 hover:underline">{{ c.title }}</Link></td>
                        <td class="text-xs">أساسي {{ c.candidates.primary || 0 }} · احتياط {{ c.candidates.reserve || 0 }} · مراجعة {{ c.candidates.review || 0 }}</td><td class="tabular-nums">{{ c.approved ?? '—' }}</td>
                        <td class="tabular-nums">{{ c.delivered_units }}</td><td class="tabular-nums">{{ c.delivery_transactions }}</td><td class="tabular-nums">{{ c.delivered_quantity }}</td>
                        <td class="tabular-nums" :class="c.variance ? 'text-red-700' : ''">{{ c.issued }} / {{ c.returned }} / {{ c.variance }}</td></tr>
                        <tr v-if="!data.campaigns.length"><td colspan="7" class="text-slate-500">لا توجد حملات في الفترة.</td></tr></tbody></table></div></section>
            <section class="grid gap-4 lg:grid-cols-2">
                <div class="card space-y-1 text-sm"><h3 class="card-title">دورة مراجعة طلبات البوابة</h3><p>المقدمة {{ data.reviews.submitted }} · المبتوت فيها {{ data.reviews.decided }}</p>
                    <p>متوسط زمن القرار: {{ data.reviews.avg_days ?? 'غير متاح' }} يوم · مفتوحة لأكثر من 14 يومًا: {{ data.reviews.open_over_14_days }}</p></div>
                <div v-if="donor" class="card space-y-1 text-sm"><h3 class="card-title">تقرير المانح (مجمّع دون بيانات شخصية)</h3>
                    <p>مستفيدون فريدون: {{ donor.unique_beneficiaries }} · عمليات تسليم: {{ donor.transactions }} · وحدات: {{ donor.units }}</p>
                    <p v-if="donor.sponsored_orphans !== null">أيتام مكفولون حاليًا: {{ donor.sponsored_orphans }}</p>
                    <ul class="mt-1"><li v-for="a in donor.by_area" :key="a.area">{{ a.area }}: {{ a.beneficiaries }} مستفيد / {{ Number(a.units) }} وحدة</li></ul></div>
            </section>
            <section v-if="data.workload.length" class="card space-y-2"><h3 class="card-title">أعباء العمل والتأخير</h3>
                <table class="data-table"><thead><tr><th scope="col">الموظف</th><th scope="col">طلبات مفتوحة</th><th scope="col">ملفات مسندة</th><th scope="col">زيارات مجدولة</th><th scope="col">متأخرة</th></tr></thead>
                    <tbody><tr v-for="w in data.workload" :key="w.id"><td>{{ w.name }}</td><td>{{ w.applications_open }}</td><td>{{ w.files }}</td><td>{{ w.visits_scheduled }}</td><td>{{ w.overdue }}</td></tr></tbody></table></section>
        </div>
        <DecisionDialog :decision="exportDecision" @close="exportDecision = null" @confirm="confirmExport" />
    </AppLayout>
</template>
