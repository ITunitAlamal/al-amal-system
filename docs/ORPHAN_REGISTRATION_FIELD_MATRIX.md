# مصفوفة حقول تسجيل الأيتام — 2026-11-02

**المصدر:** «تسجيل الايتام.xlsx»، ورقة «الاستمارة» (الخلايا) وورقة «البيانات» (القوائم). قورنت بالمخطط الحي `al_amal_db` وبالكود الحالي قبل أي تعديل.

## مفتاح المراحل
- **R:** مطلوب عند الإرسال.
- **C:** مطلوب عند الإرسال إذا تحقق الشرط.
- **O:** اختياري.
- **L:** مطلوب في مرحلة لاحقة (التحقق أو الاعتماد)، لا عند الإرسال.
- **—:** لا ينطبق.

**المسودة** تقبل كل شيء ناقصًا في جميع الحقول، فلا يُكرر عمود «مسودة» في الجدول.

## مصدر القواعد (مصدر واحد للخادم والواجهة)
- **القواعد:** في `RegistrationFieldSpec::fields()`، وهي بيانات (شرط + فحص).
- **الخادم:** يفرضها عند الإرسال في `FamilyRegistrationRequest::after()` ← `RegistrationForm::issues()` ← `RegistrationFieldSpec::errors()`. هذا يشمل البوابة والقسم.
- **الواجهة:** تستقبلها في `fieldRules`، وتقيّمها في `Support/fieldRules.js` لتعرض:
  - علامة `*` على الحقل المطلوب حاليًا فقط؛
  - قائمة «حقول مطلوبة لم تُستكمل» في خطوة المراجعة.
- **قواعد `by: form`:** قواعد سابقة في `issues()` بالمعنى نفسه، مدرجة للعرض.

## أ. التسجيل الأولي — الوصي والتواصل

| التسمية في الاستمارة | الخلية | الحقل (الجدول.العمود) | إرسال | تحقق | العنصر | القائمة | الشرط | تحقق الخادم | الحساسية | الفحص |
|---|---|---|---|---|---|---|---|---|---|---|
| اسم الوصي | B5/B6 | `guardians.*` ← `persons.first_name…family_name` (أو `full_name`) | C | — | نص ×4 + اسم كامل | — | الوصي «شخص آخر» | الاسم الأول والعائلة، أو الاسم الكامل | عادي | E1 |
| رقم الهوية | C5 | `persons.id_number` (مشفر، نص بالأصفار) | O | L | نص `dir=ltr` | `identity_document_types` | — | صيغة الهوية الفلسطينية عند الإرسال؛ الرقم الوهمي مرفوض | مقيد (`identity`) | E3 |
| سبب عدم توفر الهوية | — (جديد) | `persons.id_missing_reason` | C | — | قائمة | ثابتة (5) | الوصي «شخص آخر» بلا رقم | `in:` القائمة | عادي | E3 |
| تاريخ الميلاد | D5 | `persons.date_of_birth` | O | L | تاريخ | — | — | ≤ اليوم | عادي | — |
| صلة القرابة باليتيم | E5 | `orphan_guardianships.kinship_value_id` (لكل طفل) | R | — | قائمة | `kinship_types` | — | `exists` | عادي | E1 |
| توضيح «أخرى» | — | `orphan_guardianships.kinship_note` | C | — | نص | — | الصلة = `other` | مطلوب ≤ 200 | عادي | E5 |
| الحالة الاجتماعية | F5 | `persons.marital_status_value_id` (حقل الأم في النموذج؛ للوصي الآخر من ملف الشخص) | O | — | قائمة | `marital_statuses` | — | `exists` | عادي | — |
| الحالة الصحية | G5 | `care_records` (من ملف الشخص بعد الاعتماد؛ ليست في نموذج التسجيل الأولي) | O | — | قائمة | `health_statuses` | — | `exists` | مقيد (`health`) | — |
| المستوى التعليمي | H5 | `persons.education_level_value_id` | O | — | قائمة | `education_levels` | — | `exists` | عادي | — |
| التخصص الجامعي | I5 | `persons.education_specialization` | O | — | نص | — | يظهر للتعليم العالي (`HIGHER_EDUCATION`) | ≤ 150 | عادي | — |
| طبيعة العمل | J5 | `economic_assessments.employment_status_value_id` / `occupation` | O | — | قائمة + نص | `employment_statuses` | تفاصيل العمل عند «يعمل» | `exists` | عادي | — |
| هل الوصاية موثقة في المحكمة؟ | B7/E7 | `orphan_guardianships.court_status_value_id` (لكل طفل) | R | L (المستند) | قائمة | `guardianship_court_statuses` (نعم/لا/قيد الإخراج/غير معروف) | — | `exists`. **حالة يذكرها مقدم الطلب، ليست اعتمادًا** | عادي | E1 |
| مستند الوصاية | — | `registry_documents` (`guardianship_order`) | O | L | رفع ملف | `orphan_document_types` | — | PDF/JPG/PNG ≤ 10MB | مقيد | E6 |
| وسيلة الاتصال | B22 | `persons.contact_channel` | O | — | قائمة | ثابتة (`CONTACT_CHANNELS`) | — | `in:` | عادي | — |
| رقم الجوال | D22 | `persons.phone` / `households.contact_phone` | C | — | هاتف | — | الوسيلة اتصال/رسائل/واتساب | مطلوب عند الشرط | عادي | — |
| جوال بديل | G22 | `persons.alt_phone` | O | — | هاتف | — | — | ≤ 16 | عادي | — |
| سبب تعذر التواصل | — (جديد) | `households.contact_unreachable_reason` | C | — | نص | — | لا رقم في الطلب كله (مقدم الطلب، الأوصياء، الأم، الأسرة). إدخال القسم فقط | ≤ 300 | عادي | E1 |

