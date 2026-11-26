<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import StatusBadge from '../StatusBadge.vue';
import SectionSelect from './SectionSelect.vue';

// «ملف اليتيم» of one staged source row, in four sections. A decision here concerns the staged row only: it never
// approves a guardianship, an orphan file, portal access, a sponsorship or a payment. The server re-checks everything.
const props = defineProps({ detail: { type: Object, required: true }, can: { type: Object, required: true }, severities: { type: Object, required: true }, decisions: { type: Object, required: true } });
const emit = defineEmits(['open-row', 'open-household', 'open-tree', 'changed', 'close']);
const section = ref('summary');
const busy = ref(false);
const error = ref('');
const message = ref('');
const form = ref({ kind: 'identity', decision: '', note: '' });
const editing = ref(false);
const editSection = ref('summary');
const edits = ref({});
const reason = ref('');
const editErrors = ref({});
const editableColumns = computed(() => props.detail.columns.filter(column => column.editable));
function startEdit() {
    edits.value = Object.fromEntries(editableColumns.value.map(column => [column.key, column.value || '']));
    reason.value = ''; editErrors.value = {}; editing.value = true;
    editSection.value = section.value === 'source' ? 'summary' : section.value;
}
watch(() => props.detail.id, () => { editing.value = false; });
async function saveEdits() {
    const changes = Object.fromEntries(editableColumns.value.filter(column => edits.value[column.key] !== (column.value || '')).map(column => [column.key, edits.value[column.key]]));
    if (!Object.keys(changes).length) { editing.value = false; return; }
    busy.value = true; error.value = ''; editErrors.value = {};
    try {
        await axios.put(`/orphans/staging/rows/${props.detail.id}`, { version: props.detail.correction_version, changes, reason: reason.value });
        editing.value = false;
        message.value = 'حُفظ التصحيح وأُعيد السجل للمتابعة. نسخة الكشف الأصلية محفوظة.';
        emit('changed');
    } catch (exception) {
        editErrors.value = exception.response?.data?.errors || {};
        error.value = exception.response?.data?.message || 'تعذّر حفظ التعديل. حاول مجددًا.';
    } finally { busy.value = false; }
}
watch(() => props.detail.id, () => { section.value = 'summary'; form.value = { kind: 'identity', decision: '', note: '' }; error.value = ''; message.value = ''; });

