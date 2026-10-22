<?php

declare(strict_types=1);

namespace App\Services\GtkBundle;

use App\Services\Security\DonationIntegrityV21;
use RuntimeException;

/** Resolve an Ed25519 PUBLIC key without ever minting a new trust root. */
final class PublisherKeyResolver
{
    public static function resolve(string $envBytes, string $configuredKey): string
    {
        if (strlen($envBytes) > 262144) {
            throw new RuntimeException('Konfigurasi penerbit pada Master terlalu besar.');
        }
        $matches = [];
        preg_match_all('/^(?:\xEF\xBB\xBF)?[ \t]*EDUXID_UPDATE_PUBLIC_KEY[ \t]*=[ \t]*([^\r\n]*)\r?$/m', $envBytes, $matches);
        $lines = $matches[1] ?? [];
        if (count($lines) > 1) {
            throw new RuntimeException('Public key penerbit terduplikasi pada Master. Sisakan tepat satu EDUXID_UPDATE_PUBLIC_KEY di .env.');
        }
        $fromEnv = count($lines) === 1 ? trim(trim((string)$lines[0]), " \t\"'") : '';
        $fromConfig = trim(trim($configuredKey), " \t\"'");
        if ($fromEnv === '' && $fromConfig === '') {
            throw new RuntimeException('Public key penerbit tidak ditemukan di .env atau konfigurasi Master. Pulihkan key PUBLIK resmi; jangan membuat key baru atau menonaktifkan verifikasi.');
        }
        if ($fromEnv !== '' && $fromConfig !== '' && !hash_equals($fromEnv, $fromConfig)) {
            throw new RuntimeException('Public key penerbit pada .env dan konfigurasi Master berbeda. Bersihkan cache konfigurasi dan periksa template Master.');
        }
        $key = $fromEnv !== '' ? $fromEnv : $fromConfig;
        if (DonationIntegrityV21::decodePublic($key) === null) {
            throw new RuntimeException('Public key Ed25519 Master tidak valid.');
        }
        return $key;
    }
}
