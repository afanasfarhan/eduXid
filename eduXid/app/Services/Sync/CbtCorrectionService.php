<?php

namespace App\Services\Sync;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\StudentAnswer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * CBT CORRECTION SERVICE — rev.4 [KONTRAK-KOREKSI + GUARD-FILLABLE]
 *
 * Kontrak (keputusan desain terkunci):
 *   1. Kunci jawaban TIDAK PERNAH keluar dari server guru.
 *   2. Koreksi terjadi HANYA di server guru. Submit menyimpan jawaban SAJA.
 *   3. SATU-SATUNYA penulis nilai resmi = confirm() — dipanggil guru.
 *      PG / Benar-Salah / Majemuk di-pre-fill otomatis (suggestion);
 *      Esai wajib guru. Konfirmasi guru = nilai resmi.
 *   4. Setelah confirm: nilai_akhir + assessment_scores + cbt_result
 *      (angka + per-soal + FEEDBACK) + markDirty → buku nilai otomatis.
 *   5. Ingest idempoten + FIRST-SUBMIT-WINS.
 *
 * [rev.4 / GUARD-FILLABLE] Semua penulisan ExamSession yang membawa
 * kolom sinkron (sync_id, feedback, nilai_akhir) memakai forceFill —
 * kebal $fillable yang tidak memuat kolom baru (akar bug: Eloquent
 * mengabaikannya diam-diam → sync_id hilang → chip LAN/PWA salah,
 * feedback tak terkirim). Ditambah guard pasca-tulis pada ingest.
 */
class CbtCorrectionService
{
    protected const PERFECT_SCORE_DURATION_FRACTION = 0.25;
    protected const DEFAULT_SIKAP = 3;

    private static ?array $syncVersionColumns = null;

    public function __construct(protected LmsSyncService $sync)
    {
    }

    /* ============================================================
     * SCHEMA-TOLERANT
     * ============================================================ */

    private function hasSyncVersionColumn(string $table): bool
    {
        self::$syncVersionColumns ??= [];

        if (!array_key_exists($table, self::$syncVersionColumns)) {
            try {
                self::$syncVersionColumns[$table] = Schema::hasColumn($table, 'sync_version');
            } catch (Throwable) {
                self::$syncVersionColumns[$table] = false;
            }
        }

        return self::$syncVersionColumns[$table];
    }

    private function stampSyncVersion(string $table, array $data, int $version): array
    {
        if ($this->hasSyncVersionColumn($table)) {
            $data['sync_version'] = $version;
        }

        return $data;
    }

    /* ============================================================
     * FASE 1 — INGEST
     * ============================================================ */

