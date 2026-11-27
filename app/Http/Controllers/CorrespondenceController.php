<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Correspondence\BoardDecisions;
use App\Services\Correspondence\ConfidentialGrants;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Correspondence\CorrespondenceEditions;
use App\Services\Correspondence\CorrespondenceInbox;
use App\Services\Correspondence\CorrespondenceRegistry;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CorrespondenceController extends Controller
{
    public function __construct(private readonly CorrespondenceAccess $access, private readonly CorrespondenceRegistry $registry, private readonly AuditService $audit) {}

    public function recent(Request $request, CorrespondenceInbox $inbox): JsonResponse
    {
        abort_unless($request->user()->is_active, 403);

        return response()->json($inbox->recent($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->is_active, 403);
        $filters = $request->validate([
            'box' => ['nullable', 'in:inbox,sent,drafts,needs_action,in_progress,archive,forwarded,returned,overdue,all'],
            'channel' => ['nullable', 'in:internal,external'],
            'direction' => ['nullable', 'in:incoming,outgoing'],
            'q' => ['nullable', 'string', 'max:150'], 'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,issued,processing,processed,closed,archived,withdrawn'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'unread' => ['nullable', 'boolean'], 'overdue' => ['nullable', 'boolean'],
        ]);
        $channel = $filters['channel'] ?? 'internal';
        $direction = $filters['direction'] ?? (($filters['box'] ?? null) === 'sent' ? 'outgoing' : 'incoming');
        $box = $channel === 'external' ? 'all' : ($filters['box'] ?? ($direction === 'outgoing' ? 'sent' : 'inbox'));
        if ($channel === 'internal' && in_array($box, ['inbox', 'sent'], true)) {
            $direction = $box === 'sent' ? 'outgoing' : 'incoming';
        }
        $query = $this->access->mailbox($request->user(), $box)
            ->leftJoin('correspondence_tasks as t', 't.correspondence_id', '=', 'c.id')
            ->leftJoin('users as author', 'author.id', '=', 'c.author_id')
            ->leftJoin('users as holder', 'holder.id', '=', 't.assigned_to')
            ->leftJoin('master_data_values as priority', 'priority.id', '=', 'c.priority_value_id');
        $query->where('c.channel', $channel);
        if ($channel === 'external') {
            $query->where('c.direction', $direction);
        }
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('c.subject', 'ilike', '%'.$filters['q'].'%')->orWhere('c.reference', 'ilike', '%'.$filters['q'].'%')
                ->orWhere('c.external_party', 'ilike', '%'.$filters['q'].'%')->orWhere('c.external_reference', 'ilike', '%'.$filters['q'].'%')
                ->orWhere('author.name', 'ilike', '%'.$filters['q'].'%')
                ->orWhereExists(DB::table('correspondence_actions as searched')->join('users as actor', 'actor.id', '=', 'searched.actor_id')
                    ->whereColumn('searched.correspondence_id', 'c.id')->where('actor.name', 'ilike', '%'.$filters['q'].'%')
                    ->whereIn('searched.id', $this->access->accessQuery($request->user())->select('access.action_id'))->selectRaw('1')));
        }
        if (! empty($filters['department_id'])) {
            $query->where('c.department_id', $filters['department_id']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where('c.letter_date', $operator, $filters[$key]);
            }
        }
        if (! empty($filters['status'])) {
            $query->where('c.status', $filters['status']);
        }
        if (! empty($filters['unread']) || ! empty($filters['overdue'])) {
            $query->where('t.assigned_to', $request->user()->id)->where('t.status', 'open')
                ->whereIn('t.action_id', $this->access->accessQuery($request->user())->select('access.action_id'));
        }
        if (! empty($filters['unread'])) {
            $query->whereNull('t.read_at');
        }
        if (! empty($filters['overdue'])) {
            $query->where('t.due_at', '<', now());
        }
        if ($request->user()->isSystemAdmin()) {
            $this->audit->recordRow('correspondence.technical_list', 'official_correspondences', 0, null, ['box' => $box], (int) $request->user()->id);
        }
        $records = $query->select(['c.public_id', 'c.reference', 'c.subject', 'c.status', 'c.channel', 'c.direction', 'c.external_party', 'c.external_reference',
            'c.external_date', 'c.updated_at', 't.due_at', 'author.name as author_name', 'holder.name as holder_name', 't.status as task_status', 'priority.code as priority_code'])
            ->selectRaw("(t.assigned_to = ? AND t.status = 'open' AND t.read_at IS NULL) AS unread", [$request->user()->id])
            ->selectRaw("(t.status = 'open' AND t.due_at < ?) AS overdue", [now()])
            ->orderByDesc('c.updated_at')->orderByDesc('c.id')->paginate(25)
            ->withQueryString();
        $counts = [];
        foreach (['inbox', 'sent', 'drafts', 'needs_action', 'in_progress', 'archive', 'forwarded', 'returned', 'overdue', 'all'] as $mailbox) {
            $counts[$mailbox] = $this->access->mailbox($request->user(), $mailbox)->where('c.channel', 'internal')->count();
        }
        $counts['internal_incoming'] = $counts['inbox'];
        $counts['internal_outgoing'] = $counts['sent'];
        $counts['external_incoming'] = $this->access->visible($request->user())->where('c.channel', 'external')->where('c.direction', 'incoming')->count();
        $counts['external_outgoing'] = $this->access->visible($request->user())->where('c.channel', 'external')->where('c.direction', 'outgoing')->count();

        return Inertia::render('Correspondence/Index', [
            'records' => $records,
            'counts' => $counts,
            'filters' => array_replace($filters, ['box' => $box, 'channel' => $channel, 'direction' => $direction]),
            'isAdmin' => $request->user()->isSystemAdmin(),
            'canCreate' => $this->access->canCreate($request->user()),
            'canCreateExternalArchive' => $this->access->canCreateExternalArchive($request->user()),
            'canSeeDecisions' => $this->seesDecisionRegister($request->user()),
        ]);
    }

    private function seesDecisionRegister(User $user): bool
    {
        $decisions = app(BoardDecisions::class);

        return $decisions->installed() && ($decisions->visibleTo($user)
            || collect(['board_secretary', 'secretariat', 'executive_director'])->contains(fn (string $role): bool => $this->access->role($user, $role)));
    }

    /**
     * «سجل قرارات مجلس الإدارة»: the recorded Board decisions the user may open (CorrespondenceAccess), with their
     * number, date, meeting, executing departments and follow-up state. Each row opens the decision letter.
     */
    public function decisions(Request $request, BoardDecisions $decisions): Response
    {
        abort_unless($request->user()->is_active, 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'], 'state' => ['nullable', 'in:open,overdue,closed'], 'department_id' => ['nullable', 'integer'],
        ]);
        if (! $decisions->installed()) {
            return Inertia::render('Correspondence/Decisions', ['records' => null, 'counts' => [], 'filters' => $filters, 'departments' => []]);
        }
        if ($request->user()->isSystemAdmin()) {
            $this->audit->recordRow('correspondence.technical_list', 'official_correspondences', 0, null, ['box' => 'board_decisions'], (int) $request->user()->id);
        }

        return Inertia::render('Correspondence/Decisions', [
            'records' => $decisions->register($request->user(), $filters)->paginate(25)->withQueryString(),
            'counts' => $decisions->counts($request->user()),
            'filters' => $filters,
            'departments' => $this->access->departments()->sortBy('name')->map(fn (object $department): array => ['id' => (int) $department->id, 'name' => $department->name])->values(),
        ]);
    }

    public function createExternal(Request $request): Response
    {
        abort_unless($this->access->canCreateExternalArchive($request->user()), 403, 'تسجيل البريد الخارجي متاح لأعضاء قسم السكرتاريا فقط.');

        $catalog = [];
        foreach (['types', 'priorities', 'classifications'] as $type) {
            $catalog[$type] = $this->registry->constants('correspondence_'.$type)->orderBy('v.sort_order')->get(['v.id', 'v.code', 'v.name_ar']);
        }

        return Inertia::render('Correspondence/ExternalForm', ['catalog' => $catalog, 'settings' => $this->access->settings()]);
    }

    public function storeExternal(Request $request): RedirectResponse
    {
        $letter = $this->registry->archiveExternal($request->user(), $request->all(), $request->file('attachments', []));

        return to_route('correspondence.show', $letter->public_id)->with('success', 'حُفظت المراسلة الخارجية في الأرشيف.');
    }

    public function create(Request $request): Response
    {
        abort_unless($this->access->canCreate($request->user()), 403);

        return Inertia::render('Correspondence/Form', $this->formData($request));
    }

    private function formData(Request $request): array
    {
        $catalog = [];
        foreach (['types', 'priorities', 'classifications', 'retention'] as $type) {
            $catalog[$type] = $this->registry->constants('correspondence_'.$type)->orderBy('v.sort_order')->get(['v.id', 'v.code', 'v.name_ar']);
        }

        $requests = DB::table('workflow_instances as i')->where('i.subject_type', '<>', 'official_correspondence')
            ->where(fn ($q) => $q->where('i.requested_by', $request->user()->id)->orWhereExists(
                DB::table('workflow_participants as p')->whereColumn('p.workflow_instance_id', 'i.id')->where('p.user_id', $request->user()->id)->where('p.is_active', true)->selectRaw('1')
            ))->orderByDesc('i.id')->limit(100)->get(['i.id', 'i.reference_no']);
        $parents = $this->access->visible($request->user())->where('c.status', '<>', 'draft')->orderByDesc('c.id')->limit(100)->get(['c.id', 'c.reference']);

        return ['catalog' => $catalog, 'departments' => $this->access->departments()->filter(fn ($d) => $this->access->member($request->user(), (int) $d->id))->values(), 'settings' => $this->access->settings(), 'linkedRequests' => $requests, 'parentLetters' => $parents];
    }

    public function edit(Request $request, string $publicId): Response
    {
        $letter = $this->access->visible($request->user())->where('c.public_id', $publicId)->first();
        abort_unless($letter, 404);
        abort_unless($letter->status === 'draft' && (int) $letter->author_id === (int) $request->user()->id, 403);

        return Inertia::render('Correspondence/Form', $this->formData($request) + ['letter' => $letter, 'decision' => app(BoardDecisions::class)->draftFields((int) $letter->id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $letter = $this->registry->draft($request->user(), $request->all(), $request->file('attachments', []), null, $request->boolean('send'));

        return to_route('correspondence.show', $letter->public_id)->with('success', $letter->status === 'draft' ? 'حُفظت المسودة.' : 'أُرسلت المراسلة.');
    }

    public function update(Request $request, string $publicId): RedirectResponse
    {
        $letter = $this->registry->draft($request->user(), $request->all(), $request->file('attachments', []), $publicId, $request->boolean('send'));

        return to_route('correspondence.show', $letter->public_id)->with('success', $letter->status === 'draft' ? 'حُفظت المسودة.' : 'أُرسلت المراسلة.');
    }

    public function action(Request $request, string $publicId): RedirectResponse
    {
        if ($request->input('action') === 'refer') {
            $referral = $this->registry->refer($request->user(), $publicId, $request->all());

            return to_route('correspondence.show', $publicId)->with('success', 'أُرسلت الإحالة '.$referral->reference.'، وأصبح بإمكان المستلم الاطلاع على الأصل.');
        }
        $letter = $this->registry->act($request->user(), $publicId, $request->all(), $request->file('attachments', []));

        return to_route('correspondence.show', $letter->public_id);
    }

    public function recipients(Request $request): JsonResponse
    {
        $data = $request->validate(['department_id' => ['required', 'integer'], 'type_value_id' => ['required', 'integer'], 'classification_value_id' => ['required', 'integer'], 'letter' => ['nullable', 'uuid'], 'action' => ['nullable', Rule::in(['reopen', 'reassign', 'refer'])], 'q' => ['nullable', 'string', 'max:100']]);
        $source = (int) $data['department_id'];
        $letter = null;
        if (! empty($data['letter'])) {
            $letter = $this->access->visible($request->user())->where('c.public_id', $data['letter'])->first();
            abort_unless($letter, 404);
            $data['type_value_id'] = $letter->type_value_id;
            $data['classification_value_id'] = $letter->classification_value_id;
        }
        $governanceAction = $data['action'] ?? null;
        $refer = $governanceAction === 'refer';
        $task = $letter ? DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first() : null;
        if ($refer) {
            // A referral is a new letter of type «إحالة» from the referring department (CorrespondenceRegistry::refer).
            $referralSource = $letter ? $this->access->referralSource($request->user(), $letter) : null;
            abort_unless($referralSource !== null, 403);
            $source = $referralSource;
            $data['type_value_id'] = (int) $this->registry->constants('correspondence_types')->where('v.code', 'referral')->value('v.id');
        } elseif ($governanceAction) {
            abort_unless($letter && ($governanceAction === 'reopen'
                ? $this->access->canReopen($request->user(), $letter)
                : $this->access->canReassign($request->user(), $letter, $task)), 403);
            $source = $this->access->role($request->user(), 'executive_director') ? (int) $request->user()->department_id : (int) $task->department_id;
        }
        abort_unless($this->access->member($request->user(), $source), 403);
        $users = User::query()->where('is_active', true)->where('id', '<>', $request->user()->id)
            ->when(! empty($data['q']), fn ($q) => $q->where('name', 'like', '%'.$data['q'].'%'))->orderBy('name')->limit(200)->get(['id', 'name', 'department_id', 'is_active']);
        $choices = [];
        $classification = DB::table('master_data_values')->where('id', $data['classification_value_id'])->value('code');
        foreach ($users as $user) {
            foreach ($this->access->departments() as $department) {
                if ($governanceAction === 'reassign' && ((int) $user->id === (int) $task->assigned_to
                    || (! $this->access->role($request->user(), 'executive_director') && (int) $department->id !== (int) $task->department_id))) {
                    continue;
                }
                // Same limits as CorrespondenceRegistry::refer: Board work stays in the department, sensitive mail goes to the director.
                if ($refer && ($letter->channel === 'external'
                    ? $classification !== 'internal' && ! $this->access->role($user, 'executive_director')
                    : ! $this->access->within((int) $department->id, $source))) {
                    continue;
                }
                if (! $this->access->canRoute($request->user(), $source, $user, (int) $department->id, (int) $data['type_value_id'], (int) $data['classification_value_id'], $refer || ! $letter || $letter->status === 'draft')) {
                    continue;
                }
                // A referral shows the record to its recipient with the same classification; other hand-overs do not widen it.
                if (! $refer && $letter && $classification !== 'internal' && ($governanceAction || $source !== (int) $department->id) && ! $this->access->visible($user, false)->where('c.id', $letter->id)->exists()) {
                    continue;
                }
                $choices[] = ['id' => $user->id, 'department_id' => $department->id, 'label' => $user->name.' — '.$department->name, 'name' => $user->name, 'department' => $department->name];
            }
        }

        return response()->json(['recipients' => $choices]);
    }

    public function show(Request $request, string $publicId): Response
    {
        $actor = $request->user();
        $letter = $this->access->visible($actor)->where('c.public_id', $publicId)->first();
        abort_unless($letter, 404);
        $task = DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first();
        $owner = $task && (int) $task->assigned_to === (int) $actor->id && $task->status === 'open' && $this->access->actionVisible($actor, (int) $task->action_id, false);
        if ($owner && $this->registry->markOpened($actor, $letter)) {
            $task = DB::table('correspondence_tasks')->where('id', $task->id)->first();
        }
        // The Secretariat sees the whole history of its external archive, the administrator that of ordinary records,
        // someone it was referred to what was shared with them, an exceptional grantee what the issuer sees
        // (CorrespondenceAccess::visibleActions).
        $actions = DB::table('correspondence_actions as a')->join('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('users as r', 'r.id', '=', 'a.recipient_id')->where('a.correspondence_id', $letter->id)
            ->whereIn('a.id', $this->access->visibleActions($actor, $letter)->select('a.id'));
        $history = $actions->orderBy('a.id')->get(['a.id', 'a.public_id', 'a.action', 'a.is_original', 'a.note', 'a.created_at', 'u.name as actor_name', 'r.name as recipient_name']);
        $attachments = DB::table('workflow_event_attachments')->whereIn('correspondence_action_id', $history->pluck('id'))->get(['public_id', 'correspondence_action_id', 'original_filename', 'file_size', 'document_version', 'created_at'])->groupBy('correspondence_action_id');
        foreach ($history as $entry) {
            $entry->attachments = $attachments->get($entry->id, collect());
        }
        $author = (int) $letter->author_id === (int) $actor->id && $this->access->member($actor, (int) $letter->department_id);
        $allowed = [];
        if ($letter->status === 'draft') {
            $allowed = $author ? ['issue'] : [];
        } elseif (! in_array($letter->status, ['archived', 'withdrawn', 'closed'], true)) {
            if ($owner) {
                $allowed = ['reply', 'forward', 'return', 'refuse', 'complete', 'correction'];
            }
            if ($author) {
                $allowed[] = 'correction';
                if ($task && $task->status === 'completed') {
                    $allowed[] = 'close';
                }
                if ($letter->status === 'issued' && $task && ! $task->read_at && ! $task->received_at) {
                    $allowed[] = 'withdraw';
                }
            }
        }
        $workflow = null;
        $workflowActions = [];
        $workflowRevision = null;
        if ($letter->workflow_instance_id && ($level = app(WorkflowVisibilityService::class)->accessLevel($actor, (int) $letter->workflow_instance_id))) {
            $instance = DB::table('workflow_instances')->find($letter->workflow_instance_id);
            $workflow = app(WorkflowProgress::class)->view($instance, $level);
            if ($instance->subject_type === 'official_correspondence') {
                $workflow = app(WorkflowProgress::class)->view($instance, 'public_status');
                $workflowRevision = $instance->revision;
                if ($owner) {
                    $workflowActions = $this->withDecisionFields($this->withExecutingDepartments(app(WorkflowEngine::class)->availableActions($instance, $actor)), (int) $letter->id);
                }
                $allowed = $workflowActions !== [] ? ['workflow'] : [];
            }
        }
        if ($letter->status === 'closed' && $author && $task?->status === 'completed') {
            $allowed[] = 'archive';
        }
        if ($this->access->canReopen($actor, $letter)) {
            $allowed[] = 'reopen';
        }
        if ($this->access->canReassign($actor, $letter, $task)) {
            $allowed[] = 'reassign';
        }
        if ($this->access->referralSource($actor, $letter) !== null) {
            $allowed[] = 'refer';
        }
        // The record this letter refers to, and the referrals made from this one, as far as the viewer may open them.
        $parentLetter = $letter->parent_id
            ? $this->access->visible($actor)->where('c.id', $letter->parent_id)->first(['c.public_id', 'c.reference', 'c.subject', 'c.channel'])
            : null;
        $referrals = $this->access->visible($actor)->where('c.parent_id', $letter->id)->where('c.status', '<>', 'draft')
            ->leftJoin('correspondence_tasks as t', 't.correspondence_id', '=', 'c.id')->leftJoin('users as holder', 'holder.id', '=', 't.assigned_to')
            ->orderBy('c.id')->limit(50)->get(['c.public_id', 'c.reference', 'c.subject', 'c.status', 't.status as task_status', 'holder.name as holder_name']);
        $this->audit->recordRow($actor->isSystemAdmin() ? 'correspondence.technical_view' : 'correspondence.view', 'official_correspondences', $letter->id, null, null, (int) $actor->id);
        $grants = app(ConfidentialGrants::class);
        $exceptional = $this->access->grantInUse($actor, $letter);
        if ($exceptional !== null) {
            $grants->recordUse($actor, $letter, $exceptional, 'view', $request->ip());
        }
        $manageGrants = $grants->canManage($actor, $letter);

        $effectiveEdition = app(CorrespondenceEditions::class)->effective((int) $letter->id);
        abort_if($effectiveEdition && ! app(CorrespondenceEditions::class)->canView($actor, $effectiveEdition), 403);

        return Inertia::render('Correspondence/Show', [
            'effectiveEdition' => $effectiveEdition ? collect((array) $effectiveEdition)->only(['subject', 'body', 'version', 'approved_at'])->all() : null,
            'canProposeEdition' => Schema::hasTable('correspondence_editions') && $author && $letter->issued_at && ! in_array($letter->status, ['withdrawn', 'archived'], true),
            'editions' => Schema::hasTable('correspondence_editions') ? DB::table('correspondence_editions')->where('correspondence_id', $letter->id)->whereIn('action_id', $this->access->visibleActions($actor, $letter)->select('a.id'))->orderByDesc('version')->get(['public_id', 'version', 'status']) : [],
            'letter' => $letter, 'history' => $history, 'task' => $task, 'allowed' => array_values(array_unique($allowed)),
            'decision' => app(BoardDecisions::class)->forLetter((int) $letter->id),
            'sourceDepartment' => $owner ? $task->department_id : ($this->access->role($actor, 'executive_director') ? $actor->department_id : $letter->department_id), 'workflow' => $workflow, 'workflowActions' => $workflowActions,
            'workflowRevision' => $workflowRevision, 'settings' => $this->access->settings(),
            'replyTo' => $owner && in_array('reply', $allowed, true) ? $this->replyTarget($actor, $letter, $task) : null,
            'meta' => $this->letterMeta($letter, $task), 'canPrintAudit' => $actor->isSystemAdmin(),
            'parentLetter' => $parentLetter, 'referrals' => $referrals,
            'exceptionalAccess' => $exceptional !== null ? DB::table('correspondence_confidential_grants')->where('id', $exceptional)->value('ends_at') : null,
            'confidentialAccess' => $manageGrants ? ['grants' => $grants->listFor($letter), 'candidates' => $grants->candidates($actor)] : null,
        ]);
    }

    /**
     * For an action that charges a department head with executing a Board decision: the departments of each
     * selectable head who heads more than one, so the executive director names the executing department.
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function withExecutingDepartments(array $actions): array
    {
        $targets = DB::table('workflow_transitions as t')->join('workflow_steps as s', 's.id', '=', 't.to_step_id')
            ->whereIn('t.id', array_column($actions, 'id'))->pluck('s.code', 't.id');
        $decisions = app(BoardDecisions::class);
        foreach ($actions as &$action) {
            if (($targets[$action['id']] ?? null) !== 'execute' || empty($action['pick_options'])) {
                continue;
            }
            $heads = User::query()->whereIn('id', array_column($action['pick_options'], 'id'))->get();
            $action['executing_departments'] = $heads->mapWithKeys(fn (User $head): array => [$head->id => $decisions->headedDepartments($head)])
                ->filter(fn (array $departments): bool => count($departments) > 1)->all();
        }

        return $actions;
    }

    /**
     * When the Secretariat returned a decision not yet registered to the board secretary, his action back to
     * registration carries the decision data, so he completes or corrects it there (BoardDecisions::correct).
     *
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function withDecisionFields(array $actions, int $letterId): array
    {
        $decisions = app(BoardDecisions::class);
        if ($actions === [] || ! $decisions->installed()
            || ! DB::table('board_decisions')->where('correspondence_id', $letterId)->whereNull('recorded_at')->exists()) {
            return $actions;
        }
        $fromRecord = DB::table('workflow_transitions as t')->join('workflow_steps as s', 's.id', '=', 't.from_step_id')
            ->whereIn('t.id', array_column($actions, 'id'))->where('s.code', 'record')->pluck('t.id')->map(fn ($id): int => (int) $id)->all();
        foreach ($actions as &$action) {
            $action['decision_fields'] = in_array((int) $action['id'], $fromRecord, true);
        }

        return $actions;
    }

    /** The executive director opens a confidential record to one person until a date (ConfidentialGrants). */
    public function grantConfidential(Request $request, string $publicId): RedirectResponse
    {
        app(ConfidentialGrants::class)->issue($request->user(), $publicId, $request->only(['user_id', 'ends_on', 'reason']), $request->ip());

        return to_route('correspondence.show', $publicId)->with('success', 'مُنح الاطلاع الاستثنائي حتى التاريخ المحدد وسُجل في سجل النظام.');
    }

    public function revokeConfidential(Request $request, string $publicId, int $grant): RedirectResponse
    {
        app(ConfidentialGrants::class)->revoke($request->user(), $grant, (string) $request->input('revoke_reason', ''), $request->ip());

        return to_route('correspondence.show', $publicId)->with('success', 'أُلغي الاطلاع الاستثنائي وسُجل في سجل النظام.');
    }

    /**
     * The person who handed the letter to its current holder, when the holder may write back to them directly.
     *
     * @return array{id: int, department_id: int, label: string}|null
     */
    private function replyTarget(User $actor, object $letter, object $task): ?array
    {
        $handover = DB::table('correspondence_actions')->where('id', $task->action_id)->first(['actor_id', 'metadata']);
        $sender = $handover ? User::find($handover->actor_id) : null;
        $department = (int) (json_decode((string) $handover?->metadata, true)['source_department_id'] ?? 0);
        if (! $sender || $department === 0
            || ! $this->access->canRoute($actor, (int) $task->department_id, $sender, $department, (int) $letter->type_value_id, (int) $letter->classification_value_id, false)) {
            return null;
        }

        return ['id' => (int) $sender->id, 'department_id' => $department, 'label' => $sender->name.' — '.($this->access->departments()->get($department)?->name ?? '')];
    }

    /**
     * Names shown above the letter: sender, first recipient, current holder and the reference labels.
     *
     * @return array<string, string|null>
     */
    private function letterMeta(object $letter, ?object $task): array
    {
        $names = User::whereIn('id', array_filter([$letter->author_id, $letter->recipient_id, $task?->assigned_to]))->pluck('name', 'id');
        $values = DB::table('master_data_values')->whereIn('id', array_filter([$letter->type_value_id, $letter->classification_value_id, $letter->priority_value_id]))->get(['id', 'code', 'name_ar'])->keyBy('id');

        return [
            'author' => $names[$letter->author_id] ?? null,
            'author_department' => $this->access->departments()->get((int) $letter->department_id)?->name,
            'recipient' => $names[$letter->recipient_id] ?? null,
            'holder' => $task && $task->status === 'open' ? ($names[$task->assigned_to] ?? null) : null,
            'type' => $values[$letter->type_value_id]->name_ar ?? null, 'type_code' => $values[$letter->type_value_id]->code ?? null,
            'classification' => $values[$letter->classification_value_id]->name_ar ?? null, 'classification_code' => $values[$letter->classification_value_id]->code ?? null,
            'priority' => $values[$letter->priority_value_id]->name_ar ?? null, 'priority_code' => $values[$letter->priority_value_id]->code ?? null,
        ];
    }

    public function record(Request $request, int $id): RedirectResponse
    {
        $publicId = $this->access->visible($request->user())->where('c.id', $id)->value('c.public_id');
        abort_unless($publicId, 404);

        return to_route('correspondence.show', $publicId);
    }

    public function print(Request $request, string $publicId): \Symfony\Component\HttpFoundation\Response
    {
        $actor = $request->user();
        $this->audit->recordRow('correspondence.print.attempted', 'official_correspondences', 0, null,
            ['target_hash' => hash('sha256', $publicId)], (int) $actor->id, $request->ip());
        $data = $request->validate(['appendix' => ['nullable', 'boolean']]);
        $letter = $this->access->visible($actor)->where('c.public_id', $publicId)->first();
        abort_unless($letter, 404);
        abort_unless($letter->issued_at && ! in_array($letter->status, ['draft', 'withdrawn'], true), 422, 'الطباعة الرسمية متاحة للمراسلات الصادرة غير المسحوبة.');
        $appendix = (bool) ($data['appendix'] ?? false);
        abort_if($appendix && ! $actor->isSystemAdmin(), 403);

        $settings = $this->access->settings();
        $edition = app(CorrespondenceEditions::class)->effective((int) $letter->id);
        if ($edition) {
            abort_unless(app(CorrespondenceEditions::class)->canView($actor, $edition), 403);
        }
        $actions = $this->access->visibleActions($actor, $letter)->orderBy('a.created_at')->orderBy('a.id')->get([
            'a.id', 'a.actor_id', 'a.recipient_id', 'a.recipient_department_id', 'a.action', 'a.is_original', 'a.note', 'a.metadata', 'a.created_at',
        ]);
        $task = DB::table('correspondence_tasks as t')->leftJoin('users as holder', 'holder.id', '=', 't.assigned_to')
            ->where('t.correspondence_id', $letter->id)
            ->first(['t.status', 't.assigned_to', 't.department_id', 'holder.name as holder_name']);
        $stage = $letter->workflow_instance_id
            ? DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
                ->where('i.id', $letter->workflow_instance_id)->value('s.name')
            : null;

        $metadata = $actions->mapWithKeys(fn (object $action): array => [
            $action->id => json_decode((string) $action->metadata, true) ?: [],
        ]);
        $userIds = $actions->pluck('actor_id')->merge($actions->pluck('recipient_id'))
            ->push($letter->author_id, $letter->recipient_id, $task?->assigned_to, $edition?->approved_by)
            ->filter()->unique()->values();
        $users = User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');
        $departmentIds = $actions->pluck('recipient_department_id')->push($letter->department_id, $letter->recipient_department_id, $task?->department_id)
            ->merge($metadata->map(fn (array $entry) => $entry['source_department_id'] ?? null))
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)->map(fn ($id): int => (int) $id)->unique()->values();
        $departments = DB::table('departments')->whereIn('id', $departmentIds)->get(['id', 'name'])->keyBy('id');
        $meta = $this->letterMeta($letter, $task);

        $originalActionIds = $actions->where('is_original', true)->pluck('id');
        $files = DB::table('workflow_event_attachments')->whereIn('correspondence_action_id', $originalActionIds)
            ->orderBy('id')->get(['original_filename', 'document_version']);
        $editionFiles = $edition
            ? DB::table('workflow_event_attachments')->where('correspondence_action_id', $edition->action_id)->orderBy('id')->get(['original_filename', 'document_version'])
            : collect();

        $historyActions = $actions->filter(fn (object $action): bool => $action->recipient_id !== null
            || $action->recipient_department_id !== null
            || in_array($action->action, ['reply', 'return', 'refuse'], true))->values();
        $historyActionIds = $historyActions->pluck('id');
        $historyAttachments = DB::table('workflow_event_attachments')->whereIn('correspondence_action_id', $historyActionIds)
            ->orderBy('id')->get(['correspondence_action_id', 'original_filename', 'document_version'])->groupBy('correspondence_action_id');
        $history = $historyActions->values()->map(function (object $action, int $index) use ($departments, $historyAttachments, $metadata, $users): array {
            $sourceDepartmentId = $metadata[$action->id]['source_department_id'] ?? null;
            $sourceDepartment = $sourceDepartmentId ? $departments->get((int) $sourceDepartmentId)?->name : null;
            $destinationDepartment = $action->recipient_department_id ? $departments->get((int) $action->recipient_department_id)?->name : null;
            $actorName = $users->get($action->actor_id)?->name;
            $recipientName = $action->recipient_id ? $users->get($action->recipient_id)?->name : null;
            $attachments = $historyAttachments->get($action->id, collect())->map(fn (object $file): array => [
                'original_filename' => $file->original_filename,
                'document_version' => (int) $file->document_version,
            ])->values();

            return [
                'sequence' => $index + 1,
                'action' => $action->action,
                'from' => implode(' — ', array_filter([$actorName, $sourceDepartment])),
                'to' => implode(' — ', array_filter([$recipientName, $destinationDepartment])),
                'created_at' => Carbon::parse($action->created_at, config('app.timezone'))->format('Y-m-d H:i:s'),
                'note' => $action->note,
                'attachments' => $attachments,
            ];
        });
        $recipients = collect([[
            'user_id' => $letter->recipient_id,
            'department_id' => $letter->recipient_department_id,
        ]])->merge($actions->where('is_original', true)->map(fn (object $action): array => [
            'user_id' => $action->recipient_id,
            'department_id' => $action->recipient_department_id,
        ]))->filter(fn (array $recipient): bool => $recipient['user_id'] || $recipient['department_id'])
            ->unique(fn (array $recipient): string => ($recipient['user_id'] ?? '').'|'.($recipient['department_id'] ?? ''))
            ->map(fn (array $recipient): string => implode(' — ', array_filter([
                $recipient['user_id'] ? $users->get($recipient['user_id'])?->name : null,
                $recipient['department_id'] ? $departments->get($recipient['department_id'])?->name : null,
            ])))->filter()->values();

        $appendixEntries = [];
        if ($appendix) {
            $allActions = DB::table('correspondence_actions as a')->join('users as u', 'u.id', '=', 'a.actor_id')
                ->leftJoin('users as recipient', 'recipient.id', '=', 'a.recipient_id')
                ->where('a.correspondence_id', $letter->id)->orderBy('a.created_at')->orderBy('a.id')
                ->get(['a.id', 'a.action', 'a.note', 'a.created_at', 'u.name as actor', 'recipient.name as recipient']);
            $allAttachments = DB::table('workflow_event_attachments')->whereIn('correspondence_action_id', $allActions->pluck('id'))
                ->orderBy('id')->get(['correspondence_action_id', 'original_filename', 'document_version'])->groupBy('correspondence_action_id');
            foreach ($allActions as $entry) {
                $entry->attachments = $allAttachments->get($entry->id, collect());
                $appendixEntries[] = $entry;
            }
        }

        $issue = $actions->firstWhere('action', 'issue');
        $printedAt = now((string) config('app.timezone'));

        $exceptional = $this->access->grantInUse($actor, $letter);
        if ($exceptional !== null) {
            app(ConfidentialGrants::class)->recordUse($actor, $letter, $exceptional, 'print', $request->ip(), ['appendix' => $appendix]);
        }

        $response = Inertia::render('Correspondence/Print', [
            'document' => [
                'reference' => $letter->reference, 'subject' => $edition?->subject ?? $letter->subject, 'body' => $edition?->body ?? $letter->body,
                'edition_version' => $edition?->version, 'edition_approved_at' => $edition?->approved_at,
                'edition_approver' => $edition ? $users->get($edition->approved_by)?->name : null,
                'date' => $letter->letter_date, 'issuer' => $issue ? $users->get($issue->actor_id)?->name : null, 'issued_at' => $issue?->created_at,
                'sender' => $meta['author'], 'sender_department' => $departments->get($letter->department_id)?->name,
                'recipients' => $recipients, 'type' => $meta['type'],
                'classification' => $meta['classification'], 'priority' => $meta['priority'],
                'status' => $letter->status, 'task_status' => $task?->status, 'current_stage' => $stage,
                'current_holder' => $task?->holder_name,
            ],
            'letterhead' => $settings['letterhead'], 'files' => $files, 'editionFiles' => $editionFiles,
            'history' => $history, 'appendix' => $appendixEntries,
            'printAt' => ['name' => $actor->name, 'date' => $printedAt->format('Y-m-d'), 'time' => $printedAt->format('H:i:s')],
            'backUrl' => route('correspondence.show', $publicId, false),
        ])->toResponse($request);
        $this->audit->recordRow('correspondence.print', 'official_correspondences', $letter->id, null,
            ['appendix' => $appendix, 'policy_version' => $settings['policy_version'], 'template_version' => 4, 'edition_id' => $edition?->id], (int) $actor->id, $request->ip());
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function download(Request $request, string $attachment): StreamedResponse
    {
        $file = DB::table('workflow_event_attachments as f')->join('correspondence_actions as a', 'a.id', '=', 'f.correspondence_action_id')->where('f.public_id', $attachment)->first(['f.*', 'a.correspondence_id']);
        $letter = $file ? $this->access->visible($request->user())->where('c.id', $file->correspondence_id)->first() : null;
        abort_unless($letter && $this->access->actionVisible($request->user(), (int) $file->correspondence_action_id), 404);
        abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 404);
        $exceptional = $this->access->grantInUse($request->user(), $letter);
        if ($exceptional !== null) {
            app(ConfidentialGrants::class)->recordUse($request->user(), $letter, $exceptional, 'download', $request->ip(), ['attachment_id' => (int) $file->id]);
        }
        $this->audit->recordRow('correspondence.attachment.download', 'workflow_event_attachments', $file->id, null, ['action_id' => $file->correspondence_action_id], (int) $request->user()->id);

        return Storage::disk($file->storage_disk)->download($file->storage_path, $file->original_filename, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
