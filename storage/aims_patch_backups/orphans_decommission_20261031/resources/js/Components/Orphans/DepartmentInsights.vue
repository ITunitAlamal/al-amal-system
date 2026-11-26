<script setup>
import { computed, ref } from 'vue';
import DistributionChart from './DistributionChart.vue';

const props = defineProps({ insights: { type: Object, required: true } });
const source = ref('approved');
const geography = computed(() => source.value === 'staged' ? props.insights.staged?.geography || [] : props.insights.approved_geography || []);
const total = computed(() => geography.value.reduce((sum, row) => sum + row.count, 0));
const unknown = computed(() => geography.value.find(row => row.label === 'غير محدد')?.count || 0);
const reviews = computed(() => props.insights.staged?.review || []);
const reviewTotal = computed(() => reviews.value.reduce((sum, row) => sum + row.count, 0));
const reviewed = computed(() => reviews.value.find(row => row.key === 'reviewed')?.count || 0);
const progress = computed(() => reviewTotal.value ? Math.round(reviewed.value / reviewTotal.value * 100) : 0);
const number = value => new Intl.NumberFormat('ar').format(value);
</script>

<template>
    <section aria-labelledby="insights-title" class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div><h2 id="insights-title" class="text-lg font-bold text-navy-800">نظرة تساعدك على القرار</h2><p class="text-xs text-slate-500">إجماليات القسم وقت فتح الصفحة، مستقلة عن بحث ملفات الأسر أدناه.</p></div>
            <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800">التوزيع والمتابعة</span>
        </div>
        <div class="grid items-start gap-4 xl:grid-cols-2">
            <section class="card min-w-0 space-y-5 border-t-4 border-t-blue-600" aria-labelledby="geography-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h3 id="geography-title" class="card-title">التوزيع الجغرافي حسب المحافظة</h3><p class="mt-1 text-xs text-slate-500">يساعد على توزيع الزيارات والخدمات ميدانيًا.</p></div>
                    <label class="text-xs text-slate-600">مصدر البيانات<select v-model="source" class="field mt-1"><option value="approved">الأطفال النشطون في السجل المعتمد</option><option v-if="insights.staged" value="staged">سجلات الكشف الأولية</option></select></label>
                </div>
                <div class="flex flex-wrap items-baseline gap-x-2 rounded-xl bg-blue-50 px-4 py-3">
                    <strong class="text-3xl font-bold tabular-nums text-blue-900">{{ number(total) }}</strong><span class="text-sm text-blue-900">{{ source === 'approved' ? 'طفل نشط حسب عنوان الأسرة الحالي' : 'سجل أولي؛ قد تتكرر هوية الطفل' }}</span>
                </div>
                <p v-if="source === 'staged'" class="break-words text-xs text-slate-500">الكشف: {{ insights.staged.batch.file_name }}. المحافظات كما وردت في المصدر؛ اضغط المحافظة لفتح سجلاتها.</p>
                <DistributionChart :rows="geography" :unit="source === 'approved' ? 'طفل' : 'سجل'" />
                <p v-if="unknown" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">{{ number(unknown) }} {{ source === 'approved' ? 'طفل دون محافظة محددة للعنوان الحالي' : 'سجل دون محافظة محددة' }}؛ استكمال العناوين يحسّن تخطيط التغطية الميدانية.</p>
                <p class="text-xs text-slate-500">طول الشريط للمقارنة مع أكبر فئة، والنسبة من إجمالي المصدر المختار.</p>
            </section>
            <div class="min-w-0 space-y-4">
                <section class="card space-y-4 border-t-4 border-t-teal-600" aria-labelledby="review-chart-title">
                    <div class="flex items-start justify-between gap-3"><div><h3 id="review-chart-title" class="card-title">تقدم تدقيق الكشف</h3><p class="mt-1 text-xs text-slate-500">حالة مراجعة السجلات الأولية، ولا تعني اعتماد ملفات الأطفال.</p></div><strong v-if="reviewTotal" class="text-2xl tabular-nums text-teal-700">{{ number(progress) }}٪</strong></div>
                    <template v-if="insights.staged">
                        <p class="break-words text-xs text-slate-500">الكشف: {{ insights.staged.batch.file_name }}</p>
                        <p v-if="reviewTotal" class="text-sm text-teal-800">تمت مراجعة {{ number(reviewed) }} من {{ number(reviewTotal) }} سجل.</p>
                        <DistributionChart :rows="reviews" color="teal" />
                    </template>
                    <p v-else class="py-4 text-sm text-slate-500">لا توجد دفعة مستوردة مكتملة لعرض تقدم التدقيق.</p>
                </section>
                <section v-if="insights.sponsorships !== null" class="card space-y-4 border-t-4 border-t-amber-500" aria-labelledby="sponsorship-chart-title">
                    <div><h3 id="sponsorship-chart-title" class="card-title">حالات الكفالات</h3><p class="mt-1 text-xs text-slate-500">عدد الكفالات حسب حالتها؛ لاستعراض ما ينتظر الاعتماد أو يحتاج استئنافًا.</p></div>
                    <DistributionChart :rows="insights.sponsorships" unit="كفالة" color="amber" />
                    <p class="text-xs text-slate-500">تُستثنى الملغاة والإصدارات المستبدلة. الأعداد لا تمثل عدد الأطفال المكفولين أو دفعات مصروفة.</p>
                </section>
            </div>
        </div>
    </section>
</template>
