# تقرير إزالة تنفيذ «شؤون الأيتام والكفالات» القديم — 2026-10-31

**القرار:** إزالة كاملة قبل إعادة التصميم. بيانات القسم قابلة للإتلاف ولم يُحتفظ بنسخة منها (قرار المالك).
**الـ migration:** `2026_11_29_000001_decommission_orphans_department` — نُفذ بـ `--path`. لا يمكن التراجع عنه، ويتوقف إذا وُجدت أي تبعية غير متوقعة لأنه يحذف دون CASCADE.

## 1. ما حُذف من قاعدة البيانات

| العنصر | العدد | التفاصيل |
|---|---|---|
| جداول | 62 | كل `orphan_*` و`orphans_module_settings` و`relief_*` و`sponsor*` و`sponsorship*`، و`guardian_applications` و`guardian_application_children`، و`field_devices` و`field_access_tokens`، و`registry_requests` و`scoring_rulesets` |
| دوال triggers | 11 | التجميد، والإلحاق فقط، وحراسة الحملات والمدفوعات |
| Sequences | 17 | أرقام المراجع (ملف، أسرة، كفالة، حملة…) |
| أعمدة `guardians` | 12 | أعمدة ملف السجل: reference_no، وidentity_type، وdate_of_birth، وsex، وalt_phone، وemail، وoccupation، وemployer، وcreated_by، وorigin، وname_parts، وname_index. وأُعيد الجدول إلى صيغة البوابة الأصلية (phone/password NOT NULL) |
| عناصر القائمة | 12 + المجموعة | مجموعة `orphans_group` وكل عناصرها، و`orphans_delegations` و`relief_devices` و`orphans_settings`، و`finance_sponsorships` |
| الصلاحيات | 6 | `orphans.guardians.*` (5)، و`finance.sponsorships.record` مع إسنادها لدور finance_head ولمستخدم واحد |
| تعريفات workflow | 10 | ORPHAN_FILE_ACTIVATION، وORPHAN_CHANGE، وORPHAN_SOCIAL_VISIT، وSCORING_RULESET، وRELIEF_CAMPAIGN/ROSTER/EXCEPTION/CLOSURE، وSPONSORSHIP، وORPHAN_ASSISTANCE، مع إصداراتها وخطواتها وانتقالاتها. لم تكن لها أي حالة (instance) |
| أنواع الإشعارات | 15 | `orphans_*` و`relief_*` و`sponsor*` |
| مؤشرات KPI | 4 | ORPHAN_* (غير مفعّلة) مع إصداراتها |
| صفوف `migrations` | 24 | صفوف ملفات migration المحذوفة |
| ملفات التخزين | 3 مجلدات | `orphan_imports` (ملف الكشف 16MB)، و`orphans`، و`relief` |

وشمل الحذف بيانات مؤقتة (staging) غير مرحّلة: 48,518 صفاً و78,856 ملاحظة، إضافة إلى طلب وصي واحد و28 حدث تسجيل. وسُجّلت العملية في `audit_logs` بالإجراء `orphans.department.decommissioned` مع عدد صفوف كل جدول قبل الحذف.

## 2. ما حُذف من الكود

نسخة من كل ملف محذوف أو معدَّل في `storage/aims_patch_backups/orphans_decommission_20261031/`، وفيها قائمتان: `deleted_paths.txt` و`modified_paths.txt`. أغلب هذه الملفات لم يكن متتبَّعاً في git.

