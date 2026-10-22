<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

class ReleaseDiscovery
{
    private bool $cloudCheckSucceeded = false;

    public function didSuccessfullyCheckCloud(): bool
    {
        return $this->cloudCheckSucceeded;
    }

    public function __construct(
        protected CurrentVersion $currentVersion,
        protected ReleaseVerifier $verifier,
    ) {
    }

    /**
     * Prioritas resmi: ROOT -> LAN Admin -> Cloud.
     * Jika release baru berhasil diperoleh, hasil akhir selalu berada di root.
     *
     * @return array{available:bool,source:?string,version:?string,file:?string}
     */
    public function ensureLatest(): array
    {
        $edition = strtolower((string) config('eduxid.edition', 'school'));
        $current = $this->currentVersion->get();

        // V21R159: existing GTK receives releases ONLY via HTTPS Cloud.
        // A previously downloaded, signed Cloud ZIP may be retried offline.
        // Never discover arbitrary USB/root ZIPs or LAN Admin releases for teacher.
        if ($edition === 'teacher') {
            if ($cached = $this->findPreviouslyDownloadedCloudRelease($edition, $current)) {
                return $cached;
            }
            $cloudUrl = trim((string) (config('eduxid.update_server_url') ?: config('eduxid.sync_server', '')));
            if (str_starts_with(strtolower($cloudUrl), 'https://')) {
                if ($remote = $this->fetchRemoteRelease(rtrim($cloudUrl, '/'), 'cloud', $edition, $current)) {
                    return $remote;
                }
            }
            return ['available' => false, 'source' => null, 'version' => null, 'file' => null];
        }

        // Admin/School retains its existing local-root and HTTPS Cloud behavior.
        if ($local = $this->findLocalRootRelease($edition, $current)) {
            return $local;
        }
        $cloudUrl = trim((string) (config('eduxid.update_server_url') ?: config('eduxid.sync_server', '')));
        if ($cloudUrl !== '' && ($remote = $this->fetchRemoteRelease(rtrim($cloudUrl, '/'), 'cloud', $edition, $current))) {
            return $remote;
        }

        return ['available' => false, 'source' => null, 'version' => null, 'file' => null];
    }

    /** Hanya ZIP lokal untuk retry setelah kondisi aman (tanpa request Cloud/LAN). */
    public function pendingLocal(): ?array
    {
        $edition = strtolower((string) config('eduxid.edition', 'school'));
        if ($edition === 'teacher') {
            return $this->findPreviouslyDownloadedCloudRelease($edition, $this->currentVersion->get());
        }
        return $this->findLocalRootRelease($edition, $this->currentVersion->get());
    }

