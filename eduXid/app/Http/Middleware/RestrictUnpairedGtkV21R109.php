<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AcademicPeriodService;
use App\Services\Sync\GtkPairedDeviceV21;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * eduXid V21R109: GTK belum pairing hanya dapat memakai empat menu.
 * Route web saja; tidak mengubah Cloud Worker, CBT/PWA siswa, atau token lokal.
 * Matching URI+method sengaja sempit; fitur baru ditolak secara default.
 */
final class RestrictUnpairedGtkV21R109
{
    public const NOTICE = 'Anda tidak bisa mengakses menu ini jika belum melakukan pairing. Hubungi admin sekolah atau pengembang eduXid di nomor WhatsApp 082253181899.';
    private const WA = 'https://wa.me/6282253181899';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !method_exists($user, 'roleName') ||
            !AcademicPeriodService::isGtkRole($user->roleName())) {
            return $next($request);
        }

        try {
            $paired = app(GtkPairedDeviceV21::class)->isPaired($user);
        } catch (Throwable) {
            $paired = false;
        }
        if ($paired || self::isAllowed($request)) {
            return $next($request);
        }

        $payload = [
            'success' => false,
            'code' => 'PAIRING_REQUIRED',
            'message' => self::NOTICE,
            'whatsapp' => self::WA,
        ];
        if ($request->expectsJson() || $request->ajax() ||
            $request->is('api/*') || str_contains((string) $request->header('Accept'), 'application/json')) {
            return response()->json($payload, 403)->header('Cache-Control', 'private, no-store');
        }
        return response()->view('errors.eduxid-gtk-pairing-v21r109', $payload, 403)
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Public helper dipakai test tanpa database, dan view untuk navigasi.
     * Merujuk pada nama route & method nyata; semua URL pada grup lain deny.
     */
    public static function isAllowed(Request $request): bool
    {
        $name = (string) ($request->route()?->getName() ?? '');
        $path = trim($request->path(), '/');
        $method = strtoupper($request->method());
        if ($method === 'HEAD') $method = 'GET';

        // Pemulihan login/pairing/semester; tidak membuka menu kelima.
        if (in_array($name, [
            'login', 'logout', 'password.confirm', 'password.update',
            'password.request', 'password.email', 'password.reset', 'password.store',
            'verification.notice', 'verification.verify', 'verification.send',
            'pilih.semester', 'pilih.semester.store',
            'lms.cloud.pair', 'cloud.pair',
            'profile.edit', 'profile.update',
        ], true)) return true;
        if ($path === 'cloud/pair' && $method === 'POST') return true;

        // Dasbor + ringkasan pengumuman yang dibaca pada Dasbor.
        if (in_array($name, ['dashboard', 'broadcast.preview'], true) && $method === 'GET') return true;
        // Buku Nilai, termasuk laporan, nilai akhir, pemilihan penugasan dan denah.
        if ($name === 'guru.mengajar' && $method === 'GET') return true;
        if (preg_match('/^guru\\.nilai(?:Akhir)?(?:\\.|$)/', $name) === 1) return true;
        if (in_array($name, ['guru.nilai', 'guru.nilaiAkhir', 'guru.nilaiAkhir.store',
            'guru.nilaiAkhir.export', 'guru.nilai.store', 'guru.nilai.export',
            'guru.nilai.destroyPertemuan', 'guru.atp.description.auto',
            'guru.atp.description.delete', 'guru.atp.generateDescriptions',
            'rombel.index', 'rombel.show', 'student.profile.overlay',
            'api.seating.update', 'api.seating.reset', 'api.sikap.update'], true)) return true;

        // Pengaturan GTK. Jangan memberikan akses ke area admin/pengaturan.
        if ($name === 'guru.pengaturan' && $method === 'GET') return true;
        if (str_starts_with($path, 'pengaturan/') &&
            str_starts_with($name, 'guru.')) return true;

        // Donasi (menu "Donasi" terletak pada about.index).
        if ($name === 'about.index' && $method === 'GET') return true;
        if (in_array($name, ['donasi.claim', 'donasi.status', 'donasi.redeem'], true)) return true;

        return false;
    }
}
