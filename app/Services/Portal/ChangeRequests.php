<?php

namespace App\Services\Portal;

use App\Models\Guardian;
use App\Models\Orphan;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\GazaAddress;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\Identity;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\Registry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «طلب تعديل البيانات»: a beneficiary proposes new values for an official record. Nothing changes until a staff
 * member with the «portal» capability approves the request.
 *
 * On approval, three groups are applied through the normal registry services:
 * - phone;
 * - current address (a new address period; the original address is never touched);
 * - school (a new dated education record).
 * The other groups (household, health, parent status, guardianship, other) are reviewed and then applied by
 * staff in the record itself; custody is never changed from here. The current values and the proposal are
 * kept, encrypted, so the reviewer can compare them.
 */
final class ChangeRequests
{
    public const GROUPS = ['phone' => 'رقم الجوال', 'current_address' => 'مكان السكن الحالي', 'school' => 'بيانات المدرسة', 'household' => 'بيانات الأسرة',
        'health' => 'المعلومات الصحية', 'parent_status' => 'حالة الأب أو الأم', 'guardianship' => 'الوصاية', 'other' => 'أخرى'];

    public const AUTO_APPLY = ['phone', 'current_address', 'school'];

    public const STATUS = ['submitted' => 'تم تقديم الطلب', 'under_review' => 'قيد المراجعة', 'needs_info' => 'بحاجة إلى استكمال البيانات', 'approved' => 'معتمد', 'rejected' => 'مرفوض'];

    /** Statuses still open for a staff decision. */
    private const OPEN = ['submitted', 'under_review', 'needs_info'];

    public function __construct(private readonly PortalScope $scope, private readonly Registry $registry, private readonly GazaAddress $geo, private readonly OrphanAccess $access,
        private readonly AuditService $audit, private readonly PortalNotifier $notifier) {}

    /** Groups allowed for a subject. */
    public static function groupsFor(string $subject): array
    {
        return $subject === 'guardian' ? ['phone', 'current_address', 'household', 'other'] : ['current_address', 'school', 'household', 'health', 'parent_status', 'guardianship', 'other'];
    }

