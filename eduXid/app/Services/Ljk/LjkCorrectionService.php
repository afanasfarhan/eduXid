<?php

namespace App\Services\Ljk;

use App\Models\Exam;
use App\Models\Student;
use App\Services\Sync\WorkProductPushService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class LjkCorrectionService
{
    public const TEMPLATE_VERSION = 'LJK-A4-V2';
    public const MAX_QUESTIONS = 50;
    public const DEFAULT_SIKAP = 3;

    /**
     * Pratinjau hasil OMR. Tidak menulis Buku Nilai.
     */
    public function preview(
        Exam $exam,
        string $nisn,
        array $answers,
        string $examDate,
        array $confidence = [],
        string $source = 'scanner'
    ): array {
        $student = $this->resolveStudent($nisn);
        $questions = $this->orderedQuestions($exam);

        if ($questions->isEmpty()) {
            throw new InvalidArgumentException('Paket ujian belum memiliki soal.');
        }

        if ($questions->count() > self::MAX_QUESTIONS) {
            throw new InvalidArgumentException('LJK-A4-V2 hanya mendukung 1–50 soal.');
        }

        $scope = $this->resolveBookScope($exam, $student);
        $normalizedAnswers = $this->normalizeAnswers($answers, $questions->count());
        $score = $this->scoreQuestions($questions, $normalizedAnswers);

        return [
            'template_version' => self::TEMPLATE_VERSION,
            'exam_id' => (int) $exam->id,
            'exam_title' => (string) ($exam->judul_ujian ?? ''),
            'exam_date' => $examDate,
            'assessment_type' => in_array((string) ($exam->jenis_penilaian ?? ''), ['diagnostik', 'formatif', 'sumatif'], true)
                ? (string) $exam->jenis_penilaian
                : 'sumatif',
            'student_id' => (int) $student->id,
            'nisn' => (string) $nisn,
            'student_name' => (string) ($student->nama ?? ''),
            'homeroom_id' => (int) $scope['homeroom_id'],
            'homeroom_name' => (string) ($scope['homeroom_name'] ?? ''),
            'teaching_assignment_id' => (int) $scope['teaching_assignment_id'],
            'source' => in_array($source, ['scanner', 'camera', 'pdf'], true) ? $source : 'scanner',
            'question_count' => $questions->count(),
            'correct_count' => $score['correct_count'],
            'wrong_count' => $score['wrong_count'],
            'score' => $score['score'],
            'answers' => $normalizedAnswers,
            'confidence' => $confidence,
        ];
    }

    /**
     * Finalisasi nilai LJK ke assessment_scores dan audit lokal ljk_results.
     * Gambar LJK tidak pernah diterima metode ini.
     */
    public function finalize(
        Exam $exam,
        string $nisn,
        array $answers,
        string $examDate,
        array $confidence = [],
        int $ambiguousCount = 0,
        string $source = 'scanner'
    ): array {
        $result = $this->preview($exam, $nisn, $answers, $examDate, $confidence, $source);
        $syncVersion = (int) floor(microtime(true) * 1000);

        DB::transaction(function () use ($exam, $result, $confidence, $ambiguousCount, $syncVersion): void {
            $this->writeDailyAssessment($exam, $result, $syncVersion);
            $this->writeAudit($exam, $result, $confidence, $ambiguousCount);
        });

        // Mekanisme yang sama dengan koreksi CBT: tandai produk kerja guru berubah,
        // lalu daemon/sync yang sudah ada membawa Buku Nilai ke PWA Guru.
        try {
            WorkProductPushService::markDirty((int) $exam->teacher_id);
        } catch (Throwable $e) {
            Log::warning('[LJK] Nilai tersimpan, tetapi markDirty sinkronisasi gagal.', [
                'exam_id' => $exam->id,
                'student_id' => $result['student_id'],
                'error' => $e->getMessage(),
            ]);
        }

        $result['sync_version'] = $syncVersion;
        $result['finalized'] = true;

        return $result;
    }


    /**
     * Validasi banyak hasil OMR sekaligus tanpa menulis Buku Nilai.
     * Satu lembar bermasalah tidak menggagalkan lembar lain.
     */
    public function previewBatch(Exam $exam, array $items, string $examDate): array
    {
        if ($items === [] || count($items) > 100) {
            throw new InvalidArgumentException('Batch LJK harus berisi 1–100 lembar.');
        }

        $nisnCounts = [];
        foreach ($items as $item) {
            $nisn = trim((string) ($item['nisn'] ?? ''));
            if (preg_match('/^\\d{10}$/', $nisn)) {
                $nisnCounts[$nisn] = ($nisnCounts[$nisn] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach (array_values($items) as $index => $item) {
            $clientId = trim((string) ($item['client_id'] ?? '')) ?: ('row-' . ($index + 1));
            $nisn = trim((string) ($item['nisn'] ?? ''));
            $ambiguousCount = max(0, (int) ($item['ambiguous_count'] ?? 0));
            $source = in_array((string) ($item['source'] ?? ''), ['scanner', 'camera', 'pdf'], true)
                ? (string) $item['source']
                : 'scanner';
            $sourceRef = mb_substr(trim((string) ($item['source_ref'] ?? '')), 0, 255);

            $base = [
                'client_id' => $clientId,
                'source' => $source,
                'source_ref' => $sourceRef,
                'ambiguous_count' => $ambiguousCount,
            ];

            if (($nisnCounts[$nisn] ?? 0) > 1) {
                $rows[] = $base + [
                    'status' => 'duplicate',
                    'nisn' => $nisn,
                    'message' => 'NISN yang sama terdeteksi lebih dari satu kali dalam batch.',
                ];
                continue;
            }

            try {
                $result = $this->preview(
                    $exam,
                    $nisn,
                    (array) ($item['answers'] ?? []),
                    $examDate,
                    (array) ($item['confidence'] ?? []),
                    $source
                );

                $existing = false;
                if (Schema::hasTable('ljk_results')) {
                    $existing = DB::table('ljk_results')
                        ->where('exam_id', $exam->id)
                        ->where('student_id', $result['student_id'])
                        ->exists();
                }

                $rows[] = $base + $result + [
                    'status' => $ambiguousCount > 0 ? 'need_review' : 'ready',
                    'existing_result' => $existing,
                    'message' => $ambiguousCount > 0
                        ? 'Hasil terbaca, tetapi perlu verifikasi guru.'
                        : ($existing ? 'Nilai LJK sebelumnya ditemukan; finalisasi akan memperbarui nilai.' : 'Siap difinalisasi.'),
                ];
            } catch (InvalidArgumentException $e) {
                $rows[] = $base + [
                    'status' => 'failed',
                    'nisn' => $nisn,
                    'message' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                Log::error('[LJK-BATCH] Preview lembar gagal.', [
                    'client_id' => $clientId,
                    'exam_id' => $exam->id,
                    'error' => $e->getMessage(),
                ]);
                $rows[] = $base + [
                    'status' => 'failed',
                    'nisn' => $nisn,
                    'message' => 'Validasi lembar gagal: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'rows' => $rows,
            'summary' => $this->batchSummary($rows),
        ];
    }

    /**
     * Finalisasi banyak LJK dalam satu transaksi database. Semua item yang
     * dikirim harus valid; markDirty dipanggil sekali sesudah transaksi.
     */
    public function finalizeBatch(
        Exam $exam,
        array $items,
        string $examDate,
        string $batchUuid,
        int $batchTotal = 0
    ): array {
        if ($items === [] || count($items) > 100) {
            throw new InvalidArgumentException('Finalisasi massal harus berisi 1–100 lembar.');
        }

        if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $batchUuid)) {
            throw new InvalidArgumentException('ID batch LJK tidak valid.');
        }

        $seen = [];
        $prepared = [];
        foreach (array_values($items) as $index => $item) {
            $nisn = trim((string) ($item['nisn'] ?? ''));
            if (isset($seen[$nisn])) {
                throw new InvalidArgumentException('Finalisasi dibatalkan: terdapat NISN duplikat ' . $nisn . '.');
            }
            $seen[$nisn] = true;

            $ambiguousCount = max(0, (int) ($item['ambiguous_count'] ?? 0));
            $confirmedReview = filter_var($item['confirmed_review'] ?? false, FILTER_VALIDATE_BOOL);
            if ($ambiguousCount > 0 && !$confirmedReview) {
                throw new InvalidArgumentException(
                    'Finalisasi dibatalkan: lembar ' . ($index + 1) . ' masih membutuhkan verifikasi guru.'
                );
            }

            $source = in_array((string) ($item['source'] ?? ''), ['scanner', 'camera', 'pdf'], true)
                ? (string) $item['source']
                : 'scanner';

            $result = $this->preview(
                $exam,
                $nisn,
                (array) ($item['answers'] ?? []),
                $examDate,
                (array) ($item['confidence'] ?? []),
                $source
            );
            $result['source'] = $source;

            $prepared[] = [
                'client_id' => trim((string) ($item['client_id'] ?? '')) ?: ('row-' . ($index + 1)),
                'result' => $result,
                'confidence' => (array) ($item['confidence'] ?? []),
                'ambiguous_count' => $ambiguousCount,
                'source_ref' => mb_substr(trim((string) ($item['source_ref'] ?? '')), 0, 255),
            ];
        }

        $syncBase = (int) floor(microtime(true) * 1000);
        DB::transaction(function () use ($exam, $prepared, $syncBase, $batchUuid, $batchTotal): void {
            foreach ($prepared as $offset => $entry) {
                $syncVersion = $syncBase + $offset;
                $this->writeDailyAssessment($exam, $entry['result'], $syncVersion);
                $this->writeAudit(
                    $exam,
                    $entry['result'],
                    $entry['confidence'],
                    $entry['ambiguous_count'],
                    $batchUuid,
                    $entry['source_ref']
                );
            }

            $this->writeBatchAudit(
                $exam,
                $batchUuid,
                $examDate,
                max($batchTotal, count($prepared)),
                count($prepared)
            );
        });

        try {
            WorkProductPushService::markDirty((int) $exam->teacher_id);
        } catch (Throwable $e) {
            Log::warning('[LJK-BATCH] Nilai massal tersimpan, tetapi markDirty gagal.', [
                'exam_id' => $exam->id,
                'batch_uuid' => $batchUuid,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'batch_uuid' => $batchUuid,
            'finalized_count' => count($prepared),
            'results' => array_map(static function (array $entry): array {
                $result = $entry['result'];
                return [
                    'client_id' => $entry['client_id'],
                    'student_id' => $result['student_id'],
                    'nisn' => $result['nisn'],
                    'student_name' => $result['student_name'],
                    'homeroom_name' => $result['homeroom_name'],
                    'score' => $result['score'],
                    'status' => 'finalized',
                ];
            }, $prepared),
        ];
    }

    protected function batchSummary(array $rows): array
    {
        $summary = [
            'total' => count($rows),
            'ready' => 0,
            'need_review' => 0,
            'duplicate' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'failed');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    public function assertExamOwnedByTeacher(Exam $exam, int $teacherId): void
    {
        if ((int) $exam->teacher_id !== $teacherId) {
            abort(403, 'Paket ujian bukan milik guru yang sedang login.');
        }
    }

    /**
     * @return Collection<int, mixed>
     */
    public function orderedQuestions(Exam $exam): Collection
    {
        $exam->loadMissing('questions');

        return $exam->questions
            ->sortBy(function ($question, $index) {
                $pivotOrder = data_get($question, 'pivot.urutan');
                if ($pivotOrder !== null) {
                    return (int) $pivotOrder;
                }

                if (isset($question->urutan)) {
                    return (int) $question->urutan;
                }

                return (int) $index;
            })
            ->values();
    }

    protected function resolveStudent(string $nisn): Student
    {
        $nisn = trim($nisn);

        if (!preg_match('/^\d{10}$/', $nisn)) {
            throw new InvalidArgumentException('NISN hasil LJK harus tepat 10 digit.');
        }

        $studentQuery = Student::query()->where('nisn', $nisn);

        if (Schema::hasColumn('students', 'login_id')) {
            $studentQuery->orWhere('login_id', $nisn);
        }

        $student = $studentQuery->first();

        if (!$student) {
            throw new InvalidArgumentException(
                'NISN ' . $nisn . ' tidak ditemukan pada database siswa eduXid.'
            );
        }

        return $student;
    }

    /**
     * Rombel tidak dibaca dari tulisan di kertas. Selalu diselesaikan dari
     * enrollment siswa lalu dicocokkan dengan teaching_assignment guru+mapel.
     */
    protected function resolveBookScope(Exam $exam, Student $student): array
    {
        $homeroomNameColumn = Schema::hasColumn('homerooms', 'nama')
            ? 'homerooms.nama'
            : (Schema::hasColumn('homerooms', 'name') ? 'homerooms.name' : null);

        $regularHomerooms = DB::table('enrollments')
            ->join('homerooms', 'homerooms.id', '=', 'enrollments.homeroom_id')
            ->where('enrollments.student_id', $student->id)
            ->when(
                Schema::hasColumn('homerooms', 'jenis_rombel'),
                fn ($q) => $q->where(function ($qq) {
                    $qq->whereNull('homerooms.jenis_rombel')
                        ->orWhere('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%');
                })
            )
            ->select('enrollments.homeroom_id')
            ->when(
                Schema::hasColumn('homerooms', 'semester_id'),
                fn ($q) => $q->addSelect('homerooms.semester_id')
            )
            ->when(
                $homeroomNameColumn !== null,
                fn ($q) => $q->addSelect(DB::raw("COALESCE({$homeroomNameColumn}, '') as homeroom_name")),
                fn ($q) => $q->addSelect(DB::raw("'' as homeroom_name"))
            )
            ->orderByDesc(Schema::hasColumn('homerooms', 'semester_id') ? 'homerooms.semester_id' : 'homerooms.id')
            ->orderByDesc('enrollments.id')
            ->get();

        if ($regularHomerooms->isEmpty()) {
            throw new InvalidArgumentException('Siswa tidak memiliki rombel reguler aktif.');
        }

        $homeroomIds = $regularHomerooms
            ->pluck('homeroom_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $ta = DB::table('teaching_assignments')
            ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->where('teaching_assignments.teacher_id', $exam->teacher_id)
            ->where('teaching_assignments.subject_id', $exam->subject_id)
            ->whereIn('teaching_assignments.homeroom_id', $homeroomIds)
            ->when(
                Schema::hasColumn('homerooms', 'semester_id'),
                fn ($q) => $q->orderByDesc('homerooms.semester_id')
            )
            ->orderByDesc('teaching_assignments.id')
            ->select([
                'teaching_assignments.id as teaching_assignment_id',
                'teaching_assignments.homeroom_id',
            ])
            ->when(
                $homeroomNameColumn !== null,
                fn ($q) => $q->addSelect(DB::raw("COALESCE({$homeroomNameColumn}, '') as homeroom_name")),
                fn ($q) => $q->addSelect(DB::raw("'' as homeroom_name"))
            )
            ->first();

        if (!$ta) {
            throw new InvalidArgumentException(
                'Penugasan mengajar guru untuk mapel dan rombel siswa tidak ditemukan. '
                . 'Nilai LJK tidak ditulis agar tidak masuk ke Buku Nilai yang salah.'
            );
        }

        return [
            'homeroom_id' => (int) $ta->homeroom_id,
            'homeroom_name' => (string) ($ta->homeroom_name ?? ''),
            'teaching_assignment_id' => (int) $ta->teaching_assignment_id,
        ];
    }

    /**
     * Metadata soal yang aman dikirim ke browser. Tidak pernah memuat kunci.
     *
     * @return array<int, array{number:int,type:string,choices:array<int,string>}>
     */
    public function questionBlueprint(Exam $exam): array
    {
        $questions = $this->orderedQuestions($exam);
        $blueprint = [];

        foreach ($questions as $index => $question) {
            $type = $this->normalizedQuestionType($question, $index + 1);
            $blueprint[] = [
                'number' => $index + 1,
                'type' => $type,
                'choices' => $type === 'benar_salah'
                    ? ['A', 'B']
                    : ['A', 'B', 'C', 'D', 'E'],
            ];
        }

        return $blueprint;
    }

    protected function normalizedQuestionType($question, int $number): string
    {
        $type = strtolower(trim((string) ($question->jenis_soal ?? 'pilihan_ganda')));

        if (in_array($type, ['pilihan_ganda', 'majemuk', 'benar_salah'], true)) {
            return $type;
        }

        throw new InvalidArgumentException(
            "Soal nomor {$number} bertipe {$type} dan tidak kompatibel dengan LJK-A4-V2. "
            . 'Gunakan hanya Pilihan Ganda, Pilihan Ganda Majemuk, atau Benar/Salah.'
        );
    }

    protected function normalizeAnswers(array $answers, int $questionCount): array
    {
        $out = [];

        for ($i = 1; $i <= $questionCount; $i++) {
            $raw = $answers[$i] ?? $answers[(string) $i] ?? '';
            $set = $this->normalizeAnswerSet($raw);
            $out[$i] = implode(',', $set);
        }

        return $out;
    }

    /** @return array<int, string> */
    protected function normalizeAnswerSet($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $raw = strtoupper(trim((string) $value));
            $parts = $raw === '' ? [] : (preg_split('/[\\s,;|+]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $normalized = [];
        foreach ($parts as $part) {
            $v = strtoupper(trim((string) $part));
            $v = match ($v) {
                'BENAR', 'TRUE' => 'A',
                'SALAH', 'FALSE' => 'B',
                default => $v,
            };

            if (!in_array($v, ['A', 'B', 'C', 'D', 'E'], true)) {
                throw new InvalidArgumentException('Jawaban LJK hanya boleh A–E.');
            }
            $normalized[$v] = true;
        }

        $set = array_keys($normalized);
        sort($set);
        return array_values($set);
    }

    protected function scoreQuestions(Collection $questions, array $answers): array
    {
        $correct = 0;
        $earned = 0.0;
        $totalWeight = 0.0;
        $results = [];

        foreach ($questions as $index => $question) {
            $number = $index + 1;
            $type = $this->normalizedQuestionType($question, $number);
            $answerSet = $this->normalizeAnswerSet($answers[$number] ?? '');
            $keySet = $this->questionKeySet($question, $number);

            if ($type === 'pilihan_ganda' && count($answerSet) > 1) {
                throw new InvalidArgumentException(
                    "Soal nomor {$number} adalah Pilihan Ganda tunggal tetapi terdeteksi lebih dari satu bulatan."
                );
            }

            if ($type === 'benar_salah') {
                if (count($answerSet) > 1 || array_diff($answerSet, ['A', 'B']) !== []) {
                    throw new InvalidArgumentException(
                        "Soal nomor {$number} adalah Benar/Salah. Gunakan A=Benar atau B=Salah."
                    );
                }
                if (array_diff($keySet, ['A', 'B']) !== []) {
                    throw new InvalidArgumentException(
                        "Kunci soal nomor {$number} tidak kompatibel dengan format Benar/Salah LJK (A/B)."
                    );
                }
            }

            $weight = max(0.0, (float) ($question->bobot_nilai ?? 1));
            if ($weight <= 0) {
                $weight = 1.0;
            }

            $isCorrect = false;
            if ($answerSet !== [] && $keySet !== []) {
                if ($type === 'majemuk') {
                    $a = $answerSet;
                    $k = $keySet;
                    sort($a);
                    sort($k);
                    $isCorrect = $a === $k;
                } else {
                    $isCorrect = count($answerSet) === 1
                        && count($keySet) === 1
                        && $answerSet[0] === $keySet[0];
                }
            }

            $totalWeight += $weight;
            if ($isCorrect) {
                $correct++;
                $earned += $weight;
            }

            // Kunci tidak pernah dimasukkan ke respons browser.
            $results[] = [
                'number' => $number,
                'type' => $type,
                'answer' => implode(',', $answerSet),
                'is_correct' => $isCorrect,
            ];
        }

        $score = $totalWeight > 0
            ? round(($earned / $totalWeight) * 100, 2)
            : 0.0;

        return [
            'correct_count' => $correct,
            'wrong_count' => $questions->count() - $correct,
            'score' => $score,
            'results' => $results,
        ];
    }

    /** @return array<int, string> */
    protected function questionKeySet($question, int $number): array
    {
        $key = $question->kunci_jawaban ?? null;
        $type = strtolower(trim((string) ($question->jenis_soal ?? '')));

        if ($type === 'benar_salah') {
            if (is_array($key)) {
                $key = reset($key);
            }
            $raw = strtoupper(trim((string) ($key ?? '')));
            $set = match ($raw) {
                'B', 'BENAR', 'TRUE', 'T' => ['A'],
                'S', 'SALAH', 'FALSE', 'F' => ['B'],
                'A' => ['A'],
                default => [],
            };
            if ($set === []) {
                throw new InvalidArgumentException("Kunci Benar/Salah soal nomor {$number} tidak valid.");
            }
            return $set;
        }

        if (is_string($key)) {
            $trimmed = trim($key);
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $key = $decoded;
            }
        }

        $set = $this->normalizeAnswerSet($key);
        if ($set === []) {
            throw new InvalidArgumentException("Kunci jawaban soal nomor {$number} kosong.");
        }

        return $set;
    }

    protected function writeDailyAssessment(Exam $exam, array $result, int $syncVersion): void
    {
        if (!Schema::hasTable('assessment_scores')) {
            throw new InvalidArgumentException('Tabel assessment_scores tidak tersedia.');
        }

        $columns = array_flip(Schema::getColumnListing('assessment_scores'));
        $assessmentType = $result['assessment_type'];
        $materi = 'Ujian LJK: ' . trim((string) ($exam->judul_ujian ?? 'Ujian'));

        $key = [
            'student_id' => $result['student_id'],
            'teaching_assignment_id' => $result['teaching_assignment_id'],
            'tanggal' => $result['exam_date'],
            'materi' => $materi,
        ];

        $data = [
            'materi' => $materi,
            $assessmentType => $result['score'],
            'updated_at' => now(),
        ];

        if (isset($columns['sync_version'])) {
            $data['sync_version'] = $syncVersion;
        }

        $existing = DB::table('assessment_scores')
            ->where('student_id', $result['student_id'])
            ->where('teaching_assignment_id', $result['teaching_assignment_id'])
            ->where('tanggal', $result['exam_date'])
            ->where('materi', $materi)
            ->first();

        if ($existing) {
            DB::table('assessment_scores')->where('id', $existing->id)->update($data);
            return;
        }

        $insert = [
            'student_id' => $result['student_id'],
            'teaching_assignment_id' => $result['teaching_assignment_id'],
            'tanggal' => $result['exam_date'],
            'materi' => $materi,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (['diagnostik', 'formatif', 'sumatif'] as $type) {
            if (isset($columns[$type])) {
                $insert[$type] = $type === $assessmentType ? $result['score'] : null;
            }
        }

        foreach (range(1, 8) as $i) {
            $col = 'd' . $i;
            if (isset($columns[$col])) {
                $insert[$col] = self::DEFAULT_SIKAP;
            }
        }

        if (isset($columns['sync_version'])) {
            $insert['sync_version'] = $syncVersion;
        }

        DB::table('assessment_scores')->insert($insert);
    }

    protected function writeAudit(
        Exam $exam,
        array $result,
        array $confidence,
        int $ambiguousCount,
        ?string $batchUuid = null,
        ?string $sourceRef = null
    ): void
    {
        if (!Schema::hasTable('ljk_results')) {
            return;
        }

        $key = [
            'exam_id' => $exam->id,
            'student_id' => $result['student_id'],
        ];

        $columns = array_flip(Schema::getColumnListing('ljk_results'));

        $data = [
            'teacher_id' => $exam->teacher_id,
            'teaching_assignment_id' => $result['teaching_assignment_id'],
            'homeroom_id' => $result['homeroom_id'],
            'nisn' => $result['nisn'],
            'exam_date' => $result['exam_date'],
            'source' => $result['source'],
            'question_count' => $result['question_count'],
            'correct_count' => $result['correct_count'],
            'wrong_count' => $result['wrong_count'],
            'score' => $result['score'],
            'answers_json' => json_encode($result['answers'], JSON_UNESCAPED_UNICODE),
            'confidence_json' => $confidence !== []
                ? json_encode($confidence, JSON_UNESCAPED_UNICODE)
                : null,
            'ambiguous_count' => max(0, $ambiguousCount),
            'template_version' => self::TEMPLATE_VERSION,
            'finalized_at' => now(),
            'updated_at' => now(),
        ];

        if (isset($columns['batch_uuid'])) {
            $data['batch_uuid'] = $batchUuid;
        }
        if (isset($columns['source_ref'])) {
            $data['source_ref'] = $sourceRef !== null && $sourceRef !== '' ? $sourceRef : null;
        }

        $existing = DB::table('ljk_results')
            ->where('exam_id', $exam->id)
            ->where('student_id', $result['student_id'])
            ->first();

        if ($existing) {
            DB::table('ljk_results')->where('id', $existing->id)->update($data);
            return;
        }

        DB::table('ljk_results')->insert($key + $data + ['created_at' => now()]);
    }

    protected function writeBatchAudit(
        Exam $exam,
        string $batchUuid,
        string $examDate,
        int $totalItems,
        int $finalizedDelta
    ): void {
        if (!Schema::hasTable('ljk_batches')) {
            return;
        }

        $existing = DB::table('ljk_batches')->where('batch_uuid', $batchUuid)->first();
        $now = now();
        $newTotal = max(1, $totalItems, (int) ($existing->total_items ?? 0));

        // Idempoten: hitung hasil unik yang benar-benar tersimpan untuk batch ini,
        // bukan menambah counter dari jumlah request (request retry tidak menggandakan).
        $storedCount = max(0, $finalizedDelta);
        if (Schema::hasTable('ljk_results') && Schema::hasColumn('ljk_results', 'batch_uuid')) {
            $storedCount = (int) DB::table('ljk_results')
                ->where('batch_uuid', $batchUuid)
                ->count();
        }
        $newFinalized = min($newTotal, $storedCount);
        $status = $newFinalized >= $newTotal ? 'finalized' : 'partial';

        if (!$existing) {
            DB::table('ljk_batches')->insert([
                'batch_uuid' => $batchUuid,
                'exam_id' => $exam->id,
                'teacher_id' => $exam->teacher_id,
                'exam_date' => $examDate,
                'total_items' => $newTotal,
                'finalized_items' => $newFinalized,
                'status' => $status,
                'finalized_at' => $status === 'finalized' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        DB::table('ljk_batches')->where('id', $existing->id)->update([
            'total_items' => $newTotal,
            'finalized_items' => $newFinalized,
            'status' => $status,
            'finalized_at' => $status === 'finalized' ? $now : null,
            'updated_at' => $now,
        ]);
    }

}
