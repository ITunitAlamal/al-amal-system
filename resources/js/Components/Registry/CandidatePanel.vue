<script setup>
import AppDialog from '../AppDialog.vue';
import AsyncState from '../AsyncState.vue';
import { useRemotePreview } from '../../Support/useRemotePreview.js';

// Right-hand explanation of one nomination: score contributions, the frozen facts used, reasons and benefit history.
const props = defineProps({ campaign: String, candidate: { type: Number, default: null } });
const emit = defineEmits(['close']);
const { data, failure, loading, retry } = useRemotePreview(() => props.candidate ? `/orphans/campaigns/${props.campaign}/candidates/${props.candidate}` : null);
const yes = v => v === true ? 'نعم' : v === false ? 'لا' : 'غير متوفر';
</script>

<template>
    <AppDialog :open="!!candidate" :title="data?.beneficiary || 'تفسير الترشيح'" description="أسباب الترشيح والبيانات المستخدمة في المحاكاة؛ الترشيح لا يثبت تسلّم المساعدة." drawer @close="emit('close')">
        <div class="text-sm">
            <AsyncState :loading="loading" :error="failure" @retry="retry" />
            <template v-if="data">
                <p class="mt-2">النتيجة: <strong>{{ data.outcome }}</strong><span v-if="data.score !== null"> · الدرجة {{ data.score }}</span><span v-if="data.rank"> · الترتيب {{ data.rank }}</span></p>
                <ul class="mt-2 list-disc space-y-1 ps-5"><li v-for="(r, i) in data.reasons" :key="i">{{ r }}</li></ul>
                <section v-if="data.dimensions?.length" class="mt-3"><h3 class="font-semibold text-navy-800">المجموع حسب البعد</h3>
                    <table class="data-table mt-1"><thead><tr><th scope="col">البعد</th><th scope="col">الوزن</th><th scope="col">النقاط</th></tr></thead>
                        <tbody><tr v-for="d in data.dimensions" :key="d.key"><td>{{ d.label }}<span v-if="d.missing" class="block text-xs text-gold-800">فيه بيانات ناقصة</span></td>
                            <td class="tabular-nums">{{ d.weight }}</td><td class="tabular-nums">{{ d.points }}</td></tr></tbody></table></section>
                <section v-if="data.contributions?.length" class="mt-3"><h3 class="font-semibold text-navy-800">مساهمة كل معيار</h3>
                    <table class="data-table mt-1"><thead><tr><th scope="col">المعيار</th><th scope="col">الوزن</th><th scope="col">القيمة</th><th scope="col">النقاط</th></tr></thead>
                        <tbody><tr v-for="c in data.contributions" :key="c.key"><td>{{ c.label }}<span class="block text-xs text-slate-500">{{ c.source }}</span></td><td class="tabular-nums">{{ c.weight }}</td>
                            <td class="tabular-nums">{{ c.value === null ? 'ناقص' : c.value.toFixed(2) }}</td><td class="tabular-nums">{{ c.points ?? '—' }}</td></tr></tbody></table></section>
                <p v-if="data.missing?.length" class="alert-warning mt-3">بيانات ناقصة: {{ data.missing.join('، ') }}</p>
                <section class="mt-3"><h3 class="font-semibold text-navy-800">الحقائق المستخدمة (لقطة ثابتة)</h3>
                    <dl class="mt-1 grid grid-cols-2 gap-1">
                        <dt class="text-slate-500">العمر</dt><dd>{{ data.snapshot.age ?? 'غير متوفر' }}</dd><dt class="text-slate-500">نازح</dt><dd>{{ yes(data.snapshot.displaced) }}</dd>
                        <dt class="text-slate-500">وصاية معتمدة</dt><dd>{{ yes(data.snapshot.approved_guardian) }}</dd><dt class="text-slate-500">مكفول</dt><dd>{{ yes(data.snapshot.sponsored) }}</dd>
                        <dt class="text-slate-500">آخر استفادة</dt><dd>{{ data.snapshot.last_benefit || 'لا يوجد' }}</dd>
                        <dt class="text-slate-500">التقييم الاقتصادي</dt><dd>{{ data.snapshot.economic ? `${data.snapshot.economic.owner} · ${data.snapshot.economic.as_of} · ${data.snapshot.economic.verification === 'verified' ? 'متحقق' : 'مُفاد'}` : 'غير متوفر' }}</dd>
                        <dt class="text-slate-500">وقت اللقطة</dt><dd dir="ltr" class="text-end">{{ data.snapshot.taken_at?.slice(0, 16) }}</dd></dl>
                    <p class="mt-1 text-xs text-slate-400">إصدار القواعد {{ data.rule_version }} · <span class="font-mono" dir="ltr">{{ data.input_hash.slice(0, 12) }}</span></p></section>
                <section v-if="data.duplicates.length" class="mt-3"><h3 class="font-semibold text-gold-800">تنبيهات تكرار (لا تستبعد تلقائيًا)</h3><ul class="mt-1 space-y-1"><li v-for="(d, i) in data.duplicates" :key="i">{{ d.detail }}</li></ul></section>
                <section class="mt-3"><h3 class="font-semibold text-navy-800">آخر الاستفادات المؤكدة</h3>
                    <ul class="mt-1 space-y-1"><li v-for="b in data.benefits" :key="b.public_id">{{ b.delivered_on }} · {{ b.description }}</li></ul><p v-if="!data.benefits.length" class="text-slate-500">لا توجد.</p></section>
            </template>
        </div>
    </AppDialog>
</template>
