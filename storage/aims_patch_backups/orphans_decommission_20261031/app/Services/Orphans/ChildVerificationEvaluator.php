<?php

namespace App\Services\Orphans;

use App\Models\GuardianApplication;
use App\Models\OrphanChild;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gives each child in a verified registry snapshot its own result (SRS-002 §5.4–5.9, §8).
 *
 * Results, from most to least severe: ineligible, conflict, pending, incomplete, verified.
 * Only "verified" children are linked on approval; the rest keep their reasons for follow-up,
 * so one child never blocks the family (AT-05).
 */
final class ChildVerificationEvaluator
{
    public const AUTHORITY_KINDS = [
        'legal_guardian' => 'ولاية/وصاية شرعية',
        'custodian' => 'حضانة بقرار',
        'agent' => 'وكالة رسمية',
        'none' => 'لا يوجد سند',
    ];

    public const RESULT_LABELS = [
        'verified' => 'متحقق',
        'pending' => 'بانتظار التحقق',
        'conflict' => 'تعارض',
        'incomplete' => 'غير مكتمل',
        'ineligible' => 'خارج الفئة',
    ];

    private const SEVERITY = ['verified' => 0, 'incomplete' => 1, 'pending' => 2, 'conflict' => 3, 'ineligible' => 4];

    public function __construct(private readonly FamilyRelationshipResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $snapshot  validated registry snapshot
     * @return list<array<string, mixed>>
     */
    public function evaluate(GuardianApplication $application, array $snapshot): array
    {
        $guardian = $application->guardian;
        $today = CarbonImmutable::today(config('orphans.timezone'));
        $applicant = [
            'id' => $guardian->national_id_hash,
            'father' => $this->hash($snapshot['guardian_father_national_id'] ?? null),
            'mother' => $this->hash($snapshot['guardian_mother_national_id'] ?? null),
        ];

        $rows = [];
        foreach ($snapshot['children'] as $child) {
            $result = 'verified';
            $reasons = [];
            $flag = function (string $level, string $reason) use (&$result, &$reasons): void {
                $reasons[] = $reason;
                if (self::SEVERITY[$level] > self::SEVERITY[$result]) {
                    $result = $level;
                }
            };

            $childHash = $this->hash($child['national_id'] ?? null);
            $fatherHash = $this->hash($child['father_national_id'] ?? null);
            $kinship = $this->resolver->resolve($applicant, [
                'id' => $childHash,
                'father' => $fatherHash,
                'mother' => $this->hash($child['mother_national_id'] ?? null),
                'father_father' => $this->hash($child['father_father_national_id'] ?? null),
                'father_mother' => $this->hash($child['father_mother_national_id'] ?? null),
                'mother_father' => $this->hash($child['mother_father_national_id'] ?? null),
                'mother_mother' => $this->hash($child['mother_mother_national_id'] ?? null),
            ]);

            // The age limit is the institution's policy setting (18 until a different limit is approved), not a constant in code.
            $maxAge = (int) (DB::table('orphans_module_settings')->where('id', 1)->value('eligibility_max_age') ?? 18);
            $birth = CarbonImmutable::createFromFormat('!Y-m-d', $child['date_of_birth'], config('orphans.timezone'));
            if ($maxAge > 0 && $birth->addYearsNoOverflow($maxAge)->lessThanOrEqualTo($today)) {
                $flag('ineligible', "بلغ {$maxAge} سنة؛ خارج الفئة العمرية المشمولة.");
            }

            if ($kinship === 'claims_to_be_father') {
                $flag('conflict', 'مقدم الطلب هو الأب المسجل للطفل والمذكور كمتوفى؛ يعلّق للتحقق من احتمال سوء استخدام الهوية.');
            } elseif ($kinship === 'is_the_child') {
                $flag('conflict', 'هوية مقدم الطلب هي هوية الطفل نفسه.');
            }

            if ($fatherHash === null) {
                $flag('pending', 'لم يرد معرف الأب الرسمي من المصدر؛ لا يُستنتج النسب من الأسماء.');
            } elseif (! hash_equals($application->deceased_id_hash, $fatherHash)) {
                $flag('conflict', 'الأب المسجل للطفل يختلف عن المتوفى المذكور في الطلب؛ لا يُنشأ ربط.');
            }

            $deathConfirmed = $child['father_death_confirmed'] ?? null;
            if ($deathConfirmed === false) {
                $flag('conflict', 'المصدر الرسمي لا يثبت وفاة الأب.');
            } elseif ($deathConfirmed === null) {
                $flag('pending', 'لم تُؤكَّد واقعة وفاة الأب من المصدر الرسمي بعد.');
            }

            $existing = $this->existingChild($snapshot['source'], $child, $childHash);
            if ($existing === false) {
                $flag('conflict', 'هوية الطفل ومرجع المصدر يشيران إلى ملفين مختلفين؛ يلزم التدقيق دون دمج آلي.');
                $existing = null;
            } elseif ($existing !== null) {
                if ($existing->date_of_birth->format('Y-m-d') !== $child['date_of_birth'] || $existing->full_name !== $child['full_name']) {
                    $flag('conflict', 'بيانات الطفل تختلف عن ملفه الموجود؛ لا يُكتب فوق الملف الرسمي.');
                }
                if ($this->linkedToAnotherGuardian($existing->id, $guardian->id, $today)) {
                    $flag('conflict', 'الطفل مرتبط بوصي آخر بسند نافذ؛ يلزم طلب نقل تمثيل ومراجعة التعارض.');
                }
            }

            $authority = is_array($child['authority'] ?? null) ? $child['authority'] : [];
            $authorityKind = array_key_exists($authority['kind'] ?? '', self::authorityKinds()) ? $authority['kind'] : 'none';
            $validUntil = ! empty($authority['valid_until']) ? CarbonImmutable::parse($authority['valid_until'], config('orphans.timezone')) : null;
            if ($authorityKind === 'none') {
                $kinshipLabel = FamilyRelationshipResolver::LABELS[$kinship] ?? $kinship;
                $flag('incomplete', "القرابة ({$kinshipLabel}) لا تمنح صفة التمثيل؛ يلزم سند ولاية أو وصاية أو وكالة من مصدر رسمي.");
            } elseif ($validUntil !== null && $validUntil->lessThan($today)) {
                $flag('incomplete', 'سند التمثيل منتهي الصلاحية.');
            }

            $rows[] = [
                'source' => $snapshot['source'],
                'source_child_id' => $child['source_child_id'],
                'full_name' => $child['full_name'],
                'date_of_birth' => $child['date_of_birth'],
                'national_id' => $child['national_id'] ?? null,
                'national_id_hash' => $childHash,
                'kinship' => $kinship,
                'authority_kind' => $authorityKind,
                'authority_reference' => $authority['reference'] ?? null,
                'authority_valid_until' => $validUntil?->toDateString(),
                'result' => $result,
                'reasons' => $reasons,
                'orphan_child_id' => $existing?->id,
            ];
        }

        return $rows;
    }

    /**
     * Legal authority kinds from the constants (admin-managed), with the original codes as fallback labels.
     *
     * @return array<string, string>
     */
    public static function authorityKinds(): array
    {
        $managed = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'guardian_authority_kinds')->where('v.is_active', true)->pluck('v.name_ar', 'v.code')->all();

        return ($managed ?: array_diff_key(self::AUTHORITY_KINDS, ['none' => true])) + ['none' => self::AUTHORITY_KINDS['none']];
    }

    private function hash(?string $identity): ?string
    {
        return $identity === null || trim($identity) === '' ? null : RegistryIdentity::hash($identity);
    }

    /**
     * The child's existing file, null when new, or false when source reference and identity point to two different files.
     *
     * @param  array<string, mixed>  $child
     */
    private function existingChild(string $source, array $child, ?string $childHash): OrphanChild|false|null
    {
        $bySource = OrphanChild::where('source', $source)->where('source_child_id', $child['source_child_id'])->first();
        $byIdentity = $childHash ? OrphanChild::where('national_id_hash', $childHash)->first() : null;

        if ($bySource && $byIdentity && $bySource->id !== $byIdentity->id) {
            return false;
        }

        return $bySource ?? $byIdentity;
    }

    private function linkedToAnotherGuardian(int $childId, int $guardianId, CarbonImmutable $today): bool
    {
        return DB::table('orphan_guardianships')
            ->where('orphan_child_id', $childId)
            ->where('guardian_id', '!=', $guardianId)
            ->where('status', 'approved')->whereNull('ended_reason')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
            ->exists();
    }
}
