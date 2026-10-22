<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Security\DonationIntegrityV21;
use Illuminate\Console\Command;

final class EduxidDonationIntegrityCheckV21 extends Command
{
    protected $signature = 'eduxid:donation-integrity:check {--json : Output laporan tanpa rahasia dalam JSON}';
    protected $description = 'Verifikasi file Donasi/Reminder tanpa mengubah file atau data.';

    public function handle(): int
    {
        $result = DonationIntegrityV21::verify(base_path(), (string) config('eduxid.update_public_key', ''));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('eduXid DONATION INTEGRITY V21R36');
            $this->line('MODE: '.$result['mode']);
            $this->line('STATUS: '.($result['ok'] ? 'PASS' : 'TAMPERED'));
            foreach ($result['errors'] as $error) $this->error($error);
        }
        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
