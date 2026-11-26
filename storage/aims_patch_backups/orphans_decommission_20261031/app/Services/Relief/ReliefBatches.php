<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Field batches. Only the head of Sponsorships & Orphans assigns approved allocations to a designated distributor on
 * an approved device (also enforced by a database trigger); the distributor can neither add beneficiaries nor raise
 * quantities. The manifest carries the minimum needed at the door: masked references, the registered guardian's name
 * and the last digits of their document for the identity check, items and quantities — no child file, no health
 * data. It is signed by the server and valid offline for at most 168 hours from issue.
 */
final class ReliefBatches
{
    public const STATUS_LABELS = ['assigned' => 'مسندة (لم تُنزّل)', 'downloaded' => 'منزّلة على الجهاز', 'returned' => 'عادت وتنتظر الإغلاق', 'closed' => 'مغلقة', 'revoked' => 'ملغاة'];

    public function __construct(private readonly OrphanAccess $access, private readonly ReliefAccess $relief, private readonly FieldKeys $keys, private readonly AuditService $audit) {}

    /** @param list<string> $allocationIds public ids */
    public function create(User $head, int $campaignId, int $distributorId, int $deviceId, array $allocationIds, ?int $areaId = null): int
    {
        abort_unless($this->access->isHead($head), 403, 'إسناد المستفيدين للموزعين لرئيس قسم الكفالات وحده.');
        if ($allocationIds === [] || count($allocationIds) > 500) {
            throw ValidationException::withMessages(['allocations' => 'اختر بين مستفيد واحد و500 مستفيد للدفعة.']);
        }

        return DB::transaction(function () use ($head, $campaignId, $distributorId, $deviceId, $allocationIds, $areaId): int {
            $campaign = DB::table('relief_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            abort_unless($campaign && $campaign->status === 'active', 422, 'الإسناد الميداني لحملة نشطة.');
            $distributor = User::find($distributorId);
            if (! $distributor || ! $this->relief->membership($distributor, $campaignId, 'field_distributor')) {
                throw ValidationException::withMessages(['distributor_user_id' => 'الموزع غير مكلف بدور موزع ميداني نافذ في هذه الحملة.']);
            }
            $device = DB::table('field_devices')->where('id', $deviceId)->where('user_id', $distributorId)->where('status', 'approved')->first();
            if (! $device) {
                throw ValidationException::withMessages(['device_id' => 'اختر جهازًا معتمدًا لهذا الموزع.']);
            }
            $roster = DB::table('relief_rosters')->where('campaign_id', $campaignId)->where('status', 'approved')->first();
            abort_unless($roster, 422, 'لا يوجد كشف معتمد للحملة.');
            $allocations = DB::table('relief_allocations')->where('campaign_id', $campaignId)->whereIn('public_id', $allocationIds)->lockForUpdate()->get();
            if ($allocations->count() !== count(array_unique($allocationIds)) || $allocations->contains(fn ($a) => $a->status !== 'reserved' || $a->batch_id !== null || (int) $a->roster_id !== (int) $roster->id)) {
                throw ValidationException::withMessages(['allocations' => 'بعض المستفيدين ليسوا محجوزين في الكشف المعتمد الحالي أو مسندين لدفعة أخرى.']);
            }
            $id = DB::table('relief_batches')->insertGetId(['public_id' => (string) Str::ulid(),
                'reference_no' => 'BAT-'.str_pad((string) DB::selectOne("select nextval('relief_batch_ref_seq') as n")->n, 6, '0', STR_PAD_LEFT),
                'campaign_id' => $campaignId, 'roster_id' => $roster->id, 'area_value_id' => $areaId, 'distributor_user_id' => $distributorId, 'device_id' => $deviceId,
                'status' => 'assigned', 'assigned_by' => $head->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('relief_allocations')->whereIn('id', $allocations->pluck('id'))->update(['status' => 'assigned', 'batch_id' => $id, 'updated_at' => now()]);
            $this->audit->recordRow('relief.batch.assigned', 'relief_batches', $id, null, ['campaign' => $campaign->reference_no, 'distributor' => $distributorId,
                'device' => $device->public_id, 'allocations' => $allocations->count()], $head->id, request()?->ip());

            return $id;
        });
    }

    /** Revoke: allocations without a confirmed delivery go back to the reserve; later uploads from the batch are held for review. */
    public function revoke(User $head, int $batchId, string $reason): void
    {
        abort_unless($this->access->isHead($head), 403, 'إلغاء الدفعات لرئيس قسم الكفالات.');
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'اذكر سبب الإلغاء.']);
        }
        DB::transaction(function () use ($head, $batchId, $reason): void {
            $batch = DB::table('relief_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch && in_array($batch->status, ['assigned', 'downloaded', 'returned'], true), 404);
            DB::table('relief_batches')->where('id', $batchId)->update(['status' => 'revoked', 'revoked_by' => $head->id, 'revoked_at' => now(), 'revoke_reason' => $reason, 'updated_at' => now()]);
            DB::table('relief_allocations')->where('batch_id', $batchId)->where('status', 'assigned')->update(['status' => 'reserved', 'batch_id' => null, 'updated_at' => now()]);
            $this->audit->recordRow('relief.batch.revoked', 'relief_batches', $batchId, ['status' => $batch->status], ['reason' => $reason], $head->id, request()?->ip());
        });
    }

    /** Close a returned batch once every allocation has a server outcome and nothing is held. */
    public function close(User $head, int $batchId): void
    {
        abort_unless($this->access->isHead($head), 403);
        DB::transaction(function () use ($head, $batchId): void {
            $batch = DB::table('relief_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch && in_array($batch->status, ['downloaded', 'returned'], true), 422, 'الدفعة ليست مستلمة من الميدان.');
            if (DB::table('relief_allocations')->where('batch_id', $batchId)->whereIn('status', ['assigned', 'conflict'])->exists()
                || DB::table('relief_delivery_events')->where('batch_id', $batchId)->whereIn('status', ['conflict_held', 'uploaded_pending_validation'])->exists()) {
                throw ValidationException::withMessages(['batch' => 'لكل مستفيد في الدفعة نتيجة مؤكدة أو قرار مراجعة قبل الإغلاق.']);
            }
            DB::table('relief_batches')->where('id', $batchId)->update(['status' => 'closed', 'closed_by' => $head->id, 'closed_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('relief.batch.closed', 'relief_batches', $batchId, null, ['status' => 'closed'], $head->id, request()?->ip());
        });
    }

    /** A confirmed "not delivered" unit goes back to the reserve for another attempt (same beneficiary; a substitute needs an exception). */
    public function requeue(User $head, int $allocationId): void
    {
        abort_unless($this->access->isHead($head), 403);
        DB::transaction(function () use ($head, $allocationId): void {
            $allocation = DB::table('relief_allocations')->where('id', $allocationId)->lockForUpdate()->first();
            abort_unless($allocation && in_array($allocation->status, ['not_delivered', 'returned'], true), 422, 'يُعاد للحجز ما لم يُسلَّم فقط.');
            DB::table('relief_allocations')->where('id', $allocationId)->update(['status' => 'reserved', 'batch_id' => null, 'updated_at' => now()]);
            $this->audit->recordRow('relief.allocation.requeued', 'relief_allocations', $allocationId, ['status' => $allocation->status], ['status' => 'reserved'], $head->id, request()?->ip());
        });
    }

    /**
     * Issues a new signed manifest version for the distributor's device.
     *
     * @return array{manifest: string, signature: string, key_id: string}
     */
    public function manifest(User $user, object $device, string $batchPublicId): array
    {
        return DB::transaction(function () use ($user, $device, $batchPublicId): array {
            $batch = DB::table('relief_batches')->where('public_id', $batchPublicId)->lockForUpdate()->first();
            if (! $batch || (int) $batch->distributor_user_id !== (int) $user->id || (int) $batch->device_id !== (int) $device->id) {
                abort(404);
            }
            if (! in_array($batch->status, ['assigned', 'downloaded'], true)) {
                abort(409, 'الدفعة ليست متاحة للتنزيل.');
            }
            $campaign = DB::table('relief_campaigns')->where('id', $batch->campaign_id)->first();
            if ($campaign->status !== 'active' || ! $this->relief->membership($user, (int) $campaign->id, 'field_distributor')) {
                abort(403, 'الحملة غير نشطة أو انتهى تكليفك.');
            }
            $settings = DB::table('orphans_module_settings')->where('id', 1)->first();
            $hours = min(168, max(1, (int) $settings->field_offline_max_hours));
            // Stored in the application timezone like every other timestamp; the manifest carries the same instants in UTC.
            $issued = now();
            $expires = $issued->copy()->addHours($hours);
            $version = (int) $batch->manifest_version + 1;
            $items = DB::table('relief_campaign_items')->where('campaign_id', $campaign->id)->get()->keyBy('id');
            $allocations = DB::table('relief_allocations as a')->join('relief_roster_entries as e', 'e.id', '=', 'a.roster_entry_id')
                ->join('orphan_households as h', 'h.id', '=', 'a.household_id')->leftJoin('guardians as g', 'g.id', '=', 'h.primary_guardian_id')
                ->leftJoin('orphan_children as c', 'c.id', '=', 'a.orphan_child_id')
                ->where('a.batch_id', $batch->id)->where('a.status', 'assigned')->orderBy('a.id')
                ->get(['a.public_id', 'a.items', 'a.unit_key', 'e.pseudonym', 'h.id as household_id', 'h.reference_no', 'g.name as guardian_name', 'g.national_id as guardian_identity',
                    'c.name_parts', 'c.file_no']);
            $addresses = DB::table('orphan_addresses')->whereIn('household_id', $allocations->pluck('household_id'))->where('kind', 'current')->whereNull('valid_to')->get()->keyBy('household_id');
            $entries = $allocations->map(function ($a) use ($items, $addresses): array {
                $identity = $a->guardian_identity ? Crypt::decryptString($a->guardian_identity) : '';
                $address = $addresses->get($a->household_id);
                $names = $a->name_parts ? json_decode(Crypt::decryptString($a->name_parts), true) : null;

                return [
                    'allocation' => $a->public_id, 'pseudonym' => $a->pseudonym, 'unit' => str_starts_with($a->unit_key, 'child:') ? 'child' : 'household',
                    'reference' => $this->mask($a->file_no ?: $a->reference_no), 'child_first_name' => $names['first_name'] ?? null,
                    'receiver' => ['name' => $a->guardian_name, 'id_last4' => mb_substr($identity, -4), 'relation' => 'guardian'],
                    'area' => array_values(array_filter([$address?->governorate, $address?->city, $address?->neighborhood])),
                    'landmark' => $address && $address->landmark ? Crypt::decrypt($address->landmark, false) : null,
                    'items' => collect(json_decode($a->items, true))->map(fn ($qty, $id) => ['id' => (string) $id, 'label' => $items[(int) $id]->label ?? '', 'unit' => $items[(int) $id]->unit ?? '',
                        'quantity' => (float) $qty])->values()->all(),
                ];
            })->values()->all();
            $manifest = json_encode([
                'kind' => 'aims.field.manifest', 'format' => 1, 'key_id' => $this->keys->keyId(),
                'batch' => ['id' => $batch->public_id, 'reference' => $batch->reference_no, 'version' => $version],
                'campaign' => ['reference' => $campaign->reference_no, 'title' => $campaign->title],
                'roster_revision' => (int) DB::table('relief_rosters')->where('id', $batch->roster_id)->value('revision'),
                'distributor' => ['id' => $user->id, 'name' => $user->name], 'device' => $device->key_fingerprint,
                'issued_at' => $issued->copy()->utc()->toIso8601ZuluString(), 'expires_at' => $expires->copy()->utc()->toIso8601ZuluString(),
                'policy' => ['max_offline_hours' => $hours, 'lock_minutes' => (int) $settings->field_lock_minutes, 'photo' => false, 'geolocation' => false],
                'failure_reasons' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'relief_failure_reasons')
                    ->where('v.is_active', true)->orderBy('v.sort_order')->get(['v.code', 'v.name_ar as label'])->map(fn ($r) => (array) $r)->all(),
                'entries' => $entries,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $manifest);
            DB::table('relief_batch_manifests')->insert(['batch_id' => $batch->id, 'version' => $version, 'device_id' => $device->id, 'manifest_hash' => $hash,
                'allocations' => count($entries), 'issued_at' => $issued, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('relief_batches')->where('id', $batch->id)->update(['status' => 'downloaded', 'manifest_version' => $version, 'manifest_hash' => $hash,
                'manifest_issued_at' => $issued, 'offline_expires_at' => $expires, 'updated_at' => now()]);
            $this->audit->recordRow('relief.batch.downloaded', 'relief_batches', (int) $batch->id, null, ['version' => $version, 'device' => $device->public_id, 'entries' => count($entries),
                'expires_at' => $expires->copy()->utc()->toIso8601ZuluString()], $user->id, request()?->ip());

            return ['manifest' => $manifest, 'signature' => $this->keys->sign($manifest), 'key_id' => $this->keys->keyId()];
        });
    }

    private function mask(?string $reference): string
    {
        if (! $reference) {
            return '—';
        }
        [$prefix, $digits] = array_pad(explode('-', $reference, 2), 2, '');

        return $prefix.'-••'.substr($digits, -3);
    }
}
