<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle: drafted by department staff, recommended by the head, approved by the executive director,
 * activated afterwards; suspension and resumption are recorded executive decisions; closure is its own workflow.
 * Items and limits are frozen once the campaign leaves draft.
 */
final class ReliefCampaigns
{
    public const STATUS_LABELS = ['draft' => 'مسودة', 'in_review' => 'قيد التوصية والقرار', 'approved' => 'معتمدة (لم تبدأ)', 'active' => 'نشطة',
        'suspended' => 'موقوفة', 'closing' => 'بانتظار قرار الإغلاق', 'closed' => 'مغلقة', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة'];

    public const UNITS = ['child' => 'الطفل', 'household' => 'الأسرة'];

    public function __construct(private readonly ReliefAccess $access, private readonly AuditService $audit, private readonly NotificationCenter $notifications) {}

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $campaignId = null): int
    {
        $this->access->assertPrepare($actor);
        $data = $this->validated($input);

        return DB::transaction(function () use ($actor, $data, $campaignId): int {
            if ($campaignId) {
                $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
                abort_unless($campaign, 404);
                if ($campaign->status !== 'draft') {
                    throw ValidationException::withMessages(['campaign' => 'لا تُعدّل الحملة بعد رفعها؛ البيانات المعتمدة ثابتة.']);
                }
                DB::table('relief_campaigns')->where('id', $campaignId)->update($data + ['updated_at' => now()]);
                $this->audit->recordRow('relief.campaign.updated', 'relief_campaigns', $campaignId, null, ['fields' => array_keys($data)], $actor->id, request()?->ip());

                return $campaignId;
            }
            $id = DB::table('relief_campaigns')->insertGetId($data + [
                'public_id' => (string) Str::ulid(), 'reference_no' => 'CMP-'.str_pad((string) DB::selectOne("select nextval('relief_campaign_ref_seq') as n")->n, 6, '0', STR_PAD_LEFT),
                'owner_department_id' => $this->access->orphans()->departmentId(), 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->recordRow('relief.campaign.created', 'relief_campaigns', $id, null, ['title' => $data['title']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function saveItem(User $actor, int $campaignId, array $input, ?int $itemId = null): void
    {
        $this->access->assertPrepare($actor);
        $data = validator($input, [
            'label' => ['required', 'string', 'min:2', 'max:200'], 'kind' => ['required', 'in:in_kind,cash,voucher,service'],
            'stock_item_id' => ['nullable', 'integer', 'exists:finance_stock_items,id'], 'unit' => ['required', 'string', 'max:30'],
            'quantity_per_unit' => ['required', 'numeric', 'gt:0', 'max:100000'], 'unit_value' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'planned_total' => ['required', 'numeric', 'gte:quantity_per_unit', 'max:100000000'],
        ], [], ['label' => 'البند', 'quantity_per_unit' => 'الكمية لكل مستفيد', 'planned_total' => 'الكمية الكلية المخططة'])->validate();
        DB::transaction(function () use ($actor, $campaignId, $data, $itemId): void {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign, 404);
            if ($campaign->status !== 'draft') {
                throw ValidationException::withMessages(['campaign' => 'بنود الحملة ثابتة بعد رفعها للاعتماد.']);
            }
            if ($itemId) {
                DB::table('relief_campaign_items')->where('id', $itemId)->where('campaign_id', $campaignId)->update($data + ['updated_at' => now()]);
            } else {
                DB::table('relief_campaign_items')->insert($data + ['campaign_id' => $campaignId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->recordRow('relief.campaign.item_saved', 'relief_campaigns', $campaignId, null, ['item' => $data['label']], $actor->id, request()?->ip());
        });
    }

    public function removeItem(User $actor, int $campaignId, int $itemId): void
    {
        $this->access->assertPrepare($actor);
        DB::transaction(function () use ($campaignId, $itemId): void {
            abort_unless(DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->value('status') === 'draft', 422, 'بنود الحملة ثابتة بعد رفعها.');
            DB::table('relief_campaign_items')->where('id', $itemId)->where('campaign_id', $campaignId)->delete();
        });
    }

    public function submit(User $actor, int $campaignId, WorkflowEngine $workflow): void
    {
        $this->access->assertPrepare($actor);
        DB::transaction(function () use ($actor, $campaignId, $workflow): void {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign, 404);
            if ($campaign->status !== 'draft' || $campaign->workflow_instance_id) {
                throw ValidationException::withMessages(['campaign' => 'الحملة مرفوعة مسبقًا؛ تابع إجراءاتها من صفحتها.']);
            }
            if (! DB::table('relief_campaign_items')->where('campaign_id', $campaignId)->exists()) {
                throw ValidationException::withMessages(['campaign' => 'أضف بندًا واحدًا على الأقل للمساعدة قبل الرفع.']);
            }
            $instance = $workflow->startForSubject($actor, 'RELIEF_CAMPAIGN', ReliefCampaignWorkflow::SUBJECT_TYPE, $campaignId, $this->access->orphans()->departmentId());
            DB::table('relief_campaigns')->where('id', $campaignId)->update(['status' => 'in_review', 'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }

    /** Operational start after the executive approval (head or the campaign manager). */
    public function activate(User $actor, int $campaignId): void
    {
        DB::transaction(function () use ($actor, $campaignId): void {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign && ($this->access->orphans()->isHead($actor) || (int) $campaign->manager_user_id === (int) $actor->id), 403);
            if ($campaign->status !== 'approved') {
                throw ValidationException::withMessages(['campaign' => 'لا تنشط إلا حملة اعتمدها المدير التنفيذي.']);
            }
            DB::table('relief_campaigns')->where('id', $campaignId)->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('relief.campaign.activated', 'relief_campaigns', $campaignId, ['status' => 'approved'], ['status' => 'active'], $actor->id, request()?->ip());
        });
    }

    /** Suspension and resumption are executive decisions, recorded with their reason. */
    public function suspend(User $actor, int $campaignId, bool $suspend, string $reason): void
    {
        abort_unless($this->access->orphans()->isExecutive($actor), 403, 'إيقاف الحملة واستئنافها قرار للمدير التنفيذي.');
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'اذكر سبب القرار.']);
        }
        DB::transaction(function () use ($actor, $campaignId, $suspend, $reason): void {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign, 404);
            if ($campaign->status !== ($suspend ? 'active' : 'suspended')) {
                throw ValidationException::withMessages(['campaign' => $suspend ? 'تُوقف الحملة النشطة فقط.' : 'يُستأنف ما كان موقوفًا فقط.']);
            }
            DB::table('relief_campaigns')->where('id', $campaignId)->update(['status' => $suspend ? 'suspended' : 'active', 'status_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow($suspend ? 'relief.campaign.suspended' : 'relief.campaign.resumed', 'relief_campaigns', $campaignId, ['status' => $campaign->status], ['reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /**
     * A time-bound role in one named campaign. Field distributors are designated by the head only; PR and other roles by
     * the head or the system admin. A membership never gives access to child files or to other campaigns.
     *
     * @param  array<string, mixed>  $input
     */
    public function grantMember(User $actor, int $campaignId, array $input): int
    {
        $data = validator($input, [
            'user_id' => ['required', 'integer', 'exists:users,id'], 'role' => ['required', Rule::in(array_keys(ReliefAccess::ROLES))],
            'starts_on' => ['required', 'date', 'after_or_equal:today'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'], 'may_view_pseudonymous' => ['boolean'],
        ], [], ['user_id' => 'الموظف', 'ends_on' => 'نهاية التكليف'])->validate();
        $head = $this->access->orphans()->isHead($actor);
        abort_unless($head || ($actor->isSystemAdmin() && $data['role'] !== 'field_distributor'), 403, $data['role'] === 'field_distributor'
            ? 'تكليف الموزعين الميدانيين لرئيس قسم الكفالات وحده.' : 'التكليف في الحملات لرئيس قسم الكفالات أو مدير النظام.');
        abort_if((int) $data['user_id'] === (int) $actor->id, 403, 'لا يكلّف المستخدم نفسه.');
        $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->first();
        abort_unless($campaign && in_array($campaign->status, ['approved', 'active', 'suspended', 'in_review', 'draft'], true), 404);
        if ($data['ends_on'] > $campaign->ends_on) {
            throw ValidationException::withMessages(['ends_on' => 'لا يتجاوز التكليف تاريخ انتهاء الحملة ('.$campaign->ends_on.').']);
        }
        abort_unless(DB::table('users')->where('id', $data['user_id'])->where('is_active', true)->exists(), 422, 'الحساب غير نشط.');

        return DB::transaction(function () use ($actor, $campaignId, $data, $campaign): int {
            $id = DB::table('relief_campaign_members')->insertGetId(Arr::only($data, ['user_id', 'role', 'starts_on', 'ends_on', 'reason'])
                + ['campaign_id' => $campaignId, 'may_view_pseudonymous' => (bool) ($data['may_view_pseudonymous'] ?? false), 'granted_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('relief.member.granted', 'relief_campaign_members', $id, null, Arr::only($data, ['user_id', 'role', 'starts_on', 'ends_on']) + ['campaign' => $campaign->reference_no], $actor->id, request()?->ip());
            $this->notifications->notify((int) $data['user_id'], 'relief_member_changed', ['title' => 'كُلفت بدور «'.ReliefAccess::ROLES[$data['role']].'» في الحملة '.$campaign->reference_no.' حتى '.$data['ends_on'],
                'url' => '/relief/campaigns/'.$campaign->public_id, 'dedupe' => 'relief-member:'.$id]);

            return $id;
        });
    }

    public function revokeMember(User $actor, int $memberId, string $reason): void
    {
        DB::transaction(function () use ($actor, $memberId, $reason): void {
            $member = DB::table('relief_campaign_members')->where('id', $memberId)->lockForUpdate()->first();
            abort_unless($member && $member->revoked_at === null, 404);
            abort_unless($this->access->orphans()->isHead($actor) || ($actor->isSystemAdmin() && $member->role !== 'field_distributor'), 403);
            DB::table('relief_campaign_members')->where('id', $memberId)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('relief.member.revoked', 'relief_campaign_members', $memberId, null, ['reason' => $reason], $actor->id, request()?->ip());
        });
    }

    /**
     * How many units the ranked roster can serve: the roster cap and the resource capacity, whichever is smaller.
     */
    public function capacity(int $campaignId): ?int
    {
        $cap = DB::table('relief_campaigns')->where('id', $campaignId)->value('roster_cap');
        $resources = $this->resourceCapacity($campaignId);
        $limits = array_filter([$cap ? (int) $cap : null, $resources], fn ($v) => $v !== null);

        return $limits === [] ? null : min($limits);
    }

    /**
     * Units the campaign's approved quantities and budget can physically serve, whatever their route (ranking or an
     * executive exception above the roster cap). Allocations never exceed it.
     */
    public function resourceCapacity(int $campaignId): ?int
    {
        $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->first();
        $items = DB::table('relief_campaign_items')->where('campaign_id', $campaignId)->get();
        $limits = [];
        foreach ($items as $item) {
            $limits[] = (int) floor((float) $item->planned_total / (float) $item->quantity_per_unit);
        }
        $unitCost = $items->sum(fn ($i) => (float) $i->quantity_per_unit * (float) ($i->unit_value ?? 0));
        if ($campaign->budget_amount !== null && $unitCost > 0) {
            $limits[] = (int) floor((float) $campaign->budget_amount / $unitCost);
        }

        return $limits === [] ? null : min($limits);
    }

    /** @param array<string, mixed> $input */
    private function validated(array $input): array
    {
        $data = validator($input, [
            'title' => ['required', 'string', 'min:5', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'objective' => ['nullable', 'string', 'max:2000'],
            'type_value_id' => ['nullable', 'integer'], 'delivery_method_value_id' => ['nullable', 'integer'], 'unit_of_benefit' => ['required', 'in:child,household'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'registration_deadline' => ['nullable', 'date', 'before_or_equal:ends_on'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'], 'currency' => ['nullable', 'string', 'size:3'], 'funding_source' => ['nullable', 'string', 'max:300'],
            'finance_fund_id' => ['nullable', 'integer', 'exists:finance_restricted_funds,id'], 'roster_cap' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'priority' => ['required', 'in:normal,high,urgent'], 'manager_user_id' => ['required', 'integer', 'exists:users,id'], 'publication_consent' => ['boolean'],
            'eligibility' => ['nullable', 'array'], 'eligibility.min_age' => ['nullable', 'integer', 'between:0,30'], 'eligibility.max_age' => ['nullable', 'integer', 'between:0,30'],
            'eligibility.orphan_classes' => ['nullable', 'array'], 'eligibility.orphan_classes.*' => ['string', 'max:60'],
            'eligibility.governorates' => ['nullable', 'array'], 'eligibility.governorates.*' => ['integer'], 'eligibility.displaced_only' => ['nullable', 'boolean'],
        ], [], ['title' => 'عنوان الحملة', 'ends_on' => 'تاريخ الانتهاء', 'manager_user_id' => 'مدير الحملة'])->validate();
        foreach (['type_value_id' => 'relief_campaign_types', 'delivery_method_value_id' => 'relief_delivery_methods'] as $field => $type) {
            if (! empty($data[$field]) && ! DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $type)->where('v.id', $data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'القيمة غير متاحة في الثوابت.']);
            }
        }
        $manager = DB::table('users')->where('id', $data['manager_user_id'])->where('is_active', true)->first();
        if (! $manager || ! $this->access->canPrepare(User::find($manager->id))) {
            throw ValidationException::withMessages(['manager_user_id' => 'مدير الحملة من موظفي قسم الكفالات المفوضين بالحملات.']);
        }
        $data['eligibility'] = json_encode(array_filter($data['eligibility'] ?? [], fn ($v) => $v !== null && $v !== [] && $v !== ''), JSON_UNESCAPED_UNICODE);
        $data['publication_consent'] = (bool) ($data['publication_consent'] ?? false);

        return Arr::only($data, ['title', 'description', 'objective', 'type_value_id', 'delivery_method_value_id', 'unit_of_benefit', 'starts_on', 'ends_on',
            'registration_deadline', 'budget_amount', 'currency', 'funding_source', 'finance_fund_id', 'roster_cap', 'priority', 'manager_user_id', 'publication_consent', 'eligibility']);
    }
}
