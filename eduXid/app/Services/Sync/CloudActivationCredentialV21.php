<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * STAGING V21R5: one high-entropy per-account credential. Only a SHA256 proof
 * of a random 192-bit credential is sent to the legacy Cloud account upsert.
 * Plaintext stays encrypted in the local school Admin's SQLite until revealed
 * once under Admin re-authentication. Never include it in a GTK ZIP or PWA JS.
 */
final class CloudActivationCredentialV21
{
    /**
     * V21R45: create credentials with a WRITE-FIRST SQLite transaction.
     * Existing credentials are immutable, even after their one-time reveal.
     * No personal identifiers, hashes or encrypted credentials are logged.
     */
    public function passwordHash(string $school, string $role, string $localId, string $alias): string
    {
        $this->assertReady($role, $alias);
        $digest = OpaqueRouteRegistryV21::lookupDigest($school, $role, $localId);
        // Read-only fast path: the normal case after bulk preparation.
        $existing = DB::table('cloud_alias_activation_credentials')
            ->where('school_id', $school)->where('role', $role)
            ->where('local_digest', $digest)->first();
        if ($existing) {
            return $this->verifyExisting($existing, $alias);
        }
        $rows = [$digest => $alias];
        $this->insertVerifiedBatch($school, $role, $rows);
        $stored = DB::table('cloud_alias_activation_credentials')
            ->where('school_id', $school)->where('role', $role)
            ->where('local_digest', $digest)->first();
        if (!$stored) {
            throw new RuntimeException('BLOCKED V21: kredensial aktivasi tidak tersimpan.');
        }
        return $this->verifyExisting($stored, $alias);
    }

    /**
     * Pre-issue unique credentials in small write-first transactions. The
     * caller must have already provisioned the immutable alias registry.
     * Repeated roster members are deduplicated and never reissued.
     *
     * @param array<string,string> $localToAliases local IDs => aliases
     */
    public function provisionBatch(string $school, string $role, array $localToAliases): void
    {
        if (!in_array($role, ['teacher', 'student'], true)) {
            throw new RuntimeException('BLOCKED V21: peran kredensial tidak valid.');
        }
        if (!OpaqueRouteRegistryV21::enabled() || !Schema::hasTable('cloud_alias_activation_credentials')) {
            throw new RuntimeException('BLOCKED V21: tabel kredensial aktivasi belum siap.');
        }
        $records = [];
        foreach ($localToAliases as $localId => $alias) {
            if (!is_string($alias) || !OpaqueIdentityV21::isAlias($alias, $role)) {
                throw new RuntimeException('BLOCKED V21: alias kredensial tidak valid.');
            }
            $digest = OpaqueRouteRegistryV21::lookupDigest($school, $role, (string) $localId);
            if (isset($records[$digest]) && !hash_equals($records[$digest], $alias)) {
                throw new RuntimeException('BLOCKED V21: benturan identitas kredensial lokal.');
            }
            $records[$digest] = $alias;
        }
        if ($records === []) return;
        ksort($records, SORT_STRING);
        // 60 rows x 8 values: compatible with older SQLite variable limits.
        foreach (array_chunk($records, 60, true) as $batch) {
            // Outside any write transaction, skip already issued credentials.
            // Re-running Siapkan Cloud should not take an SQLite write lock
            // just to re-verify hundreds of identical accounts.
            $existing = DB::table('cloud_alias_activation_credentials')
                ->where('school_id', $school)->where('role', $role)
                ->whereIn('local_digest', array_keys($batch))
                ->get(['local_digest', 'cloud_alias', 'password_hash']);
            foreach ($existing as $row) {
                $digest = (string) $row->local_digest;
                if (isset($batch[$digest])) {
                    $this->verifyExisting($row, $batch[$digest]);
                    unset($batch[$digest]);
                }
            }
            if ($batch !== []) {
                $this->insertVerifiedBatch($school, $role, $batch);
            }
        }
    }

