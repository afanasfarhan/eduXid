<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [F1 / P0-4-safety] Jejak "scope ini pernah punya data".
 *
 * Tombstone hanya boleh dikirim oleh laptop yang PERNAH memegang
 * data scope tsb. Tanpa ini, laptop baru/kosong (D14: 2+ laptop/guru)
 * yang flush batch kosong akan menghapus data fresh di laptop lain —
 * kebalikan dari bug S-03 yang ingin diperbaiki.
 *
 * Penanda dibuat otomatis oleh queueAndPush (entity batch, upsert)
 * dan dibersihkan saat tombstone terkirim.
 */
final class ScopeFootprint
{
    public const PREFIX = 'sync::footprint::';

    public static function mark(string $syncId): void
    {
        try {
            $key = self::PREFIX . $syncId;

            $exists = DB::table('app_settings')->where('key', $key)->exists();

            if ($exists) {
                return; // Tidak perlu bump updated_at — keberadaan saja yang penting.
            }

            DB::table('app_settings')->insert([
                'key' => $key,
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Penanda gagal → tombstone nanti di-skip (aman, tidak destruktif).
        }
    }

    public static function has(string $syncId): bool
    {
        try {
            return DB::table('app_settings')
                ->where('key', self::PREFIX . $syncId)
                ->exists();
        } catch (Throwable) {
            return false; // Tidak yakin → jangan destruktif.
        }
    }

    public static function forget(string $syncId): void
    {
        try {
            DB::table('app_settings')
                ->where('key', self::PREFIX . $syncId)
                ->delete();
        } catch (Throwable) {
        }
    }
}