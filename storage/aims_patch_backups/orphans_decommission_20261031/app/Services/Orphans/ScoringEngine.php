<?php

namespace App\Services\Orphans;

use App\Models\OrphanChild;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deterministic, explainable scoring used by both systems: the general social score of a child's file and a
 * campaign's priority score. No factor, band or weight is built in: they come from a ruleset the executive director
 * approved. A score ranks for review; it never approves or rejects anyone by itself.
 */
final class ScoringEngine
{
    /** factor code => [Arabic label, type, used by (social|campaign|both)] */
    public const FACTORS = [
        'orphan_class' => ['تصنيف اليتم', 'category', 'both'],
        'sole_survivor' => ['الناجي الوحيد (موثق)', 'boolean', 'both'],
        'age_years' => ['العمر بالسنوات', 'range', 'both'],
        'family_size' => ['عدد أفراد الأسرة', 'range', 'both'],
        'dependents' => ['عدد المعالين', 'range', 'both'],
        'working_members' => ['عدد العاملين في الأسرة', 'range', 'both'],
        'income_per_member' => ['الدخل الشهري للفرد', 'range', 'both'],
        'displaced' => ['الأسرة نازحة', 'boolean', 'both'],
        'shelter_type' => ['نوع المأوى الحالي', 'category', 'both'],
        'current_tenure' => ['ملكية السكن الحالي', 'category', 'both'],
        'needs_level' => ['مستوى الاحتياج', 'category', 'both'],
        'economic_status' => ['الوضع الاقتصادي', 'category', 'both'],
        'health_need' => ['احتياج صحي (مرض مزمن أو إعاقة، دون تشخيص)', 'boolean', 'both'],
        'education_enrollment' => ['الحالة التعليمية الحالية', 'category', 'both'],
        'governorate' => ['المحافظة الحالية', 'category', 'campaign'],
        'social_score' => ['التقييم الاجتماعي العام المعتمد', 'range', 'campaign'],
        'prior_aid_count' => ['عدد المساعدات المسلّمة خلال نافذة الحملة', 'range', 'campaign'],
    ];

    public const MISSING = ['zero' => 'صفر نقاط مع تنبيه', 'exclude' => 'يُستبعد العامل من الحساب', 'hold' => 'يُعلّق التقييم حتى استكمال البيانات'];

    /**
     * Validates a ruleset's factors (structure only; the values are an institutional decision).
     *
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $factors, string $kind): array
    {
        if (! is_array($factors) || $factors === [] || count($factors) > 30) {
            throw ValidationException::withMessages(['factors' => 'حدد بين عامل واحد و30 عاملًا.']);
        }
        $seen = [];
        $clean = [];
        foreach (array_values($factors) as $index => $factor) {
            $code = (string) ($factor['code'] ?? '');
            $definition = self::FACTORS[$code] ?? null;
            if (! $definition || ($definition[2] === 'campaign' && $kind !== 'campaign') || isset($seen[$code])) {
                throw ValidationException::withMessages(["factors.{$index}.code" => 'عامل غير معروف أو مكرر أو لا يخص هذا النوع من التقييم.']);
            }
            $seen[$code] = true;
            $weight = $factor['weight'] ?? null;
            if (! is_numeric($weight) || $weight < 0 || $weight > 100) {
                throw ValidationException::withMessages(["factors.{$index}.weight" => 'الوزن رقم بين 0 و100.']);
            }
            $cap = $factor['cap'] ?? null;
            if ($cap !== null && $cap !== '' && (! is_numeric($cap) || $cap < 0)) {
                throw ValidationException::withMessages(["factors.{$index}.cap" => 'الحد الأعلى رقم موجب أو فارغ.']);
            }
            $missing = (string) ($factor['missing'] ?? 'zero');
            if (! isset(self::MISSING[$missing])) {
                throw ValidationException::withMessages(["factors.{$index}.missing" => 'معالجة البيانات الناقصة غير معروفة.']);
            }
            $bands = [];
            foreach (array_values((array) ($factor['bands'] ?? [])) as $b => $band) {
                if (! is_numeric($band['points'] ?? null)) {
                    throw ValidationException::withMessages(["factors.{$index}.bands.{$b}" => 'كل تدرج يحتاج عدد نقاط.']);
                }
                $bands[] = match ($definition[1]) {
                    'range' => ['min' => $this->number($band['min'] ?? null), 'max' => $this->number($band['max'] ?? null), 'points' => (float) $band['points']],
                    'boolean' => ['value' => filter_var($band['value'] ?? null, FILTER_VALIDATE_BOOLEAN), 'points' => (float) $band['points']],
                    default => ['value' => (string) ($band['value'] ?? ''), 'points' => (float) $band['points']],
                };
            }
            if ($bands === []) {
                throw ValidationException::withMessages(["factors.{$index}.bands" => 'حدد تدرجات النقاط لهذا العامل.']);
            }
            $clean[] = ['code' => $code, 'weight' => (float) $weight, 'cap' => ($cap === null || $cap === '') ? null : (float) $cap, 'missing' => $missing, 'bands' => $bands];
        }

        return $clean;
    }

    /**
     * @param  list<array<string, mixed>>  $factors  normalized factors of an approved ruleset
     * @param  array<string, mixed>  $inputs  factor code => input value (null = missing)
     * @return array{total: float, complete: bool, components: array<string, array<string, mixed>>}
     */
    public function evaluate(array $factors, array $inputs): array
    {
        $total = 0.0;
        $complete = true;
        $components = [];
        foreach ($factors as $factor) {
            $input = $inputs[$factor['code']] ?? null;
            if ($input === null) {
                $components[$factor['code']] = ['input' => null, 'points' => 0.0, 'missing' => $factor['missing']];
                if ($factor['missing'] === 'hold') {
                    $complete = false;
                }

                continue;
            }
            $band = $this->band($factor, $input);
            $points = $band === null ? 0.0 : round($band['points'] * $factor['weight'], 2);
            if ($factor['cap'] !== null) {
                $points = min($points, $factor['cap']);
            }
            $total += $points;
            $components[$factor['code']] = ['input' => $input, 'band' => $band, 'weight' => $factor['weight'], 'points' => $points];
        }

        return ['total' => round($total, 2), 'complete' => $complete, 'components' => $components];
    }

