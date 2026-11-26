<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Models\User;
use App\Services\LeaveApproverResolver;
use App\Services\LeaveWorkflow;
use App\Services\Workflow\WorkflowProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stepper for a leave request.
 *
 * - On the workflow engine: the real approval path of its workflow version, then the leave's own execution
 *   steps (balance deduction, HR notice), so an approved leave never looks finished before its deduction.
 * - A draft in workflow mode: the path its published workflow will take, shown as upcoming.
 * - Legacy single-step requests: built from saved evidence only (approval log, ledger, HR notices).
 *
 * Lists use forList(): a fixed number of queries for any number of leaves.
 */
final class LeaveProgress
{
    /** @var array<int, list<array<string, mixed>>> requester id => draft preview */
    private array $previews = [];

    public function __construct(
        private readonly LeaveApproverResolver $approvers,
        private readonly WorkflowProgress $workflow,
        private readonly LeaveWorkflow $leaveWorkflow,
    ) {}

    /**
     * @param  string  $level  the viewer's visibility on the linked workflow ("public_status" for the requester)
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function view(object $leave, string $level = 'operational'): array
    {
        $evidence = $this->evidence(collect([$leave]));
        if ($leave->workflow_instance_id ?? null) {
            $instance = DB::table('workflow_instances')->where('id', $leave->workflow_instance_id)->first();
            if ($instance) {
                return $this->withExecution($leave, $this->workflow->view($instance, $level), $evidence);
            }
        }

        return $this->withoutWorkflow($leave, $evidence, true);
    }

    /**
     * @param  Collection<int, object>  $leaves  rows with id, status, workflow_instance_id, requested_by, created_at, submitted_at
     * @return array<int, array{steps: list<array<string, mixed>>, summary: array<string, mixed>}> keyed by leave id
     */
    public function forList(Collection $leaves): array
    {
        if ($leaves->isEmpty()) {
            return [];
        }
        $evidence = $this->evidence($leaves);
        $instanceIds = $leaves->pluck('workflow_instance_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $flows = $instanceIds->isEmpty() ? [] : $this->workflow->forList(DB::table('workflow_instances')->whereIn('id', $instanceIds)->get(), 'public_status');

        $result = [];
        foreach ($leaves as $leave) {
            $instanceId = (int) ($leave->workflow_instance_id ?? 0);
            $result[(int) $leave->id] = $instanceId && isset($flows[$instanceId])
                ? $this->withExecution($leave, $flows[$instanceId], $evidence)
                : $this->withoutWorkflow($leave, $evidence, false);
        }

        return $result;
    }

    /**
     * @param  array{steps: list<array<string, mixed>>, summary: array<string, mixed>}  $flow
     * @param  array<string, mixed>  $evidence
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function withExecution(object $leave, array $flow, array $evidence): array
    {
        $status = $leave->status;
        $debit = $evidence['debits'][(int) $leave->id] ?? null;
        $hrNotice = $evidence['notices'][(int) $leave->id] ?? null;
        $steps = [...$flow['steps'], ...$this->executionSteps($status, $debit, $hrNotice)];

        $summary = $flow['summary'];
        if ($status === 'approved') {
            $summary = [
                ...$summary, 'status' => 'معتمدة', 'tone' => $debit ? 'success' : 'warning',
                'where' => $debit ? 'اعتُمدت وخُصمت من الرصيد' : 'اعتُمدت؛ الخصم بانتظار المراجعة',
                'next' => $hrNotice ? 'لا إجراءات متبقية' : 'إشعار الموارد البشرية',
            ];
        }

        return ['steps' => $steps, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function withoutWorkflow(object $leave, array $evidence, bool $withOwner): array
    {
        if ($leave->status === 'draft' && $this->workflowMode()) {
            $preview = $this->preview((int) $leave->requested_by);
            if ($preview !== []) {
                $next = $preview[1]['label'] ?? 'المراجعة';

                return [
                    'steps' => [...$preview, ...$this->executionSteps('draft', null, null)],
                    'summary' => ['status' => 'مسودة', 'tone' => 'warning', 'where' => 'لدى مقدم الطلب (لم يُرسل بعد)', 'next' => 'إرسال الطلب إلى: '.$next, 'dueAt' => null, 'overdue' => false, 'partial' => false, 'reason' => null],
                ];
            }
        }

        return $this->legacy($leave, $evidence, $withOwner);
    }

    /**
     * Single-step requests from before the workflow mode, from their saved evidence.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function legacy(object $leave, array $evidence, bool $withOwner): array
    {
        $history = $evidence['history'][(int) $leave->id] ?? collect();
        $find = fn (string $status): ?object => $history->last(fn (object $row): bool => $row->to_status === $status);
        $submitted = $find('submitted');
        $decided = $find('approved') ?? $find('rejected');
        $cancelled = $find('cancelled');
        $debit = $evidence['debits'][(int) $leave->id] ?? null;
        $hrNotice = $evidence['notices'][(int) $leave->id] ?? null;
        $status = $leave->status;
        $approverId = $withOwner && $status === 'submitted' ? $this->approvers->approverFor($leave) : null;
        $approverName = $approverId ? DB::table('users')->where('id', $approverId)->value('name') : null;
        $approverRole = $approverId && $this->approvers->isExecutive($approverId) ? 'المدير التنفيذي' : 'رئيس القسم';

        $steps = [
            [
                'label' => $status === 'draft' ? 'مسودة الطلب' : 'تقديم الطلب',
                'state' => $status === 'draft' ? 'current' : 'done',
                'owner' => $withOwner ? $submitted?->name : null, 'startedAt' => $leave->created_at, 'completedAt' => $submitted?->created_at ?? ($leave->submitted_at ?? null),
            ],
            [
                'label' => $status === 'rejected' ? 'رفض الطلب' : 'اعتماد الطلب',
                'state' => match ($status) {
                    'submitted', 'manager_approved' => 'current',
                    'approved' => 'done',
                    'rejected' => 'rejected',
                    'cancelled' => $submitted ? 'cancelled' : 'skipped',
                    default => 'upcoming',
                },
                'owner' => $withOwner ? ($decided?->name ?? ($approverName ? $approverName.' ('.$approverRole.')' : ($status === 'submitted' ? 'لا يوجد معتمد صالح؛ راجع الموارد البشرية' : null))) : null,
                'startedAt' => $submitted?->created_at, 'completedAt' => $decided?->created_at ?? $cancelled?->created_at,
                'note' => $status === 'rejected' ? $decided?->comment : ($status === 'cancelled' ? 'أُلغي الطلب من مقدمه' : null),
            ],
            ...$this->executionSteps($status, $debit, $hrNotice),
        ];
        $steps = array_map(fn (array $step): array => $step + ['startedAt' => null, 'completedAt' => null, 'dueAt' => null, 'overdue' => false, 'attempt' => 1, 'note' => null, 'owner' => null], $steps);

        $summary = match ($status) {
            'draft' => ['status' => 'مسودة', 'tone' => 'warning', 'where' => 'لدى مقدم الطلب (لم يُرسل بعد)', 'next' => 'إرسال الطلب للاعتماد'],
            'submitted', 'manager_approved' => ['status' => 'بانتظار الاعتماد', 'tone' => 'info', 'where' => $approverName ? 'لدى '.$approverName : 'بانتظار قرار المعتمد', 'next' => 'قرار الاعتماد ثم خصم الرصيد'],
            'approved' => ['status' => 'معتمدة', 'tone' => 'success', 'where' => $debit ? 'اعتُمدت وخُصمت من الرصيد' : 'اعتُمدت؛ الخصم بانتظار المراجعة', 'next' => $hrNotice ? 'لا إجراءات متبقية' : 'إشعار الموارد البشرية'],
            'rejected' => ['status' => 'مرفوضة', 'tone' => 'danger', 'where' => 'أُغلق الطلب بالرفض', 'next' => 'لا إجراءات متبقية'],
            'cancelled' => ['status' => 'ملغاة', 'tone' => 'muted', 'where' => 'أُلغي الطلب', 'next' => 'لا إجراءات متبقية'],
            default => ['status' => $status, 'tone' => 'muted', 'where' => $status, 'next' => '—'],
        };

        return ['steps' => $steps, 'summary' => $summary + ['dueAt' => null, 'overdue' => false, 'partial' => $status === 'manager_approved', 'reason' => $status === 'rejected' ? $decided?->comment : null]];
    }

    /**
     * The leave's own steps after the administrative decision: they are separate so a decision is never
     * shown as the end of the request while its deduction or HR notice is missing.
     *
     * @return list<array<string, mixed>>
     */
    private function executionSteps(string $status, ?object $debit, ?object $hrNotice): array
    {
        $base = ['startedAt' => null, 'dueAt' => null, 'overdue' => false, 'attempt' => 1];

        return [
            [
                'label' => 'خصم الأيام من الرصيد',
                'state' => match (true) {
                    $status === 'approved' && $debit !== null => 'done',
                    $status === 'approved' => 'pending',
                    in_array($status, ['rejected', 'cancelled'], true) => 'skipped',
                    default => 'upcoming',
                },
                'owner' => 'مرة واحدة عند تسجيل الموارد البشرية', 'completedAt' => $debit?->created_at,
                'note' => $status === 'approved' && $debit === null ? 'لم يُعثر على حركة الخصم في سجل الرصيد؛ تحتاج مراجعة الموارد البشرية.' : null,
            ] + $base,
            [
                'label' => 'إشعار الموارد البشرية',
                'state' => match (true) {
                    $hrNotice !== null => 'done',
                    $status === 'cancelled' => 'skipped',
                    in_array($status, ['approved', 'rejected'], true) => 'pending',
                    default => 'upcoming',
                },
                'owner' => 'الموارد البشرية', 'completedAt' => $hrNotice?->created_at, 'note' => null,
            ] + $base,
        ];
    }

    /**
     * Saved evidence for many leaves at once.
     *
     * @param  Collection<int, object>  $leaves
     * @return array{history: Collection<int, Collection<int, object>>, debits: array<int, object>, notices: array<int, object>}
     */
    private function evidence(Collection $leaves): array
    {
        $ids = $leaves->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        return [
            'history' => DB::table('employee_leave_approvals as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
                ->whereIn('a.leave_request_id', $ids)->orderBy('a.id')
                ->get(['a.leave_request_id', 'a.to_status', 'a.comment', 'a.created_at', 'u.name'])
                ->groupBy('leave_request_id'),
            'debits' => DB::table('employee_leave_transactions')->whereIn('external_key', array_map(fn (int $id): string => 'leave_taken:'.$id, $ids))
                ->get(['leave_request_id', 'created_at'])->keyBy(fn (object $row): int => (int) $row->leave_request_id)->all(),
            'notices' => DB::table('leave_hr_notifications')->whereIn('leave_request_id', $ids)->whereIn('event', ['approved', 'rejected'])
                ->orderBy('id')->get(['leave_request_id', 'created_at'])->unique('leave_request_id')
                ->keyBy(fn (object $row): int => (int) $row->leave_request_id)->all(),
        ];
    }

    private function workflowMode(): bool
    {
        return DB::table('leave_module_settings')->where('id', 1)->value('approval_mode') === 'workflow';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function preview(int $requesterId): array
    {
        if (! array_key_exists($requesterId, $this->previews)) {
            $requester = User::query()->find($requesterId);
            try {
                $this->previews[$requesterId] = $requester ? $this->workflow->preview($this->leaveWorkflow->definitionFor($requester), 'مسودة الطلب') : [];
            } catch (ValidationException) {
                $this->previews[$requesterId] = []; // no leave workflow configured: the legacy view is shown
            }
        }

        return $this->previews[$requesterId];
    }
}
