<?php

namespace App\Services\Orphans;

use App\Models\OrphanChild;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Versioned scoring rulesets (general social score; one ruleset per campaign version) and the social score of
 * each child. A ruleset is edited while it is a draft, approved by the executive director, then frozen (database
 * trigger); a later change is a new version and never rewrites scores or rosters computed with the old one.
 */
final class ScoringRulesets
{
    public const STATUS_LABELS = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار القرار', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'retired' => 'مستبدلة بإصدار أحدث'];

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly ScoringEngine $engine,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data name, factors, tie_breakers, notes */
    public function saveDraft(User $actor, string $kind, ?int $campaignId, array $data, ?int $rulesetId = null): int
    {
        $this->access->assert($actor, $kind === 'campaign' ? 'campaigns' : 'records');
        $factors = $this->engine->normalize($data['factors'] ?? null, $kind);
        $tieBreakers = array_values(array_filter((array) ($data['tie_breakers'] ?? []), fn ($code) => isset(ScoringEngine::FACTORS[$code]) || $code === 'file_no'));
        $name = trim((string) ($data['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw ValidationException::withMessages(['name' => 'اكتب اسمًا واضحًا للإصدار.']);
        }

        return DB::transaction(function () use ($actor, $kind, $campaignId, $factors, $tieBreakers, $name, $data, $rulesetId): int {
            if ($rulesetId) {
                $row = DB::table('scoring_rulesets')->where('id', $rulesetId)->lockForUpdate()->first();
                abort_unless($row && $row->kind === $kind, 404);
                if ($row->status !== 'draft') {
                    throw ValidationException::withMessages(['ruleset' => 'لا يُعدّل إلا الإصدار المسودة؛ أنشئ إصدارًا جديدًا.']);
                }
                DB::table('scoring_rulesets')->where('id', $rulesetId)->update(['name' => $name, 'factors' => json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'tie_breakers' => json_encode($tieBreakers), 'notes' => $data['notes'] ?? null, 'updated_at' => now()]);

                return $rulesetId;
            }
            // Serialise version numbering per scope (row locks cannot be taken on an aggregate in PostgreSQL).
            DB::select('select pg_advisory_xact_lock(?)', [crc32('scoring:'.$kind.':'.($campaignId ?? 0))]);
            $version = (int) DB::table('scoring_rulesets')->where('kind', $kind)->where('campaign_id', $campaignId)->max('version') + 1;
            $id = DB::table('scoring_rulesets')->insertGetId(['public_id' => (string) Str::ulid(), 'kind' => $kind, 'campaign_id' => $campaignId, 'version' => $version,
                'name' => $name, 'factors' => json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'tie_breakers' => json_encode($tieBreakers),
                'notes' => $data['notes'] ?? null, 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.scoring.drafted', 'scoring_rulesets', $id, null, ['kind' => $kind, 'version' => $version, 'campaign' => $campaignId], $actor->id, request()?->ip());

            return $id;
        });
    }

    public function submit(User $actor, int $rulesetId, WorkflowEngine $workflow): void
    {
        DB::transaction(function () use ($actor, $rulesetId, $workflow): void {
            $row = DB::table('scoring_rulesets')->where('id', $rulesetId)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->access->assert($actor, $row->kind === 'campaign' ? 'campaigns' : 'records');
            if ($row->status !== 'draft') {
                throw ValidationException::withMessages(['ruleset' => 'الإصدار ليس مسودة.']);
            }
            if ($row->workflow_instance_id) {
                throw ValidationException::withMessages(['ruleset' => 'للإصدار معاملة قائمة؛ أعد رفعه من إجراءات المعاملة في صفحته.']);
            }
            $instance = $workflow->startForSubject($actor, 'SCORING_RULESET', ScoringRulesetWorkflow::SUBJECT_TYPE, (int) $row->id, $this->access->departmentId());
            DB::table('scoring_rulesets')->where('id', $row->id)->update(['status' => 'pending_approval', 'submitted_at' => now(), 'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }

    /** Called inside the workflow move when the executive director approves. */
    public function approve(int $rulesetId, int $actorId): void
    {
        $row = DB::table('scoring_rulesets')->where('id', $rulesetId)->lockForUpdate()->first();
        $factors = json_decode($row->factors, true);
        if ($row->kind === 'social') {
            DB::table('scoring_rulesets')->where('kind', 'social')->where('status', 'approved')->update(['status' => 'retired', 'updated_at' => now()]);
        } else {
            DB::table('scoring_rulesets')->where('kind', 'campaign')->where('campaign_id', $row->campaign_id)->where('status', 'approved')->update(['status' => 'retired', 'updated_at' => now()]);
        }
        DB::table('scoring_rulesets')->where('id', $rulesetId)->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now(),
            'factors_hash' => ScoringEngine::hash($factors), 'updated_at' => now()]);
    }

    public function activeSocial(): ?object
    {
        return DB::table('scoring_rulesets')->where('kind', 'social')->where('status', 'approved')->first();
    }

    /** Computes and stores the child's general social score with the approved ruleset; the previous score stays as history. */
    public function computeSocial(User $actor, OrphanChild $child): int
    {
        $this->access->assert($actor, 'verify');
        $ruleset = $this->activeSocial();
        if (! $ruleset) {
            throw ValidationException::withMessages(['score' => 'لا توجد قواعد تقييم اجتماعي معتمدة من المدير التنفيذي بعد.']);
        }
        if ($child->status !== 'active') {
            throw ValidationException::withMessages(['score' => 'يُقيّم الملف النشط فقط.']);
        }
        $factors = json_decode($ruleset->factors, true);
        $inputs = $this->engine->inputs($child, array_column($factors, 'code'));
        $result = $this->engine->evaluate($factors, $inputs);

        return DB::transaction(function () use ($actor, $child, $ruleset, $inputs, $result): int {
            DB::table('orphan_children')->where('id', $child->id)->lockForUpdate()->first();
            DB::table('orphan_social_scores')->where('orphan_child_id', $child->id)->where('is_current', true)->update(['is_current' => false, 'updated_at' => now()]);
            $id = DB::table('orphan_social_scores')->insertGetId(['orphan_child_id' => $child->id, 'ruleset_id' => $ruleset->id, 'total' => $result['total'],
                'components' => json_encode(['complete' => $result['complete'], 'factors' => $result['components']], JSON_UNESCAPED_UNICODE),
                'inputs_hash' => hash('sha256', json_encode($inputs)), 'is_current' => true, 'computed_by' => $actor->id, 'computed_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.social_score.computed', 'orphan_social_scores', $id, null, ['child' => $child->file_no, 'ruleset_version' => $ruleset->version, 'total' => $result['total']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** A human-reviewed adjustment by the head, with its reason; the computed total stays visible beside it. */
    public function override(User $actor, int $scoreId, float $total, string $reason): void
    {
        abort_unless($this->access->isHead($actor), 403, 'تعديل التقييم بمبرر لرئيس قسم الكفالات.');
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'اذكر مبررًا واضحًا للتعديل.']);
        }
        DB::transaction(function () use ($actor, $scoreId, $total, $reason): void {
            $score = DB::table('orphan_social_scores')->where('id', $scoreId)->lockForUpdate()->first();
            abort_unless($score && $score->is_current, 404);
            DB::table('orphan_social_scores')->where('id', $scoreId)->update(['override_total' => round($total, 2), 'override_reason' => $reason,
                'override_by' => $actor->id, 'override_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.social_score.overridden', 'orphan_social_scores', $scoreId, ['total' => (float) $score->total], ['override' => round($total, 2), 'reason' => $reason], $actor->id, request()?->ip());
        });
    }
}
