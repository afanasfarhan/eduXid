<?php

namespace App\Services;

use App\Models\Patch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PatchExecutor
{
    public function __construct(
        protected PatchService $patchService,
        protected PatchPackageValidator $packageValidator,
        protected PatchFilesystemTransaction $filesystem
    ) {
    }

    public function execute(string $version): Patch
    {
        $version = trim($version);
        $this->packageValidator->assertValidVersion($version);

        $patch = $this->patchService->find($version);

        if (!$patch) {
            throw new RuntimeException("Patch {$version} tidak ditemukan.");
        }

        if ($patch->status === 'installed') {
            return $patch;
        }

        if ($patch->status !== 'pending') {
            throw new RuntimeException(
                "Patch {$version} tidak dapat dijalankan karena statusnya {$patch->status}."
            );
        }

$normalizedVersion =
    $this->packageValidator->normalizeVersion($version);

        $class =
            'App\\Updates\\Patches\\Patch_' . $normalizedVersion;

        $filePath =
            base_path(
                'app/Updates/Patches/Patch_' .
                $normalizedVersion .
                '.php'
            );

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
    $version .
    '.zip';

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException(
                "ZIP patch {$version} tidak ditemukan atau tidak dapat dibaca."
            );
        }

        $tx = null;

        try {
            $inspection =
                $this->packageValidator
                    ->inspect($zipPath, $version);

            Log::info('Paket patch eduXid tervalidasi.', [
                'version' => $version,
                'sha256' => $inspection['sha256'],
                'files' => $inspection['files'],
                'uncompressed_bytes' => $inspection['uncompressed_bytes'],
            ]);

            $tx =
                $this->filesystem
                    ->stage($zipPath, $version);

            $tx =
                $this->filesystem
                    ->install($tx);

            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new RuntimeException(
                    "File patch tidak ditemukan setelah instalasi: {$filePath}"
                );
            }

            require_once $filePath;

            if (!class_exists($class)) {
                throw new RuntimeException(
                    "Class patch {$class} tidak ditemukan setelah file dimuat."
                );
            }

            DB::transaction(function () use ($class, $version) {
                $instance = app($class);

                if (!method_exists($instance, 'handle')) {
                    throw new RuntimeException(
                        "Patch {$version} tidak memiliki method handle()."
                    );
                }

                Log::info('Menjalankan patch eduXid.', [
                    'version' => $version,
                    'class' => $class,
                ]);

                $instance->handle();
            });

            $installed =
                $this->patchService
                    ->markInstalled($version);

            try {
                $this->filesystem->cleanup($tx);
            } catch (Throwable $cleanupException) {
                Log::warning('Patch berhasil tetapi cleanup transaksi gagal.', [
                    'version' => $version,
                    'error' => $cleanupException->getMessage(),
                ]);
            }

            Log::info('Patch eduXid berhasil dipasang.', [
                'version' => $version,
                'class' => $class,
                'sha256' => $inspection['sha256'],
            ]);

            return $installed;

        } catch (Throwable $e) {
            Log::error('Eksekusi patch eduXid gagal.', [
                'version' => $version,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if (is_array($tx)) {
                try {
                    $this->filesystem->rollback($tx);
                } catch (Throwable $rollbackException) {
                    Log::critical('ROLLBACK FILESYSTEM PATCH GAGAL.', [
                        'version' => $version,
                        'update_error' => $e->getMessage(),
                        'rollback_error' => $rollbackException->getMessage(),
                        'transaction' => $tx['root'] ?? null,
                    ]);
                }
            }

            try {
                $this->patchService->markFailed($version);
            } catch (Throwable $statusException) {
                Log::error('Gagal menandai patch sebagai failed.', [
                    'version' => $version,
                    'error' => $statusException->getMessage(),
                ]);
            }

            throw $e;
        }
    }


}
