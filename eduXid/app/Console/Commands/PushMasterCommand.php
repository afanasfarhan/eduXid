<?php

namespace App\Console\Commands;

use App\Services\Sync\MasterPushService;
use Illuminate\Console\Command;
use Throwable;

class PushMasterCommand extends Command
{
    protected $signature = 'eduxid:push-master
                            {--force : Push snapshot walau content_hash tidak berubah}';

    protected $description = 'Push master + direktori akun siswa/guru ke eduXid Cloud (sekali jalan).';

    public function handle(MasterPushService $pusher): int
    {
        try {
            $result = $pusher->flush((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('GAGAL: ' . $e->getMessage());
            return self::FAILURE;
        }

        $report = $result['report'] ?? [];

        $this->line('Status   : ' . ($result['status'] ?? '-'));

        if (isset($report['snapshot'])) {
            $this->line('Snapshot : ' . json_encode($report['snapshot'], JSON_UNESCAPED_UNICODE));
        }

        if (isset($report['students'])) {
            $this->line('Siswa    : ' . ($report['students']['message'] ?? '-'));
        }

        if (isset($report['teachers'])) {
            $this->line('Guru     : ' . ($report['teachers']['message'] ?? '-'));
        }

        foreach ((array) ($report['errors'] ?? []) as $err) {
            $this->warn('Error    : ' . $err);
        }

        return ($result['status'] ?? '') === 'pushed' ? self::SUCCESS : self::FAILURE;
    }
}