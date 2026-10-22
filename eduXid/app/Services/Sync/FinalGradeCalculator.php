<?php

namespace App\Services\Sync;

/** Rumus yang sama untuk Buku Nilai, ekspor, legger, dan rapor. */
final class FinalGradeCalculator
{
    public static function fromAverages($d, $f, $s, int $bD, int $bF, int $bS): ?int
    {
        if ($d === null && $f === null && $s === null) {
            return null;
        }
        // Pertahankan kebijakan bobot proyek: komponen kosong berkontribusi 0.
        // Bobot tidak dinormalisasi ulang; 0 tetap nilai yang sah.
        $value = $bD + $bF + $bS > 0
            ? ((float) $d * $bD + (float) $f * $bF + (float) $s * $bS) / 100
            : ((float) $d + (float) $f + (float) $s) / 3;
        return (int) round($value);
    }
}