    /** Retry ONLY a prior HTTPS Cloud download, bound to local SHA and signed ZIP. */
    private function findPreviouslyDownloadedCloudRelease(string $edition, string $current): ?array
    {
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $markerPath = $root . DIRECTORY_SEPARATOR . '.eduxid-updater' . DIRECTORY_SEPARATOR . 'cloud-download.json';
        if (!is_file($markerPath) || is_link($markerPath)) return null;
        try {
            $marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($marker)) return null;
            $version = (string) ($marker['version'] ?? '');
            $basename = (string) ($marker['basename'] ?? '');
            $expectedHash = strtolower((string) ($marker['sha256'] ?? ''));
            if (!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/D', $version)
                || $basename !== 'eduxid-patch-' . $version . '.zip'
                || !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)
                || version_compare($version, $current, '<=')) return null;
            $file = $root . DIRECTORY_SEPARATOR . $basename;
            if (!is_file($file) || is_link($file)
                || !hash_equals($expectedHash, strtolower((string) hash_file('sha256', $file)))) return null;
            $manifest = $this->verifier->verify($file, $edition);
            if ((string) $manifest['version'] !== $version
                || version_compare($current, (string) $manifest['min_supported_version'], '<')) return null;
            return ['available' => true, 'source' => 'cloud-cache', 'version' => $version, 'file' => $file];
        } catch (Throwable $e) {
            Log::warning('Cache ZIP Cloud GTK ditolak; pemeriksaan Cloud berikutnya tetap tersedia.', [
                'reason' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function findLocalRootRelease(string $edition, string $current): ?array
    {
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $files = glob($root . DIRECTORY_SEPARATOR . 'eduxid-patch-*.zip') ?: [];
        $candidates = [];

        foreach ($files as $file) {
            try {
                $manifest = $this->verifier->verify($file, $edition);
                $version = (string) $manifest['version'];

                if (version_compare($version, $current, '>')
                    && version_compare($current, (string) $manifest['min_supported_version'], '>=')) {
                    $candidates[] = ['version' => $version, 'file' => $file];
                }
            } catch (Throwable $e) {
                Log::warning('Patch root diabaikan karena tidak valid.', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => version_compare($b['version'], $a['version']));
        $best = $candidates[0];

        return [
            'available' => true,
            'source' => 'root',
            'version' => $best['version'],
            'file' => $best['file'],
        ];
    }

    private function fetchRemoteRelease(string $baseUrl, string $source, string $edition, string $current): ?array
    {
        try {
            $response = Http::timeout((int) config('eduxid.update_check_timeout', 10))
                ->acceptJson()
                ->withHeaders($this->headers($source))
                ->get($baseUrl . '/api/update/latest', [
                    'edition' => $edition,
                    'current_version' => $current,
                    'channel' => (string) config('eduxid.update_channel', 'stable'),
                ]);

            if (!$response->successful()) {
                return null;
            }

            $release = $response->json('release');
            if (!is_array($release)) {
                if ($source === 'cloud' && $response->json('success') === true) {
                    $this->cloudCheckSucceeded = true; // No update is also a valid check.
                }
                return null;
            }

            $version = trim((string) ($release['version'] ?? ''));
            if (!preg_match('/^\\d+\\.\\d+\\.\\d+(?:-[a-zA-Z0-9._-]+)?$/', $version) || version_compare($version, $current, '<=')) {
                if ($source === 'cloud') $this->cloudCheckSucceeded = true;
                return null;
            }

            $downloadUrl = trim((string) ($release['download_url'] ?? ''));
            if ($downloadUrl === '') {
                $downloadUrl = $baseUrl . '/api/update/releases/' . rawurlencode($version) . '/download';
            } elseif (!preg_match('#^https?://#i', $downloadUrl)) {
                $downloadUrl = $baseUrl . '/' . ltrim($downloadUrl, '/');
            }

            // Never forward school/device Authorization to a foreign update URL.
            // Cloud metadata may only refer to the same HTTPS origin.
            $baseParts = parse_url($baseUrl);
            $downloadParts = parse_url($downloadUrl);
            if (!is_array($baseParts) || !is_array($downloadParts)
                || strtolower((string) ($downloadParts['scheme'] ?? '')) !== ($source === 'lan' ? strtolower((string) ($baseParts['scheme'] ?? '')) : 'https')
                || strcasecmp((string) ($baseParts['host'] ?? ''), (string) ($downloadParts['host'] ?? '')) !== 0
                || (int) ($baseParts['port'] ?? (strtolower((string) ($baseParts['scheme'] ?? '')) === 'https' ? 443 : 80)) !== (int) ($downloadParts['port'] ?? (strtolower((string) ($downloadParts['scheme'] ?? '')) === 'https' ? 443 : 80))) {
                throw new RuntimeException('URL patch harus HTTPS dan satu host dengan Cloud asal.');
            }

            $root = rtrim((string) config('eduxid.install_root'), '\\/');
            $final = $root . DIRECTORY_SEPARATOR . 'eduxid-patch-' . $version . '.zip';
            $part = $final . '.part';

            @unlink($part);

            $download = Http::timeout((int) config('eduxid.update_download_timeout', 300))
                ->withHeaders($this->headers($source))
                ->withOptions(['sink' => $part, 'allow_redirects' => false])
                ->get($downloadUrl);

            if (!$download->successful() || !is_file($part) || filesize($part) <= 0) {
                @unlink($part);
                throw new RuntimeException('Download patch gagal. HTTP ' . $download->status());
            }

            // Bind ZIP bytes to the authenticated Cloud release metadata as well
            // as the cryptographically signed internal manifest.
            $expectedHash = strtolower(trim((string) ($release['sha256'] ?? '')));
            if ($expectedHash !== '' && !hash_equals($expectedHash, strtolower((string) hash_file('sha256', $part)))) {
                throw new RuntimeException('SHA-256 arsip unduhan berbeda dari metadata Cloud.');
            }
            $expectedSize = (int) ($release['size_bytes'] ?? 0);
            if ($expectedSize > 0 && filesize($part) !== $expectedSize) {
                throw new RuntimeException('Ukuran ZIP tidak cocok dengan metadata Cloud.');
            }
            $manifest = $this->verifier->verify($part, $edition);
            if (version_compare($current, (string) $manifest['min_supported_version'], '<')) {
                throw new RuntimeException('Patch Cloud memerlukan versi perantara.');
            }
            if ((string) $manifest['version'] !== $version) {
                throw new RuntimeException('Versi manifest hasil download tidak cocok.');
            }

            @unlink($final);
            if (!rename($part, $final)) {
                throw new RuntimeException('Gagal memindahkan patch ke root eduXidMaster.');
            }

            if ($source === 'cloud') {
                $markerDir = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . '.eduxid-updater';
                if (!is_dir($markerDir)) @mkdir($markerDir, 0770, true);
                @file_put_contents($markerDir . DIRECTORY_SEPARATOR . 'cloud-download.json', json_encode([
                    'basename' => basename($final), 'version' => $version, 'release_id' => (string) ($release['release_id'] ?? ''),
                    'sha256' => hash_file('sha256', $final),
                ]));
            }
            if ($source === 'cloud') $this->cloudCheckSucceeded = true;
            return [
                'available' => true,
                'source' => $source,
                'version' => $version,
                'file' => $final,
            ];
        } catch (Throwable $e) {
            if (isset($part) && is_string($part) && is_file($part)) @unlink($part);
            Log::warning('Pemeriksaan update ' . $source . ' gagal.', [
                'url' => $baseUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function adminUrl(): ?string
    {
        try {
            $serverIp = DB::table('app_settings')->where('key', 'server_ip')->value('value');
            if (is_string($serverIp) && trim($serverIp) !== '') {
                $serverIp = trim($serverIp);
                return preg_match('#^https?://#i', $serverIp)
                    ? rtrim($serverIp, '/')
                    : 'http://' . $serverIp . ':8000';
            }
        } catch (Throwable) {
        }

        $url = trim((string) config('eduxid.admin_url', ''));
        return $url !== '' ? rtrim($url, '/') : null;
    }

    private function headers(string $source): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'eduXid-Updater-V2',
        ];

        if ($source === 'lan') {
            $token = trim((string) config('eduxid.sync_token', ''));
            if ($token !== '') {
                $headers['X-Sync-Token'] = $token;
            }
        }

        if ($source === 'cloud') {
            $edition = strtolower((string)config('eduxid.edition', 'school'));
            if ($edition === 'school') {
                $adminToken = \App\Services\Sync\EduxidCloudConfig::adminToken();
                if ($adminToken !== '') $headers['Authorization'] = 'Admin ' . $adminToken;
            } elseif ($edition === 'teacher') {
                try {
                    // Device routing in V21 is opaque: never look up an update
                    // token through a stale pre-V21 NIP/NIK device_id from .env.
                    // The canonical route resolver validates ownership and alias.
                    $deviceId = app(\App\Services\Sync\LmsSyncService::class)->getDeviceId();
                    $token = (string)\Illuminate\Support\Facades\DB::table('sync_devices')
                        ->where('device_id', $deviceId)
                        ->where('device_type', 'teacher')
                        ->where('is_active', true)
                        ->value('sync_token');
                    if ($token !== '') $headers['Authorization'] = 'Bearer ' . $token;
                } catch (\Throwable) {}
            }
        }
        return $headers;
    }
}
