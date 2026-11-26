<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_version_id',
        'code',
        'name',
        'sort_order',
        'step_type',
        'assignment_type',
        'assigned_role_code',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowVersion::class,
            'workflow_version_id'
        );
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(
            WorkflowTransition::class,
            'from_step_id'
        );
    }

    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(
            WorkflowTransition::class,
            'to_step_id'
        );
    }
}
