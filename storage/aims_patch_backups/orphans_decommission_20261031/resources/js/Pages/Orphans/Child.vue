<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import RecordContext from '../../Components/Orphans/RecordContext.vue';
import { recordResultsUrl, recordWithResults } from '../../Support/orphanNavigation.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import FormErrorSummary from '../../Components/FormErrorSummary.vue';
import RuleFields from '../../Components/Orphans/RuleFields.vue';
import DocumentsChecklist from '../../Components/Orphans/DocumentsChecklist.vue';
import { childFields, educationFields, healthFields } from '../../Support/orphanFields';

// A child's file: data, yearly education, health history (sensitive), documents, guardianship, social score, changes.
const props = defineProps({
    child: { type: Object, required: true },
    household: { type: Object, required: true },
    education: { type: Array, default: () => [] },
    health: { type: Array, default: () => [] },
    guardianships: { type: Array, default: () => [] },
    score: { type: Object, default: null },
    scoreHistory: { type: Array, default: () => [] },
    socialRuleset: Boolean,
    documents: { type: Array, default: () => [] },
    changes: { type: Array, default: () => [] },
    rules: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    changeCategories: { type: Object, default: () => ({}) },
    sponsorship: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});
const tab = ref('data');
const tabs = [['data', 'الملخص والأسرة والوصاية'], ['social', 'البحث الاجتماعي'], ['support', 'الكفالات'], ['records', 'الوثائق والسجل']];
const page = usePage();
const resultsUrl = computed(() => recordResultsUrl(page.url));
const base = `/orphans/children/${props.child.public_id}`;
const year = new Date().getMonth() >= 7 ? `${new Date().getFullYear()}/${new Date().getFullYear() + 1}` : `${new Date().getFullYear() - 1}/${new Date().getFullYear()}`;
const educationForm = useForm({ school_year: year, enrollment_value_id: null });
const educationModel = computed({ get: () => educationForm.data(), set: v => Object.keys(v).forEach(k => { educationForm[k] = v[k]; }) });
const healthForm = useForm({ has_chronic: false, has_disability: false, disability_types: [] });
const healthModel = computed({ get: () => healthForm.data(), set: v => Object.keys(v).forEach(k => { healthForm[k] = v[k]; }) });
const overrideForm = useForm({ total: props.score?.total ?? 0, reason: '' });

const changeForm = useForm({ category: 'identity', subject_type: 'child', subject_id: props.child.id, changes: {}, reason: '' });
const changeModel = computed({ get: () => changeForm.changes, set: v => { changeForm.changes = v; } });
const changeFields = computed(() => ({
    identity: childFields.filter(f => ['first_name', 'father_name', 'grandfather_name', 'family_name', 'english_name', 'identity_type_value_id', 'identity_no', 'date_of_birth', 'sex', 'place_of_birth'].includes(f.key)),
    eligibility: childFields.filter(f => ['orphan_class_value_id', 'sole_survivor'].includes(f.key)),
    contact: childFields.filter(f => f.key === 'phone'),
    file_status: [{ key: 'status', label: 'الحالة المطلوبة', type: 'select', raw: true, choices: [{ value: 'suspended', label: 'إيقاف الملف' }, { value: 'closed', label: 'إغلاق الملف' }, { value: 'active', label: 'إعادة فتح/تفعيل' }] }],
}[changeForm.category] || []));
function requestChange() {
    const keys = changeFields.value.map(f => f.key);
    const picked = Object.fromEntries(Object.entries(changeForm.changes).filter(([k, v]) => keys.includes(k) && v !== null && v !== ''));
    changeForm.transform(d => ({ ...d, changes: picked })).post('/orphans/changes', { preserveScroll: true });
}
const factorLabel = code => props.score?.factor_labels?.[code] || code;
const tone = { applicant: 'info', pending_activation: 'warning', active: 'success', suspended: 'muted', closed: 'muted' };
</script>

