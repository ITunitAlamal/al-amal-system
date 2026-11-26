<script setup>
import GeographicAddress from '../../../Components/GeographicAddress.vue';
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import FormErrorSummary from '../../../Components/FormErrorSummary.vue';
import { useUnsavedChanges } from '../../../Support/useUnsavedChanges.js';
import { employeeErrorStep } from '../../../Support/employeeFormErrors.js';

const props = defineProps({
  username: { type: String, default: '' },
  navigation: { type: Array, default: () => [] },
  employee: { type: Object, default: null },
  // Completing the profile of an account that already signs in (number, department, first and family name).
  prefill: { type: Object, default: null },
  lookups: { type: Object, default: () => ({}) },
  leaveOnboarding: { type: Object, default: () => ({}) },
});

const base = '/admin/employee-profiles';
const editing = computed(() => Boolean(props.employee?.id));
const step = ref(0);
const steps = ['البيانات الأساسية', 'بيانات التعليم', 'بيانات العمل', 'بيانات العائلة', 'الإجازات'];
const wizardError = ref('');
const reviewConfirmed = ref(false);

// Constants lists from «ثوابت النظام». Values the employee already holds stay listed even if deactivated.
const masterOptions = type => (props.lookups?.masterData ?? []).filter(x => x.type_code === type);
const selectable = (x, selected) => x.selectable !== false || String(x.id) === String(selected);
const constantsLink = type => props.lookups?.constantsLinks?.[type] ?? null;
const governorates = computed(() => masterOptions('governorates'));
const kinshipTypes = computed(() => masterOptions('kinship_types'));
const commonDegrees = ['ثانوية عامة', 'دبلوم متوسط', 'بكالوريوس', 'دبلوم عالٍ', 'ماجستير', 'دكتوراه'];
// New profiles start with the Palestinian identity card and nationality; HR changes them for other documents.
const defaultValue = (type, code) => (props.employee ? '' : (masterOptions(type).find(x => x.code === code)?.id ?? ''));

const yesNo = value => (value === null || value === undefined ? '' : (Boolean(value) ? 'yes' : 'no'));
const form = useForm({
  employee_number: props.employee?.employee_number ?? props.prefill?.employee_number ?? '',
  link_existing_account: Boolean(props.prefill),
  activation_method: 'sms',
  temporary_password: '',
  temporary_password_confirmation: '',
  first_name_ar: props.employee?.first_name_ar ?? props.prefill?.first_name_ar ?? '',
  father_name_ar: props.employee?.father_name_ar ?? '',
  grandfather_name_ar: props.employee?.grandfather_name_ar ?? '',
  family_name_ar: props.employee?.family_name_ar ?? props.prefill?.family_name_ar ?? '',
  full_name_en: props.employee?.full_name_en ?? '',
  full_name_ar: props.employee?.full_name_ar ?? '',
  original_family_name_ar: props.employee?.original_family_name_ar ?? '',
  name_parts_reviewed: false,
  children_count: props.employee?.children_count ?? '',
  landline_phone: props.employee?.landline_phone ?? '',
  residence_address: props.employee?.residence_address ?? '',
  attendance_number: props.employee?.attendance_number ?? '',
  national_id: props.employee?.national_id ?? '',
  identity_document_type_value_id: props.employee?.identity_document_type_value_id ?? defaultValue('identity_document_types', 'palestinian_id'),
  nationality_value_id: props.employee?.nationality_value_id ?? defaultValue('nationalities', 'palestinian'),
  date_of_birth: props.employee?.date_of_birth?.slice(0, 10) ?? '',
  gender: props.employee?.gender ?? '',
  place_of_birth: props.employee?.place_of_birth ?? '',
  marital_status_value_id: props.employee?.marital_status_value_id ?? '',
  has_qualifications: yesNo(props.employee?.has_qualifications),
  has_family_members: yesNo(props.employee?.has_family_members),
  employment_term_value_id: props.employee?.employment_term_value_id ?? '',
  contract_type_value_id: props.employee?.contract_type_value_id ?? '',
  work_schedule_value_id: props.employee?.work_schedule_value_id ?? '',
  administrative_level_value_id: props.employee?.administrative_level_value_id ?? '',
  confirm_no_opening_balances: false,
  leave_opening_balances: [],
  phone: props.employee?.phone ?? '',
  alternative_phone: props.employee?.alternative_phone ?? '',
  personal_email: props.employee?.personal_email ?? '',
  department_id: props.employee?.department_id ?? props.prefill?.department_id ?? '',
  job_category_id: props.employee?.job_category_id ?? '',
  job_title_id: props.employee?.job_title_id ?? '',
  job_grade_id: props.employee?.job_grade_id ?? '',
  hire_date: props.employee?.hire_date?.slice(0, 10) ?? '',
  work_start_date: props.employee?.work_start_date?.slice(0, 10) ?? '',
  employment_status: props.employee?.employment_status ?? 'active',
  notes: props.employee?.notes ?? '',
  addresses: (props.employee?.addresses ?? []).map(x => ({ ...x, valid_from: x.valid_from?.slice(0, 10) ?? '', valid_to: x.valid_to?.slice(0, 10) ?? '' })),
  qualifications: (props.employee?.qualifications ?? []).map(x => ({ ...x, study_start_date: x.study_start_date?.slice(0, 10) ?? '', graduation_date: x.graduation_date?.slice(0, 10) ?? '' })),
  family_members: (props.employee?.family_members ?? []).map(x => ({ ...x, date_of_birth: x.date_of_birth?.slice(0, 10) ?? '' })),
});

const departments = computed(() => props.lookups?.departments ?? []);
useUnsavedChanges(() => form.isDirty);
const categories = computed(() => props.lookups?.jobCategories ?? []);
const titles = computed(() => (props.lookups?.jobTitles ?? []).filter(x => String(x.job_category_id) === String(form.job_category_id)));
const grades = computed(() => props.lookups?.jobGrades ?? []);
const activeOption = (x, selected) => Boolean(x.is_active) || String(x.id) === String(selected);
const activeGrade = x => (Boolean(x.is_active) && Boolean(x.scale_is_active)) || String(x.id) === String(form.job_grade_id);
function changeCategory() { form.job_title_id = ''; }

// Dates: a new employee must be at least 15 years old (Palestinian Labour Law).
const isoDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const today = isoDate(new Date());
const latestBirthDate = (() => { const d = new Date(); d.setFullYear(d.getFullYear() - 15); return isoDate(d); })();
const age = computed(() => {
  if (!form.date_of_birth) return null;
  const d = new Date(`${form.date_of_birth}T12:00:00`);
  if (Number.isNaN(d.getTime())) return null;
  const now = new Date();
  let years = now.getFullYear() - d.getFullYear();
  if (now.getMonth() < d.getMonth() || (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())) years--;
  return years >= 0 && years <= 130 ? years : null;
});

// Arabic-keyboard digits are accepted; the server converts them too.
const latinDigits = value => String(value ?? '').replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0));
const phonePattern = /^\+?[0-9]{8,15}$/;
// Palestinian identity numbers end with a check digit; a mismatch usually means a typing mistake.
function identityChecksumOk(raw) {
  const id = latinDigits(raw).trim();
  if (!/^[0-9]{9}$/.test(id)) return false;
  let sum = 0;
  for (let i = 0; i < 9; i++) {
    let digit = Number(id[i]) * ((i % 2) + 1);
    if (digit > 9) digit -= 9;
    sum += digit;
  }
  return sum % 10 === 0;
}
// The 9-digit rule applies to the Palestinian identity card only; passports and foreign cards keep their own format.
const isPalestinianId = computed(() => masterOptions('identity_document_types').find(x => String(x.id) === String(form.identity_document_type_value_id))?.code === 'palestinian_id');
const identityWarning = computed(() => {
  const id = latinDigits(form.national_id).trim();
  if (!id || !isPalestinianId.value) return '';
  if (!/^[0-9]{9}$/.test(id)) return 'رقم الهوية الفلسطينية يتكون من 9 أرقام.';
  return identityChecksumOk(id) ? '' : 'تنبيه: رقم الهوية لا يجتاز فحص الرقم الأخير المعتاد. تأكد منه قبل المتابعة.';
});
// A family member's number is checked only when it looks like a Palestinian identity number.
const familyIdWarning = raw => {
  const id = latinDigits(raw).trim();
  return /^[0-9]{9}$/.test(id) && !identityChecksumOk(id) ? 'تأكد من رقم الهوية؛ الرقم الأخير لا يطابق الفحص المعتاد.' : '';
};

