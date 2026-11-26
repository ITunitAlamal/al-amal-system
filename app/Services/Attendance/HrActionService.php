<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
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
 * Every change appends a snapshot to employee_hr_action_events (append-only by trigger).
 */
final class HrActionService
{
    public const STATUS = AttendanceLedger::HR_ACTION_STATUS;

    public const EVENTS = ['created' => 'إنشاء مسودة', 'edited' => 'تعديل المسودة', 'submitted' => 'رفع للمراجعة', 'returned' => 'إعادة للتعديل',
        'issued' => 'إصدار', 'rejected' => 'رفض', 'cancelled' => 'إلغاء', 'acknowledged' => 'اطلاع الموظف', 'responded' => 'رد الموظف'];

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
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['status' => 'in_review', 'submitted_at' => now(), 'updated_at' => now()]);
            $row = DB::table('employee_hr_actions')->where('id', $row->id)->first();
            $this->event($row, 'submitted', $user->id, null);
            $reviewers = $this->reviewers($row, (int) $user->id);
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
            $type = collect($this->types())->firstWhere('id', (int) $row->type_value_id);
            abort_if(($type['decider'] ?? 'hr') === 'executive' && ! $this->access->isExecutive($user), 403, 'هذا النوع يقرره المدير التنفيذي.');
            $status = ['issue' => 'issued', 'reject' => 'rejected', 'return' => 'draft'][$decision];
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
                'review_note' => $note, 'issued_at' => $status === 'issued' ? now() : null, 'updated_at' => now()]);
            $row = DB::table('employee_hr_actions')->where('id', $row->id)->first();
            $this->event($row, ['issue' => 'issued', 'reject' => 'rejected', 'return' => 'returned'][$decision], $user->id, $note);
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
     * Cancels with a reason: the author while a draft or under review, a decider at any stage (including issued).
     */
    public function cancel(User $user, object $action, string $reason): void
    {
        DB::transaction(function () use ($user, $action, $reason): void {
            $row = $this->locked($action, ['draft', 'in_review', 'issued']);
            $author = (int) $row->created_by === (int) $user->id && $this->access->canManageHrActions($user) && $row->status !== 'issued';
            abort_unless($author || $this->access->canDecideHrActions($user), 403);
            DB::table('employee_hr_actions')->where('id', $row->id)->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(),
                'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->event(DB::table('employee_hr_actions')->where('id', $row->id)->first(), 'cancelled', $user->id, $reason);
        });
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
     * Register rows for HR (all) or for the employee themself (issued only, without internal review notes).
     *
     * @return list<array<string, mixed>>
     */
    public function list(?int $employeeId, bool $forEmployee, int $limit = 200): array
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
            ->get(['a.*', 't.name_ar as type_name', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'e.employee_number', 'c.name as created_by_name', 'r.name as reviewed_by_name']);
        $events = $forEmployee || $rows->isEmpty() ? collect() : DB::table('employee_hr_action_events as ev')->leftJoin('users as u', 'u.id', '=', 'ev.actor_id')
            ->whereIn('ev.hr_action_id', $rows->pluck('id'))->orderBy('ev.id')->get(['ev.hr_action_id', 'ev.version', 'ev.event', 'ev.note', 'ev.created_at', 'u.name as actor'])
            ->groupBy('hr_action_id');

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id, 'reference_no' => $row->reference_no, 'employee_id' => (int) $row->employee_id, 'employee_name' => AttendanceLedger::fullName($row),
            'employee_number' => $row->employee_number, 'type_value_id' => (int) $row->type_value_id, 'type' => $row->type_name,
            'incident_date' => (string) $row->incident_date, 'attendance_date' => $row->attendance_date ? (string) $row->attendance_date : null,
            'facts' => $row->facts, 'evidence' => $forEmployee ? null : $row->evidence, 'status' => $row->status, 'status_label' => self::STATUS[$row->status] ?? $row->status,
            'version' => (int) $row->version, 'requires_acknowledgment' => (bool) $row->requires_acknowledgment,
            'has_attachment' => $row->attachment_path !== null, 'attachment_name' => $row->attachment_name,
            'created_by' => (int) $row->created_by, 'created_by_name' => $forEmployee ? null : $row->created_by_name,
            'reviewed_by_name' => $forEmployee ? null : $row->reviewed_by_name, 'review_note' => $forEmployee ? null : $row->review_note,
            'issued_at' => $local($row->issued_at), 'cancel_reason' => $forEmployee ? null : $row->cancel_reason,
            'acknowledged_at' => $local($row->acknowledged_at), 'employee_response' => $row->employee_response, 'responded_at' => $local($row->responded_at),
            'timeline' => ($events[$row->id] ?? collect())->map(fn (object $event): array => ['event' => self::EVENTS[$event->event] ?? $event->event,
                'version' => (int) $event->version, 'note' => $event->note, 'actor' => $event->actor, 'at' => $local($event->created_at)])->values()->all(),
        ])->all();
    }

    public static function url(): string
    {
        return '/admin/attendance?tab=actions';
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
     * People who may decide this action, except its author.
     *
     * @return list<int>
     */
    private function reviewers(object $row, int $authorId): array
    {
        $executive = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', 'executive_director')->where('u.is_active', true)->pluck('a.user_id')->map(fn ($id): int => (int) $id)->all();
        $hrHead = (int) DB::table('departments')->where('code', 'HR')->where('is_active', true)->value('head_user_id');

        return array_values(array_unique(array_diff(array_filter([...$executive, $hrHead]), [$authorId])));
    }

    private function event(object $row, string $event, int $actorId, ?string $note): void
    {
        DB::table('employee_hr_action_events')->insert([
            'hr_action_id' => $row->id, 'version' => (int) $row->version, 'event' => $event, 'actor_id' => $actorId, 'note' => $note,
            'snapshot' => json_encode(array_intersect_key((array) $row, array_flip(['employee_id', 'type_value_id', 'incident_date', 'attendance_date', 'facts', 'evidence',
                'status', 'version', 'attachment_name', 'review_note', 'cancel_reason', 'acknowledged_at', 'employee_response'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