<template>
    <AppLayout :title="child.file_no ? `ملف اليتيم ${child.file_no}` : 'ملف طفل قيد الدراسة'">
        <div class="space-y-4">
            <RecordContext :results-url="resultsUrl" :household-url="recordWithResults(`/orphans/households/${household.public_id}`, resultsUrl)" :household-reference="household.reference_no" label="ملف يتيم" />
            <PageHeader eyebrow="ملف يتيم" :title="child.full_name" :description="child.file_no ? `رقم الملف ${child.file_no} — مفعّل منذ ${child.activated_at}` : 'لم يُفعّل بعد: الرقم الرسمي يصدر بقرار المدير التنفيذي.'">
                <template #meta><div class="mt-2 flex flex-wrap gap-2"><StatusBadge :label="child.status_label" :tone="tone[child.status]" /><StatusBadge :label="child.verification" tone="info" /><StatusBadge v-if="sponsorship" :label="`الكفالة: ${sponsorship.label}`" :tone="sponsorship.sponsored ? 'success' : 'warning'" /></div></template>
            </PageHeader>
            <section v-if="tab === 'support'" class="card space-y-2 text-sm">
                <h2 class="card-title">الكفالة والادخار</h2>
                <p v-if="!sponsorship" class="text-slate-500">بيانات الكفالة غير متاحة.</p>
                <p v-for="s in sponsorship?.list || []" :key="s.public_id"><Link :href="`/orphans/sponsorships/${s.public_id}`" class="font-semibold text-blue-700 hover:underline">{{ s.sponsorship_no }}</Link> — {{ s.sponsor }} — {{ s.amount }} {{ s.currency }} ({{ s.frequency }}) — {{ s.status_label }}<span v-if="s.regularity && s.status === 'active'"> — {{ s.regularity }}</span></p>
                <p v-if="sponsorship && !sponsorship.list.length" class="text-slate-500">لا توجد كفالات مسجلة في هذا الملف.</p>
                <p v-if="sponsorship?.savings"><span class="text-slate-500">رصيد الادخار (من قيود الحسابات):</span>
                    <span v-for="(amount, currency) in sponsorship.savings" :key="currency" class="me-3 font-semibold">{{ amount }} <span dir="ltr">{{ currency }}</span></span>
                    <span v-if="!Object.keys(sponsorship.savings).length">لا يوجد</span></p>
            </section>
            <nav class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" aria-label="أقسام ملف الطفل">
                <button v-for="[key, label] in tabs" :key="key" type="button" :aria-pressed="tab === key" class="btn btn-sm" :class="tab === key ? 'btn-primary' : 'btn-secondary'" @click="tab = key">{{ label }}</button>
            </nav>

            <section v-if="tab === 'data'" class="rounded-lg border border-slate-200 p-3 text-sm">
                <h2 class="font-semibold">الإجراء المطلوب</h2>
                <p v-if="['applicant', 'pending_activation'].includes(child.status)" class="mt-1 text-slate-600">مراجعة بيانات الأسرة والمستندات وطلب الاعتماد عبر المسار القائم. لا تمثل بيانات الوصي المسجل اعتمادًا للوصاية.</p>
                <p v-else class="mt-1 text-slate-600">راجع المستندات والسجل قبل طلب أي تغيير على الملف المعتمد.</p>
                <button type="button" class="btn btn-secondary btn-sm mt-2" @click="tab = 'records'">المستندات وطلبات التعديل</button>
            </section>

            <section v-if="tab === 'data'" class="card">
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">رقم الوثيقة</dt><dd dir="ltr" class="text-right">{{ child.identity || '—' }}</dd></div>
                    <div><dt class="text-slate-500">تاريخ الميلاد / العمر</dt><dd>{{ child.date_of_birth || 'غير متوفر' }}<span v-if="child.age !== null && child.age !== undefined"> ({{ child.age }} سنة)</span></dd></div>
                    <div><dt class="text-slate-500">الجنس</dt><dd>{{ child.sex === 'male' ? 'ذكر' : child.sex === 'female' ? 'أنثى' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">تصنيف اليتم</dt><dd>{{ child.class || '—' }}{{ child.sole_survivor ? ' — الناجي الوحيد' : '' }}</dd></div>
                    <div><dt class="text-slate-500">الأب</dt><dd>{{ child.father || '—' }}</dd></div>
                    <div><dt class="text-slate-500">الأم</dt><dd>{{ child.mother || '—' }}</dd></div>
                    <div><dt class="text-slate-500">مكان الميلاد</dt><dd>{{ child.place_of_birth || '—' }}</dd></div>
                    <div v-if="child.status_reason" class="sm:col-span-2"><dt class="text-slate-500">ملاحظة الحالة</dt><dd>{{ child.status_reason }}</dd></div>
                </dl>
                <h3 class="mt-4 font-semibold">الوصاية والتمثيل</h3>
                <ul class="mt-1 text-sm"><li v-for="(g, i) in guardianships" :key="i">{{ g.name }} ({{ g.reference_no }}) — من {{ g.starts_on }}<span v-if="g.ends_on"> حتى {{ g.ends_on }}</span><span v-if="g.ended_reason" class="text-slate-500"> — {{ g.ended_reason }}</span></li>
                    <li v-if="!guardianships.length" class="text-slate-500">لا توجد وصاية معتمدة بعد (تبدأ بقرار التفعيل).</li></ul>
            </section>

            <details v-if="tab === 'social'" class="card space-y-3"><summary class="cursor-pointer font-semibold">التعليم</summary>
                <section class="card overflow-x-auto">
                    <table class="w-full text-sm"><thead class="text-right text-xs text-slate-500"><tr><th class="py-2">العام</th><th>الحالة</th><th>المرحلة</th><th>المدرسة</th><th>الصف</th><th>التحصيل</th></tr></thead>
                        <tbody><tr v-for="e in education" :key="e.school_year" class="border-t border-slate-100"><td class="py-2">{{ e.school_year }}</td><td>{{ e.enrollment }}</td><td>{{ e.stage || '—' }}</td><td>{{ e.school_name || '—' }}</td><td>{{ e.grade || '—' }}</td><td>{{ e.achievement || '—' }}</td></tr>
                            <tr v-if="!education.length"><td colspan="6" class="py-3 text-slate-500">لا توجد سجلات تعليمية.</td></tr></tbody></table>
                </section>
                <form v-if="can.records" class="card space-y-3" data-error-scope @submit.prevent="educationForm.post(`${base}/education`, { preserveScroll: true })">
                    <h2 class="card-title">تسجيل عام دراسي (يحفظ الأعوام السابقة كما هي)</h2>
                    <FormErrorSummary :errors="educationForm.errors" />
                    <RuleFields v-model="educationModel" :fields="educationFields" :rules="rules.education" :options="options" :errors="educationForm.errors" />
                    <button class="btn btn-primary btn-sm" :disabled="educationForm.processing">حفظ</button>
                </form>
            </details>

            <details v-if="tab === 'social' && can.sensitive" class="card space-y-3"><summary class="cursor-pointer font-semibold">الصحة</summary>
                <section class="card space-y-2 text-sm">
                    <p class="text-xs text-slate-500">بيانات صحية محمية: لا تظهر في اللوحات العامة ولا للمتبرعين، وكل اطلاع مسجل.</p>
                    <div v-for="h in health" :key="h.id" class="rounded-lg border p-3" :class="h.is_current ? 'border-blue-200' : 'border-slate-100 opacity-75'">
                        <p class="font-semibold">{{ h.is_current ? 'الحالة الحالية' : 'سجل سابق' }} — {{ h.recorded_at }}</p>
                        <p>{{ h.status || 'الحالة غير محددة' }}<span v-if="h.has_chronic"> — مرض مزمن: {{ h.chronic_note }}</span><span v-if="h.has_disability"> — إعاقة: {{ (h.disability_types || []).join('، ') }}</span></p>
                        <p v-if="h.medications">الأدوية: {{ h.medications }}</p><p v-if="h.follow_up">المتابعة: {{ h.follow_up }}</p>
                    </div>
                    <p v-if="!health.length" class="text-slate-500">لا توجد سجلات صحية.</p>
                </section>
                <form v-if="can.records" class="card space-y-3" data-error-scope @submit.prevent="healthForm.post(`${base}/health`, { preserveScroll: true })">
                    <h2 class="card-title">تسجيل حالة صحية جديدة</h2>
                    <FormErrorSummary :errors="healthForm.errors" />
                    <RuleFields v-model="healthModel" :fields="healthFields" :rules="rules.health" :options="options" :errors="healthForm.errors" />
                    <button class="btn btn-primary btn-sm" :disabled="healthForm.processing">حفظ</button>
                </form>
            </details>

            <details v-if="tab === 'records'" class="card" open><summary class="mb-3 cursor-pointer font-semibold">المستندات</summary><DocumentsChecklist :items="documents" :can-upload="can.records" :can-verify="can.verify" /></details>

            <details v-if="tab === 'social'" class="card space-y-3 text-sm"><summary class="cursor-pointer font-semibold">التقييم الاجتماعي</summary>
                <p class="text-xs text-slate-500">التقييم الاجتماعي العام أداة لترتيب المراجعة؛ لا يمنح أو يرفض أي مساعدة أو كفالة بذاته، ومنفصل عن أولوية الحملات.</p>
                <template v-if="score">
                    <p class="text-lg font-bold">{{ score.override_total ?? score.total }} <span v-if="score.override_total !== null" class="text-sm font-normal text-slate-500">(المحسوب {{ score.total }} — عُدّل: {{ score.override_reason }})</span></p>
                    <p class="text-slate-500">{{ score.ruleset }} — حُسب {{ String(score.computed_at).slice(0, 16) }}<span v-if="score.components?.complete === false"> — بيانات ناقصة تعلّق التقييم</span></p>
                    <div v-if="score.review_reasons?.length" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900" role="status">
                        <p class="font-semibold">يُستحسن إعادة التقييم</p>
                        <p v-for="r in score.review_reasons" :key="r" class="text-xs">{{ r }}</p>
                        <p class="text-xs">التقييم الحالي لا يتغير تلقائيًا؛ إعادة الحساب تحفظ تقييمًا جديدًا ويبقى هذا في السجل.</p>
                    </div>
                    <table class="w-full"><thead class="text-right text-xs text-slate-500"><tr><th class="py-1">العامل</th><th>القيمة</th><th>النقاط</th></tr></thead>
                        <tbody><tr v-for="(c, code) in score.components?.factors || {}" :key="code" class="border-t border-slate-100"><td class="py-1">{{ factorLabel(code) }}</td><td>{{ c.input === null ? 'غير متوفر' : c.input }}</td><td>{{ c.points }}</td></tr></tbody></table>
                    <form v-if="can.override" class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3" @submit.prevent="overrideForm.post(`/orphans/scores/${score.id}/override`, { preserveScroll: true })">
                        <label class="block"><span class="field-label">تعديل بشري للتقييم</span><input v-model="overrideForm.total" type="number" step="0.01" min="0" class="field w-32"></label>
                        <input v-model="overrideForm.reason" class="field flex-1" minlength="10" maxlength="1000" required placeholder="مبرر التعديل (يُسجل)">
                        <button class="btn btn-secondary btn-sm" :disabled="overrideForm.processing">حفظ التعديل</button>
                        <p v-for="(m, k) in overrideForm.errors" :key="k" class="field-error w-full">{{ m }}</p>
                    </form>
                </template>
                <p v-else class="text-slate-500">لم يُحسب تقييم بعد.</p>
                <button v-if="can.verify && child.status === 'active'" type="button" class="btn btn-primary btn-sm" :disabled="!socialRuleset" @click="router.post(`${base}/score`, {}, { preserveScroll: true })">
                    {{ socialRuleset ? (score ? 'إعادة التقييم بالإصدار المعتمد' : 'حساب التقييم بالإصدار المعتمد') : 'لا توجد قواعد معتمدة بعد' }}</button>
                <div v-if="scoreHistory.length" class="border-t border-slate-100 pt-3">
                    <h3 class="font-semibold">التقييمات السابقة</h3>
                    <ul class="mt-1 space-y-1 text-xs text-slate-600">
                        <li v-for="(s, i) in scoreHistory" :key="i"><span dir="ltr">{{ s.computed_at }}</span> — إصدار القواعد {{ s.version }} — {{ s.override_total ?? s.total }}<span v-if="s.override_total !== null"> (المحسوب {{ s.total }})</span></li>
                    </ul>
                </div>
            </details>

            <details v-if="tab === 'records'" class="card space-y-3"><summary class="cursor-pointer font-semibold">طلبات التعديل</summary>
                <section class="card space-y-1 text-sm">
                    <p v-for="c in changes" :key="c.id"><Link :href="`/orphans/changes/${c.id}`" class="font-semibold text-blue-700 hover:underline">{{ c.reference_no }}</Link> — {{ c.category_label }} — {{ c.status_label }}</p>
                    <p v-if="!changes.length" class="text-slate-500">لا توجد طلبات تعديل.</p>
                </section>
                <form v-if="can.records && child.status !== 'applicant' && child.status !== 'pending_activation'" class="card space-y-3" data-error-scope @submit.prevent="requestChange">
                    <h2 class="card-title">طلب تعديل على الملف المعتمد</h2>
                    <FormErrorSummary :errors="changeForm.errors" />
                    <select v-model="changeForm.category" class="field max-w-xs"><option v-for="(label, key) in changeCategories" v-show="['identity', 'eligibility', 'contact', 'file_status'].includes(key)" :key="key" :value="key">{{ label }}</option></select>
                    <RuleFields v-model="changeModel" :fields="changeFields" :rules="{}" :options="options" :errors="changeForm.errors" prefix="changes." />
                    <textarea v-model="changeForm.reason" class="field" rows="2" maxlength="2000" required placeholder="السبب والدليل (أرفق المستند في تبويب المستندات)"></textarea>
                    <button class="btn btn-primary btn-sm" :disabled="changeForm.processing">تقديم الطلب</button>
                </form>
            </details>
        </div>
    </AppLayout>
</template>
