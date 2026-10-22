<?php

namespace App\Services;

use App\Models\AppVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

class UpdateClientService
{
    protected int $pingTimeout = 5;
    protected int $checkTimeout = 10;
    protected int $downloadTimeout = 120;

    public function __construct(
        protected UpdateSignatureService $signatures,
        protected UpdateReleasePublisherService $publisher,
        protected CentralUpdateClientService $central
    ) {
    }

    public function currentVersion(): string
    {
        return AppVersion::where(
            'is_current',
            true
        )->value('version')
            ?? (string) config(
                'eduxid.version',
                '1.0.0'
            );
    }

    public function edition(): string
    {
        $edition = strtolower(
            trim(
                (string) config(
                    'eduxid.edition',
                    'school'
                )
            )
        );

        if (
            !in_array(
                $edition,
                ['school', 'teacher'],
                true
            )
        ) {
            throw new RuntimeException(
                'EDUXID_EDITION tidak valid.'
            );
        }

        return $edition;
    }

    public function adminUrl(): ?string
    {
        try {
            $serverIp =
                DB::table('app_settings')
                    ->where(
                        'key',
                        'server_ip'
                    )
                    ->value('value');

            if (
                is_string($serverIp)
                && trim($serverIp) !== ''
            ) {
                $serverIp =
                    trim($serverIp);

                return preg_match(
                    '/^https?:\/\//i',
                    $serverIp
                )
                    ? rtrim(
                        $serverIp,
                        '/'
                    )
                    : 'http://' .
                        $serverIp .
                        ':8000';
            }
        } catch (Throwable $e) {
            Log::warning(
                'Gagal membaca server_ip dari app_settings.',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );
        }

        $url =
            config('eduxid.admin_url');

        return is_string($url)
            && trim($url) !== ''
                ? rtrim(
                    trim($url),
                    '/'
                )
                : null;
    }

    protected function syncToken(): ?string
    {
        $token =
            config('eduxid.sync_token');

        return is_string($token)
            && trim($token) !== ''
            && $token !== 'change-this-token'
                ? trim($token)
                : null;
    }

    protected function authHeaders(): array
    {
        $headers = [
            'Accept' =>
                'application/json',

            'User-Agent' =>
                'eduXid-Updater',
        ];

        if (
            $token =
                $this->syncToken()
        ) {
            $headers['X-Sync-Token'] =
                $token;
        }

        return $headers;
    }

    public function ping(): bool
    {
        /*
         * Edition school adalah server Admin itu sendiri.
         */
        if ($this->edition() === 'school') {
            return true;
        }

        $url = $this->adminUrl();

        if (!$url) {
            return false;
        }

        try {
            $response =
                Http::timeout(
                    $this->pingTimeout
                )
                    ->withHeaders(
                        $this->authHeaders()
                    )
                    ->acceptJson()
                    ->get(
                        $url .
                        '/api/local/ping'
                    );

            return
                $response->successful()
                && $response->json('status')
                    === 'online';

        } catch (Throwable $e) {
            Log::warning(
                'Gagal menghubungi server eduXid Admin.',
                [
                    'url' => $url,
                    'error' =>
                        $e->getMessage(),
                ]
            );

            return false;
        }
    }

    public function checkVersion(): array
    {
        /*
         * Admin School:
         *
         * 1. Coba refresh mirror dari Central.
         * 2. Jika Central offline, local mirror tetap digunakan.
         * 3. Semua release tetap harus lolos verifikasi P5.
         */
        if ($this->edition() === 'school') {
            $this->refreshFromCentral();

            return $this->checkLocalReleases();
        }

        /*
         * Guru:
         *
         * mengambil signed release dari Admin School.
         */
        return $this->checkRemoteReleases();
    }

