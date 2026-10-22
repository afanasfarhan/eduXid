<?php

namespace App\Services\Sync;

use App\Models\SyncDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [F1 / CM-02 / D15] CLOUD MASTER DATA SERVICE — rev.3
 *  - 409: baca stored_version dari fields JSON (fallback regex lama).
 *  - Push membawa header X-Pusher-Device (diagnostik cloud; D15).
 */
class CloudMasterDataService
{
    public function __construct(
        protected LmsSyncService $sync,
        protected MasterSnapshotService $masterSnapshot
    ) {
    }

    public function pushSnapshot(bool $force = false): array
    {
        $adminKey = EduxidCloudConfig::adminToken();

        if ($adminKey === '') {
            throw new \RuntimeException(
                'Kunci admin belum dikonfigurasi - buka menu Admin -> eduXid Cloud -> Setup Cloud.'
            );
        }

        $server = rtrim((string) EduxidCloudConfig::syncServer(), '/');

        if ($server === '') {
            throw new \RuntimeException('URL eduXid Cloud belum dikonfigurasi.');
        }

        // V21R40: the Cloud Hub must never receive the raw Dapodik master.
        // Keep the full snapshot solely for local/LAN/m2g paths.
        if (!CloudDirectoryAliasV21::stagingEnabled()) {
            throw new \RuntimeException('BLOCKED V21: Cloud master membutuhkan protokol identitas v21-opaque.');
        }
        $master = CloudMasterOpaqueProjectionV21::project(
            $this->buildMasterSnapshot(),
            $this->sync->getSchoolId(),
            CloudDirectoryAliasV21::fromConfig()
        );
        $version = (int) ($master['metadata']['version'] ?? 0);
        $hash    = (string) ($master['metadata']['content_hash'] ?? '');
        $counts  = $this->countSnapshot($master);

        if ($version <= 0) {
            throw new \RuntimeException(
                'Snapshot tanpa version - MasterSnapshotService belum versi final.'
            );
        }

        if (
            !$force
            && $hash !== ''
            && $hash === MasterRegistry::get(MasterRegistry::KEY_PUSHED_HASH)
        ) {
            return [
                'status'  => 'unchanged',
                'version' => MasterRegistry::pushedVersion(),
                'counts'  => $counts,
            ];
        }

        $response = $this->postMaster($server, $adminKey, $version, $master, $force);

        if ($response->status() === 409 && str_contains($response->body(), 'MASTER_VERSION_CONFLICT')) {
            $stored = $this->extractStoredVersion($response);

            if ($stored !== null && $stored > 0) {
                Log::warning('[CLOUD-MASTER] Konflik versi - pulih otomatis (stored+1).', [
                    'version_lama' => $version,
                    'stored'       => $stored,
                ]);

                $version = $stored + 1;
                $master['metadata']['version'] = $version;
                $response = $this->postMaster($server, $adminKey, $version, $master, false);
            }
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Cloud menolak snapshot master (HTTP ' . $response->status() . '): '
                . mb_substr($response->body(), 0, 500)
            );
        }

        $data = $response->json();

        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            throw new \RuntimeException('Respons Cloud tidak valid: ' . mb_substr($response->body(), 0, 500));
        }

        MasterRegistry::recordPushed(
            (int) ($data['version'] ?? $version),
            $hash
        );

        return [
            'status'  => 'pushed',
            'version' => $data['version'] ?? $version,
            'counts'  => $counts,
        ];
    }

    public function pullSnapshot(): array
    {
        $server = rtrim((string) EduxidCloudConfig::syncServer(), '/');

        if ($server === '') {
            throw new \RuntimeException(
                'URL eduXid Cloud belum dikonfigurasi - hubungi Admin sekolah.'
            );
        }

        $deviceId = $this->sync->getDeviceId();

        $token = SyncDevice::query()
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->value('sync_token');

        if (!$token) {
            throw new \RuntimeException(
                'Laptop ini belum terpasangkan dengan eduXid Cloud. Lakukan pairing terlebih dahulu.'
            );
        }

        $response = Http::timeout(90)
            ->connectTimeout(10)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'X-Device-Id'   => $deviceId,
            ])
            ->withOptions($this->httpOptions())
            ->get($server . '/api/teacher/master-data');

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Gagal menarik master dari Cloud (HTTP ' . $response->status() . '): '
                . mb_substr($response->body(), 0, 500)
            );
        }

        $data = $response->json();

        if (!is_array($data) || ($data['success'] ?? false) !== true || !isset($data['master'])) {
            throw new \RuntimeException(
                'Snapshot master di Cloud belum tersedia atau respons tidak valid.'
            );
        }

        return $data;
    }

    public function buildMasterSnapshot(): array
    {
        return $this->masterSnapshot->build();
    }

    /* ============================================================
     * INTERNAL
     * ============================================================ */

    private function postMaster(string $server, string $adminKey, int $version, array $master, bool $force)
    {
        $body = [
            'school_id' => $this->sync->getSchoolId(),
            'version'   => $version,
            'master'    => $master,
        ];

        if ($force) {
            $body['force'] = true;
        }

        $pusherDevice = null;

        try {
            $pusherDevice = $this->sync->getDeviceId();
        } catch (Throwable) {
            $pusherDevice = null;
        }

        $request = Http::timeout(90)
            ->connectTimeout(10)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => 'Admin ' . $adminKey]);

        if ($pusherDevice !== null && $pusherDevice !== '') {
            $request = $request->withHeaders(['X-Pusher-Device' => $pusherDevice]);
        }

        return $request
            ->withOptions($this->httpOptions())
            ->post($server . '/api/school/master-data/sync', $body);
    }

    /**
     * [CM-02] stored_version dari fields JSON Worker; fallback regex
     * pesan (kompatibilitas Worker lama).
     */
    private function extractStoredVersion($response): ?int
    {
        $json = $response->json();

        $candidate =
            $json['error']['fields']['stored_version']
            ?? null;

        if (is_numeric($candidate)) {
            return (int) $candidate;
        }

        $body = (string) $response->body();

        if (preg_match('/"stored_version"\s*:\s*(\d+)/', $body, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/Harus > (\d+)/', $body, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/snapshot tersimpan \((\d+)\)/', $body, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function countSnapshot(array $master): array
    {
        $counts = [];

        foreach (MasterSnapshotService::TABLE_MAP as $key => $table) {
            $counts[$key] = is_array($master[$key] ?? null) ? count($master[$key]) : 0;
        }

        return $counts;
    }

    protected function httpOptions(): array
    {
        $caCert = trim((string) config('eduxid.ca_cert', ''));

        return $caCert !== '' && is_file($caCert)
            ? ['verify' => $caCert]
            : [];
    }
}