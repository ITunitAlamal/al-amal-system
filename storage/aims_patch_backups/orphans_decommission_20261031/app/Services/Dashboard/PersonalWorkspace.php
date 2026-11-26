<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\OrphanFollowups;
use App\Services\Workflow\WorkflowSubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The personal parts of «مساحتي» beyond requests and tasks: what needs my action now, my official correspondence,
 * my calendar and my recent activity. Everything is read from the live records the user may see; nothing here
 * changes an official record.
 */
final class PersonalWorkspace
{
    /** Arabic labels of correspondence states (same wording as the correspondence register). */
    private const CORRESPONDENCE_STATUS = ['draft' => 'مسودة', 'issued' => 'صادرة', 'processing' => 'قيد المتابعة', 'processed' => 'تم التنفيذ',
        'closed' => 'مغلقة', 'archived' => 'مؤرشفة', 'withdrawn' => 'مسحوبة'];

    private const CALENDAR_DAYS = 14;

    public function __construct(
        private readonly PersonalTasks $personalTasks,
        private readonly NotificationCenter $notifications,
        private readonly WorkflowSubjects $subjects,
    ) {}

    /**
     * Counters of what waits for me now, each with the list it opens. «بانتظار إجرائي» is my own work; returned
     * requests wait for me as their author.
     *
     * @param  array{tasks: int, overdue: int}  $stats
     * @return list<array{key: string, label: string, value: int, detail: ?string, url: string, tone: string}>
     */
    public function actionCenter(User $user, array $stats): array
    {
        $returned = DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.requested_by', $user->id)->where('i.status', 'in_progress')->where('s.assignment_type', 'requester')->count();
        $correspondence = $this->correspondenceTasks($user);
        $todo = $this->personalTasks->summary($user, 0);
        $employeeId = DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->value('id');
        $swapsAwaiting = $employeeId && Schema::hasTable('attendance_shift_swaps')
            ? DB::table('attendance_shift_swaps')->where('counterpart_employee_id', $employeeId)->where('status', 'awaiting_counterpart')->count() : 0;
        $swapItem = $swapsAwaiting > 0 ? [['key' => 'swaps', 'label' => 'طلبات تبديل مناوبة بانتظار ردي', 'value' => $swapsAwaiting, 'detail' => null,
            'url' => route('my.profile', ['tab' => 'attendance'], false), 'tone' => 'warning']] : [];
        $field = app(OrphanFollowups::class)->workload($user);
        $fieldItem = $field['visits'] + $field['followups'] > 0 ? [['key' => 'orphans_field', 'label' => 'زيارات ومتابعات أيتام مسندة إليّ', 'value' => $field['visits'] + $field['followups'],
            'detail' => $field['overdue'] ? 'متأخرة: '.$field['overdue'] : null, 'url' => route('orphans.index', ['tab' => 'mine'], false),
            'tone' => $field['overdue'] ? 'danger' : 'warning']] : [];

        return [...$swapItem, ...$fieldItem,
            ['key' => 'tasks', 'label' => 'مهام رسمية بانتظار إجرائي', 'value' => $stats['tasks'], 'detail' => $stats['overdue'] ? 'منها متأخرة: '.$stats['overdue'] : null,
                'url' => route('tasks.index', [], false), 'tone' => $stats['overdue'] ? 'danger' : ($stats['tasks'] ? 'warning' : 'muted')],
            ['key' => 'returned', 'label' => 'طلباتي المعادة للاستكمال', 'value' => $returned, 'detail' => null,
                'url' => route('requests.index', ['status' => 'returned'], false), 'tone' => $returned ? 'warning' : 'muted'],
            ['key' => 'correspondence', 'label' => 'مراسلات تنتظر إجرائي', 'value' => $correspondence['pending'], 'detail' => $correspondence['unread'] ? 'غير مقروءة: '.$correspondence['unread'] : null,
                'url' => route('correspondence.index', ['box' => 'needs_action'], false), 'tone' => $correspondence['pending'] ? 'warning' : 'muted'],
            ['key' => 'todo', 'label' => 'مهامي الشخصية المستحقة', 'value' => $todo['today'] + $todo['overdue'], 'detail' => $todo['overdue'] ? 'متأخرة: '.$todo['overdue'] : null,
                'url' => route('todo.index', ['filter' => $todo['overdue'] ? 'overdue' : 'today'], false), 'tone' => $todo['overdue'] ? 'danger' : ($todo['today'] ? 'info' : 'muted')],
            ['key' => 'notifications', 'label' => 'إشعارات غير مقروءة', 'value' => $this->notifications->unreadCount($user), 'detail' => null,
                'url' => route('notifications.index', ['filter' => 'unread'], false), 'tone' => 'info'],
        ];
    }

