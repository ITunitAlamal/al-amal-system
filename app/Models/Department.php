<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'parent_id',
        'is_active',
        'head_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'head_user_id' => 'integer',
        ];
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'head_user_id'
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'parent_id'
        );
    }
}
