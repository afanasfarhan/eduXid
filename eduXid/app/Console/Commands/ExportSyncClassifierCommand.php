<?php

namespace App\Console\Commands;

use App\Services\Sync\MasterSnapshotService;
use App\Services\Sync\SyncEventClassifier;
use Illuminate\Console\Command;

class ExportSyncClassifierCommand extends Command
{
    protected $signature = 'eduxid:export-classifier
                            {--check= : Path file Worker (index.js) untuk diverifikasi drift}';

    protected $description = 'Ekspor peta entity sinkron (PHP) sebagai konstanta JS Worker + verifikasi drift.';

    public function handle(): int
    {
        $fanout = SyncEventClassifier::FANOUT_FALLBACK_ENTITIES;
        $masterKeys = array_keys(MasterSnapshotService::TABLE_MAP);

        if (($path = trim((string) $this->option('check'))) !== '') {
            return $this->checkAgainstWorker($path, $fanout, $masterKeys);
        }

        $this->line('/* HASIL GENERATE — eduxid:export-classifier. JANGAN edit manual di Worker. */');
        $this->line('const TEACHER_ROUTED_ENTITIES = ' . json_encode(array_values($fanout)) . ';');
        $this->line('const TEACHER_REPLAY_ENTITIES = ' . json_encode(array_values($fanout)) . ';');
        $this->line('const MASTER_SNAPSHOT_KEYS = ' . json_encode(array_values($masterKeys)) . ';');

        $this->newLine();
        $this->info('Gunakan --check=/path/worker/index.js untuk memverifikasi Worker sinkron.');

        return self::SUCCESS;
    }

    protected function checkAgainstWorker(string $path, array $fanout, array $masterKeys): int
    {
        if (!is_file($path)) {
            $this->error("File Worker tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $source = (string) file_get_contents($path);
        $drift = [];

        foreach ($fanout as $entity) {
            if (!str_contains($source, '"' . $entity . '"') && !str_contains($source, "'" . $entity . "'")) {
                $drift[] = 'fanout/replay: ' . $entity;
            }
        }

        foreach ($masterKeys as $key) {
            if (!str_contains($source, '"' . $key . '"')) {
                $drift[] = 'master-snapshot: ' . $key;
            }
        }

        if ($drift !== []) {
            $this->error('DRIFT terdeteksi — entitas berikut tidak ditemukan di Worker:');
            foreach ($drift as $item) {
                $this->line('  - ' . $item);
            }
            return self::FAILURE;
        }

        $this->info('✓ Worker sinkron dengan classifier PHP (' . count($fanout) . ' fanout, ' . count($masterKeys) . ' master).');

        return self::SUCCESS;
    }
}