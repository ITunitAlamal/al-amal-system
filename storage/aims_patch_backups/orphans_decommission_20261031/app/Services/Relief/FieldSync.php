<?php

namespace App\Services\Relief;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Receives delivery events recorded offline. Each event is the exact JSON the device signed with its own key, linked
 * to the previous event by hash. Validation is atomic per event under a lock on its allocation; a retried event (same
 * UUID) returns its stored outcome and never counts twice. Problems never drop evidence: the event is kept as
 * conflict-held or rejected with its reason for the head's review. Only confirmed events count as delivered.
 */
final class FieldSync
{
    public const STATUS_LABELS = ['uploaded_pending_validation' => 'مرفوع بانتظار التحقق', 'confirmed' => 'مؤكد ومطابق', 'conflict_held' => 'معلق لتعارض', 'rejected' => 'مرفوض مع السبب'];

    private const CLOCK_TOLERANCE_SECONDS = 600;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{payload: string, signature: string, signature_image?: ?string}  $upload
     * @return array{event_uuid: ?string, status: string, reason: ?string}
     */
    public function receive(User $user, object $device, array $upload): array
    {
        $payload = json_decode($upload['payload'], true);
        if (! is_array($payload) || ! isset($payload['event_uuid']) || ! preg_match('/^[0-9a-f-]{36}$/', (string) $payload['event_uuid'])) {
            return ['event_uuid' => null, 'status' => 'rejected', 'reason' => 'صيغة الحدث غير صالحة.'];
        }
        $uuid = (string) $payload['event_uuid'];
        $existing = DB::table('relief_delivery_events')->where('event_uuid', $uuid)->first();
        if ($existing) {
            return ['event_uuid' => $uuid, 'status' => $existing->status, 'reason' => $existing->status_reason];
        }
        if (! FieldKeys::verifyDevice($upload['payload'], $upload['signature'], $device->public_key)) {
            // Not signed by this device's key: nothing from it can be trusted or stored as evidence of this device.
            return ['event_uuid' => $uuid, 'status' => 'rejected', 'reason' => 'توقيع الجهاز غير صحيح.'];
        }

        try {
            return DB::transaction(fn (): array => $this->store($user, $device, $upload, $payload, $uuid));
        } catch (UniqueConstraintViolationException) {
            $stored = DB::table('relief_delivery_events')->where('event_uuid', $uuid)->first();

            return ['event_uuid' => $uuid, 'status' => $stored->status ?? 'rejected', 'reason' => $stored->status_reason ?? null];
        }
    }

