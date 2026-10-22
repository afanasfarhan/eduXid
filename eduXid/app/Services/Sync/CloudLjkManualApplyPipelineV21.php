<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Services\Ljk\LjkStandaloneManualService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * V21R14 STAGING ONLY. No HTTP route, Cloud handler, or scheduler invokes this service.
 * Entrypoint is for a FUTURE separately authenticated PC transport adapter.
 * Caller MUST authenticate the inbound Cloud/PWA device and teacher session, ensure
 * source/target routing, and never pass a roster, local key or student ID supplied by HP.
 * All authority is re-resolved locally in this service and nonce ledger.
 */
final class CloudLjkManualApplyPipelineV21
{
    public function __construct(private DailyAssessmentWriter $writer, private CloudLjkNonceLedgerV21 $nonces) {}

    /** @param array<string,mixed> $sealed @param array<string,mixed> $answers @param array<string,mixed> $quality */
    public function apply(int $authenticatedTeacherId, string $authenticatedHp, string $receiverPc,
        array $sealed, array $answers, array $quality): array
    {
        if (!OpaqueRouteRegistryV21::enabled() || !Schema::hasTable('cloud_ljk_publications_v21r14')
            || !Schema::hasTable('cloud_ljk_nonces_v21') || !Schema::hasTable('ljk_manual_sessions')
            || !Schema::hasTable('ljk_scan_results') || !Schema::hasTable('assessment_scores')
            || !Schema::hasColumn('ljk_scan_results', 'manual_keys_json')
            || !Schema::hasColumn('ljk_scan_results', 'key_source')
            || !Schema::hasColumn('ljk_manual_sessions', 'deleted_at')) {
            throw new RuntimeException('BLOCKED V21R14: staging atau tabel penerbitan LJK belum siap.');
        }
        CloudLjkSealedProofV21::assertEnvelope($sealed, $answers);
        if ($authenticatedTeacherId < 1 || ($sealed['publish_requested'] ?? false) !== true
            || !hash_equals($authenticatedHp, (string) $sealed['source_hp'])
            || !hash_equals($receiverPc, (string) $sealed['target_pc'])) {
            throw new RuntimeException('BLOCKED V21R14: sumber/tujuan/publikasi LJK belum terotorisasi.');
        }
        $ref = (string) $sealed['session_ref'];
        $scanId = (string) $sealed['scan_id'];
        if (!preg_match('/^manual::[a-f0-9-]{36}$/Di', $ref)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $scanId)) {
            throw new RuntimeException('BLOCKED V21R14: hanya LJK manual dan scan UUID yang diizinkan.');
        }
        $school = CloudLjkPairVaultV21::assertOwner($authenticatedTeacherId, $authenticatedHp, $receiverPc);
        if (!hash_equals($school, (string) $sealed['school_id'])) {
            throw new RuntimeException('BLOCKED V21R14: sekolah tidak cocok.');
        }
        $session = $this->session($authenticatedTeacherId, $ref);
        $keys = json_decode((string) $session->keys_json, true);
        if (!is_array($keys) || count($keys) !== (int) $session->question_count
            || !hash_equals((string) $session->key_fingerprint, LjkStandaloneManualService::fingerprint($keys))) {
            throw new RuntimeException('BLOCKED V21R14: kunci LJK laptop berubah atau tidak sah.');
        }
        $renormalized = LjkStandaloneManualService::normalizeKeys($keys);
        if ($renormalized !== $keys) {
            throw new RuntimeException('BLOCKED V21R14: struktur kunci lokal tidak normal.');
        }
        $normalized = self::normalizedAnswers($answers, $keys);
        if (!hash_equals((string) $session->key_fingerprint, (string) ($quality['key_fingerprint'] ?? ''))) {
            throw new RuntimeException('BLOCKED V21R14: fingerprint kunci PWA tidak sesuai kunci laptop.');
        }
        $secret = app(CloudLjkPairVaultV21::class)->active($authenticatedTeacherId, $authenticatedHp, $receiverPc);
        CloudLjkScanQualityV21::verify($quality, $sealed, $secret);
        CloudLjkScanQualityV21::assertPublishable($quality);
        $roster = DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.homeroom_id', (int) $session->homeroom_id)->orderBy('e.student_id')
            ->get(['s.id as student_id', 's.nisn'])->map(fn ($r) => ['student_id' => (int) $r->student_id,
                'nisn' => (string) $r->nisn])->all();
        // No roster or student identity from the HP is trusted.
        if (count($roster) < 1 || count($roster) > 1000) throw new RuntimeException('BLOCKED V21R14: roster tidak valid.');
        $nonceHash = hash('sha256', $sealed['nonce']);
        $qualityHash = hash('sha256', $quality['tag']);
        $grade = LjkStandaloneManualService::grade($keys, $normalized);
        $result = $this->nonces->consume($authenticatedTeacherId, $sealed, $answers, $roster,
            function (int $studentId) use ($session, $ref, $scanId, $nonceHash, $qualityHash,
                $sealed, $quality, $grade, $normalized, $keys, $school, $authenticatedTeacherId, $authenticatedHp): void {
                // Re-read authoritative context INSIDE the transaction; never use client grade.
                $latest = $this->session($authenticatedTeacherId, $ref);
                if ((int) $latest->version !== (int) $session->version
                    || !hash_equals((string) $latest->key_fingerprint, (string) $session->key_fingerprint)) {
                    throw new RuntimeException('BLOCKED V21R14: sesi/kunci berubah sebelum penulisan.');
                }
                if (DB::table('ljk_scan_results')->where('exam_sync_id', $ref)
                    ->where('student_id', $studentId)->whereNotNull('published_at')->exists()
                    || DB::table('ljk_scan_results')->where('scan_id', $scanId)->exists()) {
                    throw new RuntimeException('BLOCKED V21R14: nilai/sidik pemindaian sudah ada pada jalur manual.');
                }
                $student = DB::table('students')->where('id', $studentId)->first(['id','nama','nisn']);
                if (!$student || !is_string($student->nisn) || !preg_match('/^[0-9]{10}$/D', $student->nisn)
                    || !DB::table('enrollments')->where('student_id', $studentId)
                        ->where('homeroom_id', $latest->homeroom_id)->exists()) {
                    throw new RuntimeException('BLOCKED V21R14: siswa/rombel tidak lagi valid.');
                }
                // Never silently overwrite a pre-existing daily score from another scoring pathway.
                $oldSlot = DB::table('assessment_scores')->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $latest->teaching_assignment_id)
                    ->where('tanggal', $latest->tanggal)->first();
                $scoreType = (string) $latest->jenis_penilaian;
                if ($oldSlot && $oldSlot->{$scoreType} !== null) {
                    throw new RuntimeException('BLOCKED V21R14: kolom nilai pada tanggal ini sudah terisi.');
                }
                // UNIQUE constraints enforce same student/session and same daily score slot even with a DIFFERENT nonce.
                DB::table('cloud_ljk_publications_v21r14')->insert([
                    'school_id' => $school, 'teacher_id' => $authenticatedTeacherId,
                    'session_ref' => $ref, 'student_id' => $studentId, 'scan_id' => $scanId,
                    'nonce_hash' => $nonceHash, 'answers_sha256' => $sealed['answers_sha256'],
                    'quality_tag_hash' => $qualityHash, 'key_fingerprint' => $latest->key_fingerprint,
                    'teaching_assignment_id' => (int) $latest->teaching_assignment_id,
                    'tanggal' => $latest->tanggal, 'score_type' => $latest->jenis_penilaian,
                    'score' => $grade['score'], 'correct_count' => $grade['correct_count'],
                    'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('ljk_scan_results')->insert([
                    'scan_id' => $scanId, 'exam_id' => null, 'exam_sync_id' => $ref,
                    'teaching_assignment_id' => (int) $latest->teaching_assignment_id,
                    'student_id' => $studentId, 'nisn' => $student->nisn,
                    'student_name' => (string) $student->nama, 'template_version' => 'LJK-A4-V3',
                    'source_type' => 'pwa', 'source_name' => 'V21R14-sealed',
                    'answers_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'question_count' => $grade['question_count'], 'correct_count' => $grade['correct_count'],
                    'score' => $grade['score'], 'confidence' => $quality['confidence_bp'] / 10000,
                    'status' => 'success', 'is_active' => true,
                    'key_source' => 'manual', 'manual_keys_json' => json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'source_device_id' => $authenticatedHp, 'scanned_at' => now(),
                    'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $written = $this->writer->writeScore($studentId, (int) $latest->teaching_assignment_id,
                    (string) $latest->tanggal, (string) $latest->jenis_penilaian, $grade['score'],
                    'Ujian LJK Manual: ' . (string) $latest->title);
                if (!($written['saved'] ?? false)) {
                    throw new RuntimeException('BLOCKED V21R14: Buku Nilai menolak penyimpanan.');
                }
                // All inserts, score update, nonce consumed are committed as ONE DB transaction by nonce ledger.
            });
        if ($result['already_applied']) {
            $existing = DB::table('cloud_ljk_publications_v21r14')
                ->where('school_id', $school)->where('teacher_id', $authenticatedTeacherId)
                ->where('session_ref', $ref)->where('scan_id', $scanId)->where('nonce_hash', $nonceHash)->first();
            if (!$existing || !hash_equals((string) $existing->quality_tag_hash, $qualityHash)
                || !hash_equals((string) $existing->answers_sha256, (string) $sealed['answers_sha256'])
                || !hash_equals((string) $existing->key_fingerprint, (string) $session->key_fingerprint)) {
                throw new RuntimeException('BLOCKED V21R14: replay tidak cocok dengan hasil penerbitan asli.');
            }
            return ['already_applied' => true, 'published' => false, 'receipt_hash' => $result['receipt_hash'],
                'scan_id' => $scanId, 'correct_count' => (int) $existing->correct_count,
                'score' => (float) $existing->score];
        }
        return ['already_applied' => false, 'published' => true, 'receipt_hash' => $result['receipt_hash'],
            'scan_id' => $scanId, 'correct_count' => $grade['correct_count'], 'score' => $grade['score']];
    }

    private function session(int $teacherId, string $ref): object
    {
        $session = DB::table('ljk_manual_sessions')->where('session_id', $ref)
            ->where('teacher_id', $teacherId)->whereNull('deleted_at')->first();
        if (!$session || !in_array($session->jenis_penilaian, DailyAssessmentWriter::VALID_SCORE_TYPES, true)) {
            throw new RuntimeException('BLOCKED V21R14: sesi LJK bukan milik guru, dihapus, atau jenisnya tidak sah.');
        }
        $ta = DB::table('teaching_assignments')->where('id', $session->teaching_assignment_id)
            ->where('teacher_id', $teacherId)->first();
        if (!$ta || (int) $ta->subject_id !== (int) $session->subject_id
            || (int) $ta->homeroom_id !== (int) $session->homeroom_id) {
            throw new RuntimeException('BLOCKED V21R14: penugasan guru tidak cocok dengan sesi.');
        }
        return $session;
    }

    /** Require answers 1..question_count. Normalize compact multi-select "BD" without changing legacy scorer. */
    public static function normalizedAnswers(array $answers, array $keys): array
    {
        if (count($keys) < 1 || count($keys) > 50 || count($answers) !== count($keys)) {
            throw new RuntimeException('BLOCKED V21R14: jumlah jawaban tidak cocok dengan kunci lokal.');
        }
        $out = [];
        foreach ($keys as $i => $key) {
            $number = $i + 1;
            if (!array_key_exists($number, $answers) && !array_key_exists((string) $number, $answers)) {
                throw new RuntimeException('BLOCKED V21R14: nomor soal hilang.');
            }
            $raw = $answers[$number] ?? $answers[(string) $number] ?? null;
            if (is_string($raw)) {
                $value = strtoupper(trim($raw));
                if ($value === '' || $value === '-') $arr = [];
                elseif (preg_match('/^[A-E]{1,5}$/D', $value)) $arr = str_split($value);
                else throw new RuntimeException('BLOCKED V21R14: isi jawaban tidak sah.');
            } elseif (is_array($raw) && array_is_list($raw)) {
                $arr = [];
                foreach ($raw as $option) {
                    if (!is_string($option) || !preg_match('/^[A-E]$/Di', $option)) {
                        throw new RuntimeException('BLOCKED V21R14: pilihan majemuk tidak sah.');
                    }
                    $arr[] = strtoupper($option);
                }
            } else throw new RuntimeException('BLOCKED V21R14: tipe jawaban tidak sah.');
            if (count($arr) !== count(array_unique($arr))) {
                throw new RuntimeException('BLOCKED V21R14: pilihan ganda duplikat.');
            }
            sort($arr, SORT_STRING);
            if (($key['type'] ?? null) !== 'majemuk' && count($arr) > 1) {
                throw new RuntimeException('BLOCKED V21R14: kunci tunggal menerima lebih dari satu pilihan.');
            }
            if (($key['type'] ?? null) === 'benar_salah' && array_diff($arr, ['A','B'])) {
                throw new RuntimeException('BLOCKED V21R14: benar/salah harus A atau B.');
            }
            $out[$number] = $arr;
        }
        return $out;
    }
}
