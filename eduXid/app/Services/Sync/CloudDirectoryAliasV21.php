<?php

declare(strict_types=1);

namespace App\Services\Sync;

use RuntimeException;

/**
 * V21R3 STAGING ONLY: convert the school-admin's private directory to
 * Cloud aliases immediately before HTTPS transmission. Raw IDs remain
 * authoritative solely inside the local Dapodik/SQLite database.
 * Do not copy the school secret to GTK bundles, browsers or Cloud.
 */
final class CloudDirectoryAliasV21
{
    private OpaqueIdentityV21 $identity;

    /** V21R78: The school secret never leaves Admin; only an HMAC commitment is transmitted. */
    private string $schoolSecret;

    /** @var array<string,string> Per-request cache; no secrets or raw IDs leave PHP. */
    private array $provisioned = [];

    /** @var array<string,true> Validated once per request (Cloud D1 checks remain authoritative). */
    private array $sealedSchools = [];

    public function __construct(string $secret)
    {
        $this->identity = new OpaqueIdentityV21($secret);
        $this->schoolSecret = $secret;
    }

    /** An opaque commitment to one school's immutable alias generation key.
     * HMAC-SHA256 resists brute forcing even if NPSN is public. Do not log this value.
     */
    private function directoryEpoch(string $schoolId): string
    {
        return hash_hmac('sha256', "eduxid-v21-directory-epoch:v1\0" . $schoolId, $this->schoolSecret);
    }

