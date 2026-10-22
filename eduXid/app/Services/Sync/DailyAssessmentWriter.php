<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * [F1 / P0-2 / D3 / D4 / D10] PENULIS TUNGGAL NILAI HARIAN
 *
 * Semua penulis non-absensi (skor CBT, skor tugas web, grade dari HP)
 * WAJIB lewat sini. Semantik merge per-field (D3):
 *   - Baris ADA   → hanya kolom nilai yang ditulis; d1..d8 & materi
 *                   TIDAK disentuh (materi = milik absensi pertemuan;
 *                   sikap = milik guru).
 *   - Baris BARU  → dibuat lengkap dengan default sikap 3 + label
 *                   materi konteks (CBT/tugas).
 * Versi baris = VersionService::above(sync_version lokal) — menang
 * LWW atas dirinya sendiri walau jam laptop mundur.
 */
class DailyAssessmentWriter
{
    public const VALID_SCORE_TYPES = ['diagnostik', 'formatif', 'sumatif'];
    public const SIKAP_DEFAULT = 3;

    /** Skor mentah tugas tetap di submission; Buku Nilai memakai skala 0–100. */
    public function assignmentScore($score, $maxScore): ?int
    {
        if ($score === null || $score === '') return null;
        $maximum = $maxScore === null ? 100.0 : (float) $maxScore;
        if (!is_numeric($score) || !is_finite((float) $score) || (float) $score < 0
            || $maximum < 0 || (float) $score > $maximum) {
            throw new \InvalidArgumentException('Skor tugas di luar rentang maksimum.');
        }
        return $maximum > 0 ? (int) round((float) $score * 100 / $maximum) : 0;
    }

    /**
     * [D10] NISN → students.id. Fallback by-nama DIPERTAHANKAN
     * dengan log KERAS (nilai = data rapor).
     */
    public function resolveStudentId(?string $loginId): ?int
    {
        $loginId = trim((string) $loginId);

        if ($loginId === '') {
            return null;
        }

        $studentIds = DB::table('students')->where('nisn', $loginId)->limit(2)->pluck('id');

        if ($studentIds->count() > 1) {
            throw new \RuntimeException('NISN siswa ambigu; perbaiki master sebelum menyimpan nilai.');
        }

        $studentId = $studentIds->first();

        if ($studentId) {
            return (int) $studentId;
        }

        $userName = DB::table('users')->where('login_id', $loginId)->value('name');

        if ($userName) {
            $studentIds = DB::table('students')->where('nama', $userName)->limit(2)->pluck('id');

            if ($studentIds->count() > 1) {
                throw new \RuntimeException('Nama siswa ambigu pada fallback; sinkronkan NISN terlebih dahulu.');
            }

            $studentId = $studentIds->first();

            if ($studentId) {
                Log::warning('[NILAI-HARIAN] Resolusi siswa via FALLBACK NAMA (NISN tidak cocok) — verifikasi data!', [
                    'login_id' => $loginId,
                    'nama_dipakai' => $userName,
                    'student_id' => (int) $studentId,
                ]);

                return (int) $studentId;
            }
        }

        return null;
    }

    /** TA exact-match guru+mapel+rombel (id terbaru menang). */
    public function resolveTaId(int $teacherId, $subjectId, $homeroomId): ?int
    {
        if (!$teacherId || !$subjectId || !$homeroomId) {
            return null;
        }

        $taId = DB::table('teaching_assignments')
            ->where('teacher_id', $teacherId)
            ->where('subject_id', (int) $subjectId)
            ->where('homeroom_id', (int) $homeroomId)
            ->orderByDesc('id')
            ->value('id');

        return $taId ? (int) $taId : null;
    }

