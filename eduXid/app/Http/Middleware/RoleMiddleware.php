<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu.');
        }

        if ($user->isAdmin()) {
            return $next($request);
        }

        $userRole = $user->roleName();

        // Izin khusus penugasan pembina ekskul Tendik, berlaku HANYA
        // untuk empat rute Ekskul. Jangan mengizinkan seluruh route
        // kelompok "pengajar" karena memberi akses di luar tugas.
        if (
            $userRole === 'tendik'
            && $request->routeIs(
                'guru.ekskul',
                'guru.ekskul.store',
                'guru.ekskul.addMember',
                'guru.ekskul.removeMember'
            )
            && $user->hasAdditionalDuty('ekskul')
        ) {
            return $next($request);
        }

        foreach ($roles as $role) {
            $role = strtolower(trim((string) $role));

            // Tugas tambahan Tendik adalah capability, bukan perubahan users.role.
            // Contoh: tugas_ekskul, tugas_wali, tugas_bk, tugas_kokurikuler.
            if (str_starts_with($role, 'tugas_')) {
                $duty = substr($role, strlen('tugas_'));

                if ($user->hasAdditionalDuty($duty)) {
                    return $next($request);
                }

                continue;
            }
            // Pengajar adalah kemampuan tambahan, bukan nilai baru users.role.
            if ($role === 'pengajar') {
                if ($user->canTeach()) {
                    return $next($request);
                }

                continue;
            }

            if ($userRole === $role) {
                return $next($request);
            }
        }

        abort(403, 'ANDA TIDAK PUNYA AKSES KE HALAMAN INI.');
    }
}
