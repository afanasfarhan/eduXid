<?php

namespace App\Console\Commands;

use App\Services\UpdateReleasePublisherService;
use Illuminate\Console\Command;
use Throwable;

class PublishUpdateReleaseCommand extends Command
{
    protected $signature = 'eduxid:update-publish
        {version : Versi patch, contoh 1.2.3}
        {--target=core : core, school, atau teacher}';

    protected $description = 'Menerbitkan ZIP patch lokal menjadi signed release eduXid.';

    public function handle(UpdateReleasePublisherService $publisher): int
    {
        try {
            $manifest = $publisher->publish(
                (string) $this->argument('version'),
                (string) $this->option('target')
            );

            $this->info('SIGNED RELEASE BERHASIL');
            $this->line('Version : ' . $manifest['version']);
            $this->line('Target  : ' . $manifest['target']);
            $this->line('SHA-256 : ' . $manifest['sha256']);
            $this->line('Algoritma: ' . $manifest['signature_algorithm']);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('PUBLISH GAGAL: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
