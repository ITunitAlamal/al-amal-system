<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { createUuid } from '../../Support/uuid.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import CorrespondenceFiles from '../../Components/CorrespondenceFiles.vue';
import { useUnsavedChanges } from '../../Support/useUnsavedChanges.js';

const props = defineProps({ catalog: Object, departments: Array, settings: Object, letter: Object, linkedRequests: Array, parentLetters: Array });
const l = props.letter;
const idOf = (list, code) => list.find(item => item.code === code)?.id;
const defaults = {
    type: idOf(props.catalog.types, 'memo') || props.catalog.types[0]?.id || '',
    classification: idOf(props.catalog.classifications, 'internal') || props.catalog.classifications[0]?.id || '',
    priority: idOf(props.catalog.priorities, 'normal') || props.catalog.priorities[0]?.id || '',
};
const urgentId = idOf(props.catalog.priorities, 'urgent');

const form = useForm({
    creation_key: l?.creation_key || createUuid(), revision: l?.revision || 1,
    department_id: l?.department_id || props.departments[0]?.id || '',
    recipient_id: l?.recipient_id || '', recipient_department_id: l?.recipient_department_id || '',
    type_value_id: l?.type_value_id || defaults.type, classification_value_id: l?.classification_value_id || defaults.classification,
    priority_value_id: l?.priority_value_id || defaults.priority, retention_value_id: l?.retention_value_id || props.catalog.retention[0]?.id || '',
    subject: l?.subject || '', body: l?.body || '', letter_date: l?.letter_date || new Date().toLocaleDateString('en-CA'),
    due_at: l?.due_at || '', acknowledgment_required: l?.acknowledgment_required ?? false,
    workflow_instance_id: l?.workflow_instance_id || '', parent_id: l?.parent_id || '', board_reference: l?.board_reference || '',
    attachments: [], send: false,
});
useUnsavedChanges(() => form.isDirty);

const urgent = computed({
    get: () => Boolean(urgentId) && String(form.priority_value_id) === String(urgentId),
    set: value => { form.priority_value_id = value ? urgentId : defaults.priority; },
});
const isBoard = computed(() => props.catalog.types.find(type => String(type.id) === String(form.type_value_id))?.code === 'board_transmission');
const advancedFields = ['type_value_id', 'classification_value_id', 'priority_value_id', 'retention_value_id', 'letter_date', 'due_at', 'acknowledgment_required', 'workflow_instance_id', 'parent_id', 'board_reference'];
const showAdvanced = ref(Boolean(l && (String(l.type_value_id) !== String(defaults.type) || String(l.classification_value_id) !== String(defaults.classification)
    || l.due_at || l.workflow_instance_id || l.parent_id || l.acknowledgment_required)));
watch(() => form.errors, errors => { if (advancedFields.some(field => errors[field])) { showAdvanced.value = true; } });

