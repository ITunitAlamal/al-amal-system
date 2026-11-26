# تحسين الواجهة والتنقل — 2026-10-29

## نطاق التنفيذ والتحقق

التنفيذ يخص الغلاف المشترك ولوحة التحكم. لم يعدّل قاعدة PostgreSQL أو مسارات الخادم أو الصلاحيات أو حسابات الحضور أو ملفات Excel. ملفات الحضور تُعدّل في نافذة أخرى؛ لذلك لم تُكتب فوقها. التحسينات المشتركة تظهر في الحضور عبر AppLayout، وليست ادعاء بإكمال إعادة تصميم تبويبات الحضور.

المهارات المستخدمة: inertia-vue-development وtailwindcss-development وtesting-best-practices. تمت مراجعة AGENTS.md؛ لا يوجد مجلد .ai/rules. الحزم: Laravel 13.36.0، Inertia Vue 3، Vue 3، Tailwind 4. استُخدم Laravel Boost search-docs. لم تُضف تبعيات أو خطوط.

## نتائج التدقيق والتعديل

- القائمة أصلًا مركزية: NavigationRegistry يعرّف الوجهات، NavigationBuilder يرشح المستخدم النشط والصلاحيات والجمهور، وNavigationAccessRules يحدد الوصول. إعدادات الإدارة المخزنة تحدد التسميات والترتيب. لم تُستبدل بقائمة ثابتة جديدة.
- المصدر الموجود يستخدم SVG آمنًا بمسارات ثابتة؛ لا حاجة لمكتبة أخرى. أضيفت أشكال دلالية للحضور والدوام التعويضي وملفات الموظفين وطلب الإجازة عند استخدام الأيقونة العامة الافتراضية فقط. اختيار المسؤول لأيقونة مختلفة يبقى نافذًا. الأيقونات لا تحتوي نص SVG غير موثوق أو v-html.
- البحث الجديد يرشح القائمة المستلمة من الخادم فقط، ويدعم تجاهل التشكيل العربي والتطويل. لا يضيف مسارات أو يستدعي بحثًا عامًا عن سجلات الموظفين. تطابق اسم مجموعة يعرض أبناءها المسموحين، والبحث الجزئي يعرض الروابط المطابقة.
- قائمة الهاتف سابقًا كانت تتحرك خارج الشاشة دون عزل عناصرها عن لوحة المفاتيح. أضيف inert عند الإغلاق، وحصر التركيز عند الفتح، وEscape، واستعادة التركيز وزيادة مساحة اللمس، وقفل تمرير الخلفية. تنتهي هذه الحالة عند الانتقال أو العودة إلى عرض سطح المكتب أو إزالة المكوّن.
- أضيف رابط تجاوز القائمة إلى المحتوى، وتسميات للأيقونات عند طي القائمة، وحلقة تركيز واضحة على الخلفية الكحلية. يغلق Escape قائمة المستخدم أيضًا.
- كانت لوحة التحكم الشخصية تستخدم CSS columns مما يجعل ترتيب القراءة المرئي مختلفًا عن ترتيب لوحة المفاتيح. أصبحت شبكة مرتبة، مع الحفاظ على ترتيب البطاقات وبياناتها وصلاحياتها. توسع المحتوى إلى حد 100rem وتقليل الحواف يدعمان الشاشات الواسعة.
- حفظ حالة المجموعات والطي والتمرير الموجود سابقًا بقي مستمرًا. يُمسح البحث عند طي القائمة كي لا يترك مرشحًا خفيًا.

## خريطة التجميع والاستخدام

لا توجد قياسات استخدام فعلية؛ التكرار التالي تقدير وظيفي، وليس تحليلات إنتاج. بقيت التجميعات المخصصة للمؤسسة بدل دمج صلاحيات مختلفة أو تعديل navigation_items.

