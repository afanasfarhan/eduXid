<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Tombstone per scan hasil LJK. Tidak menyimpan/menyentuh nilai resmi.
 * Menggunakan app_settings yang sudah ada agar tidak memerlukan migrasi.
 */
final class LjkScanDeletionMarker
{
    private static function key(string $scanId): string
    {
        return 'sync::ljk_deleted_scan::' . hash('sha256', trim($scanId));
    }

    public static function mark(string $scanId, string $examSyncId = '', string $sourceDeviceId = '', int $version = 0): void
    {
        $scanId = trim($scanId);
        if ($scanId === '') {
            throw new \InvalidArgumentException('scan_id LJK wajib tersedia untuk tombstone.');
        }
        $key = self::key($scanId);
        $old = DB::table('app_settings')->where('key', $key)->value('value');
        $previous = is_string($old) ? json_decode($old, true) : null;
        if (!is_array($previous)) $previous = [];
        $examSyncId = preg_replace('/^ljkpkg::/', '', trim($examSyncId));
        $data = [
            'scan_id' => $scanId,
            'exam_sync_id' => $examSyncId !== '' ? $examSyncId : (string) ($previous['exam_sync_id'] ?? ''),
            'source_device_id' => trim($sourceDeviceId) !== '' ? trim($sourceDeviceId) : (string) ($previous['source_device_id'] ?? ''),
            'version' => max($version, (int) ($previous['version'] ?? 0)),
            'deleted_at' => now()->toIso8601String(),
        ];
        DB::table('app_settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function isDeleted(string $scanId, string $examSyncId = '', string $sourceDeviceId = ''): bool
    {
        $scanId = trim($scanId);
        if ($scanId === '') return false;
        $raw = DB::table('app_settings')->where('key', self::key($scanId))->value('value');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !hash_equals((string) ($data['scan_id'] ?? ''), $scanId)) return false;
        $storedExam = (string) ($data['exam_sync_id'] ?? '');
        $incomingExam = preg_replace('/^ljkpkg::/', '', trim($examSyncId));
        if ($storedExam !== '' && $incomingExam !== '' && !hash_equals($storedExam, $incomingExam)) return false;
        $storedDevice = (string) ($data['source_device_id'] ?? '');
        if ($storedDevice !== '' && $sourceDeviceId !== '' && strcasecmp($storedDevice, trim($sourceDeviceId)) !== 0) return false;
        return true;
    }
}
