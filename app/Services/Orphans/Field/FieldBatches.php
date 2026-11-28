<?php

namespace App\Services\Orphans\Field;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\Campaigns\Campaigns;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribution batches: approved primary entries assigned to one distributor on one approved device, and the signed
 * manifest the device downloads. A unit sits in at most one active batch (database index), so two devices never
 * carry the same beneficiary.
 */
final class FieldBatches
{
    public function __construct(private readonly OrphanAccess $access, private readonly Campaigns $campaigns, private readonly FieldKeys $keys, private readonly AuditService $audit,
        private readonly FieldDevices $devices) {}

    /** @param list<string> $unitKeys empty = every primary entry of the approved version not yet in a batch and not delivered */
    public function create(object $campaign, int $distributorId, int $deviceId, array $unitKeys, ?int $governorateId, User $actor): object
    {
        abort_unless($this->access->canAssign($actor) || $this->access->allows($actor, 'campaigns'), 403, 'إنشاء دفعات التوزيع لرئيس القسم أو معدّ الحملة.');
        abort_unless(in_array($campaign->status, ['approved', 'distributing'], true), 409, 'الدفعات تُنشأ لكشف معتمد فقط.');
        abort_unless($this->devices->isDistributorOf($distributorId, (int) $campaign->id), 422, 'المستخدم ليس مكلفًا بالتسليم في هذه الحملة (أو انتهت مدة تكليفه).');
        abort_unless(DB::table('field_devices')->where('id', $deviceId)->where('user_id', $distributorId)->where('status', 'approved')->exists(), 422, 'الجهاز غير معتمد لهذا الموزع.');
        $list = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->firstOrFail();

        return DB::transaction(function () use ($campaign, $distributorId, $deviceId, $unitKeys, $governorateId, $list, $actor): object {
            $entries = DB::table('campaign_list_entries')->where('list_id', $list->id)->where('kind', 'primary')
                ->when($unitKeys !== [], fn ($q) => $q->whereIn('unit_key', $unitKeys))->when($governorateId, fn ($q) => $q->where('governorate_value_id', $governorateId))
                ->whereNotExists(fn ($q) => $q->from('campaign_batch_entries as b')->whereColumn('b.unit_key', 'campaign_list_entries.unit_key')->where('b.campaign_id', $campaign->id)->where('b.is_active', true))
                ->orderBy('position')->pluck('unit_key')->filter(fn ($key) => in_array($this->campaigns->entryState($campaign->id, $key), ['prepared', 'undelivered'], true))->values();
            abort_if($entries->isEmpty(), 422, 'لا يوجد مستفيدون متاحون لدفعة جديدة (كلهم في دفعات نشطة أو سُلّموا).');
            $id = DB::table('campaign_batches')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => $campaign->reference_no.'-B'.DB::selectOne("SELECT nextval('campaign_batch_seq') AS n")->n,
                'campaign_id' => $campaign->id, 'distributor_user_id' => $distributorId, 'device_id' => $deviceId, 'status' => 'assigned', 'assigned_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($entries as $key) {
                DB::table('campaign_batch_entries')->insert(['public_id' => (string) Str::ulid(), 'batch_id' => $id, 'campaign_id' => $campaign->id, 'unit_key' => $key, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->recordRow('orphans.field.batch_created', 'campaign_batches', $id, null, ['entries' => $entries->count(), 'device' => $deviceId], $actor->id, request()?->ip());

            return DB::table('campaign_batches')->find($id);
        });
    }

    /** Revokes a batch: its units become free; events already received are kept and reviewed. */
    public function revoke(object $batch, User $actor): void
    {
        abort_unless($this->access->canAssign($actor), 403);
        DB::transaction(fn () => $this->revokeRow((int) $batch->id, $actor, null));
    }

    /**
     * Withdraws a user's assignment to a campaign. In the same transaction the user's open batches of that campaign
     * (assigned or downloaded) are revoked, so the device can no longer download their manifest and their units are
     * free for another distributor; events already received stay for review. Returns the number of batches revoked.
     */
    public function withdrawMember(object $campaign, int $memberId, User $actor): int
    {
        abort_unless($this->access->canAssign($actor) || $this->access->isExecutive($actor), 403, 'سحب التكليف بالحملة لرئيس القسم.');

        return DB::transaction(function () use ($campaign, $memberId, $actor): int {
            $member = DB::table('campaign_members')->where('id', $memberId)->where('campaign_id', $campaign->id)->lockForUpdate()->first();
            abort_unless($member, 404, 'التكليف غير موجود في هذه الحملة.');
            if ($member->revoked_at !== null) {
                return 0; // Already withdrawn (a repeated click): same outcome, nothing applied twice.
            }
            DB::table('campaign_members')->where('id', $member->id)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);
            $batches = DB::table('campaign_batches')->where('campaign_id', $campaign->id)->where('distributor_user_id', $member->user_id)->whereIn('status', ['assigned', 'downloaded'])
                ->orderBy('id')->lockForUpdate()->pluck('id');
            foreach ($batches as $batchId) {
                $this->revokeRow((int) $batchId, $actor, 'member_withdrawn');
            }
            $this->audit->recordRow('orphans.campaign.member_revoked', 'campaigns', (int) $campaign->id, ['user' => (int) $member->user_id, 'role' => $member->role],
                ['batches_revoked' => $batches->count()], $actor->id, request()?->ip());

            return $batches->count();
        });
    }

    private function revokeRow(int $batchId, User $actor, ?string $reason): void
    {
        DB::table('campaign_batches')->where('id', $batchId)->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
        DB::table('campaign_batch_entries')->where('batch_id', $batchId)->update(['is_active' => false, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.field.batch_revoked', 'campaign_batches', $batchId, null, $reason ? ['reason' => $reason] : null, $actor->id, request()?->ip());
    }

    /** The signed manifest for the device (format 1, as read by field_client). */
    public function manifest(User $user, object $device, string $batchPublicId): array
    {
        return DB::transaction(function () use ($user, $device, $batchPublicId): array {
            $batch = DB::table('campaign_batches')->where('public_id', $batchPublicId)->lockForUpdate()->first();
            // Only to the batch's distributor on its device, while their assignment in THIS campaign stands.
            abort_if(! $batch || (int) $batch->distributor_user_id !== (int) $user->id || (int) $batch->device_id !== (int) $device->id
                || ! $this->devices->isDistributorOf($user, (int) $batch->campaign_id), 404);
            abort_unless(in_array($batch->status, ['assigned', 'downloaded'], true), 409, 'الدفعة ليست متاحة للتنزيل.');
            $campaign = DB::table('campaigns')->find($batch->campaign_id);
            abort_unless(in_array($campaign->status, ['approved', 'distributing'], true), 403, 'الحملة غير مفتوحة للتسليم.');
            $list = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->firstOrFail();
            $hours = min(168, max(1, FieldDevices::setting('field_offline_max_hours', 72)));
            $issued = now();
            $expires = $issued->copy()->addHours($hours);
            $version = (int) $batch->manifest_version + 1;
            $rows = DB::table('campaign_batch_entries as b')->join('campaign_list_entries as e', fn ($j) => $j->on('e.unit_key', '=', 'b.unit_key')->where('e.list_id', $list->id)->where('e.kind', 'primary'))
                ->leftJoin('campaign_items as i', 'i.id', '=', 'e.item_id')->where('b.batch_id', $batch->id)->where('b.is_active', true)->orderBy('e.position')
                ->get(['b.public_id as allocation', 'b.unit_key', 'e.orphan_id', 'e.household_id', 'e.guardian_id', 'e.recipient_guardian_id', 'e.item_id', 'e.quantity', 'i.label', 'i.variant']);
            $entries = $rows->filter(fn ($r) => in_array($this->campaigns->entryState($campaign->id, $r->unit_key), ['prepared', 'undelivered'], true))->map(function ($r): array {
                $receiver = $this->receiver($r);
                $orphan = $r->orphan_id ? DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->where('o.id', $r->orphan_id)->first(['o.reference_no', 'p.first_name', 'p.id as person_id']) : null;
                $addressPerson = $orphan->person_id ?? $receiver['person_id'];
                $address = DB::table('registry_addresses as a')->leftJoin('master_data_values as g', 'g.id', '=', 'a.governorate_value_id')->leftJoin('master_data_values as l', 'l.id', '=', 'a.locality_value_id')
                    ->leftJoin('master_data_values as ar', 'ar.id', '=', 'a.area_value_id')->where('a.kind', 'current')->whereNull('a.ends_on')
                    ->where(fn ($q) => $r->household_id ? $q->where('a.household_id', $r->household_id) : $q->where('a.person_id', $addressPerson))->first(['g.name_ar as g', 'l.name_ar as l', 'ar.name_ar as a']);

                return ['allocation' => $r->allocation, 'pseudonym' => substr(hash('sha256', $r->allocation), 0, 8), 'unit' => $r->orphan_id ? 'child' : 'household',
                    'reference' => $orphan ? '•••'.substr($orphan->reference_no, -4) : null, 'child_first_name' => $orphan->first_name ?? null,
                    'receiver' => ['name' => $receiver['name'], 'id_last4' => $receiver['id_last4'], 'relation' => 'guardian'],
                    'area' => array_values(array_filter([$address->g ?? null, $address->l ?? null, $address->a ?? null])), 'landmark' => null,
                    'items' => [['id' => (string) ($r->item_id ?? 0), 'label' => trim(($r->label ?? 'حصة الحملة').' '.($r->variant ?? '')), 'unit' => '', 'quantity' => (float) $r->quantity]]];
            })->values()->all();
            $body = json_encode(['kind' => 'aims.field.manifest', 'format' => 1, 'key_id' => $this->keys->keyId(),
                'batch' => ['id' => $batch->public_id, 'reference' => $batch->reference_no, 'version' => $version], 'campaign' => ['reference' => $campaign->reference_no, 'title' => $campaign->title],
                'roster_revision' => (int) $list->version, 'distributor' => ['id' => $user->id, 'name' => $user->name], 'device' => $device->key_fingerprint,
                'issued_at' => $issued->copy()->utc()->toIso8601ZuluString(), 'expires_at' => $expires->copy()->utc()->toIso8601ZuluString(),
                'policy' => ['max_offline_hours' => $hours, 'lock_minutes' => FieldDevices::setting('field_lock_minutes', 5), 'photo' => false, 'geolocation' => false],
                'failure_reasons' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'relief_failure_reasons')->where('v.is_active', true)
                    ->orderBy('v.sort_order')->get(['v.code', 'v.name_ar as label'])->map(fn ($x) => (array) $x)->all(),
                'entries' => $entries], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $signature = $this->keys->sign($body);
            DB::table('campaign_batch_manifests')->insert(['batch_id' => $batch->id, 'version' => $version, 'list_id' => $list->id, 'body' => $body, 'body_hash' => hash('sha256', $body), 'signature' => $signature,
                'issued_at' => $issued, 'expires_at' => $expires, 'created_at' => now()]);
            DB::table('campaign_batches')->where('id', $batch->id)->update(['status' => 'downloaded', 'manifest_version' => $version, 'offline_expires_at' => $expires, 'updated_at' => now()]);

            return ['manifest' => $body, 'signature' => $signature, 'key_id' => $this->keys->keyId()];
        });
    }

    /** Who may receive: the approved guardian (orphan/guardian units) or the household's registered contact. */
    public function receiver(object $entry): array
    {
        $personId = $entry->recipient_guardian_id ? DB::table('orphan_guardians')->where('id', $entry->recipient_guardian_id)->value('person_id')
            : ($entry->household_id ? DB::table('households')->where('id', $entry->household_id)->value('contact_person_id') : null);
        $person = $personId ? DB::table('persons')->where('id', $personId)->first(['id', 'full_name', 'id_last4']) : null;

        return ['person_id' => $person->id ?? null, 'name' => $person->full_name ?? null, 'id_last4' => $person->id_last4 ?? null];
    }
}
