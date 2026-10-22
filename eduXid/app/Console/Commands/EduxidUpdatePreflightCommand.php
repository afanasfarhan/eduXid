<?php

namespace App\Console\Commands;

use App\Services\Update\CurrentVersion;
use App\Services\Update\ReleaseVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EduxidUpdatePreflightCommand extends Command
{
    protected $signature = 'eduxid:update:preflight {--file=} {--edition=}';
    protected $description = 'Memeriksa apakah patch eduXid aman dipasang sekarang';

    public function handle(ReleaseVerifier $verifier, CurrentVersion $currentVersion): int
    {
        try {
            $file = trim((string) $this->option('file'));
            $edition = trim((string) ($this->option('edition') ?: config('eduxid.edition', '')));
            $manifest = $verifier->verify($file, $edition);

            $current = $currentVersion->get();
            $target = (string) $manifest['version'];
            $minimum = (string) $manifest['min_supported_version'];

            if (version_compare($target, $current, '<=')) {
                $this->line("SKIP: versi {$target} sudah terpasang / tidak lebih baru dari {$current}.");
                return 20;
            }

            if (version_compare($current, $minimum, '<')) {
                $this->error("Versi saat ini {$current} terlalu lama. Patch membutuhkan minimal {$minimum}.");
                return self::FAILURE;
            }

            if ($this->hasActiveCbtSession() || $this->hasCriticalRequests()) {
                $this->warn('PENDING: CBT aktif atau operasi kritis belum aman untuk pembaruan.');
                return 10;
            }

            $required = max(100 * 1024 * 1024, ((int) @filesize($file)) * 3);
            $free = @disk_free_space((string) config('eduxid.install_root'));

            if (is_float($free) || is_int($free)) {
                if ($free < $required) {
                    $this->error('Ruang disk tidak cukup untuk staging + backup update.');
                    return self::FAILURE;
                }
            }

            if (!is_writable(base_path()) || !is_writable(storage_path())) {
                $this->error('Folder aplikasi/storage tidak memiliki izin tulis.');
                return self::FAILURE;
            }

            $this->info("READY {$current} -> {$target}");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function hasCriticalRequests(): bool
    {
        $dir=rtrim((string)config('eduxid.install_root'), '\\/').DIRECTORY_SEPARATOR.'.eduxid-updater';
        if (is_file($dir.DIRECTORY_SEPARATOR.'critical-tracking-error.flag')) return true;
        foreach (glob($dir.DIRECTORY_SEPARATOR.'critical'.DIRECTORY_SEPARATOR.'*.busy') ?: [] as $file) {
            // Fail closed if any active request marker exists; stale markers need operator recovery.
            if (is_file($file)) return true;
        }
        return false;
    }

    private function hasActiveCbtSession(): bool
    {
        try {
            if (!Schema::hasTable('exam_sessions') || !Schema::hasColumn('exam_sessions', 'status')) {
                return false;
            }

            return DB::table('exam_sessions')
                ->where('status', 'sedang_mengerjakan')
                ->exists();
        } catch (Throwable) {
            // Database readiness unavailable: never assume that no CBT is active.
            return true;
        }
    }
}
