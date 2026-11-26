<?php

namespace App\Services\Orphans\Import;

/**
 * Deterministic review categories of the registry import. Severity orders the review inbox: identity, guardian and
 * date conflicts first; missing sex and family-count differences last (they may reflect the source's own meaning).
 * Details carry field codes and reasons only — never the values themselves.
 */
final class RegistryIssueTypes
{
    public const SEVERITIES = ['high' => 'عالية', 'medium' => 'متوسطة', 'low' => 'منخفضة', 'info' => 'معلومة'];

    /** code => [Arabic label, severity, recommendation] */
    public const TYPES = [
        'existing_record' => ['مطابقة لملف معتمد قائم', 'high', 'قارن مع الملف المعتمد؛ لا يُعدَّل الملف المعتمد من الكشف.'],
        'child_id_missing' => ['هوية الطفل غير مكتملة', 'high', 'استكمل رقم الهوية من وثيقة رسمية قبل أي ربط.'],
        'child_id_format' => ['هوية الطفل غير مطابقة للصيغة', 'high', 'راجع الرقم من الوثيقة؛ قد تكون الخلية الرقمية أسقطت صفرًا في البداية. لا يُعدَّل الرقم تلقائيًا.'],
        'child_conflict' => ['تعارض بيانات الطفل', 'high', 'الهوية نفسها وردت ببيانات مختلفة (الميلاد أو الاسم أو الوالدين). قارن الصفوف وحدد الصحيح بدليل.'],
        'guardian_mother_mismatch' => ['تعارض هوية الوصي والأم', 'high', 'الصلة المذكورة «الأم» لكن هوية الوصي تختلف عن هوية الأم.'],
        'guardian_is_father' => ['الوصي مطابق لهوية الأب', 'high', 'هوية الوصي هي هوية الأب المسجل متوفى. تحقق من الوصي الفعلي.'],
        'date_review' => ['تاريخ يحتاج مراجعة', 'medium', 'تحقق من التاريخ من وثيقة رسمية.'],
        'duplicate' => ['تكرار محتمل', 'medium', 'الهوية نفسها في أكثر من صف بالبيانات نفسها؛ تُراجع ولا تُدمج تلقائيًا.'],
        'household_link' => ['تعارض ربط الأسرة', 'medium', 'تعذر تكوين أسرة مرشحة موثوقة (هوية أب أو أم ناقصة) أو ظهر الطفل في أكثر من أسرة.'],
        'multiple_guardians' => ['تعدد أوصياء محتمل', 'medium', 'الأسرة المرشحة نفسها وردت بأكثر من وصي مدّعى.'],
        'guardian_name_variant' => ['اختلاف اسم الوصي', 'medium', 'هوية الوصي نفسها وردت بأسماء مختلفة.'],
        'guardianship_unconfirmed' => ['الوصاية غير مؤكدة', 'medium', 'هوية الوصي المدّعى ناقصة أو غير مطابقة للصيغة.'],
        'family_counts' => ['بيانات الأسرة تحتاج تفسيرًا', 'low', 'العدد الكلي يختلف عن مجموع الذكور والإناث؛ قد يختلف معنى العدد في المصدر.'],
        'sex_missing' => ['الجنس غير محدد', 'info', 'يُستكمل من وثيقة؛ لا يُستنتج من الاسم.'],
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type][0] ?? $type;
    }

    public static function severity(string $type): string
    {
        return self::TYPES[$type][1] ?? 'medium';
    }
}
