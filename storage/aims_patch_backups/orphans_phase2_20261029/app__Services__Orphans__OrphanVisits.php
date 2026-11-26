<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Social visits: planned by the department, reported and attested by the assigned researcher, reviewed by the head.
 * Findings are encrypted; media capture is off (no consent and policy basis has been approved for it).
 */
final class OrphanVisits
{
    public const STATUS_LABELS = ['planned' => 'مجدولة', 'submitted' => 'بانتظار مراجعة الرئيس', 'returned' => 'معادة للباحث', 'reviewed' => 'معتمدة', 'cancelled' => 'ملغاة'];

    private const TEXT = ['interviewed', 'social_findings', 'economic_findings', 'housing_findings', 'recommendations'];

    public function __construct(private readonly OrphanAccess $access, private readonly OrphanRegistry $registry, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function plan(User $actor, int $householdId, array $data): int
    {
        $this->access->assert($actor, 'visits');
        $data = validator($data, [
            'orphan_child_id' => ['nullable', 'integer'], 'visit_type_value_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'scheduled_on' => ['required', 'date', 'after_or_equal:today'], 'researcher_id' => ['nullable', 'integer'],
        ], [], ['reason' => 'سبب الزيارة', 'scheduled_on' => 'موعد الزيارة'])->validate();
        $researcher = (int) ($data['researcher_id'] ?? $actor->id);
        if ($researcher !== $actor->id) {
            abort_unless($this->access->isHead($actor), 403, 'تكليف باحث آخر لرئيس القسم.');
            abort_unless($this->access->allows(User::find($researcher), 'visits'), 422, 'الباحث المختار لا يملك تفويض الزيارات.');
        }
        if (! empty($data['orphan_child_id'])) {
            abort_unless(DB::table('orphan_children')->where('id', $data['orphan_child_id'])->where('household_id', $householdId)->exists(), 422);
        }
        if (! empty($data['visit_type_value_id'])) {
            validator($data, ['visit_type_value_id' => [app(OrphanForms::class)->valueOf('social_visit_types')]])->validate();
        }

        $id = DB::table('orphan_social_visits')->insertGetId([
            'public_id' => (string) Str::ulid(), 'reference_no' => $this->registry->number('orphan_visit_ref_seq', 'VIS-'), 'household_id' => $householdId,
            'orphan_child_id' => $data['orphan_child_id'] ?? null, 'visit_type_value_id' => $data['visit_type_value_id'] ?? null, 'reason' => $data['reason'],
            'scheduled_on' => $data['scheduled_on'], 'researcher_id' => $researcher, 'status' => 'planned', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->recordRow('orphans.visit.planned', 'orphan_social_visits', $id, null, ['household' => $householdId, 'researcher' => $researcher], $actor->id, request()?->ip());

        return $id;
    }
    /** The researcher writes the report (only their own visit, before review). @param array<string, mixed> $data */
    public function report(User $actor, int $visitId, array $data): void
    {
        $data = validator($data, [
            'visited_on' => ['required', 'date', 'before_or_equal:today'], 'interviewed' => ['required', 'string', 'max:500'],
            'social_findings' => ['nullable', 'string', 'max:3000'], 'economic_findings' => ['nullable', 'string', 'max:3000'],
            'housing_findings' => ['nullable', 'string', 'max:3000'], 'needs' => ['nullable', 'array', 'max:20'], 'needs.*' => ['string', 'max:120'],
            'recommendations' => ['required', 'string', 'max:3000'], 'action_items' => ['nullable', 'array', 'max:10'], 'action_items.*' => ['string', 'max:300'],
            'next_visit_on' => ['nullable', 'date', 'after:visited_on'],
        ], [], ['visited_on' => 'تاريخ الزيارة', 'interviewed' => 'من قوبل', 'recommendations' => 'التوصيات'])->validate();
        DB::transaction(function () use ($actor, $visitId, $data): void {
            $visit = DB::table('orphan_social_visits')->where('id', $visitId)->lockForUpdate()->first();
            abort_unless($visit, 404);
            abort_unless((int) $visit->researcher_id === (int) $actor->id && $this->access->allows($actor, 'visits'), 403, 'يكتب التقرير الباحث المكلف.');
            if (! in_array($visit->status, ['planned', 'returned'], true)) {
                throw ValidationException::withMessages(['visit' => 'التقرير مرفوع للمراجعة أو معتمد.']);
            }
            $values = ['visited_on' => $data['visited_on'], 'next_visit_on' => $data['next_visit_on'] ?? null,
                'needs' => json_encode(array_values($data['needs'] ?? []), JSON_UNESCAPED_UNICODE), 'action_items' => json_encode(array_values($data['action_items'] ?? []), JSON_UNESCAPED_UNICODE),
                'updated_at' => now()];
            foreach (self::TEXT as $field) {
                $values[$field] = isset($data[$field]) && $data[$field] !== '' ? Crypt::encryptString($data[$field]) : null;
            }
            DB::table('orphan_social_visits')->where('id', $visitId)->update($values);
        });
    }

    /** The researcher attests the report and sends it to the head. */
    public function submit(User $actor, int $visitId, WorkflowEngine $workflow): void
    {
        DB::transaction(function () use ($actor, $visitId, $workflow): void {
            $visit = DB::table('orphan_social_visits')->where('id', $visitId)->lockForUpdate()->first();
            abort_unless($visit && (int) $visit->researcher_id === (int) $actor->id, 403);
            if ($visit->status !== 'planned' || ! $visit->visited_on || ! $visit->recommendations) {
                throw ValidationException::withMessages(['visit' => 'أكمل تقرير الزيارة قبل رفعه.']);
            }
            $instance = $workflow->startForSubject($actor, 'ORPHAN_SOCIAL_VISIT', OrphanVisitWorkflow::SUBJECT_TYPE, (int) $visit->id, $this->access->departmentId());
            DB::table('orphan_social_visits')->where('id', $visit->id)->update(['status' => 'submitted', 'attested_at' => now(), 'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }

    /** @return array<string, ?string> decrypted findings for authorised screens */
    public function findings(object $visit): array
    {
        $out = [];
        foreach (self::TEXT as $field) {
            $out[$field] = $visit->{$field} ? Crypt::decryptString($visit->{$field}) : null;
        }

        return $out;
    }
}
