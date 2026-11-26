<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The authoritative orphan file. Never exists without a live guardianship (database constraint trigger). */
class Orphan extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['restricted_notes', 'loss_circumstances'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['restricted_notes' => 'encrypted', 'loss_circumstances' => 'encrypted', 'registered_on' => 'date', 'review_due_on' => 'date', 'completeness_sections' => 'array'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function guardianships(): HasMany
    {
        return $this->hasMany(OrphanGuardianship::class);
    }

    public function parentLinks(): HasMany
    {
        return $this->hasMany(OrphanParentLink::class);
    }
}
