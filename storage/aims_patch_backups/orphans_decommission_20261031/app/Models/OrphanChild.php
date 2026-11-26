<?php

namespace App\Models;

use App\Services\Orphans\NameIndex;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrphanChild extends Model
{
    use HasFactory;

    public const STATUS_LABELS = [
        'applicant' => 'طلب قيد الدراسة', 'pending_activation' => 'بانتظار قرار التفعيل', 'active' => 'ملف نشط',
        'suspended' => 'ملف موقوف', 'closed' => 'ملف مغلق',
    ];

    public const VERIFICATION_LABELS = [
        'unverified' => 'غير متحقق', 'api_verified' => 'متحقق من السجل المدني', 'manual_verified' => 'متحقق بمراجعة موثقة', 'conflict' => 'تعارض',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['national_id', 'national_id_hash', 'source_child_id', 'name_index'];

    /** Keeps the keyed-hash name index in step with the (encrypted) name on every save. */
    protected static function booted(): void
    {
        static::saving(function (OrphanChild $child): void {
            if ($child->isDirty(['full_name', 'name_parts']) || ! $child->exists) {
                $child->name_index = NameIndex::literal(NameIndex::tokens($child->name_parts, $child->full_name));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted', 'full_name' => 'encrypted', 'name_parts' => 'encrypted:array', 'english_name' => 'encrypted',
            'phone' => 'encrypted', 'date_of_birth' => 'date', 'sole_survivor' => 'boolean', 'registered_at' => 'datetime', 'activated_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(OrphanHousehold::class, 'household_id');
    }

    public function father(): BelongsTo
    {
        return $this->belongsTo(OrphanParent::class, 'father_id');
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(OrphanParent::class, 'mother_id');
    }

    public function guardians(): BelongsToMany
    {
        // Approved live links only; pending claims are listed by GuardianLinks, never treated as guardianship.
        return $this->belongsToMany(Guardian::class, 'orphan_guardianships')
            ->where('orphan_guardianships.status', 'approved')->whereNull('orphan_guardianships.ended_reason')
            ->where(fn ($query) => $query->whereNull('orphan_guardianships.ends_on')
                ->orWhere('orphan_guardianships.ends_on', '>=', now(config('orphans.timezone'))->toDateString()));
    }

    public function ageOn(?\DateTimeInterface $date = null): int
    {
        return (int) $this->date_of_birth->diffInYears($date ?? now(config('orphans.timezone')));
    }
}
