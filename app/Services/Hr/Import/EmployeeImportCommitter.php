<?php

namespace App\Services\Hr\Import;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Hr\EmployeeAccess;
use App\Services\Hr\EmployeePlacements;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Executes a reviewed import batch and undoes it.
 *
 * Rows run in chunks; each chunk is one transaction, and a row's result is saved in the same transaction as its
 * records, so a failure rolls the chunk back whole and a second run resumes with the rows still pending. Unique
 * indexes on the identity number, the employee number and the biometric number stop duplicates even when the
 * data changed after validation. No login account, notification, payroll entry or approval is created.
 */
final class EmployeeImportCommitter
{
    private const CHUNK = 50;

    /** Normalised value => employee column, per update group. */
    private const UPDATE_COLUMNS = [
        'attendance' => ['attendance_number' => 'attendance_number'],
        'personal' => ['full_name' => 'full_name_ar', 'original_family_name' => 'original_family_name_ar', 'gender' => 'gender', 'date_of_birth' => 'date_of_birth',
            'marital_status_id' => 'marital_status_value_id', 'children_count' => 'children_count'],
        'contact' => ['phone' => 'phone', 'alternative_phone' => 'alternative_phone', 'landline_phone' => 'landline_phone'],
        'address' => ['address' => 'residence_address'],
        'employment' => ['employment_term_id' => 'employment_term_value_id', 'hire_date' => 'hire_date', 'department_id' => 'department_id', 'department_source' => 'department_source',
            'job_title_id' => 'job_title_id', 'job_title_source' => 'job_title_source', 'grade_id' => 'job_grade_id', 'grade_source' => 'grade_source'],
    ];

    /** Session advisory lock space of a running undo (pg_try_advisory_lock(space, batch id)). */
    private const REVERT_LOCK_SPACE = 42023;

    public function __construct(
        private readonly EmployeeImportService $imports,
        private readonly EmployeePlacements $placements,
        private readonly AuditService $audit,
        private readonly EmployeeAccess $access,
    ) {}

    /**
     * @return array<string, mixed> the batch summary after the run, with `withheld`: the confidential groups the
     *                              actor may not write, left out of the run
     */
    public function commit(int $batchId, User $actor, ?string $ip): array
    {
        $withheld = $this->withheldGroups($actor);
        $options = DB::transaction(function () use ($batchId, $withheld): array {
            $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            if (! in_array($batch->status, ['validated', 'failed'], true)) {
                throw ValidationException::withMessages(['batch' => match ($batch->status) {
                    'staged' => 'حدد لكل عمود حقله أو اختر تجاهله صراحة قبل التنفيذ.',
                    'reverting' => 'يجري التراجع عن هذه الدفعة الآن؛ لا تُنفذ أثناءه.',
                    default => 'هذه الدفعة نُفذت أو أُلغيت سابقًا.',
                }]);
            }
            $this->checkReady($batch, $withheld);
            DB::table('employee_import_batches')->where('id', $batchId)->update(['status' => 'committing', 'failure' => null, 'updated_at' => now()]);

            return $this->imports->options($batch);
        });

        $context = [...$this->context($batchId, $options), 'withheld' => $withheld];
        while (true) {
            $ids = DB::table('employee_import_rows')->where('batch_id', $batchId)->where('result', 'pending')->orderBy('row_number')->limit(self::CHUNK)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            try {
                DB::transaction(function () use ($ids, $batchId, $context, $actor, $ip): void {
                    foreach ($ids as $id) {
                        $this->processRow((int) $id, $batchId, $context, $actor, $ip);
                    }
                });
            } catch (Throwable $exception) {
                $message = $exception instanceof QueryException && in_array($exception->getCode(), ['23505', '23P01'], true)
                    ? 'تعارض مع بيانات أُدخلت بعد التحقق (رقم هوية أو رقم وظيفي أو فترة متداخلة). أعد التحقق ثم تابع التنفيذ؛ الصفوف المنفذة لا تتكرر.'
                    : ($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : 'تعذر تنفيذ جزء من الدفعة؛ لم يُحفظ ذلك الجزء. راجع السجل ثم تابع التنفيذ.');
                DB::table('employee_import_batches')->where('id', $batchId)->update(['status' => 'failed', 'failure' => $message, 'updated_at' => now()]);
                $this->audit->recordRow('hr.employee_import.failed', 'employee_import_batches', $batchId, null, ['message' => $message], $actor->id, $ip);
                if (! $exception instanceof ValidationException && ! $exception instanceof QueryException) {
                    report($exception);
                }

                throw ValidationException::withMessages(['batch' => $message]);
            }
        }

        DB::table('employee_import_batches')->where('id', $batchId)->update(['status' => 'committed', 'committed_by' => $actor->id, 'committed_at' => now(), 'updated_at' => now()]);
        $summary = $this->imports->summarize($batchId);
        $this->audit->recordRow('hr.employee_import.committed', 'employee_import_batches', $batchId, null,
            ['results' => $summary['results'], 'withheld_groups' => $withheld], $actor->id, $ip);

        return [...$summary, 'withheld' => $withheld];
    }

