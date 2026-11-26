<script setup>
import { computed, inject } from 'vue';
import Choice from './Choice.vue';
import FieldError from './FieldError.vue';
import Req from './Req.vue';
import { ageOf, blankAid, blankMember, nameOf, regularity, stability } from '../../Support/registration.js';

const rules = inject('fieldRules', null);

// The household is social and residential context only: it never establishes guardianship or portal access.
// Declared totals are kept as reported; mismatches are shown, never corrected silently.
const props = defineProps({ form: Object, options: Object, portal: Boolean });
const h = computed(() => props.form.household);
const e = computed(() => props.form.errors);
const sum = computed(() => ['male_count', 'female_count', 'unknown_sex_count'].reduce((total, key) => total + (Number(h.value[key]) || 0), 0));
const hasSplit = computed(() => ['male_count', 'female_count', 'unknown_sex_count'].some(key => h.value[key] !== '' && h.value[key] !== null));
const mismatch = computed(() => h.value.declared_members !== '' && h.value.declared_members !== null && hasSplit.value && sum.value !== Number(h.value.declared_members));
const tooManyOrphans = computed(() => h.value.declared_members !== '' && h.value.declared_members !== null && props.form.children.length > Number(h.value.declared_members));
function removeMember(i) { if (window.confirm('حذف هذا الفرد من الطلب؟')) h.value.members.splice(i, 1); }
// Income per source: an amount only when known; «لا يوجد» and «غير معروف» are kept as such, never as zero.
const addIncome = () => { h.value.income_items ??= []; h.value.income_items.push({ source_value_id: null, label: '', amount_status: 'unknown', amount: '', currency: 'ILS', period: 'monthly', note: '' }); };
const itemsSum = computed(() => (h.value.income_items || []).filter(i => i.amount_status === 'known' && i.amount !== '' && i.currency === h.value.currency).reduce((t, i) => t + Number(i.amount || 0), 0));
const counts = [['declared_members', 'إجمالي أفراد الأسرة'], ['male_count', 'عدد الذكور'], ['female_count', 'عدد الإناث'], ['unknown_sex_count', 'غير محدد الجنس'],
    ['studying_count', 'عدد الدارسين'], ['employed_count', 'عدد العاملين'], ['earners_count', 'عدد من لهم دخل'], ['dependents_count', 'عدد المُعالين'],
    ['special_needs_count', 'ذوو احتياجات دعم خاصة']];
</script>

