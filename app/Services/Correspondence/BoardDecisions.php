<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «سجل قرارات مجلس الإدارة» (TRANSFORM §3.3; SRS 3.6.1): the one authoritative record of each Board decision. It is
 * fed by the decision letter itself (type «تبليغ قرار مجلس الإدارة», workflow BOARD_CORRESPONDENCE) and adds what the
 * letter lacked: the decision number and date, the meeting, and the departments charged with executing it with
 * their follow-up state. The text and attachments are the letter's issued original. Who sees a decision is decided
 * by CorrespondenceAccess on the letter; nothing here widens it. Installed by migration 2026_11_30_000037; until it
 * runs every method is a no-op and the letter keeps its free-text board_reference.
 */
final class BoardDecisions
{
    public const FIELDS = ['decision_number', 'decision_date', 'meeting_number', 'meeting_date'];

    public const ASSIGNMENT_LABELS = ['executing' => 'قيد التنفيذ', 'reported' => 'رُفع تقرير التنفيذ', 'accepted' => 'قُبل التنفيذ', 'superseded' => 'نُقل التكليف إلى قسم آخر'];

    private ?bool $installed = null;

    public function __construct(private readonly CorrespondenceAccess $access, private readonly AuditService $audit) {}

    public function installed(): bool
    {
        return $this->installed ??= Schema::hasTable('board_decisions') && Schema::hasTable('board_decision_assignments');
    }

    public function isBoardType(int $typeValueId): bool
    {
        return DB::table('master_data_values')->where('id', $typeValueId)->value('code') === 'board_transmission';
    }

