<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every deviation from a campaign's approved rules (not eligible, outside the ranking, above the cap, after roster
 * approval, substitution, ...) is an exception that the executive director alone decides, personally: no delegation,
 * not the head, not the system admin, not a PR delegate or distributor. The database refuses any other decider.
 * An approved exception reaches a field assignment only through a re-approved roster revision.
 */
final class ReliefExceptions
{
    public const STATUS_LABELS = ['pending' => 'بانتظار القرار', 'approved' => 'معتمد (يدخل في المراجعة القادمة للكشف)', 'applied' => 'مطبق في كشف معتمد',
        'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب', 'expired' => 'منتهي الصلاحية'];

    public function __construct(private readonly ReliefAccess $access, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function request(User $actor, int $campaignId, array $input, WorkflowEngine $workflow): int
    {
        $this->access->assertPrepare($actor);
        $data = validator($input, [
            'unit_child_id' => ['nullable', 'integer'], 'unit_household_id' => ['nullable', 'integer'], 'category_value_id' => ['required', 'integer'],
            'rule_violated' => ['required', 'string', 'min:5', 'max:1000'], 'explanation' => ['required', 'string', 'min:10', 'max:3000'],
            'staff_recommendation' => ['required', 'string', 'min:5', 'max:2000'], 'proposed_items' => ['required', 'array', 'min:1'],
            'proposed_items.*' => ['numeric', 'gt:0'], 'budget_impact' => ['nullable', 'numeric', 'min:0'], 'stock_impact' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'array', 'max:10'], 'evidence.*' => ['integer'],
        ], [], ['rule_violated' => 'القاعدة المخالفة', 'explanation' => 'الشرح', 'staff_recommendation' => 'توصية الموظف'])->validate();
        abort_unless(DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'relief_exception_categories')
            ->where('v.id', $data['category_value_id'])->exists(), 422, 'فئة الاستثناء غير معروفة.');

        return DB::transaction(function () use ($actor, $campaignId, $data, $workflow): int {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign && in_array($campaign->status, ['approved', 'active'], true), 422, 'الاستثناء لحملة معتمدة قائمة.');
            if ($campaign->unit_of_benefit === 'household') {
                $household = (int) ($data['unit_household_id'] ?? 0);
                abort_unless(DB::table('orphan_households')->where('id', $household)->where('status', 'active')->exists(), 422, 'اختر أسرة بملف نشط.');
                [$unitType, $childId, $unitKey] = ['household', null, 'household:'.$household];
            } else {
                $child = DB::table('orphan_children')->where('id', (int) ($data['unit_child_id'] ?? 0))->where('status', 'active')->first();
                abort_unless($child, 422, 'اختر طفلًا بملف نشط.');
                [$unitType, $childId, $unitKey, $household] = ['child', (int) $child->id, 'child:'.$child->id, (int) $child->household_id];
            }
            $items = DB::table('relief_campaign_items')->where('campaign_id', $campaignId)->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (array_diff(array_keys($data['proposed_items']), $items)) {
                throw ValidationException::withMessages(['proposed_items' => 'البنود المقترحة يجب أن تكون من بنود الحملة.']);
            }
            if (DB::table('relief_allocations')->where('campaign_id', $campaignId)->where('unit_key', $unitKey)->where('status', '<>', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['unit' => 'لهذا المستفيد مخصص قائم في الحملة؛ لا يلزم استثناء.']);
            }
            $id = DB::table('relief_exceptions')->insertGetId([
                'public_id' => (string) Str::ulid(), 'reference_no' => 'EXC-'.str_pad((string) DB::selectOne("select nextval('relief_exception_ref_seq') as n")->n, 6, '0', STR_PAD_LEFT),
                'campaign_id' => $campaignId, 'base_roster_id' => DB::table('relief_rosters')->where('campaign_id', $campaignId)->where('status', 'approved')->value('id'),
                'unit_type' => $unitType, 'orphan_child_id' => $childId, 'household_id' => $household, 'unit_key' => $unitKey, 'category_value_id' => $data['category_value_id'],
                'rule_violated' => $data['rule_violated'], 'explanation' => $data['explanation'], 'staff_recommendation' => $data['staff_recommendation'],
                'evidence_document_ids' => isset($data['evidence']) ? json_encode(array_map('intval', $data['evidence'])) : null,
                'proposed_items' => json_encode(array_map('floatval', $data['proposed_items'])), 'budget_impact' => $data['budget_impact'] ?? null,
                'stock_impact' => $data['stock_impact'] ?? null, 'status' => 'pending', 'requested_by' => $actor->id, 'expires_on' => $campaign->ends_on,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $instance = $workflow->startForSubject($actor, 'RELIEF_EXCEPTION', ReliefExceptionWorkflow::SUBJECT_TYPE, $id, $this->access->orphans()->departmentId());
            DB::table('relief_exceptions')->where('id', $id)->update(['workflow_instance_id' => $instance->id]);
            $this->audit->recordRow('relief.exception.requested', 'relief_exceptions', $id, null, ['campaign' => $campaign->reference_no, 'unit' => $unitType], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** The decision itself, inside the workflow move. The trigger re-checks the executive role at write time. */
    public function decide(int $exceptionId, int $actorId, bool $approve, ?string $reason): void
    {
        $exception = DB::table('relief_exceptions')->where('id', $exceptionId)->lockForUpdate()->first();
        if (! $exception || $exception->status !== 'pending') {
            throw ValidationException::withMessages(['exception' => 'الاستثناء ليس بانتظار القرار.']);
        }
        if (mb_strlen(trim((string) $reason)) < 5) {
            throw ValidationException::withMessages(['comment' => 'قرار الاستثناء يُسجّل بمبرره.']);
        }
        DB::table('relief_exceptions')->where('id', $exceptionId)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actorId,
            'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
    }
}
