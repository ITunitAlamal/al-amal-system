<?php

namespace App\Services\Organization;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The approved organizational tree kept in `departments`: executive offices, departments and their functional units.
 * Every structural change is validated against the live tree and applied only here, with a ledger row
 * (department_structure_changes) so history keeps who decided what, on what authority and why. Archiving hides a
 * node from lists and selections but never deletes it, so old records keep resolving.
 */
final class OrganizationStructure
{
    public const TYPES = ['office' => 'مكتب تنفيذي', 'department' => 'قسم', 'unit' => 'وحدة وظيفية'];

    /** Departments the institution approved, with the evidence (approved requirement documents or owner decisions). */
    public const APPROVED = [
        'EXEC' => 'وثيقة متطلبات المدير التنفيذي المعتمدة',
        'HR' => 'وثيقة متطلبات الموارد البشرية المعتمدة',
        'FIN' => 'وثيقة متطلبات قسم الحسابات المعتمدة',
        'ASSETS' => 'وثيقة متطلبات الأصول والمباني والإيجارات المعتمدة',
        'ORPHANS' => 'وثيقة متطلبات الكفالات والأيتام المعتمدة (الإصدار 2.0)',
        'SHELTER' => 'وثيقة متطلبات الإيواء المعتمدة؛ التنفيذ مؤجل',
        'PR' => 'قرار المالك في 2026-10-22: قسم العلاقات العامة والإعلام',
        'it' => 'قرار المالك في 2026-10-23: يبقى قسمًا مستقلًا',
    ];

    public const CHANGE_LABELS = ['create_department' => 'إنشاء قسم مستقل', 'create_unit' => 'إنشاء وحدة', 'convert_to_unit' => 'تحويل إلى وحدة', 'rename' => 'إعادة تسمية',
        'retire' => 'أرشفة', 'reparent' => 'نقل التبعية', 'reactivate' => 'إعادة تفعيل'];

