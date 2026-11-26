<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Beneficiary portal account (guard `guardian`). */
class Guardian extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'national_id', 'national_id_hash', 'phone', 'password'];

    protected $hidden = ['national_id', 'national_id_hash', 'password', 'remember_token'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'password' => 'hashed', 'is_active' => 'boolean', 'must_change_password' => 'boolean',
            'phone_verified_at' => 'datetime', 'sms_consent_at' => 'datetime', 'last_login_at' => 'datetime'];
    }
}
