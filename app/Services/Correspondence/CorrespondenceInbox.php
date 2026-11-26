<?php

namespace App\Services\Correspondence;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CorrespondenceInbox
{
    public function __construct(private readonly CorrespondenceAccess $access) {}

    public function recent(User $user): array
    {
        // Use the last delivery to this recipient, rather than updates made by other users.
        $deliveries = DB::table('correspondence_actions as delivery')
            ->where('delivery.recipient_id', $user->id)
            ->whereIn('delivery.id', $this->access->accessQuery($user)->select('access.action_id'))
            ->selectRaw('delivery.correspondence_id, MAX(delivery.id) AS action_id')
            ->groupBy('delivery.correspondence_id');

        $query = $this->access->mailbox($user, 'inbox')->where('c.channel', 'internal')
            ->joinSub($deliveries, 'latest', 'latest.correspondence_id', '=', 'c.id')
            ->join('correspondence_actions as incoming', 'incoming.id', '=', 'latest.action_id')
            ->leftJoin('users as sender', 'sender.id', '=', 'incoming.actor_id')
            ->leftJoin('correspondence_tasks as task', 'task.action_id', '=', 'incoming.id');

        $unread = (clone $query)->where('task.assigned_to', $user->id)
            ->where('task.status', 'open')->whereNull('task.read_at')->count();

        $items = $query->orderByDesc('incoming.id')->limit(8)
            ->get(['incoming.id', 'incoming.created_at', 'c.public_id', 'c.subject', 'c.reference',
                'sender.name as sender_name', 'task.assigned_to', 'task.status', 'task.read_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => $row->subject,
                'body' => implode(' — ', array_filter([$row->sender_name, $row->reference])),
                'url' => route('correspondence.show', $row->public_id, false),
                'read' => (int) $row->assigned_to !== (int) $user->id || $row->status !== 'open' || $row->read_at !== null,
                'createdAt' => (string) $row->created_at,
            ]);

        return ['items' => $items, 'unread' => $unread];
    }
}
