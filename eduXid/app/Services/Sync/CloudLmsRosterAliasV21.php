<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V21R27: copy-only roster projection for course and attendance payloads.
 * Local SQLite still uses numeric student IDs and real NISN; neither the
 * original roster NISN nor a school alias-generator key is sent to Cloud.
 */
final class CloudLmsRosterAliasV21
{
    public static function supports(string $entity): bool
    {
        return in_array($entity, ['lms_course', 'attendance_session'], true);
    }

    public function outbound(string $entity, array $payload): array
    {
        if (!OpaqueRouteRegistryV21::enabled() || !self::supports($entity)) return $payload;
        if (!isset($payload['students'])) return $payload; // tombstone or sparse update
        if (!is_array($payload['students']) || !array_is_list($payload['students'])
            || count($payload['students']) > 12000) {
            throw new RuntimeException('BLOCKED V21: roster LMS tidak valid.');
        }
        $homeroom = (int) ($payload['homeroom_id'] ?? $payload['target_homeroom_id'] ?? 0);
        if ($homeroom <= 0) throw new RuntimeException('BLOCKED V21: homeroom_id LMS wajib.');
        $ta = (int) ($payload['teaching_assignment_id'] ?? 0);
        if ($ta > 0 && !DB::table('teaching_assignments')->where('id', $ta)->where('homeroom_id', $homeroom)->exists()) {
            throw new RuntimeException('BLOCKED V21: scope roster tidak sesuai penugasan.');
        }
        $school = app(SchoolIdentity::class)->schoolId();
        $registry = app(OpaqueRouteRegistryV21::class);
        $rows = [];
        foreach ($payload['students'] as $student) {
            if (!is_array($student)) throw new RuntimeException('BLOCKED V21: format siswa roster tidak valid.');
            $id = filter_var(($entity === 'lms_course' ? ($student['id'] ?? null) : ($student['student_id'] ?? null)),
                FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new RuntimeException('BLOCKED V21: ID siswa roster lokal tidak sah.');
            if (!DB::table('enrollments')->where('homeroom_id', $homeroom)->where('student_id', $id)->exists()) {
                throw new RuntimeException('BLOCKED V21: siswa tidak terdaftar pada rombel tujuan.');
            }
            $nisn = trim((string) DB::table('students')->where('id', $id)->value('nisn'));
            if ($nisn === '' || DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
                throw new RuntimeException('BLOCKED V21: NISN roster hilang atau tidak unik.');
            }
            if (isset($student['nisn']) && (string) $student['nisn'] !== $nisn) {
                throw new RuntimeException('BLOCKED V21: NISN roster berbeda dari database lokal.');
            }
            $alias = $registry->alias($school, 'student', $nisn);
            if (isset($student['student_login_id']) && $student['student_login_id'] !== $alias) {
                throw new RuntimeException('BLOCKED V21: alias roster berbeda dari registry lokal.');
            }
            unset($student['nisn'], $student['student_nisn'], $student['student_nik']);
            $student['student_login_id'] = $alias;
            $rows[] = $student;
        }
        $payload['students'] = $rows;
        CloudOutboundPrivacyGuardV21::assertPayload($payload, $school);
        return $payload;
    }
}
