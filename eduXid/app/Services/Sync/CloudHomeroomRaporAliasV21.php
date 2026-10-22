<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V21R10 STAGING ONLY: anonymous transport for *numeric* homeroom recap.
 * Catatan wali free text is intentionally excluded. Remote aliases are
 * resolved only into a local COPY after checking homeroom owner + membership.
 */
final class CloudHomeroomRaporAliasV21
{
    private const ENTITY = 'rapor_recap_batch';
    private const ROW_KEYS = ['student_id', 'rapor_sakit', 'rapor_izin', 'rapor_alpa', 'updated_at'];
    private const ENVELOPE_KEYS = ['entity','sync_id','homeroom_id','rows','sync_version','updated_at','identity_protocol','student_reference'];

    public static function supports(string $entity): bool
    {
        return strtolower(trim($entity)) === self::ENTITY;
    }

    /** @param array<string,mixed> $payload */
    public static function assertEnvelope(string $entity, array $payload): void
    {
        if (!self::supports($entity)
            || ($payload['entity'] ?? '') !== self::ENTITY
            || ($payload['identity_protocol'] ?? '') !== 'v21-opaque'
            || ($payload['student_reference'] ?? '') !== 'school-issued-alias-v1') {
            throw new RuntimeException('BLOCKED V21: rekap rapor belum memakai kontrak alias.');
        }
        $room = filter_var($payload['homeroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($room === false || ($payload['sync_id'] ?? '') !== 'rapor_recap::homeroom::' . $room) {
            throw new RuntimeException('BLOCKED V21: scope rekap rapor tidak sah.');
        }
        if (!is_array($payload['rows'] ?? null) || !array_is_list($payload['rows'])
            || count($payload['rows']) < 1 || count($payload['rows']) > 5000) {
            throw new RuntimeException('BLOCKED V21: daftar rapor kosong atau tidak valid.');
        }
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, self::ENVELOPE_KEYS, true)) {
                throw new RuntimeException('BLOCKED V21: payload rapor memiliki kolom yang tidak diperbolehkan.');
            }
        }
        $aliases = [];
        foreach ($payload['rows'] as $row) {
            if (!is_array($row) || !is_string($row['student_id'] ?? null)
                || !OpaqueIdentityV21::isAlias($row['student_id'], 'student')) {
                throw new RuntimeException('BLOCKED V21: ID siswa rapor bukan alias.');
            }
            $alias = $row['student_id'];
            if (isset($aliases[$alias])) throw new RuntimeException('BLOCKED V21: siswa rapor terduplikasi.');
            $aliases[$alias] = true;
            foreach ($row as $key => $_) {
                if (!in_array($key, self::ROW_KEYS, true)) {
                    throw new RuntimeException('BLOCKED V21: rekap rapor membawa field lain yang mungkin pribadi.');
                }
            }
            foreach (['rapor_sakit', 'rapor_izin', 'rapor_alpa'] as $key) {
                $val = $row[$key] ?? null;
                if ((!is_int($val) && !(is_string($val) && preg_match('/^[0-9]{1,4}$/D', $val)))
                    || (int) $val < 0 || (int) $val > 9999) {
                    throw new RuntimeException('BLOCKED V21: angka kehadiran rapor tidak valid.');
                }
            }
            if (isset($row['updated_at']) && (!is_string($row['updated_at'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $row['updated_at']))) {
                throw new RuntimeException('BLOCKED V21: timestamp rapor tidak valid.');
            }
        }
        if (isset($payload['updated_at']) && (!is_string($payload['updated_at'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $payload['updated_at']))) {
            throw new RuntimeException('BLOCKED V21: tanggal snapshot rapor tidak valid.');
        }
        CloudOutboundPrivacyGuardV21::assertPayload($payload, app(SchoolIdentity::class)->schoolId());
    }

    /**
     * Pure projection, fails closed on duplicate/missing rows and unsafe keys.
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function projectRows(array $rows, callable $resolve): array
    {
        if (!array_is_list($rows) || count($rows) < 1 || count($rows) > 5000) {
            throw new RuntimeException('BLOCKED V21: jumlah baris rapor tidak valid.');
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('student_id', $row) || !is_scalar($row['student_id'])) {
                throw new RuntimeException('BLOCKED V21: identitas siswa rapor kosong.');
            }
            foreach ($row as $key => $_) {
                if (!in_array($key, array_merge(self::ROW_KEYS, ['enrollment_id']), true)) {
                    throw new RuntimeException('BLOCKED V21: kolom rapor tidak diizinkan.');
                }
            }
            foreach (['rapor_sakit','rapor_izin','rapor_alpa'] as $field) {
                $n = $row[$field] ?? null;
                if ((!is_int($n) && !(is_string($n) && preg_match('/^[0-9]{1,4}$/D', $n)))
                    || (int) $n < 0 || (int) $n > 9999) {
                    throw new RuntimeException('BLOCKED V21: angka rapor tidak valid.');
                }
            }
            if (isset($row['updated_at']) && (!is_string($row['updated_at'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $row['updated_at']))) {
                throw new RuntimeException('BLOCKED V21: tanggal baris rapor tidak valid.');
            }
            $projected = $row;
            unset($projected['enrollment_id']);
            $projected['student_id'] = $resolve($row['student_id']);
            $key = (string) $projected['student_id'];
            if (isset($seen[$key])) throw new RuntimeException('BLOCKED V21: siswa rapor duplikat.');
            $seen[$key] = true;
            $result[] = $projected;
        }
        return $result;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function outbound(array $payload, string $sourceDevice): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) return $payload;
        $homeroom = $this->homeroom($payload);
        $this->assertWaliPc((int) $homeroom->homeroom_teacher_id, $sourceDevice);
        $roomId = (int) $homeroom->id;
        $school = app(SchoolIdentity::class)->schoolId();
        $payload['rows'] = self::projectRows($payload['rows'] ?? [], function ($localId) use ($roomId, $school): string {
            $id = filter_var($localId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !DB::table('enrollments')->where('homeroom_id', $roomId)->where('student_id', $id)->exists()) {
                throw new RuntimeException('BLOCKED V21: siswa bukan anggota rombel wali.');
            }
            $student = DB::table('students')->where('id', $id)->first(['nisn']);
            $nisn = trim((string) ($student->nisn ?? ''));
            if (!preg_match('/^[0-9]{10,20}$/D', $nisn)
                || DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
                throw new RuntimeException('BLOCKED V21: NISN lokal siswa tidak sah/duplikat.');
            }
            return app(OpaqueRouteRegistryV21::class)->alias($school, 'student', $nisn);
        });
        $payload['identity_protocol'] = 'v21-opaque';
        $payload['student_reference'] = 'school-issued-alias-v1';
        self::assertEnvelope(self::ENTITY, $payload);
        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function inbound(array $payload, string $sourceDevice): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) return $payload;
        self::assertEnvelope(self::ENTITY, $payload);
        $homeroom = $this->homeroom($payload);
        $this->assertWaliPc((int) $homeroom->homeroom_teacher_id, $sourceDevice);
        $room = (int) $homeroom->id;
        $payload['rows'] = self::projectRows($payload['rows'], function ($alias) use ($room): int {
            if (!is_string($alias)) throw new RuntimeException('BLOCKED V21: alias rapor invalid.');
            $nisn = app(CloudInboundStudentV21::class)->resolveInHomeroom($alias, $room);
            if (DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
                throw new RuntimeException('BLOCKED V21: identitas siswa penerima ambigu.');
            }
            $id = (int) (DB::table('students')->where('nisn', $nisn)->value('id') ?? 0);
            if ($id < 1 || !DB::table('enrollments')->where('homeroom_id', $room)->where('student_id', $id)->exists()) {
                throw new RuntimeException('BLOCKED V21: anggota rombel penerima tidak sah.');
            }
            return $id;
        });
        unset($payload['identity_protocol'], $payload['student_reference']);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function homeroom(array $payload): object
    {
        $id = filter_var($payload['homeroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ($payload['sync_id'] ?? '') !== 'rapor_recap::homeroom::'.$id) {
            throw new RuntimeException('BLOCKED V21: scope rombel rapor tidak valid.');
        }
        $homeroom = DB::table('homerooms')->where('id', $id)->first();
        if (!$homeroom || (int) ($homeroom->homeroom_teacher_id ?? 0) < 1) {
            throw new RuntimeException('BLOCKED V21: wali kelas rombel tidak tersedia.');
        }
        return $homeroom;
    }

    private function assertWaliPc(int $teacherId, string $sourceDevice): void
    {
        $teacher = Teacher::find($teacherId);
        if (!$teacher || $sourceDevice === '') throw new RuntimeException('BLOCKED V21: wali kelas/pengirim tidak ditemukan.');
        $school = app(SchoolIdentity::class)->schoolId();
        CloudOutboundPrivacyGuardV21::assertDevice($school, $sourceDevice, 'teacher');
        if (!str_ends_with($sourceDevice, '-PC')) throw new RuntimeException('BLOCKED V21: rekap rapor wajib dari PC wali kelas.');
        $logins = array_filter(array_unique(array_map('strval', [
            $teacher->nip ?? null, $teacher->nik ?? null, $teacher->nuptk ?? null,
            $teacher->login_id ?? null,
            $teacher->user_id ? User::find((int) $teacher->user_id)?->login_id : null,
        ])));
        foreach ($logins as $login) {
            try {
                $expected = app(OpaqueRouteRegistryV21::class)->deviceId($school, 'teacher', $login, 'PC');
                if (hash_equals($expected, $sourceDevice)) return;
            } catch (RuntimeException) {
                // other credentials may match; never accept fallback/guesses
            }
        }
        throw new RuntimeException('BLOCKED V21: perangkat bukan PC wali kelas sah.');
    }
}
