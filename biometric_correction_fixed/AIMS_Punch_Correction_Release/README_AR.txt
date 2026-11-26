AIMS — نظام طلب واعتماد تصحيح اتجاه البصمة

مهم: السكربت يعمل محليًا على جهاز Windows الخاص بالمشروع، ولا يعدل قاعدة البيانات أثناء تركيب الكود.

1) خذ نسخة احتياطية PostgreSQL بصيغة Custom وتحقق من نجاحها قبل تشغيل Migration.
2) فك الضغط إلى مجلد مستقل، مثلاً C:\Projects\al-amal-system\AIMS_Punch_Correction_Release
3) شغّل من PowerShell:
   powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Projects\al-amal-system\AIMS_Punch_Correction_Release\Install_AIMS_Punch_Corrections.ps1"
4) بعد ظهور SOURCE_INSTALLED=YES ونجاح النسخة الاحتياطية:
   cd C:\Projects\al-amal-system
   php artisan migrate --path=database/migrations/2026_10_28_140000_create_attendance_punch_corrections.php
   php artisan optimize:clear
5) افتح /admin/attendance/operations وتحقق من عرض الحركات وطلبات التصحيح.
6) من حساب رئيس الموارد البشرية: ارفع طلب تصحيح بحركة فعلية وسبب واضح، مثل اختيار خروج بدل دخول بالخطأ.
7) من حساب مدير نظام مختلف: اعتمد أو ارفض مع ملاحظة قرار إدارية. لا يمكن للطالب اعتماد طلبه.
8) تحقق من تحديث attendance_daily_results وعدم تغيير attendance_punches.raw_payload أو direction.
9) يمكن لمدير النظام إلغاء التصحيح مع سبب، ويعاد احتساب اليوم مجددًا.

الأدوار الحالية: رئيس الموارد البشرية ومدير النظام يستطيعان تقديم الطلب، ومدير النظام فقط يقرر. مطلوب حسابان مختلفان للمراجعة المستقلة.
لم تُختبر هذه الحزمة على جهازك أو قاعدة al_amal_db مباشرة. لا تنفذ ترحيلًا عامًا php artisan migrate دون مراجعة الترحيلات الأخرى المعلقة.
ملاحظة: لا تغير الحزمة الساعات الأصلية ولا الأرقام الوظيفية، ولا تنشئ خصومات مالية.