    /**
     * Confidential groups the actor may not write (AC-08). A pay or bank baseline is entered only by someone who may
     * see it and request its change (the confidential permissions, never the technical administrator's role); for
     * anyone else the import writes the other fields and leaves these for HR to enter through «طلب تعديل الراتب».
     *
     * @return list<string>
     */
    private function withheldGroups(User $actor): array
    {
        $request = $this->access->canRequestPayChange($actor);

        return array_values(array_filter([
            $request && $this->access->canViewPay($actor) ? null : 'pay',
            $request && $this->access->canViewBank($actor) ? null : 'bank',
        ]));
    }

    /**
     * Columns resolved, and the dates, currency and leave type the mapped columns need (withheld groups need none).
     *
     * @param  list<string>  $withheld
     */
    private function checkReady(object $batch, array $withheld): void
    {
        $map = $this->imports->columnMap($batch);
        if (in_array(null, $map, true)) {
            throw ValidationException::withMessages(['columns' => 'يوجد عمود لم يُحدد حقله ولم يُتجاهل صراحة.']);
        }
        $options = $this->imports->options($batch);
        $fields = array_values(array_filter($map, fn (?string $field): bool => $field !== null && $field !== 'ignore'));
        $pay = ! in_array('pay', $withheld, true) && array_intersect($fields, [...EmployeeImportNormalizer::MONEY_FIELDS, ...EmployeeImportNormalizer::RATE_FIELDS]) !== [];
        $leave = array_intersect($fields, EmployeeImportNormalizer::LEAVE_FIELDS) !== [];
        $bank = ! in_array('bank', $withheld, true) && array_intersect($fields, ['bank', 'bank_account']) !== [];
        if (($pay || $leave || $bank) && empty($options['as_of_date'])) {
            throw ValidationException::withMessages(['as_of_date' => 'حدد تاريخ الأساس الذي تصح عنده الرواتب والأرصدة والحسابات في الملف.']);
        }
        if ($pay && empty($options['currency'])) {
            throw ValidationException::withMessages(['currency' => 'حدد عملة مبالغ الرواتب في الملف.']);
        }
        if ($leave && empty($options['leave_type_value_id'])) {
            throw ValidationException::withMessages(['leave_type_value_id' => 'حدد نوع الإجازة الذي تخصه أعمدة الأرصدة.']);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function context(int $batchId, array $options): array
    {
        return [
            'options' => $options,
            'identity_type' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'identity_document_types')->where('v.code', 'palestinian_id')->value('v.id'),
            'level_names' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'education_levels')->pluck('v.name_ar', 'v.id')->all(),
            'title_categories' => DB::table('job_titles')->pluck('job_category_id', 'id')->all(),
            'batch_label' => 'استيراد الموظفين — الدفعة #'.$batchId,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function processRow(int $rowId, int $batchId, array $context, User $actor, ?string $ip): void
    {
        $row = DB::table('employee_import_rows')->where('id', $rowId)->lockForUpdate()->first();
        if (! $row || $row->result !== 'pending') {
            return;
        }
        $values = $row->normalized_payload ? (json_decode(Crypt::decryptString($row->normalized_payload), true) ?: []) : [];
        $update = [];

        if ($row->action === 'insert' && $row->status !== 'error' && $row->matched_employee_id === null) {
            $created = $this->insert($values, $batchId, (int) $row->row_number, $context, $actor, $ip);
            $update += ['result' => 'inserted', 'employee_id' => $created['employee'], 'created_records' => json_encode($created)];
        } elseif ($row->action === 'update' && $row->matched_employee_id !== null && $row->status !== 'error') {
            [$changed, $before, $created] = $this->update((int) $row->matched_employee_id, $values, json_decode((string) $row->update_fields, true) ?: [], $batchId, (int) $row->row_number, $context, $actor, $ip);
            $update += ['result' => $changed ? 'updated' : 'skipped', 'employee_id' => $row->matched_employee_id, 'created_records' => json_encode($created),
                'before_payload' => Crypt::encryptString(json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        } else {
            $update += ['result' => $row->action === 'skip' ? 'skipped' : 'rejected'];
        }
        // Stamped after the row's records exist, so the undo can tell later edits from the import itself.
        DB::table('employee_import_rows')->where('id', $rowId)->update([...$update, 'processed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     * @return array<string, int>
     */
    private function insert(array $values, int $batchId, int $rowNumber, array $context, User $actor, ?string $ip): array
    {
        $options = $context['options'];
        $number = $values['employee_number'] ?? (($options['employee_number_from_attendance'] ?? true) ? ($values['attendance_number'] ?? null) : null);
        if ($number === null || ! isset($values['full_name'], $values['first_name_ar'], $values['family_name_ar'])) {
            throw ValidationException::withMessages(['batch' => "الصف {$rowNumber} يفتقد الرقم الوظيفي أو الاسم؛ أعد التحقق."]);
        }
        $titleId = $values['job_title_id'] ?? null;

        $employee = new Employee;
        $employee->forceFill([
            'employee_number' => $number,
            'attendance_number' => $values['attendance_number'] ?? null,
            'first_name_ar' => $values['first_name_ar'], 'father_name_ar' => $values['father_name_ar'] ?? null,
            'grandfather_name_ar' => $values['grandfather_name_ar'] ?? null, 'family_name_ar' => $values['family_name_ar'],
            'full_name_ar' => $values['full_name'], 'original_family_name_ar' => $values['original_family_name'] ?? null, 'name_review_status' => 'suggested',
            'national_id' => $values['national_id'] ?? null,
            'identity_document_type_value_id' => isset($values['national_id']) ? $context['identity_type'] : null,
            'gender' => $values['gender'] ?? null, 'date_of_birth' => $values['date_of_birth'] ?? null,
            'marital_status_value_id' => $values['marital_status_id'] ?? null, 'children_count' => $values['children_count'] ?? null,
            'phone' => $values['phone'] ?? null, 'alternative_phone' => $values['alternative_phone'] ?? null, 'landline_phone' => $values['landline_phone'] ?? null,
            'residence_address' => $values['address'] ?? null,
            'department_id' => $values['department_id'] ?? null, 'department_source' => $values['department_source'] ?? null,
            'job_title_id' => $titleId, 'job_category_id' => $titleId ? ($context['title_categories'][$titleId] ?? null) : null, 'job_title_source' => $values['job_title_source'] ?? null,
            'job_grade_id' => $values['grade_id'] ?? null, 'grade_source' => $values['grade_source'] ?? null,
            'employment_term_value_id' => $values['employment_term_id'] ?? null, 'hire_date' => $values['hire_date'] ?? null,
            'has_qualifications' => isset($values['qualification_source']) || isset($values['specialization_source']) ? true : null,
            'employment_status' => 'active', 'onboarding_status' => 'draft', 'onboarding_step' => 1,
            'import_batch_id' => $batchId, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $employee->save();
        $id = (int) $employee->id;

        $created = ['employee' => $id];
        $created['placement'] = $this->placements->record($id, [
            'department_id' => $employee->department_id, 'job_title_id' => $employee->job_title_id, 'job_grade_id' => $employee->job_grade_id,
            'department_source' => $employee->department_source, 'job_title_source' => $employee->job_title_source, 'grade_source' => $employee->grade_source,
        ], $values['hire_date'] ?? $options['as_of_date'] ?? now()->toDateString(), 'import', $context['batch_label'], $actor->id, $batchId);
        $created += $this->records($id, $values, ['qualification', 'pay', 'bank', 'leave'], $batchId, $rowNumber, $context, $actor, $ip, (int) ($values['employment_term_id'] ?? 0));

        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'action' => 'employee_profile.imported', 'subject_type' => 'employees', 'subject_id' => (string) $id,
            // Field names only: personal, pay and bank values never enter the audit log.
            'metadata' => json_encode(['batch_id' => $batchId, 'row' => $rowNumber, 'filled_fields' => array_keys($values), 'created_records' => array_keys($created)], JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $created;
    }

    /**
     * Updates the chosen field groups of a matched profile. Empty imported values never erase stored ones; pay and
     * bank records are added only when the profile has none (later changes go through the approval request).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $groups
     * @param  array<string, mixed>  $context
     * @return array{0: bool, 1: array<string, mixed>, 2: array<string, int|string>}
     */
    private function update(int $employeeId, array $values, array $groups, int $batchId, int $rowNumber, array $context, User $actor, ?string $ip): array
    {
        $employee = Employee::query()->whereKey($employeeId)->lockForUpdate()->first();
        if (! $employee) {
            throw ValidationException::withMessages(['batch' => "الملف المطابق للصف {$rowNumber} لم يعد قائمًا؛ أعد التحقق."]);
        }
        $before = [];
        $after = [];
        $notes = [];
        foreach ($groups as $group) {
            foreach (self::UPDATE_COLUMNS[$group] ?? [] as $key => $column) {
                if (! isset($values[$key])) {
                    continue;
                }
                $current = $employee->{$column} instanceof \DateTimeInterface ? $employee->{$column}->format('Y-m-d') : $employee->{$column};
                if ((string) $current === (string) $values[$key]) {
                    continue;
                }
                if ($column === 'department_id' && $employee->user_id !== null) {
                    $notes[] = 'department_locked_account';

                    continue;
                }
                $before[$column] = $current;
                $after[$column] = $values[$key];
            }
        }
        if (isset($after['job_title_id'])) {
            $before['job_category_id'] = $employee->job_category_id;
            $after['job_category_id'] = $context['title_categories'][$after['job_title_id']] ?? null;
        }
        if ($after !== []) {
            $employee->forceFill([...$after, 'updated_by' => $actor->id])->save();
        }
        $created = [];
        if (array_intersect(array_keys($after), ['department_id', 'job_title_id', 'job_grade_id', 'department_source', 'job_title_source', 'grade_source']) !== []) {
            $previous = DB::table('employee_placements')->where('employee_id', $employeeId)->whereNull('effective_to')->value('id');
            $created['placement'] = $this->placements->record($employeeId, [
                'department_id' => $employee->department_id, 'job_title_id' => $employee->job_title_id, 'job_grade_id' => $employee->job_grade_id,
                'department_source' => $employee->department_source, 'job_title_source' => $employee->job_title_source, 'grade_source' => $employee->grade_source,
            ], now()->toDateString(), 'import', $context['batch_label'], $actor->id, $batchId);
            $created['previous_placement'] = (int) $previous;
        }
        $created += $this->records($employeeId, $values, $groups, $batchId, $rowNumber, $context, $actor, $ip, (int) $employee->employment_term_value_id);
        $notes = [...$notes, ...array_values(array_filter($created, 'is_string'))];
        $created = array_filter($created, 'is_int');

        if ($after !== [] || $created !== []) {
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id, 'action' => 'employee_profile.import_updated', 'subject_type' => 'employees', 'subject_id' => (string) $employeeId,
                'metadata' => json_encode(['batch_id' => $batchId, 'row' => $rowNumber, 'changed_fields' => array_keys($after), 'created_records' => array_keys($created), 'notes' => $notes], JSON_UNESCAPED_UNICODE),
                'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$after !== [] || $created !== [], ['before' => $before, 'after' => $after, 'notes' => $notes], $created];
    }

    /**
     * Qualification, pay, bank and leave records of one row. A string entry is a note explaining a skipped record.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $groups
     * @param  array<string, mixed>  $context
     * @return array<string, int|string>
     */
    private function records(int $employeeId, array $values, array $groups, int $batchId, int $rowNumber, array $context, User $actor, ?string $ip, int $employmentTerm): array
    {
        $options = $context['options'];
        $asOf = $options['as_of_date'] ?? null;
        $created = [];
        $now = now();

        if (in_array('qualification', $groups, true) && (isset($values['qualification_source']) || isset($values['specialization_source']))) {
            $exists = DB::table('employee_qualifications')->where('employee_id', $employeeId)->where('source', 'import')->exists();
            if (! $exists) {
                $highest = DB::table('employee_qualifications')->where('employee_id', $employeeId)->where('is_highest_qualification', true)->exists();
                $level = isset($values['qualification_id']) ? ($context['level_names'][$values['qualification_id']] ?? null) : null;
                $created['qualification'] = (int) DB::table('employee_qualifications')->insertGetId([
                    'employee_id' => $employeeId, 'qualification_name' => mb_substr($values['qualification_source'] ?? $values['specialization_source'], 0, 255),
                    'degree_level' => $level ? mb_substr($level, 0, 100) : (isset($values['qualification_source']) ? mb_substr($values['qualification_source'], 0, 100) : null),
                    'education_level_value_id' => $values['qualification_id'] ?? null, 'institution_name' => null,
                    'specialization' => $values['specialization_source'] ?? null, 'discipline_value_id' => $values['specialization_id'] ?? null,
                    'is_highest_qualification' => ! $highest, 'source' => 'import', 'verification_status' => 'unverified', 'import_batch_id' => $batchId,
                    'notes' => 'من ملف بيانات الموظفين؛ لا يغني عن الشهادة الموثّقة.', 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('employees')->where('id', $employeeId)->update(['has_qualifications' => true]);
            }
        }

        $pay = array_intersect_key($values, array_flip([...EmployeeImportNormalizer::MONEY_FIELDS, ...EmployeeImportNormalizer::RATE_FIELDS]));
        // Withheld confidential groups (AC-08) are noted on the row and never written.
        $withheld = $context['withheld'] ?? ['pay', 'bank'];
        if (in_array('pay', $withheld, true)) {
            if (in_array('pay', $groups, true) && $pay !== []) {
                $created['pay_note'] = 'pay_withheld_confidential';
            }
            $groups = array_values(array_diff($groups, ['pay']));
        }
        if (in_array('bank', $withheld, true)) {
            if (in_array('bank', $groups, true) && (isset($values['bank_account']) || isset($values['bank_source']))) {
                $created['bank_note'] = 'bank_withheld_confidential';
            }
            $groups = array_values(array_diff($groups, ['bank']));
        }
        if (in_array('pay', $groups, true) && $pay !== [] && $asOf && ! empty($options['currency'])) {
            if (DB::table('employee_compensations')->where('employee_id', $employeeId)->exists()) {
                $created['pay_note'] = 'pay_requires_request';
            } else {
                $created['compensation'] = (int) DB::table('employee_compensations')->insertGetId([
                    'employee_id' => $employeeId, 'effective_from' => $asOf, 'effective_to' => null, 'currency_code' => $options['currency'],
                    'base_salary' => $pay['base_salary'] ?? null, 'spouse_allowance' => $pay['spouse_allowance'] ?? null, 'children_allowance' => $pay['children_allowance'] ?? null,
                    'cost_of_living_rate' => $pay['cost_of_living_rate'] ?? null, 'nature_of_work_rate' => $pay['nature_of_work_rate'] ?? null,
                    'source_total_salary' => $pay['total_salary'] ?? null, 'status' => 'baseline', 'source' => 'import', 'import_batch_id' => $batchId,
                    'note' => 'بيانات مرجعية من ملف الموظفين بتاريخ الأساس؛ ليست اعتمادًا للصرف ولا قيدًا محاسبيًا.',
                    'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (in_array('bank', $groups, true) && (isset($values['bank_account']) || isset($values['bank_source'])) && $asOf) {
            if (DB::table('employee_bank_accounts')->where('employee_id', $employeeId)->exists()) {
                $created['bank_note'] = 'bank_requires_request';
            } else {
                $account = $values['bank_account'] ?? null;
                $created['bank'] = (int) DB::table('employee_bank_accounts')->insertGetId([
                    'employee_id' => $employeeId, 'bank_value_id' => $values['bank_id'] ?? null, 'bank_source' => $values['bank_source'] ?? null,
                    'account_number' => $account ? Crypt::encryptString($account) : null, 'account_hash' => $account ? $this->imports->accountHash($account) : null,
                    'account_last4' => $account ? substr($account, -4) : null, 'effective_from' => $asOf, 'effective_to' => null,
                    'status' => 'baseline', 'source' => 'import', 'import_batch_id' => $batchId, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $leave = array_intersect_key($values, array_flip(EmployeeImportNormalizer::LEAVE_FIELDS));
        $type = (int) ($options['leave_type_value_id'] ?? 0);
        if (in_array('leave', $groups, true) && $leave !== [] && $asOf && $type > 0) {
            $exists = DB::table('employee_leave_baselines')->where('employee_id', $employeeId)->where('leave_type_value_id', $type)->where('as_of_date', $asOf)->exists();
            if ($exists) {
                $created['leave_note'] = 'leave_snapshot_exists';
            } else {
                $baseline = (int) DB::table('employee_leave_baselines')->insertGetId([
                    'employee_id' => $employeeId, 'leave_type_value_id' => $type, 'as_of_date' => $asOf,
                    'annual_entitlement_days' => $leave['leave_annual'] ?? null, 'carried_over_days' => $leave['leave_carried'] ?? null,
                    'accrued_days' => $leave['leave_accrued'] ?? null, 'used_days' => $leave['leave_used'] ?? null, 'balance_days' => $leave['leave_balance'] ?? null,
                    'consistency' => $values['leave_consistency'] ?? 'incomplete', 'import_batch_id' => $batchId,
                    'note' => 'لقطة من ملف الموظفين كما وردت؛ لا تُشتق منها حركات إجازة.', 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $created['leave_baseline'] = $baseline;
                $opening = $this->opening($employeeId, $type, $asOf, $leave, $values['leave_consistency'] ?? 'incomplete', $employmentTerm, $batchId, $rowNumber, $options, $actor, $ip);
                if (is_int($opening)) {
                    DB::table('employee_leave_baselines')->where('id', $baseline)->update(['opening_balance_id' => $opening]);
                    $created['opening_balance'] = $opening;
                } elseif ($opening !== null) {
                    $created['opening_note'] = $opening;
                }
            }
        }

        return $created;
    }

    /**
     * The current-year opening balance, recorded the same way HR records it by hand, only when the reviewer asked
     * for it and nothing could be counted twice. Returns the new id, a note code, or null when not requested.
     *
     * @param  array<string, string>  $leave
     * @param  array<string, mixed>  $options
     */
    private function opening(int $employeeId, int $type, string $asOf, array $leave, string $consistency, int $employmentTerm, int $batchId, int $rowNumber, array $options, User $actor, ?string $ip): int|string|null
    {
        if (! ($options['post_opening_balances'] ?? false)) {
            return null;
        }
        $year = (int) substr($asOf, 0, 4);
        $balance = isset($leave['leave_balance']) ? (float) $leave['leave_balance'] : null;
        $annual = isset($leave['leave_annual']) ? (float) $leave['leave_annual'] : null;
        $blocked = match (true) {
            $year !== (int) now()->year => 'opening_not_current_year',
            $balance === null || $annual === null || $balance < 0 || $consistency === 'inconsistent' => 'opening_values_unusable',
            $employmentTerm <= 0 => 'opening_needs_employment_term',
            DB::table('employee_leave_opening_balances')->where('employee_id', $employeeId)->where('leave_type_value_id', $type)->where('balance_year', $year)->exists() => 'opening_exists',
            DB::table('employee_leave_accounts as a')->join('employee_leave_transactions as t', 't.account_id', '=', 'a.id')
                ->where('a.employee_id', $employeeId)->where('a.leave_type_value_id', $type)->where('a.balance_year', $year)->exists() => 'opening_has_movements',
            default => null,
        };
        if ($blocked !== null) {
            return $blocked;
        }
        $id = (int) DB::table('employee_leave_opening_balances')->insertGetId([
            'employee_id' => $employeeId, 'leave_type_value_id' => $type, 'balance_year' => $year,
            'annual_entitlement_days' => $annual, 'opening_balance_days' => $balance,
            'notes' => "رصيد افتتاحي من استيراد ملف الموظفين (الدفعة #{$batchId}، الصف {$rowNumber}) كما هو حتى {$asOf}.",
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'action' => 'leave_opening_balance.recorded', 'subject_type' => 'employee_leave_opening_balances', 'subject_id' => (string) $id, 'ip_address' => $ip,
            'metadata' => json_encode(['before' => null, 'after' => ['employee_id' => $employeeId, 'leave_type_value_id' => $type, 'balance_year' => $year,
                'annual_entitlement_days' => $annual, 'opening_balance_days' => $balance, 'source' => 'import', 'batch_id' => $batchId]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Compensating undo: removes what the batch created and restores what it changed, only where nothing was edited
     * or linked afterwards. Anything else stays as it is and is listed as blocked for a manual correction.
     *
     * The batch is claimed first (CO-11): under its row lock it moves to «reverting», which a commit retry refuses,
     * and a session advisory lock marks the run as alive. A batch left «reverting» by a run that died (its lock is
     * gone with its connection) is claimed again and resumes; rows already undone are not touched twice. If the run
     * fails, the batch returns to its previous status with the rows undone so far, and a new undo resumes.
     *
     * @return array<string, mixed>
     */
    public function revert(int $batchId, User $actor, string $reason, ?string $ip): array
    {
        if (! DB::selectOne('SELECT pg_try_advisory_lock(?::int, ?::int) AS locked', [self::REVERT_LOCK_SPACE, $batchId])->locked) {
            throw ValidationException::withMessages(['reason' => 'يجري التراجع عن هذه الدفعة الآن من جلسة أخرى.']);
        }
        try {
            $previous = DB::transaction(function () use ($batchId, $actor, $ip): string {
                $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
                abort_unless($batch, 404);
                if (! in_array($batch->status, ['committed', 'failed', 'reverting'], true)) {
                    throw ValidationException::withMessages(['reason' => 'التراجع متاح لدفعة نُفذت فقط.']);
                }
                // Only a finished commit stamps committed_at, so a batch left «reverting» tells which status it had.
                $previous = $batch->status === 'reverting' ? ($batch->committed_at !== null ? 'committed' : 'failed') : $batch->status;
                DB::table('employee_import_batches')->where('id', $batchId)->update(['status' => 'reverting', 'updated_at' => now()]);
                $this->audit->recordRow('hr.employee_import.revert_started', 'employee_import_batches', $batchId, ['status' => $batch->status], ['status' => 'reverting'], $actor->id, $ip);

                return $previous;
            });

            try {
                $references = $this->employeeReferences();
                foreach (DB::table('employee_import_rows')->where('batch_id', $batchId)->whereIn('result', ['inserted', 'updated'])->orderByDesc('row_number')->pluck('id') as $rowId) {
                    DB::transaction(function () use ($rowId, $batchId, $references, $actor, $ip): void {
                        $row = DB::table('employee_import_rows')->where('id', $rowId)->lockForUpdate()->first();
                        if (! $row || ! in_array($row->result, ['inserted', 'updated'], true)) {
                            return;
                        }
                        $blocked = $row->result === 'inserted'
                            ? $this->revertInsert($row, $batchId, $references, $actor, $ip)
                            : $this->revertUpdate($row, $batchId, $actor, $ip);
                        $created = json_decode((string) $row->created_records, true) ?: [];
                        DB::table('employee_import_rows')->where('id', $rowId)->update([
                            'result' => $blocked === [] ? 'reverted' : 'revert_blocked', 'employee_id' => $blocked === [] && $row->result === 'inserted' ? null : $row->employee_id,
                            'created_records' => json_encode([...$created, 'revert_blocked_by' => $blocked]), 'updated_at' => now(),
                        ]);
                    });
                }
            } catch (Throwable $exception) {
                DB::table('employee_import_batches')->where('id', $batchId)->where('status', 'reverting')->update(['status' => $previous, 'updated_at' => now()]);
                $this->audit->recordRow('hr.employee_import.revert_failed', 'employee_import_batches', $batchId, null, ['status' => $previous], $actor->id, $ip);
                if ($exception instanceof ValidationException) {
                    throw $exception;
                }
                report($exception);

                throw ValidationException::withMessages(['reason' => 'تعذر إكمال التراجع؛ ما تُرُوجع عنه من الصفوف يبقى كذلك، وعادت الدفعة إلى حالتها السابقة. أعد التراجع لإكمال الباقي.']);
            }

            // The final status and its audit row land together; if this fails the batch stays «reverting» and a new
            // undo (always offered for «reverting») finishes it with no row left to touch.
            return DB::transaction(function () use ($batchId, $actor, $reason, $ip): array {
                DB::table('employee_import_batches')->where('id', $batchId)->where('status', 'reverting')
                    ->update(['status' => 'reverted', 'reverted_by' => $actor->id, 'reverted_at' => now(), 'revert_reason' => $reason, 'updated_at' => now()]);
                $summary = $this->imports->summarize($batchId);
                $this->audit->recordRow('hr.employee_import.reverted', 'employee_import_batches', $batchId, null, ['reason' => $reason, 'results' => $summary['results']], $actor->id, $ip);

                return $summary;
            });
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?::int, ?::int)', [self::REVERT_LOCK_SPACE, $batchId]);
        }
    }

    /**
     * Every table column that points at employees.id (read from the database, so new modules are covered).
     *
     * @return list<array{table: string, column: string}>
     */
    private function employeeReferences(): array
    {
        return array_map(fn (object $row): array => ['table' => $row->table_name, 'column' => $row->column_name], DB::select("
            select kcu.table_name, kcu.column_name
            from information_schema.referential_constraints rc
            join information_schema.key_column_usage kcu on kcu.constraint_name = rc.constraint_name and kcu.constraint_schema = rc.constraint_schema
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = rc.unique_constraint_name and ccu.constraint_schema = rc.unique_constraint_schema
            where ccu.table_name = 'employees' and ccu.column_name = 'id' and kcu.table_schema = 'public'"));
    }

    /**
     * @param  list<array{table: string, column: string}>  $references
     * @return list<string> reasons the profile must stay (empty when removed)
     */
    private function revertInsert(object $row, int $batchId, array $references, User $actor, ?string $ip): array
    {
        $created = json_decode((string) $row->created_records, true) ?: [];
        $employeeId = (int) ($created['employee'] ?? 0);
        $employee = DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
        if (! $employee) {
            return [];
        }
        $processed = Carbon::parse($row->processed_at);
        $reasons = [];
        if ($employee->user_id !== null) {
            $reasons[] = 'account_linked';
        }
        if ($employee->deleted_at !== null || Carbon::parse($employee->updated_at)->gt($processed)) {
            $reasons[] = 'edited_after_import';
        }
        $own = ['employee_placements' => [$created['placement'] ?? 0], 'employee_qualifications' => [$created['qualification'] ?? 0], 'employee_compensations' => [$created['compensation'] ?? 0],
            'employee_bank_accounts' => [$created['bank'] ?? 0], 'employee_leave_baselines' => [$created['leave_baseline'] ?? 0], 'employee_leave_opening_balances' => [$created['opening_balance'] ?? 0],
            'employee_import_rows' => [$row->id]];
        foreach ($references as $reference) {
            $count = DB::table($reference['table'])->where($reference['column'], $employeeId)
                ->when(isset($own[$reference['table']]), fn ($query) => $query->whereNotIn('id', $own[$reference['table']]))
                // A later batch that only matched this profile and skipped or rejected it did not change it.
                ->when($reference['table'] === 'employee_import_rows', fn ($query) => $query->whereNotIn('result', ['skipped', 'rejected', 'pending', 'reverted']))
                ->count();
            if ($count > 0) {
                $reasons[] = 'linked:'.$reference['table'];
            }
        }
        foreach (['employee_placements' => 'placement', 'employee_qualifications' => 'qualification', 'employee_compensations' => 'compensation',
            'employee_bank_accounts' => 'bank', 'employee_leave_baselines' => 'leave_baseline', 'employee_leave_opening_balances' => 'opening_balance'] as $table => $key) {
            if (isset($created[$key]) && DB::table($table)->where('id', $created[$key])->where('updated_at', '>', $processed)->exists()) {
                $reasons[] = 'edited:'.$table;
            }
        }
        if ($reasons !== []) {
            return array_values(array_unique($reasons));
        }

        DB::table('employee_import_rows')->where('id', $row->id)->update(['employee_id' => null]);
        $released = DB::table('employee_import_rows')->where('id', '<>', $row->id)->where('matched_employee_id', $employeeId)->pluck('id')->all();
        DB::table('employee_import_rows')->whereIn('id', $released)->update(['matched_employee_id' => null, 'match_type' => 'reverted_profile', 'updated_at' => now()]);
        DB::table('employee_leave_baselines')->where('id', $created['leave_baseline'] ?? 0)->delete();
        DB::table('employee_leave_opening_balances')->where('id', $created['opening_balance'] ?? 0)->delete();
        foreach (['employee_compensations' => 'compensation', 'employee_bank_accounts' => 'bank', 'employee_qualifications' => 'qualification', 'employee_placements' => 'placement'] as $table => $key) {
            DB::table($table)->where('id', $created[$key] ?? 0)->delete();
        }
        DB::table('employees')->where('id', $employeeId)->delete();
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'action' => 'employee_profile.import_reverted', 'subject_type' => 'employees', 'subject_id' => (string) $employeeId,
            'metadata' => json_encode(['batch_id' => $batchId, 'row' => (int) $row->row_number, 'removed_records' => array_keys($created), 'released_match_rows' => $released], JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [];
    }

    /**
     * @return list<string>
     */
    private function revertUpdate(object $row, int $batchId, User $actor, ?string $ip): array
    {
        $created = json_decode((string) $row->created_records, true) ?: [];
        $changes = $row->before_payload ? (json_decode(Crypt::decryptString($row->before_payload), true) ?: []) : [];
        $employee = Employee::query()->withTrashed()->whereKey($row->employee_id)->lockForUpdate()->first();
        if (! $employee) {
            return ['profile_missing'];
        }
        $processed = Carbon::parse($row->processed_at);
        $reasons = [];
        $restore = [];
        foreach ($changes['after'] ?? [] as $column => $value) {
            $current = $employee->{$column} instanceof \DateTimeInterface ? $employee->{$column}->format('Y-m-d') : $employee->{$column};
            if ((string) $current === (string) $value) {
                $restore[$column] = $changes['before'][$column] ?? null;
            } else {
                $reasons[] = 'changed_since:'.$column;
            }
        }
        if ($restore !== []) {
            $employee->forceFill([...$restore, 'updated_by' => $actor->id])->save();
        }
        foreach (['employee_leave_baselines' => 'leave_baseline', 'employee_leave_opening_balances' => 'opening_balance', 'employee_compensations' => 'compensation',
            'employee_bank_accounts' => 'bank', 'employee_qualifications' => 'qualification'] as $table => $key) {
            if (! isset($created[$key])) {
                continue;
            }
            $record = DB::table($table)->where('id', $created[$key])->first();
            if ($record && Carbon::parse($record->updated_at)->gt($processed)) {
                $reasons[] = 'edited:'.$table;

                continue;
            }
            if ($key === 'opening_balance' && DB::table('employee_leave_baselines')->where('opening_balance_id', $created[$key])->exists()) {
                DB::table('employee_leave_baselines')->where('opening_balance_id', $created[$key])->update(['opening_balance_id' => null]);
            }
            DB::table($table)->where('id', $created[$key])->delete();
        }
        if (isset($created['placement'])) {
            $placement = DB::table('employee_placements')->where('id', $created['placement'])->first();
            if ($placement && $placement->effective_to === null && $placement->import_batch_id === $batchId && $placement->id !== ($created['previous_placement'] ?? null)) {
                DB::table('employee_placements')->where('id', $placement->id)->delete();
                DB::table('employee_placements')->where('id', $created['previous_placement'] ?? 0)->update(['effective_to' => null, 'updated_at' => now()]);
            } elseif ($placement) {
                $reasons[] = 'placement_changed';
            }
        }
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'action' => 'employee_profile.import_update_reverted', 'subject_type' => 'employees', 'subject_id' => (string) $employee->id,
            'metadata' => json_encode(['batch_id' => $batchId, 'row' => (int) $row->row_number, 'restored_fields' => array_keys($restore), 'blocked' => $reasons], JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $reasons;
    }
}
