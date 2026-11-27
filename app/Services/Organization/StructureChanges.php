<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Proposals to change the organizational tree. A proposal goes through workflow ORG_STRUCTURE_CHANGE to the executive
 * director and is applied automatically on approval (StructureChangeWorkflow). Organization managers (system
 * administrator, HR head) propose for any node; a department head proposes for their own department and its units.
 */
final class StructureChanges
{
    public const WORKFLOW = 'ORG_STRUCTURE_CHANGE';

    /** Functional units suggested for review, by parent department code. They exist only as suggestions until approved. */
    public const SUGGESTED_UNITS = [
        'ORPHANS' => ['سجلات الأيتام والأوصياء', 'البحث الاجتماعي ومتابعة الحالات', 'الكفالات والمتبرعون', 'الحملات الإغاثية'],
        'HR' => ['شؤون الموظفين والعقود', 'الدوام والإجازات', 'التوظيف والتدريب'],
        'FIN' => ['المشتريات', 'المخازن', 'المحاسبة والصندوق والبنوك', 'الرواتب والتسويات', 'التحصيل والتقارير المالية'],
        'ASSETS' => ['سجل الأصول والمباني', 'العقود والتأجير', 'تشغيل المباني وتنسيق الصيانة'],
        'PR' => ['التواصل المؤسسي والإعلام', 'الشراكات والفعاليات'],
    ];

    public function __construct(
        private readonly OrganizationStructure $structure,
        private readonly WorkflowEngine $engine,
        private readonly AuditService $audit,
    ) {}

    /** Whether the user may propose a change touching this node (null: anywhere). */
    public function canPropose(User $user, ?int $departmentId = null): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ($user->canManageOrganization()) {
            return true;
        }
        if ($departmentId === null) {
            return $this->headedDepartments($user) !== [];
        }
        $node = DB::table('departments')->where('id', $departmentId)->first();
        $root = $node && $node->node_type === 'unit' ? (int) $node->parent_id : $departmentId;

