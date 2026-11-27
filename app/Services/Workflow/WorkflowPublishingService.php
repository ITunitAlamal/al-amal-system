<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
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
        private readonly WorkflowSubjects $subjects,
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
            $assignment = $this->assignees->problems($step);
            array_push($problems, ...$assignment);
            // A decision nobody can take today would stop every request reaching it (TRANSFORM §3.4: never skipped,
            // never auto-approved), so it is refused until the role is held or the step names an alternate.
            if ($step->step_type === 'decision' && $assignment === [] && ! $this->assignees->hasEligibleHolder($step)) {
                $problems[] = "مرحلة القرار «{$step->name}» لا يحملها اليوم أحد مؤهل؛ يلزم: ".$this->assignees->holderNeeded($step).'.';
            }
            array_push($problems, ...$this->stepOutcomeProblems($step, $definition));
        }

        $stepIds = $steps->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $stepsById = $steps->keyBy('id');
        $fields = $definition ? $this->conditionFields($definition) : null;
        foreach ($transitions as $transition) {
            if (! in_array((int) $transition->from_step_id, $stepIds, true) || ! in_array((int) $transition->to_step_id, $stepIds, true)) {
                $problems[] = "الإجراء «{$transition->name}» يشير إلى مرحلة خارج هذا الإصدار.";
            }
            if (in_array($transition->action_code, self::REASONED_ACTIONS, true) && ! $transition->requires_comment) {
                $problems[] = "الإجراء «{$transition->name}» يجب أن يتطلب كتابة السبب.";
            }
            $target = $stepsById->get($transition->to_step_id);
            if ($transition->action_code === 'reject' && $target && $target->step_type === 'end'
                && ($this->assignees->config($target)['outcome'] ?? 'completed') !== 'rejected') {
                $problems[] = "الإجراء «{$transition->name}» رفضٌ ينهي الطلب في «{$target->name}» التي تُسجَّل إنجازًا؛ اجعل نتيجتها «مرفوض».";
            }
            foreach ($this->conditions->problems($this->decode($transition->conditions), $fields) as $problem) {
                $problems[] = "الإجراء «{$transition->name}»: {$problem}";
            }
        }
        foreach ($transitions->groupBy(fn (object $transition): string => $transition->from_step_id.':'.$transition->action_code) as $same) {
            if ($same->count() > 1) {
                $from = $stepsById->get($same->first()->from_step_id);
                $problems[] = "رمز الإجراء «{$same->first()->action_code}» مكرر في المرحلة «".($from->name ?? '؟').'»؛ لكل إجراء في المرحلة رمز مختلف.';
            }
        }

        if ($starts->count() === 1) {
            array_push($problems, ...$this->unreachable($starts->first(), $steps, $transitions));
        }
        array_push($problems, ...$this->deadEnds($steps, $transitions));

        return array_values(array_unique($problems));
    }

    /**
     * Points that do not stop publishing but deserve a look: a step whose every forward action is conditional on a
     * request field that may be left empty, so the request can only be sent back or refused there.
     *
     * @return list<string>
     */
    public function warnings(int $versionId): array
    {
        $version = DB::table('workflow_versions')->where('id', $versionId)->first();
        $definition = $version ? DB::table('workflow_definitions')->where('id', $version->workflow_definition_id)->first() : null;
        if (! $definition || $definition->subject_type !== WorkflowEngine::SUBJECT_TYPE) {
            return [];
        }
        $optional = DB::table('custom_field_definitions')->where('entity_type', CustomFieldEntities::AIMS_REQUEST)
            ->whereIn('scope_key', ['', $definition->code])->where('is_active', true)->where('is_required', false)
            ->pluck('label_ar', 'code')->all();
        $warnings = [];
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $versionId)->get()->keyBy('id');
        $transitions = DB::table('workflow_transitions')->where('workflow_version_id', $versionId)->where('is_active', true)->get();
        foreach ($transitions->groupBy('from_step_id') as $fromId => $routes) {
            $forward = $routes->reject(fn (object $transition): bool => in_array($transition->action_code, ['return', 'reject', 'field_cancel'], true));
            if ($forward->isEmpty() || $forward->contains(fn (object $transition): bool => empty($this->decode($transition->conditions)))) {
                continue;
            }
            $tested = $forward->flatMap(fn (object $transition): array => is_array($conditions = $this->decode($transition->conditions)) ? array_keys($conditions) : [])->unique();
            foreach ($tested->filter(fn ($code): bool => is_string($code) && isset($optional[$code])) as $code) {
                $warnings[] = 'كل إجراءات التقدّم من «'.($steps->get($fromId)->name ?? '؟').'» مشروطة بالحقل «'.$optional[$code]
                    .'» غير الإلزامي؛ إن تُرك فارغًا فلن يظهر هناك إلا الإرجاع أو الرفض. اجعله إلزاميًا أو أضف مسارًا افتراضيًا.';
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * The facts a transition condition of this definition can test: the request's own fields (custom fields of a
     * generic request, with the codes of a list field's values; the module's facts for a module record) and the
     * built-in facts about the requester. Null when the module's facts are not known (names are then not checked).
     *
     * @return array<string, array{type: string, values: list<string>|null}>|null
     */
    public function conditionFields(object $definition): ?array
    {
        $fields = [];
        if ($definition->subject_type === WorkflowEngine::SUBJECT_TYPE) {
            $rows = DB::table('custom_field_definitions')->where('entity_type', CustomFieldEntities::AIMS_REQUEST)
                ->whereIn('scope_key', ['', $definition->code])->where('is_active', true)
                ->orderByRaw("CASE WHEN scope_key = '' THEN 0 ELSE 1 END")
                ->get(['code', 'field_type', 'master_data_type_id']);
            $codes = DB::table('master_data_values')->whereIn('type_id', $rows->pluck('master_data_type_id')->filter()->unique()->values())
                ->get(['type_id', 'code'])->groupBy('type_id');
            foreach ($rows as $row) {
                // A list field is tested by the code of its value (CustomFieldService::conditionContext).
                $fields[$row->code] = ['type' => $row->field_type, 'values' => $row->field_type === 'select' && $row->master_data_type_id
                    ? $codes->get($row->master_data_type_id, collect())->pluck('code')->map(fn ($code): string => (string) $code)->all() : null];
            }
        } else {
            $module = $this->subjects->conditionFields((string) $definition->subject_type);
            if ($module === null) {
                return null;
            }
            foreach ($module as $code => $type) {
                $fields[$code] = ['type' => $type, 'values' => null];
            }
        }
        foreach (array_keys(WorkflowEngine::REQUESTER_CONTEXT) as $code) {
            $fields[$code] = ['type' => 'boolean', 'values' => null];
        }

        return $fields;
    }

    /**
     * An end step records approval unless its outcome says "rejected" (WorkflowEngine::move), so its outcome must be
     * one of the two. A step effect runs only for generic requests, must be known, and opens its follow-up once, when
     * the request is completed.
     *
     * @return list<string>
     */
    private function stepOutcomeProblems(object $step, ?object $definition): array
    {
        $config = $this->assignees->config($step);
        $problems = [];
        $outcome = $config['outcome'] ?? null;
        if ($step->step_type === 'end' && $outcome !== null && ! in_array($outcome, ['completed', 'rejected'], true)) {
            $problems[] = "نتيجة مرحلة النهاية «{$step->name}» يجب أن تكون «مكتمل» أو «مرفوض».";
        }
        $effect = $config['effect'] ?? null;
        if ($effect === null || $effect === '') {
            return $problems;
        }
        $effect = is_string($effect) ? $effect : (string) json_encode($effect, JSON_UNESCAPED_UNICODE);
        $problems[] = match (true) {
            ($definition->subject_type ?? null) !== WorkflowEngine::SUBJECT_TYPE => "الأثر في المرحلة «{$step->name}» يعمل في الطلبات العامة فقط؛ معاملات الوحدات تطبّق أثرها بنفسها.",
            ! in_array($effect, AimsRequestEffects::EFFECTS, true) => "الأثر «{$effect}» في المرحلة «{$step->name}» غير معروف.",
            $step->step_type !== 'end' || $outcome === 'rejected' => "الأثر «{$effect}» يُنفَّذ مرة واحدة عند إنجاز الطلب؛ ضعه على مرحلة نهاية نتيجتها «مكتمل».",
            default => '',
        };

        return array_values(array_filter($problems));
    }

    /**
     * Steps from which no path leads to an end step (e.g. a return loop with no exit). A step with no action at
     * all is already reported on its own.
     *
     * @param  Collection<int, object>  $steps
     * @param  Collection<int, object>  $transitions
     * @return list<string>
     */
    private function deadEnds(Collection $steps, Collection $transitions): array
    {
        $incoming = [];
        $leaves = [];
        foreach ($transitions as $transition) {
            $incoming[(int) $transition->to_step_id][] = (int) $transition->from_step_id;
            $leaves[(int) $transition->from_step_id] = true;
        }
        $reaches = [];
        $queue = $steps->where('step_type', 'end')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($reaches[$current])) {
                continue;
            }
            $reaches[$current] = true;
            foreach ($incoming[$current] ?? [] as $previous) {
                $queue[] = $previous;
            }
        }

        return $steps
            ->filter(fn (object $step): bool => $step->step_type !== 'end' && isset($leaves[(int) $step->id]) && ! isset($reaches[(int) $step->id]))
            ->map(fn (object $step): string => "لا يوجد من المرحلة «{$step->name}» طريق إلى نهاية المسار، فقد تدور المعاملة فيها دون إغلاق.")
            ->values()
            ->all();
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
