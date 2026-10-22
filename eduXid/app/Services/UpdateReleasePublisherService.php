<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class UpdateReleasePublisherService
{
    public function __construct(
        protected UpdateSignatureService $signatures
    ) {
    }

    public function publish(string $version, string $target = 'core'): array
    {
        $version = trim($version);
        $target = strtolower(trim($target));

        if (!in_array($target, ['core', 'school', 'teacher'], true)) {
            throw new RuntimeException('Target release tidak valid.');
        }

        if (
            preg_match(
                '/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/',
                $version
            ) !== 1
        ) {
            throw new RuntimeException('Versi release tidak valid.');
        }

        $cacheDir = rtrim(
            (string) config(
                'eduxid.patch_cache_path',
                storage_path('app/patches')
            ),
            '/\\'
        );

        $releaseDir = rtrim(
            (string) config(
                'eduxid.release_path',
                storage_path('app/releases')
            ),
            '/\\'
        );

        $source =
            $cacheDir .
            DIRECTORY_SEPARATOR .
            'patch_' .
            $version .
            '.zip';

        if (
            !is_file($source)
            || filesize($source) <= 0
        ) {
            throw new RuntimeException(
                'ZIP patch sumber tidak ditemukan: ' . $source
            );
        }

        File::ensureDirectoryExists($releaseDir);

        $this->assertVersionTargetAvailable(
            $releaseDir,
            $version,
            $target
        );

        $releaseFile =
            'patch_' .
            $version .
            '_' .
            $target .
            '.zip';

        $manifestFile =
            'release_' .
            $version .
            '_' .
            $target .
            '.json';

        $destination =
            $releaseDir .
            DIRECTORY_SEPARATOR .
            $releaseFile;

        $manifestPath =
            $releaseDir .
            DIRECTORY_SEPARATOR .
            $manifestFile;

        $nonce = bin2hex(random_bytes(8));

        $tempZip =
            $releaseDir .
            DIRECTORY_SEPARATOR .
            '.publish_' .
            $version .
            '_' .
            $nonce .
            '.zip.tmp';

        $tempManifest =
            $releaseDir .
            DIRECTORY_SEPARATOR .
            '.publish_' .
            $version .
            '_' .
            $nonce .
            '.json.tmp';

        $oldZip = null;
        $oldManifest = null;

        try {
            /*
             * Jangan pernah menulis langsung ke release final.
             * Seluruh proses build + sign dilakukan pada temporary file.
             */
            if (!copy($source, $tempZip)) {
                throw new RuntimeException(
                    'Gagal membuat temporary ZIP release.'
                );
            }

            if (
                !is_file($tempZip)
                || filesize($tempZip) <= 0
            ) {
                throw new RuntimeException(
                    'Temporary ZIP release tidak valid.'
                );
            }

            $sourceSha = strtolower(
                (string) hash_file(
                    'sha256',
                    $source
                )
            );

            $sha256 = strtolower(
                (string) hash_file(
                    'sha256',
                    $tempZip
                )
            );

            if (
                preg_match(
                    '/^[a-f0-9]{64}$/',
                    $sourceSha
                ) !== 1
                || preg_match(
                    '/^[a-f0-9]{64}$/',
                    $sha256
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Gagal menghitung SHA-256 release.'
                );
            }

            if (!hash_equals($sourceSha, $sha256)) {
                throw new RuntimeException(
                    'SHA-256 berubah saat menyalin release.'
                );
            }

            $signature = $this->signatures->sign(
                $version,
                $target,
                $sha256
            );

            /*
             * Publisher melakukan self-verification sebelum
             * release boleh dipromosikan.
             */
            if (
                !$this->signatures->verify(
                    $version,
                    $target,
                    $sha256,
                    $signature
                )
            ) {
                throw new RuntimeException(
                    'Self-verification signature release gagal.'
                );
            }

            $manifest = [
                'schema' => 'EDUXID-RELEASE-V1',
                'application' => (string) config(
                    'eduxid.name',
                    'eduXid'
                ),
                'version' => $version,
                'target' => $target,
                'sha256' => $sha256,
                'signature_algorithm' => 'Ed25519',
                'signature' => $signature,
                'file' => $releaseFile,
                'size' => filesize($tempZip),
                'published_at' => now()->toIso8601String(),
            ];

            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            );

            if (
                file_put_contents(
                    $tempManifest,
                    $json . PHP_EOL,
                    LOCK_EX
                ) === false
            ) {
                throw new RuntimeException(
                    'Gagal membuat temporary manifest release.'
                );
            }

            /*
             * Jika ini republish target yang sama, simpan release
             * sebelumnya sebagai backup sementara.
             */
            if (is_file($destination)) {
                $oldZip =
                    $destination .
                    '.old.' .
                    bin2hex(random_bytes(4));

                if (!rename($destination, $oldZip)) {
                    throw new RuntimeException(
                        'Gagal menyimpan ZIP release lama.'
                    );
                }
            }

            if (is_file($manifestPath)) {
                $oldManifest =
                    $manifestPath .
                    '.old.' .
                    bin2hex(random_bytes(4));

                if (!rename($manifestPath, $oldManifest)) {
                    if (
                        $oldZip !== null
                        && is_file($oldZip)
                        && !is_file($destination)
                    ) {
                        @rename(
                            $oldZip,
                            $destination
                        );
                    }

                    throw new RuntimeException(
                        'Gagal menyimpan manifest release lama.'
                    );
                }
            }

            /*
             * ZIP dipromosikan terlebih dahulu.
             *
             * Release belum discoverable karena manifests()
             * hanya membaca manifest final.
             */
            if (!rename($tempZip, $destination)) {
                throw new RuntimeException(
                    'Gagal mempromosikan ZIP release.'
                );
            }

            /*
             * Manifest adalah commit point.
             */
            if (!rename($tempManifest, $manifestPath)) {
                @unlink($destination);

                throw new RuntimeException(
                    'Gagal mempromosikan manifest release.'
                );
            }

            /*
             * Setelah ZIP + manifest final tersedia, backup lama
             * aman untuk dibuang.
             */
            if (
                $oldZip !== null
                && is_file($oldZip)
            ) {
                @unlink($oldZip);
            }

            if (
                $oldManifest !== null
                && is_file($oldManifest)
            ) {
                @unlink($oldManifest);
            }

            return $manifest;

        } catch (Throwable $e) {
            @unlink($tempZip);
            @unlink($tempManifest);

            /*
             * Jika promotion gagal setelah ZIP baru muncul,
             * jangan biarkan release parsial.
             */
            if (
                is_file($destination)
                && !is_file($manifestPath)
                && $oldManifest !== null
                && is_file($oldManifest)
            ) {
                @unlink($destination);
            }

            /*
             * Pulihkan ZIP lama jika tersedia.
             */
            if (
                $oldZip !== null
                && is_file($oldZip)
                && !is_file($destination)
            ) {
                @rename(
                    $oldZip,
                    $destination
                );
            }

            /*
             * Pulihkan manifest lama jika tersedia.
             */
            if (
                $oldManifest !== null
                && is_file($oldManifest)
                && !is_file($manifestPath)
            ) {
                @rename(
                    $oldManifest,
                    $manifestPath
                );
            }

            throw $e;
        }
    }

    protected function assertVersionTargetAvailable(
        string $releaseDir,
        string $version,
        string $target
    ): void {
        if (!is_dir($releaseDir)) {
            return;
        }

        foreach (File::files($releaseDir) as $file) {
            $filename = $file->getFilename();

            if (
                preg_match(
                    '/^release_(.+)_(core|school|teacher)\.json$/',
                    $filename,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            try {
                $data = json_decode(
                    (string) file_get_contents(
                        $file->getPathname()
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                continue;
            }

            if (!is_array($data)) {
                continue;
            }

            $existingVersion = trim(
                (string) ($data['version'] ?? '')
            );

            $existingTarget = strtolower(
                trim(
                    (string) ($data['target'] ?? '')
                )
            );

            if (
                $existingVersion !== $version
                || !in_array(
                    $existingTarget,
                    ['core', 'school', 'teacher'],
                    true
                )
            ) {
                continue;
            }

            /*
             * Versi dan target yang sama boleh di-republish.
             */
            if ($existingTarget === $target) {
                continue;
            }

            throw new RuntimeException(
                "Versi release {$version} sudah digunakan untuk target " .
                "{$existingTarget}. Satu nomor versi eduXid hanya boleh " .
                'memiliki satu target release.'
            );
        }
    }

    public function manifests(): array
    {
        $releaseDir = rtrim(
            (string) config(
                'eduxid.release_path',
                storage_path('app/releases')
            ),
            '/\\'
        );

        if (!is_dir($releaseDir)) {
            return [];
        }

        $items = [];

        foreach (File::files($releaseDir) as $file) {
            if (
                preg_match(
                    '/^release_(.+)_(core|school|teacher)\.json$/',
                    $file->getFilename()
                ) !== 1
            ) {
                continue;
            }

            try {
                $data = json_decode(
                    (string) file_get_contents(
                        $file->getPathname()
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                continue;
            }

            if (!is_array($data)) {
                continue;
            }

            $schema = trim(
                (string) ($data['schema'] ?? '')
            );

            $application = trim(
                (string) ($data['application'] ?? '')
            );

            $version = trim(
                (string) ($data['version'] ?? '')
            );

            $target = strtolower(
                trim(
                    (string) ($data['target'] ?? '')
                )
            );

            $sha256 = strtolower(
                trim(
                    (string) ($data['sha256'] ?? '')
                )
            );

            $algorithm = trim(
                (string) (
                    $data['signature_algorithm']
                    ?? ''
                )
            );

            $signature = trim(
                (string) ($data['signature'] ?? '')
            );

            $releaseFile = basename(
                trim(
                    (string) ($data['file'] ?? '')
                )
            );

            if ($schema !== 'EDUXID-RELEASE-V1') {
                continue;
            }

            if (
                $application !==
                (string) config(
                    'eduxid.name',
                    'eduXid'
                )
            ) {
                continue;
            }

            if (
                preg_match(
                    '/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/',
                    $version
                ) !== 1
            ) {
                continue;
            }

            if (
                !in_array(
                    $target,
                    ['core', 'school', 'teacher'],
                    true
                )
            ) {
                continue;
            }

            if (
                preg_match(
                    '/^[a-f0-9]{64}$/',
                    $sha256
                ) !== 1
            ) {
                continue;
            }

            if ($algorithm !== 'Ed25519') {
                continue;
            }

            if ($signature === '') {
                continue;
            }

            $expectedReleaseFile =
                'patch_' .
                $version .
                '_' .
                $target .
                '.zip';

            if ($releaseFile !== $expectedReleaseFile) {
                continue;
            }

            $zip =
                $releaseDir .
                DIRECTORY_SEPARATOR .
                $releaseFile;

            if (!is_file($zip)) {
                continue;
            }

            $actual = strtolower(
                (string) hash_file(
                    'sha256',
                    $zip
                )
            );

            if (!hash_equals($sha256, $actual)) {
                continue;
            }

            try {
                if (
                    !$this->signatures->verify(
                        $version,
                        $target,
                        $sha256,
                        $signature
                    )
                ) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $items[] = $data;
        }

        usort(
            $items,
            fn(array $a, array $b) =>
                version_compare(
                    (string) $a['version'],
                    (string) $b['version']
                )
        );

        return $items;
    }
}