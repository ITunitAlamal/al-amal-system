# 01 — مطابقة حقول النظام القديم مع أسماء AIMS الفعلية

**المصدر:** تقرير النظام القديم، القسم 9، وقواعده R1–R14. **الهدف:** مفتاح النموذج ← الجدول.العمود الفعلي.

**الثقة:**
- **ع** = عالية، مطابقة مباشرة.
- **م** = متوسطة، تحتاج جدول مطابقة.
- **ق** = قرار مطلوب (`05_OPEN_DECISIONS.md`).

القيم المجهولة تبقى `NULL`، أو «غير معروف» حيث يوجد ذلك الخيار، ولا تتحول إلى صفر.

## اليتيم / فرد الأسرة — `aml_beneficiary_family_members`
| القديم | الهدف الفعلي | التحويل | الثقة |
|---|---|---|---|
| `id` | `registry_legacy_references` (`source_table=aml_beneficiary_family_members`، `subject_type=persons`، و`orphans` إن صار يتيمًا) | كما هو | ع |
| `first_name`…`last_name` | `children[].first_name/father_name/grandfather_name/family_name` ← `persons` | trim | ع |
| `full_name` | `persons.full_name` يُعاد حسابه | لا يُنقل؛ علَم `truncated_name` إن اختلف | ع |
| `id_no` | `persons.id_number` (مشفّر) + `id_hash` + `id_format` | <ul><li>نص.</li><li>1–3 أرقام → `placeholder`.</li><li>مكرر → علَم `duplicate_id_group`.</li></ul> | ع |
| `bod` | `persons.date_of_birth`، `dob_status=declared` | يُرفض المستقبل | ع |
| `sex` | `persons.sex` | 1 → `male`، 2 → `female`، NULL يبقى فارغًا | ع |
| `relationship_id` | `children[].kinship_value_id` / `household_members.relation_value_id` (`kinship_types`) | جدول مطابقة | م/ق (D2) |
| `social_status_id` | `persons.marital_status_value_id` (`marital_statuses`) | مطابقة | م |
| `qualification_id` | `education.status_value_id` / `education.stage_value_id` | جدول مطابقة (القائمة مختلطة) | ق (D7) |
| `class` | `children[].education.grade` | نص | م |
| `education_level_id` (أداء) | `children[].education.performance` | ممتاز…ضعيف كنص | م |
| `work` | للأفراد الآخرين: `persons.notes` / `is_employed` | نص | م |
| `health_status_id` | `children[].health.status_value_id` (`health_statuses`) | 1 لا مشاكل، 2 مرض، 3 إعاقة؛ مقيّد | م |
| `disease_type` | `children[].health.details` (مقيّد) | نص | م |
| `disability_type` | `children[].health.disability_type_value_id` أو `details` | نص → قائمة بجدول مطابقة | م |
| `benefit_type_id` / `benefit_sub_type_id` | لا مقابل | يُحفظ في `registry_legacy_references.flags` أو ملاحظة | ق (D4) |
| `image` | `registry_documents` (نوع «صورة شخصية»، `is_restricted`) | تخزين خاص | ع |
| `beneficiary_id` | `household_members.household_id` عبر مرجع الأسرة المرحّلة | — | ع |
| `skills`، `other` | — | لا يُرحَّل (R14) | ع |

## الأب والأم — `aml_beneficiary_parent` (النوعان 1 و2)
| القديم | الهدف الفعلي | التحويل |
|---|---|---|
| الأسماء الأربعة، `id_no`، `birth_date` | `father.*` / `mother.*` ← `persons` | R1، R2 |
| `died_date` | `father/mother.vital_status` + `death.date` ← `person_status_events` | <ul><li>R5.</li><li>الأم «متوفاة» فقط بالشرط المعتمد.</li><li>غير ذلك: `unknown` مع التاريخ كبيان مُبلَّغ، وعلَم `maternal_date_conflict`.</li><li>النموذج يرفض الآن «تاريخ وفاة + حالة غير متوفى».</li></ul> |
| `die_reson` | `death.cause_value_id` (`death_causes`) أو نص | مطابقة |
| `die_place` | `death.place` | نص |
| `work` | `economic.occupation` (`period=pre_death` للأب) | نص |
| `income_value` (أب) | `father.economic.income_amount`، والعملة `NULL` | <ul><li>بلا عملة.</li><li>صفر مع غياب العلَم → `NULL`.</li></ul> |
| `average_income` (أم) | `mother.economic.income_amount` | نص → رقم عند الإمكان |
| `other_notes` | `economic.notes` | نص |
| `social_status_id` (أم) | `mother.marital_status_value_id` | مطابقة؛ القيمة 5 لقاعدة R5 |
| `live_with_kids` | `mother.resides_with_household` | 1 → `yes`، 0 → `no`، وغيرهما `unknown` |
| `education_stage_id` / `specialization_id` | `persons.education_level_value_id` / `education_specialization` | مطابقة |
| `mobile` | `persons.phone` | نص |
| الصحة (أم) | قسم الصحة المقيّد | R12 |
| `governorate_id`…`address` (أم) | `registry_addresses` (`person_id`، `kind=current`، `source=self_reported`) | مطابقة جغرافية عبر `locations` |