| المجموعة | الغرض والتكرار المتوقع | قرار التحسين |
|---|---|---|
| مساحتي، مهامي، المراسلات | عمل يومي | روابط مباشرة، أيقونات ثابتة، حالة نشطة |
| طلباتي | إنشاء ومتابعة الطلبات | مجموعة قابلة للطي وبحث محلي |
| الدوام والإجازات | حضور وطلبات وأرصدة | إبقاء الفصل بين الروابط المسموحة، تمييز أيقونة الحضور |
| الموظفون | ملفات وهيكل وحسابات | تمييز الملف عن مجموعة الموظفين |
| المحاسبة، الرواتب، الموازنات | عمليات مالية مختلفة الصلاحيات | لا دمج يطمس حدود العمل |
| الأصول والإيجارات، الأيتام والأوصياء | أعمال تخصصية | المحافظة على المجموعات الحالية |
| الإعدادات والصلاحيات | إعداد عرضي | مجموعة قابلة للطي وفق صلاحية الخادم |

الأسطح الأخرى المفحوصة: قائمة المستخدم في AppLayout، البحث العام QuickSearch، بوابة الأوصياء GuardianLayout المنفصلة، وتبويبات الحضور. روابط المهام والمراسلات أعلى الصفحة اختصارات بأعداد وليست مصدر صلاحيات جديدًا. بوابة الأوصياء ليست قائمة موظفين مكررة. لم تُحذف وظائف أو وجهات.

## الملفات

- resources/js/Layouts/AppLayout.vue: البحث، الوصول، درج الهاتف، أحجام اللمس ومساحة المحتوى.
- resources/js/Support/navigationFilter.js: بحث في الشجرة المسموحة دون تعديل الأصل.
- resources/js/Support/navigationDrawer.js: دورة التركيز والعزل والتنظيف.
- resources/js/Support/navigationIcons.js: أشكال دلالية واختيار آمن يحافظ على تخصيص المسؤول.
- resources/js/Pages/Home.vue: شبكة البطاقات وفق ترتيب القراءة.
- tests/navigationAccessibility.test.mjs: اختبارات البحث، اختيار الأيقونات، دورة درج الهاتف.

## الاستجابة حسب العرض

هذه مراجعة قواعد CSS، وليست نتائج قياس بصري:

| العرض CSS px | السلوك المقصود |
|---|---|
| 360، 390، 428 | درج بعرض لا يتجاوز الشاشة ناقص 32px؛ أهداف لمس 44px؛ لوحة شخصية بعمود واحد؛ محتوى قابل للالتفاف |
| 768 | درج الهاتف مستمر، مع زيادة حواف المحتوى؛ لا قائمة جانبية دائمة |
| 1024 | قائمة جانبية دائمة قابلة للطي؛ إنهاء عزل درج الهاتف وقفل التمرير |
| 1280، 1440 | لوحة شخصية بعمودين، ومساحة محتوى أوسع من الحد القديم |
| 1920 | حتى ثلاثة أعمدة للوحة الشخصية؛ عرض أقصى 100rem للمحتوى |

توزيع اللوحة التنفيذية المخصص بقي محفوظًا. جداول الحضور تستخدم أصلًا aims-responsive-table وبطاقات الهاتف وتفاصيل اليوم؛ لم تتغير حساباتها. لا يوجد ادعاء بمنع كل تجاوز أفقي في جميع صفحات المشروع؛ لم تُختبر جميع النوافذ أو النصوص الطويلة بصريًا.

## إرشادات المكونات والأيقونات

أيقونات القائمة 20px وstroke 1.8 وcurrentColor، مخفية عن قارئ الشاشة مع إبقاء تسمية الرابط. الأشكال الجديدة ملفات JS ثابتة فقط؛ صورة أو markup من قاعدة البيانات لا يرسم مباشرة. chart للتقارير وsettings للإعدادات محفوظتان. correction متاح لإعادة الاستخدام لاحقًا؛ لم يُربط بتبويب الحضور الجاري تعديله في النافذة الأخرى. إظهار حالات الحضور يبقى نصًا ولونًا معًا وفق المكوّن الموجود. لا تضف قائمة مستقلة لصفحة عمل؛ استخدم قائمة الخادم.

