<?php

namespace App\Services\Hr;

use App\Http\Controllers\Admin\EmployeePayChangeController;
use App\Models\Employee;
use App\Models\User;
use App\Services\Configuration\AuditActionCatalog;
use App\Services\EmployeeLeaveBalanceService;
use App\Services\EmployeeLeaveEntitlementService;
use Illuminate\Support\Facades\DB;

/**
 * The employee file as one viewer may see it. A section the viewer may not open is left out of the payload
 * entirely (never sent and hidden in the browser).
 */
final class EmployeeProfileView
{
    private const PERSONAL = ['first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar', 'full_name_ar', 'original_family_name_ar', 'name_review_status',
        'full_name_en', 'date_of_birth', 'gender', 'place_of_birth', 'marital_status_value_id', 'children_count', 'nationality_value_id', 'has_family_members'];

    private const IDENTITY = ['national_id', 'identity_document_type_value_id'];

    private const CONTACT = ['phone', 'alternative_phone', 'landline_phone', 'personal_email'];

    private const JOB = ['employee_number', 'attendance_number', 'department_id', 'job_category_id', 'job_title_id', 'job_grade_id', 'employment_term_value_id', 'contract_type_value_id',
        'work_schedule_value_id', 'administrative_level_value_id', 'hire_date', 'work_start_date', 'employment_status', 'onboarding_status', 'job_title_source', 'department_source', 'grade_source',
        'has_qualifications', 'user_id'];