// Fields the final onboarding requires (new profiles). The server enforces the same list.
const labels = {
  employee_number: 'الرقم الوظيفي', first_name_ar: 'الاسم الأول', father_name_ar: 'اسم الأب', grandfather_name_ar: 'اسم الجد',
  family_name_ar: 'اسم العائلة', identity_document_type_value_id: 'نوع الوثيقة', national_id: 'رقم الوثيقة', nationality_value_id: 'الجنسية',
  gender: 'الجنس', date_of_birth: 'تاريخ الميلاد', place_of_birth: 'مكان الميلاد',
  phone: 'الجوال الأساسي', marital_status_value_id: 'الحالة الاجتماعية', department_id: 'القسم', job_category_id: 'الفئة الوظيفية',
  job_title_id: 'المسمى الوظيفي', hire_date: 'تاريخ التعيين الرسمي', work_start_date: 'تاريخ مباشرة العمل الفعلي',
  employment_term_value_id: 'بند التوظيف', contract_type_value_id: 'نوع العقد',
};
const stageFields = {
  0: ['employee_number', 'first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar', 'identity_document_type_value_id', 'national_id', 'nationality_value_id', 'gender', 'date_of_birth', 'place_of_birth', 'phone', 'marital_status_value_id'],
  2: ['department_id', 'job_category_id', 'job_title_id', 'hire_date', 'work_start_date', 'employment_term_value_id', 'contract_type_value_id'],
};
const alwaysRequired = ['first_name_ar', 'family_name_ar', 'phone', 'employee_number'];
const required = key => alwaysRequired.includes(key) || (!editing.value && Object.values(stageFields).flat().includes(key));

const relatedFields = {
  addresses: ['id', 'governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id', 'address_other', 'address_type', 'governorate', 'city', 'area', 'neighborhood', 'address_details', 'notes', 'valid_from', 'valid_to', 'is_active'],
  qualifications: ['id', 'qualification_name', 'degree_level', 'institution_name', 'specialization', 'issuing_country_code', 'study_start_date', 'graduation_date', 'graduation_year', 'grade', 'gpa', 'gpa_scale', 'certificate_number', 'notes', 'is_highest_qualification'],
  family_members: ['id', 'relationship_code', 'full_name_ar', 'full_name_en', 'national_id', 'date_of_birth', 'phone', 'notes', 'is_dependent', 'is_active'],
};
function addAddress() {
  const hasOriginal = form.addresses.some(a => a.address_type === 'original' && a.is_active);
  const hasCurrent = form.addresses.some(a => a.address_type === 'current' && a.is_active);
  const type = !hasOriginal ? 'original' : !hasCurrent ? 'current' : 'previous';
  form.addresses.push({ governorate_value_id: null, locality_value_id: null, area_value_id: null, subarea_value_id: null, address_other: false, address_type: type, governorate: '', city: '', area: '', neighborhood: '', address_details: '', notes: '', valid_from: '', valid_to: '', is_active: true });
}
function copyOriginalToCurrent(index) {
  const original = form.addresses.find(a => a.address_type === 'original' && a.is_active);
  if (!original) return;
  Object.assign(form.addresses[index], { governorate_value_id: original.governorate_value_id, locality_value_id: original.locality_value_id, area_value_id: original.area_value_id, subarea_value_id: original.subarea_value_id, address_other: original.address_other, governorate: original.governorate, city: original.city, area: original.area, neighborhood: original.neighborhood, address_details: original.address_details });
}
function addQualification() {
  form.has_qualifications = 'yes';
  form.qualifications.push({ qualification_name: '', degree_level: '', institution_name: '', specialization: '', issuing_country_code: '', study_start_date: '', graduation_date: '', graduation_year: '', grade: '', gpa: '', gpa_scale: '', certificate_number: '', notes: '', is_highest_qualification: form.qualifications.length === 0 });
}
function addFamilyMember() {
  form.has_family_members = 'yes';
  form.family_members.push({ relationship_code: '', full_name_ar: '', full_name_en: '', national_id: '', date_of_birth: '', phone: '', notes: '', is_dependent: false, is_active: true });
}
function discardNewRow(key, index) {
  if (form[key][index]?.id) return;
  form[key].splice(index, 1);
}
function markHighest(index) {
  form.qualifications.forEach((q, i) => { q.is_highest_qualification = i === index; });
}
// Old values that are not in today's constants list (kept so the select shows what is stored).
const legacyGovernorate = a => a.governorate && !governorates.value.some(x => x.name_ar === a.governorate);
const legacyKinship = m => m.relationship_code && !kinshipTypes.value.some(x => x.code === m.relationship_code);

const activeCollection = computed(() => (step.value === 0 ? 'addresses' : step.value === 1 ? 'qualifications' : step.value === 3 ? 'family_members' : null));
function errorFor(key, index, name) { return form.errors[`${key}.${index}.${name}`]; }
function filled(value) { return value !== null && value !== undefined && String(value).trim() !== ''; }
const missingList = keys => keys.filter(k => !filled(form[k])).map(k => labels[k]);

function validateStage(index) {
  if (editing.value) return '';
  if (index === 0) {
    const missing = missingList(stageFields[0]);
    if (missing.length) return `أكمل الحقول الإلزامية: ${missing.join('، ')}.`;
    if (!/^[A-Za-z0-9_-]+$/.test(String(form.employee_number).trim())) return 'الرقم الوظيفي يقبل الأرقام والحروف الإنجليزية والشرطة فقط، دون مسافات.';
    if (isPalestinianId.value && !/^[0-9]{9}$/.test(latinDigits(form.national_id).trim())) return 'رقم الهوية الفلسطينية يتكون من 9 أرقام.';
    if (!isPalestinianId.value && !/^[A-Za-z0-9]+$/.test(latinDigits(form.national_id).trim())) return 'رقم الوثيقة يقبل الأرقام والحروف الإنجليزية فقط، دون مسافات أو شرطات.';
    if (!phonePattern.test(latinDigits(form.phone).trim())) return 'الجوال الأساسي يتكون من 8 إلى 15 رقمًا، ويجوز أن يبدأ بـ +، دون مسافات.';
    if (filled(form.alternative_phone) && !phonePattern.test(latinDigits(form.alternative_phone).trim())) return 'الجوال الاحتياطي يتكون من 8 إلى 15 رقمًا، ويجوز أن يبدأ بـ +.';
    if (form.date_of_birth > latestBirthDate) return 'تاريخ الميلاد يجعل عمر الموظف أقل من 15 سنة. راجع التاريخ.';
    for (const [type, label] of [['original', 'الأصلي'], ['current', 'الحالي']]) {
      const rows = form.addresses.filter(a => a.address_type === type && a.is_active);
      if (rows.length !== 1) return `أدخل عنوان السكن ${label} (عنوانًا نشطًا واحدًا).`;
    }
    if (form.addresses.some(a => !a.address_other && (!filled(a.governorate) || !filled(a.city)))) return 'أكمل المحافظة والمدينة في جميع العناوين.';
  }
  if (index === 1) {
    if (!['yes', 'no'].includes(form.has_qualifications)) return 'حدد ما إذا كان لدى الموظف مؤهلات علمية.';
    if (form.has_qualifications === 'yes' && !form.qualifications.length) return 'أضف مؤهلًا علميًا واحدًا على الأقل.';
    if (form.has_qualifications === 'no' && form.qualifications.length) return 'لا يمكن اختيار عدم وجود مؤهلات مع إضافة مؤهلات.';
    if (form.qualifications.some(q => !filled(q.qualification_name))) return 'أكمل اسم المؤهل لكل مؤهل.';
  }
  if (index === 2) {
    const missing = missingList(stageFields[2]);
    if (missing.length) return `أكمل الحقول الإلزامية: ${missing.join('، ')}.`;
  }
  if (index === 3) {
    if (!['yes', 'no'].includes(form.has_family_members)) return 'حدد ما إذا كان لدى الموظف أفراد عائلة مطلوب تسجيلهم.';
    if (form.has_family_members === 'yes' && !form.family_members.length) return 'أضف فرد عائلة واحدًا على الأقل.';
    if (form.has_family_members === 'no' && form.family_members.length) return 'لا يمكن اختيار عدم وجود أفراد مع إضافة أفراد عائلة.';
    if (form.family_members.length && !kinshipTypes.value.length) return 'قائمة صلات القرابة فارغة في ثوابت النظام. اطلب من مدير النظام إضافتها، أو اختر «لا يوجد أفراد عائلة للتسجيل» مؤقتًا.';
    if (form.family_members.some(m => !filled(m.relationship_code) || !filled(m.full_name_ar))) return 'أكمل صلة القرابة والاسم لكل فرد عائلة.';
  }
  if (index === 4) {
    if (!form.leave_opening_balances.length && !form.confirm_no_opening_balances) return 'أدخل أرصدة الإجازات أو أكّد عدم وجود أرصدة افتتاحية.';
    if (form.leave_opening_balances.length && form.confirm_no_opening_balances) return 'لا يمكن إدخال أرصدة مع تأكيد عدم وجودها.';
    if (form.leave_opening_balances.some(x => !filled(x.leave_type_value_id) || !filled(x.annual_entitlement_days) || !filled(x.opening_balance_days))) return 'أكمل نوع الإجازة والاستحقاق والرصيد لكل سجل.';
    if (form.leave_opening_balances.some(x => String(x.notes ?? '').trim().length < 5)) return 'اكتب مرجع المستند المعتمد لكل رصيد افتتاحي.';
    if (!reviewConfirmed.value) return 'يجب تأكيد مراجعة بيانات المراحل الخمس قبل الاعتماد.';
  }
  return '';
}
function nextStep() {
  wizardError.value = validateStage(step.value);
  if (!wizardError.value && step.value < 4) step.value++;
}
function previousStep() { wizardError.value = ''; if (step.value > 0) step.value--; }
function goToStep(index) {
  if (editing.value || index <= step.value) { wizardError.value = ''; step.value = index; }
}
function addLeaveBalance() {
  form.confirm_no_opening_balances = false;
  form.leave_opening_balances.push({ leave_type_value_id: '', balance_year: new Date().getFullYear(), annual_entitlement_days: '', opening_balance_days: '', notes: '' });
}
function removeLeaveBalance(index) { form.leave_opening_balances.splice(index, 1); }

