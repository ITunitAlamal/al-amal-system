<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeAccountService
{
    /**
     * Create a pending employee account and profile atomically.
     *
     * The caller is responsible for authorization and validation of all
     * submitted employee data. SMS issuance is deliberately a separate step.
     * No usable or recoverable temporary password is issued.
     *
     * @return array{employee: Employee, user: User}
     */
    public function create(array $employeeData, array $accountData = []): array
    {
        $number = trim((string) ($employeeData['employee_number'] ?? ''));
        $phone = trim((string) ($employeeData['phone'] ?? ''));

        if ($number === '' || ! preg_match('/^[A-Za-z0-9_-]{1,50}$/', $number)) {
            throw ValidationException::withMessages([
                'employee_number' => 'يرجى إدخال رقم وظيفي صالح.',
            ]);
        }

        if (! preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
            throw ValidationException::withMessages([
                'phone' => 'الجوال الأساسي مطلوب ويجب أن يكون رقمًا دوليًا صالحًا (8 إلى 15 رقمًا).',
            ]);
        }

        if (empty(trim((string) ($employeeData['first_name_ar'] ?? '')))
            || empty(trim((string) ($employeeData['family_name_ar'] ?? '')))) {
            throw ValidationException::withMessages([
                'first_name_ar' => 'الاسم الأول واسم العائلة مطلوبان.',
            ]);
        }

        // Do not copy arbitrary account attributes, roles or permissions
        // supplied by callers into the authentication record.
        return DB::transaction(function () use ($employeeData, $number, $phone): array {
            if (User::query()->where('employee_number', $number)->exists()
                || Employee::withTrashed()->where('employee_number', $number)->exists()) {
                throw ValidationException::withMessages([
                    'employee_number' => 'الرقم الوظيفي مستخدم مسبقًا.',
                ]);
            }

            $fullName = trim(implode(' ', array_filter([
                $employeeData['first_name_ar'] ?? null,
                $employeeData['father_name_ar'] ?? null,
                $employeeData['grandfather_name_ar'] ?? null,
                $employeeData['family_name_ar'] ?? null,
            ], fn ($part) => $part !== null && trim((string) $part) !== '')));

            $user = new User;
            $user->name = $fullName;
            $user->employee_number = $number;
            $user->email = null;
            $user->department_id = $employeeData['department_id'] ?? null;

            // A non-disclosed, high-entropy random secret satisfies the
            // existing NOT NULL password column. It is never communicated,
            // and login must reject inactive users until SMS activation.
            $user->password = Hash::make(Str::random(80));
            $user->is_active = false;
            $user->must_change_password = false;
            $user->save();

            $allowedProfileFields = [
                'employee_number', 'first_name_ar', 'father_name_ar',
                'grandfather_name_ar', 'family_name_ar', 'full_name_en',
                'national_id', 'identity_document_type_value_id', 'nationality_value_id', 'date_of_birth', 'gender', 'place_of_birth',
                'marital_status_value_id', 'phone',
                'alternative_phone', 'personal_email', 'department_id',
                'job_category_id', 'job_title_id', 'job_grade_id',
                'employment_term_value_id', 'contract_type_value_id', 'work_schedule_value_id', 'administrative_level_value_id',
                'has_qualifications', 'has_family_members',
                'hire_date', 'work_start_date', 'employment_status', 'notes',
                'full_name_ar', 'original_family_name_ar', 'children_count', 'landline_phone', 'residence_address', 'attendance_number',
            ];

            $profileData = array_intersect_key($employeeData, array_flip($allowedProfileFields));
            $profileData['employee_number'] = $number;
            $profileData['phone'] = $phone;

            $employee = new Employee;
            $employee->fill($profileData);
            $employee->user_id = $user->id;
            $employee->created_by = auth()->id();
            $employee->updated_by = auth()->id();
            $employee->save();

            return [
                'employee' => $employee,
                'user' => $user,
            ];
        });
    }

    /**
     * A pending login account for a profile that has none (for example one created by the staff import). The caller
     * holds the profile lock and has authorised HR. Same rules as create(): inactive, random undisclosed secret,
     * activation by SMS only.
     */
    public function createForProfile(Employee $employee): User
    {
        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['account' => 'الملف مرتبط بحساب دخول مسبقًا.']);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,50}$/', (string) $employee->employee_number)) {
            throw ValidationException::withMessages(['account' => 'الرقم الوظيفي في الملف غير صالح لاسم مستخدم.']);
        }
        if (! preg_match('/^\+?[0-9]{8,15}$/', (string) $employee->phone)) {
            throw ValidationException::withMessages(['account' => 'سجّل جوالًا أساسيًا صالحًا في الملف أولًا؛ إليه يُرسل رمز التفعيل.']);
        }
        if (! $employee->department_id) {
            throw ValidationException::withMessages(['account' => 'حدد قسم الموظف في الملف أولًا.']);
        }
        if (User::query()->where('employee_number', $employee->employee_number)->exists()) {
            throw ValidationException::withMessages(['account' => 'يوجد حساب بالرقم الوظيفي نفسه؛ استخدم «ربط بالحساب القائم».']);
        }

        $user = new User;
        $user->name = mb_substr(trim((string) ($employee->full_name_ar ?: implode(' ', array_filter([$employee->first_name_ar, $employee->father_name_ar,
            $employee->grandfather_name_ar, $employee->family_name_ar])))), 0, 255);
        $user->employee_number = $employee->employee_number;
        $user->email = null;
        $user->department_id = $employee->department_id;
        $user->password = Hash::make(Str::random(80));
        $user->is_active = false;
        $user->must_change_password = false;
        $user->save();

        $employee->forceFill(['user_id' => $user->id, 'onboarding_status' => 'pending_activation', 'onboarding_step' => 5,
            'onboarding_completed_at' => now(), 'updated_by' => auth()->id()])->save();

        return $user;
    }
}
