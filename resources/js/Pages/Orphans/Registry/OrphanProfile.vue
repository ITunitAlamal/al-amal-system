<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import BasicPanel from '../../../Components/Registry/BasicPanel.vue';
import AddressesPanel from '../../../Components/Registry/AddressesPanel.vue';
import CarePanel from '../../../Components/Registry/CarePanel.vue';
import DocumentsPanel from '../../../Components/Registry/DocumentsPanel.vue';
import HouseholdPanel from '../../../Components/Registry/HouseholdPanel.vue';
import GuardianshipsPanel from '../../../Components/Registry/GuardianshipsPanel.vue';
import ParentCard from '../../../Components/Registry/ParentCard.vue';
import { fileTones, sectionLabels, sectionStates } from '../../../Support/orphans.js';

const props = defineProps({ file: Object, person: Object, guardianships: Array, parents: Object, households: Array, addresses: Array, education: Array, care: Array, economic: Array,
    assessments: Array, documents: Array, status_events: Array, siblings: Array, history: Array, modules: Array, options: Object, abilities: Object, staff: Array, tab: String });
const tabs = { basic: 'البيانات الأساسية', family: 'الأسرة والوصاية', education: 'التعليم', health: 'الصحة', residence: 'السكن والنزوح', assessment: 'التقييم الاجتماعي', aid: 'الكفالات والمساعدات', documents: 'المستندات وسجل التغييرات' };
const tab = ref(tabs[props.tab] ? props.tab : 'basic');
watch(tab, value => { const url = new URL(window.location.href); url.searchParams.set('tab', value); window.history.replaceState(window.history.state, '', url); });
const primary = () => props.guardianships.find(g => g.is_primary) || props.guardianships[0];

const fileForm = useForm({ status: props.file.status, status_reason: props.file.status_reason || '', orphan_class_value_id: props.file.orphan_class_value_id, review_due_on: props.file.review_due_on || '',
    notes: props.file.notes || '', assigned_to: props.file.assigned_to, restricted_notes: props.file.restricted_notes || '' });
const editingFile = ref(false);
const eduForm = useForm({ academic_year: '', stage_value_id: null, grade: '', school_name: '', school_type: '', status_value_id: null, performance: '', attendance_note: '', support_needs: '', starts_on: '', ends_on: '' });
const addingEdu = ref(false);
const visitForm = useForm({ kind: 'visit', status: 'scheduled', scheduled_on: '', completed_on: '', assigned_to: null, needs_level_value_id: null, summary: '', valid_until: '' });
const addingVisit = ref(false);
function completeVisit(v, status) {
    const summary = status === 'completed' ? window.prompt('ملخص الزيارة:') : null;
    if (status === 'completed' && summary === null) return;
    router.post(`/orphans/registry/assessments/${v.id}`, { status, summary }, { preserveScroll: true });
}
const aid = () => props.modules.filter(m => m.tab === 'aid');
</script>