    /**
     * My official correspondence: what waits for my action, what is unread, and the latest items I sent or received.
     *
     * @return array{available: bool, pending: int, unread: int, overdue: int, outgoing: int, items: list<array<string, mixed>>}
     */
    public function correspondence(User $user): array
    {
        if (! Schema::hasTable('official_correspondences')) {
            return ['available' => false, 'pending' => 0, 'unread' => 0, 'overdue' => 0, 'outgoing' => 0, 'items' => []];
        }
        $tasks = $this->correspondenceTasks($user);
        $mine = fn (Builder $q) => $q->where('c.recipient_id', $user->id)->orWhere('c.author_id', $user->id)
            ->orWhereExists(fn (Builder $t) => $t->from('correspondence_tasks as ct')->whereColumn('ct.correspondence_id', 'c.id')->where('ct.assigned_to', $user->id));
        $items = app(CorrespondenceAccess::class)->visible($user)->where($mine)
            ->select(['c.public_id', 'c.reference', 'c.subject', 'c.status', 'c.due_at', 'c.author_id', 'c.updated_at'])
            ->selectSub(DB::table('correspondence_tasks as ut')->whereColumn('ut.correspondence_id', 'c.id')
                ->where('ut.assigned_to', $user->id)->where('ut.status', 'open')->whereNull('ut.read_at')
                ->whereIn('ut.action_id', app(CorrespondenceAccess::class)->accessQuery($user)->select('access.action_id'))
                ->selectRaw('COUNT(*) > 0'), 'unread')
            ->orderByDesc('c.updated_at')->limit(5)->get();

        return [
            'available' => true, 'pending' => $tasks['pending'], 'unread' => $tasks['unread'], 'overdue' => $tasks['overdue'],
            'outgoing' => app(CorrespondenceAccess::class)->mailbox($user, 'sent')->whereIn('c.status', ['issued', 'processing', 'processed'])->count(),
            'items' => $items->map(fn (object $row): array => [
                'reference' => $row->reference, 'subject' => Str::limit((string) $row->subject, 90), 'direction' => (int) $row->author_id === (int) $user->id ? 'صادرة' : 'واردة',
                'status' => self::CORRESPONDENCE_STATUS[$row->status] ?? 'قيد المتابعة', 'due_at' => $row->due_at, 'unread' => (bool) $row->unread,
                'url' => route('correspondence.show', ['publicId' => $row->public_id], false),
            ])->all(),
        ];
    }

