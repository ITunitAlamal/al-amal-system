<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\GuardianApplication;

/** No endpoint or successful verification is invented before the API contract is supplied. */
final class UnavailableGuardianRegistry implements GuardianRegistryClient
{
    public function available(): bool
    {
        return false;
    }

    public function verify(Guardian $guardian, GuardianApplication $application): GuardianRegistryResult
    {
        return new GuardianRegistryResult(false);
    }
}
