<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckLocalSyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Sync-Token');

        $serverToken = trim((string) config('eduxid.sync_token', ''));
        // SECURITY V19: LAN sync is disabled until provisioned with a unique
        // per-installation secret. Never accept the public image fallback.
        if ($serverToken === '' || hash_equals('eduxid_secret_2024', $serverToken)) {
            Log::warning('[LAN-SYNC] Sinkronisasi ditolak: token lokal unik belum disetel.', [
                'ip' => $request->ip(), 'path' => $request->path(),
            ]);
            return response()->json([
                'error' => 'Sinkronisasi LAN belum dikonfigurasi. Admin harus menetapkan token acak unik pada kedua perangkat.',
            ], 503);
        }
        if (!is_string($token) || !hash_equals($serverToken, $token)) {
            return response()->json(['error' => 'Unauthorized. Token tidak valid.'], 401);
        }

        return $next($request);
    }
}