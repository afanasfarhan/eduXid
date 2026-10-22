<?php

namespace App\Console\Commands;

use App\Services\Update\ReleaseDiscovery;
use App\Services\Update\UpdateLauncher;
use Illuminate\Console\Command;
use Throwable;

class EduxidUpdateAutoCommand extends Command
{
    protected $signature = 'eduxid:update:auto {--startup : Periksa Cloud hanya saat launcher dibuka}';
    protected $description = 'Pemeriksaan update satu kali saat launcher dijalankan; tanpa polling terjadwal';

    public function handle(ReleaseDiscovery $discovery, UpdateLauncher $launcher): int
    {
        // Pemanggilan lama oleh Scheduled Task atau daemon tidak boleh memeriksa Cloud.
        if (!$this->option('startup')) {
            $this->line('DISABLED_BACKGROUND_CHECK');
            return self::SUCCESS;
        }
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $dir = $root . DIRECTORY_SEPARATOR . '.eduxid-updater';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            $this->warn('Direktori status pembaruan tidak dapat dibuat; aplikasi tetap dibuka.');
            return self::SUCCESS;
        }
        $lock = @fopen($dir . DIRECTORY_SEPARATOR . 'startup-check.lock', 'c+');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            $this->line('UPDATE_CHECK_ALREADY_RUNNING');
            return self::SUCCESS;
        }
        try {
            // Jangan buat loop restart otomatis setelah rollback patch gagal.
            if (getenv('EDUXID_SKIP_STARTUP_UPDATE') === '1') {
                $this->line('SKIP_AFTER_ROLLBACK');
                return self::SUCCESS;
            }
            $release = $discovery->ensureLatest();
            if (!($release['available'] ?? false)) {
                $this->line('NO_UPDATE_OR_OFFLINE');
                return self::SUCCESS;
            }
            $this->info('UPDATE_READY ' . $release['version'] . ' source=' . $release['source']);
            $launcher->launch(3);
            // Kode 20 berarti launcher utama TIDAK boleh membuka Laravel
            // sebelum updater menyelesaikan pemasangan dan restart sendiri.
            return 20;
        } catch (Throwable $e) {
            $this->warn('Pemeriksaan update ditunda: ' . $e->getMessage());
            return self::SUCCESS;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
