<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuardianApplication extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['deceased_national_id', 'deceased_id_hash', 'verification_payload'];

    protected function casts(): array
    {
        return ['deceased_national_id' => 'encrypted', 'verification_payload' => 'encrypted:array',
            'verified_at' => 'datetime', 'reviewed_at' => 'datetime',
            'verification_revision' => 'integer', 'imported_children_count' => 'integer'];
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }
}
