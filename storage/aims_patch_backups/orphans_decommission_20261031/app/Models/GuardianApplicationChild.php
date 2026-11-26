<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child's verification result inside a guardian application run.
 */
class GuardianApplicationChild extends Model
{
    public const RESULTS = ['verified', 'pending', 'conflict', 'incomplete', 'ineligible'];

    protected $guarded = ['id'];

    protected $hidden = ['national_id', 'national_id_hash', 'source_child_id', 'authority_reference'];

    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'national_id' => 'encrypted',
            'authority_reference' => 'encrypted',
            'date_of_birth' => 'date',
            'authority_valid_until' => 'date',
            'reasons' => 'array',
            'linked' => 'boolean',
            'decided_at' => 'datetime',
            'verification_revision' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(GuardianApplication::class, 'guardian_application_id');
    }

    public function orphanChild(): BelongsTo
    {
        return $this->belongsTo(OrphanChild::class);
    }
}
