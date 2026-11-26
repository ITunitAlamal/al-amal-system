<?php

declare(strict_types=1);

namespace App\Services\Progress;

use Illuminate\Support\Facades\DB;

/**
 * Stepper for single-decision transactions (request → decision → optional execution), built from the
 * columns the record already keeps (status, who decided, when). Used by delegations, attendance
 * schedule changes and assignments, and compensatory work. Steps without evidence stay "upcoming".
 */
final class ApprovalProgress
{
    /** @var array<int, string|null> */
    private array $names = [];

    /**
     * @param  array{requestLabel?: string, requestedBy?: ?int, requestedAt?: ?string, decisionLabel: string, decidedBy?: ?int, decidedAt?: ?string, decisionOwner?: ?string, note?: ?string, execution?: ?array{label: string, done: bool, at?: ?string, owner?: ?string}}  $config
     * @return list<array<string, mixed>>
     */
    public function steps(string $status, array $config): array
    {
        $pending = in_array($status, ['pending', 'submitted'], true);
        $approved = in_array($status, ['approved', 'implemented', 'credited', 'active', 'applied'], true);
        $rejected = $status === 'rejected';
        $cancelled = $status === 'cancelled';

        $steps = [
            $this->step($config['requestLabel'] ?? 'تقديم الطلب', $status === 'draft' ? 'current' : 'done', $this->name($config['requestedBy'] ?? null), $config['requestedAt'] ?? null),
            $this->step(
                $rejected ? 'رفض الطلب' : $config['decisionLabel'],
                match (true) {
                    $pending => 'current',
                    $approved => 'done',
                    $rejected => 'rejected',
                    $cancelled => 'cancelled',
                    default => 'upcoming',
                },
                $this->name($config['decidedBy'] ?? null) ?? ($pending ? ($config['decisionOwner'] ?? null) : null),
                $config['decidedAt'] ?? null,
                $rejected || $cancelled ? ($config['note'] ?? null) : null,
            ),
        ];

        if (! empty($config['execution'])) {
            $execution = $config['execution'];
            $steps[] = $this->step(
                $execution['label'],
                match (true) {
                    $execution['done'] => 'done',
                    $approved => 'current',
                    $rejected || $cancelled => 'skipped',
                    default => 'upcoming',
                },
                $execution['owner'] ?? null,
                $execution['at'] ?? null,
            );
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $label, string $state, ?string $owner, ?string $completedAt, ?string $note = null): array
    {
        return [
            'label' => $label, 'state' => $state, 'owner' => $owner, 'startedAt' => null,
            'completedAt' => $state === 'done' || $state === 'rejected' ? $completedAt : null,
            'dueAt' => null, 'overdue' => false, 'attempt' => 1, 'note' => $note,
        ];
    }

    private function name(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        return $this->names[$userId] ??= DB::table('users')->where('id', $userId)->value('name');
    }
}