    /** @param array<string, mixed> $payload */
    private function store(User $user, object $device, array $upload, array $payload, string $uuid): array
    {
        $batch = DB::table('relief_batches')->where('public_id', (string) ($payload['batch'] ?? ''))->lockForUpdate()->first();
        $allocation = $batch ? DB::table('relief_allocations')->where('public_id', (string) ($payload['allocation'] ?? ''))->lockForUpdate()->first() : null;
        if (! $batch || ! $allocation || (int) $batch->distributor_user_id !== (int) $user->id || (int) $batch->device_id !== (int) $device->id) {
            return ['event_uuid' => $uuid, 'status' => 'rejected', 'reason' => 'الدفعة أو المستفيد لا يخص هذا الموزع وجهازه.'];
        }
        $kind = (string) ($payload['kind'] ?? '');
        $problems = [];
        $hold = [];
        if (! in_array($kind, ['delivered', 'partial', 'not_delivered', 'referral'], true)) {
            $problems[] = 'نوع الحدث غير معروف.';
        }
        $manifest = DB::table('relief_batch_manifests')->where('batch_id', $batch->id)->where('version', (int) ($payload['manifest_version'] ?? 0))->first();
        $occurred = $this->time($payload['occurred_at'] ?? null);
        if (! $manifest) {
            $problems[] = 'إصدار البيان المستخدم غير معروف للخادم.';
        }
        if (! $occurred) {
            $problems[] = 'وقت التسليم غير صالح.';
        }
        if ($manifest && $occurred) {
            $issued = Carbon::parse($manifest->issued_at);
            $expires = Carbon::parse($manifest->expires_at);
            if ($occurred->lt($issued->copy()->subSeconds(self::CLOCK_TOLERANCE_SECONDS)) || $occurred->gt(now()->addSeconds(self::CLOCK_TOLERANCE_SECONDS))) {
                $hold[] = 'وقت التسليم المسجل غير منطقي مقارنة بوقت التنزيل ووقت الخادم (احتمال تعديل ساعة الجهاز).';
            }
            $elapsed = (int) ($payload['clock']['elapsed_ms'] ?? -1);
            if ($elapsed < 0 || abs(($occurred->getTimestamp() - $issued->getTimestamp()) * 1000 - $elapsed) > self::CLOCK_TOLERANCE_SECONDS * 1000) {
                $hold[] = 'ساعة الجهاز لا تتسق مع الزمن المنقضي المسجل منذ التنزيل.';
            }
            if ($occurred->gt($expires) || $elapsed > $expires->diffInMilliseconds($issued, true)) {
                $hold[] = 'سُجّل بعد انتهاء صلاحية العمل دون اتصال (168 ساعة كحد أقصى)؛ يحتاج مراجعة.';
            }
        }
        $previous = DB::table('relief_delivery_events')->where('device_id', $device->id)->orderByDesc('id')->value('event_hash');
        if (($payload['prev_hash'] ?? null) !== null && $previous !== null && $payload['prev_hash'] !== $previous
            && ! DB::table('relief_delivery_events')->where('device_id', $device->id)->where('event_hash', $payload['prev_hash'])->exists()) {
            $hold[] = 'سلسلة أحداث الجهاز غير متصلة (حدث سابق لم يصل بعد أو مفقود).';
        }
        if ((int) $allocation->batch_id !== (int) $batch->id) {
            $hold[] = 'المستفيد نُقل من هذه الدفعة قبل الرفع.';
        }
        if ($batch->status === 'revoked') {
            $hold[] = 'أُلغيت الدفعة قبل وصول هذا الحدث؛ يحتاج مراجعة رئيس القسم.';
        }
        if ($allocation->status === 'cancelled') {
            $hold[] = 'المخصص ملغى في الكشف المعتمد الحالي.';
        }
        $receiver = is_array($payload['receiver'] ?? null) ? $payload['receiver'] : [];
        $items = is_array($payload['items'] ?? null) ? array_map('floatval', $payload['items']) : [];
        $signaturePath = null;
        $signatureHash = null;
        if (in_array($kind, ['delivered', 'partial'], true)) {
            $image = isset($upload['signature_image']) ? base64_decode((string) $upload['signature_image'], true) : false;
            $declared = (string) ($payload['signature_sha256'] ?? '');
            if ($image === false || $image === '' || strlen($image) > 512 * 1024 || ! str_starts_with($image, "\x89PNG") || ! hash_equals($declared, hash('sha256', $image))) {
                $problems[] = 'التوقيع الإلكتروني للمستلم مفقود أو لا يطابق بصمته في الحدث؛ لا يُؤكد تسليم دون توقيع.';
            } else {
                $signatureHash = $declared;
                $signaturePath = 'relief/signatures/'.$uuid.'.bin';
                Storage::disk('local')->put($signaturePath, Crypt::encryptString(base64_encode($image)));
            }
            if (empty($receiver['attested']) || ($receiver['relation'] ?? '') !== 'guardian') {
                $hold[] = ($receiver['relation'] ?? '') === 'proxy' ? 'المستلم ليس الوصي المسجل (مفوض غير معتمد مسبقًا).' : 'لم يُقرّ الموزع بالتحقق من هوية المستلم.';
            }
            $guardianIdentity = DB::table('orphan_households as h')->join('guardians as g', 'g.id', '=', 'h.primary_guardian_id')->where('h.id', $allocation->household_id)->value('g.national_id');
            $last4 = $guardianIdentity ? mb_substr(Crypt::decryptString($guardianIdentity), -4) : null;
            if (! $last4 || ! hash_equals($last4, (string) ($receiver['id_last4'] ?? ''))) {
                $hold[] = 'آخر أرقام وثيقة المستلم لا تطابق الوصي المسجل.';
            }
            $allocated = array_map('floatval', json_decode($allocation->items, true) ?: []);
            $delivered = $this->confirmedQuantities((int) $allocation->id);
            if ($items === [] || array_diff_key($items, $allocated) !== [] || array_filter($items, fn ($q) => $q <= 0) !== []) {
                $problems[] = 'كميات الحدث لا تطابق بنود المخصص.';
            } else {
                foreach ($items as $item => $quantity) {
                    if ($quantity + ($delivered[$item] ?? 0) > $allocated[$item] + 0.0001) {
                        $hold[] = 'الكمية تتجاوز المتبقي من المخصص (احتمال تسليم مكرر من جهازين).';
                        break;
                    }
                }
                $complete = collect($allocated)->every(fn ($q, $item) => ($items[$item] ?? 0) + ($delivered[$item] ?? 0) >= $q - 0.0001);
                if ($kind === 'delivered' && ! $complete) {
                    $problems[] = 'حدث «تم التسليم» بكميات أقل من المخصص؛ يُسجل كتسليم جزئي.';
                }
            }
        } elseif (! DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'relief_failure_reasons')
            ->where('v.code', (string) ($payload['failure_reason'] ?? ''))->exists()) {
            $problems[] = 'سبب عدم التسليم غير معروف.';
        }
        $status = $problems !== [] ? 'rejected' : ($hold !== [] ? 'conflict_held' : 'confirmed');
        $reason = implode(' ', array_merge($problems, $hold)) ?: null;
        DB::table('relief_delivery_events')->insert([
            'event_uuid' => $uuid, 'device_id' => $device->id, 'user_id' => $user->id, 'batch_id' => $batch->id, 'allocation_id' => $allocation->id,
            'manifest_version' => (int) ($payload['manifest_version'] ?? 0), 'kind' => in_array($kind, ['delivered', 'partial', 'not_delivered', 'referral'], true) ? $kind : 'referral',
            'items' => json_encode($items), 'receiver' => json_encode(['relation' => $receiver['relation'] ?? null, 'id_last4' => isset($receiver['id_last4']) ? '••'.mb_substr((string) $receiver['id_last4'], -2) : null,
                'method' => $receiver['method'] ?? null, 'attested' => (bool) ($receiver['attested'] ?? false)]),
            'failure_reason_value_id' => isset($payload['failure_reason']) ? DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'relief_failure_reasons')->where('v.code', (string) $payload['failure_reason'])->value('v.id') : null,
            'note' => isset($payload['note']) && $payload['note'] !== '' ? Crypt::encryptString(mb_substr((string) $payload['note'], 0, 500)) : null,
            'signature_hash' => $signatureHash, 'signature_path' => $signaturePath,
            'occurred_at' => $occurred ? $occurred->copy()->setTimezone(config('app.timezone')) : now(),
            'device_clock' => json_encode(is_array($payload['clock'] ?? null) ? $payload['clock'] : []), 'prev_event_hash' => $payload['prev_hash'] ?? null,
            'event_hash' => hash('sha256', $upload['payload']), 'device_signature' => $upload['signature'], 'received_at' => now(),
            'status' => $status, 'status_reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($status === 'confirmed') {
            $this->applyToAllocation($allocation, $kind);
        } elseif ($status === 'conflict_held' && in_array($allocation->status, ['assigned', 'reserved'], true)) {
            DB::table('relief_allocations')->where('id', $allocation->id)->update(['status' => 'conflict', 'updated_at' => now()]);
        }
        if (in_array($batch->status, ['downloaded'], true) && ! DB::table('relief_allocations')->where('batch_id', $batch->id)->where('status', 'assigned')->exists()) {
            DB::table('relief_batches')->where('id', $batch->id)->update(['status' => 'returned', 'updated_at' => now()]);
        }
        DB::table('field_devices')->where('id', $device->id)->update(['last_sync_at' => now()]);
        $this->audit->recordRow('relief.delivery.'.$status, 'relief_delivery_events', $uuid, null, ['batch' => $batch->reference_no, 'kind' => $kind], $user->id, request()?->ip());

