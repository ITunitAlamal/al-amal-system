<?php

namespace App\Services\Orphans\Import;

use Illuminate\Support\Facades\DB;

/**
 * Policy matrix of the smart import: what each finding does to a row.
 * - info: shown, never holds the row back;
 * - review: the row waits for a person (accept/correct/skip);
 * - block: the row is a conflict until resolved.
 * Configurable levels live in orphan_settings.import_policy (head of department). Safety invariants are fixed here and
 * can never be relaxed from the settings.
 */
final class ImportPolicy
{
    public const LEVELS = ['info' => 'معلومة فقط', 'review' => 'يحتاج مراجعة', 'block' => 'يمنع حتى الحل'];

    /** code (or code.field) => [Arabic label, default level] */
    public const CONFIGURABLE = [
        'sex_unknown' => ['الجنس غير مذكور', 'info'],
        'mother_is_guardian' => ['الأم هي الوصية المدّعاة', 'info'],
        'health_data' => ['يتضمن بيانات صحية', 'info'],
        'invalid_date' => ['تاريخ غير مفهوم (لا يُحفظ)', 'info'],
        'invalid_date.child_dob' => ['تاريخ ميلاد اليتيم غير مفهوم', 'review'],
        'invalid_id' => ['هوية أب/أم/وصي بصيغة غير صحيحة', 'info'],
        'invalid_id.child_id' => ['هوية اليتيم بصيغة غير صحيحة', 'review'],
        'placeholder_id' => ['هوية أب/أم/وصي وهمية', 'info'],
        'placeholder_id.child_id' => ['هوية اليتيم وهمية', 'review'],
        'guardian_unidentified' => ['الوصي دون هوية صحيحة', 'review'],
        'name_collision' => ['اسم مطابق لشخص مسجل دون هوية للمطابقة', 'review'],
        'parent_name_differs' => ['هوية الأب/الأم نفسها بأسماء مختلفة في الملف', 'review'],
        'guardian_inferred' => ['وصي مستنتج من الإخوة (أسرة محددة بهويتي الأب والأم)', 'info'],
        'guardian_inference_proposed' => ['وصي مقترح من الإخوة بدليل أضعف', 'review'],
        'sibling_guardian_ambiguous' => ['للإخوة أكثر من وصي؛ لا استنتاج', 'review'],
        'family_counts_mismatch' => ['الذكور + الإناث لا يساوي عدد الأسرة', 'info'],
        'already_listed' => ['مدرج مسبقًا في الكشف المعتمد', 'info'],
        'unknown_variant' => ['صنف غير معرّف في الحملة', 'info'],
    ];

    /** Fixed invariants: code => level (reject | duplicate | conflict | review). Not editable. */
    public const FIXED = [
        'missing_child_name' => 'reject', 'future_date' => 'reject', 'invalid_quantity' => 'reject', 'not_in_registry' => 'reject',
        'duplicate_in_file' => 'duplicate',
        'child_id_contradiction' => 'conflict', 'identity_other_role' => 'conflict', 'name_differs' => 'conflict', 'guardian_name_differs' => 'conflict',
        'impossible_chronology' => 'conflict', 'source_changed' => 'conflict',
        'missing_guardian' => 'review', 'guardian_conflicts_registry' => 'conflict',
    ];

    public const FIXED_LABELS = [
        'missing_child_name' => 'اسم اليتيم غير موجود', 'future_date' => 'تاريخ ميلاد في المستقبل', 'invalid_quantity' => 'كمية غير صالحة', 'not_in_registry' => 'غير موجود في السجل',
        'duplicate_in_file' => 'تكرار بالبيانات نفسها في الملف', 'child_id_contradiction' => 'هوية اليتيم نفسها ببيانات مختلفة', 'identity_other_role' => 'الهوية لشخص بدور آخر',
        'name_differs' => 'الاسم يختلف عن السجل', 'guardian_name_differs' => 'اسم الوصي يختلف عن السجل', 'impossible_chronology' => 'تواريخ مستحيلة',
        'source_changed' => 'محتوى الصف تغيّر عند إعادة القراءة', 'missing_guardian' => 'لا وصي مرتبط', 'guardian_conflicts_registry' => 'يخالف وصاية معتمدة في السجل',
    ];

    public const FIXED_LEVEL_LABELS = ['reject' => 'مرفوض', 'duplicate' => 'مكرر', 'conflict' => 'تعارض يمنع', 'review' => 'يحتاج مراجعة'];

    /** Arabic label of any finding code. */
    public static function label(string $code): string
    {
        return self::FIXED_LABELS[$code] ?? self::CONFIGURABLE[$code][0] ?? $code;
    }

    private static ?array $levels = null;

    /** Effective level of one configurable finding. */
    public static function level(string $code, ?string $field): string
    {
        self::$levels ??= self::stored();
        foreach ([$code.'.'.$field, $code] as $key) {
            if (isset(self::CONFIGURABLE[$key])) {
                return self::$levels[$key] ?? self::CONFIGURABLE[$key][1];
            }
        }

        return 'review'; // an unknown code is never silently ignored
    }

    /**
     * Row category from its findings.
     *
     * @param  list<array{code: string, severity: string, field: ?string}>  $issues
     */
    public static function category(array $issues): string
    {
        $rank = ['ready' => 0, 'needs_review' => 1, 'conflict' => 2, 'duplicate' => 3, 'rejected' => 4];
        $category = 'ready';
        foreach ($issues as $issue) {
            $level = self::FIXED[$issue['code']] ?? match (self::level($issue['code'], $issue['field'] ?? null)) {
                'info' => null, 'block' => 'conflict', default => 'review',
            };
            $candidate = match ($level) {
                'reject' => 'rejected', 'duplicate' => 'duplicate', 'conflict' => 'conflict', 'review' => 'needs_review', default => 'ready',
            };
            if ($rank[$candidate] > $rank[$category]) {
                $category = $candidate;
            }
        }

        return $category;
    }

    /** The matrix as shown in the settings and on the batch page. */
    public static function matrix(): array
    {
        self::$levels ??= self::stored();

        return [
            'configurable' => collect(self::CONFIGURABLE)->map(fn ($d, $key) => ['key' => $key, 'label' => $d[0], 'default' => $d[1], 'level' => self::$levels[$key] ?? $d[1]])->values()->all(),
            'fixed' => collect(self::FIXED)->map(fn ($level, $code) => ['code' => $code, 'label' => self::label($code), 'level' => self::FIXED_LEVEL_LABELS[$level]])->values()->all(),
            'levels' => self::LEVELS,
        ];
    }

    /** Validated settings value (only configurable keys and known levels are kept). */
    public static function sanitize(array $value): array
    {
        return array_filter(array_intersect_key($value, self::CONFIGURABLE), fn ($level) => isset(self::LEVELS[$level]));
    }

    public static function forget(): void
    {
        self::$levels = null;
    }

    private static function stored(): array
    {
        $value = json_decode((string) DB::table('orphan_settings')->where('key', 'import_policy')->value('value'), true);

        return is_array($value) ? self::sanitize($value) : [];
    }
}
