<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CbtServeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbt:serve {port=8000} {--host=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mulai server CBT yang dapat diakses via WiFi Lokal oleh siswa';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $port = $this->argument('port');
        
        // Deteksi IP Lokal komputer guru secara otomatis
        $host = $this->option('host') ?: getHostByName(getHostName());
        
        // Pastikan SQLite WAL aktif sebelum server berjalan
        try {
            DB::statement('PRAGMA journal_mode = WAL;');
            DB::statement('PRAGMA busy_timeout = 5000;');
            $this->info("✅ SQLite WAL Mode diaktifkan untuk CBT.");
        } catch (\Exception $e) {
            $this->warn("⚠️ Tidak bisa mengaktifkan WAL Mode: " . $e->getMessage());
        }

        $this->info("=========================================");
        $this->info("🚀 Server CBT eduXid dimulai!");
        $this->info("=========================================");
        $this->info("👨‍🏫 Akses Guru   : http://127.0.0.1:{$port}");
        $this->info("📶 Akses Siswa   : http://{$host}:{$port}");
        $this->info("=========================================");
        $this->info("Pastikan firewall komputer guru mengizinkan akses port {$port}.");
        $this->info("Tekan Ctrl+C untuk menghentikan server.");

        // Jalankan PHP Native Server
        passthru("php -S {$host}:{$port} -t public");
    }
}