- **Controllers:** `Orphans/*` (10)، و`Relief/CampaignController`، و`Field/FieldApiController`، و`Admin/GuardianReviewController` و`OrphanDelegationController`، و`Finance/FinanceSponsorshipsController`.
- **الخدمات:** `app/Services/Orphans/**` (49 ملفاً مع Import)، و`app/Services/Relief/*` (13)، و`Progress/GuardianApplicationProgress`.
- **Models:** GuardianApplication وGuardianApplicationChild وOrphanChild وOrphanHealthRecord وOrphanHousehold وOrphanParent وOrphanPaymentInstruction.
- **الأوامر المجدولة:** `orphans:sponsorship-schedules` و`orphans:document-expiry` و`orphans:followups` و`orphans:sponsor-reminders` و`orphans:registry-import` (هذا الأخير كان يعمل كل دقيقة)، مع حذفها من `routes/console.php`.
- **Routes:** `aims_orphans.php` و`aims_relief.php` و`field_api.php`، مع إزالة تحميل الأخير كـ `api:` من bootstrap.
- **Middleware:** `AuthenticateFieldDevice`. وحُذف أيضاً ملف الإعداد `config/orphans.php`.
- **الواجهة:** صفحات `Pages/Orphans` (19) و`Pages/Relief` (4) و`Pages/Admin/Orphans` و`Finance/Sponsorships.vue`، ومكوّنات `Components/Orphans` (13)، وملفات `Support/orphan*.js` و`guardianProgress.js`، وقالب Blade `orphans/agreement-print`.
- **تطبيق التوزيع الميداني:** `field_client/` (Electron).
- **ملفات أخرى:** 25 ملف migration خاصة بالقسم، وseeder واحد، وfactory اثنتان، و7 ملفات اختبار خاصة بالقسم.

## 3. ما عُدِّل في الملفات المشتركة

- **WorkflowSubjects:** أُزيلت أنواع الأيتام العشرة.
- **ApprovalDelegations:** أُزيل نطاقا تفويض `orphans.*`.
- **PersonalWorkspace:** أُزيل عنصر «زيارات ومتابعات أيتام» من «مساحتي» ومن التقويم.
- **NavigationRegistry وNavigationAccessRules:** أُزيلت قواعد ظهور الأيتام الثمانية، واعتماد هذه الملفات على `OrphanAccess`.
- **NavigationPriorities:** بقي سياق «orphans» لموظفي القسم، مع روابط عامة فقط. وأُزيلت `relief_campaigns` من سياق العلاقات العامة.
- **ملفات أخرى:** DashboardLayoutResolver، وCustomFieldEntities (أُزيل الكيان `orphan_guardian`، ولم تكن له أي حقول)، وNotificationCenter، وFinanceGuard، وFinancePermissionCatalog، وبطاقة «قبض الكفالات» في `Finance/Index.vue`، و`routes/finance.php`، و`AppServiceProvider`، و`AppLayout.vue`.
- **بوابة الأوصياء:** بقي فيها الدخول والخروج وصفحة حساب فارغة. حُذف التسجيل الذاتي وتقديم الطلبات لأنهما كانا لتسجيل الأيتام فقط. وصار رابط صفحة دخول الموظفين يفتح `guardian.login`.
- **الاختبارات المشتركة:** حُدِّث NavigationPrioritiesTest وExecutiveDashboardTest وuiRecordActions بإزالة الحالات الخاصة بالأيتام.

## 4. ما بقي ولماذا

| العنصر | السبب |
|---|---|
| قسم ORPHANS ووحداته الأربع (U1–U4) ورئيسه المعيّن | جزء من الهيكل التنظيمي |
| ثوابت الأيتام والكفالات والإغاثة: 3 مجموعات و29 نوعاً | من «ثوابت النظام» المشتركة. تُراجع عند إعادة التصميم |
| جدول `guardians` (حساب واحد)، والحارس `guardian`، وGuardianLayout، وProtectOrphanResponses، وEnsureGuardianActive | البنية التحتية لبوابة المستفيدين |
| نوع الاستحقاق «كفالة» ووحدة `sponsorships` في FinanceSourceBridge | تصنيف مالي عام |
| `finance_restricted_funds` و`finance_stock_items` | سجلات مالية ومخزنية. كانت مرتبطة بالحملات من جهة واحدة فقط |
| `audit_logs` و`finance_audit_events` و`department_structure_changes` | سجلات تدقيق مؤسسية |
| migration `2026_11_01_000001` | تُنشئ جدول `guardians`. جداول الأيتام التي تُنشئها تحذفها migration الإزالة على أي تثبيت جديد |
| تسمية مجال KPI «الأيتام والكفالات» وقوالب وحدات القسم في StructureChanges | تسميات عامة يستعملها الهيكل والمؤشرات |
| `docs/` ووثائق المتطلبات | مرجع لإعادة التصميم |