    protected function refreshFromCentral(): void
    {
        if (!$this->central->configured()) {
            return;
        }

        try {
            $status =
                $this->central->check();

            if (
                ($status['update_available'] ?? false)
                    !== true
                || !is_array(
                    $status['release'] ?? null
                )
            ) {
                return;
            }

            $release =
                $status['release'];

            $version = trim(
                (string) (
                    $release['version']
                    ?? ''
                )
            );

            $target = strtolower(
                trim(
                    (string) (
                        $release['target']
                        ?? ''
                    )
                )
            );

            $sha256 = strtolower(
                trim(
                    (string) (
                        $release['sha256']
                        ?? ''
                    )
                )
            );

            if (
                $version === ''
                || $target === ''
                || preg_match(
                    '/^[a-f0-9]{64}$/',
                    $sha256
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Central mengembalikan release tidak lengkap.'
                );
            }

            /*
             * Jangan download ulang jika release yang identik
             * sudah berada di mirror Admin dan lolos verifikasi.
             */
            foreach (
                $this->publisher->manifests()
                as $local
            ) {
                if (!is_array($local)) {
                    continue;
                }

                $localVersion =
                    (string) (
                        $local['version']
                        ?? ''
                    );

                $localTarget =
                    (string) (
                        $local['target']
                        ?? ''
                    );

                $localSha = strtolower(
                    (string) (
                        $local['sha256']
                        ?? ''
                    )
                );

                if (
                    $localVersion === $version
                    && $localTarget === $target
                    && preg_match(
                        '/^[a-f0-9]{64}$/',
                        $localSha
                    ) === 1
                    && hash_equals(
                        $sha256,
                        $localSha
                    )
                ) {
                    return;
                }
            }

            $downloaded =
                $this->central->download(
                    $release
                );

            Log::info(
                'Signed release Central berhasil dimirror oleh Admin.',
                [
                    'version' =>
                        $downloaded['version']
                        ?? $version,

                    'target' =>
                        $downloaded['target']
                        ?? $target,

                    'sha256' =>
                        $downloaded['sha256']
                        ?? $sha256,
                ]
            );

        } catch (Throwable $e) {
            /*
             * Central boleh offline.
             *
             * Kegagalan Central tidak mematikan Admin dan tidak
             * menghapus signed release yang sudah ada di mirror.
             */
            Log::warning(
                'Central update server tidak dapat direfresh.',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }

    protected function checkLocalReleases(): array
    {
        $current =
            $this->currentVersion();

        $verified = [];

        foreach (
            $this->publisher->manifests()
            as $manifest
        ) {
            if (!is_array($manifest)) {
                continue;
            }

            $this->assertManifest(
                $manifest
            );

            $target =
                (string) $manifest['target'];

            if (
                !$this->signatures
                    ->targetApplies(
                        $target,
                        'school'
                    )
            ) {
                continue;
            }

            if (
                !$this->signatures->verify(
                    (string) $manifest['version'],
                    $target,
                    (string) $manifest['sha256'],
                    (string) $manifest['signature']
                )
            ) {
                throw new RuntimeException(
                    'SIGNATURE RELEASE LOKAL TIDAK VALID: ' .
                    (string) $manifest['version']
                );
            }

            if (
                version_compare(
                    (string) $manifest['version'],
                    $current,
                    '>'
                )
            ) {
                $manifest['name'] =
                    $manifest['name']
                    ?? 'Patch ' .
                        $manifest['version'];

                $manifest['type'] =
                    $manifest['type']
                    ?? 'patch';

                $verified[] =
                    $manifest;
            }
        }

        return $this->buildVersionInfo(
            $verified,
            $current
        );
    }

    protected function checkRemoteReleases(): array
    {
        $url =
            $this->adminUrl();

        if (!$url) {
            throw new RuntimeException(
                'URL server Admin belum dikonfigurasi.'
            );
        }

        try {
            $response =
                Http::timeout(
                    $this->checkTimeout
                )
                    ->withHeaders(
                        $this->authHeaders()
                    )
                    ->acceptJson()
                    ->get(
                        $url .
                        '/api/local/check-version'
                    );

        } catch (Throwable $e) {
            throw new RuntimeException(
                'Server Admin tidak dapat dihubungi.',
                0,
                $e
            );
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'Server Admin mengembalikan HTTP ' .
                $response->status() .
                '.'
            );
        }

        $data =
            $response->json();

        if (
            !is_array($data)
            || (
                $data['signature_required']
                ?? false
            ) !== true
        ) {
            throw new RuntimeException(
                'Server Admin tidak menyediakan signed release P5.'
            );
        }

        if (
            ($data['signature_algorithm'] ?? '')
            !== 'Ed25519'
        ) {
            throw new RuntimeException(
                'Algoritma signature release tidak didukung.'
            );
        }

        $current =
            $this->currentVersion();

        $verified = [];

        foreach (
            ($data['patches'] ?? [])
            as $manifest
        ) {
            if (!is_array($manifest)) {
                throw new RuntimeException(
                    'Manifest release dari server tidak valid.'
                );
            }

            $this->assertManifest(
                $manifest
            );

            $target =
                (string) $manifest['target'];

            if (
                !$this->signatures
                    ->targetApplies(
                        $target,
                        'teacher'
                    )
            ) {
                continue;
            }

            if (
                !$this->signatures->verify(
                    (string) $manifest['version'],
                    $target,
                    (string) $manifest['sha256'],
                    (string) $manifest['signature']
                )
            ) {
                throw new RuntimeException(
                    'SIGNATURE RELEASE TIDAK VALID: ' .
                    (string) $manifest['version']
                );
            }

            if (
                version_compare(
                    (string) $manifest['version'],
                    $current,
                    '>'
                )
            ) {
                $manifest['name'] =
                    $manifest['name']
                    ?? 'Patch ' .
                        $manifest['version'];

                $manifest['type'] =
                    $manifest['type']
                    ?? 'patch';

                $verified[] =
                    $manifest;
            }
        }

        $result =
            $this->buildVersionInfo(
                $verified,
                $current
            );

        $result['application'] =
            $data['application']
            ?? $result['application'];

        return $result;
    }

    protected function buildVersionInfo(
        array $verified,
        string $current
    ): array {
        usort(
            $verified,
            fn(array $a, array $b) =>
                version_compare(
                    (string) $a['version'],
                    (string) $b['version']
                )
        );

        $latest = $current;

        foreach (
            $verified
            as $manifest
        ) {
            if (
                version_compare(
                    (string) $manifest['version'],
                    $latest,
                    '>'
                )
            ) {
                $latest =
                    (string) $manifest['version'];
            }
        }

        return [
            'application' =>
                (string) config(
                    'eduxid.name',
                    'eduXid'
                ),

            'current_version' =>
                $current,

            'latest_version' =>
                $latest,

            'update_available' =>
                version_compare(
                    $latest,
                    $current,
                    '>'
                ),

            'patches' =>
                $verified,
        ];
    }

    public function downloadPatch(
        string $version
    ): ?string {
        $version =
            trim($version);

        if (
            preg_match(
                '/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/',
                $version
            ) !== 1
        ) {
            return null;
        }

        try {
            $info =
                $this->checkVersion();

            $manifest = null;

            foreach (
                $info['patches']
                as $item
            ) {
                if (
                    (string) $item['version']
                    === $version
                ) {
                    $manifest = $item;
                    break;
                }
            }

            if (!$manifest) {
                throw new RuntimeException(
                    "Signed manifest {$version} tidak tersedia untuk edition ini."
                );
            }

            if (
                $this->edition()
                === 'school'
            ) {
                return $this->cacheLocalRelease(
                    $manifest
                );
            }

            return $this->downloadRemoteRelease(
                $manifest
            );

        } catch (Throwable $e) {
            $dir = rtrim(
                (string) config(
                    'eduxid.patch_cache_path',
                    storage_path('app/patches')
                ),
                '/\\'
            );

            @unlink(
                $dir .
                DIRECTORY_SEPARATOR .
                'patch_' .
                $version .
                '.zip.download'
            );

            Log::error(
                'Download/verifikasi signed release gagal.',
                [
                    'version' => $version,
                    'edition' =>
                        $this->edition(),

                    'error' =>
                        $e->getMessage(),
                ]
            );

            return null;
        }
    }

    protected function cacheLocalRelease(
        array $manifest
    ): string {
        $this->assertManifest(
            $manifest
        );

        if (
            !$this->signatures
                ->targetApplies(
                    (string) $manifest['target'],
                    'school'
                )
        ) {
            throw new RuntimeException(
                'Target signed release lokal tidak berlaku untuk school.'
            );
        }

        $releaseDir = rtrim(
            (string) config(
                'eduxid.release_path',
                storage_path('app/releases')
            ),
            '/\\'
        );

        $cacheDir = rtrim(
            (string) config(
                'eduxid.patch_cache_path',
                storage_path('app/patches')
            ),
            '/\\'
        );

        File::ensureDirectoryExists(
            $cacheDir
        );

        $source =
            $releaseDir .
            DIRECTORY_SEPARATOR .
            basename(
                (string) $manifest['file']
            );

        if (
            !is_file($source)
            || filesize($source) <= 0
        ) {
            throw new RuntimeException(
                'ZIP signed release lokal tidak ditemukan.'
            );
        }

        $actual = strtolower(
            (string) hash_file(
                'sha256',
                $source
            )
        );

        if (
            !hash_equals(
                (string) $manifest['sha256'],
                $actual
            )
        ) {
            throw new RuntimeException(
                'SHA-256 signed release lokal tidak cocok.'
            );
        }

        if (
            !$this->signatures->verify(
                (string) $manifest['version'],
                (string) $manifest['target'],
                $actual,
                (string) $manifest['signature']
            )
        ) {
            throw new RuntimeException(
                'Signature Ed25519 signed release lokal tidak valid.'
            );
        }

        $this->validatePatchZip(
            $source,
            (string) $manifest['version']
        );

        $save =
            $cacheDir .
            DIRECTORY_SEPARATOR .
            'patch_' .
            (string) $manifest['version'] .
            '.zip';

        $temp =
            $save .
            '.localcopy';

        @unlink($temp);

        if (
            !copy(
                $source,
                $temp
            )
        ) {
            throw new RuntimeException(
                'Gagal menyalin signed release lokal ke cache.'
            );
        }

        $copiedHash = strtolower(
            (string) hash_file(
                'sha256',
                $temp
            )
        );

        if (
            !hash_equals(
                $actual,
                $copiedHash
            )
        ) {
            @unlink($temp);

            throw new RuntimeException(
                'SHA-256 berubah saat menyalin signed release ke cache.'
            );
        }

        @unlink($save);

        if (
            !rename(
                $temp,
                $save
            )
        ) {
            @unlink($temp);

            throw new RuntimeException(
                'Gagal memindahkan signed release lokal ke cache final.'
            );
        }

        $this->writeSidecar(
            $cacheDir,
            $manifest
        );

        Log::info(
            'Signed release lokal eduXid berhasil dipindahkan ke cache.',
            [
                'version' =>
                    $manifest['version'],

                'target' =>
                    $manifest['target'],

                'sha256' =>
                    $actual,
            ]
        );

        return $save;
    }

    protected function downloadRemoteRelease(
        array $manifest
    ): string {
        $url =
            $this->adminUrl();

        if (!$url) {
            throw new RuntimeException(
                'URL Admin tidak tersedia.'
            );
        }

        $dir = rtrim(
            (string) config(
                'eduxid.patch_cache_path',
                storage_path('app/patches')
            ),
            '/\\'
        );

        File::ensureDirectoryExists(
            $dir
        );

        $version =
            (string) $manifest['version'];

        $save =
            $dir .
            DIRECTORY_SEPARATOR .
            'patch_' .
            $version .
            '.zip';

        $temp =
            $save .
            '.download';

        @unlink($temp);

        $response =
            Http::timeout(
                $this->downloadTimeout
            )
                ->withHeaders(
                    $this->authHeaders()
                )
                ->get(
                    $url .
                    '/api/local/download-patch',
                    [
                        'version' =>
                            $version,

                        'target' =>
                            (string) $manifest['target'],
                    ]
                );

        if (!$response->successful()) {
            throw new RuntimeException(
                'Download signed release gagal. HTTP ' .
                $response->status()
            );
        }

        if (
            file_put_contents(
                $temp,
                $response->body(),
                LOCK_EX
            ) === false
            || !is_file($temp)
            || filesize($temp) <= 0
        ) {
            throw new RuntimeException(
                'Gagal menyimpan signed release.'
            );
        }

        $actual = strtolower(
            (string) hash_file(
                'sha256',
                $temp
            )
        );

        if (
            !hash_equals(
                (string) $manifest['sha256'],
                $actual
            )
        ) {
            throw new RuntimeException(
                'SHA-256 patch tidak cocok dengan signed manifest.'
            );
        }

        if (
            !$this->signatures->verify(
                $version,
                (string) $manifest['target'],
                $actual,
                (string) $manifest['signature']
            )
        ) {
            throw new RuntimeException(
                'Signature Ed25519 patch tidak valid.'
            );
        }

        $this->validatePatchZip(
            $temp,
            $version
        );

        @unlink($save);

        if (
            !rename(
                $temp,
                $save
            )
        ) {
            @unlink($temp);

            throw new RuntimeException(
                'Gagal memindahkan patch terverifikasi ke cache final.'
            );
        }

        $this->writeSidecar(
            $dir,
            $manifest
        );

        Log::info(
            'Signed release eduXid berhasil diverifikasi dan diunduh.',
            [
                'version' =>
                    $version,

                'target' =>
                    $manifest['target'],

                'sha256' =>
                    $actual,
            ]
        );

        return $save;
    }

    protected function writeSidecar(
        string $cacheDir,
        array $manifest
    ): void {
        $sidecar =
            $cacheDir .
            DIRECTORY_SEPARATOR .
            'release_' .
            (string) $manifest['version'] .
            '.json';

        $temp =
            $sidecar .
            '.tmp.' .
            bin2hex(random_bytes(4));

        try {
            $written =
                file_put_contents(
                    $temp,
                    json_encode(
                        $manifest,
                        JSON_PRETTY_PRINT
                            | JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR
                    ) .
                    PHP_EOL,
                    LOCK_EX
                );

            if ($written === false) {
                throw new RuntimeException(
                    'Gagal menyimpan signed manifest sidecar.'
                );
            }

            @unlink($sidecar);

            if (
                !rename(
                    $temp,
                    $sidecar
                )
            ) {
                throw new RuntimeException(
                    'Gagal mempromosikan signed manifest sidecar.'
                );
            }

        } catch (Throwable $e) {
            @unlink($temp);

            throw $e;
        }
    }

    protected function assertManifest(
        array $manifest
    ): void {
        foreach (
            [
                'schema',
                'version',
                'target',
                'sha256',
                'signature_algorithm',
                'signature',
                'file',
            ]
            as $key
        ) {
            if (
                !isset($manifest[$key])
                || !is_string(
                    $manifest[$key]
                )
                || trim(
                    $manifest[$key]
                ) === ''
            ) {
                throw new RuntimeException(
                    "Manifest release kehilangan field {$key}."
                );
            }
        }

        if (
            $manifest['schema']
            !== 'EDUXID-RELEASE-V1'
        ) {
            throw new RuntimeException(
                'Schema signed release tidak didukung.'
            );
        }

        if (
            $manifest['signature_algorithm']
            !== 'Ed25519'
        ) {
            throw new RuntimeException(
                'Algoritma signature manifest tidak valid.'
            );
        }

        if (
            preg_match(
                '/^\d+\.\d+\.\d+(-[A-Za-z0-9._-]+)?$/',
                $manifest['version']
            ) !== 1
        ) {
            throw new RuntimeException(
                'Versi manifest tidak valid.'
            );
        }

        if (
            !in_array(
                $manifest['target'],
                [
                    'core',
                    'school',
                    'teacher',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Target manifest tidak valid.'
            );
        }

        if (
            preg_match(
                '/^[a-f0-9]{64}$/',
                $manifest['sha256']
            ) !== 1
        ) {
            throw new RuntimeException(
                'SHA-256 manifest tidak valid.'
            );
        }

        $expectedFile =
            'patch_' .
            $manifest['version'] .
            '_' .
            $manifest['target'] .
            '.zip';

        if (
            basename(
                $manifest['file']
            ) !== $expectedFile
        ) {
            throw new RuntimeException(
                'Nama file signed release tidak sesuai manifest.'
            );
        }
    }

    protected function validatePatchZip(
        string $path,
        string $version
    ): void {
        if (
            !is_file($path)
            || filesize($path) <= 0
        ) {
            throw new RuntimeException(
                'File ZIP patch tidak valid.'
            );
        }

        $zip =
            new ZipArchive();

        $result =
            $zip->open($path);

        if ($result !== true) {
            throw new RuntimeException(
                "File patch {$version} bukan ZIP valid. Kode: {$result}"
            );
        }

        try {
            if ($zip->numFiles <= 0) {
                throw new RuntimeException(
                    "ZIP patch {$version} kosong."
                );
            }

            for (
                $i = 0;
                $i < $zip->numFiles;
                $i++
            ) {
                $stat =
                    $zip->statIndex($i);

                if (!$stat) {
                    continue;
                }

                $entry =
                    str_replace(
                        '\\',
                        '/',
                        (string) $stat['name']
                    );

                if (
                    str_starts_with(
                        $entry,
                        '/'
                    )
                    || preg_match(
                        '/^[A-Za-z]:\//',
                        $entry
                    )
                    || in_array(
                        '..',
                        explode(
                            '/',
                            $entry
                        ),
                        true
                    )
                ) {
                    throw new RuntimeException(
                        "ZIP patch {$version} memiliki path tidak aman: {$entry}"
                    );
                }
            }

        } finally {
            $zip->close();
        }
    }

    public function status(): array
    {
        $current =
            $this->currentVersion();

        if (
            $this->edition()
            === 'school'
        ) {
            try {
                $info =
                    $this->checkVersion();

                return [
                    'application' =>
                        $info['application']
                        ?? 'eduXid',

                    'current_version' =>
                        $current,

                    'admin_connected' =>
                        true,

                    'update_source' =>
                        $this->central->configured()
                            ? 'central_signed_releases'
                            : 'local_signed_releases',

                    'update_available' =>
                        $info['update_available']
                        ?? false,

                    'latest_version' =>
                        $info['latest_version']
                        ?? $current,

                    'patches' =>
                        $info['patches']
                        ?? [],
                ];

            } catch (Throwable $e) {
                Log::warning(
                    'Gagal membaca signed release lokal saat status().',
                    [
                        'error' =>
                            $e->getMessage(),
                    ]
                );

                return [
                    'application' =>
                        'eduXid',

                    'current_version' =>
                        $current,

                    'admin_connected' =>
                        true,

                    'update_source' =>
                        $this->central->configured()
                            ? 'central_signed_releases'
                            : 'local_signed_releases',

                    'update_available' =>
                        false,

                    'latest_version' =>
                        $current,

                    'patches' =>
                        [],
                ];
            }
        }

        $connected =
            $this->ping();

        $latest =
            $current;

        $available =
            false;

        $patches =
            [];

        if ($connected) {
            try {
                $info =
                    $this->checkVersion();

                $latest =
                    $info['latest_version']
                    ?? $current;

                $available =
                    $info['update_available']
                    ?? false;

                $patches =
                    $info['patches']
                    ?? [];

            } catch (Throwable $e) {
                Log::warning(
                    'Gagal mengambil signed update saat status().',
                    [
                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }
        }

        return [
            'application' =>
                'eduXid',

            'current_version' =>
                $current,

            'admin_connected' =>
                $connected,

            'update_source' =>
                'admin_signed_releases',

            'update_available' =>
                $available,

            'latest_version' =>
                $latest,

            'patches' =>
                $patches,
        ];
    }
}