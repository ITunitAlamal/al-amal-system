// Field definitions of the orphans forms. Requiredness comes from the versioned rules sent by the server.
export const householdFields = [
    { key: 'family_name', label: 'اسم العائلة', max: 100 },
    { key: 'family_size', label: 'عدد أفراد الأسرة', type: 'number', min: 1 },
    { key: 'dependents_count', label: 'عدد المعالين', type: 'number', min: 0 },
    { key: 'working_members_count', label: 'عدد العاملين', type: 'number', min: 0 },
    { key: 'income_source_value_id', label: 'مصدر الدخل', type: 'select' },
    { key: 'monthly_income', label: 'الدخل الشهري', type: 'number', min: 0, step: '0.01' },
    { key: 'economic_status_value_id', label: 'الوضع الاقتصادي', type: 'select' },
    { key: 'needs_level_value_id', label: 'مستوى الاحتياج', type: 'select' },
    { key: 'previous_tenure_value_id', label: 'ملكية السكن السابق', type: 'select', options: 'previous_tenure_value_id' },
    { key: 'current_tenure_value_id', label: 'ملكية السكن الحالي', type: 'select', options: 'current_tenure_value_id' },
    { key: 'rent_amount', label: 'قيمة الإيجار الشهري', type: 'number', min: 0, step: '0.01' },
    { key: 'displaced', label: 'الأسرة نازحة حاليًا', type: 'boolean' },
    { key: 'displaced_since', label: 'تاريخ النزوح', type: 'date' },
    { key: 'shelter_type_value_id', label: 'نوع المأوى الحالي', type: 'select' },
    { key: 'basic_needs', label: 'الاحتياجات الأساسية', type: 'textarea', max: 1000 },
];

export const parentFields = role => [
    { key: 'full_name', label: role === 'father' ? 'اسم الأب الرباعي' : 'اسم الأم الرباعي', max: 200, wide: true },
    { key: 'identity_type_value_id', label: 'نوع الوثيقة', type: 'select' },
    { key: 'identity_no', label: 'رقم الوثيقة', max: 40, ltr: true },
    { key: 'date_of_birth', label: 'تاريخ الميلاد', type: 'date' },
    { key: 'vital_status_value_id', label: 'الحالة الحيوية', type: 'select' },
    { key: 'death_date', label: 'تاريخ الوفاة', type: 'date' },
    { key: 'death_cause_value_id', label: 'سبب الوفاة', type: 'select' },
    { key: 'occupation', label: role === 'father' ? 'العمل قبل الوفاة' : 'العمل', max: 150 },
    ...(role === 'mother' ? [
        { key: 'marital_status_value_id', label: 'الحالة الاجتماعية', type: 'select' },
        { key: 'education_level_value_id', label: 'المستوى التعليمي', type: 'select' },
        { key: 'specialization', label: 'التخصص الجامعي (إن وجد)', max: 150 },
        { key: 'has_illness', label: 'تعاني من مرض', type: 'boolean' },
        { key: 'illness_note', label: 'وصف موجز للمرض', type: 'textarea', max: 200 },
        { key: 'has_disability', label: 'لديها إعاقة', type: 'boolean' },
        { key: 'disability_types', label: 'أنواع الإعاقة', type: 'multi', options: 'disability_types' },
    ] : []),
];

export const childFields = [
    { key: 'first_name', label: 'الاسم الأول', max: 60 },
    { key: 'father_name', label: 'اسم الأب', max: 60 },
    { key: 'grandfather_name', label: 'اسم الجد', max: 60 },
    { key: 'family_name', label: 'اسم العائلة', max: 60 },
    { key: 'english_name', label: 'الاسم بالإنجليزية', max: 150, ltr: true },
    { key: 'identity_type_value_id', label: 'نوع الوثيقة', type: 'select' },
    { key: 'identity_no', label: 'رقم الوثيقة', max: 40, ltr: true, help: 'الهوية الفلسطينية 9 أرقام؛ الوثائق الأخرى بصيغتها.' },
    { key: 'date_of_birth', label: 'تاريخ الميلاد', type: 'date' },
    { key: 'place_of_birth', label: 'مكان الميلاد', max: 150 },
    { key: 'sex', label: 'الجنس', type: 'select', raw: true, choices: [{ value: 'male', label: 'ذكر' }, { value: 'female', label: 'أنثى' }] },
    { key: 'orphan_class_value_id', label: 'تصنيف اليتم', type: 'select' },
    { key: 'sole_survivor', label: 'الناجي الوحيد من الأسرة (موثق)', type: 'boolean' },
    { key: 'phone', label: 'رقم تواصل (إن وجد)', max: 15, ltr: true },
];

export const guardianFields = [
    { key: 'name', label: 'اسم الوصي/المستلم المفوض', max: 200, wide: true },
    { key: 'identity_type_value_id', label: 'نوع الوثيقة', type: 'select' },
    { key: 'identity_no', label: 'رقم الوثيقة', max: 40, ltr: true },
    { key: 'phone', label: 'رقم الجوال', max: 15, ltr: true },
    { key: 'alt_phone', label: 'هاتف بديل', max: 15, ltr: true },
    { key: 'email', label: 'البريد الإلكتروني', type: 'email', max: 150, ltr: true },
    { key: 'date_of_birth', label: 'تاريخ الميلاد', type: 'date' },
    { key: 'sex', label: 'الجنس', type: 'select', raw: true, choices: [{ value: 'male', label: 'ذكر' }, { value: 'female', label: 'أنثى' }] },
    { key: 'occupation', label: 'العمل', max: 150 },
    { key: 'employer', label: 'جهة العمل', max: 150 },
];

export const educationFields = [
    { key: 'school_year', label: 'العام الدراسي (مثل 2026/2027)', max: 9, ltr: true },
    { key: 'enrollment_value_id', label: 'الحالة التعليمية', type: 'select' },
    { key: 'stage_value_id', label: 'المرحلة', type: 'select' },
    { key: 'school_name', label: 'المدرسة', max: 200 },
    { key: 'grade', label: 'الصف', max: 40 },
    { key: 'achievement', label: 'التحصيل', max: 120 },
    { key: 'support_needs', label: 'احتياجات الدعم', type: 'textarea', max: 500 },
];

export const healthFields = [
    { key: 'health_status_value_id', label: 'الحالة الصحية', type: 'select' },
    { key: 'has_chronic', label: 'مرض مزمن', type: 'boolean' },
    { key: 'chronic_note', label: 'وصف موجز (حتى 200 حرف)', type: 'textarea', max: 200 },
    { key: 'has_disability', label: 'إعاقة', type: 'boolean' },
    { key: 'disability_types', label: 'أنواع الإعاقة', type: 'multi', options: 'disability_types' },
    { key: 'medications', label: 'الأدوية', type: 'textarea', max: 500 },
    { key: 'coverage', label: 'التغطية العلاجية', max: 200 },
    { key: 'follow_up', label: 'المتابعة والتدخلات', type: 'textarea', max: 1000 },
];

export const emptyAddress = () => ({ governorate_value_id: null, locality_value_id: null, area_value_id: null, subarea_value_id: null, governorate: '', city: '', area: '', neighborhood: '', address_other: false, address_details: '', landmark: '' });
