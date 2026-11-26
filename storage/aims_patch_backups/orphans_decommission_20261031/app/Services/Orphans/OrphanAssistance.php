<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Assistance for a family or one child (ORPHAN_ASSISTANCE): the department requests with its evidence, the head
 * recommends, the executive director decides, Accounts/stores execute with their document reference, and the
 * requester confirms delivery with proof. Approval alone is never delivery.
 */
final class OrphanAssistance
{
    public const STATUS_LABELS = ['in_review' => 'قيد التوصية والقرار', 'returned' => 'معاد للاستكمال', 'approved' => 'معتمد — بانتظار التنفيذ لدى الحسابات',
        'executed' => 'منفذ — بانتظار إثبات التسليم', 'delivered' => 'سُلّم وأُغلق', 'rejected' => 'مرفوض'];

    public function __construct(private readonly OrphanAccess $access, private readonly OrphanForms $forms, private readonly OrphanRegistry $registry, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, int $householdId, array $input, WorkflowEngine $engine): int
    {
        $this->access->assert($actor, 'records');
        $values = $this->validated($householdId, $input);

        return DB::transaction(function () use ($actor, $householdId, $values, $engine): int {
            $id = DB::table('orphan_assistance_requests')->insertGetId($values + ['public_id' => (string) Str::ulid(),
                'reference_no' => $this->registry->number('assistance_ref_seq', 'AST-'), 'household_id' => $householdId, 'status' => 'in_review',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $instance = $engine->startForSubject($actor, 'ORPHAN_ASSISTANCE', OrphanAssistanceWorkflow::SUBJECT_TYPE, $id, $this->access->departmentId());
            DB::table('orphan_assistance_requests')->where('id', $id)->update(['workflow_instance_id' => $instance->id]);
            $this->audit->recordRow('orphans.assistance.requested', 'orphan_assistance_requests', $id, null, ['household' => $householdId, 'workflow' => $instance->reference_no],
                $actor->id, request()?->ip());

            return $id;
        });
    }

    /** The requester completes a returned request, then resubmits it from the workflow actions. @param array<string, mixed> $input */
    public function update(User $actor, int $id, array $input): void
    {
        $this->access->assert($actor, 'records');
        DB::transaction(function () use ($actor, $id, $input): void {
            $row = DB::table('orphan_assistance_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->created_by === (int) $actor->id, 403, 'يستكمل الطلب مقدّمه.');
            if ($row->status !== 'returned') {
                throw ValidationException::withMessages(['request' => 'يُعدّل الطلب بعد إعادته للاستكمال فقط.']);
            }
            DB::table('orphan_assistance_requests')->where('id', $id)->update($this->validated((int) $row->household_id, $input) + ['updated_at' => now()]);
            $this->audit->recordRow('orphans.assistance.completed', 'orphan_assistance_requests', $id, null, ['status' => 'returned'], $actor->id, request()?->ip());
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(int $householdId, array $input): array
    {
        $status = DB::table('orphan_households')->where('id', $householdId)->value('status');
        abort_unless($status !== null, 404);
        if (! in_array($status, ['registered', 'active'], true)) {
            throw ValidationException::withMessages(['household' => 'طلب المساعدة لأسرة ملفها مسجل أو نشط.']);
        }
        $data = validator($input, [
            'child_public_id' => ['nullable', 'string', 'max:30'], 'assistance_type_value_id' => ['nullable', 'integer', $this->forms->valueOf('assistance_types')],
            'description' => ['required', 'string', 'min:10', 'max:2000'], 'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:999999'],
            'estimated_value' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'currency' => ['nullable', 'required_with:estimated_value', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'funding_source' => ['nullable', 'string', 'max:200'], 'evidence_document_ids' => ['nullable', 'array', 'max:10'], 'evidence_document_ids.*' => ['integer'],
        ], [], ['description' => 'وصف الحاجة ومبرراتها', 'quantity' => 'الكمية', 'estimated_value' => 'القيمة التقديرية', 'currency' => 'العملة', 'funding_source' => 'مصدر التمويل'])->validate();
        $childId = null;
        if (! empty($data['child_public_id'])) {
            $childId = DB::table('orphan_children')->where('public_id', $data['child_public_id'])->where('household_id', $householdId)->value('id');
            if (! $childId) {
                throw ValidationException::withMessages(['child_public_id' => 'الطفل المختار ليس من هذه الأسرة.']);
            }
        }
        $documents = array_values(array_unique(array_map('intval', $data['evidence_document_ids'] ?? [])));
        if ($documents !== []) {
            $children = DB::table('orphan_children')->where('household_id', $householdId)->pluck('id');
            $owned = DB::table('orphan_documents')->whereIn('id', $documents)
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('owner_type', 'household')->where('owner_id', $householdId))
                    ->orWhere(fn ($w) => $w->where('owner_type', 'child')->whereIn('owner_id', $children)))->count();
            if ($owned !== count($documents)) {
                throw ValidationException::withMessages(['evidence_document_ids' => 'أحد المستندات المختارة لا يخص هذه الأسرة.']);
            }
        }

        return ['orphan_child_id' => $childId, 'assistance_type_value_id' => $data['assistance_type_value_id'] ?? null, 'description' => $data['description'],
            'quantity' => $data['quantity'] ?? null, 'estimated_value' => isset($data['estimated_value']) ? number_format((float) $data['estimated_value'], 2, '.', '') : null,
            'currency' => isset($data['estimated_value']) ? strtoupper((string) $data['currency']) : null, 'funding_source' => $data['funding_source'] ?? null,
            'evidence_document_ids' => $documents !== [] ? json_encode($documents) : null];
    }
}
