<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormDraft extends Model
{
    protected $fillable = [
        'user_id',
        'draft_key',
        'form_type',
        'payload',
        'version',
        'last_saved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version' => 'integer',
            'last_saved_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
