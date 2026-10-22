<?php

namespace App\Services\Update;

use RuntimeException;
use ZipArchive;

class ReleaseVerifier
{
    public const SCHEMA = 'EDUXID-RELEASE-V2';

    public function verify(string $zipPath, string $edition): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP zip diperlukan untuk memproses patch eduXid.');
        }

        $edition = strtolower(trim($edition));

        if (!in_array($edition, ['school', 'teacher'], true)) {
            throw new RuntimeException('Edition update harus school atau teacher.');
        }

        if (!is_file($zipPath) || filesize($zipPath) <= 0) {
            throw new RuntimeException('File patch tidak ditemukan atau kosong.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException('ZIP patch tidak valid. Kode ZipArchive: ' . $opened);
        }

        try {
            $manifest = $this->readManifest($zip);
            $this->assertManifest($manifest, $edition);
            // The installer extracts and copies the whole payload tree. Therefore
            // every archive entry must be covered by the SIGNED file allowlist.
            $this->assertSafeEntries($zip, $manifest);
            $this->verifyPayloadHashes($zip, $manifest);
            $this->verifySignature($manifest);

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function inspect(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP zip diperlukan untuk memproses patch eduXid.');
        }

        if (!is_file($zipPath)) {
            throw new RuntimeException('File patch tidak ditemukan.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException('ZIP patch tidak valid.');
        }

        try {
            $manifest = $this->readManifest($zip);
            $this->assertManifestStructure($manifest);
            $this->assertSafeEntries($zip, $manifest);
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function appliesToEdition(array $manifest, string $edition): bool
    {
        $components = $manifest['components'] ?? [];

        if (is_array($components) && array_is_list($components)) {
            return in_array('core', $components, true)
                || in_array($edition, $components, true);
        }

        if (!is_array($components)) {
            return false;
        }

        return (bool) ($components['core'] ?? false)
            || (bool) ($components[$edition] ?? false);
    }

    private function readManifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');

        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('manifest.json tidak ditemukan pada root ZIP.');
        }

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('manifest.json tidak valid: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($manifest)) {
            throw new RuntimeException('manifest.json harus berupa object JSON.');
        }

        return $manifest;
    }

    private function assertManifest(array $manifest, string $edition): void
    {
        foreach (['schema', 'version', 'min_supported_version', 'channel', 'components', 'files', 'signature_algorithm', 'signature'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new RuntimeException("Manifest kehilangan field {$field}.");
            }
        }

        if (($manifest['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Schema patch tidak didukung.');
        }

        foreach (['version', 'min_supported_version'] as $field) {
            $value = (string) ($manifest[$field] ?? '');
            if (preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/', $value) !== 1) {
                throw new RuntimeException("{$field} tidak valid.");
            }
        }

        if (($manifest['signature_algorithm'] ?? null) !== 'Ed25519') {
            throw new RuntimeException('Algoritma signature harus Ed25519.');
        }

        if (!$this->appliesToEdition($manifest, $edition)) {
            throw new RuntimeException("Patch tidak memiliki komponen yang berlaku untuk edition {$edition}.");
        }

        if (!is_array($manifest['files']) || $manifest['files'] === []) {
            throw new RuntimeException('Manifest files kosong.');
        }

        foreach ($manifest['files'] as $path => $hash) {
            if (!is_string($path) || !$this->isSafeRelativePath($path)
                || !preg_match('#^payload/(core|school|teacher)/[^/]+#', $path)) {
                throw new RuntimeException('Manifest memuat path file yang tidak aman.');
            }

            if ($this->isProtectedPayloadPath($path)) {
                throw new RuntimeException('Manifest mencoba mengganti data atau konfigurasi lokal: ' . $path);
            }
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
                throw new RuntimeException("SHA-256 tidak valid untuk {$path}.");
            }
        }
    }

    /** Strict, case-insensitive Windows ZIP allowlist. Never install an unsigned file. */
    private function assertSafeEntries(ZipArchive $zip, array $manifest): void
    {
        $expected = [];
        $allowedDirectories = [];
        foreach (array_keys($manifest['files'] ?? []) as $path) {
            if (!is_string($path) || !$this->isSafeRelativePath($path)
                || !preg_match('#^payload/(core|school|teacher)/[^/]+#', $path)) {
                throw new RuntimeException('Manifest mengandung lokasi payload tidak sah.');
            }
            $key = strtolower($path);
            if (isset($expected[$key])) {
                throw new RuntimeException('Nama file manifest bertabrakan pada Windows: ' . $path);
            }
            $expected[$key] = $path;
            $segments = explode('/', $path);
            array_pop($segments);
            while ($segments !== []) {
                $allowedDirectories[strtolower(implode('/', $segments))] = true;
                array_pop($segments);
            }
        }
        $seen = [];
        $filesSeen = [];
        if ($zip->numFiles > 20000) {
            throw new RuntimeException('ZIP memiliki terlalu banyak entri.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException('ZIP mempunyai entri yang tidak dapat dibaca.');
            }
            $name = (string) $stat['name'];
            $isDirectory = str_ends_with($name, '/');
            $trimmed = $isDirectory ? substr($name, 0, -1) : $name;
            if (!$this->isSafeRelativePath($trimmed)) {
                throw new RuntimeException('ZIP mengandung path berbahaya: ' . $name);
            }
            $key = strtolower($trimmed);
            if (isset($seen[$key])) {
                throw new RuntimeException('ZIP mengandung entri duplikat/beda kapitalisasi: ' . $name);
            }
            $seen[$key] = true;
            // ZipArchive external attributes are available in production PHP.
            // Reject symlinks and non-regular POSIX filesystem types.
            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === ZipArchive::OPSYS_UNIX) {
                    $kind = (($attributes >> 16) & 0170000);
                    if ($kind !== 0 && $kind !== ($isDirectory ? 0040000 : 0100000)) {
                        throw new RuntimeException('ZIP berisi symlink atau tipe file tak didukung: ' . $name);
                    }
                }
            }
            if ($isDirectory) {
                if (!isset($allowedDirectories[$key])) {
                    throw new RuntimeException('Direktori ZIP tidak tercantum dalam payload: ' . $name);
                }
                continue;
            }
            if ($name === 'manifest.json') {
                continue;
            }
            if (!isset($expected[$key]) || $expected[$key] !== $name) {
                throw new RuntimeException('File ZIP tidak terdaftar dalam manifest bertanda tangan: ' . $name);
            }
            $filesSeen[$key] = true;
        }
        if (!isset($seen['manifest.json']) || !isset($filesSeen) || count($filesSeen) !== count($expected)) {
            throw new RuntimeException('Jumlah atau daftar file ZIP berbeda dari manifest.');
        }
    }

    private function assertManifestStructure(array $manifest): void
    {
        if (!isset($manifest['files']) || !is_array($manifest['files']) || !$manifest['files']) {
            throw new RuntimeException('Daftar file manifest tidak valid.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            if (!is_string($path) || !$this->isSafeRelativePath($path)
                || !preg_match('#^payload/(core|school|teacher)/[^/]+#', $path)) {
                throw new RuntimeException('Path manifest tidak aman.');
            }
            if ($this->isProtectedPayloadPath($path)) {
                throw new RuntimeException('File data pengguna dilarang dalam patch: ' . $path);
            }
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                throw new RuntimeException('SHA-256 manifest tidak valid.');
            }
        }
    }

    /** Data, identity and runtime folders are never replaceable by a release. */
    private function isProtectedPayloadPath(string $path): bool
    {
        if (!preg_match('#^payload/(?:core|school|teacher)/(.+)$#i', $path, $match)) {
            return true;
        }
        $relative = strtolower($match[1]);
        return $relative === '.env'
            || str_starts_with($relative, '.env.')
            || $relative === 'eduxid'
            || $relative === 'eduxid-wal'
            || $relative === 'eduxid-shm'
            || $relative === 'database/database.sqlite'
            || str_starts_with($relative, 'storage/')
            || str_starts_with($relative, '.git/')
            || str_starts_with($relative, 'eduxid-');
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, "\0")
            || str_contains($path, "\\") || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || trim($segment, " .") !== $segment || strlen($segment) > 180
                || preg_match('/[<>:"|?*\x00-\x1f]/', $segment)
                || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment)) {
                return false;
            }
        }
        return true;
    }

    private function verifyPayloadHashes(ZipArchive $zip, array $manifest): void
    {
        foreach ($manifest['files'] as $path => $expected) {
            $stream = $zip->getStream($path);

            if (!is_resource($stream)) {
                throw new RuntimeException("File {$path} tercantum di manifest tetapi tidak ada di ZIP.");
            }

            try {
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                $actual = hash_final($ctx);
            } finally {
                fclose($stream);
            }

            if (!hash_equals(strtolower((string) $expected), strtolower($actual))) {
                throw new RuntimeException("SHA-256 tidak cocok untuk {$path}.");
            }
        }
    }

    private function verifySignature(array $manifest): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Ekstensi Sodium PHP diperlukan untuk verifikasi Ed25519.');
        }

        $publicKey = $this->decodeKey((string) config('eduxid.update_public_key', ''), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public key');
        $signature = $this->decodeKey((string) ($manifest['signature'] ?? ''), SODIUM_CRYPTO_SIGN_BYTES, 'signature');

        $signed = $manifest;
        unset($signed['signature']);

        $payload = $this->canonicalJson($signed);

        if (!sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new RuntimeException('Signature Ed25519 patch tidak valid.');
        }
    }

    private function decodeKey(string $value, int $expectedLength, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException("{$label} update belum dikonfigurasi.");
        }

        $decoded = false;

        if (preg_match('/^[a-f0-9]+$/i', $value) === 1 && strlen($value) % 2 === 0) {
            $decoded = hex2bin($value);
        }

        if ($decoded === false || strlen($decoded) !== $expectedLength) {
            $decoded = base64_decode($value, true);
        }

        if (!is_string($decoded) || strlen($decoded) !== $expectedLength) {
            throw new RuntimeException("Format {$label} Ed25519 tidak valid.");
        }

        return $decoded;
    }

    private function canonicalJson(array $data): string
    {
        $normalized = $this->sortRecursively($data);

        return json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortRecursively($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