        return ['event_uuid' => $uuid, 'status' => $status, 'reason' => $reason];
    }

    /** Updates the allocation from a confirmed event: full, partial or not delivered. */
    public function applyToAllocation(object $allocation, string $kind): void
    {
        $allocated = array_map('floatval', json_decode($allocation->items, true) ?: []);
        $delivered = $this->confirmedQuantities((int) $allocation->id);
        $status = match (true) {
            in_array($kind, ['not_delivered', 'referral'], true) && $delivered === [] => 'not_delivered',
            collect($allocated)->every(fn ($q, $item) => ($delivered[$item] ?? 0) >= $q - 0.0001) => 'delivered',
            $delivered !== [] => 'partially_delivered',
            default => $allocation->status,
        };
        DB::table('relief_allocations')->where('id', $allocation->id)->update(['status' => $status, 'updated_at' => now()]);
    }

    /** @return array<string, float> */
    public function confirmedQuantities(int $allocationId): array
    {
        $totals = [];
        foreach (DB::table('relief_delivery_events')->where('allocation_id', $allocationId)->where('status', 'confirmed')->whereIn('kind', ['delivered', 'partial'])->pluck('items') as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $item => $quantity) {
                $totals[(string) $item] = ($totals[(string) $item] ?? 0) + (float) $quantity;
            }
        }

        return $totals;
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The head's review of a held event: confirm (only if quantities still allow) or reject, with a recorded note.
     */
    public function resolve(User $head, int $eventId, bool $confirm, string $note, OrphanAccess $access): void
    {
        abort_unless($access->isHead($head), 403, 'مراجعة نتائج الميدان لرئيس قسم الكفالات.');
        if (mb_strlen(trim($note)) < 5) {
            abort(422, 'وثّق أساس القرار.');
        }
        DB::transaction(function () use ($head, $eventId, $confirm, $note): void {
            $event = DB::table('relief_delivery_events')->where('id', $eventId)->lockForUpdate()->first();
            abort_unless($event && $event->status === 'conflict_held', 404);
            $allocation = DB::table('relief_allocations')->where('id', $event->allocation_id)->lockForUpdate()->first();
            if ($confirm) {
                if ($allocation->status === 'cancelled') {
                    abort(422, 'المخصص ملغى؛ التسليم الفعلي يُعالج باستثناء يقرره المدير التنفيذي.');
                }
                $allocated = array_map('floatval', json_decode($allocation->items, true) ?: []);
                $delivered = $this->confirmedQuantities((int) $allocation->id);
                foreach (json_decode((string) $event->items, true) ?: [] as $item => $quantity) {
                    if ((float) $quantity + ($delivered[$item] ?? 0) > ($allocated[$item] ?? 0) + 0.0001) {
                        abort(422, 'التأكيد يتجاوز كمية المخصص؛ لا يُؤكد تسليم مكرر.');
                    }
                }
                if (in_array($event->kind, ['delivered', 'partial'], true) && (! $event->signature_hash || ! $event->signature_path)) {
                    abort(422, 'لا يُؤكد تسليم دون توقيع المستلم.');
                }
            }
            DB::table('relief_delivery_events')->where('id', $eventId)->update(['status' => $confirm ? 'confirmed' : 'rejected', 'reviewed_by' => $head->id, 'reviewed_at' => now(),
                'review_note' => $note, 'updated_at' => now()]);
            if ($confirm) {
                $this->applyToAllocation($allocation, $event->kind);
            } elseif ($allocation->status === 'conflict') {
                $other = DB::table('relief_delivery_events')->where('allocation_id', $allocation->id)->where('status', 'conflict_held')->where('id', '<>', $eventId)->exists();
                DB::table('relief_allocations')->where('id', $allocation->id)->update(['status' => $other ? 'conflict' : ($this->confirmedQuantities((int) $allocation->id) ? 'partially_delivered' : 'not_delivered'), 'updated_at' => now()]);
            }
            $this->audit->recordRow('relief.delivery.reviewed', 'relief_delivery_events', $event->event_uuid, ['status' => 'conflict_held'], ['status' => $confirm ? 'confirmed' : 'rejected', 'note' => $note], $head->id, request()?->ip());
        });
    }
}
