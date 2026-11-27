<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowEngine;
use App\Services\WorkflowVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CorrespondenceRegistry
{
    public function __construct(private readonly CorrespondenceAccess $access, private readonly CorrespondenceDocuments $documents, private readonly AuditService $audit, private readonly NotificationCenter $notifications, private readonly BoardDecisions $decisions) {}

    public function constants(string $type): Builder
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', $type)->where('t.is_active', true)->where('v.is_active', true)
            ->where(fn ($q) => $q->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', today()->toDateString()));
    }

    /**
     * Saves a draft; with $send the same transaction issues it, so the writer never has a separate «issue» step.
     */
    public function draft(User $actor, array $input, array $files, ?string $publicId = null, bool $send = false): object
    {
        $data = Validator::make($input, [
            'creation_key' => ['required', 'uuid'], 'revision' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['required', 'integer'], 'recipient_id' => ['required', 'integer'], 'recipient_department_id' => ['required', 'integer'],
            'type_value_id' => ['required', 'integer'], 'classification_value_id' => ['required', 'integer'], 'priority_value_id' => ['required', 'integer'],
            'retention_value_id' => ['nullable', 'integer'], 'subject' => ['required', 'string', 'max:250'], 'body' => ['required', 'string', 'max:50000'],
            'letter_date' => ['required', 'date_format:Y-m-d'], 'due_at' => ['nullable', 'date'], 'acknowledgment_required' => ['required', 'boolean'],
            'workflow_instance_id' => ['nullable', 'integer'], 'parent_id' => ['nullable', 'integer'], 'board_reference' => ['nullable', 'string', 'max:200'],
            // A Board decision's own data (BoardDecisions), kept apart from the letter; required when it is sent.
            'decision_number' => ['nullable', 'string', 'max:100'], 'decision_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'meeting_number' => ['nullable', 'string', 'max:100'], 'meeting_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [], ['decision_number' => 'رقم القرار', 'decision_date' => 'تاريخ القرار', 'meeting_number' => 'رقم الجلسة', 'meeting_date' => 'تاريخ الجلسة'])->validate();
        $fingerprints = $this->documents->validate($files);
        $hash = hash('sha256', json_encode([$data, $fingerprints], JSON_THROW_ON_ERROR));
        $decision = array_intersect_key($data, array_flip(BoardDecisions::FIELDS));
        $data = array_diff_key($data, $decision);
        $isBoard = $this->decisions->isBoardType((int) $data['type_value_id']);
        if ($isBoard && filled($decision['decision_number'] ?? null)) {
            // The letter's Board reference is the decision number, so the two never disagree.
            $data['board_reference'] = mb_substr(trim($decision['decision_number']), 0, 200);
        }
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $data, $decision, $isBoard, $files, $publicId, $hash, $send, &$written): object {
                DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                $existing = DB::table('official_correspondences')->where('creation_key', $data['creation_key'])->first();
                if ($publicId === null && $existing) {
                    $action = DB::table('correspondence_actions')->where('correspondence_id', $existing->id)->where('action', 'draft')->first();
                    abort_unless((int) $existing->author_id === (int) $actor->id && hash_equals($action->payload_hash, $hash), 409);

                    return $send && $existing->status === 'draft' ? $this->issue($actor, $existing) : $existing;
                }
                $letter = $publicId ? $this->access->visible($actor)->where('c.public_id', $publicId)->lockForUpdate()->first() : null;
                if ($publicId) {
                    abort_unless($letter, 404);
                    abort_unless((int) $letter->author_id === (int) $actor->id && $letter->status === 'draft', 403);
                    abort_unless((int) $letter->revision === (int) ($data['revision'] ?? 0), 409, 'المسودة تغيرت؛ أعد تحميل الصفحة.');
                    abort_unless($letter->creation_key === $data['creation_key'], 422);
                }
                $this->validateDestination($actor, $data, true);
                foreach (['type' => 'types', 'classification' => 'classifications', 'priority' => 'priorities', 'retention' => 'retention'] as $field => $catalog) {
                    if (! empty($data[$field.'_value_id'])) {
                        abort_unless($this->constants('correspondence_'.$catalog)->where('v.id', $data[$field.'_value_id'])->exists(), 422, 'قيمة مرجعية غير نشطة أو غير صحيحة.');
                    }
                }
                if (! empty($data['parent_id'])) {
                    $this->parentShare($actor, (int) $data['parent_id'], (int) $data['department_id'], (int) $data['classification_value_id'],
                        User::findOrFail($data['recipient_id']), (int) $data['recipient_department_id']);
                }
                $revision = $letter ? (int) $letter->revision + 1 : 1;
                unset($data['revision']);
                $values = $data + ['updated_at' => now(), 'revision' => $revision];
                if ($letter) {
                    DB::table('official_correspondences')->where('id', $letter->id)->update($values);
                    $id = (int) $letter->id;
                } else {
                    $id = DB::table('official_correspondences')->insertGetId($values + ['public_id' => (string) Str::uuid(), 'author_id' => $actor->id, 'created_at' => now()]);
                }
                $letter = DB::table('official_correspondences')->find($id);
                $this->decisions->saveDraft($actor, $letter, $isBoard ? $decision : null);
                $action = $this->record($actor, $letter, $publicId ? 'draft_edit' : 'draft', (string) Str::uuid(), $hash, null, null, null, true);
                $this->documents->store($actor, $letter, $action, $files, [], $written);

                return $send ? $this->issue($actor, $letter) : $letter;
            });
        } catch (\Throwable $exception) {
            $this->documents->cleanup($written);
            throw $exception;
        }
    }

    public function archiveExternal(User $actor, array $input, array $files): object
    {
        abort_unless($this->access->canCreateExternalArchive($actor), 403, 'تسجيل البريد الخارجي متاح لأعضاء قسم السكرتاريا فقط.');

        $data = Validator::make($input, [
            'creation_key' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'external_party' => ['required', 'string', 'max:255'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'external_date' => ['required', 'date_format:Y-m-d'],
            'type_value_id' => ['required', 'integer'],
            'classification_value_id' => ['required', 'integer'],
            'priority_value_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:250'],
            'body' => ['nullable', 'string', 'max:50000'],
        ])->validate();
        $fingerprints = $this->documents->validate($files);
        $hash = hash('sha256', json_encode([$data, $fingerprints], JSON_THROW_ON_ERROR));
        $written = [];

        try {
            return DB::transaction(function () use ($actor, $data, $files, $hash, &$written): object {
                DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                abort_unless($this->access->canCreateExternalArchive($actor), 403, 'انتهى تكليفك في قسم السكرتاريا.');
                $departmentId = $this->access->externalArchiveDepartmentId();
                abort_unless($departmentId !== null, 403, 'قسم السكرتاريا المستقل غير مهيأ في الهيكل التنظيمي.');

                $existing = DB::table('official_correspondences')->where('creation_key', $data['creation_key'])->first();
                if ($existing) {
                    $action = DB::table('correspondence_actions')->where('correspondence_id', $existing->id)->where('action', 'external_register')->first();
                    abort_unless((int) $existing->author_id === (int) $actor->id && $action && hash_equals($action->payload_hash, $hash), 409);

                    return $existing;
                }

                foreach (['type' => 'types', 'classification' => 'classifications', 'priority' => 'priorities'] as $field => $catalog) {
                    abort_unless($this->constants('correspondence_'.$catalog)->where('v.id', $data[$field.'_value_id'])->exists(), 422, 'قيمة مرجعية غير نشطة أو غير صحيحة.');
                }

                $date = $data['external_date'];
                $now = now();
                $letterId = DB::table('official_correspondences')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'creation_key' => $data['creation_key'],
                    'reference' => $this->externalNumber($data['direction'], $date),
                    'author_id' => $actor->id,
                    'department_id' => $departmentId,
                    'recipient_id' => null,
                    'recipient_department_id' => null,
                    'type_value_id' => $data['type_value_id'],
                    'classification_value_id' => $data['classification_value_id'],
                    'priority_value_id' => $data['priority_value_id'],
                    'channel' => 'external',
                    'direction' => $data['direction'],
                    'external_party' => trim($data['external_party']),
                    'external_reference' => filled($data['external_reference'] ?? null) ? trim($data['external_reference']) : null,
                    'external_date' => $date,
                    'subject' => trim($data['subject']),
                    'body' => trim((string) ($data['body'] ?? '')),
                    'letter_date' => $date,
                    'acknowledgment_required' => false,
                    'status' => 'archived',
                    'revision' => 1,
                    'closed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $letter = DB::table('official_correspondences')->find($letterId);
                $actionId = DB::table('correspondence_actions')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'correspondence_id' => $letterId,
                    'actor_id' => $actor->id,
                    'recipient_id' => null,
                    'recipient_department_id' => null,
                    'idempotency_key' => $data['creation_key'],
                    'payload_hash' => $hash,
                    'action' => 'external_register',
                    'is_original' => true,
                    'note' => null,
                    'metadata' => json_encode([
                        'channel' => 'external',
                        'direction' => $data['direction'],
                        'external_party' => trim($data['external_party']),
                        'external_reference' => $data['external_reference'] ?? null,
                        'external_date' => $date,
                        'attachment_count' => count($files),
                        'authority' => $this->access->authority($actor, $departmentId),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
                DB::table('correspondence_access')->insert([
                    'action_id' => $actionId,
                    'user_id' => $actor->id,
                    'department_id' => $departmentId,
                    ...$this->access->authority($actor, $departmentId),
                    'created_at' => $now,
                ]);
                $this->audit->recordRow('correspondence.external_archived', 'official_correspondences', $letterId, null, [
                    'action_id' => $actionId,
                    'direction' => $data['direction'],
                    'attachment_count' => count($files),
                ], (int) $actor->id);
                $this->documents->store($actor, $letter, $actionId, $files, [], $written);

                return DB::table('official_correspondences')->find($letterId);
            });
        } catch (\Throwable $exception) {
            $this->documents->cleanup($written);
            throw $exception;
        }
    }

    public function act(User $actor, string $publicId, array $input, array $files = []): object
    {
        $data = Validator::make($input, [
            'idempotency_key' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['issue', 'receive', 'read', 'acknowledge', 'forward', 'reply', 'return', 'refuse', 'complete', 'close', 'archive', 'withdraw', 'correction', 'workflow', 'reopen', 'reassign'])],
            'transition_id' => ['exclude_unless:action,workflow', 'required', 'integer'], 'workflow_revision' => ['exclude_unless:action,workflow', 'required', 'integer'],
            'recipient_id' => ['nullable', 'integer'], 'recipient_department_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:20000'],
            'due_at' => ['nullable', 'date'], 'replacements' => ['sometimes', 'array'], 'replacements.*' => ['nullable', 'uuid'],
            // The board secretary's completion of a decision the Secretariat returned (BoardDecisions::correct).
            'decision' => ['exclude_unless:action,workflow', 'sometimes', 'array'],
            'decision.decision_number' => ['nullable', 'string', 'max:100'], 'decision.decision_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'decision.meeting_number' => ['nullable', 'string', 'max:100'], 'decision.meeting_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [], ['decision.decision_number' => 'رقم القرار', 'decision.decision_date' => 'تاريخ القرار', 'decision.meeting_number' => 'رقم الجلسة', 'decision.meeting_date' => 'تاريخ الجلسة'])->validate();
        $fingerprints = $this->documents->validate($files);
        $hash = hash('sha256', json_encode([$data, $fingerprints], JSON_THROW_ON_ERROR));
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $publicId, $data, $files, $hash, &$written): object {
                $letter = $this->access->visible($actor)->where('c.public_id', $publicId)->lockForUpdate()->first();
                abort_unless($letter, 404);
                abort_if($letter->channel === 'external', 403, 'المراسلات الخارجية محفوظة للأرشفة فقط ولا تقبل إجراءات داخلية.');
                $prior = DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($prior) {
                    abort_unless((int) $prior->actor_id === (int) $actor->id && hash_equals($prior->payload_hash, $hash), 409);

                    return $letter;
                }
                abort_unless((int) $letter->revision === (int) $data['revision'], 409, 'تغير السجل؛ أعد تحميل الصفحة قبل المتابعة.');
                $action = $data['action'];
                abort_if($files !== [] && ! in_array($action, ['issue', 'forward', 'reply', 'return', 'refuse', 'complete', 'correction', 'workflow'], true), 422, 'لا يقبل هذا الإجراء مرفقات.');
                $board = DB::table('master_data_values')->where('id', $letter->type_value_id)->value('code') === 'board_transmission';
                if ($board && $letter->status !== 'draft') {
                    abort_unless(in_array($action, ['receive', 'read', 'acknowledge', 'workflow', 'archive'], true), 403, 'يجب متابعة القرار من مساره المؤسسي.');
                }
                if ($action === 'workflow') {
                    abort_unless($board && $letter->workflow_instance_id, 422);
                    if (isset($data['decision'])) {
                        $this->decisions->correct($actor, $letter, $data['decision']);
                    }
                    // The department the executive director charges, when the head he picks heads several (CorrespondenceWorkflow).
                    CorrespondenceWorkflow::$executingDepartment = isset($data['recipient_department_id']) ? (int) $data['recipient_department_id'] : null;
                    try {
                        app(WorkflowEngine::class)->act((int) $letter->workflow_instance_id, $actor, (int) $data['transition_id'], $data['note'] ?? null, [], isset($data['recipient_id']) ? (int) $data['recipient_id'] : null, (int) $data['workflow_revision']);
                    } finally {
                        CorrespondenceWorkflow::$executingDepartment = null;
                    }
                    $projection = DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->orderByDesc('id')->first();
                    abort_unless($projection && $projection->action === 'workflow', 500);
                    DB::table('correspondence_actions')->where('id', $projection->id)->update(['idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash]);
                    $this->documents->store($actor, $letter, (int) $projection->id, $files, [], $written);

                    return DB::table('official_correspondences')->find($letter->id);
                }
                abort_if(in_array($letter->status, ['archived', 'withdrawn'], true) || ($letter->status === 'closed' && ! in_array($action, ['archive', 'reopen'], true)), 422, 'السجل مغلق.');
                $task = DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first();
                $owner = $task && (int) $task->assigned_to === (int) $actor->id && $task->status === 'open'
                    && $this->access->actionVisible($actor, (int) $task->action_id, false);
                $author = (int) $letter->author_id === (int) $actor->id && $this->access->member($actor, (int) $letter->department_id);
                $source = $owner ? (int) $task->department_id : (int) $letter->department_id;
                $recipient = null;
                $destination = null;
                $share = null;
                $updates = ['revision' => (int) $letter->revision + 1, 'updated_at' => now()];
                if (in_array($action, ['forward', 'reply', 'return', 'refuse', 'withdraw', 'correction', 'reopen', 'reassign'], true)) {
                    abort_unless(filled($data['note'] ?? null), 422, 'سبب الإجراء أو نص الرد مطلوب.');
                }
                abort_if($action !== 'correction' && ! empty(array_filter($data['replacements'] ?? [])), 422);
                if (in_array($action, ['reopen', 'reassign'], true)) {
                    abort_unless($action === 'reopen' ? $this->access->canReopen($actor, $letter) : $this->access->canReassign($actor, $letter, $task), 403);
                    abort_unless(filled($data['due_at'] ?? null) && Carbon::parse($data['due_at'])->isFuture(), 422, 'موعد متابعة جديد في المستقبل مطلوب.');
                    $source = $this->access->role($actor, 'executive_director') ? (int) $actor->department_id : (int) $task->department_id;
                    $recipient = User::findOrFail($data['recipient_id'] ?? 0);
                    $destination = (int) ($data['recipient_department_id'] ?? 0);
                    abort_if($task && (int) $task->assigned_to === (int) $recipient->id && $action === 'reassign', 422, 'اختر مسؤولًا جديدًا.');
                    abort_unless($this->access->role($actor, 'executive_director') || $destination === (int) $task->department_id, 403);
                    $this->validateDestination($actor, [...(array) $letter, 'department_id' => $source, 'recipient_id' => $recipient->id, 'recipient_department_id' => $destination], false);
                    $classification = DB::table('master_data_values')->where('id', $letter->classification_value_id)->value('code');
                    abort_if($classification !== 'internal' && ! $this->access->visible($recipient, false)->where('c.id', $letter->id)->exists(), 403, 'إعادة الإسناد لا توسع تداول المراسلات السرية.');
                    $updates += ['status' => 'processing', 'closed_at' => null];
                } elseif ($action === 'issue') {
                    abort_unless($author && $letter->status === 'draft', 403);
                    $this->validateDestination($actor, (array) $letter, true);
                    foreach (['type' => 'types', 'classification' => 'classifications', 'priority' => 'priorities', 'retention' => 'retention'] as $field => $catalog) {
                        $value = $letter->{$field.'_value_id'};
                        abort_if($value && ! $this->constants('correspondence_'.$catalog)->where('v.id', $value)->exists(), 422, 'تغيرت القيم المرجعية؛ راجع المسودة قبل الإصدار.');
                    }
                    $recipient = User::findOrFail($letter->recipient_id);
                    $destination = (int) $letter->recipient_department_id;
                    // Checked again when sent: the linked record may have moved on since the draft was saved.
                    $share = $letter->parent_id ? $this->parentShare($actor, (int) $letter->parent_id, (int) $letter->department_id,
                        (int) $letter->classification_value_id, $recipient, $destination) : null;
                    $updates += ['reference' => $this->number($letter), 'status' => 'issued', 'issued_at' => now()];
                } else {
                    abort_if($letter->status === 'draft', 422);
                    if (in_array($action, ['forward', 'reply', 'return', 'refuse'], true)) {
                        abort_unless($owner, 403);
                        $recipient = User::findOrFail($data['recipient_id'] ?? 0);
                        $destination = (int) ($data['recipient_department_id'] ?? 0);
                        $target = (array) $letter;
                        $target['department_id'] = $source;
                        $target['recipient_id'] = $recipient->id;
                        $target['recipient_department_id'] = $destination;
                        $this->validateDestination($actor, $target, false);
                        $classification = DB::table('master_data_values')->where('id', $letter->classification_value_id)->value('code');
                        abort_if($classification !== 'internal' && $source !== $destination && ! $this->access->visible($recipient, false)->where('c.id', $letter->id)->exists(), 403, 'الإحالة الحساسة إلى جهة جديدة تتطلب سياسة خاصة معتمدة.');
                        $updates['status'] = 'processing';
                    } elseif ($action === 'withdraw') {
                        abort_unless($author && $task && $task->read_at === null && $task->received_at === null && $letter->status === 'issued', 403);
                        $updates += ['status' => 'withdrawn', 'closed_at' => now()];
                    } elseif ($action === 'close' || $action === 'archive') {
                        abort_unless($author && $task && $task->status === 'completed', 403);
                        if ($letter->workflow_instance_id) {
                            abort_unless(DB::table('workflow_instances')->where('id', $letter->workflow_instance_id)->where('status', 'completed')->exists(), 422, 'المسار المرتبط لم يكتمل.');
                        }
                        $updates += ['status' => $action === 'close' ? 'closed' : 'archived', 'closed_at' => now()];
                    } elseif ($action === 'correction') {
                        abort_unless($author || $owner, 403);
                    } else {
                        abort_unless($owner, 403);
                        if ($action === 'complete') {
                            $linkedRequestRunning = $letter->workflow_instance_id
                                && ! DB::table('workflow_instances')->where('id', $letter->workflow_instance_id)->where('status', 'completed')->exists();
                            $updates += $linkedRequestRunning ? ['status' => 'processed'] : ['status' => 'closed', 'closed_at' => now()];
                        }
                        abort_if($files !== [] && $action !== 'complete', 422, 'لا يقبل هذا الإجراء مرفقات.');
                    }
                }
                $id = $this->record($actor, $letter, $action, $data['idempotency_key'], $hash, $data['note'] ?? null, $recipient, $destination, $action === 'issue', $source);
                if ($action === 'complete') {
                    $requester = User::findOrFail($letter->author_id);
                    if ($this->access->visible($requester, false)->where('c.id', $letter->id)->exists()
                        && $this->access->member($requester, (int) $letter->department_id)) {
                        $this->grant($id, $requester, (int) $letter->department_id);
                    }
                }
                if ($action === 'correction' && $task) {
                    $responsible = User::findOrFail($task->assigned_to);
                    if ($this->access->member($responsible, (int) $task->department_id)) {
                        $this->grant($id, $responsible, (int) $task->department_id);
                    }
                }
                $this->documents->store($actor, $letter, $id, $files, $data['replacements'] ?? [], $written);
                DB::table('official_correspondences')->where('id', $letter->id)->update($updates);
                if ($board && $action === 'issue') {
                    $this->decisions->checkOnIssue($letter);
                    $instance = app(WorkflowEngine::class)->startForSubject($actor, 'BOARD_CORRESPONDENCE', 'official_correspondence', (int) $letter->id, (int) $letter->department_id, (int) $recipient->id);
                    DB::table('official_correspondences')->where('id', $letter->id)->update(['workflow_instance_id' => $instance->id]);
                } elseif ($recipient) {
                    // Shared first, so the referral's own reason is the one logged on the linked record.
                    if ($share !== null) {
                        $this->shareOriginals((int) $letter->parent_id, $recipient, $destination, $id, (int) $actor->id, $share);
                    }
                    $this->assign($letter, $id, $recipient, $destination, $data['due_at'] ?? $letter->due_at, (int) $actor->id);
                    if (in_array($action, ['reopen', 'reassign'], true) && $task) {
                        $previousOwner = User::find($task->assigned_to);
                        if ($previousOwner && $this->access->visible($previousOwner, false)->where('c.id', $letter->id)->exists()) {
                            $this->alert((int) $previousOwner->id, $id, $letter->public_id, ':previous', 'تغير إسناد مراسلة رسمية');
                        }
                    }
                } elseif ($task) {
                    $changes = ['updated_at' => now()];
                    $field = ['receive' => 'received_at', 'read' => 'read_at', 'acknowledge' => 'acknowledged_at', 'complete' => 'completed_at'][$action] ?? null;
                    if ($field && $task->$field === null) {
                        $changes[$field] = now();
                    }
                    if ($action === 'complete') {
                        $changes['status'] = 'completed';
                        foreach (['received_at', 'read_at', 'acknowledged_at'] as $stamp) {
                            $changes[$stamp] = $task->$stamp ?? now();
                        }
                    }
                    if ($action === 'withdraw') {
                        $changes['status'] = 'cancelled';
                    }
                    DB::table('correspondence_tasks')->where('id', $task->id)->update($changes);
                    if ($actor->id !== (int) $letter->author_id && $this->access->visible(User::findOrFail($letter->author_id), false)->where('c.id', $letter->id)->exists()) {
                        $this->alert((int) $letter->author_id, $id, $letter->public_id, '', $action === 'complete' ? 'أُنجزت مراسلتك' : 'مراسلة رسمية تحتاج متابعة');
                    }
                }
                $metadata = json_decode(DB::table('correspondence_actions')->where('id', $id)->value('metadata'), true);
                DB::table('correspondence_actions')->where('id', $id)->update(['metadata' => json_encode($metadata + ['after' => $updates, 'attachment_count' => count($files)], JSON_THROW_ON_ERROR)]);

                return DB::table('official_correspondences')->find($letter->id);
            });
        } catch (\Throwable $exception) {
            $this->documents->cleanup($written);
            throw $exception;
        }
    }

    private function validateDestination(User $actor, array $data, bool $issuing): void
    {
        $recipient = User::findOrFail($data['recipient_id']);
        abort_unless($this->access->canRoute($actor, (int) $data['department_id'], $recipient, (int) $data['recipient_department_id'], (int) $data['type_value_id'], (int) $data['classification_value_id'], $issuing), 403, 'هذا المسار غير مصرح به.');
        if ($issuing && DB::table('master_data_values')->where('id', $data['type_value_id'])->value('code') === 'board_transmission') {
            abort_unless($this->access->role($actor, 'board_secretary') && $this->access->role($recipient, 'secretariat') && filled($data['board_reference'] ?? null) && empty($data['workflow_instance_id']), 422, 'يتطلب القرار مرجع المجلس وإصداره من أمين السر إلى السكرتاريا.');
        }
        if (! empty($data['workflow_instance_id'])) {
            $visibility = app(WorkflowVisibilityService::class);
            abort_unless($visibility->accessLevel($actor, (int) $data['workflow_instance_id']) && $visibility->accessLevel($recipient, (int) $data['workflow_instance_id']), 403);
        }
    }

    private function number(object $letter): string
    {
        $scope = now()->year.'-'.$letter->department_id.'-'.$letter->type_value_id;
        DB::table('correspondence_sequences')->insertOrIgnore(['scope' => $scope, 'last_number' => 0]);
        $sequence = DB::table('correspondence_sequences')->where('scope', $scope)->lockForUpdate()->first();
        $number = (int) $sequence->last_number + 1;
        DB::table('correspondence_sequences')->where('scope', $scope)->update(['last_number' => $number]);

        return $this->access->settings()['prefix'].'/'.$scope.'/'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function externalNumber(string $direction, string $date): string
    {
        $kind = $direction === 'incoming' ? 'IN' : 'OUT';
        $scope = 'external-'.$kind.'-'.Carbon::parse($date)->year;
        DB::table('correspondence_sequences')->insertOrIgnore(['scope' => $scope, 'last_number' => 0]);
        $sequence = DB::table('correspondence_sequences')->where('scope', $scope)->lockForUpdate()->first();
        $number = (int) $sequence->last_number + 1;
        DB::table('correspondence_sequences')->where('scope', $scope)->update(['last_number' => $number]);

        return 'AIMS/EXT/'.$kind.'/'.Carbon::parse($date)->year.'/'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** Returns 1 when the access is new, 0 when the user already had it. */
    private function grant(int $action, User $user, int $department): int
    {
        return DB::table('correspondence_access')->insertOrIgnore(['action_id' => $action, 'user_id' => $user->id, 'department_id' => $department, ...$this->access->authority($user, $department), 'created_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $extra  more facts kept with the action and its audit row
     */
    private function record(User $actor, object $letter, string $action, string $key, string $hash, ?string $note, ?User $recipient, ?int $destination, bool $original = false, ?int $source = null, array $extra = []): int
    {
        $source ??= (int) $letter->department_id;
        $metadata = ['source_department_id' => $source, 'destination_department_id' => $destination, 'revision' => $letter->revision, 'previous_status' => $letter->status, 'previous_assignment' => DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first(), 'policy_version' => $this->access->settings()['policy_version'], 'authority' => $this->access->authority($actor, $source)] + $extra;
        $id = DB::table('correspondence_actions')->insertGetId(['public_id' => (string) Str::uuid(), 'correspondence_id' => $letter->id, 'actor_id' => $actor->id, 'recipient_id' => $recipient?->id, 'recipient_department_id' => $destination, 'idempotency_key' => $key, 'payload_hash' => $hash, 'action' => $action, 'is_original' => $original, 'note' => $note, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->grant($id, $actor, $source);
        if ($recipient) {
            $this->grant($id, $recipient, $destination);
        }
        $this->audit->recordRow('correspondence.'.$action, 'official_correspondences', $letter->id, null, ['action_id' => $id, 'correlation_id' => $key, ...$metadata], (int) $actor->id);

        return $id;
    }

    private function assign(object $letter, int $action, User $recipient, int $department, ?string $due, int $actorId): void
    {
        $this->grantOriginals((int) $letter->id, $recipient, $department);
        // A letter that refers incoming external mail with the same classification carries that mail's original to
        // each holder it reaches (refer()); the letter's own routing and confidentiality rules decide who that is.
        // Each new reader is logged on the external record.
        $parent = $letter->parent_id ? DB::table('official_correspondences')->find($letter->parent_id) : null;
        if ($parent && $parent->channel === 'external' && $parent->direction === 'incoming'
            && (int) $parent->classification_value_id === (int) $letter->classification_value_id) {
            $this->shareOriginals((int) $parent->id, $recipient, $department, $action, $actorId, 'external_referral_handover');
        }
        DB::table('correspondence_tasks')->updateOrInsert(['correspondence_id' => $letter->id], ['action_id' => $action, 'assigned_to' => $recipient->id, 'department_id' => $department, 'status' => 'open', 'due_at' => $due, 'received_at' => null, 'read_at' => null, 'acknowledged_at' => null, 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->alert((int) $recipient->id, $action, $letter->public_id);
    }

    /**
     * The record as issued (and its approved replacement text, where editions are enabled) for its new reader.
     * A Board decision sent back to its board secretary for incomplete data comes back with the missing data as the
     * note and documents of the resubmission (leaving «record»); the reader gets those too, while the issued text
     * stays the only original. Returns how many of these the reader did not have yet.
     */
    private function grantOriginals(int $correspondenceId, User $recipient, int $department): int
    {
        $granted = 0;
        if (Schema::hasTable('correspondence_editions')) {
            foreach (DB::table('correspondence_editions')->where('correspondence_id', $correspondenceId)->where('status', 'approved')->pluck('action_id') as $publishedAction) {
                $granted += $this->grant((int) $publishedAction, $recipient, $department);
            }
        }
        foreach (DB::table('correspondence_actions')->where('correspondence_id', $correspondenceId)->where('is_original', true)->pluck('id') as $original) {
            $granted += $this->grant((int) $original, $recipient, $department);
        }
        $completions = DB::table('correspondence_actions as a')
            ->join('official_correspondences as c', 'c.id', '=', 'a.correspondence_id')
            ->join('workflow_events as e', 'e.id', '=', DB::raw("(a.metadata->>'workflow_event_id')::bigint"))
            ->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')
            ->where('a.correspondence_id', $correspondenceId)->where('a.action', 'workflow')
            ->whereColumn('a.actor_id', 'c.author_id')->where('s.code', 'record')
            ->pluck('a.id');
        foreach ($completions as $completion) {
            $granted += $this->grant((int) $completion, $recipient, $department);
        }

        return $granted;
    }

    /**
     * «إحالة»: one step that writes and sends a referral letter linked to the record, to someone who cannot see the
     * record yet. The Secretariat refers incoming external mail it archived, to the executive director or a head by
     * the usual routing rules; the head executing a Board decision hands part of the work to someone in the
     * department, while the decision stays with the head. The referral keeps the record's classification and
     * priority, its recipient reads the record's original, and the referral is logged on the record, which is not
     * otherwise changed. A referral is not an approval: the recipient answers on the referral letter.
     */
    public function refer(User $actor, string $publicId, array $input): object
    {
        $data = Validator::make($input, [
            'idempotency_key' => ['required', 'uuid'],
            'recipient_id' => ['required', 'integer'], 'recipient_department_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:20000'], 'due_at' => ['nullable', 'date', 'after:now'],
        ], [], ['note' => 'نص الإحالة', 'due_at' => 'موعد المتابعة'])->validate();
        $hash = hash('sha256', json_encode(['refer', $publicId, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $publicId, $data, $hash): object {
            $record = $this->access->visible($actor)->where('c.public_id', $publicId)->lockForUpdate()->first();
            abort_unless($record, 404);
            $prior = DB::table('correspondence_actions')->where('correspondence_id', $record->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($prior) {
                abort_unless((int) $prior->actor_id === (int) $actor->id && hash_equals($prior->payload_hash, $hash), 409);

                return DB::table('official_correspondences')->where('creation_key', $data['idempotency_key'])->first() ?? $record;
            }
            $source = $this->access->referralSource($actor, $record);
            abort_unless($source !== null, 403, 'الإحالة متاحة للسكرتاريا على البريد الوارد، ولرئيس القسم المكلف بتنفيذ قرار المجلس.');
            $recipient = User::findOrFail($data['recipient_id']);
            $destination = (int) $data['recipient_department_id'];
            abort_unless($record->channel === 'external' || $this->access->within($destination, $source), 403, 'يوزَّع العمل على قرار المجلس داخل القسم المكلف فقط.');
            $type = $this->constants('correspondence_types')->where('v.code', 'referral')->value('v.id');
            abort_unless($type, 422, 'نوع المراسلة «إحالة» غير مفعّل في القوائم المرجعية.');

            $referral = $this->draft($actor, [
                'creation_key' => $data['idempotency_key'], 'department_id' => $source,
                'recipient_id' => $recipient->id, 'recipient_department_id' => $destination,
                'type_value_id' => $type, 'classification_value_id' => $record->classification_value_id, 'priority_value_id' => $record->priority_value_id,
                'subject' => mb_substr('إحالة: '.$record->subject, 0, 250), 'body' => trim($data['note']),
                'letter_date' => today()->toDateString(), 'due_at' => $data['due_at'] ?? null, 'acknowledgment_required' => false,
                'parent_id' => $record->id,
            ], [], null, true);
            $this->record($actor, $record, 'refer', $data['idempotency_key'], $hash, trim($data['note']), $recipient, $destination, false, $source,
                ['referral_id' => (int) $referral->id, 'referral_reference' => $referral->reference]);

            return $referral;
        });
    }

    /**
     * A letter linked to an earlier record («المراسلة المرتبطة») goes only to someone who already sees that record,
     * unless the sender may refer it to them (CorrespondenceAccess::referralSource). The letter must then come from
     * that department and keep the record's classification, so the same confidentiality rules cover both. A Board
     * decision stays inside the executing department, and confidential or restricted mail goes to the executive
     * director only, who decides where it goes next. Returns why the record is shown to the recipient, or null when
     * they already see it.
     */
    private function parentShare(User $actor, int $parentId, int $department, int $classification, User $recipient, int $destination): ?string
    {
        $parent = $this->access->visible($actor)->where('c.id', $parentId)->first();
        abort_unless($parent, 404);
        if ($this->access->visible($recipient, false)->where('c.id', $parentId)->exists()) {
            return null;
        }
        $source = (int) $parent->classification_value_id === $classification ? $this->access->referralSource($actor, $parent) : null;
        $reaches = $parent->channel === 'external'
            ? DB::table('master_data_values')->where('id', $classification)->value('code') === 'internal' || $this->access->role($recipient, 'executive_director')
            : $this->access->within($destination, (int) $source);
        abort_unless($source !== null && $source === $department && $reaches, 403,
            'المستلم لا يطّلع على المراسلة المرتبطة. تُحال إليه من السكرتاريا للبريد الوارد (والسري أو المقيد إلى المدير التنفيذي فقط)، أو من رئيس القسم المكلف بتنفيذ قرار المجلس داخل قسمه، بسرية المراسلة نفسها.');

        return $parent->channel === 'external' ? 'external_referral' : 'board_distribution';
    }

    /**
     * The recipient of a referral reads the original of the record it refers to, its documents included. The record's
     * audit trail names everyone who gains that access, with the action that gave it.
     */
    private function shareOriginals(int $parentId, User $recipient, int $department, int $viaAction, int $actorId, string $reason): void
    {
        if ($this->grantOriginals($parentId, $recipient, $department) > 0) {
            $this->audit->recordRow('correspondence.original_shared', 'official_correspondences', $parentId, null,
                ['reason' => $reason, 'recipient_id' => (int) $recipient->id, 'department_id' => $department, 'via_action_id' => $viaAction], $actorId);
        }
    }

    public function alert(int $recipient, int $action, string $publicId, string $suffix = '', string $title = 'مراسلة رسمية تحتاج متابعة'): void
    {
        $this->notifications->notify($recipient, 'correspondence.assigned', ['title' => $title, 'url' => route('correspondence.show', $publicId, false), 'dedupe' => 'correspondence:'.$action.':u'.$recipient.$suffix]);
    }

    /**
     * The current holder's first view records receipt and reading (and acknowledgment when the sender asked for it),
     * so nobody has to log these by hand. Returns whether anything was recorded.
     */
    public function markOpened(User $actor, object $letter): bool
    {
        return DB::transaction(function () use ($actor, $letter): bool {
            $task = DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->lockForUpdate()->first();
            if (! $task || (int) $task->assigned_to !== (int) $actor->id || $task->status !== 'open'
                || ! $this->access->actionVisible($actor, (int) $task->action_id, false)
                || ($task->received_at !== null && $task->read_at !== null)) {
                return false;
            }
            $changes = ['received_at' => $task->received_at ?? now(), 'read_at' => $task->read_at ?? now(), 'updated_at' => now()];
            if ($letter->acknowledgment_required && $task->acknowledged_at === null) {
                $changes['acknowledged_at'] = now();
            }
            DB::table('correspondence_tasks')->where('id', $task->id)->update($changes);
            $this->record($actor, $letter, 'read', (string) Str::uuid(), hash('sha256', 'opened:'.$task->id.':'.$task->action_id), null, null, null, false, (int) $task->department_id);

            return true;
        });
    }

    private function issue(User $actor, object $letter): object
    {
        return $this->act($actor, $letter->public_id, ['idempotency_key' => (string) Str::uuid(), 'revision' => (int) $letter->revision, 'action' => 'issue']);
    }

    /**
     * @param  bool  $stageGrant  the recipient reads the record only because they hold its next stage
     */
    public function projectWorkflow(User $actor, object $letter, object $instance, int $event, string $transition, ?string $comment, int $source, ?User $recipient, ?int $department, bool $stageGrant = false): void
    {
        $id = $this->record($actor, $letter, 'workflow', (string) Str::uuid(), hash('sha256', 'workflow_event:'.$event), $comment, $recipient, $department, false, $source,
            ['workflow_event_id' => $event, 'transition' => $transition] + ($stageGrant ? ['access_basis' => 'workflow_stage'] : []));
        $closed = $instance->status === 'completed';
        DB::table('official_correspondences')->where('id', $letter->id)->update(['workflow_instance_id' => $instance->id, 'status' => $closed ? 'closed' : 'processing', 'revision' => (int) $letter->revision + 1, 'closed_at' => $closed ? now() : null, 'updated_at' => now()]);
        if ($recipient) {
            $this->assign($letter, $id, $recipient, $department, $instance->due_at ?? $letter->due_at, (int) $actor->id);
        } else {
            DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        }
    }
}
