<?php

namespace App\Services\Orphans;

final class RegistryIdentity
{
    public static function hash(string $identity): string
    {
        return hash_hmac('sha256', trim($identity), (string) config('app.key'));
    }
}
