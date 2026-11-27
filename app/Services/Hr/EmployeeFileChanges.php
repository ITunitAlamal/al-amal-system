<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * What HR staff prepare on employee files, and the HR head's review (approved HR requirements §7.1, §11, §13).
 *
 * An HR staff member (EmployeeAccess::canPrepare) submits a new file or a change to a file; nothing reaches the file
 * until the appointed HR head approves it. The head approves it (the file controller then writes it the same way as
 * the head's own edit, recording who prepared and who approved) or returns it with a note; the preparer may withdraw
 * it. A change keeps only what differs from the file and the values it replaces, so an approval over data changed
 * since is refused instead of silently overwriting it. One open change per file; the preparer's later edits of the
 * same file join it. Nobody decides their own change (also a database CHECK). Pay and bank stay on
 * EMPLOYEE_PAY_CHANGE; documents and photos keep their own verification (EmployeeFiles).
 */
final class EmployeeFileChanges
{
    /** To the HR head: a prepared file or change waits for approval. */
    public const EVENT_SUBMITTED = 'employee_file_change_submitted';

    /** To the preparer: their file or change was approved or returned. */
    public const EVENT_DECIDED = 'employee_file_change_decided';

    public const OPEN = ['pending', 'returned'];

    /** @var array<string, array{0: string, 1: string}> status => label, tone */
    public const STATUS = [
        'pending' => ['بانتظار اعتماد رئيس الموارد البشرية', 'info'],
        'returned' => ['أُعيد للاستكمال', 'warning'],
        'approved' => ['معتمد ومثبت في الملف', 'success'],
        'withdrawn' => ['مسحوب', 'muted'],
    ];

    /**
     * The row collections of the profile form: relation and the columns saved for each row.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const COLLECTIONS = [
        'addresses' => ['addresses', ['address_type', 'governorate', 'city', 'area', 'neighborhood', 'governorate_value_id', 'locality_value_id', 'area_value_id',
            'subarea_value_id', 'address_other', 'address_details', 'notes', 'valid_from', 'valid_to', 'is_active']],
        'qualifications' => ['qualifications', ['qualification_name', 'degree_level', 'institution_name', 'specialization', 'issuing_country_code', 'study_start_date',
            'graduation_date', 'graduation_year', 'grade', 'gpa', 'gpa_scale', 'certificate_number', 'notes', 'is_highest_qualification']],
        'family_members' => ['familyMembers', ['relationship_code', 'full_name_ar', 'full_name_en', 'national_id', 'date_of_birth', 'phone', 'notes', 'is_dependent', 'is_active']],
    ];

    private const LABELS = [
        'employee_number' => 'الرقم الوظيفي', 'first_name_ar' => 'الاسم الأول', 'father_name_ar' => 'اسم الأب', 'grandfather_name_ar' => 'اسم الجد',
        'family_name_ar' => 'اسم العائلة', 'full_name_ar' => 'الاسم الكامل كما ورد', 'original_family_name_ar' => 'اسم العائلة الأصلي',
        'name_parts_reviewed' => 'تأكيد تقسيم الاسم', 'children_count' => 'عدد الأبناء', 'landline_phone' => 'الهاتف الأرضي', 'residence_address' => 'عنوان السكن',
        'attendance_number' => 'رقم البصمة', 'full_name_en' => 'الاسم بالإنجليزية', 'national_id' => 'رقم الوثيقة', 'identity_document_type_value_id' => 'نوع الوثيقة',
        'nationality_value_id' => 'الجنسية', 'date_of_birth' => 'تاريخ الميلاد', 'gender' => 'الجنس', 'phone' => 'الجوال الأساسي', 'alternative_phone' => 'الجوال الاحتياطي',
        'personal_email' => 'البريد الشخصي', 'department_id' => 'القسم', 'job_category_id' => 'الفئة الوظيفية', 'job_title_id' => 'المسمى الوظيفي',
        'job_grade_id' => 'الدرجة الوظيفية', 'hire_date' => 'تاريخ التعيين', 'work_start_date' => 'تاريخ مباشرة العمل', 'employment_status' => 'الحالة الوظيفية',
        'notes' => 'ملاحظات', 'place_of_birth' => 'مكان الميلاد', 'marital_status_value_id' => 'الحالة الاجتماعية', 'has_qualifications' => 'لديه مؤهلات',
        'has_family_members' => 'لديه أفراد عائلة', 'employment_term_value_id' => 'بند التوظيف', 'contract_type_value_id' => 'نوع العقد',
        'work_schedule_value_id' => 'نظام الدوام', 'administrative_level_value_id' => 'المستوى الإداري', 'link_existing_account' => 'ربط بحساب دخول قائم',
        'confirm_no_opening_balances' => 'لا توجد أرصدة إجازات افتتاحية',
        // Row fields
        'address_type' => 'نوع العنوان', 'governorate' => 'المحافظة', 'city' => 'المدينة', 'area' => 'المنطقة', 'neighborhood' => 'الحي', 'address_details' => 'التفاصيل',
        'valid_from' => 'من', 'valid_to' => 'إلى', 'is_active' => 'نشط', 'qualification_name' => 'المؤهل', 'degree_level' => 'الدرجة العلمية', 'institution_name' => 'المؤسسة',
        'specialization' => 'التخصص', 'issuing_country_code' => 'دولة الإصدار', 'study_start_date' => 'بداية الدراسة', 'graduation_date' => 'تاريخ التخرج',
        'graduation_year' => 'سنة التخرج', 'grade' => 'التقدير', 'gpa' => 'المعدل', 'gpa_scale' => 'من أصل', 'certificate_number' => 'رقم الشهادة',
        'is_highest_qualification' => 'أعلى مؤهل', 'relationship_code' => 'صلة القرابة', 'is_dependent' => 'مُعال', 'leave_type_value_id' => 'نوع الإجازة',
        'balance_year' => 'السنة', 'annual_entitlement_days' => 'الاستحقاق السنوي', 'opening_balance_days' => 'الرصيد الافتتاحي',
    ];

    private const ROW_LABELS = ['addresses' => 'عنوان', 'qualifications' => 'مؤهل', 'family_members' => 'فرد عائلة', 'leave_opening_balances' => 'رصيد إجازة افتتاحي'];

    private const BOOLEAN_FIELDS = ['name_parts_reviewed', 'has_qualifications', 'has_family_members', 'link_existing_account', 'confirm_no_opening_balances',
        'is_active', 'address_other', 'is_highest_qualification', 'is_dependent'];

    /** Lookup and technical fields left out of the summary of a row (the labels resolved from them are shown). */
    private const HIDDEN_ROW_FIELDS = ['id', 'governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id', 'address_other'];

    private const EMPLOYMENT_STATUS = ['active' => 'على رأس عمله', 'inactive' => 'غير نشط', 'on_leave' => 'في إجازة', 'terminated' => 'انتهت الخدمة'];

    private static ?bool $available = null;

    public function __construct(
        private readonly EmployeeAccess $access,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    /** Whether migration 000035 has run; until then HR staff cannot submit and nothing is listed. */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('employee_file_changes');
    }

    public static function url(?int $changeId = null): string
    {
        return route('admin.employee-file-changes.index', $changeId ? ['change' => $changeId] : [], false);
    }

    /** One comparable form for a stored or submitted value: dates as Y-m-d, booleans as 1/0, decimals without trailing zeros. */
    public static function normalize(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (function () use ($value): ?string {
                $text = trim((string) $value);
                if ($text === '') {
                    return null;
                }
                // Only a real date or timestamp is cut to its date; free text that merely starts with a date stays whole.
                if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}(:?\d{2})?)?)?$/', $text)) {
                    return substr($text, 0, 10);
                }

                return preg_match('/^-?\d+\.\d+$/', $text) ? rtrim(rtrim($text, '0'), '.') : $text;
            })(),
        };
    }

    // ---------------------------------------------------------------- submitting

    /** The open change of a file (pending or returned), if any. */
    public function openFor(int $employeeId): ?object
    {
        return self::available() ? DB::table('employee_file_changes')->where('employee_id', $employeeId)->where('kind', 'update')
            ->whereIn('status', self::OPEN)->first() : null;
    }

    /**
     * Submits (or adds to) the preparer's change to a file. Only what differs from the file is kept, with the values it
     * replaces. The basic fields sent now replace those of the open change (the form shows the open change); a
     * collection replaces its own rows only.
     *
     * @param  array<string, mixed>  $data  validated form data (EmployeeProfileController::update)
     * @param  list<string>  $basicKeys
     */
    public function submitUpdate(User $actor, Employee $record, array $data, array $basicKeys, ?string $ip): void
    {
        $this->assertCanSubmit($actor);
        DB::transaction(function () use ($actor, $record, $data, $basicKeys, $ip): void {
            $locked = Employee::query()->lockForUpdate()->findOrFail($record->id);
            $open = DB::table('employee_file_changes')->where('employee_id', $locked->id)->where('kind', 'update')->whereIn('status', self::OPEN)->lockForUpdate()->first();
            if ($open && (int) $open->prepared_by !== (int) $actor->id) {
                throw ValidationException::withMessages(['file_change' => 'على هذا الملف تعديل أعده '.$this->userName((int) $open->prepared_by)
                    .' ولم يُبت فيه بعد. ينتظر قرار رئيس الموارد البشرية أو سحبه من مُعِدّه.']);
            }
            if ($open) {
                $this->assertFresh($open, $locked);
            }
            ['payload' => $payload, 'before' => $before] = $this->diff($locked, $data, $basicKeys);
            if ($open) {
                [$payload, $before] = $this->merge($open, $data, $basicKeys, $payload, $before);
            }
            if ($payload === []) {
                throw ValidationException::withMessages(['file_change' => $open
                    ? 'لم يبقَ في التعديل أي تغيير عن الملف. إن لم تعد تحتاجه فاسحبه من «تعديلات ملفات الموظفين».'
                    : 'لا يوجد تغيير عن بيانات الملف لإرساله.']);
            }

            $values = ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'before' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'pending', 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null, 'updated_at' => now()];
            if ($open) {
                $revision = (int) $open->revision + 1;
                DB::table('employee_file_changes')->where('id', $open->id)->update([...$values, 'revision' => $revision]);
                $id = (int) $open->id;
            } else {
                $revision = 1;
                $id = (int) DB::table('employee_file_changes')->insertGetId([...$values, 'kind' => 'update', 'employee_id' => $locked->id,
                    'employee_number' => $locked->employee_number, 'revision' => 1, 'prepared_by' => $actor->id, 'created_at' => now()]);
            }
            $this->audit->recordRow($open ? 'employee_file_change.resubmitted' : 'employee_file_change.submitted', 'employee_file_changes', $id, null,
                ['kind' => 'update', 'employee_id' => (int) $locked->id, 'revision' => $revision, 'changed_fields' => $this->changedKeys($payload)], (int) $actor->id, $ip);
            $this->notifyReviewer($actor, $id, $revision, 'تعديل ملف موظف بانتظار اعتمادك: '.EmployeeNames::display($locked));
        });
    }

    /**
     * Submits a new file prepared by HR staff, or the corrected version of one returned to them.
     *
     * @param  array<string, mixed>  $data  validated form data (EmployeeProfileController::store)
     */
    public function submitCreate(User $actor, array $data, ?int $changeId, ?string $ip): int
    {
        $this->assertCanSubmit($actor);

        return DB::transaction(function () use ($actor, $data, $changeId, $ip): int {
            $number = (string) $data['employee_number'];
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $values = ['employee_number' => $number, 'payload' => $payload, 'before' => null, 'status' => 'pending', 'submitted_at' => now(),
                'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null, 'updated_at' => now()];
            // Two staff submitting the same number at once: the second waits here and then sees the first.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['employee-file-create:'.$number]);
            $clash = DB::table('employee_file_changes')->where('kind', 'create')->where('employee_number', $number)->whereIn('status', self::OPEN)
                ->when($changeId, fn ($query) => $query->where('id', '<>', $changeId))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['employee_number' => 'يوجد ملف جديد بهذا الرقم الوظيفي أُعدّ للاعتماد ولم يُبت فيه بعد.']);
            }
            if ($changeId) {
                $open = DB::table('employee_file_changes')->where('id', $changeId)->lockForUpdate()->first();
                abort_unless($open && $open->kind === 'create' && (int) $open->prepared_by === (int) $actor->id && in_array($open->status, self::OPEN, true), 404);
                $revision = (int) $open->revision + 1;
                DB::table('employee_file_changes')->where('id', $changeId)->update([...$values, 'revision' => $revision]);
                $id = $changeId;
            } else {
                $revision = 1;
                $id = (int) DB::table('employee_file_changes')->insertGetId([...$values, 'kind' => 'create', 'employee_id' => null, 'revision' => 1,
                    'prepared_by' => $actor->id, 'created_at' => now()]);
            }
            $this->audit->recordRow($changeId ? 'employee_file_change.resubmitted' : 'employee_file_change.submitted', 'employee_file_changes', $id, null,
                ['kind' => 'create', 'employee_number' => $number, 'revision' => $revision], (int) $actor->id, $ip);
            $this->notifyReviewer($actor, $id, $revision, 'ملف موظف جديد بانتظار اعتمادك: '.EmployeeNames::display((object) $data));

            return $id;
        });
    }

    // ---------------------------------------------------------------- deciding

    /**
     * The HR head approves a pending change. $apply writes it to the file inside this transaction (the same writing as
     * the head's own edit) and returns the employee id; a failure leaves the change pending.
     *
     * @param  callable(object $change, array<string, mixed> $payload, int $preparedBy): int  $apply
     */
    public function approve(User $actor, int $id, int $revision, callable $apply, ?string $ip): object
    {
        abort_unless($this->access->canReviewChanges($actor), 403, 'اعتماد ملفات الموظفين لرئيس الموارد البشرية المعيّن.');

        return DB::transaction(function () use ($actor, $id, $revision, $apply, $ip): object {
            // The file is locked before the change row, the same order as submitUpdate(), so the two never deadlock.
            $peek = DB::table('employee_file_changes')->where('id', $id)->first(['kind', 'employee_id']);
            abort_unless($peek !== null, 404);
            $record = $peek->kind === 'update' && $peek->employee_id ? Employee::query()->lockForUpdate()->findOrFail($peek->employee_id) : null;
            $change = $this->lockedForDecision($actor, $id, $revision);
            if ($change->kind === 'update') {
                $this->assertFresh($change, $record ?? Employee::query()->lockForUpdate()->findOrFail($change->employee_id));
            }
            $employeeId = $apply($change, json_decode((string) $change->payload, true) ?: [], (int) $change->prepared_by);
            DB::table('employee_file_changes')->where('id', $id)->update(['status' => 'approved', 'employee_id' => $employeeId, 'reviewed_by' => $actor->id,
                'reviewed_at' => now(), 'review_note' => null, 'updated_at' => now()]);
            $this->audit->recordRow('employee_file_change.approved', 'employee_file_changes', $id, ['status' => 'pending'],
                ['status' => 'approved', 'kind' => $change->kind, 'employee_id' => $employeeId, 'prepared_by' => (int) $change->prepared_by, 'revision' => (int) $change->revision], (int) $actor->id, $ip);
            $this->notifyPreparer($actor, $change, true, null);

            return DB::table('employee_file_changes')->where('id', $id)->first();
        });
    }

    /** The HR head returns a pending change to its preparer with what is missing or wrong. */
    public function returnToPreparer(User $actor, int $id, int $revision, string $note, ?string $ip): void
    {
        abort_unless($this->access->canReviewChanges($actor), 403, 'إعادة ملفات الموظفين لرئيس الموارد البشرية المعيّن.');
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw ValidationException::withMessages(['note' => 'اكتب ما يجب استكماله أو تصحيحه.']);
        }
        DB::transaction(function () use ($actor, $id, $revision, $note, $ip): void {
            $change = $this->lockedForDecision($actor, $id, $revision);
            DB::table('employee_file_changes')->where('id', $id)->update(['status' => 'returned', 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'review_note' => mb_substr($note, 0, 2000), 'updated_at' => now()]);
            $this->audit->recordRow('employee_file_change.returned', 'employee_file_changes', $id, ['status' => 'pending'],
                ['status' => 'returned', 'kind' => $change->kind, 'employee_id' => $change->employee_id, 'revision' => (int) $change->revision], (int) $actor->id, $ip);
            $this->notifyPreparer($actor, $change, false, $note);
        });
    }

    /** The preparer takes back their own open change. */
    public function withdraw(User $actor, int $id, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $id, $ip): void {
            $change = DB::table('employee_file_changes')->where('id', $id)->lockForUpdate()->first();
            abort_unless($change && (int) $change->prepared_by === (int) $actor->id, 403, 'يسحب التعديل من أعده فقط.');
            if (! in_array($change->status, self::OPEN, true)) {
                throw ValidationException::withMessages(['file_change' => 'بُتّ في هذا التعديل؛ لا يمكن سحبه.']);
            }
            DB::table('employee_file_changes')->where('id', $id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            $this->audit->recordRow('employee_file_change.withdrawn', 'employee_file_changes', $id, ['status' => $change->status],
                ['status' => 'withdrawn', 'kind' => $change->kind, 'employee_id' => $change->employee_id], (int) $actor->id, $ip);
        });
    }

    private function lockedForDecision(User $actor, int $id, int $revision): object
    {
        $change = DB::table('employee_file_changes')->where('id', $id)->lockForUpdate()->first();
        abort_unless($change !== null, 404);
        if ($change->status !== 'pending') {
            throw ValidationException::withMessages(['file_change' => 'لم يعد هذا التعديل بانتظار الاعتماد (بُتّ فيه أو سُحب).']);
        }
        if ((int) $change->revision !== $revision) {
            throw ValidationException::withMessages(['file_change' => 'حدّث مُعِدّ التعديل بياناته بعد أن فتحتَ الصفحة. راجع النسخة الحالية ثم قرر.']);
        }
        if ((int) $change->prepared_by === (int) $actor->id) {
            throw ValidationException::withMessages(['file_change' => 'لا يعتمد أحد تعديلًا أعده بنفسه.']);
        }

        return $change;
    }

    /**
     * Refuses a change whose replaced values are no longer those of the file (edited directly or by another approved
     * change since it was prepared).
     */
    private function assertFresh(object $change, Employee $record): void
    {
        $before = json_decode((string) ($change->before ?? ''), true) ?: [];
        $stale = [];
        foreach ($before['basic'] ?? [] as $key => $value) {
            if (self::normalize($record->getAttribute($key)) !== $value) {
                $stale[] = self::LABELS[$key] ?? $key;
            }
        }
        foreach ($before['rows'] ?? [] as $key => $rows) {
            if (! isset(self::COLLECTIONS[$key]) || $rows === []) {
                continue;
            }
            $stored = $record->{self::COLLECTIONS[$key][0]}()->whereIn('id', array_map('intval', array_keys($rows)))->get()->keyBy('id');
            foreach ($rows as $rowId => $fields) {
                $current = $stored->get((int) $rowId);
                foreach ($fields as $field => $value) {
                    if ($current === null || self::normalize($current->getAttribute($field)) !== $value) {
                        $stale[] = self::ROW_LABELS[$key];

                        continue 2;
                    }
                }
            }
        }
        if ($stale !== []) {
            throw ValidationException::withMessages(['file_change' => 'تغيّرت في الملف بعد إعداد التعديل: '.implode('، ', array_values(array_unique($stale)))
                .'. أعِد التعديل إلى مُعِدّه ليبنيه على البيانات الحالية.']);
        }
    }

    // ---------------------------------------------------------------- differences

    /**
     * What the submitted data changes in the file, and the current values it replaces.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $basicKeys
     * @return array{payload: array<string, mixed>, before: array{basic: array<string, ?string>, rows: array<string, array<string, array<string, ?string>>>}}
     */
    private function diff(Employee $record, array $data, array $basicKeys): array
    {
        $payload = [];
        $before = ['basic' => [], 'rows' => []];
        foreach ($basicKeys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'name_parts_reviewed') {
                if ($data[$key] && $record->name_review_status === 'suggested') {
                    $payload[$key] = true;
                }

                continue;
            }
            $old = self::normalize($record->getAttribute($key));
            if (self::normalize($data[$key]) !== $old) {
                $payload[$key] = $data[$key];
                $before['basic'][$key] = $old;
            }
        }
        foreach (self::COLLECTIONS as $key => [$relation, $fields]) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $stored = $record->{$relation}()->get()->keyBy('id');
            $rows = [];
            foreach ($data[$key] as $row) {
                if (empty($row['id'])) {
                    $rows[] = $row;

                    continue;
                }
                $current = $stored->get((int) $row['id']);
                if ($current === null) {
                    continue;
                }
                $snapshot = [];
                $changed = false;
                foreach ($fields as $field) {
                    if (! array_key_exists($field, $row)) {
                        continue;
                    }
                    $snapshot[$field] = self::normalize($current->getAttribute($field));
                    $changed = $changed || self::normalize($row[$field]) !== $snapshot[$field];
                }
                if ($changed) {
                    $rows[] = $row;
                    $before['rows'][$key][(string) $row['id']] = $snapshot;
                }
            }
            if ($rows !== []) {
                $payload[$key] = $rows;
            }
        }

        return ['payload' => $payload, 'before' => $before];
    }

    /**
     * @param  list<string>  $basicKeys
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $before
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function merge(object $open, array $data, array $basicKeys, array $payload, array $before): array
    {
        $oldPayload = json_decode((string) $open->payload, true) ?: [];
        $oldBefore = json_decode((string) ($open->before ?? ''), true) ?: [];
        $merged = $oldPayload;
        $mergedBefore = ['basic' => $oldBefore['basic'] ?? [], 'rows' => $oldBefore['rows'] ?? []];
        foreach ($basicKeys as $key) {
            // The name-split confirmation is a tick the form always sends unticked; an earlier tick stays.
            if (! array_key_exists($key, $data) || ($key === 'name_parts_reviewed' && ! array_key_exists($key, $payload))) {
                continue;
            }
            unset($merged[$key], $mergedBefore['basic'][$key]);
            if (array_key_exists($key, $payload)) {
                $merged[$key] = $payload[$key];
                if (array_key_exists($key, $before['basic'])) {
                    $mergedBefore['basic'][$key] = $before['basic'][$key];
                }
            }
        }
        foreach (array_keys(self::COLLECTIONS) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            unset($merged[$key], $mergedBefore['rows'][$key]);
            if (isset($payload[$key])) {
                $merged[$key] = $payload[$key];
                $mergedBefore['rows'][$key] = $before['rows'][$key] ?? [];
            }
        }

        return [$merged, $mergedBefore];
    }

    /**
     * The edit form of a file with the viewer's own open change shown in it, so a later step does not send the old
     * values back. Rows of the change replace the stored rows with the same id; new rows are added.
     *
     * @param  array<string, mixed>  $employee  the form payload of the file
     * @return array<string, mixed>
     */
    public function overlay(array $employee, object $change): array
    {
        $payload = json_decode((string) $change->payload, true) ?: [];
        foreach (Arr::except($payload, [...array_keys(self::COLLECTIONS), 'name_parts_reviewed']) as $key => $value) {
            $employee[$key] = $value;
        }
        foreach (array_keys(self::COLLECTIONS) as $key) {
            if (! isset($payload[$key])) {
                continue;
            }
            $rows = collect($employee[$key] ?? [])->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));
            foreach ($payload[$key] as $row) {
                if (! empty($row['id']) && $rows->has((string) $row['id'])) {
                    $rows->put((string) $row['id'], [...$rows->get((string) $row['id']), ...$row]);
                } else {
                    $rows->push($row);
                }
            }
            $employee[$key] = $rows->values()->all();
        }

        return $employee;
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function changedKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            $keys[] = isset(self::COLLECTIONS[$key]) ? $key.':'.count((array) $value) : $key;
        }

        return $keys;
    }

    // ---------------------------------------------------------------- reading

    /** Pending changes the HR head may decide (none of their own); null for anyone else. For «مهامي». */
    public function reviewQueue(User $user): ?Builder
    {
        return self::available() && $this->access->canReviewChanges($user)
            ? DB::table('employee_file_changes as c')->where('c.status', 'pending')->where('c.prepared_by', '<>', $user->id) : null;
    }

    /** The preparer's changes returned to them for completion; null for anyone who does not prepare. */
    public function returnedQueue(User $user): ?Builder
    {
        return self::available() && $this->access->canPrepare($user)
            ? DB::table('employee_file_changes as c')->where('c.status', 'returned')->where('c.prepared_by', $user->id) : null;
    }

    public function canList(User $user): bool
    {
        return self::available() && $this->access->canOpenFiles($user);
    }

    /**
     * The changes the viewer may see: all of them for HR management (the head decides, the administrator reads),
     * the viewer's own for HR staff. «open» = waiting or returned; «all» = the latest 100.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(User $viewer, string $scope): array
    {
        if (! $this->canList($viewer)) {
            return [];
        }

        return DB::table('employee_file_changes as c')
            ->leftJoin('employees as e', 'e.id', '=', 'c.employee_id')
            ->join('users as p', 'p.id', '=', 'c.prepared_by')->leftJoin('users as r', 'r.id', '=', 'c.reviewed_by')
            ->when(! $this->access->canManage($viewer), fn ($query) => $query->where('c.prepared_by', $viewer->id))
            ->when($scope === 'open', fn ($query) => $query->whereIn('c.status', self::OPEN))
            ->orderByRaw("CASE c.status WHEN 'pending' THEN 0 WHEN 'returned' THEN 1 ELSE 2 END")->orderByDesc('c.submitted_at')->limit(100)
            ->get(['c.id', 'c.kind', 'c.status', 'c.employee_id', 'c.employee_number', 'c.payload', 'c.submitted_at', 'c.reviewed_at', 'c.review_note',
                'p.name as prepared_by_name', 'r.name as reviewed_by_name', 'e.full_name_ar', 'e.first_name_ar', 'e.father_name_ar', 'e.grandfather_name_ar', 'e.family_name_ar'])
            ->map(function (object $row): array {
                $payload = json_decode((string) $row->payload, true) ?: [];

                return [
                    'id' => (int) $row->id, 'kind' => $row->kind, 'kindLabel' => $row->kind === 'create' ? 'ملف جديد' : 'تعديل ملف',
                    'status' => $row->status, 'statusLabel' => self::STATUS[$row->status][0], 'tone' => self::STATUS[$row->status][1],
                    'employee' => $row->kind === 'create' ? EmployeeNames::display((object) $payload) : EmployeeNames::display($row),
                    'employeeNumber' => $row->employee_number, 'employeeId' => $row->employee_id ? (int) $row->employee_id : null,
                    'items' => count($row->kind === 'create' ? [1] : $payload), 'preparedBy' => $row->prepared_by_name, 'submittedAt' => $row->submitted_at,
                    'reviewedBy' => $row->reviewed_by_name, 'reviewedAt' => $row->reviewed_at, 'note' => $row->review_note,
                ];
            })->all();
    }

    /**
     * One change as the viewer may see it: what it adds or changes, with the current values, and what the viewer may do.
     *
     * @return array<string, mixed>
     */
    public function detail(User $viewer, int $id): array
    {
        $change = self::available() ? DB::table('employee_file_changes')->where('id', $id)->first() : null;
        abort_unless($change !== null, 404);
        $own = (int) $change->prepared_by === (int) $viewer->id;
        abort_unless($this->access->canManage($viewer) || ($own && $this->access->canPrepare($viewer)), 403, 'لا تملك الاطلاع على هذا التعديل.');
        $payload = json_decode((string) $change->payload, true) ?: [];
        $before = json_decode((string) ($change->before ?? ''), true) ?: [];
        $employee = $change->employee_id ? Employee::query()->find($change->employee_id) : null;
        $open = in_array($change->status, self::OPEN, true);
        $decide = $change->status === 'pending' && ! $own && $this->access->canReviewChanges($viewer);

        $fields = [];
        foreach (Arr::except($payload, [...array_keys(self::COLLECTIONS), 'leave_opening_balances']) as $key => $value) {
            if ($change->kind === 'create' && (self::normalize($value) === null || $value === false)) {
                continue;
            }
            $fields[] = ['label' => self::LABELS[$key] ?? $key, 'after' => $this->display($key, $value),
                'before' => $change->kind === 'update' ? $this->display($key, $before['basic'][$key] ?? null) : null];
        }
        $rows = [];
        foreach ([...array_keys(self::COLLECTIONS), 'leave_opening_balances'] as $key) {
            foreach ($payload[$key] ?? [] as $row) {
                $old = ! empty($row['id']) ? ($before['rows'][$key][(string) $row['id']] ?? null) : null;
                $rows[] = $old === null
                    ? ['label' => self::ROW_LABELS[$key].' جديد', 'kind' => 'new', 'summary' => $this->rowSummary($row), 'changes' => []]
                    : ['label' => 'تعديل '.self::ROW_LABELS[$key], 'kind' => 'changed', 'summary' => $this->rowSummary(array_merge($old, $row)),
                        'changes' => $this->rowChanges($row, $old)];
            }
        }

        return [
            'id' => (int) $change->id, 'kind' => $change->kind, 'kindLabel' => $change->kind === 'create' ? 'ملف موظف جديد' : 'تعديل ملف موظف',
            'status' => $change->status, 'statusLabel' => self::STATUS[$change->status][0], 'tone' => self::STATUS[$change->status][1],
            'revision' => (int) $change->revision, 'employee' => $employee ? EmployeeNames::display($employee) : EmployeeNames::display((object) $payload),
            'employeeNumber' => $change->employee_number, 'employeeId' => $change->employee_id ? (int) $change->employee_id : null,
            'preparedBy' => $this->userName((int) $change->prepared_by), 'submittedAt' => $change->submitted_at,
            'reviewedBy' => $change->reviewed_by ? $this->userName((int) $change->reviewed_by) : null, 'reviewedAt' => $change->reviewed_at, 'note' => $change->review_note,
            'fields' => $fields, 'rows' => $rows,
            'linksAccount' => $change->kind === 'create' && (bool) ($payload['link_existing_account'] ?? false),
            'can' => [
                'approve' => $decide, 'return' => $decide,
                'withdraw' => $own && $open,
                'edit' => $own && $open && $this->access->canPrepare($viewer),
            ],
            'editUrl' => $change->kind === 'create'
                ? route('admin.employee-file-changes.edit', ['change' => $change->id], false)
                : ($change->employee_id ? route('admin.employee-profiles.edit', ['employee' => $change->employee_id], false) : null),
        ];
    }

    /** The open change of a file as HR management and HR staff see it on the file, or null. */
    public function banner(User $viewer, int $employeeId): ?array
    {
        $open = $this->access->canOpenFiles($viewer) ? $this->openFor($employeeId) : null;
        if ($open === null) {
            return null;
        }
        $own = (int) $open->prepared_by === (int) $viewer->id;

        return ['id' => (int) $open->id, 'status' => $open->status, 'statusLabel' => self::STATUS[$open->status][0], 'tone' => self::STATUS[$open->status][1],
            'preparedBy' => $this->userName((int) $open->prepared_by), 'own' => $own, 'note' => $own ? $open->review_note : null,
            'url' => $own || $this->access->canManage($viewer) ? self::url((int) $open->id) : null];
    }

    public function pendingCount(): int
    {
        return self::available() ? DB::table('employee_file_changes')->where('status', 'pending')->count() : 0;
    }

    private function display(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($key, self::BOOLEAN_FIELDS, true)) {
            return in_array($value, [true, 1, '1', 'true'], true) ? 'نعم' : 'لا';
        }

        $name = match (true) {
            $key === 'department_id' => DB::table('departments')->where('id', (int) $value)->value('name'),
            $key === 'job_category_id' => DB::table('job_categories')->where('id', (int) $value)->value('name_ar'),
            $key === 'job_title_id' => DB::table('job_titles')->where('id', (int) $value)->value('name_ar'),
            $key === 'job_grade_id' => DB::table('job_grades')->where('id', (int) $value)->value('name_ar'),
            str_ends_with($key, '_value_id') => DB::table('master_data_values')->where('id', (int) $value)->value('name_ar'),
            $key === 'relationship_code' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'kinship_types')->where('v.code', (string) $value)->value('v.name_ar'),
            $key === 'gender' => ['male' => 'ذكر', 'female' => 'أنثى'][$value] ?? null,
            $key === 'employment_status' => self::EMPLOYMENT_STATUS[$value] ?? null,
            $key === 'address_type' => ['original' => 'الأصلي', 'current' => 'الحالي', 'previous' => 'سابق'][$value] ?? null,
            default => null,
        };

        return (string) ($name ?? self::normalize($value) ?? '—');
    }

    /** @param array<string, mixed> $row */
    private function rowSummary(array $row): string
    {
        $parts = [];
        foreach (Arr::except($row, self::HIDDEN_ROW_FIELDS) as $field => $value) {
            if (self::normalize($value) === null || (in_array($field, self::BOOLEAN_FIELDS, true) && ! in_array($value, [true, 1, '1'], true))) {
                continue;
            }
            $parts[] = (self::LABELS[$field] ?? $field).': '.$this->display($field, $value);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, ?string>  $old
     * @return list<array{label: string, before: string, after: string}>
     */
    private function rowChanges(array $row, array $old): array
    {
        $changes = [];
        foreach ($old as $field => $value) {
            if (in_array($field, self::HIDDEN_ROW_FIELDS, true) || ! array_key_exists($field, $row) || self::normalize($row[$field]) === $value) {
                continue;
            }
            $changes[] = ['label' => self::LABELS[$field] ?? $field, 'before' => $this->display($field, $value), 'after' => $this->display($field, $row[$field])];
        }

        return $changes;
    }

    // ---------------------------------------------------------------- notices

    private function assertCanSubmit(User $actor): void
    {
        abort_unless($this->access->canPrepare($actor), 403, 'إعداد الملفات للاعتماد لموظفي الموارد البشرية.');
        abort_unless(self::available(), 503, 'مراجعة ملفات الموظفين غير مفعلة بعد (لم يُشغَّل ترحيل قاعدة البيانات).');
    }

    /** Tells the appointed HR head that a change waits; never the person who prepared it. */
    private function notifyReviewer(User $actor, int $changeId, int $revision, string $title): void
    {
        $headId = DB::table('departments')->where('code', HrServicesDashboard::DEPARTMENT_CODE)->where('is_active', true)->whereNull('deleted_at')->value('head_user_id');
        $head = $headId && (int) $headId !== (int) $actor->id ? User::query()->find($headId) : null;
        if ($head && $this->access->canReviewChanges($head)) {
            $this->notifications->notify((int) $head->id, self::EVENT_SUBMITTED, [
                'title' => $title, 'body' => 'أعده '.$actor->name.'. راجعه ثم اعتمده أو أعده للاستكمال.', 'url' => self::url($changeId),
                'dedupe' => 'emp-file-change:'.$changeId.':'.$revision,
            ]);
        }
    }

    private function notifyPreparer(User $actor, object $change, bool $approved, ?string $note): void
    {
        if ((int) $change->prepared_by === (int) $actor->id) {
            return;
        }
        $what = $change->kind === 'create' ? 'الملف الجديد' : 'تعديل الملف';
        $this->notifications->notify((int) $change->prepared_by, self::EVENT_DECIDED, [
            'title' => $approved ? "اعتُمد {$what} الذي أعددته (الرقم الوظيفي {$change->employee_number})" : "أُعيد إليك {$what} للاستكمال (الرقم الوظيفي {$change->employee_number})",
            'body' => $approved ? null : 'الملاحظة: '.$note, 'url' => self::url((int) $change->id),
            'dedupe' => 'emp-file-change:'.$change->id.':'.($approved ? 'approved' : 'returned').':'.$change->revision,
        ]);
    }

    private function userName(int $id): string
    {
        return (string) (DB::table('users')->where('id', $id)->value('name') ?? '—');
    }
}
