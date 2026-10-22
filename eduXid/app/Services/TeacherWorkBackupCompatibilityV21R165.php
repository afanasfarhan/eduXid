<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kontrak backup_guru_*.json lama → GTK baru (format JSON V1).
 * Tidak menulis DB. Fail-closed bila data akan terbuang atau salah pemilik.
 */
final class TeacherWorkBackupCompatibilityV21R165
{
    private const DATA_SECTIONS = [
        'surat_records', 'assessment_scores', 'final_grades', 'attitude_logs',
        'bk_logs', 'question_banks', 'exams', 'exam_questions',
    ];

    public function inspectForCurrentUser(array $payload, string $loginId, ?int $teacherId, string $role): array
    {
        // Ambil referensi yang benar-benar dipakai backup; dibatasi chunk agar SQLite tidak terkena batas placeholder.
        $assignmentIds = [];
        $studentIds = [];
        foreach (['assessment_scores', 'final_grades', 'attitude_logs'] as $section) {
            foreach (($payload[$section] ?? []) as $row) {
                if (is_array($row)) {
                    $assignmentIds[] = (int) ($row['teaching_assignment_id'] ?? 0);
                    $studentIds[] = (int) ($row['student_id'] ?? 0);
                }
            }
        }
        foreach (($payload['bk_logs'] ?? []) as $row) {
            if (is_array($row) && isset($row['student_id'])) {
                $studentIds[] = (int) $row['student_id'];
            }
        }
        $assignments = [];
        foreach (array_chunk(array_unique($assignmentIds), 250) as $group) {
            foreach (DB::table('teaching_assignments')->whereIn('id', $group)->get(['id','teacher_id']) as $record) {
                $assignments[(int) $record->id] = (int) $record->teacher_id;
            }
        }
        $students = [];
        foreach (array_chunk(array_unique($studentIds), 250) as $group) {
            foreach (DB::table('students')->whereIn('id', $group)->pluck('id') as $id) {
                $students[(int) $id] = true;
            }
        }
        return $this->inspectPure($payload, $loginId, $teacherId, $role, $assignments, $students);
    }

    /** Used for deterministic contract tests with synthetic IDs. */
    public function inspectPure(array $payload, string $loginId, ?int $teacherId, string $role, array $assignments, array $students): array
    {
        if (($payload['metadata']['tipe_file'] ?? '') !== 'backup_guru') {
            throw new RuntimeException('File bukan backup_guru JSON lama yang didukung.');
        }
        $owner = trim((string) ($payload['metadata']['login_id'] ?? ''));
        if ($owner === '' || $loginId === '' || !hash_equals($loginId, $owner)) {
            throw new RuntimeException('Restore ditolak: identitas login pemilik backup tidak cocok.');
        }
        $role = strtolower(trim($role));
        $sourceRole = strtolower(trim((string) ($payload['metadata']['role'] ?? '')));
        if (($role === 'tendik') !== ($sourceRole === 'tendik')) {
            throw new RuntimeException('Restore ditolak: tipe akun guru/tendik berbeda.');
        }
        if ($teacherId === null && $role !== 'tendik') {
            throw new RuntimeException('Guru tujuan belum terhubung dengan penugasan/identitas guru.');
        }
        $sourceTeacherId = (int) ($payload['metadata']['guru_id'] ?? 0);
        $hasIdBoundWork = !empty($payload['assessment_scores']) || !empty($payload['final_grades'])
            || !empty($payload['attitude_logs']) || !empty($payload['bk_logs']);
        if ($hasIdBoundWork && $role !== 'tendik' && ($sourceTeacherId <= 0 || $sourceTeacherId !== $teacherId)) {
            throw new RuntimeException('Restore ditolak: guru_id berubah antara backup lama dan database baru. Pemetaan resmi diperlukan.');
        }
        foreach ($payload as $name => $rows) {
            if ($name !== 'metadata' && !in_array($name, self::DATA_SECTIONS, true) && is_array($rows) && $rows !== []) {
                throw new RuntimeException('Restore ditolak: bagian data tidak dikenali oleh versi baru: '.$name);
            }
        }
        $report = [];
        foreach (self::DATA_SECTIONS as $name) {
            $rows = $payload[$name] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new RuntimeException('Format data backup rusak: '.$name);
            }
            foreach ($rows as $row) {
                if (!is_array($row)) throw new RuntimeException('Isi backup bukan objek data pada '.$name);
            }
            $report[$name] = count($rows);
        }
        foreach (['assessment_scores', 'final_grades', 'attitude_logs'] as $name) {
            foreach ($payload[$name] ?? [] as $r) {
                $aid = (int) ($r['teaching_assignment_id'] ?? 0);
                $sid = (int) ($r['student_id'] ?? 0);
                if ($aid <= 0 || ($assignments[$aid] ?? 0) !== $teacherId) {
                    throw new RuntimeException('Restore ditolak: ID penugasan lama tidak cocok dengan guru pada database baru. Pemetaan ulang resmi diperlukan.');
                }
                if ($sid <= 0 || !isset($students[$sid])) {
                    throw new RuntimeException('Restore ditolak: ID siswa backup tidak ditemukan pada database baru. Pemetaan NISN resmi diperlukan.');
                }
            }
        }
        foreach ($payload['bk_logs'] ?? [] as $r) {
            if (isset($r['student_id']) && (!isset($students[(int) $r['student_id']]))) {
                throw new RuntimeException('Restore ditolak: siswa catatan BK tidak ada di tujuan.');
            }
        }
        $examIds = array_fill_keys(array_map(static fn($r) => (int) ($r['id'] ?? 0), $payload['exams'] ?? []), true);
        $bankIds = array_fill_keys(array_map(static fn($r) => (int) ($r['id'] ?? 0), $payload['question_banks'] ?? []), true);
        foreach ($payload['exam_questions'] ?? [] as $r) {
            if (!isset($examIds[(int) ($r['exam_id'] ?? 0)]) || !isset($bankIds[(int) ($r['question_bank_id'] ?? 0)])) {
                throw new RuntimeException('Restore ditolak: soal ujian mengacu ke soal/paket yang tidak ada dalam backup.');
            }
        }
        return ['compatible'=>true, 'sections'=>$report, 'owner_verified'=>true];
    }
}
