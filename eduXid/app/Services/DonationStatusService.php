<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SyncDevice;
use App\Models\User;
use App\Services\Sync\EduxidCloudConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DonationStatusService
{
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED = 'revoked';

    public function status(User $user): array
    {
        $response = $this->request($user)
            ->get($this->url('/api/donation/status'));

        if ($response->failed()) {
            throw new RuntimeException('Status donasi belum dapat diverifikasi dari eduXid Cloud.');
        }

        $data = $response->json('data');

        if (!is_array($data)) {
            throw new RuntimeException('Respons status donasi dari Cloud tidak valid.');
        }

        return $data + [
            'status' => self::STATUS_UNVERIFIED,
            'verified' => false,
        ];
    }

    public function claim(User $user, array $payload): array
    {
        $body = [
            'donor_name' => trim((string) ($payload['donor_name'] ?? $user->name ?? '')),
            'whatsapp' => trim((string) ($payload['whatsapp'] ?? '')),
            'channel' => strtolower(trim((string) ($payload['channel'] ?? ''))),
            'amount' => isset($payload['amount']) && $payload['amount'] !== ''
                ? (int) $payload['amount']
                : null,
            'transaction_ref' => trim((string) ($payload['transaction_ref'] ?? '')),
            'donated_at' => trim((string) ($payload['donated_at'] ?? '')) ?: null,
        ];

        $response = $this->request($user)
            ->post($this->url('/api/donation/claim'), $body);

        if ($response->failed()) {
            $message = trim((string) ($response->json('error.message') ?? $response->json('message') ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Klaim donasi gagal dikirim ke eduXid Cloud.');
        }

        $data = $response->json('data');

        if (!is_array($data)) {
            throw new RuntimeException('Respons klaim donasi dari Cloud tidak valid.');
        }

        return $data;
    }

    public function redeem(User $user, string $code): array
    {
        $response = $this->request($user)
            ->post($this->url('/api/donation/redeem'), [
                'code' => trim($code),
            ]);

        if ($response->failed()) {
            $message = trim((string) ($response->json('error.message') ?? $response->json('message') ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Kode verifikasi donasi tidak dapat diproses.');
        }

        $data = $response->json('data');

        if (!is_array($data)) {
            throw new RuntimeException('Respons verifikasi donasi dari Cloud tidak valid.');
        }

        return $data;
    }

    public function shouldPromptOnCurrentRoute(User $user): bool
    {
        $role = $user->roleName();

        if ($role === 'tendik') {
            return request()->routeIs('tu.ujian.index', 'tu.kartu-pelajar.index');
        }

        if (!$user->canTeach()) {
            return false;
        }

        return request()->routeIs(
            'lms.courses.index',
            'guru.cbt',
            'guru.cbt.koreksi',
            'guru.ljk-auto.index'
        );
    }

    public function isGtk(User $user): bool
    {
        return AcademicPeriodService::isGtkRole($user->roleName());
    }

    private function request(User $user): PendingRequest
    {
        $request = Http::timeout(8)
            ->connectTimeout(4)
            ->acceptJson()
            ->asJson();

        $caCert = trim((string) config('eduxid.ca_cert', ''));
        if ($caCert !== '' && is_file($caCert)) {
            $request = $request->withOptions(['verify' => $caCert]);
        }

        $device = $this->resolveDevice($user);
        if (!$device) {
            throw new \App\Services\Sync\GtkCloudPairingRequired();
        }

        try {
            $token = trim((string) $device->sync_token);
        } catch (\Throwable) {
            $token = '';
        }

        if ($token === '') {
            throw new \App\Services\Sync\GtkCloudPairingRequired();
        }

        return $request->withToken($token);
    }

    private function resolveDevice(User $user): ?SyncDevice
    {
        // V21: device address is NPSN-t_<opaque-alias>-PC, not NPSN-NIP-PC.
        // Resolve ONLY the authenticated GTK's own, paired device token.
        return app(\App\Services\Sync\GtkPairedDeviceV21::class)->resolve($user);
    }

    private function url(string $path): string
    {
        $base = rtrim(EduxidCloudConfig::syncServer(), '/');
        if ($base === '') {
            $base = rtrim((string) config('eduxid.sync_server'), '/');
        }
        if ($base === '') {
            throw new RuntimeException('URL eduXid Cloud belum dikonfigurasi.');
        }

        return $base . '/' . ltrim($path, '/');
    }
}
