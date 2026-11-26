<?php

namespace App\Models;

use App\Services\Orphans\Identity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One human, stored once (child, guardian, father, mother, household contact). Department roles hang off it. */
class Person extends Model
{
    use HasUlids;

    protected $table = 'persons';

    protected $guarded = ['id'];

    protected $hidden = ['id_number', 'id_hash', 'restricted_notes'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['id_number' => 'encrypted', 'restricted_notes' => 'encrypted', 'date_of_birth' => 'date', 'id_verified_at' => 'datetime'];
    }

    public function orphan(): HasOne
    {
        return $this->hasOne(Orphan::class);
    }

    public function guardianProfile(): HasOne
    {
        return $this->hasOne(OrphanGuardian::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(RegistryAddress::class);
    }

    public function maskedId(): string
    {
        return Identity::mask($this->id_last4);
    }

    /** Age in full years today; derived, never stored as authoritative. */
    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
