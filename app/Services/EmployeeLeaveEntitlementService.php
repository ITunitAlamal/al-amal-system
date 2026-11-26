<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Services\Configuration\MasterDataLookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read only. Never treat an unapplied historical mistake as entitlement.
 *
 * The annual entitlement comes from the active rule that matches the employee's employment term,
 * contract type and seniority (months since the actual work start date). A rule for the exact
 * contract type wins over a rule left open to all contract types. Nothing here credits a balance.
 */
final class EmployeeLeaveEntitlementService
{
    public const ROUNDING = [
        'none' => 'بدون تقريب (حتى منزلتين عشريتين)',
        'half_day' => 'لأقرب نصف يوم',
        'nearest_day' => 'لأقرب يوم',
        'floor_day' => 'لأسفل إلى يوم كامل',
        'ceil_day' => 'لأعلى إلى يوم كامل',
    ];

    public function __construct(private readonly MasterDataLookup $masterData) {}

    public function isCompensatoryType(int $leaveTypeId): bool
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('v.id', $leaveTypeId)->where('v.code', 'compensatory_leave')
            ->where('t.code', 'leave_types')->where('v.is_active', true)->where('t.is_active', true)->exists();
    }

    /**
     * @return array<string, mixed> status is one of: defined, policy_undefined, below_first_tier,
     *                              missing_employment_data, not_started, not_launched, invalid_date
     */
    public function calculate(Employee $employee, int $leaveTypeId, string $asOf): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $asOf);
        if (! $date || $date->format('Y-m-d') !== $asOf) {
            return ['status' => 'invalid_date', 'annual_days' => null];
        }
        $cutover = DB::table('leave_policy_drafts')
            ->where('status', 'launched')->whereNotNull('go_live_date')->orderBy('go_live_date')->value('go_live_date');
        if (! $cutover || $asOf < $cutover) {
            return ['status' => 'not_launched', 'annual_days' => null];
        }
        if (! $employee->employment_term_value_id || ! $employee->work_start_date) {
            return ['status' => 'missing_employment_data', 'annual_days' => null];
        }
        $start = CarbonImmutable::parse($employee->work_start_date)->startOfDay();
        if ($start->greaterThan($date)) {
            return ['status' => 'not_started', 'annual_days' => null];
        }
        $months = (int) floor($start->diffInMonths($date));

        $base = DB::table('leave_entitlement_rules')
            ->where('employment_term_value_id', $employee->employment_term_value_id)
            ->where('leave_type_value_id', $leaveTypeId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $asOf))
            ->where(fn ($q) => $q->whereNull('contract_type_value_id')
                ->when($employee->contract_type_value_id, fn ($inner) => $inner->orWhere('contract_type_value_id', $employee->contract_type_value_id)));

        $matching = (clone $base)
            ->where('min_service_months', '<=', $months)
            ->where(fn ($q) => $q->whereNull('max_service_months')->orWhere('max_service_months', '>', $months))
            ->limit(5)->get(['id', 'name_ar', 'annual_entitlement_days', 'contract_type_value_id']);

        $exact = $matching->whereNotNull('contract_type_value_id');
        $chosen = match (true) {
            $exact->count() === 1 => $exact->first(),
            $exact->count() === 0 && $matching->count() === 1 => $matching->first(),
            default => null,
        };

        if ($chosen === null) {
            $firstTier = (clone $base)->min('min_service_months');
            if ($matching->isEmpty() && $firstTier !== null && $months < (int) $firstTier) {
                return ['status' => 'below_first_tier', 'annual_days' => null, 'service_months' => $months, 'first_tier_months' => (int) $firstTier];
            }

            return ['status' => 'policy_undefined', 'annual_days' => null, 'service_months' => $months];
        }

        return ['status' => 'defined', 'annual_days' => (float) $chosen->annual_entitlement_days,
            'rule_id' => $chosen->id, 'rule_name' => $chosen->name_ar, 'contract_specific' => $chosen->contract_type_value_id !== null,
            'service_months' => $months, 'as_of' => $asOf];
    }

    /**
     * The employment facts behind the entitlement, for display.
     *
     * @return array<string, mixed>
     */
    public function basis(Employee $employee, ?string $asOf = null): array
    {
        $date = CarbonImmutable::parse($asOf ?? now()->toDateString())->startOfDay();
        $start = $employee->work_start_date ? CarbonImmutable::parse($employee->work_start_date)->startOfDay() : null;
        $months = $start && $start->lessThanOrEqualTo($date) ? (int) floor($start->diffInMonths($date)) : null;

        return [
            'employmentTerm' => $this->masterData->label($employee->employment_term_value_id),
            'contractType' => $this->masterData->label($employee->contract_type_value_id),
            'workSchedule' => $this->masterData->label($employee->work_schedule_value_id),
            'hireDate' => $employee->hire_date?->format('Y-m-d'),
            'workStartDate' => $start?->format('Y-m-d'),
            'serviceMonths' => $months,
            'serviceYears' => $months === null ? null : intdiv($months, 12),
            'serviceRemainderMonths' => $months === null ? null : $months % 12,
        ];
    }

    /**
     * Entitlement with its explanation and, for service under the first tier, the pro-rata estimate.
     * Display only: the estimate appears only after the executive director approves the pro-rata policy,
     * and it is never written to the balance.
     *
     * @return array<string, mixed>
     */
    public function explain(Employee $employee, int $leaveTypeId, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $result = $this->calculate($employee, $leaveTypeId, $asOf);
        $result['prorata'] = null;

        if ($result['status'] === 'below_first_tier') {
            $result['prorata'] = $this->prorata((int) $result['service_months']);
        }

        return $result;
    }

    /**
     * @return array{status: string, days: ?float, rate: ?float, min_months: ?int, rounding: ?string}
     */
    public function prorata(int $serviceMonths): array
    {
        $settings = DB::table('leave_module_settings')->where('id', 1)->first();
        $policy = [
            'status' => 'awaiting_policy', 'days' => null,
            'rate' => $settings?->prorata_monthly_rate_days !== null ? (float) $settings->prorata_monthly_rate_days : null,
            'min_months' => $settings?->prorata_min_service_months !== null ? (int) $settings->prorata_min_service_months : null,
            'rounding' => $settings?->prorata_rounding,
        ];

        if (! $settings || $settings->prorata_status !== 'approved' || $policy['rate'] === null || $policy['rounding'] === null) {
            return $policy;
        }
        if ($policy['min_months'] !== null && $serviceMonths < $policy['min_months']) {
            return ['status' => 'not_eligible_yet'] + $policy;
        }

        return ['status' => 'estimated', 'days' => $this->round($serviceMonths * $policy['rate'], $policy['rounding'])] + $policy;
    }

    private function round(float $days, string $rounding): float
    {
        return match ($rounding) {
            'half_day' => round($days * 2) / 2,
            'nearest_day' => round($days),
            'floor_day' => floor($days),
            'ceil_day' => ceil($days),
            default => round($days, 2),
        };
    }
}
