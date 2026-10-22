<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arena grade approval: only a Cloud-validated teacher request, never a student
 * submission, can reach this boundary. Writes once to the existing score writer.
 * Any ambiguous identity or occupied grade slot stops the ACK.
 */
final class ArenaGradeCommitService
{
    public function __construct(private readonly DailyAssessmentWriter $writer, private readonly SyncClient $cloud) {}

    /** @return array{saved:bool,receipt_sent:bool} */
    public function apply(array $event, string $deviceId, string $schoolId): array
    {
        $p = $event['payload'] ?? null;
        if (!is_array($p) || ($event['entity_type'] ?? null) !== 'arena_grade_request'
            || ($event['action'] ?? '') !== 'upsert'
            || ($event['entity_id'] ?? '') !== ($p['sync_id'] ?? null)
            || !str_ends_with($deviceId, '-PC')
            || !str_ends_with((string) ($event['source_device_id'] ?? ''), '-HP')
            || !Schema::hasTable('arena_grade_commits')) {
            throw new \RuntimeException('BLOCKED ARENA: permintaan nilai atau migrasi tidak valid.');
        }
        $requestId = (string) ($p['sync_id'] ?? '');
        $packageId = (string) ($p['package_id'] ?? '');
        $studentLogin = (string) ($p['student_id'] ?? '');
        $taId = (int) ($p['teaching_assignment_id'] ?? 0);
        $homeroomId = (int) ($p['homeroom_id'] ?? 0);
        $examId = (int) ($p['source_exam_id'] ?? 0);
        $score = $p['score'] ?? null;
        $date = (string) ($p['grade_date'] ?? '');
        $sourceHash = (string) ($p['source_sha256'] ?? '');
        if (!preg_match('/^arena-grade-[0-9a-f]{40}$/', $requestId)
            || !preg_match('/^arena-pkg-[0-9a-f-]{36}$/', $packageId)
            || !preg_match('/^(?:[0-9]{10}|s_[a-z2-7]{26})$/D', $studentLogin)
            || $taId <= 0 || $homeroomId <= 0 || $examId <= 0
            || !is_int($score) || $score < 0 || $score > 100
            || !preg_match('/^[0-9a-f]{64}$/', $sourceHash)
            || ($p['score_type'] ?? '') !== 'formatif'
            || ($p['game_type'] ?? '') !== 'quiz'
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \RuntimeException('BLOCKED ARENA: nilai, sumber, atau identitas permintaan tidak valid.');
        }
        $checked = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$checked || $checked->format('Y-m-d') !== $date) {
            throw new \RuntimeException('BLOCKED ARENA: tanggal penilaian tidak valid.');
        }
        if (($event['event_id'] ?? '') !== 'arena-grade-event-'.substr(hash('sha256', $schoolId.'|'.$packageId.'|'.$studentLogin.'|'.$date), 0, 32)) {
            throw new \RuntimeException('BLOCKED ARENA: bukti persetujuan guru tidak cocok.');
        }
        $ta = DB::table('teaching_assignments')->where('id', $taId)->first();
        $exam = DB::table('exams')->where('id', $examId)->first();
        if (!$ta || !$exam || (int) $ta->homeroom_id !== $homeroomId
            || (int) $ta->teacher_id !== (int) $exam->teacher_id
            || (int) $ta->subject_id !== (int) ($exam->subject_id ?? 0)) {
            throw new \RuntimeException('BLOCKED ARENA: paket ujian tidak dimiliki guru dan mata pelajaran yang sesuai.');
        }
        $localLogin = OpaqueRouteRegistryV21::enabled()
            ? app(CloudInboundStudentV21::class)->resolveInHomeroom($studentLogin, $homeroomId)
            : $studentLogin;
        $students = DB::table('students')->where('nisn', $localLogin)->limit(2)->pluck('id');
        if ($students->count() !== 1) {
            throw new \RuntimeException('BLOCKED ARENA: NISN tidak ditemukan/ambigu (fallback nama dilarang).');
        }
        $studentId = (int) $students->first();
        if (!DB::table('enrollments')->where('student_id', $studentId)->where('homeroom_id', $homeroomId)->exists()) {
            throw new \RuntimeException('BLOCKED ARENA: siswa bukan anggota rombel yang dinilai.');
        }
        $expectedReceiptId = 'arena-receipt-'.$requestId;
        $receiptStatus = 'committed';
        try {
        DB::transaction(function () use ($requestId, $packageId, $studentId, $taId, $date, $score, $sourceHash) {
            $prior = DB::table('arena_grade_commits')->where('request_sync_id', $requestId)->lockForUpdate()->first();
            $byPackage = DB::table('arena_grade_commits')->where('package_id', $packageId)
                ->where('student_id', $studentId)->lockForUpdate()->first();
            if ($byPackage && (!$prior || (int) $byPackage->id !== (int) $prior->id)) {
                throw new \RuntimeException('BLOCKED ARENA: paket dan siswa ini sudah pernah dikirim ke Buku Nilai.');
            }
            $slot = DB::table('assessment_scores')->where('student_id', $studentId)
                ->where('teaching_assignment_id', $taId)->where('tanggal', $date)
                ->lockForUpdate()->first();
            if ($prior) {
                if ((int) $prior->score !== $score || $prior->grade_date !== $date
                    || (string) $prior->source_sha256 !== $sourceHash || !$slot
                    || (int) $slot->formatif !== $score || $slot->formatif === null) {
                    throw new \RuntimeException('BLOCKED ARENA: nilai awal berubah; perlu pemeriksaan manual.');
                }
                return;
            }
            // Never overwrite a teacher-entered formative mark or another game on the same date.
            if ($slot && $slot->formatif !== null) {
                throw new \RuntimeException('BLOCKED ARENA: tanggal sudah berisi nilai formatif. Pilih tanggal lain sebelum mengirim.');
            }
            $written = $this->writer->writeScore($studentId, $taId, $date, 'formatif', $score, 'Arena Belajar: '.substr($packageId, 0, 70));
            if (($written['saved'] ?? false) !== true) {
                throw new \RuntimeException('BLOCKED ARENA: penulis Buku Nilai belum mengonfirmasi penyimpanan.');
            }
            DB::table('arena_grade_commits')->insert([
                'request_sync_id' => $requestId, 'package_id' => $packageId,
                'student_id' => $studentId, 'teaching_assignment_id' => $taId,
                'grade_date' => $date, 'game_type' => 'quiz', 'score' => $score,
                'source_sha256' => $sourceHash, 'receipt_confirmed' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        } catch (\RuntimeException $e) {
            // Occupied formative slot: explicit, durable negative receipt so the
            // teacher can choose another date without overwriting any mark.
            if ($e->getMessage() !== 'BLOCKED ARENA: tanggal sudah berisi nilai formatif. Pilih tanggal lain sebelum mengirim.') {
                throw $e;
            }
            $receiptStatus = 'rejected';
        }
        $receipt = [
            'sync_id' => $expectedReceiptId,
            'request_sync_id' => $requestId,
            'package_id' => $packageId,
            'teaching_assignment_id' => $taId,
            'homeroom_id' => $homeroomId,
            'score' => $score,
            'status' => $receiptStatus,
            'reason' => $receiptStatus === 'rejected' ? 'GRADE_DATE_OCCUPIED' : null,
            'sync_version' => max(1, (int) ($event['version'] ?? 1)),
        ];
        $response = $this->cloud->push([
            'school_id' => $schoolId,
            'source_device_id' => $deviceId,
            'event_id' => $expectedReceiptId,
            'entity_type' => 'arena_grade_receipt',
            'entity_id' => $expectedReceiptId,
            'action' => 'upsert',
            'version' => $receipt['sync_version'],
            'payload' => $receipt,
        ]);
        $rows = $response['data']['results'] ?? [];
        $state = (string) ($rows[0]['status'] ?? '');
        if (!in_array($state, ['accepted', 'duplicate'], true)) {
            throw new \RuntimeException('BLOCKED ARENA: Cloud belum menerima bukti masuk Buku Nilai.');
        }
        if ($receiptStatus === 'committed') {
            DB::table('arena_grade_commits')->where('request_sync_id', $requestId)->update([
                'receipt_confirmed' => true, 'updated_at' => now(),
            ]);
        }
        return ['saved' => $receiptStatus === 'committed', 'receipt_sent' => true];
    }
}
