# فهرس الأدلة وحدودها

**الفرع/HEAD:** `master` / `36705f5365b9c33a873a1b1631a650a820ec7d78`  
**تاريخ التقرير:** 2026-10-27  
**قاعدة بيانات:** لم يتم الاتصال بها أو قراءة metadata.  
**خصوصية:** لا يتضمن هذا الفهرس قيم أسرار أو بيانات شخصية.

## 1. المنهج والأوامر المنفذة

| الغرض | الأمر/الوسيلة | النتيجة أو الحد |
|---|---|---|
| حالة المستودع | `git status --short --untracked-files=all` | قبل إنشاء التقارير: 26 ملفًا متتبعًا معدلًا/محذوفًا و730 عنصرًا غير متتبع. تغييرات سابقة محفوظة؛ أضاف التدقيق بعد ذلك 6 ملفات تقرير غير متتبعة. |
| هوية المصدر | `git branch --show-current`; `git rev-parse HEAD` | `master`; `36705f5365b9c33a873a1b1631a650a820ec7d78`. لم يُنشأ commit تدقيق. |
| نسخ PHP | `php -v`; `composer --version`; `composer show --direct --no-interaction` | PHP 8.4.26، Composer 2.10.2، Laravel 13.36.0، Inertia Laravel 3.0.0، Boost 2.10.3، PHPUnit 12.5.39، Pint 1.32.1. |
| نسخ JS | `node --version`; `npm --version`; `npm list --depth=0 --json` | Node 26.12.0، npm 11.20.0، Vue 3.5.44، Inertia Vue 3.9.1، Vite 8.3.4، Tailwind 4.3.3، Axios 1.20.0. |
| جرد الملفات | قراءة أسماء الملفات/الامتدادات في مجلدات التطبيق | 72 controllers، 36 models، 176 PHP services، 99 migrations، 12 commands، 85 Vue pages، 29 Vue components، 7 PHP test files و8 Node test files. |
| اختبارات JS | `node --test tests/*.test.mjs` | 32 اختبارًا: 31 pass و1 fail في `tests/listExperience.test.mjs:86-98`. لا browser automation. |
| اختبار PHP | لم يُشغّل | `tests/TestCase.php` غير موجود/محذوف في worktree؛ ولمنع التباس bootstrap/DB لم تُشغّل اختبارات PHP. |
| ملفات البيئة | قراءة أسماء متغيرات `.env.example` فقط | لم تُقرأ `.env` أو أي قيمة سرية. المثال لا يثبت إعداد البيئة الفعلية. |
| قواعد `.ai/rules` | فحص وجود `.ai/rules` | غير موجود؛ قُرئت تعليمات `AGENTS.md`. |

## 2. بنية ومسارات التطبيق

| الادعاء | المرجع |
|---|---|
| وصف README القديم للمشروع كـ starter overlay، وحدود الوثيقة | `README.md:5-10,32-65` |
| Routing للويب وfield API، health path والـ middleware | `bootstrap/app.php:14-25` |
| الجذر يوجه إلى dashboard ومسارات الداخل تحت `auth` | `routes/web.php:63,103` |
| تجميع ملفات نطاقات HR/finance/rentals/organization/guardian/orphans/relief | `routes/web.php:717-732` |
| Shell Inertia عربي RTL | `resources/views/inertia.blade.php:1-17` |
| تعريف المهام المجدولة | `routes/console.php:1-34` |
| دعم PostgreSQL ضمن config مع default SQLite | `config/database.php:20,87-99` |
| إعداد locale/timezone | `config/app.php:68,81`; المثال `/.env.example:7` |
| نموذج جلسة قابل للتشفير وقيمته default/example false | `config/session.php:50`; `.env.example:32` |
| إعدادات queue تدعم drivers متعددة | `config/queue.php` |

## 3. الوحدات والأدلة الرئيسية

