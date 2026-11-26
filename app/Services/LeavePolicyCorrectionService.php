<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrative cutover of a previously recorded leave-policy correction.
 * This service never recalculates employee balances or changes master data.
 * Merely installing this class has no database side effects.
 */
final class LeavePolicyCorrectionService
{
    public function preview(int $correctionId): array
    {
        $correction = DB::table('leave_policy_corrections')->where('id', $correctionId)->first();
        if (! $correction) {
            throw ValidationException::withMessages(['correction' => 'طلب التصحيح غير موجود.']);
        }
        $original = DB::table('leave_entitlement_rules')->where('id', $correction->original_rule_id)->first();
        if (! $original) {
            throw ValidationException::withMessages(['correction' => 'القاعدة الأصلية غير موجودة.']);
        }
        $payload = $this->validatedPayload($correction, $original);

        return [
            'correction_id' => (int) $correction->id,
            'status' => $correction->status,
            'go_live_date' => $correction->go_live_date,
            'original_rule_id' => (int) $original->id,
            'original_annual_days' => (string) $original->annual_entitlement_days,
            'employment_term_value_id' => (int) $original->employment_term_value_id,
            'leave_type_value_id' => (int) $original->leave_type_value_id,
            'tiers' => $payload['tiers'],
            'would_change_balances' => false,
            'ready_for_activation' => $correction->status === 'pending'
                && $correction->go_live_date === null,
        ];
    }

    /**
     * Only call after explicit administrator confirmation ON official launch day.
     * All writes, including audit trail, succeed or roll back as one unit.
     */
    public function apply(int $correctionId, string $goLiveDate, User $actor, bool $confirmed = false): array
    {
        if (! $confirmed || ! $actor->is_active || ! $actor->isSystemAdmin()) {
            throw ValidationException::withMessages(['confirmation' => 'يلزم تأكيد صريح من مدير النظام.']);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $goLiveDate);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['go_live_date' => 'صيغة التاريخ غير صحيحة.']);
        }
        if (! $date || $date->format('Y-m-d') !== $goLiveDate) {
            throw ValidationException::withMessages(['go_live_date' => 'صيغة التاريخ غير صحيحة.']);
        }
        if ($goLiveDate !== now()->toDateString()) {
            throw ValidationException::withMessages([
                'go_live_date' => 'يُطبّق التصحيح في يوم الإطلاق الرسمي فقط، ولا يُفعّل مسبقًا أو بأثر رجعي.',
            ]);
        }

        return DB::transaction(function () use ($correctionId, $goLiveDate, $actor): array {
            $correction = DB::table('leave_policy_corrections')
                ->where('id', $correctionId)->lockForUpdate()->first();
            if (! $correction || $correction->status !== 'pending' || $correction->go_live_date !== null) {
                throw ValidationException::withMessages(['correction' => 'التصحيح غير موجود أو سبق استخدامه.']);
            }
            $original = DB::table('leave_entitlement_rules')
                ->where('id', $correction->original_rule_id)->lockForUpdate()->first();
            if (! $original || ! $original->is_active || $original->effective_until !== null) {
                throw ValidationException::withMessages(['correction' => 'حالة السياسة الأصلية تغيّرت؛ راجعها قبل التفعيل.']);
            }
            if ((string) $original->effective_from >= $goLiveDate) {
                throw ValidationException::withMessages(['go_live_date' => 'يجب أن يلي تاريخ الإطلاق بداية السياسة الأصلية.']);
            }
            $payload = $this->validatedPayload($correction, $original);
            if ((float) $original->annual_entitlement_days !== 10.0
                || (int) $original->min_service_months !== 12
                || $original->max_service_months !== null) {
                throw ValidationException::withMessages(['correction' => 'السياسة القديمة تغيّرت عن قيمها التي راجعناها.']);
            }

            // Retain the old row and its original effective_from; close its interval.
            DB::table('leave_entitlement_rules')->where('id', $original->id)->update([
                'effective_until' => $goLiveDate,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);

            $createdIds = [];
            foreach ($payload['tiers'] as $index => $tier) {
                $createdIds[] = DB::table('leave_entitlement_rules')->insertGetId([
                    'code' => 'correction_'.$correctionId.'_tier_'.($index + 1),
                    'name_ar' => 'تصحيح استحقاق الإجازة السنوية - شريحة '.($index + 1),
                    'employment_term_value_id' => $original->employment_term_value_id,
                    'leave_type_value_id' => $original->leave_type_value_id,
                    'min_service_months' => $tier['min_service_months'],
                    'max_service_months' => $tier['max_service_months'],
                    'annual_entitlement_days' => $tier['annual_entitlement_days'],
                    'effective_from' => $goLiveDate,
                    'effective_until' => null,
                    'is_active' => true,
                    'notes' => 'تصحيح سياسة قديمة معتمدة، تاريخ اعتمادها الأصلي غير معروف. مرجع التصحيح: '.$correctionId,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('leave_policy_corrections')->where('id', $correctionId)->update([
                'status' => 'applied',
                'go_live_date' => $goLiveDate,
                'applied_at' => now(),
                'applied_by' => $actor->id,
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'action' => 'leave_policy_correction.applied',
                'subject_type' => 'leave_policy_corrections',
                'subject_id' => $correctionId,
                'metadata' => json_encode([
                    'original_rule_id' => $original->id,
                    'new_rule_ids' => $createdIds,
                    'go_live_date' => $goLiveDate,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'applied', 'correction_id' => $correctionId, 'new_rule_ids' => $createdIds];
        });
    }

    private function validatedPayload(object $correction, object $original): array
    {
        $data = json_decode((string) $correction->replacement_rules, true);
        if (! is_array($data) || (int) ($data['employment_term_value_id'] ?? 0) !== (int) $original->employment_term_value_id
            || (int) ($data['leave_type_value_id'] ?? 0) !== (int) $original->leave_type_value_id) {
            throw ValidationException::withMessages(['correction' => 'بيانات التصحيح لا تطابق السياسة الأصلية.']);
        }
        $expected = [
            [12, 36, 15],
            [36, 180, 21],
            [180, null, 30],
        ];
        $tiers = $data['tiers'] ?? null;
        if (! is_array($tiers) || count($tiers) !== 3) {
            throw ValidationException::withMessages(['correction' => 'عدد الشرائح غير صحيح.']);
        }
        foreach ($expected as $i => [$min, $max, $days]) {
            $row = $tiers[$i] ?? [];
            if ((int) ($row['min_service_months'] ?? -1) !== $min
                || ($row['max_service_months'] ?? null) !== $max
                || (float) ($row['annual_entitlement_days'] ?? -1) !== (float) $days) {
                throw ValidationException::withMessages(['correction' => 'شرائح التصحيح مختلفة عن السياسة المعتمدة.']);
            }
        }

        return ['tiers' => $tiers];
    }
}
