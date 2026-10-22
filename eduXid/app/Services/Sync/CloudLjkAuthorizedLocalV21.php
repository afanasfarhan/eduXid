<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V21R11 — STAGING ONLY. Read-only local authorization for photographed LJK.
 *
 * This class NEVER publishes a grade, creates an outbox, acknowledges an event,
 * exports a roster, or sends NISN/teacher login to Cloud. It binds the physical
 * sheet to a UNIQUE school student and the teacher's actual exam/TA scope.
 * The camera→LAN HTTPS identity-proof exchange is NOT implemented by this class.
 */
final class CloudLjkAuthorizedLocalV21
{
    /**
     * @return array{student_id:int,student_alias:string,teacher_device_pc:string,teacher_device_hp:string,teaching_assignment_id:int,homeroom_id:int,subject_id:int,session_ref:string,local_only:true}
     */
    public function verifyManualScan(
        string $recognizedNisn,
        string $sessionId,
        int $teacherId,
        string $sourceHp,
        string $receiverPc
    ): array {
        $this->assertStaging();
        if (!preg_match('/^manual::[A-Za-z0-9._:-]{1,180}$/D', $sessionId) || $teacherId < 1) {
            throw new RuntimeException('BLOCKED V21: sesi manual LJK/guru tidak valid.');
        }
        $session = DB::table('ljk_manual_sessions')
            ->where('session_id', $sessionId)->where('teacher_id', $teacherId)
            ->whereNull('deleted_at')->first();
        if (!$session) throw new RuntimeException('BLOCKED V21: sesi manual tidak dimiliki guru atau sudah dihapus.');
        return $this->verifyScopedScan(
            $recognizedNisn, $teacherId, $sourceHp, $receiverPc,
            (int) ($session->teaching_assignment_id ?? 0),
            (int) ($session->homeroom_id ?? 0),
            (int) ($session->subject_id ?? 0),
            $sessionId
        );
    }

    /**
     * The caller supplies the exact local teaching assignment; never infer one
     * by subject alone where two classes/teachers can share the same subject.
     *
     * @return array{student_id:int,student_alias:string,teacher_device_pc:string,teacher_device_hp:string,teaching_assignment_id:int,homeroom_id:int,subject_id:int,session_ref:string,local_only:true}
     */
    public function verifyExamScan(
        string $recognizedNisn,
        int $examId,
        int $teachingAssignmentId,
        int $homeroomId,
        int $teacherId,
        string $sourceHp,
        string $receiverPc
    ): array {
        $this->assertStaging();
        if ($examId < 1 || $teachingAssignmentId < 1 || $homeroomId < 1 || $teacherId < 1) {
            throw new RuntimeException('BLOCKED V21: identitas paket ujian/penugasan/rombel tidak valid.');
        }
        $exam = DB::table('exams')->where('id', $examId)->where('teacher_id', $teacherId)->first();
        if (!$exam || (int) ($exam->subject_id ?? 0) < 1) {
            throw new RuntimeException('BLOCKED V21: paket ujian bukan milik guru atau mapel tidak ada.');
        }
        if (!empty($exam->homeroom_id) && (int) $exam->homeroom_id !== $homeroomId) {
            throw new RuntimeException('BLOCKED V21: rombel paket ujian tidak sesuai.');
        }
        return $this->verifyScopedScan(
            $recognizedNisn, $teacherId, $sourceHp, $receiverPc,
            $teachingAssignmentId, $homeroomId, (int) $exam->subject_id,
            'exam::'.$examId
        );
    }

    private function assertStaging(): void
    {
        if (!OpaqueRouteRegistryV21::enabled()) {
            throw new RuntimeException('BLOCKED V21: bukti identitas LJK khusus staging anonim.');
        }
    }

    /**
     * @return array{student_id:int,student_alias:string,teacher_device_pc:string,teacher_device_hp:string,teaching_assignment_id:int,homeroom_id:int,subject_id:int,session_ref:string,local_only:true}
     */
    private function verifyScopedScan(
        string $recognizedNisn,
        int $teacherId,
        string $sourceHp,
        string $receiverPc,
        int $taId,
        int $roomId,
        int $subjectId,
        string $sessionRef
    ): array {
        if ($taId < 1 || $roomId < 1 || $subjectId < 1 || trim($sourceHp) === '' || trim($receiverPc) === '') {
            throw new RuntimeException('BLOCKED V21: scope pemindaian atau pasangan PC/HP kosong.');
        }
        if (!DB::table('teaching_assignments')->where('id', $taId)
            ->where('teacher_id', $teacherId)->where('homeroom_id', $roomId)
            ->where('subject_id', $subjectId)->exists()) {
            throw new RuntimeException('BLOCKED V21: guru tidak memiliki penugasan tepat untuk paket dan rombel LJK.');
        }
        $teacher = DB::table('teachers')->where('id', $teacherId)->first();
        if (!$teacher) throw new RuntimeException('BLOCKED V21: akun guru tidak ada.');
        $school = app(SchoolIdentity::class)->schoolId();
        $registry = app(OpaqueRouteRegistryV21::class);
        $logins = array_values(array_unique(array_filter(array_map('trim', [
            (string) ($teacher->nip ?? ''), (string) ($teacher->nik ?? ''),
            (string) ($teacher->nuptk ?? ''), (string) ($teacher->login_id ?? ''),
        ]))));
        $valid = false;
        foreach ($logins as $login) {
            try {
                $expectedHp = $registry->deviceId($school, 'teacher', $login, 'HP');
                $expectedPc = $registry->deviceId($school, 'teacher', $login, 'PC');
                if (hash_equals($expectedHp, $sourceHp) && hash_equals($expectedPc, $receiverPc)) {
                    $valid = true;
                    break;
                }
            } catch (RuntimeException) {
                // A different local login identifier is not a fallback proof.
            }
        }
        if (!$valid || !str_ends_with($sourceHp, '-HP') || !str_ends_with($receiverPc, '-PC')) {
            throw new RuntimeException('BLOCKED V21: pasangan perangkat bukan HP/PC guru pemilik yang sama.');
        }
        $student = app(CloudLjkLocalReconciliationV21::class)->resolveLocalScan($recognizedNisn, $roomId);
        // Deliberately omit NISN, names, login_id, scanner photograph and raw answers.
        return [
            'student_id' => $student['student_id'],
            'student_alias' => $student['student_alias'],
            'teacher_device_pc' => $receiverPc,
            'teacher_device_hp' => $sourceHp,
            'teaching_assignment_id' => $taId,
            'homeroom_id' => $roomId,
            'subject_id' => $subjectId,
            'session_ref' => $sessionRef,
            'local_only' => true,
        ];
    }
}