## الوصي / الحاضن / الولي (الأنواع 3/4/5)
| القديم | الهدف الفعلي | التحويل |
|---|---|---|
| النوع 3/4/5 | `orphan_guardianships.authority_kind_value_id` (`guardian_authority_kinds`: ولاية/وصاية شرعية، حضانة بقرار، وكالة رسمية) | **D3**: النوعان 3 و5 كلاهما في «ولاية/وصاية» حاليًا |
| الأسماء، `id_no`، `birth_date` | `guardians[]` ← `persons` + `orphan_guardians` | إعادة استخدام شخص الأم إن تطابقت الهوية |
| `relation_id` | `orphan_guardianships.kinship_value_id` لكل طفل | <ul><li>تُطبَّق على كل طفل.</li><li>علَم `family_level_guardian`.</li><li>**`status=claimed`** لا `approved`.</li></ul> |
| `guardianship_date` | `orphan_guardianships.starts_on` | — |
| `guardianship_sponsor` / `nursery_place` / `state_place` | `orphan_guardianships.issuing_authority` | نص |
| `mobile` | `persons.phone` (للوصي متاح الآن) | نص |
| العنوان | `registry_addresses` للشخص | مطابقة جغرافية |
| — | `orphan_guardianships.status` | دائمًا `claimed` حتى قرار بشري لكل طفل؛ لا حساب بوابة |

## الأسرة — `aml_beneficiary`
| القديم | الهدف الفعلي | التحويل |
|---|---|---|
| `id` | `registry_legacy_references` (`aml_beneficiary` ← `households`) | فريد لكل صف |
| `family_id` | **`households.legacy_file_no`** (جديد، غير فريد) | مرجع فقط (R8) |
| `beneficiary_type_id` | علَم في `registry_legacy_references.flags`، لا تصنيف يُتم | R6 |
| `mobile1` / `mobile2` | `household.contact_phone` / `persons.alt_phone` لمقدم الطلب | نص |
| `family_count` و`male_count` و`female_count` | `households.members_count` و`male_count` و`female_count` | كما هي؛ الفرق يُعرض في النموذج |
| `fixed_income` ومكوناته | **`economic_income_items`** (جديد): سطر لكل مصدر، `amount_status`، والعملة `NULL` | المصادر: `social_affairs` (جديد)، `pension`، `martyrs_wounded` (جديد)، و«أخرى» باسم في `label` |
| `other_fixed_income*` | `economic_income_items` (`label` = المكان/الوصف) | كما سبق |
| `sponsorships`، `sponsorship_member_count`، `monthly_sponsorships_amount`، `other_income_*` | `household.aid[]` ← `external_support_records` (مُبلَّغ وليس كفالة الأمل) | — |
| `total_income` | `economic_assessments.income_amount`، والعملة `NULL` | نص → رقم |
| `describe_economic_status` | `economic_assessments.notes` | نص |
| `house_type` / `rent_value` | `registry_addresses.tenure_value_id` (ملك/إيجار) / `economic_assessments.rent_amount` | 0/NULL → غير معروف |
| `rooms_count` | **`registry_addresses.rooms_count`** (جديد) | رقم |
| `members_count_in_room` | يُحسب في الواجهة من العدد والغرف، ولا يُخزَّن | — |
| `building_status` / `building_type` / `building_space` / `furniture_case` | **`building_condition_value_id` / `building_type_value_id` / `building_area_sqm` / `furnishing_condition_value_id`** (جديدة) | نص قديم ← قائمة **بجدول مطابقة فقط** (R10)؛ وإلا في `housing_condition` |
| `describe_building` | `registry_addresses.housing_condition` | نص |
| `describe_social_status` | ملاحظة تقييم قديم (`social_assessments.summary` بحالة غير معتمدة) | لا يُعدّ تقييمًا معتمدًا |
| الحقول التاريخية 34–39 | ملاحظات أو سجل تاريخي | R11 |
| — | العنوان الأصلي والنزوح | «غير معروف» (لا مصدر) |

## المرفقات
| القديم | الهدف | التحويل |
|---|---|---|
| `aml_beneficiary_attachments` | `registry_documents` (`household_id`، النوع «مستند آخر»، `review_status=pending`، `is_restricted=true`) + علَم `unclassified_attachment` | <ul><li>MIME من المحتوى.</li><li>تخزين خاص.</li><li>لا روابط عامة.</li></ul> |
| `family_members.image` | `registry_documents` (`person_id`، «صورة شخصية») | كما سبق |
