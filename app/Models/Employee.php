<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        // Account
        'user_id',
        'employee_number',

        // Personal information
        'first_name_ar',
        'father_name_ar',
        'grandfather_name_ar',
        'family_name_ar',
        'full_name_en',
        'national_id',
        'identity_document_type_value_id',
        'nationality_value_id',
        'date_of_birth',
        'gender',
        'place_of_birth',
        'marital_status_value_id',

        // Contact information
        'phone',
        'alternative_phone',
        'personal_email',

        // Employment information
        'department_id',
        'job_category_id',
        'job_title_id',
        'job_grade_id',
        'employment_term_value_id',
        'contract_type_value_id',
        'work_schedule_value_id',
        'administrative_level_value_id',
        'hire_date',
        'work_start_date',
        'employment_status',

        // Education and family
        'has_qualifications',
        'has_family_members',

        // Onboarding
        'onboarding_status',
        'onboarding_step',
        'onboarding_completed_at',

        // Other
        'notes',
        'created_by',
        'updated_by',

        // Staff workbook fields (source text kept beside the approved references)
        'full_name_ar',
        'original_family_name_ar',
        'name_review_status',
        'children_count',
        'landline_phone',
        'residence_address',
        'attendance_number',
        'job_title_source',
        'department_source',
        'grade_source',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'work_start_date' => 'date',

            'marital_status_value_id' => 'integer',
            'identity_document_type_value_id' => 'integer',
            'nationality_value_id' => 'integer',
            'employment_term_value_id' => 'integer',
            'contract_type_value_id' => 'integer',
            'work_schedule_value_id' => 'integer',
            'administrative_level_value_id' => 'integer',

            'has_qualifications' => 'boolean',
            'has_family_members' => 'boolean',

            'onboarding_step' => 'integer',
            'onboarding_completed_at' => 'datetime',
            'children_count' => 'integer',
        ];
    }

    // =================================================
    // 1. Login Account
    // =================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // =================================================
    // 2. Organization
    // =================================================

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    // =================================================
    // 3. Master Data
    // =================================================

    public function maritalStatus(): BelongsTo
    {
        return $this->belongsTo(
            MasterDataValue::class,
            'marital_status_value_id'
        );
    }

    public function identityDocumentType(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'identity_document_type_value_id');
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'nationality_value_id');
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

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(MasterDataValue::class, 'work_schedule_value_id');
    }

    public function administrativeLevel(): BelongsTo
    {
        return $this->belongsTo(
            MasterDataValue::class,
            'administrative_level_value_id'
        );
    }

    // =================================================
    // 4. Addresses
    // =================================================

    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class);
    }

    // =================================================
    // 5. Qualifications
    // =================================================

    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class);
    }

    // =================================================
    // 6. Family
    // =================================================

    public function familyMembers(): HasMany
    {
        return $this->hasMany(EmployeeFamilyMember::class);
    }

    // =================================================
    // 7. Audit
    // =================================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // =================================================
    // 8. Onboarding Status
    // =================================================

    public function isOnboardingCompleted(): bool
    {
        return in_array(
            $this->onboarding_status,
            ['pending_activation', 'completed'],
            true
        );
    }

    public function isOnboardingDraft(): bool
    {
        return $this->onboarding_status === 'draft';
    }
}