    /** @param array<string, mixed> $factor */
    private function band(array $factor, mixed $input): ?array
    {
        foreach ($factor['bands'] as $band) {
            if (array_key_exists('min', $band)) {
                if (is_numeric($input) && ($band['min'] === null || $input >= $band['min']) && ($band['max'] === null || $input < $band['max'])) {
                    return $band;
                }
            } elseif ($band['value'] === $input || (is_bool($band['value']) && $band['value'] === (bool) $input)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Inputs of a child for the given factors, read from the file at this moment (the caller stores them with the score).
     *
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    public function inputs(OrphanChild $child, array $codes, ?int $campaignId = null, ?int $aidWindowDays = null): array
    {
        $household = DB::table('orphan_households')->where('id', $child->household_id)->first();
        $code = fn (?int $id): ?string => $id ? DB::table('master_data_values')->where('id', $id)->value('code') : null;
        $health = DB::table('orphan_health_records')->where('orphan_child_id', $child->id)->where('is_current', true)->first();
        $education = DB::table('orphan_education_records')->where('orphan_child_id', $child->id)->orderByDesc('school_year')->first();
        $address = DB::table('orphan_addresses')->where('household_id', $child->household_id)->where('kind', 'current')->whereNull('valid_to')->first();
        $values = [];
        foreach ($codes as $factor) {
            $values[$factor] = match ($factor) {
                'orphan_class' => $code($child->orphan_class_value_id),
                'sole_survivor' => (bool) $child->sole_survivor,
                'age_years' => $child->ageOn(),
                'family_size' => $household?->family_size,
                'dependents' => $household?->dependents_count,
                'working_members' => $household?->working_members_count,
                'income_per_member' => $household && $household->monthly_income !== null && $household->family_size ? round((float) $household->monthly_income / $household->family_size, 2) : null,
                'displaced' => $household ? (bool) $household->displaced : null,
                'shelter_type' => $code($household?->shelter_type_value_id),
                'current_tenure' => $code($household?->current_tenure_value_id),
                'needs_level' => $code($household?->needs_level_value_id),
                'economic_status' => $code($household?->economic_status_value_id),
                'health_need' => $health ? (bool) ($health->has_chronic || $health->has_disability) : null,
                'education_enrollment' => $code($education?->enrollment_value_id),
                'governorate' => $code($address?->governorate_value_id),
                'social_score' => ($score = DB::table('orphan_social_scores')->where('orphan_child_id', $child->id)->where('is_current', true)->first())
                    ? (float) ($score->override_total ?? $score->total) : null,
                'prior_aid_count' => $this->priorAid($child, $campaignId, $aidWindowDays),
                default => null,
            };
        }

        return $values;
    }

    private function priorAid(OrphanChild $child, ?int $campaignId, ?int $windowDays): ?int
    {
        if (! DB::getSchemaBuilder()->hasTable('relief_allocations')) {
            return 0;
        }
        $since = now()->subDays($windowDays ?: 365);

        return (int) DB::table('relief_allocations')
            ->where(fn ($q) => $q->where('orphan_child_id', $child->id)->orWhere('household_id', $child->household_id))
            ->when($campaignId, fn ($q) => $q->where('campaign_id', '<>', $campaignId))
            ->whereIn('status', ['delivered', 'partially_delivered'])->where('updated_at', '>=', $since)->count();
    }

    public static function hash(array $factors): string
    {
        return hash('sha256', json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
