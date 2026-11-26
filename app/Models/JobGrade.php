<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobGrade extends Model
{
    use HasFactory;

    protected $fillable = ['career_scale_id', 'grade_level', 'salary_min', 'salary_max', 'currency_code', 'code', 'name_ar', 'name_en', 'description', 'sort_order', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'grade_level' => 'integer', 'sort_order' => 'integer', 'salary_min' => 'decimal:2', 'salary_max' => 'decimal:2'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
