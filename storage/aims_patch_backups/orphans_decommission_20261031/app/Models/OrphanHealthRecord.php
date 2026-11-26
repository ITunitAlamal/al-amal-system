<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Health snapshot of a child; each update adds a row and keeps the previous ones as history. */
class OrphanHealthRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['chronic_note' => 'encrypted', 'medications' => 'encrypted', 'follow_up' => 'encrypted', 'disability_types' => 'array',
            'has_chronic' => 'boolean', 'has_disability' => 'boolean', 'is_current' => 'boolean'];
    }
}
