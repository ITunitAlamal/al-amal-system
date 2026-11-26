<?php

namespace App\Services\Orphans;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Finance\FinanceGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sponsors (donors): a domain separate from guardians even when one person holds both roles. Contact details are
 * encrypted; a confidential sponsor's name is shown only to the sponsorships staff, the executive director and Accounts.
 */
final class Sponsors
{
    public const KINDS = ['person' => 'فرد', 'organization' => 'جهة/مؤسسة'];

    public function __construct(private readonly OrphanAccess $access, private readonly OrphanRegistry $registry, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $sponsorId = null): int
    {
        $this->access->assert($actor, 'sponsorships');
        $data = validator($input, [
            'kind' => ['required', 'in:person,organization'], 'name' => ['required', 'string', 'min:3', 'max:200'], 'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{7,20}$/'], 'email' => ['nullable', 'email', 'max:150'], 'address' => ['nullable', 'string', 'max:500'],
            'preferences' => ['nullable', 'string', 'max:1000'], 'confidential' => ['boolean'], 'status' => ['nullable', 'in:active,inactive'],
        ], [], ['name' => 'اسم الكافل', 'phone' => 'الهاتف', 'preferences' => 'التفضيلات المعتمدة'])->validate();
        $values = ['kind' => $data['kind'], 'name' => trim($data['name']), 'country' => $data['country'] ?? null, 'email' => $data['email'] ?? null,
            'phone' => ! empty($data['phone']) ? Crypt::encryptString($data['phone']) : null, 'address' => ! empty($data['address']) ? Crypt::encryptString($data['address']) : null,
            'preferences' => $data['preferences'] ?? null, 'confidential' => (bool) ($data['confidential'] ?? false), 'updated_at' => now()];

        return DB::transaction(function () use ($actor, $values, $data, $sponsorId): int {
            if ($sponsorId) {
                $before = DB::table('sponsors')->where('id', $sponsorId)->lockForUpdate()->first();
                abort_unless($before, 404);
                DB::table('sponsors')->where('id', $sponsorId)->update($values + ['status' => $data['status'] ?? $before->status]);
                $this->audit->recordRow('sponsors.updated', 'sponsors', $sponsorId, ['status' => $before->status], ['fields' => array_keys($data)], $actor->id, request()?->ip());

                return $sponsorId;
            }
            $id = DB::table('sponsors')->insertGetId($values + ['public_id' => (string) Str::ulid(), 'donor_no' => $this->registry->number('sponsor_ref_seq', 'DNR-'),
                'status' => 'active', 'created_by' => $actor->id, 'created_at' => now()]);
            $this->audit->recordRow('sponsors.created', 'sponsors', $id, null, ['kind' => $values['kind']], $actor->id, request()?->ip());

            return $id;
        });
    }

    /** Whether the user may see a confidential sponsor's identity and the sponsors' contact details. */
    public function seesIdentity(User $user): bool
    {
        return $this->access->allows($user, 'sponsorships') || $this->access->isExecutive($user)
            || (! $user->isSystemAdmin() && app(FinanceGuard::class)->allows($user, 'finance.sponsorships.record'));
    }

    public function displayName(object $sponsor, bool $identity): string
    {
        return $sponsor->confidential && ! $identity ? 'كافل '.$sponsor->donor_no.' (سري)' : $sponsor->name;
    }

    /** @return array<string, mixed> */
    public function present(object $sponsor, bool $identity, bool $contacts): array
    {
        return ['id' => (int) $sponsor->id, 'public_id' => $sponsor->public_id, 'donor_no' => $sponsor->donor_no, 'kind' => $sponsor->kind,
            'kind_label' => self::KINDS[$sponsor->kind] ?? $sponsor->kind, 'name' => $this->displayName($sponsor, $identity), 'country' => $sponsor->country,
            'confidential' => (bool) $sponsor->confidential, 'status' => $sponsor->status,
            'phone' => $contacts ? $this->decrypt($sponsor->phone) : null, 'email' => $contacts ? $sponsor->email : null,
            'address' => $contacts ? $this->decrypt($sponsor->address) : null, 'preferences' => $contacts ? $sponsor->preferences : null];
    }

    private function decrypt(?string $cipher): ?string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
