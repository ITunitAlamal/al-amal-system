<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Hr\EmployeeNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff distribution inside a department: its head places the department's own employees in its functional units
 * and may name one coordinator per unit. A unit assignment is operational: it grants no permission and does not
 * move anyone between departments — a transfer stays a formal HR change on the employee's file.
 */
final class UnitAssignments
{
    public function __construct(
        private readonly StructureChanges $changes,
        private readonly AuditService $audit,
    ) {}

    /** @return list<int> departments whose units the user manages */
    public function managedDepartments(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($user->canManageOrganization()) {
            return DB::table('departments')->whereIn('node_type', ['office', 'department'])->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $this->changes->headedDepartments($user);
    }

    /**
     * Units of the managed departments with their members, the staff who may be placed, and recent history.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(User $user): array
    {
        $departments = DB::table('departments')->whereIn('id', $this->managedDepartments($user))->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $result = [];
        foreach ($departments as $department) {
            $units = DB::table('departments')->where('parent_id', $department->id)->where('node_type', 'unit')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'is_active', 'responsibilities']);
            if ($units->isEmpty()) {
                continue;
            }
            $members = DB::table('department_unit_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->whereIn('a.unit_id', $units->pluck('id'))->where('a.status', 'active')
                ->orderBy('a.role')->get(['a.id', 'a.unit_id', 'a.role', 'a.starts_on', 'e.id as employee_id', 'e.employee_number', 'e.full_name_ar', 'e.first_name_ar', 'e.family_name_ar'])->groupBy('unit_id');
            $staff = DB::table('employees')->where('department_id', $department->id)->whereNull('deleted_at')->orderBy('employee_number')
                ->get(['id', 'employee_number', 'full_name_ar', 'first_name_ar', 'family_name_ar']);
            $history = DB::table('department_unit_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->join('departments as u', 'u.id', '=', 'a.unit_id')
                ->whereIn('a.unit_id', $units->pluck('id'))->where('a.status', 'ended')->orderByDesc('a.ended_at')->limit(15)
                ->get(['a.id', 'u.name as unit', 'a.role', 'a.starts_on', 'a.ends_on', 'a.end_reason', 'e.full_name_ar', 'e.first_name_ar', 'e.family_name_ar']);
            $result[] = [
                'id' => (int) $department->id, 'name' => $department->name,
                'units' => $units->map(fn (object $unit): array => ['id' => (int) $unit->id, 'name' => $unit->name, 'active' => (bool) $unit->is_active, 'responsibilities' => $unit->responsibilities,
                    'members' => $members->get($unit->id, collect())->map(fn (object $m): array => ['id' => (int) $m->id, 'employee_id' => (int) $m->employee_id,
                        'name' => EmployeeNames::display($m), 'number' => $m->employee_number, 'role' => $m->role, 'since' => $m->starts_on])->values()])->values(),
                'staff' => $staff->map(fn (object $e): array => ['id' => (int) $e->id, 'name' => EmployeeNames::display($e), 'number' => $e->employee_number])->values(),
                'history' => $history->map(fn (object $h): array => ['id' => (int) $h->id, 'unit' => $h->unit, 'name' => EmployeeNames::display($h), 'role' => $h->role,
                    'from' => $h->starts_on, 'to' => $h->ends_on, 'reason' => $h->end_reason])->values(),
            ];
        }

        return $result;
    }

    public function assign(User $actor, int $unitId, int $employeeId, string $role, ?string $startsOn, ?string $reason, ?string $ip): int
    {
        return DB::transaction(function () use ($actor, $unitId, $employeeId, $role, $startsOn, $reason, $ip): int {
            $unit = DB::table('departments')->where('id', $unitId)->lockForUpdate()->first();
            $this->assertManages($actor, $unit);
            $employee = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->first();
            if (! $employee || (int) $employee->department_id !== (int) $unit->parent_id) {
                throw ValidationException::withMessages(['employee_id' => 'يوزَّع على وحدات القسم موظفوه فقط؛ نقل موظف من قسم آخر إجراء رسمي من الموارد البشرية في ملفه.']);
            }
            if (DB::table('department_unit_assignments')->where('unit_id', $unitId)->where('employee_id', $employeeId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'الموظف موزع على هذه الوحدة أصلًا.']);
            }
            if ($role === 'coordinator' && DB::table('department_unit_assignments')->where('unit_id', $unitId)->where('role', 'coordinator')->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['role' => 'للوحدة منسق حالي؛ أنهِ تكليفه أولًا.']);
            }
            $id = (int) DB::table('department_unit_assignments')->insertGetId(['unit_id' => $unitId, 'employee_id' => $employeeId, 'role' => $role,
                'starts_on' => $startsOn ?: today()->toDateString(), 'status' => 'active', 'reason' => $reason, 'assigned_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('organization.unit.assigned', 'department_unit_assignments', $id, null,
                ['unit_id' => $unitId, 'employee_id' => $employeeId, 'role' => $role, 'starts_on' => $startsOn ?: today()->toDateString(), 'reason' => $reason], $actor->id, $ip);

            return $id;
        });
    }

    public function end(User $actor, int $assignmentId, string $reason, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $assignmentId, $reason, $ip): void {
            $assignment = DB::table('department_unit_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            abort_unless($assignment && $assignment->status === 'active', 404);
            $this->assertManages($actor, DB::table('departments')->where('id', $assignment->unit_id)->first(), false);
            $endsOn = max(today()->toDateString(), (string) $assignment->starts_on);
            DB::table('department_unit_assignments')->where('id', $assignmentId)->update(['status' => 'ended', 'ends_on' => $endsOn, 'ended_by' => $actor->id,
                'ended_at' => now(), 'end_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('organization.unit.ended', 'department_unit_assignments', $assignmentId, ['status' => 'active'], ['status' => 'ended', 'ends_on' => $endsOn, 'reason' => $reason], $actor->id, $ip);
        });
    }

    private function assertManages(User $actor, ?object $unit, bool $mustBeActive = true): void
    {
        abort_unless($unit && $unit->node_type === 'unit' && $unit->deleted_at === null, 404);
        if ($mustBeActive && ! $unit->is_active) {
            throw ValidationException::withMessages(['unit_id' => 'الوحدة معطّلة.']);
        }
        abort_unless(in_array((int) $unit->parent_id, $this->managedDepartments($actor), true), 403, 'توزيع الموظفين على الوحدات لرئيس القسم الذي تتبعه الوحدة.');
    }
}
