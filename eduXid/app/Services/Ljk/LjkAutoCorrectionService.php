<?php

namespace App\Services\Ljk;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\Teacher;
use App\Services\Sync\CbtCorrectionService;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\LjkScanDeletionMarker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LjkAutoCorrectionService
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_REVIEW = 'review';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_FAILED = 'failed';
    public const CONFIDENCE_MIN = 0.65;

    public function __construct(
        protected CbtCorrectionService $correction,
        protected LmsSyncService $sync
    ) {
    }

    public function teacherId(): int
    {
        $user = Auth::user();
        if (!$user) {
            throw new RuntimeException('Sesi guru tidak tersedia.');
        }

        // Sebagian instalasi eduXid memakai tabel teachers tanpa kolom user_id.
        // Jika relasi ini tidak ada, identitas guru harus dicocokkan dengan
        // login_id autentikasi (NIP, NIK, atau NUPTK); jangan buat query kolom
        // yang tidak ada atau memakai guru lain sebagai fallback.
        if (Schema::hasColumn('teachers', 'user_id')) {
            $teacherId = (int) Teacher::query()->where('user_id', $user->id)->value('id');
            if ($teacherId > 0) {
                return $teacherId;
            }
        }

        $login = trim((string) ($user->login_id ?? ''));
        if ($login !== '') {
            $teacherId = (int) Teacher::query()
                ->where(function ($q) use ($login) {
                    $q->where('nip', $login)->orWhere('nik', $login)->orWhere('nuptk', $login);
                })
                ->value('id');
        }

        if ($teacherId <= 0) {
            throw new RuntimeException('Data guru untuk akun ini tidak ditemukan.');
        }

        return $teacherId;
    }

    public function exams()
    {
        // Daftar Paket Ujian harus tetap dapat dibuka meskipun ada paket
        // melebihi kapasitas LJK. Kompatibilitas diperiksa per paket di bawah.
        $allowed = ['pilihan_ganda', 'majemuk', 'benar_salah'];

        return Exam::query()
            ->where('teacher_id', $this->teacherId())
            ->with(['questions:id,jenis_soal'])
            ->withCount('questions')
            ->orderByDesc('updated_at')
            ->get()
            ->each(function (Exam $exam) use ($allowed): void {
                $exam->setAttribute(
                    'ljk_compatible',
                    $exam->questions->isNotEmpty()
                    && $exam->questions->count() <= 50
                    && $exam->questions->every(
                        fn ($q) => in_array(strtolower(trim((string) ($q->jenis_soal ?? ''))), $allowed, true)
                    )
                );
            });
    }

    private function ensureExamSyncId(Exam $exam): Exam
    {
        if (trim((string) ($exam->sync_id ?? '')) === '') {
            $exam->forceFill(['sync_id' => (string) Str::uuid()])->save();
            $exam->refresh();
        }
        return $exam;
    }

    public function ownedExam(int $examId): Exam
    {
        $exam = Exam::query()
            ->with('questions')
            ->whereKey($examId)
            ->where('teacher_id', $this->teacherId())
            ->first();

        if (!$exam) {
            throw new RuntimeException('Paket Ujian tidak ditemukan atau bukan milik guru ini.');
        }

        $exam = $this->ensureExamSyncId($exam);
        $exam->setRelation('questions', $exam->questions->sortBy(
            fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? $q->id)
        )->values());
        return $exam;
    }

    public function examByPayload(array $payload): Exam
    {
        $syncId = trim((string) ($payload['exam_sync_id'] ?? ''));
        $examId = (int) ($payload['exam_id'] ?? 0);

        $q = Exam::query()->with('questions');
        if ($syncId !== '') {
            $q->where('sync_id', $syncId);
        } elseif ($examId > 0) {
            $q->whereKey($examId);
        } else {
            throw new RuntimeException('Referensi Paket Ujian LJK tidak tersedia.');
        }

        $exam = $q->first();
        if (!$exam) {
            throw new RuntimeException('Paket Ujian hasil LJK belum tersedia di laptop guru.');
        }

        $exam->setRelation('questions', $exam->questions->sortBy(
            fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? $q->id)
        )->values());
        return $exam;
    }

    private function assertLjkCompatible(Exam $exam): void
    {
        if ($exam->questions->isEmpty()) {
            throw new RuntimeException('Paket Ujian belum memiliki soal.');
        }

        // LJK A4 fisik memiliki 50 nomor jawaban. Setiap pernyataan
        // dalam soal kategori menempati satu nomor terpisah.
        // Tolak paket tidak kompatibel sebelum scanner, ekspor, atau sinkronisasi.
        if ($exam->questions->count() > 50) {
            throw new RuntimeException(
                'LJK A4 mendukung maksimum 50 slot jawaban. '
                . 'Setiap pernyataan kategori dihitung sebagai satu slot.'
            );
        }

        $allowed = ['pilihan_ganda', 'majemuk', 'benar_salah'];
        $unsupported = [];

        foreach ($exam->questions->values() as $index => $question) {
            $type = strtolower(trim((string) ($question->jenis_soal ?? '')));
            if (!in_array($type, $allowed, true)) {
                $unsupported[] = ($index + 1) . ' (' . ($type !== '' ? $type : 'tanpa jenis') . ')';
            }
        }

        if ($unsupported !== []) {
            throw new RuntimeException(
                'Paket Ujian tidak kompatibel dengan Koreksi LJK. '
                . 'LJK hanya menerima soal Pilihan Ganda, Pilihan Majemuk, dan Benar/Salah. '
                . 'Periksa soal nomor: ' . implode(', ', $unsupported) . '.'
            );
        }
    }

    private function isTrueFalseQuestion($question): bool
    {
        return strtolower(trim((string) ($question->jenis_soal ?? ''))) === 'benar_salah';
    }

    /**
     * Bubble LJK Benar/Salah memakai A=Benar, B=Salah, sedangkan Bank Soal
     * menyimpan kunci/response kanonik B=Benar, S=Salah. Terjemahkan hanya
     * pada jalur LJK agar service CBT tetap membaca format Bank Soal normal.
     */
    private function answerForOfficialCorrection($question, mixed $answer): mixed
    {
        if (!$this->isTrueFalseQuestion($question)) {
            return $answer;
        }

        if (is_array($answer)) {
            $answer = reset($answer);
        }

        $raw = strtoupper(trim((string) ($answer ?? '')));
        return match ($raw) {
            'A', 'BENAR', 'TRUE', 'T' => 'B',
            'B', 'SALAH', 'FALSE', 'F' => 'S',
            default => $raw,
        };
    }

    private function keyForLjkPackage($question): mixed
    {
        $key = $question->kunci_jawaban ?? null;
        $type = strtolower(trim((string) ($question->jenis_soal ?? '')));

        if ($type === 'benar_salah') {
            $raw = strtoupper(trim((string) ($key ?? '')));
            return match ($raw) {
                'B', 'BENAR', 'TRUE', 'T' => 'A',
                'S', 'SALAH', 'FALSE', 'F' => 'B',
                'A' => 'A',
                default => $raw,
            };
        }

        if (is_string($key)) {
            $trimmed = trim($key);
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $key = $decoded;
            }
        }

        if ($type === 'majemuk') {
            $items = is_array($key) ? $key : preg_split('/[\s,;|+\/]+/u', (string) $key, -1, PREG_SPLIT_NO_EMPTY);
            $out = [];
            foreach ((array) $items as $item) {
                $v = strtoupper(trim((string) $item));
                if (in_array($v, ['A', 'B', 'C', 'D', 'E'], true) && !in_array($v, $out, true)) {
                    $out[] = $v;
                }
            }
            sort($out);
            return $out;
        }

        return strtoupper(trim((string) ($key ?? '')));
    }

    /**
     * Kunci manual milik guru dan Paket Ujian (tidak mengubah Bank Soal).
     * Setiap scan menyimpan snapshot kuncinya sendiri agar nilai audit tidak
     * bergeser ketika guru memperbarui kunci untuk pemindaian berikutnya.
     */
    private function manualFingerprint(Exam $exam): string
    {
        return hash('sha256', json_encode($exam->questions->values()->map(fn ($q) => [
            (int) $q->id, strtolower((string) $q->jenis_soal)
        ])->all(), JSON_UNESCAPED_UNICODE));
    }

    private function normalizeManualKeys(Exam $exam, array $submitted): array
    {
        $keys = [];
        foreach ($exam->questions->values() as $index => $question) {
            $qid = (int) $question->id;
            $raw = $submitted[(string) $qid] ?? $submitted[$qid] ?? null;
            $type = strtolower(trim((string) ($question->jenis_soal ?? '')));
            $allowed = $type === 'benar_salah' ? ['A', 'B'] : ['A', 'B', 'C', 'D', 'E'];
            if ($type === 'majemuk') {
                $tokens = is_array($raw) ? $raw : preg_split('/[\s,;|+\/]+/', strtoupper(trim((string) ($raw ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
                $value = array_values(array_unique(array_map(static fn ($v) => strtoupper(trim((string) $v)), (array) $tokens)));
                sort($value);
                if ($value === [] || count(array_diff($value, $allowed)) > 0) {
                    throw new RuntimeException('Kunci manual nomor ' . ($index + 1) . ' (pilihan majemuk) wajib A–E, minimal satu pilihan.');
                }
            } else {
                $value = strtoupper(trim((string) ($raw ?? '')));
                if (!in_array($value, $allowed, true)) {
                    throw new RuntimeException('Kunci manual nomor ' . ($index + 1) . ' wajib satu pilihan ' . implode('/', $allowed) . '.');
                }
            }
            $keys[(string) $qid] = $value;
        }
        if (count($keys) !== $exam->questions->count()) {
            throw new RuntimeException('Kunci manual belum lengkap.');
        }
        return $keys;
    }

    private function manualKeyRow(Exam $exam): ?object
    {
        if (!Schema::hasTable('ljk_manual_answer_keys')) return null;
        return DB::table('ljk_manual_answer_keys')
            ->where('teacher_id', (int) $exam->teacher_id)
            ->where('exam_id', (int) $exam->id)
            ->first();
    }

    private function manualKeyConfig(Exam $exam): array
    {
        $row = $this->manualKeyRow($exam);
        $keys = $row ? (json_decode((string) $row->keys_json, true) ?: []) : [];
        $stale = $row && (bool) $row->is_active && (string) $row->questions_fingerprint !== $this->manualFingerprint($exam);
        return [
            'source' => ($row && $row->is_active && !$stale) ? 'manual' : 'package',
            'saved_source' => ($row && $row->is_active) ? 'manual' : 'package',
            'has_manual_key' => (bool) $row,
            'keys' => $stale ? [] : $keys,
            'stale' => (bool) $stale,
            'version' => (int) ($row->version ?? 0),
        ];
    }

    public function saveManualKeys(int $examId, array $input): array
    {
        $exam = $this->ownedExam($examId);
        $this->assertLjkCompatible($exam);
        $source = (string) ($input['source'] ?? '');
        if (!in_array($source, ['manual', 'package'], true)) {
            throw new RuntimeException('Sumber kunci jawaban harus Paket Ujian atau Manual.');
        }
        $submitted = $input['keys'] ?? [];
        if ($source === 'manual' && !is_array($submitted)) {
            throw new RuntimeException('Daftar kunci jawaban manual tidak valid.');
        }
        $keys = $source === 'manual' ? $this->normalizeManualKeys($exam, $submitted) : [];
        DB::transaction(function () use ($exam, $source, $keys): void {
            $row = $this->manualKeyRow($exam);
            if ($source === 'package' && !$row) return;
            $now = now();
            if ($row) {
                DB::table('ljk_manual_answer_keys')->where('id', $row->id)->update([
                    'is_active' => $source === 'manual',
                    'keys_json' => $source === 'manual' ? json_encode($keys, JSON_UNESCAPED_UNICODE) : $row->keys_json,
                    'questions_fingerprint' => $source === 'manual' ? $this->manualFingerprint($exam) : $row->questions_fingerprint,
                    'version' => (int) $row->version + 1,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('ljk_manual_answer_keys')->insert([
                    'teacher_id' => (int) $exam->teacher_id,
                    'exam_id' => (int) $exam->id,
                    'keys_json' => json_encode($keys, JSON_UNESCAPED_UNICODE),
                    'questions_fingerprint' => $this->manualFingerprint($exam),
                    'is_active' => true,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
        return $this->manualKeyConfig($exam);
    }

    /** Hapus kunci manual aktif dari Paket Ujian, tanpa menyentuh Bank Soal dan hasil yang telah dikoreksi. */
    public function deleteManualKeys(int $examId): array
    {
        $exam = $this->ownedExam($examId);
        DB::transaction(function () use ($exam): void {
            DB::table('ljk_manual_answer_keys')
                ->where('teacher_id', (int) $exam->teacher_id)
                ->where('exam_id', (int) $exam->id)
                ->delete();
        });
        return $this->manualKeyConfig($exam);
    }

    private function gradingQuestion($question, mixed $key)
    {
        $gradedQuestion = clone $question;
        $value = $key;
        if ($this->isTrueFalseQuestion($question)) {
            $value = strtoupper((string) $key) === 'A' ? 'B' : 'S';
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $gradedQuestion->setAttribute('kunci_jawaban', $value);
        return $gradedQuestion;
    }

    private function manualOverrides(Exam $exam, array $answers, array $rawKeys): array
    {
        $keys = $this->normalizeManualKeys($exam, $rawKeys);
        $overrides = [];
        foreach ($exam->questions->values() as $index => $question) {
            $qid = (int) $question->id;
            $answer = $this->answerAt($answers, $qid, $index + 1);
            $graded = $this->correction->gradeObjective(
                $this->gradingQuestion($question, $keys[(string) $qid]),
                $this->answerForOfficialCorrection($question, $answer)
            );
            if ($graded === null) throw new RuntimeException('Kunci manual hanya mendukung soal objektif LJK.');
            $overrides[$qid] = ['is_benar' => $graded['is_benar'] === true];
        }
        return $overrides;
    }

    public function normalizeNisn(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if ($digits === '') {
            return '';
        }
        return str_pad(substr($digits, -10), 10, '0', STR_PAD_LEFT);
    }

    private function assertRemoteSourceOwnsExam(Exam $exam, string $sourceDeviceId): void
    {
        $sourceDeviceId = trim($sourceDeviceId);
        if ($sourceDeviceId === '') {
            throw new RuntimeException('Source device hasil LJK kosong.');
        }

        $base = preg_replace('/-(HP|PC)$/i', '', $sourceDeviceId) ?: $sourceDeviceId;
        $parts = explode('-', $base, 2);
        $loginId = trim((string) ($parts[1] ?? ''));
        if ($loginId === '') {
            throw new RuntimeException('Identitas guru pada source device LJK tidak valid.');
        }

        $teacher = Teacher::query()->find($exam->teacher_id);
        if (!$teacher) {
            throw new RuntimeException('Guru pemilik Paket Ujian tidak ditemukan.');
        }

        $allowed = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            [$teacher->nip ?? null, $teacher->nik ?? null, $teacher->nuptk ?? null]
        )));

        if ($allowed !== [] && !in_array($loginId, $allowed, true)) {
            throw new RuntimeException('Hasil LJK ditolak: source device bukan milik guru pemilik Paket Ujian.');
        }
    }

    private function studentByNisn(string $nisn): ?Student
    {
        if ($nisn === '') {
            return null;
        }

        $student = Student::query()->where('nisn', $nisn)->first();
        if ($student) {
            return $student;
        }

        $withoutZeros = ltrim($nisn, '0');
        if ($withoutZeros !== '') {
            return Student::query()->where('nisn', $withoutZeros)->first();
        }

        return null;
    }

    public function resolveTeachingAssignment(Exam $exam, ?Student $student = null): ?int
    {
        if (!Schema::hasTable('teaching_assignments')) {
            return null;
        }

        $q = DB::table('teaching_assignments')->where('teacher_id', $exam->teacher_id);
        if (!empty($exam->subject_id) && Schema::hasColumn('teaching_assignments', 'subject_id')) {
            $q->where('subject_id', $exam->subject_id);
        }

        if ($student && Schema::hasTable('enrollments') && Schema::hasColumn('teaching_assignments', 'homeroom_id')) {
            $homerooms = DB::table('enrollments')
                ->where('student_id', $student->id)
                ->pluck('homeroom_id')
                ->filter()
                ->all();
            if ($homerooms !== []) {
                $q->whereIn('homeroom_id', $homerooms);
            }
        }

        $id = (int) $q->orderByDesc('id')->value('id');
        return $id > 0 ? $id : null;
    }

    private function homeroomIdsForExam(Exam $exam): array
    {
        $ids = collect();

        if (Schema::hasTable('lms_assignments') && Schema::hasTable('lms_topics') && Schema::hasTable('lms_courses')) {
            $ids = $ids->merge(
                DB::table('lms_assignments')
                    ->join('lms_topics', 'lms_topics.id', '=', 'lms_assignments.topic_id')
                    ->join('lms_courses', 'lms_courses.id', '=', 'lms_topics.course_id')
                    ->where('lms_assignments.exam_id', $exam->id)
                    ->pluck('lms_courses.homeroom_id')
            );
        }

        if (Schema::hasColumn('exams', 'homeroom_id') && !empty($exam->homeroom_id)) {
            $ids->push((int) $exam->homeroom_id);
        }

        if ($ids->filter(fn ($v) => (int) $v > 0)->isEmpty() && Schema::hasTable('teaching_assignments')) {
            $fallback = DB::table('teaching_assignments')
                ->where('teacher_id', (int) $exam->teacher_id)
                ->when(
                    !empty($exam->subject_id) && Schema::hasColumn('teaching_assignments', 'subject_id'),
                    fn ($q) => $q->where('subject_id', (int) $exam->subject_id)
                )
                ->pluck('homeroom_id');
            $ids = $ids->merge($fallback);
        }

        return $ids
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function homeroomForExam(Exam $exam): ?int
    {
        $ids = $this->homeroomIdsForExam($exam);
        return $ids !== [] ? (int) $ids[0] : null;
    }

    private function rosterForExam(Exam $exam): array
    {
        $homeroomIds = $this->homeroomIdsForExam($exam);
        if ($homeroomIds === [] || !Schema::hasTable('enrollments')) {
            return [];
        }

        $classColumn = Schema::hasColumn('homerooms', 'nama') ? 'homerooms.nama'
            : (Schema::hasColumn('homerooms', 'name') ? 'homerooms.name' : null);
        $q = DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('homerooms', 'homerooms.id', '=', 'enrollments.homeroom_id')
            ->whereIn('enrollments.homeroom_id', $homeroomIds)
            ->orderBy('students.nama');
        $columns = ['students.id', 'students.nisn', 'students.nama', 'enrollments.homeroom_id'];
        if ($classColumn) $columns[] = DB::raw($classColumn . ' as class_name');
        return $q->get($columns)
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'nisn' => $this->normalizeNisn($s->nisn),
                'nama' => (string) $s->nama,
                'homeroom_id' => (int) $s->homeroom_id,
                'class_name' => (string) ($s->class_name ?? ''),
            ])
            ->filter(fn ($s) => $s['nisn'] !== '')
            ->unique('nisn')
            ->values()
            ->all();
    }

    private function studentBelongsToExamRoster(Exam $exam, Student $student): bool
    {
        $homeroomIds = $this->homeroomIdsForExam($exam);
        if ($homeroomIds === [] || !Schema::hasTable('enrollments')) {
            return true;
        }

        return DB::table('enrollments')
            ->where('student_id', (int) $student->id)
            ->whereIn('homeroom_id', $homeroomIds)
            ->exists();
    }

    public function package(int $examId): array
    {
        $exam = $this->ownedExam($examId);
        $this->assertLjkCompatible($exam);
        $questions = [];
        foreach ($exam->questions as $index => $q) {
            $questions[] = [
                'question_bank_id' => (int) $q->id,
                'number' => $index + 1,
                'type' => (string) $q->jenis_soal,
                'weight' => (float) ($q->bobot_nilai ?? 1),
            ];
        }

        return [
            'exam' => [
                'id' => (int) $exam->id,
                'sync_id' => (string) $exam->sync_id,
                'title' => (string) ($exam->judul_ujian ?? ('Paket #' . $exam->id)),
                'question_count' => count($questions),
                'homeroom_id' => $this->homeroomForExam($exam),
                'homeroom_ids' => $this->homeroomIdsForExam($exam),
            ],
            'questions' => $questions,
            'students' => $this->rosterForExam($exam),
            'template_version' => 'LJK-A4-V3',
            'manual_key' => $this->manualKeyConfig($exam),
        ];
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

    public function grade(Exam $exam, array $answers, ?array $manualKeys = null): array
    {
        $correct = 0;
        $earned = 0.0;
        $totalWeight = 0.0;
        $normalized = [];

        foreach ($exam->questions as $index => $question) {
            $qid = (int) $question->id;
            $answer = $this->answerAt($answers, $qid, $index + 1);
            $normalized[$qid] = $answer;
            $weight = (float) ($question->bobot_nilai ?? 1);
            $totalWeight += $weight;

            $graded = $this->correction->gradeObjective(
                $manualKeys === null ? $question : $this->gradingQuestion(
                    $question, $manualKeys[(string) $qid] ?? ''
                ),
                $this->answerForOfficialCorrection($question, $answer)
            );
            if ($graded === null) {
                continue;
            }
            if ($graded['is_benar'] === true) {
                $correct++;
                $earned += (float) $graded['nilai'];
            }
        }

        return [
            'answers' => $normalized,
            'correct_count' => $correct,
            'question_count' => $exam->questions->count(),
            'score' => $totalWeight > 0 ? round(($earned / $totalWeight) * 100, 2) : 0.0,
        ];
    }

    private function statusFor(
        ?Student $student,
        array $input,
        int $expected,
        bool $duplicate,
        bool $studentInRoster = true
    ): array {
        if ($duplicate) {
            return [self::STATUS_DUPLICATE, 'DUPLICATE', 'Siswa sudah memiliki hasil aktif untuk Paket Ujian yang sama.'];
        }
        if (!$student) {
            return [self::STATUS_REVIEW, 'NISN_NOT_FOUND', 'NISN tidak terbaca atau tidak ditemukan pada data siswa.'];
        }
        if (!$studentInRoster) {
            return [self::STATUS_REVIEW, 'STUDENT_OUTSIDE_ROSTER', 'NISN ditemukan, tetapi siswa bukan anggota rombel Paket Ujian ini.'];
        }
        if (($input['marker_ok'] ?? true) === false || ($input['scan_ok'] ?? true) === false) {
            return [self::STATUS_REVIEW, 'MARKER_OR_SCAN', 'Marker/posisi LJK tidak terbaca dengan cukup baik.'];
        }
        $ambiguous = (int) ($input['ambiguous_count'] ?? 0);
        if ($ambiguous > 0) {
            return [self::STATUS_REVIEW, 'AMBIGUOUS_ANSWER', $ambiguous . ' jawaban terdeteksi ambigu.'];
        }
        if (array_key_exists('confidence', $input) && $input['confidence'] !== null && (float) $input['confidence'] < self::CONFIDENCE_MIN) {
            return [self::STATUS_REVIEW, 'LOW_CONFIDENCE', 'Confidence pembacaan di bawah ambang aman.'];
        }
        $detected = isset($input['detected_question_count']) ? (int) $input['detected_question_count'] : null;
        if ($detected !== null && $detected > 0 && $detected !== $expected) {
            return [self::STATUS_REVIEW, 'STRUCTURE_MISMATCH', 'Jumlah area jawaban tidak sesuai Paket Ujian.'];
        }
        return [self::STATUS_SUCCESS, null, null];
    }

    private function boolValue(mixed $value, bool $default = true): bool
    {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    private function saveCore(array $input, ?UploadedFile $image = null, ?string $sourceDeviceId = null, bool $owned = true): array
    {
        $exam = $owned ? $this->ownedExam((int) ($input['exam_id'] ?? 0)) : $this->examByPayload($input);
        $this->assertLjkCompatible($exam);
        if (!$owned) {
            $this->assertRemoteSourceOwnsExam($exam, (string) $sourceDeviceId);
        }
        $scanId = trim((string) ($input['scan_id'] ?? '')) ?: (string) Str::uuid();

        $answers = $input['answers'] ?? [];
        if (is_string($answers)) {
            $answers = json_decode($answers, true) ?: [];
        }
        if (!is_array($answers)) $answers = [];

        $input['marker_ok'] = $this->boolValue($input['marker_ok'] ?? true);
        $input['scan_ok'] = $this->boolValue($input['scan_ok'] ?? true);

        $nisn = $this->normalizeNisn($input['nisn'] ?? $input['student_nisn'] ?? '');
        $student = $this->studentByNisn($nisn);
        $studentInRoster = $student ? $this->studentBelongsToExamRoster($exam, $student) : false;
        $manualConfig = $this->manualKeyConfig($exam);
        if ($manualConfig['stale'] && $manualConfig['saved_source'] === 'manual') {
            throw new RuntimeException('Susunan soal berubah. Simpan ulang kunci jawaban manual sebelum memindai.');
        }
        $manualKeys = $manualConfig['source'] === 'manual' ? $manualConfig['keys'] : null;
        if ($manualKeys !== null) $manualKeys = $this->normalizeManualKeys($exam, $manualKeys);
        $graded = $this->grade($exam, $answers, $manualKeys);

        $duplicate = false;
        if ($student) {
            $duplicate = DB::table('ljk_scan_results')
                ->where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->where('scan_id', '<>', $scanId)
                ->exists();
        }

        [$status, $issueCode, $issueMessage] = $this->statusFor(
            $student,
            $input,
            $graded['question_count'],
            $duplicate,
            $studentInRoster
        );

        $imagePath = null;
        if ($image && $image->isValid()) {
            $ext = strtolower($image->getClientOriginalExtension() ?: 'jpg');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
            $imagePath = $image->storeAs('private/ljk-scans/' . now()->format('Ym'), $scanId . '.' . $ext);
        }

        $old = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        $row = [
            'scan_id' => $scanId,
            'exam_id' => $exam->id,
            'exam_sync_id' => $exam->sync_id,
            'teaching_assignment_id' => $student ? $this->resolveTeachingAssignment($exam, $student) : null,
            'student_id' => $student?->id,
            'nisn' => $nisn ?: null,
            'student_name' => $student?->nama ?? ($input['student_name'] ?? null),
            'template_version' => (string) ($input['template_version'] ?? 'LJK-A4-V3'),
            'source_type' => (string) ($input['source_type'] ?? 'image'),
            'source_name' => $input['source_name'] ?? null,
            'source_index' => isset($input['source_index']) ? (int) $input['source_index'] : null,
            'answers_json' => json_encode($graded['answers'], JSON_UNESCAPED_UNICODE),
            'key_source' => $manualKeys !== null ? 'manual' : 'package',
            'manual_keys_json' => $manualKeys !== null ? json_encode($manualKeys, JSON_UNESCAPED_UNICODE) : null,
            'answer_confidence_json' => isset($input['answer_confidence'])
                ? json_encode($input['answer_confidence'], JSON_UNESCAPED_UNICODE)
                : null,
            'question_count' => (int) $graded['question_count'],
            'correct_count' => (int) $graded['correct_count'],
            'score' => (float) $graded['score'],
            'confidence' => isset($input['confidence']) ? (float) $input['confidence'] : null,
            'status' => $status,
            'issue_code' => $issueCode,
            'issue_message' => $issueMessage,
            'is_active' => true,
            'device_id' => $input['device_id'] ?? null,
            'source_device_id' => $sourceDeviceId ?? ($input['source_device_id'] ?? null),
            'scanned_at' => now(),
            'updated_at' => now(),
        ];

        if ($imagePath) $row['image_path'] = $imagePath;
        if ($old) {
            DB::table('ljk_scan_results')->where('scan_id', $scanId)->update($row);
        } else {
            $row['created_at'] = now();
            DB::table('ljk_scan_results')->insert($row);
        }
        return $this->resultByScanId($scanId);
    }

    public function saveScan(array $input, ?UploadedFile $image = null): array
    {
        return $this->saveCore($input, $image, (string) config('eduxid.device_id', ''), true);
    }

    public function ingestRemote(array $payload, string $sourceDeviceId): array
    {
        $payload['source_type'] = 'pwa';
        return $this->saveCore($payload, null, $sourceDeviceId, false);
    }

    public function deleteRemote(array $payload, string $sourceDeviceId): void
    {
        $scanId = trim((string) ($payload['scan_id'] ?? $payload['sync_id'] ?? ''));
        if ($scanId === '') return;
        $row = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        if (!$row) return;
        if ($sourceDeviceId !== '' && !empty($row->source_device_id) && $row->source_device_id !== $sourceDeviceId) {
            throw new RuntimeException('Perangkat sumber tidak sesuai dengan hasil LJK yang akan dihapus.');
        }
        if (!empty($row->published_at)) {
            throw new RuntimeException('Hasil LJK sudah masuk Buku Nilai dan tidak boleh dihapus. Gunakan Edit untuk memperbaiki hasil scan.');
        }
        DB::transaction(function () use ($row): void {
            LjkScanDeletionMarker::mark(
                (string) $row->scan_id,
                (string) ($row->exam_sync_id ?? ''),
                (string) ($row->source_device_id ?? '')
            );
            $this->deleteRow($row);
        });
    }

    public function resultByScanId(string $scanId): array
    {
        $row = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        if (!$row) throw new RuntimeException('Hasil LJK tidak ditemukan.');
        $classNames = [];
        if (!empty($row->student_id)) {
            $name = $this->classNameForExamStudent((int) $row->exam_id, (int) $row->student_id);
            if ($name !== '') $classNames[(int) $row->student_id] = $name;
        }
        return $this->serialize($row, $classNames);
    }

    private function classNameForExamStudent(int $examId, int $studentId): string
    {
        if ($studentId <= 0 || !Schema::hasTable('enrollments') || !Schema::hasTable('homerooms')) return '';
        $exam = Exam::query()->find($examId);
        if (!$exam) return '';
        $allowed = $this->homeroomIdsForExam($exam);
        $query = DB::table('enrollments as e')
            ->join('homerooms as h', 'h.id', '=', 'e.homeroom_id')
            ->where('e.student_id', $studentId);
        if ($allowed) $query->whereIn('e.homeroom_id', $allowed);
        return (string) ($query->orderByDesc('e.id')->value('h.nama') ?? '');
    }

    public function results(?int $examId = null): array
    {
        $q = DB::table('ljk_scan_results as r')
            ->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->where('e.teacher_id', $this->teacherId())
            ->where('r.is_active', true)
            ->select('r.*')
            ->orderByDesc('r.id');
        if ($examId) $q->where('r.exam_id', $examId);
        $items = $q->limit(1000)->get();
        $studentIds = $items->pluck('student_id')->filter()->unique()->values()->all();
        $classNames = [];
        if ($studentIds !== [] && Schema::hasTable('enrollments') && Schema::hasTable('homerooms')) {
            $col = Schema::hasColumn('homerooms', 'nama') ? 'homerooms.nama'
                : (Schema::hasColumn('homerooms', 'name') ? 'homerooms.name' : null);
            if ($col) {
                $classes = DB::table('enrollments')
                    ->join('homerooms', 'homerooms.id', '=', 'enrollments.homeroom_id')
                    ->whereIn('enrollments.student_id', $studentIds);
                if ($examId) {
                    $exam = Exam::query()->find($examId);
                    $allowed = $exam ? $this->homeroomIdsForExam($exam) : [];
                    if ($allowed !== []) $classes->whereIn('enrollments.homeroom_id', $allowed);
                }
                foreach ($classes->orderByDesc('enrollments.id')
                    ->get(['enrollments.student_id', DB::raw($col . ' as class_name')]) as $c) {
                    $id = (int) $c->student_id;
                    if (!isset($classNames[$id])) $classNames[$id] = (string) $c->class_name;
                }
            }
        }
        return $items->map(fn ($r) => $this->serialize($r, $classNames))->all();
    }

    private function serialize(object $r, array $classNames = []): array
    {
        return [
            'id' => (int) $r->id,
            'scan_id' => (string) $r->scan_id,
            'exam_id' => (int) $r->exam_id,
            'exam_sync_id' => (string) ($r->exam_sync_id ?? ''),
            'student_id' => $r->student_id ? (int) $r->student_id : null,
            'nisn' => (string) ($r->nisn ?? ''),
            'student_name' => (string) ($r->student_name ?? ''),
            'class_name' => (string) ($classNames[(int) ($r->student_id ?? 0)] ?? ''),
            'answers' => json_decode((string) ($r->answers_json ?? '{}'), true) ?: [],
            'question_count' => (int) $r->question_count,
            'correct_count' => (int) $r->correct_count,
            'score' => (float) $r->score,
            'key_source' => (string) ($r->key_source ?? 'package'),
            'confidence' => $r->confidence !== null ? (float) $r->confidence : null,
            'status' => (string) $r->status,
            'issue_code' => $r->issue_code,
            'issue_message' => $r->issue_message,
            'source_type' => (string) $r->source_type,
            'source_name' => $r->source_name,
            'has_image' => !empty($r->image_path),
            'published' => !empty($r->published_at),
            'scanned_at' => $r->scanned_at,
        ];
    }

    public function review(string $scanId, array $input): array
    {
        $current = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        if (!$current) throw new RuntimeException('Hasil LJK tidak ditemukan.');

        $exam = $this->ownedExam((int) $current->exam_id);
        $wasPublished = !empty($current->published_at);
        $nisn = $this->normalizeNisn($input['nisn'] ?? $current->nisn);
        $student = $this->studentByNisn($nisn);
        if (!$student) {
            throw new RuntimeException('NISN hasil Edit tidak ditemukan pada data siswa.');
        }
        if (!$this->studentBelongsToExamRoster($exam, $student)) {
            throw new RuntimeException('NISN hasil Edit bukan anggota rombel Paket Ujian ini.');
        }

        if ($wasPublished && (int) ($current->student_id ?? 0) !== (int) $student->id) {
            throw new RuntimeException(
                'Hasil ini sudah masuk Buku Nilai. NISN tidak dapat dialihkan ke siswa lain setelah publikasi. '
                . 'Perbaiki NISN sebelum Kirim ke Buku Nilai; untuk nilai yang sudah terlanjur salah gunakan koreksi nilai resmi.'
            );
        }

        $answers = $input['answers'] ?? json_decode((string) $current->answers_json, true) ?? [];
        if (is_string($answers)) $answers = json_decode($answers, true) ?: [];
        $snapshot = (string) ($current->key_source ?? 'package') === 'manual'
            ? (json_decode((string) ($current->manual_keys_json ?? ''), true) ?: []) : null;
        if ($snapshot !== null) $snapshot = $this->normalizeManualKeys($exam, $snapshot);
        $graded = $this->grade($exam, is_array($answers) ? $answers : [], $snapshot);

        $duplicate = DB::table('ljk_scan_results')
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->where('scan_id', '<>', $scanId)
            ->exists();

        /*
         * Baris yang sudah dipublikasikan adalah sumber nilai resmi untuk
         * siswa tersebut. Jika kemudian ada scan duplikat lain, baris resmi
         * tetap menjadi authoritative dan Edit harus tetap dapat memperbaiki
         * salah baca scanner + menulis ulang Buku Nilai. Jangan menurunkan
         * baris resmi menjadi DUPLICATE hanya karena scan lain muncul belakangan.
         */
        if ($wasPublished) {
            $duplicate = false;
        }

        $update = [
            'student_id' => $student->id,
            'nisn' => $nisn,
            'student_name' => $student->nama,
            'teaching_assignment_id' => $this->resolveTeachingAssignment($exam, $student),
            'answers_json' => json_encode($graded['answers'], JSON_UNESCAPED_UNICODE),
            'question_count' => $graded['question_count'],
            'correct_count' => $graded['correct_count'],
            'score' => $graded['score'],
            'status' => $duplicate ? self::STATUS_DUPLICATE : self::STATUS_SUCCESS,
            'issue_code' => $duplicate ? 'DUPLICATE' : null,
            'issue_message' => $duplicate ? 'Siswa sudah memiliki hasil aktif lain untuk Paket Ujian yang sama.' : null,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ];

        /*
         * Edit setelah publikasi tetap diperbolehkan untuk memperbaiki salah
         * baca scanner. Tandai belum-publish sesaat, lalu tulis ulang nilai
         * resmi melalui jalur confirm() yang sama agar Buku Nilai konsisten.
         */
        if ($wasPublished) {
            $update['published_at'] = null;
        }

        DB::table('ljk_scan_results')->where('scan_id', $scanId)->update($update);

        $republish = null;
        if ($wasPublished && !$duplicate) {
            $republish = $this->publishExamRows($exam, [$scanId]);
        }

        $result = $this->resultByScanId($scanId);
        if ($republish !== null) {
            $result['republish'] = $republish;
        }

        return $result;
    }

    /**
     * Arsipkan seluruh hasil pemindaian milik guru untuk satu Paket Ujian.
     * Tidak mengubah assessment_scores, CBT, nilai akhir, maupun Buku Nilai.
     * Mengarsipkan (bukan menghapus permanen) baris published untuk audit.
     */
    public function clearExamResults(int $examId): array
    {
        $exam = $this->ownedExam($examId);

        return DB::transaction(function () use ($exam): array {
            $query = DB::table('ljk_scan_results')
                ->where('exam_id', $exam->id)
                ->where('is_active', true);
            $total = (clone $query)->count();
            $published = (clone $query)->whereNotNull('published_at')->count();
            if ($total > 0) {
                // Pertahankan bukti penghapusan per scan agar hasil PWA yang
                // tertunda tidak hidup kembali ketika Cloud mengirim backlog.
                foreach ((clone $query)->get(['scan_id', 'exam_sync_id', 'source_device_id']) as $row) {
                    LjkScanDeletionMarker::mark(
                        (string) $row->scan_id,
                        trim((string) ($row->exam_sync_id ?? '')) ?: trim((string) ($exam->sync_id ?? '')),
                        (string) ($row->source_device_id ?? '')
                    );
                }
                $query->update(['is_active' => false, 'updated_at' => now()]);
            }

            return [
                'archived' => (int) $total,
                'published_preserved' => (int) $published,
                'book_grades_unchanged' => true,
            ];
        });
    }

    private function deleteRow(object $row): void
    {
        if (!empty($row->image_path)) {
            try { Storage::delete($row->image_path); } catch (Throwable) {}
        }
        DB::table('ljk_scan_results')->where('id', $row->id)->delete();
    }

    public function delete(string $scanId): void
    {
        $row = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        if (!$row) return;
        $this->ownedExam((int) $row->exam_id);
        if (!empty($row->published_at)) {
            throw new RuntimeException('Hasil LJK sudah masuk Buku Nilai dan tidak boleh dihapus. Gunakan Edit untuk memperbaiki hasil scan.');
        }
        $this->deleteRow($row);
    }

    public function imagePath(string $scanId): string
    {
        $row = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        if (!$row || empty($row->image_path)) throw new RuntimeException('Foto sumber LJK tidak ditemukan.');
        $this->ownedExam((int) $row->exam_id);
        $path = Storage::path($row->image_path);
        if (!is_file($path)) throw new RuntimeException('File foto sumber LJK sudah tidak tersedia.');
        return $path;
    }

    private function answersForIngest(Exam $exam, array $map): array
    {
        $items = [];
        foreach ($exam->questions as $index => $q) {
            $qid = (int) $q->id;
            $items[] = [
                'question_bank_id' => $qid,
                'jawaban_siswa' => $this->answerAt($map, $qid, $index + 1),
            ];
        }
        return $items;
    }

    private function prepareOfficialSessionFromLjk(Exam $exam, object $row, array $map): ExamSession
    {
        /* LJK-MATERIALIZE-GUARD-V15R6_2 */
        if (empty($row->student_id) || empty($row->nisn)) {
            throw new RuntimeException('Identitas siswa belum valid. Perbaiki hasil melalui Edit terlebih dahulu.');
        }

        $student = Student::query()->find((int) $row->student_id);
        if (!$student) {
            throw new RuntimeException('Data siswa hasil LJK tidak ditemukan.');
        }

        $questions = $exam->questions->values();
        $expectedQuestionCount = $questions->count();
        if ($expectedQuestionCount <= 0) {
            throw new RuntimeException(
                'Paket Ujian tidak memiliki daftar soal pada saat materialisasi LJK. '
                . 'Nilai tidak akan dikirim ke Buku Nilai.'
            );
        }

        $teachingAssignmentId = $this->resolveTeachingAssignment($exam, $student);
        if (!$teachingAssignmentId) {
            throw new RuntimeException(
                'Penugasan mengajar guru + mata pelajaran + rombel siswa tidak ditemukan. '
                . 'Nilai belum dapat dikirim ke Buku Nilai.'
            );
        }

        $sessionSyncId = 'ljk::' . $exam->sync_id . '::' . $row->nisn;

        return DB::transaction(function () use (
            $exam,
            $row,
            $map,
            $sessionSyncId,
            $teachingAssignmentId,
            $questions,
            $expectedQuestionCount
        ) {
            $session = ExamSession::query()
                ->where('sync_id', $sessionSyncId)
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $existing = ExamSession::query()
                    ->where('exam_id', $exam->id)
                    ->where('student_id', (int) $row->student_id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existingSync = trim((string) ($existing->sync_id ?? ''));

                    if (!str_starts_with($existingSync, 'ljk::')) {
                        if ($existing->nilai_akhir !== null) {
                            throw new RuntimeException(
                                'Siswa sudah memiliki nilai resmi dari sesi CBT untuk Paket Ujian ini. '
                                . 'Hasil LJK tidak akan menimpa nilai tersebut.'
                            );
                        }

                        throw new RuntimeException(
                            'Siswa memiliki sesi CBT yang belum selesai dikoreksi. '
                            . 'Selesaikan atau hapus sesi CBT tersebut sebelum mengirim hasil LJK.'
                        );
                    }

                    $session = $existing;
                }
            }

            if (!$session) {
                $session = new ExamSession();
            }

            /*
             * Saat hasil LJK yang sudah pernah dipublikasikan diedit, sesi
             * resmi dipakai ulang. Pertahankan tanggal submit asli agar
             * pembaruan nilai tidak membuat baris Buku Nilai baru di tanggal
             * edit dan meninggalkan nilai lama sebagai duplikat.
             */
            $waktuMasuk = $session->waktu_masuk ?: now()->subMinute();
            $waktuSubmit = $session->waktu_submit ?: now();

            $session->forceFill([
                'exam_id' => (int) $exam->id,
                'student_id' => (int) $row->student_id,
                'sync_id' => $sessionSyncId,
                'status' => 'selesai',
                'nilai_akhir' => null,
                'feedback' => null,
                'waktu_masuk' => $waktuMasuk,
                'waktu_submit' => $waktuSubmit,
            ])->save();

            /*
             * Bangun ulang jawaban secara deterministik. Jalur ini sengaja
             * memakai Query Builder agar tidak bergantung pada fillable/model
             * event. Sesi LJK saja yang disentuh; sesi CBT biasa dilindungi di
             * guard di atas.
             */
            DB::table('student_answers')
                ->where('exam_session_id', (int) $session->id)
                ->delete();

            $now = now();
            foreach ($questions as $index => $question) {
                $qid = (int) $question->id;
                $answer = $this->answerAt($map, $qid, $index + 1);
                $officialAnswer = $this->answerForOfficialCorrection($question, $answer);

                DB::table('student_answers')->insert([
                    'exam_session_id' => (int) $session->id,
                    'question_bank_id' => $qid,
                    'jawaban_siswa' => is_array($officialAnswer)
                        ? json_encode(array_values($officialAnswer), JSON_UNESCAPED_UNICODE)
                        : (string) $officialAnswer,
                    'is_benar' => null,
                    'nilai_per_soal' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $actualQuestionIds = DB::table('student_answers')
                ->where('exam_session_id', (int) $session->id)
                ->pluck('question_bank_id')
                ->map(static fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            $expectedQuestionIds = $questions
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if (
                count($actualQuestionIds) !== $expectedQuestionCount
                || $actualQuestionIds !== $expectedQuestionIds
            ) {
                throw new RuntimeException(
                    'Materialisasi jawaban LJK gagal: expected=' . $expectedQuestionCount
                    . ', actual=' . count($actualQuestionIds)
                    . '. Nilai tidak dikirim ke Buku Nilai.'
                );
            }

            DB::table('ljk_scan_results')
                ->where('id', $row->id)
                ->update([
                    'teaching_assignment_id' => $teachingAssignmentId,
                    'updated_at' => now(),
                ]);

            return $session->fresh();
        });
    }

    private function normalizeBookGradeAsLjk(
        Exam $exam,
        object $row,
        ExamSession $session
    ): void {
        $student = Student::query()->find((int) $row->student_id);
        if (!$student) {
            throw new RuntimeException('Data siswa tidak ditemukan saat memberi label Buku Nilai LJK.');
        }

        $taId = $this->resolveTeachingAssignment($exam, $student);
        if (!$taId) {
            throw new RuntimeException('Penugasan mengajar tidak tersedia saat memberi label Buku Nilai LJK.');
        }

        if (!Schema::hasTable('assessment_scores')) {
            throw new RuntimeException('Tabel Buku Nilai assessment_scores tidak tersedia.');
        }

        $tanggal = optional($session->waktu_submit)->format('Y-m-d') ?: now()->toDateString();
        $cbtMateri = 'Ujian CBT: ' . $exam->judul_ujian;
        $ljkMateri = 'Ujian LJK: ' . $exam->judul_ujian;
        $scoreColumn = in_array(
            (string) ($exam->jenis_penilaian ?? ''),
            ['diagnostik', 'formatif', 'sumatif'],
            true
        ) ? (string) $exam->jenis_penilaian : 'sumatif';

        $cbtRow = DB::table('assessment_scores')
            ->where('student_id', (int) $row->student_id)
            ->where('teaching_assignment_id', $taId)
            ->where('tanggal', $tanggal)
            ->where('materi', $cbtMateri)
            ->first();

        $ljkRow = DB::table('assessment_scores')
            ->where('student_id', (int) $row->student_id)
            ->where('teaching_assignment_id', $taId)
            ->where('tanggal', $tanggal)
            ->where('materi', $ljkMateri)
            ->first();

        if ($cbtRow && $ljkRow && (int) $cbtRow->id !== (int) $ljkRow->id) {
            /*
             * Jika hasil LJK pernah dikirim sebelumnya, pertahankan baris
             * LJK (termasuk d1..d8 milik guru) dan masukkan nilai terbaru,
             * lalu hapus baris CBT sementara yang dibuat confirm().
             */
            DB::table('assessment_scores')
                ->where('id', (int) $ljkRow->id)
                ->update([
                    $scoreColumn => (int) $session->nilai_akhir,
                    'materi' => $ljkMateri,
                    'updated_at' => now(),
                ]);

            DB::table('assessment_scores')
                ->where('id', (int) $cbtRow->id)
                ->delete();

            return;
        }

        if ($cbtRow) {
            DB::table('assessment_scores')
                ->where('id', (int) $cbtRow->id)
                ->update([
                    'materi' => $ljkMateri,
                    $scoreColumn => (int) $session->nilai_akhir,
                    'updated_at' => now(),
                ]);

            return;
        }

        if ($ljkRow) {
            DB::table('assessment_scores')
                ->where('id', (int) $ljkRow->id)
                ->update([
                    $scoreColumn => (int) $session->nilai_akhir,
                    'updated_at' => now(),
                ]);

            return;
        }

        throw new RuntimeException(
            'Nilai resmi sudah dihitung, tetapi baris Buku Nilai belum ditemukan untuk diberi label Ujian LJK.'
        );
    }

    private function assertBookGradeWritten(
        Exam $exam,
        object $row,
        ExamSession $session
    ): void {
        $student = Student::query()->find((int) $row->student_id);
        if (!$student) {
            throw new RuntimeException('Data siswa tidak ditemukan saat verifikasi Buku Nilai.');
        }

        $taId = $this->resolveTeachingAssignment($exam, $student);
        if (!$taId) {
            throw new RuntimeException('Penugasan mengajar tidak tersedia saat verifikasi Buku Nilai.');
        }

        if (!Schema::hasTable('assessment_scores')) {
            throw new RuntimeException('Tabel Buku Nilai assessment_scores tidak tersedia.');
        }

        $tanggal = optional($session->waktu_submit)->format('Y-m-d') ?: now()->toDateString();
        $materi = 'Ujian LJK: ' . $exam->judul_ujian;

        $written = DB::table('assessment_scores')
            ->where('student_id', (int) $row->student_id)
            ->where('teaching_assignment_id', $taId)
            ->where('tanggal', $tanggal)
            ->where('materi', $materi)
            ->exists();

        if (!$written) {
            throw new RuntimeException(
                'Koreksi selesai, tetapi nilai dengan keterangan "Ujian LJK" belum ditemukan pada Buku Nilai.'
            );
        }
    }

    public function publish(int $examId, array $scanIds = []): array
    {
        return $this->publishExamRows($this->ownedExam($examId), $scanIds);
    }

    public function publishFromSync(int $examId, array $scanIds = []): array
    {
        $exam = Exam::query()->with('questions')->findOrFail($examId);
        $exam->setRelation('questions', $exam->questions->sortBy(
            fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? $q->id)
        )->values());

        return $this->publishExamRows($exam, $scanIds);
    }

    private function publishExamRows(Exam $exam, array $scanIds = []): array
    {
        $q = DB::table('ljk_scan_results')
            ->where('exam_id', $exam->id)
            ->where('is_active', true)
            ->where('status', self::STATUS_SUCCESS)
            ->whereNotNull('student_id')
            ->whereNull('published_at');

        if ($scanIds !== []) {
            $q->whereIn('scan_id', $scanIds);
        }

        $rows = $q->orderBy('id')->get();
        $summary = [
            'published' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        if ($rows->isEmpty()) {
            $summary['skipped'] = count($scanIds);
            return $summary;
        }

        foreach ($rows as $row) {
            try {
                $map = json_decode((string) $row->answers_json, true) ?: [];

                /*
                 * LJK adalah ujian kertas; jangan melewati ingest CBT yang
                 * mensyaratkan status exam aktif/selesai. Materialisasikan
                 * jawaban ke sesi resmi lokal, lalu tetap gunakan confirm()
                 * sebagai SATU-SATUNYA penulis nilai resmi.
                 */
                $session = $this->prepareOfficialSessionFromLjk($exam, $row, $map);

                /* LJK-MATERIALIZE-GUARD-V15R6_2: jangan pernah confirm sesi kosong. */
                $expectedMaterialized = $exam->questions->count();
                $actualMaterialized = StudentAnswer::query()
                    ->where('exam_session_id', (int) $session->id)
                    ->count();

                if ($expectedMaterialized <= 0 || $actualMaterialized !== $expectedMaterialized) {
                    throw new RuntimeException(
                        'Guard materialisasi LJK menolak koreksi: expected=' . $expectedMaterialized
                        . ', actual=' . $actualMaterialized
                        . '. Nilai resmi tidak ditulis.'
                    );
                }

                // Manual: override benar/salah pada mekanisme resmi confirm().
                // Kunci asli Bank Soal tidak disentuh. PWA Guru menerima nilai
                // lewat WorkProductPushService::markDirtyOrFail di confirm().
                $manualOverrides = null;
                if ((string) ($row->key_source ?? 'package') === 'manual') {
                    $snapshot = json_decode((string) ($row->manual_keys_json ?? ''), true);
                    if (!is_array($snapshot) || !$snapshot) {
                        throw new RuntimeException('Snapshot kunci manual scan tidak tersedia. Nilai belum dikirim.');
                    }
                    $manualOverrides = $this->manualOverrides($exam, $map, $snapshot);
                    $verified = $this->grade($exam, $map, $snapshot);
                    if (abs((float) $verified['score'] - (float) $row->score) > 0.011
                        || (int) $verified['correct_count'] !== (int) $row->correct_count) {
                        throw new RuntimeException('Nilai scan tidak konsisten dengan snapshot kunci manual. Review ulang sebelum Kirim ke Buku Nilai.');
                    }
                }
                $result = $this->correction->confirm(
                    $session,
                    $manualOverrides,
                    $manualOverrides !== null ? 'Koreksi LJK dengan kunci manual guru.' : 'Koreksi LJK otomatis.'
                );

                $session->refresh();
                if ($session->nilai_akhir === null) {
                    throw new RuntimeException('Nilai resmi sesi belum terbentuk setelah proses koreksi.');
                }

                $this->normalizeBookGradeAsLjk($exam, $row, $session);
                $this->assertBookGradeWritten($exam, $row, $session);

                /*
                 * Buku Nilai sudah berhasil. Sinkron hasil ke siswa adalah
                 * langkah tambahan; kegagalan Cloud tidak boleh membatalkan
                 * nilai lokal yang sudah sah.
                 */
                try {
                    $this->correction->pushCbtResult($result);
                } catch (Throwable $pushError) {
                    $summary['warnings'][] =
                        ($row->student_name ?: $row->nisn ?: $row->scan_id)
                        . ': nilai masuk Buku Nilai, tetapi sinkron hasil ke siswa belum berhasil: '
                        . $pushError->getMessage();
                }

                DB::table('ljk_scan_results')
                    ->where('id', $row->id)
                    ->update([
                        'published_at' => now(),
                        'updated_at' => now(),
                    ]);

                $summary['published']++;
            } catch (Throwable $e) {
                $summary['errors'][] =
                    ($row->student_name ?: $row->nisn ?: $row->scan_id)
                    . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    private function teacherHpDeviceId(): string
    {
        $pc = trim((string) $this->sync->getDeviceId());
        if ($pc !== '' && preg_match('/-PC$/i', $pc)) {
            return preg_replace('/-PC$/i', '-HP', $pc);
        }

        $user = Auth::user();
        $login = trim((string) ($user->login_id ?? ''));
        $school = trim((string) (config('eduxid.school_id') ?: config('eduxid.npsn') ?: env('EDUXID_SCHOOL_ID', '')));
        if ($school !== '' && $login !== '') return $school . '-' . $login . '-HP';
        throw new RuntimeException('Device ID PWA Guru tidak dapat ditentukan. Pastikan laptop guru sudah pairing Cloud.');
    }

    public function syncPrivatePackageToPwa(int $examId): array
    {
        $exam = $this->ownedExam($examId);
        $this->assertLjkCompatible($exam);
        $target = $this->teacherHpDeviceId();
        $manualConfig = $this->manualKeyConfig($exam);
        if ($manualConfig['stale'] && $manualConfig['saved_source'] === 'manual') {
            throw new RuntimeException('Kunci manual usang karena susunan soal berubah. Simpan ulang kunci sebelum mengirim ke PWA.');
        }
        $manualKeys = $manualConfig['source'] === 'manual'
            ? $this->normalizeManualKeys($exam, $manualConfig['keys']) : null;
        $questions = [];
        foreach ($exam->questions as $index => $q) {
            $key = $manualKeys !== null
                ? $manualKeys[(string) $q->id]
                : $this->keyForLjkPackage($q);
            $questions[] = [
                'question_bank_id' => (int) $q->id,
                'number' => $index + 1,
                'jenis_soal' => (string) $q->jenis_soal,
                'bobot_nilai' => (float) ($q->bobot_nilai ?? 1),
                'kunci_jawaban' => $key,
            ];
        }

        $version = (int) floor(microtime(true) * 1000);
        $pkgSyncId = 'ljkpkg::' . $exam->sync_id;
        $payload = [
            'sync_id' => $pkgSyncId,
            'entity' => 'ljk_private_package',
            'exam_id' => (int) $exam->id,
            'exam_sync_id' => (string) $exam->sync_id,
            // V19R2: Worker authorizes private LJK keys against teacher scope.
            'teacher_id' => (int) $exam->teacher_id,
            'judul_ujian' => (string) $exam->judul_ujian,
            'template_version' => 'LJK-A4-V3',
            'key_source' => $manualKeys !== null ? 'manual' : 'package',
            'key_version' => (int) $manualConfig['version'],
            'questions' => $questions,
            'students' => $this->rosterForExam($exam),
            'target_device_id' => $target,
            'sync_version' => $version,
            'updated_at' => now()->toISOString(),
        ];

        $result = $this->sync->queueAndPush(
            'lms_assignment_submission',
            $pkgSyncId,
            'update',
            $version,
            $payload,
            $target,
            null
        );

        return ['target_device_id' => $target, 'package_sync_id' => $pkgSyncId, 'sync' => $result];
    }
}
