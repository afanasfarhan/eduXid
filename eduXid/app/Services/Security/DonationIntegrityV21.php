<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * V21R36 publisher-controlled donation file integrity.
 * Baseline hash pins the audited distribution. Official updates can supply a
 * signed inventory at security/donation-integrity-v1.json, using the same Ed25519
 * publisher public key as the verified Windows updater.
 *
 * Local hashes alone detect accidents/casual editing, not arbitrary admin-level
 * attackers. The signed inventory prevents changing expected hashes without
 * the official private signing key, as long as verifier and trust root are intact.
 */
final class DonationIntegrityV21
{
    public const SCHEMA = 'EDUXID-DONATION-INTEGRITY-V1';
    public const SIGNED_MANIFEST = 'security/donation-integrity-v1.json';
    public const BASELINE = [
        'app/Http/Controllers/DonationController.php' => '69bd421bf3307fad4c06daf4239c477f7bc486876c8bfaf70887d2707ff64095',
        'app/Services/DonationStatusService.php' => 'd754470a48a20f4b6ae663ec6998fb902460c2fe023663b0e084772b4802d923',
        'resources/views/about/index.blade.php' => '59f464982256d749e82cd53c60ca5d0256211e3e4dd56db3a9764af14b2eb88a',
        'resources/views/layouts/partials/donation-reminder.blade.php' => '7a51b7af5c1e912c3c6c3a93fea02cf413ce637bda3909647f8e4e6d56f836db',
        'resources/views/layouts/app.blade.php' => '79b959b80a699dcf60c6e2ac42a91dfa81c8bb7b7cdd8ff23ac924bc74dd6fda',
        'routes/web.php' => '961d72faf207beefc7ba360e59c755c5738d90bb974649a3bcc073274c2a99c8',
        'bootstrap/app.php' => '51d89063f6b2fe77450553174d7435d287df061fc73b3df42ed4ab97b44f15b9',
        'app/Http/Middleware/VerifyDonationIntegrityV21.php' => '84722f0b3a6448a21e8d46cc2ee8e03730a7de17c03afe346060bdef15fc2c45',
    ];

    /** @return array{ok:bool,mode:string,errors:list<string>} */
    public static function verify(string $baseDir, string $publicKey = ''): array
    {
        $baseDir = rtrim($baseDir, '/\\');
        $signaturePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::SIGNED_MANIFEST);
        $fileHashes = self::BASELINE;
        $mode = 'pinned-baseline';
        $errors = [];
        if (is_file($signaturePath)) {
            $mode = 'signed';
            $raw = @file_get_contents($signaturePath);
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($manifest) || !self::verifySignedManifest($manifest, $publicKey)) {
                return ['ok' => false, 'mode' => 'invalid-signed-manifest', 'errors' => ['signature_or_manifest_invalid']];
            }
            $fileHashes = $manifest['files'];
        }
        foreach ($fileHashes as $relative => $hash) {
            $path = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || !is_readable($path)) {
                $errors[] = 'missing:' . $relative;
                continue;
            }
            $actual = @hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($hash, strtolower($actual))) {
                $errors[] = 'modified:' . $relative;
            }
        }
        // Cloud policy is an additional independent check when online. Offline
        // operation remains available under the local/publisher-signed baseline.
        try {
            if (function_exists('app') && app()->bound('request')) {
                $measured = DonationCloudIntegrityV21::measuredFiles($baseDir);
                $cloud = DonationCloudIntegrityV21::check($baseDir, $mode, $measured);
                $errors = array_merge($errors, $cloud['errors']);
            }
        } catch (\Throwable) {
            // Never lock teaching or exams due to transient Cloud/HTTP errors.
        }
        return ['ok' => !$errors, 'mode' => $mode, 'errors' => $errors];
    }

    /** @param array<string,mixed> $manifest */
    public static function verifySignedManifest(array $manifest, string $publicKey): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
        if (($manifest['schema'] ?? null) !== self::SCHEMA
            || ($manifest['signature_algorithm'] ?? null) !== 'Ed25519'
            || !is_array($manifest['files'] ?? null)
            || !is_string($manifest['signature'] ?? null)) return false;
        // Exactly these protected files, no path traversal, no partial scope.
        $files = $manifest['files'];
        if (array_keys($files) !== array_keys(self::BASELINE)) return false;
        foreach ($files as $hash) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return false;
        }
        $key = self::decodePublic($publicKey);
        $signature = base64_decode($manifest['signature'], true);
        if ($key === null || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
        $signed = ['schema' => self::SCHEMA, 'signature_algorithm' => 'Ed25519', 'files' => $files];
        $json = json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && sodium_crypto_sign_verify_detached($signature, "EDUXID-DONATION-INTEGRITY-V1\n" . $json, $key);
    }

    public static function decodePublic(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^[a-f0-9]{64}$/iD', $value) === 1) {
            $decoded = hex2bin($value);
        } else {
            $decoded = base64_decode($value, true);
        }
        return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
    }
}