    public const STATUS_LABELS = ['suggested' => 'اقتراح بانتظار التقديم', 'in_review' => 'لدى المدير التنفيذي', 'returned' => 'معاد للاستكمال',
        'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب', 'applied' => 'مطبَّق'];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Live nodes in tree order (offices, then departments, each followed by its units), with head and staff counts.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $nodes = DB::table('departments as d')->leftJoin('users as h', 'h.id', '=', 'd.head_user_id')->whereNull('d.deleted_at')
            ->orderBy('d.name')->get(['d.id', 'd.code', 'd.name', 'd.node_type', 'd.parent_id', 'd.is_active', 'd.description', 'd.responsibilities', 'd.effective_from', 'd.head_user_id', 'h.name as head']);
        $staff = DB::table('employees')->whereNull('deleted_at')->whereNotNull('department_id')->selectRaw('department_id, count(*) AS n')->groupBy('department_id')->pluck('n', 'department_id');
        $members = DB::table('department_unit_assignments')->where('status', 'active')->selectRaw('unit_id, count(*) AS n')->groupBy('unit_id')->pluck('n', 'unit_id');
        $children = $nodes->groupBy(fn (object $node): int => (int) $node->parent_id);
        $order = ['office' => 0, 'department' => 1, 'unit' => 2];
        $rows = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$rows, $children, $staff, $members, $order): void {
            foreach ($children->get($parent, collect())->sortBy(fn (object $node): string => $order[$node->node_type].$node->name) as $node) {
                $rows[] = ['id' => (int) $node->id, 'code' => $node->code, 'name' => $node->name, 'node_type' => $node->node_type, 'type_label' => self::TYPES[$node->node_type] ?? $node->node_type,
                    'parent_id' => $node->parent_id === null ? null : (int) $node->parent_id, 'is_active' => (bool) $node->is_active, 'depth' => $depth, 'head' => $node->head,
                    'head_user_id' => $node->head_user_id === null ? null : (int) $node->head_user_id,
                    'description' => $node->description, 'responsibilities' => $node->responsibilities, 'effective_from' => $node->effective_from,
                    'staff' => (int) ($staff[$node->id] ?? 0), 'unit_members' => (int) ($members[$node->id] ?? 0), 'evidence' => self::APPROVED[$node->code] ?? null];
                $walk((int) $node->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }

    /**
     * Every node (archived ones included) with its references and the proposed disposition, for the cleanup review.
     * Read-only: it changes nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(): array
    {
        $references = $this->referenceCounts();
        $names = DB::table('departments')->pluck('name', 'id');

        return DB::table('departments')->orderBy('id')->get()->map(function (object $node) use ($references, $names): array {
            $refs = $references[(int) $node->id] ?? [];
            $category = match (true) {
                $node->deleted_at !== null => 'archived',
                isset(self::APPROVED[$node->code]) => 'approved',
                $node->node_type === 'unit' => 'unit',
                ! $node->is_active && $refs === [] => 'retire_candidate',
                default => 'needs_review',
            };

            return ['id' => (int) $node->id, 'code' => $node->code, 'name' => $node->name, 'node_type' => $node->node_type, 'type_label' => self::TYPES[$node->node_type] ?? $node->node_type,
                'parent' => $node->parent_id ? ($names[$node->parent_id] ?? null) : null, 'is_active' => (bool) $node->is_active, 'archived' => $node->deleted_at !== null,
                'retired_reason' => $node->retired_reason, 'category' => $category, 'evidence' => self::APPROVED[$node->code] ?? null, 'references' => $refs,
                'blockers' => $node->deleted_at === null ? $this->retireBlockers((int) $node->id) : []];
        })->all();
    }

    /**
     * Rows referencing each department, per referencing table and column, from the database's own foreign keys.
     *
     * @return array<int, array<string, int>>
     */
    public function referenceCounts(): array
    {
        $references = [];
        $columns = DB::select("SELECT cl.relname AS child, att.attname AS col FROM pg_constraint con
            JOIN pg_class cl ON cl.oid = con.conrelid JOIN pg_namespace ns ON ns.oid = cl.relnamespace AND ns.nspname = 'public'
            JOIN pg_class parent ON parent.oid = con.confrelid AND parent.relname = 'departments'
            JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = con.conkey[1]
            WHERE con.contype = 'f'");
        foreach ($columns as $column) {
            foreach (DB::table($column->child)->whereNotNull($column->col)->selectRaw("\"{$column->col}\" AS department, count(*) AS n")->groupBy($column->col)->get() as $row) {
                $references[(int) $row->department]["{$column->child}.{$column->col}"] = (int) $row->n;
            }
        }

        return $references;
    }

    /**
     * Live work that keeps a node from being archived; empty when archiving is safe.
     *
     * @return list<string>
     */
    public function retireBlockers(int $id): array
    {
        $today = today()->toDateString();
        $checks = [
            'وحدات أو أقسام تابعة غير مؤرشفة' => DB::table('departments')->where('parent_id', $id)->whereNull('deleted_at')->count(),
            'موظفون مرتبطون بالقسم في ملفاتهم' => DB::table('employees')->where('department_id', $id)->whereNull('deleted_at')->count(),
            'أدوار مسندة في القسم' => DB::table('department_user_roles')->where('department_id', $id)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->count(),
            'رئيس قسم معيّن' => (int) DB::table('departments')->where('id', $id)->whereNotNull('head_user_id')->exists(),
            'معاملات مفتوحة في مسارات العمل' => DB::table('workflow_instances')->where('department_id', $id)->where('status', 'in_progress')->count(),
            'مؤشرات أداء فعالة يملكها القسم' => DB::table('kpi_definitions')->where('owner_department_id', $id)->where('is_active', true)->count(),
            'تكليفات دوام سارية' => DB::table('attendance_assignments')->where('department_id', $id)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->count(),
            'موظفون موزعون على الوحدة' => DB::table('department_unit_assignments')->where('unit_id', $id)->where('status', 'active')->count(),
            'تفويضات سارية في نطاقه' => DB::table('scoped_delegations')->where('scope_department_id', $id)->where('status', 'active')->where('ends_at', '>', now())->count(),
        ];
        if (Schema::hasTable('correspondence_tasks')) {
            $checks['مهام مراسلات مفتوحة'] = DB::table('correspondence_tasks')->where('department_id', $id)->where('status', 'open')->count();
        }

        return array_values(array_map(fn (string $label, int $count): string => "{$label}: {$count}", array_keys(array_filter($checks)), array_filter($checks)));
    }

    /**
     * Checks a change against the live tree. Throws a ValidationException with an Arabic reason.
     */
    public function validate(object $change): void
    {
        $node = $change->department_id ? DB::table('departments')->where('id', $change->department_id)->first() : null;
        $parent = $change->parent_id ? DB::table('departments')->where('id', $change->parent_id)->first() : null;
        $fail = fn (string $message) => throw ValidationException::withMessages(['change' => $message]);
        if (! in_array($change->change_type, ['create_unit', 'create_department'], true) && (! $node || $node->deleted_at !== null)) {
            $fail('القسم المعني غير موجود أو مؤرشف.');
        }
        switch ($change->change_type) {
            case 'create_department':
                if ($change->parent_id !== null || $change->department_id !== null) {
                    $fail('القسم المستقل جديد وبلا جهة تابعة.');
                }
                $this->checkName((string) $change->proposed_name, 0, null, $fail);
                if (! preg_match('/^[A-Za-z0-9_-]{1,50}$/', (string) $change->proposed_code)
                    || DB::table('departments')->where('code', $change->proposed_code)->exists()) {
                    $fail('اختر رمزًا فريدًا للقسم من أحرف لاتينية وأرقام وشرطة.');
                }
                break;
            case 'create_unit':
                $this->checkParent($parent, null, $fail);
                $this->checkName((string) $change->proposed_name, (int) $parent->id, null, $fail);
                break;
            case 'convert_to_unit':
                $this->checkParent($parent, $node, $fail);
                if (DB::table('departments')->where('parent_id', $node->id)->whereNull('deleted_at')->exists()) {
                    $fail('لا يتحول إلى وحدة قسم له أقسام أو وحدات تابعة غير مؤرشفة.');
                }
                $this->checkName((string) ($change->proposed_name ?: $node->name), (int) $parent->id, (int) $node->id, $fail);
                break;
            case 'rename':
                $this->checkName((string) $change->proposed_name, (int) $node->parent_id, (int) $node->id, $fail);
                break;
            case 'reparent':
                if ($node->node_type === 'unit' || $parent) {
                    $this->checkParent($parent, $node, $fail, $node->node_type !== 'unit');
                }
                $this->checkName($node->name, (int) ($parent->id ?? 0), (int) $node->id, $fail);
                break;
            case 'retire':
                if (isset(self::APPROVED[$node->code])) {
                    $fail('قسم معتمد بوثيقة أو قرار؛ لا يؤرشف إلا بقرار يعدّل الهيكل المعتمد نفسه.');
                }
                if ($blockers = $this->retireBlockers((int) $node->id)) {
                    $fail('لا يؤرشف قبل معالجة: '.implode('، ', $blockers).'.');
                }
                break;
            case 'reactivate':
                if ($node->is_active) {
                    $fail('القسم مفعّل أصلًا.');
                }
                if ($node->parent_id && ! DB::table('departments')->where('id', $node->parent_id)->where('is_active', true)->whereNull('deleted_at')->exists()) {
                    $fail('فعّل الجهة التي يتبعها أولًا.');
                }
                break;
            default:
                $fail('نوع التعديل غير معروف.');
        }
    }

    /**
     * Applies one change (validated again inside the transaction) and marks its ledger row applied.
     */
    public function apply(object $change, int $actorId, string $authority, ?string $ip = null): void
    {
        DB::transaction(function () use ($change, $actorId, $authority, $ip): void {
            $change = DB::table('department_structure_changes')->where('id', $change->id)->lockForUpdate()->first();
            if ($change->status === 'applied') {
                return;
            }
            $this->validate($change);
            $node = $change->department_id ? DB::table('departments')->where('id', $change->department_id)->lockForUpdate()->first() : null;
            $before = $node ? (array) $node : null;
            $id = $node ? (int) $node->id : null;
            $values = match ($change->change_type) {
                'create_unit', 'create_department' => null,
                'convert_to_unit' => ['node_type' => 'unit', 'parent_id' => $change->parent_id, 'name' => trim((string) ($change->proposed_name ?: $node->name)), 'is_active' => true],
                'rename' => ['name' => trim((string) $change->proposed_name)],
                'reparent' => ['parent_id' => $change->parent_id],
                'retire' => ['is_active' => false, 'retired_at' => now(), 'retired_reason' => $change->rationale, 'retired_by' => $actorId, 'deleted_at' => now()],
                'reactivate' => ['is_active' => true],
            };
            if (in_array($change->change_type, ['create_unit', 'create_department'], true)) {
                $parent = DB::table('departments')->where('id', $change->parent_id)->first();
                $details = json_decode((string) $change->details, true) ?: [];
                $id = (int) DB::table('departments')->insertGetId([
                    'code' => $change->proposed_code ?: $this->unitCode($parent), 'name' => trim((string) $change->proposed_name), 'parent_id' => $parent?->id,
                    'node_type' => $change->change_type === 'create_department' ? 'department' : 'unit', 'is_active' => true, 'head_user_id' => null, 'description' => $details['description'] ?? null,
                    'responsibilities' => $details['responsibilities'] ?? null, 'effective_from' => today(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('departments')->where('id', $id)->update([...$values, 'updated_at' => now()]);
            }
            $after = (array) DB::table('departments')->where('id', $id)->first();
            DB::table('department_structure_changes')->where('id', $change->id)->update(['department_id' => $id, 'before_state' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                'after_state' => json_encode($after, JSON_UNESCAPED_UNICODE), 'status' => 'applied', 'authority' => $authority, 'decided_by' => $actorId,
                'decided_at' => now(), 'applied_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('organization.structure.applied', 'departments', $id, $before, ['change_id' => $change->id, 'change' => $change->change_type, 'authority' => $authority, 'after' => $after], $actorId, $ip);
        });
    }

    /**
     * Adds a ledger row (a suggestion, a proposal or an owner decision about to be applied).
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, ?int $proposedBy, string $status): int
    {
        return (int) DB::table('department_structure_changes')->insertGetId([
            'change_type' => $data['change_type'], 'department_id' => $data['department_id'] ?? null, 'parent_id' => $data['parent_id'] ?? null,
            'proposed_name' => isset($data['proposed_name']) ? trim((string) $data['proposed_name']) : null, 'proposed_code' => $data['proposed_code'] ?? null,
            'details' => empty($data['details']) ? null : json_encode($data['details'], JSON_UNESCAPED_UNICODE), 'rationale' => trim((string) $data['rationale']),
            'status' => $status, 'proposed_by' => $proposedBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Ids of a node and all its live descendants. */
    public function descendants(int $id): array
    {
        $found = [$id];
        $frontier = [$id];
        while ($frontier !== []) {
            $frontier = DB::table('departments')->whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->map(fn ($v): int => (int) $v)->diff($found)->values()->all();
            $found = [...$found, ...$frontier];
        }

        return $found;
    }

    private function checkParent(?object $parent, ?object $node, callable $fail, bool $allowTopLevel = false): void
    {
        if (! $parent) {
            $allowTopLevel ? null : $fail('اختر القسم أو المكتب الذي تتبعه الوحدة.');

            return;
        }
        if ($parent->deleted_at !== null || ! $parent->is_active) {
            $fail('الجهة المختارة مؤرشفة أو معطّلة.');
        }
        if ($parent->node_type === 'unit') {
            $fail('لا توضع وحدة أو قسم تحت وحدة وظيفية.');
        }
        if ($node && in_array((int) $parent->id, $this->descendants((int) $node->id), true)) {
            $fail('هذا النقل يجعل القسم تابعًا لنفسه أو لأحد فروعه.');
        }
    }

    private function checkName(string $name, int $parentId, ?int $exceptId, callable $fail): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            $fail('اكتب اسمًا واضحًا للوحدة أو القسم.');
        }
        $taken = DB::table('departments')->whereNull('deleted_at')->whereRaw('COALESCE(parent_id, 0) = ?', [$parentId])
            ->whereRaw('lower(btrim(name)) = lower(btrim(?))', [$name])->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
        if ($taken) {
            $fail("الاسم «{$name}» مستخدم تحت الجهة نفسها.");
        }
    }

    private function unitCode(object $parent): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $parent->code) ?: 'DEPT');
        $number = DB::table('departments')->where('code', 'like', $prefix.'-U%')->count() + 1;
        while (DB::table('departments')->where('code', $prefix.'-U'.$number)->exists()) {
            $number++;
        }

        return $prefix.'-U'.$number;
    }
}
