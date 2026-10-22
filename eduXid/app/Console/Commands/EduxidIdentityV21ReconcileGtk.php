<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\CloudTeacherAliasRosterV21;
use Illuminate\Console\Command;
use Throwable;

final class EduxidIdentityV21ReconcileGtk extends Command
{
    protected $signature = 'eduxid:identity-v21-reconcile-gtk';
    protected $description = 'Rekonsiliasi alias siswa pada laptop GTK paired (tanpa kunci sekolah).';

    public function handle(CloudTeacherAliasRosterV21 $service): int
    {
        try {
            $result = $service->reconcile();
            $this->info('PASS: alias siswa tersinkron dalam registry lokal.');
            $this->line('Mapping: ' . (int)($result['mapped'] ?? 0)
                . '; scope ditolak: ' . (int)($result['scope_rejected'] ?? 0));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