    /**
     * The next two weeks: my leave, official holidays, deadlines of my official tasks and correspondence, my field
     * visits and case follow-ups, and my personal items — each labelled with where it comes from.
     *
     * @return list<array{date: string, end: ?string, title: string, source: string, url: ?string, tone: string}>
     */
    public function calendar(User $user): array
    {
        $from = now()->startOfDay();
        $to = now()->addDays(self::CALENDAR_DAYS)->endOfDay();
        $events = [];
        $employeeId = DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->value('id');
        if ($employeeId) {
            foreach (DB::table('employee_leave_requests as l')->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
                ->where('l.employee_id', $employeeId)->whereIn('l.status', ['submitted', 'manager_approved', 'pending_registration', 'approved'])
                ->where('l.start_date', '<=', $to->toDateString())->where('l.end_date', '>=', $from->toDateString())
                ->get(['l.id', 'l.start_date', 'l.end_date', 'l.status', 't.name_ar']) as $leave) {
                $approved = in_array($leave->status, ['approved', 'pending_registration'], true);
                $events[] = ['date' => (string) $leave->start_date, 'end' => (string) $leave->end_date, 'title' => ($leave->name_ar ?? 'إجازة'),
                    'source' => $approved ? 'إجازة معتمدة' : 'إجازة قيد الاعتماد', 'url' => route('leaves.show', ['leave' => $leave->id], false), 'tone' => $approved ? 'success' : 'warning'];
            }
        }
        if ($employeeId && Schema::hasTable('attendance_shift_swaps')) {
            $attendance = route('my.profile', ['tab' => 'attendance'], false);
            foreach (DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
                ->where('r.employee_id', $employeeId)->whereIn('r.status', ['submitted', 'approved'])
                ->whereBetween('r.work_date', [$from->toDateString(), $to->toDateString()])->get(['r.work_date', 'r.status', 't.name_ar']) as $permission) {
                $events[] = ['date' => (string) $permission->work_date, 'end' => null, 'title' => $permission->name_ar,
                    'source' => $permission->status === 'approved' ? 'إذن معتمد' : 'إذن قيد الاعتماد', 'url' => $attendance, 'tone' => $permission->status === 'approved' ? 'success' : 'warning'];
            }
            foreach (DB::table('attendance_shift_swaps')->where(fn ($query) => $query->where('requester_employee_id', $employeeId)->orWhere('counterpart_employee_id', $employeeId))
                ->whereIn('status', ['awaiting_counterpart', 'pending_approval', 'approved'])->get(['status', 'days']) as $swap) {
                foreach (json_decode((string) $swap->days, true) ?: [] as $day) {
                    if ($day['date'] >= $from->toDateString() && $day['date'] <= $to->toDateString()) {
                        $events[] = ['date' => $day['date'], 'end' => null, 'title' => 'تبديل مناوبة', 'source' => $swap->status === 'approved' ? 'تبديل معتمد' : 'تبديل قيد الإجراء',
                            'url' => $attendance, 'tone' => $swap->status === 'approved' ? 'success' : 'warning'];
                    }
                }
            }
            foreach (DB::table('attendance_overrides')->where('employee_id', $employeeId)->where('status', 'approved')->whereNull('revoked_at')->whereNull('swap_id')
                ->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get(['starts_on', 'ends_on', 'label']) as $change) {
                $events[] = ['date' => (string) max($change->starts_on, $from->toDateString()), 'end' => (string) $change->ends_on, 'title' => $change->label,
                    'source' => 'تغيير على جدول دوامي', 'url' => $attendance, 'tone' => 'info'];
            }
        }
        foreach (DB::table('leave_holidays')->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])->get(['holiday_date', 'name_ar']) as $holiday) {
            $events[] = ['date' => (string) $holiday->holiday_date, 'end' => null, 'title' => $holiday->name_ar, 'source' => 'عطلة رسمية', 'url' => null, 'tone' => 'info'];
        }
        foreach (DB::table('workflow_participants as p')->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('p.user_id', $user->id)->where('p.participation_type', 'assignee')->where('p.is_active', true)
            ->where('i.status', 'in_progress')->whereBetween('i.due_at', [$from, $to])
            ->get(['i.public_id', 'i.subject_type', 'i.subject_id', 'i.reference_no', 'i.due_at', 's.name']) as $task) {
            $events[] = ['date' => (string) $task->due_at, 'end' => null, 'title' => $task->name.' · '.$task->reference_no, 'source' => 'موعد مهمة رسمية',
                'url' => $this->subjects->url($task), 'tone' => 'warning'];
        }
        if (Schema::hasTable('correspondence_tasks')) {
            foreach (DB::table('correspondence_tasks as ct')->join('official_correspondences as c', 'c.id', '=', 'ct.correspondence_id')
                ->where('ct.assigned_to', $user->id)->whereNull('ct.completed_at')->whereBetween('ct.due_at', [$from, $to])
                ->get(['c.public_id', 'c.reference', 'ct.due_at']) as $task) {
                $events[] = ['date' => (string) $task->due_at, 'end' => null, 'title' => 'مراسلة '.$task->reference, 'source' => 'موعد مراسلة',
                    'url' => route('correspondence.show', ['publicId' => $task->public_id], false), 'tone' => 'warning'];
            }
        }
        array_push($events, ...app(OrphanFollowups::class)->calendar($user, $from->toDateString(), $to->toDateString()));
        foreach (DB::table('personal_tasks')->where('user_id', $user->id)->whereNull('deleted_at')->whereNull('archived_at')->where('status', '<>', 'done')
            ->whereBetween('due_at', [$from, $to])->get(['title', 'due_at']) as $task) {
            $events[] = ['date' => (string) $task->due_at, 'end' => null, 'title' => Str::limit($task->title, 60), 'source' => 'مهمة شخصية',
                'url' => route('todo.index', [], false), 'tone' => 'muted'];
        }
        usort($events, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return array_slice($events, 0, 20);
    }

    /**
     * My latest actions on requests I handled and on my own To-Do items.
     *
     * @return list<array{at: string, label: string, target: string, url: ?string}>
     */
    public function activity(User $user, int $limit = 8): array
    {
        $workflow = DB::table('workflow_events as e')
            ->join('workflow_instances as i', 'i.id', '=', 'e.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_transitions as t', 't.id', '=', 'e.workflow_transition_id')
            ->where('e.actor_id', $user->id)
            ->where(fn (Builder $q) => $q->whereNotNull('e.workflow_transition_id')->orWhereIn('e.action', ['cancel', 'hold', 'resume', 'reopen']))
            ->orderByDesc('e.occurred_at')->limit($limit)
            ->get(['e.occurred_at', 'e.action', 't.name as transition', 'd.name as workflow', 'i.reference_no', 'i.public_id', 'i.subject_type', 'i.subject_id'])
            ->map(fn (object $row): array => ['at' => (string) $row->occurred_at,
                'label' => $row->transition ?? ['cancel' => 'ألغيت الطلب', 'hold' => 'علّقت المعاملة', 'resume' => 'استأنفت المعاملة', 'reopen' => 'أعدت فتح المعاملة'][$row->action] ?? $row->action,
                'target' => $row->workflow.' · '.$row->reference_no, 'url' => $this->subjects->url($row)]);
        $personal = DB::table('personal_task_events as pe')->join('personal_tasks as pt', 'pt.id', '=', 'pe.personal_task_id')
            ->where('pe.user_id', $user->id)->whereIn('pe.action', ['created', 'completed', 'reopened'])->whereNull('pt.deleted_at')
            ->orderByDesc('pe.created_at')->limit($limit)->get(['pe.created_at', 'pe.action', 'pt.title'])
            ->map(fn (object $row): array => ['at' => (string) $row->created_at,
                'label' => ['created' => 'أضفت مهمة شخصية', 'completed' => 'أنجزت مهمة شخصية', 'reopened' => 'أعدت فتح مهمة شخصية'][$row->action],
                'target' => Str::limit($row->title, 60), 'url' => route('todo.index', [], false)]);

        return $workflow->concat($personal)->sortByDesc('at')->take($limit)->values()->all();
    }

    /**
     * @return array{pending: int, unread: int, overdue: int}
     */
    private function correspondenceTasks(User $user): array
    {
        if (! Schema::hasTable('correspondence_tasks')) {
            return ['pending' => 0, 'unread' => 0, 'overdue' => 0];
        }
        $access = app(CorrespondenceAccess::class);
        $open = DB::table('correspondence_tasks')->where('assigned_to', $user->id)->where('status', 'open')
            ->whereIn('correspondence_id', $access->visible($user)->select('c.id'))
            ->whereIn('action_id', $access->accessQuery($user)->select('access.action_id'));

        return ['pending' => (clone $open)->count(), 'unread' => (clone $open)->whereNull('read_at')->count(),
            'overdue' => (clone $open)->where('due_at', '<', now())->count()];
    }
}
