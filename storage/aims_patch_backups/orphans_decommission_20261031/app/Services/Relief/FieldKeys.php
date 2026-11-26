<?php

namespace App\Services\Relief;

use RuntimeException;

/**
 * The server's Ed25519 key for signing field manifests, derived from the application key (HKDF) so no extra secret
 * file exists and rotating the application key rotates it. Devices pin the public key at registration.
 */
final class FieldKeys
{
    private ?string $keypair = null;

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey($this->keypair());
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey());
    }

    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 16);
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($this->keypair())));
    }

    /** Verifies a device signature (base64) over the exact bytes it signed, with the device's registered public key (base64). */
    public static function verifyDevice(string $message, string $signature, string $publicKey): bool
    {
        $signature = base64_decode($signature, true);
        $publicKey = base64_decode($publicKey, true);
        if ($signature === false || $publicKey === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    private function keypair(): string
    {
        if ($this->keypair !== null) {
            return $this->keypair;
        }
        $key = (string) config('app.key');
        $material = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! $material) {
            throw new RuntimeException('Application key is missing.');
        }

        return $this->keypair = sodium_crypto_sign_seed_keypair(hash_hkdf('sha256', $material, SODIUM_CRYPTO_SIGN_SEEDBYTES, 'aims-field-manifest-v1'));
    }
}