## ب. الأسرة والسكن

| التسمية | الخلية | الحقل | إرسال | تحقق | العنصر | القائمة | الشرط | تحقق الخادم | الحساسية | الفحص |
|---|---|---|---|---|---|---|---|---|---|---|
| تاريخ التسجيل | H1 | `portal_applications.created_at` / `orphans.registered_on` | تلقائي | — | — | — | لا يُكتب يدويًا | — | عادي | — |
| رقم الأسرة | — | `households.reference_no` (`HH-…`، ثابت) | تلقائي | — | — | — | — | تسلسل | عادي | — |
| يتيم واحد على الأقل | — | `children` | R | — | بطاقات متكررة | — | — | ≥ 1 | — | E1 |
| وصي واحد على الأقل | — | `guardians` | R | — | بطاقات | — | — | ≥ 1 | — | E1 |
| التكوين الأسري | B9–J16 | `household_members` + `persons` (لا أعمدة طفل_1/طفل_2) | O | — | صفوف | `kinship_types` | — | — | عادي | — |
| عدد الأفراد / الذكور / الإناث / الأيتام | B17–H17 | `households.members_count, male_count, female_count, unknown_sex_count` | O | — | أرقام | — | المساواة تُفحص فقط إذا ذُكرت الأعداد؛ خانة «غير محدد» للجنس | لا تُعدل الأعداد، تُعرض الفروق | عادي | — |
| نوع السكن الأصلي | C19/C20 | `registry_addresses.housing_type_value_id` (kind=original) | O | — | قائمة | `original_housing_types` | — | `exists` | عادي | L |
| حالة السكن الأصلي | D19/D20 | `registry_addresses.building_condition_value_id` (original) | O | — | قائمة | `building_conditions` | — | `exists` | عادي | L |
| طبيعة السكن الحالي | E19/E20 | `registry_addresses.shelter_type_value_id` (current) | O | — | قائمة | `shelter_types` | — | `exists` | عادي | L |
| صفة السكن الحالي | F19/F20 | `registry_addresses.dwelling_condition_value_id` (current) | O | — | قائمة | `dwelling_conditions` | — | `exists` | عادي | L |
| العنوان السابق | G19 | `registry_addresses` (original) | C | — | محافظة ← بلدة ← حي ← تفاصيل | `governorates…` | انظر السطر التالي | — | عادي | E1 |
| العنوان الحالي | G20 | `registry_addresses` (current) | C | — | كذلك | كذلك | مطلوب إذا كانت الأسرة نازحة | — | عادي | — |
| سبب عدم تحديد السكن | — (جديد) | `households.location_missing_reason` | C | — | نص | — | لا عنوان حالي ولا أصلي | ≤ 300 | عادي | E1 |
| النزوح | ورقة البيانات P | `households.displacement_status` (yes/no/unknown) | O | — | نعم/لا/غير معروف | ثابتة | التواريخ والحركات تظهر عند «نعم» | CHECK | عادي | — |