| الوحدة/الادعاء | مراجع المصدر |
|---|---|
| HR وملفات الموظفين والاستيراد | `routes/aims_employees.php:14-55`; `app/Services/Hr/EmployeeAccess.php`; `app/Http/Controllers/Admin/EmployeeProfileController.php`; `database/migrations/2026_10_18_193757_create_employees_table.php` |
| الإجازات/الحضور | `routes/web.php:658-689`; `app/Http/Controllers/EmployeeLeaveRequestController.php`; `app/Console/Commands/AttendanceSync.php`; `attendance_bridge/zk_readonly.py` |
| توافق `workflow_instance_id` | `app/Http/Controllers/EmployeeLeaveRequestController.php`; `database/migrations/2026_11_08_000001_link_leave_requests_to_workflow_engine.php:39-40` |
| توافق `node_type` والهيكل | `app/Http/Controllers/Admin/OrganizationController.php`; `database/migrations/2026_11_19_000002_organization_units_and_delegations.php:23-34,73-88,117-149` |
| المالية/المشتريات/المخزون | `routes/finance.php:13-75`; `app/Services/Finance/FinanceGuard.php:15-17,77-103`; `database/migrations/2026_10_30_000001_create_aims_finance_operational_tables.php` |
| نمط الدورة المستندية | `database/migrations/2026_11_15_000001_finance_documentary_cycle_mode.php:9-36`; `app/Services/Finance/FinanceGuard.php:50-82` |
| الإيجارات والمباني | `routes/aims_rentals.php:11-39`; `app/Services/Rentals/RentalsAccess.php:13-48`; `app/Http/Controllers/Admin/PropertyRentalsController.php` |
| الأيتام/الأسرة والحماية | `routes/aims_orphans.php:16-80`; `app/Services/Orphans/OrphanAccess.php:13-30,77-96`; `app/Http/Middleware/ProtectOrphanResponses.php:14-17` |
| بوابة الوصي والحارس | `routes/guardians.php:10-20`; `app/Http/Middleware/EnsureGuardianActive.php`; `app/Http/Controllers/Auth/GuardianPortalController.php` |
| عميل السجل المدني unavailable | `app/Providers/AppServiceProvider.php`; `app/Services/Orphans/UnavailableGuardianRegistry.php:9-18`; `app/Services/Orphans/GuardianRegistrationService.php:43,51` |
| حملات الإغاثة وتوزيعها | `routes/aims_relief.php:12-23`; `routes/field_api.php:9-19`; `app/Services/Relief/FieldSync.php:32-110`; `app/Http/Middleware/AuthenticateFieldDevice.php:11-31` |
| العميل الميداني | `field_client/package.json`; `field_client/README.md`؛ وجود المصدر في `field_client/src/`. الادعاءات التشغيلية في README لا تعادل اختبار build/توزيع. |
| طلبات الصيانة | `routes/web.php:518-527`; `app/Services/MaintenanceDraftService.php:12-34`; `app/Services/MaintenanceSubmissionService.php:10-46,88-95` |
| المراسلات V3 | `app/Services/Correspondence/CorrespondenceRegistry.php:33-87,388-406`; `app/Services/Correspondence/CorrespondenceAccess.php`; `routes/web.php:734-754` |
| نسخ V3.1 ومراجعتها | `app/Services/Correspondence/CorrespondenceEditions.php:27-63`; `database/migrations/2026_11_21_000001_create_correspondence_editions_table.php:15-58` |
| طباعة ومرفقات المراسلات | `app/Http/Controllers/CorrespondenceController.php:353-491`; `app/Services/Correspondence/CorrespondenceDocuments.php:18-76`; `resources/js/Pages/Correspondence/Print.vue` |
| محرك سير العمل | `app/Services/Workflow/WorkflowEngine.php:17-19,172-192`; `app/Services/WorkflowVisibilityService.php:23-88`; `database/migrations/2026_10_18_074026_create_aims_workflow_core_tables.php` |
| KPI/التقارير | `app/Http/Controllers/KpiController.php`; `app/Services/Kpi/KpiEngine.php`; `app/Services/Kpi/KpiSources.php` |
| SMS | `app/Services/HotSmsService.php:42-84`; `app/Services/Notifications/NotificationCenter.php:250-273` |
| التدقيق | `app/Services/AuditService.php:9-70`; أحداث المراسلات في `app/Http/Controllers/CorrespondenceController.php:353-489` |

