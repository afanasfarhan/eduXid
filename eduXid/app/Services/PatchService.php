<?php

namespace App\Services;

use App\Models\Patch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PatchService
{
    /**
     * Versi aplikasi yang sedang berjalan.
     */
    public function currentVersion(): string
    {
        return (string) config(
            'eduxid.version',
            '0.0.0'
        );
    }

    /**
     * Nama aplikasi.
     */
    public function applicationName(): string
    {
        return (string) config(
            'eduxid.name',
            config('app.name', 'eduXid')
        );
    }

    /**
     * Ambil seluruh patch yang sudah terpasang.
     */
    public function installedPatches()
    {
        return Patch::query()
            ->where('status', 'installed')
            ->orderBy('version')
            ->get();
    }

    /**
     * Ambil seluruh patch yang masih pending.
     */
    public function pendingPatches()
    {
        return Patch::query()
            ->where('status', 'pending')
            ->orderBy('version')
            ->get();
    }

    /**
     * Periksa apakah sebuah patch sudah terpasang.
     */
    public function isInstalled(string $version): bool
    {
        return Patch::query()
            ->where('version', $version)
            ->where('status', 'installed')
            ->exists();
    }

    /**
     * Ambil data patch berdasarkan versi.
     */
    public function find(string $version): ?Patch
    {
        return Patch::query()
            ->where('version', $version)
            ->first();
    }

    /**
     * Mendaftarkan patch baru.
     *
     * Method ini HANYA mencatat patch.
     * Belum melakukan perubahan file aplikasi.
     */
    public function register(
        string $version,
        string $name,
        ?string $description = null,
        string $type = 'patch'
    ): Patch {
        if ($this->isInstalled($version)) {
            throw new \RuntimeException(
                "Patch {$version} sudah terpasang."
            );
        }

        return Patch::query()->updateOrCreate(
            [
                'version' => $version,
            ],
            [
                'name' => $name,
                'description' => $description,
                'type' => $type,
                'status' => 'pending',
            ]
        );
    }

    /**
     * Menandai patch sebagai berhasil dipasang.
     *
     * Method ini sengaja tidak mengubah file aplikasi.
     * Eksekusi patch akan ditambahkan pada tahap berikutnya.
     */
    public function markInstalled(
        string $version
    ): Patch {
        $patch = $this->find($version);

        if (!$patch) {
            throw new \RuntimeException(
                "Patch {$version} tidak ditemukan."
            );
        }

        if ($patch->status === 'installed') {
            return $patch;
        }

        $patch->update([
            'status' => 'installed',
            'installed_at' => now(),
        ]);

        return $patch->fresh();
    }

    /**
     * Menandai patch gagal.
     */
    public function markFailed(
        string $version
    ): Patch {
        $patch = $this->find($version);

        if (!$patch) {
            throw new \RuntimeException(
                "Patch {$version} tidak ditemukan."
            );
        }

        $patch->update([
            'status' => 'failed',
            'installed_at' => null,
        ]);

        return $patch->fresh();
    }

    /**
     * Ringkasan status patch.
     */
    public function status(): array
    {
        return [
            'application' =>
                $this->applicationName(),

            'version' =>
                $this->currentVersion(),

            'installed' =>
                Patch::query()
                    ->where('status', 'installed')
                    ->count(),

            'pending' =>
                Patch::query()
                    ->where('status', 'pending')
                    ->count(),

            'failed' =>
                Patch::query()
                    ->where('status', 'failed')
                    ->count(),
        ];
    }

    /**
     * Tes pencatatan patch secara aman.
     *
     * Method ini belum melakukan update aplikasi.
     */
    public function testRegistration(): Patch
    {
        return DB::transaction(function () {

            $patch = $this->register(
                '1.0.1',
                'Patch 1.0.1',
                'Patch pengujian sistem update eduXid.',
                'patch'
            );

            Log::info(
                'Patch eduXid didaftarkan.',
                [
                    'version' =>
                        $patch->version,

                    'name' =>
                        $patch->name,
                ]
            );

            return $patch;
        });
    }
}