const recipients = ref([]);
const recipientKey = ref(l ? `${l.recipient_id}:${l.recipient_department_id}` : '');
const loading = ref(false);
const loadError = ref('');
let generation = 0;
const groups = computed(() => {
    const byDepartment = new Map();
    for (const person of recipients.value) {
        const department = person.department || '';
        if (!byDepartment.has(department)) { byDepartment.set(department, []); }
        byDepartment.get(department).push(person);
    }
    return [...byDepartment].map(([name, people]) => ({ name, people }));
});
async function loadRecipients(clear = false) {
    const current = ++generation;
    if (clear) { recipientKey.value = ''; }
    loading.value = true;
    try {
        const query = new URLSearchParams({ department_id: form.department_id, type_value_id: form.type_value_id, classification_value_id: form.classification_value_id });
        const response = await fetch(`/correspondence/recipients?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) { throw new Error(); }
        const result = await response.json();
        if (current === generation) { recipients.value = result.recipients; loadError.value = ''; }
    } catch {
        if (current === generation) { recipients.value = []; loadError.value = 'تعذر تحميل قائمة المستلمين. أعد تحميل الصفحة.'; }
    } finally {
        if (current === generation) { loading.value = false; }
    }
}
watch(() => [form.department_id, form.type_value_id, form.classification_value_id], () => loadRecipients(true));
watch(recipientKey, value => { const [id, department] = value.split(':'); form.recipient_id = id || ''; form.recipient_department_id = department || ''; });
loadRecipients();

function submit(send) {
    form.send = send;
    form.post(l ? `/correspondence/${l.public_id}/draft` : '/correspondence', { forceFormData: true });
}
</script>

<template>
    <AppLayout :title="l ? 'تحرير المسودة' : 'مراسلة جديدة'">
        <form class="mx-auto max-w-4xl space-y-5 pb-24 sm:pb-0" @submit.prevent="submit(true)">
            <PageHeader :title="l ? 'تحرير المسودة' : 'مراسلة جديدة'" description="اختر المستلم واكتب الموضوع والنص ثم اضغط «إرسال». تُرقّم المراسلة تلقائيًا عند إرسالها." />

            <div v-if="Object.keys(form.errors).length" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert" aria-label="أخطاء التحقق">
                <p class="mb-2 font-bold">يرجى مراجعة الحقول التالية:</p>
                <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            </div>

            <section class="card space-y-5">
                <div class="border-b border-slate-100 pb-3">
                    <h2 class="font-bold text-navy-800">بيانات المراسلة</h2>
                    <p class="mt-1 text-sm text-slate-500">الحقول الأساسية اللازمة لتوجيه المراسلة وتوثيق نصها.</p>
                </div>

                <label v-if="departments.length > 1" class="block text-sm font-semibold">باسم القسم
                    <select v-model="form.department_id" class="field mt-1 w-full" required>
                        <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                    </select>
                </label>

                <label class="block text-sm font-semibold">إلى <span class="text-red-700" aria-hidden="true">*</span>
                    <select v-model="recipientKey" class="field mt-1 w-full" :disabled="loading || !recipients.length" required aria-describedby="recipient-help">
                        <option value="">{{ loading ? 'جارٍ تحميل المستلمين…' : 'اختر المستلم' }}</option>
                        <optgroup v-for="group in groups" :key="group.name" :label="group.name">
                            <option v-for="person in group.people" :key="`${person.id}:${person.department_id}`" :value="`${person.id}:${person.department_id}`">{{ person.name }}</option>
                        </optgroup>
                    </select>
                </label>
                <div id="recipient-help" class="-mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <p v-if="loadError" class="text-red-700" role="alert">{{ loadError }}</p>
                    <p v-else-if="!loading && !recipients.length" class="text-slate-600">لا يوجد مستلم متاح حاليًا ضمن نطاق تكليفك.</p>
                    <p v-else-if="!loading" class="text-slate-500">تُعرض هنا الجهات والأشخاص المتاحون بحسب صلاحياتك.</p>
                    <button v-if="loadError" type="button" class="font-semibold text-blue-700 underline" @click="loadRecipients()">إعادة المحاولة</button>
                </div>

                <label class="block text-sm font-semibold">الموضوع <span class="text-red-700" aria-hidden="true">*</span>
                    <input v-model="form.subject" class="field mt-1 w-full" required maxlength="250" aria-describedby="subject-count" />
                    <span id="subject-count" class="mt-1 block text-end text-xs font-normal text-slate-500">{{ form.subject.length }} / 250</span>
                </label>
                <label class="block text-sm font-semibold">النص <span class="text-red-700" aria-hidden="true">*</span>
                    <textarea v-model="form.body" class="field mt-1 w-full leading-7" rows="12" required maxlength="50000" aria-describedby="body-count"></textarea>
                    <span id="body-count" class="mt-1 block text-end text-xs font-normal text-slate-500">{{ form.body.length }} / 50000</span>
                </label>
                <CorrespondenceFiles v-model="form.attachments" :settings="settings" :progress="form.progress" :disabled="form.processing" />
                <p v-if="l" class="text-xs text-slate-500">المرفقات المحفوظة سابقًا تبقى؛ ما تضيفه هنا يُضاف إليها.</p>
                <label v-if="urgentId" class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">
                    <input v-model="urgent" type="checkbox" class="mt-1" />
                    <span>إعطاء أولوية عاجلة<p class="mt-1 font-normal text-red-700">استخدمها عند الحاجة الفعلية إلى متابعة عاجلة.</p></span>
                </label>
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">تُعرض المراسلة ومرفقاتها وفق صلاحيات التصنيف ونطاق الإحالة المعتمدين.</p>
            </section>

            <details class="card" :open="showAdvanced" @toggle="showAdvanced = $event.target.open">
                <summary class="cursor-pointer text-sm font-semibold text-navy-800">خيارات إضافية (اختيارية)</summary>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm">نوع المراسلة
                        <select v-model="form.type_value_id" class="field mt-1 w-full"><option v-for="item in catalog.types" :key="item.id" :value="item.id">{{ item.name_ar }}</option></select>
                    </label>
                    <label class="text-sm">السرية
                        <select v-model="form.classification_value_id" class="field mt-1 w-full"><option v-for="item in catalog.classifications" :key="item.id" :value="item.id">{{ item.name_ar }}</option></select>
                        <span class="mt-1 block text-xs font-normal text-slate-500">يؤثر التصنيف في نطاق الإحالة والاطلاع المتاح.</span>
                    </label>
                    <label class="text-sm">الأولوية
                        <select v-model="form.priority_value_id" class="field mt-1 w-full"><option v-for="item in catalog.priorities" :key="item.id" :value="item.id">{{ item.name_ar }}</option></select>
                    </label>
                    <label class="text-sm">مدة الاحتفاظ
                        <select v-model="form.retention_value_id" class="field mt-1 w-full"><option v-for="item in catalog.retention" :key="item.id" :value="item.id">{{ item.name_ar }}</option></select>
                    </label>
                    <label v-if="isBoard" class="text-sm sm:col-span-2">مرجع قرار مجلس الإدارة <span class="text-red-700" aria-hidden="true">*</span>
                        <input v-model="form.board_reference" class="field mt-1 w-full" />
                    </label>
                    <label class="text-sm">تاريخ الكتاب
                        <input v-model="form.letter_date" type="date" class="field mt-1 w-full" />
                    </label>
                    <label class="text-sm">موعد المتابعة
                        <input v-model="form.due_at" type="datetime-local" class="field mt-1 w-full" />
                    </label>
                    <label class="text-sm">ربط بطلب قائم
                        <select v-model="form.workflow_instance_id" class="field mt-1 w-full"><option value="">دون ربط</option><option v-for="item in linkedRequests" :key="item.id" :value="item.id">{{ item.reference_no }}</option></select>
                    </label>
                    <label class="text-sm">ردًا على مراسلة سابقة
                        <select v-model="form.parent_id" class="field mt-1 w-full"><option value="">دون مرجع</option><option v-for="item in parentLetters" :key="item.id" :value="item.id">{{ item.reference }}</option></select>
                    </label>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.acknowledgment_required" type="checkbox" /> أطلب إقرارًا بالاستلام (يُسجَّل تلقائيًا عند فتح المستلم للمراسلة)</label>
                </div>
            </details>

            <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap gap-3 border-t border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none sm:backdrop-blur-none">
                <button type="submit" class="btn btn-primary min-w-32" :disabled="form.processing || loading || !recipientKey">
                    {{ form.processing ? 'جارٍ الحفظ…' : 'إرسال المراسلة' }}
                </button>
                <button type="button" class="btn btn-secondary" :disabled="form.processing" @click="submit(false)">حفظ كمسودة</button>
                <Link href="/correspondence" class="btn btn-secondary">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>
