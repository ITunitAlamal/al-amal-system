<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Correspondence\CorrespondenceRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('correspondence:reconcile')]
#[Description('Reconcile authorized correspondence assignments and deduplicated reminders')]
final class CorrespondenceReconcile extends Command
{
    public function handle(CorrespondenceRegistry $registry, CorrespondenceAccess $access): int
    {
        if (! Schema::hasTable('correspondence_tasks')) {
            return self::SUCCESS;
        }
        $checked = 0;
        $stale = 0;
        DB::table('correspondence_tasks as t')->join('official_correspondences as c', 'c.id', '=', 't.correspondence_id')
            ->where('t.status', 'open')->select(['t.*', 'c.public_id'])->orderBy('t.id')->chunkById(200, function ($tasks) use ($registry, $access, &$checked, &$stale): void {
                $users = User::whereIn('id', $tasks->pluck('assigned_to'))->get()->keyBy('id');
                foreach ($tasks as $task) {
                    $user = $users->get($task->assigned_to);
                    if (! $user || ! $access->actionVisible($user, (int) $task->action_id)) {
                        $stale++;

                        continue;
                    }
                    $registry->alert((int) $task->assigned_to, (int) $task->action_id, $task->public_id);
                    if ($task->due_at && $task->due_at < now()->toDateTimeString()) {
                        $registry->alert((int) $task->assigned_to, (int) $task->action_id, $task->public_id, ':overdue:'.today()->toDateString(), 'مراسلة رسمية تجاوزت موعد المتابعة');
                        $policy = $access->policies('escalation')->first();
                        if ($policy && filled($policy->approval_reference)) {
                            $timings = json_decode($policy->configuration, true);
                            $overdueHours = Carbon::parse($task->due_at)->diffInHours(now());
                            $candidates = $access->assignments()->filter(fn (object $assignment): bool => ($assignment->code === 'executive_director' && $overdueHours >= ($timings['executive_after_hours'] ?? PHP_INT_MAX))
                                || (in_array($assignment->code, ['department_head', 'acting_department_head'], true)
                                    && (int) $assignment->department_id === (int) $task->department_id
                                    && $overdueHours >= ($timings['head_after_hours'] ?? PHP_INT_MAX)));
                            foreach (User::whereIn('id', $candidates->pluck('user_id')->unique())->get() as $recipient) {
                                if ((int) $recipient->id !== (int) $user->id
                                    && ($access->role($recipient, 'executive_director') || $access->head($recipient, (int) $task->department_id))
                                    && $access->visible($recipient)->where('c.id', $task->correspondence_id)->exists()) {
                                    $registry->alert((int) $recipient->id, (int) $task->action_id, $task->public_id,
                                        ':escalation:'.today()->toDateString(), 'مراسلة رسمية تحتاج متابعة إدارية');
                                }
                            }
                        }
                    }
                    $checked++;
                }
            }, 't.id', 'id');
        $this->info("Checked: {$checked}; assignments requiring administrative review: {$stale}.");

        return $stale ? self::FAILURE : self::SUCCESS;
    }
}