## ج. اليتيم (بطاقة لكل طفل)

| التسمية | الخلية | الحقل | إرسال | تحقق | العنصر | القائمة | الشرط | تحقق الخادم | الحساسية | الفحص |
|---|---|---|---|---|---|---|---|---|---|---|
| الاسم رباعي | B9 | `persons.*_name` | R | — | نص ×4 | — | — | الأول والعائلة، أو الكامل | عادي | E2 |
| رقم الهوية | C9 | `persons.id_number` | O | L | نص | — | — | الصيغة؛ رقم مكرر في الطلب يُرفض؛ تعارض مع سجل قائم يُحوَّل للمراجعة | مقيد | E3 |
| سبب عدم توفر الهوية | — (جديد) | `persons.id_missing_reason` | C | — | قائمة | ثابتة | بلا رقم | `in:` | عادي | E3 |
| تاريخ الميلاد | D9 | `persons.date_of_birth` | O | L (للأهلية) | تاريخ | — | — | ≤ اليوم، وبعد ميلاد الأم، وقبل وفاة الأب + 300 يوم. لا تقدير للعمر | عادي | — |
| الجنس | E9 | `persons.sex` (NULL = غير معروف) | O | — | قائمة | ثابتة | — | CHECK male/female/NULL. **لم يعد مطلوبًا** | عادي | E3 |
| الحالة الاجتماعية | F9 | `persons.marital_status_value_id` | O | — | قائمة | `marital_statuses` | — | `exists` | عادي | — |
| الحالة الصحية | G9 | `orphans.health_status_value_id` + `care_records` | O | — | قائمة | `health_statuses` | التفاصيل لغير «معافى / غير معروف» | `exists` | مقيد (`health`) | — |
| المستوى التعليمي | H9 | `education_records.status_value_id` | O | — | قائمة | `enrollment_statuses` | — | `exists` | عادي | — |
| الصف الدراسي | C80 | `education_records.grade_value_id` | C | — | قائمة | `school_grades` (فيها «غير معروف») | ملتحق أو بشكل متقطع | `exists` | عادي | — |
| المستوى التحصيلي | D80 | `education_records.achievement_value_id` | O | — | قائمة | `achievement_levels` (فيها «لم يُقيَّم بعد») | — | `exists` | عادي | — |
| الجهة التعليمية | F80 | `education_records.institution_type_value_id` | O | — | قائمة | `institution_types` | — | `exists` | عادي | — |
| سبب عدم الالتحاق | E79 | `education_records.nonattendance_reason` | C | — | نص | — | متسرب، لم يلتحق، غير ملتحق | مطلوب | عادي | — |
| صلة القرابة بالوصي | I9 | `orphan_guardianships.kinship_value_id` | R | — | قائمة | `kinship_types` | — | `exists` | عادي | E1 |
| وصي الطفل | — | `orphan_guardianships` (`claimed` حتى قرار القسم) | R | L (اعتماد لكل طفل) | اختيار من الأوصياء | — | — | الوصي موجود في الطلب | عادي | E4 |
| حالة اليُتم | J9 | `orphans.orphan_class_value_id` + `class_basis` | مشتق | L | — | `orphan_classifications` | يُشتق من حالة الأب والأم **المتحقق منها**؛ قبل التحقق `class_basis=provisional` | لا يُستنتج موت من الفقد | عادي | E6 |

