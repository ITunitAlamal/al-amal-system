<?php

namespace App\Services\Orphans\Field;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\Campaigns\Campaigns;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Receives events recorded offline. Each event is the exact JSON the device signed. A retried event (same UUID)
 * returns its stored outcome. A clean event becomes a campaign delivery whose idempotency key is the event UUID, so
 * a repeated sync cannot deliver, decrement stock or add benefit twice. Doubtful events are held for the head with
 * their evidence; nothing is dropped.
 */
final class FieldSync
{
    public const STATUS_LABELS = ['confirmed' => 'مؤكد ومطابق', 'conflict_held' => 'معلق لتعارض', 'rejected' => 'مرفوض مع السبب'];

    private const CLOCK_TOLERANCE_SECONDS = 600;

    public function __construct(private readonly AuditService $audit, private readonly Campaigns $campaigns, private readonly FieldBatches $batches) {}

    /** @return array{event_uuid: ?string, status: string, reason: ?string} */
    public function receive(User $user, object $device, array $upload): array
    {
        $payload = json_decode($upload['payload'], true);
        if (! is_array($payload) || ! preg_match('/^[0-9a-f-]{36}$/', (string) ($payload['event_uuid'] ?? ''))) {
            return ['event_uuid' => null, 'status' => 'rejected', 'reason' => 'صيغة الحدث غير صالحة.'];
        }
        $uuid = (string) $payload['event_uuid'];
        if ($existing = DB::table('field_events')->where('event_uuid', $uuid)->first()) {
            return ['event_uuid' => $uuid, 'status' => $existing->status, 'reason' => $existing->status_reason];
        }
        if (! FieldKeys::verifyDevice($upload['payload'], $upload['signature'], $device->public_key)) {
            return ['event_uuid' => $uuid, 'status' => 'rejected', 'reason' => 'توقيع الجهاز غير صحيح.'];
        }
        try {
            return DB::transaction(fn (): array => $this->store($user, $device, $upload, $payload, $uuid));
        } catch (UniqueConstraintViolationException) {
            $stored = DB::table('field_events')->where('event_uuid', $uuid)->first();

            return ['event_uuid' => $uuid, 'status' => $stored->status ?? 'rejected', 'reason' => $stored->status_reason ?? null];
        }
    }

