<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pay and bank records of an employee. Imported figures are reference data («baseline»); a change is a request
 * from HR with its reference document, approved by the executive director on the workflow engine
 * (EMPLOYEE_PAY_CHANGE). On approval the current record is closed the day before the new one takes effect, so
 * history is never overwritten. None of this is a payroll payment or an accounting entry.
 */
final class EmployeePayChanges
{
    public const STATUS_LABELS = ['in_review' => 'بانتظار قرار المدير التنفيذي', 'returned' => 'مُعاد للموارد البشرية', 'approved' => 'معتمد',
        'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب'];

    public function __construct(private readonly EmployeeAccess $access, private readonly WorkflowEngine $engine) {}

    public function currentCompensation(int $employeeId, ?string $on = null): ?object
    {
        $date = $on ?? now()->toDateString();

        return DB::table('employee_compensations')->where('employee_id', $employeeId)->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
    }

    public function currentBank(int $employeeId, ?string $on = null): ?object
    {
        $date = $on ?? now()->toDateString();

        return DB::table('employee_bank_accounts as b')->leftJoin('master_data_values as v', 'v.id', '=', 'b.bank_value_id')
            ->where('b.employee_id', $employeeId)->where('b.effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('b.effective_to')->orWhere('b.effective_to', '>=', $date))->orderByDesc('b.effective_from')
            ->first(['b.*', 'v.name_ar as bank_name']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function propose(User $actor, Employee $employee, array $data, ?string $ip): int
    {
        abort_unless($this->access->canRequestPayChange($actor), 403, 'طلب تعديل الراتب أو الحساب البنكي لرئيس الموارد البشرية.');
        $row = $this->row($employee, $data);

        return DB::transaction(function () use ($actor, $employee, $row, $ip): int {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            if (DB::table('employee_pay_changes')->where('employee_id', $employee->id)->whereIn('status', ['in_review', 'returned'])->exists()) {
                throw ValidationException::withMessages(['pay' => 'يوجد طلب تعديل مفتوح لهذا الموظف؛ أكمله أو اسحبه أولًا.']);
            }
            $id = (int) DB::table('employee_pay_changes')->insertGetId([...$row, 'employee_id' => $employee->id, 'status' => 'in_review',
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->log($actor, 'employee_pay_change.requested', $id, ['employee_id' => $employee->id, 'pay' => $row['changes_pay'], 'bank' => $row['changes_bank'],
                'effective_from' => $row['effective_from']], $ip);
            $department = (int) ($actor->department_id ?: DB::table('departments')->where('code', 'HR')->value('id'));
            $instance = $this->engine->startForSubject($actor, 'EMPLOYEE_PAY_CHANGE', EmployeePayChangeWorkflow::SUBJECT_TYPE, $id, $department);
            DB::table('employee_pay_changes')->where('id', $id)->update(['workflow_instance_id' => $instance->id, 'updated_at' => now()]);

            return $id;
        });
    }

    /**
     * A returned request is corrected by its requester, then resubmitted from the request page.
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(User $actor, int $changeId, array $data, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $changeId, $data, $ip): void {
            $change = DB::table('employee_pay_changes')->where('id', $changeId)->lockForUpdate()->first();
            abort_unless($change, 404);
            abort_unless($change->status === 'returned' && (int) $change->requested_by === (int) $actor->id && $this->access->canRequestPayChange($actor), 403);
            $employee = Employee::query()->findOrFail($change->employee_id);
            DB::table('employee_pay_changes')->where('id', $changeId)->update([...$this->row($employee, $data), 'updated_at' => now()]);
            $this->log($actor, 'employee_pay_change.revised', $changeId, ['employee_id' => $employee->id], $ip);
        });
    }

    /**
     * The requester withdraws an open request; the workflow closes as cancelled with a timeline entry.
     */
    public function withdraw(User $actor, int $changeId, string $reason, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $changeId, $reason, $ip): void {
            $change = DB::table('employee_pay_changes')->where('id', $changeId)->lockForUpdate()->first();
            abort_unless($change, 404);
            abort_unless(in_array($change->status, ['in_review', 'returned'], true) && (int) $change->requested_by === (int) $actor->id, 403, 'السحب لمقدم الطلب ما دام مفتوحًا.');
            if ($change->workflow_instance_id) {
                $this->engine->cancel((int) $change->workflow_instance_id, $actor, $reason);
            }
            DB::table('employee_pay_changes')->where('id', $changeId)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            $this->log($actor, 'employee_pay_change.withdraw', $changeId, ['employee_id' => $change->employee_id, 'reason' => $reason], $ip);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function row(Employee $employee, array $data): array
    {
        $pay = (bool) ($data['changes_pay'] ?? false);
        $bank = (bool) ($data['changes_bank'] ?? false);
        if (! $pay && ! $bank) {
            throw ValidationException::withMessages(['changes_pay' => 'اختر تعديل الراتب أو الحساب البنكي أو كليهما.']);
        }
        $from = Carbon::parse($data['effective_from'])->toDateString();
        foreach ([$pay ? $this->latest('employee_compensations', $employee->id) : null, $bank ? $this->latest('employee_bank_accounts', $employee->id) : null] as $latest) {
            if ($latest && $latest->effective_from >= $from) {
                throw ValidationException::withMessages(['effective_from' => 'تاريخ السريان يجب أن يلي بداية السجل الحالي ('.$latest->effective_from.').']);
            }
        }
        if ($pay && empty($data['currency_code'])) {
            throw ValidationException::withMessages(['currency_code' => 'حدد عملة الراتب.']);
        }
        if ($pay && collect(['base_salary', 'spouse_allowance', 'children_allowance', 'cost_of_living_rate', 'nature_of_work_rate'])->every(fn (string $key): bool => ! isset($data[$key]) || $data[$key] === '')) {
            throw ValidationException::withMessages(['base_salary' => 'أدخل قيمة واحدة على الأقل من الراتب أو العلاوات.']);
        }
        $account = $bank ? strtoupper((string) preg_replace('/[\s\-]/', '', (string) ($data['account_number'] ?? ''))) : '';
        if ($bank && ! preg_match('/^[A-Z0-9]{4,34}$/D', $account)) {
            throw ValidationException::withMessages(['account_number' => 'رقم الحساب البنكي من 4 إلى 34 حرفًا ورقمًا دون مسافات.']);
        }

        return [
            'effective_from' => $from, 'changes_pay' => $pay, 'changes_bank' => $bank,
            'currency_code' => $pay ? $data['currency_code'] : null,
            'base_salary' => $pay ? ($data['base_salary'] ?? null) : null, 'spouse_allowance' => $pay ? ($data['spouse_allowance'] ?? null) : null,
            'children_allowance' => $pay ? ($data['children_allowance'] ?? null) : null, 'cost_of_living_rate' => $pay ? ($data['cost_of_living_rate'] ?? null) : null,
            'nature_of_work_rate' => $pay ? ($data['nature_of_work_rate'] ?? null) : null,
            'bank_value_id' => $bank ? ($data['bank_value_id'] ?? null) : null,
            'account_number' => $bank ? Crypt::encryptString($account) : null,
            'account_hash' => $bank ? hash_hmac('sha256', $account, (string) config('app.key')) : null,
            'account_last4' => $bank ? substr($account, -4) : null,
            'reason' => trim((string) $data['reason']), 'reference' => trim((string) $data['reference']),
        ];
    }

    private function latest(string $table, int $employeeId): ?object
    {
        return DB::table($table)->where('employee_id', $employeeId)->orderByDesc('effective_from')->first();
    }

    /**
     * Applies an approved request: closes the current records the day before and adds the approved ones.
     */
    public function apply(object $change, int $actorId): void
    {
        $from = Carbon::parse($change->effective_from);
        $close = function (string $table) use ($change, $from): void {
            DB::table($table)->where('employee_id', $change->employee_id)->whereNull('effective_to')->where('effective_from', '<', $from->toDateString())
                ->update(['effective_to' => $from->copy()->subDay()->toDateString(), 'updated_at' => now()]);
            if (DB::table($table)->where('employee_id', $change->employee_id)->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->where('effective_from', '>=', $from->toDateString())->exists()) {
                throw ValidationException::withMessages(['workflow' => 'يوجد سجل يبدأ في تاريخ السريان أو بعده؛ أعد الطلب لتصحيح التاريخ.']);
            }
        };
        if ($change->changes_pay) {
            $close('employee_compensations');
            DB::table('employee_compensations')->insert([
                'employee_id' => $change->employee_id, 'effective_from' => $change->effective_from, 'effective_to' => null, 'currency_code' => $change->currency_code,
                'base_salary' => $change->base_salary, 'spouse_allowance' => $change->spouse_allowance, 'children_allowance' => $change->children_allowance,
                'cost_of_living_rate' => $change->cost_of_living_rate, 'nature_of_work_rate' => $change->nature_of_work_rate, 'source_total_salary' => null,
                'status' => 'approved', 'source' => 'request', 'pay_change_id' => $change->id, 'note' => $change->reference,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($change->changes_bank) {
            $close('employee_bank_accounts');
            DB::table('employee_bank_accounts')->insert([
                'employee_id' => $change->employee_id, 'bank_value_id' => $change->bank_value_id, 'bank_source' => null, 'account_number' => $change->account_number,
                'account_hash' => $change->account_hash, 'account_last4' => $change->account_last4, 'effective_from' => $change->effective_from, 'effective_to' => null,
                'status' => 'approved', 'source' => 'request', 'pay_change_id' => $change->id, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * History visible on the profile: amounts only for pay viewers, account numbers masked unless allowed in full.
     *
     * @return array{compensation: list<array<string, mixed>>, bank: list<array<string, mixed>>}
     */
    public function history(Employee $employee, bool $pay, bool $bank, bool $bankFull): array
    {
        return [
            'compensation' => $pay ? DB::table('employee_compensations')->where('employee_id', $employee->id)->orderByDesc('effective_from')
                ->get(['id', 'effective_from', 'effective_to', 'currency_code', 'base_salary', 'spouse_allowance', 'children_allowance', 'cost_of_living_rate',
                    'nature_of_work_rate', 'source_total_salary', 'status', 'source', 'note', 'import_batch_id', 'pay_change_id'])->map(fn (object $row): array => (array) $row)->all() : [],
            'bank' => $bank ? DB::table('employee_bank_accounts as b')->leftJoin('master_data_values as v', 'v.id', '=', 'b.bank_value_id')
                ->where('b.employee_id', $employee->id)->orderByDesc('b.effective_from')
                ->get(['b.id', 'b.effective_from', 'b.effective_to', 'b.status', 'b.source', 'b.bank_source', 'v.name_ar as bank_name', 'b.account_number', 'b.account_last4'])
                ->map(fn (object $row): array => [
                    'id' => $row->id, 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'status' => $row->status, 'source' => $row->source,
                    'bank' => $row->bank_name ?? $row->bank_source,
                    'account' => $bankFull && $row->account_number ? Crypt::decryptString($row->account_number) : EmployeeAccess::maskAccount($row->account_last4),
                ])->all() : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(User|int $actor, string $action, int $changeId, array $metadata, ?string $ip): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor instanceof User ? $actor->id : $actor, 'action' => $action, 'subject_type' => 'employee_pay_changes', 'subject_id' => (string) $changeId,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), 'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
