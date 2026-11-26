<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEventAttachment extends Model
{
    protected $table = 'workflow_event_attachments';

    protected $fillable = [
        'workflow_event_id',
        'uploaded_by',
        'public_id',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'sha256_checksum',
        'visibility_class',
        'document_type',
        'description',
    ];

    protected $hidden = [
        'storage_disk',
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function workflowEvent(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowEvent::class,
            'workflow_event_id'
        );
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }
}