## التحقق والحدود

- 10 اختبارات Node نجحت: الوصول للقائمة (3)، التنقل وحفظ المرشحات (2)، طباعة المراسلات (5).
- ExecutiveDashboardTest: اختباران و32 تأكيدًا نجحا على SQLite :memory:؛ يغطيان تخصيص المدير التنفيذي والإدارة والموظفين والأدوار المنتهية/المستقبلية. لا يمثلان اختبار كل مسار لكل دور.
- اختبار إضافي AttendanceControllerDeviceStatusesTest على SQLite :memory:: نجحت 7 من 8 حالات، مع 38 تأكيدًا. فشلت حالة قراءة الصحة المخزنة لأن جدول الاختبار attendance_sync_runs لا يحتوي العمود error الذي يطلبه الكنترولر الحالي (runs.error)، فاستجاب 500 بدل 200. لم يُعدّل الاختبار أو الكنترولر ضمن عمل الواجهة بسبب التعديلات المتزامنة للحضور. يحتاج مواءمة بيانات الاختبار بعد تثبيت عمل النافذة الأخرى؛ لا تُعرض المجموعة كاملة كناجحة.
- npm run build نجح. بقيت تحذيرات WOFF2 سابقة؛ لم تُنزّل خطوط.
- أعيد اختبارا التنقل واختبارات الوصول الثلاثة بعد اللمسات النهائية: 5/5، والبناء النهائي نجح خلال 24.21 ثانية. git diff --check لم يعرض أخطاء مسافات؛ معظم ملفات المشروع غير متتبعة، لذا لا يمثل وحده مراجعة جميع الملفات الجديدة.
- لم تُشغّل اختبارات ضد al_amal_db، ولم تُنفّذ ترحيلات.
- لم تُستخدم صور شاشة أو متصفح آلي. اختبار التركيز يستعمل عناصر وهمية ويثبت المنطق، لا التوافق الفعلي مع كل قارئ شاشة. لا يوجد أمر lint في package.json.
- تحتاج التبويبات الداخلية للحضور والتصميم المرئي الشامل مراجعة بعد انتهاء النافذة الأخرى. اختبار كل نقطة عرض وكل دور في واجهة حقيقية غير منفذ.

## الرجوع الآمن

النسخة السابقة للتعديل موجودة في storage/aims_patch_backups/ui_navigation_20261029، مع routes.json لقائمة الوجهات. لاسترجاع هذا العمل راجع الفروق أولًا، ثم استرجع AppLayout.vue وHome.vue فقط من النسخة بعد التأكد من عدم وجود عمل أحدث. أزل استيرادات المساعدات قبل إزالة ملفاتها الجديدة. شغّل npm run build. لا تستخدم git reset أو تنظيف الملفات غير المتتبعة؛ المشروع يحتوي عملًا متزامنًا. لا يوجد تراجع قاعدة بيانات لهذا التغيير.

## جدول الوجهات من المصدر

الجدول التالي يصف تعريفات الكود وليس القائمة الفعلية لكل حساب؛ الجمهور والتفعيل وتخصيصات الإدارة قد تخفي عناصر. شروط الصلاحية المركبة تبقى كما في NavigationAccessRules، ولا تُختزل إلى اسم دور واحد. معاملات المسار محفوظة في عمود مستقل.

