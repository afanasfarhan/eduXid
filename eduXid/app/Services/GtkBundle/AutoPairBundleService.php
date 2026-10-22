<?php

declare(strict_types=1);

namespace App\Services\GtkBundle;

use App\Services\Sync\EduxidCloudConfig;
use App\Services\Sync\OpaqueRouteRegistryV21;
use App\Services\Sync\CloudDirectoryAliasV21;
use App\Services\Sync\OpaqueIdentityV21;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use ZipArchive;

final class AutoPairBundleService
{
    /* [GTK-BUNDLE-AUTOPAIR-V1R3] Setup Cloud credential resolver + Final-ZIP guard. */
    private static bool $terminatingGuardScheduled = false;
    private static ?int $terminatingGuardSince = null;

    public const CONTRACT = 'gtk-autopair-v1';
    public const TICKET_SCHEMA = 'EDUXID-GTK-AUTOPAIR-V1';

    public function finalizeZip(string $zipPath): void
    {
        $this->scheduleFinalZipGuard();
        $this->finalizeZipInternal($zipPath);
    }

    /**
     * Finalisasi ZIP terbaru yang benar-benar tersimpan pada storage/app/gtk-bundles.
     * Dipakai oleh command recovery dan guard terminasi request.
     */
    public function finalizeLatestStoredBundle(bool $recentOnly = false): ?string
    {
        $dir = storage_path('app/gtk-bundles');
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, static function (string $a, string $b): int {
            $ma = @filemtime($a) ?: 0;
            $mb = @filemtime($b) ?: 0;
            if ($ma === $mb) {
                return strcmp($b, $a);
            }
            return $mb <=> $ma;
        });

        $since = self::$terminatingGuardSince ?? 0;

        foreach ($files as $file) {
            if ($recentOnly && $since > 0) {
                $mtime = @filemtime($file) ?: 0;
                if ($mtime < ($since - 3)) {
                    continue;
                }
            }

            if ($this->zipHasTicket($file)) {
                return $file;
            }

            $this->finalizeZipInternal($file);
            return $file;
        }

