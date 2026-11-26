<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencySetting extends Model
{
    protected $table = 'aims_currency_settings';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'base_currency_code',
        'updated_by',
    ];

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(
            Currency::class,
            'base_currency_code',
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
