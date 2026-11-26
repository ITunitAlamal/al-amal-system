<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Original, current or displacement address of a person or a household (exactly one owner). */
class RegistryAddress extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['detail'];

    protected function casts(): array
    {
        return ['detail' => 'encrypted', 'starts_on' => 'date', 'ends_on' => 'date', 'verified_at' => 'datetime'];
    }
}
