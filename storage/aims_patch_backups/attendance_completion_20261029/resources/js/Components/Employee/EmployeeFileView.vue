<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AttendanceLedger from '../Attendance/AttendanceLedger.vue';
import { localToday } from '../Attendance/attendanceUi.js';
import CopyReference from '../CopyReference.vue';
import EmptyState from '../EmptyState.vue';
import StatusBadge from '../StatusBadge.vue';
import EmployeeDocuments from './EmployeeDocuments.vue';
import EmployeePayPanel from './EmployeePayPanel.vue';
import EmployeePhoto from './EmployeePhoto.vue';

/**
 * One employee file as the server allowed this viewer to see it. A tab exists only when its section was sent.
 * Horizontal contextual tabs (the application keeps a single sidebar).
 */
const props = defineProps({
    employee: { type: Object, required: true },
    sections: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
    qualifications: { type: Array, default: () => [] },
    placements: { type: Array, default: () => [] },
    photo: { type: Object, default: null },
    documents: { type: Object, default: null },
    documentTypes: { type: Array, default: () => [] },
    pay: { type: Object, default: null },
    bank: { type: Object, default: null },
    payChange: { type: Object, default: null },
    payChoices: { type: Object, default: null },
    leave: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    provenance: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
    fileLimits: { type: Object, default: () => ({}) },
});

const page = usePage();
const all = [
    ['personal', 'البيانات الشخصية', s => s.personal || s.contact || s.identity || s.address],
    ['job', 'البيانات الوظيفية', s => s.job],
    ['qualifications', 'المؤهلات العلمية', s => s.qualifications],
    ['pay', 'الرواتب والعلاوات', s => s.pay],
    ['leave', 'الأرصدة والإجازات', s => s.leave],
    ['attendance', 'الحضور والانصراف', s => s.attendance],
    ['bank', 'البيانات البنكية', s => s.bank],
    ['photo', 'الصورة الشخصية', s => s.photo],
    ['documents', 'الوثائق والمرفقات', s => s.documents],
    ['history', 'سجل التعديلات', s => s.history],
];
const tabs = computed(() => all.filter(([, , visible]) => visible(props.sections || {})));
const requested = new URLSearchParams(String(page.url).split('?')[1] || '').get('tab');
const tab = ref(tabs.value.some(([key]) => key === requested) ? requested : (tabs.value[0]?.[0] || 'job'));
watch(tab, value => {
    const base = String(page.url).split('?')[0];
    router.replace({ url: `${base}?tab=${value}`, preserveScroll: true, preserveState: true });
});

const todayLocal = localToday();
const monthStart = `${todayLocal.slice(0, 8)}01`;
const value = item => (item === null || item === undefined || item === '' ? '—' : item);
const date = item => (item ? String(item).slice(0, 10) : '—');
const days = item => (item === null || item === undefined ? '—' : Number(item).toLocaleString('en-US', { maximumFractionDigits: 2 }));
const statusLabels = { active: ['على رأس عمله', 'success'], inactive: ['غير نشط', 'muted'], on_leave: ['في إجازة', 'info'], terminated: ['انتهت الخدمة', 'danger'] };
const onboarding = { draft: ['مسودة', 'warning'], pending_activation: ['بانتظار تفعيل الحساب', 'info'], completed: ['مكتمل', 'success'] };
const addressTypes = { original: 'السكن الأصلي', current: 'السكن الحالي', previous: 'عنوان سابق' };
const placementSource = { baseline: 'الملف القائم', import: 'الاستيراد', profile: 'تعديل الملف' };
const verification = { unverified: ['غير موثّق', 'warning'], verified: ['موثّق', 'success'], rejected: ['مرفوض', 'danger'] };
const consistency = { consistent: ['متسق', 'success'], inconsistent: ['غير متسق', 'danger'], incomplete: ['ناقص', 'warning'] };
const kinship = code => props.labels?.kinship?.[code] || code;
const e = computed(() => props.employee || {});
const nameParts = computed(() => [e.value.first_name_ar, e.value.father_name_ar, e.value.grandfather_name_ar, e.value.family_name_ar].filter(Boolean).join(' '));
</script>

