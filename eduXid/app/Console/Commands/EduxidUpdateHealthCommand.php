<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class EduxidUpdateHealthCommand extends Command
{
    protected $signature = 'eduxid:update:health';
    protected $description = 'Health check minimum setelah update eduXid';

    public function handle(): int
    {
        try {
            if (!is_file(base_path('artisan'))) {
                throw new \RuntimeException('artisan tidak ditemukan.');
            }

            if (!is_dir(base_path('app')) || !is_dir(base_path('routes'))) {
                throw new \RuntimeException('Struktur aplikasi eduXid tidak lengkap.');
            }

            if (!is_writable(storage_path())) {
                throw new \RuntimeException('storage tidak writable.');
            }

            DB::select('select 1');

            $this->info('HEALTHY');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('UNHEALTHY: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