    private function store(User $user, object $device, array $upload, array $payload, string $uuid): array
    {
        $batch = DB::table('campaign_batches')->where('public_id', (string) ($payload['batch'] ?? ''))->lockForUpdate()->first();
        $slot = $batch ? DB::table('campaign_batch_entries')->where('public_id', (string) ($payload['allocation'] ?? ''))->where('batch_id', $batch->id)->first() : null;
        if (! $batch || ! $slot || (int) $batch->distributor_user_id !== (int) $user->id || (int) $batch->device_id !== (int) $device->id) {
            return ['event_uuid' => $uuid, 'status' => 'rejected', 'reason' => 'الدفعة أو المستفيد لا يخص هذا الموزع وجهازه.'];
        }
        $campaign = DB::table('campaigns')->find($batch->campaign_id);
        $kind = (string) ($payload['kind'] ?? '');
        $problems = [];
        $hold = [];
        if (! in_array($kind, ['delivered', 'partial', 'not_delivered', 'referral'], true)) {
            $problems[] = 'نوع الحدث غير معروف.';
        }
        if ($kind === 'partial') {
            $problems[] = 'التسليم الجزئي غير مدعوم؛ كل مستفيد يستلم حصته كاملة أو يُسجَّل «لم يُسلَّم».';
        }
        $manifest = DB::table('campaign_batch_manifests')->where('batch_id', $batch->id)->where('version', (int) ($payload['manifest_version'] ?? 0))->first();
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
                $hold[] = 'وقت التسليم المسجل غير منطقي مقارنة بوقت التنزيل ووقت الخادم.';
            }
            $elapsed = (int) ($payload['clock']['elapsed_ms'] ?? -1);
            if ($elapsed < 0 || abs(($occurred->getTimestamp() - $issued->getTimestamp()) * 1000 - $elapsed) > self::CLOCK_TOLERANCE_SECONDS * 1000) {
                $hold[] = 'ساعة الجهاز لا تتسق مع الزمن المنقضي منذ التنزيل.';
            }
            if ($occurred->gt($expires)) {
                $hold[] = 'سُجّل بعد انتهاء صلاحية العمل دون اتصال.';
            }
        }
        $previous = DB::table('field_events')->where('device_id', $device->id)->orderByDesc('id')->value('event_hash');
        if (($payload['prev_hash'] ?? null) !== null && $previous !== null && $payload['prev_hash'] !== $previous
            && ! DB::table('field_events')->where('device_id', $device->id)->where('event_hash', $payload['prev_hash'])->exists()) {
            $hold[] = 'سلسلة أحداث الجهاز غير متصلة (حدث سابق لم يصل).';
        }
        if ($batch->status === 'revoked' || ! $slot->is_active) {
            $hold[] = 'أُلغيت الدفعة أو نُقل المستفيد منها قبل وصول الحدث.';
        }
        // The current approved version decides: a unit removed by an approved change is held, never auto-delivered.
        $entry = DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->where('l.campaign_id', $campaign->id)->where('l.status', 'approved')
            ->where('e.unit_key', $slot->unit_key)->where('e.kind', 'primary')->first(['e.*']);
        if (! $entry) {
            $hold[] = 'المستفيد لم يعد في الكشف الأساسي المعتمد الحالي.';
        }
        $receiver = is_array($payload['receiver'] ?? null) ? $payload['receiver'] : [];
        $signaturePath = null;
        if ($kind === 'delivered') {
            $image = isset($upload['signature_image']) ? base64_decode((string) $upload['signature_image'], true) : false;
            if ($image === false || $image === '' || strlen($image) > 512 * 1024 || ! str_starts_with($image, "\x89PNG") || ! hash_equals((string) ($payload['signature_sha256'] ?? ''), hash('sha256', $image))) {
                $problems[] = 'توقيع المستلم مفقود أو لا يطابق بصمته؛ لا يُؤكد تسليم دون توقيع.';
            } else {
                $signaturePath = 'field/signatures/'.$uuid.'.bin';
                Storage::disk('local')->put($signaturePath, Crypt::encryptString(base64_encode($image)));
            }
            if (empty($receiver['attested']) || ($receiver['relation'] ?? '') !== 'guardian') {
                $hold[] = 'المستلم ليس المستلم المعتمد أو لم يُقرّ الموزع بالتحقق من هويته.';
            }
            $expected = $entry ? $this->batches->receiver($entry)['id_last4'] : null;
            if (! $expected || ! hash_equals((string) $expected, (string) ($receiver['id_last4'] ?? ''))) {
                $hold[] = 'آخر أرقام وثيقة المستلم لا تطابق المستلم المعتمد.';
            }
            $items = is_array($payload['items'] ?? null) ? array_map('floatval', $payload['items']) : [];
            if ($entry && (count($items) !== 1 || abs(array_sum($items) - (float) $entry->quantity) > 0.0001)) {
                $problems[] = 'الكمية لا تطابق حصة المستفيد في الكشف المعتمد.';
            }
            if ($entry && $this->campaigns->entryState($campaign->id, $entry->unit_key) === 'delivered') {
                $hold[] = 'سُجّل تسليم سابق لهذا المستفيد (احتمال تسليم مكرر).';
            }
        } elseif (in_array($kind, ['not_delivered', 'referral'], true) && ! $this->failureReason((string) ($payload['failure_reason'] ?? ''))) {
            $problems[] = 'سبب عدم التسليم غير معروف.';
        }
        $status = $problems !== [] ? 'rejected' : ($hold !== [] ? 'conflict_held' : 'confirmed');
        $reason = implode(' ', array_merge($problems, $hold)) ?: null;
        $eventId = DB::table('field_events')->insertGetId(['event_uuid' => $uuid, 'device_id' => $device->id, 'user_id' => $user->id, 'batch_id' => $batch->id, 'batch_entry_id' => $slot->id,
            'kind' => $kind ?: 'unknown', 'payload' => $upload['payload'], 'device_signature' => $upload['signature'], 'event_hash' => hash('sha256', $upload['payload']), 'prev_event_hash' => $payload['prev_hash'] ?? null,
            'occurred_at' => $occurred?->copy()->setTimezone(config('app.timezone')), 'status' => $status, 'status_reason' => $reason, 'signature_path' => $signaturePath, 'received_at' => now()]);
        if ($status === 'confirmed') {
            $this->apply($eventId, $campaign, $entry, $payload, $user, $device->id, $signaturePath);
        }
        DB::table('field_devices')->where('id', $device->id)->update(['last_sync_at' => now()]);
        $this->audit->recordRow('orphans.field.event_'.$status, 'field_events', $eventId, null, ['batch' => $batch->reference_no, 'kind' => $kind], $user->id, request()?->ip());

        return ['event_uuid' => $uuid, 'status' => $status, 'reason' => $reason];
    }

    /** Turns a confirmed event into the campaign delivery (idempotency key = event UUID). */
    private function apply(int $eventId, object $campaign, object $entry, array $payload, User $user, int $deviceId, ?string $signaturePath): void
    {
        $kind = $payload['kind'] === 'delivered' ? 'delivered' : 'undelivered';
        $receiver = $this->batches->receiver($entry);
        $result = $this->campaigns->deliver($campaign, (int) $entry->id, ['idempotency_key' => $payload['event_uuid'], 'kind' => $kind,
            'recipient_name' => $kind === 'delivered' ? $receiver['name'] : null, 'recipient_relation' => $kind === 'delivered' ? 'guardian' : null, 'identity_checked' => $kind === 'delivered',
            'failure_reason_value_id' => $kind === 'undelivered' ? $this->failureReason((string) ($payload['failure_reason'] ?? '')) : null,
            'note' => trim(($payload['kind'] === 'referral' ? 'إحالة للمراجعة. ' : '').mb_substr((string) ($payload['note'] ?? ''), 0, 500)) ?: null,
            'occurred_at' => $this->time($payload['occurred_at'])?->setTimezone(config('app.timezone')), 'signature_path' => $signaturePath], $user, $deviceId);
        DB::table('field_events')->where('id', $eventId)->update(['delivery_id' => $result['delivery_id']]);
    }

    /** The head's decision on a held event: confirm (still subject to every delivery rule) or reject, with a note. */
    public function resolve(User $head, int $eventId, bool $confirm, string $note, OrphanAccess $access): void
    {
        abort_unless($access->isHead($head), 403, 'مراجعة نتائج الميدان لرئيس القسم.');
        abort_if(mb_strlen(trim($note)) < 5, 422, 'وثّق أساس القرار.');
        DB::transaction(function () use ($head, $eventId, $confirm, $note): void {
            $event = DB::table('field_events')->where('id', $eventId)->lockForUpdate()->first();
            abort_unless($event && $event->status === 'conflict_held', 404);
            $payload = json_decode($event->payload, true);
            if ($confirm) {
                $slot = DB::table('campaign_batch_entries')->find($event->batch_entry_id);
                $campaign = DB::table('campaigns')->find($slot->campaign_id);
                $entry = DB::table('campaign_list_entries as e')->join('campaign_lists as l', 'l.id', '=', 'e.list_id')->where('l.campaign_id', $campaign->id)->where('l.status', 'approved')
                    ->where('e.unit_key', $slot->unit_key)->where('e.kind', 'primary')->first(['e.*']);
                abort_unless($entry, 422, 'المستفيد غير موجود في الكشف المعتمد الحالي؛ أي تسليم خارجه يحتاج طلب تعديل يعتمده المدير التنفيذي.');
                abort_if($event->kind === 'delivered' && ! $event->signature_path, 422, 'لا يُؤكد تسليم دون توقيع المستلم.');
                $user = User::findOrFail($event->user_id);
                $this->apply($eventId, $campaign, $entry, $payload, $user, (int) $event->device_id, $event->signature_path);
            }
            DB::table('field_events')->where('id', $eventId)->update(['status' => $confirm ? 'confirmed' : 'rejected', 'reviewed_by' => $head->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->recordRow('orphans.field.event_reviewed', 'field_events', $eventId, ['status' => 'conflict_held'], ['status' => $confirm ? 'confirmed' : 'rejected', 'note' => $note], $head->id, request()?->ip());
        });
    }

    private function failureReason(string $code): ?int
    {
        return $code === '' ? null : DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'relief_failure_reasons')->where('v.code', $code)->value('v.id');
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
