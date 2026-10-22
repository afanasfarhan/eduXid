<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Bootstrap ini berjalan sebelum Dotenv Laravel/config dimuat.
require_once __DIR__ . '/SchoolAliasBootstrapV21.php';
\EduXid\Bootstrap\SchoolAliasBootstrapV21::ensure(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {
        // Track critical HTTP writes to hold silent updater until the current request completes.
        $middleware->append(\App\Http\Middleware\TrackCriticalUpdateActivity::class);
        $middleware->append(\App\Http\Middleware\ProtectPersonalDataResponses::class);
        // Cloud-backed Donation integrity V21R38; learning features remain available.
        $middleware->append(\App\Http\Middleware\VerifyDonationIntegrityV21::class);


        /*
        |--------------------------------------------------------------------------
        | TRUST CLOUDFLARE TUNNEL
        |--------------------------------------------------------------------------
        |
        | Browser
        |     ↓ HTTPS
        | Cloudflare Tunnel
        |     ↓ HTTP ke localhost
        | Laravel
        |
        | Laravel harus mempercayai informasi proxy Cloudflare agar dapat
        | mengetahui bahwa request asli datang melalui HTTPS.
        |
        */

        $middleware->trustProxies(
            // Only local Tunnel/reverse-proxy peers are trusted by default.
            // Configure EDUXID_TRUSTED_PROXIES with a comma-separated list when needed.
            at: array_values(array_filter(array_map('trim', explode(',',
                (string) env('EDUXID_TRUSTED_PROXIES', '127.0.0.1,::1')
            )))),
            headers:
                Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO
        );


        /*
        |--------------------------------------------------------------------------
        | REDIRECT TAMU (SESI HABIS / BELUM LOGIN)
        |--------------------------------------------------------------------------
        |
        | HANYA untuk area LMS Siswa dan PWA: tamu diarahkan ke gerbang
        | Cloud Login (bukan /login) agar sesi Laravel + sesi Cloud
        | terbentuk bersamaan di satu halaman.
        |
        | Guru, admin, tendik, dan seluruh area lain tetap diarahkan
        | ke /login seperti semula — TIDAK berubah.
        |
        */

        $middleware->redirectGuestsTo(function (Request $request) {
            if (
                $request->is('siswa/lms')
                || $request->is('siswa/lms/*')
                || $request->is('pwa/*')
            ) {
                return route('pwa.cloud.login');
            }

            return route('login');
        });


        /*
        |--------------------------------------------------------------------------
        | ALIAS MIDDLEWARE KHUSUS APLIKASI
        |--------------------------------------------------------------------------
        */

        $middleware->alias([

            'role' =>
                \App\Http\Middleware\RoleMiddleware::class,

            'password.changed' =>
                \App\Http\Middleware\EnsurePasswordIsChanged::class,

            'semester.selected' =>
                \App\Http\Middleware\EnsureSemesterIsSelected::class,

            'local-sync' =>
                \App\Http\Middleware\CheckLocalSyncToken::class,

        ]);


        /*
        |--------------------------------------------------------------------------
        | CSRF EXCEPTION UNTUK LOCAL SYNC API
        |--------------------------------------------------------------------------
        */

        $middleware->validateCsrfTokens(except: [
            'api/local/*',
            'api/pwa/*',
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {
        //
    })

    ->create();