<template>
    <AppLayout :title="`ملف اليتيم ${person.full_name}`">
        <div class="mx-auto max-w-7xl space-y-5">
            <header class="card flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 space-y-2">
                    <Link href="/orphans/registry" class="text-sm text-blue-700 hover:underline">← إدارة الأيتام والأوصياء</Link>
                    <h1 class="text-2xl font-bold text-navy-800">{{ person.full_name }}</h1>
                    <p class="text-sm text-slate-600"><span class="font-mono" dir="ltr">{{ file.reference_no }}</span> · <span class="font-mono" dir="ltr">{{ person.id_masked }}</span> · {{ person.sex_label }} · العمر: {{ person.age ?? 'غير متوفر' }}</p>
                    <div class="flex flex-wrap gap-1">
                        <StatusBadge :label="file.status_label" :tone="fileTones[file.status]" /><StatusBadge :label="`اكتمال ${file.completeness}%`" :tone="file.completeness === 100 ? 'success' : 'warning'" />
                        <StatusBadge v-if="file.orphan_class" :label="`${file.orphan_class}${file.class_basis === 'provisional' ? ' (مبدئي)' : ''}`" tone="info" /><StatusBadge v-else label="فئة اليتم غير محددة" tone="muted" />
                    </div>
                    <p v-if="file.status === 'provisional'" class="alert-info text-sm">«معتمد مبدئيًا» من الاستيراد الذكي{{ file.notes ? ` (${String(file.notes).split('\n')[0]})` : '' }}. ليس اعتمادًا قانونيًا للوصاية: لا يدخل ترشيح الحملات ولا الكفالات ولا البوابة حتى تُعتمد الوصاية ثم الملف.</p>
                    <p v-if="primary()" class="text-sm">الوصي الرئيسي: <Link :href="`/orphans/registry/guardians/${primary().guardian_public_id}`" class="text-blue-700 hover:underline">{{ primary().guardian_name }}</Link> — {{ primary().status_label }}</p>
                </div>
                <div class="flex flex-wrap gap-1 lg:max-w-sm lg:justify-end">
                    <StatusBadge v-for="(state, key) in file.sections" :key="key" :label="`${sectionLabels[key]}: ${sectionStates[state][0]}`" :tone="sectionStates[state][1]" />
                </div>
            </header>

            <nav class="flex gap-1 overflow-x-auto" role="tablist" aria-label="أقسام الملف">
                <button v-for="(label, key) in tabs" :key="key" type="button" role="tab" :aria-selected="tab === key" :class="['btn btn-sm shrink-0', tab === key ? 'btn-primary' : 'btn-secondary']" @click="tab = key">{{ label }}</button>
            </nav>

            <section v-if="tab === 'basic'" class="card space-y-5" role="tabpanel">
                <BasicPanel :person="person" :options="options" :abilities="abilities" :events="status_events" :show-status="false" />
                <div class="border-t border-slate-100 pt-4">
                    <div class="flex items-center justify-between"><h3 class="card-title">بيانات الملف</h3><button v-if="abilities.edit && !editingFile" type="button" class="btn btn-sm btn-secondary" @click="editingFile = true">تعديل</button></div>
                    <dl v-if="!editingFile" class="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs text-slate-500">تاريخ التسجيل</dt><dd>{{ file.registered_on }} ({{ { staff: 'موظف', portal: 'البوابة', import: 'استيراد' }[file.registration_source] }})</dd></div>
                        <div><dt class="text-xs text-slate-500">الموظف المسؤول</dt><dd>{{ file.assignee || 'غير محدد' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">موعد المراجعة</dt><dd>{{ file.review_due_on || 'غير محدد' }}</dd></div>
                        <div class="sm:col-span-3"><dt class="text-xs text-slate-500">ملاحظات تشغيلية</dt><dd>{{ file.notes || '—' }}</dd></div>
                        <div v-if="abilities.health" class="sm:col-span-3"><dt class="text-xs text-slate-500">ملاحظات مقيدة</dt><dd>{{ file.restricted_notes || '—' }}</dd></div>
                    </dl>
                    <form v-else class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="fileForm.put(`/orphans/registry/orphans/${file.public_id}/file`, { preserveScroll: true, onSuccess: () => { editingFile = false; } })">
                        <label class="text-sm">حالة الملف<select v-model="fileForm.status" class="field mt-1"><option v-for="(label, key) in options.statuses" :key="key" :value="key">{{ label }}</option></select></label>
                        <label class="text-sm">سبب الحالة<input v-model="fileForm.status_reason" class="field mt-1" maxlength="300"></label>
                        <label class="text-sm">فئة اليتم<select v-model="fileForm.orphan_class_value_id" class="field mt-1"><option :value="null">تُشتق من حالة الوالدين المتحقق منها</option><option v-for="c in options.classes" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                        <label class="text-sm">موعد المراجعة<input v-model="fileForm.review_due_on" type="date" class="field mt-1"></label>
                        <label v-if="abilities.assign" class="text-sm">الموظف المسؤول<select v-model="fileForm.assigned_to" class="field mt-1"><option :value="null">غير محدد</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                        <label class="text-sm sm:col-span-2">ملاحظات تشغيلية<textarea v-model="fileForm.notes" class="field mt-1" rows="2" maxlength="3000" /></label>
                        <label v-if="abilities.health" class="text-sm sm:col-span-2">ملاحظات مقيدة<textarea v-model="fileForm.restricted_notes" class="field mt-1" rows="2" maxlength="3000" /></label>
                        <p v-for="(e, k) in fileForm.errors" :key="k" class="field-error sm:col-span-2">{{ e }}</p>
                        <div class="flex gap-2 sm:col-span-2"><button type="submit" class="btn btn-primary" :disabled="fileForm.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="editingFile = false">إلغاء</button></div>
                    </form>
                </div>
            </section>

            <section v-if="tab === 'family'" class="space-y-5" role="tabpanel">
                <div class="card space-y-3"><h3 class="card-title">الأوصياء والارتباطات</h3>
                    <GuardianshipsPanel :links="guardianships" :orphan-id="file.public_id" :options="options" :abilities="abilities" /></div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <ParentCard relation="father" :parent="parents.father" :orphan-id="file.public_id" :options="options" :abilities="abilities" />
                    <ParentCard relation="mother" :parent="parents.mother" :orphan-id="file.public_id" :options="options" :abilities="abilities" />
                </div>
                <div class="card space-y-3"><h3 class="card-title">الأسرة (سياق اجتماعي، لا يمنح وصاية)</h3>
                    <HouseholdPanel :person-id="person.public_id" :households="households" :options="options" :can-edit="abilities.edit" /></div>
                <div v-if="siblings.length" class="card"><h3 class="card-title">الإخوة المسجلون (يشتركون في أب أو أم)</h3>
                    <ul class="mt-2 space-y-1 text-sm"><li v-for="s in siblings" :key="s.public_id"><Link :href="`/orphans/registry/orphans/${s.public_id}`" class="text-blue-700 hover:underline">{{ s.full_name }}</Link> <span class="font-mono text-xs text-slate-500" dir="ltr">{{ s.reference_no }}</span></li></ul></div>
            </section>

            <section v-if="tab === 'education'" class="card space-y-3" role="tabpanel">
                <h3 class="card-title">السجل التعليمي (سنة بسنة؛ لا يُستبدل السابق)</h3>
                <ul class="space-y-2 text-sm">
                    <li v-for="e in education" :key="e.id" class="rounded-xl border border-slate-200 p-3">
                        <p class="font-semibold text-navy-800">{{ e.academic_year || 'عام غير محدد' }} · {{ e.stage || 'مرحلة غير محددة' }} {{ e.grade ? `· ${e.grade}` : '' }}</p>
                        <p class="text-slate-600">{{ [e.school_name, e.school_type, e.status_name, e.performance, e.attendance_note].filter(Boolean).join(' · ') }}</p>
                        <p v-if="e.support_needs" class="text-slate-600">احتياجات الدعم: {{ e.support_needs }}</p>
                    </li>
                    <li v-if="!education.length" class="text-slate-500">لا يوجد سجل تعليمي (غير متوفر).</li>
                </ul>
                <button v-if="abilities.edit && !addingEdu" type="button" class="btn btn-sm btn-secondary" @click="addingEdu = true">إضافة سنة دراسية أو انتقال</button>
                <form v-if="addingEdu" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-3" @submit.prevent="eduForm.post(`/orphans/registry/orphans/${file.public_id}/education`, { preserveScroll: true, onSuccess: () => { addingEdu = false; eduForm.reset(); } })">
                    <label class="text-sm">العام الدراسي<input v-model="eduForm.academic_year" class="field mt-1" dir="ltr" placeholder="2026/2027"></label>
                    <label class="text-sm">المرحلة<select v-model="eduForm.stage_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="s in options.stages" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm">الصف<input v-model="eduForm.grade" class="field mt-1" maxlength="60"></label>
                    <label class="text-sm">المدرسة / المؤسسة<input v-model="eduForm.school_name" class="field mt-1" maxlength="200"></label>
                    <label class="text-sm">نوع المدرسة<input v-model="eduForm.school_type" class="field mt-1" maxlength="60"></label>
                    <label class="text-sm">الحالة التعليمية<select v-model="eduForm.status_value_id" class="field mt-1"><option :value="null">غير محددة</option><option v-for="s in options.education" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm">المستوى / التحصيل<input v-model="eduForm.performance" class="field mt-1" maxlength="100"></label>
                    <label class="text-sm">الحضور والغياب<input v-model="eduForm.attendance_note" class="field mt-1" maxlength="300"></label>
                    <label class="text-sm">من تاريخ<input v-model="eduForm.starts_on" type="date" class="field mt-1"></label>
                    <label class="text-sm sm:col-span-3">احتياجات الدعم التعليمي (قرطاسية، مواصلات، رسوم، أجهزة…)<textarea v-model="eduForm.support_needs" class="field mt-1" rows="2" maxlength="2000" /></label>
                    <p v-for="(e, k) in eduForm.errors" :key="k" class="field-error sm:col-span-3">{{ e }}</p>
                    <div class="flex gap-2 sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="eduForm.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="addingEdu = false">إلغاء</button></div>
                </form>
            </section>

            <section v-if="tab === 'health'" class="card space-y-3" role="tabpanel"><h3 class="card-title">البيانات الصحية والاحتياجات</h3>
                <CarePanel :person-id="person.public_id" :items="care" :options="options" :can-edit="abilities.edit" :clinical="abilities.health" /></section>

            <section v-if="tab === 'residence'" class="card space-y-3" role="tabpanel"><h3 class="card-title">السكن والنزوح</h3>
                <AddressesPanel :person-id="person.public_id" :addresses="addresses" :options="options" :can-edit="abilities.edit" /></section>

            <section v-if="tab === 'assessment'" class="card space-y-3" role="tabpanel">
                <h3 class="card-title">التقييم الاجتماعي والزيارات</h3>
                <p class="text-xs text-slate-500">التقييم الاجتماعي العام مستقل عن درجة الأولوية الخاصة بأي حملة.</p>
                <ul class="space-y-2 text-sm">
                    <li v-for="v in assessments" :key="v.id" class="rounded-xl border border-slate-200 p-3">
                        <div class="flex flex-wrap items-center gap-2"><StatusBadge :label="{ visit: 'زيارة', assessment: 'تقييم', follow_up: 'متابعة' }[v.kind]" tone="info" />
                            <StatusBadge :label="{ scheduled: 'مجدولة', completed: 'منجزة', cancelled: 'ملغاة' }[v.status]" :tone="{ scheduled: 'warning', completed: 'success', cancelled: 'muted' }[v.status]" />
                            <span class="text-slate-500">{{ v.status === 'completed' ? v.completed_on : v.scheduled_on }} · {{ v.assignee || 'غير مسند' }}</span>
                            <span v-if="abilities.edit && v.status === 'scheduled'" class="ms-auto flex gap-1"><button type="button" class="btn btn-sm btn-primary" @click="completeVisit(v, 'completed')">إنجاز</button><button type="button" class="btn btn-sm btn-secondary" @click="completeVisit(v, 'cancelled')">إلغاء</button></span></div>
                        <p v-if="v.summary" class="mt-1 text-slate-700">{{ v.summary }}</p><p v-if="v.needs_level" class="text-slate-600">مستوى الاحتياج: {{ v.needs_level }}</p>
                    </li>
                    <li v-if="!assessments.length" class="text-slate-500">لا توجد زيارات أو تقييمات.</li>
                </ul>
                <button v-if="abilities.edit && !addingVisit" type="button" class="btn btn-sm btn-secondary" @click="addingVisit = true">جدولة زيارة أو تسجيل تقييم</button>
                <form v-if="addingVisit" class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-3" @submit.prevent="visitForm.post(`/orphans/registry/orphans/${file.public_id}/assessments`, { preserveScroll: true, onSuccess: () => { addingVisit = false; visitForm.reset(); } })">
                    <label class="text-sm">النوع<select v-model="visitForm.kind" class="field mt-1"><option value="visit">زيارة ميدانية</option><option value="assessment">تقييم اجتماعي</option><option value="follow_up">متابعة</option></select></label>
                    <label class="text-sm">الحالة<select v-model="visitForm.status" class="field mt-1"><option value="scheduled">مجدولة</option><option value="completed">منجزة</option></select></label>
                    <label v-if="visitForm.status === 'scheduled'" class="text-sm">موعدها<input v-model="visitForm.scheduled_on" type="date" class="field mt-1"></label>
                    <label v-else class="text-sm">تاريخ الإنجاز<input v-model="visitForm.completed_on" type="date" class="field mt-1"></label>
                    <label class="text-sm">المسؤول<select v-model="visitForm.assigned_to" class="field mt-1"><option :value="null">أنا / غير محدد</option><option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
                    <label class="text-sm">مستوى الاحتياج<select v-model="visitForm.needs_level_value_id" class="field mt-1"><option :value="null">غير محدد</option><option v-for="n in options.needs" :key="n.id" :value="n.id">{{ n.name }}</option></select></label>
                    <label class="text-sm">صالح حتى<input v-model="visitForm.valid_until" type="date" class="field mt-1"></label>
                    <label class="text-sm sm:col-span-3">الملخص<textarea v-model="visitForm.summary" class="field mt-1" rows="3" maxlength="5000" /></label>
                    <p v-for="(e, k) in visitForm.errors" :key="k" class="field-error sm:col-span-3">{{ e }}</p>
                    <div class="flex gap-2 sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="visitForm.processing">حفظ</button><button type="button" class="btn btn-secondary" @click="addingVisit = false">إلغاء</button></div>
                </form>
            </section>

            <section v-if="tab === 'aid'" class="card space-y-3" role="tabpanel"><h3 class="card-title">الكفالات والمساعدات وسجل الاستفادة</h3>
                <div v-for="m in aid()" :key="m.key" class="space-y-2"><h4 class="font-semibold text-navy-800">{{ m.title }}</h4>
                    <ul class="space-y-1 text-sm"><li v-for="(item, i) in m.items" :key="i" class="rounded-lg border border-slate-100 p-2">{{ item.text }}</li></ul>
                    <p v-if="!m.items.length" class="text-sm text-slate-500">{{ m.empty }}</p></div>
                <p v-if="!aid().length" class="text-sm text-slate-500">لا توجد كفالات أو استفادات مسجلة لهذا الملف.</p></section>

            <section v-if="tab === 'documents'" class="card space-y-3" role="tabpanel"><h3 class="card-title">المستندات وسجل التغييرات</h3>
                <DocumentsPanel :person-id="person.public_id" :items="documents" :options="options" :can-edit="abilities.edit" :can-review="abilities.portal" :can-restrict="abilities.health" :history="history" /></section>
        </div>
    </AppLayout>
</template>
