<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncAuditService;
use Illuminate\Console\Command;
use Throwable;

class SyncAuditCommand extends Command
{
    protected $signature = 'eduxid:sync-audit
        {--repair : Terapkan self-healing yang aman}
        {--watch : Jalankan audit berulang}
        {--interval=60 : Interval watch dalam detik}';

    protected $description = 'Audit kesehatan sinkronisasi eduXid dan self-healing metadata/outbox yang aman.';

    public function handle(SyncAuditService $audit): int
    {
        $watch = (bool) $this->option('watch');
        $repair = (bool) $this->option('repair');
        $interval = max(30, (int) $this->option('interval'));

        do {
            try {
                $report = $audit->run($repair);
                $counts = $report['counts'];

                $this->line(
                    '[' . now()->format('Y-m-d H:i:s') . '] SYNC AUDIT'
                    . ' | ISSUES:' . ($counts['issues'] ?? 0)
                    . ' | REPAIRS:' . ($counts['repairs'] ?? 0)
                    . ' | STALE:' . ($counts['stale_processing'] ?? 0)
                    . ' | BAD_DATE_SCORE:' . ($counts['assessment_scores_invalid_dates'] ?? 0)
                    . ' | BAD_DATE_ATT:' . ($counts['attendances_invalid_dates'] ?? 0)
                    . ' | BAD_OUTBOX:' . ($counts['malformed_attendance_outbox'] ?? 0)
                    . ' | DEVICE_NO_TOKEN:' . ($counts['active_devices_without_token'] ?? 0)
                );

                foreach ($report['issues'] as $issue) {
                    $this->warn('  ISSUE  ' . json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                foreach ($report['repairs'] as $fixed) {
                    $this->info('  REPAIR ' . json_encode($fixed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (Throwable $e) {
                $this->error('SYNC AUDIT ERROR: ' . $e->getMessage());
            }

            if (!$watch) {
                break;
            }
            sleep($interval);
        } while (true);

        return self::SUCCESS;
    }
}
