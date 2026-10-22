<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Services\Sync\CbtCorrectionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class CbtController extends Controller
{
    /*
     * ==========================================================
     * [REWRITE-07] CBT CONTROLLER — KONTRAK-KOREKSI
     * ==========================================================
     * Perubahan vs versi lama (REWRITE-06.2):
     *
     *   1. [KONTRAK-KOREKSI] submit() TIDAK mengoreksi apa pun.
     *      Jawaban disimpan dengan is_benar = NULL (penanda antrean
     *      koreksi), nilai_akhir = NULL. SATU-SATUNYA penulis nilai
     *      resmi = CbtCorrectionService::confirm() yang dipicu guru.
     *      Kunci jawaban tidak pernah menyentuh controller ini.
     *      (Dulu: auto-grade exact-match — esai selalu 0, dan nilai
     *      final tertulis tanpa guru melihatnya.)
     *
     *   2. [ABSENSI-ONLY] Penulisan assessment_scores DIHAPUS dari
     *      submit (pindah ke confirm()). Yang tersisa: kehadiran
     *      'Hadir' — fakta, bukan penilaian — via service
     *      (INSERT-if-missing; status manual guru Sakit/Izin/Alpa
     *      tidak pernah ditimpa). SIKAP_DEFAULT dihapus dari sini.
     *
     *   3. [MATERIALIZZE] Soal yang TIDAK dijawab tetap dibuatkan
     *      baris (jawaban kosong, is_benar NULL) — antrian koreksi
     *      di LAN identik dengan jalur PWA (ingest service).
     *
     *   4. [TX-FIX] Submit dalam transaksi + lockForUpdate + re-check
     *      status — double-submit/double-tab tidak lagi menggandakan
     *      penilaian (di-times dua request lolos check bersamaan).
     *
     *   5. [C6] Ownership guard: sesi CBT terikat ke sesi Laravel;
     *      sessionId milik orang lain tidak bisa dibuka/disubmit.
     *      [FIX-FATAL] Eager-load relasi lewat array (dulu memanggil
     *      properti 'exam' pada Builder = fatal).
     *
     *   6. [DURATION] Durasi dicek server-side (waktu_masuk + durasi);
     *      autosave ditolak setelah habis, submit lewat waktu DITERIMA
     *      dan ditandai di log.
     *
     *   7. [ACTIVE-GUARD] Ujian non-aktif: halaman soal, autosave,
     *      dan submit sama-sama menolak (dulu halaman ujian tetap
     *      terbuka setelah ujian ditutup).
     *
     *   8. [LOGIN-HARDEN] Rate limit 5/5 menit, token di-trim sebelum
     *      validasi, session regenerate pasca-login (anti fixation),
     *      NISN di-mask di log (PII), fallback lookup siswa dicatat.
     *
     *   9. [MAJEMUK] jenis_soal diteruskan ke view — frontend LAN
     *      merender checkbox untuk 'Majemuk', radio untuk lainnya.
     *      Jawaban majemuk dikirim sebagai array (disimpan JSON).
     *
     *  10. [RACE-SESSION] firstOrCreate dilindungi unique index
     *      (exam_id, student_id) dari migrasi integritas; tabrakan
     *      race ditangani dengan re-fetch, bukan 500.
     * ==========================================================
     */

    /**
     * Batas ukuran payload jawaban (bytes) — sanity check agar
     * request raksasa tidak membebani DB.
     */
    protected const MAX_ANSWERS_PAYLOAD_BYTES = 512000;

    /* ==========================================================
     * C6: OWNERSHIP GUARD
     * ==========================================================
     * Setelah login CBT (masuk), id sesi diikat ke sesi Laravel.
     * Semua akses berikutnya wajib cocok — sessionId di URL milik
     * orang lain TIDAK bisa dibuka/disubmit.
     */
    private function resolveOwnedSession(int $sessionId, bool $withQuestions = true): ?ExamSession
    {
        $boundId = (int) session('cbt_session_id');

        if ($boundId === 0 || $boundId !== $sessionId) {
            return null;
        }

        /*
         * [FIX-FATAL] Relasi dipilih eksplisit sebelum with().
         * questions diperlukan untuk validasi soal di autosave/submit.
         */
        $relations = $withQuestions
            ? ['exam.questions', 'student']
            : ['exam'];

        return ExamSession::with($relations)->find($sessionId);
    }

    /* ==========================================================
     * TINGKAT KELAS PESERTA CBT
     * ========================================================== */
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

        // Exam lama sebelum fitur tingkat kelas tetap kompatibel.
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

    /* ==========================================================
     * 1. Form Login CBT (NISN + password + token)
     * ========================================================== */
    public function index()
    {
        return view('siswa.cbt.login');
    }

    /* ==========================================================
     * 2. Proses Login CBT
     * ========================================================== */
    public function masuk(Request $request)
    {
        /*
         * [LOGIN-HARDEN] Trim SEBELUM validasi — token dengan spasi
         * di ujung tidak lagi gagal `size:6` dengan pesan membingungkan.
         */
        $request->merge([
            'nisn' => trim((string) $request->input('nisn')),
            'token' => trim((string) $request->input('token')),
        ]);

        $request->validate([
            'nisn' => 'required|string',
            'password' => 'required|string',
            'token' => 'required|string|size:6',
        ]);

        $nisn = (string) $request->input('nisn');

        /*
         * Rate limit: 5 percobaan / 5 menit / NISN+IP — token 6 digit
         * + password tidak boleh dibrute-force tanpa henti.
         */
        $rateKey = 'cbt-login:' . strtolower($nisn) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return back()
                ->with('error', 'Terlalu banyak percobaan. Coba lagi dalam ' . $seconds . ' detik.')
                ->withInput();
        }

        $user = DB::table('users')
            ->where('login_id', $nisn)
            ->where('role', 'siswa')
            ->first(['id', 'password']);

        if (!$user || !Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($rateKey, 300);

            return back()->with('error', 'NISN atau password salah.')->withInput();
        }

        $exam = Exam::query()
            ->where('token', (string) $request->input('token'))
            ->where('status', 'aktif')
            ->first();

        if (!$exam) {
            RateLimiter::hit($rateKey, 300);

            return back()
                ->with('error', 'Token salah atau ujian belum diaktifkan oleh guru.')
                ->withInput();
        }

        $student = Student::where('user_id', $user->id)->first();

        if (!$student) {
            /*
             * [LOGIN-HARDEN] Fallback NISN bisa lintas identitas bila
             * user_id rusak — selalu dicatat untuk ditindaklanjuti admin.
             */
            $student = Student::where('nisn', $nisn)->first();

            if ($student) {
                Log::warning('CBT login memakai fallback NISN (user_id tidak tertaut).', [
                    'user_id' => $user->id,
                    'student_id' => $student->id,
                ]);
            }
        }

        if (!$student) {
            return back()->with('error', 'Data profil murid tidak ditemukan.')->withInput();
        }

        /*
         * [GRADE-GUARD] Ujian Kelas 10/11/12 hanya boleh diikuti siswa
         * yang kelas REGULER-nya pada semester aktif berada pada tingkat
         * yang sama. Rombel Pilihan/Ekstrakurikuler tidak dipakai untuk
         * menentukan tingkat siswa.
         */
        if (!$this->studentMatchesExamGrade($exam, $student)) {
            $grade = $this->normalizeTingkatKelas($exam->tingkat_kelas ?? null);

            RateLimiter::hit($rateKey, 300);

            Log::warning('CBT login ditolak: tingkat kelas murid tidak sesuai.', [
                'exam_id' => $exam->id,
                'student_id' => $student->id,
                'tingkat_kelas_exam' => $grade,
                'nisn' => $this->maskNisn($nisn),
            ]);

            return back()
                ->with('error', 'Ujian ini hanya untuk Kelas ' . ($grade ?? '-') . '. Tingkat kelas Anda tidak sesuai.')
                ->withInput();
        }

        /*
         * [D6] ROSTER CHECK — siswa wajib terdaftar di rombel ujian.
         * Homeroom exam dicari: kolom exams.homeroom_id (instalasi
         * tertentu) atau via aktivitas LMS terhubung. Exam LAN lama
         * tanpa jejak rombel tidak dikunci (kompatibilitas).
         */
        $examHomeroomId = null;

        try {
            if (Schema::hasColumn('exams', 'homeroom_id')) {
                $examHomeroomId = (int) (DB::table('exams')
                    ->where('id', $exam->id)
                    ->value('homeroom_id') ?? 0);
            }
        } catch (\Throwable) {
        }

        if (!$examHomeroomId) {
            $examHomeroomId = (int) (DB::table('lms_assignments')
                ->join('lms_topics', 'lms_assignments.topic_id', '=', 'lms_topics.id')
                ->join('lms_courses', 'lms_topics.course_id', '=', 'lms_courses.id')
                ->where('lms_assignments.exam_id', $exam->id)
                ->orderByDesc('lms_assignments.id')
                ->value('lms_courses.homeroom_id') ?? 0);
        }

        if ($examHomeroomId > 0) {
            $isPeserta = DB::table('enrollments')
                ->where('homeroom_id', $examHomeroomId)
                ->where('student_id', $student->id)
                ->exists();

            if (!$isPeserta) {
                RateLimiter::hit($rateKey, 300);

                Log::warning('CBT login ditolak: murid bukan peserta rombel ujian (D6).', [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'homeroom_exam' => $examHomeroomId,
                    'nisn' => $this->maskNisn($nisn),
                ]);

                return back()
                    ->with('error', 'Anda bukan peserta ujian ini (rombel tidak sesuai). Hubungi pengawas.')
                    ->withInput();
            }
        }

        RateLimiter::clear($rateKey);

        /*
         * [AUDIT] NISN di-mask (PII) — jejak cukup untuk forensik tanpa
         * membocorkan identitas penuh di log.
         */
        Log::info('CBT login berhasil.', [
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'nisn' => $this->maskNisn($nisn),
            'ip' => $request->ip(),
        ]);

        /*
         * [RACE-SESSION] Unique index (exam_id, student_id) menjamin
         * satu sesi per pasangan. Tabrakan race ditangani re-fetch.
         */
        try {
            $session = ExamSession::firstOrCreate(
                [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                ],
                [
                    'status' => 'sedang_mengerjakan',
                    'waktu_masuk' => now(),
                ]
            );
        } catch (QueryException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'unique')) {
                throw $e;
            }

            $session = ExamSession::where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->first();
        }

        if (!$session) {
            return back()->with('error', 'Gagal membuka sesi ujian. Coba lagi.')->withInput();
        }

        if ($session->status === 'selesai') {
            return back()->with('error', 'Anda sudah menyelesaikan ujian ini.')->withInput();
        }

        /*
         * C6: ikat sesi ujian ke sesi Laravel saat ini.
         * [BIND-FIX] Lepas ikatan lama dulu; [LOGIN-HARDEN] regenerate
         * ID sesi (anti fixation) — data tetap, urutan aman.
         */
        session()->forget('cbt_session_id');
        session(['cbt_session_id' => $session->id]);
        session()->regenerate();
        session(['cbt_session_id' => $session->id]);

        return redirect()->route('cbt.ujian', $session->id);
    }

    /* ==========================================================
     * 3. Halaman Ujian
     * ========================================================== */
    public function ujian($sessionId)
    {
        $session = $this->resolveOwnedSession((int) $sessionId);

        if (!$session) {
            return redirect()
                ->route('cbt.index')
                ->with('error', 'Sesi ujian tidak valid. Silakan masuk dengan token ujian.');
        }

        if ($session->status === 'selesai') {
        /*
         * [SELESAI] Id sesi dititipkan via flash agar halaman selesai
         * dapat menampilkan status/koreksi — tanpa membuka akses ke
         * sesi milik orang lain (C6 tetap utuh).
         */
        session()->flash('cbt_selesai_id', $session->id);

        return redirect()->route('cbt.selesai');        }

        /*
         * [ACTIVE-GUARD] Ujian dinonaktifkan guru — soal tidak lagi
         * ter-expose (dulu halaman tetap terbuka walau autosave/submit
         * sudah menolak).
         */
        if ($session->exam->status !== 'aktif') {
            session()->forget('cbt_session_id');

            return redirect()
                ->route('cbt.index')
                ->with('error', 'Ujian telah dinonaktifkan. Hubungi pengawas.');
        }

        if ($session->exam->questions->isEmpty()) {
            return redirect()
                ->route('cbt.index')
                ->with('error', 'Paket ujian belum memiliki soal. Hubungi guru.');
        }

        $savedAnswers = StudentAnswer::where('exam_session_id', $session->id)
            ->pluck('jawaban_siswa', 'question_bank_id')
            ->toArray();

        /*
         * [MAJEMUK] jenis_soal ikut dikirim — frontend merender
         * checkbox untuk Majemuk (jawaban array), radio untuk lainnya.
         */
        $questions = $session->exam->questions
            ->sortBy(fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? 0))
            ->map(function ($q) {
                $opsiRaw = is_array($q->opsi_jawaban)
                    ? $q->opsi_jawaban
                    : json_decode((string) ($q->opsi_jawaban ?? ''), true);

                $options = [];

                if ((string) $q->jenis_soal === 'benar_salah') {
                    // Bank Soal memakai format kanonik B=Benar, S=Salah.
                    // Jangan bergantung pada opsi_jawaban karena tipe ini
                    // memang dapat tersimpan tanpa opsi eksplisit.
                    $options = [
                        ['key' => 'B', 'text' => 'Benar'],
                        ['key' => 'S', 'text' => 'Salah'],
                    ];
                } elseif (is_array($opsiRaw)) {
                    $index = 0;

                    foreach ($opsiRaw as $key => $val) {
                        $optionKey = (is_string($key) && ctype_alpha($key))
                            ? strtoupper($key)
                            : chr(65 + $index); // [GUARD] opsi > 26 praktis tak terjadi

                        $options[] = [
                            'key' => $optionKey,
                            'text' => is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val,
                        ];
                        $index++;
                    }
                }

                return [
                    'id' => (int) $q->id,
                    'question' => $q->pertanyaan,
                    'options' => $options,
                    'jenis_soal' => $q->jenis_soal,
                    'bobot_nilai' => (float) ($q->bobot_nilai ?? 1),
                    'gambar' => $q->gambar_soal,
                    'tka_group_code' => $q->tka_group_code ?? null,
                    'tka_stimulus' => $q->tka_stimulus ?? null,
                    'tka_category' => (bool)($q->tka_category ?? false),
                    'tka_statement_order' => $q->tka_statement_order ?? null,
                ];
            })
            ->values();

        /*
         * [DURATION] Durasi efektif dari waktu_masuk (resume tetap
         * benar), bukan durasi mentah.
         */
        $startedAt = $session->waktu_masuk
            ? \Carbon\Carbon::parse($session->waktu_masuk)
            : now();

        $durationMinutes = (int) ($session->exam->durasi_menit ?? 0);
        $endsAt = $startedAt->copy()->addMinutes($durationMinutes);
        $remainingSeconds = max(0, now()->diffInSeconds($endsAt, false));

        return view('siswa.cbt.ujian', [
            'session' => $session,
            'exam' => $session->exam,
            'questions' => $questions,
            'savedAnswers' => $savedAnswers,
            'duration' => $session->exam->durasi_menit,
            'remainingSeconds' => $remainingSeconds,
            'endsAtIso' => $endsAt->toIso8601String(),
        ]);
    }

    /* ==========================================================
     * 4. Auto Save — TANPA penilaian (autosave tidak menyentuh
     *    kunci/kebenaran apa pun).
     *
     *    [DURATION] Ditolak setelah durasi habis.
     *    [ACTIVE-GUARD] Ujian dinonaktifkan — tolak.
     * ========================================================== */
    public function autoSave(Request $request, $sessionId)
    {
        $session = $this->resolveOwnedSession((int) $sessionId, true);

        if (!$session || $session->status === 'selesai') {
            return response()->json(['status' => 'error', 'message' => 'Sesi tidak valid.'], 403);
        }

        if ($session->exam->status !== 'aktif') {
            return response()->json(['status' => 'error', 'message' => 'Ujian telah dinonaktifkan.'], 403);
        }

        if ($this->isDurationExceeded($session)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Waktu ujian telah habis. Silakan submit.',
                'duration_exceeded' => true,
            ], 403);
        }

        $rawPayload = (string) $request->input('answers_payload', '');

        if (strlen($rawPayload) > self::MAX_ANSWERS_PAYLOAD_BYTES) {
            return response()->json(['status' => 'error', 'message' => 'Payload terlalu besar.'], 413);
        }

        $answersPayload = json_decode($rawPayload, true);

        if (is_array($answersPayload) && $answersPayload !== []) {
            /*
             * [PERF] Flip id soal sekali — lookup O(1) per jawaban.
             * TANPA is_benar/nilai — koreksi hanya oleh guru di server.
             */
            $validQuestionIds = $session->exam->questions->pluck('id')->flip();

            foreach ($answersPayload as $questionId => $answer) {
                $questionId = (int) $questionId;

                if (!$validQuestionIds->has($questionId)) {
                    continue;
                }

                StudentAnswer::updateOrCreate(
                    [
                        'exam_session_id' => $session->id,
                        'question_bank_id' => $questionId,
                    ],
                    [
                        'jawaban_siswa' => is_array($answer)
                            ? json_encode($answer, JSON_UNESCAPED_UNICODE)
                            : (string) $answer,
                    ]
                );
            }
        }

        return response()->json(['status' => 'success']);
    }

    /* ==========================================================
     * 5. Submit — SIMPAN JAWABAN SAJA
     *
     *    [KONTRAK-KOREKSI] Tidak ada koreksi, tidak ada nilai.
     *    is_benar = NULL = masuk antrean koreksi guru
     *    (CbtCorrectionController → CbtCorrectionService::confirm).
     *    nilai_akhir diisi SATU-SATUNYA oleh confirm().
     *
     *    [TX-FIX] Transaksi + lockForUpdate — double-submit aman.
     *    [DURATION] Submit lewat waktu DITERIMA + ditandai di log.
     * ========================================================== */
    public function submit(Request $request, $sessionId)
    {
        $session = $this->resolveOwnedSession((int) $sessionId, true);

        if (!$session) {
            return redirect()
                ->route('cbt.index')
                ->with('error', 'Sesi ujian tidak valid. Silakan masuk dengan token ujian.');
        }

        if ($session->status === 'selesai') {
            return redirect()->route('cbt.selesai');
        }

        if ($session->exam->status !== 'aktif') {
            return back()->with('error', 'Ujian telah dinonaktifkan oleh guru. Hubungi pengawas.');
        }

        $durationExceeded = $this->isDurationExceeded($session);

        if ($durationExceeded) {
            Log::warning('CBT submit melewati batas waktu.', [
                'exam_session_id' => $session->id,
                'exam_id' => $session->exam_id,
                'student_id' => $session->student_id,
                'waktu_masuk' => (string) $session->waktu_masuk,
                'submitted_at' => now()->toDateTimeString(),
            ]);
        }

        $rawPayload = (string) $request->input('answers_payload', '');

        if (strlen($rawPayload) > self::MAX_ANSWERS_PAYLOAD_BYTES) {
            return back()->with('error', 'Payload jawaban terlalu besar.');
        }

        $answersPayload = json_decode($rawPayload, true);
        $questionsById = $session->exam->questions->keyBy('id');

        /*
         * [TX-FIX + KONTRAK-KOREKSI] Simpan jawaban SAJA dalam
         * transaksi terkunci. Tidak ada kunci jawaban di jalur ini.
         */
        $saved = DB::transaction(function () use ($answersPayload, $session, $questionsById) {
            $locked = ExamSession::whereKey($session->id)->lockForUpdate()->first();

            if (!$locked || $locked->status === 'selesai') {
                return false; // request kedua dari double-submit — abaikan
            }

            if (is_array($answersPayload)) {
                foreach ($answersPayload as $questionId => $answer) {
                    $questionId = (int) $questionId;

                    if (!$questionsById->has($questionId)) {
                        continue;
                    }

                    StudentAnswer::updateOrCreate(
                        [
                            'exam_session_id' => $session->id,
                            'question_bank_id' => $questionId,
                        ],
                        [
                            'jawaban_siswa' => is_array($answer)
                                ? json_encode($answer, JSON_UNESCAPED_UNICODE)
                                : (string) $answer,
                            'is_benar' => null,       // antrean koreksi
                            'nilai_per_soal' => 0,
                        ]
                    );
                }
            }

            /*
             * [MATERIALIZZE] Soal tak dijawab tetap dibuatkan baris —
             * guru melihat kekosongan di halaman koreksi; identik
             * dengan jalur PWA (ingest service).
             */
            foreach ($questionsById as $q) {
                StudentAnswer::firstOrCreate(
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

            $session->update([
                'nilai_akhir' => null, // diisi SATU-SATUNYA oleh confirm()
                'status' => 'selesai',
                'waktu_submit' => now(),
            ]);

            return true;
        });

        if (!$saved) {
            return redirect()->route('cbt.selesai');
        }

        /*
         * Ujian selesai — lepaskan ikatan sesi CBT dari sesi Laravel
         * agar id lama tidak bisa dipakai ulang.
         */
        session()->forget('cbt_session_id');

        Log::info('CBT submit berhasil (menunggu koreksi guru).', [
            'exam_session_id' => $session->id,
            'exam_id' => $session->exam_id,
            'student_id' => $session->student_id,
            'duration_exceeded' => $durationExceeded,
            'ip' => request()->ip(),
        ]);

        /*
         * [ABSENSI-ONLY] Kehadiran = fakta, ditulis saat submit.
         * NILAI (assessment_scores) ditulis confirm() — setelah guru
         * mengoreksi. Kegagalan di sini tidak menggagalkan submit.
         */
        try {
            if ($session->student) {
                app(CbtCorrectionService::class)->ensureAttendancePublic(
                    $session->exam,
                    $session->student,
                    now()->format('Y-m-d')
                );
            }
        } catch (Throwable $e) {
            Log::error('CBT post-submit attendance gagal.', [
                'exam_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('cbt.selesai');
    }

    /* ==========================================================
     * 6. Halaman Selesai — status jujur sesuai kontrak koreksi
     * ========================================================== */
    public function selesai()
    {
        $sessionId = (int) session('cbt_selesai_id');

        $session = $sessionId > 0
            ? ExamSession::with(['exam'])->find($sessionId)
            : null;

        $benar = null;
        $total = null;

        /*
         * [KONTRAK-KOREKSI] Jumlah benar hanya ditampilkan bila nilai
         * sudah final (dikonfirmasi guru) — di luar itu 15 benar dari
         * 20 dengan esai belum dinilai akan menyesatkan siswa.
         */
        if ($session && $session->nilai_akhir !== null) {
            $total = $session->exam->questions()->count();
            $benar = StudentAnswer::where('exam_session_id', $session->id)
                ->where('is_benar', true)
                ->count();
        }

        return view('siswa.cbt.selesai', [
            'session' => $session,
            'benar'   => $benar,
            'total'   => $total,
        ]);
    }

    /* ==========================================================
     * HELPERS
     * ========================================================== */

    /**
     * [DURATION] Server-side: waktu_masuk + durasi_menit terlewati?
     * Durasi tidak lagi hanya dihitung di JS.
     */
    private function isDurationExceeded(ExamSession $session): bool
    {
        if (!$session->waktu_masuk) {
            return false;
        }

        $durationMinutes = (int) ($session->exam->durasi_menit ?? 0);

        if ($durationMinutes <= 0) {
            return false; // tanpa batas waktu
        }

        $endsAt = \Carbon\Carbon::parse($session->waktu_masuk)->addMinutes($durationMinutes);

        return now()->greaterThan($endsAt);
    }

    /** [LOGIN-HARDEN] Mask NISN untuk log (PII). */
    private function maskNisn(string $nisn): string
    {
        $len = strlen($nisn);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($nisn, 0, 4) . str_repeat('*', $len - 4);
    }
}