const sections = [['summary', 'الملخص'], ['family', 'الأسرة والوصاية'], ['details', 'التعليم والصحة والسكن'], ['source', 'المصدر والسجل']];
const columnsOf = {
    summary: ['child_name', 'child_sex', 'child_dob', 'source_age', 'child_national_id', 'registered_at', 'source_serial', 'child_photo_ref'],
    family: ['father_name', 'father_national_id', 'father_dob', 'father_death_date', 'father_death_cause', 'father_occupation', 'mother_name', 'mother_national_id', 'mother_dob',
        'mother_status', 'mother_death_cause', 'mother_death_date', 'family_total', 'family_males', 'family_females', 'guardian_name', 'guardian_national_id', 'guardian_relation'],
    details: ['school_grade', 'academic_performance', 'mother_education', 'mother_specialization', 'health_status', 'health_note', 'governorate', 'city', 'address_detail',
        'is_displaced', 'displacement_address', 'contact_phone_1', 'contact_phone_2'],
};
const value = key => props.detail.columns?.find(c => c.key === key)?.value || '';
const columns = computed(() => (props.detail.columns || []).filter(c => (columnsOf[section.value] || []).includes(c.key)));
const severityTone = { high: 'danger', medium: 'warning', low: 'info', info: 'muted' };
const decisionLabels = { pending: 'بانتظار التأكيد', supported: 'مدعوم بدليل — بانتظار التحقق الرسمي', rejected: 'غير صحيح', needs_evidence: 'يحتاج دليلًا' };
const issueStatus = { resolved: 'محلولة', dismissed: 'مستبعدة', follow_up: 'متابعة', open: 'مفتوحة' };
const flagLabels = { text_date: 'تاريخ نصي', text_date_ambiguous: 'تاريخ نصي محتمل الترتيب', text_date_month_first: 'قُرئ الشهر أولًا', unreadable_date: 'تاريخ غير مقروء', serial_unformatted: 'رقم تاريخ غير منسق', not_a_count: 'ليس عددًا', unknown_value: 'قيمة غير معروفة', source_error: 'خطأ في خلية المصدر' };
const openIssues = computed(() => (props.detail.issues || []).filter(i => ['open', 'follow_up'].includes(i.status) && i.severity !== 'info'));
// «الإجراء المطلوب»: derived from the row's own state, never from a score.
const nextAction = computed(() => {
    if (props.detail.placeholder_ids?.length) return `رقم هوية ${props.detail.placeholder_ids.join(' و')} شكلي (رقم مكرر): اطلب الرقم الصحيح بوثيقة وسجّله عند فتح الملف المعتمد؛ القيمة الأصلية في الكشف لا تُعدَّل.`;
    if (openIssues.value.length) return `مراجعة ${openIssues.value.length} ملاحظة مفتوحة، أولها: ${openIssues.value[0].label}`;
    const pending = [['identity', 'البيانات الشخصية'], ['household', 'عضوية الأسرة'], ['guardianship', 'ادعاء الوصي']].filter(([k]) => !props.detail.decisions[k].decision);
    if (pending.length) return `تسجيل قرار بدليل على: ${pending.map(([, l]) => l).join('، ')}`;
    return 'لا إجراء مطلوب على السجل الأولي؛ التحقق الرسمي يتم عند فتح الملف المعتمد.';
});
function errorText(e) { return Object.values(e.response?.data?.errors || {}).flat().join(' — ') || e.response?.data?.message || 'تعذر إتمام العملية'; }
async function post(url, body, done) {
    busy.value = true; error.value = ''; message.value = '';
    try { await axios.post(url, body); message.value = done; emit('changed'); return true; }
    catch (e) { error.value = errorText(e); return false; } finally { busy.value = false; }
}
async function decide() {
    if (await post(`/orphans/staging/rows/${props.detail.id}/decision`, form.value, 'سُجّل القرار على السجل المؤقت فقط؛ لا يغيّر أي ملف معتمد.')) form.value = { kind: form.value.kind, decision: '', note: '' };
}
async function settle(issue, status) {
    const note = prompt(status === 'resolved' ? 'ملاحظة الحل (10 أحرف على الأقل)' : status === 'dismissed' ? 'سبب الاستبعاد (10 أحرف على الأقل)' : 'ملاحظة المتابعة (10 أحرف على الأقل)') || '';
    if (note.trim().length < 10) return;
    await post(`/orphans/staging/issues/${issue.id}`, { status, note }, 'حُفظ قرار الملاحظة.');
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="row-file-title">
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 id="row-file-title" class="card-title break-words">{{ detail.columns.find(c => c.key === 'child_name')?.value || 'سجل بلا اسم' }}</h2>
                <p class="text-xs text-slate-500">سجل أولي — صف {{ detail.row }} في الكشف · {{ detail.assigned_to ? `المكلف: ${detail.assigned_to}` : 'غير مكلف' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-if="detail.can_edit && !editing" type="button" class="btn btn-primary btn-sm" @click="startEdit">تعديل بيانات اليتيم والوصي</button>
                <button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="emit('close')">العودة إلى النتائج</button>
            </div>
        </header>
        <dl class="grid grid-cols-2 gap-2 text-sm lg:grid-cols-5">
            <div v-for="[label, key] in [['البيانات الشخصية', 'personal'], ['عضوية الأسرة', 'household'], ['الوصي المدّعى', 'claimed_guardian']]" :key="key" class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">{{ label }}</dt><dd class="font-semibold">{{ decisionLabels[detail.statuses[key]] }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">الوصاية القانونية</dt><dd class="font-semibold text-red-700">بانتظار التحقق من الوصاية</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">دخول البوابة</dt><dd class="font-semibold">غير ممنوح</dd></div>
        </dl>
        <div class="rounded-xl border border-gold-200 bg-gold-50 p-3 text-sm"><span class="font-bold text-navy-800">الإجراء المطلوب: </span>{{ nextAction }}</div>
        <div class="grid gap-2 text-sm sm:grid-cols-2">
            <div class="rounded-lg border border-slate-200 p-2"><span class="text-xs text-slate-500">الوصي المدّعى (علاقة هذا اليتيم وحده)</span>
                <span class="block font-semibold">{{ value('guardian_name') || '—' }} <span class="text-xs font-normal text-slate-500">{{ value('guardian_relation') }}</span></span>
                <button v-if="detail.guardian_ref" type="button" class="text-xs font-semibold text-blue-700 hover:underline" @click="emit('open-tree', detail.guardian_ref)">أيتام هذا الوصي</button></div>
            <div class="rounded-lg border border-slate-200 p-2"><span class="text-xs text-slate-500">الأسرة (بيانات اجتماعية، لا تعني وصاية)</span>
                <span class="block">{{ detail.household_ref ? `${detail.siblings} طفل آخر في الأسرة المرشحة` : 'هوية الأب/الأم غير صالحة للتجميع' }}</span>
                <button v-if="detail.household_ref" type="button" class="text-xs font-semibold text-blue-700 hover:underline" @click="emit('open-household', detail.household_ref)">عرض الأسرة</button></div>
        </div>
        <p v-if="detail.existing" class="alert-info text-sm">يطابق ملفًا معتمدًا قائمًا ({{ detail.existing.name }}). لا يُعدَّل الملف المعتمد من الكشف.</p>
        <div v-if="error" class="alert-error" role="alert">{{ error }}</div>
        <div v-if="message" class="alert-success" role="status">{{ message }}</div>
        <form v-if="editing" class="space-y-4 rounded-xl border border-blue-200 bg-blue-50/40 p-4" @submit.prevent="saveEdits">
            <h3 class="font-bold text-navy-800">تعديل بيانات السجل المستورد</h3>
            <p class="text-sm text-slate-600">يشمل بيانات الطفل والوصي في هذا الصف فقط. لا تُعدّل سجلات الأطفال الآخرين المرتبطين بالوصي تلقائيًا. التعديل يعيد السجل للمتابعة ويلغي قرارات المراجعة السابقة عليه.</p>
            <SectionSelect v-model="editSection" :options="[['summary', 'بيانات اليتيم'], ['family', 'الوصي والأسرة'], ['details', 'التواصل والسكن والتعليم والصحة']]" label="البيانات المراد تعديلها" />
            <div class="grid gap-3 sm:grid-cols-2">
                <label v-for="column in editableColumns.filter(item => columnsOf[editSection]?.includes(item.key))" :key="column.key">
                    <span class="field-label">{{ column.header }}</span>
                    <select v-if="column.type === 'sex' || column.key === 'is_displaced'" v-model="edits[column.key]" class="field" :disabled="busy">
                        <option value="">غير محدد</option><option v-for="choice in column.type === 'sex' ? ['ذكر', 'أنثى'] : ['نعم', 'لا']" :key="choice" :value="choice">{{ choice }}</option>
                    </select>
                    <input v-else v-model="edits[column.key]" class="field" :disabled="busy" :maxlength="column.type === 'name' ? 255 : column.type === 'lookup' ? 120 : 2000" :placeholder="column.type === 'date' ? 'YYYY-MM-DD' : ''" :dir="['date', 'national_id', 'phone'].includes(column.type) ? 'ltr' : undefined" />
                    <span v-if="editErrors[`changes.${column.key}`]" class="text-xs text-red-700">{{ editErrors[`changes.${column.key}`][0] }}</span>
                </label>
            </div>
            <label class="block"><span class="field-label">سبب التصحيح أو مرجع المستند *</span><textarea v-model="reason" required minlength="10" maxlength="1000" class="field" :disabled="busy" /><span v-if="editErrors.reason" class="text-xs text-red-700">{{ editErrors.reason[0] }}</span></label>
            <div class="flex gap-2"><button class="btn btn-primary btn-sm" :disabled="busy">{{ busy ? 'جارٍ الحفظ…' : 'حفظ التصحيحات' }}</button><button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="editing = false">إلغاء</button></div>
        </form>
        <p v-if="detail.correction_version" class="alert-info text-sm">تم تصحيح هذا السجل {{ detail.correction_version }} مرة. ملاحظات الاستيراد تخص الكشف الأصلي، وتحتاج مراجعة بعد التصحيح. النسخة الأصلية في «المصدر والسجل».</p>
        <SectionSelect v-model="section" :options="sections" label="القسم" />

        <p v-if="section === 'details' && !detail.sensitive" class="text-xs text-slate-500">بعض البيانات محجوبة: تحتاج صلاحية الاطلاع على البيانات الحساسة.</p>
        <div v-if="columns.length" class="overflow-x-auto">
            <table class="data-table min-w-full text-sm"><tbody><tr v-for="c in columns" :key="c.key"><th scope="row" class="w-48 text-right font-normal text-slate-500">{{ c.header }}</th><td class="break-words">{{ c.value || '—' }}<span v-if="c.flag" class="mr-2 text-xs text-gold-800">({{ flagLabels[c.flag] || c.flag }})</span></td></tr></tbody></table>
        </div>

        <template v-if="section === 'summary'">
            <h3 class="text-sm font-bold text-navy-800">الملاحظات ({{ detail.issues.length }})</h3>
            <p v-if="!detail.issues.length" class="text-sm text-slate-500">لا توجد ملاحظات على هذا السجل.</p>
            <ul class="space-y-2">
                <li v-for="i in detail.issues" :key="i.id" class="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-slate-100 p-2 text-sm">
                    <span class="min-w-0"><StatusBadge :label="severities[i.severity]" :tone="severityTone[i.severity]" /> <strong>{{ i.label }}</strong><span class="block text-xs text-slate-500">{{ i.recommendation }}</span><span v-if="i.note" class="block text-xs">ملاحظة: {{ i.note }}</span></span>
                    <span v-if="can.review && ['open', 'follow_up'].includes(i.status)" class="flex gap-1"><button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="settle(i, 'resolved')">حل</button><button type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="settle(i, 'dismissed')">استبعاد</button></span>
                    <span v-else class="text-xs text-slate-500">{{ issueStatus[i.status] }}</span>
                </li>
            </ul>
            <details v-if="can.verify" class="rounded-xl border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-800">تسجيل قرار مراجعة</summary>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="decide">
                    <label><span class="field-label">المسار</span><select v-model="form.kind" class="field"><option value="identity">البيانات الشخصية للطفل</option><option value="household">عضوية الأسرة</option><option value="guardianship">ادعاء الوصي</option></select></label>
                    <label><span class="field-label">القرار</span><select v-model="form.decision" required class="field"><option value="">اختر</option><option v-for="(text, code) in decisions" :key="code" :value="code">{{ text }}</option></select></label>
                    <label class="sm:col-span-2"><span class="field-label">الدليل أو مرجع الوثيقة <span class="text-red-600">*</span></span><textarea v-model="form.note" rows="2" required minlength="10" class="field" placeholder="مثال: صورة الهوية رقم ... أو تقرير الزيارة ..." /></label>
                    <p class="text-xs text-slate-500">القرار على السجل المؤقت فقط. لا يُثبت وصاية قانونية، ولا يفعّل بوابة أو كفالة أو صرفًا.</p>
                    <button type="submit" class="btn btn-primary" :disabled="busy || !form.decision || form.note.trim().length < 10">حفظ القرار</button>
                </form>
            </details>
            <ul class="grid gap-2 text-sm sm:grid-cols-3">
                <li v-for="[kind, label] in [['identity', 'البيانات الشخصية للطفل'], ['household', 'عضوية الأسرة'], ['guardianship', 'ادعاء الوصي']]" :key="kind" class="rounded-lg bg-slate-50 p-2">
                    <span class="text-xs text-slate-500">{{ label }}</span><span class="block">{{ decisionLabels[detail.decisions[kind].decision || 'pending'] }}</span>
                    <span v-if="detail.decisions[kind].note" class="block text-xs text-slate-500">{{ detail.decisions[kind].note }} — {{ detail.decisions[kind].by }}</span>
                </li>
            </ul>
        </template>

        <div v-if="section === 'family'" class="space-y-2 text-sm">
            <p class="flex flex-wrap gap-3">
                <button v-if="detail.household_ref" type="button" class="font-semibold text-blue-700 hover:underline" @click="emit('open-household', detail.household_ref)">الأسرة المرشحة ({{ detail.siblings }} سجل آخر)</button>
                <span v-else class="text-red-700">هوية الأب/الأم غير صالحة للتجميع في أسرة.</span>
                <button v-if="detail.guardian_ref" type="button" class="font-semibold text-blue-700 hover:underline" @click="emit('open-tree', detail.guardian_ref)">الوصي المدّعى وأسره</button>
            </p>
            <template v-if="detail.same_child.length">
                <p class="font-semibold text-red-700">الهوية نفسها في {{ detail.same_child.length }} سجل آخر — قارن:</p>
                <div class="overflow-x-auto"><table class="data-table min-w-full text-sm"><thead><tr><th scope="col">صف</th><th scope="col">الاسم</th><th scope="col">الميلاد</th><th scope="col">الأب</th><th scope="col">الأم</th><th scope="col"><span class="sr-only">فتح</span></th></tr></thead>
                    <tbody><tr v-for="s in detail.same_child" :key="s.id"><td>{{ s.row }}</td><td>{{ s.name }}</td><td>{{ s.dob }}</td><td>{{ s.father_name }}</td><td>{{ s.mother_name }}</td><td><button type="button" class="btn btn-secondary btn-sm" @click="emit('open-row', s.id)">فتح</button></td></tr></tbody></table></div>
            </template>
        </div>
        <p v-if="section === 'details'" class="text-sm text-slate-600">لا توجد وثائق مرفقة بالسجل الأولي. رابط الصورة في الكشف مرجع فقط ولا يُنزَّل تلقائيًا.</p>
        <template v-if="section === 'source'">
            <div class="overflow-x-auto"><p class="mb-2 text-xs text-slate-500">كل أعمدة الصف كما وردت ({{ detail.columns.length }}).</p>
                <table class="data-table min-w-full text-sm"><tbody><tr v-for="c in detail.columns" :key="c.key"><th scope="row" class="w-14 text-right font-normal" dir="ltr">{{ c.letter }}</th><td class="text-slate-500">{{ c.header }}</td><td class="break-words">{{ c.original || '—' }}</td></tr></tbody></table></div>
            <h3 class="text-sm font-bold text-navy-800">سجل المراجعة</h3>
            <p v-if="!detail.history.length" class="text-sm text-slate-500">لا توجد إجراءات مسجلة بعد.</p>
            <ul class="text-xs text-slate-500"><li v-for="(h, n) in detail.history" :key="n">{{ h.created_at?.slice(0, 16) }} — {{ h.action }}</li></ul>
        </template>
    </section>
</template>
