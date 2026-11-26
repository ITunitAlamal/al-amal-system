<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\Import\SourceFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The finalized monthly payroll recorded from the external accounting software (owner decision 2026-11-01: the
 * books stay in «القلعة الأصيل»; AIMS records the authorised result to issue payslips, never pays or posts).
 *
 * record (finance.payroll.record): upload the approved sheet, map its columns, validate every line
 *   (employee matched, earnings − deductions = net) → run «بانتظار المراجعة».
 * review (finance.payroll.publish, never the recorder) → executive director's approval (finance.payroll.authorize,
 *   neither the recorder nor the reviewer; owner decision 2026-11-02) → publish (finance.payroll.publish, neither the
 *   recorder nor the approver) → payslips issued, waiting requests fulfilled. Before approval the reviewer or the
 *   executive director may return it with a reason. A published run is final (database trigger).
 */
final class FinancePayrollRuns
{
    public const MAX_ROWS = 5000;

    private const DISK_DIR = 'finance-payroll';

    public const STATES = ['pending_approval' => 'بانتظار المراجعة', 'pending_executive' => 'بانتظار اعتماد المدير التنفيذي', 'approved' => 'معتمد، بانتظار النشر',
        'published' => 'منشور', 'returned' => 'مُعاد للتصحيح', 'draft' => 'مسودة'];

    public function __construct(
        private readonly FinanceGuard $guard,
        private readonly NotificationCenter $notifications,
        private readonly FinancePayslips $payslips,
    ) {}

    /** Stores the uploaded sheet privately and returns its token, sheets and header suggestions. @return array<string, mixed> */
    public function upload(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $format = $extension === 'csv' ? 'csv' : 'xlsx';
        $token = hash_file('sha256', $file->getRealPath());
        $name = $token.'.'.$format;
        if (! Storage::disk('local')->exists(self::DISK_DIR.'/'.$name)) {
            Storage::disk('local')->putFileAs(self::DISK_DIR, $file, $name);
        }

        return ['token' => $token, 'format' => $format, 'fileName' => mb_substr($file->getClientOriginalName(), 0, 255),
            'sheets' => $format === 'xlsx' ? SourceFile::sheets($this->path($token, $format)) : []] + $this->inspect($token, $format, null);
    }

    /** Header row and column suggestions of one sheet. @return array{headerRow: int, columns: list<array<string, mixed>>} */
    public function inspect(string $token, string $format, ?string $sheet): array
    {
        $path = $this->path($token, $format);
        $sheet ??= $format === 'xlsx' ? (SourceFile::sheets($path)[0] ?? null) : null;
        foreach ($this->rows($path, $format, $sheet) as $number => $cells) {
            if (count(array_filter($cells, fn ($v): bool => trim((string) $v) !== '')) >= 3) {
                $columns = [];
                foreach ($cells as $index => $label) {
                    $label = trim((string) $label);
                    if ($label !== '') {
                        $columns[] = ['index' => $index, 'label' => mb_substr($label, 0, 80), 'suggest' => $this->suggest($label)];
                    }
                }

                return ['sheet' => $sheet, 'headerRow' => $number, 'columns' => $columns];
            }
            if ($number > 15) {
                break;
            }
        }
        throw ValidationException::withMessages(['file' => 'لم يُعثر على صف عناوين في أول 15 صفًا من الورقة.']);
    }

    /** key | net | earning | deduction | ignore, from the column title. */
    private function suggest(string $label): string
    {
        $l = mb_strtolower($label);

        return match (true) {
            (bool) preg_match('/الرقم الوظيفي|رقم الموظف|employee.?(no|number|id)|staff/u', $l) => 'key',
            (bool) preg_match('/صافي|net/u', $l) => 'net',
            (bool) preg_match('/خصم|خصومات|استقطاع|ضريبة|تأمين|سلفة|قرض|غياب|جزاء|deduct|tax|loan/u', $l) => 'deduction',
            (bool) preg_match('/اسم|name|هوية|بنك|حساب|iban|قسم|وظيفة|ملاحظ|تاريخ|رقم|#|م$/u', $l) => 'ignore',
            (bool) preg_match('/راتب|أساسي|علاوة|بدل|إضافي|مكافأة|حافز|إجمالي|salary|basic|allowance|bonus|overtime|gross/u', $l) => 'earning',
            default => 'ignore',
        };
    }

