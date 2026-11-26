# 03 — سجل التنفيذ (2026-11-02)

| الملف | التغيير |
|---|---|
| `database/migrations/2026_11_30_000016_registration_legacy_coverage.php` | <ul><li>قوائم السكن الثلاث، ومصدرا الدخل، وأعمدة السكن.</li><li>`households.legacy_file_no`.</li><li>`economic_income_items`، و`registry_legacy_references`.</li><li>`down()` يرفض التراجع إذا وُجدت بيانات.</li><li>**RAN** في 678ms.</li></ul> |
| `app/Services/Orphans/Registration/RegistrationForm.php` | <ul><li>قواعد `household.legacy_file_no` و`household.income_items.*`.</li><li>قواعد `residence.current.rooms_count/building_*/furnishing_*`.</li><li>الثابت `AMOUNT_STATUS`.</li><li>رقم الملف القديم صار للموظفين فقط: البوابة لا تراه ولا تغيّره.</li><li>كشف «تاريخ وفاة مع حالة غير متوفى».</li></ul> |
| `app/Services/Orphans/Registration/FamilyPromotion.php` | <ul><li>حفظ رقم الملف القديم، وتفاصيل السكن في العنوان الحالي.</li><li>سطر دخل لكل مصدر: المبلغ فقط عند «معروف».</li><li>`economic()` تعيد المعرف.</li></ul> |
| `app/Services/Orphans/Registration/FamilyRegistrations.php` | القوائم الجديدة في `options()` |
| `app/Services/Orphans/Registration/LegacyReferences.php` (جديد) | تسجيل مرجع الصف القديم مرة واحدة (آمن عند الإعادة)، بأعلام مراجعة مغلقة |
| `resources/js/Components/Registration/HouseholdSection.vue` | <ul><li>رقم الملف القديم (للموظفين).</li><li>«الدخل حسب المصدر» مع حالة المبلغ والعملة.</li><li>تنبيه إذا خالف مجموع المصادر الإجمالي، دون تصحيح.</li></ul> |
| `resources/js/Components/Registration/ResidenceSection.vue` | الغرف والمساحة ونوع المبنى وحالته والأثاث، ومؤشر «فرد/غرفة» للعرض فقط |
| `resources/js/Support/registration.js` | القيم الافتراضية للحقول الجديدة (المسودات القديمة تُفتح بلا خطأ) |

**الأوامر:**
- `php artisan migrate --path=database/migrations/2026_11_30_000016_registration_legacy_coverage.php --force`
- `RegistryQueries::forgetOptions()`
- `vendor/bin/pint <المسارات>`
- `npm run build`

**التراجع:** `php artisan migrate:rollback --path=database/migrations/2026_11_30_000016_registration_legacy_coverage.php --force`. يرفض التراجع إذا وُجدت بيانات في الحقول الجديدة.

**Git:** لم أعمل commit ولا فرعًا. المستودع فيه تعديلات غير محفوظة من جلسات سابقة كثيرة على `master`، وأي commit الآن سيضمها كلها. ينتظر قرار المالك.
