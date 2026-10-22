<?php

namespace App\Services\Sync;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [REWRITE-02] MASTER REGISTRY — buku besar versi master.
 *
 * master::pushed_*  : versi terakhir yang DIKIRIM ke Cloud (admin).
 * master::applied_* : versi terakhir yang DITERAPKAN ke DB lokal.
 * master::push_dirty: flag "ada perubahan master yang belum dipush"
 *                     (dipasang dapodik:sync, dibuka MasterPushService).
 *
 * Penyimpanan: tabel app_settings (key/value) — tanpa migrasi baru.
 */
final class MasterRegistry
{
    public const KEY_PUSHED_VERSION  = 'master::pushed_version';
    public const KEY_PUSHED_HASH     = 'master::pushed_hash';
    public const KEY_APPLIED_VERSION = 'master::applied_version';
    public const KEY_APPLIED_HASH    = 'master::applied_hash';
    public const KEY_APPLIED_AT      = 'master::applied_at';

    public const KEY_DIRTY             = 'master::push_dirty';
    public const KEY_LAST_PUSH_ATTEMPT = 'master::last_push_attempt';

    public static function get(string $key): ?string
    {
        if (!Schema::hasTable('app_settings')) {
            return null;
        }

        $value = DB::table('app_settings')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * Simpan nilai untuk kunci.
     *
     * [TS-FIX] created_at hanya di-set saat INSERT — update tidak
     * boleh me-refresh waktu pembuatan baris (konsisten dengan
     * konvensi [TS-FIX] di PengaturanController & WorkProductApplier).
     *
     * Insert dilindungi dari race: bila proses lain menyisipkan kunci
     * yang sama lebih dulu (unique index menolak), jatuh ke update.
     */
    public static function set(string $key, string $value): void
    {
        $exists = DB::table('app_settings')->where('key', $key)->exists();

        if ($exists) {
            DB::table('app_settings')
                ->where('key', $key)
                ->update([
                    'value'      => $value,
                    'updated_at' => now(),
                ]);

            return;
        }

        try {
            DB::table('app_settings')->insert([
                'key'        => $key,
                'value'      => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            /*
             * Race insert: proses lain menang — cukup perbarui.
             * Bila tabel tidak punya unique index, exception ini
             * tidak pernah terjadi dan perilaku tetap benar.
             */
            if (!str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }

            DB::table('app_settings')
                ->where('key', $key)
                ->update([
                    'value'      => $value,
                    'updated_at' => now(),
                ]);
        }
    }

    public static function forget(string $key): void
    {
        if (!Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->where('key', $key)->delete();
    }

    public static function pushedVersion(): int
    {
        return max(0, (int) (self::get(self::KEY_PUSHED_VERSION) ?? 0));
    }

    public static function appliedVersion(): int
    {
        return max(0, (int) (self::get(self::KEY_APPLIED_VERSION) ?? 0));
    }

    /**
     * Versi berikutnya — MONOTON tanpa mempercayai jam lokal
     * (clock laptop terbukti bisa melompat): max(epoch-ms, catatan
     * tertinggi + 1).
     */
    public static function nextVersion(): int
    {
        $floor = max(self::pushedVersion(), self::appliedVersion()) + 1;
        $epoch = (int) floor(microtime(true) * 1000);

        return max($floor, $epoch);
    }

    public static function recordPushed(int $version, string $hash): void
    {
        self::set(self::KEY_PUSHED_VERSION, (string) max(1, $version));
        self::set(self::KEY_PUSHED_HASH, $hash);
    }

    public static function recordApplied(int $version, string $hash): void
    {
        self::set(self::KEY_APPLIED_VERSION, (string) max(1, $version));
        self::set(self::KEY_APPLIED_HASH, $hash);
        self::set(self::KEY_APPLIED_AT, now()->toIso8601String());
    }
}