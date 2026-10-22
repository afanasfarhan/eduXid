<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * [F1 / P3-7 / WP-03 / WP-05 / D14] WORK PRODUCT PUSH SERVICE — rev.3
 *
 *  - Klaim throttle ATOMIK (compare-and-swap pada value lama) —
 *    dua daemon tidak bisa lagi memproses guru yang sama bersamaan.
 *  - Push work-product atas nama device PC guru pemilik data
 *    ([F1-NODE]) — bukan lagi "device guru pertama" (SPOF).
 *  - markDirty logging (WP-08) tanpa menggagalkan penyimpanan nilai.
 */
class WorkProductPushService
{
    public const FLAG_PREFIX    = 'grades::dirty::';
    public const ATTEMPT_PREFIX = 'grades::last_attempt::';
    public const DEFAULT_THROTTLE = 300;

    public function __construct(protected LmsSyncService $sync)
    {
    }

    /** Jalur kompatibel untuk pemanggil lama; kegagalan tetap dicatat. */
    public static function markDirty(int $teacherId): void
    {
        if ($teacherId <= 0) { return; }

        try {
            self::markDirtyOrFail($teacherId);
        } catch (Throwable $e) {
            /* [F1] WP-08: tetap tidak menggagalkan penyimpanan nilai,
             * tapi kini berjejak — kegagalan markDirty = nilai tidak
             * akan tersinkron sampai aksi berikutnya. */
            Log::warning('[WP-PUSH] markDirty gagal — sinkronisasi nilai tertunda.', [
                'teacher_id' => $teacherId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Penanda durable, tanpa panggilan jaringan. Gunakan dalam transaksi
     * penulisan nilai agar kegagalan antrean tidak dilaporkan sebagai sukses.
     * UUID membedakan dua perubahan dalam detik yang sama.
     */
    public static function markDirtyOrFail(int $teacherId): void
    {
        if ($teacherId <= 0) {
            throw new \InvalidArgumentException('teacher_id penanda nilai tidak valid.');
        }

        DB::table('app_settings')->upsert([
            [
                'key' => self::FLAG_PREFIX . $teacherId,
                'value' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['key'], ['value', 'updated_at']);
    }

    /** Penghapusan parsial juga harus menaikkan versi snapshot sisa scope. */
    public static function markDailyScope(int $taId, int $version): void
    {
        $key = 'grades::scope::daily::' . $taId;
        DB::table('app_settings')->insertOrIgnore([
            'key' => $key, 'value' => '0', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $current = (int) (DB::table('app_settings')->where('key', $key)->lockForUpdate()->value('value') ?? 0);
        DB::table('app_settings')->where('key', $key)
            ->update(['value' => (string) max($current, $version), 'updated_at' => now()]);
    }

    public static function markDirtyByTeachingAssignment(int $taId): void
    {
        if ($taId <= 0) { return; }

        try {
            $ta = DB::table('teaching_assignments')
                ->where('id', $taId)->first(['teacher_id']);

            if ($ta && (int) $ta->teacher_id > 0) {
                self::markDirty((int) $ta->teacher_id);
            }
        } catch (Throwable $e) {
            Log::warning('[WP-PUSH] markDirtyByTeachingAssignment gagal.', [
                'ta_id' => $taId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function flushPending(int $limit = 20, ?int $throttleSeconds = null): array
    {
        $throttle = $throttleSeconds ?? self::DEFAULT_THROTTLE;

        $result = [
            'processed' => 0, 'pushed' => 0,
            'skipped_throttled' => 0, 'deferred_unpaired' => 0, 'failed' => 0, 'details' => [],
        ];

        if (!Schema::hasTable('app_settings')) { return $result; }

        if (!$this->sync->isCloudConfigured()) { return $result; }

        $candidates = DB::table('app_settings')
            ->where('key', 'like', self::FLAG_PREFIX . '%')
            ->orderBy('updated_at')
            ->limit(max(1, $limit) * 4)
            ->get(['key', 'value', 'updated_at']);

        foreach ($candidates as $flag) {
            if ($result['processed'] >= $limit) { break; }

            $teacherId = (int) substr((string) $flag->key, strlen(self::FLAG_PREFIX));

            if ($teacherId <= 0) { continue; }

            if (!$this->claimAttempt($teacherId, $throttle)) {
                $result['skipped_throttled']++;
                continue;
            }

            $result['processed']++;

            try {
                /*
                 * [F1] Push atas nama device PC guru pemilik data —
                 * fallback getDeviceId() hanya bila device PC guru
                 * tidak terdaftar (dilog; bukan SPOF senyap).
                 */
                $sourceDeviceId = $this->sync->resolveTeacherPcDeviceId($teacherId);

                if ($sourceDeviceId === null) {
                    // Tidak ada fallback lintas guru: perangkat Anas bukan
                    // perangkat Zainal hanya karena keduanya ada di sekolah.
                    // Flag dirty tetap ada dan akan dicoba lagi setelah
                    // perangkat pemilik dipasangkan, tanpa membuat outbox salah.
                    $result['deferred_unpaired']++;
                    $result['details'][] = 'guru #' . $teacherId . ': menunggu PC guru pemilik data aktif/paired';
                    Log::info('[WP-PUSH] Ditunda: device PC guru pemilik belum aktif/paired.', [
                        'teacher_id' => $teacherId,
                    ]);
                    continue;
                }

                $pushResult = $this->sync->pushAllWorkProductsForTeacher($teacherId, $sourceDeviceId);

                if ((int) ($pushResult['failed'] ?? 0) === 0) {
                    DB::table('app_settings')
                        ->where('key', $flag->key)
                        ->where('value', $flag->value)
                        ->delete();

                    $result['pushed']++;
                } else {
                    $result['failed']++;
                    $result['details'][] = 'guru #' . $teacherId . ': '
                        . (($pushResult['details'][0] ?? null) ?? 'sebagian batch gagal');
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['details'][] = 'guru #' . $teacherId . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * [F1 / P3-7] Klaim ATOMIK via compare-and-swap: UPDATE hanya
     * berhasil bila value masih persis nilai yang baru dibaca.
     * Dua daemon read bersamaan → hanya satu CAS yang berdampak.
     */
    protected function claimAttempt(int $teacherId, int $throttle): bool
    {
        $key = self::ATTEMPT_PREFIX . $teacherId;
        $nowTs = now()->timestamp;
        $nowValue = (string) $nowTs;
        $threshold = $nowTs - $throttle;

        try {
            $row = DB::table('app_settings')->where('key', $key)->first(['value']);

            if (!$row) {
                try {
                    DB::table('app_settings')->insert([
                        'key' => $key, 'value' => $nowValue,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    return true;
                } catch (Throwable) {
                    // Race insert — jatuh ke CAS di bawah.
                    $row = DB::table('app_settings')->where('key', $key)->first(['value']);

                    if (!$row) {
                        return false;
                    }
                }
            }

            $last = (string) ($row->value ?? '');
            $lastTs = is_numeric($last) ? (int) $last : (strtotime($last) ?: 0);

            if ($lastTs > 0 && ($nowTs - $lastTs) < $throttle) {
                return false;
            }

            /* CAS: menang persis satu proses. */
            $claimed = DB::table('app_settings')
                ->where('key', $key)
                ->where('value', $last)
                ->update(['value' => $nowValue, 'updated_at' => now()]);

            return $claimed > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function pendingCount(): int
    {
        try {
            if (!Schema::hasTable('app_settings')) { return 0; }

            return (int) DB::table('app_settings')
                ->where('key', 'like', self::FLAG_PREFIX . '%')
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
