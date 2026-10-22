<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * eduXid V17R2: only paired teacher PC devices may use the named features.
 * Verification is local: an established pairing remains usable offline.
 * Do not rely on Blade hiding navigation; this middleware protects requests.
 */
final class EnsureTeacherCloudPairing
{
    private const NOTICE = 'Fitur ini memerlukan pairing eduXid Cloud terlebih dahulu. Hubungi Admin Pusat melalui WhatsApp 082253181899 untuk melakukan pairing.';

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isRestrictedTeacherFeature($request)) {
            return $next($request);
        }

        // Authentication and roles still belong to the application's existing middleware.
        // A guest must NOT see internal Cloud pairing state.
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        // Restrictions apply to teaching features, not Tendik-only tasks.
        if (!method_exists($user, 'canTeach') || !$user->canTeach()) {
            return $next($request);
        }

        if (app(\App\Services\Sync\GtkPairedDeviceV21::class)->isPaired($user)) {
            return $next($request);
        }

        $payload = [
            'success' => false,
            'code' => 'EDUXID_CLOUD_PAIRING_REQUIRED',
            'message' => self::NOTICE,
            'whatsapp' => 'https://wa.me/6282253181899',
        ];

        if ($request->expectsJson() || $request->is('api/*') || $request->ajax()) {
            return response()->json($payload, 403);
        }

        // For unsafe methods: return 403, never perform the operation.
        // Preserve any submitted data from being accidentally replayed.
        return response()->view('errors.eduxid-pairing-required', [
            'notice' => self::NOTICE,
            'waUrl' => $payload['whatsapp'],
        ], 403);
    }

    private function isRestrictedTeacherFeature(Request $request): bool
    {
        $name = strtolower((string) ($request->route()?->getName() ?? ''));
        $path = strtolower(trim($request->path(), '/'));

        // Never obstruct dashboard pairing, sign-in, or student LMS/CBT.
        if (in_array($name, ['cloud.pair', 'lms.cloud.pair'], true)
            || $path === 'cloud/pair'
            || str_starts_with($path, 'siswa/')
            || str_starts_with($path, 'pwa/')
            || str_starts_with($path, 'api/local/')) {
            return false;
        }

        // Existing and future named teacher routes; all HTTP methods included.
        if ($name !== '') {
            // Catch LJK endpoints even if a module adds a new teacher route prefix.
            if (preg_match('/^guru\..*ljk(?:[.\-_]|$)/i', $name)) {
                return true;
            }
            if (preg_match('/^guru\.(?:bank[._-]?soal|cetak[._-]?soal|cbt|paket(?:[._-]?(?:ujian|soal))?|ljk|koreksi(?:[._-]?(?:ljk|cbt))?)(?:[.\-_]|$)/i', $name)) {
                return true;
            }
            if (preg_match('/^(?:lms\.|guru\.lms\.|ljk[.\-_]|guru\.ujian(?:[.\-_]|$)|guru\.koreksi(?:[.\-_]|$))/i', $name)) {
                return true;
            }
        }

        // Unnamed/legacy endpoints, including independently required route files.
        foreach ([
            '#^ujian/(?:bank-soal|cetak-soal|cbt|paket-soal|paket-ujian)(?:/|$)#',
            '#^guru/cbt/koreksi(?:/|$)#',
            '#^guru/(?:[^/]+/)*[^/]*ljk[^/]*(?:/|$)#',
            '#^guru/koreksi[-_/]?(?:cbt|ljk)(?:/|$)#',
            '#^guru/ujian(?:/|$)#', // Guru exam module includes alternate Paket and LJK routes.
            '#^(?:bank-soal|cetak-soal|paket-soal|paket-ujian|koreksi-cbt|koreksi-ljk)(?:/|$)#',
            '#^guru/(?:ujian/)?(?:bank-soal|cetak-soal|cbt|paket(?:-soal|-ujian)?)(?:/|$)#',
            '#^(?:guru/)?lms(?:/|$)#',
            '#^(?:guru/)?(?:koreksi-ljk|ljk-auto|ljk-correction)(?:/|$)#',
            '#^guru/(?:ujian/)?(?:ljk|koreksi/ljk|paket-ujian|paket-soal)(?:/|$)#',
            '#^ujian/(?:koreksi-cbt|koreksi-ljk)(?:/|$)#',
        ] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
