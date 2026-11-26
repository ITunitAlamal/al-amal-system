<?php

namespace App\Services\Orphans\Import;

use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\OrphanGuardianship;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\GazaGeography;
use App\Services\Orphans\ArabicText;
use App\Services\Orphans\Campaigns\Campaigns;
use App\Services\Orphans\Identity;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\Persons;
use App\Services\Orphans\Registry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * «الاستيراد الذكي»: upload → mapping → smart check (staging) → review and corrections → «اعتماد مبدئي وإدراج في
 * السجل» → execution. Nothing reaches the registry before that action. Execution writes real registry records with the
 * status «معتمد مبدئيًا» (orphans.status = provisional), one family per transaction, idempotent per row and per
 * entity key. Guardianships are claims, never approvals; names and identity numbers are kept as written.
 *
 * Concurrency: one worker per batch (PostgreSQL advisory lock). Staging a row and advancing the cursor commit together,
 * and a row already staged is never inserted again (same content skipped, changed content reported).
 */
final class ImportPipeline
{
    public const CATEGORIES = ['ready' => 'جاهز للاعتماد المبدئي', 'needs_review' => 'يحتاج مراجعة', 'conflict' => 'تعارض', 'duplicate' => 'مكرر', 'rejected' => 'مرفوض'];

    public const STATUS = ['uploaded' => 'مرفوع', 'mapped' => 'تمت المطابقة', 'analyzing' => 'قيد الفحص الذكي', 'reviewing' => 'قيد المراجعة', 'approved' => 'معتمد للتنفيذ',
        'executing' => 'قيد الإدراج في السجل', 'partially_imported' => 'أُدرج جزئيًا (معتمد مبدئيًا)', 'imported' => 'أُدرج في السجل (معتمد مبدئيًا)',
        'completed' => 'اكتمل', 'cancelled' => 'ملغى', 'failed' => 'فشل'];

    public const OUTCOMES = ['created' => 'أُنشئ', 'linked' => 'رُبط بسجل قائم', 'updated' => 'استُكمل سجل قائم', 'skipped' => 'تُخطي', 'held_for_review' => 'معلّق للمراجعة',
        'failed' => 'فشل', 'proposed' => 'مقترح'];

    /** Batch states in which rows may still be reviewed, corrected and committed. */
    public const REVIEWABLE = ['reviewing', 'partially_imported'];

    /** Largest import file; the controller rule and php.ini (upload_max_filesize 64M, post_max_size 80M) match it. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    private const INLINE_ROWS = 3000;

    /** Inline (web request) work: files up to 2 MB, at most 10 seconds. */
    private const INLINE_MAX_BYTES = 2 * 1024 * 1024;

    private const INLINE_SECONDS = 10;

    /** Rows staged per transaction (row content and cursor commit together). */
    private const STAGE_CHUNK = 200;

    /** Advisory-lock namespace of import batches. */
    /** import_row_issues.resolution (varchar 15, default «open»): an open finding counts; any other value is resolved. */
    public const OPEN = 'open';

    public const INFERRED = 'sibling';

    private const LOCK_SPACE = 42017;

    /** Findings recomputed by the batch-level family pass. */
    private const FAMILY_CODES = ['parent_name_differs', 'guardian_inferred', 'guardian_inference_proposed', 'sibling_guardian_ambiguous'];

    private array $geo = [];

    public function __construct(private readonly OrphanAccess $access, private readonly Registry $registry, private readonly Persons $persons, private readonly AuditService $audit) {}

    /* ---------- 1. upload ---------- */

