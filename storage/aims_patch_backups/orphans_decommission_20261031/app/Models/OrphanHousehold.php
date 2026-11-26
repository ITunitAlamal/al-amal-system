<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One family file shared by its children, so household facts are never copied into each child's record. */
class OrphanHousehold extends Model
{
    public const STATUS_LABELS = ['draft' => 'مسودة', 'registered' => 'مسجلة قيد الدراسة', 'active' => 'نشطة', 'inactive' => 'غير نشطة', 'closed' => 'مغلقة'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['family_name' => 'encrypted', 'basic_needs' => 'encrypted', 'notes' => 'encrypted', 'displaced' => 'boolean',
            'displaced_since' => 'date', 'monthly_income' => 'decimal:2', 'rent_amount' => 'decimal:2'];
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrphanChild::class, 'household_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'primary_guardian_id');
    }
}
