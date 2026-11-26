<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A father or mother, stored once and linked from each child (siblings and half-siblings share the same row). */
class OrphanParent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['identity_no', 'identity_hash'];

    protected function casts(): array
    {
        return ['full_name' => 'encrypted', 'identity_no' => 'encrypted', 'illness_note' => 'encrypted', 'disability_types' => 'array',
            'date_of_birth' => 'date', 'death_date' => 'date', 'has_illness' => 'boolean', 'has_disability' => 'boolean'];
    }
}
