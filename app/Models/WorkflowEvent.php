<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEvent extends Model
{
    // جدول workflow_events يحتوي على created_at فقط.
    public const UPDATED_AT = null;

    protected $fillable = [
        'workflow_instance_id',
        'workflow_transition_id',
        'from_step_id',
        'to_step_id',
        'actor_id',
        'assigned_to_id',
        'action',
        'comment',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowInstance::class,
            'workflow_instance_id'
        );
    }

    public function transition(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowTransition::class,
            'workflow_transition_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to_id'
        );
    }

    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowStep::class,
            'from_step_id'
        );
    }

    public function toStep(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowStep::class,
            'to_step_id'
        );
    }
}