function save() {
  if (form.processing || (editing.value && step.value === 4)) return;
  if (!editing.value) {
    for (let i = 0; i < 5; i++) {
      wizardError.value = validateStage(i);
      if (wizardError.value) { step.value = i; return; }
    }
  }
  wizardError.value = '';
  const nullable = ['father_name_ar', 'grandfather_name_ar', 'full_name_en', 'national_id', 'identity_document_type_value_id', 'nationality_value_id', 'date_of_birth', 'gender', 'alternative_phone', 'personal_email', 'department_id', 'job_category_id', 'job_title_id', 'job_grade_id', 'hire_date', 'work_start_date', 'notes', 'place_of_birth', 'marital_status_value_id', 'employment_term_value_id', 'contract_type_value_id', 'work_schedule_value_id', 'administrative_level_value_id'];
  form.transform(data => {
    const payload = { ...data };
    for (const key of nullable) if (payload[key] === '') payload[key] = null;
    for (const key of ['phone', 'alternative_phone', 'national_id']) if (payload[key]) payload[key] = latinDigits(payload[key]).trim();
    payload.has_qualifications = data.has_qualifications === '' ? null : data.has_qualifications === 'yes';
    payload.has_family_members = data.has_family_members === '' ? null : data.has_family_members === 'yes';
    if (editing.value) {
      delete payload.employee_number; delete payload.link_existing_account;
      delete payload.activation_method; delete payload.temporary_password; delete payload.temporary_password_confirmation;
      delete payload.confirm_no_opening_balances; delete payload.leave_opening_balances;
      // Each step saves its own answer: the qualifications question on step 2, the family question on step 4.
      if (step.value !== 1) delete payload.has_qualifications;
      if (step.value !== 3) delete payload.has_family_members;
    } else {
      payload.leave_opening_balances = data.leave_opening_balances.map(row => ({ ...row, annual_entitlement_days: Number(row.annual_entitlement_days), opening_balance_days: Number(row.opening_balance_days) }));
    }
    for (const key of Object.keys(relatedFields)) {
      if (editing.value && activeCollection.value !== key) {
        delete payload[key];
      } else {
        payload[key] = data[key].map(row => {
          const out = {};
          for (const field of relatedFields[key]) {
            if (row[field] !== undefined && row[field] !== '') out[field] = row[field];
            else if (row[field] === '') out[field] = null;
          }
          return out;
        });
      }
    }
    return payload;
  });
  const options = {
    preserveScroll: true,
    onError: errors => {
      const keys = Object.keys(errors);
      if (keys.some(k => k.startsWith('addresses'))) step.value = 0;
      else if (keys.some(k => ['employee_number', 'first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar', 'national_id', 'identity_document_type_value_id', 'nationality_value_id', 'gender', 'date_of_birth', 'place_of_birth', 'phone', 'alternative_phone', 'personal_email', 'marital_status_value_id', 'full_name_en', 'full_name_ar', 'original_family_name_ar', 'children_count', 'landline_phone', 'residence_address'].includes(k))) step.value = 0;
      else if (keys.some(k => k.startsWith('qualifications') || k === 'has_qualifications')) step.value = 1;
      else if (keys.some(k => ['department_id', 'job_category_id', 'job_title_id', 'job_grade_id', 'hire_date', 'work_start_date', 'employment_status', 'employment_term_value_id', 'contract_type_value_id', 'work_schedule_value_id', 'administrative_level_value_id', 'notes', 'attendance_number'].includes(k))) step.value = 2;
      else if (keys.some(k => k.startsWith('family_members') || k === 'has_family_members')) step.value = 3;
      else if (keys.some(k => k.startsWith('leave_opening_balances') || k === 'confirm_no_opening_balances')) step.value = 4;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
  };
  if (editing.value) form.put(`${base}/${props.employee.id}`, options);
  else form.post(base, options);
}
</script>

<template>
  <AppLayout :title="editing ? 'تعديل ملف الموظف' : 'موظف جديد'">
    <div class="mx-auto max-w-6xl space-y-5 pb-10">
      <nav aria-label="مسار التنقل" class="flex flex-wrap gap-2 text-sm text-slate-600">
        <Link href="/ui-preview" class="text-blue-700 hover:underline">الرئيسية</Link><span aria-hidden="true">←</span>
        <Link :href="base" class="text-blue-700 hover:underline">ملفات الموظفين</Link><span aria-hidden="true">←</span>
        <span class="font-semibold">{{ editing ? 'تعديل موظف' : 'موظف جديد' }}</span>
      </nav>
      <PageHeader eyebrow="الموارد البشرية" :title="editing ? 'تعديل بيانات الموظف' : 'إضافة ملف موظف'" :description="`الخطوة ${step + 1} من 5 — ${steps[step]}`" />

      <div v-if="prefill" role="status" class="alert-info leading-7">
        <strong>استكمال ملف موظف له حساب دخول:</strong> «{{ prefill.account_name }}» — الرقم الوظيفي <span dir="ltr">{{ prefill.employee_number }}</span>.
        يُربط الملف بحسابه الحالي دون تغيير كلمة مروره أو صلاحياته، ولا تُرسل رسالة تفعيل. أكمل الاسم الرباعي وباقي البيانات، وتأكد من هويته.
      </div>
      <div v-else-if="!editing" role="status" class="alert-info space-y-3 leading-7">
        <template v-if="lookups.canActivateDirectly">
          <fieldset class="space-y-1">
            <legend class="font-semibold">طريقة تفعيل حساب الدخول</legend>
            <label class="flex items-start gap-2"><input v-model="form.activation_method" type="radio" value="sms" class="mt-1.5" /> <span><strong>برسالة SMS:</strong> يُنشأ الحساب بانتظار التفعيل ويُرسل الرمز إلى الجوال الأساسي، ويختار الموظف كلمة مروره.</span></label>
            <label class="flex items-start gap-2"><input v-model="form.activation_method" type="radio" value="direct" class="mt-1.5" /> <span><strong>حساب مفعّل الآن دون SMS (مدير النظام):</strong> بكلمة مرور مؤقتة تسلّمها للموظف شخصيًا، ويُلزَم بتغييرها عند أول دخول.</span></label>
          </fieldset>
          <div v-if="form.activation_method === 'direct'" class="grid gap-2 sm:grid-cols-2">
            <input v-model="form.temporary_password" data-error-field="temporary_password" type="password" class="field" minlength="10" maxlength="128" autocomplete="new-password" placeholder="كلمة مرور مؤقتة (10 أحرف على الأقل)" aria-label="كلمة مرور مؤقتة" />
            <input v-model="form.temporary_password_confirmation" type="password" class="field" minlength="10" maxlength="128" autocomplete="new-password" placeholder="تأكيد كلمة المرور المؤقتة" aria-label="تأكيد كلمة المرور المؤقتة" />
            <p v-if="form.errors.temporary_password" class="field-error sm:col-span-2">{{ form.errors.temporary_password }}</p>
          </div>
        </template>
        <p v-else><strong>تفعيل الحساب برسالة SMS:</strong> عند اعتماد الملف يُنشأ حساب دخول بانتظار التفعيل، ويُرسل رمز التفعيل إلى الجوال الأساسي بعد الحفظ مباشرة. إذا تعذر الإرسال يبقى الملف محفوظًا، وتعيد الإرسال من صفحة الموظف. لا يلزم بريد إلكتروني.</p>
      </div>
      <p v-else class="alert-info">كل خطوة تُحفظ وحدها بزر «حفظ بيانات هذه الخطوة». الرقم الوظيفي والقسم يُعدّلان من «حسابات الموظفين والأدوار» ليبقى الحساب والملف متطابقين.</p>

      <ol aria-label="خطوات نموذج الموظف" class="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <li v-for="(label, i) in steps" :key="label">
          <button type="button" :aria-current="step === i ? 'step' : undefined" :disabled="!editing && i > step" @click="goToStep(i)"
            class="flex h-full w-full flex-col items-center gap-1 rounded-xl border px-2 py-3 text-center text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
            :class="step === i ? 'border-navy-300 bg-navy-50 text-navy-800 ring-2 ring-gold-300' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'">
            <span class="flex h-7 w-7 items-center justify-center rounded-full" :class="step === i ? 'bg-navy-700 text-white' : (!editing && i < step ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600')">{{ !editing && i < step ? '✓' : i + 1 }}</span>
            <span>{{ label }}</span>
          </button>
        </li>
      </ol>

      <form novalidate class="aims-long-form space-y-5" @submit.prevent="save">
        <FormErrorSummary :errors="form.errors" @select="step = employeeErrorStep($event)" />
        <div v-if="wizardError" role="alert" class="alert-error">{{ wizardError }}</div>
        <p class="text-sm leading-6 text-slate-700">الحقول المعلّمة بـ <span class="text-red-700">*</span> إلزامية لاعتماد الملف. راجع الإرشادات أسفل الحقول قبل الانتقال إلى الخطوة التالية.</p>

        <!-- Step 1: personal data, contact and addresses -->
        <template v-if="step === 0">
          <section class="card md:p-7">
            <h2 class="card-title mb-5">البيانات الشخصية</h2>
            <div class="grid gap-5 md:grid-cols-2">
              <div>
                <label for="employee_number" class="field-label">الرقم الوظيفي <span class="text-red-700">*</span></label>
                <input id="employee_number" v-model.trim="form.employee_number" data-error-field="employee_number" :disabled="editing" :readonly="Boolean(prefill)" maxlength="50" dir="ltr" autocomplete="off" required class="field disabled:bg-slate-100 read-only:bg-slate-50" />
                <p class="mt-1 text-xs text-slate-500">هو اسم المستخدم لتسجيل الدخول: أرقام وحروف إنجليزية دون مسافات.</p>
                <p v-if="form.errors.employee_number" class="field-error">{{ form.errors.employee_number }}</p>
                <label v-if="!editing" class="mt-3 flex items-start gap-2 rounded-lg bg-blue-50 p-3 text-sm text-slate-800">
                  <input v-model="form.link_existing_account" data-error-field="link_existing_account" type="checkbox" class="mt-1" />
                  <span>لهذا الموظف حساب دخول سابق بالرقم الوظيفي نفسه، وأؤكد بعد مراجعة الهوية أنني أريد ربط الملف بالحساب الموجود دون تعديل كلمة المرور أو الصلاحيات أو حالة التفعيل.</span>
                </label>
              </div>
              <div v-for="field in [
                { key: 'first_name_ar', label: 'الاسم الأول' },
                { key: 'father_name_ar', label: 'اسم الأب' },
                { key: 'grandfather_name_ar', label: 'اسم الجد' },
                { key: 'family_name_ar', label: 'اسم العائلة' },
              ]" :key="field.key">
                <label :for="field.key" class="field-label">{{ field.label }} <span v-if="required(field.key)" class="text-red-700">*</span></label>
                <input :id="field.key" v-model.trim="form[field.key]" :data-error-field="field.key" type="text" maxlength="150" class="field" :aria-invalid="Boolean(form.errors[field.key])" />
                <p v-if="form.errors[field.key]" class="field-error">{{ form.errors[field.key] }}</p>
              </div>
              <div>
                <label for="full_name_ar" class="field-label">الاسم الكامل كما في الوثائق</label>
                <input id="full_name_ar" v-model.trim="form.full_name_ar" data-error-field="full_name_ar" type="text" maxlength="255" class="field" />
                <p class="mt-1 text-xs text-slate-500">يُعرض كما هو في الملف والقوائم؛ يبقى التقسيم أعلاه للبحث والمطابقة.</p>
                <p v-if="form.errors.full_name_ar" class="field-error">{{ form.errors.full_name_ar }}</p>
              </div>
              <div>
                <label for="original_family_name_ar" class="field-label">العائلة الأصلية</label>
                <input id="original_family_name_ar" v-model.trim="form.original_family_name_ar" data-error-field="original_family_name_ar" type="text" maxlength="150" class="field" />
                <p v-if="form.errors.original_family_name_ar" class="field-error">{{ form.errors.original_family_name_ar }}</p>
              </div>
              <label v-if="editing && employee?.name_review_status === 'suggested'" class="flex items-start gap-2 rounded-lg bg-gold-50 p-3 text-sm text-slate-800 md:col-span-2">
                <input v-model="form.name_parts_reviewed" type="checkbox" class="mt-1" />
                <span>تقسيم الاسم أعلاه مقترح من الاستيراد. راجعت الأسماء (الأول/الأب/الجد/العائلة) مع الوثيقة وأعتمدها.</span>
              </label>
              <div>
                <label for="full_name_en" class="field-label">الاسم الكامل بالإنجليزية</label>
                <input id="full_name_en" v-model.trim="form.full_name_en" data-error-field="full_name_en" type="text" maxlength="255" dir="ltr" class="field" />
                <p v-if="form.errors.full_name_en" class="field-error">{{ form.errors.full_name_en }}</p>
              </div>
              <div>
                <label for="document_type" class="field-label">نوع الوثيقة <span v-if="required('identity_document_type_value_id')" class="text-red-700">*</span></label>
                <select id="document_type" v-model="form.identity_document_type_value_id" data-error-field="identity_document_type_value_id" class="field" :aria-invalid="Boolean(form.errors.identity_document_type_value_id)">
                  <option value="">اختر نوع الوثيقة</option>
                  <option v-for="x in masterOptions('identity_document_types')" :key="x.id" :value="x.id" :disabled="!selectable(x, form.identity_document_type_value_id)">{{ x.name_ar }}{{ x.selectable === false ? ' (غير نشط)' : '' }}</option>
                </select>
                <p v-if="form.errors.identity_document_type_value_id" class="field-error">{{ form.errors.identity_document_type_value_id }}</p>
              </div>
              <div>
                <label for="national_id" class="field-label">{{ isPalestinianId ? 'رقم الهوية' : 'رقم الوثيقة' }} <span v-if="required('national_id')" class="text-red-700">*</span></label>
                <input id="national_id" v-model.trim="form.national_id" data-error-field="national_id" type="text" :inputmode="isPalestinianId ? 'numeric' : 'text'" :maxlength="isPalestinianId ? 9 : 30" dir="ltr" autocomplete="off" class="field" :aria-invalid="Boolean(form.errors.national_id)" />
                <p v-if="!form.errors.national_id && !identityWarning" class="mt-1 text-xs text-slate-500">{{ isPalestinianId ? '9 أرقام.' : 'كما هو مكتوب في الوثيقة: أرقام وحروف إنجليزية دون مسافات.' }}</p>
                <p v-if="form.errors.national_id" class="field-error">{{ form.errors.national_id }}</p>
                <p v-else-if="identityWarning" class="mt-1 text-xs text-gold-800">{{ identityWarning }}</p>
              </div>
              <div>
                <label for="nationality" class="field-label">الجنسية <span v-if="required('nationality_value_id')" class="text-red-700">*</span></label>
                <select id="nationality" v-model="form.nationality_value_id" data-error-field="nationality_value_id" class="field" :aria-invalid="Boolean(form.errors.nationality_value_id)">
                  <option value="">اختر الجنسية</option>
                  <option v-for="x in masterOptions('nationalities')" :key="x.id" :value="x.id" :disabled="!selectable(x, form.nationality_value_id)">{{ x.name_ar }}{{ x.selectable === false ? ' (غير نشطة)' : '' }}</option>
                </select>
                <p v-if="masterOptions('nationalities').length < 2" class="mt-1 text-xs text-slate-500">جنسية أخرى؟ يضيفها مدير النظام في «ثوابت النظام ← البيانات الاجتماعية ← الجنسيات». <Link v-if="constantsLink('nationalities')" :href="constantsLink('nationalities')" class="font-semibold underline">إضافة</Link></p>
                <p v-if="form.errors.nationality_value_id" class="field-error">{{ form.errors.nationality_value_id }}</p>
              </div>
              <div>
                <label for="gender" class="field-label">الجنس <span v-if="required('gender')" class="text-red-700">*</span></label>
                <select id="gender" v-model="form.gender" data-error-field="gender" class="field"><option value="">اختر</option><option value="male">ذكر</option><option value="female">أنثى</option></select>
                <p v-if="form.errors.gender" class="field-error">{{ form.errors.gender }}</p>
              </div>
              <div>
                <label for="date_of_birth" class="field-label">تاريخ الميلاد <span v-if="required('date_of_birth')" class="text-red-700">*</span></label>
                <input id="date_of_birth" v-model="form.date_of_birth" data-error-field="date_of_birth" type="date" min="1900-01-02" :max="latestBirthDate" class="field" :aria-invalid="Boolean(form.errors.date_of_birth)" />
                <p class="mt-1 text-xs text-slate-500">العمر: {{ age === null ? '—' : `${age} سنة` }}</p>
                <p v-if="form.errors.date_of_birth" class="field-error">{{ form.errors.date_of_birth }}</p>
              </div>
              <div>
                <label for="place_of_birth" class="field-label">مكان الميلاد <span v-if="required('place_of_birth')" class="text-red-700">*</span></label>
                <input id="place_of_birth" v-model.trim="form.place_of_birth" data-error-field="place_of_birth" maxlength="255" class="field" />
                <p v-if="form.errors.place_of_birth" class="field-error">{{ form.errors.place_of_birth }}</p>
              </div>
              <div>
                <label for="marital_status_value_id" class="field-label">الحالة الاجتماعية <span v-if="required('marital_status_value_id')" class="text-red-700">*</span></label>
                <select id="marital_status_value_id" v-model="form.marital_status_value_id" data-error-field="marital_status_value_id" class="field">
                  <option value="">اختر الحالة الاجتماعية</option>
                  <option v-for="x in masterOptions('marital_statuses')" :key="x.id" :value="x.id" :disabled="!selectable(x, form.marital_status_value_id)">{{ x.name_ar }}{{ x.selectable === false ? ' (غير نشطة)' : '' }}</option>
                </select>
                <p v-if="!masterOptions('marital_statuses').length" class="mt-1 text-xs text-gold-800">لا توجد حالات اجتماعية في ثوابت النظام. <Link v-if="constantsLink('marital_statuses')" :href="constantsLink('marital_statuses')" class="font-semibold underline">أضفها من هنا</Link></p>
                <p v-if="form.errors.marital_status_value_id" class="field-error">{{ form.errors.marital_status_value_id }}</p>
              </div>
              <div>
                <label for="children_count" class="field-label">عدد الأبناء</label>
                <input id="children_count" v-model="form.children_count" data-error-field="children_count" type="number" min="0" max="40" dir="ltr" class="field" />
                <p v-if="form.errors.children_count" class="field-error">{{ form.errors.children_count }}</p>
              </div>
            </div>
          </section>

          <section class="card md:p-7">
            <h2 class="card-title mb-5">معلومات الاتصال</h2>
            <div class="grid gap-5 md:grid-cols-2">
              <div>
                <label for="phone" class="field-label">الجوال الأساسي <span class="text-red-700">*</span></label>
                <input id="phone" v-model.trim="form.phone" data-error-field="phone" type="tel" inputmode="tel" maxlength="16" dir="ltr" autocomplete="off" placeholder="9705XXXXXXXX" class="field" :aria-invalid="Boolean(form.errors.phone)" />
                <p class="mt-1 text-xs text-slate-500">يصله رمز تفعيل الحساب والتنبيهات العاجلة. صيغة دولية دون مسافات، مثل 970599123456.</p>
                <p v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</p>
              </div>
              <div>
                <label for="alternative_phone" class="field-label">الجوال الاحتياطي</label>
                <input id="alternative_phone" v-model.trim="form.alternative_phone" data-error-field="alternative_phone" type="tel" inputmode="tel" maxlength="16" dir="ltr" class="field" />
                <p v-if="form.errors.alternative_phone" class="field-error">{{ form.errors.alternative_phone }}</p>
              </div>
              <div>
                <label for="personal_email" class="field-label">البريد الشخصي</label>
                <input id="personal_email" v-model.trim="form.personal_email" data-error-field="personal_email" type="email" maxlength="255" dir="ltr" class="field" />
                <p v-if="form.errors.personal_email" class="field-error">{{ form.errors.personal_email }}</p>
              </div>
              <div>
                <label for="landline_phone" class="field-label">الهاتف الأرضي</label>
                <input id="landline_phone" v-model.trim="form.landline_phone" data-error-field="landline_phone" type="tel" inputmode="tel" maxlength="16" dir="ltr" class="field" />
                <p v-if="form.errors.landline_phone" class="field-error">{{ form.errors.landline_phone }}</p>
              </div>
              <div class="md:col-span-2">
                <label for="residence_address" class="field-label">عنوان السكن الكامل (نص)</label>
                <textarea id="residence_address" v-model="form.residence_address" data-error-field="residence_address" rows="2" maxlength="2000" class="field"></textarea>
                <p class="mt-1 text-xs text-slate-500">كما ورد في ملف الموظفين؛ العناوين المهيكلة أدناه تبقى أساس العنوان الأصلي والحالي.</p>
                <p v-if="form.errors.residence_address" class="field-error">{{ form.errors.residence_address }}</p>
              </div>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-5">
              <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-bold">عناوين السكن <span v-if="!editing" class="text-red-700">*</span></h3>
                <button type="button" class="btn btn-sm btn-accent" @click="addAddress">+ إضافة عنوان</button>
              </div>
              <p class="mb-3 text-sm text-slate-500">مطلوب عنوان «أصلي» وعنوان «حالي» نشطان (ولو تطابقا). المحافظة من ثوابت النظام، والمدينة والمنطقة تُقترح من الثوابت ويمكن كتابتها. لا تُحذف العناوين السابقة.</p>
              <p v-if="form.errors.addresses" class="field-error">{{ form.errors.addresses }}</p>
              <p v-if="form.errors['addresses.current']" class="field-error">{{ form.errors['addresses.current'] }}</p>
              <article v-for="(a, i) in form.addresses" :key="a.id ?? `new-${i}`" class="mb-4 rounded-xl border border-slate-200 p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <h4 class="font-semibold">العنوان {{ i + 1 }}</h4>
                  <div class="flex gap-3 text-sm">
                    <button v-if="a.address_type === 'current' && form.addresses.some(x => x.address_type === 'original' && x.is_active)" type="button" class="font-semibold text-blue-700 hover:underline" @click="copyOriginalToCurrent(i)">مطابق للأصلي</button>
                    <button v-if="!a.id" type="button" class="font-semibold text-red-700 hover:underline" @click="discardNewRow('addresses', i)">إزالة</button>
                  </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                  <div>
                    <label :for="`address-type-${i}`" class="field-label">نوع العنوان</label>
                    <select :id="`address-type-${i}`" v-model="a.address_type" :data-error-field="`addresses.${i}.address_type`" class="field"><option value="original">السكن الأصلي</option><option value="current">السكن الحالي</option><option value="previous">عنوان سابق</option></select>
                    <p v-if="errorFor('addresses', i, 'address_type')" class="field-error">{{ errorFor('addresses', i, 'address_type') }}</p>
                  </div>
                  <GeographicAddress class="sm:col-span-2" :model-value="a" :errors="form.errors" :prefix="`addresses.${i}.`" @update:model-value="Object.assign(a, $event)" />
                  <div>
                    <label :for="`address-from-${i}`" class="field-label">من تاريخ</label>
                    <input :id="`address-from-${i}`" v-model="a.valid_from" :data-error-field="`addresses.${i}.valid_from`" type="date" :max="today" class="field" />
                  </div>
                  <div>
                    <label :for="`address-to-${i}`" class="field-label">إلى تاريخ</label>
                    <input :id="`address-to-${i}`" v-model="a.valid_to" :data-error-field="`addresses.${i}.valid_to`" type="date" :min="a.valid_from || undefined" class="field" />
                    <p v-if="errorFor('addresses', i, 'valid_to')" class="field-error">{{ errorFor('addresses', i, 'valid_to') }}</p>
                  </div>
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="a.is_active" :data-error-field="`addresses.${i}.is_active`" type="checkbox" /> عنوان نشط</label>
              </article>
              <p v-if="!form.addresses.length" class="text-sm text-slate-500">لا توجد عناوين بعد. أضف عنوان السكن الأصلي ثم الحالي.</p>
            </div>
          </section>
        </template>

        <!-- Step 2: qualifications -->
        <section v-else-if="step === 1" class="card md:p-7">
          <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3">
            <label for="has-qualifications" class="field-label">هل لدى الموظف مؤهلات علمية؟ <span v-if="!editing" class="text-red-700">*</span></label>
            <select id="has-qualifications" v-model="form.has_qualifications" data-error-field="has_qualifications" class="field"><option value="">اختر</option><option value="yes">نعم</option><option value="no">لا يوجد مؤهل</option></select>
            <p v-if="form.errors.has_qualifications" class="field-error">{{ form.errors.has_qualifications }}</p>
          </div>
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="card-title">المؤهلات العلمية</h2>
            <button type="button" class="btn btn-sm btn-accent" @click="addQualification">+ إضافة مؤهل</button>
          </div>
          <p class="mb-4 text-sm text-slate-500">سجّل المؤهلات كلها وحدد مؤهلًا أعلى واحدًا. مرفقات الشهادات تُفعّل بعد تجهيز تخزين الملفات الآمن.</p>
          <p v-if="form.errors.qualifications" class="field-error">{{ form.errors.qualifications }}</p>
          <datalist id="degree-levels"><option v-for="degree in commonDegrees" :key="degree" :value="degree" /></datalist>
          <datalist id="academic-grades"><option v-for="x in masterOptions('academic_grades')" :key="x.id" :value="x.name_ar" /></datalist>
          <article v-for="(q, i) in form.qualifications" :key="q.id ?? `new-${i}`" class="mb-4 rounded-xl border border-slate-200 p-4">
            <div class="mb-3 flex items-center justify-between">
              <h3 class="font-bold">المؤهل {{ i + 1 }}</h3>
              <button v-if="!q.id" type="button" class="text-sm font-semibold text-red-700 hover:underline" @click="discardNewRow('qualifications', i)">إزالة</button>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label :for="`q-name-${i}`" class="field-label">اسم المؤهل <span class="text-red-700">*</span></label>
                <input :id="`q-name-${i}`" v-model.trim="q.qualification_name" :data-error-field="`qualifications.${i}.qualification_name`" maxlength="255" class="field" placeholder="مثال: بكالوريوس محاسبة" />
                <p v-if="errorFor('qualifications', i, 'qualification_name')" class="field-error">{{ errorFor('qualifications', i, 'qualification_name') }}</p>
              </div>
              <div>
                <label :for="`q-level-${i}`" class="field-label">المستوى العلمي</label>
                <input :id="`q-level-${i}`" v-model.trim="q.degree_level" :data-error-field="`qualifications.${i}.degree_level`" list="degree-levels" maxlength="100" autocomplete="off" class="field" />
                <p v-if="errorFor('qualifications', i, 'degree_level')" class="field-error">{{ errorFor('qualifications', i, 'degree_level') }}</p>
              </div>
              <div>
                <label :for="`q-institution-${i}`" class="field-label">الجامعة / المؤسسة</label>
                <input :id="`q-institution-${i}`" v-model.trim="q.institution_name" :data-error-field="`qualifications.${i}.institution_name`" maxlength="255" class="field" />
                <p v-if="errorFor('qualifications', i, 'institution_name')" class="field-error">{{ errorFor('qualifications', i, 'institution_name') }}</p>
              </div>
              <div>
                <label :for="`q-specialization-${i}`" class="field-label">التخصص</label>
                <input :id="`q-specialization-${i}`" v-model.trim="q.specialization" :data-error-field="`qualifications.${i}.specialization`" maxlength="255" class="field" />
              </div>
              <div>
                <label :for="`q-grade-${i}`" class="field-label">التقدير</label>
                <input :id="`q-grade-${i}`" v-model.trim="q.grade" :data-error-field="`qualifications.${i}.grade`" list="academic-grades" maxlength="100" autocomplete="off" class="field" />
                <p v-if="errorFor('qualifications', i, 'grade')" class="field-error">{{ errorFor('qualifications', i, 'grade') }}</p>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label :for="`q-gpa-${i}`" class="field-label">المعدل</label>
                  <input :id="`q-gpa-${i}`" v-model.trim="q.gpa" :data-error-field="`qualifications.${i}.gpa`" type="text" inputmode="decimal" maxlength="7" dir="ltr" class="field" placeholder="85.5" />
                  <p v-if="errorFor('qualifications', i, 'gpa')" class="field-error">{{ errorFor('qualifications', i, 'gpa') }}</p>
                </div>
                <div>
                  <label :for="`q-scale-${i}`" class="field-label">من (السلم)</label>
                  <input :id="`q-scale-${i}`" v-model.trim="q.gpa_scale" :data-error-field="`qualifications.${i}.gpa_scale`" maxlength="30" dir="ltr" class="field" placeholder="100 أو 4" />
                </div>
              </div>
              <div>
                <label :for="`q-country-${i}`" class="field-label">دولة إصدار الشهادة (رمز)</label>
                <input :id="`q-country-${i}`" v-model.trim="q.issuing_country_code" :data-error-field="`qualifications.${i}.issuing_country_code`" maxlength="3" dir="ltr" autocomplete="off" class="field uppercase" placeholder="PS" />
                <p class="mt-1 text-xs text-slate-500">حرفان بالإنجليزية: فلسطين PS، مصر EG، الأردن JO.</p>
                <p v-if="errorFor('qualifications', i, 'issuing_country_code')" class="field-error">{{ errorFor('qualifications', i, 'issuing_country_code') }}</p>
              </div>
              <div>
                <label :for="`q-certificate-${i}`" class="field-label">رقم الشهادة</label>
                <input :id="`q-certificate-${i}`" v-model.trim="q.certificate_number" :data-error-field="`qualifications.${i}.certificate_number`" maxlength="150" dir="ltr" class="field" />
              </div>
              <div>
                <label :for="`q-start-${i}`" class="field-label">بداية الدراسة</label>
                <input :id="`q-start-${i}`" v-model="q.study_start_date" :data-error-field="`qualifications.${i}.study_start_date`" type="date" class="field" />
              </div>
              <div>
                <label :for="`q-graduation-${i}`" class="field-label">تاريخ التخرج</label>
                <input :id="`q-graduation-${i}`" v-model="q.graduation_date" :data-error-field="`qualifications.${i}.graduation_date`" type="date" :min="q.study_start_date || undefined" class="field" />
                <p v-if="errorFor('qualifications', i, 'graduation_date')" class="field-error">{{ errorFor('qualifications', i, 'graduation_date') }}</p>
              </div>
              <div>
                <label :for="`q-year-${i}`" class="field-label">سنة التخرج</label>
                <input :id="`q-year-${i}`" v-model="q.graduation_year" :data-error-field="`qualifications.${i}.graduation_year`" type="number" min="1940" :max="new Date().getFullYear() + 1" dir="ltr" class="field" />
                <p v-if="errorFor('qualifications', i, 'graduation_year')" class="field-error">{{ errorFor('qualifications', i, 'graduation_year') }}</p>
              </div>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm"><input type="radio" name="highest-qualification" :checked="Boolean(q.is_highest_qualification)" @change="markHighest(i)" /> أعلى مؤهل علمي</label>
          </article>
          <p v-if="!form.qualifications.length" class="text-sm text-slate-500">لا توجد مؤهلات مسجلة.</p>
        </section>

        <!-- Step 3: employment -->
        <template v-else-if="step === 2">
          <section class="card md:p-7">
            <h2 class="card-title mb-5">بيانات العمل</h2>
            <div class="grid gap-5 md:grid-cols-2">
              <div>
                <label for="department" class="field-label">القسم <span v-if="required('department_id')" class="text-red-700">*</span></label>
                <select id="department" v-model="form.department_id" data-error-field="department_id" :disabled="(editing && Boolean(employee?.user_id)) || Boolean(prefill?.department_id)" class="field disabled:bg-slate-100"><option value="">اختر القسم</option><option v-for="d in departments" :key="d.id" :value="d.id" :disabled="!activeOption(d, form.department_id)">{{ d.name }}{{ !d.is_active ? ' (غير نشط)' : '' }}</option></select>
                <p v-if="(editing && employee?.user_id) || prefill?.department_id" class="mt-1 text-xs text-slate-500">يُغيَّر القسم من «حسابات الموظفين والأدوار».</p>
                <p v-if="form.errors.department_id" class="field-error">{{ form.errors.department_id }}</p>
              </div>
              <div>
                <label for="category" class="field-label">الفئة الوظيفية <span v-if="required('job_category_id')" class="text-red-700">*</span></label>
                <select id="category" v-model="form.job_category_id" data-error-field="job_category_id" class="field" @change="changeCategory"><option value="">اختر الفئة</option><option v-for="c in categories" :key="c.id" :value="c.id" :disabled="!activeOption(c, form.job_category_id)">{{ c.name_ar }}{{ !c.is_active ? ' (غير نشطة)' : '' }}</option></select>
                <p v-if="!categories.length" class="mt-1 text-xs text-gold-800">لا توجد فئات وظيفية. تضاف من «ثوابت العمل ← الفئات والمسميات الوظيفية».</p>
                <p v-if="form.errors.job_category_id" class="field-error">{{ form.errors.job_category_id }}</p>
              </div>
              <div>
                <label for="title" class="field-label">المسمى الوظيفي <span v-if="required('job_title_id')" class="text-red-700">*</span></label>
                <select id="title" v-model="form.job_title_id" data-error-field="job_title_id" :disabled="!form.job_category_id" class="field disabled:bg-slate-100"><option value="">{{ form.job_category_id ? 'اختر المسمى' : 'اختر الفئة أولًا' }}</option><option v-for="t in titles" :key="t.id" :value="t.id" :disabled="!activeOption(t, form.job_title_id)">{{ t.name_ar }}{{ !t.is_active ? ' (غير نشط)' : '' }}</option></select>
                <p v-if="form.job_category_id && !titles.length" class="mt-1 text-xs text-gold-800">لا توجد مسميات في هذه الفئة.</p>
                <p v-if="form.errors.job_title_id" class="field-error">{{ form.errors.job_title_id }}</p>
              </div>
              <div>
                <label for="grade" class="field-label">الدرجة الوظيفية</label>
                <select id="grade" v-model="form.job_grade_id" data-error-field="job_grade_id" class="field"><option value="">بدون درجة</option><option v-for="g in grades" :key="g.id" :value="g.id" :disabled="!activeGrade(g)">{{ g.scale_name }} — {{ g.name_ar }}</option></select>
                <p v-if="form.errors.job_grade_id" class="field-error">{{ form.errors.job_grade_id }}</p>
                <p v-if="employee?.grade_source" class="mt-1 text-xs text-slate-500">الدرجة كما وردت في ملف الموظفين: {{ employee.grade_source }}</p>
              </div>
              <div>
                <label for="attendance_number" class="field-label">رقم جهاز البصمة</label>
                <input id="attendance_number" v-model.trim="form.attendance_number" data-error-field="attendance_number" maxlength="50" dir="ltr" autocomplete="off" class="field" />
                <p class="mt-1 text-xs text-slate-500">مستقل عن الرقم الوظيفي وإن تطابقا.</p>
                <p v-if="form.errors.attendance_number" class="field-error">{{ form.errors.attendance_number }}</p>
              </div>
              <div v-for="field in [
                { key: 'employment_term_value_id', type: 'employment_terms', label: 'بند التوظيف', empty: 'اختر بند التوظيف' },
                { key: 'contract_type_value_id', type: 'contract_types', label: 'نوع العقد', empty: 'اختر نوع العقد' },
                { key: 'work_schedule_value_id', type: 'work_schedules', label: 'نظام الدوام', empty: 'غير محدد' },
                { key: 'administrative_level_value_id', type: 'administrative_levels', label: 'المستوى الإداري (عند انطباقه)', empty: 'غير منطبق' },
              ]" :key="field.key">
                <label :for="field.key" class="field-label">{{ field.label }} <span v-if="required(field.key)" class="text-red-700">*</span></label>
                <select :id="field.key" v-model="form[field.key]" class="field">
                  <option value="">{{ field.empty }}</option>
                  <option v-for="x in masterOptions(field.type)" :key="x.id" :value="x.id" :disabled="!selectable(x, form[field.key])">{{ x.name_ar }}{{ x.selectable === false ? ' (غير نشط)' : '' }}</option>
                </select>
                <p v-if="!masterOptions(field.type).length" class="mt-1 text-xs text-gold-800">القائمة فارغة في ثوابت النظام. <Link v-if="constantsLink(field.type)" :href="constantsLink(field.type)" class="font-semibold underline">أضف القيم من هنا</Link></p>
                <p v-if="form.errors[field.key]" class="field-error">{{ form.errors[field.key] }}</p>
              </div>
              <div v-for="f in [{ key: 'hire_date', label: 'تاريخ التعيين الرسمي' }, { key: 'work_start_date', label: 'تاريخ مباشرة العمل الفعلي (أساس الأقدمية)' }]" :key="f.key">
                <label :for="f.key" class="field-label">{{ f.label }} <span v-if="required(f.key)" class="text-red-700">*</span></label>
                <input :id="f.key" v-model="form[f.key]" type="date" min="1950-01-02" class="field" />
                <p v-if="form.errors[f.key]" class="field-error">{{ form.errors[f.key] }}</p>
              </div>
              <div>
                <label for="status" class="field-label">الحالة الوظيفية</label>
                <select id="status" v-model="form.employment_status" data-error-field="employment_status" class="field"><option value="active">على رأس العمل</option><option value="on_leave">في إجازة</option><option value="inactive">غير نشط</option><option value="terminated">انتهت الخدمة</option></select>
                <p v-if="form.errors.employment_status" class="field-error">{{ form.errors.employment_status }}</p>
              </div>
            </div>
            <label for="notes" class="field-label mt-5">ملاحظات إدارية</label>
            <textarea id="notes" v-model="form.notes" data-error-field="notes" rows="4" maxlength="10000" class="field"></textarea>
            <p v-if="form.errors.notes" class="field-error">{{ form.errors.notes }}</p>
          </section>
        </template>

        <!-- Step 4: family -->
        <section v-else-if="step === 3" class="card md:p-7">
          <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3">
            <label for="has-family" class="field-label">هل توجد بيانات لأفراد العائلة؟ <span v-if="!editing" class="text-red-700">*</span></label>
            <select id="has-family" v-model="form.has_family_members" data-error-field="has_family_members" class="field"><option value="">اختر</option><option value="yes">نعم</option><option value="no">لا يوجد أفراد عائلة للتسجيل</option></select>
            <p v-if="form.errors.has_family_members" class="field-error">{{ form.errors.has_family_members }}</p>
          </div>
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="card-title">بيانات العائلة</h2>
            <button type="button" class="btn btn-sm btn-accent" :disabled="!kinshipTypes.length" @click="addFamilyMember">+ إضافة فرد</button>
          </div>
          <div v-if="!kinshipTypes.length" class="alert-warning mb-4 text-sm leading-7">
            قائمة «صلات القرابة» فارغة في ثوابت النظام، لذلك لا يمكن تسجيل أفراد العائلة بعد.
            <Link v-if="constantsLink('kinship_types')" :href="constantsLink('kinship_types')" class="font-semibold underline">أضف صلات القرابة</Link>
            <span v-else>يضيفها مدير النظام من «ثوابت النظام ← ثوابت البيانات الاجتماعية ← صلات القرابة».</span>
          </div>
          <p v-if="form.errors.family_members" class="field-error">{{ form.errors.family_members }}</p>
          <article v-for="(m, i) in form.family_members" :key="m.id ?? `new-${i}`" class="mb-4 rounded-xl border border-slate-200 p-4">
            <div class="mb-3 flex items-center justify-between">
              <h3 class="font-bold">فرد العائلة {{ i + 1 }}</h3>
              <button v-if="!m.id" type="button" class="text-sm font-semibold text-red-700 hover:underline" @click="discardNewRow('family_members', i)">إزالة</button>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label :for="`m-relation-${i}`" class="field-label">صلة القرابة <span class="text-red-700">*</span></label>
                <select :id="`m-relation-${i}`" v-model="m.relationship_code" :data-error-field="`family_members.${i}.relationship_code`" class="field">
                  <option value="">اختر صلة القرابة</option>
                  <option v-if="legacyKinship(m)" :value="m.relationship_code">{{ m.relationship_code }} (قيمة سابقة)</option>
                  <option v-for="x in kinshipTypes" :key="x.id" :value="x.code">{{ x.name_ar }}</option>
                </select>
                <p v-if="errorFor('family_members', i, 'relationship_code')" class="field-error">{{ errorFor('family_members', i, 'relationship_code') }}</p>
              </div>
              <div>
                <label :for="`m-name-${i}`" class="field-label">الاسم الرباعي بالعربية <span class="text-red-700">*</span></label>
                <input :id="`m-name-${i}`" v-model.trim="m.full_name_ar" :data-error-field="`family_members.${i}.full_name_ar`" maxlength="255" class="field" />
                <p v-if="errorFor('family_members', i, 'full_name_ar')" class="field-error">{{ errorFor('family_members', i, 'full_name_ar') }}</p>
              </div>
              <div>
                <label :for="`m-name-en-${i}`" class="field-label">الاسم بالإنجليزية</label>
                <input :id="`m-name-en-${i}`" v-model.trim="m.full_name_en" :data-error-field="`family_members.${i}.full_name_en`" maxlength="255" dir="ltr" class="field" />
              </div>
              <div>
                <label :for="`m-id-${i}`" class="field-label">رقم الهوية أو الوثيقة</label>
                <input :id="`m-id-${i}`" v-model.trim="m.national_id" :data-error-field="`family_members.${i}.national_id`" maxlength="30" dir="ltr" autocomplete="off" class="field" />
                <p v-if="errorFor('family_members', i, 'national_id')" class="field-error">{{ errorFor('family_members', i, 'national_id') }}</p>
                <p v-else-if="familyIdWarning(m.national_id)" class="mt-1 text-xs text-gold-800">{{ familyIdWarning(m.national_id) }}</p>
              </div>
              <div>
                <label :for="`m-dob-${i}`" class="field-label">تاريخ الميلاد</label>
                <input :id="`m-dob-${i}`" v-model="m.date_of_birth" :data-error-field="`family_members.${i}.date_of_birth`" type="date" :max="today" class="field" />
                <p v-if="errorFor('family_members', i, 'date_of_birth')" class="field-error">{{ errorFor('family_members', i, 'date_of_birth') }}</p>
              </div>
              <div>
                <label :for="`m-phone-${i}`" class="field-label">الهاتف</label>
                <input :id="`m-phone-${i}`" v-model.trim="m.phone" :data-error-field="`family_members.${i}.phone`" type="tel" inputmode="tel" maxlength="16" dir="ltr" class="field" />
                <p v-if="errorFor('family_members', i, 'phone')" class="field-error">{{ errorFor('family_members', i, 'phone') }}</p>
              </div>
            </div>
            <div class="mt-4 flex gap-6"><label class="flex items-center gap-2 text-sm"><input v-model="m.is_dependent" :data-error-field="`family_members.${i}.is_dependent`" type="checkbox" /> مُعال</label><label class="flex items-center gap-2 text-sm"><input v-model="m.is_active" :data-error-field="`family_members.${i}.is_active`" type="checkbox" /> سجل نشط</label></div>
          </article>
          <p v-if="!form.family_members.length" class="text-sm text-slate-500">لا توجد بيانات عائلية مسجلة.</p>
        </section>

        <!-- Step 5: leave balances and final review -->
        <section v-else class="card space-y-4 md:p-7">
          <h2 class="card-title">الإجازات والمراجعة النهائية</h2>
          <div class="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-slate-800">
            <h3 class="font-bold text-blue-950">سياسة الإجازة السنوية (للاطلاع فقط)</h3>
            <p v-if="leaveOnboarding.policy_status === 'pending'" class="text-gold-900">السياسة المعتمدة مسجلة بانتظار الإطلاق الرسمي؛ لا ينتج عن هذه الشاشة أي استحقاق فعّال أو إضافة رصيد تلقائية.</p>
            <p v-else-if="leaveOnboarding.policy_status === 'applied'" class="text-emerald-900">تم تطبيق تصحيح السياسة. الاستحقاق الفعلي يُحتسب عبر وحدة الإجازات وفق تاريخ الخدمة والقواعد السارية، وليس من هذه المعاينة.</p>
            <p v-else class="text-gold-900">لا توجد سياسة تصحيح مسجلة للعرض؛ لا تفترض أي عدد أيام تلقائيًا.</p>
            <ul v-if="leaveOnboarding.annual_policy_tiers?.length" class="list-disc space-y-1 pr-5">
              <li v-for="(tier, i) in leaveOnboarding.annual_policy_tiers" :key="i">
                من {{ Number(tier.min_service_months) / 12 }} سنة
                <template v-if="tier.max_service_months != null"> إلى أقل من {{ Number(tier.max_service_months) / 12 }} سنة</template>
                <template v-else> فأكثر</template>
                — {{ tier.annual_entitlement_days }} يومًا سنويًا
              </li>
            </ul>
            <p class="text-slate-600">الأرصدة الافتتاحية تُدخل يدويًا وفق مستنداتها المعتمدة.</p>
          </div>

          <div v-if="editing" class="space-y-2 rounded-xl border border-slate-200 p-4 text-sm">
            <h3 class="font-bold">الأرصدة الافتتاحية المسجلة (للقراءة)</h3>
            <p v-if="!leaveOnboarding.opening_balances?.length" class="text-slate-500">لا توجد أرصدة افتتاحية مسجلة.</p>
            <div v-for="(balance, i) in (leaveOnboarding.opening_balances ?? [])" :key="i" class="border-t pt-2">
              {{ masterOptions('leave_types').find(x => String(x.id) === String(balance.leave_type_value_id))?.name_ar ?? `#${balance.leave_type_value_id}` }}
              — {{ balance.balance_year }} — الاستحقاق {{ balance.annual_entitlement_days }} يومًا — الرصيد الافتتاحي {{ balance.opening_balance_days }} يومًا
            </div>
            <p class="text-slate-600">تُعدَّل الأرصدة من صفحة <Link :href="`/leaves/balances?employee_id=${employee.id}`" class="font-semibold text-blue-700 underline">أرصدة الإجازات</Link>، حيث يُسجَّل كل تعديل في السجل.</p>
          </div>
          <template v-else>
            <p class="text-sm text-slate-600">أدخل الأرصدة الافتتاحية المعتمدة، أو أكّد عدم وجود أرصدة. هذه ليست طلبات إجازة.</p>
            <button type="button" class="btn btn-sm btn-accent" :disabled="!masterOptions('leave_types').length" @click="addLeaveBalance">+ إضافة رصيد إجازة</button>
            <p v-if="!masterOptions('leave_types').length" class="text-xs text-gold-800">لا توجد أنواع إجازات في ثوابت النظام.</p>
            <article v-for="(balance, i) in form.leave_opening_balances" :key="i" class="space-y-3 rounded-xl border p-4">
              <div class="flex justify-between"><strong>رصيد {{ i + 1 }}</strong><button type="button" class="text-sm font-semibold text-red-700 hover:underline" @click="removeLeaveBalance(i)">إزالة</button></div>
              <div class="grid gap-4 md:grid-cols-2">
                <div>
                  <label :for="`b-type-${i}`" class="field-label">نوع الإجازة <span class="text-red-700">*</span></label>
                  <select :id="`b-type-${i}`" v-model="balance.leave_type_value_id" :data-error-field="`leave_opening_balances.${i}.leave_type_value_id`" class="field"><option value="">اختر</option><option v-for="x in masterOptions('leave_types')" :key="x.id" :value="x.id">{{ x.name_ar }}</option></select>
                  <p v-if="errorFor('leave_opening_balances', i, 'leave_type_value_id')" class="field-error">{{ errorFor('leave_opening_balances', i, 'leave_type_value_id') }}</p>
                </div>
                <div>
                  <label :for="`b-year-${i}`" class="field-label">السنة</label>
                  <input :id="`b-year-${i}`" :value="balance.balance_year" type="number" readonly dir="ltr" class="field bg-slate-50" />
                  <p class="mt-1 text-xs text-slate-500">الرصيد الافتتاحي للسنة الحالية فقط.</p>
                  <p v-if="errorFor('leave_opening_balances', i, 'balance_year')" class="field-error">{{ errorFor('leave_opening_balances', i, 'balance_year') }}</p>
                </div>
                <div>
                  <label :for="`b-entitlement-${i}`" class="field-label">الاستحقاق السنوي بالأيام <span class="text-red-700">*</span></label>
                  <input :id="`b-entitlement-${i}`" v-model="balance.annual_entitlement_days" :data-error-field="`leave_opening_balances.${i}.annual_entitlement_days`" type="number" min="0" max="366" step="0.5" dir="ltr" class="field" />
                  <p v-if="errorFor('leave_opening_balances', i, 'annual_entitlement_days')" class="field-error">{{ errorFor('leave_opening_balances', i, 'annual_entitlement_days') }}</p>
                </div>
                <div>
                  <label :for="`b-opening-${i}`" class="field-label">الرصيد الافتتاحي بالأيام <span class="text-red-700">*</span></label>
                  <input :id="`b-opening-${i}`" v-model="balance.opening_balance_days" :data-error-field="`leave_opening_balances.${i}.opening_balance_days`" type="number" min="0" max="9999" step="0.5" dir="ltr" class="field" />
                  <p v-if="errorFor('leave_opening_balances', i, 'opening_balance_days')" class="field-error">{{ errorFor('leave_opening_balances', i, 'opening_balance_days') }}</p>
                </div>
              </div>
              <div>
                <label :for="`b-notes-${i}`" class="field-label">مرجع المستند <span class="text-red-700">*</span></label>
                <textarea :id="`b-notes-${i}`" v-model.trim="balance.notes" :data-error-field="`leave_opening_balances.${i}.notes`" rows="2" maxlength="2000" class="field" placeholder="مثال: كشف أرصدة الموارد البشرية رقم ... بتاريخ ..."></textarea>
                <p v-if="errorFor('leave_opening_balances', i, 'notes')" class="field-error">{{ errorFor('leave_opening_balances', i, 'notes') }}</p>
              </div>
            </article>
            <label class="flex items-center gap-3 rounded-lg bg-slate-50 p-3 text-sm"><input v-model="form.confirm_no_opening_balances" data-error-field="confirm_no_opening_balances" type="checkbox" :disabled="form.leave_opening_balances.length > 0" /> أؤكد عدم وجود أرصدة إجازات افتتاحية لهذا الموظف.</label>
            <p v-if="form.errors.leave_opening_balances || form.errors.confirm_no_opening_balances" class="field-error">{{ form.errors.leave_opening_balances || form.errors.confirm_no_opening_balances }}</p>
            <label class="flex items-center gap-3 rounded-lg border border-blue-200 p-4 text-sm font-semibold"><input v-model="reviewConfirmed" type="checkbox" /> راجعت البيانات الأساسية والتعليم والعمل والعائلة والإجازات، وأؤكد صحتها قبل الاعتماد.</label>
          </template>
        </section>

        <div class="aims-form-actions flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
          <div class="flex gap-2">
            <button v-if="step > 0" type="button" :disabled="form.processing" class="btn btn-secondary" @click="previousStep">السابق</button>
            <button v-if="step < 4" type="button" :disabled="form.processing" class="btn btn-accent" @click="nextStep">التالي</button>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="editing && step < 4" type="submit" :disabled="form.processing" class="btn btn-primary">{{ form.processing ? 'جارٍ الحفظ...' : 'حفظ بيانات هذه الخطوة' }}</button>
            <button v-if="!editing && step === 4" type="submit" :disabled="form.processing" class="btn btn-primary">{{ form.processing ? 'جارٍ الاعتماد...' : 'اعتماد وتسجيل الموظف' }}</button>
            <Link :href="editing ? `${base}/${employee.id}` : base" class="btn btn-secondary">{{ editing ? 'العودة لملف الموظف' : 'إلغاء والعودة' }}</Link>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
