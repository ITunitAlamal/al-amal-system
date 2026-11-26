<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A guardian in the department registry; may exist before any child is linked. */
class OrphanGuardian extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function guardianships(): HasMany
    {
        return $this->hasMany(OrphanGuardianship::class, 'guardian_id');
    }

    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'portal_account_id');
    }
}
