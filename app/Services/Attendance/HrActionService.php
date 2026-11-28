<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\Authority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «الإجراءات الإدارية»: a confidential register of administrative actions (types from system constants, e.g. «تنبيه»,
 * «لفت نظر»). It is a record reviewed by people, never an automatic engine: no action is created from a late badge,
 * a device error, a provisional correction or an absence, and none has a payroll or legal effect in the system.
 *
 * draft (HR) → in_review → issued or rejected; returned to draft for changes; cancelled with a reason at any stage.
 * Nobody reviews their own entry. A type whose metadata says "decider": "executive" is decided by the executive
 * director only. The employee sees an action only once issued, and may acknowledge it and reply once.
 * Every change appends a snapshot to employee_hr_action_events (append-only by trigger), with the authority the
 * actor used ("authority": executive, hr or author).
 *
 * An action that stands on the executive director's decision (an executive-decided type under review or issued,
 * or any action the executive director issued) is cancelled by the executive director only (TRANSFORM §3.1: a
 * central decision is not undone by the receiving department). The HR head files a cancellation request instead;
 * the executive director then cancels the action or keeps it.
 *
 * An action under review is a task in «مهامي» of whoever may decide it, and one returned for changes is a task of
 * its author (taskQueues). A returned action is a draft again in the record; the screens call it «مُعاد للتعديل».
 */
final class HrActionService
{
    public const STATUS = AttendanceLedger::HR_ACTION_STATUS;

    /** A draft that a reviewer sent back to its author. */
    public const RETURNED_LABEL = 'مُعاد للتعديل';

    /** To the employee: an action issued to them was cancelled. */
    public const EVENT_CANCELLED = 'hr_action_cancelled';

    /** To the executive director: the HR head asks to cancel an action that stands on the director's decision. */
    public const EVENT_CANCEL_REQUEST = 'hr_action_cancel_request';

    /** To whoever asked: the executive director cancelled the action or kept it. */
    public const EVENT_CANCEL_REQUEST_DECIDED = 'hr_action_cancel_request_decided';

    public const EVENTS = ['created' => 'إنشاء مسودة', 'edited' => 'تعديل المسودة', 'submitted' => 'رفع للمراجعة', 'returned' => 'إعادة للتعديل',
        'issued' => 'إصدار', 'rejected' => 'رفض', 'cancelled' => 'إلغاء', 'acknowledged' => 'اطلاع الموظف', 'responded' => 'رد الموظف',
        'cancel_requested' => 'طلب إلغاء من المدير التنفيذي', 'cancel_request_declined' => 'إبقاء الإجراء ورفض طلب الإلغاء'];

    /** @var array<string, bool> reviewer|date => held the executive director's role that day */
    private array $executiveOn = [];

    /** @var array<int, string> type value id => decider */
    private array $deciders = [];

    /** @var array<int, array{executive: bool, decider: bool, manager: bool}> user id => capacities in the register */
    private array $capacityCache = [];

    public function __construct(
        private AttendanceAccess $access,
        private NotificationCenter $notifications,
    ) {}

    public function available(): bool
    {
        return AttendanceLedger::hasTable('employee_hr_actions');
    }

