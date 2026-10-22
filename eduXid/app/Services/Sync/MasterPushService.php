<?php

namespace App\Services\Sync;

use Throwable;

/**
 * [F1 / D15] MASTER PUSH ORCHESTRATOR — rev.3
 * Setelah snapshot ter-push sukses, laptop yang menulis menjadi
 * PEMILIK push master (master::owner_device) bila belum ada —
 * daemon laptop lain kemudian melewati push master (LmsSyncCommand).
 */
class MasterPushService
{
    public const OWNER_KEY = 'master::owner_device';

    public function __construct(
        protected CloudMasterDataService $cloudMaster,
        protected LmsSyncService $sync
    ) {
    }

    public function flush(bool $force = false): array
    {
        $schoolId = $this->sync->getSchoolId();

        if (!$this->sync->isCloudConfigured()) {
            throw new \RuntimeException('URL eduXid Cloud belum dikonfigurasi.');
        }

        $lock = \Illuminate\Support\Facades\Cache::lock('master-push:' . $schoolId, 600);

        if (!$lock->get()) {
            return ['status' => 'busy', 'report' => []];
        }

        try {
            MasterRegistry::set(
                MasterRegistry::KEY_LAST_PUSH_ATTEMPT,
                now()->toIso8601String()
            );

            $report = [
                'snapshot' => null,
                'students' => null,
                'teachers' => null,
                'errors'   => [],
            ];

            try {
                $report['snapshot'] = $this->cloudMaster->pushSnapshot($force);
            } catch (Throwable $e) {
                $report['errors'][] = 'snapshot: ' . $e->getMessage();
            }

            try {
                $report['students'] = $this->sync->pushStudentAccountDirectory();
            } catch (Throwable $e) {
                $report['errors'][] = 'students: ' . $e->getMessage();
            }

            try {
                $report['teachers'] = $this->sync->pushTeacherAccountDirectory();
            } catch (Throwable $e) {
                $report['errors'][] = 'teachers: ' . $e->getMessage();
            }

            if ($report['errors'] === []) {
                MasterRegistry::forget(MasterRegistry::KEY_DIRTY);
                $this->stampOwnerIfEmpty();
            }

            return [
                'status' => $report['errors'] === [] ? 'pushed' : 'partial',
                'report' => $report,
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /** [D15] Laptop pertama yang berhasil push menjadi pemilik. */
    protected function stampOwnerIfEmpty(): void
    {
        try {
            if (MasterRegistry::get(self::OWNER_KEY) !== null && MasterRegistry::get(self::OWNER_KEY) !== '') {
                return;
            }

            $deviceId = $this->sync->getDeviceId();

            if ($deviceId !== null && $deviceId !== '') {
                MasterRegistry::set(self::OWNER_KEY, (string) $deviceId);
            }
        } catch (Throwable) {
            // Stempel gagal → daemon tetap menyegel saat push berikutnya.
        }
    }
}