    /** Upload problems are field errors shown on the form, not an error page. */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function upload(UploadedFile $file, string $purpose, ?int $campaignId, ?string $encoding, User $actor): object
    {
        $this->access->assert($actor, 'import');
        isset(ImportFields::PURPOSES[$purpose]) || $this->refuse('purpose', 'اختر غرض الاستيراد.');
        $file->getSize() <= self::MAX_BYTES || $this->refuse('file', 'حجم الملف '.round($file->getSize() / 1048576, 1).' ميغابايت، والحد 64 ميغابايت. قسّم الملف إلى أجزاء.');
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $format = match (true) {
            $extension === 'xlsx' && in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true) => 'xlsx',
            $extension === 'csv' && (str_starts_with($mime, 'text/') || $mime === 'application/csv' || $mime === 'application/vnd.ms-excel') => 'csv',
            default => $this->refuse('file', 'الصيغ المدعومة: XLSX وCSV فقط (يُتحقق من محتوى الملف لا من اسمه).'),
        };
        if ($format === 'xlsx') {
            $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4);
            $head === "PK\x03\x04" || $this->refuse('file', 'محتوى الملف ليس ملف Excel صالحًا.');
        }
        if ($purpose === 'campaign_candidates' || $purpose === 'delivery_reconciliation') {
            ($campaignId && DB::table('campaigns')->where('id', $campaignId)->exists()) || $this->refuse('campaign_id', 'اختر الحملة المستهدفة.');
        }
        $path = $file->store('imports/'.now()->format('Y/m'), 'local');
        $sha = hash_file('sha256', $file->getRealPath());
        $id = DB::table('import_batches')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('IMP', 'import_batch_seq'), 'purpose' => $purpose, 'status' => 'uploaded',
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'path' => $path, 'mime' => $mime, 'size' => $file->getSize(), 'sha256' => $sha, 'format' => $format,
            'encoding' => $format === 'csv' ? ($encoding ?: 'UTF-8') : null, 'campaign_id' => $campaignId, 'uploaded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.import.uploaded', 'import_batches', $id, null, ['purpose' => $purpose, 'sha256' => $sha, 'size' => $file->getSize()], $actor->id, request()?->ip());

        return DB::table('import_batches')->find($id);
    }

    public function sheets(object $batch): array
    {
        return $batch->format === 'xlsx' ? SourceFile::sheets($this->file($batch)) : [];
    }

    /** Header row + first sample rows of the chosen sheet; nothing is stored except the header labels. */
    public function preview(object $batch, ?string $sheet, int $headerRow = 1): array
    {
        $headers = [];
        $samples = [];
        foreach (SourceFile::rows($this->file($batch), $batch->format, $sheet, $batch->encoding) as $number => $cells) {
            if ($number < $headerRow) {
                continue;
            }
            if ($number === $headerRow) {
                $headers = $cells;

                continue;
            }
            $samples[] = $cells;
            if (count($samples) >= 8) {
                break;
            }
        }

        return ['headers' => $headers, 'samples' => $samples];
    }

    /* ---------- 2. mapping ---------- */

    public function saveMapping(object $batch, ?string $sheet, int $headerRow, array $mapping, ?string $profileName, User $actor): void
    {
        $this->assertOwnerOrHead($batch, $actor);
        abort_unless(in_array($batch->status, ['uploaded', 'mapped', 'reviewing'], true), 409, 'لا تُعدّل المطابقة بعد الاعتماد.');
        if ($batch->format === 'xlsx') {
            abort_unless(in_array($sheet, $this->sheets($batch), true), 422, 'اختر ورقة العمل.');
        }
        $fields = ImportFields::for($batch->purpose);
        $mapping = array_filter($mapping, fn ($field) => isset($fields[$field]));
        abort_if(count($mapping) !== count(array_unique($mapping)), 422, 'حقل الوجهة نفسه مرتبط بأكثر من عمود؛ اختر عمودًا واحدًا لكل حقل.');
        foreach ($mapping as $field) {
            if ($fields[$field][2] === 'health') {
                abort_unless($this->access->allows($actor, 'health'), 403, 'ربط الأعمدة الصحية يحتاج قدرة «عرض التفاصيل الصحية السريرية».');
            }
        }
        if ($batch->purpose === 'registry') {
            abort_unless(array_intersect($mapping, ['child_full_name', 'child_first_name']) !== [], 422, 'اربط عمود اسم اليتيم.');
        }
        $preview = $this->preview($batch, $sheet, $headerRow);
        DB::table('import_batches')->where('id', $batch->id)->update(['sheet' => $sheet, 'header_row' => $headerRow, 'headers' => json_encode($preview['headers'], JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode($mapping), 'status' => 'mapped', 'updated_at' => now()]);
        if ($profileName) {
            DB::table('import_mapping_profiles')->insert(['name' => $profileName, 'purpose' => $batch->purpose,
                'mapping' => json_encode(collect($mapping)->mapWithKeys(fn ($field, $index) => [ImportFields::headerKey((string) ($preview['headers'][$index] ?? '')) => $field])->all(), JSON_UNESCAPED_UNICODE),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /* ---------- 3. smart check ---------- */

    /**
     * Starts (or restarts) the analysis; small files finish inline, large ones continue in the scheduled worker.
     * A restart erases staged rows, so it is refused once people reviewed rows or anything was committed, unless the
     * head of department asks for an explicit reset with a reason (audited). Committed batches are never re-analysed.
     */
    public function startAnalysis(object $batch, User $actor, bool $reset = false, ?string $reason = null): void
    {
        $this->assertOwnerOrHead($batch, $actor);
        abort_unless(in_array($batch->status, ['mapped', 'reviewing'], true), 409, 'احفظ مطابقة الأعمدة أولًا.');
        abort_if(DB::table('import_execution_events')->where('batch_id', $batch->id)->exists(), 409, 'أُدرج جزء من هذه الدفعة في السجل؛ لا يُعاد فحصها. ارفع الملف في دفعة جديدة.');
        $staged = DB::table('import_staged_rows')->where('batch_id', $batch->id)->count();
        $manual = DB::table('import_staged_rows')->where('batch_id', $batch->id)->where(fn ($q) => $q->whereNotNull('decision')->orWhere('corrected', true))->count();
        if ($manual > 0) {
            abort_unless($reset && $this->access->canAssign($actor) && filled($reason), 409,
                "في الدفعة {$manual} صفًا بقرارات أو تصحيحات يدوية؛ إعادة الفحص تمحوها. تحتاج «إعادة ضبط» صريحة من رئيس القسم مع ذكر السبب.");
        }
        DB::transaction(function () use ($batch): void {
            DB::table('import_row_issues')->where('batch_id', $batch->id)->delete();
            DB::table('import_staged_rows')->where('batch_id', $batch->id)->delete();
            DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'analyzing', 'cursor' => 0, 'rows_processed' => 0, 'rows_total' => 0, 'summary' => null, 'error' => null, 'updated_at' => now()]);
        });
        if ($staged > 0) {
            $this->audit->recordRow('orphans.import.analysis_reset', 'import_batches', $batch->id, ['staged' => $staged, 'manual' => $manual], ['reason' => $reason], $actor->id, request()?->ip());
        }
        $this->inline($batch->id);
    }

    /**
     * Continues a failed analysis after checking what was persisted: the cursor goes back to the first missing row and
     * staging continues idempotently (rows already staged are skipped, never inserted twice).
     */
    public function resume(object $batch, User $actor): array
    {
        $this->assertOwnerOrHead($batch, $actor);
        abort_unless($batch->status === 'failed', 409, 'الاستئناف للدفعات المتوقفة بخطأ فقط.');
        $first = (int) $batch->header_row + 1;
        $staged = DB::table('import_staged_rows')->where('batch_id', $batch->id)->count();
        $gap = DB::table('import_staged_rows')->where('batch_id', $batch->id)->where('row_no', $first)->exists()
            ? (int) DB::selectOne('SELECT min(s.row_no) + 1 AS gap FROM import_staged_rows s WHERE s.batch_id = ? AND NOT EXISTS
                (SELECT 1 FROM import_staged_rows t WHERE t.batch_id = s.batch_id AND t.row_no = s.row_no + 1)', [$batch->id])->gap
            : $first;
        $state = ['status' => $batch->status, 'cursor' => (int) $batch->cursor, 'rows_processed' => (int) $batch->rows_processed, 'staged' => $staged, 'max_row' => DB::table('import_staged_rows')->where('batch_id', $batch->id)->max('row_no'),
            'issues' => DB::table('import_row_issues')->where('batch_id', $batch->id)->count(), 'error' => mb_substr((string) $batch->error, 0, 300)];
        DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'analyzing', 'cursor' => $gap - 1, 'rows_processed' => $staged, 'error' => null, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.import.resumed', 'import_batches', $batch->id, $state, ['cursor' => $gap - 1], $actor->id, request()?->ip());

        return $state + ['resume_from' => $gap];
    }

    /**
     * One worker step (analysis or execution) bounded by rows and seconds.
     *
     * @return bool|null true when the batch moved to its next state, false when more work remains, null when another
     *                   worker holds the batch (nothing done)
     */
    public function work(object $batch, int $maxRows = 1000, int $seconds = 40): ?bool
    {
        if (! DB::selectOne('SELECT pg_try_advisory_lock(?::int, ?::int) AS locked', [self::LOCK_SPACE, (int) $batch->id])->locked) {
            return null;
        }
        try {
            $batch = DB::table('import_batches')->find($batch->id); // fresh state, read under the lock

            return match ($batch->status) {
                'analyzing' => $this->analyzeChunk($batch, $maxRows, $seconds),
                'executing' => $this->executeChunk($batch, $maxRows, $seconds),
                default => false,
            };
        } catch (\Throwable $e) {
            DB::table('import_batches')->where('id', $batch->id)->update(['status' => $batch->status === 'executing' ? 'executing' : 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
            report($e);

            return false;
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(?::int, ?::int)', [self::LOCK_SPACE, (int) $batch->id]);
        }
    }

    private function analyzeChunk(object $batch, int $maxRows, int $seconds): bool
    {
        $started = microtime(true);
        $mapping = json_decode((string) $batch->mapping, true) ?: [];
        $done = 0;
        $buffer = [];
        $flush = function () use (&$buffer, &$done, $batch, $mapping): void {
            if ($buffer === []) {
                return;
            }
            DB::transaction(function () use ($buffer, $batch, $mapping): void {
                foreach ($buffer as $number => $cells) {
                    $this->stage($batch, $number, $cells, $this->values($cells, $mapping));
                }
                DB::table('import_batches')->where('id', $batch->id)->update(['cursor' => array_key_last($buffer), 'updated_at' => now(),
                    'rows_processed' => DB::raw('(SELECT count(*) FROM import_staged_rows WHERE batch_id = '.(int) $batch->id.')')]);
            });
            $done += count($buffer);
            $buffer = [];
        };
        foreach (SourceFile::rows($this->file($batch), $batch->format, $batch->sheet, $batch->encoding, max((int) $batch->header_row, (int) $batch->cursor)) as $number => $cells) {
            if ($number <= max($batch->header_row, (int) $batch->cursor)) {
                continue;
            }
            $buffer[$number] = $cells;
            if (count($buffer) >= self::STAGE_CHUNK) {
                $flush();
                if ($done >= $maxRows || microtime(true) - $started > $seconds) {
                    return false;
                }
            }
        }
        $flush();
        $this->finishAnalysis($batch);

        return true;
    }

    /**
     * Normalizes, validates and categorizes one row; originals are kept untouched (encrypted) in `original`.
     * A row already staged is never inserted again: identical content is skipped, different content is reported.
     */
    public function stage(object $batch, int $number, array $cells, array $values, ?int $rowId = null): void
    {
        $hash = hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE));
        if (! $rowId && ($existing = DB::table('import_staged_rows')->where('batch_id', $batch->id)->where('row_no', $number)->first(['id', 'row_hash']))) {
            if ($existing->row_hash !== $hash && ! DB::table('import_row_issues')->where('row_id', $existing->id)->where('code', 'source_changed')->exists()) {
                DB::table('import_row_issues')->insert(['row_id' => $existing->id, 'batch_id' => $batch->id, 'code' => 'source_changed', 'severity' => 'conflict', 'field' => null,
                    'message' => 'أُعيدت قراءة الصف بمحتوى يختلف عن المخزن؛ لم يُستبدل المخزن. راجع الملف المصدر.']);
                $this->recategorize([$existing->id]);
            }

            return;
        }
        [$normalized, $issues, $matches, $action, $diff, $group, $family] = $batch->purpose === 'registry' ? $this->checkRegistry($batch, $number, $values, $rowId) : $this->checkCampaign($batch, $values, $rowId);
        $row = ['batch_id' => $batch->id, 'row_no' => $number, 'row_hash' => $hash, 'normalized' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'matches' => $matches ? json_encode($matches, JSON_UNESCAPED_UNICODE) : null, 'category' => ImportPolicy::category($issues), 'proposed_action' => $action,
            'diff' => $diff ? json_encode($diff, JSON_UNESCAPED_UNICODE) : null, 'group_key' => $group, 'family_key' => $family, 'inference' => null, 'updated_at' => now()];
        if ($rowId) {
            DB::table('import_staged_rows')->where('id', $rowId)->update([...$row, 'decision' => null, 'corrected' => true]);
            DB::table('import_row_issues')->where('row_id', $rowId)->delete();
        } else {
            $rowId = DB::table('import_staged_rows')->insertGetId([...$row, 'original' => encrypt(json_encode($cells, JSON_UNESCAPED_UNICODE)), 'created_at' => now()]);
        }
        foreach ($issues as $issue) {
            DB::table('import_row_issues')->insert(['row_id' => $rowId, 'batch_id' => $batch->id, ...$issue]);
        }
    }

    private function checkRegistry(object $batch, int $number, array $v, ?int $rowId): array
    {
        $issues = [];
        $add = function (string $code, string $severity, ?string $field, string $message) use (&$issues): void {
            $issues[] = ['code' => $code, 'severity' => $severity, 'field' => $field, 'message' => $message];
        };
        $n = [];
        foreach (['child', 'guardian', 'father', 'mother'] as $who) {
            $n[$who.'_full_name'] = $v[$who.'_full_name'] ?? '';
            $n[$who.'_name_key'] = ArabicText::key($n[$who.'_full_name']);
        }
        foreach (['child_first_name', 'child_father_name', 'child_grandfather_name', 'child_family_name', 'guardian_relation', 'governorate', 'city', 'address_detail', 'displacement_address',
            'school_grade', 'academic_performance', 'father_occupation', 'mother_marital', 'mother_education', 'mother_specialization', 'health_status', 'health_note', 'father_death_cause', 'mother_death_cause', 'source_serial'] as $k) {
            $n[$k] = $v[$k] ?? '';
        }
        if ($n['child_full_name'] === '' && ($n['child_first_name'] ?? '') === '') {
            $add('missing_child_name', 'error', 'child_full_name', 'اسم اليتيم غير موجود.');
        }
        foreach (['child', 'guardian', 'father', 'mother'] as $who) {
            [$format, $clean] = Identity::classify($v[$who.'_id'] ?? '', null);
            $n[$who.'_id'] = $clean;
            $n[$who.'_id_format'] = $format;
            $label = ['child' => 'اليتيم', 'guardian' => 'الوصي', 'father' => 'الأب', 'mother' => 'الأم'][$who];
            if ($format === 'placeholder') {
                $add('placeholder_id', 'warning', $who.'_id', "رقم هوية $label وهمي ($clean)؛ يُحفظ معلَّمًا ولا يُعدّ تحققًا.");
            } elseif ($format === 'invalid_format') {
                $add('invalid_id', 'warning', $who.'_id', "رقم هوية $label بصيغة غير صحيحة (قد تكون الأصفار البادئة حُذفت في Excel).");
            }
        }
        foreach (['child_dob', 'father_dob', 'mother_dob', 'father_death_date', 'mother_death_date', 'registered_at'] as $k) {
            $n[$k] = $this->date($v[$k] ?? '');
            if (($v[$k] ?? '') !== '' && $n[$k] === null) {
                $add('invalid_date', 'warning', $k, 'تاريخ غير مفهوم: «'.mb_substr($v[$k], 0, 30).'»؛ لم يُحفظ.');
            }
        }
        if ($n['child_dob'] && $n['child_dob'] > today()->toDateString()) {
            $add('future_date', 'error', 'child_dob', 'تاريخ ميلاد اليتيم في المستقبل.');
        }
        // Impossible chronology between the child and the parents (dates as written; nothing is corrected).
        $chronology = match (true) {
            $n['child_dob'] && $n['mother_dob'] && $n['child_dob'] <= $n['mother_dob'] => 'ميلاد اليتيم يسبق ميلاد الأم أو يساويه.',
            $n['child_dob'] && $n['father_dob'] && $n['child_dob'] <= $n['father_dob'] => 'ميلاد اليتيم يسبق ميلاد الأب أو يساويه.',
            $n['child_dob'] && $n['mother_death_date'] && $n['mother_death_date'] < $n['child_dob'] => 'تاريخ وفاة الأم يسبق ميلاد اليتيم.',
            $n['child_dob'] && $n['father_death_date'] && $n['child_dob'] > date('Y-m-d', strtotime($n['father_death_date'].' +300 days')) => 'ميلاد اليتيم بعد وفاة الأب بأكثر من 300 يوم.',
            default => null,
        };
        if ($chronology) {
            $add('impossible_chronology', 'conflict', 'child_dob', $chronology.' راجع التواريخ.');
        }
        $n['child_sex'] = match (ArabicText::normalize($v['child_sex'] ?? '')) {
            'ذكر', 'male', 'm' => 'male', 'انثي', 'female', 'f' => 'female', default => null,
        };
        if (($v['child_sex'] ?? '') === '') {
            $add('sex_unknown', 'warning', 'child_sex', 'الجنس غير مذكور؛ يبقى «غير محدد» ولا يُستنتج.');
        }
        foreach (['contact_phone', 'contact_phone_2'] as $k) {
            $n[$k] = Identity::clean($v[$k] ?? '') ?: null;
        }
        foreach (['family_total', 'family_males', 'family_females'] as $k) {
            $n[$k] = ($v[$k] ?? '') !== '' && ctype_digit(ArabicText::digits($v[$k])) ? (int) ArabicText::digits($v[$k]) : null;
        }
        if ($n['family_total'] !== null && $n['family_males'] !== null && $n['family_females'] !== null && $n['family_total'] !== $n['family_males'] + $n['family_females']) {
            $add('family_counts_mismatch', 'warning', 'family_total', 'الذكور ('.$n['family_males'].') + الإناث ('.$n['family_females'].') لا يساوي عدد الأسرة ('.$n['family_total'].'). تُحفظ الأعداد كما وردت.');
        }
        $displaced = ArabicText::normalize($v['is_displaced'] ?? '');
        $n['is_displaced'] = in_array($displaced, ['نعم', 'yes', '1', 'نازح', 'true'], true) ? true : (in_array($displaced, ['لا', 'no', '0', 'false'], true) ? false : null);
        $n['mother_deceased'] = $n['mother_death_date'] !== null || in_array(ArabicText::key($n['mother_marital']), ['متوفاه', 'متوفيه', 'متوفي'], true);

        $hasGuardian = $n['guardian_full_name'] !== '' || $n['guardian_id'] !== '';
        if (! $hasGuardian) {
            $add('missing_guardian', 'warning', 'guardian_full_name', 'لا يوجد وصي مرتبط بالصف؛ لا يُنشأ ملف يتيم دون وصي. يُستنتج من الإخوة إن أمكن، وإلا صحّح الصف بإضافة الوصي.');
        } elseif (! in_array($n['guardian_id_format'], ['valid_format', 'other_type'], true)) {
            $add('guardian_unidentified', 'warning', 'guardian_id', 'الوصي دون رقم هوية صحيح؛ يُعاد استخدامه داخل الدفعة بالاسم والجوال فقط.');
        }
        $guardianIsMother = $hasGuardian && (($n['mother_id'] !== '' && $n['mother_id'] === $n['guardian_id']) || $this->kinshipCode($n['guardian_relation']) === 'mother');
        if ($guardianIsMother) {
            $add('mother_is_guardian', 'warning', 'guardian_id', 'الأم هي الوصي المدّعى؛ يُستخدم سجل الأم نفسه، وتُسجل وصاية «مُدّعاة» مستقلة لكل طفل.');
            if ($n['mother_deceased']) {
                $add('impossible_chronology', 'conflict', 'guardian_relation', 'الأم مسجلة متوفاة ووصية في الصف نفسه.');
            }
        }

        // In-file duplicates (first occurrence wins) and registry matches.
        $matches = [];
        $action = 'create';
        $diff = null;
        if ($n['child_id'] !== '') {
            $first = $n['child_id_format'] === 'placeholder' ? null : DB::table('import_staged_rows')->where('batch_id', $batch->id)->where('row_no', '<', $number)
                ->when($rowId, fn ($q) => $q->where('id', '<>', $rowId))->whereRaw("(normalized->>'child_id') = ?", [$n['child_id']])->orderBy('row_no')->first(['row_no', 'normalized']);
            if ($first) {
                $o = json_decode($first->normalized, true);
                $same = ArabicText::key($this->childName($o)) === ArabicText::key($this->childName($n)) && ($o['child_dob'] ?? null) === $n['child_dob'] && ($o['guardian_id'] ?? '') === $n['guardian_id'];
                $same ? $add('duplicate_in_file', 'warning', 'child_id', 'تكرار للصف '.$first->row_no.' بالبيانات نفسها؛ لا يُستورد مرتين.')
                    : $add('child_id_contradiction', 'conflict', 'child_id', 'رقم الهوية نفسه في الصف '.$first->row_no.' ببيانات مختلفة (الاسم أو الميلاد أو الوصي).');
            }
            $person = Person::query()->where('id_hash', Identity::hash($n['child_id']))->first();
            if ($person) {
                $orphan = Orphan::query()->where('person_id', $person->id)->first();
                $matches['child'] = ['person' => $person->public_id, 'name' => $person->full_name, 'orphan' => $orphan?->reference_no, 'status' => $orphan?->status];
                if (! $orphan) {
                    $add('identity_other_role', 'conflict', 'child_id', 'رقم الهوية مسجل لشخص بدور آخر («'.$person->full_name.'»).');
                } else {
                    $action = 'update';
                    $diff = $this->fillDiff($person, $n);
                    if (ArabicText::key($person->full_name) !== ArabicText::key($this->childName($n))) {
                        $add('name_differs', 'conflict', 'child_full_name', 'الاسم في الملف يختلف عن السجل («'.$person->full_name.'»).');
                    }
                    // An approved guardian in the registry is never contradicted by a file claim.
                    $approved = DB::table('orphan_guardianships as gs')->join('orphan_guardians as g', 'g.id', '=', 'gs.guardian_id')->join('persons as p', 'p.id', '=', 'g.person_id')
                        ->where('gs.orphan_id', $orphan->id)->where('gs.status', 'approved')->pluck('p.id_hash')->filter()->all();
                    if ($approved && $n['guardian_id_format'] === 'valid_format' && ! in_array(Identity::hash($n['guardian_id']), $approved, true)) {
                        $add('guardian_conflicts_registry', 'conflict', 'guardian_id', 'لليتيم وصاية معتمدة في السجل لوصي آخر؛ الملف لا يغيّرها.');
                    }
                }
            }
        } else {
            $name = ArabicText::normalize($this->childName($n));
            if ($name !== '' && DB::table('persons')->where('name_search', $name)->exists()) {
                $add('name_collision', 'warning', 'child_full_name', 'يوجد شخص مسجل بالاسم نفسه ولا يوجد رقم هوية للمطابقة؛ راجع قبل الإنشاء.');
            }
        }
        if ($n['guardian_id'] !== '' && ($g = Person::query()->where('id_hash', Identity::hash($n['guardian_id']))->first())) {
            $matches['guardian'] = ['person' => $g->public_id, 'name' => $g->full_name];
            if ($n['guardian_full_name'] !== '' && ArabicText::key($g->full_name) !== $n['guardian_name_key']) {
                $add('guardian_name_differs', 'conflict', 'guardian_full_name', 'اسم الوصي يختلف عن الشخص المسجل بالرقم نفسه («'.$g->full_name.'»).');
            }
        }
        if (($n['health_status'] ?? '') !== '' || ($n['health_note'] ?? '') !== '') {
            $add('health_data', 'warning', 'health_status', 'يتضمن بيانات صحية مقيدة؛ تُحفظ كإفادة بصلاحية مقيدة.');
        }
        // Keys only group rows inside one batch (HMAC of identity numbers; never the numbers themselves).
        $group = match (true) {
            $n['guardian_id_format'] === 'valid_format' => 'g:'.substr(Identity::hash($n['guardian_id']), 0, 62),
            $n['guardian_name_key'] !== '' => 'n:'.substr(hash('sha256', $n['guardian_name_key'].'|'.($n['contact_phone'] ?? '')), 0, 62),
            default => null,
        };
        // Family = shared parents proven by identity numbers. Never by phone, street, surname or a social household alone.
        $fatherValid = $n['father_id_format'] === 'valid_format';
        $motherValid = $n['mother_id_format'] === 'valid_format';
        $family = match (true) {
            $fatherValid && $motherValid => 'fm:'.substr(Identity::hash($n['father_id'].'|'.$n['mother_id']), 0, 61),
            $fatherValid && $n['mother_name_key'] !== '' => 'fn:'.substr(Identity::hash($n['father_id'].'|'.$n['mother_name_key']), 0, 61),
            $motherValid && $n['father_name_key'] !== '' => 'mn:'.substr(Identity::hash($n['mother_id'].'|'.$n['father_name_key']), 0, 61),
            default => null,
        };

        return [$n, $issues, $matches, $action, $diff, $group, $family];
    }

    private function checkCampaign(object $batch, array $v, ?int $rowId): array
    {
        $issues = [];
        $campaign = DB::table('campaigns')->find($batch->campaign_id);
        $n = ['full_name' => $v['full_name'] ?? '', 'variant' => $v['variant'] ?? '', 'governorate' => $v['governorate'] ?? '', 'recipient' => $v['recipient'] ?? '',
            'orphan_reference' => strtoupper(trim($v['orphan_reference'] ?? '')), 'household_reference' => strtoupper(trim($v['household_reference'] ?? ''))];
        [, $n['child_id']] = Identity::classify($v['child_id'] ?? '', null);
        [, $n['guardian_id']] = Identity::classify($v['guardian_id'] ?? '', null);
        $n['quantity'] = ($v['quantity'] ?? '') === '' ? null : (is_numeric(ArabicText::digits($v['quantity'])) ? (float) ArabicText::digits($v['quantity']) : -1);
        $n['delivered'] = in_array(ArabicText::normalize($v['delivered'] ?? ''), ['نعم', 'yes', '1', 'تم'], true);
        $n['delivered_on'] = $this->date($v['delivered_on'] ?? '');
        if ($n['quantity'] !== null && $n['quantity'] <= 0) {
            $issues[] = ['code' => 'invalid_quantity', 'severity' => 'error', 'field' => 'quantity', 'message' => 'الكمية غير صالحة.'];
        }
        $unit = null;
        $unitType = $campaign->beneficiary_unit ?? 'orphan';
        if ($unitType === 'orphan') {
            $orphan = $n['child_id'] !== '' ? DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->where('p.id_hash', Identity::hash($n['child_id']))->first(['o.id', 'p.full_name'])
                : ($n['orphan_reference'] !== '' ? DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->where('o.reference_no', $n['orphan_reference'])->first(['o.id', 'p.full_name']) : null);
            $unit = $orphan ? ['key' => 'o:'.$orphan->id, 'name' => $orphan->full_name] : null;
        } elseif ($unitType === 'household') {
            $household = $n['household_reference'] !== '' ? DB::table('households')->where('reference_no', $n['household_reference'])->first(['id', 'reference_no']) : null;
            $unit = $household ? ['key' => 'h:'.$household->id, 'name' => 'أسرة '.$household->reference_no] : null;
        } else {
            $guardian = $n['guardian_id'] !== '' ? DB::table('orphan_guardians as g')->join('persons as p', 'p.id', '=', 'g.person_id')->where('p.id_hash', Identity::hash($n['guardian_id']))->first(['g.id', 'p.full_name']) : null;
            $unit = $guardian ? ['key' => 'g:'.$guardian->id, 'name' => $guardian->full_name] : null;
        }
        if (! $unit) {
            $issues[] = ['code' => 'not_in_registry', 'severity' => 'error', 'field' => null, 'message' => 'لا يوجد مستفيد مطابق في السجل (يُطابق برقم الهوية أو رقم الملف فقط، لا بالاسم).'];
        } else {
            $n['unit_key'] = $unit['key'];
            if ($n['full_name'] !== '' && ArabicText::normalize($n['full_name']) !== ArabicText::normalize($unit['name'])) {
                $issues[] = ['code' => 'name_differs', 'severity' => 'conflict', 'field' => 'full_name', 'message' => 'الاسم في الملف يختلف عن السجل («'.$unit['name'].'»).'];
            }
            $sameInFile = DB::table('import_staged_rows')->where('batch_id', $batch->id)->when($rowId, fn ($q) => $q->where('id', '<>', $rowId))->whereRaw("(normalized->>'unit_key') = ?", [$unit['key']])->value('row_no');
            if ($sameInFile) {
                $issues[] = ['code' => 'duplicate_in_file', 'severity' => 'warning', 'field' => null, 'message' => 'المستفيد مكرر في الملف (الصف '.$sameInFile.').'];
            }
            if ($batch->purpose === 'campaign_candidates') {
                $approved = DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->where('l.campaign_id', $batch->campaign_id)->where('l.status', 'approved')->where('e.unit_key', $unit['key'])->exists();
                if ($approved) {
                    $issues[] = ['code' => 'already_listed', 'severity' => 'warning', 'field' => null, 'message' => 'مدرج مسبقًا في الكشف المعتمد.'];
                }
                if ($n['variant'] !== '' && ! DB::table('campaign_items')->where('campaign_id', $batch->campaign_id)->where(fn ($q) => $q->where('variant', $n['variant'])->orWhere('label', $n['variant']))->exists()) {
                    $issues[] = ['code' => 'unknown_variant', 'severity' => 'warning', 'field' => 'variant', 'message' => 'المقاس/الصنف غير معرّف في الحملة؛ يُدرج دون صنف.'];
                }
            }
        }

        return [$n, $issues, $unit ? ['unit' => $unit] : null, $batch->purpose === 'campaign_candidates' ? 'propose' : 'report', null, null, null];
    }

    private function finishAnalysis(object $batch): void
    {
        if ($batch->purpose === 'registry') {
            $this->familyPass($batch);
        }
        DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'reviewing', 'updated_at' => now(),
            'rows_total' => DB::table('import_staged_rows')->where('batch_id', $batch->id)->count()]);
        $this->refreshSummary($batch, true);
    }

    /**
     * Batch-level checks that need all rows: one parent identity with different names, and the sibling-guardian rule.
     * Rule (approved by the owner): in a family proven by both parents' identity numbers, a child without a guardian
     * takes the guardian of its siblings when exactly one guardian is named; a weaker family key only proposes it, and
     * two or more guardians stop any inference. The pass is recomputed from scratch, so it can run again after edits.
     */
    private function familyPass(object $batch): void
    {
        $id = (int) $batch->id;
        $touched = DB::table('import_row_issues')->where('batch_id', $id)->where(fn ($q) => $q->whereIn('code', self::FAMILY_CODES)->orWhere('resolution', '<>', self::OPEN))->pluck('row_id')->all();
        $touched = [...$touched, ...DB::table('import_staged_rows')->where('batch_id', $id)->whereNotNull('inference')->pluck('id')->all()];
        DB::table('import_row_issues')->where('batch_id', $id)->whereIn('code', self::FAMILY_CODES)->delete();
        DB::table('import_row_issues')->where('batch_id', $id)->where('code', 'missing_guardian')->update(['resolution' => self::OPEN]);
        DB::table('import_staged_rows')->where('batch_id', $id)->whereNotNull('inference')->update(['inference' => null]);
        $issues = [];

        foreach (['father' => 'الأب', 'mother' => 'الأم'] as $who => $label) {
            $rows = DB::select("SELECT s.id, s.normalized->>'{$who}_full_name' AS name FROM import_staged_rows s WHERE s.batch_id = ? AND (s.normalized->>'{$who}_id') IN (
                SELECT normalized->>'{$who}_id' FROM import_staged_rows WHERE batch_id = ? AND normalized->>'{$who}_id_format' = 'valid_format'
                GROUP BY 1 HAVING count(DISTINCT normalized->>'{$who}_name_key') > 1)", [$id, $id]);
            foreach ($rows as $row) {
                $issues[] = ['row_id' => $row->id, 'batch_id' => $id, 'code' => 'parent_name_differs', 'severity' => 'warning', 'field' => $who.'_full_name',
                    'message' => "رقم هوية {$label} نفسه مكتوب بأسماء مختلفة في الملف؛ يُستخدم سجل واحد له. تأكد أنه الشخص نفسه."];
            }
        }

        $missing = DB::table('import_staged_rows as r')->join('import_row_issues as i', 'i.row_id', '=', 'r.id')->where('r.batch_id', $id)->where('i.code', 'missing_guardian')
            ->whereNotNull('r.family_key')->get(['r.id', 'r.row_no', 'r.family_key', 'i.id as issue_id']);
        if ($missing->isNotEmpty()) {
            $guardians = DB::table('import_staged_rows')->where('batch_id', $id)->whereIn('family_key', $missing->pluck('family_key')->unique()->all())->whereNotNull('group_key')
                ->whereNotIn('category', ['rejected', 'duplicate'])->orderBy('row_no')->get(['row_no', 'family_key', 'group_key', 'normalized'])->groupBy('family_key');
            foreach ($missing as $row) {
                $known = ($guardians[$row->family_key] ?? collect())->unique('group_key')->values();
                if ($known->count() > 1) {
                    $issues[] = ['row_id' => $row->id, 'batch_id' => $id, 'code' => 'sibling_guardian_ambiguous', 'severity' => 'warning', 'field' => 'guardian_full_name',
                        'message' => 'للإخوة في هذه الأسرة '.$known->count().' أوصياء مختلفون؛ لا يُستنتج وصي. اختر الوصي يدويًا.'];

                    continue;
                }
                if ($known->count() !== 1) {
                    continue;
                }
                $sibling = $known->first();
                $s = json_decode($sibling->normalized, true);
                $strong = str_starts_with($row->family_key, 'fm:');
                DB::table('import_staged_rows')->where('id', $row->id)->update(['inference' => json_encode([
                    'source' => 'inferred_from_sibling_guardian', 'sibling_row' => (int) $sibling->row_no, 'group_key' => $sibling->group_key,
                    'guardian_full_name' => $s['guardian_full_name'] ?? '', 'guardian_id' => $s['guardian_id'] ?? '', 'guardian_id_format' => $s['guardian_id_format'] ?? 'missing',
                    'guardian_relation' => $s['guardian_relation'] ?? '', 'contact_phone' => $s['contact_phone'] ?? null, 'contact_phone_2' => $s['contact_phone_2'] ?? null,
                    'confidence' => $strong ? 'high' : 'medium',
                    'rationale' => $strong ? 'الأخ/الأخت في الصف '.$sibling->row_no.' بهويتي الأب والأم نفسيهما، ولا وصي آخر مذكور في الأسرة.'
                        : 'الأخ/الأخت في الصف '.$sibling->row_no.' بهوية أحد الوالدين واسم الآخر فقط؛ دليل أضعف.',
                ], JSON_UNESCAPED_UNICODE)]);
                if ($strong) {
                    DB::table('import_row_issues')->where('id', $row->issue_id)->update(['resolution' => self::INFERRED]);
                    $issues[] = ['row_id' => $row->id, 'batch_id' => $id, 'code' => 'guardian_inferred', 'severity' => 'warning', 'field' => 'guardian_full_name',
                        'message' => 'الوصي مستنتج من الأخ/الأخت في الصف '.$sibling->row_no.' («'.($s['guardian_full_name'] ?? '').'»)؛ يُسجل ادعاء وصاية مستقلًا لهذا الطفل بانتظار التحقق.'];
                } else {
                    $issues[] = ['row_id' => $row->id, 'batch_id' => $id, 'code' => 'guardian_inference_proposed', 'severity' => 'warning', 'field' => 'guardian_full_name',
                        'message' => 'مقترح: وصي الأخ/الأخت في الصف '.$sibling->row_no.' («'.($s['guardian_full_name'] ?? '').'»). اقبل الصف لاعتماده مبدئيًا أو صحّحه.'];
                }
            }
        }
        foreach (array_chunk($issues, 500) as $chunk) {
            DB::table('import_row_issues')->insert($chunk);
        }
        $this->recategorize(array_values(array_unique([...$touched, ...array_column($issues, 'row_id'), ...$missing->pluck('id')->all()])));
    }

    /** Recomputes the category of rows from their unresolved findings (decisions are kept). */
    private function recategorize(array $rowIds): void
    {
        foreach (array_chunk($rowIds, 1000) as $chunk) {
            $issues = DB::table('import_row_issues')->whereIn('row_id', $chunk)->where('resolution', self::OPEN)->get(['row_id', 'code', 'severity', 'field'])->groupBy('row_id');
            foreach ($chunk as $rowId) {
                $category = ImportPolicy::category(($issues[$rowId] ?? collect())->map(fn ($i) => (array) $i)->all());
                DB::table('import_staged_rows')->where('id', $rowId)->where('category', '<>', $category)->update(['category' => $category, 'updated_at' => now()]);
            }
        }
    }

    /** Source quality profile: missing values per mapped field and unique people/families (never the values). */
    private function profile(object $batch): array
    {
        $id = (int) $batch->id;
        $fields = array_values(array_unique(array_values(json_decode((string) $batch->mapping, true) ?: [])));
        $missing = [];
        foreach (array_chunk($fields, 20) as $chunk) {
            $select = implode(', ', array_map(fn ($f) => "count(*) FILTER (WHERE coalesce(normalized->>'".preg_replace('/[^a-z0-9_]/', '', $f)."', '') = '') AS \"".preg_replace('/[^a-z0-9_]/', '', $f).'"', $chunk));
            $missing += (array) DB::selectOne("SELECT {$select} FROM import_staged_rows WHERE batch_id = ?", [$id]);
        }
        $u = DB::selectOne("SELECT count(*) AS rows,
            count(DISTINCT normalized->>'child_id') FILTER (WHERE normalized->>'child_id_format' = 'valid_format') AS children_with_id,
            count(*) FILTER (WHERE coalesce(normalized->>'child_id_format', 'missing') <> 'valid_format') AS children_without_id,
            count(DISTINCT group_key) AS guardians, count(*) FILTER (WHERE group_key IS NULL AND inference IS NULL) AS rows_without_guardian,
            count(DISTINCT family_key) AS families, count(*) FILTER (WHERE family_key IS NULL) AS rows_without_family,
            count(DISTINCT family_key) FILTER (WHERE family_key LIKE 'fm:%') AS families_strong,
            count(*) FILTER (WHERE inference IS NOT NULL) AS inferred,
            count(*) FILTER (WHERE (normalized->>'is_displaced') = 'true') AS displaced
            FROM import_staged_rows WHERE batch_id = ?", [$id]);
        $duplicateIds = DB::selectOne("SELECT count(*) AS n FROM (SELECT normalized->>'child_id' FROM import_staged_rows WHERE batch_id = ? AND normalized->>'child_id_format' = 'valid_format'
            GROUP BY 1 HAVING count(*) > 1) d", [$id])->n;

        return ['missing' => $missing, 'unique' => [...(array) $u, 'duplicate_child_ids' => (int) $duplicateIds], 'sheet' => $batch->sheet, 'computed_at' => now()->toDateTimeString()];
    }

    private function refreshSummary(object $batch, bool $withProfile = false): void
    {
        $summary = $this->summary($batch->id);
        $old = json_decode((string) DB::table('import_batches')->where('id', $batch->id)->value('summary'), true) ?: [];
        $summary['profile'] = $withProfile && $batch->purpose === 'registry' ? $this->profile($batch) : ($old['profile'] ?? null);
        DB::table('import_batches')->where('id', $batch->id)->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    /** Counts shown on the batch page. The preview is computed from staging only and never writes anything. */
    public function summary(int $batchId): array
    {
        $categories = DB::table('import_staged_rows')->where('batch_id', $batchId)->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')->all();
        $pending = DB::table('import_staged_rows as r')->where('r.batch_id', $batchId)->where(fn ($q) => $this->eligible($q))
            ->whereNotExists(fn ($q) => $q->from('import_execution_events as x')->whereColumn('x.row_id', 'r.id'));
        $actions = (clone $pending)->selectRaw('proposed_action, count(*) as n')->groupBy('proposed_action')->pluck('n', 'proposed_action')->all();
        $unique = (clone $pending)->selectRaw('count(*) AS rows, count(DISTINCT r.group_key) AS guardians, count(DISTINCT r.family_key) AS families, count(*) FILTER (WHERE r.family_key IS NULL) AS single_rows,
            count(*) FILTER (WHERE r.inference IS NOT NULL) AS inferred')->first();
        $issues = DB::table('import_row_issues')->where('batch_id', $batchId)->where('resolution', self::OPEN)->selectRaw('code, count(*) as n')->groupBy('code')->orderByDesc('n')->pluck('n', 'code')->all();
        $executed = DB::table('import_execution_events')->where('batch_id', $batchId)->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->all();
        $official = DB::table('import_execution_events')->where('batch_id', $batchId)->selectRaw('count(DISTINCT orphan_id) AS orphans, count(DISTINCT guardian_id) AS guardians, count(DISTINCT household_id) AS households')->first();
        $held = DB::table('import_staged_rows as r')->where('r.batch_id', $batchId)->whereIn('r.category', ['needs_review', 'conflict'])->whereNull('r.decision')
            ->whereNotExists(fn ($q) => $q->from('import_execution_events as x')->whereColumn('x.row_id', 'r.id')->where('x.outcome', '<>', 'held_for_review'))->count();

        return ['categories' => $categories, 'actions' => $actions, 'preview' => (array) $unique, 'issues' => $issues, 'executed' => $executed, 'official' => (array) $official, 'awaiting_review' => $held];
    }

    /* ---------- 4. review ---------- */

    /** Authorized correction of staged values (never of source-of-truth records), then the row and its family are checked again. */
    public function correct(object $batch, int $rowId, array $fields, User $actor): void
    {
        $this->assertOwnerOrHead($batch, $actor);
        abort_unless(in_array($batch->status, self::REVIEWABLE, true), 409, 'التصحيح متاح أثناء المراجعة فقط.');
        $row = DB::table('import_staged_rows')->where('batch_id', $batch->id)->where('id', $rowId)->firstOrFail();
        abort_if(DB::table('import_execution_events')->where('row_id', $row->id)->whereNotIn('outcome', ['held_for_review', 'failed'])->exists(), 409, 'أُدرج هذا الصف في السجل؛ يُعدّل من ملف اليتيم لا من الاستيراد.');
        $known = ImportFields::for($batch->purpose);
        foreach (array_keys($fields) as $field) {
            abort_unless(isset($known[$field]), 422, 'حقل غير معروف.');
            if ($known[$field][2] === 'health') {
                $this->access->assert($actor, 'health');
            }
        }
        $values = array_map('strval', array_filter([...$this->rawValues($row, $batch), ...$fields], fn ($v) => $v !== null));
        DB::transaction(function () use ($batch, $row, $values): void {
            DB::table('import_execution_events')->where('row_id', $row->id)->whereIn('outcome', ['held_for_review', 'failed'])->delete();
            $this->stage($batch, (int) $row->row_no, [], $values, (int) $row->id);
            if ($batch->purpose === 'registry') {
                $this->familyPass($batch);
            }
        });
        $this->refreshSummary($batch);
        $this->audit->recordRow('orphans.import.row_corrected', 'import_batches', $batch->id, null, ['row' => $row->row_no, 'fields' => array_keys($fields)], $actor->id, request()?->ip());
    }

    /** Accept a reviewable row (needs_review/conflict) or skip any row; rejected and duplicate rows cannot be accepted. */
    /**
     * Findings a reviewer accepts one row at a time: identity doubts and guardians the file does not establish.
     * Accepting such a row confirms a person or a guardian claim, so it is never part of a group decision.
     */
    public const SINGLE_ROW_CODES = ['invalid_id', 'placeholder_id', 'child_id_contradiction', 'impossible_chronology',
        'guardian_inferred', 'guardian_inference_proposed', 'guardian_unidentified', 'sibling_guardian_ambiguous'];

    /** Accepted together only within one family: the same parent's ID written with different names. */
    public const FAMILY_ROW_CODES = ['parent_name_differs'];

    /**
     * A reviewer's accept/skip decision on staged rows. A group accept leaves out conflicts and the single-row
     * findings above (owner rule 2026-11-01: no bulk confirmation of identities or guardianship); every decision is
     * audited with its reason and the findings it covered.
     *
     * @return array{decided: int, excluded: int}
     */
    public function decide(object $batch, array $rowIds, string $decision, User $actor, ?string $reason = null): array
    {
        $this->assertOwnerOrHead($batch, $actor);
        abort_unless(in_array($batch->status, self::REVIEWABLE, true), 409);
        ['rows' => $rows, 'eligible' => $eligible, 'open' => $open] = $this->decisionScope($batch, $rowIds, $decision);
        $ids = $eligible->pluck('id')->all();
        DB::transaction(function () use ($batch, $ids, $decision, $actor, $reason, $open, $rows): void {
            if ($ids !== []) {
                DB::table('import_staged_rows')->whereIn('id', $ids)
                    ->update(['decision' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
            }
            $codes = collect($ids)->flatMap(fn (int $id): array => $open[$id] ?? [])->countBy()->all();
            $this->audit->recordRow('orphans.import.rows_decided', 'import_batches', $batch->id, null, ['decision' => $decision,
                'rows' => count($ids), 'excluded' => $rows->count() - count($ids), 'findings' => $codes, 'reason' => $reason], $actor->id, request()?->ip());
        });
        $this->refreshSummary($batch);

        return ['decided' => count($ids), 'excluded' => $rows->count() - count($ids)];
    }

    /**
     * Which of the selected rows a decision covers (read only). Rows already in the registry are never re-decided.
     *
     * @return array{rows: Collection, eligible: Collection, open: Collection}
     */
    public function decisionScope(object $batch, array $rowIds, string $decision): array
    {
        $rows = DB::table('import_staged_rows')->where('batch_id', $batch->id)->whereIn('id', $rowIds)
            ->whereNotExists(fn ($q) => $q->from('import_execution_events as x')->whereColumn('x.row_id', 'import_staged_rows.id')->whereNotIn('x.outcome', ['held_for_review', 'failed']))
            ->when($decision === 'accept', fn ($q) => $q->whereIn('category', ['needs_review', 'conflict']))
            ->get(['id', 'category', 'family_key']);
        $open = DB::table('import_row_issues')->whereIn('row_id', $rows->pluck('id'))->where('resolution', self::OPEN)
            ->get(['row_id', 'code'])->groupBy('row_id')->map(fn ($issues) => $issues->pluck('code')->unique()->values()->all());
        $eligible = $rows;
        if ($decision === 'accept' && $rows->count() > 1) {
            $families = $rows->pluck('family_key')->unique();
            $eligible = $rows->reject(fn (object $row): bool => $row->category === 'conflict'
                || array_intersect($open[$row->id] ?? [], self::SINGLE_ROW_CODES) !== []
                || (array_intersect($open[$row->id] ?? [], self::FAMILY_ROW_CODES) !== [] && ($families->count() > 1 || $row->family_key === null)));
        }

        return ['rows' => $rows, 'eligible' => $eligible->values(), 'open' => $open];
    }

    /* ---------- 5. «اعتماد مبدئي وإدراج في السجل» and 6. execution ---------- */

    /**
     * Authorizes writing the eligible rows into the registry as «معتمد مبدئيًا». With $rowIds only those rows' families
     * are committed (a controlled smaller run). Failed or held rows of earlier runs are retried.
     */
    public function approve(object $batch, User $actor, ?array $rowIds = null): void
    {
        $this->access->assert($actor, 'import');
        abort_unless(in_array($batch->status, self::REVIEWABLE, true), 409, 'الدفعة ليست في مرحلة المراجعة.');
        $scope = null;
        if ($rowIds) {
            $families = DB::table('import_staged_rows')->where('batch_id', $batch->id)->whereIn('id', $rowIds)->whereNotNull('family_key')->pluck('family_key')->all();
            $scope = DB::table('import_staged_rows')->where('batch_id', $batch->id)->where(fn ($q) => $q->whereIn('id', $rowIds)->orWhereIn('family_key', $families))->pluck('id')->all();
            abort_if($scope === [], 422, 'لم يُحدد أي صف من هذه الدفعة.');
        }
        $retried = DB::table('import_execution_events')->where('batch_id', $batch->id)->whereIn('outcome', ['failed', 'held_for_review'])
            ->when($scope, fn ($q) => $q->whereIn('row_id', $scope))->delete();
        DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'executing', 'approved_by' => $actor->id, 'approved_at' => now(), 'cursor' => 0,
            'commit_scope' => $scope ? json_encode(['rows' => $scope]) : null, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.import.provisional_commit', 'import_batches', $batch->id, null,
            ['preview' => $this->summary($batch->id)['preview'], 'scoped_rows' => $scope ? count($scope) : null, 'retried' => $retried], $actor->id, request()?->ip());
        $this->inline($batch->id);
    }

    /**
     * A first short step inside the web request, only for small files: opening a large workbook (shared strings,
     * skipping to the cursor) alone can use most of the request's 30 seconds. Everything else is left to the
     * scheduled worker (orphans:import-process, every minute); the batch lock keeps the two from overlapping.
     */
    public function inline(int $batchId): void
    {
        $batch = DB::table('import_batches')->find($batchId);
        if ($batch && (int) $batch->size <= self::INLINE_MAX_BYTES) {
            $this->work($batch, self::INLINE_ROWS, self::INLINE_SECONDS);
        }
    }

    private function eligible($query, string $alias = 'r'): void
    {
        $query->where(fn ($q) => $q->where($alias.'.category', 'ready')->whereNull($alias.'.decision'))->orWhere($alias.'.decision', 'accept');
    }

    private function executeChunk(object $batch, int $maxRows, int $seconds): bool
    {
        $started = microtime(true);
        $actor = User::findOrFail($batch->approved_by);
        $scope = json_decode((string) $batch->commit_scope, true)['rows'] ?? null;
        $pending = fn () => DB::table('import_staged_rows as r')->where('r.batch_id', $batch->id)->where(fn ($q) => $this->eligible($q))
            ->whereNotExists(fn ($q) => $q->from('import_execution_events as x')->whereColumn('x.row_id', 'r.id'))->when($scope, fn ($q) => $q->whereIn('r.id', $scope));
        $cursor = (int) $batch->cursor;
        $done = 0;
        while ($first = $pending()->where('r.id', '>', $cursor)->orderBy('r.id')->first(['r.*'])) {
            // One family = one unit of work: its people, household, children and per-child guardian links commit together.
            $unit = $batch->purpose === 'registry' && $first->family_key
                ? $pending()->where('r.family_key', $first->family_key)->orderBy('r.row_no')->get(['r.*']) : collect([$first]);
            $this->executeUnit($batch, $unit, $actor, (int) $first->id);
            $cursor = (int) $first->id;
            $done += $unit->count();
            if ($done >= $maxRows || microtime(true) - $started > $seconds) {
                return false;
            }
        }
        $reviewLeft = DB::table('import_staged_rows as r')->where('r.batch_id', $batch->id)->whereIn('r.category', ['needs_review', 'conflict'])->whereNull('r.decision')
            ->whereNotExists(fn ($q) => $q->from('import_execution_events as x')->whereColumn('x.row_id', 'r.id')->where('x.outcome', '<>', 'held_for_review'))->exists();
        $held = DB::table('import_execution_events')->where('batch_id', $batch->id)->whereIn('outcome', ['held_for_review', 'failed'])->exists();
        $status = $batch->purpose !== 'registry' ? 'completed' : ($scope || $reviewLeft || $held || $pending()->exists() ? 'partially_imported' : 'imported');
        DB::table('import_batches')->where('id', $batch->id)->update(['status' => $status, 'completed_at' => in_array($status, ['completed', 'imported'], true) ? now() : null,
            'commit_scope' => null, 'updated_at' => now()]);
        $this->refreshSummary($batch);
        $this->audit->recordRow('orphans.import.'.($status === 'partially_imported' ? 'partially_imported' : 'completed'), 'import_batches', $batch->id, null, $this->summary($batch->id)['executed'], $actor->id, null);

        return true;
    }

    /** Commits one unit (family or single row) in one transaction; on failure nothing of the unit stays and each row is marked failed. */
    private function executeUnit(object $batch, Collection $rows, User $actor, int $cursor): void
    {
        try {
            DB::transaction(function () use ($batch, $rows, $actor, $cursor): void {
                foreach ($rows as $row) {
                    $n = json_decode($row->normalized, true);
                    [$outcome, $refs, $message] = match ($batch->purpose) {
                        'registry' => $this->importRegistryRow($batch, $row, $n, $actor),
                        'campaign_candidates' => $this->proposeCandidate($batch, $row, $n, $actor),
                        default => ['proposed', ['unit_key' => $n['unit_key'] ?? null], $this->reconcileNote($batch, $n)],
                    };
                    DB::table('import_execution_events')->insert(['batch_id' => $batch->id, 'row_id' => $row->id, 'outcome' => $outcome, 'entities' => json_encode($refs, JSON_UNESCAPED_UNICODE),
                        'orphan_id' => $refs['orphan_id'] ?? null, 'guardian_id' => $refs['guardian_id'] ?? null, 'household_id' => $refs['household_id'] ?? null,
                        'message' => $message, 'actor_id' => $actor->id, 'created_at' => now()]);
                }
                DB::table('import_batches')->where('id', $batch->id)->update(['cursor' => $cursor, 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            report($e);
            foreach ($rows as $row) {
                DB::table('import_execution_events')->insertOrIgnore(['batch_id' => $batch->id, 'row_id' => $row->id, 'outcome' => 'failed', 'message' => mb_substr($this->safeMessage($e), 0, 1000),
                    'actor_id' => $actor->id, 'created_at' => now()]);
            }
            DB::table('import_batches')->where('id', $batch->id)->update(['cursor' => $cursor, 'updated_at' => now()]);
        }
    }

    /**
     * One child into the registry as «معتمد مبدئيًا»: reuses people (identity number, then this batch's keys), one
     * household per family, the shared father/mother rows, one guardian person, and a separate claimed link per child.
     * An existing child file is only completed where empty (never overwritten).
     */
    private function importRegistryRow(object $batch, object $row, array $n, User $actor): array
    {
        $inference = json_decode((string) $row->inference, true);
        $guardianSource = ($n['guardian_full_name'] !== '' || $n['guardian_id'] !== '') ? ['inferred' => false, ...$n, 'group_key' => $row->group_key]
            : ($inference && ($inference['confidence'] === 'high' || $row->decision === 'accept') ? ['inferred' => true, ...$inference] : null);
        if (! $guardianSource) {
            return ['held_for_review', [], 'لا يوجد وصي مرتبط بهذا الطفل؛ يبقى الصف في المرحلة حتى يُربط وصي.'];
        }
        $note = 'استيراد '.$batch->reference_no.' الصف '.$row->row_no;
        $existing = $n['child_id_format'] === 'valid_format' ? Orphan::query()->whereHas('person', fn ($q) => $q->where('id_hash', Identity::hash($n['child_id'])))->lockForUpdate()->first() : null;

        $mother = ($existing && DB::table('orphan_parent_links')->where('orphan_id', $existing->id)->where('relation', 'mother')->exists()) ? null : $this->parentPerson($batch, $row, $n, 'mother', $actor);
        $father = ($existing && DB::table('orphan_parent_links')->where('orphan_id', $existing->id)->where('relation', 'father')->exists()) ? null : $this->parentPerson($batch, $row, $n, 'father', $actor);
        $household = $this->household($batch, $row, $n, $existing, $mother, $actor);
        $guardian = $this->guardianFor($batch, $row, $guardianSource, $mother ?? $this->linkedParent($existing, 'mother'), $actor);
        $kinship = $this->kinship($guardianSource['guardian_relation'] ?? '');
        $provenance = ['batch' => $batch->reference_no, 'row' => (int) $row->row_no, 'source' => $guardianSource['inferred'] ? 'inferred_from_sibling_guardian' : 'import_row',
            ...($guardianSource['inferred'] ? ['sibling_row' => $guardianSource['sibling_row'], 'confidence' => $guardianSource['confidence'], 'rationale' => $guardianSource['rationale']] : []),
            'relation_as_written' => $guardianSource['guardian_relation'] ?? null];
        $link = ['link_status' => 'claimed', 'source' => 'import', 'kinship_value_id' => $kinship, 'provenance' => $provenance,
            'evidence_note' => ($guardianSource['inferred'] ? 'ادعاء مستنتج من وصي الإخوة — ' : 'ادعاء من ').$note];
        $parents = array_filter(['father' => $father ? ['person_id' => $father->id, 'basis' => 'import', 'link_note' => $note] : null,
            'mother' => $mother ? ['person_id' => $mother->id, 'basis' => 'import', 'link_note' => $note] : null]);

        $child = ['first_name' => $n['child_first_name'] ?: null, 'father_name' => $n['child_father_name'] ?: null, 'grandfather_name' => $n['child_grandfather_name'] ?: null,
            'family_name' => $n['child_family_name'] ?: null, 'full_name' => $this->childName($n), 'id_number' => $n['child_id'] ?: null, 'date_of_birth' => $n['child_dob'], 'sex' => $n['child_sex']];
        if ($existing) {
            $changed = false;
            $person = Person::findOrFail($existing->person_id);
            $fill = array_filter(collect($child)->only(['date_of_birth', 'sex', 'first_name', 'father_name', 'grandfather_name', 'family_name'])->all(),
                fn ($value, $field) => $value !== null && $person->{$field} === null, ARRAY_FILTER_USE_BOTH);
            if ($fill) {
                $this->persons->save($fill, $person, $actor->id);
                $changed = true;
            }
            if (! OrphanGuardianship::query()->where('orphan_id', $existing->id)->where('guardian_id', $guardian->id)->whereIn('status', OrphanGuardianship::LIVE)->exists()) {
                $this->registry->addGuardianship($existing, $guardian, $link, $actor);
                $changed = true;
            }
            foreach ($parents as $who => $data) {
                $this->registry->linkParent($existing, $who, $data, $actor);
                $changed = true;
            }
            $orphan = $existing;
            $outcome = $changed ? 'updated' : 'linked';
        } else {
            $orphan = $this->registry->createOrphan($child, ['status' => 'provisional', 'registration_source' => 'import', 'registered_on' => $n['registered_at'] ?? today()->toDateString(),
                'notes' => trim($note.($n['source_serial'] !== '' ? ' — الرقم التسلسلي في المصدر: '.$n['source_serial'] : ''))], ['guardian_id' => $guardian->id, ...$link], $parents, $actor);
            $orphan->forceFill(['import_batch_id' => $batch->id])->save();
            $outcome = 'created';
        }
        $this->member($household, (int) $orphan->person_id, $this->kinshipId('child'), $actor);
        $this->enrich($orphan, $n, $actor, $outcome === 'created');
        $this->registry->refreshCompleteness($orphan->fresh());

        return [$outcome, ['orphan_id' => $orphan->id, 'orphan' => $orphan->reference_no, 'guardian_id' => $guardian->id, 'guardian' => $guardian->reference_no,
            'household_id' => $household, 'father_id' => $father?->id, 'mother_id' => $mother?->id, 'inferred_guardian' => $guardianSource['inferred']], null];
    }

    /** Father or mother: one person for all siblings (identity number, else this batch's family key). Facts are recorded once. */
    private function parentPerson(object $batch, object $row, array $n, string $who, User $actor): ?Person
    {
        if ($n[$who.'_full_name'] === '' && $n[$who.'_id'] === '') {
            return null;
        }
        $person = $n[$who.'_id_format'] === 'valid_format' ? Person::query()->where('id_hash', Identity::hash($n[$who.'_id']))->first() : null;
        $key = $who.':'.($row->family_key ?? 'row:'.$row->id);
        if (! $person && $n[$who.'_id_format'] !== 'valid_format' && ($id = $this->entity($batch, $key))) {
            $person = Person::find($id);
        }
        $created = false;
        if (! $person) {
            $person = $this->persons->save(['full_name' => $n[$who.'_full_name'] !== '' ? $n[$who.'_full_name'] : 'غير مسمى', 'id_number' => $n[$who.'_id'] ?: null,
                'date_of_birth' => $n[$who.'_dob'], 'sex' => $who === 'father' ? 'male' : 'female'], null, $actor->id);
            $created = true;
            if ($n[$who.'_id_format'] !== 'valid_format') {
                $this->remember($batch, $key, 'person', $person->id);
            }
        }
        $note = 'استيراد '.$batch->reference_no.' الصف '.$row->row_no;
        $deceased = $who === 'father' ? $n['father_death_date'] !== null : $n['mother_deceased'];
        if ($deceased && in_array($person->vital_status, [null, 'unknown'], true)) {
            $cause = $n[$who.'_death_cause'] ?? '';
            $this->persons->recordStatus($person, ['status' => 'deceased', 'event_date' => $n[$who.'_death_date'], 'cause' => $cause !== '' ? $cause : null,
                'evidence_note' => $note, 'source' => 'ملف الاستيراد', 'verification' => 'reported'], $actor->id);
        }
        if ($created && $who === 'mother') {
            $updates = array_filter(['marital_status_value_id' => $n['mother_deceased'] ? null : $this->maritalId($n['mother_marital']),
                'education_level_value_id' => $this->educationId($n['mother_education']), 'education_specialization' => mb_substr($n['mother_specialization'], 0, 150) ?: null]);
            $raw = array_filter(['المستوى التعليمي كما ورد: '.$n['mother_education'] => $n['mother_education'] !== '' && empty($updates['education_level_value_id'])]);
            $person->forceFill([...$updates, 'notes' => $raw ? implode("\n", array_keys($raw)) : $person->notes])->save();
        }
        if ($created && $who === 'father' && $n['father_occupation'] !== '') {
            $idle = in_array(ArabicText::key($n['father_occupation']), ['لا يعمل', 'لايعمل', 'عاطل'], true);
            DB::table('economic_assessments')->insert(['person_id' => $person->id, 'period' => $n['father_death_date'] ? 'pre_death' : 'current', 'reference_date' => $n['father_death_date'] ?? today()->toDateString(),
                'occupation' => mb_substr($n['father_occupation'], 0, 200), 'employed' => $idle ? 'no' : null, 'source' => 'self_reported', 'verification' => 'reported',
                'notes' => $note, 'assessed_by' => $actor->id, 'created_at' => now()]);
        }

        return $person;
    }

    /** The guardian person: identity number, the mother herself, or this batch's guardian key; one guardian row per person. */
    private function guardianFor(object $batch, object $row, array $g, ?Person $mother, User $actor): OrphanGuardian
    {
        $person = $g['guardian_id_format'] === 'valid_format' ? Person::query()->where('id_hash', Identity::hash($g['guardian_id']))->first() : null;
        if (! $person && $mother && $this->kinshipCode($g['guardian_relation'] ?? '') === 'mother') {
            $person = $mother; // «الأم» without a number: the mother's own record, never a copy
        }
        $key = $g['group_key'] ? 'guardian:'.$g['group_key'] : null;
        if (! $person && $key && ($id = $this->entity($batch, $key))) {
            $person = Person::find($id);
        }
        if (! $person) {
            $person = $this->persons->save(['full_name' => $g['guardian_full_name'] !== '' ? $g['guardian_full_name'] : 'وصي غير مسمى', 'id_number' => $g['guardian_id'] ?: null,
                'phone' => $g['contact_phone'] ?? null, 'alt_phone' => $g['contact_phone_2'] ?? null], null, $actor->id);
            if ($key && $g['guardian_id_format'] !== 'valid_format') {
                $this->remember($batch, $key, 'person', $person->id);
            }
        } else {
            foreach (['phone' => $g['contact_phone'] ?? null, 'alt_phone' => $g['contact_phone_2'] ?? null] as $column => $value) {
                if ($value) {
                    DB::table('persons')->where('id', $person->id)->whereNull($column)->update([$column => $value, 'updated_at' => now()]);
                }
            }
        }

        return $this->registry->createGuardian([], $person->id, [], $actor);
    }

    /** One household per family (social/residential context only, never guardianship), reused on re-import. */
    private function household(object $batch, object $row, array $n, ?Orphan $existing, ?Person $mother, User $actor): int
    {
        $key = 'household:'.($row->family_key ?? 'row:'.$row->id);
        if ($id = $this->entity($batch, $key)) {
            return $id;
        }
        $member = fn (?int $personId) => $personId ? DB::table('household_members')->where('person_id', $personId)->whereNull('ends_on')->orderBy('id')->value('household_id') : null;
        $id = $member($existing?->person_id) ?? $member($mother?->id ?? $this->linkedParent($existing, 'mother')?->id);
        if (! $id) {
            $id = DB::table('households')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('HH', 'household_reference_seq'),
                'members_count' => $n['family_total'], 'male_count' => $n['family_males'], 'female_count' => $n['family_females'],
                'displacement_status' => $n['is_displaced'] === null ? 'unknown' : ($n['is_displaced'] ? 'yes' : 'no'), 'external_aid_status' => 'unknown', 'status' => 'active',
                'notes' => 'استيراد '.$batch->reference_no.' الصف '.$row->row_no, 'assessed_on' => $n['registered_at'] ?? null,
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $place = trim($n['governorate'].' — '.$n['city'], ' —');
            if ($place !== '' || $n['address_detail'] !== '') {
                [$gov, $city] = $this->geography($n['governorate'], $n['city']);
                // Displaced: the stated address is the original home and the displacement address is the current one.
                $this->registry->saveAddress(['household_id' => $id], $n['is_displaced'] ? 'original' : 'current', ['governorate_value_id' => $gov, 'locality_value_id' => $city,
                    'address_text' => mb_substr($place, 0, 500) ?: null, 'detail' => $n['address_detail'] ?: null], $actor->id);
            }
            if ($n['is_displaced']) {
                $this->registry->saveAddress(['household_id' => $id], 'current', ['address_text' => mb_substr($n['displacement_address'], 0, 500) ?: 'نازح (العنوان غير مذكور في الملف)'], $actor->id);
            }
            if ($mother && ! $n['mother_deceased']) {
                $this->member($id, $mother->id, $this->kinshipId('mother'), $actor);
            }
        }
        $this->remember($batch, $key, 'household', $id);

        return $id;
    }

    private function linkedParent(?Orphan $orphan, string $relation): ?Person
    {
        $id = $orphan ? DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->where('relation', $relation)->value('person_id') : null;

        return $id ? Person::find($id) : null;
    }

    private function member(int $household, int $personId, ?int $relation, User $actor): void
    {
        if (DB::table('household_members')->where('person_id', $personId)->whereNull('ends_on')->exists()) {
            return; // already a member of a household; residence changes are a separate, reviewed action
        }
        DB::table('household_members')->insert(['household_id' => $household, 'person_id' => $personId, 'relation_value_id' => $relation, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Education and (authorized) health facts from the row, appended as dated records for a new file only. */
    private function enrich(Orphan $orphan, array $n, User $actor, bool $new): void
    {
        if (! $new) {
            return; // an existing file keeps its records; the reviewer sees the file's values in the staged diff
        }
        if ($n['school_grade'] !== '' || $n['academic_performance'] !== '') {
            $grade = ArabicText::key($n['school_grade']);
            $status = match (true) {
                str_contains($grade, 'رياض') || str_contains($grade, 'روضه') => 'kindergarten',
                str_contains($grade, 'منقطع') => 'dropped_out',
                $grade === '' || str_contains($grade, 'غير ملتحق') => null, // «غير ملتحق / دون سن الدراسة» are two different facts
                default => 'enrolled',
            };
            DB::table('education_records')->insert(['orphan_id' => $orphan->id, 'grade' => mb_substr($n['school_grade'], 0, 60) ?: null, 'performance' => mb_substr($n['academic_performance'], 0, 100) ?: null,
                'status_value_id' => $status ? $this->valueByCode('enrollment_statuses', $status) : null, 'stage_value_id' => $status === 'kindergarten' ? $this->valueByName('education_stages', 'روضة') : null,
                'source' => 'import', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (ArabicText::key($n['health_status']) === 'سليم') {
            $orphan->forceFill(['health_status_value_id' => $this->valueByCode('health_statuses', 'no_known_issue')])->save();
        } elseif (($n['health_status'] !== '' || $n['health_note'] !== '') && $this->access->allows($actor, 'health')) {
            DB::table('care_records')->insert(['person_id' => $orphan->person_id, 'category' => 'health', 'summary' => mb_substr($n['health_status'], 0, 300) ?: null,
                'clinical_details' => $n['health_note'] !== '' ? encrypt($n['health_note']) : null, 'source' => 'import', 'verification' => 'reported', 'is_active' => true,
                'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Adds one matched beneficiary to the campaign's draft list, or to a change-request draft if a list is approved. */
    private function proposeCandidate(object $batch, object $row, array $n, User $actor): array
    {
        $campaign = DB::table('campaigns')->find($batch->campaign_id);
        $svc = app(Campaigns::class);
        $approved = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->first();
        $draft = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'draft')->lockForUpdate()->first();
        abort_if(DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'pending_executive')->exists(), 409, 'يوجد كشف بانتظار قرار المدير التنفيذي؛ لا يُضاف إليه.');
        if (! $draft) {
            $draft = $approved ? $svc->requestChange($campaign, 'مقترحات من الاستيراد '.$batch->reference_no, null, $actor)
                : DB::table('campaign_lists')->find(DB::table('campaign_lists')->insertGetId(['public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id,
                    'version' => (int) DB::table('campaign_lists')->where('campaign_id', $campaign->id)->max('version') + 1, 'status' => 'draft', 'source' => 'import', 'prepared_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now()]));
            if (! $approved) {
                DB::table('campaigns')->where('id', $campaign->id)->whereIn('status', ['draft'])->update(['status' => 'list_draft', 'updated_at' => now()]);
            }
        }
        [$kind, $id] = [substr($n['unit_key'], 0, 1), (int) substr($n['unit_key'], 2)];
        $item = $n['variant'] !== '' ? DB::table('campaign_items')->where('campaign_id', $campaign->id)->where(fn ($q) => $q->where('variant', $n['variant'])->orWhere('label', $n['variant']))->value('id') : null;
        $exists = DB::table('campaign_list_entries')->where('list_id', $draft->id)->where('unit_key', $n['unit_key'])->whereRaw('COALESCE(item_id, 0) = ?', [$item ?? 0])->exists();
        if ($exists) {
            return ['skipped', ['list' => $draft->version], 'مدرج مسبقًا في المسودة.'];
        }
        DB::table('campaign_list_entries')->insert(['list_id' => $draft->id, 'kind' => 'primary', 'position' => (int) DB::table('campaign_list_entries')->where('list_id', $draft->id)->max('position') + 1,
            'orphan_id' => $kind === 'o' ? $id : null, 'household_id' => $kind === 'h' ? $id : null, 'guardian_id' => $kind === 'g' ? $id : null, 'unit_key' => $n['unit_key'],
            'recipient_guardian_id' => $kind === 'o' ? DB::table('orphan_guardianships')->where('orphan_id', $id)->where('status', 'approved')->orderByDesc('is_primary')->value('guardian_id') : ($kind === 'g' ? $id : null),
            'item_id' => $item, 'quantity' => $n['quantity'] ?: 1, 'reason' => 'مقترح من الاستيراد '.$batch->reference_no.' الصف '.$row->row_no, 'created_at' => now(), 'updated_at' => now()]);
        $svc->refreshList($draft->id);

        return ['proposed', ['list_version' => $draft->version, 'unit_key' => $n['unit_key']], $approved ? 'أُضيف إلى طلب تعديل بانتظار إعداد ثم اعتماد المدير التنفيذي.' : 'أُضيف إلى مسودة الكشف.'];
    }

    /** Delivery and donor lists only compare with the records; nothing is delivered or approved from a file. */
    private function reconcileNote(object $batch, array $n): string
    {
        if (empty($n['unit_key'])) {
            return 'غير مطابق للسجل.';
        }
        if ($batch->purpose === 'donor_recipients') {
            return 'مطابق للسجل؛ مقترح بانتظار قرار القسم.';
        }
        $state = app(Campaigns::class)->entryState((int) $batch->campaign_id, $n['unit_key']);
        $listed = DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->where('l.campaign_id', $batch->campaign_id)->where('l.status', 'approved')->where('e.unit_key', $n['unit_key'])->exists();

        return match (true) {
            ! $listed => 'تعارض: غير مدرج في الكشف المعتمد.',
            $n['delivered'] && $state !== 'delivered' => 'تعارض: الملف يفيد بالتسليم ولا يوجد تسليم مسجل بدليل في النظام (لم يُسجَّل تسليم).',
            ! $n['delivered'] && $state === 'delivered' => 'تعارض: النظام يسجل تسليمًا والملف لا يفيد بذلك.',
            default => 'مطابق.',
        };
    }

    /* ---------- helpers ---------- */

    private function entity(object $batch, string $key): ?int
    {
        $id = DB::table('import_entity_keys')->where('batch_id', $batch->id)->where('entity_key', mb_substr($key, 0, 80))->value('entity_id');

        return $id ? (int) $id : null;
    }

    private function remember(object $batch, string $key, string $type, int $id): void
    {
        DB::table('import_entity_keys')->insertOrIgnore(['batch_id' => $batch->id, 'entity_key' => mb_substr($key, 0, 80), 'entity_type' => $type, 'entity_id' => $id, 'created_at' => now()]);
    }

    private function values(array $cells, array $mapping): array
    {
        $values = [];
        foreach ($mapping as $index => $field) {
            $values[$field] = isset($cells[(int) $index]) ? trim((string) $cells[(int) $index]) : '';
        }

        return $values;
    }

    private function childName(array $n): string
    {
        return ($n['child_full_name'] ?? '') !== '' ? $n['child_full_name'] : trim(implode(' ', array_filter([$n['child_first_name'] ?? '', $n['child_father_name'] ?? '', $n['child_grandfather_name'] ?? '', $n['child_family_name'] ?? ''])));
    }

    private function fillDiff(Person $person, array $n): array
    {
        $diff = [];
        foreach (['date_of_birth' => 'child_dob', 'sex' => 'child_sex'] as $field => $key) {
            $current = $field === 'date_of_birth' ? $person->date_of_birth?->toDateString() : $person->{$field};
            if ($n[$key] !== null && $current !== $n[$key]) {
                $diff[$field] = ['current' => $current, 'proposed' => $n[$key], 'applies' => $current === null];
            }
        }

        return $diff;
    }

    private function date(string $value): ?string
    {
        $value = trim(ArabicText::digits($value));
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y/m/d', 'Y/m/d H:i', 'd-m-Y', 'd-m-Y H:i', 'j/n/Y', 'j/n/Y H:i', 'm/d/Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value && (int) $date->format('Y') >= 1900) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function geography(string $governorate, string $city): array
    {
        if ($this->geo === []) {
            foreach (app(GazaGeography::class)->nodes() as $node) {
                $this->geo[$node->type_code][ArabicText::key($node->name_ar)][] = $node;
            }
        }
        $gov = ($this->geo['governorates'][ArabicText::key($governorate)] ?? [null])[0];
        $cityNode = null;
        foreach ($this->geo['cities'][ArabicText::key($city)] ?? [] as $candidate) {
            if (! $gov || (int) $candidate->parent_id === (int) $gov->id) {
                $cityNode = $candidate;
                break;
            }
        }

        return [$gov?->id, $cityNode?->id];
    }

    /** Relation as written («الأم»، «الجد»، «العمة»…) → kinship code; unknown wording stays unmapped. */
    private function kinshipCode(string $label): ?string
    {
        $words = array_map(fn ($w) => preg_replace('/^ال(?=\S{2,})/u', '', $w), explode(' ', ArabicText::key($label)));
        $key = trim(implode(' ', $words));

        return match (true) {
            $key === '' => null,
            $key === 'ام' => 'mother',
            in_array($key, ['اب', 'والد'], true) => 'father',
            $key === 'جد' => 'grandfather',
            $key === 'جده' => 'grandmother',
            in_array($key, ['عم', 'عمه'], true) => 'paternal_uncle_aunt',
            in_array($key, ['خال', 'خاله'], true) => 'maternal_uncle_aunt',
            in_array($key, ['اخ', 'اخ كبير', 'اخ اكبر'], true) => 'brother',
            in_array($key, ['اخت', 'اختهم', 'اخت كبيره', 'اخت كبري'], true) => 'sister',
            default => null,
        };
    }

    private function kinship(string $label): ?int
    {
        $code = $this->kinshipCode($label);

        return $code ? $this->kinshipId($code) : $this->valueByName('kinship_types', $label);
    }

    private function kinshipId(string $code): ?int
    {
        return $this->valueByCode('kinship_types', $code);
    }

    private function maritalId(string $label): ?int
    {
        $key = ArabicText::key($label);
        $code = match (true) {
            in_array($key, ['ارمله', 'ارمل'], true) => 'widowed',
            in_array($key, ['متزوجه', 'متزوج'], true) => 'married_status',
            in_array($key, ['مطلقه', 'مطلق'], true) => 'divorced',
            default => null,
        };

        return $code ? $this->valueByCode('marital_statuses', $code) : $this->valueByName('marital_statuses', $label);
    }

    private function educationId(string $label): ?int
    {
        $key = ArabicText::key($label);
        $code = match ($key) {
            'ابتدائي' => 'primary', 'اعدادي' => 'preparatory', 'ثانوي' => 'secondary', 'دبلوم' => 'diploma', 'بكالوريوس' => 'bachelor', 'ماجستير' => 'master', 'دكتوراه' => 'doctorate',
            'اميه', 'امي' => 'illiterate', default => null,
        };

        return $code ? $this->valueByCode('education_levels', $code) : $this->valueByName('education_levels', $label);
    }

    private function valueByCode(string $type, string $code): ?int
    {
        static $cache = [];

        return $cache[$type][$code] ??= (int) DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', $type)->where('v.code', $code)->value('v.id') ?: null;
    }

    private function valueByName(string $type, string $label): ?int
    {
        static $cache = [];
        if (trim($label) === '') {
            return null;
        }
        $cache[$type] ??= self::labelIndex($type);

        return $cache[$type][ArabicText::key($label)] ?? null;
    }

    /** Staged fields read through a reference list => [Arabic label, list type]. */
    public const LIST_FIELDS = [
        'guardian_relation' => ['صلة الوصي باليتيم', 'kinship_types'], 'mother_marital' => ['الحالة الاجتماعية للأم', 'marital_statuses'],
        'mother_education' => ['المستوى التعليمي للأم', 'education_levels'], 'health_status' => ['الحالة الصحية', 'health_statuses'],
        'father_death_cause' => ['سبب وفاة الأب', 'death_causes'], 'mother_death_cause' => ['سبب وفاة الأم', 'death_causes'],
        'school_grade' => ['الصف الدراسي', 'school_grades'], 'academic_performance' => ['المستوى التحصيلي', 'achievement_levels'],
    ];

    /**
     * Review queue of list wordings in a batch that match no value (name or alias), most frequent first. Nothing is
     * changed: the answer is kept as written, and a person resolves it by adding the wording as an alias or a new value
     * in «ثوابت النظام», after which a re-run maps it.
     *
     * @return list<array{field: string, label: string, list: string, value: string, rows: int}>
     */
    public function unmappedOptions(int $batchId, int $perField = 30): array
    {
        $out = [];
        foreach (self::LIST_FIELDS as $field => [$label, $type]) {
            $index = self::labelIndex($type);
            $values = DB::table('import_staged_rows')->where('batch_id', $batchId)->whereRaw("coalesce(trim(normalized->>'{$field}'), '') <> ''")
                ->selectRaw("normalized->>'{$field}' as v, count(*) as n")->groupBy('v')->orderByDesc('n')->limit(500)->get();
            $kept = 0;
            foreach ($values as $row) {
                $key = ArabicText::key((string) $row->v);
                $resolved = isset($index[$key]) || match ($field) {
                    'guardian_relation' => $this->kinshipCode((string) $row->v) !== null,
                    'mother_marital' => $this->maritalId((string) $row->v) !== null || in_array($key, ['متوفاه', 'متوفيه', 'متوفي'], true),
                    'mother_education' => $this->educationId((string) $row->v) !== null,
                    default => false,
                };
                if (! $resolved && $kept++ < $perField) {
                    $out[] = ['field' => $field, 'label' => $label, 'list' => $type, 'value' => mb_substr((string) $row->v, 0, 120), 'rows' => (int) $row->n];
                }
            }
        }

        return $out;
    }

    /**
     * Normalized label => value id for one list: each active value's name plus its aliases (metadata.aliases, e.g. the
     * workbook wording «جزئي بليغ»). A wording that two values both claim is ambiguous and is left out, so it stays
     * unmapped for a person to resolve.
     *
     * @return array<string, int>
     */
    public static function labelIndex(string $type): array
    {
        $values = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $type)->where('v.is_active', true)
            ->orderBy('v.id')->get(['v.id', 'v.name_ar', 'v.metadata']);
        // A value's own name always wins; aliases fill the remaining wordings.
        $names = [];
        foreach ($values as $v) {
            $names[ArabicText::key((string) $v->name_ar)] ??= (int) $v->id;
        }
        $aliases = [];
        $ambiguous = [];
        foreach ($values as $v) {
            foreach ((array) (json_decode((string) $v->metadata, true)['aliases'] ?? []) as $label) {
                $key = ArabicText::key((string) $label);
                if ($key === '' || isset($names[$key])) {
                    continue;
                }
                if (isset($aliases[$key]) && $aliases[$key] !== (int) $v->id) {
                    $ambiguous[$key] = true;
                }
                $aliases[$key] ??= (int) $v->id;
            }
        }
        unset($names['']);

        return $names + array_diff_key($aliases, $ambiguous);
    }

    private function rawValues(object $row, object $batch): array
    {
        return $this->values(json_decode(decrypt($row->original), true) ?: [], json_decode((string) $batch->mapping, true) ?: []);
    }

    /** Errors in reports never carry identity numbers or health text. */
    private function safeMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }

        return $e instanceof HttpException ? $e->getMessage() : 'تعذر تنفيذ الصف (خطأ داخلي مسجل في سجل النظام).';
    }

    private function file(object $batch): string
    {
        return Storage::disk('local')->path($batch->path);
    }

    private function assertOwnerOrHead(object $batch, User $actor): void
    {
        $this->access->assert($actor, 'import');
        abort_unless((int) $batch->uploaded_by === (int) $actor->id || $this->access->canAssign($actor), 403, 'هذه الدفعة لموظف آخر.');
    }
}