    public function submit(Guardian $account, ?object $orphan, string $group, array $proposed, ?string $reason, string $idempotencyKey): object
    {
        $subject = $orphan ? 'child' : 'guardian';
        abort_unless(in_array($group, self::groupsFor($subject), true), 422, 'نوع التعديل غير متاح لهذا السجل.');
        abort_unless($this->scope->linked($account), 403, 'يتاح طلب تعديل السجلات الرسمية بعد اعتماد حسابك.');
        if ($existing = DB::table('portal_change_requests')->where('guardian_account_id', $account->id)->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing; // the same submission repeated after a lost connection
        }
        $proposed = $this->clean($group, $proposed);

        return DB::transaction(function () use ($account, $orphan, $subject, $group, $proposed, $reason, $idempotencyKey): object {
            $id = DB::table('portal_change_requests')->insertGetId([
                'public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('CHG', 'portal_change_seq'), 'guardian_account_id' => $account->id,
                'orphan_id' => $orphan?->id, 'subject' => $subject, 'field_group' => $group, 'proposed' => Crypt::encryptString(json_encode($proposed, JSON_UNESCAPED_UNICODE)),
                'current_snapshot' => Crypt::encryptString(json_encode($this->current($account, $orphan, $group), JSON_UNESCAPED_UNICODE)), 'reason' => $reason,
                'status' => 'submitted', 'idempotency_key' => $idempotencyKey, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('portal_change_requests')->find($id);
            $this->notifier->notify($account->id, 'info', 'استلمنا طلب التعديل '.$row->reference_no, '/guardian/requests', null, 'chg-sent-'.$id);
            foreach ($this->access->headUserIds() as $userId) {
                app(NotificationCenter::class)->notify($userId, 'orphans_portal_change', ['title' => 'طلب تعديل بيانات جديد '.$row->reference_no,
                    'url' => '/orphans/reviews/changes', 'dedupe' => 'portal-change-'.$id.'-'.$userId]);
            }

            return $row;
        });
    }

    /** The beneficiary answers a «needs info» message. */
    public function respond(Guardian $account, string $publicId, string $note): void
    {
        $row = DB::table('portal_change_requests')->where('public_id', $publicId)->where('guardian_account_id', $account->id)->firstOrFail();
        abort_unless($row->status === 'needs_info', 409, 'لا يُنتظر منك استكمال لهذا الطلب.');
        // Only while staff still wait for it: a decision taken meanwhile is not reopened.
        $updated = DB::table('portal_change_requests')->where('id', $row->id)->where('status', 'needs_info')->update(['status' => 'submitted',
            'reason' => trim(($row->reason ? $row->reason."\n— " : '').$note), 'updated_at' => now()]);
        abort_if($updated === 0, 409, 'لا يُنتظر منك استكمال لهذا الطلب.');
    }

    /** Staff decision. approve applies the safe groups; reject and needs_info carry a message for the beneficiary. */
    public function decide(object $row, string $action, ?string $publicNote, ?string $internalNote, User $actor): void
    {
        $this->access->assert($actor, 'portal');
        abort_unless(in_array($row->status, self::OPEN, true), 409, 'تم البت في هذا الطلب.');
        if (in_array($action, ['reject', 'needs_info', 'approve'], true) && blank($publicNote)) {
            throw ValidationException::withMessages(['public_note' => 'اكتب الرد الذي يظهر للوصي.']);
        }
        DB::transaction(function () use ($row, $action, $publicNote, $internalNote, $actor): void {
            $row = DB::table('portal_change_requests')->where('id', $row->id)->lockForUpdate()->first();
            // Re-checked under the lock: a request already decided is never applied twice or overturned.
            abort_unless($row && in_array($row->status, self::OPEN, true), 409, 'تم البت في هذا الطلب.');
            $update = ['internal_note' => $internalNote ?? $row->internal_note, 'updated_at' => now()];
            if ($action === 'review') {
                $update += ['status' => 'under_review', 'assigned_to' => $row->assigned_to ?? $actor->id];
            } elseif ($action === 'needs_info') {
                $update += ['status' => 'needs_info', 'public_note' => $publicNote];
            } else {
                $applied = $action === 'approve' && in_array($row->field_group, self::AUTO_APPLY, true) ? $this->apply($row, $actor) : false;
                $update += ['status' => $action === 'approve' ? 'approved' : 'rejected', 'public_note' => $publicNote, 'decided_by' => $actor->id, 'decided_at' => now(),
                    'applied_at' => $applied ? now() : null];
            }
            $updated = DB::table('portal_change_requests')->where('id', $row->id)->whereIn('status', self::OPEN)->update($update);
            abort_if($updated === 0, 409, 'تم البت في هذا الطلب.');
            $this->audit->recordRow('orphans.portal_change.'.$action, 'portal_change_requests', $row->id, null, ['reference' => $row->reference_no, 'group' => $row->field_group], $actor->id, request()?->ip());
            if ($action !== 'review') {
                $this->notifier->notify((int) $row->guardian_account_id, $action === 'needs_info' ? 'action' : 'decision',
                    ($action === 'needs_info' ? 'مطلوب استكمال طلب التعديل ' : 'صدر قرار في طلب التعديل ').$row->reference_no, '/guardian/requests');
            }
        });
    }

    public static function proposed(object $row): array
    {
        return json_decode(Crypt::decryptString($row->proposed), true) ?: [];
    }

    public static function snapshot(object $row): array
    {
        return $row->current_snapshot ? (json_decode(Crypt::decryptString($row->current_snapshot), true) ?: []) : [];
    }

    private function clean(string $group, array $input): array
    {
        return match ($group) {
            'phone' => preg_match('/^\+?[0-9]{7,15}$/', $phone = Identity::clean($input['phone'] ?? '')) ? ['phone' => $phone]
                : throw ValidationException::withMessages(['phone' => 'رقم الجوال غير صحيح.']),
            'current_address' => (function () use ($input): array {
                $n = $this->geo->normalize($input, null, '', true);

                return [...collect($n)->only(['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id', 'governorate', 'city', 'area', 'neighborhood', 'address_details', 'address_other'])->all(),
                    'street' => mb_substr((string) ($input['street'] ?? ''), 0, 200) ?: null, 'landmark' => mb_substr((string) ($input['landmark'] ?? ''), 0, 200) ?: null,
                    'moved_on' => $input['moved_on'] ?? null];
            })(),
            'school' => collect($input)->only(['school_name', 'stage_value_id', 'status_value_id', 'grade', 'academic_year'])->filter(fn ($v) => filled($v))->all()
                ?: throw ValidationException::withMessages(['school_name' => 'اكتب بيانات المدرسة الجديدة.']),
            default => filled($input['details'] ?? null) ? ['details' => mb_substr((string) $input['details'], 0, 3000)]
                : throw ValidationException::withMessages(['details' => 'اكتب التعديل المطلوب.']),
        };
    }

    /** The values the reviewer compares against (no health or identity numbers). */
    private function current(Guardian $account, ?object $orphan, string $group): array
    {
        $personId = $orphan ? $orphan->person_id : $account->person_id;
        $household = DB::table('household_members')->where('person_id', $personId)->whereNull('ends_on')->value('household_id');

        return match ($group) {
            'phone' => ['phone' => $account->phone],
            'current_address' => (array) (DB::table('registry_addresses as a')->leftJoin('master_data_values as g', 'g.id', '=', 'a.governorate_value_id')
                ->leftJoin('master_data_values as l', 'l.id', '=', 'a.locality_value_id')->where('a.kind', 'current')->whereNull('a.ends_on')
                ->where(fn ($q) => $q->where('a.person_id', $personId)->when($household, fn ($w) => $w->orWhere('a.household_id', $household)))
                ->orderByDesc('a.id')->first(['g.name_ar as governorate', 'l.name_ar as city', 'a.detail as address_details', 'a.street', 'a.landmark']) ?? []),
            'school' => $orphan ? (array) (DB::table('education_records')->where('orphan_id', $orphan->id)->orderByDesc('id')->first(['school_name', 'grade', 'academic_year']) ?? []) : [],
            default => [],
        };
    }

    /** Applies phone, current address or school. Returns true when applied. */
    private function apply(object $row, User $actor): bool
    {
        $proposed = self::proposed($row);
        $account = Guardian::findOrFail($row->guardian_account_id);
        $orphan = $row->orphan_id ? DB::table('orphans')->find($row->orphan_id) : null;
        $personId = $orphan ? (int) $orphan->person_id : (int) $account->person_id;
        if ($row->field_group === 'phone') {
            abort_if(Guardian::query()->where('phone', $proposed['phone'])->where('id', '<>', $account->id)->exists(), 409, 'الرقم مستخدم لحساب آخر؛ لا يمكن اعتماده.');
            $account->forceFill(['phone' => $proposed['phone'], 'phone_verified_at' => null, 'phone_verification_method' => null, 'phone_verified_by' => null])->save();
            DB::table('persons')->where('id', $personId)->update(['phone' => $proposed['phone'], 'updated_by' => $actor->id, 'updated_at' => now()]);
        } elseif ($row->field_group === 'current_address') {
            $household = DB::table('household_members')->where('person_id', $personId)->whereNull('ends_on')->value('household_id');
            $address = $this->registry->saveAddress($household ? ['household_id' => (int) $household] : ['person_id' => $personId], 'current', [
                ...collect($proposed)->only(['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id'])->all(), 'detail' => $proposed['address_details'] ?? null,
                'starts_on' => $proposed['moved_on'] ?? null], $actor->id);
            $address->forceFill(array_filter(['street' => $proposed['street'] ?? null, 'landmark' => $proposed['landmark'] ?? null]))->save();
        } else {
            DB::table('education_records')->insert([...collect($proposed)->only(['school_name', 'stage_value_id', 'status_value_id', 'grade', 'academic_year'])->all(),
                'orphan_id' => $row->orphan_id, 'source' => 'caregiver', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->registry->refreshCompleteness(Orphan::findOrFail($row->orphan_id));
        }

        return true;
    }
}