    /**
     * Reads and validates every line with the mapping. Nothing is written.
     *
     * @param  array{token: string, format: string, sheet: ?string, header_row: int, key_type: string, key: int, net: int, earnings: list<int>, deductions: list<int>, labels: array<int|string, string>}  $m
     * @return array{lines: list<array<string, mixed>>, errors: list<array{row: int, message: string}>, totals: array<string, string>, count: int}
     */
    public function analyze(array $m): array
    {
        if ($m['earnings'] === []) {
            throw ValidationException::withMessages(['earnings' => 'حدد عمود استحقاق واحدًا على الأقل.']);
        }
        if (array_intersect($m['earnings'], $m['deductions']) !== [] || in_array($m['net'], [...$m['earnings'], ...$m['deductions'], $m['key']], true)) {
            throw ValidationException::withMessages(['mapping' => 'لا يُستخدم العمود نفسه لأكثر من غرض.']);
        }
        $employees = DB::table('employees')->whereNull('deleted_at')->get(['id', $m['key_type'] === 'national_id' ? 'national_id as k' : 'employee_number as k'])
            ->filter(fn (object $e): bool => trim((string) $e->k) !== '')->mapWithKeys(fn (object $e): array => [$this->normalizeKey((string) $e->k) => (int) $e->id]);
        $lines = [];
        $errors = [];
        $seen = [];
        $gross = $deductions = $net = 0;
        $label = fn (int $index): string => mb_substr(trim((string) ($m['labels'][$index] ?? 'عمود '.$index)), 0, 80);
        foreach ($this->rows($this->path($m['token'], $m['format']), $m['format'], $m['sheet']) as $number => $cells) {
            if ($number <= $m['header_row']) {
                continue;
            }
            $key = $this->normalizeKey((string) ($cells[$m['key']] ?? ''));
            $values = array_filter($cells, fn ($v): bool => trim((string) $v) !== '');
            if ($values === []) {
                continue;
            }
            if ($key === '') {
                // Totals and signature lines at the bottom of the sheet carry no employee key.
                continue;
            }
            if (count($lines) + count($errors) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'الملف يتجاوز '.self::MAX_ROWS.' سطر رواتب.']);
            }
            $fail = function (string $message) use (&$errors, $number): void {
                $errors[] = ['row' => $number, 'message' => $message];
            };
            $employeeId = $employees[$key] ?? null;
            if (! $employeeId) {
                $fail('لا يوجد موظف بهذا '.($m['key_type'] === 'national_id' ? 'الرقم الوطني' : 'الرقم الوظيفي').' في ملفات الموظفين.');

                continue;
            }
            if (isset($seen[$employeeId])) {
                $fail('الموظف مكرر في الملف (السطر '.$seen[$employeeId].').');

                continue;
            }
            $seen[$employeeId] = $number;
            try {
                $earn = $ded = [];
                foreach ($m['earnings'] as $index) {
                    if (($c = $this->money($cells[$index] ?? '')) !== 0) {
                        $earn[] = ['label' => $label($index), 'amount' => FinanceMoney::format($c), 'cents' => $c];
                    }
                }
                foreach ($m['deductions'] as $index) {
                    if (($c = $this->money($cells[$index] ?? '')) !== 0) {
                        $ded[] = ['label' => $label($index), 'amount' => FinanceMoney::format($c), 'cents' => $c];
                    }
                }
                $lineNet = $this->money($cells[$m['net']] ?? '');
            } catch (Throwable) {
                $fail('قيمة مالية غير مقروءة.');

                continue;
            }
            $g = array_sum(array_column($earn, 'cents'));
            $d = array_sum(array_column($ded, 'cents'));
            if (array_filter([...$earn, ...$ded], fn (array $x): bool => $x['cents'] < 0) !== [] || $lineNet < 0) {
                $fail('قيمة سالبة: سجّل الخصم في أعمدة الخصومات.');

                continue;
            }
            if ($g - $d !== $lineNet) {
                $fail('الصافي في الملف ('.FinanceMoney::format($lineNet).') لا يساوي الاستحقاقات ناقص الخصومات ('.FinanceMoney::format($g - $d).').');

                continue;
            }
            $lines[] = ['row' => $number, 'employee_id' => $employeeId, 'earnings' => array_map(fn (array $x): array => ['label' => $x['label'], 'amount' => $x['amount']], $earn),
                'deductions' => array_map(fn (array $x): array => ['label' => $x['label'], 'amount' => $x['amount']], $ded),
                'gross' => FinanceMoney::format($g), 'deduction' => FinanceMoney::format($d), 'net' => FinanceMoney::format($lineNet)];
            $gross += $g;
            $deductions += $d;
            $net += $lineNet;
        }

        return ['lines' => $lines, 'errors' => $errors, 'count' => count($lines),
            'totals' => ['gross' => FinanceMoney::format($gross), 'deductions' => FinanceMoney::format($deductions), 'net' => FinanceMoney::format($net)]];
    }

    /**
     * Records the run after a full server-side re-check. Every line must be valid: a payroll is recorded whole.
     *
     * @param  array<string, mixed>  $m  analyze() mapping plus period, currency, source_reference, file_name
     */
    public function record(User $actor, array $m): int
    {
        $this->guard->assert($actor, 'finance.payroll.record');
        $result = $this->analyze($m);
        if ($result['errors'] !== [] || $result['count'] === 0) {
            throw ValidationException::withMessages(['file' => $result['count'] === 0 ? 'لا توجد أسطر رواتب صالحة في الملف.'
                : 'في الملف '.count($result['errors']).' سطرًا غير صالح؛ صححها في البرنامج المحاسبي وأعد الرفع. لا يُسجَّل مسير ناقص.']);
        }

        return DB::transaction(function () use ($actor, $m, $result): int {
            if (DB::table('finance_payroll_batches')->where('period', $m['period'])->where('currency', $m['currency'])->where('state', '!=', 'returned')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['period' => 'يوجد مسير لهذا الشهر وهذه العملة. التصحيح بعد النشر يكون في مسير لاحق.']);
            }
            $id = DB::table('finance_payroll_batches')->insertGetId([
                'period' => $m['period'], 'currency' => $m['currency'], 'state' => 'pending_approval', 'source' => 'external',
                'total' => $result['totals']['net'], 'gross_total' => $result['totals']['gross'], 'deductions_total' => $result['totals']['deductions'],
                'employee_count' => $result['count'], 'hr_approval_reference' => $m['source_reference'], 'source_reference' => $m['source_reference'],
                'source_file_name' => $m['file_name'] ?? null, 'source_file_hash' => $m['token'], 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($result['lines'], 500) as $chunk) {
                DB::table('finance_payroll_items')->insert(array_map(fn (array $line): array => [
                    'batch_id' => $id, 'employee_id' => $line['employee_id'], 'gross' => $line['gross'], 'deductions' => $line['deduction'], 'net' => $line['net'],
                    'earnings' => json_encode($line['earnings'], JSON_UNESCAPED_UNICODE), 'deduction_lines' => json_encode($line['deductions'], JSON_UNESCAPED_UNICODE),
                    'source_row' => $line['row'], 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            // Amounts stay in the payroll tables; the audit keeps counts and the source reference only.
            $this->guard->audit('payroll', $id, (int) $actor->id, 'record_external', ['period' => $m['period'], 'currency' => $m['currency'],
                'employees' => $result['count'], 'source_reference' => $m['source_reference'], 'file_hash' => substr($m['token'], 0, 16)]);
            $reviewers = $this->holders('finance.payroll.publish')->reject(fn (int $uid): bool => $uid === (int) $actor->id);
            $this->notifications->notifyMany($reviewers, 'finance_payroll_review', [
                'title' => 'مسير رواتب '.$m['period'].' بانتظار المراجعة', 'url' => route('finance.payroll-runs.index', ['run' => $id], false), 'dedupe' => 'payroll-review:'.$id,
            ]);

            return $id;
        });
    }

    /** Step 2 — independent review (finance.payroll.publish, never the recorder): sends the run to the executive director. */
    public function review(User $actor, int $id): void
    {
        $this->guard->assert($actor, 'finance.payroll.publish');
        $this->assertNotRecorder($actor, $id, 'مراجعة المسير');
        DB::transaction(function () use ($actor, $id): void {
            $batch = $this->locked($id, 'pending_approval', 'المسير ليس بانتظار المراجعة.');
            DB::table('finance_payroll_batches')->where('id', $id)->update(['state' => 'pending_executive', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            $this->guard->audit('payroll', $id, (int) $actor->id, 'review', ['period' => $batch->period]);
            $this->notifications->notifyMany($this->holders('finance.payroll.authorize')->reject(fn (int $uid): bool => in_array($uid, [(int) $actor->id, (int) $batch->created_by], true)),
                'finance_payroll_review', ['title' => 'مسير رواتب '.$batch->period.' بانتظار اعتماد المدير التنفيذي', 'url' => route('finance.approvals', [], false), 'dedupe' => 'payroll-authorize:'.$id]);
        });
    }

    /** Step 3 — the executive director's approval (finance.payroll.authorize), never the recorder or the reviewer. */
    public function authorize(User $actor, int $id): void
    {
        $this->guard->assert($actor, 'finance.payroll.authorize');
        DB::transaction(function () use ($actor, $id): void {
            $batch = $this->locked($id, 'pending_executive', 'المسير ليس بانتظار اعتماد المدير التنفيذي.');
            app(FinanceSod::class)->assertDistinct((int) $actor->id, [(int) $batch->created_by => 'إدخال المسير', (int) $batch->reviewed_by => 'مراجعة المسير'], 'اعتماد المسير', 'payroll', $id);
            DB::table('finance_payroll_batches')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->guard->audit('payroll', $id, (int) $actor->id, 'authorize', ['period' => $batch->period]);
            $this->notifications->notifyMany($this->holders('finance.payroll.publish')->reject(fn (int $uid): bool => $uid === (int) $batch->created_by),
                'finance_payroll_review', ['title' => 'اعتُمد مسير '.$batch->period.'؛ جاهز للنشر', 'url' => route('finance.payroll-runs.index', ['run' => $id], false), 'dedupe' => 'payroll-publish:'.$id]);
        });
    }

    /** Step 4 — publication after the executive director's approval (finance.payroll.publish, never the recorder or the approver). */
    public function publish(User $actor, int $id): void
    {
        $this->guard->assert($actor, 'finance.payroll.publish');
        $this->assertNotRecorder($actor, $id, 'نشر المسير');
        DB::transaction(function () use ($actor, $id): void {
            $batch = $this->locked($id, 'approved', 'لا يُنشر المسير قبل مراجعته واعتماد المدير التنفيذي.');
            app(FinanceSod::class)->assertDistinct((int) $actor->id, [(int) $batch->created_by => 'إدخال المسير', (int) $batch->approved_by => 'اعتماد المسير'], 'نشر المسير', 'payroll', $id);
            DB::table('finance_payroll_batches')->where('id', $id)->update(['state' => 'published', 'published_by' => $actor->id, 'published_at' => now(), 'updated_at' => now()]);
            $issued = $this->payslips->issueFor((int) $id);
            $this->guard->audit('payroll', $id, (int) $actor->id, 'publish', ['period' => $batch->period, 'payslips' => $issued]);
            $this->payslips->fulfilWaiting($batch->period);
        });
    }

    /** Return for correction with a reason: by the reviewer before review, or by the executive director before approval. */
    public function return(User $actor, int $id, string $reason): void
    {
        $state = DB::table('finance_payroll_batches')->where('id', $id)->value('state');
        $this->guard->assert($actor, $state === 'pending_executive' ? 'finance.payroll.authorize' : 'finance.payroll.publish');
        $this->assertNotRecorder($actor, $id, 'إعادة المسير');
        DB::transaction(function () use ($actor, $id, $reason): void {
            $batch = DB::table('finance_payroll_batches')->lockForUpdate()->find($id);
            abort_unless($batch && $batch->source === 'external', 404);
            if (! in_array($batch->state, ['pending_approval', 'pending_executive'], true)) {
                throw ValidationException::withMessages(['run' => 'لا يُعاد المسير إلا قبل اعتماده.']);
            }
            DB::table('finance_payroll_batches')->where('id', $id)->update(['state' => 'returned', 'returned_by' => $actor->id, 'returned_reason' => trim($reason), 'updated_at' => now()]);
            $this->guard->audit('payroll', $id, (int) $actor->id, 'return', ['reason' => trim($reason), 'stage' => $batch->state]);
            $this->notifications->notify((int) $batch->created_by, 'finance_payroll_review', [
                'title' => 'أُعيد مسير '.$batch->period.' للتصحيح', 'body' => trim($reason), 'url' => route('finance.payroll-runs.index', ['run' => $id], false), 'dedupe' => 'payroll-returned:'.$id,
            ]);
        });
    }

    private function locked(int $id, string $state, string $message): object
    {
        $batch = DB::table('finance_payroll_batches')->lockForUpdate()->find($id);
        abort_unless($batch && $batch->source === 'external', 404);
        if ($batch->state !== $state) {
            throw ValidationException::withMessages(['run' => $message]);
        }

        return $batch;
    }

    /**
     * Active users FinanceGuard allows a permission today (candidates: finance staff and per-user grants).
     *
     * @return Collection<int, int>
     */
    public function holders(string $permission)
    {
        $candidates = DB::table('finance_staff_assignments')->where('is_active', true)->pluck('user_id')
            ->merge(DB::table('finance_user_permissions')->where('permission_code', $permission)->where('effect', 'grant')->pluck('user_id'))
            ->merge(DB::table('finance_board_approvers')->where('is_active', true)->pluck('user_id'))
            ->map(fn ($id): int => (int) $id)->unique();

        return User::whereIn('id', $candidates)->where('is_active', true)->get()
            ->filter(fn (User $user): bool => (new FinanceGuard)->allows($user, $permission))->pluck('id')->map(fn ($id): int => (int) $id)->values();
    }

    /** The recorder of a run never reviews it: refused and audited before any change. */
    private function assertNotRecorder(User $actor, int $id, string $step): void
    {
        $recorder = (int) DB::table('finance_payroll_batches')->where('id', $id)->value('created_by');
        app(FinanceSod::class)->assertDistinct((int) $actor->id, [$recorder => 'إدخال المسير'], $step, 'payroll', $id);
    }

    private function path(string $token, string $format): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token) === 1 && in_array($format, ['xlsx', 'csv'], true), 404);
        $relative = self::DISK_DIR.'/'.$token.'.'.$format;
        if (! Storage::disk('local')->exists($relative)) {
            throw ValidationException::withMessages(['file' => 'انتهت صلاحية الملف المرفوع؛ ارفعه من جديد.']);
        }

        return Storage::disk('local')->path($relative);
    }

    /** @return iterable<int, array<int, string>> */
    private function rows(string $path, string $format, ?string $sheet): iterable
    {
        try {
            yield from SourceFile::rows($path, $format, $sheet, $format === 'csv' ? 'UTF-8' : null);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }

    private function normalizeKey(string $value): string
    {
        $value = strtr(trim($value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return preg_replace('/\s+|\.0+$/u', '', $value) ?? '';
    }

    /** Sheet amounts ("1,250.5", Arabic digits, blanks) in cents; anything else is refused. */
    private function money(mixed $value): int
    {
        $text = strtr(trim((string) $value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '', ',' => '', ' ' => '']);
        if ($text === '' || $text === '-') {
            return 0;
        }
        if (! preg_match('/^(-?)(\d{1,12})(?:\.(\d+))?$/', $text, $m)) {
            throw new \InvalidArgumentException('not money');
        }
        // Exact decimal arithmetic: whole units, two decimals, half-up on the third.
        $decimals = str_pad($m[3] ?? '', 3, '0');
        $cents = (int) $m[2] * 100 + (int) substr($decimals, 0, 2) + ((int) $decimals[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$cents : $cents;
    }
}
