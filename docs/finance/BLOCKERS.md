# العوائق المرتبة — 2026-11-02

| # | العائق | الأثر | من يحله |
|---|---|---|---|
| 1 | ثلاث منح بانتظار قرار المدير التنفيذي: قالب رئيس الحسابات ونشر القسائم لأنس، والرواتب لمحمد | كل طلب شراء يتوقف عند «مراجعة رئيس الحسابات». الجرد بلا معتمد. المسير بلا مُدخل ولا ناشر | المدير التنفيذي من «مهامي» ← تفاصيل المنحة ← موافقة |
| 2 | لا حساب لـ«هيثم السك» أمين صندوق المجلس | كل طلب شراء فوق 500 ₪ يبقى «بانتظار أمين الصندوق» | <ol><li>مدير النظام ينشئ الحساب بعد التحقق من هويته.</li><li>يضيفه أمينَ صندوق من «تكليفات الحسابات».</li><li>يُمنح `procurement.board_approve`، وهي حساسة يقررها المدير التنفيذي.</li></ol> |
| 3 | HTTPS غير مفعّل | كلمات المرور والبيانات تمر دون تشفير | مسؤول الشبكة |
| 4 | الحزم الآلية والاختبار في قاعدة معزولة لم تُشغَّل | لا تحقق آلي قبل النشر | قرار المالك |
| 5 | لا تفويض لمراحل اعتماد الحسابات | غياب المعتمد يوقف الطلب. البديل منحة مؤقتة | قرار المالك إن أراد تفويضًا كمسارات «طلباتي» |

## الملفات المعدّلة في هذه الجولة
- **Migration:** `database/migrations/2026_11_30_000015_purchase_request_workflow.php`، RAN في 444ms.
  - مسار PURCHASE.
  - العمودان `rejected_by` و`decision_note`.
  - فهرس فريد للطلب المرتبط.
- **`app/Services/Workflow/AimsRequestEffects.php`** (جديد) و`app/Services/Workflow/WorkflowEngine.php`: مفتاح `effect` للمرحلة.
- **`app/Services/Finance/FinanceGuard.php`:** `EXECUTIVE_PERMISSIONS` و`isExecutiveDirector`.
- **`app/Services/Finance/FinanceOperations.php`:** `requesterOf`، و`decidePurchase`، و`replacePurchaseLines`، واستبعاد الطالب من الاعتماد.
- **`app/Http/Controllers/Finance/FinanceOperationsController.php` و`routes/finance.php`:**
  - `POST /finance/purchases/{id}/items`
  - `POST /finance/purchases/{id}/decision/{reject|return}`
- **`app/Http/Controllers/Finance/FinanceProcurementController.php`:** الطالب بدل المنشئ في اختيار المورد وإصدار الأمر.
- **`app/Services/Progress/PurchaseRequestProgress.php`:** حالة الرفض، والسند الخارجي.
- **`app/Http/Controllers/WorkflowTaskController.php` و`resources/js/Pages/Workflows/Show.vue`:** مسار الشراء في صفحة الطلب.
- **`resources/js/Pages/Finance/Index.vue`:** تعديل بنود المسودة، والرفض/الإعادة.
- **`resources/js/Pages/Finance/Approvals.vue`:** الرفض/الإعادة في صندوق الموافقات.

**إضافة 2026-11-02 (اعتماد المدير التنفيذي للمسير):**
- **Migration:** `database/migrations/2026_11_30_000017_payroll_executive_approval.php`، RAN.
  - حالة `pending_executive`.
  - الأعمدة `reviewed_by` و`reviewed_at` و`approved_at`.
  - صلاحية `finance.payroll.authorize`.
- **الخدمة:** `app/Services/Finance/FinancePayrollRuns.php`: `review` و`authorize` و`publish` (بعد الاعتماد فقط) و`return` (من المراجع أو المدير التنفيذي).
- **الحماية:** في `FinanceGuard` و`FinancePermissionCatalog` الصلاحية حساسة، وممنوعة على رئيس القسم ومدير النظام، ولها زوجا تعارض.
- **صندوق الموافقات:** `FinanceApprovalQueue` يضم المسيرات.
- **الواجهة والمسارات:** `FinancePayrollController`، و`routes/finance.php`، و`Finance/Payroll.vue`، و`Finance/Approvals.vue`.
- **لم يُفعَّل:** `finance.payroll.approve` القديم، التابع لنظام الرواتب الداخلي، يبقى مرفوضًا في الوضع المستندي.

**الأوامر:**
- `php artisan migrate --path=database/migrations/2026_11_30_000015_purchase_request_workflow.php --force`
- `vendor/bin/pint <المسارات>`، و`npm run build`.

**التراجع:** `migrate:rollback --path=…000015…`. يرفض التراجع إذا وُجد طلب شراء أو قرار رفض/إعادة.
