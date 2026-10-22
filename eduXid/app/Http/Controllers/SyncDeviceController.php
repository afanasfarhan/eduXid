<?php

namespace App\Http\Controllers;

use App\Models\SyncDevice;
use App\Services\Sync\DeviceClient;
use App\Services\Sync\DeviceIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SyncDeviceController extends Controller
{
    /**
     * ==========================================================
     * C6-COMPANION: OWNERSHIP + ROLE GUARD
     * ==========================================================
     * Bukan cuma memastikan device_id cocok dengan pola
     * NPSN-NIP-PC - juga memastikan USER yang login MEMILIKI role
     * yang sesuai. Tanpa ini, guru bisa mendaftar device siswa,
     * siswa bisa mendaftar device guru, dan admin bisa mencuri slot
     * enrollment guru.
     * ==========================================================
     */
    private function assertDeviceOwnership(Request $request, object $user, string $schoolId, string $deviceType, string $deviceId): void
    {
        $loginId = trim((string) $user->login_id);
        $role = strtolower((string) $user->role);

        if ($deviceType === 'student') {
            /*
             * [HARDEN-1-COMPANION] Device siswa TIDAK lagi didaftarkan
             * lewat endpoint ini. Worker menolak student register
             * (STUDENT_DEVICE_LOGIN_ONLY) dan pendaftaran device siswa
             * terjadi OTOMATIS saat login PWA siswa (/api/pwa/auth/login,
             * terautentikasi password siswa). Endpoint ini hanya untuk
             * device PC guru/tendik.
             */
            Log::warning('Registrasi device murid ditolak: gunakan login PWA murid.', [
                'user_id' => $user->id,
                'login_id_masked' => \App\Support\SensitiveDataProtector::maskIdentifier($loginId),
                'device_id_masked' => \App\Support\SensitiveDataProtector::maskDeviceId($deviceId),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Device murid didaftarkan otomatis saat login PWA murid. Buka aplikasi murid dan login dengan NISN + password.');
        }

        if ($deviceType === 'teacher') {
            /*
             * Guru, wali_kelas, guru_bk, tendik, dan kepala_sekolah
             * semuanya menggunakan laptop dengan device guru -
             * karena fitur (surat, pengaturan, absensi) juga diakses
             * tendik dari laptop yang sama.
             */
            if (!in_array($role, ['guru', 'wali_kelas', 'guru_bk', 'tendik', 'kepala_sekolah'], true)) {
                Log::warning('Registrasi device guru ditolak: role akun tidak berhak.', [
                    'user_id' => $user->id,
                    'login_id_masked' => \App\Support\SensitiveDataProtector::maskIdentifier($loginId),
                    'role' => $role,
                    'device_id_masked' => \App\Support\SensitiveDataProtector::maskDeviceId($deviceId),
                    'ip' => $request->ip(),
                ]);
                abort(403, 'Hanya akun GTK yang dapat mendaftarkan device PC.');
            }

            $expectedDeviceId = app(\App\Services\Sync\SchoolIdentity::class)->teacherPcDeviceId($loginId);

            if ($deviceId !== $expectedDeviceId) {
                Log::warning('Registrasi device guru ditolak: device bukan milik akun.', [
                    'user_id' => $user->id,
                    'login_id_masked' => \App\Support\SensitiveDataProtector::maskIdentifier($loginId),
                    'device_id_masked' => \App\Support\SensitiveDataProtector::maskDeviceId($deviceId),
                    'ip' => $request->ip(),
                ]);
                abort(403, 'Device ID tidak sesuai dengan akun guru yang login.');
            }

            return;
        }

        abort(422, 'Device type tidak dikenal: ' . $deviceType);
    }

    public function register(
        Request $request,
        DeviceIdentityService $identity,
        DeviceClient $client
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'school_id' => 'required|string|max:100',
                'device_type' => 'required|string|in:teacher',
                'device_id' => 'required|string|max:255',
                'enrollment_token' => 'required|string|max:500',
                'user_id' => 'nullable|integer',
                'login_id' => 'nullable|string|max:255',
                'device_name' => 'nullable|string|max:255',
            ]);

            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sesi berakhir. Silakan login kembali.',
                ], 401);
            }

            $schoolId = trim((string) $validated['school_id']);
            $deviceType = trim((string) $validated['device_type']);
            $deviceId = trim((string) $validated['device_id']);
            $enrollmentToken = trim((string) ($validated['enrollment_token'] ?? ''));
            $deviceName = isset($validated['device_name']) ? trim((string) $validated['device_name']) : null;

            $userId = (int) $user->id;
            $loginId = trim((string) $user->login_id);

            if ($loginId === '') {
                throw new RuntimeException('Login ID akun kosong. Device tidak dapat didaftarkan.');
            }

            if ($schoolId === '') {
                throw new RuntimeException('NPSN sekolah wajib diisi.');
            }

            /*
             * [ROLE-GUARD] Cek kepemilikan device + role yang sesuai
             * SEBELUM memanggil identity service atau Cloud.
             */
            $this->assertDeviceOwnership($request, $user, $schoolId, $deviceType, $deviceId);

            /*
             * [TEACHER-GTK] Pastikan data GTK guru tersedia sebelum
             * mendaftar ke Cloud - tanpa ini, Cloud menolak dengan
             * pesan yang lebih samar.
             */
            $teacher = $user->teacher();

            if (!$teacher) {
                throw new RuntimeException(
                    'Profil guru Anda belum terdata (NIP/NIK/NUPTK tidak cocok dengan akun). '
                    . 'Sinkronkan Data Pokok terlebih dahulu.'
                );
            }

            $identityResult = $identity->register(
                schoolId: $schoolId,
                deviceType: $deviceType,
                requestedDeviceId: $deviceId,
                userId: $userId,
                deviceName: $deviceName
            );

            $syncDevice = null;

            if ($identityResult instanceof SyncDevice) {
                $syncDevice = $identityResult;
            } elseif (is_array($identityResult) && ($identityResult['device'] ?? null) instanceof SyncDevice) {
                $syncDevice = $identityResult['device'];
            }

            if (is_array($identityResult) && !empty($identityResult['device_id'])) {
                $deviceId = trim((string) $identityResult['device_id']);
            }

            if ($syncDevice instanceof SyncDevice) {
                $deviceId = trim((string) $syncDevice->device_id);
                if ($syncDevice->school_id !== null && trim((string) $syncDevice->school_id) !== '') {
                    $schoolId = trim((string) $syncDevice->school_id);
                }
            }

            $cloudResponse = $client->register(
                deviceId: $deviceId,
                deviceType: $deviceType,
                schoolId: $schoolId,
                userId: (string) $userId,
                enrollmentToken: $enrollmentToken,
                homeroomId: null,
                loginId: \App\Services\Sync\OpaqueRouteRegistryV21::enabled()
                    ? app(\App\Services\Sync\OpaqueRouteRegistryV21::class)->alias($schoolId, 'teacher', $loginId)
                    : $loginId,
                deviceName: $deviceName
            );

            $deviceToken = isset($cloudResponse['data']['device_token'])
                ? trim((string) $cloudResponse['data']['device_token'])
                : '';

            $existingTokenRow = SyncDevice::query()
                ->where('school_id', $schoolId)
                ->where('device_id', $deviceId)
                ->first();

            $existingToken = $existingTokenRow ? trim((string) $existingTokenRow->sync_token) : '';

            if ($deviceToken === '' && $existingToken === '') {
                throw new RuntimeException('EDUXID CLOUD berhasil merespons, tetapi device_token tidak ditemukan dan tidak ada token lokal yang tersimpan.');
            }

            if ($deviceToken === '') {
                $deviceToken = $existingToken;
            }

            $attributes = [
                'device_type' => $deviceType,
                'sync_token' => $deviceToken,
                'user_id' => $userId,
                'is_active' => true,
                'last_synced_at' => now(),
            ];

            if ($deviceName !== null) {
                $attributes['device_name'] = $deviceName;
            }

            $syncDevice = SyncDevice::query()->updateOrCreate(
                ['school_id' => $schoolId, 'device_id' => $deviceId],
                $attributes
            );

            Log::info('Device terdaftar ke EDUXID CLOUD.', [
                'user_id' => $userId,
                'login_id_masked' => \App\Support\SensitiveDataProtector::maskIdentifier($loginId),
                'device_id_masked' => \App\Support\SensitiveDataProtector::maskDeviceId($deviceId),
                'device_type' => $deviceType,
                'school_id' => $schoolId,
                'token_rotated' => (bool) ($cloudResponse['data']['token_rotated'] ?? false),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Device berhasil terdaftar ke EDUXID CLOUD.',
                'data' => [
                    'school_id' => $syncDevice->school_id,
                    'device_id' => $syncDevice->device_id,
                    'device_type' => $syncDevice->device_type,
                    'user_id' => $syncDevice->user_id,
                    'device_name' => $syncDevice->device_name,
                    'is_active' => (bool) $syncDevice->is_active,
                    'registered' => true,
                ],
            ]);
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            /*
             * [ABORT-FIX] Exception 403/422 dari guard di atas harus
             * dipertahankan sebagai HTTP status yang benar - dulu
             * ditelan catch-all dan dikembalikan sebagai 422 dengan
             * pesan generik yang membingungkan.
             */
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }

            // Hindari report exception mentah (query/credential dapat ikut tertulis di log).

            Log::error('Registrasi device ke EDUXID CLOUD gagal.', [
                'user_id' => auth()->id(),
                'login_id_masked' => \App\Support\SensitiveDataProtector::maskIdentifier(auth()->user()?->login_id),
                'device_id_masked' => \App\Support\SensitiveDataProtector::maskDeviceId($deviceId ?? null),
                'device_type' => $deviceType ?? null,
                'error_code' => 'CLOUD_DEVICE_REGISTRATION_FAILED',
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registrasi device ke EDUXID CLOUD gagal.',
                'error' => 'Periksa status pairing dan koneksi Cloud; detail teknis tersedia pada log terlindungi.',
            ], 422);
        }
    }
}