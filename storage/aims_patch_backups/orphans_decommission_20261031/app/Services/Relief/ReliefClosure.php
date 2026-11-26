<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceGuard;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconciliation and closure. Accounts (stock or finance) records its own reconciliation against its source-of-truth
 * references; the campaign keeps only the reference and the counted confirmed deliveries. Closure is requested by the
 * manager and decided by the executive director only once every batch is closed, no conflict is held and a
 * reconciliation was recorded after the last confirmed delivery. Approval alone is never delivery or completion.
 */
final class ReliefClosure
{
    public function __construct(private readonly ReliefAccess $access, private readonly FinanceGuard $finance, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function reconcile(User $actor, int $campaignId, array $input): int
    {
        $data = validator($input, ['kind' => ['required', 'in:stock,finance'], 'reference' => ['required', 'string', 'min:3', 'max:120'],
            'outcome' => ['required', 'in:reconciled,discrepancy'], 'notes' => ['nullable', 'string', 'max:2000', 'required_if:outcome,discrepancy']],
            [], ['reference' => 'مرجع المستند المحاسبي/المخزني'])->validate();
        $permission = $data['kind'] === 'stock' ? 'finance.stock.issue' : 'finance.sponsorships.record';
        // A record made from Accounts' own documents: never by the administrator's technical role.
        abort_unless(! $actor->isSystemAdmin() && $this->finance->allows($actor, $permission), 403, 'التسوية يسجلها قسم الحسابات/المخازن من مستنداته.');

        return DB::transaction(function () use ($actor, $campaignId, $data): int {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign && in_array($campaign->status, ['active', 'suspended', 'closing'], true), 422, 'التسوية لحملة قائمة.');
            $summary = $this->confirmedSummary($campaignId);
            $id = DB::table('relief_reconciliations')->insertGetId(['campaign_id' => $campaignId, 'kind' => $data['kind'], 'reference' => $data['reference'],
                'confirmed_units' => $summary['units'], 'quantities' => json_encode($summary['quantities']), 'outcome' => $data['outcome'], 'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('relief.campaign.reconciled', 'relief_reconciliations', $id, null, ['campaign' => $campaign->reference_no, 'kind' => $data['kind'],
                'outcome' => $data['outcome'], 'units' => $summary['units']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** Units and quantities delivered according to server-confirmed events only (pending sync never counts). */
    public function confirmedSummary(int $campaignId): array
    {
        $events = DB::table('relief_delivery_events as e')->join('relief_allocations as a', 'a.id', '=', 'e.allocation_id')
            ->where('a.campaign_id', $campaignId)->where('e.status', 'confirmed')->whereIn('e.kind', ['delivered', 'partial'])->get(['e.items', 'e.allocation_id']);
        $quantities = [];
        foreach ($events as $event) {
            foreach (json_decode((string) $event->items, true) ?: [] as $item => $quantity) {
                $quantities[(string) $item] = round(($quantities[(string) $item] ?? 0) + (float) $quantity, 2);
            }
        }

        return ['units' => $events->pluck('allocation_id')->unique()->count(), 'quantities' => $quantities];
    }

    /** @return list<string> what still prevents closing */
    public function blockers(int $campaignId): array
    {
        $blockers = [];
        if (DB::table('relief_batches')->where('campaign_id', $campaignId)->whereIn('status', ['assigned', 'downloaded', 'returned'])->exists()) {
            $blockers[] = 'توجد دفعات ميدانية لم تُغلق بعد.';
        }
        if (DB::table('relief_delivery_events as e')->join('relief_batches as b', 'b.id', '=', 'e.batch_id')->where('b.campaign_id', $campaignId)
            ->whereIn('e.status', ['conflict_held', 'uploaded_pending_validation'])->exists()) {
            $blockers[] = 'توجد أحداث تسليم معلقة أو متعارضة لم يبت فيها رئيس القسم.';
        }
        if (DB::table('relief_allocations')->where('campaign_id', $campaignId)->whereIn('status', ['assigned', 'conflict'])->exists()) {
            $blockers[] = 'توجد مخصصات مسندة لم تُحسم نتيجتها.';
        }
        $lastDelivery = DB::table('relief_delivery_events as e')->join('relief_batches as b', 'b.id', '=', 'e.batch_id')->where('b.campaign_id', $campaignId)
            ->where('e.status', 'confirmed')->max('e.updated_at');
        $reconciled = DB::table('relief_reconciliations')->where('campaign_id', $campaignId)->where('outcome', 'reconciled')
            ->when($lastDelivery, fn ($q) => $q->where('created_at', '>=', $lastDelivery))->exists();
        if (! $reconciled) {
            $blockers[] = 'لا توجد تسوية مخزنية/مالية مسجلة من الحسابات بعد آخر تسليم مؤكد.';
        }

        return $blockers;
    }

    public function requestClosure(User $actor, int $campaignId, WorkflowEngine $workflow): void
    {
        DB::transaction(function () use ($actor, $campaignId, $workflow): void {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign && ((int) $campaign->manager_user_id === (int) $actor->id || $this->access->orphans()->isHead($actor)), 403, 'طلب الإغلاق لمدير الحملة أو رئيس القسم.');
            if (! in_array($campaign->status, ['active', 'suspended'], true) || $campaign->closure_instance_id) {
                throw ValidationException::withMessages(['campaign' => 'الحملة ليست في حالة تسمح بطلب الإغلاق.']);
            }
            if ($blockers = $this->blockers($campaignId)) {
                throw ValidationException::withMessages(['campaign' => $blockers]);
            }
            $instance = $workflow->startForSubject($actor, 'RELIEF_CLOSURE', ReliefClosureWorkflow::SUBJECT_TYPE, $campaignId, $this->access->orphans()->departmentId());
            DB::table('relief_campaigns')->where('id', $campaignId)->update(['status' => 'closing', 'closure_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }
}