## 4. الاختبارات والنتائج القابلة لإعادة الإنتاج

- `node --test tests/*.test.mjs` — 31/32 pass، الفشل الوحيد:
  - `tests/listExperience.test.mjs:86-98`
  - الفعلي: `{ box: 'sent', channel: 'internal', direction: 'incoming', q: '' }`
  - المتوقع: `{ box: 'sent', q: '' }`
- `tests/correspondencePrint.test.mjs:46-60` — اجتاز اختبارًا ساكنًا لقواعد طباعة A4/footer. لا يتحقق من هندسة PDF أو التداخل المرئي.
- `tests/homePriorities.test.mjs:127` — اجتاز فحص مصدر واجهة إدخال المهمة والتاريخ والوقت.
- `tests/Feature/OfficialCorrespondenceTest.php:132-250,933` — توجد تغطية مصدرية لمسارات المراسلات؛ لم تُشغل.
- `tests/Feature/OfficialCorrespondenceTest.php:1125-1177` — يوجد اختبار مصدر لتزامن دالة الترقيم بعمليتين واتصالين مستقلين، ويتحقق من الرقمين المتتاليين؛ لم يُشغّل، ولا يختبر دورة الإصدار كاملة.
- يتعارض ذلك مع `CORRESPONDENCE_TEST_RESULTS.md:52-57` الذي يذكر عدم اختبار عمليتين متزامنتين؛ لم يُعتمد التقرير التاريخي بوصفه نتيجة حديثة.
- `phpunit.xml` يحدد `DB_CONNECTION=sqlite` و`DB_DATABASE=:memory:`، لكن harness في worktree ناقص، ولذلك لم يجر اختبار PHP.

## 5. نتائج البحث السلبية وحدودها

- بحث `change_note` ضمن `app/`, `database/migrations/`, `tests/`: **لا نتائج**. هذا لا يثبت schema حية.
- بحث متصل بمكونات Gmail/Workspace أو Integration Hub أو SOAP/OpenAPI في `app/`, `routes/`, `config/`: لم يظهر تنفيذ عام؛ وُجد HotSMS ومزود سجل مدني غير متاح وfield API.
- جرد root لم يظهر `.github`, `.gitlab`, `docker`, `deploy`, `infra`, `k8s` أو ملفات نشر معروفة؛ النتيجة لا تغطي أنظمة/مستودعات خارجية.
- `app/Policies`, `app/Jobs`, `app/Events`, `app/Listeners`: فارغة أو غير موجودة؛ لا يعني هذا غياب خدمات التفويض أو scheduler.

## 6. أدلة تاريخية لا تعد إثبات تشغيل

توجد وثائق مثل `IMPLEMENTATION_PLAN.md`, `HANDOFF.md`, `ROLE_DEPARTMENT_ACCESS_MATRIX.md`, `CORRESPONDENCE_TEST_RESULTS.md`, وملفات `*_AUDIT*.md`. تحتوي هذه الوثائق لقطات زمنية وتأكيدات عن قواعد/اختبارات سابقة؛ لا تُستخدم هنا لتقرير حالة قاعدة أو مستخدم أو ترحيلة حاليًا. README يصف starter overlay (السطور أعلاه) بينما المصدر الحالي يتضمن وحدات أكثر؛ لذلك تم إعطاء الأولوية للشيفرة والـ manifests ونتيجة الاختبار الحالي.
