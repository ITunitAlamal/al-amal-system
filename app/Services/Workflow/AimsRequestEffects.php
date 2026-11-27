<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Finance\FinanceGuard;
use App\Services\Maintenance\MaintenanceAddenda;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Effects a generic «طلباتي» request applies when it reaches a step whose assignment_config names one
 * ("effect"). They run inside the engine's move transaction: a refused effect undoes the move.
 */
final class AimsRequestEffects
{
    public const EFFECTS = ['finance_purchase_draft', MaintenanceAddenda::CLOSURE_EFFECT];

    public function __construct(private readonly CustomFieldService $fields, private readonly FinanceGuard $guard) {}

    public function apply(string $effect, object $instance, int $actorId): void
    {
        match ($effect) {
            'finance_purchase_draft' => $this->financePurchaseDraft($instance, $actorId),
            // «طلب صيانة» closes only when no required addendum is open without a separation decision.
            MaintenanceAddenda::CLOSURE_EFFECT => app(MaintenanceAddenda::class)->assertClosable($instance),
            default => throw ValidationException::withMessages(['transition' => 'إجراء غير معروف في إعداد المسار.']),
        };
    }

    /**
     * «طلب شراء» accepted by procurement: a draft purchase request in Accounts, created by the procurement officer
     * who accepted it (so that officer never reviews or approves it), linked to the request. One per request: a
     * request the executive director reopened and procurement accepts again keeps the purchase request it already
     * opened while it is still live; a rejected or cancelled one refuses the move. No second one is created.
     */
    private function financePurchaseDraft(object $instance, int $actorId): void
    {
        $actor = User::find($actorId);
        if (! $this->guard->allows($actor, 'finance.procurement.manage')) {
            throw ValidationException::withMessages(['transition' => 'استلام طلب الشراء لموظف المشتريات المكلَّف في الحسابات فقط.']);
        }
        $existing = DB::table('finance_purchase_requests')->where('source_module', 'aims_request')->where('source_id', (string) $instance->subject_id)
            ->lockForUpdate()->first(['id', 'state']);
        if ($existing !== null) {
            // A purchase request Accounts or the executive director already rejected or cancelled is closed for good:
            // reusing it would hand over nothing, and the unique link forbids a second one for this request.
            if (in_array($existing->state, ['rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['transition' => 'طلب الشراء المرتبط بهذا الطلب في الحسابات مرفوض أو ملغى، فلا يمكن تسليمه مرة أخرى. يُرجى تقديم طلب شراء جديد.']);
            }
            $this->guard->audit('purchase', (int) $existing->id, $actorId, 'relinked_from_reopened_request', ['request' => $instance->reference_no, 'state' => $existing->state]);

            return;
        }
        $request = DB::table('aims_requests')->where('id', $instance->subject_id)->first();
        $values = $this->fields->context(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id);
        $purpose = trim(implode("\n", array_filter([
            'طلب '.$instance->reference_no.': '.trim((string) ($values['purpose'] ?? '')),
            'الأصناف كما طلبها الموظف: '.trim((string) ($values['items'] ?? '')),
            isset($values['estimated_amount']) && $values['estimated_amount'] !== null && $values['estimated_amount'] !== '' ? 'التكلفة التقديرية: '.$values['estimated_amount'].' ₪' : null,
            ! empty($values['needed_by']) ? 'تاريخ الحاجة: '.$values['needed_by'] : null,
            ($stores = $this->storesNote($instance)) !== null ? 'فحص المخزن: '.$stores : null,
        ])));
        $id = DB::table('finance_purchase_requests')->insertGetId([
            'number' => 'PR-'.strtoupper(bin2hex(random_bytes(8))), 'department_id' => $request->department_id,
            'budget_line_id' => null, 'currency' => 'ILS', 'purpose' => mb_substr($purpose, 0, 5000), 'state' => 'draft',
            'source_module' => 'aims_request', 'source_id' => (string) $request->id,
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->guard->audit('purchase', $id, $actorId, 'create_from_request', ['request' => $instance->reference_no]);
    }

    /**
     * What the storekeeper wrote when sending the request on to procurement (the stores stage of «طلب شراء»: what is
     * missing and what was issued from stock), so procurement buys only the shortage (Accounts requirements §3, 9.2).
     */
    private function storesNote(object $instance): ?string
    {
        $note = DB::table('workflow_events as e')->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')
            ->where('e.workflow_instance_id', $instance->id)->whereNotNull('e.workflow_transition_id')
            ->where('s.code', 'stores')->where('e.action', 'unavailable')->orderByDesc('e.id')->value('e.comment');

        return $note !== null && trim((string) $note) !== '' ? trim((string) $note) : null;
    }
}
