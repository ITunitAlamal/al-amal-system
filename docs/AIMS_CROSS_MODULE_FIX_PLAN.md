# خطة الإصلاح عبر الوحدات (2026-11-01/02)

**المرجع:** `docs/AIMS_FULL_SYSTEM_AUDIT.md` (تدقيق 2026-11-01). نتائجه اعتُبرت فرضيات، وأعيد التحقق منها على الشيفرة والقاعدة الحية قبل أي تعديل.

**البيئة:**
- قاعدة البيانات: `pgsql` المحلية `al_amal_db`، وهي القاعدة التشغيلية الحية.
- خادم الويب: لا يخدم المشروع إلا عند تشغيله على الشبكة الداخلية (`APP_URL=http://172.16.10.42:8080`). أثناء العمل لم يكن هذا العنوان على الجهاز.

**قرارات المالك في هذه الجلسة:**
- إيقاف منح #31 المتعارضة والقديمة.
- تحويل الخادم إلى production.
- منع العمليات المالية عن الدور التقني لمدير النظام.
- التحقق على النظام الحقيقي دون قاعدة اختبار ولا متصفح.

## قائمة التنفيذ
| # | البند | الحالة |
|---|---|---|
| 1 | جرد الصلاحيات المالية والقوائم والمسارات وحالة الاستيراد | ✅ |
| 2 | تأمين الخادم: production، وإيقاف عرض الأخطاء وBoost | ✅ (HTTPS معلّق لدى مسؤول الشبكة) |
| 3 | تشخيص وصول محمد القصاص (#31) إلى «المشتريات» وإصلاحه | ✅ |
| 4 | منع الدور التقني لمدير النظام من العمليات المالية | ✅ |
| 5 | إظهار خطوات الاعتماد التي بلا مسؤول | ✅ (الإسناد قرار المالك) |
| 6 | قرارات المراجعة في الاستيراد: لا قبول جماعي للهوية والوصاية، وتدقيق، ومتابعة المراجعة | ✅ |
| 7 | فهرس `import_row_issues(row_id)`: صفحة الدفعة من نحو 7–9 ثوانٍ إلى نحو 1.1 ثانية | ✅ |
| 8 | استبعاد «معتمد مبدئيًا» من استعلام البوابة `PortalScope::orphanIds` | ✅ |
| 9 | صفحة `Orphans/Reviews/Changes` | ✅ |
| 10 | `phpunit.xml` دون مجموعة `tests/Unit` المفقودة | ✅ |
| 11 | باقي إصلاحات الاختبارات (التوقعات القديمة، SQLite، الخادم 55439) | ⏸ تحتاج إذنًا بتشغيل الاختبارات |
| 12 | إسناد المراجعة والاعتماد والرواتب والجرد | ⏸ قرار المالك والمدير التنفيذي |

## الملفات المعدّلة
- `app/Services/Finance/FinanceGuard.php`: `TECHNICAL_ADMIN` هي القائمة الوحيدة لما يملكه مدير النظام تقنيًا.
- `app/Services/Finance/FinanceAccessGrants.php` و`app/Http/Controllers/Finance/FinanceSetupController.php`: رفض منح غير القائمة التقنية لحساب مدير النظام.
- `app/Http/Controllers/Finance/FinanceOperationsController.php`: أوامر الشراء للمستلم والمحاسب في حالة خطوتهما فقط.
- `app/Http/Controllers/Finance/FinanceDocumentCycleController.php`: «السجل» للمشتريات والمحاسب والرقابة فقط.
- `app/Http/Controllers/Finance/FinanceProcurementController.php`: الرقابة تقرأ تفاصيل الطلب.
- `app/Services/Finance/FinanceReports.php`: تقرير أوامر الشراء للمشتريات فقط.
- `app/Http/Controllers/Finance/FinanceStaffController.php` و`app/Services/Finance/FinancePayrollRuns.php`: «خطوات بلا مسؤول».
- `resources/js/Pages/Finance/Index.vue` و`resources/js/Pages/Finance/Staff.vue`.
- `app/Services/Orphans/Import/ImportPipeline.php` و`app/Http/Controllers/Orphans/ImportController.php` و`resources/js/Pages/Orphans/Import/Batch.vue`.
- `app/Services/Portal/PortalScope.php`.
- `resources/js/Pages/Orphans/Reviews/Changes.vue` (جديدة) و`resources/js/Pages/Orphans/Reviews/Index.vue`.
- `database/migrations/2026_11_30_000014_import_row_issues_row_index.php`: RAN، 755ms، `CREATE INDEX CONCURRENTLY`.
- `phpunit.xml`.
- `.env`: `APP_ENV` و`APP_DEBUG` و`LOG_LEVEL` و`BOOST_ENABLED`، والنسخة السابقة في `storage/app/private/env-backups/`.

## الأوامر المنفذة
- `php artisan migrate --path=database/migrations/2026_11_30_000014_import_row_issues_row_index.php --force`
- `vendor/bin/pint <المسارات>`، و`npm run build`.
- سكربتات تحقق داخل معاملات `READ ONLY` تُلغى (خارج المشروع).
- سكربت إيقاف منح #31: كتابة فعلية بتدقيق.

## التراجع
- **الخادم:** إرجاع `.env` من `storage/app/private/env-backups/.env.2026-11-01-before-hardening`.
- **منح #31:** `valid_until = NULL` للصفوف 1 و2 و3 و11 و12 و15 و16 و17 و18 في `finance_user_permissions`، وكل صف ما زال موجودًا بسببه.
- **الفهرس:** `php artisan migrate:rollback --path=database/migrations/2026_11_30_000014_import_row_issues_row_index.php --force`.
- **الشيفرة:** `git diff` للملفات أعلاه.