        return in_array($root, $this->headedDepartments($user), true);
    }

    /** @return list<int> departments the user heads with the head role held there */
    public function headedDepartments(User $user): array
    {
        return DB::table('departments as d')->join('department_user_roles as a', fn ($join) => $join->on('a.department_id', '=', 'd.id')->where('a.user_id', $user->id))
            ->join('roles as r', 'r.id', '=', 'a.role_id')->where('r.code', 'department_head')->where('d.head_user_id', $user->id)->whereRaw(EffectiveAssignments::sql('a'))
            ->where('d.is_active', true)->whereNull('d.deleted_at')->distinct()->pluck('d.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function propose(User $actor, array $input, ?string $ip): int
    {
        $data = Validator::make($input, [
            'change_type' => ['required', Rule::in(array_keys(OrganizationStructure::CHANGE_LABELS))],
            'department_id' => ['nullable', 'integer', 'required_unless:change_type,create_unit,create_department', 'prohibited_if:change_type,create_department'],
            'parent_id' => ['nullable', 'integer', 'required_if:change_type,create_unit,convert_to_unit', 'prohibited_if:change_type,create_department'],
            'proposed_name' => ['nullable', 'string', 'max:255', 'required_if:change_type,create_unit,create_department,rename'],
            'proposed_code' => ['nullable', 'string', 'max:50', 'required_if:change_type,create_department', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('departments', 'code')],
            'description' => ['nullable', 'string', 'max:2000'],
            'responsibilities' => ['nullable', 'string', 'max:4000'],
            'rationale' => ['required', 'string', 'min:10', 'max:4000'],
        ], [], ['rationale' => 'المبرر', 'proposed_name' => 'الاسم المقترح', 'parent_id' => 'الجهة التابع لها', 'department_id' => 'القسم', 'proposed_code' => 'الرمز'])->validate();
        $creatingDepartment = $data['change_type'] === 'create_department';
        abort_if($creatingDepartment && (! $actor->is_active || ! $actor->canManageOrganization()), 403, 'يقترح إنشاء قسم مستقل مدير النظام ورئيس الموارد البشرية.');
        $target = $data['change_type'] === 'create_unit' ? (int) $data['parent_id'] : (int) ($data['department_id'] ?? 0);
        abort_unless($this->canPropose($actor, $target), 403, 'يقترح تعديل الهيكل مدير النظام ورئيس الموارد البشرية، ورئيس القسم لقسمه ووحداته.');

        return DB::transaction(function () use ($actor, $data, $target, $ip): int {
            $this->assertNoOpenChange(in_array($data['change_type'], ['create_unit', 'create_department'], true) ? null : $target, $data);
            $details = array_filter(['description' => $data['description'] ?? null, 'responsibilities' => $data['responsibilities'] ?? null]);
            $id = $this->structure->record([...$data, 'details' => $details], $actor->id, 'in_review');
            $this->structure->validate(DB::table('department_structure_changes')->find($id));
            $this->start($actor, $id, $target);
            $this->audit->recordRow('organization.change.proposed', 'department_structure_changes', $id, null, ['change' => $data['change_type'], 'target' => $target], $actor->id, $ip);

            return $id;
        });
    }

    /** A system suggestion becomes a proposal of the user who submits it. */
    public function submitSuggestion(User $actor, int $changeId, string $rationale, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $changeId, $rationale, $ip): void {
            $change = DB::table('department_structure_changes')->where('id', $changeId)->lockForUpdate()->first();
            abort_unless($change && $change->status === 'suggested', 404);
            abort_if($change->change_type === 'create_department' && (! $actor->is_active || ! $actor->canManageOrganization()), 403);
            $target = (int) ($change->department_id ?: $change->parent_id);
            abort_unless($this->canPropose($actor, $target), 403, 'لا تملك تقديم هذا الاقتراح.');
            DB::table('department_structure_changes')->where('id', $changeId)->update(['rationale' => trim($rationale) ?: $change->rationale, 'status' => 'in_review',
                'proposed_by' => $actor->id, 'updated_at' => now()]);
            $this->structure->validate(DB::table('department_structure_changes')->find($changeId));
            $this->start($actor, $changeId, $target);
            $this->audit->recordRow('organization.change.proposed', 'department_structure_changes', $changeId, ['status' => 'suggested'], ['status' => 'in_review'], $actor->id, $ip);
        });
    }

    public function dismissSuggestion(User $actor, int $changeId, ?string $ip): void
    {
        abort_unless($actor->canManageOrganization(), 403);
        $updated = DB::table('department_structure_changes')->where('id', $changeId)->where('status', 'suggested')->update(['status' => 'withdrawn', 'updated_at' => now()]);
        abort_unless($updated === 1, 404);
        $this->audit->recordRow('organization.change.dismissed', 'department_structure_changes', $changeId, ['status' => 'suggested'], ['status' => 'withdrawn'], $actor->id, $ip);
    }

    public function withdraw(User $actor, int $changeId, string $reason, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $changeId, $reason, $ip): void {
            $change = DB::table('department_structure_changes')->where('id', $changeId)->lockForUpdate()->first();
            abort_unless($change && in_array($change->status, ['in_review', 'returned'], true), 404);
            abort_unless((int) $change->proposed_by === (int) $actor->id, 403, 'يسحب المقترح مقدمه.');
            if ($change->workflow_instance_id) {
                $this->engine->cancel((int) $change->workflow_instance_id, $actor, $reason);
            }
            DB::table('department_structure_changes')->where('id', $changeId)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            $this->audit->recordRow('organization.change.withdrawn', 'department_structure_changes', $changeId, ['status' => $change->status], ['status' => 'withdrawn', 'reason' => $reason], $actor->id, $ip);
        });
    }

    /** Adds the suggested units that are not already live or pending. Returns how many were added. */
    public function suggestDefaults(): int
    {
        $added = 0;
        foreach (self::SUGGESTED_UNITS as $code => $names) {
            $parent = DB::table('departments')->where('code', $code)->whereNull('deleted_at')->where('is_active', true)->first();
            if (! $parent) {
                continue;
            }
            foreach ($names as $name) {
                $live = DB::table('departments')->where('parent_id', $parent->id)->whereNull('deleted_at')->whereRaw('lower(btrim(name)) = lower(btrim(?))', [$name])->exists();
                $known = DB::table('department_structure_changes')->where('change_type', 'create_unit')->where('parent_id', $parent->id)->where('proposed_name', $name)->exists();
                if (! $live && ! $known) {
                    $this->structure->record(['change_type' => 'create_unit', 'parent_id' => $parent->id, 'proposed_name' => $name,
                        'rationale' => 'وحدة وظيفية مقترحة لتقسيم مهام «'.$parent->name.'»؛ تُفعّل بعد تقديمها واعتماد المدير التنفيذي.'], null, 'suggested');
                    $added++;
                }
            }
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoOpenChange(?int $departmentId, array $data): void
    {
        $open = DB::table('department_structure_changes')->whereIn('status', ['in_review', 'returned'])
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId),
                fn ($q) => $q->where('change_type', $data['change_type'])->where('parent_id', $data['parent_id'] ?? null)->whereRaw('lower(btrim(proposed_name)) = lower(btrim(?))', [trim($data['proposed_name'])]))
            ->exists();
        if ($open) {
            throw ValidationException::withMessages(['change' => 'يوجد مقترح مفتوح على هذا القسم أو بهذا الاسم؛ أكمله أو اسحبه أولًا.']);
        }
    }

    private function start(User $actor, int $changeId, int $target): void
    {
        $department = (int) ($actor->department_id ?: $target ?: DB::table('departments')->where('code', 'EXEC')->value('id'));
        $instance = $this->engine->startForSubject($actor, self::WORKFLOW, StructureChangeWorkflow::SUBJECT_TYPE, $changeId, $department);
        DB::table('department_structure_changes')->where('id', $changeId)->update(['workflow_instance_id' => $instance->id, 'updated_at' => now()]);
    }
}