| Item | Route / URL | Parameters | Access rule | Group | Icon |
|---|---|---|---|---|---|
| مساحتي (dashboard) | ui.preview / ui-preview | [] | authenticated | null | layout |
| مهامي (my_tasks) | tasks.index / tasks | [] | authenticated | null | inbox |
| مهامي الشخصية (my_todo) | todo.index / my/todo | [] | authenticated | null | clipboard-list |
| المراسلات الرسمية (official_correspondence) | correspondence.index / correspondence | [] | authenticated | null | mail |
| ملفي الوظيفي (my_profile) | my.profile / my/profile | [] | employee_profile | null | file-text |
| قسمي (my_department) | department.workspace / my-department | [] | department_workspace | null | users |
| مؤشرات الأداء (kpi_dashboard) | kpi.index / kpi | [] | kpi_view | null | chart |
| التفويضات الإدارية (approval_delegations) | admin.approval-delegations.index / admin/approval-delegations | [] | approval_delegations | null | key |
| جميع طلباتي (my_requests_all) | requests.index / requests | [] | authenticated | 'my_requests' | clipboard-list |
| طلب إجازة (leave_request) | leaves.create / leaves/create | [] | leave_requester | 'my_requests' | calendar |
| أنواع الطلبات المفعلة (startable_workflows) | @startable_workflows | [] | authenticated | 'my_requests' | handshake |
| مسودة طلب صيانة (maintenance_draft) | maintenance.requests.create / maintenance/requests/create | [] | authenticated | 'my_requests' | wrench |
| الإجازات والموافقات (leaves_requests) | leaves.index / leaves | [] | authenticated | 'leaves' | calendar |
| أرصدة الإجازات (leaves_balances) | leaves.balances / leaves/balances | [] | authenticated | 'leaves' | list |
| الحضور والانصراف (attendance) | admin.attendance.index / admin/attendance | [] | attendance_access | 'leaves' | calendar |
| العطل والعمل التعويضي (attendance_compensatory) | admin.attendance.compensatory.index / admin/attendance/compensatory | [] | attendance_access | 'leaves' | calendar |
| عمليات الحضور والأجهزة (attendance_operations) | admin.attendance.operations / admin/attendance/operations | [] | manage_employees | 'leaves' | settings |
| ملفات الموظفين (employee_profiles) | admin.employee-profiles.index / admin/employee-profiles | [] | manage_employees | 'hr_group' | users |
| استيراد الموظفين (employee_imports) | admin.employee-imports.index / admin/employee-imports | [] | manage_employees | 'hr_group' | list |
| حسابات الموظفين والأدوار (employee_accounts) | admin.employees.index / admin/employees | [] | manage_employees | 'hr_group' | key |
| الهيكل التنظيمي (departments) | admin.organization.index / admin/organization | [] | org_structure | 'hr_group' | building |
| متابعة الموظفين والخدمات (hr_dashboard) | hr.dashboard / hr-services | [] | hr_dashboard | 'hr_group' | chart |
| بانتظار إجرائي (finance_pending) | finance.index / finance | ['view' => 'pending'] | finance_cycle | 'purchasing_stores' | inbox |
| الدورة المستندية (finance_overview) | finance.index / finance | ['view' => 'overview'] | finance_cycle | 'purchasing_stores' | git-branch |
| طلبات الشراء (finance_purchases) | finance.index / finance | ['view' => 'purchases'] | finance_purchasing | 'purchasing_stores' | shopping-cart |
| عروض الأسعار (finance_quotes) | finance.index / finance | ['view' => 'quotes'] | finance:finance.procurement.manage&#124;finance.procurement.review | 'purchasing_stores' | clipboard-list |
| أوامر الشراء (finance_orders) | finance.index / finance | ['view' => 'orders'] | finance:finance.procurement.manage&#124;finance.procurement.review&#124;finance.purchase.receive&#124;finance.purchase.invoice | 'purchasing_stores' | clipboard-list |
| فواتير الموردين (finance_invoices) | finance.index / finance | ['view' => 'invoices'] | finance:finance.purchase.invoice&#124;finance.procurement.review | 'purchasing_stores' | list |
| الموردون (finance_suppliers) | finance.index / finance | ['view' => 'suppliers'] | finance:finance.procurement.manage&#124;finance.procurement.review | 'purchasing_stores' | handshake |
| الاستلام (finance_receipts) | finance.index / finance | ['view' => 'purchase_receipts'] | finance:finance.procurement.manage&#124;finance.procurement.review&#124;finance.purchase.receive&#124;finance.purchase.invoice | 'purchasing_stores' | inbox |
| الصرف وحركات المخزون (finance_stock) | finance.index / finance | ['view' => 'stock'] | finance:finance.stock.receive&#124;finance.stock.issue&#124;finance.warehouse.manage | 'purchasing_stores' | list |
| الأصناف (finance_items) | finance.index / finance | ['view' => 'items'] | finance:finance.warehouse.manage | 'purchasing_stores' | list |
| تعريف المخازن (finance_warehouses) | finance.index / finance | ['view' => 'warehouses'] | finance:finance.warehouse.manage | 'purchasing_stores' | building |
| الجرد (finance_counts) | finance.index / finance | ['view' => 'counts'] | finance:finance.stock.count&#124;finance.stock.count.approve | 'purchasing_stores' | clipboard-list |
| السجل (finance_archive) | finance.index / finance | ['view' => 'archive'] | finance_cycle | 'purchasing_stores' | save |
| صندوق الاعتمادات المالية (finance_approvals) | finance.approvals / finance/approvals | [] | finance_approver | 'finance' | key |
| الصناديق والبنوك (finance_treasuries) | finance.index / finance | ['view' => 'treasuries'] | finance:finance.treasury.manage | 'finance' | coins |
| سندات القبض والصرف (finance_transactions) | finance.index / finance | ['view' => 'transactions'] | finance:finance.transaction.draft | 'finance' | list |
| المستحقات (finance_receivables) | finance.index / finance | ['view' => 'receivables'] | finance:finance.receivable.manage | 'finance' | list |
| قبض الإيجارات وتسوياتها (finance_rentals) | finance.rentals / finance/rentals | [] | finance:finance.rentals.record | 'finance' | coins |
| قبض الكفالات والتبرعات وصرفها (finance_sponsorships) | finance.sponsorships / finance/sponsorships | [] | finance:finance.sponsorships.record | 'finance' | coins |
| التحويلات (finance_transfers) | finance.index / finance | ['view' => 'transfers'] | finance:finance.transfer.execute | 'finance' | list |
| العهد والسلف (finance_advances) | finance.index / finance | ['view' => 'advances'] | finance:finance.advance.manage | 'finance' | list |
| الشيكات (finance_cheques) | finance.index / finance | ['view' => 'cheques'] | finance:finance.cheque.manage | 'finance' | list |
| المطابقة (finance_reconciliations) | finance.index / finance | ['view' => 'reconciliations'] | finance:finance.reconcile | 'finance' | list |
| التقارير والتصدير (finance_reports) | finance.index / finance | ['view' => 'reports'] | finance:finance.report.export | 'finance' | chart |
| الرواتب (finance_payroll) | finance.index / finance | ['view' => 'payroll'] | finance:finance.payroll.manage | 'payroll_group' | wallet |
| بنود الرواتب (finance_payroll_items) | finance.index / finance | ['view' => 'payroll_items'] | finance:finance.payroll.manage | 'payroll_group' | list |
| الموازنات (finance_budgets) | finance.index / finance | ['view' => 'budgets'] | finance:finance.budget.manage | 'budgets_group' | chart |
| استثناءات الموازنة (finance_budget_exceptions) | finance.index / finance | ['view' => 'budget_exceptions'] | finance:finance.budget.transfer | 'budgets_group' | list |
| مناقلات الموازنة (finance_budget_transfers) | finance.index / finance | ['view' => 'budget_transfers'] | finance:finance.budget.transfer | 'budgets_group' | list |
| التمويل المخصص (finance_funds) | finance.index / finance | ['view' => 'funds'] | finance:finance.fund.manage | 'budgets_group' | coins |
| حركات الأموال المقيدة (finance_fund_movements) | finance.index / finance | ['view' => 'fund_movements'] | finance:finance.fund.manage | 'budgets_group' | list |
| ملف الأصول والإيجارات (rentals_file) | admin.rentals.index / admin/rentals | [] | permission:rentals.view | 'rentals' | building |
| إدارة المباني والأعيان (rentals_buildings) | admin.master-data.buildings.index / admin/master-data/buildings | [] | permission:rentals.manage | 'rentals' | settings |
| ملفات الأيتام والأسر (orphans_registry) | orphans.index / orphans | [] | orphans_review | 'orphans_group' | heart |
| طلبات الأوصياء (orphans_guardians) | admin.orphans.guardians.index / admin/orphans/guardians | [] | orphans_review | 'orphans_group' | users |
| تفويضات الأيتام والكفالات (orphans_delegations) | admin.orphans.delegations.index / admin/orphans/delegations | [] | orphans_delegations | 'orphans_group' | key |
| الكفالات والتبرعات والمساعدات (orphans_sponsorships) | orphans.sponsorships.index / orphans/sponsorships | [] | orphans_sponsorships | 'orphans_group' | coins |
| الحملات الإغاثية (relief_campaigns) | relief.campaigns.index / relief/campaigns | [] | relief_campaigns | 'orphans_group' | heart |
| أجهزة التوزيع الميداني (relief_devices) | relief.devices / relief/devices | [] | relief_devices | 'orphans_group' | key |
| إعدادات قسم الكفالات (orphans_settings) | orphans.settings / orphans/settings | [] | orphans_settings | 'orphans_group' | settings |
| ثوابت النظام (master_data) | admin.master-data.index / admin/master-data | [] | system_admin | 'system_settings' | list |
| الفئات والمسميات الوظيفية (job_catalog) | admin.job-catalog.index / admin/job-catalog | [] | system_admin | 'system_settings' | users |
| السلالم والدرجات الوظيفية (career_scales) | admin.career-scales.index / admin/career-scales | [] | system_admin | 'system_settings' | list |
| إعدادات الإجازات (leave_settings) | admin.leaves.settings / admin/leaves/settings | [] | system_admin | 'system_settings' | calendar |
| قواعد استحقاق الإجازات (leave_entitlement_rules) | admin.leave-entitlement-rules.index / admin/leave-entitlement-rules | [] | system_admin | 'system_settings' | calendar |
| تكليفات الحسابات (finance_access) | admin.finance.access / admin/finance/access | [] | system_admin | 'system_settings' | key |
| إعدادات العملات والصلاحيات المالية (financial_settings) | admin.financial-settings.ui / admin/financial-settings-ui | [] | system_admin | 'system_settings' | coins |
| مسارات العمل (workflows_admin) | admin.workflows.index / admin/workflows | [] | system_admin | 'system_settings' | git-branch |
| سياسات المراسلات (correspondence_policies) | correspondence.policies / correspondence/policies | [] | system_admin | 'system_settings' | mail |
| إعدادات الإشعارات (notification_settings) | admin.notification-settings.index / admin/notification-settings | [] | system_admin | 'system_settings' | inbox |
| الحقول الديناميكية (custom_fields_admin) | admin.custom-fields.index / admin/custom-fields | [] | system_admin | 'system_settings' | save |
| القائمة الرئيسية (navigation_admin) | admin.navigation.index / admin/navigation | [] | system_admin | 'system_settings' | list |
| تخصيص اللوحات (dashboard_layouts) | admin.dashboard-layouts.index / admin/dashboard-layouts | [] | system_admin | 'system_settings' | layout |
| إدارة مؤشرات الأداء (kpi_definitions) | admin.kpi.index / admin/kpi | [] | system_admin | 'system_settings' | chart |
| سجل نشاط النظام (audit_logs) | admin.audit-logs.index / admin/audit-logs | [] | system_admin | 'system_settings' | clipboard-list |
