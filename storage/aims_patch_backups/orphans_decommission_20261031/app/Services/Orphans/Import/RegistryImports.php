<?php

namespace App\Services\Orphans\Import;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use App\Support\Spreadsheets\StreamingXlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Batches of «استيراد الكشوف»: private upload → analysis (dry run) → approval by a second person → staging in
 * resumable chunks. Staging writes only the orphan_import_* tables: no approved orphan, parent, household,
 * guardianship, portal account, sponsorship, score or payment is ever created or changed here.
 */
final class RegistryImports
{
    public const DISK = 'local';

    public const DIRECTORY = 'orphan_imports';

    public const CHUNK = 500;

    public const MAX_UPLOAD_KB = 51200;

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    /** Private upload. The same file (same SHA-256) is never a second batch. */
    public function upload(User $user, UploadedFile $file, ?int $supersedesId = null): object
    {
        $this->access->assert($user, 'imports');
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = DB::table('orphan_import_batches')->where('file_sha256', $hash)->first();
        if ($existing) {
            throw ValidationException::withMessages(['file' => 'هذا الملف رُفع سابقًا في الدفعة '.$existing->public_id.'؛ لا تُنشأ دفعة مكررة.']);
        }
        if ($supersedesId !== null && ! DB::table('orphan_import_batches')->where('id', $supersedesId)->exists()) {
            throw ValidationException::withMessages(['supersedes_id' => 'الدفعة السابقة غير موجودة.']);
        }
        $publicId = (string) Str::uuid();
        $path = $file->storeAs(self::DIRECTORY, $publicId.'.xlsx', self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'تعذر حفظ الملف في التخزين الخاص.']);
        }
        try {
            (new StreamingXlsxReader(Storage::disk(self::DISK)->path($path), RegistrySheet::SHEET_NAME))->open();
        } catch (RuntimeException $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['file' => match ($exception->getMessage()) {
                'sheet_missing' => 'الملف لا يحتوي ورقة باسم «'.RegistrySheet::SHEET_NAME.'». لا تُقرأ أي ورقة أخرى.',
                'xlsx_encrypted' => 'الملف محمي بكلمة مرور.',
                'xlsx_too_large' => 'محتوى الملف أكبر من الحد المسموح.',
                default => 'تعذر قراءة الملف بصيغة XLSX.',
            }]);
        }
        $id = DB::table('orphan_import_batches')->insertGetId([
            'public_id' => $publicId, 'status' => 'uploaded', 'source_sheet' => RegistrySheet::SHEET_NAME,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'file_sha256' => $hash, 'file_size' => $file->getSize(),
            'storage_path' => $path, 'supersedes_id' => $supersedesId, 'uploaded_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->recordRow('orphans.import.uploaded', 'orphan_import_batches', $id, null, ['sha256' => $hash, 'size' => $file->getSize(), 'supersedes_id' => $supersedesId], (int) $user->id, request()?->ip());

        return DB::table('orphan_import_batches')->find($id);
    }

    /** Dry run: header map, blockers and counts (no names, identities or addresses). Runs in the background command. */
    public function analyze(object $batch): void
    {
        DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'analyzing', 'heartbeat_at' => now(), 'failure' => null, 'updated_at' => now()]);
        try {
            $analyzer = $this->analyzer($batch);
            $headers = $analyzer->headers();
            $blockers = $analyzer->headerBlockers();
            $profile = $blockers === [] ? $analyzer->profile() : null;
            DB::table('orphan_import_batches')->where('id', $batch->id)->update([
                'status' => $blockers === [] ? 'analyzed' : 'blocked',
                'header_map' => json_encode(['map' => array_map(fn (int $i): string => StreamingXlsxReader::columnLetter($i), $headers['map']),
                    'unknown' => $headers['unknown'], 'moved' => $headers['moved'], 'header_row' => $headers['header_row'], 'headers' => $headers['headers']], JSON_UNESCAPED_UNICODE),
                'blockers' => json_encode($blockers, JSON_UNESCAPED_UNICODE), 'profile' => $profile ? json_encode($profile, JSON_UNESCAPED_UNICODE) : null,
                'data_rows' => $profile['rows'] ?? null, 'analyzed_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->recordRow('orphans.import.analyzed', 'orphan_import_batches', $batch->id, null, ['rows' => $profile['rows'] ?? null, 'blockers' => count($blockers)], null);
        } catch (Throwable $exception) {
            $this->fail($batch, $exception);
        }
    }

    /** Approval of the staging import, by a different person than the uploader (also enforced by a DB CHECK). */
    public function approve(User $user, string $publicId, string $note): void
    {
        $this->access->assert($user, 'imports');
        DB::transaction(function () use ($user, $publicId, $note): void {
            $batch = DB::table('orphan_import_batches')->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            if ($batch->status !== 'analyzed') {
                throw ValidationException::withMessages(['batch' => 'الاعتماد متاح بعد اكتمال التحليل وبدون موانع.']);
            }
            if ((int) $batch->uploaded_by === (int) $user->id) {
                throw ValidationException::withMessages(['batch' => 'يعتمد الاستيراد شخص غير من رفع الملف.']);
            }
            DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'approval_note' => $note, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.approved', 'orphan_import_batches', $batch->id, ['status' => $batch->status], ['status' => 'approved', 'note' => $note], (int) $user->id, request()?->ip());
        });
    }

    /** A failed run resumes from its cursor: back to «approved» if it was approved, otherwise to analysis. */
    public function retry(User $user, string $publicId): void
    {
        $this->access->assert($user, 'imports');
        $batch = DB::table('orphan_import_batches')->where('public_id', $publicId)->first();
        abort_unless($batch && $batch->status === 'failed', 422, 'إعادة المحاولة متاحة للدفعات المتعثرة فقط.');
        DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => $batch->approved_at ? 'approved' : 'uploaded', 'failure' => null, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.import.retried', 'orphan_import_batches', $batch->id, ['status' => 'failed'], ['status' => $batch->approved_at ? 'approved' : 'uploaded'], (int) $user->id, request()?->ip());
    }

    public function cancel(User $user, string $publicId, string $reason): void
    {
        $this->access->assert($user, 'imports');
        DB::transaction(function () use ($user, $publicId, $reason): void {
            $batch = DB::table('orphan_import_batches')->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            if (! in_array($batch->status, ['uploaded', 'analyzed', 'blocked', 'approved', 'failed'], true)) {
                throw ValidationException::withMessages(['batch' => 'لا يمكن إلغاء دفعة قيد المعالجة أو مكتملة؛ تُراجع صفوفها بدلًا من ذلك.']);
            }
            DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.cancelled', 'orphan_import_batches', $batch->id, ['status' => $batch->status], ['status' => 'cancelled', 'reason' => $reason], (int) $user->id, request()?->ip());
        });
    }

    /**
     * Stages the approved batch in chunks. Resumable: rows already staged (cursor) are skipped, inserts ignore
     * existing (batch, row) and (row, type, reason) keys, so a retry never duplicates anything.
     */
    public function stage(object $batch, ?callable $progress = null): void
    {
        DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'staging', 'started_at' => $batch->started_at ?? now(), 'heartbeat_at' => now(), 'failure' => null, 'updated_at' => now()]);
        try {
            $existing = DB::table('orphan_children')->whereNotNull('national_id_hash')->pluck('national_id_hash')->mapWithKeys(fn (string $hash): array => [$hash => true])->all();
            $analyzer = $this->analyzer($batch, $existing);
            $analyzer->aggregate();
            $cursor = (int) DB::table('orphan_import_batches')->where('id', $batch->id)->value('cursor_row');
            $chunk = [];
            foreach ($analyzer->rows() as $number => $row) {
                if ($number <= $cursor) {
                    continue;
                }
                $chunk[] = $row;
                if (count($chunk) >= self::CHUNK) {
                    $this->writeChunk($batch, $chunk);
                    $progress && $progress(end($chunk)['row']);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->writeChunk($batch, $chunk);
            }
            $staged = DB::table('orphan_import_rows')->where('batch_id', $batch->id)->count();
            $issues = DB::table('orphan_import_issues')->where('batch_id', $batch->id)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
            DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'staged', 'staged_rows' => $staged, 'completed_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.staged', 'orphan_import_batches', $batch->id, null, [
                'rows' => $staged, 'issues' => $issues, 'approved_records_created' => 0, 'guardianships_created' => 0, 'portal_accounts_created' => 0,
            ], null);
        } catch (Throwable $exception) {
            $this->fail($batch, $exception);
        }
    }

    /** @param list<array<string, mixed>> $chunk */
    private function writeChunk(object $batch, array $chunk): void
    {
        DB::transaction(function () use ($batch, $chunk): void {
            $now = now();
            $rows = [];
            foreach ($chunk as $row) {
                $v = $row['values'];
                $worst = null;
                foreach ($row['issues'] as $issue) {
                    $worst = $this->worse($worst, $issue['severity']);
                }
                $rows[] = [
                    'batch_id' => $batch->id, 'source_row_number' => $row['row'], 'fingerprint' => $row['fingerprint'],
                    'raw_payload' => Crypt::encryptString(json_encode($row['raw'], JSON_UNESCAPED_UNICODE)),
                    'parse_flags' => $row['flags'] === [] ? null : json_encode($row['flags']),
                    'registered_at' => $v['registered_at'], 'child_name' => $v['child_name'], 'child_name_search' => $v['child_name_search'] ?: null,
                    'child_national_id' => $v['child_national_id'], 'child_id_status' => $v['child_national_id_status'], 'child_sex' => $v['child_sex'], 'child_dob' => $v['child_dob'],
                    'father_name' => $v['father_name'], 'father_name_search' => $v['father_name_search'] ?: null, 'father_national_id' => $v['father_national_id'],
                    'father_id_status' => $v['father_national_id_status'], 'father_death_date' => $v['father_death_date'],
                    'mother_name' => $v['mother_name'], 'mother_name_search' => $v['mother_name_search'] ?: null, 'mother_national_id' => $v['mother_national_id'],
                    'mother_id_status' => $v['mother_national_id_status'], 'mother_status_key' => $v['mother_status_key'],
                    'guardian_name' => $v['guardian_name'], 'guardian_name_search' => $v['guardian_name_search'] ?: null, 'guardian_national_id' => $v['guardian_national_id'],
                    'guardian_id_status' => $v['guardian_national_id_status'], 'guardian_relation_key' => $v['guardian_relation_key'], 'guardian_claims_mother' => $v['guardian_claims_mother'],
                    'governorate_key' => $v['governorate_key'], 'city_key' => $v['city_key'], 'is_displaced' => $v['is_displaced'],
                    'school_grade_key' => $v['school_grade_key'], 'academic_performance_key' => $v['academic_performance_key'],
                    'health_status_key' => $v['health_status_key'], 'mother_education_key' => $v['mother_education_key'],
                    'family_total' => $v['family_total'], 'family_males' => $v['family_males'], 'family_females' => $v['family_females'],
                    'child_key' => $v['child_key'], 'household_key' => $v['household_key'], 'guardian_key' => $v['guardian_key'],
                    'issue_count' => count($row['issues']), 'max_severity' => $worst, 'review_status' => 'pending_review',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('orphan_import_rows')->insertOrIgnore($rows);
            $ids = DB::table('orphan_import_rows')->where('batch_id', $batch->id)->whereIn('source_row_number', array_column($chunk, 'row'))->pluck('id', 'source_row_number');
            $issues = [];
            foreach ($chunk as $row) {
                foreach ($row['issues'] as $issue) {
                    $issues[] = ['batch_id' => $batch->id, 'row_id' => $ids[$row['row']], 'type' => $issue['type'], 'reason' => (string) ($issue['details']['reason'] ?? ''),
                        'severity' => $issue['severity'], 'details' => $issue['details'] === [] ? null : json_encode($issue['details']), 'status' => 'open',
                        'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($issues, 1000) as $part) {
                DB::table('orphan_import_issues')->insertOrIgnore($part);
            }
            $last = end($chunk)['row'];
            DB::table('orphan_import_batches')->where('id', $batch->id)->update([
                'cursor_row' => $last, 'staged_rows' => DB::raw('(SELECT count(*) FROM orphan_import_rows WHERE batch_id = '.(int) $batch->id.')'),
                'heartbeat_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    /** The next batch for the background command: analysis of uploads, staging of approvals, resumption of stale runs. */
    public function nextJob(): ?array
    {
        $stale = now()->subMinutes(15);
        foreach ([['analyze', 'uploaded', 'analyzing'], ['stage', 'approved', 'staging']] as [$step, $waiting, $running]) {
            $batch = DB::table('orphan_import_batches')->where(fn ($q) => $q->where('status', $waiting)->orWhere(fn ($q) => $q->where('status', $running)->where('heartbeat_at', '<', $stale)))->orderBy('id')->first();
            if ($batch && $this->claim($batch, $running)) {
                return [$step, $batch];
            }
        }

        return null;
    }

    /** Atomically takes a batch for one worker (the scheduler and a manual run never process it twice at once). */
    public function claim(object $batch, string $running): bool
    {
        return DB::table('orphan_import_batches')->where('id', $batch->id)->where('status', $batch->status)
            ->where(fn ($q) => $q->whereNull('heartbeat_at')->orWhere('heartbeat_at', $batch->heartbeat_at))
            ->update(['status' => $running, 'heartbeat_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * Privileged rollback of one batch's staged rows (system administrator, reason required, audited). Touches only
     * orphan_import_rows/issues of that batch; approved registry data is never deleted here.
     */
    public function purge(User $admin, string $publicId, string $reason): array
    {
        abort_unless($admin->isSystemAdmin(), 403);
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'سبب الحذف مطلوب (10 أحرف على الأقل).']);
        }

        return DB::transaction(function () use ($admin, $publicId, $reason): array {
            $batch = DB::table('orphan_import_batches')->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($batch, 404);
            if (in_array($batch->status, ['staging', 'analyzing'], true)) {
                throw ValidationException::withMessages(['batch' => 'الدفعة قيد المعالجة.']);
            }
            $decided = DB::table('orphan_import_rows')->where('batch_id', $batch->id)->where(fn ($q) => $q->whereNotNull('identity_decision')->orWhereNotNull('household_decision')->orWhereNotNull('guardianship_decision'))->count();
            if ($decided > 0) {
                throw ValidationException::withMessages(['batch' => 'في الدفعة قرارات مراجعة مسجلة ('.$decided.')؛ لا تُحذف.']);
            }
            $issues = DB::table('orphan_import_issues')->where('batch_id', $batch->id)->delete();
            $rows = DB::table('orphan_import_rows')->where('batch_id', $batch->id)->delete();
            DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'cancelled', 'staged_rows' => 0, 'cursor_row' => 0, 'cancelled_by' => $admin->id,
                'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.purged', 'orphan_import_batches', $batch->id, ['status' => $batch->status, 'rows' => $rows, 'issues' => $issues], ['status' => 'cancelled', 'reason' => $reason], (int) $admin->id);

            return ['rows' => $rows, 'issues' => $issues];
        });
    }

    /** Decrypted source values of one staged row (callers check the «sensitive» capability first). */
    public function raw(object $row): array
    {
        return json_decode(Crypt::decryptString($row->raw_payload), true) ?: [];
    }

    private function analyzer(object $batch, array $existing = []): RegistryAnalyzer
    {
        $path = Storage::disk(self::DISK)->path($batch->storage_path);
        if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_sha256) {
            throw new RuntimeException('source_file_changed_or_missing');
        }

        return new RegistryAnalyzer($path, $existing);
    }

    private function fail(object $batch, Throwable $exception): void
    {
        report($exception);
        DB::table('orphan_import_batches')->where('id', $batch->id)->update(['status' => 'failed', 'failure' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
    }

    private function worse(?string $current, string $candidate): string
    {
        $order = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

        return $current === null || $order[$candidate] > $order[$current] ? $candidate : $current;
    }
}
