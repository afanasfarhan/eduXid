<?php

namespace App\Services;

use RuntimeException;

class SignedPatchVerifier
{
    public function __construct(
        protected UpdateSignatureService $signatures,
        protected PatchPackageValidator $packageValidator
    ) {
    }

    public function verifyCached(string $version): array
    {
        $version = trim($version);
        $this->packageValidator->assertValidVersion($version);

        $dir = (string) config('eduxid.patch_cache_path', storage_path('app/patches'));
        $zip = $dir . DIRECTORY_SEPARATOR . 'patch_' . $version . '.zip';
        $manifest = $dir . DIRECTORY_SEPARATOR . 'release_' . $version . '.json';

        return $this->verifyFiles($version, $zip, $manifest);
    }

    public function verifyFiles(string $version, string $zipPath, string $manifestPath): array
    {
        $version = trim($version);
        $this->packageValidator->assertValidVersion($version);

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException("ZIP signed patch {$version} tidak ditemukan.");
        }

        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            throw new RuntimeException(
                "SIGNED MANIFEST patch {$version} tidak ditemukan. " .
                "Patch unsigned/legacy ditolak oleh P5."
            );
        }

        try {
            $manifest = json_decode(
                (string) file_get_contents($manifestPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Signed manifest patch {$version} bukan JSON valid.",
                0,
                $e
            );
        }

        if (!is_array($manifest)) {
            throw new RuntimeException("Signed manifest patch {$version} tidak valid.");
        }

        foreach (['schema','version','target','sha256','signature_algorithm','signature'] as $key) {
            if (!isset($manifest[$key]) || !is_string($manifest[$key]) || trim($manifest[$key]) === '') {
                throw new RuntimeException("Signed manifest kehilangan field {$key}.");
            }
        }

        if ($manifest['schema'] !== 'EDUXID-RELEASE-V1') {
            throw new RuntimeException('Schema signed release tidak didukung.');
        }

        if ($manifest['signature_algorithm'] !== 'Ed25519') {
            throw new RuntimeException('Algoritma signature signed release tidak valid.');
        }

        if (!hash_equals($version, trim($manifest['version']))) {
            throw new RuntimeException('Versi ZIP tidak cocok dengan signed manifest.');
        }

        $target = strtolower(trim($manifest['target']));
        $edition = strtolower(trim((string) config('eduxid.edition', 'school')));

        if (!in_array($edition, ['school', 'teacher'], true)) {
            throw new RuntimeException('EDUXID_EDITION tidak valid.');
        }

        if (!$this->signatures->targetApplies($target, $edition)) {
            throw new RuntimeException(
                "Target signed release {$target} tidak berlaku untuk edition {$edition}."
            );
        }

        $expectedHash = strtolower(trim($manifest['sha256']));

        if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new RuntimeException('SHA-256 pada signed manifest tidak valid.');
        }

        $actualHash = strtolower((string) hash_file('sha256', $zipPath));

        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException(
                "SHA-256 patch {$version} tidak cocok dengan signed manifest."
            );
        }

        if (!$this->signatures->verify(
            $version,
            $target,
            $actualHash,
            trim($manifest['signature'])
        )) {
            throw new RuntimeException(
                "Signature Ed25519 patch {$version} tidak valid."
            );
        }

        // Pertahankan seluruh validasi keamanan paket dari P4.
        $inspection = $this->packageValidator->inspect($zipPath, $version);

        return [
            'version' => $version,
            'target' => $target,
            'sha256' => $actualHash,
            'manifest' => $manifest,
            'inspection' => $inspection,
        ];
    }
}