## د. الأب والأم

| التسمية | الخلية | الحقل | إرسال | تحقق | العنصر | القائمة | الشرط | تحقق الخادم | الحساسية | الفحص |
|---|---|---|---|---|---|---|---|---|---|---|
| الاسم | B25 | `persons` (شخص واحد لكل الإخوة) | C | — | نص ×4 | — | الوالد متوفى أو مفقود، أو الأم وصية | الاسم الأول والعائلة | عادي | E1 |
| رقم الهوية | D25 | `persons.id_number` | O | L | نص | — | — | الصيغة | مقيد | — |
| الحالة | E25 | `persons.vital_status` + `person_status_events` | R | L | على قيد الحياة / متوفى / مفقود / غير محدد | ثابتة | «غير محدد» إجابة مقبولة | CHECK. تاريخ وفاة مع حالة غير «متوفى» يُرفض | عادي | E6 |
| تاريخ الوفاة | F25 | `person_status_events.event_date` | C | L | تاريخ + «غير معروف» | — | متوفى ولم يُختر «التاريخ غير معروف» | ≤ اليوم وبعد الميلاد | عادي | E6 |
| سبب الوفاة | G25 | `person_status_events.cause_value_id` | O | — | قائمة | `death_causes` | — | `exists` | عادي | E5 |
| توضيح السبب | — | `person_status_events.cause` | C | — | نص | — | السبب = «سبب آخر» | مطلوب | عادي | E5 |
| وثائق وفاة؟ | F7 | `person_status_events.evidence_note` («وثائق الوفاة: …») | O | L | نعم/لا/غير معروف | ثابتة | متوفى | `in:` | عادي | — |
| آخر ظهور (مفقود) | — | `person_status_events.event_date` | C | — | تاريخ + «غير معروف» | — | مفقود | لا تاريخ وفاة ولا شهادة وفاة للمفقود | عادي | E6 |
| العمل السابق | H25 | `economic_assessments` (`period=pre_death`) | O | — | قائمة + نص | `employment_statuses` | — | `exists` | عادي | — |

## هـ. المستندات والإقرار

| التسمية | الحقل | إرسال | تحقق | ملاحظات | الفحص |
|---|---|---|---|---|---|
| المرفقات | `registry_documents` (خاصة، مشفرة المسار) | O | L | النقص مهمة استكمال (`missingDocuments`) لا عائق | E6 |
| الإقرار بصحة البيانات + الموافقة + اسم المُقر | `portal_applications.declaration_accepted_at`, `consent_accepted_at`, `declared_by_name` | R | — | عند الإرسال فقط، لا عند الحفظ التلقائي | E1 |

## و. دراسة الحالة (مرحلة مستقلة: `household_case_studies`، `CaseStudySchema` v1)
- **في المسودة:** كل الأسئلة اختيارية (O).
- **عند «إرسال للمراجعة»:** تُطبق الشروط في عمود «إرسال».
- **الأسئلة الشرطية:** تظهر فقط عند الإجابة الموجبة.
- **«أخرى» في أي قائمة:** تفتح توضيحًا مطلوبًا عند الإرسال (`<key>_other`).

