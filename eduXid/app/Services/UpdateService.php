<?php

namespace App\Services;

use App\Models\AppVersion;
use App\Models\Patch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class UpdateService
{
    public function __construct(
        protected PatchExecutor $executor,
        protected UpdateClientService $client,
        protected PatchService $patchService,
        protected BackupService $backupService,
        protected RecoveryService $recoveryService,
        protected SignedPatchVerifier $signedPatchVerifier
    ) {
    }

    public function version(): string
    {
        $current = $this->currentVersion();

        return $current?->version
            ?? (string) config('eduxid.version', '1.0.0');
    }

    public function currentVersion(): ?AppVersion
    {
        return AppVersion::current()->first();
    }

    public function status(): array
    {
        $sourceStatus = $this->client->status();
        $currentVersion = $this->version();

        $localPatches = Patch::query()->get()->keyBy('version');
        $unifiedPatches = collect();

        foreach (($sourceStatus['patches'] ?? []) as $remote) {
            $version = (string) ($remote['version'] ?? '');

            if ($version === '') {
                continue;
            }

            if ($localPatch = $localPatches->get($version)) {
                $unifiedPatches->push($localPatch);
                continue;
            }

            $temp = new \stdClass();
            $temp->version = $version;
            $temp->name = $remote['name'] ?? 'Patch ' . $version;
            $temp->description = $remote['description'] ?? 'Signed release tersedia.';
            $temp->type = $remote['type'] ?? 'patch';
            $temp->status = 'available';
            $temp->installed_at = null;

            $unifiedPatches->push($temp);
        }

        foreach ($localPatches as $local) {
            if (!$unifiedPatches->contains('version', $local->version)) {
                $unifiedPatches->push($local);
            }
        }

        $unifiedPatches = $unifiedPatches
            ->sortByDesc(fn($patch) => $this->versionSortKey((string) $patch->version))
            ->values();

        return [
            'application' => config('eduxid.name', 'eduXid'),
            'current_version' => $currentVersion,
            'latest_version' => $sourceStatus['latest_version'] ?? $currentVersion,
            'update_available' => $sourceStatus['update_available'] ?? false,
            'admin_connected' => $sourceStatus['admin_connected'] ?? false,
            'update_source' => $sourceStatus['update_source'] ?? null,
            'patches' => $unifiedPatches,
        ];
    }

    public function planUpdate(string $targetVersion): array
    {
        $targetVersion = trim($targetVersion);
        $currentVersion = $this->version();

        if ($targetVersion === '') {
            throw new RuntimeException('Versi target tidak boleh kosong.');
        }

        if (version_compare($targetVersion, $currentVersion, '<=')) {
            throw new RuntimeException(
                "Versi target ({$targetVersion}) harus lebih besar " .
                "dari versi saat ini ({$currentVersion})."
            );
        }

        /*
         * P5 Stage 3D:
         * - school  -> checkVersion() membaca signed release lokal.
         * - teacher -> checkVersion() membaca signed release dari Admin.
         *
         * Tidak ada unsigned fallback.
         */
        $info = $this->client->checkVersion();
        $sourcePatches = $info['patches'] ?? [];
        $planned = [];

        foreach ($sourcePatches as $patch) {
            $version = (string) ($patch['version'] ?? '');

            if ($version === '') {
                continue;
            }

            if (
                version_compare($version, $currentVersion, '>')
                && version_compare($version, $targetVersion, '<=')
                && !$this->patchService->isInstalled($version)
            ) {
                $this->patchService->register(
                    $version,
                    $patch['name'] ?? 'Patch ' . $version,
                    $patch['description'] ?? null,
                    $patch['type'] ?? 'patch'
                );

                $planned[] = $version;
            }
        }

        if (!in_array($targetVersion, $planned, true)
            && !$this->patchService->find($targetVersion)) {
            throw new RuntimeException(
                "Signed release target {$targetVersion} tidak tersedia untuk edition ini."
            );
        }

        return [
            'target_version' => $targetVersion,
            'planned_patches' => $planned,
        ];
    }

    public function updateTo(string $targetVersion): Patch
    {
        $targetVersion = trim($targetVersion);

        if ($targetVersion === '') {
            throw new RuntimeException('Versi target tidak boleh kosong.');
        }

        $currentVersion = $this->version();

        if (version_compare($targetVersion, $currentVersion, '<=')) {
            throw new RuntimeException(
                "Versi target ({$targetVersion}) tidak lebih baru " .
                "dari versi saat ini ({$currentVersion})."
            );
        }

        $targetPatch = $this->patchService->find($targetVersion);

        if (!$targetPatch) {
            $this->planUpdate($targetVersion);
        }

        $patches = Patch::query()
            ->where('status', 'pending')
            ->get()
            ->filter(
                fn(Patch $patch) =>
                    version_compare($patch->version, $currentVersion, '>')
                    && version_compare($patch->version, $targetVersion, '<=')
            )
            ->sortBy(fn(Patch $patch) => $this->versionSortKey($patch->version))
            ->values();

        if ($patches->isEmpty()) {
            throw new RuntimeException(
                "Tidak ada patch pending yang menuju versi {$targetVersion}."
            );
        }

        $preUpdateBackup = null;

        try {
            Log::info('Membuat backup sebelum update eduXid.', [
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ]);

            $preUpdateBackup = $this->backupService->createBackup();
            $this->backupService->validateBackup($preUpdateBackup);

            Log::info('Backup sebelum update berhasil dibuat.', [
                'backup' => $preUpdateBackup,
            ]);
        } catch (Throwable $e) {
            Log::error('UPDATE DIBATALKAN: backup sebelum update gagal.', [
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Update dibatalkan karena backup sebelum update gagal: ' .
                $e->getMessage(),
                0,
                $e
            );
        }

        $lastInstalled = null;

        try {
            foreach ($patches as $patch) {
$patchCachePath = rtrim(
    (string) config(
        'eduxid.patch_cache_path',
        storage_path('app/patches')
    ),
    '/\\'
);

$zipPath =
    $patchCachePath .
    DIRECTORY_SEPARATOR .
    'patch_' .
    $patch->version .
    '.zip';

                $verified = false;

                if (file_exists($zipPath)) {
                    try {
                        $this->signedPatchVerifier->verifyCached($patch->version);
                        $verified = true;

                        Log::info('Cached signed patch lolos verifikasi P5.', [
                            'version' => $patch->version,
                        ]);
                    } catch (Throwable $verificationException) {
                        Log::warning('Cached patch ditolak P5; cache dibuang.', [
                            'version' => $patch->version,
                            'error' => $verificationException->getMessage(),
                        ]);

                        @unlink($zipPath);
                        @unlink(
$patchCachePath .
DIRECTORY_SEPARATOR .
'release_' .
$patch->version .
'.json'
                        );
                    }
                }

                if (!$verified) {
                    Log::info('Mengambil signed patch dari sumber update resmi.', [
                        'version' => $patch->version,
                        'edition' => config('eduxid.edition', 'school'),
                    ]);

                    $downloadedPath = $this->client->downloadPatch($patch->version);

                    if (!$downloadedPath || !file_exists($downloadedPath)) {
                        throw new RuntimeException(
                            "Gagal memperoleh signed patch {$patch->version} " .
                            "dari sumber update resmi."
                        );
                    }

                    /*
                     * Verifikasi terakhir sesudah transport/copy.
                     * Ini adalah gate wajib sebelum PatchExecutor.
                     */
                    $this->signedPatchVerifier->verifyCached($patch->version);
                }

                Log::info('Memulai eksekusi patch.', [
                    'version' => $patch->version,
                    'target_version' => $targetVersion,
                ]);

                $lastInstalled = $this->executor->execute($patch->version);

                Log::info('Patch berhasil dieksekusi.', [
                    'version' => $patch->version,
                ]);
            }

            if (!$lastInstalled) {
                throw new RuntimeException('Tidak ada patch yang berhasil dipasang.');
            }

            $activatedVersion = $this->activateVersion($lastInstalled->version);

            Log::info('Update eduXid berhasil.', [
                'previous_version' => $currentVersion,
                'new_version' => $activatedVersion->version,
                'backup' => $preUpdateBackup,
            ]);

            return $lastInstalled;
        } catch (Throwable $updateException) {
            Log::error('UPDATE EDUXID GAGAL.', [
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
                'backup' => $preUpdateBackup,
                'error' => $updateException->getMessage(),
                'exception' => get_class($updateException),
                'file' => $updateException->getFile(),
                'line' => $updateException->getLine(),
            ]);

            if ($preUpdateBackup && file_exists($preUpdateBackup)) {
                try {
                    Log::warning(
                        'Memulai recovery database setelah update gagal.',
                        [
                            'backup' => $preUpdateBackup,
                            'current_version' => $currentVersion,
                            'target_version' => $targetVersion,
                        ]
                    );

                    $this->recoveryService->restoreDatabase($preUpdateBackup);

                    Log::warning(
                        'Recovery database setelah update gagal berhasil.',
                        [
                            'backup' => $preUpdateBackup,
                            'restored_version' => $currentVersion,
                        ]
                    );
                } catch (Throwable $recoveryException) {
                    Log::critical('RECOVERY DATABASE EDUXID JUGA GAGAL.', [
                        'backup' => $preUpdateBackup,
                        'update_error' => $updateException->getMessage(),
                        'recovery_error' => $recoveryException->getMessage(),
                        'recovery_exception' => get_class($recoveryException),
                    ]);
                }
            } else {
                Log::critical(
                    'UPDATE GAGAL DAN BACKUP RECOVERY TIDAK TERSEDIA.',
                    [
                        'target_version' => $targetVersion,
                        'backup' => $preUpdateBackup,
                    ]
                );
            }

            try {
                $activeVersion = $this->currentVersion();

                if (
                    $activeVersion
                    && $activeVersion->version !== $currentVersion
                ) {
                    $this->activateVersion($currentVersion);
                }
            } catch (Throwable $versionException) {
                Log::critical('Gagal memastikan versi lama tetap aktif.', [
                    'expected_version' => $currentVersion,
                    'error' => $versionException->getMessage(),
                ]);
            }

            throw $updateException;
        }
    }

    public function installUploadedPatch(
        string $version,
        string $tempZipPath
    ): Patch {
        $version = trim($version);

        if ($version === '') {
            throw new RuntimeException('Versi target tidak boleh kosong.');
        }

        Log::warning('Upload patch manual unsigned ditolak oleh P5.', [
            'version' => $version,
            'temp_zip' => basename($tempZipPath),
        ]);

        throw new RuntimeException(
            'P5 SECURITY: upload ZIP manual tanpa signed manifest tidak diizinkan. ' .
            'Gunakan signed release melalui updater eduXid.'
        );
    }

    public function activateVersion(string $version): AppVersion
    {
        $version = trim($version);

        if ($version === '') {
            throw new RuntimeException('Versi aplikasi tidak boleh kosong.');
        }

        return DB::transaction(function () use ($version) {
            AppVersion::where('is_current', true)->update([
                'is_current' => false,
            ]);

            $appVersion = AppVersion::firstOrCreate(
                ['version' => $version],
                ['name' => 'eduXid ' . $version]
            );

            $appVersion->update([
                'is_current' => true,
                'activated_at' => now(),
            ]);

            return $appVersion->fresh();
        });
    }

    public function availablePatches()
    {
        return Patch::query()
            ->where('status', '!=', 'installed')
            ->get()
            ->sortBy(fn($patch) => $this->versionSortKey((string) $patch->version))
            ->values();
    }

    protected function versionSortKey(string $version): array
    {
        return array_map(
            'intval',
            explode('.', preg_replace('/-.+$/', '', $version))
        );
    }
}
