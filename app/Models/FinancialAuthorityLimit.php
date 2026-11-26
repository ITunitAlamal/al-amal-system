<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAuthorityLimit extends Model
{
    protected $fillable = [
        'authority_code',
        'action_code',
        'currency',
        'limit_minor_units',
        'is_unlimited',
        'is_active',
        'decision_reference',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'limit_minor_units' => 'integer',
            'is_unlimited' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function currencyRecord(): BelongsTo
    {
        return $this->belongsTo(
            Currency::class,
            'currency',
            'code'
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }
}
