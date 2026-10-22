<?php

namespace App\Services;

use RuntimeException;

class UpdateSignatureService
{
    public function sodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_sign_detached')
            && function_exists('sodium_crypto_sign_verify_detached');
    }

    public function canonicalPayload(string $version, string $target, string $sha256): string
    {
        $version = trim($version);
        $target = strtolower(trim($target));
        $sha256 = strtolower(trim($sha256));

        if (preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/', $version) !== 1) {
            throw new RuntimeException('Versi release tidak valid.');
        }
        if (!in_array($target, ['core', 'school', 'teacher'], true)) {
            throw new RuntimeException('Target release tidak valid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('SHA-256 release tidak valid.');
        }

        return "EDUXID-RELEASE-V1\nversion={$version}\ntarget={$target}\nsha256={$sha256}";
    }

    public function sign(string $version, string $target, string $sha256): string
    {
        $this->assertSodium();
        $key = $this->decodeKey(
            (string) config('eduxid.update_private_key'),
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
            'private'
        );
        return base64_encode(sodium_crypto_sign_detached(
            $this->canonicalPayload($version, $target, $sha256),
            $key
        ));
    }

    public function verify(string $version, string $target, string $sha256, string $signature): bool
    {
        $this->assertSodium();
        $key = $this->decodeKey(
            (string) config('eduxid.update_public_key'),
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'public'
        );
        $sig = base64_decode(trim($signature), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached(
            $sig,
            $this->canonicalPayload($version, $target, $sha256),
            $key
        );
    }

    public function targetApplies(string $target, ?string $edition = null): bool
    {
        $target = strtolower(trim($target));
        $edition = strtolower(trim($edition ?: (string) config('eduxid.edition', 'school')));
        return in_array($edition, ['school', 'teacher'], true)
            && ($target === 'core' || $target === $edition);
    }

    protected function assertSodium(): void
    {
        if (!$this->sodiumAvailable()) {
            throw new RuntimeException('PHP Sodium wajib tersedia untuk update eduXid.');
        }
    }

    protected function decodeKey(string $encoded, int $length, string $label): string
    {
        $decoded = base64_decode(trim($encoded), true);
        if ($decoded === false || strlen($decoded) !== $length) {
            throw new RuntimeException("EDUXID update {$label} key belum dikonfigurasi atau tidak valid.");
        }
        return $decoded;
    }
}
