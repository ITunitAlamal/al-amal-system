<?php

namespace App\Services\Hr\Import;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Hr\EmployeeAccess;
use App\Services\Hr\EmployeeNames;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The staged staff import: upload → column mapping → value decisions and options → validation and preview →
 * commit (EmployeeImportCommitter) → reconciliation and undo.
 *
 * The uploaded file is read once and never stored; each row is staged encrypted with its source row number and a
 * fingerprint. Analysis can run any number of times before the commit and never writes employee data.
 */
final class EmployeeImportService
{
    public const MAX_FILE_KB = 10240;

    public const DEFAULT_OPTIONS = [
        'as_of_date' => null,
        'currency' => null,
        'percent_rule' => 'review',
        'zero_salary_unknown' => true,
        'employee_number_from_attendance' => true,
        'leave_type_value_id' => null,
        'post_opening_balances' => false,
    ];

    /** Field groups a reviewer may update on a matched profile. Blank imported values never erase data. */
    public const UPDATE_GROUPS = [
        'attendance' => 'رقم جهاز البصمة',
        'personal' => 'البيانات الشخصية (الاسم المصدر، العائلة الأصلية، الجنس، الميلاد، الحالة الاجتماعية، الأبناء)',
        'contact' => 'الهواتف',
        'address' => 'عنوان السكن',
        'employment' => 'البيانات الوظيفية (البند، التعيين، القسم، المسمى، الدرجة)',
        'qualification' => 'المؤهل العلمي (يُضاف سجل غير موثّق)',
        'pay' => 'الراتب المرجعي (فقط إن لم يوجد سجل راتب)',
        'bank' => 'الحساب البنكي (فقط إن لم يوجد حساب مسجل)',
        'leave' => 'لقطة رصيد الإجازات بتاريخ الأساس',
    ];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly EmployeeImportNormalizer $normalizer,
        private readonly EmployeeAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * Reads the file, finds the header row and stages every data row encrypted. Returns the batch id.
     */
    public function stage(UploadedFile $file, User $actor, ?string $ip): int
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = (string) $file->getRealPath();
        $head = (string) file_get_contents($path, false, null, 0, 8);
        if ($extension === 'xlsx' && ! str_starts_with($head, "PK\x03\x04")) {
            throw ValidationException::withMessages(['file' => 'محتوى الملف لا يطابق صيغة XLSX.']);
        }
        if ($extension === 'csv' && str_contains((string) file_get_contents($path, false, null, 0, 65536), "\0")) {
            throw ValidationException::withMessages(['file' => 'محتوى الملف لا يطابق ملف CSV نصيًا.']);
        }

