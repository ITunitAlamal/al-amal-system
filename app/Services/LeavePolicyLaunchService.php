<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Launch approved policy drafts without granting or altering employee balances. */
final class LeavePolicyLaunchService
{
    private function validated(object $draft): array
    {
        $tiers = json_decode((string) $draft->tiers, true, 512, JSON_THROW_ON_ERROR);
        $expected = [[12, 36, 15], [36, 180, 21], [180, null, 30]];
        if (! is_array($tiers) || count($tiers) !== 3) {
            throw ValidationException::withMessages(['policy' => 'عدد شرائح السياسة غير صحيح.']);
        }
        foreach ($expected as $i => [$min, $max, $days]) {
            $t = $tiers[$i] ?? [];
            if (($t['min_service_months'] ?? null) !== $min || ! array_key_exists('max_service_months', $t)
                || $t['max_service_months'] !== $max || (float) ($t['annual_entitlement_days'] ?? -1) !== (float) $days) {
                throw ValidationException::withMessages(['policy' => 'الشرائح لا تطابق السياسة المعتمدة.']);
            }
        }

        return $tiers;
    }

    public function preview(int $id): array
    {
        $draft = DB::table('leave_policy_drafts')->where('id', $id)->first();
        if (! $draft) {
            throw ValidationException::withMessages(['policy' => 'السياسة المعتمدة غير موجودة.']);
        }
        $tiers = $this->validated($draft);
        $settings = DB::table('leave_module_settings')->where('id', 1)->first();

        return [
            'correction_id' => (int) $draft->id, 'status' => $draft->status,
            'go_live_date' => $draft->go_live_date, 'tiers' => $tiers,
            'employment_term_value_id' => (int) $draft->employment_term_value_id,
            'leave_type_value_id' => (int) $draft->leave_type_value_id,
            'rules_count' => DB::table('leave_entitlement_rules')->count(),
            'ready_for_activation' => $draft->status === 'approved_pending_launch'
                && $draft->go_live_date === null
                && DB::table('leave_entitlement_rules')->count() === 0
                && $settings?->counting_mode === 'calendar'
                && $settings?->approval_mode === 'department_or_executive'
                && ! $settings?->requests_enabled,
            'would_change_balances' => false,
        ];
    }

    public function activate(int $id, string $date, User $actor, bool $confirmed): array
    {
        if (! $confirmed || ! $actor->is_active || ! $actor->isSystemAdmin()) {
            throw ValidationException::withMessages(['confirmed' => 'يلزم تأكيد مدير النظام.']);
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date || $date !== now()->toDateString()) {
            throw ValidationException::withMessages(['go_live_date' => 'التفعيل مسموح في يوم الإطلاق الرسمي فقط.']);
        }

        return DB::transaction(function () use ($id, $date, $actor) {
            $draft = DB::table('leave_policy_drafts')->where('id', $id)->lockForUpdate()->first();
            if (! $draft || $draft->status !== 'approved_pending_launch' || $draft->go_live_date !== null) {
                throw ValidationException::withMessages(['policy' => 'السياسة مفعلة مسبقًا أو تغيرت.']);
            }
            $tiers = $this->validated($draft);
            $settings = DB::table('leave_module_settings')->where('id', 1)->lockForUpdate()->first();
            if (! $settings || $settings->counting_mode !== 'calendar'
                || $settings->approval_mode !== 'department_or_executive' || $settings->requests_enabled) {
                throw ValidationException::withMessages(['settings' => 'إعدادات الإجازات غير مطابقة أو الطلبات مفتوحة مسبقًا.']);
            }
            if (DB::table('leave_entitlement_rules')->exists()) {
                throw ValidationException::withMessages(['policy' => 'توجد قواعد أخرى؛ أوقف التفعيل لحين مراجعتها.']);
            }
            $ids = [];
            foreach ($tiers as $i => $tier) {
                $ids[] = DB::table('leave_entitlement_rules')->insertGetId([
                    'code' => 'approved_draft_'.$id.'_tier_'.($i + 1),
                    'name_ar' => 'الإجازة السنوية المعتمدة - شريحة '.($i + 1),
                    'employment_term_value_id' => $draft->employment_term_value_id,
                    'leave_type_value_id' => $draft->leave_type_value_id,
                    'min_service_months' => $tier['min_service_months'],
                    'max_service_months' => $tier['max_service_months'],
                    'annual_entitlement_days' => $tier['annual_entitlement_days'],
                    'effective_from' => $date, 'effective_until' => null, 'is_active' => true,
                    'notes' => 'سياسة معتمدة؛ تاريخ اعتمادها الأصلي غير معلوم. يبدأ الحساب عند إطلاق AIMS.',
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('leave_policy_drafts')->where('id', $id)->update([
                'status' => 'launched', 'go_live_date' => $date, 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id, 'action' => 'leave_policy.launched',
                'subject_type' => 'leave_policy_drafts', 'subject_id' => $id,
                'metadata' => json_encode(['rule_ids' => $ids, 'go_live_date' => $date], JSON_THROW_ON_ERROR),
                'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['status' => 'launched', 'policy_id' => $id, 'rule_ids' => $ids];
        });
    }
}
