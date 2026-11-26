<?php

namespace App\Services\Orphans;

final readonly class GuardianRegistryResult
{
    /**
     * Children may also carry official lineage and authority data when the provider returns it:
     * father_national_id, mother_national_id, father_death_confirmed, father_father_national_id,
     * father_mother_national_id, mother_father_national_id, mother_mother_national_id,
     * authority {kind: legal_guardian|custodian|agent, reference, valid_until}.
     *
     * @param  list<array<string, mixed>>  $children
     */
    public function __construct(
        public bool $available,
        public bool $guardianEligible = false,
        public ?string $guardianNationalId = null,
        public ?string $deceasedNationalId = null,
        public ?string $source = null,
        public ?string $reference = null,
        public array $children = [],
        public ?string $guardianFatherNationalId = null,
        public ?string $guardianMotherNationalId = null,
    ) {}
}
