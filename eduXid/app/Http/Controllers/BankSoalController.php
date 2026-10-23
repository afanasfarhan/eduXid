<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Imports\SoalImport;
use App\Imports\SoalWorkbookImport;
use App\Support\ExcelQuestionImageReader;
use App\Support\TkaCategory;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\QuestionBank;
use App\Models\Subject;
use App\Models\Homeroom;
use App\Services\LocalMediaService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use HTMLPurifier_Config;
use HTMLPurifier;

class BankSoalController extends Controller
{
    /**
     * ==========================================================
     * HELPER: GURU PEMILIK SESI LOGIN
     * ==========================================================
     * Satu sumber kebenaran pemetaan User login → Teacher.
     * Selaras dengan ExamController::currentTeacher().
     *
     * [GROUPING-FIX] Kondisi orWhere dibungkus closure — bila
     * kelak ada filter tambahan, kondisi OR tidak akan "meresap"
     * ke luar dan mengubah semantik query.
     */
    private function currentTeacher(): ?Teacher
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        return Teacher::where(function ($query) use ($user) {
            $query->where('nip', $user->login_id)
                ->orWhere('nik', $user->login_id)
                ->orWhere('nuptk', $user->login_id);
        })->first();
    }

    /**
     * Semester yang dipakai untuk scope penugasan Bank Soal.
     * Prioritas session semester aktif; fallback semester terbaru
     * yang benar-benar memiliki teaching_assignment guru tersebut.
     */
    private function activeSemesterIdForTeacher(int $teacherId): ?string
    {
        $sessionSemester = trim((string) session('semester_aktif'));

        if ($sessionSemester !== '') {
            return $sessionSemester;
        }

        $semester = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $teacherId)
            ->max('homerooms.semester_id');

        return $semester !== null ? (string) $semester : null;
    }

    /**
     * Normalisasi nilai tingkat pendidikan Dapodik/Excel menjadi
     * angka kelas SMA 10, 11, atau 12.
     */
    private function normalizeGradeLevel(mixed $raw): ?int
    {
        $value = strtoupper(trim((string) $raw));

        if ($value === '') {
            return null;
        }

        if (preg_match('/(?:^|\D)(10|11|12)(?:\D|$)/', $value, $matches)) {
            return (int) $matches[1];
        }

        $value = preg_replace('/\s+/', ' ', $value);
        $roman = [
            'X' => 10,
            'XI' => 11,
            'XII' => 12,
            'KELAS X' => 10,
            'KELAS XI' => 11,
            'KELAS XII' => 12,
            'TINGKAT X' => 10,
            'TINGKAT XI' => 11,
            'TINGKAT XII' => 12,
        ];

        return $roman[$value] ?? null;
    }

    /**
     * Peta subject_id => [10, 11, 12] sesuai teaching_assignments
     * guru pada semester aktif. Ini menjadi sumber kebenaran untuk
     * pilihan Tingkat Kelas pada form tambah/edit.
     */
    private function teachingGradeMap(int $teacherId): array
    {
        $semesterId = $this->activeSemesterIdForTeacher($teacherId);

        $query = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $teacherId)
            ->select([
                'teaching_assignments.subject_id',
                'homerooms.tingkat_pendidikan',
            ]);

        if ($semesterId !== null) {
            $query->where('homerooms.semester_id', $semesterId);
        }

        $map = [];

        foreach ($query->get() as $row) {
            $subjectId = (int) $row->subject_id;
            $grade = $this->normalizeGradeLevel($row->tingkat_pendidikan ?? null);

            if ($subjectId <= 0 || $grade === null) {
                continue;
            }

            $map[$subjectId] ??= [];

            if (!in_array($grade, $map[$subjectId], true)) {
                $map[$subjectId][] = $grade;
            }
        }

        foreach ($map as &$grades) {
            sort($grades, SORT_NUMERIC);
        }
        unset($grades);

        ksort($map, SORT_NUMERIC);

        return $map;
    }

    private function gradeLevelsForTeacher(int $teacherId): array
    {
        $grades = [];

        foreach ($this->teachingGradeMap($teacherId) as $subjectGrades) {
            foreach ($subjectGrades as $grade) {
                $grades[$grade] = $grade;
            }
        }

        ksort($grades, SORT_NUMERIC);

        return array_values($grades);
    }

    /**
     * Server-side guard: browser tidak boleh memalsukan subject/kelas.
     * Kombinasi mapel + tingkat wajib benar-benar ada pada penugasan
     * guru di semester aktif.
     */
    private function assertTeacherTeachesSubjectGrade(
        int $teacherId,
        int $subjectId,
        int $grade
    ): void {
        $map = $this->teachingGradeMap($teacherId);
        $allowed = $map[$subjectId] ?? [];

        if (!in_array($grade, $allowed, true)) {
            throw ValidationException::withMessages([
                'tingkat_kelas' => 'Tingkat kelas tidak sesuai dengan penugasan mengajar Anda untuk mata pelajaran yang dipilih pada semester aktif.',
            ]);
        }
    }

    /**
     * ==========================================================
     * OWNERSHIP GUARD — SOAL WAJIB MILIK GURU LOGIN
     * ==========================================================
     * Dipakai di edit/update/destroy/cetak — menutup celah
     * guru mengakses/mencetak soal milik guru lain (naskah
     * soal berisi kunci jawaban!).
     */
    private function resolveOwnedSoal(int $id): QuestionBank
    {
        $guru = $this->currentTeacher();

        abort_unless(
            $guru,
            403,
            'Akun Anda tidak terhubung ke data Guru.'
        );

        $soal = QuestionBank::where('id', $id)
            ->where('teacher_id', $guru->id)
            ->first();

        abort_unless(
            $soal,
            404,
            'Soal tidak ditemukan atau bukan milik Anda.'
        );

        return $soal;
    }

    // ==========================================
    // PRIVATE HELPER UNTUK HTML PURIFIER
    // ==========================================
    private function getPurifier(): HTMLPurifier
    {
        return \App\Support\QuestionHtmlSanitizer::purifier();
    }

    // ==========================================
    // M2: HELPER MEDIA SOAL
    // ==========================================

    /**
     * Encode gambar soal menjadi data-URL base64 agar ikut payload
     * CBT dan terbaca OFFLINE di PWA siswa. Guard ukuran menjaga
     * payload broadcast tidak membengkak (D1).
     */
    private function encodeGambarSoal(UploadedFile $file): string
    {
        $binary = file_get_contents($file->getRealPath());
        $mime = $file->getMimeType();
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($binary);

        if (strlen($dataUrl) > 400 * 1024) {
            throw new \RuntimeException(
                'Gambar soal terlalu besar setelah diproses (maks ±400 KB base64). Kecilkan dimensinya.'
            );
        }

        return $dataUrl;
    }

    /**
     * Validasi + ambil gambar soal dari request (jika diunggah).
     */
    private function validateAndEncodeGambarSoal(Request $request): ?string
    {
        if (!$request->hasFile('gambar_soal')) {
            return null;
        }

        $request->validate(
            [
                'gambar_soal' => 'image|mimes:jpeg,png,webp|max:200',
            ],
            [],
            [
                'gambar_soal.max' => 'Gambar soal maksimal 200 KB — kecilkan dimensinya terlebih dahulu.',
            ]
        );

        return $this->encodeGambarSoal($request->file('gambar_soal'));
    }

    /**
     * Validasi + normalisasi link video (opsional).
     */
    private function normalizedVideoUrl(Request $request): ?string
    {
        $videoUrl = trim((string) $request->input('video_url'));

        if ($videoUrl === '') {
            return null;
        }

        if (!filter_var($videoUrl, FILTER_VALIDATE_URL) || strlen($videoUrl) > 2048) {
            throw new \RuntimeException('Link video tidak valid (harus URL lengkap, maks 2048 karakter).');
        }

        return $videoUrl;
    }

    // ==========================================
    // BANK SOAL CRUD
    // ==========================================

    public function index(Request $request)
    {
        $guru = $this->currentTeacher();

        if (!$guru) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Data guru tidak ditemukan.');
        }

        $tingkatDiajar = $this->gradeLevelsForTeacher((int) $guru->id);
        $filterTingkat = (int) $request->query('tingkat_kelas', 0);

        $query = QuestionBank::where('teacher_id', $guru->id)
            ->with('subject');

        if (in_array($filterTingkat, $tingkatDiajar, true)) {
            $query->where('tingkat_kelas', $filterTingkat);
        } else {
            $filterTingkat = null;
        }

        $soals = $query->latest()->get();
        $wordSubjectGrades = $this->teachingGradeMap((int)$guru->id);
        $wordSubjectNames = Subject::whereIn('id', array_keys($wordSubjectGrades))
            ->pluck('nama_mata_pelajaran', 'id')->all();
        // Jumlah keseluruhan milik guru, tidak terpengaruh filter kelas.
        $totalSoalGuru = QuestionBank::where('teacher_id', $guru->id)->count();

        return view('guru.bank_soal.index', compact(
            'soals',
            'totalSoalGuru',
            'tingkatDiajar',
            'filterTingkat',
            'wordSubjectGrades',
            'wordSubjectNames'
        ));
    }

    public function create()
    {
        $guru = $this->currentTeacher();

        if (!$guru) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Data guru tidak ditemukan.');
        }

        $tingkatByMapel = $this->teachingGradeMap((int) $guru->id);
        $tingkatDiajar = $this->gradeLevelsForTeacher((int) $guru->id);
        $subjectIds = array_keys($tingkatByMapel);

        $mapels = Subject::whereIn('id', $subjectIds)
            ->orderBy('nama_mata_pelajaran')
            ->get();

        return view('guru.bank_soal.create', compact(
            'mapels',
            'tingkatDiajar',
            'tingkatByMapel'
        ));
    }

    public function store(Request $request)
    {
        try {

            $guru = $this->currentTeacher();

            if (!$guru) {
                return back()
                    ->with('error', 'Akun Anda tidak terhubung ke data Guru. Hubungi Admin.')
                    ->withInput();
            }

            $validated = $request->validate([
                'subject_id' => 'required|exists:subjects,id',
                'atp_item_id' => 'nullable|integer|exists:atp_items,id',
                'tingkat_kelas' => 'required|integer|in:10,11,12',
                'jenis_soal' => 'required|in:pilihan_ganda,benar_salah,jawaban_singkat,majemuk,tka_kategori',
                'pertanyaan' => 'required|string',
                'bobot_nilai' => 'required|numeric|min:0.1',
                'judul_materi' => 'nullable|string|max:255',
                'indikator' => 'nullable|string',
                'level_kognitif' => 'required|in:C1,C2,C3,C4,C5,C6',
                'opsi' => 'nullable|array',
                'kunci_pg' => 'nullable|string',
                'kunci_majemuk' => 'nullable|array',
                'kunci_singkat' => 'nullable|string',
                'video_url' => 'nullable|url|max:2048',
                'tka_group_code' => 'nullable|string|max:96|regex:/^[A-Za-z0-9_-]+$/',
                'tka_stimulus' => 'nullable|string|max:15000',
                'tka_pernyataan' => 'required_if:jenis_soal,tka_kategori|nullable|string|max:25000',
            ]);

            $this->assertTeacherTeachesSubjectGrade(
                (int) $guru->id,
                (int) $validated['subject_id'],
                (int) $validated['tingkat_kelas']
            );

            // ==========================================
            // AMBIL KUNCI JAWABAN
            // ==========================================

            $kunciJawaban = null;

            if (
                $validated['jenis_soal'] === 'pilihan_ganda' ||
                $validated['jenis_soal'] === 'benar_salah'
            ) {
                $kunciJawaban = $request->input('kunci_pg');
            } elseif ($validated['jenis_soal'] === 'majemuk') {
                $kunciJawaban = $request->input('kunci_majemuk', []);
                if (!empty($kunciJawaban)) {
                    $kunciJawaban = json_encode(array_values($kunciJawaban));
                }
            } elseif ($validated['jenis_soal'] === 'jawaban_singkat') {
                $kunciJawaban = $request->input('kunci_singkat');
            }

            if (empty($kunciJawaban) && $validated['jenis_soal'] !== 'tka_kategori') {
                return back()
                    ->with('error', 'Kunci jawaban wajib dipilih atau diisi!')
                    ->withInput();
            }

            // ==========================================
            // SANITASI HTML
            // ==========================================

            $purifier = $this->getPurifier();

            $cleanPertanyaan = $purifier->purify($validated['pertanyaan']);

            // ==========================================
            // FORMAT OPSI JAWABAN
            // ==========================================

            $opsiJson = null;

            if (in_array($validated['jenis_soal'], ['pilihan_ganda', 'majemuk'])) {
                $opsiRaw = $request->input('opsi', []);
                $opsiJson = [];

                foreach ($opsiRaw as $key => $val) {
                    if (!empty(trim($val))) {
                        $opsiJson[$key] = $purifier->purify($val);
                    }
                }
            }

            // ==========================================
            // M2: MEDIA SOAL (gambar + video)
            // ==========================================

            try {
                $gambarSoal = $this->validateAndEncodeGambarSoal($request);
                $videoUrl = $this->normalizedVideoUrl($request);
            } catch (\RuntimeException $e) {
                return back()
                    ->with('error', $e->getMessage())
                    ->withInput();
            }

            // ==========================================
            // SIMPAN
            // ==========================================

            // TKA PGC kategori: satu nomor LJK A/B untuk tiap pernyataan.
            // Simpan seluruh grup secara atomik; tidak membuat tipe DB baru.
            if ($validated['jenis_soal'] === 'tka_kategori') {
                $statements = TkaCategory::statements($validated['tka_pernyataan'] ?? '');
                $group = trim((string)($validated['tka_group_code'] ?? ''));
                if ($group === '') $group = 'tka-' . (string) Str::uuid();
                $stimulus = $purifier->purify(trim((string)($validated['tka_stimulus'] ?? '')) . "\n" . $cleanPertanyaan);
                DB::transaction(function () use ($statements, $validated, $guru, $group, $stimulus, $gambarSoal) {
                    foreach ($statements as $i => $item) {
                        QuestionBank::create([
                            'teacher_id' => $guru->id,
                            'subject_id' => $validated['subject_id'],
                            'atp_item_id' => $validated['atp_item_id'] ?? null,
                            'tingkat_kelas' => (int)$validated['tingkat_kelas'],
                            'jenis_soal' => 'benar_salah',
                            'pertanyaan' => ($i+1) . '. ' . e($item['text']),
                            'opsi_jawaban' => ['B' => 'Benar', 'S' => 'Salah'],
                            'kunci_jawaban' => $item['key'],
                            'bobot_nilai' => (float)$validated['bobot_nilai'] / count($statements),
                            'judul_materi' => $validated['judul_materi'] ?? null,
                            'indikator' => $validated['indikator'] ?? null,
                            'level_kognitif' => $validated['level_kognitif'],
                            'gambar_soal' => $i === 0 ? $gambarSoal : null,
                            'tka_group_code' => $group,
                            'tka_stimulus' => $stimulus,
                            'tka_category' => true,
                            'tka_statement_order' => $i + 1,
                        ]);
                    }
                });
                return redirect()->route('guru.bankSoal')->with('success', 'TKA kategori berhasil disimpan sebagai ' . count($statements) . ' pernyataan Benar/Salah untuk CBT dan LJK.');
            }

            QuestionBank::create([
                'teacher_id' => $guru->id,
                'subject_id' => $validated['subject_id'],
                'atp_item_id' => $validated['atp_item_id'] ?? null,
                'tingkat_kelas' => (int) $validated['tingkat_kelas'],
                'jenis_soal' => $validated['jenis_soal'],
                'pertanyaan' => $cleanPertanyaan,
                'opsi_jawaban' => $opsiJson,
                'kunci_jawaban' => $kunciJawaban,
                'bobot_nilai' => $validated['bobot_nilai'],
                'judul_materi' => $validated['judul_materi'],
                'indikator' => $validated['indikator'],
                'level_kognitif' => $validated['level_kognitif'],
                'gambar_soal' => $gambarSoal,
                'video_url' => $videoUrl,
                'tka_group_code' => trim((string)($validated['tka_group_code'] ?? '')) ?: null,
                'tka_stimulus' => $purifier->purify((string)($validated['tka_stimulus'] ?? '')) ?: null,
            ]);

            return redirect()
                ->route('guru.bankSoal')
                ->with('success', 'Soal berhasil ditambahkan ke Bank Soal!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return back()
                ->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage())
                ->withInput();
        }
    }

    /** Preview soal dalam mode siswa (CBT/cetak), tanpa kunci jawaban. */
    public function preview($id)
    {
        $soal = $this->resolveOwnedSoal((int) $id);
        $soal->loadMissing('subject');
        $opsi = $soal->opsi_jawaban;
        if (is_string($opsi)) $opsi = json_decode($opsi, true) ?? [];
        elseif (!is_array($opsi)) $opsi = [];
        $formattedOptions = [];
        $index = 0;
        foreach ($opsi as $key => $value) {
            $formattedOptions[] = [
                'key' => (is_string($key) && ctype_alpha($key)) ? strtoupper($key) : chr(65 + $index),
                'text' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
            ];
            $index++;
        }
        $tkaGroupItems = collect();
        if ($soal->tka_category && $soal->tka_group_code) {
            $tkaGroupItems = QuestionBank::where('teacher_id', $soal->teacher_id)
                ->where('subject_id', $soal->subject_id)
                ->where('tingkat_kelas', $soal->tingkat_kelas)
                ->where('tka_category', true)
                ->where('tka_group_code', $soal->tka_group_code)
                ->orderBy('tka_statement_order')->get();
        }
        return view('guru.bank_soal.preview', compact('soal', 'formattedOptions', 'tkaGroupItems'));
    }

    public function edit($id)
    {
        /*
         * [OWNERSHIP] Guru hanya dapat membuka form edit miliknya.
         */
        $soal = $this->resolveOwnedSoal((int) $id);

        $guru = $this->currentTeacher();

        // ==========================================
        // FORMAT OPSI
        // ==========================================

        $opsi = $soal->opsi_jawaban;

        if (is_string($opsi)) {
            $opsi = json_decode($opsi, true) ?? [];
        } elseif (!is_array($opsi)) {
            $opsi = [];
        }

        $formattedOptions = [];
        $index = 0;

        foreach ($opsi as $key => $val) {
            $formattedOptions[] = [
                'key' => is_string($key) ? $key : chr(65 + $index),
                'text' => $val,
            ];
            $index++;
        }

        if (empty($formattedOptions)) {
            $formattedOptions = [
                ['key' => 'A', 'text' => ''],
                ['key' => 'B', 'text' => ''],
                ['key' => 'C', 'text' => ''],
            ];
        }

        $soal->opsi_jawaban = $formattedOptions;

        // ==========================================
        // AMBIL MAPEL
        // ==========================================

        $tingkatByMapel = $this->teachingGradeMap((int) $guru->id);
        $tingkatDiajar = $this->gradeLevelsForTeacher((int) $guru->id);
        $subjectIds = array_keys($tingkatByMapel);

        $mapels = Subject::whereIn('id', $subjectIds)
            ->orderBy('nama_mata_pelajaran')
            ->get();

        return view('guru.bank_soal.edit', compact(
            'soal',
            'mapels',
            'tingkatDiajar',
            'tingkatByMapel'
        ));
    }

    public function update(Request $request, $id)
    {
        try {

            $guru = $this->currentTeacher();

            if (!$guru) {
                return back()
                    ->with('error', 'Akun Anda tidak terhubung ke data Guru. Hubungi Admin.')
                    ->withInput();
            }

            /*
             * [OWNERSHIP] Guru hanya dapat meng-update soal miliknya.
             */
            $soal = QuestionBank::where('id', $id)
                ->where('teacher_id', $guru->id)
                ->firstOrFail();

            $validated = $request->validate([
                'subject_id' => 'required|exists:subjects,id',
                'atp_item_id' => 'nullable|integer|exists:atp_items,id',
                'tingkat_kelas' => 'required|integer|in:10,11,12',
                'jenis_soal' => 'required|in:pilihan_ganda,benar_salah,jawaban_singkat,majemuk',
                'pertanyaan' => 'required|string',
                'bobot_nilai' => 'required|numeric|min:0.1',
                'judul_materi' => 'nullable|string|max:255',
                'indikator' => 'nullable|string',
                'level_kognitif' => 'required|in:C1,C2,C3,C4,C5,C6',
                'opsi' => 'nullable|array',
                'kunci_pg' => 'nullable|string',
                'kunci_majemuk' => 'nullable|array',
                'kunci_singkat' => 'nullable|string',
                'video_url' => 'nullable|url|max:2048',
                'tka_group_code' => 'nullable|string|max:96|regex:/^[A-Za-z0-9_-]+$/',
                'tka_stimulus' => 'nullable|string|max:15000',
            ]);

            $this->assertTeacherTeachesSubjectGrade(
                (int) $guru->id,
                (int) $validated['subject_id'],
                (int) $validated['tingkat_kelas']
            );

            // ==========================================
            // KUNCI JAWABAN
            // ==========================================

            $kunciJawaban = null;

            if (
                $validated['jenis_soal'] === 'pilihan_ganda' ||
                $validated['jenis_soal'] === 'benar_salah'
            ) {
                $kunciJawaban = $request->input('kunci_pg');
            } elseif ($validated['jenis_soal'] === 'majemuk') {
                $kunciJawaban = $request->input('kunci_majemuk', []);
                if (!empty($kunciJawaban)) {
                    $kunciJawaban = json_encode(array_values($kunciJawaban));
                }
            } elseif ($validated['jenis_soal'] === 'jawaban_singkat') {
                $kunciJawaban = $request->input('kunci_singkat');
            }

            if (empty($kunciJawaban)) {
                return back()
                    ->with('error', 'Kunci jawaban wajib dipilih atau diisi!')
                    ->withInput();
            }

            // ==========================================
            // SANITASI
            // ==========================================

            $purifier = $this->getPurifier();

            $cleanPertanyaan = $purifier->purify($validated['pertanyaan']);

            // ==========================================
            // OPSI
            // ==========================================

            $opsiJson = null;

            if (in_array($validated['jenis_soal'], ['pilihan_ganda', 'majemuk'])) {
                $opsiRaw = $request->input('opsi', []);
                $opsiJson = [];

                foreach ($opsiRaw as $key => $val) {
                    if (!empty(trim($val))) {
                        $opsiJson[$key] = $purifier->purify($val);
                    }
                }
            }

            // ==========================================
            // M2: MEDIA SOAL
            // ==========================================
            // Aturan update:
            // - unggah file baru  → gambar ditimpa
            // - centang hapus     → gambar dikosongkan
            // - tanpa keduanya    → gambar lama dipertahankan

            try {
                $videoUrl = $this->normalizedVideoUrl($request);
            } catch (\RuntimeException $e) {
                return back()
                    ->with('error', $e->getMessage())
                    ->withInput();
            }

            $gambarSoal = $soal->gambar_soal;

            if ($request->boolean('remove_gambar')) {
                $gambarSoal = null;
            }

            if ($request->hasFile('gambar_soal')) {
                try {
                    $gambarSoal = $this->validateAndEncodeGambarSoal($request);
                } catch (\RuntimeException $e) {
                    return back()
                        ->with('error', $e->getMessage())
                        ->withInput();
                }
            }

            // ==========================================
            // UPDATE
            // ==========================================
            if ($soal->tka_category) {
                if ($validated['jenis_soal'] !== 'benar_salah') {
                    return back()->with('error', 'Pernyataan kategori TKA harus tetap bertipe Benar/Salah.')->withInput();
                }
                // Selalu pertahankan identitas satu grup yang sama.
                $validated['tka_group_code'] = $soal->tka_group_code;
                $validated['tka_stimulus'] = $soal->tka_stimulus;
            }

            $soal->update([
                'subject_id' => $validated['subject_id'],
                'atp_item_id' => $validated['atp_item_id'] ?? null,
                'tingkat_kelas' => (int) $validated['tingkat_kelas'],
                'jenis_soal' => $validated['jenis_soal'],
                'pertanyaan' => $cleanPertanyaan,
                'opsi_jawaban' => $opsiJson,
                'kunci_jawaban' => $kunciJawaban,
                'bobot_nilai' => $validated['bobot_nilai'],
                'judul_materi' => $validated['judul_materi'],
                'indikator' => $validated['indikator'],
                'level_kognitif' => $validated['level_kognitif'],
                'gambar_soal' => $gambarSoal,
                'video_url' => $videoUrl,
                'tka_group_code' => trim((string)($validated['tka_group_code'] ?? '')) ?: null,
                'tka_stimulus' => $purifier->purify((string)($validated['tka_stimulus'] ?? '')) ?: null,
            ]);

            return redirect()
                ->route('guru.bankSoal')
                ->with('success', 'Soal berhasil diperbarui!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return back()
                ->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Hapus satu soal pada kolom Aksi, tanpa merusak Paket Ujian, jawaban
     * peserta, atau analisis butir yang masih menggunakan soal tersebut.
     * Hak milik guru diperiksa di server, bukan hanya pada tampilan.
     */
    public function destroy($id)
    {
        $soal = $this->resolveOwnedSoal((int) $id);

        try {
            DB::transaction(function () use ($soal): void {
                foreach (['exam_questions', 'student_answers', 'exam_answers', 'item_analysis_results'] as $table) {
                    if (Schema::hasTable($table)
                        && Schema::hasColumn($table, 'question_bank_id')
                        && DB::table($table)->where('question_bank_id', $soal->id)->exists()) {
                        throw ValidationException::withMessages([
                            'soal_id' => 'Soal tidak dapat dihapus karena masih digunakan dalam Paket Ujian, jawaban siswa, atau analisis butir.',
                        ]);
                    }
                }

                $soal->delete();
            }, 3);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Hapus satu Bank Soal gagal.', [
                'question_bank_id' => $soal->id,
                'teacher_id' => $soal->teacher_id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Soal gagal dihapus. Data Bank Soal tidak berubah.');
        }

        return redirect()
            ->route('guru.bankSoal')
            ->with('success', 'Satu soal berhasil dihapus. Soal lainnya tidak berubah.');
    }

    /**
     * Hapus seluruh Bank Soal guru login SAJA. Jika ada relasi ujian,
     * jawaban siswa, atau analisis butir, tolak sebelum menghapus satu pun.
     * Penghapusan langsung bisa memicu ON DELETE CASCADE pada data ujian.
     */
    public function destroyAll(Request $request)
    {
        $guru = $this->currentTeacher();
        abort_unless($guru, 403, 'Akun tidak terhubung dengan data Guru.');

        $request->validate([
            'confirmation' => ['required', 'in:HAPUS SEMUA'],
        ]);

        try {
            $deleted = DB::transaction(function () use ($guru): int {
                $ids = QuestionBank::query()
                    ->where('teacher_id', $guru->id)
                    ->select('id');

                // Berhenti total jika penghapusan dapat merusak soal paket ujian,
                // jawaban peserta, atau riwayat analisis butir.
                foreach (['exam_questions', 'student_answers', 'exam_answers', 'item_analysis_results'] as $table) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($table)
                        && DB::table($table)->whereIn('question_bank_id', clone $ids)->exists()) {
                        throw ValidationException::withMessages([
                            'confirmation' => 'Hapus semua dibatalkan: masih ada soal terkait Paket Ujian, jawaban siswa, atau analisis butir. Lepaskan keterkaitan tersebut dengan aman terlebih dahulu.',
                        ]);
                    }
                }

                return QuestionBank::where('teacher_id', $guru->id)->delete();
            }, 3);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Hapus semua Bank Soal gagal.', [
                'teacher_id' => $guru->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Tidak dapat menghapus Bank Soal. Tidak ada soal yang dihapus.');
        }

        return redirect()->route('guru.bankSoal')
            ->with('success', "{$deleted} soal milik Anda berhasil dihapus. Data guru lain tidak berubah.");
    }

    /**
     * Hapus hanya soal yang dicentang pada daftar Bank Soal.
     * Guard server: semua ID wajib milik guru login dan tidak dipakai ujian.
     * Satu transaksi: jika satu ID tidak aman, tidak ada yang terhapus.
     */
    public function destroySelected(Request $request)
    {
        $guru = $this->currentTeacher();
        abort_unless($guru, 403, 'Akun tidak terhubung ke data Guru.');

        $validated = $request->validate([
            'soal_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'soal_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ], [
            'soal_ids.required' => 'Centang setidaknya satu soal untuk dihapus.',
        ]);
        $ids = array_values(array_unique(array_map('intval', $validated['soal_ids'])));
        try {
            $deleted = DB::transaction(function () use ($guru, $ids): int {
                $owned = QuestionBank::query()
                    ->where('teacher_id', $guru->id)
                    ->whereIn('id', $ids)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (count($owned) !== count($ids)) {
                    throw ValidationException::withMessages([
                        'soal_ids' => 'Ada soal terpilih yang tidak ditemukan atau bukan milik Anda. Tidak ada soal dihapus.',
                    ]);
                }

                foreach (['exam_questions', 'student_answers', 'exam_answers', 'item_analysis_results'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'question_bank_id')
                        && DB::table($table)->whereIn('question_bank_id', $ids)->exists()) {
                        throw ValidationException::withMessages([
                            'soal_ids' => 'Penghapusan dibatalkan: salah satu soal terpilih masih digunakan Paket Ujian, jawaban siswa, atau analisis butir.',
                        ]);
                    }
                }
                return QuestionBank::query()->where('teacher_id', $guru->id)->whereIn('id', $ids)->delete();
            }, 3);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Hapus Bank Soal terpilih gagal.', [
                'teacher_id' => $guru->id,
                'jumlah_dipilih' => count($ids),
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Penghapusan soal terpilih gagal. Tidak ada soal yang dihapus.');
        }
        return back()->with('success', "{$deleted} soal terpilih berhasil dihapus. Soal lain tidak berubah.");
    }

    // ==========================================
    // CETAK SOAL
    // ==========================================

    /**
     * [NULL-SAFETY] Dulu $guru->id ?? 0 fallback diam-diam —
     * daftar soal & kelas kosong tanpa penjelasan. Kini early-return
     * dengan pesan error yang jelas.
     *
     * [GROUPING-FIX] Kondisi orWhere dibungkus closure.
     */
    public function cetakIndex()
    {
        $guru = $this->currentTeacher();

        if (!$guru) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Data guru tidak ditemukan.');
        }

        $tingkatKelas = $this->gradeLevelsForTeacher((int) $guru->id);

        $soals = QuestionBank::query()
            ->where('teacher_id', $guru->id)
            ->when(
                !empty($tingkatKelas),
                fn ($query) => $query->whereIn('tingkat_kelas', $tingkatKelas),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->with('subject')
            ->latest()
            ->get();

        return view(
            'guru.cetak_soal.index',
            compact('soals', 'tingkatKelas')
        );
    }

    // ==========================================
    // CETAK PDF / PRINT HTML
    // ==========================================

    /**
     * Cetak naskah sekarang memakai TINGKAT KELAS (10/11/12),
     * bukan nama rombel. Tingkat wajib termasuk penugasan guru
     * pada semester aktif dan semua soal terpilih wajib berasal
     * dari tingkat yang sama.
     */
    public function cetakPdf(Request $request)
    {
        $validated = $request->validate([
            'soal_ids' => 'required|array|min:1',
            'soal_ids.*' => 'integer|exists:question_banks,id',
            'judul_ujian' => 'nullable|string|max:255',
            'tingkat_kelas' => 'required|integer|in:10,11,12',
            'tanggal' => 'nullable|string|max:255',
            'waktu' => 'nullable|string|max:255',
        ]);

        $guru = $this->currentTeacher();

        if (!$guru) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Data guru tidak ditemukan.');
        }

        $tingkat = (int) $validated['tingkat_kelas'];
        $tingkatDiampu = $this->gradeLevelsForTeacher((int) $guru->id);

        if (!in_array($tingkat, $tingkatDiampu, true)) {
            return redirect()
                ->route('guru.cetakSoal')
                ->with('error', 'Tingkat kelas yang dipilih tidak termasuk penugasan mengajar Anda pada semester aktif.');
        }

        $requestedIds = collect($validated['soal_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $soals = QuestionBank::query()
            ->whereIn('id', $requestedIds)
            ->where('teacher_id', $guru->id)
            ->where('tingkat_kelas', $tingkat)
            ->with('subject')
            ->get()
            ->sortBy(fn ($soal) => $requestedIds->search((int) $soal->id))
            ->values();

        if ($soals->count() !== $requestedIds->count()) {
            return redirect()
                ->route('guru.cetakSoal')
                ->with('error', 'Ada soal yang bukan milik Anda atau tidak sesuai dengan tingkat kelas yang dipilih. Pilih ulang soal dari daftar yang tampil.');
        }

        if ($soals->isEmpty()) {
            return redirect()
                ->route('guru.cetakSoal')
                ->with('error', 'Tidak ada soal yang valid untuk dicetak.');
        }

        $sekolah = DB::table('schools')->first();

        $semesterId = session('semester_aktif');
        $tahunAjaran = '-';
        $semester = '-';

        if ($semesterId && strlen((string) $semesterId) === 5) {
            $tahun1 = substr((string) $semesterId, 0, 4);
            $tahun2 = ((int) $tahun1) + 1;
            $tahunAjaran = $tahun1 . '/' . $tahun2;
            $sem = substr((string) $semesterId, 4, 1);
            $semester = $sem === '1' ? 'GANJIL' : 'GENAP';
        }

        $data = [
            'soals' => $soals,
            'sekolah' => $sekolah,
            'guru' => $guru,
            'judul_ujian' => $validated['judul_ujian'] ?? 'NASKAH SOAL',
            'kelas' => 'Kelas ' . $tingkat,
            'tingkat_kelas' => $tingkat,
            'mapel' => $soals->first()->subject->nama_mata_pelajaran ?? 'Mata Pelajaran',
            'tanggal' => $validated['tanggal'] ?? null,
            'waktu' => $validated['waktu'] ?? null,
            'tahunAjaran' => $tahunAjaran,
            'semester' => $semester,
        ];

        return view('guru.cetak_soal.print_template', $data);
    }

    // ==========================================
    // UPLOAD GAMBAR (rich-text editor pertanyaan)
    // Dipertahankan: untuk <img> di dalam HTML pertanyaan.
    // Gambar soal utuh yang ikut OFFLINE ke PWA memakai
    // kolom gambar_soal (base64) — lihat store/update.
    // ==========================================

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $filename = 'soal_' . now()->format('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;

            $media = app(LocalMediaService::class);
            $path = $media->storeUploadedAs($file, 'soal_images', $filename);

            return response()->json([
                'path' => $path,
                'url' => $media->url($path),
            ]);
        }

        return response()->json(['error' => 'Upload gagal'], 400);
    }

    // ==========================================
    // IMPORT EXCEL
    // ==========================================

    /**
     * [IMPORT-OWNER-FIX] teacher_id diteruskan ke SoalImport agar
     * soal hasil impor ter-atribusi ke guru yang mengimpor — bukan
     * NULL/guru pertama.
     *
     * PENTING: Sesuaikan SoalImport untuk menerima teacher_id di
     * constructor:
     *
     *   public function __construct(protected int $teacherId) {}
     *
     * lalu gunakan pada model()->create(['teacher_id' => $this->teacherId, ...]).
     */
    public function importExcel(Request $request)
    {
        // V21R180: DOCX uses the existing protected Bank Soal import route.
        // routes/web.php remains untouched (signed integrity manifest).
        if ($request->input('word_mode') === 'preview') {
            return $this->previewWordImport($request);
        }
        if ($request->input('word_mode') === 'commit') {
            return $this->commitWordImport($request);
        }

        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $guru = $this->currentTeacher();

        if (!$guru) {
            return back()->with('error', 'Data guru tidak ditemukan.');
        }

        try {
            $upload = $request->file('excel_file');
            $extension = strtolower((string) $upload->getClientOriginalExtension());
            $imagesByRow = $extension === 'xlsx'
                ? ExcelQuestionImageReader::fromXlsx($upload->getRealPath())
                : [];
            if (!Schema::hasColumn('question_banks', 'gambar_soal')) {
                throw new \RuntimeException('Kolom gambar_soal belum ada dalam database. Jalankan migrasi eduXid terlebih dahulu.');
            }
            $repairImages = $request->boolean('perbaiki_gambar');
            if ($repairImages && $imagesByRow === []) {
                throw new \RuntimeException('File ini tidak memiliki gambar tertanam untuk diperbaiki. Gunakan XLSX dengan gambar di kolom P.');
            }
            $import = new SoalImport((int) $guru->id, $imagesByRow, $repairImages);
            // Transaksi atomik: seluruh soal + gambar sukses, atau tidak ada yang disimpan.
            DB::transaction(function () use ($upload, $import, $imagesByRow): void {
                // Satu-satunya worksheet data yang boleh diimpor adalah sheet pertama.
                // Sheet panduan/instruksi tidak boleh diperlakukan sebagai baris soal.
                Excel::import(new SoalWorkbookImport($import), $upload);
                if ($import->savedImageCount() !== count($imagesByRow)) {
                    throw new \RuntimeException('Sebagian gambar Excel tidak tersimpan. Impor dibatalkan.');
                }
            });

            return back()
                ->with('success', ($repairImages ? 'Soal lama tidak diduplikasi. ' : 'Bank soal berhasil diimpor. ') . $import->savedImageCount() . ' gambar disimpan ke database.');
        } catch (\Throwable $e) {
            Log::warning('Import Bank Soal gagal.', [
                'teacher_id' => $guru->id,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->with('error', 'Gagal import: ' . $e->getMessage());
        }
    }

    // ==========================================
    // EXPORT / DOWNLOAD TEMPLATE EXCEL
    // ==========================================

    /**
     * [NULL-SAFETY] Dulu $guru->id ?? 0 fallback diam-diam.
     * Kini early-return dengan pesan error.
     */
    public function exportExcel()
    {
        $guru = $this->currentTeacher();

        if (!$guru) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Data guru tidak ditemukan.');
        }

        $semuaSoal = QuestionBank::where('teacher_id', $guru->id)
            ->with('subject')->orderBy('id')->get();
        // Satu baris Excel untuk tiap grup kategori: ekspor tidak
        // menggandakan stimulus/pertanyaan dan menjaga posisi gambar.
        $kategoriGroups = $semuaSoal->filter(fn ($q) => (bool)($q->tka_category ?? false))
            ->groupBy(fn ($q) => (string)$q->subject_id . '|' . (string)$q->tingkat_kelas . '|' . (string)($q->tka_group_code ?? ''));
        $seenGroups = [];
        $soals = $semuaSoal->filter(function ($soal) use (&$seenGroups) {
            if (!(bool)($soal->tka_category ?? false) || empty($soal->tka_group_code)) return true;
            $group = (string)$soal->subject_id . '|' . (string)$soal->tingkat_kelas . '|' . (string)$soal->tka_group_code;
            if (isset($seenGroups[$group])) return false;
            $seenGroups[$group] = true;
            return true;
        })->values();

        $dataArray = [];

        $tingkatByMapel = $this->teachingGradeMap((int) $guru->id);
        $sampleSubjectId = array_key_first($tingkatByMapel);
        $sampleSubjectName = $sampleSubjectId
            ? (Subject::whereKey($sampleSubjectId)->value('nama_mata_pelajaran') ?? 'Mata Pelajaran')
            : 'Mata Pelajaran';
        $sampleGrade = $sampleSubjectId && !empty($tingkatByMapel[$sampleSubjectId])
            ? (int) $tingkatByMapel[$sampleSubjectId][0]
            : 10;

        // ==========================================
        // HEADER
        // ==========================================

        $headers = [
            'No.',
            'Mata Pelajaran',
            'Tingkat Kelas',
            'Jenis Soal',
            'Bobot Nilai',
            'Level Kognitif',
            'Judul Materi',
            'Indikator',
            'Pertanyaan',
            'Opsi A',
            'Opsi B',
            'Opsi C',
            'Opsi D',
            'Opsi E',
            'Kunci Jawaban',
            'Gambar Soal (tempel gambar di sel ini)',
            'TKA Kode Grup',
            'TKA Stimulus Bersama',
            'TKA Pernyataan dan Kunci JSON',
        ];

        // ==========================================
        // JIKA BELUM ADA SOAL
        // ==========================================

        if ($soals->isEmpty()) {
            $dataArray[] = [
                1,
                $sampleSubjectName,
                $sampleGrade,
                'pilihan_ganda',
                1,
                'C3',
                'Kinematika',
                'Menentukan kecepatan',
                'Sebuah mobil menempuh jarak 100 m dalam 10 detik. Berapa kecepatannya?',
                '5 m/s',
                '10 m/s',
                '15 m/s',
                '20 m/s',
                '',
                'B',
                '', // P2: tempel satu gambar PNG/JPG/WebP langsung di sel P2
                '', '', '', // Q, R, S: metadata TKA opsional
            ];
        } else {

            // ==========================================
            // DATA SOAL
            // ==========================================

            foreach ($soals as $index => $soal) {

                // --------------------------------------
                // OPSI JAWABAN
                // --------------------------------------

                $opsi = [];

                if (!empty($soal->opsi_jawaban)) {
                    if (is_array($soal->opsi_jawaban)) {
                        $opsi = $soal->opsi_jawaban;
                    } else {
                        $opsi = json_decode($soal->opsi_jawaban, true) ?? [];
                    }
                }

                // --------------------------------------
                // KUNCI JAWABAN
                // --------------------------------------

                $kunci = $soal->kunci_jawaban;

                // Jika field lama masih digunakan
                if (empty($kunci) && isset($soal->kunci_pg)) {
                    $kunci = $soal->kunci_pg;
                }

                // Soal majemuk
                if ($soal->jenis_soal === 'majemuk') {
                    if (is_string($kunci)) {
                        $decoded = json_decode($kunci, true);
                        if (is_array($decoded)) {
                            $kunci = implode(',', $decoded);
                        }
                    }
                }

                // --------------------------------------
                // SOAL JAWABAN SINGKAT
                // --------------------------------------

                if ($soal->jenis_soal === 'jawaban_singkat') {
                    if (empty($kunci) && isset($soal->kunci_singkat)) {
                        $kunci = $soal->kunci_singkat;
                    }
                }

                // --------------------------------------
                // DATA EXCEL
                // --------------------------------------
                $tkaJson = '';
                $exportJenis = $soal->jenis_soal;
                $exportBobot = $soal->bobot_nilai;
                $exportPertanyaan = strip_tags($soal->pertanyaan ?? '');
                if ((bool)($soal->tka_category ?? false) && !empty($soal->tka_group_code)) {
                    $exportJenis = 'tka_kategori';
                    $groupItems = ($kategoriGroups->get((string)$soal->subject_id . '|' . (string)$soal->tingkat_kelas . '|' . (string)$soal->tka_group_code) ?? collect())
                        ->sortBy(fn ($item) => (int)($item->tka_statement_order ?? $item->id));
                    $statements = [];
                    foreach ($groupItems as $item) {
                        $statementText = preg_replace('/^\d+\.\s*/', '', strip_tags((string)$item->pertanyaan));
                        $statements[] = ['text' => $statementText, 'key' => $item->kunci_jawaban];
                    }
                    $tkaJson = json_encode($statements, JSON_UNESCAPED_UNICODE);
                    $exportBobot = $groupItems->sum('bobot_nilai');
                    $exportPertanyaan = 'Tentukan Benar atau Salah untuk setiap pernyataan berikut.';
                    $kunci = '';
                }
                $dataArray[] = [
                    $index + 1,
                    $soal->subject->nama_mata_pelajaran ?? '',
                    $soal->tingkat_kelas ?? '',
                    $exportJenis ?? '',
                    $exportBobot ?? '',
                    $soal->level_kognitif ?? '',
                    $soal->judul_materi ?? '',
                    $soal->indikator ?? '',
                    $exportPertanyaan,
                    isset($opsi['A']) ? strip_tags($opsi['A']) : '',
                    isset($opsi['B']) ? strip_tags($opsi['B']) : '',
                    isset($opsi['C']) ? strip_tags($opsi['C']) : '',
                    isset($opsi['D']) ? strip_tags($opsi['D']) : '',
                    isset($opsi['E']) ? strip_tags($opsi['E']) : '',
                    $kunci ?? '',
                    '', // Gambar dikemas sebagai objek drawing, bukan base64 di sel.
                    $soal->tka_group_code ?? '',
                    $soal->tka_stimulus ?? '',
                    $tkaJson,
                ];
            }
        }

        // ==========================================
        // EXPORT CLASS
        // ==========================================

        $exportClass = new class($dataArray, $headers, $soals) implements
            \Maatwebsite\Excel\Concerns\FromArray,
            \Maatwebsite\Excel\Concerns\WithHeadings,
            \Maatwebsite\Excel\Concerns\WithStyles,
            \Maatwebsite\Excel\Concerns\WithColumnWidths,
            \Maatwebsite\Excel\Concerns\WithDrawings
        {
            private $data;
            private $headers;
            private $soals;

            public function __construct($data, $headers, $soals)
            {
                $this->data = $data;
                $this->headers = $headers;
                $this->soals = $soals;
            }

            /** Objek gambar XLSX ditempel pada P{baris}, tanpa URL eksternal. */
            public function drawings(): array
            {
                $drawings = [];
                foreach ($this->soals as $index => $soal) {
                    $image = (string) ($soal->gambar_soal ?? '');
                    if (!preg_match('~^data:image/(png|jpeg|webp);base64,(.+)$~s', $image, $parts)) {
                        continue;
                    }
                    $binary = base64_decode($parts[2], true);
                    if ($binary === false || @getimagesizefromstring($binary) === false) {
                        continue;
                    }
                    $extension = $parts[1] === 'jpeg' ? 'jpg' : $parts[1];
                    $tmp = tempnam(sys_get_temp_dir(), 'eduxid_soal_');
                    if ($tmp === false) {
                        throw new \RuntimeException('Gagal membuat file sementara untuk ekspor gambar.');
                    }
                    $path = $tmp . '.' . $extension;
                    if (!@rename($tmp, $path) || file_put_contents($path, $binary) === false) {
                        @unlink($tmp);
                        @unlink($path);
                        throw new \RuntimeException('Gagal menyiapkan gambar soal untuk Excel.');
                    }
                    register_shutdown_function(static function () use ($path): void {
                        @unlink($path);
                    });
                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setName('Gambar Soal ' . ($index + 1));
                    $drawing->setDescription('Gambar soal tersemat');
                    $drawing->setPath($path);
                    $drawing->setCoordinates('P' . ($index + 2));
                    $drawing->setHeight(92);
                    $drawings[] = $drawing;
                }
                return $drawings;
            }

            public function array(): array
            {
                return $this->data;
            }

            public function headings(): array
            {
                return $this->headers;
            }

            public function columnWidths(): array
            {
                return [
                    'A' => 7,
                    'B' => 25,
                    'C' => 15,
                    'D' => 22,
                    'E' => 13,
                    'F' => 17,
                    'G' => 25,
                    'H' => 35,
                    'I' => 55,
                    'J' => 25,
                    'K' => 25,
                    'L' => 25,
                    'M' => 25,
                    'N' => 25,
                    'O' => 18,
                    'P' => 29,
                    'Q' => 23,
                    'R' => 55,
                    'S' => 75,
                ];
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $sheet->getStyle('A1:S1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        ],
                    ],
                ]);

                $lastRow = $sheet->getHighestRow();

                $sheet->getStyle('A1:S' . $lastRow)
                    ->getAlignment()
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

                $sheet->getStyle('A1:S' . $lastRow)
                    ->getAlignment()
                    ->setWrapText(true);

                $sheet->getStyle('A2:A' . $lastRow)
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('C2:C' . $lastRow)
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('E2:E' . $lastRow)
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('F2:F' . $lastRow)
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('O2:O' . $lastRow)
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle('A1:S' . $lastRow)
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                $sheet->getRowDimension(1)->setRowHeight(36);
                foreach ($this->soals as $i => $soal) {
                    if (!empty($soal->gambar_soal)) {
                        $sheet->getRowDimension($i + 2)->setRowHeight(74);
                    }
                }
                $sheet->getComment('P1')->getText()->createTextRun(
                    'Tempel satu gambar PNG/JPG/WebP sebagai objek Excel pada sel P2, P3, dst. '
                    . 'Maksimal 200 KB per gambar. Jangan mengetik nama file atau URL.'
                );

                $sheet->setAutoFilter('A1:S' . $lastRow);

                $sheet->freezePane('A2');

                return [];
            }
        };

        // ==========================================
        // DOWNLOAD
        // ==========================================

        return Excel::download(
            $exportClass,
            'Bank_Soal_Import.xlsx'
        );
    }

    // -------------------------------------------------------------
    // V21R180: WORD (.DOCX) -> PRATINJAU -> SIMPAN (offline-local).
    // -------------------------------------------------------------
    private function wordDraftPath(int $teacherId, string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            abort(400, 'Token impor Word tidak valid.');
        }
        return storage_path('app/private/eduxid-word-drafts/'.$teacherId.'/'.$token.'.json');
    }

    private function loadWordDraft(int $teacherId, string $token): array
    {
        $path = $this->wordDraftPath($teacherId, $token);
        if (!is_file($path) || is_link($path)) abort(404, 'Pratinjau Word tidak ditemukan atau telah disimpan.');
        $draft = json_decode((string)file_get_contents($path), true);
        if (!is_array($draft)
            || (int)($draft['teacher_id'] ?? -1) !== $teacherId
            || (int)($draft['user_id'] ?? -1) !== (int)auth()->id()
            || (int)($draft['created_at'] ?? 0) + (int)config('word_import.preview_ttl_seconds', 3600) < time()) {
            abort(403, 'Pratinjau Word telah kedaluwarsa atau bukan milik guru aktif.');
        }
        return $draft;
    }

    private function previewWordImport(Request $request)
    {
        $guru = $this->currentTeacher();
        abort_unless($guru, 403, 'Akun tidak terhubung ke data Guru.');
        $input = $request->validate([
            'word_file' => 'required|file|mimes:docx|max:10240',
            'subject_id' => 'required|integer|exists:subjects,id',
            'tingkat_kelas' => 'required|integer|in:10,11,12',
            'ai_mode' => 'nullable|in:on,off',
        ]);
        $this->assertTeacherTeachesSubjectGrade((int)$guru->id, (int)$input['subject_id'], (int)$input['tingkat_kelas']);
        try {
            $parsed = app(\App\Services\WordQuestionImportService::class)
                ->parse($request->file('word_file')->getRealPath());
            app(\App\Services\WordQuestionAiReview::class)
                ->review($parsed, ($input['ai_mode'] ?? 'off') === 'on');
        } catch (\Throwable $e) {
            Log::warning('Parser Word gagal.', ['teacher_id'=>$guru->id, 'error'=>$e->getMessage()]);
            return back()->with('error', 'Impor Word belum dapat dipratinjau: '.$e->getMessage());
        }
        $token = bin2hex(random_bytes(16));
        $draft = [
            'teacher_id'=>(int)$guru->id,
            'user_id'=>(int)auth()->id(),
            'subject_id'=>(int)$input['subject_id'],
            'tingkat_kelas'=>(int)$input['tingkat_kelas'],
            'created_at'=>time(),
            'parsed'=>$parsed,
        ];
        $file = $this->wordDraftPath((int)$guru->id, $token);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
            return back()->with('error', 'Folder pratinjau Word tidak bisa dibuat.');
        }
        $contents = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($contents) || strlen($contents) > 4 * 1024 * 1024) {
            return back()->with('error', 'Hasil Word melebihi batas pratinjau 4 MB. Kurangi jumlah atau ukuran gambar.');
        }
        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            return back()->with('error', 'Gagal menyimpan pratinjau Word secara lokal.');
        }
        @chmod($file, 0600);
        return view('guru.bank_soal.word_preview', [
            'draft'=>$draft, 'token'=>$token, 'errorMessage'=>null,
            'prefill'=>[],
        ]);
    }

    private function commitWordImport(Request $request)
    {
        $guru = $this->currentTeacher();
        abort_unless($guru, 403, 'Akun tidak terhubung ke data Guru.');
        $input = $request->validate([
            'draft_token'=>'required|string|size:32',
            'selected'=>'required|array|min:1|max:200',
            'selected.*'=>'required|integer|min:0|max:199|distinct',
            'pertanyaan'=>'required|array|max:200',
            'opsi'=>'required|array|max:200',
            'kunci'=>'required|array|max:200',
            'jenis_soal'=>'required|array|max:200',
        ]);
        $token = (string)$input['draft_token'];
        $draft = $this->loadWordDraft((int)$guru->id, $token);
        $this->assertTeacherTeachesSubjectGrade(
            (int)$guru->id, (int)$draft['subject_id'], (int)$draft['tingkat_kelas']
        );
        $questions = $draft['parsed']['questions'] ?? [];
        $rows = [];
        $errors = [];
        foreach ($input['selected'] as $index) {
            $q = $questions[$index] ?? null;
            if (!is_array($q)) { $errors[] = 'Nomor soal yang dipilih tidak ada.'; continue; }
            $type = (string)($input['jenis_soal'][$index] ?? 'pilihan_ganda');
            if (!in_array($type, ['pilihan_ganda','majemuk','benar_salah'], true)) {
                $errors[] = 'Tipe soal '.$q['number'].' tidak valid.';
                continue;
            }
            $question = trim((string)($input['pertanyaan'][$index] ?? ''));
            if ($question === '' || mb_strlen(strip_tags($question)) > 12000) {
                $errors[] = 'Pertanyaan nomor '.$q['number'].' kosong atau terlalu panjang.';
                continue;
            }
            $options = [];
            if ($type === 'benar_salah') {
                $options = ['B'=>'Benar','S'=>'Salah'];
            } else {
                foreach (['A','B','C','D','E'] as $letter) {
                    $value = trim((string)($input['opsi'][$index][$letter] ?? ''));
                    if ($value === '' || mb_strlen(strip_tags($value)) > 3500) {
                        $errors[] = 'Opsi '.$letter.' soal nomor '.$q['number'].' harus lengkap (maks. 3500 karakter).';
                        continue 2;
                    }
                    $options[$letter] = \App\Support\QuestionHtmlSanitizer::clean($value);
                }
            }
            $key = strtoupper(str_replace(' ', '', (string)($input['kunci'][$index] ?? '')));
            if ($type === 'majemuk') {
                $letters = array_values(array_unique(array_filter(explode(',', $key))));
                if (!$letters || array_diff($letters, ['A','B','C','D','E'])) {
                    $errors[] = 'Kunci soal majemuk nomor '.$q['number'].' harus berisi A–E dipisahkan koma.';
                    continue;
                }
                $key = json_encode($letters);
            } elseif ($type === 'benar_salah') {
                // User-facing A=Benar, B=Salah. Preserve internal B/S semantics.
                if (!in_array($key, ['A','B'], true)) {
                    $errors[] = 'Kunci benar/salah nomor '.$q['number'].' harus A atau B.';
                    continue;
                }
                $key = $key === 'A' ? 'B' : 'S';
            } elseif (!in_array($key, ['A','B','C','D','E'], true)) {
                $errors[] = 'Kunci nomor '.$q['number'].' wajib A–E.';
                continue;
            }
            $rows[] = [
                'teacher_id'=>(int)$guru->id,
                'subject_id'=>(int)$draft['subject_id'],
                'tingkat_kelas'=>(int)$draft['tingkat_kelas'],
                'jenis_soal'=>$type,
                'pertanyaan'=>\App\Support\QuestionHtmlSanitizer::clean($question),
                'opsi_jawaban'=>$options,
                'kunci_jawaban'=>$key,
                'bobot_nilai'=>1,
                'judul_materi'=>null,
                'indikator'=>null,
                'level_kognitif'=>'C2',
                'gambar_soal'=>$q['image'] ?? null,
            ];
        }
        if (!$rows || $errors) {
            return view('guru.bank_soal.word_preview', [
                'draft'=>$draft, 'token'=>$token,
                'errorMessage'=>implode(' ', $errors ?: ['Tidak ada soal valid yang dipilih.']),
                'prefill'=>$request->all(),
            ]);
        }
        // Existing QuestionBank model casts opsi_jawaban to JSON automatically.
        // Serialise commits for this token, preventing double-click duplication.
        $draftPath = $this->wordDraftPath((int)$guru->id, $token);
        $lockPath = $draftPath.'.lock';
        $lock = @fopen($lockPath, 'c');
        if (!$lock) throw ValidationException::withMessages(['word_file'=>'Kunci impor tidak dapat dibuat.']);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw ValidationException::withMessages(['word_file'=>'Impor dokumen ini sedang diproses.']);
        }
        try {
            if (!is_file($draftPath)) abort(409, 'Soal Word ini sudah diimpor atau pratinjau tidak tersedia.');
            // One transaction: either every selected question is saved or none is.
            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) QuestionBank::create($row);
            }, 3);
            @unlink($draftPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
        return redirect()->route('guru.bankSoal')
            ->with('success', count($rows).' soal Word berhasil diimpor ke Bank Soal. Kunci jawaban sudah diperiksa guru.');
    }

}