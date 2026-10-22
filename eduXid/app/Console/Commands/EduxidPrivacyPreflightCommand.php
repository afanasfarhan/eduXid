<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Diagnostics only: never dumps NIP/NIK or raw device identifiers. */
final class EduxidPrivacyPreflightCommand extends Command
{
    protected $signature = 'eduxid:privacy-preflight';
    protected $description = 'Memeriksa konfigurasi transport dan format identitas tanpa membuka data sensitif';
    public function handle(): int
    {
        $url = \App\Services\Sync\EduxidCloudConfig::syncServer();
        $this->line($url === '' ? '[BLOCK] HTTPS Cloud belum dikonfigurasi dengan benar' : '[OK] URL Cloud menggunakan HTTPS');
        $legacy = 0;
        try {
            $legacy = DB::table('sync_devices')->where('device_id', 'like', '%-HP')->orWhere('device_id','like','%-PC')->count();
        } catch (\Throwable) { $this->warn('Tabel sync_devices belum dapat diakses.'); }
        $this->line("[INFO] Perangkat dengan format ID legacy: {$legacy} (identitas tidak dicetak)");
        if ($legacy) $this->warn('ID legacy masih memakai identitas pribadi. Perlindungan HTTPS tidak menggantikan migrasi identitas anonim pada Cloud/D1.');
        return $url === '' ? self::FAILURE : self::SUCCESS;
    }
}
