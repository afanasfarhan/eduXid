<?php

namespace App\Console\Commands;

use App\Models\SyncDeadLetter;
use App\Services\Sync\SyncApplier;
use App\Services\Sync\SyncOutcome;
use Illuminate\Console\Command;
use Throwable;

class ReplayDeadLettersCommand extends Command
{
    protected $signature = 'eduxid:replay-dead
                            {--device= : Hanya untuk device tertentu}
                            {--limit=50 : Maksimal event per run}
                            {--include-retrying : Sertakan yang masih retrying}';

    protected $description = 'Replay event dead-letter (mis. setelah master diperbarui).';

    public function handle(SyncApplier $applier): int
    {
        $query = SyncDeadLetter::query()->whereIn('status', ['dead']);

        if ($this->option('include-retrying')) {
            $query->orWhere('status', 'retrying');
        }

        if ($device = $this->option('device')) {
            $query->where('device_id', $device);
        }

        $rows = $query->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada dead-letter untuk di-replay.');
            return self::SUCCESS;
        }

        $ok = 0;
        $still = 0;

        foreach ($rows as $row) {
            $change = [
                'event_id'    => $row->event_id,
                'entity_type' => $row->entity_type,
                'entity_id'   => $row->entity_id,
                'action'      => $row->operation ?? 'update',
                'version'     => $row->version ?? 1,
                'payload'     => $row->payload ?? [],
            ];

            try {
                $result = $applier->apply($change, $row->device_id);
            } catch (Throwable $e) {
                $this->line("  GAGAL  {$row->event_id} :: {$e->getMessage()}");
                $still++;
                continue;
            }

            if (in_array($result['outcome'], [SyncOutcome::APPLIED, SyncOutcome::SKIPPED_STALE, SyncOutcome::SKIPPED_DUPLICATE], true)) {
                $row->update(['status' => 'resolved', 'resolved_at' => now()]);
                $this->line("  OK     {$row->event_id} ({$row->entity_type})");
                $ok++;
            } else {
                $this->line("  MASIH  {$row->event_id} :: {$result['reason']}");
                $still++;
            }
        }

        $this->info("Selesai: berhasil={$ok}, masih tertahan={$still}.");
        return self::SUCCESS;
    }
}