    /**
     * Keeps the decision data of a draft letter: written while the letter is a draft of type «قرار مجلس», removed
     * when the draft changes to another type. $fields = null means the letter is no Board decision.
     *
     * @param  array<string, mixed>|null  $fields
     */
    public function saveDraft(User $actor, object $letter, ?array $fields): void
    {
        if (! $this->installed()) {
            return;
        }
        $row = DB::table('board_decisions')->where('correspondence_id', $letter->id)->lockForUpdate()->first();
        abort_if($row && $row->recorded_at !== null, 422, 'القرار مسجل ولا تتغير بياناته.');
        if ($fields === null) {
            if ($row) {
                DB::table('board_decisions')->where('id', $row->id)->delete();
            }

            return;
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        if ($row) {
            DB::table('board_decisions')->where('id', $row->id)->update([...$values, 'updated_at' => now()]);
        } else {
            DB::table('board_decisions')->insert([...$values, 'correspondence_id' => $letter->id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * The board secretary sends the decision: its data must be complete and its number free in its year. Nothing is
     * fixed yet; the Secretariat checks the data first (SRS 3.6.2) and may return it to the secretary (correct()).
     */
    public function checkOnIssue(object $letter): void
    {
        if (! $this->installed()) {
            return;
        }
        $row = DB::table('board_decisions')->where('correspondence_id', $letter->id)->lockForUpdate()->first();
        $this->checkComplete($row, 'أكمل بيانات القرار في المسودة قبل الإرسال: رقم القرار وتاريخه ورقم الجلسة.');
    }

    /**
     * The board secretary completes or corrects the decision data when the Secretariat returned it («register» →
     * «record»), sent with the record → register action. Only the author, only at that stage, only before the
     * Secretariat registered it; every change keeps its before/after in the audit trail.
     *
     * @param  array<string, mixed>  $fields
     */
    public function correct(User $actor, object $letter, array $fields): void
    {
        if (! $this->installed()) {
            return;
        }
        $row = DB::table('board_decisions')->where('correspondence_id', $letter->id)->lockForUpdate()->first();
        $stage = DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.id', $letter->workflow_instance_id)->value('s.code');
        abort_unless($row && $stage === 'record' && (int) $letter->author_id === (int) $actor->id, 403, 'تُستكمل بيانات القرار عند إعادته لأمين السر فقط.');
        abort_if($row->recorded_at !== null, 422, 'القرار مسجل ولا تتغير بياناته.');
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $before = array_intersect_key((array) $row, $values);
        if ($before != $values) {
            DB::table('board_decisions')->where('id', $row->id)->update([...$values, 'updated_at' => now()]);
            if ($values['decision_number'] !== null) {
                // The letter's Board reference follows the decision number, as on the draft.
                DB::table('official_correspondences')->where('id', $letter->id)->update(['board_reference' => mb_substr($values['decision_number'], 0, 200)]);
            }
            $this->audit->recordRow('board_decision.corrected', 'board_decisions', (int) $row->id, $before, $values, (int) $actor->id);
        }
        $this->checkComplete(DB::table('board_decisions')->find($row->id), 'أكمل بيانات القرار قبل إرساله للتسجيل: رقم القرار وتاريخه ورقم الجلسة.');
    }

    /**
     * The Secretariat registers the decision («register» → «allocate»): its number, date and meeting become fixed
     * (trigger board_decisions_guard) and the number may not repeat within the decision's year.
     */
    private function record(object $row, object $letter, int $actorId): void
    {
        $this->checkComplete($row, 'بيانات القرار غير مكتملة؛ أعده لأمين السر لاستكمال رقم القرار وتاريخه ورقم الجلسة.');
        try {
            DB::transaction(fn () => DB::table('board_decisions')->where('id', $row->id)->update(['recorded_at' => now(), 'updated_at' => now()]));
        } catch (QueryException $exception) {
            // Two decisions with one number registered at the same moment: the unique index refuses the second.
            abort_if($exception->getCode() === '23505', 422, 'رقم القرار مسجل من قبل في السنة نفسها؛ أعده لأمين السر لتصحيحه.');
            throw $exception;
        }
        $this->audit->recordRow('board_decision.recorded', 'board_decisions', (int) $row->id, null,
            ['correspondence_id' => (int) $letter->id, ...array_intersect_key((array) $row, array_flip(self::FIELDS))], $actorId);
    }

    private function checkComplete(?object $row, string $incomplete): void
    {
        abort_unless($row && filled($row->decision_number) && $row->decision_date && filled($row->meeting_number), 422, $incomplete);
        abort_if($row->decision_date > today()->toDateString() || ($row->meeting_date && $row->meeting_date > today()->toDateString()), 422, 'تاريخ القرار أو الجلسة لا يكون في المستقبل.');
        $duplicate = DB::table('board_decisions')->whereNotNull('recorded_at')->where('id', '<>', $row->id)
            ->whereRaw('lower(btrim(decision_number)) = lower(btrim(?))', [$row->decision_number])
            ->whereYear('decision_date', substr((string) $row->decision_date, 0, 4))->exists();
        abort_if($duplicate, 422, 'رقم القرار مسجل من قبل في السنة نفسها.');
    }

    /** The departments the user heads now (department head or a dated acting head). */
    public function headedDepartments(User $user): array
    {
        return $this->access->departments()->filter(fn (object $department): bool => $this->access->head($user, (int) $department->id))
            ->map(fn (object $department): array => ['id' => (int) $department->id, 'name' => $department->name])->values()->all();
    }

    /**
     * The department a decision is executed by when the executive director hands it to this head: the one chosen,
     * which he must head; else the only one he heads; else the one that already executed this decision. Null when he
     * heads none (the stage check refuses him); refused when he heads several and none is known.
     */
    public function executingDepartment(User $head, int $letterId, ?int $chosen): ?int
    {
        $headed = array_column($this->headedDepartments($head), 'id');
        if ($chosen !== null) {
            abort_unless(in_array($chosen, $headed, true), 422, 'المكلف ليس رئيسًا للقسم المختار للتنفيذ.');

            return $chosen;
        }
        if (count($headed) <= 1) {
            return $headed[0] ?? null;
        }
        $previous = $this->installed() ? DB::table('board_decision_assignments as a')->join('board_decisions as d', 'd.id', '=', 'a.board_decision_id')
            ->where('d.correspondence_id', $letterId)->where('a.head_user_id', $head->id)->whereIn('a.department_id', $headed)
            ->orderByDesc('a.updated_at')->value('a.department_id') : null;
        abort_unless($previous, 422, 'المكلف رئيس لأكثر من قسم؛ اختر القسم المكلف بتنفيذ القرار.');

        return (int) $previous;
    }

    /**
     * Follows a move of the decision's workflow (inside the engine's transaction): the Secretariat's registration,
     * the executive director's assignment, the department's report, its acceptance or return, and the closure.
     */
    public function afterMove(object $letter, object $from, object $target, int $actorId, ?User $recipient, ?int $department, int $event, ?string $due): void
    {
        if (! $this->installed()) {
            return;
        }
        $decision = DB::table('board_decisions')->where('correspondence_id', $letter->id)->lockForUpdate()->first();
        if (! $decision) {
            return;
        }
        if ($from->code === 'register' && $target->code === 'allocate' && $decision->recorded_at === null) {
            $this->record($decision, $letter, $actorId);
        }
        $rows = DB::table('board_decision_assignments')->where('board_decision_id', $decision->id);
        if ($target->code === 'execute' && $recipient && $department) {
            foreach ((clone $rows)->where('department_id', '<>', $department)->whereIn('status', ['executing', 'reported', 'accepted'])->get() as $other) {
                $this->change($other, ['status' => 'superseded'], $actorId);
            }
            $values = ['head_user_id' => $recipient->id, 'assigned_by' => $actorId, 'assigned_event_id' => $event, 'due_at' => $due,
                'status' => 'executing', 'reported_at' => null, 'report_event_id' => null, 'accepted_at' => null];
            $existing = (clone $rows)->where('department_id', $department)->first();
            if ($existing) {
                $this->change($existing, $values, $actorId);
            } else {
                $id = (int) DB::table('board_decision_assignments')->insertGetId([...$values, 'board_decision_id' => $decision->id, 'department_id' => $department, 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->recordRow('board_decision.assigned', 'board_decision_assignments', $id, null, [...$values, 'board_decision_id' => (int) $decision->id, 'department_id' => $department], $actorId);
            }
        } elseif ($from->code === 'execute') {
            foreach ((clone $rows)->where('status', 'executing')->get() as $row) {
                $this->change($row, ['status' => 'reported', 'reported_at' => now(), 'report_event_id' => $event], $actorId);
            }
        } elseif ($from->code === 'review') {
            foreach ((clone $rows)->where('status', 'reported')->get() as $row) {
                $this->change($row, ['status' => 'accepted', 'accepted_at' => now()], $actorId);
            }
        } elseif ($from->code === 'authorized_close' && $target->code === 'review') {
            foreach ((clone $rows)->where('status', 'accepted')->get() as $row) {
                $this->change($row, ['status' => 'reported', 'accepted_at' => null], $actorId);
            }
        }
        if ($target->step_type === 'end') {
            DB::table('board_decisions')->where('id', $decision->id)->update(['closed_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('board_decision.closed', 'board_decisions', (int) $decision->id, null, ['workflow_event_id' => $event], $actorId);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function change(object $row, array $values, int $actorId): void
    {
        DB::table('board_decision_assignments')->where('id', $row->id)->update([...$values, 'updated_at' => now()]);
        $this->audit->recordRow('board_decision.assignment_changed', 'board_decision_assignments', (int) $row->id,
            array_intersect_key((array) $row, $values), $values, $actorId);
    }

    /** The decision data shown on the letter's page, or null when the letter is no recorded Board decision. */
    public function forLetter(int $letterId): ?array
    {
        if (! $this->installed()) {
            return null;
        }
        $decision = DB::table('board_decisions')->where('correspondence_id', $letterId)->first();
        if (! $decision) {
            return null;
        }
        $assignments = DB::table('board_decision_assignments as a')->join('departments as d', 'd.id', '=', 'a.department_id')
            ->join('users as h', 'h.id', '=', 'a.head_user_id')->where('a.board_decision_id', $decision->id)->orderBy('a.id')
            ->get(['a.id', 'a.status', 'a.due_at', 'a.reported_at', 'a.accepted_at', 'a.created_at', 'd.name as department', 'h.name as head'])
            ->map(fn (object $row): array => [...(array) $row, 'label' => self::ASSIGNMENT_LABELS[$row->status] ?? $row->status,
                'overdue' => $row->status === 'executing' && $row->due_at !== null && $row->due_at < now()->toDateTimeString()])->all();

        return [
            'decision_number' => $decision->decision_number, 'decision_date' => $decision->decision_date,
            'meeting_number' => $decision->meeting_number, 'meeting_date' => $decision->meeting_date,
            'recorded_at' => $decision->recorded_at, 'closed_at' => $decision->closed_at, 'assignments' => $assignments,
        ];
    }

    /** The draft's decision fields for the edit form. */
    public function draftFields(int $letterId): array
    {
        $row = $this->installed() ? DB::table('board_decisions')->where('correspondence_id', $letterId)->first(self::FIELDS) : null;

        return $row ? (array) $row : [];
    }

    /**
     * The register: recorded decisions the user may open (CorrespondenceAccess::visible), newest first, with their
     * stage, current holder, deadline and executing departments.
     *
     * @param  array{q?: string|null, state?: string|null, department_id?: int|string|null}  $filters
     */
    public function register(User $user, array $filters): Builder
    {
        $query = $this->access->visible($user)
            ->join('board_decisions as d', 'd.correspondence_id', '=', 'c.id')->whereNotNull('d.recorded_at')
            ->leftJoin('workflow_instances as i', 'i.id', '=', 'c.workflow_instance_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('correspondence_tasks as t', 't.correspondence_id', '=', 'c.id')
            ->leftJoin('users as holder', 'holder.id', '=', 't.assigned_to');
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('d.decision_number', 'ilike', $term)->orWhere('d.meeting_number', 'ilike', $term)
                ->orWhere('c.subject', 'ilike', $term)->orWhere('c.reference', 'ilike', $term));
        }
        if (filled($filters['department_id'] ?? null)) {
            $query->whereExists(DB::table('board_decision_assignments as charged')->whereColumn('charged.board_decision_id', 'd.id')
                ->where('charged.department_id', (int) $filters['department_id'])->where('charged.status', '<>', 'superseded')->selectRaw('1'));
        }
        $this->state($query, $filters['state'] ?? null);

        return $query->select(['c.public_id', 'c.subject', 'c.reference', 'c.status', 'd.decision_number', 'd.decision_date', 'd.meeting_number',
            'd.meeting_date', 'd.closed_at', 's.name as stage', 't.due_at', 't.status as task_status', 'holder.name as holder_name'])
            ->selectRaw("(d.closed_at IS NULL AND t.status = 'open' AND t.due_at < ?) AS overdue", [now()])
            ->selectRaw("(SELECT string_agg(dep.name, '، ' ORDER BY a.id) FROM board_decision_assignments a JOIN departments dep ON dep.id = a.department_id
                WHERE a.board_decision_id = d.id AND a.status <> 'superseded') AS departments")
            ->orderByDesc('d.decision_date')->orderByDesc('d.id');
    }

    /** Counts per follow-up state of the decisions the user may open. */
    public function counts(User $user): array
    {
        $counts = [];
        foreach (['open', 'overdue', 'closed'] as $state) {
            $query = $this->access->visible($user)->join('board_decisions as d', 'd.correspondence_id', '=', 'c.id')->whereNotNull('d.recorded_at')
                ->leftJoin('correspondence_tasks as t', 't.correspondence_id', '=', 'c.id');
            $this->state($query, $state);
            $counts[$state] = $query->count();
        }

        return $counts;
    }

    private function state(Builder $query, ?string $state): void
    {
        match ($state) {
            'open' => $query->whereNull('d.closed_at'),
            'closed' => $query->whereNotNull('d.closed_at'),
            'overdue' => $query->whereNull('d.closed_at')->where('t.status', 'open')->where('t.due_at', '<', now()),
            default => null,
        };
    }

    /** Whether the user may open at least one recorded decision (the link on «المراسلات الرسمية»). */
    public function visibleTo(User $user): bool
    {
        return $this->installed() && $this->access->visible($user)->join('board_decisions as d', 'd.correspondence_id', '=', 'c.id')
            ->whereNotNull('d.recorded_at')->exists();
    }
}
