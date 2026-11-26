# 00 — الاكتشاف: ما هو قائم فعلًا في AIMS (2026-11-02)

## المدخلات
- **تقرير النظام القديم:** `Downloads/Compressed/alamal/docs/legacy/ORPHAN_GUARDIAN_FIELDS.md` (الأقسام 0–11)، **متاح وقُرئ**.
- **ما فحصته:** الشيفرة والمخطط الحي (`al_amal_db`، PostgreSQL)، وقاموس البيانات `docs/aims2/AIMS2_REGISTRATION_DATA_DICTIONARY_AR.md`.

**مستويات التأكيد:**

| الرمز | المعنى |
|---|---|
| **VERIFIED** | مقروء من الشيفرة أو المخطط ومفحوص |
| **REQUIREMENT** | مطلوب في المواصفة |
| **LEGACY-ONLY** | موجود في النظام القديم وحده |
| **PROPOSED** | مقترح |

## النتيجة الأساسية
تسجيل الأسرة والأيتام **مبني وفعّال** منذ 2026-10-31 (migrations `000008` و`000010` و`000011`). المطلوب في المواصفة يغطيه في معظمه، فلم أُنشئ نظامًا موازيًا. أضفت ما ينقصه فقط للتوافق مع حقول النظام القديم.

| المطلوب | الموجود (VERIFIED) | المسار |
|---|---|---|
| معالج مسودة قابل للاستئناف | <ul><li>`portal_applications` (`source=staff/portal`، `status=draft`)، و`form_data` مشفّر.</li><li>`form_version` لمنع ضياع التعديل، وحفظ تلقائي كل 60 ثانية، و`last_step`.</li></ul> | `app/Services/Orphans/Registration/FamilyRegistrations.php`، `resources/js/Pages/Orphans/Registrations/Edit.vue`، `resources/js/Components/Registration/*` |
| أسرة، وأطفال متعددون، ووالدان، وأوصياء، ومرفقات، ومراجعة | أقسام النموذج: `request` و`father` و`mother` و`guardians[]` و`household` و`residence` و`children[]` و`household.members[]` و`declaration` | `RegistrationForm.php` (القواعد والتحقق التقاطعي `issues()`) |
| شخص واحد لكل إنسان | <ul><li>`persons`: الهوية مشفّرة، و`id_hash`، و`id_format` (missing/valid/invalid/placeholder/other)، و`vital_status` (alive/deceased/missing/unknown).</li><li>إعادة الاستخدام بمطابقة الهوية الصالحة عند الاعتماد.</li></ul> | `FamilyPromotion::existingPerson` |
| علاقة والد‑طفل لكل طفل | `orphan_parent_links` (`relation`، `basis`، `verification`) | |
| وصاية/حضانة/ولاية لكل طفل | <ul><li>`orphan_guardianships`: `status`، و`authority_kind_value_id` من قائمة `guardian_authority_kinds` (وكالة رسمية، حضانة بقرار، ولاية/وصاية شرعية)، و`legal_basis`، و`authority_scope`، و`issuing_authority`، و`decision_no`، و`starts_on`/`ends_on`، و`provenance`.</li><li>مشغّل يمنع وجود يتيم بلا وصاية.</li></ul> | |
| الأسرة والعضوية | `households` و`household_members` (`starts_on`/`ends_on`، الصلة) | |
| العناوين الأصلي والحالي والنزوح | `registry_addresses.kind` ∈ original/current/displacement/last_known، مع `locations` الجغرافية | |
| التقييم الاقتصادي بالتاريخ والعملة | `economic_assessments` (`period`، و`income_amount`، و`currency`، و`income_source_ids`…) | |
| مستندات خاصة مصنفة بمراجعة | `registry_documents` (النوع من القائمة، و`review_status`، و`sha256`، و`is_restricted`، والمالك) | تنزيل بموجب صلاحية: `GET /orphans/registry/documents/{publicId}` |
| مراجعة واعتماد مبدئي | <ul><li>`ReviewsController`: إسناد، وقائمة تحقق، وطلب استكمال، وقرار **لكل طفل**.</li><li>`FamilyPromotion::promote` قرار صريح لكل طفل.</li><li>الاستيراد يكتب «معتمد مبدئيًا» (`provisional`).</li></ul> | |
| عزل البوابة | <ul><li>حراس وسياسات منفصلة.</li><li>`PortalScope` يعرض الطفل فقط إذا كان الحساب معتمدًا، والوصاية لذلك الطفل معتمدة وسارية، والطفل ليس مبدئيًا.</li></ul> | `app/Services/Portal/PortalScope.php` |
| قوائم قابلة للتعديل | `master_data_types` و`master_data_values` من «ثوابت النظام» | `RegistryQueries::values` |
| سجل تدقيق | `AuditService` ← `audit_logs` (إضافة فقط مع ختم) | |

## ما كان ناقصًا مقابل النظام القديم (أُضيف في `000016`)
1. **تفاصيل المسكن:** عدد الغرف، والمساحة، ونوع المبنى وحالته، وحالة الأثاث. لم يكن في AIMS إلا الحيازة ونوع المأوى ووصف نصي.
2. **الدخل لكل مصدر بمبلغه:** كان المصدر يُختار دون مبلغ مع إجمالي واحد. القديم يفصل الشؤون الاجتماعية والمعاشات والشهداء والجرحى والكفالات.
3. **«غير معروف» ≠ صفر:** لم يكن للمبلغ حالة صريحة.
4. **رقم الملف القديم ومراجع الصفوف القديمة.**
5. **تعارض تاريخ الوفاة مع الحالة:** لم يكن يُكشف. أي تاريخ وفاة مع حالة غير «متوفى» صار يُعرض للتصحيح، ولا يُستنتج.
6. **مصدرا دخل قديمان** غير موجودين في القائمة.

## ما لا يُعاد بناؤه
تعمل اليوم ولا يُعاد بناؤها: المعالج، والمراجعة، والاعتماد لكل طفل، والاستيراد الذكي، والبوابة. التعديلات كلها إضافية.