<template>
    <section class="card space-y-5">
        <h2 class="card-title" tabindex="-1" data-step-heading>بيانات الأسرة</h2>
        <p class="alert-info">الأسرة سياق سكني واجتماعي فقط، ولا تُثبت الوصاية القانونية ولا تمنح وصولًا للبوابة.</p>
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="text-sm">اسم الأسرة كما يُعرض<input v-model="h.display_name" data-error-field="household.display_name" class="field mt-1" maxlength="200" placeholder="مثال: أسرة فلان"></label>
            <label class="text-sm">تاريخ هذه الإفادة<input v-model="h.assessed_on" type="date" data-error-field="household.assessed_on" class="field mt-1"></label>
            <p class="self-end rounded-lg bg-slate-50 p-2 text-sm">عدد الأيتام في الطلب: <strong>{{ form.children.length }}</strong> <span class="text-xs text-slate-500">(يُحسب تلقائيًا)</span></p>
            <label class="text-sm">رب الأسرة (صلته بالأيتام)<select v-model="h.head_relation_value_id" data-error-field="household.head_relation_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select></label>
            <label class="text-sm">جوال التواصل مع الأسرة<input v-model="h.contact_phone" data-error-field="household.contact_phone" class="field mt-1" dir="ltr" inputmode="tel" maxlength="16"><FieldError :errors="e" name="household.contact_phone" /></label>
            <label v-if="rules?.required('household.contact_unreachable_reason') || h.contact_unreachable_reason" class="text-sm sm:col-span-2">لا يوجد رقم تواصل — سبب تعذر التواصل<Req k="household.contact_unreachable_reason" /><input v-model="h.contact_unreachable_reason" data-error-field="household.contact_unreachable_reason" class="field mt-1" maxlength="300" placeholder="مثال: لا تملك الأسرة جوالًا؛ التواصل عبر زيارة ميدانية"><FieldError :errors="e" name="household.contact_unreachable_reason" /></label>
            <label v-if="!portal" class="text-sm">رقم الملف في النظام القديم (للمرجع)<input v-model="h.legacy_file_no" data-error-field="household.legacy_file_no" class="field mt-1" dir="ltr" maxlength="40"><span class="text-xs text-slate-500">قد يتكرر لأسر مختلفة؛ لا يُستخدم للمطابقة.</span></label>
        </div>

        <fieldset class="space-y-3">
            <legend class="field-label">أعداد الأسرة كما أُفيد بها</legend>
            <p class="text-xs text-slate-500">يُحسب كل من يقيم فعليًا في المسكن الآن، بمن فيهم الأيتام والأم أو الوصي إن أقاما معهم. لا يُحسب الغائب أو المقيم في مكان آخر. «ذوو احتياجات الدعم» عدد فقط، دون تفاصيل صحية.</p>
            <div class="grid gap-3 sm:grid-cols-4">
                <label v-for="[key, label] in counts" :key="key" class="text-sm">{{ label }}<input v-model="h[key]" type="number" min="0" max="200" :data-error-field="`household.${key}`" class="field mt-1" dir="ltr"><FieldError :errors="e" :name="`household.${key}`" /></label>
            </div>
            <p v-if="mismatch" class="alert-warning" role="status">مجموع الذكور والإناث وغير المحدد ({{ sum }}) لا يساوي إجمالي الأفراد ({{ h.declared_members }}). استخدم «غير محدد الجنس» لمن لم يُذكر جنسه.</p>
            <template v-if="tooManyOrphans">
                <p class="alert-warning" role="status">عدد الأيتام ({{ form.children.length }}) أكبر من عدد أفراد الأسرة. صحح العدد أو اكتب سبب الاستثناء.</p>
                <label class="block text-sm">سبب الاستثناء<textarea v-model="h.count_exception_reason" data-error-field="household.count_exception_reason" class="field mt-1" rows="2" maxlength="1000" /></label>
            </template>
        </fieldset>

        <details class="rounded-xl border border-slate-200 p-4" open>
            <summary class="cursor-pointer font-semibold text-navy-800">الوضع المالي للأسرة</summary>
            <div class="mt-3 space-y-3">
                <fieldset class="text-sm" data-error-field="household.income_source_ids"><legend class="mb-1">مصادر الدخل</legend>
                    <div class="flex flex-wrap gap-3"><label v-for="s in options.income_sources" :key="s.id" class="flex items-center gap-1.5"><input v-model="h.income_source_ids" type="checkbox" :value="s.id"> {{ s.name }}</label>
                        <span v-if="!options.income_sources.length" class="text-slate-500">لا توجد قائمة مصادر دخل في «ثوابت النظام» بعد؛ اكتبها في الملاحظة.</span></div></fieldset>
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="text-sm">إجمالي دخل الأسرة<input v-model="h.total_income" type="number" min="0" step="0.01" data-error-field="household.total_income" class="field mt-1" dir="ltr"></label>
                    <label class="text-sm">العملة<select v-model="h.currency" data-error-field="household.currency" class="field mt-1"><option v-for="c in options.currencies" :key="c.code" :value="c.code">{{ c.name }}</option></select><FieldError :errors="e" name="household.currency" /></label>
                    <label class="text-sm">الفترة<select v-model="h.income_period" class="field mt-1"><option value="monthly">شهري</option><option value="weekly">أسبوعي</option><option value="yearly">سنوي</option></select></label>
                    <label class="text-sm">الوضع الاقتصادي كما تصفه الأسرة<select v-model="h.economic_status_value_id" data-error-field="household.economic_status_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="s in options.economic" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label v-if="!portal" class="text-sm">الوضع الاقتصادي حسب تقييم القسم<select v-model="h.assessed_economic_status_value_id" data-error-field="household.assessed_economic_status_value_id" class="field mt-1"><option :value="null">لم يُقيَّم بعد</option><option v-for="s in options.economic" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm">المصاريف الشهرية التقريبية<input v-model="h.expenses_amount" type="number" min="0" step="0.01" data-error-field="household.expenses_amount" class="field mt-1" dir="ltr"></label>
                    <label class="text-sm">الإيجار الشهري (إن وجد)<input v-model="h.rent_amount" type="number" min="0" step="0.01" data-error-field="household.rent_amount" class="field mt-1" dir="ltr"></label>
                    <label class="text-sm">استقرار عمل أفراد الأسرة<select v-model="h.employment_stability" data-error-field="household.employment_stability" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in stability" :key="v" :value="v">{{ t }}</option></select></label>
                    <label class="text-sm sm:col-span-3">مصدر معلومة الدخل وملاحظاتها<input v-model="h.income_note" data-error-field="household.income_note" class="field mt-1" maxlength="500"></label>
                    <label class="text-sm sm:col-span-4">احتياجات أساسية غير ملباة<textarea v-model="h.unmet_needs" data-error-field="household.unmet_needs" class="field mt-1" rows="2" maxlength="1000" /></label>
                </div>
                <fieldset class="space-y-2 rounded-lg border border-slate-200 p-3" data-error-field="household.income_items"><legend class="px-1 text-sm font-semibold">الدخل حسب المصدر (اختياري)</legend>
                    <div v-for="(item, i) in (h.income_items || [])" :key="i" class="grid gap-2 sm:grid-cols-6">
                        <label class="text-sm sm:col-span-2">المصدر<select v-model="item.source_value_id" :data-error-field="`household.income_items.${i}.source_value_id`" class="field mt-1"><option :value="null">أخرى (اكتبها)</option><option v-for="s in options.income_sources" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                        <label v-if="!item.source_value_id" class="text-sm">اسم المصدر<input v-model="item.label" :data-error-field="`household.income_items.${i}.label`" class="field mt-1" maxlength="200"></label>
                        <label class="text-sm">المبلغ<select v-model="item.amount_status" class="field mt-1"><option v-for="(t, v) in options.amount_status" :key="v" :value="v">{{ t }}</option></select></label>
                        <template v-if="item.amount_status === 'known'">
                            <label class="text-sm">القيمة<input v-model="item.amount" type="number" min="0" step="0.01" :data-error-field="`household.income_items.${i}.amount`" class="field mt-1" dir="ltr"><FieldError :errors="e" :name="`household.income_items.${i}.amount`" /></label>
                            <label class="text-sm">العملة<select v-model="item.currency" class="field mt-1"><option :value="null">غير معروفة</option><option v-for="c in options.currencies" :key="c.code" :value="c.code">{{ c.name }}</option></select></label>
                        </template>
                        <button type="button" class="btn btn-sm btn-secondary self-end" @click="h.income_items.splice(i, 1)">حذف</button>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" @click="addIncome">إضافة مصدر دخل</button>
                    <p v-if="itemsSum && h.total_income !== '' && Number(h.total_income) !== itemsSum" class="alert-warning text-sm" role="status">مجموع المصادر المعروفة بالعملة نفسها ({{ itemsSum }}) لا يساوي إجمالي الدخل المُفاد ({{ h.total_income }}). يُحفظ كما هو للمراجعة.</p>
                </fieldset>
                <p class="text-xs text-slate-500">دخل الوصي الشخصي يُدخل في قسم الأوصياء، ولا يُكرر هنا.</p>
            </div>
        </details>

        <details class="rounded-xl border border-slate-200 p-4" :open="h.external_aid === 'yes'">
            <summary class="cursor-pointer font-semibold text-navy-800">مساعدات من جهات أخرى</summary>
            <div class="mt-3 space-y-3">
                <Choice v-model="h.external_aid" label="هل تتلقى الأسرة مساعدات من جهات أخرى؟" name="household.external_aid" :errors="e" />
                <template v-if="h.external_aid === 'yes'">
                    <FieldError :errors="e" name="household.aid" />
                    <div v-for="(aid, i) in h.aid" :key="i" class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-4">
                        <label class="text-sm">المساعدة مخصصة لـ<select v-model="aid.child_key" :data-error-field="`household.aid.${i}.child_key`" class="field mt-1"><option value="">الأسرة كلها</option><option v-for="(c, ci) in form.children" :key="c.key" :value="c.key">{{ nameOf(c) || `اليتيم ${ci + 1}` }}</option></select><FieldError :errors="e" :name="`household.aid.${i}.child_key`" /></label>
                        <label class="text-sm">الجهة<input v-model="aid.organization" :data-error-field="`household.aid.${i}.organization`" class="field mt-1" maxlength="200"><FieldError :errors="e" :name="`household.aid.${i}.organization`" /></label>
                        <label class="text-sm">نوع المساعدة<select v-model="aid.support_type_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="t in options.assistance" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                        <label class="text-sm">وصف النوع<input v-model="aid.support_type_note" class="field mt-1" maxlength="200"></label>
                        <label class="text-sm">الانتظام<select v-model="aid.regularity" class="field mt-1"><option value="">غير محدد</option><option v-for="[v, t] in regularity" :key="v" :value="v">{{ t }}</option></select></label>
                        <label class="text-sm">الدورية<select v-model="aid.frequency_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="f in options.frequencies" :key="f.id" :value="f.id">{{ f.name }}</option></select></label>
                        <label class="text-sm">تاريخ آخر استلام<input v-model="aid.received_on" type="date" :data-error-field="`household.aid.${i}.received_on`" class="field mt-1"></label>
                        <label class="text-sm">القيمة التقريبية<input v-model="aid.amount" type="number" min="0" step="0.01" class="field mt-1" dir="ltr"></label>
                        <label class="text-sm">العملة<select v-model="aid.currency" :data-error-field="`household.aid.${i}.currency`" class="field mt-1"><option v-for="c in options.currencies" :key="c.code" :value="c.code">{{ c.name }}</option></select></label>
                        <label class="text-sm">الكمية (للعينية)<input v-model="aid.quantity" class="field mt-1" maxlength="100"></label>
                        <label class="text-sm sm:col-span-2">ملاحظة أو إثبات<input v-model="aid.note" class="field mt-1" maxlength="500"></label>
                        <button type="button" class="btn btn-sm btn-danger self-end" @click="h.aid.splice(i, 1)">حذف</button>
                    </div>
                    <button v-if="h.aid.length < 10" type="button" class="btn btn-sm btn-secondary" @click="h.aid.push(blankAid())">إضافة جهة</button>
                    <p class="text-xs text-slate-500">هذه إفادة عن مساعدات خارجية، ولا تُسجَّل كاستفادة من الجمعية. مساعدة الأسرة تُسجل مرة واحدة للأسرة، ولا تتكرر لكل يتيم.</p>
                </template>
            </div>
        </details>

        <details class="rounded-xl border border-slate-200 p-4">
            <summary class="cursor-pointer font-semibold text-navy-800">الخدمات وظروف المعيشة</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <label class="text-sm sm:col-span-3">الوصول إلى الخدمات الأساسية (ماء، كهرباء…)<input v-model="h.utilities_access" data-error-field="household.utilities_access" class="field mt-1" maxlength="300"></label>
                <label class="text-sm sm:col-span-3">وصف ظروف المعيشة<textarea v-model="h.living_conditions" data-error-field="household.living_conditions" class="field mt-1" rows="2" maxlength="1000" /></label>
            </div>
        </details>

        <details class="rounded-xl border border-slate-200 p-4" :open="h.members.length > 0">
            <summary class="cursor-pointer font-semibold text-navy-800">أفراد الأسرة الآخرون (اختياري)</summary>
            <p class="mt-2 text-xs text-slate-500">من يسكن مع الأسرة من غير الأيتام والأم والوصي. لا يصبح أي منهم يتيمًا أو مستفيدًا تلقائيًا.</p>
            <div v-for="(m, i) in h.members" :key="i" class="mt-3 grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-4">
                <label class="text-sm">الاسم الأول<input v-model="m.first_name" :data-error-field="`household.members.${i}.first_name`" class="field mt-1" maxlength="100"></label>
                <label class="text-sm">اسم الأب<input v-model="m.father_name" class="field mt-1" maxlength="100"></label>
                <label class="text-sm">اسم الجد<input v-model="m.grandfather_name" class="field mt-1" maxlength="100"></label>
                <label class="text-sm">اسم العائلة<input v-model="m.family_name" :data-error-field="`household.members.${i}.family_name`" class="field mt-1" maxlength="100"></label>
                <label class="text-sm">رقم الهوية<input v-model="m.id_number" :data-error-field="`household.members.${i}.id_number`" class="field mt-1 font-mono" dir="ltr" inputmode="numeric" maxlength="40"><FieldError :errors="e" :name="`household.members.${i}.id_number`" /></label>
                <label class="text-sm">الجنس<select v-model="m.sex" class="field mt-1"><option value="">غير محدد</option><option value="male">ذكر</option><option value="female">أنثى</option></select></label>
                <label class="text-sm">تاريخ الميلاد<input v-model="m.date_of_birth" type="date" :data-error-field="`household.members.${i}.date_of_birth`" class="field mt-1"><span v-if="ageOf(m.date_of_birth)" class="text-xs text-slate-500">العمر: {{ ageOf(m.date_of_birth) }}</span></label>
                <label class="text-sm">الصلة بالأسرة<select v-model="m.relation_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="k in options.kinship" :key="k.id" :value="k.id">{{ k.name }}</option></select></label>
                <Choice v-model="m.is_studying" label="يدرس؟" :name="`household.members.${i}.is_studying`" :errors="e" />
                <Choice v-model="m.is_employed" label="يعمل؟" :name="`household.members.${i}.is_employed`" :errors="e" />
                <Choice v-model="m.is_dependent" label="مُعال؟" :name="`household.members.${i}.is_dependent`" :errors="e" />
                <label class="text-sm">يسكن مع الأسرة منذ<input v-model="m.since" type="date" class="field mt-1"></label>
                <button type="button" class="btn btn-sm btn-danger sm:col-span-4 sm:justify-self-start" @click="removeMember(i)">حذف الفرد</button>
            </div>
            <button v-if="h.members.length < 30" type="button" class="btn btn-sm btn-secondary mt-3" @click="h.members.push(blankMember())">إضافة فرد</button>
        </details>
    </section>
</template>
