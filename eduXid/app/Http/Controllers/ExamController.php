<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\StudentAnswer;
use App\Models\LmsAssignment;
use App\Models\QuestionBank;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Services\Sync\LmsSyncService;
use App\Services\Ljk\LjkPrivatePackageService;
use App\Services\Sync\LjkKeySnapshotPushService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExamController extends Controller
{
    /* [LJK-PIPELINE-V13] Paket Ujian langsung refresh snapshot private PWA Guru. */
    private function refreshPrivateLjkSnapshotV13(): void
    {
        try {
            $result = app(LjkKeySnapshotPushService::class)->forceFlushAll(100);
            Log::info('[LJK SNAPSHOT V13] refresh setelah perubahan Paket Ujian.', $result);
        } catch (\Throwable $e) {
            Log::warning('[LJK SNAPSHOT V13] refresh ditunda; daemon akan retry.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    /*
     * ==========================================================
     * [REWRITE-12.1] EXAM CONTROLLER
     * ==========================================================
     * Perubahan vs REWRITE-12 (kumulatif, semua dari lapangan):
     *
     *   1. [LAPIS-A] activate()/deactivate()/syncToStudents()/
     *      storeQuestions() menulis status + sync_version +
     *      updated_at dalam SATU query atomik (DB::raw). Dulu
     *      increment('sync_version') terpisah → versi basi di
     *      getCurrentVersion() → push dianggap duplikat.
     *
     *   2. [PESAN-JUJUR] pushExamWithNotice: EXAM_LAN_ONLY → panduan
     *      LMS (bukan "akan dicoba ulang otomatis"). PENDING = ada
     *      outbox → janji retry otomatis BENAR.
     *
     *   3. [IDENTITAS] currentTeacher(): user_id dulu, lalu
     *      NIP/NIK/NUPTK; resolveOwnedExam membedakan 403 identitas
     *      vs 403 ownership.
     *
     *   4. [TOKEN-TOCTOU] store(): create dengan retry pada
     *      pelanggaran unique token.
     *
     *   5. [SUBJECT-SCOPE] subject_id wajib termasuk penugasan guru.
     *
     *   6. [INPUT] storeQuestions(): ID soal difilter ke yang ada;
     *      activate() menolak exam tanpa soal.
     *
     *   7. [ORPHAN-FIX] destroy(): push delete AKTIVITAS LMS terhubung
     *      SEBELUM apa pun dihapus; versi delete = epoch-ms.
     *
     *   8. [GUARD-JAWABAN] destroy() MENOLAK menghapus exam yang
     *      masih punya jawaban belum terkoreksi (konfirmasi guru).
     *      Lapangan terbukti: guru menghapus exam setelah siswa
     *      mengerjakan via PWA → jawaban kehilangan induk koreksi →
     *      dead-letter "exam tidak ditemukan" + siswa tidak dapat
     *      dinilai. Halaman Koreksi harus selesai dulu; penghapusan
     *      setelah nilai final tidak membuang data siswa.
     *
     *   9. [EXPORT] Dead code dihapus; NISN tanpa prefix apostrophe;
     *      "Waktu Mulai" memakai waktu_masuk; nilai NULL tampil
     *      "Menunggu koreksi" (kontrak koreksi server).
     * ==========================================================
     */

    /* ==========================================================
     * HELPER: GURU PEMILIK SESI LOGIN
     * ========================================================== */
    private function currentTeacher(): ?Teacher
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        /*
         * [IDENTITAS] user_id paling andal; fallback NIP/NIK/NUPTK
         * dari login_id untuk data setengah-terisi.
         */
        $teacher = Teacher::where('user_id', $user->id)->first();

        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return null;
        }

        return Teacher::where(function ($query) use ($loginId) {
            $query->where('nip', $loginId)
                ->orWhere('nik', $loginId)
                ->orWhere('nuptk', $loginId);
        })->first();
    }

    /* ==========================================================
     * TINGKAT KELAS CBT
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

    private function activeSemesterId(): ?string
    {
        $semester = trim((string) session('semester_aktif'));

        if ($semester !== '') {
            return $semester;
        }

        $semester = (string) (DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->whereNotNull('semester_id')
            ->max('semester_id') ?? '');

        return $semester !== '' ? $semester : null;
    }

    /**
     * Map subject_id => [10, 11, 12] sesuai penugasan guru pada
     * semester aktif. Tingkat dibaca dari homerooms.tingkat_pendidikan,
     * bukan ditebak dari nama rombel.
     */
    private function teacherSubjectGradeMap(Teacher $guru): array
    {
        $query = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $guru->id)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->select([
                'teaching_assignments.subject_id',
                'homerooms.tingkat_pendidikan',
            ]);

        if ($semester = $this->activeSemesterId()) {
            $query->where('homerooms.semester_id', $semester);
        }

        $map = [];

        foreach ($query->get() as $row) {
            $subjectId = (int) ($row->subject_id ?? 0);
            $grade = $this->normalizeTingkatKelas($row->tingkat_pendidikan ?? null);

            if ($subjectId <= 0 || $grade === null) {
                continue;
            }

            $map[$subjectId] ??= [];
            $map[$subjectId][] = $grade;
        }

        foreach ($map as $subjectId => $grades) {
            $grades = array_values(array_unique(array_map('intval', $grades)));
            sort($grades);
            $map[$subjectId] = $grades;
        }

        ksort($map);

        return $map;
    }

    private function examQuestionScopeError(Exam $exam): ?string
    {
        $grade = $this->normalizeTingkatKelas($exam->tingkat_kelas ?? null);

        if ($grade === null || !Schema::hasColumn('question_banks', 'tingkat_kelas')) {
            return null;
        }

        $invalid = $exam->questions()
            ->where(function ($q) use ($exam, $grade) {
                $q->where('question_banks.subject_id', '!=', (int) $exam->subject_id)
                    ->orWhereNull('question_banks.tingkat_kelas')
                    ->orWhere('question_banks.tingkat_kelas', '!=', $grade);
            })
            ->count();

        if ($invalid > 0) {
            return "Paket ujian memiliki {$invalid} soal yang tidak sesuai mata pelajaran/tingkat Kelas {$grade}. Hapus atau ganti soal tersebut terlebih dahulu.";
        }

        return null;
    }

    /* ==========================================================
     * C7: OWNERSHIP GUARD — 403 identitas ≠ 403 ownership
     * ========================================================== */
    private function resolveOwnedExam(Exam $exam): Teacher
    {
        $guru = $this->currentTeacher();

        if (!$guru) {
            abort(403, 'Profil guru Anda belum terdata (NIP/NIK/NUPTK/user_id '
                . 'tidak cocok dengan akun). Hubungi pengelola sekolah.');
        }

        abort_unless(
            (int) $exam->teacher_id === (int) $guru->id,
            403,
            'Paket ujian ini bukan milik Anda.'
        );

        return $guru;
    }

    /* ==========================================================
     * HELPER: PUSH EXAM DENGAN PESAN RAMAH (TIDAK PERNAH 500)
     * ==========================================================
     * [PESAN-JUJUR] Tiga kelas kegagalan dibedakan:
     *   a) PENDING (ada outbox)   → "dicoba ulang otomatis" — JUJUR.
     *   b) EXAM_LAN_ONLY (prasyarat LMS) → panduan, TANPA janji retry.
     *   c) Gagal pra-antrean lain → arahkan tekan Sinkronkan manual.
     *
     * @return array{0:string,1:?string} [flashType, notice]
     */
    private function pushExamWithNotice(
        Exam $exam,
        LmsSyncService $lmsSync,
        string $action = 'update'
    ): array {
        try {
            $result = $lmsSync->pushExam($exam->fresh(['questions']), $action);

            $status = strtoupper((string) ($result['status'] ?? ''));

            if (($result['success'] ?? false) === true
                && in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)) {
                return ['success', null];
            }

            if ($status === 'PENDING') {
                $error = trim((string) ($result['error'] ?? ''));

                return [
                    'warning',
                    'Perubahan tersimpan lokal dan sudah masuk antrean sinkronisasi'
                    . ($error !== '' ? ' (' . $error . ')' : '')
                    . ' — akan dicoba ulang otomatis.',
                ];
            }

            if (($result['success'] ?? false) === false) {
                $error = trim((string) ($result['error'] ?? ''));

                return [
                    'warning',
                    'Perubahan tersimpan lokal, tetapi belum dapat dipastikan masuk antrean sinkronisasi'
                    . ($error !== '' ? ' (' . $error . ')' : '')
                    . ' — periksa koneksi/pengaturan Cloud lalu tekan "📱 Sinkronkan ke Murid".',
                ];
            }

            return [
                'warning',
                'Respon sinkronisasi tidak dikenali (' . ($status ?: 'kosong')
                . ') — tekan "📱 Sinkronkan ke Murid" untuk mencoba lagi.',
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'EXAM_LAN_ONLY:')) {
                return [
                    'warning',
                    'Ujian belum terhubung aktivitas LMS sehingga berlaku lokal (LAN) '
                    . 'saja. Untuk mengirimkannya ke perangkat murid: LMS Guru → pilih '
                    . 'kelas & topik → Tambah Aktivitas → tipe CBT → hubungkan dengan '
                    . 'ujian ini → Publish, lalu tekan "📱 Sinkronkan ke Murid".',
                ];
            }

            return [
                'warning',
                'Sinkronisasi gagal: ' . $message
                . ' — perbaiki koneksi/pengaturan Cloud lalu tekan "📱 Sinkronkan ke Murid".',
            ];
        }
    }

    /* ==========================================================
     * DAFTAR PAKET UJIAN — TER-SCOPE PER GURU
     * + [KOREKSI-QUEUE] panel antrian koreksi di dasbor
     * ========================================================== */
    public function index()
    {
        $guru = $this->currentTeacher();

        $exams = $guru
            ? Exam::withCount('questions')
                ->where('teacher_id', $guru->id)
                ->latest()
                ->get()
            : collect();

        $sessions = collect();
        $koreksiPending = collect();
        $lmsLinkedExamIds = collect();

        /*
         * [KELAS-REGULER] Monitoring CBT harus menampilkan kelas utama
         * siswa, bukan rombel mapel pilihan yang dipakai Course/CBT.
         *
         * Semester diprioritaskan dari session semester_aktif. Jika session
         * belum ada, gunakan semester terbaru dari rombel reguler.
         */
        $semesterAktif = trim((string) session('semester_aktif', ''));

        if ($semesterAktif === '' && Schema::hasTable('homerooms')) {
            $semesterAktif = (string) (DB::table('homerooms')
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%')
                ->max('semester_id') ?? '');
        }

        if ($guru && Schema::hasTable('exam_sessions')) {
            $sessions = DB::table('exam_sessions')
                ->join('students', 'exam_sessions.student_id', '=', 'students.id')
                ->join('exams', 'exam_sessions.exam_id', '=', 'exams.id')
                ->where('exams.teacher_id', $guru->id)
                ->select(
                    'exam_sessions.*',
                    'students.nama',
                    'students.nisn',
                    'exams.judul_ujian',
                    'exams.durasi_menit'
                )
                ->selectSub(
                    function ($query) use ($semesterAktif) {
                        $query
                            ->from('enrollments as regular_enrollments')
                            ->join(
                                'homerooms as regular_homerooms',
                                'regular_homerooms.id',
                                '=',
                                'regular_enrollments.homeroom_id'
                            )
                            ->select('regular_homerooms.nama')
                            ->whereColumn(
                                'regular_enrollments.student_id',
                                'students.id'
                            )
                            ->where(
                                'regular_homerooms.jenis_rombel',
                                'not like',
                                '%Ekstrakurikuler%'
                            )
                            ->where(
                                'regular_homerooms.jenis_rombel',
                                'not like',
                                '%Pilihan%'
                            );

                        if ($semesterAktif !== '') {
                            $query->where(
                                'regular_homerooms.semester_id',
                                $semesterAktif
                            );
                        }

                        $query
                            ->orderBy('regular_homerooms.id')
                            ->limit(1);
                    },
                    'kelas_reguler'
                )
                ->latest('exam_sessions.created_at')
                ->get();

            /*
             * [KOREKSI-QUEUE] Sesi selesai tanpa nilai = antrean
             * koreksi — ditampilkan di dasbor agar guru tidak perlu
             * mencari halaman koreksi terpisah.
             */
            $koreksiPending = DB::table('exam_sessions')
                ->join('students', 'exam_sessions.student_id', '=', 'students.id')
                ->join('exams', 'exam_sessions.exam_id', '=', 'exams.id')
                ->where('exams.teacher_id', $guru->id)
                ->where('exam_sessions.status', 'selesai')
                ->whereNull('exam_sessions.nilai_akhir')
                ->orderBy('exam_sessions.waktu_submit')
                ->select(
                    'exam_sessions.id as session_id',
                    'students.nama as nama_siswa',
                    'students.nisn',
                    'exams.judul_ujian',
                    'exam_sessions.waktu_submit'
                )
                ->get();
        }

        if ($guru && $exams->isNotEmpty()) {
            /*
             * [LAN-INDICATOR] Exam tanpa LmsAssignment = LAN-only —
             * ditandai di tabel agar "Belum dikirim" tidak membingungkan.
             */
            $lmsLinkedExamIds = LmsAssignment::query()
                ->whereIn('exam_id', $exams->pluck('id'))
                ->distinct()
                ->pluck('exam_id');
        }

        return view('guru.cbt.index', compact(
            'exams',
            'sessions',
            'koreksiPending',
            'lmsLinkedExamIds'
        ));
    }

    public function create()
    {
        $guru = $this->currentTeacher();
        $subjectGradeMap = [];

        if ($guru) {
            $subjectIds = TeachingAssignment::where('teacher_id', $guru->id)
                ->pluck('subject_id')
                ->unique();

            $subjects = Subject::whereIn('id', $subjectIds)
                ->orderBy('nama_mata_pelajaran')
                ->get();

            $subjectGradeMap = $this->teacherSubjectGradeMap($guru);
        } else {
            $subjects = collect();
        }

        return view('guru.cbt.create', compact('guru', 'subjects', 'subjectGradeMap'));
    }

    public function store(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'judul_ujian' => 'required|string|max:255',
            'durasi_menit' => 'required|integer|min:1',
            'subject_id' => 'required|exists:subjects,id',
            'tingkat_kelas' => 'required|integer|in:10,11,12',
            'jenis_penilaian' => 'required|in:diagnostik,formatif,sumatif',
            'atp_item_ids' => 'nullable|array',
            'atp_item_ids.*' => 'integer|exists:atp_items,id',
        ]);
        if ($validator->fails()) {
            return redirect()->route('guru.cbt.create')->withErrors($validator)->withInput();
        }
        $validated = $validator->validated();

        $guru = $this->currentTeacher();

        if (!$guru) {
            return redirect()->route('guru.cbt.create')
                ->with('error', 'Profil guru Anda belum terdata (NIP/NIK/NUPTK tidak '
                    . 'cocok dengan akun). Ujian tidak dapat dibuat. Hubungi pengelola sekolah.')
                ->withInput();
        }

        $subjectId = (int) $validated['subject_id'];
        $grade = (int) $validated['tingkat_kelas'];
        $subjectGradeMap = $this->teacherSubjectGradeMap($guru);
        $allowedGrades = $subjectGradeMap[$subjectId] ?? [];

        if (!in_array($grade, $allowedGrades, true)) {
            return redirect()->route('guru.cbt.create')
                ->withErrors([
                    'tingkat_kelas' => "Kelas {$grade} tidak termasuk tingkat yang Anda ampu untuk mata pelajaran tersebut pada semester aktif.",
                ])
                ->withInput();
        }

        $exam = null;

        for ($attempt = 0; $attempt < 5 && !$exam; $attempt++) {
            $token = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            try {
                $candidate = new Exam();
                $candidate->forceFill([
                    'teacher_id' => $guru->id,
                    'subject_id' => $subjectId,
                    'tingkat_kelas' => $grade,
                    'judul_ujian' => $validated['judul_ujian'],
                    'durasi_menit' => (int) $validated['durasi_menit'],
                    'token' => $token,
                    'status' => 'draft',
                    'jenis_penilaian' => $validated['jenis_penilaian'],
                    'sync_version' => 1,
                ]);
                $candidate->save();
                $exam = $candidate;
            } catch (QueryException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE')
                    && !str_contains($e->getMessage(), 'unique')) {
                    throw $e;
                }
            }
        }

        if (!$exam) {
            return redirect()->route('guru.cbt.create')
                ->with('error', 'Gagal membuat token ujian unik. Coba lagi.')
                ->withInput();
        }

        $this->refreshPrivateLjkSnapshotV13();

        /*
         * [ATP-LINK] Paket Ujian menyimpan cakupan ATP yang dipilih guru.
         */
        $requestedAtpIds = collect($request->input('atp_item_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique();

        $validAtpIds = DB::table('atp_items')
            ->where('teacher_id', $guru->id)
            ->where('subject_id', $request->subject_id)
            ->whereIn('id', $requestedAtpIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        DB::table('exams')
            ->where('id', $exam->id)
            ->update([
                'atp_item_ids' => $validAtpIds !== [] ? json_encode($validAtpIds) : null,
            ]);
        /*
         * [ATP-LINK-v24] Cakupan ATP Paket Ujian.
         */
        $requestedAtpIds = collect($request->input('atp_item_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique();

        $validAtpIds = DB::table('atp_items')
            ->where('teacher_id', $guru->id)
            ->where('subject_id', $request->subject_id)
            ->whereIn('id', $requestedAtpIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        DB::table('exams')
            ->where('id', $exam->id)
            ->update([
                'atp_item_ids' => $validAtpIds !== []
                    ? json_encode($validAtpIds)
                    : null,
            ]);
        return redirect()
            ->route('guru.cbt')
            ->with('success', "Paket ujian Kelas {$grade} berhasil dibuat. Silakan tambahkan soal.");
    }

    public function addQuestions(Exam $exam)
    {
        $this->resolveOwnedExam($exam);

        // V21R106: Gunakan route soal terproteksi yang sudah ada, tanpa mengubah routes/web.php.
        if (request()->query('arena_export') === '1') {
            return app(\App\Http\Controllers\Guru\ArenaBelajarExportController::class)($exam);
        }

        $selectedQuestions = $exam->questions->pluck('question_bank_id')->toArray();
        $grade = $this->normalizeTingkatKelas($exam->tingkat_kelas ?? null);

        $bankSoal = QuestionBank::query()
            ->where('teacher_id', $exam->teacher_id)
            ->where('subject_id', $exam->subject_id)
            ->when(
                $grade !== null && Schema::hasColumn('question_banks', 'tingkat_kelas'),
                fn ($q) => $q->where('tingkat_kelas', $grade)
            )
            ->latest()
            ->get();

        return view('guru.cbt.soal', compact('exam', 'bankSoal', 'selectedQuestions'));
    }

    public function storeQuestions(Request $request, Exam $exam)
    {
        $this->resolveOwnedExam($exam);

        // V21R128: existing protected route, separate POST action; no changes to signed routes/web.php.
        if ($request->input('arena_cloud_publish') === '1') {
            return app(\App\Http\Controllers\Guru\ArenaBelajarExportController::class)->publishToCloud($exam);
        }

        $rawQuestions = $request->questions ?? [];

        $questionIds = [];
        $order = 1;

        foreach ($rawQuestions as $key => $value) {
            $questionId = is_array($value) ? ($value['question_id'] ?? $key) : $value;
            $questionId = (int) $questionId;

            if ($questionId <= 0) {
                continue;
            }

            $questionIds[$questionId] = ['urutan' => $order++];
        }

        /*
         * [INPUT] Filter ke ID yang benar-benar ada — sync() ke ID
         * hantu menabrak FK (500). ID tak valid dilaporkan, bukan
         * diabaikan senyap.
         */
        if ($questionIds !== []) {
            $grade = $this->normalizeTingkatKelas($exam->tingkat_kelas ?? null);

            $validIds = QuestionBank::whereIn('id', array_keys($questionIds))
                ->where('teacher_id', $exam->teacher_id)
                ->where('subject_id', $exam->subject_id)
                ->when(
                    $grade !== null && Schema::hasColumn('question_banks', 'tingkat_kelas'),
                    fn ($q) => $q->where('tingkat_kelas', $grade)
                )
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->flip();

            $invalid = array_keys(array_filter(
                $questionIds,
                fn ($v, $id) => !$validIds->has((int) $id),
                ARRAY_FILTER_USE_BOTH
            ));

            if ($invalid !== []) {
                return redirect()->route('guru.cbt.soal', $exam->id)->with(
                    'error',
                    'Beberapa soal tidak ditemukan di bank soal (ID: '
                    . implode(', ', array_slice($invalid, 0, 5)) . '). Muat ulang halaman lalu coba lagi.'
                );
            }

            // Satu grup kategori TKA harus selalu terpilih seluruhnya.
            // Per statement satu nomor LJK dan bobot masing-masing.
            if (Schema::hasColumn('question_banks', 'tka_group_code')) {
                $groups = QuestionBank::whereIn('id', array_keys($questionIds))
                    ->where('tka_category', true)->whereNotNull('tka_group_code')
                    ->pluck('tka_group_code')->unique()->filter()->values();
                foreach ($groups as $group) {
                    $required = QuestionBank::where('teacher_id', $exam->teacher_id)
                        ->where('subject_id', $exam->subject_id)
                        ->when($grade !== null, fn ($q) => $q->where('tingkat_kelas', $grade))
                        ->where('tka_group_code', $group)->where('tka_category', true)
                        ->pluck('id')->map(fn ($id) => (int)$id)->all();
                    if (array_diff($required, array_keys($questionIds))) {
                        return redirect()->route('guru.cbt.soal', $exam->id)->with('error', 'Grup kategori TKA ' . $group . ' harus dipilih lengkap.');
                    }
                }
            }
            // Urutkan pernyataan kategori 1,2,3 meskipun daftar Bank Soal
            // tampil terbaru dahulu. Nomor LJK tetap cocok dengan kunci.
            if (Schema::hasColumn('question_banks', 'tka_group_code')) {
                $orderedMeta = QuestionBank::whereIn('id', array_keys($questionIds))
                    ->get(['id', 'tka_group_code', 'tka_category', 'tka_statement_order'])->keyBy('id');
                $orderedIds = [];
                $visitedGroups = [];
                foreach (array_keys($questionIds) as $id) {
                    $q = $orderedMeta->get($id);
                    if ($q && $q->tka_category && $q->tka_group_code) {
                        $g = (string)$q->tka_group_code;
                        if (isset($visitedGroups[$g])) continue;
                        $visitedGroups[$g] = true;
                        foreach ($orderedMeta->filter(fn ($v) => $v->tka_category && $v->tka_group_code === $g)
                            ->sortBy('tka_statement_order') as $groupQ) $orderedIds[] = (int)$groupQ->id;
                    } else $orderedIds[] = $id;
                }
                $questionIds = [];
                foreach ($orderedIds as $i => $id) $questionIds[$id] = ['urutan' => $i + 1];
            }
            $exam->questions()->sync($questionIds);
        } else {
            $exam->questions()->sync([]);
        }

        /*
         * [LAPIS-A] Satu query atomik: versi +1 DAN updated_at naik
         * bersama — getCurrentVersion() service selalu segar.
         */
        Exam::whereKey($exam->id)->update([
            'sync_version' => DB::raw('sync_version + 1'),
            'updated_at' => now(),
        ]);

        $this->refreshPrivateLjkSnapshotV13();

        return redirect()
            ->route('guru.cbt')
            ->with('success', 'Soal berhasil diperbarui. Jangan lupa tekan '
                . '"📱 Sinkronkan ke Murid" agar perangkat murid menerima versi terbaru.');
    }

    /* ==========================================================
     * SINKRONKAN PAKET CBT KE PERANGKAT SISWA
     * ========================================================== */
    public function syncToStudents(Exam $exam, LmsSyncService $lmsSync)
    {
        $this->resolveOwnedExam($exam);

        if ($scopeError = $this->examQuestionScopeError($exam)) {
            return redirect()->route('guru.cbt')->with('error', $scopeError);
        }

        Exam::whereKey($exam->id)->update([
            'sync_version' => DB::raw('sync_version + 1'),
            'updated_at' => now(),
        ]);

        $exam->refresh();

        $this->refreshPrivateLjkSnapshotV13();

        [$flashType, $notice] = $this->pushExamWithNotice($exam, $lmsSync, 'update');

        $questionCount = $exam->questions()->count();

        /* [LJK-V13R7-PRIVATE-PACKAGE]
         * Bersamaan dengan paket siswa TANPA kunci, kirim snapshot privat
         * (kunci + jenis soal + roster) hanya ke HP guru.
         */
        try {
            app(\App\Services\Ljk\LjkPrivatePackageService::class)
                ->syncToTeacherPwa($exam->fresh(['questions']));
        } catch (\Throwable $e) {
            Log::warning('LJK v13R7: paket privat ke PWA Guru belum terkirim.', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($flashType === 'warning') {
            return redirect()->route('guru.cbt')->with('warning', 'Paket ujian "' . $exam->judul_ujian . '" tersimpan lokal. ' . $notice);
        }

        return redirect()->route('guru.cbt')->with(
            'success',
            'Paket ujian "' . $exam->judul_ujian . '" telah dikirim ke Cloud — '
            . 'perangkat murid menerima saat tersinkron (' . $questionCount
            . ' soal, kunci jawaban tetap di server guru).'
        );
    }

    /* ==========================================================
     * AKTIFKAN UJIAN
     * ========================================================== */
    public function activate(Exam $exam, LmsSyncService $lmsSync)
    {
        $this->resolveOwnedExam($exam);

        /*
         * [INPUT] Ujian kosong tidak boleh dibuka — semua siswa akan
         * bernilai tanpa dasar koreksi.
         */
        if ($exam->questions()->count() === 0) {
            return redirect()->route('guru.cbt')->with('error', 'Ujian belum memiliki soal — tambahkan soal terlebih dahulu.');
        }

        if ($scopeError = $this->examQuestionScopeError($exam)) {
            return redirect()->route('guru.cbt')->with('error', $scopeError);
        }

        /*
         * [LAPIS-A] Satu query atomik: status + versi + updated_at.
         */
        Exam::whereKey($exam->id)->update([
            'status' => 'aktif',
            'sync_version' => DB::raw('sync_version + 1'),
            'updated_at' => now(),
        ]);

        $exam->refresh();

        $this->refreshPrivateLjkSnapshotV13();

        [$flashType, $notice] = $this->pushExamWithNotice($exam, $lmsSync, 'update');

        if ($flashType === 'warning') {
            return redirect()->route('guru.cbt')->with('warning', 'Ujian telah DIBUKA secara lokal — murid dapat mulai '
                . 'mengerjakan via modul CBT (LAN). Catatan: ' . $notice);
        }

        return redirect()->route('guru.cbt')->with('success', 'Ujian telah DIBUKA. Status sudah dikirim ke Cloud — '
            . 'perangkat murid PWA menerima saat tersinkron.');
    }

    /* ==========================================================
     * TUTUP UJIAN
     * ========================================================== */
    public function deactivate(Request $request, Exam $exam, LmsSyncService $lmsSync)
    {
        $this->resolveOwnedExam($exam);
        $exam->loadMissing('questions');

        $forcedCount = 0;

        /*
         * TUTUP UJIAN = PAKSA SELESAI SEMUA SESI AKTIF.
         *
         * Snapshot final setiap siswa adalah StudentAnswer TERAKHIR
         * yang sudah berhasil diterima server melalui autosave.
         * Jawaban yang sudah ada tidak ditulis ulang.
         * Soal yang belum pernah tersimpan dibuat sebagai jawaban kosong.
         *
         * Penutupan ujian TIDAK memberi nilai. Nilai tetap NULL sampai
         * diproses melalui mekanisme koreksi resmi.
         */
        DB::transaction(function () use ($exam, &$forcedCount) {
            $sessions = ExamSession::query()
                ->where('exam_id', $exam->id)
                ->where('status', 'sedang_mengerjakan')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $existingQuestionIds = StudentAnswer::query()
                    ->where('exam_session_id', $session->id)
                    ->pluck('question_bank_id')
                    ->map(fn ($id) => (int) $id)
                    ->flip();

                foreach ($exam->questions as $question) {
                    $qid = (int) $question->id;

                    if ($existingQuestionIds->has($qid)) {
                        continue;
                    }

                    StudentAnswer::create([
                        'exam_session_id' => $session->id,
                        'question_bank_id' => $qid,
                        'jawaban_siswa' => '',
                        'is_benar' => null,
                        'nilai_per_soal' => 0,
                    ]);
                }

                $session->forceFill([
                    'status' => 'selesai',
                    'waktu_submit' => now(),
                    'nilai_akhir' => null,
                    'cbt_lock_token_hash' => null,
                    'cbt_lock_acquired_at' => null,
                    'cbt_last_seen_at' => now(),
                    'cbt_lock_ip' => null,
                    'cbt_lock_user_agent' => null,
                ])->save();

                $forcedCount++;
            }

            Exam::whereKey($exam->id)->update([
                'status' => 'selesai',
                'sync_version' => DB::raw('sync_version + 1'),
                'updated_at' => now(),
            ]);
        });

        $exam->refresh();

        $this->refreshPrivateLjkSnapshotV13();

        $pendingCorrection = ExamSession::query()
            ->where('exam_id', $exam->id)
            ->where('status', 'selesai')
            ->whereNull('nilai_akhir')
            ->count();

        Log::warning('CBT ditutup — seluruh sesi aktif dipaksa selesai.', [
            'exam_id' => $exam->id,
            'teacher_id' => $exam->teacher_id,
            'forced_sessions' => $forcedCount,
            'pending_correction' => $pendingCorrection,
            'snapshot' => 'last_saved_student_answers',
        ]);

        [$flashType, $notice] = $this->pushExamWithNotice($exam, $lmsSync, 'update');

        $message = 'Ujian telah DITUTUP. '
            . "{$forcedCount} sesi yang masih mengerjakan dipaksa selesai menggunakan jawaban terakhir yang tersimpan. "
            . "{$pendingCorrection} sesi sekarang menunggu koreksi.";

        if ($flashType === 'warning') {
            return redirect()->route('guru.cbt')->with('warning', $message . ' Catatan sinkron: ' . $notice);
        }

        return redirect()->route('guru.cbt')->with('success', $message);
    }

    /* ==========================================================
     * HAPUS PAKET UJIAN
     * ==========================================================
     * [GUARD-JAWABAN] + [ORPHAN-FIX] + [DELETE-EPOCH].
     */
    public function destroy(Exam $exam, LmsSyncService $lmsSync)
    {
        $guru = $this->resolveOwnedExam($exam);

        $pending = 0;
        $inProgress = 0;

        if (Schema::hasTable('exam_sessions')) {
            $pending = DB::table('exam_sessions')
                ->where('exam_id', $exam->id)
                ->where('status', 'selesai')
                ->whereNull('nilai_akhir')
                ->count();

            $inProgress = DB::table('exam_sessions')
                ->where('exam_id', $exam->id)
                ->where('status', 'sedang_mengerjakan')
                ->count();
        }

        if ($inProgress > 0) {
            return redirect()->route('guru.cbt')->with(
                'warning',
                "Paket ujian belum dapat dihapus karena masih ada {$inProgress} sesi murid yang sedang mengerjakan. "
                . 'Tutup ujian terlebih dahulu agar jawaban autosave terakhir dimaterialisasi sebagai jawaban final.'
            );
        }

        if ($pending > 0) {
            return redirect()->route('guru.cbt')->with(
                'warning',
                "Paket ujian belum dapat dihapus karena masih ada {$pending} jawaban murid yang menunggu koreksi. "
                . 'Selesaikan koreksi CBT terlebih dahulu agar tidak ada jawaban yang kehilangan paket induknya.'
            );
        }

        $syncId = trim((string) $exam->sync_id);
        $epochVersion = \App\Services\Sync\VersionService::next();
        $assignments = LmsAssignment::with('topic.course')
            ->where('exam_id', $exam->id)
            ->get();
        $deleteSyncPending = false;
        $deleteSyncSkipped = false;

        /*
         * Tombstone dikirim/diantrekan lebih dulu. Jika bahkan belum bisa
         * masuk antrean, penghapusan lokal dibatalkan agar perangkat lain
         * tidak menyimpan paket yatim yang tidak pernah menerima delete.
         */
        foreach ($assignments as $assignment) {
            $aSyncId = trim((string) $assignment->sync_id);

            if ($aSyncId === '') {
                continue;
            }

            try {
                $deleteResult = $lmsSync->pushAssignmentDeleteToStudents($aSyncId, $epochVersion);
                $deleteStatus = strtolower((string) ($deleteResult['status'] ?? ''));
                $deleteSyncPending = $deleteSyncPending || $deleteStatus === 'pending';
                $deleteSyncSkipped = $deleteSyncSkipped || $deleteStatus === 'skipped';
            } catch (\Throwable $e) {
                Log::warning('Penghapusan exam dibatalkan: delete aktivitas LMS gagal pra-antrean.', [
                    'exam_id' => $exam->id,
                    'assignment_id' => $assignment->id,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('guru.cbt')->with(
                    'error',
                    'Paket ujian belum dihapus. Perintah hapus untuk aktivitas LMS "'
                    . $assignment->title
                    . '" belum berhasil masuk antrean sinkronisasi. Coba lagi setelah koneksi/sinkronisasi normal.'
                );
            }
        }

        if ($syncId !== '') {
            try {
                $deleteResult = $lmsSync->pushExamDelete($syncId, $epochVersion);
                $deleteStatus = strtolower((string) ($deleteResult['status'] ?? ''));
                $deleteSyncPending = $deleteSyncPending || $deleteStatus === 'pending';
                $deleteSyncSkipped = $deleteSyncSkipped || $deleteStatus === 'skipped';
            } catch (\Throwable $e) {
                Log::warning('Penghapusan exam dibatalkan: delete exam gagal pra-antrean.', [
                    'exam_id' => $exam->id,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('guru.cbt')->with(
                    'error',
                    'Paket ujian belum dihapus. Perintah hapus paket belum berhasil masuk antrean sinkronisasi. '
                    . 'Coba lagi setelah koneksi/sinkronisasi normal.'
                );
            }
        }

        /*
         * [LJK-DELETE-V27]
         * Paket privat Koreksi LJK di PWA Guru bukan broadcast cbt_exam siswa.
         * Kirim tombstone privat ke HP guru SEBELUM record Exam lokal dihapus.
         * Outbox dibuat lebih dulu; bila Cloud offline, retry daemon tetap dapat
         * mengirimkannya setelah koneksi pulih.
         */
        try {
            $privateDelete = app(LjkPrivatePackageService::class)
                ->syncDeleteToTeacherPwa($exam, $epochVersion);
            $privateStatus = strtolower((string) (($privateDelete['push']['status'] ?? '')));
            $deleteSyncPending = $deleteSyncPending || $privateStatus === 'pending';
        } catch (\Throwable $e) {
            // Tombstone harus masuk outbox sebelum paket lokal dihapus.
            // Cloud offline diperbolehkan selama queueAndPush menyimpan pending.
            Log::warning('[LJK-DELETE-V28] Penghapusan dibatalkan: tombstone privat tidak terantre.', [
                'exam_id' => $exam->id,
                'exam_sync_id' => $syncId,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('guru.cbt')->with('error',
                'Paket belum dihapus: perintah hapus ke PWA Guru gagal dicatat. '
                . 'Periksa antrean sinkronisasi lalu coba kembali.'
            );
        }

        $cleanup = [
            'sessions' => 0,
            'ljk_scan_results' => 0,
            'ljk_results' => 0,
            'ljk_batches' => 0,
            'item_analysis_runs' => 0,
            'lms_assignments' => $assignments->count(),
        ];

        try {
            DB::transaction(function () use ($exam, $assignments, &$cleanup) {
                $examId = (int) $exam->id;
                $assignmentIds = $assignments->pluck('id')->map(fn ($id) => (int) $id)->all();

                if (Schema::hasTable('item_analysis_runs')) {
                    $runIds = DB::table('item_analysis_runs')
                        ->where('exam_id', $examId)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    if ($runIds !== [] && Schema::hasTable('item_analysis_results')) {
                        DB::table('item_analysis_results')
                            ->whereIn('item_analysis_run_id', $runIds)
                            ->delete();
                    }

                    $cleanup['item_analysis_runs'] = DB::table('item_analysis_runs')
                        ->where('exam_id', $examId)
                        ->delete();
                }

                if (Schema::hasTable('ljk_scan_results')) {
                    $q = DB::table('ljk_scan_results')->where('exam_id', $examId);
                    if (Schema::hasColumn('ljk_scan_results', 'exam_sync_id') && trim((string) $exam->sync_id) !== '') {
                        $q->orWhere('exam_sync_id', trim((string) $exam->sync_id));
                    }
                    $cleanup['ljk_scan_results'] = $q->delete();
                }

                if (Schema::hasTable('ljk_results')) {
                    $cleanup['ljk_results'] = DB::table('ljk_results')
                        ->where('exam_id', $examId)
                        ->delete();
                }

                if (Schema::hasTable('ljk_batches')) {
                    $cleanup['ljk_batches'] = DB::table('ljk_batches')
                        ->where('exam_id', $examId)
                        ->delete();
                }

                if (Schema::hasTable('exam_sessions')) {
                    $sessionIds = DB::table('exam_sessions')
                        ->where('exam_id', $examId)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    if ($sessionIds !== [] && Schema::hasTable('student_answers')) {
                        DB::table('student_answers')
                            ->whereIn('exam_session_id', $sessionIds)
                            ->delete();
                    }

                    $cleanup['sessions'] = DB::table('exam_sessions')
                        ->where('exam_id', $examId)
                        ->delete();
                }

                if (Schema::hasTable('exam_answers')) {
                    DB::table('exam_answers')->where('exam_id', $examId)->delete();
                }

                if (Schema::hasTable('exam_questions')) {
                    DB::table('exam_questions')->where('exam_id', $examId)->delete();
                }

                if ($assignmentIds !== [] && Schema::hasTable('lms_assignment_submissions')) {
                    DB::table('lms_assignment_submissions')
                        ->whereIn('assignment_id', $assignmentIds)
                        ->delete();
                }

                if (Schema::hasTable('lms_assignments')) {
                    DB::table('lms_assignments')->where('exam_id', $examId)->delete();
                }

                $exam->delete();
            });
        } catch (\Throwable $e) {
            report($e);

            Log::error('Penghapusan paket ujian gagal pada tahap pembersihan lokal.', [
                'exam_id' => $exam->id,
                'teacher_id' => $guru->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('guru.cbt')->with(
                'error',
                'Paket ujian belum berhasil dihapus karena pembersihan data lokal gagal: ' . $e->getMessage()
            );
        }

        Log::info('Paket ujian dihapus beserta data operasional terkait.', [
            'exam_id' => $exam->id,
            'teacher_id' => $guru->id,
            'cleanup' => $cleanup,
        ]);

        $this->refreshPrivateLjkSnapshotV13();

        $successMessage = 'Paket ujian berhasil dihapus. Sesi CBT, relasi soal, hasil pemindaian LJK, snapshot analisis butir, '
            . 'dan aktivitas LMS terkait telah dibersihkan. Nilai yang sudah resmi masuk Buku Nilai tetap dipertahankan.';

        if ($deleteSyncPending) {
            return redirect()
                ->route('guru.cbt')
                ->with(
                    'warning',
                    $successMessage . ' Perintah hapus untuk perangkat lain sudah masuk antrean dan akan dikirim otomatis saat koneksi Cloud normal.'
                );
        }

        if ($deleteSyncSkipped) {
            $successMessage .= ' Paket/aktivitas yang tidak pernah didistribusikan ke PWA cukup dibersihkan secara lokal.';
        }

        return redirect()
            ->route('guru.cbt')
            ->with('success', $successMessage);
    }

    /* ==========================================================
     * PAKSA SELESAI SESI CBT LAN
     * ==========================================================
     * Snapshot final = StudentAnswer terakhir yang sudah diterima server
     * melalui autosave. Tidak mengambil ulang jawaban dari browser.
     * Hanya boleh jika durasi sesi benar-benar sudah berakhir.
     */
    public function forceFinish(ExamSession $session)
    {
        $exam = Exam::with('questions')->findOrFail($session->exam_id);
        $this->resolveOwnedExam($exam);

        if ($session->status === 'selesai') {
            return redirect()->route('guru.cbt')->with('warning', 'Sesi murid ini sudah selesai.');
        }

        if ($session->status !== 'sedang_mengerjakan') {
            return redirect()->route('guru.cbt')->with('error', 'Sesi tidak dapat dipaksa selesai karena statusnya tidak valid.');
        }

        if (!$session->waktu_masuk) {
            return redirect()->route('guru.cbt')->with('error', 'Waktu mulai sesi tidak tersedia. Paksa selesai dibatalkan.');
        }

        $durationMinutes = (int) ($exam->durasi_menit ?? 0);

        if ($durationMinutes <= 0) {
            return redirect()->route('guru.cbt')->with(
                'error',
                'Ujian ini tidak memiliki batas durasi. Paksa selesai otomatis hanya tersedia untuk ujian yang waktunya sudah berakhir.'
            );
        }

        $endsAt = \Carbon\Carbon::parse($session->waktu_masuk)->addMinutes($durationMinutes);

        if (now()->lt($endsAt)) {
            return redirect()->route('guru.cbt')->with(
                'warning',
                'Belum dapat dipaksa selesai. Waktu murid masih tersisa sampai '
                . $endsAt->format('H:i:s') . '.'
            );
        }

        DB::transaction(function () use ($session, $exam) {
            $locked = ExamSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'selesai') {
                return;
            }

            /*
             * StudentAnswer yang sudah ada TIDAK diubah: itulah snapshot
             * jawaban terakhir dari autosave. Hanya soal yang belum pernah
             * tersimpan yang dimaterialisasi sebagai jawaban kosong.
             */
            $existingQuestionIds = StudentAnswer::where('exam_session_id', $locked->id)
                ->pluck('question_bank_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            foreach ($exam->questions as $question) {
                $qid = (int) $question->id;

                if ($existingQuestionIds->has($qid)) {
                    continue;
                }

                StudentAnswer::create([
                    'exam_session_id' => $locked->id,
                    'question_bank_id' => $qid,
                    'jawaban_siswa' => '',
                    'is_benar' => null,
                    'nilai_per_soal' => 0,
                ]);
            }

            $locked->forceFill([
                'status' => 'selesai',
                'waktu_submit' => now(),
                'nilai_akhir' => null,
                'cbt_lock_token_hash' => null,
                'cbt_lock_acquired_at' => null,
                'cbt_last_seen_at' => now(),
                'cbt_lock_ip' => null,
                'cbt_lock_user_agent' => null,
            ])->save();
        });

        Log::warning('CBT LAN dipaksa selesai setelah durasi habis.', [
            'exam_session_id' => $session->id,
            'exam_id' => $exam->id,
            'student_id' => $session->student_id,
            'teacher_id' => $exam->teacher_id,
            'snapshot' => 'last_saved_student_answers',
        ]);

        return redirect()->route('guru.cbt')->with(
            'success',
            'Sesi berhasil dipaksa selesai. Jawaban terakhir yang tersimpan dijadikan jawaban final dan sekarang masuk antrean koreksi.'
        );
    }

    /* ==========================================================
     * EXPORT REKAP HASIL UJIAN CBT KE EXCEL — TER-SCOPE PER GURU
     * ========================================================== */
    public function exportHasil()
    {
        $guru = $this->currentTeacher();

        $sessions = collect();

        if ($guru && Schema::hasTable('exam_sessions')) {
            $sessions = DB::table('exam_sessions')
                ->join('students', 'exam_sessions.student_id', '=', 'students.id')
                ->join('exams', 'exam_sessions.exam_id', '=', 'exams.id')
                ->where('exams.teacher_id', $guru->id)
                ->select(
                    'exam_sessions.*',
                    'students.nisn',
                    'students.nama',
                    'exams.judul_ujian'
                )
                ->latest('exam_sessions.created_at')
                ->get();
        }

        if ($sessions->isEmpty()) {
            return redirect()
                ->route('guru.cbt')
                ->with('error', 'Belum ada data hasil ujian CBT untuk direkap.');
        }

        $headers = [
            'No.',
            'NISN',
            'Nama Murid',
            'Judul Ujian',
            'Status',
            'Hasil/Nilai',
            'Waktu Mulai',
        ];

        $dataArray = [];

        foreach ($sessions as $index => $s) {
            $nisn = !empty($s->nisn) ? (string) $s->nisn : '-';

            /*
             * [EXPORT] Dead code $s->score dihapus — kolom tabel ini
             * adalah nilai_akhir. NULL = menunggu koreksi guru.
             */
            $hasilNilai = ($s->nilai_akhir !== null && $s->nilai_akhir !== '')
                ? $s->nilai_akhir
                : 'Menunggu koreksi';

            $status = !empty($s->status) ? $s->status : '-';

            /*
             * [EXPORT] "Waktu Mulai" = waktu_masuk (siswa resume tidak
             * lagi tercatat mulai lebih awal dari kenyataan).
             */
            $waktuMulai = !empty($s->waktu_masuk)
                ? $s->waktu_masuk
                : ($s->created_at ?? '-');

            $dataArray[] = [
                $index + 1,
                $nisn,
                $s->nama ?? '-',
                $s->judul_ujian ?? '-',
                $status,
                $hasilNilai,
                $waktuMulai,
            ];
        }

        $exportClass = new class($dataArray, $headers) implements
            FromArray,
            WithHeadings,
            WithColumnFormatting,
            WithStyles,
            ShouldAutoSize
        {
            private $data;
            private $headers;

            public function __construct($data, $headers)
            {
                $this->data = $data;
                $this->headers = $headers;
            }

            public function array(): array
            {
                return $this->data;
            }

            public function headings(): array
            {
                return $this->headers;
            }

            public function columnFormats(): array
            {
                return ['B' => NumberFormat::FORMAT_TEXT];
            }

            public function styles(Worksheet $sheet)
            {
                $lastRow = max(1, count($this->data) + 1);

                $sheet->getStyle('A1:G1')->getFont()->setBold(true);

                $sheet->getStyle('A1:G1')
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                $sheet->getRowDimension(1)->setRowHeight(25);

                $sheet->getStyle('A1:G' . $lastRow)
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                foreach (['A', 'B', 'E', 'F', 'G'] as $col) {
                    $sheet->getStyle($col . '2:' . $col . $lastRow)
                        ->getAlignment()
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }

                $sheet->getStyle('A1:G' . $lastRow)
                    ->getAlignment()
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

                $sheet->getStyle('C2:D' . $lastRow)->getAlignment()->setWrapText(true);

                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:G' . $lastRow);

                $sheet->getColumnDimension('A')->setWidth(7);
                $sheet->getColumnDimension('B')->setWidth(18);
                $sheet->getColumnDimension('C')->setWidth(30);
                $sheet->getColumnDimension('D')->setWidth(30);
                $sheet->getColumnDimension('E')->setWidth(15);
                $sheet->getColumnDimension('F')->setWidth(18);
                $sheet->getColumnDimension('G')->setWidth(22);

                return [];
            }
        };

        return Excel::download($exportClass, 'Rekap_Hasil_Ujian_CBT.xlsx');
    }
}