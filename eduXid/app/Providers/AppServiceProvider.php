<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Jangan tampilkan SQL, token atau identitas GTK pada halaman error produksi.
        // Aplikasi GTK didistribusikan sebagai server lokal. Jangan paparkan SQL/identitas
        // meskipun .env bawaan memiliki APP_ENV=local dan APP_DEBUG=true.
        if (!app()->environment('testing')) {
            config(['app.debug' => false]);
        }

        /*
         * [REWRITE-16] PRAGMA SQLite — guard lama memeriksa
         * database_path('database.sqlite') yang tidak ada pada
         * instalasi dengan path DB kustom (mis. <root>/eduxid),
         * sehingga WAL/busy_timeout/synchronous tidak pernah
         * diterapkan. Guard baru: cukup driver sqlite.
         *
         * synchronous = FULL: komit yang sudah dilaporkan sukses
         * (dan di-ACK ke cloud) tidak boleh hilang saat listrik
         * padam — durability di atas kecepatan.
         */
        if (config('database.default') === 'sqlite') {
            try {
                DB::statement('PRAGMA journal_mode = WAL;');
                DB::statement('PRAGMA busy_timeout = 5000;');
                DB::statement('PRAGMA synchronous = FULL;');
            } catch (\Throwable $e) {
                // Abaikan (mis. environment testing tanpa DB).
            }
        }

        // V21R109: seluruh route pada grup web memerlukan pemeriksaan pairing GTK
        // berbasis perangkat V21 lokal. Berjalan SETELAH sesi/cookie terbaca.
        // Tidak mengubah bootstrap/app.php atau routes/web.php bertanda tangan.
        \Illuminate\Support\Facades\Route::pushMiddlewareToGroup(
            'web', \App\Http\Middleware\RestrictUnpairedGtkV21R109::class
        );

        // Tidak ada bootstrap Task Scheduler dari request web.
        // Pemeriksaan Auto-Update hanya dilakukan sekali oleh launcher.
    }
}
