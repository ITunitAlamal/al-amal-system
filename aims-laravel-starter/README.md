# AIMS — Al-Amal Institute Management System

نواة المرحلة الأولى لنظام جمعية معهد الأمل للأيتام، وفق SRS v3.0 الصادر 12 أكتوبر 2026.

> **حالة الإصدار:** مصدر أولي (starter overlay)، وليس تطبيق Laravel مكتملًا أو نظامًا جاهزًا للإنتاج. أنشئ تطبيق Laravel 13 جديدًا ثم انسخ الملفات كما في التعليمات.

## المتطلبات

- PHP وComposer وLaravel 13، مع امتدادات PHP المطلوبة.
- PostgreSQL أو SQLite للتجربة المحلية.
- لا تعتمد الحسابات التجريبية أو البيانات الحساسة قبل ضبط سياسة الأمان.

## البدء (PowerShell على Windows)

```powershell
composer create-project laravel/laravel aims "^13.0"
cd aims
# انسخ جميع الملفات والمجلدات من هذه الحزمة إلى مجلد aims مع الموافقة على استبدال الملفات المطابقة.
Copy-Item .env.example .env
php artisan key:generate
# يمكن استخدام SQLite محليًا: عدّل DB_CONNECTION=sqlite في .env وأنشئ database/database.sqlite
php artisan migrate
php artisan db:seed --class=AimsCoreSeeder
php artisan serve
```

يمكن فتح http://127.0.0.1:8000/login، لكن يجب إنشاء مستخدم وتعيين دور له أولاً. لا توجد بيانات دخول ثابتة ضمن هذه الحزمة.

## إنشاء مدير نظام محلي أول مرة

`php artisan tinker` ثم:

```php
$user = \App\Models\User::create(['name'=>'Local Admin','email'=>'admin@example.test','password'=>\Illuminate\Support\Facades\Hash::make('CHANGE_TO_A_LONG_RANDOM_PASSWORD'),'is_active'=>true]);
\App\Models\DepartmentUserRole::create(['user_id'=>$user->id,'role_id'=>\App\Models\Role::where('code','system_admin')->firstOrFail()->id,'department_id'=>null]);
```

استبدل كلمة المرور قبل التنفيذ ولا تستخدم بيانات مثال في بيئة حقيقية. رئيس القسم وموظف القسم يحصلان على دور مُسند إلى department_id المحدد. لا تضع department_id=null إلا لدور له تفويض صريح على مستوى المؤسسة.

## ما تم بناؤه

- مخطط بيانات للأقسام والأدوار والصلاحيات والإسناد بنطاق القسم.
- جدول سجل تدقيق للأحداث الحساسة، مع تسجيل محاولات الدخول الناجحة والخروج. **تسجيل جميع تعديلات البيانات يتطلب استكمال Events/Observers**.
- نموذج User مع فحص صلاحيات يعتمد الدور ونطاق القسم ويمنع التفويض التلقائي لمدير النظام.
- تهيئة أولية لأدوار: موظف قسم، رئيس قسم، مدير تنفيذي، مدير نظام.
- نموذج دخول وخروج آمن مع Session regeneration وCSRF وthrottle، ولوحة تحكم عربية أولية.

## ما لم يُنفذ بعد

- شاشات CRUD للمستخدمين والموظفين والأقسام وإدارة الصلاحيات، workflows والإصدارات، تقارير فعلية.
- وظائف الكفالات والأيتام والموارد البشرية والصيانة والأصول والحسابات.
- حماية صفوف السجلات باستعمال Policies وقيود SQL في كل نقطة وصول؛ يجب تطبيقها عند إنشاء هذه الوحدات.
- اختبارات تكاملية وقبول، قيود فصل المهام، نسخ احتياطي، وإعداد النشر الإنتاجي.

## قواعد SRS

- المدير التنفيذي لا يتدخل في بيانات أصحاب الاختصاص؛ الصلاحيات تتبع التفويض.
- مدير النظام لا يحصل على موافقات أو صرف أو وصول لملفات حساسة تلقائيًا.
- كل تغيّر تشغيلي حساس يجب أن يمتلك مراجعة وسجلًا مستقلًا.
- وحدة المدرسة ليست ضمن نطاق المشروع، والإيواء التفصيلي مؤجل.