    public function __construct(
        private readonly EmployeeAccess $access,
        private readonly EmployeeFiles $files,
        private readonly EmployeePayChanges $pay,
        private readonly EmployeePlacements $placements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $viewer, Employee $employee): array
    {
        $sections = $this->access->sections($viewer, $employee);
        $manage = $this->access->canManage($viewer);
        $fields = ['id', 'first_name_ar', 'family_name_ar', 'full_name_ar', 'employee_number', ...self::JOB];
        foreach (['personal' => self::PERSONAL, 'identity' => self::IDENTITY, 'contact' => self::CONTACT, 'address' => ['residence_address']] as $section => $list) {
            if ($sections[$section]) {
                $fields = [...$fields, ...$list];
            }
        }
        if ($manage) {
            $fields = [...$fields, 'notes', 'import_batch_id', 'created_at', 'updated_at'];
        }
        $data = $employee->only(array_values(array_unique($fields)));
        foreach (['date_of_birth', 'hire_date', 'work_start_date'] as $date) {
            if (isset($data[$date]) && $data[$date] instanceof \DateTimeInterface) {
                $data[$date] = $data[$date]->format('Y-m-d');
            }
        }
        $data['display_name'] = EmployeeNames::display($employee);
        if (! $sections['contact']) {
            unset($data['phone']);
        }
        if ($sections['address']) {
            $data['addresses'] = $employee->addresses()->orderByDesc('is_active')->get()->toArray();
        }
        if ($sections['personal']) {
            $data['family_members'] = $employee->familyMembers()->get(['id', 'relationship_code', 'full_name_ar', 'date_of_birth', 'is_dependent', 'is_active'])->toArray();
        }

        $openChange = DB::table('employee_pay_changes')->where('employee_id', $employee->id)->whereIn('status', ['in_review', 'returned'])->first(['id', 'status']);
        $payHistory = $this->pay->history($employee, $sections['pay'], $sections['bank'], $sections['bank_full']);

        return [
            'employee' => $data,
            'sections' => $sections,
            'labels' => $this->labels($employee),
            'qualifications' => $sections['qualifications'] ? DB::table('employee_qualifications as q')
                ->leftJoin('master_data_values as l', 'l.id', '=', 'q.education_level_value_id')->leftJoin('users as u', 'u.id', '=', 'q.verified_by')
                ->where('q.employee_id', $employee->id)->orderByDesc('q.is_highest_qualification')->orderByDesc('q.graduation_year')
                ->get(['q.id', 'q.qualification_name', 'q.degree_level', 'l.name_ar as level', 'q.institution_name', 'q.specialization', 'q.graduation_year', 'q.grade', 'q.gpa', 'q.gpa_scale',
                    'q.is_highest_qualification', 'q.source', 'q.verification_status', 'q.verification_note', 'q.valid_until', 'u.name as verified_by']) : [],
            'placements' => $sections['job'] ? $this->placements->history($employee->id) : [],
            'photo' => $sections['photo'] ? $this->files->photos($viewer, $employee) : null,
            'documents' => $sections['documents'] ? $this->files->documents($viewer, $employee) : null,
            'documentTypes' => array_values(array_filter($this->files->documentTypes(true), fn (array $type): bool => $this->access->canUploadDocument($viewer, $employee, $type['classification']))),
            'pay' => $sections['pay'] ? ['current' => $this->pay->currentCompensation($employee->id), 'history' => $payHistory['compensation']] : null,
            'bank' => $sections['bank'] ? ['history' => $payHistory['bank'], 'full' => $sections['bank_full']] : null,
            'payChange' => $openChange && ($sections['pay'] || $this->access->canRequestPayChange($viewer)) ? ['id' => (int) $openChange->id, 'status' => $openChange->status,
                'label' => EmployeePayChanges::STATUS_LABELS[$openChange->status] ?? $openChange->status] : null,
            'leave' => $sections['leave'] ? $this->leave($employee) : null,
            'history' => $sections['history'] ? $this->history($employee) : [],
            'provenance' => $sections['provenance'] && $employee->import_batch_id ? DB::table('employee_import_rows')->where('employee_id', $employee->id)
                ->where('batch_id', $employee->import_batch_id)->first(['batch_id', 'row_number', 'processed_at']) : null,
            'can' => [
                'manage' => $manage,
                'edit' => $manage,
                'verify' => $this->access->canVerify($viewer),
                'uploadPhoto' => $this->access->canUploadPhoto($viewer, $employee),
                'uploadDocuments' => $this->access->canUploadDocument($viewer, $employee, 'normal'),
                'requestPayChange' => $this->access->canRequestPayChange($viewer) && ! $openChange,
                'linkAccount' => $manage && $employee->user_id === null,
                'self' => $this->access->isSelf($viewer, $employee),
            ],
            'payChoices' => $this->access->canRequestPayChange($viewer) && ! $openChange ? app(EmployeePayChangeController::class)->choices() : null,
            'fileLimits' => ['photoKb' => (int) $this->files->settings()->photo_max_kb, 'documentKb' => (int) $this->files->settings()->document_max_kb,
                'phpKb' => (int) floor(min($this->bytes((string) ini_get('upload_max_filesize')), $this->bytes((string) ini_get('post_max_size'))) / 1024)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(Employee $employee): array
    {
        $ids = array_filter([$employee->marital_status_value_id, $employee->employment_term_value_id, $employee->contract_type_value_id, $employee->work_schedule_value_id,
            $employee->administrative_level_value_id, $employee->identity_document_type_value_id, $employee->nationality_value_id]);
        $names = DB::table('master_data_values')->whereIn('id', $ids)->pluck('name_ar', 'id');

        return [
            'department' => $employee->department_id ? DB::table('departments')->where('id', $employee->department_id)->value('name') : null,
            'jobCategory' => $employee->job_category_id ? DB::table('job_categories')->where('id', $employee->job_category_id)->value('name_ar') : null,
            'jobTitle' => $employee->job_title_id ? DB::table('job_titles')->where('id', $employee->job_title_id)->value('name_ar') : null,
            'jobGrade' => $employee->job_grade_id ? DB::table('job_grades')->where('id', $employee->job_grade_id)->value('name_ar') : null,
            'maritalStatus' => $names[$employee->marital_status_value_id] ?? null,
            'employmentTerm' => $names[$employee->employment_term_value_id] ?? null,
            'contractType' => $names[$employee->contract_type_value_id] ?? null,
            'workSchedule' => $names[$employee->work_schedule_value_id] ?? null,
            'administrativeLevel' => $names[$employee->administrative_level_value_id] ?? null,
            'identityDocumentType' => $names[$employee->identity_document_type_value_id] ?? null,
            'nationality' => $names[$employee->nationality_value_id] ?? null,
            'leaveTypes' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'leave_types')->pluck('v.name_ar', 'v.id'),
            'kinship' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'kinship_types')->pluck('v.name_ar', 'v.code'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leave(Employee $employee): array
    {
        $year = (int) now()->year;
        $balances = app(EmployeeLeaveBalanceService::class);
        $types = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'leave_types')->where('v.is_active', true)->orderBy('v.sort_order')->get(['v.id', 'v.name_ar']);

        return [
            'year' => $year,
            'balances' => $types->map(fn (object $type): array => ['type' => $type->name_ar, ...$balances->summary($employee->id, (int) $type->id, $year)])->all(),
            'baselines' => DB::table('employee_leave_baselines as b')->join('master_data_values as v', 'v.id', '=', 'b.leave_type_value_id')
                ->where('b.employee_id', $employee->id)->orderByDesc('b.as_of_date')
                ->get(['b.id', 'v.name_ar as type', 'b.as_of_date', 'b.annual_entitlement_days', 'b.carried_over_days', 'b.accrued_days', 'b.used_days', 'b.balance_days',
                    'b.consistency', 'b.opening_balance_id', 'b.import_batch_id']),
            'opening' => DB::table('employee_leave_opening_balances as o')->join('master_data_values as v', 'v.id', '=', 'o.leave_type_value_id')
                ->where('o.employee_id', $employee->id)->orderByDesc('o.balance_year')
                ->get(['o.id', 'v.name_ar as type', 'o.balance_year', 'o.annual_entitlement_days', 'o.opening_balance_days', 'o.notes']),
            'basis' => app(EmployeeLeaveEntitlementService::class)->basis($employee),
        ];
    }

    /**
     * Who did what on this file: actions and field names only, never values.
     *
     * @return list<array<string, mixed>>
     */
    private function history(Employee $employee): array
    {
        $catalog = app(AuditActionCatalog::class);
        $id = (string) $employee->id;

        return DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('a.subject_type', 'employees')->where('a.subject_id', $id))
                ->orWhere(fn ($q) => $q->whereIn('a.subject_type', ['employee_photos', 'employee_documents', 'employee_pay_changes'])
                    ->whereRaw("a.metadata::jsonb ->> 'employee_id' = ?", [$id])))
            ->orderByDesc('a.id')->limit(60)
            ->get(['a.id', 'a.action', 'a.metadata', 'a.created_at', 'u.name as actor'])
            ->map(function (object $row) use ($catalog): array {
                $meta = json_decode((string) $row->metadata, true) ?: [];

                return ['id' => (int) $row->id, 'action' => $catalog->label($row->action), 'actor' => $row->actor ?? 'النظام', 'at' => $row->created_at,
                    'fields' => array_values(array_filter((array) ($meta['changed_fields'] ?? $meta['filled_fields'] ?? []), 'is_string'))];
            })->all();
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1073741824, 'm' => $number * 1048576, 'k' => $number * 1024, default => $number,
        };
    }
}