    /** @param array<string,string> $batch digest => immutable Cloud alias */
    private function insertVerifiedBatch(string $school, string $role, array $batch): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::transactionLevel() === 0) {
            DB::statement('PRAGMA busy_timeout = 10000');
        }
        $attempt = 0;
        while (true) {
            try {
                DB::transaction(function () use ($school, $role, $batch): void {
                    $rows = [];
                    $when = now();
                    foreach ($batch as $digest => $alias) {
                        $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                        $rows[] = [
                            'school_id' => $school,
                            'role' => $role,
                            'local_digest' => $digest,
                            'cloud_alias' => $alias,
                            'password_hash' => hash('sha256', $school . '|' . $alias . '|' . $password),
                            'encrypted_password' => Crypt::encryptString($password),
                            'created_at' => $when,
                            'updated_at' => $when,
                        ];
                    }
                    // First DB operation inside transaction MUST be a write:
                    // no SQLite DEFERRED read-to-write lock upgrade.
                    DB::table('cloud_alias_activation_credentials')->insertOrIgnore($rows);
                    $stored = DB::table('cloud_alias_activation_credentials')
                        ->where('school_id', $school)->where('role', $role)
                        ->whereIn('local_digest', array_keys($batch))
                        ->get(['local_digest', 'cloud_alias', 'password_hash']);
                    $found = [];
                    foreach ($stored as $row) {
                        $found[(string) $row->local_digest] = $row;
                    }
                    foreach ($batch as $digest => $alias) {
                        if (!isset($found[$digest])) {
                            throw new RuntimeException('BLOCKED V21: benturan kredensial alias; tidak ada akun yang diganti.');
                        }
                        $this->verifyExisting($found[$digest], $alias);
                    }
                });
                return;
            } catch (\Illuminate\Database\QueryException $e) {
                // Laravel QueryException interpolates SQL bind values (including
                // encrypted_password). Do NOT expose/chaining this exception.
                if (!$this->isSqliteBusy($e)) {
                    throw new RuntimeException('BLOCKED V21: penyimpanan kredensial gagal; periksa skema dan log yang disanitasi.');
                }
                if (++$attempt >= 3 || DB::transactionLevel() > 0) {
                    throw new RuntimeException(
                        'SQLite sedang digunakan saat menyiapkan kredensial aktivasi. '
                        .'Hentikan Sync Daemon sementara dan tutup proses persiapan Cloud lain, lalu coba lagi.'
                    );
                }
                usleep($attempt === 1 ? 150000 : 400000);
            }
        }
    }

    private function verifyExisting(object $record, string $alias): string
    {
        if (!hash_equals((string) ($record->cloud_alias ?? ''), $alias)) {
            throw new RuntimeException('BLOCKED V21: alias akun berubah; reissue manual diperlukan.');
        }
        $hash = (string) ($record->password_hash ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('BLOCKED V21: hash kredensial lokal tidak valid.');
        }
        return $hash;
    }

    private function assertReady(string $role, string $alias): void
    {
        if (!OpaqueRouteRegistryV21::enabled()) {
            throw new RuntimeException('BLOCKED V21: akun aktivasi hanya untuk mode v21-opaque.');
        }
        if (!OpaqueIdentityV21::isAlias($alias, $role)) {
            throw new RuntimeException('BLOCKED V21: peran alias tidak sesuai.');
        }
        if (!Schema::hasTable('cloud_alias_activation_credentials')) {
            throw new RuntimeException('BLOCKED V21: migrasi kredensial belum dijalankan.');
        }
    }

    private function isSqliteBusy(\Illuminate\Database\QueryException $e): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') return false;
        $message = strtolower($e->getMessage());
        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'sqlite_busy')
            || str_contains($message, 'sqlite_locked');
    }

    /** Callable only after the command has reauthenticated the school Admin. */
    public function revealOnce(string $school, string $role, string $alias): string
    {
        if (!OpaqueRouteRegistryV21::enabled() || !OpaqueIdentityV21::isAlias($alias, $role)) {
            throw new RuntimeException('BLOCKED V21: permintaan aktivasi tidak valid.');
        }
        return DB::transaction(function () use ($school, $role, $alias): string {
            $q = DB::table('cloud_alias_activation_credentials')
                ->where('school_id', $school)->where('role', $role)
                ->where('cloud_alias', $alias);
            $record = $q->lockForUpdate()->first();
            if (!$record || !$record->encrypted_password || ($record->revealed_at ?? null)) {
                throw new RuntimeException('BLOCKED V21: kredensial tidak tersedia atau sudah pernah diambil.');
            }
            $plain = Crypt::decryptString((string) $record->encrypted_password);
            $q->update(['encrypted_password' => null, 'revealed_at' => now(), 'updated_at' => now()]);
            return $plain;
        });
    }
}