| القسم (الصفوف) | الأسئلة | العنصر / القائمة | إرسال | الحساسية | الفحص |
|---|---|---|---|---|---|
| تاريخ الدراسة والباحث | `assessed_on`، `assessor_id` | تاريخ / تلقائي | R | — | CS |
| النفسي (28–37) | الحالة العامة `psych_general_states`؛ ما يقلقهم `child_worries`؛ التعامل مع الفقد `loss_coping`؛ أعراض؟ نعم/لا/غير معروف؛ صفوف لكل طفل (الأعراض `psych_symptoms`، التغيرات `behavioral_changes`، جلسات، جهة، علاج، مختص)؛ الرغبة في الجلسات وسبب «لا»؛ الاحتياجات | قوائم ومتعدد بشرائح | صفوف الأطفال C عند «نعم» | **مقيد (`health`)، مشفر منفصلًا** | CS |
| الاجتماعي (40–49) | العلاقات `family_relations`؛ القائم بالرعاية `daily_caregivers`؛ الاندماج `community_integration`؛ صراعات + نوعها `conflict_types`؛ وصمة + توضيح؛ علاقات داعمة + الداعم `supporter_types` أو سبب «لا»؛ خدمات + نوعها `received_service_types`؛ أنشطة `child_activities`؛ تفاعل مع الأقران؛ شعور بالاختلاف + توضيح؛ رغبة في التدريب + المجال `training_interest_fields`؛ التحديات | كذلك | «أخرى» C | عادي | E5 |
| الصحي (52–62) | الوضع العام `health_ratings`؛ التطعيمات؛ الغذاء؛ الرعاية الصحية؛ صعوبة الأدوية؛ مرض مزمن + صفوف (الشخص، `chronic_diseases`، `illness_states`، `treatment_availability`)؛ إعاقة + صفوف (`disability_types`، `health_aids`)؛ إصابة حرب + صفوف (`injury_severities`، `health_follow_up_types`)؛ أمراض وراثية + تحديد؛ الاحتياجات | كذلك | صف واحد على الأقل C عند «نعم» | **مقيد (`health`)، مشفر منفصلًا** | E7 |
| الاقتصادي (65–77) | مصادر الدخل `income_sources`؛ الدخل الشهري: مبلغ معروف (₪ عشري) / لا يوجد / غير معروف؛ الكفاية؛ النفقات `expense_burdens`؛ الاحتياجات الملحة؛ مساعدات غذائية؛ ديون + قيمة؛ أصول `productive_assets`؛ تدريب مهني `vocational_fields` + مكان؛ العاملون (صفوف)؛ المساعدات (صفوف: `assistance_types`، قيمة، جهة، `sponsorship_frequencies`، انتظام) | كذلك | المبلغ C عند «معروف»؛ الصفوف C عند «نعم» | عادي. المساعدات إفادة لا صرف | CS |
| التعليمي (78–87) | الالتحاق نعم/لا/متقطع/غير معروف؛ أسباب عدم الالتحاق `nonenrollment_reasons` (عند لا/متقطع)؛ صف لكل طفل (`school_grades`، `achievement_levels`، `enrollment_statuses`، `institution_types`، `learning_difficulties`)؛ احتياجات `educational_needs`؛ التحديات | كذلك | «أخرى» C | عادي | — |
| التقييم والتوصيات (90–97) | الوضع العام؛ الاحتياجات `household_needs`؛ التحديات؛ المقترحات؛ التوصيات النهائية | نص / متعدد | الوضع العام R، التوصيات R | عادي | CS |

**قواعد ثابتة لدراسة الحالة:**
- لا اعتماد تلقائي لأي استحقاق من الدراسة أو من درجة.
- المراجع رئيس القسم، ولا يراجع الباحث دراسته (فحص في الخدمة وقيد في قاعدة البيانات).
- السجل لا يحفظ نص أي إجابة.

**رموز الفحص:** E1–E10 معايير القبول، و L القوائم، و CS دراسة الحالة. النتائج في `ORPHAN_REGISTRATION_IMPLEMENTATION_REPORT.md`.
