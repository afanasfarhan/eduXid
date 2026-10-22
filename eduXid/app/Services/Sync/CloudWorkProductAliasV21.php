<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V21R9 STAGING — transport-only alias projection for Grade Book snapshots.
 * Never change the local grade tables. Cloud ledger keeps the alias envelope.
 * Only final_grade and daily_grade are supported. Other work products fail closed.
 */
final class CloudWorkProductAliasV21
{
    private const SETS = [
        'final_grade' => ['grades'],
        'daily_grade' => ['assessment_scores', 'attitude_logs', 'attendances'],
    ];
    private const FORBIDDEN_ROW_KEYS = [
        'nisn', 'nip', 'nik', 'nuptk', 'student_nisn', 'student_nik',
        'student_name', 'nama', 'nama_siswa', 'name', 'student_user_id',
        'teacher_nip', 'login_id', 'device_id', 'student_device_id',
    ];

    public static function supports(string $entity): bool
    {
        return array_key_exists($entity, self::SETS);
    }

    /** @param array<string,mixed> $payload */
    public static function assertEnvelope(string $entity, array $payload): void
    {
        if (!self::supports($entity)
            || ($payload['identity_protocol'] ?? null) !== 'v21-opaque'
            || ($payload['student_reference'] ?? null) !== 'school-issued-alias-v1') {
            throw new RuntimeException('BLOCKED V21: snapshot nilai belum memakai kontrak alias terverifikasi.');
        }
        if ((int) ($payload['teaching_assignment_id'] ?? 0) <= 0) {
            throw new RuntimeException('BLOCKED V21: identitas penugasan nilai tidak tersedia.');
        }
        foreach (self::SETS[$entity] as $field) {
            if (!array_key_exists($field, $payload)) {
                if ($field === 'attendances') continue; // optional in legacy daily snapshots
                throw new RuntimeException('BLOCKED V21: kolom snapshot nilai tidak lengkap.');
            }
            if (!is_array($payload[$field]) || !array_is_list($payload[$field])) {
                throw new RuntimeException('BLOCKED V21: format daftar nilai tidak valid.');
            }
            foreach ($payload[$field] as $row) {
                if (!is_array($row) || !is_string($row['student_id'] ?? null)
                    || !OpaqueIdentityV21::isAlias($row['student_id'], 'student')) {
                    throw new RuntimeException('BLOCKED V21: baris nilai belum memakai alias siswa.');
                }
                self::assertSafeRow($row);
            }
        }
        CloudOutboundPrivacyGuardV21::assertPayload($payload, app(SchoolIdentity::class)->schoolId());
    }

    /** @param array<string,mixed> $row */
    private static function assertSafeRow(array $row): void
    {
        foreach (self::FORBIDDEN_ROW_KEYS as $field) {
            if (array_key_exists($field, $row)) {
                throw new RuntimeException('BLOCKED V21: baris nilai memuat referensi identitas yang tidak diizinkan.');
            }
        }
        foreach (['id', 'enrollment_id'] as $localKey) {
            if (array_key_exists($localKey, $row)) {
                throw new RuntimeException('BLOCKED V21: primary key lokal tidak boleh dikirim pada snapshot nilai.');
            }
        }
    }

