<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payslips come only from a published payroll run (FinancePayrollRuns::publish): one slip per payroll line, with a
 * unique reference. «طلب قسيمة راتب» in «طلباتي» identifies the employee from the signed-in account's employee file,
 * never from the form; issuing the employee's copy is immediate and requires no approval or staff notification.
 * Missing monthly data returns a validation error. A repeated issue for the same month reuses the first one.
 * Viewing is owner-only (plus payroll staff),
 * rendered on demand with no stored file or public link, and every view is audited without amounts.
 */
final class FinancePayslips
{
    public const STATES = [
        'fulfilled' => ['القسيمة جاهزة', 'success'],
        'awaiting_payroll' => ['بانتظار نشر مسير الشهر', 'warning'],
        'not_included' => ['لا يوجد لك سطر في مسير الشهر المنشور', 'danger'],
        'no_employee_record' => ['حسابك غير مرتبط بملف موظف', 'danger'],
    ];

    public function __construct(
        private readonly FinanceGuard $guard,
        private readonly NotificationCenter $notifications,
    ) {}

    public function employeeOf(User $user): ?object
    {
        return DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->orderBy('id')->first(['id', 'employee_number', 'department_id']);
    }

    /** Months for which this employee can obtain a salary slip immediately. @return list<string> */
    public function availablePeriods(User $user): array
    {
        if (! $user->is_active || ! ($employee = $this->employeeOf($user))) {
            return [];
        }

        return DB::table('finance_payslips as s')->join('finance_payroll_batches as b', 'b.id', '=', 's.batch_id')
            ->where('s.employee_id', $employee->id)->where('b.state', 'published')->where('s.period', '<=', now()->format('Y-m'))
            ->distinct()->orderByDesc('s.period')->pluck('s.period')->all();
    }

    /** Issues one slip per line of a run being published. Returns the number issued. */
    public function issueFor(int $batchId): int
    {
        $batch = DB::table('finance_payroll_batches')->find($batchId);
        $rows = DB::table('finance_payroll_items as i')->join('employees as e', 'e.id', '=', 'i.employee_id')
            ->leftJoin('finance_payslips as s', 's.payroll_item_id', '=', 'i.id')->where('i.batch_id', $batchId)->whereNull('s.id')
            ->get(['i.id', 'i.employee_id', 'e.user_id']);
        $period = str_replace('-', '', $batch->period);
        foreach ($rows->chunk(500) as $chunk) {
            DB::table('finance_payslips')->insert($chunk->map(fn (object $r): array => [
                'reference' => 'PS-'.$period.'-'.$batch->currency.'-'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT),
                'payroll_item_id' => $r->id, 'batch_id' => $batchId, 'employee_id' => $r->employee_id, 'user_id' => $r->user_id,
                'period' => $batch->period, 'currency' => $batch->currency, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ])->all());
        }

