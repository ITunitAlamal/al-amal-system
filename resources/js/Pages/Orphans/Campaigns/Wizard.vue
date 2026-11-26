<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import FormSteps from '../../../Components/FormSteps.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import { useUnsavedChanges } from '../../../Support/useUnsavedChanges.js';
import DistributionBars from '../../../Components/Registry/DistributionBars.vue';

const props = defineProps({ campaign: Object, step: Number, items: Array, quotas: Array, rules: Object, run: Object, options: Object });
const steps = ['الأساسيات', 'الاستهداف', 'معايير الترشيح', 'المراجعة والمحاكاة'];
const go = n => props.campaign && router.get(`/orphans/campaigns/${props.campaign.public_id}/wizard`, { step: n });
const c = props.campaign || {};
const basics = useForm({ title: c.title || '', description: c.description || '', donor_name: c.donor_name || '', sponsor_id: c.sponsor_id ?? null, aid_type_value_id: c.aid_type_value_id ?? null,
    beneficiary_unit: c.beneficiary_unit || 'orphan', starts_on: c.starts_on || '', ends_on: c.ends_on || '', warehouse_id: c.warehouse_id ?? null, restricted_fund_id: c.restricted_fund_id ?? null,
    manager_user_id: c.manager_user_id ?? null, items: props.items.length ? props.items.map(i => ({ id: i.id, label: i.label, variant: i.variant || '', stock_item_id: i.stock_item_id, per_unit_quantity: Number(i.per_unit_quantity),
        total_quantity: Number(i.total_quantity), criteria: { age_min: i.criteria.age_min ?? null, age_max: i.criteria.age_max ?? null, sex: i.criteria.sex ?? null } })) : [] });
const blankItem = () => ({ id: null, label: '', variant: '', stock_item_id: null, per_unit_quantity: 1, total_quantity: 0, criteria: { age_min: null, age_max: null, sex: null } });
function saveBasics() { props.campaign ? basics.put(`/orphans/campaigns/${props.campaign.public_id}/basics`) : basics.post('/orphans/campaigns'); }
const t = c.targeting || {};
const targeting = useForm({ targeting: { governorates: t.governorates || [], age_min: t.age_min ?? null, age_max: t.age_max ?? null, sex: t.sex || null },
    quotas: props.quotas.map(q => ({ governorate_value_id: q.governorate_value_id, quantity: q.quantity })) });
const r = props.rules || {};
const rules = useForm({ mode: r.mode || 'hybrid', missing_policy: r.missing_policy || 'review', tie_break: r.tie_break || null, reserve_count: r.reserve_count ?? 0, notes: '',
    gates: { classes: r.gates?.classes || [], require_approved_guardian: r.gates?.require_approved_guardian ?? true, active_files_only: r.gates?.active_files_only ?? false,
        displaced_only: r.gates?.displaced_only ?? false, exclude_sponsored: r.gates?.exclude_sponsored ?? false, same_type_window_days: r.gates?.same_type_window_days ?? 90 },
    criteria: r.criteria?.length ? r.criteria.map(x => ({ ...x, map: Object.fromEntries(Object.entries(x.map || {}).filter(([, v]) => v !== null && v !== '')) })) : [] });
const weightSum = computed(() => rules.criteria.reduce((s, x) => s + Number(x.weight || 0), 0));
function toggleCriterion(key) {
    const i = rules.criteria.findIndex(x => x.key === key);
    if (i >= 0) rules.criteria.splice(i, 1); else rules.criteria.push({ key, weight: 0, map: {}, cap: 8, max_age: 18, days: 180 });
}
const mapOptions = key => ({ orphan_class: props.options.classes, current_income_band: props.options.income_bands, father_pre_death_income: props.options.income_bands, needs_level: props.options.needs })[key] || [];
const report = computed(() => props.run?.report);
const bars = obj => Object.entries(obj || {}).map(([label, count]) => ({ key: label, label, count: Number(count), filter: {} }));
const outcomeLabels = { primary: 'الكشف الأساسي', reserve: 'الاحتياط', review: 'للمراجعة', ineligible: 'غير مؤهل', insufficient_priority: 'أولوية غير كافية' };
const simulation = useForm({});
const draft = useForm({ run: props.run?.public_id });
useUnsavedChanges(() => [basics, targeting, rules].some(form => form.isDirty && !form.processing && !form.wasSuccessful));
function simulate() { if (!simulation.processing) simulation.post(`/orphans/campaigns/${props.campaign.public_id}/simulate`); }
function createDraft() { if (!draft.processing) { draft.run = props.run.public_id; draft.post(`/orphans/campaigns/${props.campaign.public_id}/draft`); } }
</script>

