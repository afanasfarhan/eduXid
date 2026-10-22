<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GtkBundle\AutoPairBundleService;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

final class GtkFinalizeLatestBundleCommand extends Command
{
    protected $signature = 'eduxid:gtk-finalize-latest {--path= : Path ZIP bundle tertentu}';

    protected $description = 'Finalisasi Paket GTK terbaru agar clean-state dan memiliki ticket auto-pair.';

    public function handle(AutoPairBundleService $service): int
    {
        try {
            $path = trim((string) $this->option('path'));

            if ($path !== '') {
                $service->finalizeZip($path);
                $finalized = $path;
            } else {
                $finalized = $service->finalizeLatestStoredBundle(false);
            }

            if (!$finalized || !is_file($finalized)) {
                throw new RuntimeException('Tidak ada ZIP Paket GTK yang dapat difinalisasi.');
            }

            $zip = new ZipArchive();
            if ($zip->open($finalized) !== true) {
                throw new RuntimeException('ZIP hasil finalisasi tidak dapat dibuka: ' . $finalized);
            }

            $ticket = null;
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (!is_string($name)) {
                        continue;
                    }
                    $normalized = strtolower(str_replace('\\', '/', $name));
                    if ($normalized === 'storage/app/gtk-autopair.json' || str_ends_with($normalized, '/storage/app/gtk-autopair.json')) {
                        $ticket = $name;
                        break;
                    }
                }
            } finally {
                $zip->close();
            }

            if ($ticket === null) {
                throw new RuntimeException('Finalisasi selesai tetapi ticket gtk-autopair.json belum ada di ZIP.');
            }

            $this->info('[GTK AUTO-PAIR V1R2] Bundle berhasil difinalisasi.');
            $this->line('ZIP    : ' . $finalized);
            $this->line('Ticket : ' . $ticket);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('[GTK AUTO-PAIR V1R2] GAGAL: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