    /**
     * Tulis skor ke buku nilai harian (merge per-field).
     * Return [saved => bool, tanggal => ?string, reason => string].
     */
    public function writeScore(
        int $studentId,
        int $taId,
        string $tanggal,
        string $scoreType,
        $score,
        ?string $insertMateri = null,
        int $minimumVersion = 1
    ): array {
        $this->assertDate($tanggal);

        $ta = DB::table('teaching_assignments')->where('id', $taId)->first();
        $teacherId = (int) ($ta->teacher_id ?? 0);
        if ($studentId <= 0 || $teacherId <= 0
            || !DB::table('students')->where('id', $studentId)->exists()
            || !DB::table('enrollments')->where('student_id', $studentId)
                ->where('homeroom_id', $ta->homeroom_id)->exists()) {
            return ['saved' => false, 'tanggal' => $tanggal, 'reason' => 'Siswa/penugasan tidak valid.'];
        }

        if (!in_array($scoreType, self::VALID_SCORE_TYPES, true)) {
            $scoreType = 'sumatif';
        }

        $write = function (bool $mustExist = false) use (
            $studentId, $taId, $tanggal, $scoreType, $score,
            $insertMateri, $minimumVersion, $teacherId
        ): array {
            $existing = DB::table('assessment_scores')
                ->where('student_id', $studentId)
                ->where('teaching_assignment_id', $taId)
                ->where('tanggal', $tanggal)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $values = $this->stampVersion('assessment_scores', [
                    $scoreType => $score,
                    'updated_at' => now(),
                ], VersionService::above((int) ($existing->sync_version ?? 0), $minimumVersion));

                DB::table('assessment_scores')
                    ->where('id', $existing->id)
                    ->update($values);

                WorkProductPushService::markDirtyOrFail($teacherId);
                return ['saved' => true, 'tanggal' => $tanggal, 'reason' => ''];
            }

            if ($mustExist) {
                throw new \RuntimeException('Baris pemenang unique race tidak ditemukan; nilai belum tersimpan.');
            }

            DB::table('assessment_scores')->insert($this->stampVersion('assessment_scores', [
                'student_id' => $studentId,
                'teaching_assignment_id' => $taId,
                'tanggal' => $tanggal,
                'materi' => $insertMateri,
                'diagnostik' => $scoreType === 'diagnostik' ? $score : null,
                'formatif' => $scoreType === 'formatif' ? $score : null,
                'sumatif' => $scoreType === 'sumatif' ? $score : null,
                'd1' => self::SIKAP_DEFAULT,
                'd2' => self::SIKAP_DEFAULT,
                'd3' => self::SIKAP_DEFAULT,
                'd4' => self::SIKAP_DEFAULT,
                'd5' => self::SIKAP_DEFAULT,
                'd6' => self::SIKAP_DEFAULT,
                'd7' => self::SIKAP_DEFAULT,
                'd8' => self::SIKAP_DEFAULT,
                'created_at' => now(),
                'updated_at' => now(),
            ], VersionService::next($minimumVersion)));

            WorkProductPushService::markDirtyOrFail($teacherId);
            return ['saved' => true, 'tanggal' => $tanggal, 'reason' => ''];
        };

        try {
            return DB::transaction(fn () => $write(), 3);
        } catch (Throwable $e) {
            // Unique race harus benar-benar menggabungkan skor ke baris
            // pemenang. Keberadaan baris saja bukan bukti skor tersimpan.
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                return DB::transaction(fn () => $write(true), 3);
            }

            throw $e;
        }
    }

    /**
     * Kehadiran = fakta. INSERT-if-missing; status manual guru
     * (Sakit/Izin/Alpa) tidak pernah ditimpa.
     */
    public function ensureAttendance(int $studentId, int $taId, string $tanggal): void
    {
        $this->assertDate($tanggal);
        $existing = DB::table('attendances')
            ->where('student_id', $studentId)
            ->where('teaching_assignment_id', $taId)
            ->where('tanggal', $tanggal)
            ->first();

        if ($existing && !in_array($existing->status, [null, '', 'Hadir'], true)) {
            return;
        }

        if ($existing && $existing->status === 'Hadir') {
            return; // Retry tidak membuat versi baru untuk data yang sama.
        }

        try {
            if ($existing) {
                DB::table('attendances')->where('id', $existing->id)->update($this->stampVersion('attendances', [
                    'status' => 'Hadir',
                    'updated_at' => now(),
                ], VersionService::above((int) ($existing->sync_version ?? 0))));

                return;
            }

            DB::table('attendances')->insert($this->stampVersion('attendances', [
                'student_id' => $studentId,
                'teaching_assignment_id' => $taId,
                'tanggal' => $tanggal,
                'status' => 'Hadir',
                'created_at' => now(),
                'updated_at' => now(),
            ], VersionService::next()));
        } catch (Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'unique')
                || !DB::table('attendances')->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $taId)->where('tanggal', $tanggal)->exists()) {
                throw $e;
            }
        }
    }

    private function stampVersion(string $table, array $values, int $version): array
    {
        if (Schema::hasColumn($table, 'sync_version')) {
            $values['sync_version'] = $version;
        }
        return $values;
    }

    private function assertDate(string $tanggal): void
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $tanggal);
            if (!$date || $date->format('Y-m-d') !== $tanggal) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new \InvalidArgumentException('Tanggal Buku Nilai harus tanggal kalender Y-m-d yang valid.');
        }
    }
}
