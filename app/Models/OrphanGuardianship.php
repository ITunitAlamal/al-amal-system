<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A direct child↔guardian relationship. "claimed"/"pending" is NOT legal guardianship; only an approved link whose
 * dates include today gives portal access to the child.
 */
class OrphanGuardianship extends Model
{
    public const LIVE = ['claimed', 'pending', 'approved'];

    public const STATUS_LABELS = [
        'claimed' => 'مُدّعاة (غير متحقق منها)',
        'pending' => 'بانتظار التحقق',
        'approved' => 'معتمدة',
        'rejected' => 'مرفوضة',
        'ended' => 'منتهية',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provenance' => 'array', 'is_primary' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date', 'decided_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function orphan(): BelongsTo
    {
        return $this->belongsTo(Orphan::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(OrphanGuardian::class, 'guardian_id');
    }

    /** Approved and in force today: the only state that grants portal access. */
    public function isEffective(): bool
    {
        $today = today();

        return $this->status === 'approved' && (! $this->starts_on || $this->starts_on->lte($today)) && (! $this->ends_on || $this->ends_on->gte($today));
    }
}
