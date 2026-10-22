<?php

namespace App\Services\Sync;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeviceClient
{
    protected string $baseUrl;
    protected int $timeout;
    protected ?string $caCert;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('eduxid.sync_server'), '/');
        $this->timeout = max(1, (int) config('eduxid.timeout', 15));

        $caCert = trim((string) config('eduxid.ca_cert', ''));
        $this->caCert = $caCert !== '' && is_file($caCert) ? $caCert : null;

        if ($caCert !== '' && $this->caCert === null) {
            Log::warning('CA certificate eduXid dikonfigurasi tetapi file tidak ditemukan — verifikasi SSL memakai CA sistem (php.ini curl.cainfo).', [
                'ca_cert' => $caCert,
            ]);
        }

        if ($this->baseUrl === '') {
            throw new RuntimeException('EDUXID_SYNC_SERVER belum dikonfigurasi.');
        }
        if (strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Koneksi Cloud wajib HTTPS. HTTP ditolak untuk melindungi identitas perangkat.');
        }
    }

    public function register(
        string $deviceId,
        string $deviceType,
        string $schoolId,
        string $userId,
        string $enrollmentToken,
        ?string $homeroomId = null,
        ?string $loginId = null,
        ?string $deviceName = null
    ): array {
        $deviceId = trim($deviceId);
        $deviceType = trim($deviceType);
        $schoolId = trim($schoolId);
        $userId = trim($userId);
        $enrollmentToken = trim($enrollmentToken);
        $homeroomId = $homeroomId !== null ? trim($homeroomId) : null;
        $loginId = $loginId !== null ? trim($loginId) : null;
        $deviceName = $deviceName !== null ? trim($deviceName) : null;

        if ($deviceId === '') {
            throw new RuntimeException('device_id wajib diisi.');
        }
        if (!in_array($deviceType, ['teacher', 'student'], true)) {
            throw new RuntimeException('device_type harus teacher atau student.');
        }
        if ($schoolId === '') {
            throw new RuntimeException('school_id wajib diisi.');
        }
        if ($deviceType === 'teacher' && $enrollmentToken === '') {
            throw new RuntimeException('enrollment_token wajib diisi untuk device teacher.');
        }

        $response = $this->http()->post(
            $this->url('/api/device/register'),
            array_filter([
                'device_id' => $deviceId,
                'device_type' => $deviceType,
                'school_id' => $schoolId,
                'user_id' => $userId !== '' ? $userId : null,
                'login_id' => $loginId !== null && $loginId !== '' ? $loginId : null,
                'homeroom_id' => $homeroomId !== null && $homeroomId !== '' ? $homeroomId : null,
                'device_name' => $deviceType === 'teacher' ? 'eduXid GTK PC' : 'eduXid Siswa HP',
                'enrollment_token' => $enrollmentToken !== '' ? $enrollmentToken : null,
            ], static fn ($value) => $value !== null)
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Registrasi device gagal. HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        return $this->decodeResponse($response);
    }

    public function authenticatedHttp(string $deviceToken): PendingRequest
    {
        $deviceToken = trim($deviceToken);
        if ($deviceToken === '') {
            throw new RuntimeException('Device token kosong. Device belum memiliki token Cloud.');
        }
        return $this->http()->withToken($deviceToken);
    }

    public function studentDevices(string $schoolId): array
    {
        throw new RuntimeException(
            'Endpoint /api/device/students tidak tersedia pada EDUXID CLOUD. '
            . 'Gunakan roster siswa lokal Laravel untuk menentukan target device.'
        );
    }

    protected function http(): PendingRequest
    {
        $http = Http::timeout($this->timeout)->acceptJson()->asJson();

        if ($this->caCert !== null) {
            $http = $http->withOptions(['verify' => $this->caCert]);
        }

        return $http;
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    protected function decodeResponse(Response $response): array
    {
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('Response Sync Server bukan JSON yang valid.');
        }

        if (($data['success'] ?? false) !== true) {
            $detail = $data['message'] ?? null;

            if (!is_string($detail) || trim($detail) === '') {
                $detail = $data['error'] ?? null;
            }

            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            throw new RuntimeException(
                is_string($detail) && trim($detail) !== ''
                    ? trim($detail)
                    : 'Sync Server mengembalikan success=false.'
            );
        }

        return $data;
    }
}