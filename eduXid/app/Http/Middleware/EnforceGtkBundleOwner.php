<?php

namespace App\Http\Middleware;

use App\Services\GtkBundle\Installation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceGtkBundleOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $owner = Installation::owner();
        if ($owner === null) return $next($request);
        $check = static function () use ($owner, $request): void {
            $user = Auth::user();
            if (!$user) return;
            // Login siswa LAN tetap didukung; GTK lain tidak menjadi pemilik instalasi.
            $role = strtolower(trim((string) $user->role));
            if (in_array($role, ['siswa','student'], true)) return;
            if (trim((string) $user->login_id) === $owner['login_id'] && $role !== 'admin') return;
            Auth::logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(403, 'Paket ini disiapkan untuk GTK pemiliknya. Gunakan paket milik akun Anda.');
        };
        $check();
        $response = $next($request);
        $check(); // Termasuk akun yang baru login pada request ini.
        return $response;
    }
}