    /**
     * Pure projection shared by outbox and unit tests. Conversion must throw on
     * missing, duplicate, unprovisioned or out-of-class local students.
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function projectRows(array $rows, callable $resolve): array
    {
        if (!array_is_list($rows) || count($rows) > 10000) {
            throw new RuntimeException('BLOCKED V21: daftar nilai terlalu besar atau format tidak valid.');
        }
        $projected = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('student_id', $row) || !is_scalar($row['student_id'])) {
                throw new RuntimeException('BLOCKED V21: student_id nilai kosong/tidak valid.');
            }
            foreach (self::FORBIDDEN_ROW_KEYS as $field) {
                if (array_key_exists($field, $row)) {
                    throw new RuntimeException('BLOCKED V21: baris nilai berisi identitas mentah.');
                }
            }
            // DB row IDs are local to each installation and must never enter Cloud.
            unset($row['id'], $row['enrollment_id']);
            $row['student_id'] = $resolve($row['student_id']);
            $projected[] = $row;
        }
        return $projected;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function outbound(string $entity, array $payload, ?string $sourceDevice = null): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) return $payload;
        if (!self::supports($entity)) {
            throw new RuntimeException('BLOCKED V21: jenis snapshot belum didukung untuk alias.');
        }
        $ta = $this->assignment($payload);
        // Source cannot be a foreign PC chosen by the caller. If no owner PC
        // is paired in staging, leave the snapshot pending locally and fail.
        $this->assertTeacherSource((int) $ta->teacher_id, trim((string) $sourceDevice));
        $school = app(SchoolIdentity::class)->schoolId();
        $resolver = function ($studentId) use ($ta, $school): string {
            $id = filter_var($studentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new RuntimeException('BLOCKED V21: siswa lokal tidak valid.');
            if (!DB::table('enrollments')->where('homeroom_id', (int) $ta->homeroom_id)
                ->where('student_id', $id)->exists()) {
                throw new RuntimeException('BLOCKED V21: siswa tidak terdaftar dalam rombel penugasan.');
            }
            $student = DB::table('students')->where('id', $id)->first(['nisn']);
            $nisn = trim((string) ($student->nisn ?? ''));
            if (!preg_match('/^[0-9]{10,20}$/D', $nisn)
                || DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
                throw new RuntimeException('BLOCKED V21: NISN siswa tidak valid atau tidak unik.');
            }
            return app(OpaqueRouteRegistryV21::class)->alias($school, 'student', $nisn);
        };
        foreach (self::SETS[$entity] as $field) {
            if ($field === 'attendances' && !array_key_exists($field, $payload)) continue;
            if (!isset($payload[$field]) || !is_array($payload[$field])) {
                throw new RuntimeException('BLOCKED V21: format nilai tidak lengkap.');
            }
            $payload[$field] = self::projectRows($payload[$field], $resolver);
        }
        $payload['identity_protocol'] = 'v21-opaque';
        $payload['student_reference'] = 'school-issued-alias-v1';
        self::assertEnvelope($entity, $payload);
        return $payload;
    }

    /**
     * Returns a COPY for local SQLite apply; never mutate incoming Cloud ledger.
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    public function inbound(string $entity, array $payload, string $sourceDevice): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) return $payload;
        self::assertEnvelope($entity, $payload);
        $ta = $this->assignment($payload);
        $this->assertTeacherSource((int) $ta->teacher_id, $sourceDevice);
        $resolver = function ($alias) use ($ta): int {
            if (!is_string($alias) || !OpaqueIdentityV21::isAlias($alias, 'student')) {
                throw new RuntimeException('BLOCKED V21: alias siswa pada snapshot salah.');
            }
            $nisn = app(CloudInboundStudentV21::class)->resolveInHomeroom($alias, (int) $ta->homeroom_id);
            if (DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
                throw new RuntimeException('BLOCKED V21: NISN hasil alias ambigu.');
            }
            $studentId = (int) (DB::table('students')->where('nisn', $nisn)->value('id') ?? 0);
            if ($studentId <= 0 || !DB::table('enrollments')
                ->where('homeroom_id', (int) $ta->homeroom_id)
                ->where('student_id', $studentId)->exists()) {
                throw new RuntimeException('BLOCKED V21: siswa tidak cocok dengan penugasan penerima.');
            }
            return $studentId;
        };
        foreach (self::SETS[$entity] as $field) {
            if (!array_key_exists($field, $payload)) continue;
            $payload[$field] = self::projectRows($payload[$field], $resolver);
        }
        unset($payload['identity_protocol'], $payload['student_reference']);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function assignment(array $payload): object
    {
        $taId = (int) ($payload['teaching_assignment_id'] ?? 0);
        if ($taId <= 0) throw new RuntimeException('BLOCKED V21: teaching_assignment_id wajib.');
        $ta = DB::table('teaching_assignments')->where('id', $taId)->first();
        if (!$ta || (int) ($ta->teacher_id ?? 0) <= 0 || (int) ($ta->homeroom_id ?? 0) <= 0
            || (int) ($ta->subject_id ?? 0) <= 0) {
            throw new RuntimeException('BLOCKED V21: penugasan guru/mapel/rombel belum tersedia.');
        }
        foreach (['teacher_id', 'homeroom_id', 'subject_id'] as $field) {
            if (array_key_exists($field, $payload) && (int) $payload[$field] !== (int) $ta->{$field}) {
                throw new RuntimeException('BLOCKED V21: scope snapshot nilai tidak sesuai penugasan.');
            }
        }
        return $ta;
    }

    /** Authoritative source check also applies to grade tombstones (no rows to alias). */
    public function assertAuthorizedFinalGradeSource(array $payload, string $sourceDevice): void
    {
        $ta = $this->assignment($payload);
        $this->assertTeacherSource((int) $ta->teacher_id, trim($sourceDevice));
    }

    private function assertTeacherSource(int $teacherId, string $sourceDevice): void
    {
        $teacher = Teacher::find($teacherId);
        if (!$teacher || $sourceDevice === '') {
            throw new RuntimeException('BLOCKED V21: perangkat guru sumber tidak terverifikasi.');
        }
        $school = app(SchoolIdentity::class)->schoolId();
        CloudOutboundPrivacyGuardV21::assertDevice($school, $sourceDevice, 'teacher');
        $candidateLogins = array_filter(array_unique(array_map('strval', [
            $teacher->nip ?? null, $teacher->nik ?? null, $teacher->nuptk ?? null,
            $teacher->login_id ?? null,
            $teacher->user_id ? User::find((int) $teacher->user_id)?->login_id : null,
        ])));
        foreach ($candidateLogins as $login) {
            foreach (['PC', 'HP'] as $suffix) {
                try {
                    if (hash_equals(app(OpaqueRouteRegistryV21::class)->deviceId($school, 'teacher', $login, $suffix), $sourceDevice)) {
                        return;
                    }
                } catch (RuntimeException) {
                    // Continue with other locally linked identity candidates only.
                }
            }
        }
        throw new RuntimeException('BLOCKED V21: snapshot nilai bukan dari guru pemilik penugasan.');
    }
}
