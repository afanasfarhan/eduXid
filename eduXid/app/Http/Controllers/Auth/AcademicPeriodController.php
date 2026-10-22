<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AcademicPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AcademicPeriodController extends Controller
{
    public function show(Request $request): View
    {
        $periods = AcademicPeriodService::periods();
        $current = AcademicPeriodService::selectedIfValid();
        $default = $current ?? AcademicPeriodService::latestSemesterId();

        return view('auth.pilih-semester', [
            'periods' => $periods,
            'currentSemesterId' => $current,
            'defaultSemesterId' => $default,
            'isGtk' => AcademicPeriodService::isGtkRole(
                $request->user()?->roleName()
                    ?? $request->user()?->role
                    ?? null
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'semester_id' => ['required', 'regex:/^\d{5}$/'],
        ]);

        try {
            AcademicPeriodService::select((string) $validated['semester_id']);
        } catch (\InvalidArgumentException $e) {
            return back()
                ->withErrors(['semester_id' => $e->getMessage()])
                ->withInput();
        }

        $redirect = redirect()
            ->intended(route('dashboard'))
            ->with(
                'success',
                'Periode aktif diubah ke ' .
                AcademicPeriodService::label((string) $validated['semester_id']) .
                '.'
            );

        if (AcademicPeriodService::isGtkRole($request->user()?->roleName() ?? $request->user()?->role)) {
            $redirect->with('donation_prompt_after_semester', true);
        }

        return $redirect;
    }
}