        return null;
    }

    private function scheduleFinalZipGuard(): void
    {
        if (self::$terminatingGuardScheduled) {
            return;
        }

        self::$terminatingGuardScheduled = true;
        self::$terminatingGuardSince = time();

        try {
            app()->terminating(function (): void {
                try {
                    $this->finalizeLatestStoredBundle(true);
                } catch (\Throwable $e) {
                    Log::error('[GTK AUTO-PAIR V1R3] Final-ZIP guard gagal.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('[GTK AUTO-PAIR V1R3] Tidak dapat memasang terminating guard.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function zipHasTicket(string $zipPath): bool
    {
        if (!is_file($zipPath)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        try {
            $entry = $this->findEntry(
                $zip,
                static fn (string $name): bool =>
                    str_ends_with(strtolower($name), '/storage/app/gtk-autopair.json')
                    || strtolower($name) === 'storage/app/gtk-autopair.json'
            );
            if ($entry === null) return false;
            // A finalized ZIP may be inspected repeatedly by the termination
            // guard. Recognize its valid ticket, but finalizeZipInternal still
            // refuses to reissue/reuse one when building a NEW V21 ZIP.
            if (!OpaqueRouteRegistryV21::enabled()) return true;
            $json=$zip->getFromName($entry);
            $ticket=is_string($json) ? json_decode($json,true) : null;
            return is_array($ticket)
                && ($ticket['schema'] ?? '')==='EDUXID-GTK-AUTOPAIR-V21-OPAQUE'
                && is_string($ticket['enrollment_token'] ?? null)
                && str_starts_with($ticket['enrollment_token'],'BND1:');
        } finally {
            $zip->close();
        }
    }

    private function finalizeZipInternal(string $zipPath): void
    {
        $zipPath = trim($zipPath);
        if ($zipPath === '' || !is_file($zipPath)) {
            throw new RuntimeException('ZIP Paket GTK tidak ditemukan untuk finalisasi auto-pair: ' . $zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP Paket GTK tidak dapat dibuka: ' . $zipPath);
        }

        try {
            $ticketEntry = $this->findEntry($zip, static fn (string $name): bool => str_ends_with(strtolower($name), '/storage/app/gtk-autopair.json') || strtolower($name) === 'storage/app/gtk-autopair.json');
            if ($ticketEntry !== null) {
                if (OpaqueRouteRegistryV21::enabled()) {
                    throw new RuntimeException('BLOCKED V21: ZIP sudah memiliki ticket; buat Paket GTK baru agar BND1 terikat satu kali.');
                }
                return;
            }

            $dbEntry = $this->findEntry($zip, static fn (string $name): bool => str_ends_with(strtolower($name), '/database/database.sqlite') || strtolower($name) === 'database/database.sqlite');
            if ($dbEntry === null) {
                throw new RuntimeException('database/database.sqlite tidak ditemukan di dalam Paket GTK.');
            }

            $dbBytes = $zip->getFromName($dbEntry);
            if (!is_string($dbBytes) || $dbBytes === '') {
                throw new RuntimeException('Database Paket GTK tidak dapat dibaca dari ZIP.');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'eduxid-gtk-db-');
            if ($tmp === false || file_put_contents($tmp, $dbBytes, LOCK_EX) === false) {
                throw new RuntimeException('Gagal membuat database sementara Paket GTK.');
            }

            try {
                $pdo = new PDO('sqlite:' . $tmp);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA busy_timeout=5000');

                [$schoolId, $loginId, $userId, $userName] = $this->resolveOwner($pdo);
                $opaque = OpaqueRouteRegistryV21::enabled();
                // The ZIP still holds the original Dapodik local record; the Cloud
                // receives only the alias. No school HMAC secret is copied to GTK.
                $cloudLogin = $opaque
                    ? CloudDirectoryAliasV21::fromConfig()->provisionLocalTeacherAlias($schoolId, $loginId)
                    : $loginId;
                $deviceId = $schoolId . '-' . $cloudLogin . '-PC';

                // Read the *bundle* APP_KEY before building keyed local-digest
                // routes. The Admin APP_KEY need not equal the GTK APP_KEY.
                $envEntry = $this->findEntry($zip, static fn (string $name): bool => strtolower($name) === '.env' || str_ends_with(strtolower($name), '/.env'));
                if ($envEntry === null) {
                    throw new RuntimeException('.env tidak ditemukan di dalam Paket GTK.');
                }
                $env = $zip->getFromName($envEntry);
                if (!is_string($env)) {
                    throw new RuntimeException('.env Paket GTK tidak dapat dibaca.');
                }
                $gtkKey = $opaque ? GtkBundleAliasRoutesV21::appKeyFromEnv($env) : null;

                $this->sanitizeBundleDatabase($pdo);
                if ($opaque) {
                    // Only students enrolled in the owner's authorized classes.
                    // No raw NISN, aliases HMAC secret or Cloud token in the registry.
                    GtkBundleAliasRoutesV21::seed(
                        $pdo, $schoolId, $userId, $gtkKey,
                        CloudDirectoryAliasV21::fromConfig()
                    );
                }
                $pdo = null;

                $ticket = $this->issueBoundTicket($schoolId, $cloudLogin);

                $newDb = file_get_contents($tmp);
                if (!is_string($newDb) || $newDb === '') {
                    throw new RuntimeException('Database Paket GTK hasil sanitasi tidak dapat dibaca.');
                }

                $env = $this->sanitizeEnv($env, $schoolId, $deviceId, $opaque);

                $projectPrefix = preg_replace('#/?database/database\.sqlite$#i', '', str_replace('\\', '/', $dbEntry));
                $projectPrefix = trim((string) $projectPrefix, '/');
                $ticketPath = ($projectPrefix !== '' ? $projectPrefix . '/' : '') . 'storage/app/gtk-autopair.json';

                $ticketPayload = [
                    'schema' => $opaque ? 'EDUXID-GTK-AUTOPAIR-V21-OPAQUE' : self::TICKET_SCHEMA,
                    'cloud_alias' => $opaque ? $cloudLogin : null,
                    'contract' => self::CONTRACT,
                    'school_id' => $schoolId,
                    'login_id' => $loginId,
                    'user_id' => $userId,
                    'user_name' => $opaque ? null : $userName,
                    'device_id' => $deviceId,
                    'device_type' => 'teacher',
                    'enrollment_token' => (string) $ticket['enrollment_token'],
                    'expires_at' => $ticket['expires_at'] ?? null,
                    'created_at' => now()->toIso8601String(),
                ];

                $json = json_encode($ticketPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                $zip->deleteName($dbEntry);
                if (!$zip->addFromString($dbEntry, $newDb)) {
                    throw new RuntimeException('Gagal menulis database bersih ke Paket GTK.');
                }

                $zip->deleteName($envEntry);
                if (!$zip->addFromString($envEntry, $env)) {
                    throw new RuntimeException('Gagal menulis .env bersih ke Paket GTK.');
                }

                $zip->deleteName($ticketPath);
                if (!$zip->addFromString($ticketPath, $json . PHP_EOL)) {
                    throw new RuntimeException('Gagal menyisipkan ticket auto-pair ke Paket GTK.');
                }
            } finally {
                if (isset($tmp) && is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function resolveOwner(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'schools') || !$this->tableExists($pdo, 'users')) {
            throw new RuntimeException('Database Paket GTK tidak memiliki tabel schools/users.');
        }

        $schoolId = trim((string) $pdo->query("SELECT npsn FROM schools WHERE npsn IS NOT NULL AND TRIM(npsn) <> '' ORDER BY id LIMIT 1")->fetchColumn());
        if ($schoolId === '') {
            throw new RuntimeException('NPSN tidak ditemukan pada database Paket GTK.');
        }

        $row = $pdo->query(
            "SELECT id, login_id, name, role
             FROM users
             WHERE login_id IS NOT NULL
               AND TRIM(login_id) <> ''
               AND LOWER(COALESCE(role,'')) NOT IN ('admin','siswa')
             ORDER BY CASE
                WHEN LOWER(COALESCE(role,'')) IN ('guru','wali_kelas','guru_bk','kepala_sekolah','pengajar') THEN 0
                ELSE 1
             END, id
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('Pemilik GTK tidak ditemukan pada database Paket GTK.');
        }

        $loginId = trim((string) ($row['login_id'] ?? ''));
        if ($loginId === '') {
            throw new RuntimeException('login_id pemilik GTK kosong.');
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,96}$/', $schoolId) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,96}$/', $loginId) !== 1) {
            throw new RuntimeException('NPSN/login_id mengandung karakter yang tidak didukung auto-pair.');
        }

        return [$schoolId, $loginId, (int) ($row['id'] ?? 0), trim((string) ($row['name'] ?? ''))];
    }

    private function issueBoundTicket(string $schoolId, string $loginId): array
    {
        $server = rtrim(trim((string) EduxidCloudConfig::syncServer()), '/');
        if ($server === '') {
            // Backward-compatible fallback untuk instalasi lama yang belum memakai resolver Setup Cloud.
            $server = rtrim((string) config('eduxid.sync_server', env('EDUXID_SYNC_SERVER', '')), '/');
        }
        if ($server === '') {
            throw new RuntimeException('Server eduXid Cloud belum dikonfigurasi. Buka Admin -> eduXid Cloud -> Setup Cloud.');
        }
        if (OpaqueRouteRegistryV21::enabled() && !str_starts_with(strtolower($server), 'https://')) {
            throw new RuntimeException('BLOCKED V21: pairing anonim mewajibkan HTTPS.');
        }

        // Sumber kanonik sama dengan Dashboard/Admin sync: Setup Cloud.
        $adminKey = trim((string) EduxidCloudConfig::adminToken());

        // Fallback hanya untuk kompatibilitas instalasi lama; key tidak pernah ditulis ke ZIP bundle.
        if ($adminKey === '') {
            $adminKey = trim((string) (
                config('eduxid.admin_key')
                ?: config('eduxid.admin_token')
                ?: env('EDUXID_ADMIN_KEY')
                ?: env('EDUXID_ADMIN_TOKEN')
            ));
        }

        if ($adminKey === '') {
            throw new RuntimeException(
                'Kunci sekolah eduXid Cloud belum tersedia. Buka Admin -> eduXid Cloud -> Setup Cloud, '
                . 'pastikan status SIAP, lalu buat/perbaiki Paket GTK kembali.'
            );
        }

        $health = $this->request()->get($server . '/health');
        if (!$health->successful()) {
            throw new RuntimeException('Worker Cloud tidak dapat diverifikasi. HTTP ' . $health->status());
        }
        $contract = trim((string) ($health->json('bundle_autopair_contract') ?? ''));
        if ($contract !== self::CONTRACT) {
            throw new RuntimeException('Worker terbaru untuk GTK auto-pair belum dideploy. Contract remote: ' . ($contract !== '' ? $contract : '(tidak ada)'));
        }

        // V21R114: the same high-entropy, account-bound BND1 ticket can be
        // reused after a clean GTK reinstall. Cloud stores only its hash.
        // Losing the bundle/ticket still requires Admin to issue a new one.
        $expiresAt = null;

        $response = $this->request()
            ->withHeaders(['Authorization' => 'Admin ' . $adminKey])
            ->post($server . '/api/admin/enrollment/create', [
                'school_id' => $schoolId,
                'login_id' => $loginId,
                'max_uses' => 0,
                'expires_at' => $expiresAt,
                'reusable_pairing' => true,
            ]);

        if (!$response->successful()) {
            $code = trim((string) ($response->json('error.code') ?? 'HTTP_' . $response->status()));
            $message = trim((string) ($response->json('error.message') ?? $response->body()));
            throw new RuntimeException('Gagal membuat ticket auto-pair Cloud [' . $code . ']: ' . mb_substr($message, 0, 500));
        }

        $token = trim((string) ($response->json('data.enrollment_code') ?? ''));
        $boundLogin = trim((string) ($response->json('data.bound_login_id') ?? ''));
        $maxUses = (int) ($response->json('data.max_uses') ?? 0);

        if ($token === '' || !str_starts_with($token, 'BND1:')) {
            throw new RuntimeException('Worker tidak mengembalikan ticket bundle BND1 yang valid.');
        }
        if ($boundLogin !== $loginId || $maxUses !== 0 ||
            $response->json('data.reusable_pairing') !== true ||
            $response->json('data.expires_at') !== null) {
            throw new RuntimeException('Worker belum mendukung ticket berulang V21R114 yang terikat satu GTK. Deploy Worker sebelum membuat paket.');
        }

        return [
            'enrollment_token' => $token,
            'expires_at' => (string) ($response->json('data.expires_at') ?? $expiresAt),
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(30)->acceptJson()->asJson();
        $ca = trim((string) config('eduxid.ca_cert', ''));
        if ($ca !== '' && is_file($ca)) {
            $request = $request->withOptions(['verify' => $ca]);
        }
        return $request;
    }

    private function sanitizeBundleDatabase(PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            foreach (['sync_devices', 'sync_cursors', 'sync_dead_letters', 'lms_sync_records',
                // Admin-only secrets/registries must never ship in a GTK ZIP.
                'cloud_alias_activation_credentials', 'cloud_identity_alias_routes',
                'cloud_ljk_pair_keys_v21', 'cloud_ljk_nonces_v21'] as $table) {
                if ($this->tableExists($pdo, $table)) {
                    $pdo->exec('DELETE FROM ' . $table);
                }
            }

            if ($this->tableExists($pdo, 'app_settings')) {
                $pdo->exec(
                    "DELETE FROM app_settings
                     WHERE key = 'daemon::heartbeat'
                        OR key = 'master::owner_device'
                        OR key LIKE 'wpa::%'
                        OR key LIKE 'ljk::snapshot::%'
                        OR key LIKE 'gtk_autopair::%'"
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function sanitizeEnv(string $env, string $schoolId, string $deviceId, bool $opaque = false): string
    {
        $env = str_replace("\r\n", "\n", $env);
        $values = [
            'EDUXID_SCHOOL_ID' => $schoolId,
            'EDUXID_NPSN' => $schoolId,
            'EDUXID_DEVICE_ID' => $deviceId,
            'EDUXID_SYNC_TOKEN' => '',
            'LOCAL_SYNC_TOKEN' => '',
            'EDUXID_ENROLLMENT_TOKEN' => '',
            'EDUXID_ADMIN_KEY' => '',
            'EDUXID_ADMIN_TOKEN' => '',
            'EDUXID_UPDATE_PRIVATE_KEY' => '',
            // Never distribute the school's alias/HMAC secret to teacher bundles.
            'EDUXID_SCHOOL_ALIAS_KEY' => '',
            'EDUXID_IDENTITY_PROTOCOL' => $opaque ? 'v21-opaque' : 'legacy',
            'EDUXID_EDITION' => 'teacher',
            'EDUXID_INSTALLATION_TYPE' => 'teacher',
        ];

        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            $line = $key . '=' . $value;
            if (preg_match($pattern, $env) === 1) {
                $env = (string) preg_replace($pattern, $line, $env, 1);
            } else {
                $env = rtrim($env) . "\n" . $line . "\n";
            }
        }

        return str_replace("\n", PHP_EOL, rtrim($env) . "\n");
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function findEntry(ZipArchive $zip, callable $predicate): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && $predicate(str_replace('\\', '/', $name))) {
                return $name;
            }
        }
        return null;
    }
}
