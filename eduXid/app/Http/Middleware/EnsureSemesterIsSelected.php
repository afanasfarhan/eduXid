<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AcademicPeriodService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSemesterIsSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $periods = AcademicPeriodService::availableSemesterIds();

        if ($periods === []) {
            AcademicPeriodService::forgetSelection();
            return $next($request);
        }

        $role = method_exists($user, 'roleName')
            ? $user->roleName()
            : strtolower(trim((string) ($user->role ?? '')));

        if ($role === 'siswa') {
            AcademicPeriodService::selectLatestForCurrentSession();
            return $next($request);
        }

        if (AcademicPeriodService::isGtkRole($role)) {
            if (AcademicPeriodService::selectedIfValid() === null) {
                return redirect()->guest(route('pilih.semester'));
            }

            return $next($request);
        }

        AcademicPeriodService::ensureSelectedForCurrentSession();

        return $next($request);
    }
}
