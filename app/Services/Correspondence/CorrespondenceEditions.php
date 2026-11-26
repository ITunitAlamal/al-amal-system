<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CorrespondenceEditions
{
    public function __construct(private readonly CorrespondenceAccess $access, private readonly CorrespondenceDocuments $documents, private readonly AuditService $audit) {}

    public function effective(int $letterId): ?object
    {
        return Schema::hasTable('correspondence_editions') ? DB::table('correspondence_editions')->where('correspondence_id', $letterId)->where('status', 'approved')->orderByDesc('version')->first() : null;
    }

    public function canView(User $user, object $edition): bool
    {
        return $this->access->actionVisible($user, (int) $edition->action_id);
    }

    public function propose(User $actor, string $publicId, array $input, array $files): object
    {
        abort_unless(Schema::hasTable('correspondence_editions'), 503, 'لم تُثبت ترقية النسخ النصية بعد.');
        $data = Validator::make($input, ['submission_key' => ['required', 'uuid'], 'subject' => ['required', 'string', 'max:250'], 'body' => ['required', 'string', 'max:50000'], 'reason' => ['required', 'string', 'max:5000'], 'substantive' => ['required', 'boolean']])->validate();
        $hash = hash('sha256', json_encode([$data, $this->documents->validate($files)], JSON_THROW_ON_ERROR));
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $publicId, $data, $hash, $files, &$written): object {
                $letter = $this->access->visible($actor)->where('c.public_id', $publicId)->lockForUpdate()->first();
                abort_unless($letter, 404);
                abort_unless((int) $letter->author_id === (int) $actor->id && $this->access->member($actor, (int) $letter->department_id), 403);
                abort_unless($letter->issued_at && ! in_array($letter->status, ['draft', 'withdrawn', 'archived'], true), 422);
                $prior = DB::table('correspondence_editions')->where('submission_key', $data['submission_key'])->first();
                if ($prior) {
                    abort_unless((int) $prior->correspondence_id === (int) $letter->id && hash_equals($prior->payload_hash, $hash), 409);

                    return $prior;
                }
                abort_if(DB::table('correspondence_editions')->where('correspondence_id', $letter->id)->where('status', 'pending')->exists(), 409, 'توجد نسخة قيد الاعتماد.');
                $previous = $this->effective((int) $letter->id);
                $before = ['subject' => $previous?->subject ?? $letter->subject, 'body' => $previous?->body ?? $letter->body];
                abort_if($before === ['subject' => $data['subject'], 'body' => $data['body']], 422, 'لم يتغير النص.');
                $issue = DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->where('action', 'issue')->first();
                $authority = $issue ? (json_decode($issue->metadata, true)['authority']['authority_role'] ?? null) : null;
                abort_unless(in_array($authority, ['staff', 'head', 'executive_director', 'secretariat', 'board_secretary'], true), 422, 'صفة المصدر عند الإصدار غير موثقة؛ يلزم تدقيق السجل.');
                $type = DB::table('master_data_values')->where('id', $letter->type_value_id)->value('code');
                $board = $type === 'board_transmission' || filled($letter->board_reference)
                    || ($letter->parent_id && DB::table('official_correspondences as p')->join('master_data_values as v', 'v.id', '=', 'p.type_value_id')->where('p.id', $letter->parent_id)->where('v.code', 'board_transmission')->exists());
                $origin = $type === 'board_transmission' || $authority === 'board_secretary' || ($board && $data['substantive']) ? 'board'
                    : ($authority === 'executive_director' ? 'executive' : ($authority === 'head' ? 'head' : 'staff'));
                $action = DB::table('correspondence_actions')->insertGetId(['public_id' => (string) Str::uuid(), 'correspondence_id' => $letter->id, 'actor_id' => $actor->id, 'idempotency_key' => $data['submission_key'], 'payload_hash' => $hash, 'action' => 'edition_proposed', 'is_original' => false, 'note' => $data['reason'], 'metadata' => json_encode(['origin_role' => $origin, 'predecessor_id' => $previous?->id]), 'created_at' => now()]);
                $this->grant($action, $actor, (int) $letter->department_id);
                $id = DB::table('correspondence_editions')->insertGetId(['public_id' => (string) Str::uuid(), 'submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'correspondence_id' => $letter->id, 'predecessor_id' => $previous?->id, 'version' => (int) DB::table('correspondence_editions')->where('correspondence_id', $letter->id)->max('version') + 1, 'proposed_by' => $actor->id, 'action_id' => $action, 'origin_role' => $origin, 'board_related' => $board, 'substantive' => $data['substantive'], 'subject' => $data['subject'], 'body' => $data['body'], 'reason' => $data['reason'], 'differences' => json_encode(['before' => $before, 'after' => ['subject' => $data['subject'], 'body' => $data['body']]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                $this->documents->store($actor, $letter, $action, $files, [], $written);
                $instance = app(WorkflowEngine::class)->startForSubject($actor, 'CORRESPONDENCE_EDITION_'.strtoupper($origin), 'correspondence_edition', $id, (int) $letter->department_id);
                DB::table('correspondence_editions')->where('id', $id)->update(['workflow_instance_id' => $instance->id]);
                $this->audit->recordRow('correspondence.edition.proposed', 'correspondence_editions', $id, null, ['workflow_instance_id' => $instance->id, 'origin_role' => $origin], (int) $actor->id);

                return DB::table('correspondence_editions')->find($id);
            });
        } catch (\Throwable $exception) {
            $this->documents->cleanup($written);
            throw $exception;
        }
    }

    public function grant(int $action, User $user, int $department): void
    {
        DB::table('correspondence_access')->insertOrIgnore(['action_id' => $action, 'user_id' => $user->id, 'department_id' => $department, ...$this->access->authority($user, $department), 'created_at' => now()]);
    }
}