<template>
    <div class="space-y-4">
        <section class="card flex flex-wrap items-center gap-4">
            <img v-if="photo?.current" :src="photo.current.url" :alt="`صورة ${e.display_name}`" class="h-16 w-16 rounded-xl object-cover" />
            <div v-else class="flex h-16 w-16 items-center justify-center rounded-xl bg-navy-50 text-lg font-bold text-navy-700">{{ String(e.display_name || '؟').trim().charAt(0) }}</div>
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-xl font-bold text-navy-900">{{ e.display_name }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    <CopyReference :value="e.employee_number" label="الرقم الوظيفي" />
                    <span v-if="e.attendance_number" class="text-xs text-slate-500">بصمة: <span class="font-mono" dir="ltr">{{ e.attendance_number }}</span></span>
                    <StatusBadge v-if="statusLabels[e.employment_status]" :label="statusLabels[e.employment_status][0]" :tone="statusLabels[e.employment_status][1]" />
                    <StatusBadge v-if="onboarding[e.onboarding_status]" :label="'الملف: ' + onboarding[e.onboarding_status][0]" :tone="onboarding[e.onboarding_status][1]" />
                    <StatusBadge v-if="provenance" :label="`مستورد — الدفعة #${provenance.batch_id}، الصف ${provenance.row_number}`" tone="info" />
                </div>
            </div>
            <slot name="actions" />
        </section>

        <nav aria-label="أقسام ملف الموظف" class="card overflow-x-auto p-2">
            <div class="flex min-w-max gap-1">
                <button v-for="[key, label] in tabs" :key="key" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold transition"
                    :class="tab === key ? 'bg-navy-700 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-current="tab === key ? 'page' : undefined" @click="tab = key">{{ label }}</button>
            </div>
        </nav>

        <section v-if="tab === 'personal'" class="card space-y-5">
            <div v-if="sections.personal">
                <h3 class="card-title mb-3">الاسم والبيانات الشخصية</h3>
                <p v-if="e.name_review_status === 'suggested'" class="mb-3 rounded-lg bg-gold-50 p-2 text-xs text-gold-900">الاسم الكامل منقول من ملف الموظفين كما ورد، وتقسيمه إلى (الأول/الأب/الجد/العائلة) مقترح لم يُراجع بعد.</p>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div class="sm:col-span-2"><dt class="text-slate-500">الاسم الكامل كما ورد</dt><dd class="mt-0.5 font-semibold">{{ value(e.full_name_ar) }}</dd></div>
                    <div><dt class="text-slate-500">الاسم المقسّم</dt><dd class="mt-0.5 font-semibold">{{ value(nameParts) }}</dd></div>
                    <div><dt class="text-slate-500">العائلة الأصلية</dt><dd class="mt-0.5 font-semibold">{{ value(e.original_family_name_ar) }}</dd></div>
                    <div><dt class="text-slate-500">الجنس</dt><dd class="mt-0.5 font-semibold">{{ e.gender === 'male' ? 'ذكر' : e.gender === 'female' ? 'أنثى' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">تاريخ الميلاد</dt><dd class="mt-0.5 font-semibold" dir="ltr">{{ date(e.date_of_birth) }}</dd></div>
                    <div><dt class="text-slate-500">الحالة الاجتماعية</dt><dd class="mt-0.5 font-semibold">{{ value(labels.maritalStatus) }}</dd></div>
                    <div><dt class="text-slate-500">عدد الأبناء</dt><dd class="mt-0.5 font-semibold">{{ value(e.children_count) }}</dd></div>
                    <div><dt class="text-slate-500">الجنسية</dt><dd class="mt-0.5 font-semibold">{{ value(labels.nationality) }}</dd></div>
                    <div><dt class="text-slate-500">مكان الميلاد</dt><dd class="mt-0.5 font-semibold">{{ value(e.place_of_birth) }}</dd></div>
                    <div><dt class="text-slate-500">الاسم بالإنجليزية</dt><dd class="mt-0.5 font-semibold" dir="ltr">{{ value(e.full_name_en) }}</dd></div>
                </dl>
            </div>
            <div v-if="sections.identity">
                <h3 class="card-title mb-3">الهوية</h3>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">{{ labels.identityDocumentType || 'وثيقة الهوية' }}</dt><dd class="mt-0.5 font-mono font-semibold" dir="ltr">{{ value(e.national_id) }}</dd></div>
                </dl>
            </div>
            <div v-if="sections.contact">
                <h3 class="card-title mb-3">التواصل</h3>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">الجوال الأساسي</dt><dd class="mt-0.5 font-semibold" dir="ltr">{{ value(e.phone) }}</dd></div>
                    <div v-if="'alternative_phone' in e"><dt class="text-slate-500">الجوال الثانوي</dt><dd class="mt-0.5" dir="ltr">{{ value(e.alternative_phone) }}</dd></div>
                    <div v-if="'landline_phone' in e"><dt class="text-slate-500">الهاتف الأرضي</dt><dd class="mt-0.5" dir="ltr">{{ value(e.landline_phone) }}</dd></div>
                    <div v-if="'personal_email' in e"><dt class="text-slate-500">البريد الشخصي</dt><dd class="mt-0.5 truncate" dir="ltr">{{ value(e.personal_email) }}</dd></div>
                </dl>
            </div>
            <div v-if="sections.address">
                <h3 class="card-title mb-3">السكن</h3>
                <p class="text-sm"><span class="text-slate-500">العنوان الكامل كما ورد:</span> <span class="font-semibold">{{ value(e.residence_address) }}</span></p>
                <ul v-if="e.addresses?.length" class="mt-2 space-y-2 text-sm">
                    <li v-for="address in e.addresses" :key="address.id" class="rounded-xl border border-slate-100 p-3">
                        <p class="font-semibold text-navy-800">{{ addressTypes[address.address_type] || address.address_type }} <StatusBadge v-if="!address.is_active" label="غير فعّال" tone="muted" /></p>
                        <p class="mt-1 text-slate-600">{{ [address.governorate, address.city, address.area, address.neighborhood].filter(Boolean).join(' — ') }}</p>
                        <p v-if="address.address_details" class="mt-1 text-xs text-slate-500">{{ address.address_details }}</p>
                    </li>
                </ul>
            </div>
            <div v-if="sections.personal && e.family_members?.length">
                <h3 class="card-title mb-3">أفراد العائلة</h3>
                <table class="data-table"><thead><tr><th>الاسم</th><th>الصلة</th><th>الميلاد</th><th>مُعال</th></tr></thead>
                    <tbody><tr v-for="member in e.family_members" :key="member.id"><td>{{ member.full_name_ar }}</td><td>{{ kinship(member.relationship_code) }}</td><td dir="ltr">{{ date(member.date_of_birth) }}</td><td>{{ member.is_dependent ? 'نعم' : 'لا' }}</td></tr></tbody>
                </table>
            </div>
        </section>

        <section v-else-if="tab === 'job'" class="card space-y-5">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">القسم</dt><dd class="mt-0.5 font-semibold">{{ value(labels.department) }}<span v-if="e.department_source" class="block text-xs font-normal text-slate-500">كما ورد: {{ e.department_source }}</span></dd></div>
                <div><dt class="text-slate-500">الفئة / المسمى</dt><dd class="mt-0.5 font-semibold">{{ value(labels.jobCategory) }} / {{ value(labels.jobTitle) }}<span v-if="e.job_title_source" class="block text-xs font-normal text-slate-500">كما ورد: {{ e.job_title_source }}</span></dd></div>
                <div><dt class="text-slate-500">الدرجة</dt><dd class="mt-0.5 font-semibold">{{ value(labels.jobGrade) }}<span v-if="e.grade_source" class="block text-xs font-normal text-slate-500">كما وردت: {{ e.grade_source }}</span></dd></div>
                <div><dt class="text-slate-500">بند التوظيف</dt><dd class="mt-0.5 font-semibold">{{ value(labels.employmentTerm) }}</dd></div>
                <div><dt class="text-slate-500">نوع العقد</dt><dd class="mt-0.5 font-semibold">{{ value(labels.contractType) }}</dd></div>
                <div><dt class="text-slate-500">نظام الدوام</dt><dd class="mt-0.5 font-semibold">{{ value(labels.workSchedule) }}</dd></div>
                <div><dt class="text-slate-500">المستوى الإداري</dt><dd class="mt-0.5 font-semibold">{{ value(labels.administrativeLevel) }}</dd></div>
                <div><dt class="text-slate-500">تاريخ التعيين</dt><dd class="mt-0.5 font-semibold" dir="ltr">{{ date(e.hire_date) }}</dd></div>
                <div><dt class="text-slate-500">مباشرة العمل الفعلية</dt><dd class="mt-0.5 font-semibold" dir="ltr">{{ date(e.work_start_date) }}</dd></div>
            </dl>
            <div>
                <h3 class="card-title mb-2">سجل التنقلات</h3>
                <table v-if="placements.length" class="data-table"><thead><tr><th>من</th><th>إلى</th><th>القسم</th><th>المسمى</th><th>الدرجة</th><th>المصدر</th></tr></thead>
                    <tbody><tr v-for="p in placements" :key="p.id">
                        <td dir="ltr">{{ date(p.effective_from) }}</td><td dir="ltr">{{ p.effective_to ? date(p.effective_to) : 'حالي' }}</td>
                        <td>{{ p.department || p.department_source || '—' }}</td><td>{{ p.job_title || p.job_title_source || '—' }}</td><td>{{ p.grade || p.grade_source || '—' }}</td>
                        <td class="text-xs">{{ placementSource[p.source] || p.source }}</td>
                    </tr></tbody>
                </table>
                <p v-else class="text-sm text-slate-500">لا توجد حركات مسجلة.</p>
            </div>
        </section>

        <section v-else-if="tab === 'qualifications'" class="card">
            <EmptyState v-if="!qualifications.length" title="لا توجد مؤهلات مسجلة" />
            <ul v-else class="space-y-2 text-sm">
                <li v-for="q in qualifications" :key="q.id" class="rounded-xl border border-slate-100 p-3">
                    <p class="font-semibold text-navy-800">{{ q.qualification_name }}
                        <StatusBadge v-if="q.is_highest_qualification" label="الأعلى" tone="navy" />
                        <StatusBadge :label="(verification[q.verification_status] || [q.verification_status])[0]" :tone="(verification[q.verification_status] || [null, 'muted'])[1]" />
                        <StatusBadge v-if="q.source === 'import'" label="من ملف الموظفين" tone="info" />
                    </p>
                    <p class="mt-1 text-slate-600">{{ [q.level || q.degree_level, q.specialization, q.institution_name, q.graduation_year].filter(Boolean).join(' · ') || '—' }}</p>
                    <p v-if="q.source === 'import'" class="mt-1 text-xs text-slate-500">المؤهل منقول من ملف بيانات؛ لا يُعد توثيقًا حتى تُرفع الشهادة وتُوثّق.</p>
                </li>
            </ul>
        </section>

        <section v-else-if="tab === 'pay' || tab === 'bank'" class="card">
            <EmployeePayPanel :employee-id="e.id" :pay="tab === 'pay' ? pay : null" :bank="tab === 'bank' ? bank : null" :pay-change="payChange"
                :can-request="Boolean(can.requestPayChange)" :choices="payChoices" />
        </section>

        <section v-else-if="tab === 'leave'" class="card space-y-5">
            <div>
                <h3 class="card-title mb-2">الأرصدة في سنة {{ leave?.year }}</h3>
                <table v-if="leave?.balances?.length" class="data-table"><thead><tr><th>النوع</th><th>الافتتاحي</th><th>الحركات</th><th>المتبقي</th><th>المحجوز</th><th>المتاح</th></tr></thead>
                    <tbody><tr v-for="b in leave.balances" :key="b.leave_type_value_id"><td>{{ b.type }}</td><td>{{ b.opening_recorded ? days(b.opening) : 'غير مسجل' }}</td><td>{{ days(b.movements) }}</td><td>{{ days(b.remaining) }}</td><td>{{ days(b.reserved) }}</td><td class="font-bold">{{ days(b.available) }}</td></tr></tbody>
                </table>
            </div>
            <div>
                <h3 class="card-title mb-2">لقطات الأرصدة من ملف الموظفين</h3>
                <p class="mb-2 text-xs text-slate-500">القيم كما وردت حتى تاريخ الأساس، وتُحفظ للمطابقة؛ لا تُشتق منها حركات إجازة ولا تغيّر الرصيد إلا إذا سُجلت رصيدًا افتتاحيًا.</p>
                <table v-if="leave?.baselines?.length" class="data-table min-w-[640px]"><thead><tr><th>حتى تاريخ</th><th>النوع</th><th>السنوية</th><th>المرحّلة</th><th>المستحقة</th><th>المستنفدة</th><th>الرصيد</th><th>الاتساق</th></tr></thead>
                    <tbody><tr v-for="b in leave.baselines" :key="b.id"><td dir="ltr">{{ date(b.as_of_date) }}</td><td>{{ b.type }}</td><td>{{ days(b.annual_entitlement_days) }}</td><td>{{ days(b.carried_over_days) }}</td><td>{{ days(b.accrued_days) }}</td><td>{{ days(b.used_days) }}</td><td>{{ days(b.balance_days) }}</td>
                        <td><StatusBadge :label="(consistency[b.consistency] || [b.consistency])[0]" :tone="(consistency[b.consistency] || [null, 'muted'])[1]" /><span v-if="b.opening_balance_id" class="ms-1 text-xs text-emerald-700">سُجّل افتتاحيًا</span></td></tr></tbody>
                </table>
                <p v-else class="text-sm text-slate-500">لا توجد لقطات.</p>
            </div>
            <div v-if="leave?.opening?.length">
                <h3 class="card-title mb-2">الأرصدة الافتتاحية المسجلة</h3>
                <table class="data-table"><thead><tr><th>النوع</th><th>السنة</th><th>الاستحقاق</th><th>الافتتاحي</th><th>المرجع</th></tr></thead>
                    <tbody><tr v-for="o in leave.opening" :key="o.id"><td>{{ o.type }}</td><td>{{ o.balance_year }}</td><td>{{ days(o.annual_entitlement_days) }}</td><td>{{ days(o.opening_balance_days) }}</td><td class="text-xs">{{ o.notes }}</td></tr></tbody>
                </table>
            </div>
        </section>

        <section v-else-if="tab === 'attendance'" class="space-y-3">
            <AttendanceLedger mode="employee" :endpoint="`/employee-files/${e.id}/attendance`" :export-url="`/employee-files/${e.id}/attendance/export.xlsx`"
                :day-url="(employeeId, date) => `/employee-files/${e.id}/attendance/days/${date}`" :default-from="monthStart" :default-to="todayLocal" />
        </section>

        <section v-else-if="tab === 'photo'" class="card">
            <EmployeePhoto :employee-id="e.id" :name="e.display_name" :photo="photo" :can-upload="Boolean(can.uploadPhoto)" :can-review="Boolean(can.verify)"
                :can-remove="Boolean(can.manage)" :max-kb="Math.min(fileLimits.photoKb || 2048, fileLimits.phpKb || 2048)" :self-service="Boolean(can.self) && !can.manage" />
        </section>

        <section v-else-if="tab === 'documents'" class="card">
            <EmployeeDocuments :employee-id="e.id" :documents="documents" :types="documentTypes" :qualifications="qualifications"
                :can-upload="Boolean(can.uploadDocuments) && documentTypes.length > 0" :can-manage="Boolean(can.manage)" :can-verify="Boolean(can.verify)"
                :max-kb="Math.min(fileLimits.documentKb || 10240, fileLimits.phpKb || 2048)" />
            <p v-if="fileLimits.phpKb && fileLimits.phpKb < (fileLimits.documentKb || 0)" class="mt-2 text-xs text-slate-500">حد الرفع الفعلي {{ fileLimits.phpKb }} ك.ب بسبب إعداد الخادم (upload_max_filesize).</p>
        </section>

        <section v-else-if="tab === 'history'" class="card">
            <ul v-if="history.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="h in history" :key="h.id" class="py-2">
                    <span class="font-semibold text-navy-800">{{ h.action }}</span> — {{ h.actor }} <span class="text-xs text-slate-500" dir="ltr">{{ String(h.at).slice(0, 16) }}</span>
                    <p v-if="h.fields.length" class="text-xs text-slate-500">الحقول: {{ h.fields.join('، ') }}</p>
                </li>
            </ul>
            <p v-else class="text-sm text-slate-500">لا توجد أحداث مسجلة.</p>
        </section>
    </div>
</template>
