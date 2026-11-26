<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowInstance extends Model
{
    protected $fillable = [
        'public_id',
        'workflow_version_id',
        'subject_type',
        'subject_id',
        'department_id',
        'requested_by',
        'current_step_id',
        'current_assignee_id',
        'status',
        'revision',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowVersion::class,
            'workflow_version_id'
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by'
        );
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowStep::class,
            'current_step_id'
        );
    }

    public function currentAssignee(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'current_assignee_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            WorkflowEvent::class,
            'workflow_instance_id'
        )->orderBy('id');
    }
}