        $sheet = $this->reader->read($path, $extension);
        [$headerRow, $headers] = $this->findHeader($sheet['rows']);
        $columnMap = [];
        $used = [];
        foreach ($headers as $column => $header) {
            $field = EmployeeImportFields::fieldForHeader($header);
            $columnMap[$column] = $field !== null && ! in_array($field, $used, true) ? $field : null;
            if ($field !== null) {
                $used[] = $field;
            }
        }
        $dataRows = array_filter($sheet['rows'], fn (array $cells, int $number): bool => $number > $headerRow
            && array_filter($cells, fn (array $cell): bool => trim($cell['value']) !== '') !== [], ARRAY_FILTER_USE_BOTH);
        // A column with data but no header is listed too, so the reviewer maps or ignores it explicitly.
        foreach ($dataRows as $cells) {
            foreach (array_keys($cells) as $column) {
                if (! isset($headers[$column])) {
                    $headers[$column] = 'عمود بلا عنوان ('.$this->columnLetter($column).')';
                    $columnMap[$column] = null;
                }
            }
        }
        ksort($headers);
        ksort($columnMap);
        if ($dataRows === []) {
            throw ValidationException::withMessages(['file' => 'لا توجد صفوف بيانات تحت صف العناوين.']);
        }
        if (count($dataRows) > SpreadsheetReader::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'عدد الصفوف أكبر من الحد المسموح ('.SpreadsheetReader::MAX_ROWS.').']);
        }

        $hash = hash_file('sha256', $path);
        $batchId = DB::transaction(function () use ($file, $actor, $ip, $sheet, $headerRow, $headers, $columnMap, $dataRows, $hash): int {
            $leaveType = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'leave_types')->where('v.is_active', true)->orderBy('v.sort_order')->value('v.id');
            $batchId = (int) DB::table('employee_import_batches')->insertGetId([
                'status' => 'staged', 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'file_sha256' => $hash, 'file_size' => (int) $file->getSize(),
                'sheet_name' => mb_substr($sheet['sheet'], 0, 150), 'header_row' => $headerRow, 'data_rows' => count($dataRows),
                'headers' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), 'column_map' => json_encode($columnMap, JSON_FORCE_OBJECT),
                'value_map' => json_encode((object) []), 'options' => json_encode([...self::DEFAULT_OPTIONS, 'leave_type_value_id' => $leaveType ? (int) $leaveType : null]),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $rows = [];
            foreach ($dataRows as $number => $cells) {
                ksort($cells);
                $rows[] = [
                    'batch_id' => $batchId, 'row_number' => $number,
                    'fingerprint' => hash('sha256', json_encode(array_map(fn (array $cell): string => trim($cell['value']), $cells), JSON_UNESCAPED_UNICODE)),
                    'raw_payload' => Crypt::encryptString(json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'status' => 'pending', 'action' => 'skip', 'result' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('employee_import_rows')->insert($chunk);
            }
            $previous = DB::table('employee_import_batches')->where('file_sha256', $hash)->where('id', '<>', $batchId)->whereIn('status', ['committed', 'reverted'])->pluck('id')->all();
            $this->audit->recordRow('hr.employee_import.staged', 'employee_import_batches', $batchId, null,
                ['file_sha256' => $hash, 'rows' => count($dataRows), 'header_row' => $headerRow, 'same_file_batches' => $previous], $actor->id, $ip);

            return $batchId;
        });
        $this->analyze($batchId);

        return $batchId;
    }

    /**
     * The header row is the row (among the first 15) that matches the most known column names; at least three.
     *
     * @param  array<int, array<int, array{value: string}>>  $rows
     * @return array{0: int, 1: array<int, string>}
     */
    private function findHeader(array $rows): array
    {
        $best = [0, 0];
        foreach (array_slice($rows, 0, 15, true) as $number => $cells) {
            $known = count(array_filter($cells, fn (array $cell): bool => EmployeeImportFields::fieldForHeader($cell['value']) !== null));
            if ($known > $best[1]) {
                $best = [$number, $known];
            }
        }
        if ($best[1] < 3) {
            throw ValidationException::withMessages(['file' => 'لم يُعثر على صف عناوين معروف في أول 15 صفًا. استخدم النموذج الرسمي أو عناوين ملف الموظفين المعتمدة.']);
        }
        $headers = [];
        foreach ($rows[$best[0]] as $column => $cell) {
            if (trim($cell['value']) !== '') {
                $headers[$column] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $cell['value'])), 0, 200);
            }
        }
        ksort($headers);

        return [$best[0], $headers];
    }

    public function columnLetter(int $column): string
    {
        $letters = '';
        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters;
    }

    public function batch(int $batchId): object
    {
        $batch = DB::table('employee_import_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404);

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(object $batch): array
    {
        return [...self::DEFAULT_OPTIONS, ...(json_decode((string) $batch->options, true) ?: [])];
    }

    /**
     * @return array<int, string|null>
     */
    public function columnMap(object $batch): array
    {
        $map = [];
        foreach (json_decode((string) $batch->column_map, true) ?: [] as $column => $field) {
            $map[(int) $column] = $field;
        }

        return $map;
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    public function decisions(object $batch): array
    {
        return json_decode((string) $batch->value_map, true) ?: [];
    }

    private function editable(object $batch): void
    {
        if (! in_array($batch->status, ['staged', 'validated', 'failed'], true)) {
            throw ValidationException::withMessages(['batch' => 'لا يمكن تعديل دفعة نُفذت أو أُلغيت.']);
        }
    }

    /**
     * @param  array<int|string, string|null>  $map  column index => field key, "ignore" or null
     */
    public function saveColumnMap(int $batchId, array $map, User $actor, ?string $ip): void
    {
        DB::transaction(function () use ($batchId, $map, $actor, $ip): void {
            $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $this->editable($batch);
            $headers = json_decode((string) $batch->headers, true) ?: [];
            $clean = [];
            foreach ($headers as $column => $header) {
                $field = $map[$column] ?? $map[(string) $column] ?? null;
                $clean[(int) $column] = $field === 'ignore' || isset(EmployeeImportFields::FIELDS[(string) $field]) ? $field : null;
            }
            $fields = array_filter($clean, fn (?string $field): bool => $field !== null && $field !== 'ignore');
            if (count($fields) !== count(array_unique($fields))) {
                throw ValidationException::withMessages(['columns' => 'لا يجوز ربط عمودين بالحقل نفسه.']);
            }
            $before = $this->columnMap($batch);
            DB::table('employee_import_batches')->where('id', $batchId)->update(['column_map' => json_encode($clean, JSON_FORCE_OBJECT), 'updated_at' => now()]);
            $this->audit->recordRow('hr.employee_import.mapping', 'employee_import_batches', $batchId, ['column_map' => $before], ['column_map' => $clean], $actor->id, $ip);
        });
        $this->analyze($batchId);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function saveOptions(int $batchId, array $options, User $actor, ?string $ip): void
    {
        DB::transaction(function () use ($batchId, $options, $actor, $ip): void {
            $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $this->editable($batch);
            $before = $this->options($batch);
            $after = [...$before, ...array_intersect_key($options, self::DEFAULT_OPTIONS)];
            DB::table('employee_import_batches')->where('id', $batchId)->update(['options' => json_encode($after), 'updated_at' => now()]);
            $this->audit->recordRow('hr.employee_import.options', 'employee_import_batches', $batchId, $before, $after, $actor->id, $ip);
        });
        $this->analyze($batchId);
    }

    /**
     * Records the reviewer's choice for source values of one reference column; "remember" keeps it for later imports.
     *
     * @param  array<string, int|null>  $choices  normalized source value => target id or null (keep the text only)
     */
    public function saveDecisions(int $batchId, string $field, array $choices, bool $remember, User $actor, ?string $ip): void
    {
        abort_unless(isset(EmployeeImportFields::REFERENCES[$field]), 422);
        DB::transaction(function () use ($batchId, $field, $choices, $remember, $actor, $ip): void {
            $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $this->editable($batch);
            $references = new ImportReferences;
            $decisions = $this->decisions($batch);
            foreach ($choices as $key => $target) {
                $key = EmployeeImportFields::normalize((string) $key);
                if ($key === '') {
                    continue;
                }
                $target = $target === null || $target === '' ? null : (int) $target;
                if ($target !== null && $references->option($field, $target) === null) {
                    throw ValidationException::withMessages(['choices' => 'القيمة المختارة غير موجودة أو غير نشطة.']);
                }
                $decisions[$field][$key] = $target;
                if ($remember) {
                    DB::table('employee_import_aliases')->updateOrInsert(['field_key' => $field, 'source_normalized' => $key],
                        ['target_id' => $target, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('employee_import_batches')->where('id', $batchId)->update(['value_map' => json_encode($decisions, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            $this->audit->recordRow('hr.employee_import.values', 'employee_import_batches', $batchId, null,
                ['field' => $field, 'decisions' => count($choices), 'remembered' => $remember], $actor->id, $ip);
        });
        $this->analyze($batchId);
    }

    /**
     * A new reference value from the import screen. Only the system administrator maintains reference lists, and
     * departments and job titles are never created here (they belong to the structure and the job catalogue).
     */
    public function createReferenceValue(string $field, string $name, User $actor, ?string $ip): int
    {
        $reference = EmployeeImportFields::REFERENCES[$field] ?? null;
        abort_unless($reference && $reference['create'] && $reference['source'] === 'master', 422);
        abort_unless($actor->isSystemAdmin(), 403, 'إضافة القيم إلى ثوابت النظام لمدير النظام فقط.');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'اكتب اسم القيمة (حتى 255 حرفًا).']);
        }

        return DB::transaction(function () use ($reference, $name, $actor, $ip): int {
            $typeId = (int) DB::table('master_data_types')->where('code', $reference['type'])->lockForUpdate()->value('id');
            abort_unless($typeId > 0, 422);
            $normalized = EmployeeImportFields::normalize($name);
            foreach (DB::table('master_data_values')->where('type_id', $typeId)->get(['id', 'name_ar']) as $existing) {
                if (EmployeeImportFields::normalize($existing->name_ar) === $normalized) {
                    return (int) $existing->id;
                }
            }
            $code = 'imp_'.substr(hash('sha256', $normalized), 0, 12);
            $data = ['type_id' => $typeId, 'code' => $code, 'name_ar' => $name, 'sort_order' => (int) DB::table('master_data_values')->where('type_id', $typeId)->max('sort_order') + 10];
            $id = (int) DB::table('master_data_values')->insertGetId([...$data, 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('master_data.value.created', 'master_data_values', $id, null, [...$data, 'source' => 'استيراد الموظفين'], $actor->id, $ip);

            return $id;
        });
    }

    /**
     * Per-row action chosen in the preview. Only actions the row allows are accepted.
     *
     * @param  list<string>  $groups
     */
    public function setRowAction(int $batchId, int $rowId, string $action, array $groups, User $actor, ?string $ip): void
    {
        DB::transaction(function () use ($batchId, $rowId, $action, $groups, $actor, $ip): void {
            $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $this->editable($batch);
            $row = DB::table('employee_import_rows')->where('batch_id', $batchId)->where('id', $rowId)->lockForUpdate()->first();
            abort_unless($row && $row->result === 'pending', 404);
            if (! in_array($action, $this->allowedActions($row), true)) {
                throw ValidationException::withMessages(['action' => 'هذا الإجراء غير متاح لهذا الصف.']);
            }
            $groups = array_values(array_intersect($groups, array_keys(self::UPDATE_GROUPS)));
            if ($action === 'update' && $groups === []) {
                throw ValidationException::withMessages(['action' => 'اختر مجموعة حقول واحدة على الأقل للتحديث.']);
            }
            DB::table('employee_import_rows')->where('id', $rowId)->update(['action' => $action, 'action_reviewed' => true,
                'update_fields' => $action === 'update' ? json_encode($groups) : null, 'updated_at' => now()]);
            $this->audit->recordRow('hr.employee_import.row_action', 'employee_import_batches', $batchId,
                ['row' => (int) $row->row_number, 'action' => $row->action], ['row' => (int) $row->row_number, 'action' => $action, 'groups' => $groups], $actor->id, $ip);
        });
        $this->summarize($batchId);
    }

    /**
     * @return list<string>
     */
    public function allowedActions(object $row): array
    {
        return match (true) {
            $row->status === 'error' => ['reject'],
            $row->matched_employee_id !== null => ['skip', 'update', 'reject'],
            default => ['insert', 'reject'],
        };
    }

    /**
     * Normalises every pending row, finds duplicates inside the file and matches against existing profiles and
     * accounts, then sets each row's status and default action. Rows already processed by a commit are untouched.
     */
    public function analyze(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch = DB::table('employee_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            if (! in_array($batch->status, ['staged', 'validated', 'failed'], true)) {
                return;
            }
            $options = $this->options($batch);
            $map = array_filter($this->columnMap($batch), fn (?string $field): bool => $field !== null && $field !== 'ignore');
            $references = new ImportReferences($this->decisions($batch));
            $rows = DB::table('employee_import_rows')->where('batch_id', $batchId)->orderBy('row_number')->get();

            $normalized = [];
            foreach ($rows as $row) {
                $normalized[$row->id] = $this->normalizer->row(json_decode(Crypt::decryptString($row->raw_payload), true), $map, $options, $references);
            }

            $numberOf = fn (array $values): ?string => $values['employee_number'] ?? (($options['employee_number_from_attendance'] ?? true) ? ($values['attendance_number'] ?? null) : null);
            $seen = ['national_id' => [], 'attendance_number' => [], 'number' => [], 'bank_account' => []];
            foreach ($normalized as $id => $result) {
                foreach (['national_id', 'attendance_number', 'bank_account'] as $key) {
                    if (isset($result['values'][$key])) {
                        $seen[$key][$result['values'][$key]][] = $id;
                    }
                }
                if (($number = $numberOf($result['values'])) !== null) {
                    $seen['number'][$number][] = $id;
                }
            }

            $values = array_column($normalized, 'values');
            $collect = fn (string $key): array => array_values(array_unique(array_filter(array_map(fn (array $v) => $v[$key] ?? null, $values))));
            $numbers = array_values(array_unique(array_filter(array_map($numberOf, $values))));
            $employees = DB::table('employees')
                ->where(fn ($query) => $query->whereIn('national_id', $collect('national_id') ?: ['-'])
                    ->orWhereIn('employee_number', $numbers ?: ['-'])->orWhereIn('attendance_number', $collect('attendance_number') ?: ['-']))
                ->get(['id', 'national_id', 'employee_number', 'attendance_number', 'deleted_at', 'user_id']);
            $accounts = DB::table('users as u')->leftJoin('employees as e', 'e.user_id', '=', 'u.id')
                ->whereIn('u.employee_number', $numbers ?: ['-'])->get(['u.id', 'u.name', 'u.employee_number', 'e.id as profile_id'])->keyBy('employee_number');
            $hashes = [];
            foreach ($collect('bank_account') as $account) {
                $hashes[$this->accountHash($account)] = $account;
            }
            $existingAccounts = DB::table('employee_bank_accounts')->whereIn('account_hash', array_keys($hashes) ?: ['-'])->whereNull('effective_to')
                ->get(['employee_id', 'account_hash']);

            foreach ($rows as $row) {
                $result = $normalized[$row->id];
                $v = $result['values'];
                $issues = $result['issues'];
                $add = function (string $field, string $code) use (&$issues): void {
                    $issues[] = ['field' => $field, 'code' => $code];
                };
                if ($row->result !== 'pending') {
                    continue;
                }

                if (! isset($v['national_id']) && ! isset($v['attendance_number']) && ! isset($v['employee_number'])) {
                    $add('national_id', 'no_key');
                }
                foreach (['national_id' => 'duplicate_national_id', 'attendance_number' => 'duplicate_attendance'] as $key => $code) {
                    if (isset($v[$key]) && count($seen[$key][$v[$key]]) > 1) {
                        $add($key, $code);
                    }
                }
                $number = $numberOf($v);
                if ($number !== null && count($seen['number'][$number]) > 1 && ! isset($v['attendance_number'], $seen['attendance_number'][$number])) {
                    $add('employee_number', 'duplicate_employee_number');
                }
                if (isset($v['bank_account']) && count($seen['bank_account'][$v['bank_account']]) > 1) {
                    $add('bank_account', 'account_duplicate_in_file');
                }

                $candidates = $employees->filter(fn (object $employee): bool => (isset($v['national_id']) && $employee->national_id === $v['national_id'])
                    || ($number !== null && $employee->employee_number === $number)
                    || (isset($v['attendance_number']) && $employee->attendance_number === $v['attendance_number']));
                $matched = null;
                $matchType = null;
                if ($candidates->contains(fn (object $employee): bool => $employee->deleted_at !== null)) {
                    $add('national_id', 'archived_match');
                } elseif ($candidates->pluck('id')->unique()->count() > 1) {
                    $add('national_id', 'match_conflict');
                } elseif ($candidates->isNotEmpty()) {
                    $matched = $candidates->first();
                    // nid = identity number, emp = employee number, att = biometric number.
                    $matchType = implode('+', array_filter([
                        isset($v['national_id']) && $matched->national_id === $v['national_id'] ? 'nid' : null,
                        $number !== null && $matched->employee_number === $number ? 'emp' : null,
                        isset($v['attendance_number']) && $matched->attendance_number === $v['attendance_number'] ? 'att' : null,
                    ]));
                    $add('row', 'matched_existing');
                } else {
                    if ($number === null) {
                        $add('employee_number', 'employee_number_missing');
                    }
                    if (! isset($v['full_name'])) {
                        $add('full_name', 'name_missing');
                    }
                    $account = $number !== null ? $accounts->get($number) : null;
                    if ($account) {
                        $sameName = isset($v['first_name_ar'], $v['family_name_ar']) && EmployeeNames::accountMatches((string) $account->name, $v['first_name_ar'], $v['family_name_ar']);
                        $add('employee_number', $account->profile_id === null && $sameName ? 'account_exists' : 'number_taken_by_account');
                    }
                }
                if (isset($v['bank_account'])) {
                    $owner = $existingAccounts->firstWhere('account_hash', $this->accountHash($v['bank_account']));
                    if ($owner && (! $matched || (int) $owner->employee_id !== (int) $matched->id)) {
                        $add('bank_account', 'account_duplicate_existing');
                    }
                }

                $levels = array_map(fn (array $issue): string => EmployeeImportFields::issue($issue['code'])['level'], $issues);
                $status = in_array('error', $levels, true) ? 'error' : (array_intersect(['field', 'warning'], $levels) !== [] ? 'warning' : 'valid');
                $probe = (object) ['status' => $status, 'matched_employee_id' => $matched?->id];
                $allowed = $this->allowedActions($probe);
                $keep = $row->action_reviewed && in_array($row->action, $allowed, true) && ($row->action !== 'update' || $row->update_fields);
                $action = $keep ? $row->action : $allowed[0];

                DB::table('employee_import_rows')->where('id', $row->id)->update([
                    'normalized_payload' => Crypt::encryptString(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'issues' => json_encode($issues, JSON_UNESCAPED_UNICODE), 'status' => $status,
                    'match_type' => $matchType, 'matched_employee_id' => $matched?->id,
                    'action' => $action, 'action_reviewed' => $keep, 'update_fields' => $keep ? $row->update_fields : null, 'updated_at' => now(),
                ]);
            }

            $unresolved = array_keys(array_filter($this->columnMap($batch), fn (?string $field): bool => $field === null));
            DB::table('employee_import_batches')->where('id', $batchId)->update([
                'status' => $batch->status === 'failed' ? 'failed' : ($unresolved === [] ? 'validated' : 'staged'),
                'validated_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->summarize($batchId);
    }

    /**
     * Counts for the preview and the reconciliation report. No personal values.
     */
    public function summarize(int $batchId): array
    {
        $batch = $this->batch($batchId);
        $rows = DB::table('employee_import_rows')->where('batch_id', $batchId)->get(['status', 'action', 'result', 'issues', 'matched_employee_id', 'normalized_payload', 'update_fields']);
        $issueCounts = [];
        $confidential = ['identity' => 0, 'pay' => 0, 'bank' => 0, 'leave' => 0, 'address' => 0];
        foreach ($rows as $row) {
            foreach (json_decode((string) $row->issues, true) ?: [] as $issue) {
                $issueCounts[$issue['code']] = ($issueCounts[$issue['code']] ?? 0) + 1;
            }
            if (! in_array($row->action, ['insert', 'update'], true) || $row->normalized_payload === null) {
                continue;
            }
            $values = json_decode(Crypt::decryptString($row->normalized_payload), true) ?: [];
            $groups = $row->action === 'update' ? (json_decode((string) $row->update_fields, true) ?: []) : array_keys(self::UPDATE_GROUPS);
            $confidential['identity'] += isset($values['national_id']) && $row->action === 'insert' ? 1 : 0;
            $confidential['pay'] += in_array('pay', $groups, true) && array_intersect_key($values, array_flip([...EmployeeImportNormalizer::MONEY_FIELDS, ...EmployeeImportNormalizer::RATE_FIELDS])) !== [] ? 1 : 0;
            $confidential['bank'] += in_array('bank', $groups, true) && isset($values['bank_account']) ? 1 : 0;
            $confidential['leave'] += in_array('leave', $groups, true) && array_intersect_key($values, array_flip(EmployeeImportNormalizer::LEAVE_FIELDS)) !== [] ? 1 : 0;
            $confidential['address'] += in_array('address', $groups, true) && isset($values['address']) ? 1 : 0;
        }
        $map = $this->columnMap($batch);
        $summary = [
            'rows' => $rows->count(),
            'status' => ['valid' => $rows->where('status', 'valid')->count(), 'warning' => $rows->where('status', 'warning')->count(), 'error' => $rows->where('status', 'error')->count()],
            'actions' => collect(['insert', 'update', 'skip', 'reject'])->mapWithKeys(fn (string $action): array => [$action => $rows->where('action', $action)->count()])->all(),
            'results' => collect(['pending', 'inserted', 'updated', 'skipped', 'rejected', 'failed', 'reverted', 'revert_blocked'])->mapWithKeys(fn (string $result): array => [$result => $rows->where('result', $result)->count()])->all(),
            'matched' => $rows->whereNotNull('matched_employee_id')->count(),
            'issues' => $issueCounts,
            'columns' => ['total' => count($map), 'mapped' => count(array_filter($map, fn (?string $field): bool => $field !== null && $field !== 'ignore')),
                'ignored' => count(array_filter($map, fn (?string $field): bool => $field === 'ignore')), 'unresolved' => array_keys(array_filter($map, fn (?string $field): bool => $field === null))],
            'confidential' => $confidential,
        ];
        DB::table('employee_import_batches')->where('id', $batchId)->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);

        return $summary;
    }

    /**
     * Distinct source values of each mapped reference column, how each one resolves today and the selectable targets.
     *
     * @return array<string, array{label: string, can_create: bool, options: list<array{id: int, label: string}>, values: list<array<string, mixed>>}>
     */
    public function referenceSummary(object $batch, User $viewer): array
    {
        $references = new ImportReferences($this->decisions($batch));
        $mapped = array_values(array_intersect(array_keys(EmployeeImportFields::REFERENCES), $this->columnMap($batch)));
        $counts = [];
        foreach (DB::table('employee_import_rows')->where('batch_id', $batch->id)->whereNotNull('normalized_payload')->pluck('normalized_payload') as $payload) {
            $values = json_decode(Crypt::decryptString($payload), true) ?: [];
            foreach ($mapped as $field) {
                if (isset($values[$field.'_source'])) {
                    $key = EmployeeImportFields::normalize($values[$field.'_source']);
                    $counts[$field][$key] ??= ['source' => $values[$field.'_source'], 'count' => 0];
                    $counts[$field][$key]['count']++;
                }
            }
        }
        $summary = [];
        foreach ($mapped as $field) {
            $values = [];
            foreach ($counts[$field] ?? [] as $key => $entry) {
                $resolved = $references->resolve($field, $entry['source']);
                $values[] = ['key' => $key, 'source' => $entry['source'], 'count' => $entry['count'], 'target_id' => $resolved['id'], 'how' => $resolved['how']];
            }
            usort($values, fn (array $a, array $b): int => [$a['target_id'] === null && $a['how'] !== 'decision' && $a['how'] !== 'alias' ? 0 : 1, -$a['count']] <=> [$b['target_id'] === null && $b['how'] !== 'decision' && $b['how'] !== 'alias' ? 0 : 1, -$b['count']]);
            $reference = EmployeeImportFields::REFERENCES[$field];
            $summary[$field] = [
                'label' => EmployeeImportFields::FIELDS[$field][0],
                'can_create' => $reference['create'] && $viewer->isSystemAdmin(),
                'options' => array_map(fn (array $option): array => ['id' => $option['id'], 'label' => $option['label']], $references->options($field)),
                'values' => $values,
            ];
        }

        return $summary;
    }

    /**
     * Rows for the preview, with values masked by the viewer's permissions.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function previewRows(object $batch, User $viewer, string $filter, int $page): array
    {
        $query = DB::table('employee_import_rows as r')->leftJoin('employees as e', 'e.id', '=', 'r.matched_employee_id')
            ->where('r.batch_id', $batch->id)
            ->when(in_array($filter, ['valid', 'warning', 'error'], true), fn ($q) => $q->where('r.status', $filter))
            ->when(in_array($filter, ['insert', 'update', 'skip', 'reject'], true), fn ($q) => $q->where('r.action', $filter))
            ->when($filter === 'matched', fn ($q) => $q->whereNotNull('r.matched_employee_id'))
            ->orderBy('r.row_number');
        $total = (clone $query)->count();
        $perPage = 50;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $pay = $this->access->canViewPay($viewer);
        $bank = $this->access->canViewBank($viewer);

        $data = $query->forPage($page, $perPage)->get(['r.id', 'r.row_number', 'r.status', 'r.action', 'r.update_fields', 'r.result', 'r.issues', 'r.match_type', 'r.matched_employee_id',
            'r.normalized_payload', 'r.employee_id', 'e.employee_number as matched_number', 'e.first_name_ar as matched_first', 'e.family_name_ar as matched_family',
            'e.full_name_ar as matched_full'])
            ->map(function (object $row) use ($pay, $bank): array {
                $values = $row->normalized_payload ? (json_decode(Crypt::decryptString($row->normalized_payload), true) ?: []) : [];
                $shown = [];
                foreach ($values as $key => $value) {
                    $base = preg_replace('/_(source|id)$/', '', (string) $key);
                    $group = EmployeeImportFields::FIELDS[$base][3] ?? (in_array($key, ['phone', 'alternative_phone', 'landline_phone'], true) ? 'contact' : 'personal');
                    if (str_ends_with((string) $key, '_id') || in_array($key, ['leave_consistency'], true)) {
                        continue;
                    }
                    $shown[$key] = match (true) {
                        $group === 'pay' && ! $pay => '••••',
                        $key === 'bank_account' && ! $bank => '••••'.substr((string) $value, -4),
                        default => $value,
                    };
                }

                return [
                    'id' => (int) $row->id, 'row' => (int) $row->row_number, 'status' => $row->status, 'action' => $row->action, 'result' => $row->result,
                    'groups' => json_decode((string) $row->update_fields, true) ?: [],
                    'allowed' => $row->result === 'pending' ? $this->allowedActions($row) : [],
                    'match' => $row->matched_employee_id ? ['id' => (int) $row->matched_employee_id, 'type' => $row->match_type, 'number' => $row->matched_number,
                        'name' => EmployeeNames::display((object) ['full_name_ar' => $row->matched_full, 'first_name_ar' => $row->matched_first, 'family_name_ar' => $row->matched_family])] : null,
                    'employee_id' => $row->employee_id ? (int) $row->employee_id : null,
                    'issues' => array_map(fn (array $issue): array => [...$issue, ...EmployeeImportFields::issue($issue['code']),
                        'field_label' => EmployeeImportFields::FIELDS[$issue['field']][0] ?? ($issue['field'] === 'options' ? 'خيارات الدفعة' : 'الصف')], json_decode((string) $row->issues, true) ?: []),
                    'values' => $shown,
                ];
            })->all();

        return ['data' => $data, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Sanitised issue report (CSV, UTF-8): row pointers and issue texts only; no names, identity, pay or bank values.
     */
    public function issueReportCsv(object $batch): string
    {
        $lines = [['رقم الصف في الملف', 'رقم جهاز البصمة', 'حالة الصف', 'الإجراء', 'النتيجة', 'العمود', 'المستوى', 'المشكلة']];
        $levels = ['error' => 'يمنع الاستيراد', 'field' => 'حقل لم يُحفظ', 'warning' => 'تنبيه', 'info' => 'معلومة'];
        foreach (DB::table('employee_import_rows')->where('batch_id', $batch->id)->orderBy('row_number')->get() as $row) {
            $values = $row->normalized_payload ? (json_decode(Crypt::decryptString($row->normalized_payload), true) ?: []) : [];
            foreach (json_decode((string) $row->issues, true) ?: [] as $issue) {
                $meta = EmployeeImportFields::issue($issue['code']);
                $lines[] = [(string) $row->row_number, (string) ($values['attendance_number'] ?? ''), $row->status, $row->action, $row->result,
                    EmployeeImportFields::FIELDS[$issue['field']][0] ?? $issue['field'], $levels[$meta['level']] ?? $meta['level'], $meta['message']];
            }
        }
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($handle, array_map(fn (string $cell): string => preg_match('/^[=+\-@]/', $cell) ? "'".$cell : $cell, $line), ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function accountHash(string $account): string
    {
        return hash_hmac('sha256', strtoupper($account), (string) config('app.key'));
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')->all();
    }
}
