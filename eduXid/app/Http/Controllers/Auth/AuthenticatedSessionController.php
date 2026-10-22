<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * ============================================================
     * DISPLAY LOGIN
     * ============================================================
     */
    public function create(): View
    {
        return view('auth.login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * ============================================================
     * HANDLE LOGIN
     * ============================================================
     */
    public function store(
        LoginRequest $request
    ): RedirectResponse {

        /*
         * --------------------------------------------------------
         * AUTHENTICATE
         * --------------------------------------------------------
         */
        $request->authenticate();

        /*
         * Regenerasi session setelah login.
         */
        $request->session()->regenerate();

        /*
         * --------------------------------------------------------
         * USER LOGIN
         * --------------------------------------------------------
         */
        $user = $request->user();

        /*
         * --------------------------------------------------------
         * SISWA
         * --------------------------------------------------------
         *
         * Siswa diarahkan ke gerbang Cloud Login (bukan langsung
         * LMS) agar sesi Cloud (device_token) ikut terbentuk di
         * halaman yang sama — satu halaman menetapkan kedua identitas
         * (Laravel session + Cloud session).
         */
        if (
            $user
            && $user->isSiswa()
        ) {

            return redirect()->route(
                'pwa.cloud.login'
            );
        }

        /*
         * --------------------------------------------------------
         * USER LAIN
         * --------------------------------------------------------
         *
         * Admin, guru, wali kelas, guru BK,
         * kepala sekolah, dan role lainnya
         * tetap menggunakan dashboard.
         */
        return redirect()->intended(
            route(
                'dashboard',
                absolute: false
            )
        );
    }

    /**
     * ============================================================
     * LOGOUT
     * ============================================================
     */
    public function destroy(
        Request $request
    ): RedirectResponse {

        /*
         * Catat role SEBELUM logout (user sudah hilang setelah logout).
         */
        $wasStudent = $request->user()
            && $request->user()->isSiswa();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        /*
         * HANYA SISWA kembali ke gerbang Cloud Login;
         * role lain tetap ke beranda '/'.
         */
        return $wasStudent
            ? redirect()->route('pwa.cloud.login')
            : redirect('/');
    }

    /**
     * ============================================================
     * FORM PILIH SEMESTER
     * ============================================================
     */
    public function showSemesterForm(): View
    {
        /*
         * --------------------------------------------------------
         * AMBIL SEMESTER DARI HOMEROOM
         * --------------------------------------------------------
         */
        $semesters = DB::table('homerooms')
            ->select('semester_id')
            ->distinct()
            ->orderBy(
                'semester_id',
                'desc'
            )
            ->pluck('semester_id');

        /*
         * --------------------------------------------------------
         * FORMAT SEMESTER
         * --------------------------------------------------------
         */
        $semesterOptions = [];

        foreach (
            $semesters as $semId
        ) {

            if (
                strlen(
                    (string) $semId
                ) === 5
            ) {

                $tahun1 =
                    substr(
                        $semId,
                        0,
                        4
                    );

                $tahun2 =
                    $tahun1 + 1;

                $sem =
                    substr(
                        $semId,
                        4,
                        1
                    ) === '1'
                        ? 'Ganjil'
                        : 'Genap';

                $semesterOptions[$semId] =
                    "TA {$tahun1}/{$tahun2} - Semester {$sem}";

            } else {

                $semesterOptions[$semId] =
                    $semId;
            }
        }

        return view(
            'auth.pilih-semester',
            compact(
                'semesterOptions'
            )
        );
    }

    /**
     * ============================================================
     * SIMPAN SEMESTER AKTIF
     * ============================================================
     */
    public function storeSemester(
        Request $request
    ): RedirectResponse {

        /*
         * --------------------------------------------------------
         * VALIDASI
         * --------------------------------------------------------
         */
        $request->validate([
            'semester_id' => 'required|string',
        ]);

        /*
         * --------------------------------------------------------
         * SIMPAN SEMESTER
         * --------------------------------------------------------
         */
        session([
            'semester_aktif' =>
                $request->semester_id,
        ]);

        /*
         * --------------------------------------------------------
         * USER LOGIN
         * --------------------------------------------------------
         */
        $user = Auth::user();

        /*
         * --------------------------------------------------------
         * SISWA
         * --------------------------------------------------------
         *
         * Siswa diarahkan ke gerbang Cloud Login agar sesi Cloud
         * (device_token) ikut terbentuk — kedua identitas ditetapkan
         * di satu halaman sebelum masuk LMS.
         */
        if (
            $user
            && $user->isSiswa()
        ) {

            return redirect()->route(
                'pwa.cloud.login'
            );
        }

        /*
         * --------------------------------------------------------
         * USER LAIN
         * --------------------------------------------------------
         */
        return redirect()->route(
            'dashboard'
        );
    }
}