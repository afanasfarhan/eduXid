<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ============================================================
 * [FASE-0 / P0-1] VERSION SERVICE — SATU STRATEGI VERSI
 * ============================================================
 *
 * Menutup keluarga "versi ganda & jam-lokal" (SR-02, EX-04, LA-10,
 * S-05, PC-05): seluruh tulisan event (HP/web/daemon) memakai
 * primitif yang sama, kebal jam laptop yang mundur.
 *
 *   next()             — versi event baru; monotonik proses-lintas
 *                        (floor persist di app_settings, kini dijamin
 *                        unik oleh migrasi Fase 0).
 *   next($floor)       — versi baru yang wajib > $floor (versi baris
 *                        target) → perangkat ber-jam mundur tetap
 *                        menang atas dirinya sendiri.
 *   above($current)    — pintu lazim utk tulis ulang baris yang sama.
 *   fromUpdatedAt($ts) — versi BATCH dari max(updated_at) sumber
 *                        [BATCH-VERSION]. SENGAJA tidak menyentuh
 *                        floor global: data tak berubah = versi sama =
 *                        reuse record outbox (anti akumulasi).
 *
 * Pola epochVersion() PWA (File cloud.js) diporting ke server.
 * ============================================================
 */
final class VersionService
{
    public const FLOOR_KEY = 'sync::version_floor';

    private static int $processFloor = 0;

    public static function next(int $floor = 0): int
    {
        $allocate = static function () use ($floor): int {
            DB::table('app_settings')->insertOrIgnore([
                'key' => self::FLOOR_KEY, 'value' => '0',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $stored = (int) (DB::table('app_settings')->where('key', self::FLOOR_KEY)
                ->lockForUpdate()->value('value') ?? 0);
            $version = max((int) floor(microtime(true) * 1000), $stored + 1,
                self::$processFloor + 1, $floor + 1, 1);
            DB::table('app_settings')->where('key', self::FLOOR_KEY)
                ->update(['value' => (string) $version, 'updated_at' => now()]);
            return $version;
        };
        try {
            $version = DB::transaction($allocate, 3);
        } catch (Throwable) {
            // Kompatibel saat setup sebelum tabel tersedia; unik dalam proses.
            $version = max((int) floor(microtime(true) * 1000), self::$processFloor + 1, $floor + 1, 1);
        }
        return self::$processFloor = $version;
    }

    /** Terima batas versi perangkat lain tanpa membuat revisi data baru. */
    public static function observe(int $version): void
    {
        DB::transaction(static function () use ($version): void {
            DB::table('app_settings')->insertOrIgnore([
                'key' => self::FLOOR_KEY, 'value' => '0',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $stored = (int) DB::table('app_settings')->where('key', self::FLOOR_KEY)
                ->lockForUpdate()->value('value');
            if ($version > $stored) {
                DB::table('app_settings')->where('key', self::FLOOR_KEY)
                    ->update(['value' => (string) $version, 'updated_at' => now()]);
            }
        }, 3);
        self::$processFloor = max(self::$processFloor, $version);
    }

    /** Versi baru yang PASTI mengalahkan versi baris target saat ini. */
    public static function above(?int $currentVersion, int $minimum = 1): int
    {
        return self::next(max((int) ($currentVersion ?? 0) + 1, $minimum));
    }

    /**
     * Versi batch dari timestamp terbaru baris sumber — murni, tanpa
     * efek samping floor (lihat komentar kelas).
     */
    public static function fromUpdatedAt($updatedAt): int
    {
        if (!$updatedAt) {
            return self::next();
        }

        try {
            $date = new \DateTimeImmutable((string) $updatedAt);

            return ((int) $date->format('U')) * 1000
                + (int) floor(((int) $date->format('u')) / 1000);
        } catch (Throwable) {
            return self::next();
        }
    }

    /* ============================================================ */

    protected static function storedFloor(): int
    {
        try {
            $value = DB::table('app_settings')
                ->where('key', self::FLOOR_KEY)
                ->value('value');

            return max(0, (int) ($value ?? 0));
        } catch (Throwable) {
            return 0;
        }
    }

    protected static function saveFloor(int $version): void
    {
        try {
            $exists = DB::table('app_settings')
                ->where('key', self::FLOOR_KEY)
                ->exists();

            if ($exists) {
                DB::table('app_settings')
                    ->where('key', self::FLOOR_KEY)
                    ->update(['value' => (string) $version, 'updated_at' => now()]);

                return;
            }

            DB::table('app_settings')->insert([
                'key'        => self::FLOOR_KEY,
                'value'      => (string) $version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Degradasi aman: tanpa floor persist, versi tetap epoch-ms
            // (benar untuk jam sehat); jam rusak tetap ditutup argumen
            // $floor/above() dari pemanggil.
        }
    }
}
