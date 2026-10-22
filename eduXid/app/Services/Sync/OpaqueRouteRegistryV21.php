<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * V21R4 STAGING ONLY. Maps a local Dapodik login to the opaque alias that the
 * trusted school Admin already issued. No school HMAC secret is needed on GTK.
 * The lookup key is a keyed digest tied to the local Laravel APP_KEY.
 * Do not enable until aliases have been delivered via authenticated pairing.
 */
final class OpaqueRouteRegistryV21
{
    public static function enabled(): bool
    {
        return CloudDirectoryAliasV21::stagingEnabled();
    }

    private static function localMacKey(): string
    {
        $encoded = (string) config('app.key', '');
        $key = str_starts_with($encoded, 'base64:')
            ? base64_decode(substr($encoded, 7), true)
            : $encoded;
        if (!is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('BLOCKED V21: kunci lokal belum cukup kuat.');
        }
        return $key;
    }

    public static function lookupDigest(string $school, string $role, string $localId): string
    {
        if (!preg_match('/^[0-9]{8}$/D', $school) || !in_array($role, ['teacher', 'student'], true)
            || trim($localId) === '' || strlen($localId) > 128) {
            throw new RuntimeException('BLOCKED V21: parameter identitas lokal tidak valid.');
        }
        return hash_hmac('sha256', "eduxid-v21-local-map:v1\0{$school}\0{$role}\0" . trim($localId), self::localMacKey());
    }

    /**
     * Provision mappings in small SQLite write transactions (V21R42).
     * Repeated roster members are deduplicated before touching SQLite.
     * An already published digest/alias is immutable; never update it.
     *
     * @param array<string,string> $localToAliases local Dapodik IDs => canonical Cloud aliases
     */
    public function provisionBatch(string $school, string $role, array $localToAliases): void
    {
        if (!self::enabled()) {
            throw new RuntimeException('BLOCKED V21: provisioning memerlukan v21-opaque.');
        }
        if (!Schema::hasTable('cloud_identity_alias_routes')) {
            throw new RuntimeException('BLOCKED V21: migrasi tabel alias belum dijalankan.');
        }
        if (!in_array($role, ['teacher', 'student'], true)) {
            throw new RuntimeException('BLOCKED V21: peran alias tidak valid.');
        }

        $records = [];
        $aliases = [];
        foreach ($localToAliases as $localId => $alias) {
            $digest = self::lookupDigest($school, $role, (string) $localId);
            if (!is_string($alias) || !OpaqueIdentityV21::isAlias($alias, $role)) {
                throw new RuntimeException('BLOCKED V21: alias guru/siswa tidak cocok.');
            }
            if (isset($records[$digest]) && !hash_equals($records[$digest], $alias)) {
                throw new RuntimeException('BLOCKED V21: satu identitas memiliki alias berbeda.');
            }
            if (isset($aliases[$alias]) && !hash_equals($aliases[$alias], $digest)) {
                throw new RuntimeException('BLOCKED V21: satu alias digunakan oleh dua identitas.');
            }
            $records[$digest] = $alias;
            $aliases[$alias] = $digest;
        }
        if ($records === []) {
            return;
        }
        ksort($records, SORT_STRING);

        // The database configuration already enables WAL; do not switch its
        // journal mode during HTTP requests or modify the user's SQLite file.
        // A longer wait is limited to this connection when provisioning aliases.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA busy_timeout = 15000');
        }
        // 75 rows x 6 columns stays below older SQLite bind-variable limits.
        foreach (array_chunk($records, 75, true) as $batch) {
            $this->writeVerifiedBatch($school, $role, $batch);
        }
    }

    public function provision(string $school, string $role, string $localId, string $alias): void
    {
        $this->provisionBatch($school, $role, [$localId => $alias]);
    }

    /** @param array<string,string> $batch local digest => canonical alias */
    private function writeVerifiedBatch(string $school, string $role, array $batch): void
    {
        $attempt = 0;
        while (true) {
            try {
                DB::transaction(function () use ($school, $role, $batch): void {
                    $timestamp = now();
                    $rows = [];
                    foreach ($batch as $digest => $alias) {
                        $rows[] = [
                            'school_id' => $school,
                            'role' => $role,
                            'local_digest' => $digest,
                            'cloud_alias' => $alias,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                    // Acquire the SQLite write lock FIRST, before reading.
                    // This avoids DEFERRED read-to-write upgrade failures.
                    // INSERT OR IGNORE never changes an existing alias.
                    DB::table('cloud_identity_alias_routes')->insertOrIgnore($rows);
                    $stored = DB::table('cloud_identity_alias_routes')
                        ->where('school_id', $school)
                        ->where('role', $role)
                        ->whereIn('local_digest', array_keys($batch))
                        ->pluck('cloud_alias', 'local_digest')
                        ->all();
                    foreach ($batch as $digest => $alias) {
                        if (!isset($stored[$digest]) || !is_string($stored[$digest])) {
                            // A uniqueness conflict on cloud_alias with another
                            // local identity must NOT be silently accepted.
                            throw new RuntimeException('BLOCKED V21: benturan alias pada registry; tidak ada identitas yang diganti.');
                        }
                        if (!hash_equals($alias, $stored[$digest])) {
                            throw new RuntimeException('BLOCKED V21: alias yang sudah diterbitkan tidak boleh diganti. Pulihkan secret sekolah asli.');
                        }
                    }
                });
                return;
            } catch (\Illuminate\Database\QueryException $exception) {
                if (!$this->isSQLiteBusy($exception)) {
                    throw $exception;
                }
                if (++$attempt >= 3 || DB::transactionLevel() > 0) {
                    throw new RuntimeException(
                        'SQLite sedang digunakan proses lain saat menyimpan alias. '
                        .'Tutup proses persiapan Cloud ganda, pastikan daemon tidak menahan transaksi, lalu coba lagi. '
                        .'Alias yang sudah berhasil tersimpan tetap aman.',
                        0,
                        $exception
                    );
                }
                usleep([150000, 350000][min($attempt - 1, 1)]);
            }
        }
    }

    private function isSQLiteBusy(\Illuminate\Database\QueryException $exception): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }
        $error = strtolower($exception->getMessage());
        return str_contains($error, 'database is locked')
            || str_contains($error, 'database table is locked')
            || str_contains($error, 'sqlite_busy')
            || str_contains($error, 'sqlite_locked');
    }

    public function alias(string $school, string $role, string $localId): string
    {
        if (!self::enabled()) {
            throw new RuntimeException('BLOCKED V21: resolver alias hanya untuk mode v21-opaque.');
        }
        if (!Schema::hasTable('cloud_identity_alias_routes')) {
            throw new RuntimeException('BLOCKED V21: tabel alias belum tersedia; jangan push/pairing.');
        }
        $digest = self::lookupDigest($school, $role, $localId);
        $alias = DB::table('cloud_identity_alias_routes')
            ->where('school_id', $school)->where('role', $role)
            ->where('local_digest', $digest)->value('cloud_alias');
        if (!is_string($alias) || !OpaqueIdentityV21::isAlias($alias, $role)) {
            throw new RuntimeException('BLOCKED V21: alias pengguna belum diprovisioning secara sah.');
        }
        return $alias;
    }

    public function deviceId(string $school, string $role, string $localId, string $suffix): string
    {
        if (!in_array($suffix, ['PC','HP'], true) || ($role === 'student' && $suffix !== 'HP')) {
            throw new RuntimeException('BLOCKED V21: peran/jenis perangkat tidak cocok.');
        }
        return $school . '-' . $this->alias($school, $role, $localId) . '-' . $suffix;
    }
}
