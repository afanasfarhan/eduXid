<?php

namespace App\Services\Sync;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncAuditService
{
    private const VALID_STATUSES = ['pending', 'processing', 'synced', 'failed'];
    private const VALID_OPERATIONS = ['create', 'update', 'upsert', 'delete'];

    public function run(bool $repair = false): array
    {
        $report = [
            'checked_at' => now()->toIso8601String(),
            'repair' => $repair,
            'healthy' => true,
            'issues' => [],
            'repairs' => [],
            'counts' => [],
        ];

        $this->auditSourceDates($report);
        $this->auditOutbox($report, $repair);
        $this->auditDevices($report);

        $report['healthy'] = count($report['issues']) === 0;
        $report['counts']['issues'] = count($report['issues']);
        $report['counts']['repairs'] = count($report['repairs']);

        return $report;
    }

    private function auditSourceDates(array &$report): void
    {
        foreach (['assessment_scores', 'attendances'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tanggal')) {
                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull('tanggal')
                ->select(array_values(array_filter([
                    Schema::hasColumn($table, 'id') ? 'id' : null,
                    Schema::hasColumn($table, 'teaching_assignment_id') ? 'teaching_assignment_id' : null,
                    'tanggal',
                ])))
                ->get();

            $bad = [];
            foreach ($rows as $row) {
                $tanggal = trim((string) ($row->tanggal ?? ''));
                if (!$this->validDate($tanggal)) {
                    $bad[] = [
                        'id' => $row->id ?? null,
                        'teaching_assignment_id' => $row->teaching_assignment_id ?? null,
                        'tanggal' => $tanggal,
                    ];
                }
            }

            $report['counts'][$table . '_invalid_dates'] = count($bad);
            if ($bad !== []) {
                $report['issues'][] = [
                    'code' => 'INVALID_SOURCE_DATE',
                    'table' => $table,
                    'count' => count($bad),
                    'samples' => array_slice($bad, 0, 20),
                    'action' => 'manual_review_required',
                ];
            }
        }
    }

    private function auditOutbox(array &$report, bool $repair): void
    {
        if (!Schema::hasTable('lms_sync_records')) {
            $report['issues'][] = ['code' => 'OUTBOX_TABLE_MISSING'];
            return;
        }

        $staleBefore = now()->subMinutes(10);
        $staleProcessing = DB::table('lms_sync_records')
            ->where('status', 'processing')
            ->where('updated_at', '<', $staleBefore)
            ->count();

        $report['counts']['stale_processing'] = $staleProcessing;
        if ($staleProcessing > 0) {
            $report['issues'][] = ['code' => 'STALE_PROCESSING', 'count' => $staleProcessing];
            if ($repair) {
                $n = DB::table('lms_sync_records')
                    ->where('status', 'processing')
                    ->where('updated_at', '<', $staleBefore)
                    ->update([
                        'status' => 'pending',
                        'last_error' => 'SELF_HEAL: stale processing dikembalikan ke pending',
                        'updated_at' => now(),
                    ]);
                $report['repairs'][] = ['code' => 'RESET_STALE_PROCESSING', 'count' => $n];
            }
        }

        $invalidStatuses = DB::table('lms_sync_records')
            ->whereNotIn('status', self::VALID_STATUSES)
            ->count();
        $report['counts']['invalid_status'] = $invalidStatuses;
        if ($invalidStatuses > 0) {
            $report['issues'][] = ['code' => 'INVALID_STATUS', 'count' => $invalidStatuses];
            if ($repair) {
                $n = DB::table('lms_sync_records')
                    ->whereNotIn('status', self::VALID_STATUSES)
                    ->update([
                        'status' => 'failed',
                        'last_error' => 'SELF_HEAL: status ilegal dinormalisasi ke failed',
                        'updated_at' => now(),
                    ]);
                $report['repairs'][] = ['code' => 'NORMALIZE_STATUS', 'count' => $n];
            }
        }

        $badVersions = DB::table('lms_sync_records')->where('version', '<=', 0)->count();
        $report['counts']['invalid_version'] = $badVersions;
        if ($badVersions > 0) {
            $report['issues'][] = ['code' => 'INVALID_VERSION', 'count' => $badVersions];
            if ($repair) {
                $n = DB::table('lms_sync_records')->where('version', '<=', 0)->update([
                    'version' => 1,
                    'last_error' => 'SELF_HEAL: version <= 0 dinormalisasi ke 1',
                    'updated_at' => now(),
                ]);
                $report['repairs'][] = ['code' => 'NORMALIZE_VERSION', 'count' => $n];
            }
        }

        $badOperations = DB::table('lms_sync_records')
            ->whereNotIn('operation', self::VALID_OPERATIONS)
            ->count();
        $report['counts']['invalid_operation'] = $badOperations;
        if ($badOperations > 0) {
            $report['issues'][] = ['code' => 'INVALID_OPERATION', 'count' => $badOperations];
        }

        $badAttendanceIds = [];
        DB::table('lms_sync_records')
            ->where('entity_type', 'attendance_session')
            ->whereIn('status', ['pending', 'failed', 'processing'])
            ->where('operation', '!=', 'delete')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$badAttendanceIds) {
                foreach ($rows as $row) {
                    $payload = $this->decodePayload($row->payload ?? null);
                    $tanggal = trim((string) ($payload['tanggal'] ?? ''));
                    if (!$this->validDate($tanggal)) {
                        $badAttendanceIds[] = (int) $row->id;
                    }
                }
            });

