<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PwaStudentAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'npsn' => ['required', 'string', 'max:50'],
            'nisn' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        $npsn = trim((string) $request->input('npsn'));
        $nisn = trim((string) $request->input('nisn'));
        $password = (string) $request->input('password');

        $rateKey = 'pwa-student-login:' . strtolower($npsn) . '|' . strtolower($nisn) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam ' . $seconds . ' detik.',
            ], 429);
        }

        $school = School::query()->where('npsn', $npsn)->first();

        if (!$school) {
            RateLimiter::hit($rateKey, 300);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'NPSN sekolah tidak ditemukan.',
            ], 401);
        }

        $user = User::query()
            ->where('login_id', $nisn)
            ->where('role', 'siswa')
            ->first();

        if (!$user) {
            RateLimiter::hit($rateKey, 300);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'NISN atau password salah.',
            ], 401);
        }

        if (!Hash::check($password, $user->password)) {
            RateLimiter::hit($rateKey, 300);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'NISN atau password salah.',
            ], 401);
        }

        $student = Student::query()->where('nisn', $nisn)->first();

        if (!$student) {
            RateLimiter::hit($rateKey, 300);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Data murid tidak ditemukan.',
            ], 404);
        }

        /*
         * SCHOOL ISOLATION:
         * Siswa wajib terbukti milik sekolah dengan NPSN yang
         * dimasukkan. Tanpa ini, siswa sekolah lain dapat login
         * memakai NPSN sekolah ini dan device-nya terdaftar
         * pada school_id yang salah di Cloud.
         */
        $studentNpsn = $student->npsn ?? $student->school_npsn ?? null;

        if ($studentNpsn !== null && trim((string) $studentNpsn) !== '' && (string) $studentNpsn !== (string) $npsn) {
            RateLimiter::hit($rateKey, 300);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Murid ini bukan milik sekolah dengan NPSN tersebut.',
            ], 403);
        }

        $enrollments = Enrollment::query()
            ->with('homeroom')
            ->where('student_id', $student->id)
            ->whereNotNull('homeroom_id')
            ->whereHas('homeroom')
            ->orderBy('id')
            ->get();

        if ($enrollments->isEmpty()) {
            RateLimiter::hit($rateKey, 300);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Data rombel murid tidak ditemukan.',
            ], 404);
        }

        $primaryEnrollment = $enrollments->first();

        RateLimiter::clear($rateKey);

        Auth::login($user);
        $request->session()->regenerate();

        return $this->jsonResponse([
            'success' => true,
            'student' => [
                'school_id' => $npsn,
                'user_id' => $user->id,
                'student_nisn' => $nisn,
                'login_id' => $nisn,
                'name' => $student->nama,
                'homeroom_id' => $primaryEnrollment->homeroom_id,
                'homeroom_name' => $primaryEnrollment->homeroom->nama,
                'homeroom_ids' => $enrollments->pluck('homeroom_id')->values()->all(),
            ],
        ]);
    }

    private function jsonResponse(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status)
            ->header('Cache-Control', 'no-store');
    }
}