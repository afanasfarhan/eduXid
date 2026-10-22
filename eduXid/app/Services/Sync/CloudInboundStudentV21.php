<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\LmsAssignment;
use App\Models\LmsAssignmentSubmission;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * V21R7 — STAGING ONLY. Convert a Cloud student alias to the *existing local*
 * NISN, only after verifying the school, assignment class and provisioned map.
 * Never accept raw NISN, name matching, or a numeric guess in strict mode.
 * There is deliberately no operation that writes remote aliases to students.
 */
final class CloudInboundStudentV21
{
    public static function enabled(): bool
    {
        return OpaqueRouteRegistryV21::enabled();
    }

    /**
     * A pure identity matcher, shared with standalone regression tests.
     * @param array<int,array{student_id:int,nisn:string,local_digest:string}> $candidates
     */
    public static function matchProvisioned(string $expectedDigest, array $candidates): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedDigest)) {
            throw new RuntimeException('BLOCKED V21: registry alias tidak valid.');
        }
        $matches = [];
        foreach ($candidates as $candidate) {
            $studentId = (int) ($candidate['student_id'] ?? 0);
            $nisn = trim((string) ($candidate['nisn'] ?? ''));
            $digest = (string) ($candidate['local_digest'] ?? '');
            if ($studentId <= 0 || !preg_match('/^[0-9]{10,20}$/D', $nisn)) continue;
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) && hash_equals($expectedDigest, $digest)) {
                $matches[$studentId] = $nisn;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('BLOCKED V21: alias siswa tidak cocok secara unik dengan siswa pada rombel penugasan.');
        }
        return (string) reset($matches);
    }

    public function resolveInHomeroom(string $alias, int $homeroomId): string
    {
        if ($homeroomId <= 0 || !OpaqueIdentityV21::isAlias($alias, 'student')) {
            throw new RuntimeException('BLOCKED V21: identitas siswa/rombel tidak sah.');
        }
        $school = app(SchoolIdentity::class)->schoolId();
        if (!Schema::hasTable('cloud_identity_alias_routes')) {
            throw new RuntimeException('BLOCKED V21: registry alias lokal belum tersedia.');
        }
        $registered = DB::table('cloud_identity_alias_routes')
            ->where('school_id', $school)->where('role', 'student')
            ->where('cloud_alias', $alias)->first(['local_digest']);
        if (!$registered) {
            throw new RuntimeException('BLOCKED V21: alias siswa belum terprovisioning di laptop.');
        }
        $rows = DB::table('students as s')
            ->join('enrollments as e', 'e.student_id', '=', 's.id')
            ->where('e.homeroom_id', $homeroomId)
            ->select('s.id', 's.nisn')->distinct()->limit(5001)->get();
        if ($rows->count() > 5000) {
            throw new RuntimeException('BLOCKED V21: data rombel melebihi ambang pemeriksaan aman.');
        }
        $candidates = [];
        foreach ($rows as $row) {
            $nisn = trim((string) ($row->nisn ?? ''));
            if (!preg_match('/^[0-9]{10,20}$/D', $nisn)) continue;
            $candidates[] = [
                'student_id' => (int) ($row->id ?? 0),
                'nisn' => $nisn,
                'local_digest' => OpaqueRouteRegistryV21::lookupDigest($school, 'student', $nisn),
            ];
        }
        return self::matchProvisioned((string) $registered->local_digest, $candidates);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function normalizeSubmission(array $payload, string $action, string $sourceDevice): array
    {
        if (!self::enabled()) return $payload;
        $school = app(SchoolIdentity::class)->schoolId();
        $alias = trim((string) ($payload['student_id'] ?? ''));
        if (!OpaqueIdentityV21::isAlias($alias, 'student')) {
            throw new RuntimeException('BLOCKED V21: submission inbound tidak menggunakan alias siswa.');
        }
        if ($sourceDevice !== $school . '-' . $alias . '-HP') {
            throw new RuntimeException('BLOCKED V21: asal submission tidak sesuai alias siswa.');
        }
        if (!empty($payload['student_nisn']) || !empty($payload['nisn']) || !empty($payload['student_nik'])) {
            throw new RuntimeException('BLOCKED V21: payload inbound membawa identitas pribadi.');
        }
        $assignmentSyncId = trim((string) ($payload['assignment_sync_id'] ?? ''));
        $assignment = $assignmentSyncId !== ''
            ? LmsAssignment::query()->with('topic.course')->where('sync_id', $assignmentSyncId)->first()
            : null;
        $homeroomId = (int) ($assignment?->topic?->course?->homeroom_id ?? 0);
        if ($homeroomId <= 0) {
            throw new RuntimeException('BLOCKED V21: assignment/rombel submission belum tersedia secara lokal.');
        }
        if (isset($payload['homeroom_id']) && (int) $payload['homeroom_id'] !== $homeroomId) {
            throw new RuntimeException('BLOCKED V21: rombel submission tidak cocok dengan penugasan.');
        }
        if (isset($payload['student_device_id']) && $payload['student_device_id'] !== $sourceDevice) {
            throw new RuntimeException('BLOCKED V21: target perangkat siswa berbeda dari asal submission.');
        }
        if (!in_array(strtolower($action), ['create','update','upsert','delete'], true)) {
            throw new RuntimeException('BLOCKED V21: operasi submission tidak dikenal.');
        }
        // A student PWA may submit answers, never an authoritative grade.
        if (array_key_exists('score', $payload) && $payload['score'] !== null
            || !empty($payload['teacher_feedback']) || !empty($payload['feedback'])
            || (int) ($payload['grade_version'] ?? 0) > 0
            || strtolower((string) ($payload['status'] ?? '')) === 'graded') {
            throw new RuntimeException('BLOCKED V21: perangkat siswa tidak boleh mengirim nilai guru.');
        }
        $nisn = $this->resolveInHomeroom($alias, $homeroomId);
        // Only the local apply COPY sees the NISN. The Cloud event and ledger
        // remain anonymous; no outgoing message is altered here.
        $payload['student_id'] = $nisn;
        unset($payload['student_nisn'], $payload['nisn'], $payload['student_nik']);
        if (isset($payload['student_device_id'])) {
            unset($payload['student_device_id']);
        }
        return $payload;
    }

    /**
     * Grade from teacher PWA, scoped to the original local submission's course.
     * Require a provisioned student alias and ensure it cannot select another
     * student than the existing submission owner.
     */
    public function localGradeNisn(array $payload, LmsAssignmentSubmission $submission, string $sourceDevice): string
    {
        if (!self::enabled()) return (string) ($payload['student_nisn'] ?? $payload['student_id'] ?? $submission->student_id);
        $school = app(SchoolIdentity::class)->schoolId();
        if (!preg_match('/^' . preg_quote($school, '/') . '-t_[a-z2-7]{26}-HP$/D', $sourceDevice)) {
            throw new RuntimeException('BLOCKED V21: sumber nilai bukan PWA guru anonim.');
        }
        if (!empty($payload['student_nisn']) || !empty($payload['nisn']) || !empty($payload['student_nik'])) {
            throw new RuntimeException('BLOCKED V21: nilai masuk membawa identitas pribadi.');
        }
        $alias = trim((string) ($payload['student_id'] ?? ''));
        $course = $submission->assignment?->topic?->course;
        $homeroom = (int) ($course?->homeroom_id ?? 0);
        $teacher = $course?->teacher_id ? Teacher::find((int) $course->teacher_id) : null;
        if (!$teacher) {
            throw new RuntimeException('BLOCKED V21: pemilik penugasan nilai belum ditemukan.');
        }
        $logins = array_filter(array_unique(array_map('strval', [
            $teacher->nip ?? null, $teacher->nik ?? null,
            $teacher->nuptk ?? null,
            $teacher->login_id ?? null,
            $teacher->user_id ? User::find((int) $teacher->user_id)?->login_id : null,
        ])));
        $matchedTeacher = false;
        foreach ($logins as $login) {
            try {
                $expected = app(OpaqueRouteRegistryV21::class)->deviceId($school, 'teacher', $login, 'HP');
                if (hash_equals($expected, $sourceDevice)) {
                    $matchedTeacher = true;
                    break;
                }
            } catch (RuntimeException) {
                // Candidate login was not the issued alias identity.
            }
        }
        if (!$matchedTeacher) {
            throw new RuntimeException('BLOCKED V21: perangkat pengirim nilai bukan guru pemilik penugasan.');
        }
        $nisn = $this->resolveInHomeroom($alias, $homeroom);
        if ((string) $submission->student_id !== $nisn) {
            throw new RuntimeException('BLOCKED V21: alias nilai tidak cocok dengan pemilik submission lokal.');
        }
        return $nisn;
    }
}
