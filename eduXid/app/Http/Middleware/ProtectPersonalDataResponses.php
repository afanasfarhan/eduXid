<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProtectPersonalDataResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        // A JSON status poll is not a navigated page. Laravel StartSession
        // must not overwrite the previous browser URL with /donasi/status.
        if ($request->isMethod('GET')
            && $request->is('donasi/status')
            && $request->wantsJson()
            && ! $request->ajax()) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // Dokumen GTK, Excel, halaman login/pairing dan API privat tidak disimpan di shared cache.
        if ($request->user() || $request->is('login', 'pwa/*', 'api/pwa/*', 'api/device/*')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }
        return $response;
    }
}
