<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\CloudManualTeacherReadinessV21R98;
use Illuminate\Console\Command;
use Throwable;

/** Aggregate-only diagnostic: no personal identifiers or secrets. */
final class EduxidManualGtkCloudAuditV21R98 extends Command
{
    protected $signature = 'eduxid:manual-gtk-cloud-audit';
    protected $description = 'Audit akun GTK manual untuk publikasi Cloud (read-only).';

    public function handle(CloudManualTeacherReadinessV21R98 $readiness): int
    {
        try {
            $counts = $readiness->inspect();
            $this->line('EDUXID_V21R98: READ_ONLY');
            foreach ($counts as $name => $count) {
                $this->line(strtoupper($name).': '.(int) $count);
            }
            $this->line('STATUS_KEAMANAN: TIDAK_ADA_DATA_YANG_DIUBAH');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('[BLOCKED V21R98] Pemeriksaan tidak dapat diselesaikan: '.preg_replace('/[^A-Za-z0-9 _.,:-]/', '', $e->getMessage()));
            return self::FAILURE;
        }
    }
}
