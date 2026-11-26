<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\OrphanChild;
use App\Models\OrphanHealthRecord;
use App\Models\OrphanHousehold;
use App\Models\OrphanParent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Family files, parents and children kept by the department. Before a file is activated the department edits it
 * freely; once active, identity, death, guardianship, eligibility and family facts change only through a reviewed
 * change request, so an approved value is never silently overwritten. Education years and health snapshots are
 * added as history rows by authorised staff.
 */
final class OrphanRegistry
{
    private const ADDRESS_FIELDS = ['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id'];

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanForms $forms,
        private readonly AuditService $audit,
    ) {}

    public function number(string $sequence, string $prefix): string
    {
        return $prefix.str_pad((string) DB::selectOne("select nextval('{$sequence}') as n")->n, 6, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $input */
    public function createHousehold(User $actor, array $input): OrphanHousehold
    {
        $this->access->assert($actor, 'records');
        $data = $this->forms->validate('household', $input, $this->forms->base('household'));
        $original = $this->address($input['original_address'] ?? [], 'original_address.');
        $current = $this->address($input['current_address'] ?? [], 'current_address.');

        return DB::transaction(function () use ($actor, $data, $original, $current): OrphanHousehold {
            $household = OrphanHousehold::create($data + [
                'public_id' => (string) Str::ulid(), 'reference_no' => $this->number('orphan_household_ref_seq', 'FAM-'),
                'status' => 'draft', 'created_by' => $actor->id,
            ]);
            $this->storeAddress($household, 'original', $original, $actor);
            $this->storeAddress($household, 'current', $current, $actor);
            $this->audit->recordRow('orphans.household.created', 'orphan_households', $household->id, null, ['reference' => $household->reference_no], $actor->id, request()?->ip());

            return $household;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateHousehold(User $actor, OrphanHousehold $household, array $input): void
    {
        $this->access->assert($actor, 'records');
        $this->assertEditable($household);
        $data = $this->forms->validate('household', $input, $this->forms->base('household'));
        $original = isset($input['original_address']) ? $this->address($input['original_address'], 'original_address.') : null;
        $current = isset($input['current_address']) ? $this->address($input['current_address'], 'current_address.') : null;
        DB::transaction(function () use ($actor, $household, $data, $original, $current): void {
            $locked = OrphanHousehold::whereKey($household->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);
            $locked->fill($data)->save();
            foreach (['original' => $original, 'current' => $current] as $kind => $address) {
                if ($address !== null) {
                    $this->replaceAddress($locked, $kind, $address, $actor);
                }
            }
            $this->audit->recordRow('orphans.household.updated', 'orphan_households', $locked->id, null, ['fields' => array_keys($data)], $actor->id, request()?->ip());
        });
    }

    /**
     * Records a father or mother for the family. A parent already on file (same identity) is linked only when
     * the user asks for it explicitly; records are never merged automatically.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveParent(User $actor, OrphanHousehold $household, string $role, array $input, ?OrphanParent $parent = null): OrphanParent
    {
        $this->access->assert($actor, 'records');
        abort_unless(in_array($role, ['father', 'mother'], true), 404);
        $this->assertEditable($household);
        if ($parent && ($input['identity_no'] ?? '') === '' && $parent->identity_no) {
            // An empty number on edit keeps the stored one (the screen never receives it in full).
            $input['identity_no'] = $parent->identity_no;
        }
        $data = $this->forms->validate($role, $input, $this->forms->base($role));
        $data['disability_types'] = $this->forms->disabilityCodes($data['disability_types'] ?? null, 'disability_types');
        if (isset($data['death_date'], $data['date_of_birth']) && $data['death_date'] < $data['date_of_birth']) {
            throw ValidationException::withMessages(['death_date' => 'تاريخ الوفاة يسبق تاريخ الميلاد.']);
        }
        $hash = empty($data['identity_no']) ? null : RegistryIdentity::hash((string) $data['identity_no']);

        return DB::transaction(function () use ($actor, $household, $role, $data, $hash, $parent, $input): OrphanParent {
            $existing = $hash ? OrphanParent::where('identity_hash', $hash)->lockForUpdate()->first() : null;
            if ($existing && (! $parent || $existing->id !== $parent->id)) {
                if (empty($input['link_existing']) || $existing->role !== $role) {
                    $files = DB::table('orphan_household_parents as l')->join('orphan_households as h', 'h.id', '=', 'l.household_id')
                        ->where('l.parent_id', $existing->id)->pluck('h.reference_no')->implode('، ');
                    throw ValidationException::withMessages(['identity_no' => 'صاحب هذه الوثيقة مسجل مسبقًا'.($files ? " في الملف {$files}" : '')
                        .($existing->role === $role ? '؛ اختر «ربط بالسجل الموجود» بدل إنشاء سجل مكرر.' : ' بصفة مختلفة؛ يلزم التدقيق.')]);
                }
                $this->linkParent($household, $existing, $actor);

                return $existing;
            }
            $values = Arr::except($data, ['identity_no']) + ['identity_no' => $data['identity_no'] ?? null, 'identity_hash' => $hash];
            if ($parent) {
                abort_unless(DB::table('orphan_household_parents')->where('household_id', $household->id)->where('parent_id', $parent->id)->exists(), 404);
                $parent->fill($values)->save();
            } else {
                $parent = OrphanParent::create($values + ['role' => $role, 'provenance' => 'declared', 'created_by' => $actor->id]);
            }
            $this->linkParent($household, $parent, $actor);
            $this->audit->recordRow('orphans.parent.saved', 'orphan_parents', $parent->id, null, ['household' => $household->reference_no, 'role' => $role], $actor->id, request()?->ip());

            return $parent;
        });
    }

    /** @param array<string, mixed> $input */
    public function saveChild(User $actor, OrphanHousehold $household, array $input, ?OrphanChild $child = null): OrphanChild
    {
        $this->access->assert($actor, 'records');
        $this->assertEditable($household);
        if ($child && in_array($child->status, ['active', 'suspended', 'closed'], true)) {
            throw ValidationException::withMessages(['child' => 'ملف الطفل معتمد؛ تعديل بياناته يتم بطلب تعديل يعتمده المدير التنفيذي.']);
        }
        if ($child && ($input['identity_no'] ?? '') === '' && $child->national_id) {
            $input['identity_no'] = $child->national_id;
        }
        $data = $this->forms->validate('child', $input, $this->forms->base('child'));
        $parents = DB::table('orphan_household_parents as l')->join('orphan_parents as p', 'p.id', '=', 'l.parent_id')
            ->where('l.household_id', $household->id)->pluck('p.role', 'p.id');
        foreach (['father_id' => 'father', 'mother_id' => 'mother'] as $field => $role) {
            $id = $input[$field] ?? null;
            if ($id !== null && $id !== '' && ($parents[(int) $id] ?? null) !== $role) {
                throw ValidationException::withMessages([$field => 'اختر '.($role === 'father' ? 'الأب' : 'الأم').' من سجل هذه الأسرة.']);
            }
            $data[$field] = $id ? (int) $id : null;
        }
        $hash = empty($data['identity_no']) ? null : RegistryIdentity::hash((string) $data['identity_no']);
        $names = Arr::only($data, ['first_name', 'father_name', 'grandfather_name', 'family_name']);
        $values = Arr::except($data, ['first_name', 'father_name', 'grandfather_name', 'family_name', 'identity_no']) + [
            'name_parts' => $names, 'full_name' => implode(' ', array_filter($names)),
            'national_id' => $data['identity_no'] ?? null, 'national_id_hash' => $hash, 'sole_survivor' => (bool) ($data['sole_survivor'] ?? false),
        ];

        return DB::transaction(function () use ($actor, $household, $child, $values, $hash): OrphanChild {
            if ($hash && OrphanChild::where('national_id_hash', $hash)->when($child, fn ($q) => $q->whereKeyNot($child->id))->exists()) {
                throw ValidationException::withMessages(['identity_no' => 'رقم الوثيقة مسجل لطفل آخر؛ لا يُنشأ ملف مكرر ولا يُدمج تلقائيًا.']);
            }
            if ($child) {
                abort_unless((int) $child->household_id === (int) $household->id, 404);
                $child->fill($values)->save();
            } else {
                $child = OrphanChild::create($values + [
                    'public_id' => (string) Str::ulid(), 'source' => 'staff', 'source_child_id' => (string) Str::ulid(), 'household_id' => $household->id,
                    'status' => 'applicant', 'verification_status' => 'unverified', 'registered_at' => now(), 'created_by' => $actor->id,
                ]);
            }
            $this->audit->recordRow('orphans.child.saved', 'orphan_children', $child->id, null, ['household' => $household->reference_no], $actor->id, request()?->ip());

            return $child;
        });
    }

    /** One education snapshot per school year; a later year never rewrites an earlier one. @param array<string, mixed> $input */
    public function saveEducation(User $actor, OrphanChild $child, array $input): void
    {
        $this->access->assert($actor, 'records');
        $this->writeEducation($actor->id, $child, $input);
    }

    /** Writes an education year without a capability check: used by staff entry (checked above) and approved change requests. */
    public function writeEducation(int $actorId, OrphanChild $child, array $input): void
    {
        $data = $this->forms->validate('education', $input, $this->forms->base('education'));
        DB::table('orphan_education_records')->upsert([[...$data, 'orphan_child_id' => $child->id, 'recorded_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]],
            ['orphan_child_id', 'school_year'], [...array_keys(Arr::except($data, ['school_year'])), 'recorded_by', 'updated_at']);
        $this->audit->recordRow('orphans.education.saved', 'orphan_children', $child->id, null, ['school_year' => $data['school_year']], $actorId, request()?->ip());
    }

    /** Adds a health snapshot; the previous one stays as history. @param array<string, mixed> $input */
    public function saveHealth(User $actor, OrphanChild $child, array $input): void
    {
        $this->access->assert($actor, 'records');
        $this->access->assert($actor, 'sensitive');
        $this->writeHealth($actor->id, $child, $input);
    }

    /** @param array<string, mixed> $input */
    public function writeHealth(int $actorId, OrphanChild $child, array $input): void
    {
        $data = $this->forms->validate('health', $input, $this->forms->base('health'));
        $data['disability_types'] = $this->forms->disabilityCodes($data['disability_types'] ?? null, 'disability_types');
        $actor = (object) ['id' => $actorId];
        DB::transaction(function () use ($actor, $child, $data): void {
            DB::table('orphan_children')->where('id', $child->id)->lockForUpdate()->first();
            OrphanHealthRecord::where('orphan_child_id', $child->id)->where('is_current', true)->update(['is_current' => false, 'updated_at' => now()]);
            OrphanHealthRecord::create($data + ['orphan_child_id' => $child->id, 'is_current' => true, 'recorded_by' => $actor->id]);
            $this->audit->recordRow('orphans.health.recorded', 'orphan_children', $child->id, null, ['fields' => 'health snapshot'], $actor->id, request()?->ip());
        });
    }

    /**
     * Registers the family's guardian (or links one already on file by identity). A guardian created by staff
     * has no portal access until the portal account is activated.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveGuardian(User $actor, OrphanHousehold $household, array $input): Guardian
    {
        $this->assertEditable($household);
        $authority = $input['guardian_authority_value_id'] ?? null;
        if ($authority !== null && $authority !== '' && ! DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'guardian_authority_kinds')->where('v.id', (int) $authority)->exists()) {
            throw ValidationException::withMessages(['guardian_authority_value_id' => 'صفة التمثيل غير متاحة في الثوابت.']);
        }
        $guardian = $this->upsertGuardianPerson($actor, $input);
        OrphanHousehold::whereKey($household->id)->update(['primary_guardian_id' => $guardian->id, 'guardian_authority_value_id' => $authority ?: null, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.guardian.linked', 'orphan_households', $household->id, null, ['guardian' => $guardian->reference_no], $actor->id, request()?->ip());

        return $guardian;
    }

    /**
     * Records a guardian person (or updates the one with the same identity) without linking a family; an active
     * family changes guardian only through an approved change request.
     *
     * @param  array<string, mixed>  $input
     */
    public function upsertGuardianPerson(User $actor, array $input): Guardian
    {
        $this->access->assert($actor, 'records');
        $data = $this->forms->validate('guardian', $input, $this->forms->base('guardian'));
        $hash = RegistryIdentity::hash((string) $data['identity_no']);

        return DB::transaction(function () use ($actor, $data, $hash): Guardian {
            $guardian = Guardian::where('national_id_hash', $hash)->lockForUpdate()->first();
            if (! $guardian) {
                if (Guardian::where('phone', $data['phone'])->exists()) {
                    throw ValidationException::withMessages(['phone' => 'رقم الجوال مسجل لوصي آخر.']);
                }
                $guardian = new Guardian;
                $guardian->forceFill([
                    'name' => $data['name'], 'national_id' => $data['identity_no'], 'national_id_hash' => $hash, 'phone' => $data['phone'],
                    'password' => Hash::make(Str::random(40)), 'is_active' => false, 'reference_no' => $this->number('guardian_ref_seq', 'GRD-'),
                    'created_by' => $actor->id,
                ]);
            }
            $guardian->forceFill(Arr::only($data, ['identity_type_value_id', 'date_of_birth', 'sex', 'email', 'occupation', 'employer'])
                + ['alt_phone' => $data['alt_phone'] ?? null, 'reference_no' => $guardian->reference_no ?: $this->number('guardian_ref_seq', 'GRD-')])->save();
            $this->audit->recordRow('orphans.guardian.saved', 'guardians', $guardian->id, null, ['guardian' => $guardian->reference_no], $actor->id, request()?->ip());

            return $guardian;
        });
    }

    public function assertEditable(OrphanHousehold $household): void
    {
        if (in_array($household->status, ['active', 'inactive', 'closed'], true)) {
            throw ValidationException::withMessages(['household' => 'الملف معتمد؛ التعديل يتم بطلب تعديل يراجعه رئيس القسم ويقرره المدير التنفيذي.']);
        }
        $open = DB::table('orphan_applications')->where('household_id', $household->id)->whereIn('status', ['in_decision'])->exists();
        if ($open) {
            throw ValidationException::withMessages(['household' => 'الطلب أمام رئيس القسم أو المدير التنفيذي؛ لا تُعدل البيانات أثناء البت.']);
        }
    }

    /** @return array<string, mixed> */
    public function address(array $input, string $prefix): array
    {
        $data = validator($input, [
            'governorate_value_id' => ['nullable', 'integer', 'exists:master_data_values,id'], 'locality_value_id' => ['nullable', 'integer', 'exists:master_data_values,id'],
            'area_value_id' => ['nullable', 'integer', 'exists:master_data_values,id'], 'subarea_value_id' => ['nullable', 'integer', 'exists:master_data_values,id'],
            'governorate' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:120'], 'area' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['nullable', 'string', 'max:120'], 'address_other' => ['boolean'], 'address_details' => ['nullable', 'string', 'max:1000', 'required_if:address_other,true'],
            'landmark' => ['nullable', 'string', 'max:300'],
        ], [], ['governorate_value_id' => 'المحافظة', 'address_details' => 'وصف العنوان', 'landmark' => 'أقرب معلم'])->validate();
        if (empty($data['governorate_value_id']) && empty($data['address_other'])) {
            throw ValidationException::withMessages([$prefix.'governorate_value_id' => 'اختر المحافظة على الأقل أو صف العنوان غير المدرج.']);
        }

        return $data;
    }

    private function storeAddress(OrphanHousehold $household, string $kind, array $data, User $actor): void
    {
        DB::table('orphan_addresses')->insert(Arr::only($data, [...self::ADDRESS_FIELDS, 'governorate', 'city', 'area', 'neighborhood']) + [
            'household_id' => $household->id, 'kind' => $kind, 'address_other' => (bool) ($data['address_other'] ?? false),
            'address_details' => isset($data['address_details']) ? encrypt($data['address_details'], false) : null,
            'landmark' => isset($data['landmark']) ? encrypt($data['landmark'], false) : null,
            'valid_from' => today()->toDateString(), 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Keeps the previous address as history (displacement trail) and opens the new one from today. */
    public function replaceAddress(OrphanHousehold $household, string $kind, array $data, User $actor): void
    {
        $current = DB::table('orphan_addresses')->where('household_id', $household->id)->where('kind', $kind)->whereNull('valid_to')->lockForUpdate()->first();
        if ($current && $current->valid_from === today()->toDateString()) {
            // A correction on the day the address was entered amends that entry instead of opening a one-day history row.
            DB::table('orphan_addresses')->where('id', $current->id)->update(Arr::only($data, [...self::ADDRESS_FIELDS, 'governorate', 'city', 'area', 'neighborhood']) + [
                'address_other' => (bool) ($data['address_other'] ?? false),
                'address_details' => isset($data['address_details']) ? encrypt($data['address_details'], false) : null,
                'landmark' => isset($data['landmark']) ? encrypt($data['landmark'], false) : null,
                'recorded_by' => $actor->id, 'updated_at' => now(),
            ]);

            return;
        }
        if ($current) {
            DB::table('orphan_addresses')->where('id', $current->id)->update(['valid_to' => today()->subDay()->toDateString(), 'updated_at' => now()]);
        }
        $this->storeAddress($household, $kind, $data, $actor);
    }

    private function linkParent(OrphanHousehold $household, OrphanParent $parent, User $actor): void
    {
        DB::table('orphan_household_parents')->insertOrIgnore(['household_id' => $household->id, 'parent_id' => $parent->id, 'linked_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed>|null decrypted current address of a kind */
    public function currentAddress(int $householdId, string $kind): ?array
    {
        $row = DB::table('orphan_addresses')->where('household_id', $householdId)->where('kind', $kind)->whereNull('valid_to')->first();
        if (! $row) {
            return null;
        }

        return Arr::only((array) $row, [...self::ADDRESS_FIELDS, 'governorate', 'city', 'area', 'neighborhood', 'address_other', 'valid_from']) + [
            'address_details' => $row->address_details ? decrypt($row->address_details, false) : null,
            'landmark' => $row->landmark ? decrypt($row->landmark, false) : null,
        ];
    }
}
