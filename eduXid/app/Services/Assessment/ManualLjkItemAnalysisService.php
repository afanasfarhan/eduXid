<?php

namespace App\Services\Assessment;

use App\Services\Ljk\LjkStandaloneManualService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Analisis hasil LJK dari sesi kunci manual, tanpa membuat record Paket Ujian. */
class ManualLjkItemAnalysisService
{
    public function __construct(
        private readonly LjkStandaloneManualService $manual,
        private readonly ItemAnalysisCalculator $calculator
    ) {}

    public function analyze(string $sessionId): array
    {
        // details() memeriksa pemilik sesi dari akun guru saat ini sebelum membaca data.
        $session = $this->manual->details($sessionId);
        $keys = $session['keys'] ?? [];
        if (!$keys) {
            throw new RuntimeException('Kunci manual sesi ini tidak tersedia.');
        }
        if (!Schema::hasTable('ljk_scan_results')) {
            throw new RuntimeException('Tabel hasil pemindaian LJK belum tersedia.');
        }

        $questions = [];
        foreach ($keys as $index => $key) {
            $number = $index + 1;
            $type = (string) ($key['type'] ?? 'pilihan_ganda');
            $options = $type === 'benar_salah'
                ? ['A' => 'Benar', 'B' => 'Salah']
                : ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E'];
            $questions[] = [
                'id' => $number, 'order' => $number, 'type' => $type,
                'stem' => 'Nomor ' . $number . ' (kunci manual; teks soal tidak tersimpan)',
                'material' => '', 'indicator' => '', 'cognitive_level' => '',
                'weight' => 1, 'options' => $options, 'key' => $key['answer'],
            ];
        }

        $rows = DB::table('ljk_scan_results')
            ->where('exam_sync_id', $sessionId)
            ->where('is_active', true)
            ->where('status', 'success')
            ->whereNotNull('student_id')
            ->orderBy('id')
            ->get();

        $byStudent = [];
        $validRaw = 0;
        foreach ($rows as $row) {
            $studentId = (int) $row->student_id;
            if ($studentId <= 0) continue;
            $savedKeys = json_decode((string) ($row->manual_keys_json ?? ''), true);
            // Cegah analisis memakai kunci yang berbeda dari snapshot hasil scan.
            if (!is_array($savedKeys) ||
                !hash_equals(
                    LjkStandaloneManualService::fingerprint($keys),
                    LjkStandaloneManualService::fingerprint($savedKeys)
                )) continue;

            $rawAnswers = json_decode((string) ($row->answers_json ?? ''), true);
            if (!is_array($rawAnswers)) continue;
            $answers = [];
            $correctness = [];
            foreach ($keys as $i => $key) {
                $number = $i + 1;
                $answer = $rawAnswers[$number] ?? $rawAnswers[(string) $number] ?? '';
                $answers[$number] = $answer;
                // Satu algoritma dengan koreksi resmi V13, termasuk kunci majemuk.
                $grade = LjkStandaloneManualService::grade([$key], [1 => $answer]);
                $correctness[$number] = ($grade['correct_count'] === 1);
            }
            $validRaw++;
            // Untuk pemindaian ganda, hasil aktif paling baru milik siswa yang dipakai.
            $byStudent[$studentId] = [
                'participant_key' => 'student::' . $studentId,
                'student_id' => $studentId,
                'nisn' => (string) ($row->nisn ?? ''),
                'name' => (string) ($row->student_name ?? ''),
                'source' => 'ljk',
                'score' => (float) $row->score,
                'timestamp' => (string) ($row->scanned_at ?? $row->updated_at ?? ''),
                'answers' => $answers,
                'correctness' => $correctness,
            ];
        }

        $participants = array_values($byStudent);
        $analysis = $this->calculator->analyze($questions, $participants, (array) config('item_analysis', []));
        $deduped = $validRaw - count($participants);
        $analysis['source'] = 'ljk';
        $analysis['source_label'] = 'LJK · Kunci Manual (tanpa Paket Ujian)';
        $analysis['deduped_count'] = $deduped;
        $analysis['summary']['cbt_count'] = 0;
        $analysis['summary']['ljk_count'] = count($participants);
        $analysis['summary']['deduped_count'] = $deduped;

        $teacher = DB::table('teachers')->where('id', $this->teacherId($sessionId))->first();
        $subject = DB::table('subjects')->where('id', (int) $session['subject_id'])->first();
        $homeroom = DB::table('homerooms')->where('id', (int) $session['homeroom_id'])->first();
        $school = Schema::hasTable('schools') ? DB::table('schools')->first() : null;
        $semesterId = (string) ($homeroom->semester_id ?? session('semester_aktif') ?? '');
        $year = preg_match('/^(\d{4})[12]$/', $semesterId, $m)
            ? $m[1] . '/' . ((int) $m[1] + 1) : '-';
        $semester = preg_match('/^\d{4}([12])$/', $semesterId, $m)
            ? ($m[1] === '1' ? 'Ganjil' : 'Genap') : '-';
        $analysis['meta'] = [
            'exam_id' => null,
            'exam_title' => (string) $session['title'],
            'teacher_name' => (string) ($teacher->nama_lengkap ?? $teacher->nama ?? '-'),
            'teacher_nip' => (string) ($teacher->nip ?? $teacher->nik ?? ''),
            'subject_name' => (string) ($subject->nama_mata_pelajaran ?? '-'),
            'school_name' => (string) ($school->nama ?? '-'),
            'school_npsn' => (string) ($school->npsn ?? ''),
            'grade_level' => (string) ($homeroom->nama ?? '-'),
            'academic_year' => $year, 'semester' => $semester,
            'semester_id' => $semesterId,
            'generated_at' => now()->format('d-m-Y H:i'),
        ];
        return $analysis;
    }

    private function teacherId(string $sessionId): int
    {
        return (int) DB::table('ljk_manual_sessions')->where('session_id', $sessionId)->value('teacher_id');
    }
}
