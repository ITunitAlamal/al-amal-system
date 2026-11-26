<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeQualification extends Model
{
    protected $fillable = [
        'employee_id',
        'qualification_name',
        'degree_level',
        'institution_name',
        'specialization',
        'issuing_country_code',
        'study_start_date',
        'graduation_date',
        'graduation_year',
        'grade',
        'gpa',
        'gpa_scale',
        'certificate_number',
        'notes',
        'is_highest_qualification',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'study_start_date' => 'date',
            'graduation_date' => 'date',
            'graduation_year' => 'integer',
            'gpa' => 'decimal:3',
            'is_highest_qualification' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
