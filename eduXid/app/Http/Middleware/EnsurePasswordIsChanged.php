<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Jika user wajib ganti password, paksa dia ke halaman profile
        if ($user && $user->must_change_password) {
            // Kecualikan route profile, logout, dan POST update agar tidak terjadi loop redirect
            $allowedRoutes = ['profile.edit', 'profile.update', 'profile.destroy', 'logout'];
            
            if (!in_array($request->route()->getName(), $allowedRoutes)) {
                return Redirect::route('profile.edit')->with('warning', 'Anda harus mengganti password default sebelum dapat mengakses sistem.');
            }
        }

        return $next($request);
    }
}