<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * Structural changes on the central engine: proposal → executive director's decision. Approval applies the change in
 * the same transaction as the move, so an approved request can never leave the tree half-changed.
 */
final class StructureChangeWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'org_structure_change';

    private const STATUS_BY_STEP = ['proposer' => 'returned', 'ed_decision' => 'in_review', 'applied' => 'applied', 'rejected' => 'rejected'];

    public function __construct(
        private readonly OrganizationStructure $structure,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $change = DB::table('department_structure_changes')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($change, 404);
        if ($from->code === 'proposer') {
            abort_unless((int) $change->proposed_by === $actorId, 403, 'يرفع المقترح مقدمه.');
            $this->structure->validate($change);
        }
        if ($target->code === 'applied') {
            $actor = User::findOrFail($actorId);
            abort_unless($actor->roleAssignments()->effective()->whereHas('role', fn ($q) => $q->where('code', 'executive_director'))->exists(), 403, 'يعتمد تعديل الهيكل المدير التنفيذي.');
            $this->structure->apply($change, $actorId, 'executive_director');
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $change = DB::table('department_structure_changes')->where('id', $instance->subject_id)->first();
        $status = self::STATUS_BY_STEP[$target->code] ?? $change->status;
        $decided = in_array($target->code, ['applied', 'rejected'], true);
        DB::table('department_structure_changes')->where('id', $change->id)->update(['status' => $status, 'decided_by' => $decided ? $actorId : $change->decided_by,
            'decided_at' => $decided ? ($change->decided_at ?? now()) : $change->decided_at, 'updated_at' => now()]);
        $this->audit->recordRow('organization.change.'.$transition->action_code, 'department_structure_changes', (int) $change->id, ['status' => $change->status],
            ['status' => $status, 'workflow' => $instance->reference_no, 'comment' => $comment], $actorId);
        if (($decided || $target->code === 'proposer') && $change->proposed_by && (int) $change->proposed_by !== $actorId) {
            $this->notifications->notify((int) $change->proposed_by, 'org_structure_change_decided', [
                'title' => match ($target->code) {
                    'applied' => 'اعتُمد تعديل الهيكل وطُبّق', 'rejected' => 'رُفض مقترح تعديل الهيكل', default => 'أُعيد مقترح تعديل الهيكل للاستكمال'
                },
                'url' => $this->url((int) $change->id), 'dedupe' => 'org-change:'.$change->id.':'.$target->code.':'.$instance->revision,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/admin/organization/changes/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('department_structure_changes as c')->leftJoin('departments as d', 'd.id', '=', 'c.department_id')->leftJoin('departments as p', 'p.id', '=', 'c.parent_id')
            ->whereIn('c.id', $subjectIds)->get(['c.id', 'c.change_type', 'c.proposed_name', 'd.name as node', 'p.name as parent'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => 'تعديل الهيكل: '.(OrganizationStructure::CHANGE_LABELS[$row->change_type] ?? $row->change_type)
                .' «'.($row->proposed_name ?: $row->node).'»'.($row->parent ? ' ← '.$row->parent : '')])->all();
    }
}