        $report['counts']['malformed_attendance_outbox'] = count($badAttendanceIds);
        if ($badAttendanceIds !== []) {
            $report['issues'][] = [
                'code' => 'MALFORMED_ATTENDANCE_OUTBOX',
                'count' => count($badAttendanceIds),
                'ids' => array_slice($badAttendanceIds, 0, 50),
            ];
            if ($repair) {
                // Event invalid tidak boleh diretry selamanya. Hapus hanya OUTBOX-nya;
                // data akademik sumber tidak disentuh dan tetap dilaporkan di auditSourceDates().
                $n = DB::table('lms_sync_records')->whereIn('id', $badAttendanceIds)->delete();
                $report['repairs'][] = ['code' => 'QUARANTINE_MALFORMED_OUTBOX', 'count' => $n];
            }
        }

        if (Schema::hasColumn('lms_sync_records', 'synced_at')) {
            $syncedWithoutTimestamp = DB::table('lms_sync_records')
                ->where('status', 'synced')
                ->whereNull('synced_at')
                ->count();
            $report['counts']['synced_without_timestamp'] = $syncedWithoutTimestamp;
            if ($syncedWithoutTimestamp > 0) {
                $report['issues'][] = ['code' => 'SYNCED_WITHOUT_TIMESTAMP', 'count' => $syncedWithoutTimestamp];
                if ($repair) {
                    $n = DB::table('lms_sync_records')
                        ->where('status', 'synced')
                        ->whereNull('synced_at')
                        ->update(['synced_at' => now(), 'updated_at' => now()]);
                    $report['repairs'][] = ['code' => 'FILL_SYNCED_AT', 'count' => $n];
                }
            }
        }
    }

    private function auditDevices(array &$report): void
    {
        if (!Schema::hasTable('sync_devices')) {
            return;
        }

        $orphans = DB::table('sync_devices')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('sync_token')->orWhere('sync_token', '');
            })
            ->count();

        $report['counts']['active_devices_without_token'] = $orphans;
        if ($orphans > 0) {
            $report['issues'][] = [
                'code' => 'ACTIVE_DEVICE_WITHOUT_TOKEN',
                'count' => $orphans,
                'action' => 'review_pairing',
            ];
        }
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $value);
            return $d !== false && $d->format('Y-m-d') === $value;
        } catch (Throwable) {
            return false;
        }
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