    public static function fromConfig(): self
    {
        $raw = trim((string) config('eduxid.identity_school_alias_key', ''));
        if ($raw === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $raw) !== 1) {
            throw new RuntimeException('BLOCKED: secret alias sekolah belum diatur pada Admin staging.');
        }
        $secret = base64_decode(strtr($raw . str_repeat('=', (4 - strlen($raw) % 4) % 4), '-_', '+/'), true);
        if (!is_string($secret) || strlen($secret) !== 32) {
            throw new RuntimeException('BLOCKED: secret alias sekolah harus 32 byte dari sumber aman.');
        }
        // V21R110: fail locally before alias provisioning / Cloud HTTP.
        // Legacy schools must explicitly bind to their D1 epoch once.
        require_once base_path('bootstrap/SchoolAliasSealV21R110.php');
        \EduXid\Bootstrap\SchoolAliasSealV21R110::assertConfigured(base_path(), $secret);
        return new self($secret);
    }

    public static function stagingEnabled(): bool
    {
        return strtolower(trim((string) config('eduxid.identity_protocol', 'legacy'))) === 'v21-opaque';
    }

    /** All school-specific entry points verify the same pinned secret and school. */
    private function assertSealedSchool(string $schoolId): void
    {
        if (isset($this->sealedSchools[$schoolId])) return;
        require_once base_path('bootstrap/SchoolAliasSealV21R110.php');
        // V21R114: one-time auto-verify with Cloud while using the existing
        // Admin setup token; never accept a changed pinned epoch.
        CloudSchoolIdentityAutoBindV21R114::ensure($schoolId);
        \EduXid\Bootstrap\SchoolAliasSealV21R110::assertSchool(base_path(), $this->schoolSecret, $schoolId);
        $this->sealedSchools[$schoolId] = true;
    }

    /** Pure preview for authenticated Admin; does not change the route registry. */
    public function teacherAliasForAdmin(string $schoolId, string $loginId): string
    {
        $this->assertSealedSchool($schoolId);
        return $this->identity->alias($schoolId, 'teacher', $loginId);
    }

    /** School-Admin only: create and bind GTK alias before generating bundle ticket. */
    public function provisionLocalTeacherAlias(string $schoolId, string $loginId): string
    {
        $this->assertSealedSchool($schoolId);
        $alias = $this->identity->alias($schoolId, 'teacher', $loginId);
        app(OpaqueRouteRegistryV21::class)->provision($schoolId, 'teacher', $loginId, $alias);
        return $alias;
    }

    /** Admin-only, deterministic; never transmit the school secret to GTK. */
    public function studentAliasForBundle(string $schoolId, string $localNisn): string
    {
        $this->assertSealedSchool($schoolId);
        return $this->identity->alias($schoolId, 'student', $localNisn);
    }

    /**
     * Pre-provision an entire directory/roster before Cloud HTTPS calls.
     * Deduplication + bounded SQLite transactions avoid one write per pupil.
     *
     * @param list<string> $localIds
     */
    public function provisionIds(string $schoolId, string $role, array $localIds): void
    {
        $this->assertSealedSchool($schoolId);
        $records = [];
        foreach ($localIds as $localId) {
            $id = trim((string) $localId);
            if ($id === '') {
                throw new RuntimeException('BLOCKED V21: identitas lokal kosong saat provisioning.');
            }
            $alias = $this->identity->alias($schoolId, $role, $id);
            $records[$id] = $alias;
        }
        if ($records === []) return;
        app(OpaqueRouteRegistryV21::class)->provisionBatch($schoolId, $role, $records);
        // V21R45: prepare activation secrets in bounded SQLite write-first
        // batches, before converting directory accounts for Cloud HTTPS.
        // Existing secrets and one-time reveal state remain immutable.
        app(CloudActivationCredentialV21::class)->provisionBatch($schoolId, $role, $records);
        foreach ($records as $id => $alias) {
            $this->provisioned[$schoolId."\0".$role."\0".$id] = $alias;
        }
    }

    private function ensureProvisioned(string $schoolId, string $role, string $id): string
    {
        $key = $schoolId."\0".$role."\0".trim($id);
        if (isset($this->provisioned[$key])) return $this->provisioned[$key];
        $this->provisionIds($schoolId, $role, [$id]);
        return $this->provisioned[$key];
    }

    /** @param array<string,mixed> $account */
    public function student(string $schoolId, array $account): array
    {
        $this->assertSealedSchool($schoolId);
        $original = trim((string) ($account['login_id'] ?? ''));
        if (preg_match('/^\d{10}$/D', $original) !== 1) {
            throw new RuntimeException('BLOCKED V21R101: NISN harus 10 digit untuk login PWA.');
        }
        $alias = $this->ensureProvisioned($schoolId, 'student', $original);
        // School Admin has the private generator. Persist the authenticated local
        // mapping BEFORE publishing the directory; GTK never receives this secret.
        // Other client-provided personal ID fields MUST NOT be sent to D1.
        unset($account['nisn'], $account['student_nisn'], $account['nik'], $account['nip']);
        // V21R101: only authenticated Admin directory publication transmits this
        // numeric identifier once over HTTPS. Cloud does not persist the raw ID;
        // it derives a secret-keyed lookup index and keeps the alias as primary ID.
        $account['login_identifier'] = $original;
        $account['login_id'] = $alias;
        $account['identity_epoch'] = $this->directoryEpoch($schoolId);
        $account['device_id'] = $schoolId . '-' . $alias . '-HP';
        // Default password on FIRST publication is NPSN; Cloud preserves any
        // password previously changed by the user (password_user_set = 1).
        $account['password_hash'] = hash('sha256', $schoolId . '|' . $alias . '|' . $schoolId);
        $this->assertOpaquePayload($account);
        return $account;
    }

    /** @param array<string,mixed> $account */
    public function teacher(string $schoolId, array $account): array
    {
        $this->assertSealedSchool($schoolId);
        $original = trim((string) ($account['login_id'] ?? ''));
        if (preg_match('/^\d{8,20}$/D', $original) !== 1) {
            throw new RuntimeException('BLOCKED V21R101: NIP/NIK guru harus 8–20 digit untuk login PWA.');
        }
        $alias = $this->ensureProvisioned($schoolId, 'teacher', $original);
        unset($account['nip'], $account['nik'], $account['nuptk']);
        // V21R101: only authenticated Admin directory publication transmits this
        // numeric identifier once over HTTPS. Cloud does not persist the raw ID;
        // it derives a secret-keyed lookup index and keeps the alias as primary ID.
        $account['login_identifier'] = $original;
        $account['login_id'] = $alias;
        $account['identity_epoch'] = $this->directoryEpoch($schoolId);
        $account['device_id'] = $schoolId . '-' . $alias . '-HP';
        $account['pc_device_id'] = $schoolId . '-' . $alias . '-PC';
        $account['password_hash'] = hash('sha256', $schoolId . '|' . $alias . '|' . $schoolId);
        $assignments = $account['assignments'] ?? [];
        if (!is_array($assignments)) {
            throw new RuntimeException('BLOCKED: penugasan guru harus berupa array.');
        }
        foreach ($assignments as &$assignment) {
            if (!is_array($assignment)) {
                throw new RuntimeException('BLOCKED: penugasan guru tidak valid.');
            }
            $roster = $assignment['roster'] ?? [];
            if (!is_array($roster)) {
                throw new RuntimeException('BLOCKED: daftar siswa tidak valid.');
            }
            foreach ($roster as &$student) {
                if (!is_array($student)) {
                    throw new RuntimeException('BLOCKED: data siswa tidak valid.');
                }
                $nisn = trim((string) ($student['nisn'] ?? ''));
                if ($nisn === '') {
                    throw new RuntimeException('BLOCKED: siswa dalam daftar kelas tidak memiliki identitas lokal.');
                }
                $studentAlias = $this->ensureProvisioned($schoolId, 'student', $nisn);
                $student['student_login_id'] = $studentAlias;
                unset($student['nisn'], $student['student_nisn'], $student['nik'], $student['nip']);
            }
            unset($student);
            $assignment['roster'] = $roster;
        }
        unset($assignment);
        $account['assignments'] = $assignments;
        $this->assertOpaquePayload($account);
        return $account;
    }

    /** Fail closed if a future roster/account field reintroduces a raw ID. */
    public function assertOpaquePayload(array $payload): void
    {
        $nodes = 0;
        $scan = function (array $row, int $depth) use (&$scan, &$nodes): void {
            if (++$nodes > 30000 || $depth > 24) {
                throw new RuntimeException('BLOCKED: payload direktori terlalu dalam/besar.');
            }
            foreach ($row as $field => $value) {
                $key = strtolower((string) $field);
                if (in_array($key, ['nip','nik','nisn','nuptk','student_nisn','teacher_nip','student_nik'], true) && $value !== null && trim((string) $value) !== '') {
                    throw new RuntimeException('BLOCKED: direktori masih memuat identitas pribadi.');
                }
                if (in_array($key, ['login_id','teacher_login_id','student_login_id'], true) &&
                    $value !== null && trim((string) $value) !== '' && !OpaqueIdentityV21::isAlias((string) $value)) {
                    throw new RuntimeException('BLOCKED: direktori masih memuat login ID lama.');
                }
                if (in_array($key, ['device_id','pc_device_id','source_device_id','target_device_id','student_device_id','teacher_device_id','node_id'], true) &&
                    $value !== null && trim((string) $value) !== '' &&
                    preg_match('/^[0-9]{8}-[ts]_[a-z2-7]{26}-(?:PC|HP)$/D', (string) $value) !== 1) {
                    throw new RuntimeException('BLOCKED V21: RAW_DEVICE_ID_REJECTED pada direktori GTK.');
                }
                // Worker also rejects ten-to-twenty-digit personal references
                // inside these nested keys, even if top-level logins are opaque.
                if (in_array($key, ['student_id','student_user_id','entity_id'], true) &&
                    is_scalar($value) &&
                    preg_match('/^[0-9]{10,20}$/D', (string) $value) === 1) {
                    throw new RuntimeException('BLOCKED V21: RAW_REFERENCE_IDENTIFIER_REJECTED pada direktori GTK.');
                }
                if (is_array($value)) {
                    $scan($value, $depth + 1);
                }
            }
        };
        $scan($payload, 0);
    }

}
