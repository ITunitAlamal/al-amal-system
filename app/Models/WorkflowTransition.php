<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTransition extends Model
{
    protected $fillable = [
        'workflow_version_id',
        'from_step_id',
        'to_step_id',
        'action_code',
        'name',
        'conditions',
        'requires_comment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'requires_comment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowVersion::class,
            'workflow_version_id'
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
