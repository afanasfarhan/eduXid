<?php

namespace App\Services\Sync;

use App\Models\SyncDevice;
use App\Models\User;
use RuntimeException;

class DeviceIdentityService
{
    public function deviceId(): string
    {
        return $this->current()->device_id;
    }

    public function schoolId(): string
    {
        return $this->current()->school_id;
    }

    public function current(
        ?string $schoolId = null,
        ?string $deviceId = null
    ): SyncDevice {
        $query = SyncDevice::query()
            ->where('is_active', true);

        $hasFilter = false;

        if ($schoolId !== null && trim($schoolId) !== '') {
            $query->where('school_id', trim($schoolId));
            $hasFilter = true;
        }

        if ($deviceId !== null && trim($deviceId) !== '') {
            $query->where('device_id', trim($deviceId));
            $hasFilter = true;
        }

        if (!$hasFilter) {
            $query->where('device_type', 'teacher')->orderByDesc('last_synced_at');
        }

        $device = $query->first();

        if (!$device) {
            throw new RuntimeException('Device eduXid belum terdaftar.');
        }

        return $device;
    }

    public function register(
        string $schoolId,
        string $deviceType,
        ?int $userId = null,
        ?string $deviceName = null,
        ?string $requestedDeviceId = null
    ): SyncDevice {
        $schoolId = trim($schoolId);
        $deviceType = trim($deviceType);
        $requestedDeviceId = trim((string) ($requestedDeviceId ?? ''));

        if ($schoolId === '') {
            throw new RuntimeException('school_id wajib diisi.');
        }

        if (!in_array($deviceType, ['teacher', 'student'], true)) {
            throw new RuntimeException('device_type harus teacher atau student.');
        }

        $deviceId = $deviceType === 'student'
            ? $this->resolveStudentDeviceId($schoolId, $requestedDeviceId, $userId)
            : $this->resolveTeacherDeviceId($schoolId, $requestedDeviceId, $userId);

        $device = SyncDevice::query()
            ->where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->first();

        if ($device) {
            $this->applyDeviceUpdates($device, $deviceType, $userId, $deviceName);
            return $device->fresh();
        }

        try {
            return SyncDevice::create([
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => $deviceType,
                'user_id' => $userId,
                'device_name' => $deviceName ?: $deviceId,
                'sync_token' => null,
                'is_active' => true,
                'last_synced_at' => null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $device = SyncDevice::query()
                ->where('school_id', $schoolId)
                ->where('device_id', $deviceId)
                ->first();

            if (!$device) {
                throw $e;
            }

            $this->applyDeviceUpdates($device, $deviceType, $userId, $deviceName);
            return $device->fresh();
        }
    }

    protected function resolveTeacherDeviceId(string $schoolId, string $requestedDeviceId, ?int $userId): string
    {
        if ($userId === null) {
            throw new RuntimeException('User ID guru wajib tersedia untuk membuat Device ID.');
        }

        $user = User::query()->find($userId);

        if (!$user) {
            throw new RuntimeException('User guru tidak ditemukan.');
        }

        $loginId = trim((string) $user->login_id);

        if ($loginId === '') {
            throw new RuntimeException('login_id guru wajib tersedia untuk membuat Device ID.');
        }

        $canonicalDeviceId = OpaqueRouteRegistryV21::enabled()
            ? app(OpaqueRouteRegistryV21::class)->deviceId($schoolId, 'teacher', $loginId, 'PC')
            : $schoolId . '-' . $loginId . '-PC';

        if ($requestedDeviceId !== '' && $requestedDeviceId !== $canonicalDeviceId) {
            throw new RuntimeException('Device ID guru tidak sesuai dengan identitas canonical (NPSN-login_id-PC).');
        }

        return $canonicalDeviceId;
    }

    protected function resolveStudentDeviceId(string $schoolId, string $requestedDeviceId, ?int $userId): string
    {
        if ($requestedDeviceId === '') {
            throw new RuntimeException('Device ID siswa wajib dikirim.');
        }

        if ($userId !== null) {
            $user = User::query()->find($userId);

            if (!$user) {
                throw new RuntimeException('User siswa tidak ditemukan.');
            }

            $loginId = trim((string) $user->login_id);

            if ($loginId === '') {
                throw new RuntimeException('login_id siswa wajib tersedia untuk membuat Device ID.');
            }

            $canonicalDeviceId = OpaqueRouteRegistryV21::enabled()
                ? app(OpaqueRouteRegistryV21::class)->deviceId($schoolId, 'student', $loginId, 'HP')
                : $schoolId . '-' . $loginId . '-HP';

            if ($requestedDeviceId !== $canonicalDeviceId) {
                throw new RuntimeException('Device ID siswa tidak sesuai dengan akun yang login (NPSN-NISN-HP).');
            }

            return $canonicalDeviceId;
        }

        if (OpaqueRouteRegistryV21::enabled()) {
            // A public string alone does not authenticate a student's ownership.
            throw new RuntimeException('BLOCKED V21: siswa tanpa akun lokal belum dapat didaftarkan melalui API ini.');
        }
        $studentPattern = '/^' . preg_quote($schoolId, '/') . '-[A-Za-z0-9._-]+-HP$/';

        if (!preg_match($studentPattern, $requestedDeviceId)) {
            throw new RuntimeException('Device ID siswa tidak sesuai dengan school_id.');
        }

        return $requestedDeviceId;
    }

    protected function applyDeviceUpdates(
        SyncDevice $device,
        string $deviceType,
        ?int $userId,
        ?string $deviceName
    ): void {
        $updates = [];

        if ($device->device_type !== $deviceType) {
            $updates['device_type'] = $deviceType;
        }

        if ($userId !== null && (int) $device->user_id !== $userId) {
            $updates['user_id'] = $userId;
        }

        if ($deviceName !== null && $deviceName !== '' && $device->device_name !== $deviceName) {
            $updates['device_name'] = $deviceName;
        }

        if (!$device->is_active) {
            $updates['is_active'] = true;
        }

        if (!empty($updates)) {
            foreach ($updates as $key => $value) {
                $device->setAttribute($key, $value);
            }
            $device->saveQuietly();
        }
    }
}