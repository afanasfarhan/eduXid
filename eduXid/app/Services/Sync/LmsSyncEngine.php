<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LmsSyncEngine
{
    /** [E-02] Satu sync() menguras antrean sampai habis (maks 10 halaman). */
    protected const MAX_PULL_LOOPS = 10;

    /** [P3-7/DK-02] Lock yang SAMA dengan LmsSyncController. */
    protected const SYNC_LOCK_TTL = 180;

    public function __construct(
        protected LmsSyncService $syncService,
        protected SyncApplier $applier
    ) {
    }

    public function sync(?string $deviceId = null): array
    {
        $deviceId = trim($deviceId ?? $this->syncService->getDeviceId());

        if ($deviceId === '') {
            throw new \InvalidArgumentException('Device ID tidak boleh kosong.');
        }

        $schoolId = trim((string) $this->syncService->getSchoolId());

        /*
         * [P3-7] Lock bersama web↔daemon per device. Gagal mendapat
         * lock = sync lain sedang berjalan → kembali tanpa duplikasi.
         */
        $lock = Cache::lock('lms-sync:' . $schoolId . ':' . $deviceId, self::SYNC_LOCK_TTL);

        if (!$lock->get()) {
            return $this->syncResult($deviceId, 0, 0, 0, 0, 0, [], 0, 0, true);
        }

        try {
            // V21R27: repair missing student aliases in older GTK installations.
            // A failed network reconciliation must not stop offline-first sync.
            if (OpaqueRouteRegistryV21::enabled()) {
                $dueKey = 'v21-gtk-roster-reconcile:' . hash('sha256', $schoolId . ':' . $deviceId);
                if (Cache::add($dueKey, true, now()->addMinutes(10))) {
                    try {
                        app(CloudTeacherAliasRosterV21::class)->reconcile($deviceId);
                    } catch (Throwable $e) {
                        Log::warning('[V21] Rekonsiliasi alias siswa tertunda.', [
                            'reason' => preg_replace('/[0-9]{10,}/', '[redacted]', $e->getMessage()),
                        ]);
                    }
                }
            }
            $totals = $this->syncResult($deviceId, 0, 0, 0, 0, 0, [], 0, 0);

            $cursorBefore = $this->getCursor($deviceId);
            $aggregate = [
                'received' => 0,
                'applied' => 0,
                'skipped' => 0,
                'failed' => 0,
                'acknowledged' => 0,
                'dead_lettered' => 0,
                'failures' => [],
            ];

            $lastResult = null;
            $retryThisCycle = [];

            for ($loop = 0; $loop < self::MAX_PULL_LOOPS; $loop++) {
                $result = $this->syncOnce($deviceId, array_keys($retryThisCycle));
                $lastResult = $result;

                $aggregate['received']      += (int) ($result['received'] ?? 0);
                $aggregate['applied']       += (int) ($result['applied'] ?? 0);
                $aggregate['skipped']       += (int) ($result['skipped'] ?? 0);
                $aggregate['failed']        += (int) ($result['failed'] ?? 0);
                $aggregate['acknowledged']  += (int) ($result['acknowledged'] ?? 0);
                $aggregate['dead_lettered'] += (int) ($result['dead_lettered'] ?? 0);

                foreach ((array) ($result['failures'] ?? []) as $failure) {
                    if (count($aggregate['failures']) >= 20) {
                        break;
                    }
                    $aggregate['failures'][] = $failure;
                }

                foreach (($result['retry_event_ids'] ?? []) as $failedId) {
                    $retryThisCycle[$failedId] = true;
                }
                // Tidak ACK event gagal. Jangan lakukan loop jika tidak
                // ada lanjutan atau batas sementara skip tercapai.
                if (empty($result['has_more']) || count($retryThisCycle) >= 100
                    || (int) ($result['received'] ?? 0) === 0) {
                    break;
                }
            }

            $cursorAfter = (int) ($lastResult['cursor_after'] ?? $cursorBefore);

            return array_merge($totals, $aggregate, [
                'device_id'    => $deviceId,
                'cursor_after' => $cursorAfter,
                'success'      => $aggregate['failed'] === 0,
            ]);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Satu halaman pull+apply+ack (dipakai loop di atas & pemanggil lama).
     */
    public function syncOnce(?string $deviceId = null, array $skipEventIds = []): array
    {
        $deviceId = trim($deviceId ?? $this->syncService->getDeviceId());

        if ($deviceId === '') {
            throw new \InvalidArgumentException('Device ID tidak boleh kosong.');
        }

        $cursorBefore = $this->getCursor($deviceId);

        $result = $this->syncService->pull($deviceId, $cursorBefore, $skipEventIds);

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            throw new \RuntimeException('PULL Sync Server gagal.');
        }

        $changes = is_array($result['changes'] ?? null) ? $result['changes'] : [];

        $serverNextCursor = max(
            $cursorBefore,
            (int) ($result['next_cursor'] ?? $cursorBefore)
        );

        if ($changes === []) {
            return $this->syncResult($deviceId, $cursorBefore, 0, 0, 0, 0, [], 0, 0, false, (bool) ($result['has_more'] ?? false));
        }

        $processed = $this->processBatch($changes, $deviceId);

        $acknowledged = 0;

        if ($processed['ack_event_ids'] !== []) {
            $acknowledged = $this->acknowledge($deviceId, $processed['ack_event_ids']);
        }

        return array_merge($this->syncResult(
            $deviceId,
            $cursorBefore,
            count($changes),
            $processed['applied'],
            $processed['skipped'],
            $processed['failed'],
            $processed['failures'],
            $acknowledged,
            $processed['dead_lettered'],
            $processed['retrying'] !== [],
            (bool) ($result['has_more'] ?? false)
        ), ['retry_event_ids' => array_values(array_unique(array_filter($processed['retrying'])))]);
    }

    /**
     * Dipakai controller (jalur browser). TIDAK menyentuh cursor.
     */
    public function applyChanges(array $changes, ?string $deviceId = null): array
    {
        $deviceId = trim($deviceId ?? $this->syncService->getDeviceId());

        if ($deviceId === '') {
            throw new \RuntimeException('Device ID tidak tersedia untuk applyChanges.');
        }

        $processed = $this->processBatch($changes, $deviceId);

        return [
            'success'       => $processed['failed'] === 0,
            'applied'       => $processed['applied'],
            'skipped'       => $processed['skipped'],
            'failed'        => $processed['failed'],
            'dead_lettered' => $processed['dead_lettered'],
            'ack_ids'       => $processed['ack_event_ids'],
            'ack_event_ids' => $processed['ack_event_ids'],
            'failures'      => $processed['failures'],
        ];
    }

    /* ============================================================
     * PROCESS BATCH (tetap)
     * ============================================================ */

    protected function processBatch(array $changes, string $deviceId): array
    {
        $applied = 0;
        $skipped = 0;
        $failed = 0;
        $dead = 0;

        $ackEventIds = [];
        $retrying = [];
        $failures = [];

        foreach ($changes as $change) {

            if (!is_array($change)) {
                $failed++;
                $failures[] = [
                    'event_id' => null,
                    'error'    => 'Format perubahan LMS tidak valid.',
                ];
                continue;
            }

            // [V21R16-STAGING] Sealed LJK cannot enter legacy dead-letter/ACK handling.
            // A verified staging consumer, NOT this daemon, must commit then authorize ACK.
            if (CloudLjkInboundQuarantineV21::isProtected($change)) {
                $failed++;
                $failures[] = [
                    'event_id' => trim((string) ($change['event_id'] ?? '')) ?: null,
                    'entity_type' => 'ljk_manual_sealed_result_v21r15',
                    'error' => 'BLOCKED V21R16: sealed LJK menunggu consumer staging terautentikasi; dilarang ACK/dead-letter.',
                    'disposition' => 'retry',
                ];
                // Intentionally no ACK, dead letter, cursor advancement, or legacy apply.
                $retrying[] = (string) ($change['event_id'] ?? '');
                continue;
            }

            $eventId = trim((string) ($change['event_id'] ?? ''));

            $source = trim((string) ($change['source_device_id'] ?? ''));

            if ($source !== '' && strcasecmp($source, $deviceId) === 0) {
                $skipped++;

                if ($eventId !== '') {
                    $ackEventIds[] = $eventId;
                }
                continue;
            }

            if ($eventId === '') {
                $failed++;
                $failures[] = [
                    'event_id'   => null,
                    'entity_type' => $change['entity_type'] ?? $change['entity'] ?? null,
                    'error'      => 'Event ID tidak valid.',
                ];
                continue;
            }

            try {
                $r = $this->applier->apply($change, $deviceId);
            } catch (Throwable $e) {
                Log::error('SyncApplier throw tak terduga; event di-dead-letter.', [
                    'device_id' => $deviceId,
                    'event_id'  => $eventId,
                    'error'     => $e->getMessage(),
                ]);

                app(SyncDeadLetterService::class)->recordDead($change, $deviceId, 'Applier exception: ' . $e->getMessage());
                $dead++;
                $ackEventIds[] = $eventId;
                $failures[] = [
                    'event_id'   => $eventId,
                    'entity_type' => $change['entity_type'] ?? null,
                    'error'      => $e->getMessage(),
                    'disposition'=> 'dead_letter',
                ];
                continue;
            }

            switch ($r['outcome']) {
                case SyncOutcome::APPLIED:
                    $applied++;
                    $ackEventIds[] = $eventId;
                    break;

                case SyncOutcome::SKIPPED_STALE:
                case SyncOutcome::SKIPPED_DUPLICATE:
                    $skipped++;
                    $ackEventIds[] = $eventId;
                    break;

                case SyncOutcome::DEAD_LETTER:
                    $dead++;
                    $ackEventIds[] = $eventId;
                    $failures[] = [
                        'event_id'    => $eventId,
                        'entity_type' => $change['entity_type'] ?? null,
                        'error'       => $r['reason'],
                        'disposition' => 'dead_letter',
                    ];
                    break;

                case SyncOutcome::RETRY_TRANSIENT:
                default:
                    $failed++;
                    $retrying[] = $eventId;
                    $failures[] = [
                        'event_id'    => $eventId,
                        'entity_type' => $change['entity_type'] ?? null,
                        'error'       => $r['reason'],
                        'disposition' => 'retry',
                    ];
                    break;
            }
        }

        return [
            'applied'       => $applied,
            'skipped'       => $skipped,
            'failed'        => $failed,
            'dead_lettered' => $dead,
            'ack_event_ids' => array_values(array_unique(array_filter($ackEventIds))),
            'retrying'      => $retrying,
            'failures'      => $failures,
        ];
    }

    /* ============================================================
     * ACK — chunked (tetap)
     * ============================================================ */

    protected function acknowledge(string $deviceId, array $eventIds): int
    {
        $eventIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => trim((string) $id), $eventIds),
            static fn ($id) => $id !== ''
        )));

        if ($eventIds === []) {
            return 0;
        }

        $total = 0;

        foreach (array_chunk($eventIds, 100) as $chunk) {
            try {
                $result = $this->syncService->acknowledge($deviceId, $chunk);

                if (!is_array($result)) {
                    continue;
                }

                if (($result['success'] ?? false) === true) {
                    $total += max(0, min(count($chunk), (int) ($result['acknowledged'] ?? 0)));
                }
            } catch (Throwable $e) {
                Log::warning('ACK Sync Server gagal; event akan dikirim ulang dan di-skip idempoten.', [
                    'device_id' => $deviceId,
                    'count'     => count($chunk),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $total;
    }

    /* ============================================================
     * CURSOR (vestigial tetap — vendor status-based)
     * ============================================================ */

    protected function getCursor(string $deviceId): int
    {
        $schoolId = trim((string) $this->syncService->getSchoolId());

        $cursor = DB::table('sync_devices')
            ->where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->value('sync_cursor');

        return max(0, (int) ($cursor ?? 0));
    }

    protected function saveCursor(string $deviceId, int $cursor): void
    {
        $cursor = max(0, $cursor);
        $schoolId = trim((string) $this->syncService->getSchoolId());

        $now = now();

        $updated = DB::table('sync_devices')
            ->where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->update([
                'sync_cursor'    => $cursor,
                'last_synced_at' => $now,
                'updated_at'     => $now,
            ]);

        if ($updated === 0) {
            DB::table('sync_devices')->insert([
                'school_id'      => $schoolId,
                'device_id'      => $deviceId,
                'device_type'    => 'teacher',
                'user_id'        => null,
                'device_name'    => $deviceId,
                'is_active'      => true,
                'last_synced_at' => $now,
                'sync_cursor'    => $cursor,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    /* ============================================================
     * RESULT
     * ============================================================ */

    protected function syncResult(
        string $deviceId,
        int $cursorAfter,
        int $received,
        int $applied,
        int $skipped,
        int $failed,
        array $failures,
        int $acknowledged = 0,
        int $deadLetter = 0,
        bool $busy = false,
        bool $hasMore = false
    ): array {
        return [
            'success'       => $failed === 0,
            'device_id'     => $deviceId,
            'cursor_after'  => $cursorAfter,
            'received'      => $received,
            'processed'     => $applied,
            'applied'       => $applied,
            'skipped'       => $skipped,
            'failed'        => $failed,
            'dead_lettered' => $deadLetter,
            'acknowledged'  => $acknowledged,
            'failures'      => $failures,
            'failed_changes'=> $failures,
            'busy'          => $busy,
            'has_more'      => $hasMore,
        ];
    }

    public function getSyncService(): LmsSyncService
    {
        return $this->syncService;
    }
}