    /**
     * Menyimpan checkpoint PWA tanpa menyelesaikan ujian dan tanpa koreksi.
     * Hanya jawaban yang hadir di payload yang di-upsert; jawaban lain tidak
     * disentuh. Sesi yang sudah selesai tidak pernah dibuka kembali.
     */
    private function normalizeTingkatKelas(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $grade = (int) $value;
            return in_array($grade, [10, 11, 12], true) ? $grade : null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/(?:kelas\s*)?(10|11|12)\b/i', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b(XII|XI|X)\b/i', $text, $m)) {
            return match (strtoupper($m[1])) {
                'X' => 10,
                'XI' => 11,
                'XII' => 12,
                default => null,
            };
        }

        return null;
    }

    private function studentMatchesExamGrade(Exam $exam, Student $student): bool
    {
        $examGrade = $this->normalizeTingkatKelas($exam->tingkat_kelas ?? null);

        if ($examGrade === null) {
            return true;
        }

        $semesterAktif = (string) (DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->whereNotNull('semester_id')
            ->max('semester_id') ?? '');

        $query = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $student->id)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%');

        if ($semesterAktif !== '') {
            $query->where('homerooms.semester_id', $semesterAktif);
        }

        foreach ($query->pluck('homerooms.tingkat_pendidikan') as $rawGrade) {
            if ($this->normalizeTingkatKelas($rawGrade) === $examGrade) {
                return true;
            }
        }

        return false;
    }

    public function checkpoint(array $payload, string $sourceDeviceId): ?array
    {
        $examSyncId = trim((string) ($payload['exam_sync_id'] ?? ''));
        $studentLoginId = trim((string) ($payload['student_id'] ?? ''));
        $sessionSyncId = trim((string) ($payload['sync_id'] ?? ''));
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

        if ($examSyncId === '' || $studentLoginId === '' || $sessionSyncId === '') {
            Log::warning('CBT checkpoint: payload tidak lengkap.', [
                'exam_sync_id' => $examSyncId,
                'student_id' => $studentLoginId,
                'session_sync_id' => $sessionSyncId,
            ]);
            return null;
        }

        $exam = Exam::with('questions')->where('sync_id', $examSyncId)->first();
        if (!$exam) {
            return null;
        }

        /* Setelah guru menutup ujian, checkpoint terlambat cukup diabaikan.
         * Final cbt_answer in-flight tetap boleh masuk melalui ingest(). */
        if ($exam->status === 'selesai') {
            return ['session_id' => null, 'status' => 'checkpoint_ignored_exam_closed'];
        }
        if ($exam->status !== 'aktif') {
            return null;
        }

        $student = Student::where('nisn', $studentLoginId)->first();
        if (!$student || !$this->studentMatchesExamGrade($exam, $student)) {
            return null;
        }

        $waktuMulai = $this->parseClamped($payload['waktu_mulai'] ?? null);
        $checkpointVersion = max(1, (int) ($payload['sync_version'] ?? 1));

        return DB::transaction(function () use (
            $exam, $student, $answers, $sessionSyncId, $waktuMulai, $sourceDeviceId, $checkpointVersion
        ) {
            $session = ExamSession::where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($session && $session->status === 'selesai') {
                return ['session_id' => $session->id, 'status' => 'checkpoint_ignored_session_finished'];
            }

            if ($session && $session->status === 'sedang_mengerjakan') {
                $existingSyncId = trim((string) ($session->sync_id ?? ''));
                if ($existingSyncId !== '' && $existingSyncId !== $sessionSyncId) {
                    Log::warning('CBT checkpoint ditunda: sesi aktif berasal dari sesi/device lain.', [
                        'exam_id' => $exam->id,
                        'student_id' => $student->id,
                        'existing_sync_id' => $existingSyncId,
                        'incoming_sync_id' => $sessionSyncId,
                        'source_device_id' => $sourceDeviceId,
                    ]);
                    return null;
                }
                if ($existingSyncId === '') {
                    Log::warning('CBT checkpoint ditunda: sesi LAN aktif.', [
                        'exam_id' => $exam->id,
                        'student_id' => $student->id,
                        'source_device_id' => $sourceDeviceId,
                    ]);
                    return null;
                }
                if ($this->hasSyncVersionColumn('exam_sessions')
                    && $checkpointVersion <= (int) ($session->sync_version ?? 0)) {
                    return ['session_id' => $session->id, 'status' => 'checkpoint_ignored_old_version'];
                }
            }

            if (!$session) {
                $session = new ExamSession();
                $session->forceFill($this->stampSyncVersion('exam_sessions', [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'sync_id' => $sessionSyncId,
                    'status' => 'sedang_mengerjakan',
                    'waktu_masuk' => $waktuMulai,
                    'waktu_submit' => null,
                    'nilai_akhir' => null,
                ], $checkpointVersion));
                $session->save();
            } else {
                $session->forceFill($this->stampSyncVersion('exam_sessions', [
                    'sync_id' => $session->sync_id ?: $sessionSyncId,
                    'status' => 'sedang_mengerjakan',
                    'waktu_masuk' => $session->waktu_masuk ?: $waktuMulai,
                    'waktu_submit' => null,
                    'nilai_akhir' => null,
                ], $checkpointVersion))->save();
            }

            foreach ($answers as $item) {
                $qid = (int) ($item['question_bank_id'] ?? 0);
                if ($qid <= 0 || !$exam->questions->contains('id', $qid)) {
                    continue;
                }

                $jawaban = $item['jawaban_siswa'] ?? null;
                StudentAnswer::updateOrCreate(
                    [
                        'exam_session_id' => $session->id,
                        'question_bank_id' => $qid,
                    ],
                    [
                        'jawaban_siswa' => is_array($jawaban)
                            ? json_encode($jawaban, JSON_UNESCAPED_UNICODE)
                            : (string) $jawaban,
                        'is_benar' => null,
                        'nilai_per_soal' => 0,
                    ]
                );
            }

            return ['session_id' => $session->id, 'status' => 'checkpoint_saved'];
        });
    }

    public function ingest(array $payload, string $sourceDeviceId): ?array
    {
        $examSyncId = trim((string) ($payload['exam_sync_id'] ?? ''));
        $studentLoginId = trim((string) ($payload['student_id'] ?? ''));
        $sessionSyncId = trim((string) ($payload['sync_id'] ?? ''));
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

        if ($examSyncId === '' || $studentLoginId === '') {
            Log::warning('CBT ingest: payload tidak lengkap.', [
                'exam_sync_id' => $examSyncId,
                'student_id' => $studentLoginId,
                'answers_count' => count($answers),
            ]);
            return null;
        }

        $exam = Exam::with('questions')->where('sync_id', $examSyncId)->first();

        if (!$exam) {
            Log::warning('CBT ingest: exam tidak ditemukan.', ['exam_sync_id' => $examSyncId]);
            return null;
        }

        /*
         * [HARDEN] Exam draft ditolak; 'selesai' diterima (grace untuk
         * submission in-flight saat guru menutup ujian).
         */
        if (!in_array($exam->status, ['aktif', 'selesai'], true)) {
            Log::warning('CBT ingest: exam tidak aktif.', [
                'exam_sync_id' => $examSyncId,
                'status' => $exam->status,
            ]);
            return null;
        }

        $student = Student::where('nisn', $studentLoginId)->first();

        if (!$student) {
            Log::warning('CBT ingest: siswa tidak ditemukan.', ['student_login_id' => $studentLoginId]);
            return null;
        }

        if (!$this->studentMatchesExamGrade($exam, $student)) {
            Log::warning('CBT ingest: tingkat kelas siswa tidak cocok dengan ujian.', ['exam_id' => $exam->id]);
            return null;
        }

        $waktuMulai = $this->parseClamped($payload['waktu_mulai'] ?? null);
        $waktuSubmit = $this->parseClamped($payload['waktu_submit'] ?? null);
        $tanggalCbt = $waktuSubmit->format('Y-m-d');

        $focusLost = (int) ($payload['focus_lost_count'] ?? 0);
        $durasiAktualMenit = max(0, (int) $waktuMulai->diffInMinutes($waktuSubmit));

        if ($focusLost > 0) {
            Log::warning('CBT ANOMALI: siswa keluar aplikasi saat ujian berlangsung.', [
                'exam_judul' => $exam->judul_ujian,
                'exam_sync_id' => $examSyncId,
                'student_login_id' => $studentLoginId,
                'focus_lost_count' => $focusLost,
                'durasi_aktual_menit' => $durasiAktualMenit,
                'durasi_seharusnya' => $exam->durasi_menit,
                'source_device_id' => $sourceDeviceId,
            ]);
        }

        $receipt = DB::transaction(function () use (
            $exam, $student, $answers, $sessionSyncId,
            $waktuMulai, $waktuSubmit, $tanggalCbt
        ) {
            $session = ExamSession::where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($session && $session->status === 'selesai') {
                $samePwaSession = $sessionSyncId !== ''
                    && trim((string) ($session->sync_id ?? '')) === $sessionSyncId;
                $forcedAt = $session->cbt_forced_finish_at
                    ? Carbon::parse($session->cbt_forced_finish_at)
                    : null;
                $wasInFlightBeforeForcedClose = $samePwaSession
                    && $forcedAt !== null
                    && $waktuSubmit->lte($forcedAt->copy()->addSeconds(60));

                /* FIRST-SUBMIT-WINS tetap berlaku. Satu pengecualian:
                 * sesi PWA yang ditutup paksa guru boleh menyelesaikan final
                 * event yang SUDAH dibuat sebelum close (toleransi clock 60 dtk). */
                if (!$wasInFlightBeforeForcedClose) {
                    Log::warning('CBT ingest ditolak: sesi sudah selesai (first-submit-wins).', [
                        'exam_id' => $exam->id,
                        'student_id' => $student->id,
                        'session_sync_id' => $sessionSyncId,
                    ]);
                    return ['session_id' => $session->id, 'status' => 'duplicate_ignored'];
                }

                Log::info('CBT ingest: final PWA in-flight diterima setelah force-finish.', [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'session_sync_id' => $sessionSyncId,
                    'forced_at' => $forcedAt?->toIso8601String(),
                    'client_submit_at' => $waktuSubmit->toIso8601String(),
                ]);
            }

            if ($session && $session->status === 'sedang_mengerjakan') {
                $existingSyncId = trim((string) ($session->sync_id ?? ''));
                $samePwaSession = $sessionSyncId !== '' && $existingSyncId === $sessionSyncId;

                /* Checkpoint PWA membuat sesi hidup di server guru. Final submit
                 * dari sync_id yang SAMA harus boleh menutup sesi itu langsung.
                 * Sesi LAN / device lain tetap dilindungi sampai stale. */
                if (!$samePwaSession) {
                    if (!$this->lanSessionIsStale($session, $exam)) {
                        Log::warning('CBT ingest ditunda: sesi lain masih berjalan.', [
                            'exam_id' => $exam->id,
                            'student_id' => $student->id,
                            'existing_sync_id' => $existingSyncId,
                            'incoming_sync_id' => $sessionSyncId,
                        ]);
                        return null;
                    }

                    Log::warning('CBT ingest: sesi lama stale — submission PWA diteruskan.', [
                        'exam_id' => $exam->id,
                        'student_id' => $student->id,
                    ]);
                }
            }

            if (!$session) {
                /*
                 * [GUARD-FILLABLE rev.4] forceFill — kebal $fillable yang
                 * tidak memuat kolom sinkron baru (sync_id). Dulu create()
                 * biasa membuat kolom itu diabaikan diam-diam.
                 */
                $session = new ExamSession();
                $session->forceFill([
                    'exam_id'      => $exam->id,
                    'student_id'   => $student->id,
                    'sync_id'      => $sessionSyncId !== '' ? $sessionSyncId : null,
                    'status'       => 'selesai',
                    'waktu_masuk'  => $waktuMulai,
                    'waktu_submit' => $waktuSubmit,
                    'cbt_forced_finish_at' => null,
                ]);
                $session->save();
            } else {
                $session->forceFill([
                    'sync_id'      => $session->sync_id ?: ($sessionSyncId !== '' ? $sessionSyncId : null),
                    'status'       => 'selesai',
                    'waktu_masuk'  => $waktuMulai,
                    'waktu_submit' => $waktuSubmit,
                    'cbt_forced_finish_at' => null,
                ])->save();
            }

            /*
             * [GUARD-SYNCID rev.4] Verifikasi pasca-tulis: ingest tidak
             * boleh selesai tanpa sync_id PWA tersimpan. Bila skema/
             * mass-assignment aneh apa pun, paksa sekali lagi.
             */
            if ($sessionSyncId !== '' && trim((string) $session->sync_id) !== $sessionSyncId) {
                $session->forceFill(['sync_id' => $sessionSyncId])->save();
            }

            /*
             * Materialisasi SEMUA soal: yang dijawab disimpan,
             * yang tidak dijawab = string kosong. Guru melihat
             * kekosongan; pembagi bobot jujur.
             */
            $answered = [];

            foreach ($answers as $item) {
                $qid = (int) ($item['question_bank_id'] ?? 0);
                $jawaban = $item['jawaban_siswa'] ?? null;

                if (!$exam->questions->contains('id', $qid)) {
                    continue;
                }

                StudentAnswer::updateOrCreate(
                    [
                        'exam_session_id' => $session->id,
                        'question_bank_id' => $qid,
                    ],
                    [
                        'jawaban_siswa' => is_array($jawaban)
                            ? json_encode($jawaban, JSON_UNESCAPED_UNICODE)
                            : (string) $jawaban,
                        'is_benar' => null,
                        'nilai_per_soal' => 0,
                    ]
                );

                $answered[$qid] = true;
            }

            foreach ($exam->questions as $q) {
                if (isset($answered[$q->id])) {
                    continue;
                }

                StudentAnswer::updateOrCreate(
                    [
                        'exam_session_id' => $session->id,
                        'question_bank_id' => (int) $q->id,
                    ],
                    [
                        'jawaban_siswa' => '',
                        'is_benar' => null,
                        'nilai_per_soal' => 0,
                    ]
                );
            }

            $this->ensureAttendance($exam, $student, $tanggalCbt);

            return ['session_id' => $session->id, 'status' => 'queued_for_correction'];
        });

        /*
         * [OPS-OPSIONAL] Exam full-objektif tanpa guru: auto-confirm.
         * Default OFF — aktifkan via config eduxid.cbt.auto_confirm_objective.
         */
        if (
            ($receipt['status'] ?? '') === 'queued_for_correction'
            && config('eduxid.cbt.auto_confirm_objective', false)
            && !$exam->questions->contains(function ($q): bool {
                $jenis = strtolower(trim((string) ($q->jenis_soal ?? '')));
                return str_contains($jenis, 'esai') || str_contains($jenis, 'singkat');
            })
        ) {
            $session = ExamSession::find($receipt['session_id']);

            if ($session) {
                $result = $this->confirm($session, null, 'Koreksi otomatis (soal objektif).');
                $this->pushCbtResult($result);
            }
        }

        return $receipt;
    }

    /* ============================================================
     * FASE 2 — CONFIRM (satu-satunya penulis nilai resmi)
     * ============================================================ */

    /**
     * @param array<int, array{is_benar: bool}> $overrides  [question_bank_id => ...]
     * @param string|null $feedback  Feedback guru → PWA siswa.
     * @param bool $force  Konfirmasi meski ada soal belum ter-koreksi
     *                     (tak disarankan — dipakai confirmAll).
     *
     * @throws \InvalidArgumentException bila masih ada soal belum dikoreksi.
     */
    public function confirm(
        ExamSession $session,
        ?array $overrides = null,
        ?string $feedback = null,
        bool $force = false
    ): array {
        $exam = Exam::with('questions')->findOrFail($session->exam_id);
        $student = Student::findOrFail($session->student_id);

        if ($session->status !== 'selesai') {
            throw new \InvalidArgumentException(
                'Sesi CBT belum selesai dan tidak dapat dikonfirmasi sebagai nilai resmi.'
            );
        }

        $validQuestionIds = $exam->questions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $overrides = collect($overrides ?? [])
            ->filter(fn ($value, $qid) =>
                $validQuestionIds->has((int) $qid)
                && is_array($value)
                && array_key_exists('is_benar', $value)
            )
            ->mapWithKeys(fn ($value, $qid) => [
                (int) $qid => ['is_benar' => (bool) $value['is_benar']],
            ])
            ->all();
        $answers = StudentAnswer::where('exam_session_id', $session->id)
            ->get()
            ->keyBy('question_bank_id');

        $tanggalCbt = optional($session->waktu_submit)->format('Y-m-d') ?? now()->format('Y-m-d');
        $syncVersion = VersionService::above((int) ($session->sync_version ?? 0));

        $results = [];
        $benar = 0;
        $perolehan = 0.0;
        $totalBobot = 0.0;
        $unresolved = [];

        DB::transaction(function () use (
            $exam, $student, $session, $answers, $overrides, $feedback, $force,
            $tanggalCbt, $syncVersion, &$results, &$benar,
            &$perolehan, &$totalBobot, &$unresolved
        ) {
            foreach ($exam->questions as $q) {
                $qid = (int) $q->id;
                $bobot = (float) ($q->bobot_nilai ?? 1);
                $totalBobot += $bobot;
                $ans = $answers->get($qid);
                $override = $overrides[$qid] ?? null;

                if (is_array($override) && array_key_exists('is_benar', $override)) {
                    $isBenar = (bool) $override['is_benar'];
                    $nilai = $isBenar ? $bobot : 0.0;
                } else {
                    /*
                     * [SUGGESTION] Objektif di-pre-fill otomatis;
                     * Esai (null) wajib guru — tidak bisa di-confirm
                     * tanpa override (kecuali force).
                     */
                    $graded = $this->gradeObjective($q, $ans?->jawaban_siswa);

                    if ($graded === null) {
                        $unresolved[] = $qid;
                        $isBenar = null;
                        $nilai = 0.0;
                    } else {
                        $isBenar = $graded['is_benar'];
                        $nilai = $graded['nilai'];
                    }
                }

                StudentAnswer::where('exam_session_id', $session->id)
                    ->where('question_bank_id', $qid)
                    ->update(['is_benar' => $isBenar, 'nilai_per_soal' => $nilai]);

                if ($isBenar === true) {
                    $benar++;
                    $perolehan += $nilai;
                }

                $results[] = [
                    'question_bank_id' => $qid,
                    'is_benar' => $isBenar,
                    'nilai_per_soal' => $nilai,
                ];
            }

            if ($unresolved !== [] && !$force) {
                throw new \InvalidArgumentException(
                    'Masih ada ' . count($unresolved) . ' soal manual (esai/jawaban singkat) belum dikoreksi. '
                    . 'Koreksi semua soal manual sebelum konfirmasi.'
                );
            }

            /*
             * [FIX-SCORE] Berbobot atas SEMUA soal (soal manual ikut
             * dinilai) — selaras CbtController web.
             */
            $score = $totalBobot > 0 ? (int) round(($perolehan / $totalBobot) * 100) : 0;

            /*
             * [GUARD-FILLABLE rev.4] forceFill — feedback guru tidak
             * pernah tenggelam diam-diam oleh $fillable lagi.
             */
            $session->forceFill($this->stampSyncVersion('exam_sessions', [
                'nilai_akhir' => $score,
                'feedback'    => $feedback !== '' && $feedback !== null ? $feedback : null,
            ], $syncVersion))->save();

            if (!$this->writeDailyAssessment($exam, $student, $tanggalCbt, $score)) {
                throw new \RuntimeException('Nilai CBT belum tersimpan di Buku Nilai; sinkronkan penugasan/enrollment lalu konfirmasi ulang.');
            }
            WorkProductPushService::markDirtyOrFail((int) $exam->teacher_id);
        });

        return [
            'session_sync_id' => $session->sync_id
                ?: ('lan::' . $exam->id . '::' . $student->id),
            'exam_sync_id' => $exam->sync_id,
            'student_id' => (string) $student->nisn,
            'student_name' => $student->nama,
            'score' => $results !== [] ? (int) round(
                $totalBobot > 0 ? (array_sum(array_map(
                    fn ($r) => $r['is_benar'] === true ? $r['nilai_per_soal'] : 0.0,
                    $results
                )) / $totalBobot * 100) : 0
            ) : 0,
            'correct_count' => $benar,
            'total_questions' => $exam->questions->count(),
            'results' => $results,
            'feedback' => $feedback !== '' && $feedback !== null ? $feedback : null,
            'waktu_submit' => optional($session->waktu_submit)->toISOString(),
            'sync_version' => $syncVersion,
        ];
    }

    /**
     * [KEMUDAHAN] Konfirmasi satu klik untuk seluruh sesi pending
     * sebuah exam (exam full-objektif = tanpa koreksi manual sama sekali).
     */
    public function confirmAllForExam(int $examId): array
    {
        $summary = [
            'confirmed' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        $sessions = ExamSession::where('exam_id', $examId)
            ->where('status', 'selesai')
            ->whereNull('nilai_akhir')
            ->get();

        foreach ($sessions as $session) {
            try {
                /*
                 * Nilai lokal adalah hasil utama. Jika push Cloud/PWA gagal,
                 * jangan melaporkan sesi seolah-olah gagal dikoreksi karena
                 * confirm() sudah menulis nilai resmi + Buku Nilai secara
                 * transaksional. Outbox akan mengejar sinkronisasi berikutnya.
                 */
                $result = $this->confirm($session);
                $summary['confirmed']++;

                try {
                    $delivery = $this->pushCbtResult($result);
                    $student = is_array($delivery['student'] ?? null) ? $delivery['student'] : [];
                    $confirmedCloud = ($student['success'] ?? false) === true
                        && in_array((string) ($student['status'] ?? ''), ['accepted', 'duplicate', 'success'], true);
                    if (!$confirmedCloud && ($student['status'] ?? '') !== 'skipped_lan_only') {
                        $summary['warnings'][] = 'Sesi #' . $session->id
                            . ': nilai tersimpan, tetapi Cloud belum mengonfirmasi antrean hasil siswa ('
                            . (string) ($student['error'] ?? $student['status'] ?? 'belum terkonfirmasi') . ').';
                    }
                } catch (Throwable $pushError) {
                    $summary['warnings'][] = 'Sesi #' . $session->id
                        . ': nilai tersimpan, sinkronisasi hasil menunggu antrean ('
                        . $pushError->getMessage() . ').';
                }
            } catch (\InvalidArgumentException $e) {
                $summary['skipped']++; // ada soal manual yang belum dikoreksi
            } catch (Throwable $e) {
                $summary['errors'][] = 'Sesi #' . $session->id . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    /* ============================================================
     * ANTRIAN KOREKSI
     * ============================================================ */

    public function pendingSessions(?int $examId = null)
    {
        $q = ExamSession::with(['exam', 'student'])
            ->where('status', 'selesai')
            ->whereNull('nilai_akhir')
            ->orderBy('waktu_submit');

        if ($examId) {
            $q->where('exam_id', $examId);
        }

        return $q->get();
    }

    /**
     * [F5-FIX] Case-insensitive DI KEDUA sisi — dulu kunci di-lowercase
     * tapi jawaban siswa tidak ("C" vs "c" = false) → semua jawaban
     * PG huruf besar dinilai SALAH walau benar. Majemuk: set-equality
     * tanpa peduli urutan/kapital.
     *
     * @return array{is_benar: bool, nilai: float}|null  null = soal manual.
     */
        /** [LJK-V13R8-MULTI] Exact set equality untuk pilihan majemuk; urutan/kapital diabaikan. */
        /** [LJK-V13R9-MULTI] Exact set equality untuk pilihan majemuk; urutan/kapital diabaikan. */
        /** [LJK-V13R9-MULTI] Exact set equality untuk pilihan majemuk; urutan/kapital diabaikan. */
        /** [LJK-V13R10-MULTI] Normalisasi kunci/jawaban + exact set equality untuk majemuk. */
        /** [LJK-V13R11-MULTI] Normalisasi kunci/jawaban + exact set equality untuk majemuk. */
        /** [LJK-V13R11-MULTI] Normalisasi kunci/jawaban + exact set equality untuk majemuk. */
        /** [LJK-V13R11R1-MULTI] Exact set equality untuk jawaban majemuk. */
    public function gradeObjective($question, $answer): ?array
    {
        $jenis = strtolower(trim((string) $question->jenis_soal));
        if (str_contains($jenis, 'esai') || str_contains($jenis, 'singkat')) {
            return null;
        }

        /*
         * Benar/Salah pada Bank Soal eduXid disimpan sebagai B=Benar dan
         * S=Salah. Jalur LJK mengubah bubble A/B menjadi B/S sebelum masuk
         * ke service ini. Normalisasi khusus ini mencegah "S" dianggap
         * kosong dan memastikan hasil CBT/LJK memakai semantik yang sama.
         */
        if ($jenis === 'benar_salah' || str_contains($jenis, 'benar') || str_contains($jenis, 'salah')) {
            $normalizeBs = static function ($value): ?string {
                if (is_array($value)) {
                    $value = reset($value);
                }

                $raw = strtoupper(trim((string) ($value ?? '')));
                if ($raw === '') {
                    return null;
                }

                return match ($raw) {
                    'B', 'BENAR', 'TRUE', 'T', '1' => 'BENAR',
                    'S', 'SALAH', 'FALSE', 'F', '0' => 'SALAH',
                    default => null,
                };
            };

            $student = $normalizeBs($answer);
            $key = $normalizeBs($question->kunci_jawaban ?? $question->kunci_pg ?? null);
            $benar = $student !== null && $key !== null && $student === $key;

            return [
                'is_benar' => $benar,
                'nilai' => $benar ? (float) ($question->bobot_nilai ?? 1) : 0.0,
            ];
        }

        $normalize = static function ($v): array {
            if (is_string($v)) {
                $raw = trim($v);
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $v = $decoded;
                elseif (preg_match('/^[A-E]{2,5}$/i', $raw)) $v = str_split(strtoupper($raw));
                else $v = preg_split('/[\\s,;|+\\/]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            if (is_array($v) && !array_is_list($v)) {
                $flags=[];
                foreach($v as $k=>$vv){
                    $letter=strtoupper(trim((string)$k));
                    $truthy=$vv===true||$vv===1||$vv==='1'||strtolower(trim((string)$vv))==='true';
                    if($truthy&&in_array($letter,['A','B','C','D','E'],true))$flags[]=$letter;
                }
                if($flags!==[])$v=$flags;
            }
            if(!is_array($v))$v=$v===null?[]:[$v];
            $out=[];
            $add=static function($item)use(&$out,&$add):void{
                if(is_array($item)){foreach($item as $sub)$add($sub);return;}
                $x=strtoupper(trim((string)$item));
                if(preg_match('/^[A-E]{2,5}$/',$x)){foreach(str_split($x) as $ch)$add($ch);return;}
                if(in_array($x,['A','B','C','D','E'],true)&&!in_array($x,$out,true))$out[]=$x;
            };
            foreach($v as $item)$add($item);
            sort($out); return $out;
        };

        $ansSet=$normalize($answer);
        $keySet=$normalize($question->kunci_jawaban ?? $question->kunci_pg ?? null);
        if($keySet===[])return ['is_benar'=>false,'nilai'=>0.0];
        $majemuk=str_contains($jenis,'majemuk')||str_contains($jenis,'multiple')||str_contains($jenis,'multi')||count($keySet)>1;
        $benar=$majemuk ? ($ansSet===$keySet) : (count($ansSet)===1&&count($keySet)===1&&$ansSet[0]===$keySet[0]);
        return ['is_benar'=>$benar,'nilai'=>$benar?(float)($question->bobot_nilai??1):0.0];
    }

    /* ============================================================
     * NILAI HARIAN + ABSENSI
     * ============================================================ */

    protected function writeDailyAssessment(Exam $exam, Student $student, string $tanggalCbt, int $score): bool
    {
        $studentHomeroomIds = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $student->id)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->pluck('enrollments.homeroom_id');

        if ($studentHomeroomIds->isEmpty()) {
            Log::warning('CBT koreksi: siswa tanpa rombel reguler - nilai harian dilewati.', [
                'exam_id' => $exam->id, 'student_id' => $student->id,
            ]);
            return false;
        }

        $taId = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $exam->teacher_id)
            ->where('teaching_assignments.subject_id', $exam->subject_id)
            ->whereIn('teaching_assignments.homeroom_id', $studentHomeroomIds)
            ->orderByDesc('homerooms.semester_id')
            ->orderByDesc('teaching_assignments.id')
            ->value('teaching_assignments.id');

        if (!$taId) {
            Log::warning('CBT koreksi: TA guru+mapel tidak ditemukan - nilai harian dilewati.', [
                'exam_id' => $exam->id, 'student_id' => $student->id,
            ]);
            return false;
        }

        $kolomNilai = in_array($exam->jenis_penilaian ?? '', ['diagnostik', 'formatif', 'sumatif'], true)
            ? $exam->jenis_penilaian
            : 'sumatif';
        $materi = 'Ujian CBT: ' . $exam->judul_ujian;

        // Grain sama dengan input manual dan tugas: siswa + TA + tanggal.
        // Materi dan D1..D8 pada pertemuan yang sudah ada dipertahankan.
        $saved = app(DailyAssessmentWriter::class)->writeScore(
            (int) $student->id, (int) $taId, $tanggalCbt, $kolomNilai, $score, $materi
        );
        if (($saved['saved'] ?? false) !== true) {
            throw new \RuntimeException('Penulisan nilai harian CBT gagal: ' . ($saved['reason'] ?? 'writer gagal'));
        }

        $this->ensureAttendance($exam, $student, $tanggalCbt);

        return true;
    }

    /**
     * [FIX-GRAIN] Absensi Hadir — INSERT-if-missing SAJA. Status
     * manual guru (Sakit/Izin/Alpa) TIDAK pernah ditimpa.
     */
    public function ensureAttendancePublic(Exam $exam, Student $student, string $tanggalCbt): void
    {
        DB::transaction(function () use ($exam, $student, $tanggalCbt): void {
            $this->ensureAttendance($exam, $student, $tanggalCbt);
            WorkProductPushService::markDirtyOrFail((int) $exam->teacher_id);
        });
    }

    protected function ensureAttendance(Exam $exam, Student $student, string $tanggalCbt): void
    {
        $studentHomeroomIds = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $student->id)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->pluck('enrollments.homeroom_id');

        if ($studentHomeroomIds->isEmpty()) {
            return;
        }

        $taId = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $exam->teacher_id)
            ->where('teaching_assignments.subject_id', $exam->subject_id)
            ->whereIn('teaching_assignments.homeroom_id', $studentHomeroomIds)
            ->orderByDesc('homerooms.semester_id')
            ->orderByDesc('teaching_assignments.id')
            ->value('teaching_assignments.id');

        if (!$taId) {
            return;
        }

        app(DailyAssessmentWriter::class)->ensureAttendance((int) $student->id, (int) $taId, $tanggalCbt);
    }

    /* ============================================================
     * PUSH cbt_result ke PWA siswa (angka + per-soal + feedback;
     * TANPA kunci — results hanya {question_bank_id, is_benar})
     * ============================================================ */

    public function pushCbtResult(array $result): array
    {
        $examSyncId = trim((string) ($result['exam_sync_id'] ?? ''));

        $exam = $examSyncId !== ''
            ? Exam::where('sync_id', $examSyncId)->first()
            : null;

        // Exam LAN-only (tanpa sync_id) tidak punya jalur PWA — skip.
        if (!$exam || empty($exam->sync_id)) {
            return ['success' => true, 'status' => 'skipped_lan_only'];
        }

        $results = ['student' => null];

        /* ---------- 1) HP SISWA (kontrak asli) ---------- */
        try {
            $target = app(SchoolIdentity::class)->studentDeviceId((string) $result['student_id']);
        } catch (Throwable $e) {
            Log::warning('CBT result: device siswa tidak dapat ditentukan.', [
                'student_id' => $result['student_id'],
                'error' => $e->getMessage(),
            ]);
            $results['student'] = ['success' => false, 'status' => 'no_device'];
        }

        if (isset($target)) {
            $results['student'] = $this->sync->queueAndPush(
                'cbt_result',
                (string) $result['session_sync_id'],
                'upsert',
                (int) $result['sync_version'],
                $result,
                $target,
                null
            );
        }

        /*
         * [F5] Salinan ke PERANGKAT GURU (HP & PC) — agar panel "Jawaban
         * CBT" di PWA guru menampilkan skor hasil koreksi. Dulu hanya
         * siswa yang jadi tujuan → PWA guru selalu "Menunggu koreksi".
         * event_id diberi suffix unik per target (cloud dedupe per id),
         * payload tetap membawa session_sync_id ASLI (kunci merge di
         * klien).
         */
        try {
            $teacher = \App\Models\Teacher::find($exam->teacher_id);
            $loginId = null;

            if ($teacher) {
                if (!empty($teacher->user_id)) {
                    $loginId = \App\Models\User::query()->where('id', $teacher->user_id)->value('login_id');
                }

                if (!$loginId) {
                    foreach (['nip', 'nik', 'nuptk'] as $col) {
                        $cand = trim((string) ($teacher->{$col} ?? ''));
                        if ($cand !== '') {
                            $hit = \App\Models\User::query()->where('login_id', $cand)->value('login_id');
                            if ($hit) { $loginId = $hit; break; }
                        }
                    }
                }
            }

            if ($loginId) {
                $identity = app(SchoolIdentity::class);
                $teacherVersion = \App\Services\Sync\VersionService::next();

                foreach (['HP', 'PC'] as $suffix) {
                    try {
                        $teacherDevice = ($suffix === 'PC' ? $identity->teacherPcDeviceId((string) $loginId) : $identity->teacherHpDeviceId((string) $loginId));

                        $results['teacher_' . strtolower($suffix)] = $this->sync->queueAndPush(
                            'cbt_result',
                            (string) $result['session_sync_id'] . '::guru::' . $suffix,
                            'upsert',
                            $teacherVersion,
                            $result,
                            $teacherDevice,
                            null
                        );
                    } catch (Throwable $e) {
                        // Device guru tidak terdaftar — lewati, siswa tetap dilayani.
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('CBT result: salinan ke perangkat guru gagal (siswa tetap dilayani).', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /* ============================================================
     * HELPERS
     * ============================================================ */

    protected function parseClamped($value): Carbon
    {
        try {
            $dt = $value ? Carbon::parse($value) : now();
        } catch (Throwable) {
            return now();
        }

        // [CLOCK-GUARD] Jam HP offline bisa meleset — clamp 1 hari.
        if ($dt->gt(now()->addDay())) { $dt = now(); }

        // Submit offline yang lama tetap memakai tanggal asli. Semua jalur
        // memakai zona waktu Laravel agar UTC dari HP tidak bergeser hari.
        return $dt->timezone(config('app.timezone', 'UTC'));
    }

    /** Sesi LAN hanya boleh digantikan setelah durasinya benar-benar lewat. */
    private function lanSessionIsStale(ExamSession $session, Exam $exam): bool
    {
        $duration = (int) ($exam->durasi_menit ?? 0);
        if ($duration <= 0 || !$session->waktu_masuk) {
            return false;
        }

        try {
            $deadline = Carbon::parse($session->waktu_masuk)->addMinutes($duration);
            return now()->greaterThan($deadline);
        } catch (Throwable) {
            return false;
        }
    }
}
