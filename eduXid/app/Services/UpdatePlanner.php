<?php

namespace App\Services;

use App\Models\Patch;
use RuntimeException;

class UpdatePlanner
{
    /**
     * Buat rencana update dari versi saat ini
     * menuju versi target.
     *
     * Contoh:
     *
     * 1.0.0 → 1.0.1 → 1.0.2
     */
    public function plan(
        string $currentVersion,
        string $targetVersion
    ): array {
        if ($currentVersion === $targetVersion) {
            return [];
        }

        $patches = Patch::query()
            ->orderBy('version')
            ->get();

        $plan = [];

        $version = $currentVersion;

        /*
         * Batasi jumlah langkah untuk mencegah
         * dependency cycle / loop tidak berujung.
         */
        $maxSteps = $patches->count() + 1;
        $steps = 0;

        while ($version !== $targetVersion) {
            $steps++;

            if ($steps > $maxSteps) {
                throw new RuntimeException(
                    'Rencana update tidak valid. '
                    . 'Terdeteksi kemungkinan dependency cycle.'
                );
            }

            /*
             * Cari patch yang:
             *
             * 1. membutuhkan versi yang sedang aktif
             * 2. bergerak menuju target
             */
            $nextPatch = $patches->first(
                function (Patch $patch) use ($version) {
                    return $patch->requiresVersion($version);
                }
            );

            if (!$nextPatch) {
                throw new RuntimeException(
                    "Tidak ditemukan patch yang dapat "
                    . "melanjutkan update dari versi {$version}."
                );
            }

            /*
             * Patch gagal tidak boleh menjadi bagian
             * dari rencana update.
             */
            if ($nextPatch->isFailed()) {
                throw new RuntimeException(
                    "Patch {$nextPatch->version} "
                    . "berstatus failed."
                );
            }

            /*
             * Jika patch sudah terpasang, kita boleh
             * melewatinya dan menganggap versi tersebut
             * sudah tersedia.
             */
            $plan[] = $nextPatch;

            $version = $nextPatch->version;

            /*
             * Jika patch justru bergerak menjauh dari target,
             * hentikan proses.
             */
            if (
                version_compare(
                    $version,
                    $targetVersion,
                    '>'
                )
            ) {
                throw new RuntimeException(
                    "Patch {$nextPatch->version} "
                    . "melewati target {$targetVersion}."
                );
            }
        }

        return $plan;
    }

    /**
     * Buat rencana update berdasarkan versi aplikasi
     * yang sedang aktif.
     */
    public function planFromCurrent(
        string $targetVersion
    ): array {
        $currentVersion = app(UpdateService::class)
            ->currentVersion();

        return $this->plan(
            $currentVersion,
            $targetVersion
        );
    }

    /**
     * Apakah update dapat dilakukan?
     */
    public function canUpdate(
        string $currentVersion,
        string $targetVersion
    ): bool {
        try {
            $this->plan(
                $currentVersion,
                $targetVersion
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ringkasan rencana update.
     */
    public function summary(
        string $currentVersion,
        string $targetVersion
    ): array {
        $plan = $this->plan(
            $currentVersion,
            $targetVersion
        );

        return [
            'from' => $currentVersion,
            'to' => $targetVersion,
            'steps' => count($plan),
            'patches' => collect($plan)
                ->map(function (Patch $patch) {
                    return [
                        'version' =>
                            $patch->version,

                        'requires_version' =>
                            $patch->requires_version,

                        'name' =>
                            $patch->name,

                        'status' =>
                            $patch->status,

                        'type' =>
                            $patch->type,
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