<template>
    <AppLayout :title="campaign ? `إعداد الحملة ${campaign.reference_no}` : 'حملة جديدة'">
        <div class="mx-auto max-w-6xl space-y-5">
            <header class="card space-y-3">
                <Link :href="campaign ? `/orphans/campaigns/${campaign.public_id}` : '/orphans/campaigns'" class="text-sm text-blue-700 hover:underline">← {{ campaign ? 'صفحة الحملة' : 'الحملات الإغاثية' }}</Link>
                <h1 class="text-2xl font-bold text-navy-800">{{ campaign ? campaign.title : 'حملة جديدة' }}</h1>
                <FormSteps :steps="steps" :model-value="step - 1" :max-step="campaign ? campaign.wizard_step - 1 : 0" :disabled="basics.processing || targeting.processing || rules.processing" @update:model-value="index => go(index + 1)" />
            </header>

            <form v-if="step === 1" class="card space-y-4" @submit.prevent="saveBasics">
                <h2 class="card-title">أساسيات الحملة ووحدة الاستفادة</h2>
                <FormErrorSummary :errors="basics.errors" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm sm:col-span-2">عنوان الحملة<input v-model="basics.title" class="field mt-1" maxlength="300" required></label>
                    <label class="text-sm">نوع المساعدة<select v-model="basics.aid_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="a in options.aid_types" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                    <label class="text-sm">وحدة الاستفادة<select v-model="basics.beneficiary_unit" class="field mt-1"><option value="orphan">لكل يتيم (مثل الكسوة)</option><option value="household">لكل أسرة (مثل الطرد الغذائي)</option><option value="guardian">لكل وصي</option></select></label>
                    <label class="text-sm">الجهة المانحة<input v-model="basics.donor_name" class="field mt-1" maxlength="300"></label>
                    <label class="text-sm">كافل مسجل (اختياري)<select v-model="basics.sponsor_id" class="field mt-1"><option :value="null">—</option><option v-for="s in options.sponsors" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm">من<input v-model="basics.starts_on" type="date" class="field mt-1"></label><label class="text-sm">إلى<input v-model="basics.ends_on" type="date" class="field mt-1"></label>
                    <label class="text-sm">مخزن المصدر<select v-model="basics.warehouse_id" class="field mt-1"><option :value="null">غير مرتبط بالمخازن</option><option v-for="w in options.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></label>
                    <label class="text-sm">التمويل المخصص<select v-model="basics.restricted_fund_id" class="field mt-1"><option :value="null">—</option><option v-for="f in options.funds" :key="f.id" :value="f.id">{{ f.name }}</option></select></label>
                    <label class="text-sm">مدير الحملة<select v-model="basics.manager_user_id" class="field mt-1"><option :value="null">—</option><option v-for="s in options.staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm sm:col-span-3">الوصف<textarea v-model="basics.description" class="field mt-1" rows="2" maxlength="3000" /></label>
                </div>
                <fieldset class="space-y-2"><legend class="field-label">الأصناف والمقاسات والكميات (يُخصص لكل مستفيد صنف/مقاس واحد مطابق)</legend>
                    <div v-for="(item, i) in basics.items" :key="i" class="grid gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-6">
                        <input v-model="item.label" class="field sm:col-span-2" placeholder="الصنف" required><input v-model="item.variant" class="field" placeholder="المقاس/النوع">
                        <select v-model="item.stock_item_id" class="field" aria-label="الصنف المخزني"><option :value="null">صنف مخزني</option><option v-for="s in options.stock_items" :key="s.id" :value="s.id">{{ s.name }} ({{ s.unit }})</option></select>
                        <input v-model.number="item.per_unit_quantity" type="number" min="0.001" step="0.001" class="field" aria-label="لكل مستفيد"><input v-model.number="item.total_quantity" type="number" min="0" step="0.001" class="field" aria-label="الكمية الكلية">
                        <input v-model.number="item.criteria.age_min" type="number" min="0" class="field" placeholder="عمر من"><input v-model.number="item.criteria.age_max" type="number" min="0" class="field" placeholder="عمر إلى">
                        <select v-model="item.criteria.sex" class="field" aria-label="الجنس"><option :value="null">الجنسان</option><option value="male">ذكور</option><option value="female">إناث</option></select>
                        <button type="button" class="btn btn-sm btn-secondary" @click="basics.items.splice(i, 1)">حذف</button>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" @click="basics.items.push(blankItem())">إضافة صنف أو مقاس</button>
                    <p class="text-xs text-slate-500">لكل صنف: الكمية لكل مستفيد والكمية الكلية. ربط الصنف بالمخزن يتيح فحص التوفر والصرف عبر المخازن.</p>
                </fieldset>
                <p v-for="(e, k) in basics.errors" :key="k" class="field-error">{{ e }}</p>
                <button type="submit" class="btn btn-primary" :disabled="basics.processing">حفظ والمتابعة إلى الاستهداف</button>
            </form>

            <form v-if="step === 2" class="card space-y-4" @submit.prevent="targeting.put(`/orphans/campaigns/${campaign.public_id}/targeting`)">
                <h2 class="card-title">المناطق والفئات المستهدفة</h2>
                <FormErrorSummary :errors="targeting.errors" />
                <fieldset><legend class="field-label">المحافظات المستهدفة (بلا اختيار = الكل)</legend>
                    <div class="flex flex-wrap gap-3"><label v-for="g in options.governorates" :key="g.id" class="flex items-center gap-1 text-sm"><input v-model="targeting.targeting.governorates" type="checkbox" :value="g.id"> {{ g.name_ar }}</label></div></fieldset>
                <div v-if="campaign.beneficiary_unit === 'orphan'" class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">العمر من<input v-model.number="targeting.targeting.age_min" type="number" min="0" max="30" class="field mt-1"></label>
                    <label class="text-sm">العمر إلى<input v-model.number="targeting.targeting.age_max" type="number" min="0" max="30" class="field mt-1"></label>
                    <label class="text-sm">الجنس<select v-model="targeting.targeting.sex" class="field mt-1"><option :value="null">الكل</option><option value="male">ذكور</option><option value="female">إناث</option></select></label>
                </div>
                <fieldset class="space-y-2"><legend class="field-label">حصص جغرافية (اختياري؛ إن وُجدت لا يُختار مستفيد خارجها)</legend>
                    <div v-for="(q, i) in targeting.quotas" :key="i" class="flex flex-wrap gap-2"><select v-model="q.governorate_value_id" class="field max-w-xs" aria-label="المحافظة"><option :value="null">المحافظة</option><option v-for="g in options.governorates" :key="g.id" :value="g.id">{{ g.name_ar }}</option></select>
                        <input v-model.number="q.quantity" type="number" min="0" class="field max-w-40" aria-label="عدد المستفيدين"><button type="button" class="btn btn-sm btn-secondary" @click="targeting.quotas.splice(i, 1)">حذف</button></div>
                    <button type="button" class="btn btn-sm btn-secondary" @click="targeting.quotas.push({ governorate_value_id: null, quantity: 0 })">إضافة حصة</button></fieldset>
                <p v-for="(e, k) in targeting.errors" :key="k" class="field-error">{{ e }}</p>
                <button type="submit" class="btn btn-primary" :disabled="targeting.processing">حفظ والمتابعة إلى المعايير</button>
            </form>

            <form v-if="step === 3" class="card space-y-4" @submit.prevent="rules.put(`/orphans/campaigns/${campaign.public_id}/rules`)">
                <h2 class="card-title">الأهلية وأوزان الأولوية</h2>
                <FormErrorSummary :errors="rules.errors" />
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">نمط الترشيح<select v-model="rules.mode" class="field mt-1"><option value="hybrid">مختلط (افتراضي): شروط ثم نقاط</option><option value="general">عام: شروط الأهلية فقط</option><option value="scored">بالنقاط: ترتيب موزون</option></select></label>
                    <label class="text-sm">معالجة البيانات الناقصة<select v-model="rules.missing_policy" class="field mt-1"><option value="review">إحالة للمراجعة (لا تُعدّ غيابًا للحاجة)</option><option value="neutral">قيمة محايدة مع تنبيه</option></select></label>
                    <label class="text-sm">عدد الاحتياط<input v-model.number="rules.reserve_count" type="number" min="0" class="field mt-1"></label>
                    <label v-if="rules.mode === 'general'" class="text-sm">سياسة الترجيح عند تجاوز الطلب للكمية<select v-model="rules.tie_break" class="field mt-1"><option :value="null">لا توجد (لن يُختار جزء تلقائيًا)</option>
                        <option value="oldest_benefit">الأقدم استفادة أولًا</option><option value="youngest">الأصغر سنًا</option><option value="earliest_registration">الأقدم تسجيلًا</option></select></label>
                </div>
                <fieldset v-if="rules.mode !== 'scored'" class="space-y-2"><legend class="field-label">شروط الأهلية الإلزامية</legend>
                    <div class="flex flex-wrap gap-3 text-sm"><span>فئات اليتم:</span><label v-for="cl in options.classes" :key="cl.id" class="flex items-center gap-1"><input v-model="rules.gates.classes" type="checkbox" :value="cl.id"> {{ cl.name }}</label></div>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-1"><input v-model="rules.gates.active_files_only" type="checkbox"> الملفات المعتمدة فقط</label>
                        <label class="flex items-center gap-1"><input v-model="rules.gates.displaced_only" type="checkbox"> النازحون فقط</label>
                        <label class="flex items-center gap-1"><input v-model="rules.gates.exclude_sponsored" type="checkbox"> استبعاد المكفولين حاليًا</label>
                    </div></fieldset>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-1"><input v-model="rules.gates.require_approved_guardian" type="checkbox"> اشتراط وصاية معتمدة (غير ذلك يُحال للمراجعة)</label>
                    <label class="flex items-center gap-1">نافذة تنبيه تكرار المساعدة المماثلة (أيام)<input v-model.number="rules.gates.same_type_window_days" type="number" min="0" class="field max-w-28"></label>
                </div>
                <fieldset v-if="rules.mode !== 'general'" class="space-y-2"><legend class="field-label">المعايير والأوزان — المجموع: <span :class="Math.abs(weightSum - 100) < 0.001 ? 'text-emerald-700' : 'text-red-700'">{{ weightSum }}</span> / 100</legend>
                    <div v-for="cr in options.criteria" :key="cr.key" class="rounded-xl border border-slate-200 p-3 text-sm">
                        <label class="flex items-center gap-2 font-semibold"><input type="checkbox" :checked="rules.criteria.some(x => x.key === cr.key)" @change="toggleCriterion(cr.key)"> {{ cr.label }}</label>
                        <template v-for="x in rules.criteria.filter(x => x.key === cr.key)" :key="x.key">
                            <div class="mt-2 flex flex-wrap items-center gap-3"><label>الوزن<input v-model.number="x.weight" type="number" min="0" max="100" class="field ms-2 max-w-24"></label>
                                <label v-if="cr.type === 'cap'">الحد الأعلى للمعالين<input v-model.number="x.cap" type="number" min="1" class="field ms-2 max-w-24"></label>
                                <label v-if="cr.type === 'age'">أقصى عمر<input v-model.number="x.max_age" type="number" min="1" class="field ms-2 max-w-24"></label>
                                <label v-if="cr.type === 'days'">عدد الأيام للحد الأقصى<input v-model.number="x.days" type="number" min="1" class="field ms-2 max-w-24"></label></div>
                            <div v-if="cr.type === 'map'" class="mt-2 flex flex-wrap gap-3"><span v-if="!mapOptions(cr.key).length" class="text-xs text-gold-800">لا توجد قيم في «ثوابت النظام» لهذا المعيار.</span>
                                <label v-for="o in mapOptions(cr.key)" :key="o.id" class="text-xs">{{ o.name }}<input v-model.number="x.map[o.id]" type="number" min="0" max="100" class="field mt-1 max-w-24" placeholder="0–100"></label></div>
                        </template>
                    </div></fieldset>
                <p v-for="(e, k) in rules.errors" :key="k" class="field-error">{{ e }}</p>
                <p class="text-xs text-slate-500">تُحفظ القواعد كإصدار ثابت؛ أي تغيير يُنشئ إصدارًا جديدًا. لا تُستخدم التشخيصات الطبية في الترتيب.</p>
                <button type="submit" class="btn btn-primary" :disabled="rules.processing">حفظ المعايير والمتابعة</button>
            </form>

            <section v-if="step === 4" class="space-y-4">
                <div class="card flex flex-wrap items-center gap-3"><p class="min-w-0 flex-1 text-sm text-slate-600">المحاكاة تقرأ البيانات الحالية بالقواعد المحفوظة، ولا تعتمد كشفًا ولا تحجز مخزونًا.</p>
                    <button type="button" class="btn btn-primary" :disabled="simulation.processing || draft.processing" @click="simulate">{{ simulation.processing ? 'جارٍ فحص البيانات وتشغيل المحاكاة…' : 'تشغيل المحاكاة' }}</button></div>
                <FormErrorSummary :errors="simulation.errors" />
                <FormErrorSummary :errors="draft.errors" />
                <template v-if="report">
                    <div class="grid gap-3 sm:grid-cols-5"><div v-for="(label, key) in outcomeLabels" :key="key" class="card"><p class="text-sm text-slate-600">{{ label }}</p><p class="mt-1 text-2xl font-bold text-navy-800 tabular-nums">{{ report.outcomes[key] || 0 }}</p></div></div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <DistributionBars title="الكشف الأساسي حسب المحافظة" :rows="report.regions.map(r => ({ key: r.label, label: r.label, count: Number(r.n), filter: {} }))" definition="عدد المرشحين في الكشف الأساسي لكل محافظة." />
                        <DistributionBars title="الكشف الأساسي حسب الفئة العمرية" :rows="report.ages.map(r => ({ key: r.label, label: r.label, count: Number(r.n), filter: {} }))" definition="بالسنوات الكاملة اليوم." />
                        <DistributionBars v-if="Object.keys(report.scores).length" title="توزيع الدرجات (كل المقيّمين)" :rows="bars(Object.fromEntries(Object.entries(report.scores).map(([k, v]) => [`${k}–${Number(k) + 9}`, v])))" definition="عدد المرشحين في كل شريحة درجات." />
                        <DistributionBars v-if="Object.keys(report.missing).length" title="بيانات ناقصة تمنع الترشيح التلقائي" :rows="bars(report.missing)" definition="عدد المحالين للمراجعة بسبب كل نقص." />
                    </div>
                    <div class="card space-y-2"><h3 class="card-title">جدوى الكميات والمخزون</h3>
                        <table class="data-table"><thead><tr><th scope="col">الصنف</th><th scope="col">المخطط</th><th scope="col">المخصص في الكشف</th><th scope="col">المتاح في المخزن</th></tr></thead>
                            <tbody><tr v-for="(i, k) in report.items" :key="k"><td>{{ i.label }}</td><td class="tabular-nums">{{ i.planned }}</td><td class="tabular-nums">{{ i.allocated }}</td>
                                <td :class="i.available !== null && i.available < i.allocated ? 'text-red-700' : ''" class="tabular-nums">{{ i.available ?? 'غير مرتبط بالمخازن' }}</td></tr></tbody></table>
                        <p class="text-sm text-slate-600">تنبيهات تكرار مساعدة مماثلة: {{ report.duplicates }} · تسليمات مماثلة خلال سنة: {{ report.similar_deliveries_last_year ?? 'غير متاح' }} · إصدار القواعد {{ report.rule_version }}</p>
                        <p v-if="report.change" class="text-sm text-slate-600">مقارنة بالمحاكاة السابقة: +{{ report.change.added }} / −{{ report.change.removed }}{{ report.change.same_input ? ' (المدخلات والقواعد نفسها)' : '' }}</p>
                        <p class="text-xs text-slate-500">بصمة بيانات المحاكاة: <bdi class="font-mono">{{ report.input_hash.slice(0, 16) }}…</bdi></p>
                    </div>
                    <div class="card flex flex-wrap items-center gap-3"><p class="min-w-0 flex-1 text-sm">إنشاء مسودة الكشف من هذه المحاكاة (الأساسي والاحتياط). المسودة تُراجع ثم يرفعها رئيس القسم لاعتماد المدير التنفيذي.</p>
                        <button type="button" class="btn btn-primary" :disabled="draft.processing || simulation.processing" @click="createDraft">{{ draft.processing ? 'جارٍ إنشاء المسودة…' : 'إنشاء مسودة الكشف' }}</button></div>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
