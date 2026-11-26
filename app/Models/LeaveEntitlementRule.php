<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveEntitlementRule extends Model
{
    protected $table = 'leave_entitlement_rules';

    protected $fillable = [
        'employment_term_value_id',
        'contract_type_value_id',
        'leave_type_value_id',
        'min_service_months',
        'max_service_months',
        'annual_entitlement_days',
        'effective_from',
        'effective_until',
        'is_active',
        'code',
        'name_ar',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'employment_term_value_id' => 'integer',
            'contract_type_value_id' => 'integer',
            'leave_type_value_id' => 'integer',
            'min_service_months' => 'integer',
            'max_service_months' => 'integer',
            'annual_entitlement_days' => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function employmentTerm(): BelongsTo
    {
        return $this->belongsTo(
            MasterDataValue::class,
            'employment_term_value_id'
        );
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'contract_type_value_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(
            MasterDataValue::class,
            'leave_type_value_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
