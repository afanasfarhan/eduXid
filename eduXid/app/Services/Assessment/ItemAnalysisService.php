<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Services\Sync\CbtCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ItemAnalysisService
{
    public function __construct(
        protected ItemAnalysisCalculator $calculator,
        protected CbtCorrectionService $correction
    ) {
    }

    public function analyze(Exam $exam, string $source = 'combined'): array
    {
        $source = $this->normalizeSource($source);
        $questions = $this->questions($exam);

        $cbt = in_array($source, ['combined', 'cbt'], true)
            ? $this->dedupeParticipants($this->cbtParticipants($exam, $questions))
            : [];

        $ljk = in_array($source, ['combined', 'ljk'], true)
            ? $this->dedupeParticipants($this->ljkParticipants($exam, $questions))
            : [];

        $rawCount = count($cbt) + count($ljk);

        if ($source === 'cbt') {
            $participants = $cbt;
        } elseif ($source === 'ljk') {
            $participants = $ljk;
        } else {
            // Bila siswa memiliki hasil CBT dan LJK untuk paket yang sama,
            // respons final terbaru dipakai agar tidak dihitung dua kali.
            $participants = $this->dedupeParticipants(array_merge($cbt, $ljk));
        }

        $deduped = max(0, $rawCount - count($participants));
        // Pada analisis LJK murni, tampilkan kunci manual yang benar-benar
        // digunakan bila seluruh peserta memakai snapshot kunci yang sama.
        if ($source === 'ljk' && $participants !== []) {
            $snapshots = array_column($participants, 'manual_key_map');
            if (count($snapshots) === count($participants) &&
                count(array_filter($snapshots, 'is_array')) === count($participants)) {
                $first = $snapshots[0];
                $identical = count(array_filter($snapshots, static fn($v) => $v === $first)) === count($snapshots);
                if ($identical) {
                    foreach ($questions as &$question) {
                        $qid = (string) $question['id'];
                        if (array_key_exists($qid, $first)) {
                            $question['key'] = $first[$qid];
                        }
                    }
                    unset($question);
                }
            }
        }

        $analysis = $this->calculator->analyze(
            $questions,
            $participants,
            (array) config('item_analysis', [])
        );

        $analysis['source'] = $source;
        $analysis['source_label'] = $this->sourceLabel($source);
        $analysis['deduped_count'] = $deduped;
        $analysis['meta'] = $this->meta($exam, $participants);
        $analysis['summary']['cbt_count'] = count(array_filter(
            $participants,
            static fn (array $p) => ($p['source'] ?? '') === 'cbt'
        ));
        $analysis['summary']['ljk_count'] = count(array_filter(
            $participants,
            static fn (array $p) => ($p['source'] ?? '') === 'ljk'
        ));
        $analysis['summary']['deduped_count'] = $deduped;

        return $analysis;
    }

    public function saveSnapshot(Exam $exam, array $analysis): string
    {
        if (!Schema::hasTable('item_analysis_runs') || !Schema::hasTable('item_analysis_results')) {
            throw new \RuntimeException('Tabel snapshot Analisis Butir belum tersedia. Jalankan migration.');
        }

        $runId = (string) Str::uuid();
        $summary = $analysis['summary'] ?? [];
        $meta = $analysis['meta'] ?? [];

        DB::transaction(function () use ($exam, $analysis, $summary, $meta, $runId) {
            $runPk = DB::table('item_analysis_runs')->insertGetId([
                'run_id' => $runId,
                'exam_id' => $exam->id,
                'teacher_id' => $exam->teacher_id,
                'source_scope' => $analysis['source'] ?? 'combined',
                'participant_count' => (int) ($summary['participant_count'] ?? 0),
                'cbt_count' => (int) ($summary['cbt_count'] ?? 0),
                'ljk_count' => (int) ($summary['ljk_count'] ?? 0),
                'deduped_count' => (int) ($summary['deduped_count'] ?? 0),
                'mean_score' => $summary['mean_score'] ?? null,
                'median_score' => $summary['median_score'] ?? null,
                'min_score' => $summary['min_score'] ?? null,
                'max_score' => $summary['max_score'] ?? null,
                'stddev_score' => $summary['stddev_score'] ?? null,
                'reliability_alpha' => $summary['reliability_alpha'] ?? null,
                'sample_note' => $summary['sample_note'] ?? null,
                'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($analysis['items'] ?? [] as $item) {
                DB::table('item_analysis_results')->insert([
                    'item_analysis_run_id' => $runPk,
                    'question_bank_id' => $item['question_id'],
                    'item_order' => $item['order'],
                    'difficulty_index' => $item['difficulty_index'],
                    'discrimination_index' => $item['discrimination_index'],
                    'point_biserial' => $item['point_biserial'],
                    'correct_count' => $item['correct_count'],
                    'blank_count' => $item['blank_count'],
                    'alpha_if_deleted' => $item['alpha_if_deleted'],
                    'indicator' => $item['indicator'] ?: null,
                    'cognitive_level' => $item['cognitive_level'] ?: null,
                    'status' => $item['status'],
                    'recommendation' => $item['recommendation'],
                    'distractor_json' => json_encode($item['options'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $runId;
    }

    public function latestSnapshot(Exam $exam, string $source = 'combined'): ?object
    {
        if (!Schema::hasTable('item_analysis_runs')) {
            return null;
        }

        return DB::table('item_analysis_runs')
            ->where('exam_id', $exam->id)
            ->where('source_scope', $this->normalizeSource($source))
            ->latest('id')
            ->first();
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, ['combined', 'cbt', 'ljk'], true) ? $source : 'combined';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'cbt' => 'CBT',
            'ljk' => 'LJK',
            default => 'Gabungan CBT + LJK',
        };
    }

    private function questions(Exam $exam): array
    {
        if (!Schema::hasTable('exam_questions') || !Schema::hasTable('question_banks')) {
            return [];
        }

        $rows = DB::table('exam_questions as eq')
            ->join('question_banks as q', 'q.id', '=', 'eq.question_bank_id')
            ->where('eq.exam_id', $exam->id)
            ->orderBy('eq.urutan')
            ->select('q.*', 'eq.urutan as item_order')
            ->get();

        return $rows->map(function ($row) {
            $options = $this->decodeOptions($row->opsi_jawaban ?? null);
            $key = $this->decodeKey($row->kunci_jawaban ?? null);

            return [
                'id' => (int) $row->id,
                'order' => (int) ($row->item_order ?? 0),
                'type' => (string) ($row->jenis_soal ?? ''),
                'stem' => trim(html_entity_decode(strip_tags((string) ($row->pertanyaan ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'material' => trim((string) ($row->judul_materi ?? '')),
                'indicator' => trim((string) ($row->indikator ?? '')),
                'cognitive_level' => trim((string) ($row->level_kognitif ?? '')),
                'weight' => (float) ($row->bobot_nilai ?? 1),
                'options' => $options,
                'key' => $key,
                'raw' => $row,
            ];
        })->values()->all();
    }

    private function decodeOptions(mixed $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }

        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        $out = [];
        $i = 0;
        foreach ($decoded as $key => $value) {
            $k = is_string($key) && trim($key) !== '' ? strtoupper(trim($key)) : chr(65 + $i);
            $out[$k] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE)
                : trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $i++;
        }
        return $out;
    }

    private function decodeKey(mixed $raw): mixed
    {
        if (is_array($raw)) return $raw;
        $text = trim((string) $raw);
        if ($text === '') return '';
        $decoded = json_decode($text, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $text;
    }

    private function cbtParticipants(Exam $exam, array $questions): array
    {
        if (!Schema::hasTable('exam_sessions') || !Schema::hasTable('student_answers')) {
            return [];
        }

        $q = DB::table('exam_sessions as es')
            ->leftJoin('students as s', 's.id', '=', 'es.student_id')
            ->where('es.exam_id', $exam->id)
            ->where('es.status', 'selesai')
            ->whereNotNull('es.nilai_akhir');

        if (Schema::hasColumn('exam_sessions', 'sync_id')) {
            $q->where(function ($inner) {
                $inner->whereNull('es.sync_id')
                    ->orWhere('es.sync_id', 'not like', 'ljk::%');
            });
        }

        $select = [
            'es.id',
            'es.student_id',
            'es.nilai_akhir',
            'es.waktu_submit',
            'es.updated_at',
            's.nisn',
            's.nama',
        ];

        if (Schema::hasColumn('exam_sessions', 'sync_id')) {
            $select[] = 'es.sync_id';
        }

        $sessions = $q->select($select)->get();

        if ($sessions->isEmpty()) return [];

        $answerRows = DB::table('student_answers')
            ->whereIn('exam_session_id', $sessions->pluck('id'))
            ->get()
            ->groupBy('exam_session_id');

        $participants = [];
        foreach ($sessions as $session) {
            $answers = [];
            $correctness = [];

            foreach ($answerRows->get($session->id, collect()) as $row) {
                $qid = (int) $row->question_bank_id;
                $answers[$qid] = $this->decodeAnswerValue($row->jawaban_siswa ?? '');
                $correctness[$qid] = $row->is_benar === null
                    ? null
                    : ((int) $row->is_benar === 1);
            }

            foreach ($questions as $question) {
                $qid = (int) $question['id'];
                $answers[$qid] ??= '';
                $correctness[$qid] ??= null;
            }

            $participants[] = [
                'participant_key' => $this->participantKey($session->student_id, $session->nisn),
                'student_id' => $session->student_id ? (int) $session->student_id : null,
                'nisn' => trim((string) ($session->nisn ?? '')),
                'name' => trim((string) ($session->nama ?? '')),
                'source' => 'cbt',
                'score' => is_numeric($session->nilai_akhir) ? (float) $session->nilai_akhir : null,
                'timestamp' => (string) ($session->waktu_submit ?? $session->updated_at ?? ''),
                'answers' => $answers,
                'correctness' => $correctness,
            ];
        }

        return $participants;
    }

    private function ljkParticipants(Exam $exam, array $questions): array
    {
        if (!Schema::hasTable('ljk_scan_results')) {
            return [];
        }

        $rows = DB::table('ljk_scan_results')
            ->where('exam_id', $exam->id)
            ->where('is_active', true)
            ->where('status', 'success')
            ->whereNotNull('student_id')
            ->orderBy('id')
            ->get();

        $participants = [];
        foreach ($rows as $row) {
            $map = json_decode((string) ($row->answers_json ?? '{}'), true);
            if (!is_array($map)) $map = [];

            $answers = [];
            $correctness = [];
            $manualSnapshot = null;
            if ((string) ($row->key_source ?? 'package') === 'manual') {
                $decoded = json_decode((string) ($row->manual_keys_json ?? ''), true);
                if (is_array($decoded) && $decoded !== []) $manualSnapshot = $decoded;
            }

            foreach ($questions as $index => $question) {
                $qid = (int) $question['id'];
                $answer = $this->answerAt($map, $qid, $index + 1);
                $answers[$qid] = $answer;

                try {
                    $gradeQuestion = $question['raw'];
                    $gradeAnswer = $answer;
                    if ($manualSnapshot !== null) {
                        $manualKey = $manualSnapshot[(string) $qid] ?? $manualSnapshot[$qid] ?? null;
                        if ($manualKey === null || $manualKey === '') {
                            $correctness[$qid] = null;
                            continue;
                        }
                        $gradeQuestion = clone $question['raw'];
                        $manualValue = $manualKey;
                        if (strtolower((string) $question['type']) === 'benar_salah') {
                            $manualValue = strtoupper((string) $manualKey) === 'A' ? 'B' : 'S';
                        } elseif (is_array($manualKey)) {
                            $manualValue = json_encode($manualKey, JSON_UNESCAPED_UNICODE);
                        }
                        $gradeQuestion->kunci_jawaban = $manualValue; // raw DB::table() adalah stdClass, bukan model Eloquent
                    }
                    // Bubble benar/salah A=Benar, B=Salah; penilaian resmi B/S.
                    if (strtolower((string) $question['type']) === 'benar_salah') {
                        $value = is_array($answer) ? reset($answer) : $answer;
                        $gradeAnswer = match (strtoupper(trim((string) $value))) {
                            'A', 'BENAR', 'TRUE', 'T' => 'B',
                            'B', 'SALAH', 'FALSE', 'F' => 'S',
                            default => $value,
                        };
                    }
                    $graded = $this->correction->gradeObjective($gradeQuestion, $gradeAnswer);
                    $correctness[$qid] = is_array($graded)
                        ? (bool) ($graded['is_benar'] ?? false)
                        : null;
                } catch (Throwable) {
                    $correctness[$qid] = null;
                }
            }

            $participants[] = [
                'participant_key' => $this->participantKey($row->student_id, $row->nisn),
                'student_id' => $row->student_id ? (int) $row->student_id : null,
                'nisn' => trim((string) ($row->nisn ?? '')),
                'name' => trim((string) ($row->student_name ?? '')),
                'source' => 'ljk',
                'score' => is_numeric($row->score) ? (float) $row->score : null,
                'timestamp' => (string) ($row->scanned_at ?? $row->updated_at ?? ''),
                'answers' => $answers,
                'correctness' => $correctness,
                'manual_key_map' => $manualSnapshot,
            ];
        }

        return $participants;
    }

    private function dedupeParticipants(array $participants): array
    {
        usort($participants, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['timestamp'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['timestamp'] ?? '')) ?: 0;
            return $ta <=> $tb;
        });

        $byKey = [];
        foreach ($participants as $participant) {
            $key = (string) ($participant['participant_key'] ?? '');
            if ($key === '') {
                $key = 'anon::' . md5(json_encode($participant));
            }
            $byKey[$key] = $participant;
        }
        return array_values($byKey);
    }

    private function participantKey(mixed $studentId, mixed $nisn): string
    {
        if ((int) $studentId > 0) return 'student::' . (int) $studentId;
        $nisn = trim((string) $nisn);
        return $nisn !== '' ? 'nisn::' . $nisn : '';
    }

    private function decodeAnswerValue(mixed $value): mixed
    {
        if (is_array($value)) return $value;
        $text = trim((string) $value);
        if ($text === '') return '';
        $decoded = json_decode($text, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $text;
    }

    private function answerAt(array $answers, int $qid, int $position): mixed
    {
        foreach ([$qid, (string) $qid, $position, (string) $position, $position - 1, (string) ($position - 1)] as $key) {
            if (array_key_exists($key, $answers)) {
                return $answers[$key];
            }
        }
        return '';
    }

    private function meta(Exam $exam, array $participants): array
    {
        $teacher = DB::table('teachers')->where('id', $exam->teacher_id)->first();
        $subject = DB::table('subjects')->where('id', $exam->subject_id)->first();
        $school = DB::table('schools')->first();
        $semesterId = (string) (session('semester_aktif') ?? '');

        [$academicYear, $semester] = $this->academicPeriod($semesterId);

        return [
            'exam_id' => $exam->id,
            'exam_title' => (string) ($exam->judul_ujian ?? ('Paket Ujian #' . $exam->id)),
            'teacher_name' => $this->teacherName($teacher),
            'teacher_nip' => trim((string) ($teacher->nip ?? $teacher->nik ?? $teacher->nuptk ?? '')),
            'subject_name' => trim((string) ($subject->nama_mata_pelajaran ?? '-')),
            'school_name' => trim((string) ($school->nama ?? '-')),
            'school_npsn' => trim((string) ($school->npsn ?? '')),
            'grade_level' => $this->gradeLevel($exam, $participants, $semesterId),
            'academic_year' => $academicYear,
            'semester' => $semester,
            'semester_id' => $semesterId,
            'generated_at' => now()->format('d-m-Y H:i'),
        ];
    }

    private function teacherName(?object $teacher): string
    {
        if (!$teacher) return '-';
        $full = trim((string) ($teacher->nama_lengkap ?? ''));
        if ($full !== '') return $full;

        $parts = array_filter([
            trim((string) ($teacher->gelar_depan ?? '')),
            trim((string) ($teacher->nama ?? '')),
            trim((string) ($teacher->gelar_belakang ?? '')),
        ], static fn ($v) => $v !== '');

        return $parts !== [] ? implode(' ', $parts) : '-';
    }

    private function academicPeriod(string $semesterId): array
    {
        if (preg_match('/^(\d{4})([12])$/', $semesterId, $m)) {
            $year = (int) $m[1];
            return [
                $year . '/' . ($year + 1),
                $m[2] === '1' ? 'Ganjil' : 'Genap',
            ];
        }
        return ['-', '-'];
    }

    private function gradeLevel(Exam $exam, array $participants, string $semesterId): string
    {
        if (Schema::hasColumn('exams', 'tingkat_kelas')) {
            $value = trim((string) DB::table('exams')->where('id', $exam->id)->value('tingkat_kelas'));
            if ($value !== '') return $this->gradeLabel($value);
        }

        $studentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $p) => (int) ($p['student_id'] ?? 0),
            $participants
        ))));

        $levels = collect();

        if ($studentIds !== [] && Schema::hasTable('enrollments') && Schema::hasTable('homerooms')) {
            $query = DB::table('enrollments as e')
                ->join('homerooms as h', 'h.id', '=', 'e.homeroom_id')
                ->whereIn('e.student_id', $studentIds);

            if ($semesterId !== '' && Schema::hasColumn('homerooms', 'semester_id')) {
                $query->where('h.semester_id', $semesterId);
            }

            if (Schema::hasColumn('homerooms', 'jenis_rombel')) {
                $query->where('h.jenis_rombel', 'not like', '%Ekstrakurikuler%')
                    ->where('h.jenis_rombel', 'not like', '%Pilihan%');
            }

            if (Schema::hasColumn('homerooms', 'tingkat_pendidikan')) {
                $levels = $query->pluck('h.tingkat_pendidikan');
            } elseif (Schema::hasColumn('homerooms', 'tingkat_kelas')) {
                $levels = $query->pluck('h.tingkat_kelas');
            }
        }

        if ($levels->filter()->isEmpty() && Schema::hasTable('teaching_assignments') && Schema::hasTable('homerooms')) {
            $query = DB::table('teaching_assignments as ta')
                ->join('homerooms as h', 'h.id', '=', 'ta.homeroom_id')
                ->where('ta.teacher_id', $exam->teacher_id)
                ->where('ta.subject_id', $exam->subject_id);

            if ($semesterId !== '' && Schema::hasColumn('homerooms', 'semester_id')) {
                $query->where('h.semester_id', $semesterId);
            }

            if (Schema::hasColumn('homerooms', 'tingkat_pendidikan')) {
                $levels = $query->pluck('h.tingkat_pendidikan');
            } elseif (Schema::hasColumn('homerooms', 'tingkat_kelas')) {
                $levels = $query->pluck('h.tingkat_kelas');
            }
        }

        $labels = $levels->filter()
            ->map(fn ($v) => $this->gradeLabel((string) $v))
            ->unique()
            ->sort()
            ->values();

        return $labels->isNotEmpty() ? $labels->implode(', ') : '-';
    }

    private function gradeLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '-';
        if (preg_match('/(\d{1,2})/', $value, $m)) {
            return 'Kelas ' . (int) $m[1];
        }
        return $value;
    }
}