    /**
     * Active action types («أنواع العقوبات» in system constants) with their review rules.
     *
     * @return list<array{id: int, code: string, name: string, decider: string, requires_acknowledgment: bool}>
     */
    public function types(): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'disciplinary_action_types')->where('t.is_active', true)->where('v.is_active', true)
            ->where(fn ($from) => $from->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', $today))
            ->where(fn ($until) => $until->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $today))
            ->orderBy('v.sort_order')->orderBy('v.id')->get(['v.id', 'v.code', 'v.name_ar', 'v.metadata'])
            ->map(function (object $row): array {
                $rules = json_decode((string) $row->metadata, true) ?: [];

                return ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name_ar,
                    'decider' => ($rules['decider'] ?? 'hr') === 'executive' ? 'executive' : 'hr', 'requires_acknowledgment' => (bool) ($rules['requires_acknowledgment'] ?? true)];
            })->all();
    }

    /**
     * Who decides an existing action of this type ("executive" or "hr"), read from the type itself so that a type
     * retired from the constants later still keeps its decider for the actions already made with it.
     */
    public function deciderOf(int $typeValueId): string
    {
        if (! isset($this->deciders[$typeValueId])) {
            $rules = json_decode((string) DB::table('master_data_values')->where('id', $typeValueId)->value('metadata'), true) ?: [];
            $this->deciders[$typeValueId] = ($rules['decider'] ?? 'hr') === 'executive' ? 'executive' : 'hr';
        }

        return $this->deciders[$typeValueId];
    }

    /**
     * @param  array{employee_id: int, type_value_id: int, incident_date: string, attendance_date?: ?string, facts: string, evidence?: ?string}  $input
     */
    public function create(User $user, array $input, ?UploadedFile $file): object
    {
        abort_unless($this->access->canManageHrActions($user), 403);
        $type = $this->type((int) $input['type_value_id']);
        $employee = Employee::query()->whereNull('deleted_at')->findOrFail($input['employee_id']);
        $stored = null;
        try {
            return DB::transaction(function () use ($user, $input, $file, $type, $employee, &$stored): object {
                $id = DB::table('employee_hr_actions')->insertGetId([
                    'employee_id' => $employee->id, 'type_value_id' => $type['id'], 'incident_date' => $input['incident_date'],
                    'attendance_date' => $input['attendance_date'] ?? null, 'facts' => trim($input['facts']), 'evidence' => $input['evidence'] ?? null,
                    'status' => 'draft', 'version' => 1, 'requires_acknowledgment' => $type['requires_acknowledgment'], 'created_by' => $user->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('employee_hr_actions')->where('id', $id)->update(['reference_no' => sprintf('HRA-%s-%05d', now()->format('Y'), $id)]);
                $stored = $this->attach($id, $file);
                $action = DB::table('employee_hr_actions')->where('id', $id)->first();
                $this->event($action, 'created', $user->id, null);

                return $action;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }
    }

    /**
     * A draft is edited as a new version; earlier versions stay in the timeline.
     *
     * @param  array{type_value_id: int, incident_date: string, attendance_date?: ?string, facts: string, evidence?: ?string}  $input
     */
    public function update(User $user, object $action, array $input, ?UploadedFile $file): void
    {
        abort_unless($this->access->canManageHrActions($user), 403);
        $type = $this->type((int) $input['type_value_id']);
        DB::transaction(function () use ($user, $action, $input, $file, $type): void {
            $row = $this->locked($action, ['draft']);
            DB::table('employee_hr_actions')->where('id', $row->id)->update([
                'type_value_id' => $type['id'], 'incident_date' => $input['incident_date'], 'attendance_date' => $input['attendance_date'] ?? null,
                'facts' => trim($input['facts']), 'evidence' => $input['evidence'] ?? null, 'requires_acknowledgment' => $type['requires_acknowledgment'],
                'version' => (int) $row->version + 1, 'updated_at' => now(),
            ]);
            $this->attach((int) $row->id, $file);
            $this->event(DB::table('employee_hr_actions')->where('id', $row->id)->first(), 'edited', $user->id, null);
        });
    }

    public function submit(User $user, object $action): void
    {
        abort_unless($this->access->canManageHrActions($user), 403);
        DB::transaction(function () use ($user, $action): void {
            $row = $this->locked($action, ['draft']);
            // Never park an action that nobody may decide: it stays a draft and the author is told.
            $reviewers = $this->reviewers($row, (int) $user->id);
            if ($reviewers === []) {
                throw ValidationException::withMessages(['status' => 'لا يوجد صاحب قرار مؤهل لهذا الإجراء غير معدّه والموظف المعني به؛ يلزم قرار الإدارة العليا.']);
            }
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['status' => 'in_review', 'submitted_at' => now(), 'updated_at' => now()]);
            $row = DB::table('employee_hr_actions')->where('id', $row->id)->first();
            $this->event($row, 'submitted', $user->id, null);
            $this->notifications->notifyMany($reviewers, 'hr_action_review', ['title' => 'إجراء إداري بانتظار المراجعة '.$row->reference_no,
                'body' => 'راجع الوقائع والأدلة قبل الإصدار أو الرفض.', 'url' => self::url(), 'dedupe' => 'hr-action:'.$row->id.':review:'.$row->version]);
        });
    }

    /**
     * Review decision: issue, reject (reason required) or return to draft (reason required).
     */
    public function decide(User $user, object $action, string $decision, ?string $note): void
    {
        abort_unless($this->access->canDecideHrActions($user), 403);
        if (in_array($decision, ['reject', 'return'], true) && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'اذكر سبب القرار.']);
        }
        DB::transaction(function () use ($user, $action, $decision, $note): void {
            $row = $this->locked($action, ['in_review']);
            abort_if((int) $row->created_by === (int) $user->id, 403, 'لا يراجع معدّ الإجراء إجراءه.');
            abort_if((int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === (int) $user->id, 403, 'لا يقرر الموظف إجراءً إداريًا بحقه.');
            abort_if($this->deciderOf((int) $row->type_value_id) === 'executive' && ! $this->access->isExecutive($user), 403, 'هذا النوع يقرره المدير التنفيذي.');
            $status = ['issue' => 'issued', 'reject' => 'rejected', 'return' => 'draft'][$decision];
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
                'review_note' => $note, 'issued_at' => $status === 'issued' ? now() : null, 'updated_at' => now()]);
            $row = DB::table('employee_hr_actions')->where('id', $row->id)->first();
            $this->event($row, ['issue' => 'issued', 'reject' => 'rejected', 'return' => 'returned'][$decision], $user->id, $note,
                $this->access->isExecutive($user) ? 'executive' : 'hr');
            if ($status === 'issued') {
                $employeeUser = DB::table('employees')->where('id', $row->employee_id)->value('user_id');
                if ($employeeUser) {
                    $this->notifications->notify((int) $employeeUser, 'hr_action_issued', ['title' => 'صدر بحقك إجراء إداري '.$row->reference_no,
                        'body' => 'اطلع عليه في ملفك الوظيفي، ويمكنك تسجيل ردك.', 'url' => '/my/profile?tab=attendance', 'dedupe' => 'hr-action:'.$row->id.':issued']);
                }
            }
        });
    }

    /**
     * Cancels with a reason: the author while a draft or under review (withdrawing their own entry), a decider at
     * any stage (including issued). An action that stands on the executive director's decision (executiveOwned) is
     * cancelled, under review or issued, by the executive director only; the HR head files requestCancel() instead.
     * The employee the action concerns never cancels it.
     */
    public function cancel(User $user, object $action, string $reason): void
    {
        DB::transaction(function () use ($user, $action, $reason): void {
            $row = $this->locked($action, ['draft', 'in_review', 'issued']);
            abort_if((int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === (int) $user->id, 403, 'لا يلغي الموظف إجراءً إداريًا بحقه.');
            $authority = $this->cancelAuthority($user, $row, $this->executiveOwned($row));
            if ($authority === null && $this->access->canDecideHrActions($user)) {
                abort(403, $row->status === 'issued'
                    ? 'صدر هذا الإجراء بقرار المدير التنفيذي، ولا يلغيه إلا هو. ارفع إليه طلب الإلغاء.'
                    : 'هذا الإجراء بانتظار قرار المدير التنفيذي، وله وحده رفضه أو إعادته.');
            }
            abort_unless($authority !== null, 403);
            $pending = $row->status === 'issued' ? $this->pendingCancelRequest((int) $row->id) : null;
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(),
                'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->event(DB::table('employee_hr_actions')->where('id', $row->id)->first(), 'cancelled', $user->id, $reason, $authority);
            // The employee saw the action once it was issued, so they hear that it no longer stands. The reason is
            // an internal note of the register and stays out of the notice.
            $employeeUser = $row->status === 'issued' ? DB::table('employees')->where('id', $row->employee_id)->value('user_id') : null;
            if ($employeeUser && (int) $employeeUser !== (int) $user->id) {
                $this->notifications->notify((int) $employeeUser, self::EVENT_CANCELLED, ['title' => 'أُلغي الإجراء الإداري '.$row->reference_no,
                    'body' => 'أُلغي الإجراء الصادر بحقك ولم يعد قائمًا، ويبقى في السجل للتوثيق فقط.', 'url' => '/my/profile?tab=attendance',
                    'dedupe' => 'hr-action:'.$row->id.':cancelled']);
            }
            if ($pending && (int) $pending->actor_id !== (int) $user->id) {
                $this->notifications->notify((int) $pending->actor_id, self::EVENT_CANCEL_REQUEST_DECIDED, ['title' => 'أُلغي الإجراء الإداري '.$row->reference_no,
                    'body' => 'ألغى المدير التنفيذي الإجراء بناءً على طلبك.', 'url' => self::url(), 'dedupe' => 'hr-action:'.$row->id.':cancel-request:'.$pending->id.':decided']);
            }
        });
    }

    /**
     * The HR head asks the executive director to cancel an issued action that stands on the director's decision.
     * Nothing changes on the action: the request is a timeline entry and a notice to the executive director, who
     * then cancels the action (cancel) or keeps it (declineCancel). One open request at a time.
     */
    public function requestCancel(User $user, object $action, string $reason): void
    {
        abort_unless($this->access->canDecideHrActions($user), 403);
        DB::transaction(function () use ($user, $action, $reason): void {
            $row = $this->locked($action, ['issued']);
            abort_if((int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === (int) $user->id, 403, 'لا يطلب الموظف إلغاء إجراء بحقه.');
            if ($this->access->isExecutive($user) || ! $this->executiveOwned($row)) {
                throw ValidationException::withMessages(['status' => 'يُلغى هذا الإجراء مباشرة ممن يملك قراره، دون طلب.']);
            }
            if ($this->pendingCancelRequest((int) $row->id)) {
                throw ValidationException::withMessages(['status' => 'يوجد طلب إلغاء لهذا الإجراء بانتظار قرار المدير التنفيذي.']);
            }
            $target = (int) DB::table('employees')->where('id', $row->employee_id)->value('user_id');
            $executives = array_values(array_diff($this->executiveIds(), [(int) $user->id, $target]));
            if ($executives === []) {
                throw ValidationException::withMessages(['status' => 'لا يوجد مدير تنفيذي مؤهل لقرار الإلغاء؛ يلزم قرار الإدارة العليا.']);
            }
            $eventId = $this->event($row, 'cancel_requested', $user->id, $reason, 'hr');
            $this->notifications->notifyMany($executives, self::EVENT_CANCEL_REQUEST, ['title' => 'طلب إلغاء إجراء إداري '.$row->reference_no,
                'body' => 'يطلب رئيس الموارد البشرية إلغاء إجراء صدر بقرارك. قرر إلغاءه أو إبقاءه من سجل الإجراءات.', 'url' => self::url(),
                'dedupe' => 'hr-action:'.$row->id.':cancel-request:'.$eventId]);
        });
    }

    /** The executive director keeps the action and closes the open cancellation request, with a reason. */
    public function declineCancel(User $user, object $action, string $note): void
    {
        abort_unless($this->access->isExecutive($user) && $this->access->canDecideHrActions($user), 403);
        DB::transaction(function () use ($user, $action, $note): void {
            $row = $this->locked($action, ['issued']);
            abort_if((int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === (int) $user->id, 403, 'لا يقرر الموظف إجراءً إداريًا بحقه.');
            $pending = $this->pendingCancelRequest((int) $row->id)
                ?? throw ValidationException::withMessages(['status' => 'لا يوجد طلب إلغاء بانتظار القرار.']);
            $this->event($row, 'cancel_request_declined', $user->id, $note, 'executive');
            $this->notifications->notify((int) $pending->actor_id, self::EVENT_CANCEL_REQUEST_DECIDED, ['title' => 'أبقى المدير التنفيذي الإجراء الإداري '.$row->reference_no,
                'body' => 'رُفض طلب الإلغاء وبقي الإجراء قائمًا. السبب في سجل الإجراء.', 'url' => self::url(), 'dedupe' => 'hr-action:'.$row->id.':cancel-request:'.$pending->id.':decided']);
        });
    }

    /**
     * What this user may do about cancelling the row (for the register's buttons; cancel(), requestCancel() and
     * declineCancel() check the same rules).
     *
     * @return array{cancel: bool, request_cancel: bool, decline_cancel: bool}
     */
    public function cancelOptions(User $user, object $row, bool $executiveOwned, bool $cancelRequested): array
    {
        $open = in_array($row->status, ['draft', 'in_review', 'issued'], true);
        $target = (int) ($row->employee_user_id ?? 0) === (int) $user->id;
        ['executive' => $executive, 'decider' => $decider] = $this->capacities($user);

        return [
            'cancel' => $open && ! $target && $this->cancelAuthority($user, $row, $executiveOwned) !== null,
            'request_cancel' => $row->status === 'issued' && ! $target && $decider && ! $executive && $executiveOwned && ! $cancelRequested,
            'decline_cancel' => $row->status === 'issued' && ! $target && $decider && $executive && $cancelRequested,
        ];
    }

    public function acknowledge(User $user, object $action): void
    {
        DB::transaction(function () use ($user, $action): void {
            $row = $this->locked($action, ['issued']);
            $this->ensureSelf($user, $row);
            if ($row->acknowledged_at) {
                return;
            }
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['acknowledged_at' => now(), 'updated_at' => now()]);
            $this->event(DB::table('employee_hr_actions')->where('id', $row->id)->first(), 'acknowledged', $user->id, null);
        });
    }

    public function respond(User $user, object $action, string $response): void
    {
        DB::transaction(function () use ($user, $action, $response): void {
            $row = $this->locked($action, ['issued']);
            $this->ensureSelf($user, $row);
            if ($row->responded_at) {
                throw ValidationException::withMessages(['response' => 'سُجل ردك على هذا الإجراء مسبقًا.']);
            }
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['employee_response' => trim($response), 'responded_at' => now(),
                'acknowledged_at' => $row->acknowledged_at ?? now(), 'updated_at' => now()]);
            $this->event(DB::table('employee_hr_actions')->where('id', $row->id)->first(), 'responded', $user->id, null);
        });
    }

    /**
     * Register rows for HR (all) or for the employee themself (issued only, without internal review notes). For the
     * register, each row also says who decides it, whether it stands on the executive director's decision, its open
     * cancellation request, and — when $viewer is given — the cancellation buttons that viewer may use.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?int $employeeId, bool $forEmployee, int $limit = 200, ?User $viewer = null): array
    {
        if (! $this->available()) {
            return [];
        }
        $tz = config('app.timezone');
        $local = fn (mixed $value): ?string => $value ? CarbonImmutable::parse($value)->setTimezone($tz)->format('Y-m-d H:i') : null;
        $rows = DB::table('employee_hr_actions as a')->join('master_data_values as t', 't.id', '=', 'a.type_value_id')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('users as c', 'c.id', '=', 'a.created_by')->leftJoin('users as r', 'r.id', '=', 'a.reviewed_by')
            ->when($employeeId !== null, fn ($query) => $query->where('a.employee_id', $employeeId))
            ->when($forEmployee, fn ($query) => $query->where('a.status', 'issued'))
            ->orderByDesc('a.id')->limit($limit)
            ->get(['a.*', 't.name_ar as type_name', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'e.employee_number', 'e.user_id as employee_user_id',
                'c.name as created_by_name', 'r.name as reviewed_by_name']);
        $events = $forEmployee || $rows->isEmpty() ? collect() : DB::table('employee_hr_action_events as ev')->leftJoin('users as u', 'u.id', '=', 'ev.actor_id')
            ->whereIn('ev.hr_action_id', $rows->pluck('id'))->orderBy('ev.id')
            ->get(['ev.id', 'ev.hr_action_id', 'ev.version', 'ev.event', 'ev.note', 'ev.created_at', 'u.name as actor', DB::raw("ev.snapshot->>'authority' as authority")])
            ->groupBy('hr_action_id');

        return $rows->map(function (object $row) use ($forEmployee, $events, $local, $viewer): array {
            $timeline = $events[$row->id] ?? collect();
            $governance = [];
            if (! $forEmployee) {
                $request = $timeline->filter(fn (object $event): bool => in_array($event->event, ['cancel_requested', 'cancel_request_declined'], true))->last();
                $request = $request?->event === 'cancel_requested' && $row->status === 'issued' ? $request : null;
                $owned = $this->executiveOwned($row, $timeline->where('event', 'issued')->last()?->authority ?? '');
                $governance = ['decider' => $this->deciderOf((int) $row->type_value_id), 'executive_owned' => $owned,
                    'cancel_request' => $request ? ['by' => $request->actor, 'reason' => $request->note, 'at' => $local($request->created_at)] : null,
                    'can_cancel' => $viewer ? $this->cancelOptions($viewer, $row, $owned, $request !== null) : null];
            }

            return $governance + $this->row($row, $forEmployee, $timeline, $local);
        })->all();
    }

    /**
     * @param  Collection<int, object>  $timeline
     * @return array<string, mixed>
     */
    private function row(object $row, bool $forEmployee, Collection $timeline, \Closure $local): array
    {
        return [
            'id' => (int) $row->id, 'reference_no' => $row->reference_no, 'employee_id' => (int) $row->employee_id, 'employee_name' => AttendanceLedger::fullName($row),
            'employee_number' => $row->employee_number, 'type_value_id' => (int) $row->type_value_id, 'type' => $row->type_name,
            'incident_date' => (string) $row->incident_date, 'attendance_date' => $row->attendance_date ? (string) $row->attendance_date : null,
            'facts' => $row->facts, 'evidence' => $forEmployee ? null : $row->evidence, 'status' => $row->status,
            'returned' => self::isReturned($row), 'status_label' => self::isReturned($row) ? self::RETURNED_LABEL : (self::STATUS[$row->status] ?? $row->status),
            'version' => (int) $row->version, 'requires_acknowledgment' => (bool) $row->requires_acknowledgment,
            'has_attachment' => $row->attachment_path !== null, 'attachment_name' => $row->attachment_name,
            'created_by' => (int) $row->created_by, 'created_by_name' => $forEmployee ? null : $row->created_by_name,
            'reviewed_by_name' => $forEmployee ? null : $row->reviewed_by_name, 'review_note' => $forEmployee ? null : $row->review_note,
            'issued_at' => $local($row->issued_at), 'cancel_reason' => $forEmployee ? null : $row->cancel_reason,
            'acknowledged_at' => $local($row->acknowledged_at), 'employee_response' => $row->employee_response, 'responded_at' => $local($row->responded_at),
            'timeline' => $timeline->map(fn (object $event): array => ['event' => self::EVENTS[$event->event] ?? $event->event,
                'version' => (int) $event->version, 'note' => $event->note, 'actor' => $event->actor, 'at' => $local($event->created_at)])->values()->all(),
        ];
    }

    public static function url(): string
    {
        return '/admin/attendance?tab=actions';
    }

    /**
     * A draft that came back from review: decide('return') leaves the reviewer and the review time on it, and a
     * draft that was never reviewed has neither.
     */
    public static function isReturned(object $row): bool
    {
        return $row->status === 'draft' && $row->reviewed_at !== null;
    }

    /**
     * What waits for this user in the register, as queries on "employee_hr_actions as a" without columns or order:
     * "review" holds the actions decide() would accept from them (under review, not their own, not about them, and
     * not a type reserved for the executive director unless they are the executive director); "returned" holds their own
     * drafts sent back for changes. A key is null when the user has nothing to do there, and the result is null
     * for anyone who cannot open the register (the tab lives in «الحضور والانصراف»).
     *
     * @return array{review: ?Builder, returned: ?Builder}|null
     */
    public function taskQueues(User $user): ?array
    {
        if (! $this->available() || ! $this->access->canViewLedger($user) || ! $this->access->canViewHrActions($user)) {
            return null;
        }
        $review = null;
        if ($this->access->canDecideHrActions($user)) {
            $review = DB::table('employee_hr_actions as a')->where('a.status', 'in_review')->where('a.created_by', '<>', $user->id)
                ->whereNotExists(fn ($target) => $target->from('employees as e')->whereColumn('e.id', 'a.employee_id')->where('e.user_id', $user->id));
            if (! $this->access->isExecutive($user)) {
                // Read from the type itself (deciderOf), so a type retired from the constants keeps its decider.
                $review->whereNotExists(fn ($type) => $type->from('master_data_values as tv')->whereColumn('tv.id', 'a.type_value_id')
                    ->whereRaw("tv.metadata->>'decider' = 'executive'"));
            }
        }
        $returned = $this->access->canManageHrActions($user)
            ? DB::table('employee_hr_actions as a')->where('a.status', 'draft')->whereNotNull('a.reviewed_at')->where('a.created_by', $user->id)
            : null;

        return ['review' => $review, 'returned' => $returned];
    }

    /**
     * @return array{id: int, code: string, name: string, decider: string, requires_acknowledgment: bool}
     */
    private function type(int $id): array
    {
        return collect($this->types())->firstWhere('id', $id)
            ?? throw ValidationException::withMessages(['type_value_id' => 'نوع الإجراء غير متاح في ثوابت النظام.']);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function locked(object $action, array $statuses): object
    {
        $row = DB::table('employee_hr_actions')->where('id', $action->id)->lockForUpdate()->first();
        abort_unless($row, 404);
        if (! in_array($row->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'لا يتاح هذا الإجراء في حالة «'.(self::STATUS[$row->status] ?? $row->status).'».']);
        }

        return $row;
    }

    private function ensureSelf(User $user, object $row): void
    {
        abort_unless((int) DB::table('employees')->where('id', $row->employee_id)->value('user_id') === (int) $user->id, 403);
    }

    private function attach(int $id, ?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }
        $path = $file->storeAs('hr-actions/'.$id, 'evidence-'.bin2hex(random_bytes(6)).'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');
        DB::table('employee_hr_actions')->where('id', $id)->update(['attachment_path' => $path, 'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'attachment_mime' => $file->getMimeType(), 'attachment_size' => $file->getSize()]);

        return $path;
    }

    /**
     * People who may decide this action, except its author and the employee it concerns. A type reserved for the
     * executive director (decide()) leaves out the HR head.
     *
     * @return list<int>
     */
    private function reviewers(object $row, int $authorId): array
    {
        $executiveOnly = $this->deciderOf((int) $row->type_value_id) === 'executive';
        $hrHead = $executiveOnly ? 0 : (int) DB::table('departments')->where('code', 'HR')->where('is_active', true)->value('head_user_id');
        $target = (int) DB::table('employees')->where('id', $row->employee_id)->value('user_id');

        return array_values(array_unique(array_diff(array_filter([...$this->executiveIds(), $hrHead]), [$authorId, $target])));
    }

    /** @return list<int> the executive director in office (Organization\Authority) */
    private function executiveIds(): array
    {
        return app(Authority::class)->executiveIds();
    }

    /**
     * The authority under which this user may cancel the row now, or null: the executive director (any open row),
     * the author withdrawing their own unissued entry, or the HR decider — except for a row that stands on the
     * executive director's decision once it is under review or issued.
     */
    private function cancelAuthority(User $user, object $row, bool $executiveOwned): ?string
    {
        ['executive' => $executive, 'decider' => $decider, 'manager' => $manager] = $this->capacities($user);
        if ($decider && $executive) {
            return 'executive';
        }
        if ($row->status !== 'issued' && (int) $row->created_by === (int) $user->id && $manager) {
            return 'author';
        }
        if (! $decider) {
            return null;
        }

        return $executiveOwned && $row->status !== 'draft' ? null : 'hr';
    }

    /**
     * @return array{executive: bool, decider: bool, manager: bool}
     */
    private function capacities(User $user): array
    {
        return $this->capacityCache[(int) $user->id] ??= ['executive' => $this->access->isExecutive($user),
            'decider' => $this->access->canDecideHrActions($user), 'manager' => $this->access->canManageHrActions($user)];
    }

    /**
     * Whether the action stands on the executive director's decision: its type is decided by the executive director,
     * or the executive director issued it. The issuing authority is read from the issue event; an action issued
     * before that was recorded counts as the director's when its reviewer held the role on the day of the review.
     *
     * @param  ?string  $issuedAuthority  the "authority" of its issue event, when already loaded
     */
    public function executiveOwned(object $row, ?string $issuedAuthority = null): bool
    {
        if ($this->deciderOf((int) $row->type_value_id) === 'executive') {
            return true;
        }
        if ($row->status !== 'issued' || ! $row->reviewed_by) {
            return false;
        }
        $issuedAuthority ??= DB::table('employee_hr_action_events')->where('hr_action_id', $row->id)->where('event', 'issued')
            ->orderByDesc('id')->value(DB::raw("snapshot->>'authority'"));
        if ($issuedAuthority !== null && $issuedAuthority !== '') {
            return $issuedAuthority === 'executive';
        }
        $date = CarbonImmutable::parse($row->reviewed_at ?? $row->issued_at)->setTimezone(config('app.timezone'))->toDateString();

        return $this->executiveOn[$row->reviewed_by.'|'.$date] ??= DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $row->reviewed_by)->where('r.code', 'executive_director')
            ->where(fn ($from) => $from->whereNull('a.starts_on')->orWhere('a.starts_on', '<=', $date))
            ->where(fn ($until) => $until->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $date))->exists();
    }

    /** The open cancellation request of an action (its latest request not yet declined), if any. */
    private function pendingCancelRequest(int $actionId): ?object
    {
        $last = DB::table('employee_hr_action_events')->where('hr_action_id', $actionId)->whereIn('event', ['cancel_requested', 'cancel_request_declined'])
            ->orderByDesc('id')->first(['id', 'event', 'actor_id', 'note', 'created_at']);

        return $last?->event === 'cancel_requested' ? $last : null;
    }

    private function event(object $row, string $event, int $actorId, ?string $note, ?string $authority = null): int
    {
        $snapshot = array_intersect_key((array) $row, array_flip(['employee_id', 'type_value_id', 'incident_date', 'attendance_date', 'facts', 'evidence',
            'status', 'version', 'attachment_name', 'review_note', 'cancel_reason', 'acknowledged_at', 'employee_response']));

        return (int) DB::table('employee_hr_action_events')->insertGetId([
            'hr_action_id' => $row->id, 'version' => (int) $row->version, 'event' => $event, 'actor_id' => $actorId, 'note' => $note,
            'snapshot' => json_encode($snapshot + ($authority !== null ? ['authority' => $authority] : []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
