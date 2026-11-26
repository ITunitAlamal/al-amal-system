<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\GuardianApplication;

interface GuardianRegistryClient
{
    public function available(): bool;

    public function verify(Guardian $guardian, GuardianApplication $application): GuardianRegistryResult;
}
