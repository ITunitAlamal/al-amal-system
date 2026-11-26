# 02 — نموذج البيانات (القائم والمضاف)

```
portal_applications (draft→submitted→under_review→completed) ──form_data (مشفّر)
        │  اعتماد صريح لكل طفل: FamilyPromotion::promote
        ▼
households ─┬─ household_members ── persons ──┬── orphans ── orphan_parent_links ── persons (أب/أم)
            │                                 └── orphan_guardians ── orphan_guardianships (لكل طفل: النوع/السند/الجهة/المدة/الحالة)
            ├─ registry_addresses (original | current | displacement | last_known)
            │       + rooms_count, building_area_sqm, building_type/condition, furnishing_condition   ← جديد
            ├─ economic_assessments ── economic_income_items (مصدر، amount_status، مبلغ، عملة، فترة)   ← جديد
            └─ legacy_file_no (غير فريد)                                                              ← جديد
registry_documents (مالك: شخص/وصاية/أسرة/طلب؛ نوع، مراجعة، sha256، مقيّد)
registry_legacy_references (source_system, source_table, source_id → subject_type, subject_id, flags)       ← جديد
master_data_types/values: building_types, building_conditions, furnishing_conditions (+ income_sources: social_affairs, martyrs_wounded)  ← جديد
```

## القيود
**`economic_income_items`:**
- `amount_status` ∈ known/none/unknown.
- `(amount_status='known') ⇔ amount IS NOT NULL`، و`amount ≥ 0`.
- الفترة ∈ monthly/weekly/yearly/one_off.
- مصدر أو اسم إلزامي.
- العملة FK إلى `currencies` أو `NULL` (غير معروفة).

**`registry_addresses_housing`:** الغرف ≤ 100، والمساحة > 0.

**`registry_legacy_references`:**
- فريد على (`source_system`، `source_table`، `source_id`، `subject_type`).
- `subject_type` من قائمة مغلقة.
- لا بيانات شخصية؛ الأعلام من `LegacyReferences::FLAGS` فقط.

**ما لم يُجعل فريدًا (عمدًا):**
- `households.legacy_file_no`: 446 مجموعة مكررة في القديم.
- `persons.id_hash`: 537 مجموعة مكررة تحتاج مراجعة قبل أي قيد.

## الدلالات المحمية
**الاعتماد والوصاية:**
- الاعتماد المبدئي (`orphans.status=provisional` من الاستيراد) ليس تحققًا قانونيًا.
- الوصاية المستوردة أو المنقولة من مستوى الأسرة `claimed` حتى قرار بشري لكل طفل.
- اعتماد طلب تسجيل الموظف هو هذا القرار الصريح نفسه: `decided_by`، وتاريخ البدء، والسند.

**البوابة:** تتطلب حسابًا معتمدًا مستقلًا مرتبطًا بالوصي (`orphan_guardians.portal_account_id`)، ووصاية معتمدة لذلك الطفل، وطفلًا غير مبدئي.

**تاريخ الوفاة:** لا يحدد الحالة الحيوية.