        return $rows->count();
    }

    /** Issue the signed-in employee's copy immediately, without an approval task. */
    public function request(User $user, string $period, ?string $note): object
    {
        abort_unless($user->is_active, 403);
        if ($period > now()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'لا تُطلب قسيمة شهر لم يأتِ بعد.']);
        }
        $employee = $this->employeeOf($user);

        return DB::transaction(function () use ($user, $period, $note, $employee): object {
            [$state, $slip] = $this->resolve($employee, $period);
            if (! $slip) {
                throw ValidationException::withMessages(['period' => $state === 'no_employee_record'
                    ? 'حسابك غير مرتبط بملف موظف. راجع الموارد البشرية لربطه بملفك الوظيفي.'
                    : 'لا تتوفر بيانات راتب لك لهذا الشهر. اختر شهرًا من الأشهر المتاحة لإصدار قسيمتك مباشرة.']);
            }
            $existing = DB::table('finance_payslip_requests')->where('user_id', $user->id)->where('period', $period)->lockForUpdate()->first();
            if ($existing) {
                // Repeated issue: reuse the same record and slip.
                $id = (int) $existing->id;
                DB::table('finance_payslip_requests')->where('id', $existing->id)->update(['state' => $state, 'payslip_id' => $slip->id,
                    'employee_id' => $employee->id, 'fulfilled_at' => $existing->fulfilled_at ?? now(), 'updated_at' => now()]);
            } else {
                $id = DB::table('finance_payslip_requests')->insertGetId(['reference' => 'PSR-pending-'.bin2hex(random_bytes(6)), 'user_id' => $user->id,
                    'employee_id' => $employee->id, 'period' => $period, 'note' => $note ? mb_substr(trim($note), 0, 1000) : null, 'state' => $state,
                    'payslip_id' => $slip->id, 'fulfilled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('finance_payslip_requests')->where('id', $id)->update(['reference' => 'PSR-'.now()->format('Y').'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
            }
            $this->guard->audit('payslip_request', $id, (int) $user->id, $existing ? 'repeated' : 'create', ['period' => $period, 'state' => $state]);
            $result = DB::table('finance_payslip_requests')->find($id);
            $result->slip_reference = $slip->reference;

            return $result;
        });
    }

    /** @return array{0: string, 1: ?object} state and the slip */
    private function resolve(?object $employee, string $period): array
    {
        if (! $employee) {
            return ['no_employee_record', null];
        }
        $slip = DB::table('finance_payslips as s')->join('finance_payroll_batches as b', 'b.id', '=', 's.batch_id')
            ->where('s.employee_id', $employee->id)->where('s.period', $period)->where('b.state', 'published')->orderBy('s.id')->first(['s.*']);
        if ($slip) {
            return ['fulfilled', $slip];
        }
        $published = DB::table('finance_payroll_batches')->where('period', $period)->where('state', 'published')->exists();

        return [$published ? 'not_included' : 'awaiting_payroll', null];
    }

    /** Complete legacy waiting requests after publication; new employee issues never enter this queue. */
    public function fulfilWaiting(string $period): void
    {
        foreach (DB::table('finance_payslip_requests')->where('period', $period)->whereIn('state', ['awaiting_payroll', 'not_included'])->lockForUpdate()->get() as $request) {
            $employee = $request->employee_id ? DB::table('employees')->where('id', $request->employee_id)
                ->where('user_id', $request->user_id)->whereNull('deleted_at')->first(['id']) : null;
            [$state, $slip] = $this->resolve($employee, $period);
            DB::table('finance_payslip_requests')->where('id', $request->id)->update(['state' => $state, 'payslip_id' => $slip?->id,
                'fulfilled_at' => $slip ? now() : null, 'updated_at' => now()]);
            if ($slip) {
                $this->notifications->notify((int) $request->user_id, 'finance_payslip_ready', [
                    'title' => 'قسيمة راتب شهر '.$period.' جاهزة', 'url' => route('payslips.show', $slip->reference, false), 'dedupe' => 'payslip-ready:'.$request->id,
                ]);
            }
        }
    }

    /** Requests of one employee, newest first. @return Collection<int, object> */
    public function requestsOf(User $user): Collection
    {
        $employee = $this->employeeOf($user);

        return DB::table('finance_payslip_requests as r')->leftJoin('finance_payslips as s', function (JoinClause $join) use ($employee): void {
            $join->on('s.id', '=', 'r.payslip_id')->where('s.employee_id', $employee?->id ?? 0)
                ->whereExists(fn ($batch) => $batch->selectRaw('1')->from('finance_payroll_batches as b')
                    ->whereColumn('b.id', 's.batch_id')->where('b.state', 'published'));
        })
            ->where('r.user_id', $user->id)->orderByDesc('r.id')->limit(100)
            ->get(['r.id', 'r.reference', 'r.period', 'r.state', 'r.created_at', 'r.fulfilled_at', 's.reference as slip']);
    }

    /** May this user see the slip? The owner, or payroll staff handling slips. */
    public function canView(User $user, object $slip): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $owner = $this->owns($user, $slip);

        return $owner || $this->guard->allows($user, 'finance.payroll.record') || $this->guard->allows($user, 'finance.payroll.publish');
    }

    private function owns(User $user, object $slip): bool
    {
        // The current employee/account link is authoritative; a historical recipient cannot retain salary access.
        return DB::table('employees')->where('id', $slip->employee_id)->where('user_id', $user->id)->whereNull('deleted_at')->exists();
    }

    /** A published slip for display, after authorisation; the view is counted and audited. @return array<string, mixed> */
    public function open(User $user, string $reference): array
    {
        $slip = DB::table('finance_payslips as s')->join('finance_payroll_batches as b', 'b.id', '=', 's.batch_id')
            ->where('s.reference', $reference)->where('b.state', 'published')->first(['s.*']);
        abort_unless($slip, 404, 'القسيمة غير موجودة.');
        $own = $this->owns($user, $slip);
        if (! $this->canView($user, $slip)) {
            app(AuditService::class)->event('finance.payslip.view_denied', ['subject_type' => 'finance_payslips', 'subject_id' => $slip->id,
                'subject_ref' => $reference, 'outcome' => 'denied', 'severity' => 'warning', 'sensitivity' => 'personal']);
            abort(403, 'لا يطّلع على القسيمة إلا صاحبها ومسؤول الرواتب.');
        }
        $item = DB::table('finance_payroll_items')->find($slip->payroll_item_id);
        $employee = DB::table('employees as e')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')->leftJoin('job_titles as j', 'j.id', '=', 'e.job_title_id')
            ->where('e.id', $slip->employee_id)->first(['e.employee_number', 'e.full_name_ar', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'd.name as department', 'j.name_ar as job_title']);
        DB::table('finance_payslips')->where('id', $slip->id)->update(['view_count' => DB::raw('view_count + 1'),
            'first_viewed_at' => $slip->first_viewed_at ?? now(), 'last_viewed_at' => now()]);
        app(AuditService::class)->event('finance.payslip.viewed', ['subject_type' => 'finance_payslips', 'subject_id' => $slip->id,
            'subject_ref' => $reference, 'sensitivity' => 'personal', 'metadata' => ['own' => $own, 'period' => $slip->period]]);
        $name = $employee->full_name_ar ?: trim(implode(' ', array_filter([$employee->first_name_ar, $employee->father_name_ar, $employee->family_name_ar])));
        $batch = DB::table('finance_payroll_batches')->find($slip->batch_id);

        return [
            'reference' => $slip->reference, 'period' => $slip->period, 'currency' => $slip->currency,
            'employee' => ['name' => $name, 'number' => $employee->employee_number, 'department' => $employee->department, 'jobTitle' => $employee->job_title],
            'earnings' => json_decode((string) $item->earnings, true) ?: [['label' => 'إجمالي الاستحقاقات', 'amount' => $item->gross]],
            'deductions' => json_decode((string) $item->deduction_lines, true) ?: ((float) $item->deductions > 0 ? [['label' => 'إجمالي الخصومات', 'amount' => $item->deductions]] : []),
            'gross' => $item->gross, 'totalDeductions' => $item->deductions, 'net' => $item->net,
            'publishedAt' => (string) $slip->published_at, 'source' => $batch->source === 'external' ? $this->guard->externalSystemName() : 'AIMS',
            'sourceReference' => $batch->source_reference, 'own' => $own, 'issuedAt' => now()->format('Y-m-d H:i'),
        ];
    }
}