## 5. الفحوص المنفذة (نتائج فعلية)

- **المفاتيح الأجنبية:** فُحصت قبل الحذف. لا يوجد أي مفتاح من جدول مشترك نحو جداول القسم، وكل الارتباطات كانت من القسم إلى الجداول المشتركة.
- **المراجع غير المباشرة:** فُحصت كل أعمدة النص وJSON في الجداول المشتركة. صُنّفت كل نتيجة: إما حُذفت، أو أُبقيت لأنها تدقيق أو ثوابت أو هيكل.
- **بعد الـ migration:** عدد الجداول 171 (كان 233). لا يبقى أي جدول أو دالة أو sequence أو عنصر قائمة أو صلاحية أو workflow أو إشعار أو KPI خاص بالقسم. صفوف `migrations` تطابق الملفات، والقيود كلها validated.
- **`route:list`:** 399 مساراً بلا أخطاء. لم يبقَ من مسارات القسم إلا 4 مسارات لدخول البوابة.
- **`npm run build`:** نجح.
- **فحص الصفحات داخل التطبيق** (طلبات GET عبر HTTP kernel، بدون كتابة):
  - المسارات المحذوفة ترجع 404: `/orphans`، و`/orphans/records`، و`/relief/campaigns`، و`/finance/sponsorships`، و`/admin/orphans/guardians`، و`/guardian/register`، و`/api/field/v1/server`.
  - الصفحات المشتركة ترجع 200 لمدير النظام، وعددها 14: مساحتي، والمهام، والطلبات، والحسابات، والهيكل، والثوابت، والمؤشرات، والمراسلات، والإيجارات، والحضور، وملفات الموظفين، والحقول الديناميكية، والتفويضات، والإشعارات.
  - `/login` و`/guardian/login` ترجعان 200.
  - قائمة مدير النظام فيها 58 عنصراً، ولا يوجد بينها أي عنصر للأيتام.
- **المجدول:** لا يحتوي أي أمر للأيتام.
- **لم يُنفَّذ:** اختبارات PHPUnit وJS، بطلب المالك. كما لم يُفحص الخادم `172.16.10.42:8080` عبر الشبكة لأنه لم يستجب.

## 6. مخاطر وعوائق متبقية

- لا عوائق عابرة للوحدات: لم تُترك أي عملية حذف بسبب تبعية.
- **الوحدات (U1–U4):** أُنشئت لهذا التصميم القديم، ويحسم الإبقاء عليها قرار تنظيمي بموافقة المدير التنفيذي.
- **الثوابت الخاصة بالقسم (29 نوعاً):** لا يستعملها الآن أي كود. يُقرَّر عند إعادة التصميم إبقاؤها أو تعطيلها.
- **حساب البوابة الوحيد** (Hamza Al mobayed): أُبقي عليه، لكن بلا خدمات.
- **فحص الشبكة:** لم يُفحص الخادم المنشور عبر الشبكة. يلزم فحص يدوي بعد إعادة تشغيله.
- **ملفات الاختبار:** `tests/TestCase.php` محذوف من المستودع مسبقاً، فاختبارات PHPUnit لا تعمل أصلاً.
- **migration مؤجّل:** `2026_11_21_000001_create_correspondence_editions_table` ما زال Pending، ولا علاقة له بهذه الإزالة.

## 7. نقطة البداية للتصميم الجديد

- لا يوجد في القسم أي جدول أو مسار أو صفحة أو صلاحية أو workflow أو أمر مجدول.
- ما يبقى متاحاً للبناء عليه: وحدة القسم في الهيكل ورئيسه، والثوابت، ومحرك الـ workflow، والتفويضات العامة، ومحرك KPI، والقائمة المركزية، والحسابات، وبوابة مستفيدين فيها الدخول فقط.
- يُبنى المخطط الجديد بـ migrations جديدة تأتي بعد `2026_11_29_000001`.
