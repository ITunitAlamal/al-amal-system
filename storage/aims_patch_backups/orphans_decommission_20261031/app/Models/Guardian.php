<?php

namespace App\Models;

use App\Services\Orphans\NameIndex;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Guardian extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'national_id', 'national_id_hash', 'phone', 'password'];

    protected $hidden = ['national_id', 'national_id_hash', 'password', 'remember_token', 'name_index'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'alt_phone' => 'encrypted', 'password' => 'hashed', 'is_active' => 'boolean', 'date_of_birth' => 'date', 'name_parts' => 'encrypted:array'];
    }

    /** Keeps the keyed-hash name index in step with the name on every save. */
    protected static function booted(): void
    {
        static::saving(function (Guardian $guardian): void {
            if ($guardian->isDirty(['name', 'name_parts']) || ! $guardian->exists) {
                $guardian->name_index = NameIndex::literal(NameIndex::tokens($guardian->name_parts, $guardian->name));
            }
        });
    }

    public function applications(): HasMany
    {
        return $this->hasMany(GuardianApplication::class);
    }

    public function children(): BelongsToMany
    {
        // Only APPROVED live links give a guardian a child (portal access depends on this); a pending or rejected
        // claim never does. Authority ends on its end date; the link row itself is kept as history (AT-14).
        return $this->belongsToMany(OrphanChild::class, 'orphan_guardianships')
            ->where('orphan_guardianships.status', 'approved')->whereNull('orphan_guardianships.ended_reason')
            ->where(fn ($query) => $query->whereNull('orphan_guardianships.ends_on')
                ->orWhere('orphan_guardianships.ends_on', '>=', now(config('orphans.timezone'))->toDateString()));
    }
}
