<?php

namespace App\Services\Workflow;

/**
 * A module record that runs through the workflow engine (for example a leave request), instead of a
 * generic aims_request. The engine moves the request between steps; the module applies its own effect
 * (status, balance, notices) in the same database transaction, so a failed effect undoes the move.
 */
interface WorkflowSubject
{
    /**
     * Inside the move transaction, before anything is written. Throw a ValidationException to stop the move.
     */
    public function beforeMove(object $instance, object $from, object $target, int $actorId): void;

    /**
     * Inside the move transaction, after the instance moved to $target.
     */
    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void;

    /**
     * Values the transition conditions may test (e.g. {"requested_days": {"gt": 14}}).
     *
     * @return array<string, mixed>
     */
    public function conditionContext(object $instance): array;

    /**
     * Relative link of the record's own screen (no database access: used in lists).
     */
    public function url(int $subjectId): string;

    /**
     * Short titles for lists and inboxes, read in one query for many records.
     *
     * @param  list<int>  $subjectIds
     * @return array<int, string> subject id => title
     */
    public function titles(array $subjectIds): array;
}
