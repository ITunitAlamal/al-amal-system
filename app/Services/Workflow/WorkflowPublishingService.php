<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft → validate → publish lifecycle for workflow versions (SRS §79 قواعد الإصدار).
 *
 * Published versions are never edited. A change is made on a new draft cloned
 * from the latest version; publishing it retires the previous version for new
 * requests while running requests stay on the version they started with.
 */
final class WorkflowPublishingService
{
    /** Actions that must always carry a written reason. */
    private const REASONED_ACTIONS = ['reject', 'return'];

    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly ConditionEvaluator $conditions,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return list<string> problems that block publishing; empty when publishable
     */
    public function problems(int $versionId): array
    {
        $version = DB::table('workflow_versions')->where('id', $versionId)->first();
        if (! $version) {
            return ['إصدار سير العمل غير موجود.'];
        }

        $problems = [];
        if ($version->status !== 'draft') {
            $problems[] = 'النشر متاح للمسودات فقط.';
        }
        $definition = DB::table('workflow_definitions')->where('id', $version->workflow_definition_id)->first();
        if (! $definition || ! $definition->is_active) {
            $problems[] = 'تعريف سير العمل غير نشط.';
        }

        $steps = DB::table('workflow_steps')->where('workflow_version_id', $versionId)->orderBy('sort_order')->get();
        $transitions = DB::table('workflow_transitions')->where('workflow_version_id', $versionId)->where('is_active', true)->get();

        if ($steps->isEmpty()) {
            return [...$problems, 'لا توجد مراحل في سير العمل.'];
        }

        $starts = $steps->where('step_type', 'start');
        if ($starts->count() !== 1) {
            $problems[] = 'يجب وجود مرحلة بداية واحدة فقط.';
        }
        if ($steps->where('step_type', 'end')->isEmpty()) {
            $problems[] = 'يجب وجود مرحلة نهاية واحدة على الأقل.';
        }

        $outgoing = $transitions->groupBy('from_step_id');
        foreach ($steps as $step) {
            $count = $outgoing->get($step->id, collect())->count();
            if ($step->step_type === 'end' && $count > 0) {
                $problems[] = "مرحلة النهاية «{$step->name}» لا يجوز أن يخرج منها انتقال.";
            }
            if ($step->step_type !== 'end' && $count === 0) {
                $problems[] = "المرحلة «{$step->name}» لا يخرج منها أي إجراء، فستتوقف المعاملة عندها.";
            }
            if ($step->step_type === 'start' && $count > 1) {
                // Several send routes are allowed only when they are told apart by conditions (at most one default).
                $unconditional = $outgoing->get($step->id)->filter(fn (object $transition): bool => empty($this->decode($transition->conditions)))->count();
                if ($unconditional > 1) {
                    $problems[] = "مرحلة البداية «{$step->name}» لها أكثر من إجراء إرسال بلا شروط؛ ميّز المسارات بشروط واترك مسارًا افتراضيًا واحدًا على الأكثر.";
                }
            }
            array_push($problems, ...$this->assignees->problems($step));
        }

        $stepIds = $steps->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($transitions as $transition) {
            if (! in_array((int) $transition->from_step_id, $stepIds, true) || ! in_array((int) $transition->to_step_id, $stepIds, true)) {
                $problems[] = "الإجراء «{$transition->name}» يشير إلى مرحلة خارج هذا الإصدار.";
            }
            if (in_array($transition->action_code, self::REASONED_ACTIONS, true) && ! $transition->requires_comment) {
                $problems[] = "الإجراء «{$transition->name}» يجب أن يتطلب كتابة السبب.";
            }
            array_push($problems, ...$this->conditions->problems($this->decode($transition->conditions)));
        }

        if ($starts->count() === 1) {
            array_push($problems, ...$this->unreachable($starts->first(), $steps, $transitions));
        }

        return array_values(array_unique($problems));
    }

    public function publish(int $versionId, User $publisher, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($versionId, $publisher, $ipAddress): void {
            $version = DB::table('workflow_versions')->where('id', $versionId)->lockForUpdate()->first();
            abort_unless($version, 404);

            $problems = $this->problems($versionId);
            if ($problems !== []) {
                throw ValidationException::withMessages(['publish' => $problems]);
            }

            $now = now();
            $retired = DB::table('workflow_versions')
                ->where('workflow_definition_id', $version->workflow_definition_id)
                ->where('status', 'published')
                ->pluck('id');
            DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
            DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);

            $this->audit->recordRow('workflow.version.published', 'workflow_versions', $versionId,
                ['status' => 'draft', 'retired_versions' => $retired->all()], ['status' => 'published'], $publisher->id, $ipAddress);
        });
    }

    /**
     * Return the open draft of a workflow, cloning the latest version when none exists.
     */
    public function draftFor(int $definitionId, User $author, ?string $ipAddress = null): int
    {
        return DB::transaction(function () use ($definitionId, $author, $ipAddress): int {
            $existing = DB::table('workflow_versions')
                ->where('workflow_definition_id', $definitionId)->where('status', 'draft')
                ->lockForUpdate()->value('id');
            if ($existing) {
                return (int) $existing;
            }

            $latest = DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)
                ->orderByDesc('version_number')->lockForUpdate()->first();
            $now = now();
            $draftId = DB::table('workflow_versions')->insertGetId([
                'workflow_definition_id' => $definitionId,
                'version_number' => ($latest->version_number ?? 0) + 1,
                'status' => 'draft',
                'created_by' => $author->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($latest) {
                $this->cloneInto((int) $latest->id, $draftId);
            }

            $this->audit->recordRow('workflow.version.drafted', 'workflow_versions', $draftId,
                null, ['cloned_from' => $latest->id ?? null], $author->id, $ipAddress);

            return $draftId;
        });
    }

    private function cloneInto(int $sourceVersionId, int $draftId): void
    {
        $now = now();
        $stepMap = [];

        foreach (DB::table('workflow_steps')->where('workflow_version_id', $sourceVersionId)->orderBy('sort_order')->get() as $step) {
            $stepMap[$step->id] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $draftId,
                'code' => $step->code,
                'name' => $step->name,
                'sort_order' => $step->sort_order,
                'step_type' => $step->step_type,
                'assignment_type' => $step->assignment_type,
                'assigned_role_code' => $step->assigned_role_code,
                'assignment_config' => $step->assignment_config,
                'sla_hours' => $step->sla_hours,
                'is_required' => $step->is_required,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('workflow_transitions')->where('workflow_version_id', $sourceVersionId)->orderBy('id')->get() as $transition) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $draftId,
                'from_step_id' => $stepMap[$transition->from_step_id],
                'to_step_id' => $stepMap[$transition->to_step_id],
                'action_code' => $transition->action_code,
                'name' => $transition->name,
                'conditions' => $transition->conditions,
                'requires_comment' => $transition->requires_comment,
                'is_active' => $transition->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  Collection<int, object>  $steps
     * @param  Collection<int, object>  $transitions
     * @return list<string>
     */
    private function unreachable(object $start, Collection $steps, Collection $transitions): array
    {
        $graph = [];
        foreach ($transitions as $transition) {
            $graph[(int) $transition->from_step_id][] = (int) $transition->to_step_id;
        }

        $visited = [];
        $queue = [(int) $start->id];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($graph[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return $steps
            ->reject(fn (object $step): bool => isset($visited[(int) $step->id]))
            ->map(fn (object $step): string => "المرحلة «{$step->name}» لا يمكن الوصول إليها من بداية المسار.")
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (is_array($json)) {
            return $json;
        }

        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }
}
