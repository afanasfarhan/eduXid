<?php

namespace App\Console\Commands;

use App\Services\Sync\CloudOutboundPrivacyGuardV21;
use App\Services\Sync\LmsSyncEngine;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\MasterPushService;
use App\Services\Sync\MasterRegistry;
use App\Services\Sync\SchoolIdentityException;
use App\Services\Sync\SyncAuditService;
use App\Services\Sync\WorkProductPushService;
use App\Services\Update\UpdateAfterSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class LmsSyncCommand extends Command
{
    protected $signature = 'eduxid:lms-sync
                            {--interval=5 : Interval sinkronisasi dalam detik}
                            {--once : Jalankan satu siklus saja (diagnostik; tanpa guard/heartbeat)}
                            {--force : Lewati guard anti-dobel (pakai hanya untuk debug)}
                            {--audit-interval=300 : Interval audit/self-healing internal dalam detik (minimum 60)}
                            {--no-self-heal : Audit saja; jangan lakukan repair otomatis}';

    protected $description =
        'Menjalankan AUTO SYNC LMS eduXid secara berkala (multi-guru).';

    protected int $iterations = 0;

    protected ?int $lastAuditAt = null;

    protected const RETRY_EVERY = 12;

    protected const GRADES_FLUSH_EVERY = 12;

    protected const RETRY_LIMIT = 50;

    protected const MASTER_PUSH_MIN_INTERVAL = 300;

    protected const HEARTBEAT_KEY = 'daemon::heartbeat';

    protected const HEARTBEAT_STALE_MIN_SECONDS = 300;

    /** [D15] Kunci pemilik push master — diisi panel admin (Fase 2). */
    protected const MASTER_OWNER_KEY = 'master::owner_device';

    /*
     * [F1] Revisi Wave 1b:
     *  - [DK-04] Device yatim (tanpa sync_token) di-skip senyap
     *    (dihitung, tidak di-warn tiap 5 detik selamanya).
     *  - [D15] Push master hanya dari laptop admin pemilik terdaftar
     *    (master::owner_device) — laptop guru biasa melewati.
     *  - engine.sync() kini menguras antrean (loop has_more) + lock
     *    bersama dengan endpoint web.
     */

    public function handle(LmsSyncEngine $syncEngine, SyncAuditService $syncAudit): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $once = (bool) $this->option('once');
        $force = (bool) $this->option('force');
        $auditInterval = max(60, (int) $this->option('audit-interval'));
        $selfHeal = !(bool) $this->option('no-self-heal');

        $this->info('eduXid LMS AUTO SYNC aktif (multi-guru).');
        $this->line('Interval: ' . $interval . ' detik');
        $this->line('Salinan  : ' . base_path());
        $this->line('Audit    : setiap ' . $auditInterval . ' detik | self-healing: ' . ($selfHeal ? 'ON' : 'OFF'));
        $this->newLine();

        if (!$once && !$force) {
            if (!$this->takeOverIfNoLivingDaemon($interval)) {
                return self::SUCCESS;
            }
        }

        $lastWaitingMessage = null;
        $wasWaiting = false;
        $lastApplyFailureFingerprint = [];
        $lastLegacyDeviceCount = null;

        do {

            try {

                if (!$once) {
                    $this->writeHeartbeat();
                }

                $schoolId = app(LmsSyncService::class)->getSchoolId();

                if ($wasWaiting) {
                    $this->info(
                        '[' . now()->format('Y-m-d H:i:s') . '] IDENTITAS SIAP'
                        . ' | school_id: ' . $schoolId
                        . ' — sinkronisasi dilanjutkan.'
                    );
                    $wasWaiting = false;
                    $lastWaitingMessage = null;
                }

                $this->maybeFlushMaster();

                if ($this->iterations % self::GRADES_FLUSH_EVERY === 0) {
                    $this->maybeFlushGrades();
                    $this->maybeSyncAdminReportGrades();
                }

                /*
                 * [DK-04] Hanya device BER-TOKEN yang di-sync —
                 * device yatim tidak lagi memicu warn tiap siklus.
                 */
                $devices = DB::table('sync_devices')
                    ->where('school_id', $schoolId)
                    ->where('device_type', 'teacher')
                    ->where('is_active', true)
                    ->whereNotNull('sync_token')
                    ->where('sync_token', '!=', '')
                    ->orderBy('device_id')
                    ->pluck('device_id');

                // V21R59: Restored pre-V21 databases may contain active, token-bearing
                // legacy device rows. These tokens cannot authenticate an opaque V21
                // address. Never guess an alias, borrow another GTK token, or send a
                // personal-ID-bearing device address to the Cloud. Retain rows so the
                // operator can re-pair safely; filter only this daemon's pull queue.
                if (CloudOutboundPrivacyGuardV21::enabled()) {
                    $legacyCount = 0;
                    $devices = $devices->filter(function ($deviceId) use ($schoolId, &$legacyCount): bool {
                        try {
                            CloudOutboundPrivacyGuardV21::assertDevice($schoolId, (string) $deviceId, 'teacher');
                            return true;
                        } catch (\RuntimeException) {
                            $legacyCount++;
                            return false;
                        }
                    })->values();
                    if ($legacyCount !== $lastLegacyDeviceCount) {
                        $lastLegacyDeviceCount = $legacyCount;
                        if ($legacyCount > 0) {
                            $this->warn(
                                '[' . now()->format('Y-m-d H:i:s') . '] V21 LEGACY DEVICE: '
                                . $legacyCount . ' perangkat lama tidak di-pull. '
                                . 'Data dan token lokal tetap disimpan. '
                                . 'Pairing ulang tiap GTK dengan identitas V21 miliknya melalui Admin Sekolah; '
                                . 'jangan mengganti device_id/token langsung di SQLite.'
                            );
                        } else {
                            $this->info(
                                '[' . now()->format('Y-m-d H:i:s')
                                . '] V21 LEGACY DEVICE: tidak ada perangkat lama dalam antrean pull.'
                            );
                        }
                    }
                }

                $cloudConnectedThisCycle = false;
                $received = 0;
                $applied = 0;
                $failed = 0;
                $acknowledged = 0;

                foreach ($devices as $deviceId) {

                    try {

                        $result = $syncEngine->sync($deviceId);

                        if (!empty($result['busy'])) {
                            continue;
                        }
                        // sync() returns only after a successful authenticated Cloud pull.
                        // Failed local application still counts as a Cloud connection.
                        $cloudConnectedThisCycle = true;

                        $received += (int) ($result['received'] ?? 0);
                        $applied += (int) ($result['applied'] ?? 0);
                        $failed += (int) ($result['failed'] ?? 0);
                        $acknowledged += (int) ($result['acknowledged'] ?? 0);

                        $deviceFailures = array_values((array) ($result['failures'] ?? []));

                        if ($deviceFailures !== []) {
                            $brief = [];

                            foreach (array_slice($deviceFailures, 0, 5) as $failure) {
                                $brief[] = implode('|', [
                                    (string) ($failure['entity_type'] ?? '?'),
                                    (string) ($failure['event_id'] ?? '?'),
                                    (string) ($failure['error'] ?? $failure['reason'] ?? 'unknown'),
                                ]);
                            }

                            $fingerprint = sha1(implode("\n", $brief));

                            if (($lastApplyFailureFingerprint[$deviceId] ?? null) !== $fingerprint) {
                                $lastApplyFailureFingerprint[$deviceId] = $fingerprint;

                                foreach (array_slice($deviceFailures, 0, 3) as $failure) {
                                    $this->warn(
                                        '[' . now()->format('Y-m-d H:i:s') . '] DEVICE '
                                        . $deviceId
                                        . ' | APPLY FAIL'
                                        . ' | ENTITY: ' . ($failure['entity_type'] ?? '?')
                                        . ' | EVENT: ' . ($failure['event_id'] ?? '?')
                                        . ' | ' . ($failure['error'] ?? $failure['reason'] ?? 'unknown')
                                    );
                                }

                                if (count($deviceFailures) > 3) {
                                    $this->warn(
                                        '[' . now()->format('Y-m-d H:i:s') . '] DEVICE '
                                        . $deviceId
                                        . ' | +' . (count($deviceFailures) - 3)
                                        . ' kegagalan lain dengan batch yang sama.'
                                    );
                                }
                            }
                        } else {
                            unset($lastApplyFailureFingerprint[$deviceId]);
                        }

                    } catch (Throwable $deviceError) {
                        if ($this->looksOffline($deviceError->getMessage())) {
                            app(UpdateAfterSync::class)->markDisconnected();
                        }

                        $failed++;

                        $this->warn(
                            '[' . now()->format('Y-m-d H:i:s') . '] DEVICE '
                            . $deviceId . ' | SYNC ERROR: '
                            . $deviceError->getMessage()
                        );
                    }
                }

                $this->line(
                    '[' . now()->format('Y-m-d H:i:s') . '] ONLINE'
                    . ' | DEVICES: ' . $devices->count()
                    . ' | RECEIVED: ' . $received
                    . ' | PROCESSED: ' . $applied
                    . ' | FAILED: ' . $failed
                    . ' | ACK: ' . $acknowledged
                );

                // Connectivity-triggered update check, independent from pending sync events.
                // On installations without paired devices, the checker probes /health.
                // Its own lock/backoff avoids an HTTP request on each 5-second cycle.
                if (!$once) {
                    $update = app(UpdateAfterSync::class)->trigger($cloudConnectedThisCycle);
                    if (in_array($update, ['UPDATER_LAUNCHED', 'WAITING_SAFE', 'PREFLIGHT_BLOCKED'], true)) {
                        $this->line('[UPDATE] ' . $update);
                    }
                }

                // Cloud donation attestation is advisory and must never prevent
                // lesson/exam sync or force payments. One report per 15 minutes.
                if (!$once && $cloudConnectedThisCycle) {
                    try {
                        \App\Services\Security\DonationCloudIntegrityV21::heartbeat(base_path(), true);
                    } catch (Throwable $ignoredDonationAudit) {
                        // Offline-first: donation audit failure never blocks sync.
                    }
                }

                $this->maybeRunSyncAudit($syncAudit, $auditInterval, $selfHeal, $once);

            } catch (SchoolIdentityException $e) {

                $message = $e->getMessage();

                if ($message !== $lastWaitingMessage) {
                    $this->line(
                        '[' . now()->format('Y-m-d H:i:s') . '] WAITING | ' . $message
                    );
                    $lastWaitingMessage = $message;
                }

                $wasWaiting = true;

            } catch (Throwable $e) {

                $message = $e->getMessage();

                if ($this->looksOffline($message)) {
                    app(UpdateAfterSync::class)->markDisconnected();

                    $this->warn(
                        '[' . now()->format('Y-m-d H:i:s') . '] OFFLINE'
                        . ' | Sync Server tidak dapat dihubungi.'
                        . ' | Akan mencoba lagi.'
                    );

                } else {

                    $this->error(
                        '[' . now()->format('Y-m-d H:i:s') . '] SYNC ERROR: ' . $message
                    );
                }
            }

            $this->iterations++;

            if ($this->iterations % self::RETRY_EVERY === 0 || $once) {
                $this->sweepOutbox();
            }

            if ($once) {
                break;
            }

            sleep($interval);

        } while (true);

        $this->info('eduXid LMS AUTO SYNC berhenti.');

        return self::SUCCESS;
    }


    /* ============================================================
     * SYNC HEALTH AUDIT + SELF-HEALING
     * ============================================================ */

    protected function maybeRunSyncAudit(
        SyncAuditService $audit,
        int $auditInterval,
        bool $selfHeal,
        bool $forceNow = false
    ): void {
        $now = now()->timestamp;

        if (!$forceNow && $this->lastAuditAt !== null && ($now - $this->lastAuditAt) < $auditInterval) {
            return;
        }

        // Catat waktu sebelum audit agar error auditor tidak membuat daemon
        // mencoba audit berat pada setiap loop berikutnya.
        $this->lastAuditAt = $now;

        try {
            $report = $audit->run($selfHeal);
            $counts = $report['counts'] ?? [];

            $issues = (int) ($counts['issues'] ?? 0);
            $repairs = (int) ($counts['repairs'] ?? 0);

            if ($issues === 0 && $repairs === 0) {
                $this->line(
                    '[' . now()->format('Y-m-d H:i:s') . '] HEALTHY'
                    . ' | SYNC AUDIT OK'
                );
                return;
            }

            $message =
                '[' . now()->format('Y-m-d H:i:s') . '] SYNC HEALTH'
                . ' | ISSUES:' . $issues
                . ' | REPAIRS:' . $repairs
                . ' | STALE:' . (int) ($counts['stale_processing'] ?? 0)
                . ' | BAD_DATE_SCORE:' . (int) ($counts['assessment_scores_invalid_dates'] ?? 0)
                . ' | BAD_DATE_ATT:' . (int) ($counts['attendances_invalid_dates'] ?? 0)
                . ' | BAD_OUTBOX:' . (int) ($counts['malformed_attendance_outbox'] ?? 0)
                . ' | DEVICE_NO_TOKEN:' . (int) ($counts['active_devices_without_token'] ?? 0);

            if ($issues > 0) {
                $this->warn($message);
            } else {
                $this->info($message);
            }

            foreach (($report['repairs'] ?? []) as $repair) {
                $this->info(
                    '  SELF-HEAL ' . json_encode(
                        $repair,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );
            }
        } catch (Throwable $e) {
            // Auditor adalah fitur proteksi. Kegagalannya tidak boleh
            // menghentikan pull/apply/ACK daemon utama.
            $this->warn(
                '[' . now()->format('Y-m-d H:i:s') . '] SYNC AUDIT ERROR'
                . ' | ' . $e->getMessage()
                . ' | daemon tetap berjalan.'
            );
        }
    }

    /* ============================================================
     * GUARD ANTI-DOBEL (tetap)
     * ============================================================ */

    protected function takeOverIfNoLivingDaemon(int $interval): bool
    {
        $staleAfter = max(
            self::HEARTBEAT_STALE_MIN_SECONDS,
            $interval * 6
        );

        $heartbeat = MasterRegistry::get(self::HEARTBEAT_KEY);

        if (
            $heartbeat !== null
            && (now()->timestamp - strtotime($heartbeat)) <= $staleAfter
        ) {
            $this->line(
                '[' . now()->format('Y-m-d H:i:s') . '] Daemon sudah hidup'
                . ' (heartbeat ' . $heartbeat . ') — instance ini keluar.'
            );

            return false;
        }

        $this->writeHeartbeat();

        $this->line(
            '[' . now()->format('Y-m-d H:i:s') . '] Ambil alih daemon'
            . ' (heartbeat lama: ' . ($heartbeat ?? 'tidak ada') . ').'
        );

        return true;
    }

    protected function writeHeartbeat(): void
    {
        try {
            MasterRegistry::set(self::HEARTBEAT_KEY, now()->toIso8601String());
        } catch (Throwable) {
        }
    }

    /* ============================================================
     * MASTER PUSH — [D15] hanya laptop admin pemilik
     * ============================================================ */

    protected function maybeFlushMaster(): void
    {
        try {
            if (MasterRegistry::get(MasterRegistry::KEY_DIRTY) !== '1') {
                return;
            }

            /*
             * [D15] Pemilik push master: device terdaftar di
             * master::owner_device (diisi saat admin menjalankan
             * push dari panel — Fase 2). Bila terdaftar dan TIDAK
             * cocok dengan laptop ini → lewati (bukan error).
             * Bila belum terdaftar → berjalan (laptop pertama yang
             * mem-push otomatis menjadi pemilik de-facto).
             */
            $owner = MasterRegistry::get(self::MASTER_OWNER_KEY);

            try {
                $deviceId = app(LmsSyncService::class)->getDeviceId();
            } catch (Throwable) {
                $deviceId = null;
            }

            if ($owner !== null && $owner !== '' && $deviceId !== null && $owner !== $deviceId) {
                if ($this->iterations % 720 === 0) {
                    $this->line(
                        '[' . now()->format('Y-m-d H:i:s') . '] MASTER PUSH'
                        . ' | dilewati — push master hanya dari laptop admin ('
                        . $owner . ').'
                    );
                }

                return;
            }

            $last = MasterRegistry::get(MasterRegistry::KEY_LAST_PUSH_ATTEMPT);

            if (
                $last !== null
                && (now()->timestamp - strtotime($last)) < self::MASTER_PUSH_MIN_INTERVAL
            ) {
                return;
            }

            $result = app(MasterPushService::class)->flush();

            if (($result['status'] ?? '') === 'pushed') {
                if ($owner === null || $owner === '') {
                    MasterRegistry::set(self::MASTER_OWNER_KEY, (string) $deviceId);
                }

                $this->info(
                    '[' . now()->format('Y-m-d H:i:s') . '] MASTER PUSH'
                    . ' | Snapshot + direktori akun siswa/guru terkirim ke Cloud.'
                );
            } elseif (($result['status'] ?? '') === 'partial') {
                $this->warn(
                    '[' . now()->format('Y-m-d H:i:s') . '] MASTER PUSH'
                    . ' | Sebagian gagal, dicoba ulang nanti: '
                    . implode('; ', (array) ($result['report']['errors'] ?? []))
                );
            } elseif (($result['status'] ?? '') === 'busy') {
                $this->line(
                    '[' . now()->format('Y-m-d H:i:s') . '] MASTER PUSH'
                    . ' | Sedang berjalan di proses lain — dilewati.'
                );
            }
        } catch (Throwable $e) {
            $this->warn(
                '[' . now()->format('Y-m-d H:i:s') . '] MASTER PUSH'
                . ' | ditunda: ' . $e->getMessage()
            );
        }
    }

    /** Admin Sekolah menerima nilai akhir terverifikasi sebelum cetak rapor. */
    protected function maybeSyncAdminReportGrades(): void
    {
        try {
            $result = app(\App\Services\Sync\AdminReportGradeInbox::class)->sync();
            if (($result['applied'] ?? 0) > 0) {
                $this->info('[' . now()->format('Y-m-d H:i:s') . '] RAPOR ADMIN'
                    . ' | ' . $result['applied'] . ' event nilai akhir diterapkan.');
            }
        } catch (Throwable $e) {
            // No cursor advance on error; retries are safe, offline print uses
            // the last committed grades, never simulated sync success.
            $this->warn('[' . now()->format('Y-m-d H:i:s') . '] RAPOR ADMIN | sinkron tertunda: '
                . $e->getMessage());
        }
    }

    protected function maybeFlushGrades(): void
    {
        try {
            $result = app(WorkProductPushService::class)->flushPending(20);

            if (($result['pushed'] ?? 0) > 0) {
                $this->info(
                    '[' . now()->format('Y-m-d H:i:s') . '] GRADES PUSH'
                    . ' | ' . $result['pushed'] . ' guru terkirim otomatis.'
                );
            }

            if (($result['failed'] ?? 0) > 0) {
                $this->warn(
                    '[' . now()->format('Y-m-d H:i:s') . '] GRADES PUSH'
                    . ' | ' . $result['failed'] . ' guru gagal - dicoba ulang nanti.'
                );
            }
        } catch (Throwable $e) {
            $this->warn(
                '[' . now()->format('Y-m-d H:i:s') . '] GRADES PUSH'
                . ' | ditunda: ' . $e->getMessage()
            );
        }
    }

    protected function sweepOutbox(): void
    {
        try {

            $result = app(LmsSyncService::class)
                ->retryPending(self::RETRY_LIMIT);

            $synced = (int) ($result['success_count'] ?? 0);
            $failed = (int) ($result['failed_count'] ?? 0);
            $dead = (int) ($result['dead_lettered'] ?? 0);

            if ($synced > 0 || $failed > 0) {
                $this->line(
                    '[' . now()->format('Y-m-d H:i:s') . '] OUTBOX'
                    . ' | SYNCED: ' . $synced
                    . ' | FAILED: ' . $failed
                    . ($dead > 0 ? ' | DEAD-LETTER: ' . $dead : '')
                );
            }

        } catch (Throwable $e) {

            $this->warn(
                '[' . now()->format('Y-m-d H:i:s') . '] OUTBOX'
                . ' | retry ditunda: ' . $e->getMessage()
            );
        }
    }

    protected function looksOffline(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'curl error')
            || str_contains($lower, 'connectionexception')
            || str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'timed out');
    }
}