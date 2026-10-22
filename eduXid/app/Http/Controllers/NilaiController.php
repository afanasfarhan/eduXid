<?php

namespace App\Http\Controllers;

use App\Services\Sync\FinalGradeCalculator;

use App\Services\Sync\VersionService;
use App\Services\Sync\MasterRegistry;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\SchoolIdentity;
use App\Services\Sync\WorkProductPushService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class NilaiController extends Controller
{
    /** [SIKAP-DEFAULT] Konsisten dengan seluruh sistem (1-5). */
    protected const SIKAP_DEFAULT = 3;

    private static ?array $scoreColumns = null;
    private static ?array $syncVersionColumns = null;

    /*
    |--------------------------------------------------------------------------
    | GUARD & IDENTITAS
    |--------------------------------------------------------------------------
    */

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user !== null && ($user->role ?? '') === 'admin';
    }

    private function resolveTeacherForUser(): ?object
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        $teacher = DB::table('teachers')->where('user_id', $user->id)->first();

        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return null;
        }

        $query = DB::table('teachers');

        if (Schema::hasColumn('teachers', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->where(function ($q) use ($loginId) {
                $q->where('nip', $loginId)
                    ->orWhere('nik', $loginId)
                    ->orWhere('nuptk', $loginId);
            })
            ->first();
    }

    /**
     * Ambil teaching assignment secara aman.
     * Admin: semua assignment. Guru: hanya miliknya.
     * Semester lampau BOLEH (edit/cetak historis); semester masa
     * depan ditolak.
     */
    private function getSecureAssignment($assignmentId): object
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Anda harus login.');
        }

        $assignment = DB::table('teaching_assignments')
            ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->join('subjects', 'subjects.id', '=', 'teaching_assignments.subject_id')
            ->where('teaching_assignments.id', $assignmentId)
            ->select(
                'teaching_assignments.id',
                'teaching_assignments.teacher_id',
                'teaching_assignments.homeroom_id',
                'teaching_assignments.bobot_diagnostik',
                'teaching_assignments.bobot_formatif',
                'teaching_assignments.bobot_sumatif',
                'homerooms.nama as kelas',
                'homerooms.semester_id',
                'subjects.nama_mata_pelajaran as mapel'
            )
            ->first();

        if (!$assignment) {
            abort(404, 'Penugasan mengajar tidak ditemukan.');
        }

        if (!$this->isAdmin()) {
            $teacher = $this->resolveTeacherForUser();

            if (!$teacher || (int) $teacher->id !== (int) $assignment->teacher_id) {
                abort(403, 'Anda tidak memiliki akses ke penugasan ini.');
            }
        }

        $this->assertNotFutureSemester($assignment);

        return $assignment;
    }

    private function activeSemesterId(): ?string
    {
        $aktif = session('semester_aktif');

        if ($aktif === null || trim((string) $aktif) === '') {
            $aktif = DB::table('homerooms')->max('semester_id');
        }

        return $aktif !== null ? (string) $aktif : null;
    }

    private function assertNotFutureSemester(object $assignment): void
    {
        $aktif = $this->activeSemesterId();
        $semester = $assignment->semester_id !== null ? (string) $assignment->semester_id : null;

        if ($aktif !== null && $semester !== null && $semester > $aktif) {
            abort(404, 'Penugasan belum tersedia (semester belum dimulai).');
        }
    }

    private function studentsForHomeroom(int $homeroomId): object
    {
        return DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.homeroom_id', $homeroomId)
            ->select('students.id', 'students.nama', 'students.nisn')
            ->orderBy('students.nama')
            ->get();
    }

    private function validateStudentsInHomeroom(int $homeroomId, array $students): void
    {
        $requested = collect($students)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($requested->isEmpty()) {
            abort(422, 'Data siswa tidak boleh kosong.');
        }

        $valid = DB::table('enrollments')
            ->where('homeroom_id', $homeroomId)
            ->whereIn('student_id', $requested)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id);

        if ($requested->diff($valid)->isNotEmpty()) {
            abort(403, 'Terdapat siswa yang bukan bagian dari rombel penugasan ini.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SCHEMA-TOLERANT HELPERS (tahan drift ejaan kolom warisan)
    |--------------------------------------------------------------------------
    */

    private function resolveScoreColumn(string $primary, string $fallback): ?string
    {
        self::$scoreColumns ??= array_flip(Schema::getColumnListing('assessment_scores'));

        if (isset(self::$scoreColumns[$primary])) {
            return $primary;
        }

        if (isset(self::$scoreColumns[$fallback])) {
            return $fallback;
        }

        return null;
    }

    private function tableHasSyncVersion(string $table): bool
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

    /** [06.1] Cap versi pada tulisan lokal bila kolom tersedia. */
    private function stampSyncVersion(string $table, array $data, int $version): array
    {
        if ($this->tableHasSyncVersion($table)) {
            $data['sync_version'] = $version;
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | SINKRONISASI KE PWA GURU (HP) - [FIX-RIWAYAT] teacher-routed
    |--------------------------------------------------------------------------
    */

    /**
     * [FIX-RIWAYAT] Siarkan pertemuan ke semua device guru yang
     * relevan (teacher-routed): Worker SCOPE-TA menentukan penerima
     * (pengampu TA + wali rombel) dari payload TA/homeroom. Dulu
     * targeted ke HP pemilik SAJA dan hanya bila penulis = pemilik,
     * sehingga absensi yang diinput di laptop admin tidak pernah
     * muncul di riwayat PWA guru.
     *
     * Echo balik ke laptop sumber di-skip oleh echo guard.
     */
    private function pushAttendanceToTeacherPwa(
        object $assignment,
        string $tanggal,
        ?string $jamKe,
        ?string $pukul,
        ?string $materi,
        array $studentsPayload,
        int $version
    ): void {
        try {
            app(LmsSyncService::class)->queueAndPush(
                'attendance_session',
                'ta::' . $assignment->id . '::' . $tanggal,
                'upsert',
                $version,
                [
                    'sync_id' => 'ta::' . $assignment->id . '::' . $tanggal,
                    'entity' => 'attendance_session',
                    'teaching_assignment_id' => (int) $assignment->id,
                    'course_sync_id' => 'ta::' . $assignment->id,
                    'homeroom_id' => (int) $assignment->homeroom_id,
                    'tanggal' => $tanggal,
                    'jam_ke' => $jamKe,
                    'pukul' => $pukul,
                    'materi' => $materi,
                    'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
                    'sync_version' => $version,
                    'students' => $studentsPayload,
                ],
                null,   // tanpa target device -> teacher-routed (SCOPE-TA)
                null
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function pushAttendanceDeleteToTeacherPwa(object $assignment, string $tanggal, int $version): void
    {
        try {
            app(LmsSyncService::class)->pushAttendanceSessionDelete(
                'ta::' . $assignment->id . '::' . $tanggal,
                $version,
                null,                                  // null -> teacher-routed (SCOPE-TA)
                (int) $assignment->id,                 // teaching_assignment_id
                (int) $assignment->homeroom_id         // homeroom_id (agar wali ikut ter-scope)
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX - INPUT NILAI & ABSENSI
    |--------------------------------------------------------------------------
    */

    public function index(Request $request, $assignmentId): View
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $students = $this->studentsForHomeroom((int) $assignment->homeroom_id);

        /*
         * GroupBy TANGGAL saja - satu pertemuan per tanggal (konsisten
         * dengan entity id kanonik ta::{ta}::{tanggal}).
         */
        $riwayats = DB::table('assessment_scores')
            ->where('teaching_assignment_id', $assignment->id)
            ->groupBy('tanggal')
            ->orderBy('tanggal', 'desc')
            ->get([
                'tanggal',
                DB::raw('MAX(materi) as materi'),
                DB::raw('MAX(jam_ke) as jam_ke'),
                DB::raw('MAX(pukul) as pukul'),
                DB::raw('COUNT(id) as jumlah_siswa'),
                DB::raw('MAX(updated_at) as updated_at'),
            ]);

        $totalPertemuan = $riwayats->count();

        $riwayats->each(function ($row, $i) use ($totalPertemuan) {
            $row->pertemuan_ke = $totalPertemuan - $i;
        });

        $tanggal_edit = trim((string) $request->query('tanggal_edit', ''));

        $edit_scores = collect();
        $edit_attendances = collect();
        $materi_edit = '';
        $jam_ke_edit = '';
        $pukul_edit = '';

        if ($tanggal_edit !== '') {
            $edit_scores = DB::table('assessment_scores')
                ->where('teaching_assignment_id', $assignment->id)
                ->where('tanggal', $tanggal_edit)
                ->get()
                ->keyBy('student_id');

            $edit_attendances = DB::table('attendances')
                ->where('teaching_assignment_id', $assignment->id)
                ->where('tanggal', $tanggal_edit)
                ->get()
                ->keyBy('student_id');

            $pertama = $edit_scores->first();

            $materiColumn = $this->resolveScoreColumn('materi', 'materi');
            $jamKeColumn = $this->resolveScoreColumn('jam_ke', 'jam_ke');
            $pukulColumn = $this->resolveScoreColumn('pukul', 'pukul');

            $materi_edit = ($materiColumn !== null && $pertama) ? (string) ($pertama->{$materiColumn} ?? '') : '';
            $jam_ke_edit = ($jamKeColumn !== null && $pertama) ? (string) ($pertama->{$jamKeColumn} ?? '') : '';
            $pukul_edit = ($pukulColumn !== null && $pertama) ? (string) ($pertama->{$pukulColumn} ?? '') : '';
        }

        return view('guru.nilai', compact(
            'assignment',
            'students',
            'riwayats',
            'tanggal_edit',
            'edit_scores',
            'edit_attendances',
            'materi_edit',
            'jam_ke_edit',
            'pukul_edit'
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | STORE NILAI + ABSENSI (LWW)
    |--------------------------------------------------------------------------
    */

    public function store(Request $request, $assignmentId): RedirectResponse
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $request->validate([
            'tanggal' => 'required|date_format:Y-m-d',
            'jam_ke' => 'nullable|string|max:20',
            'pukul' => 'nullable|string|max:50',
            'materi' => 'nullable|string|max:255',
            'students' => 'required|array|min:1',
            'students.*.student_id' => 'required|integer|exists:students,id',
            'students.*.status' => 'required|in:Hadir,Sakit,Izin,Alpa',
            'students.*.diagnostik' => 'nullable|integer|min:0|max:100',
            'students.*.formatif' => 'nullable|integer|min:0|max:100',
            'students.*.sumatif' => 'nullable|integer|min:0|max:100',
            'students.*.d1' => 'nullable|integer|min:1|max:5',
            'students.*.d2' => 'nullable|integer|min:1|max:5',
            'students.*.d3' => 'nullable|integer|min:1|max:5',
            'students.*.d4' => 'nullable|integer|min:1|max:5',
            'students.*.d5' => 'nullable|integer|min:1|max:5',
            'students.*.d6' => 'nullable|integer|min:1|max:5',
            'students.*.d7' => 'nullable|integer|min:1|max:5',
            'students.*.d8' => 'nullable|integer|min:1|max:5',
            'students.*.catatan_siswa' => 'nullable|string|max:1000',
        ]);

        $this->validateStudentsInHomeroom((int) $assignment->homeroom_id, $request->input('students'));

        $tanggal = $request->input('tanggal');
        $jamKe = trim((string) $request->input('jam_ke', '')) ?: null;
        $pukul = trim((string) $request->input('pukul', '')) ?: null;
        $materi = trim((string) $request->input('materi', '')) ?: null;

        $materiColumn = $this->resolveScoreColumn('materi', 'materi');
        $jamKeColumn = $this->resolveScoreColumn('jam_ke', 'jam_ke');
        $pukulColumn = $this->resolveScoreColumn('pukul', 'pukul');

        /*
         * SATU versi & SATU timestamp untuk seluruh transaksi -
         * kunci LWW lintas jalur (HP vs web vs batch).
         */
        $version = VersionService::next();   /* [F1-VER] monotonik, satu versi/transaksi */
        $updatedAt = now();

        $studentsPayload = [];

        DB::transaction(function () use (
            $request, $assignment, $tanggal, $jamKe, $pukul, $materi,
            $materiColumn, $jamKeColumn, $pukulColumn,
            $version, $updatedAt, &$studentsPayload
        ) {
            foreach ($request->input('students') as $data) {
                $studentId = (int) $data['student_id'];

                /* ---------------- ABSENSI ---------------- */
                $existingAttendance = DB::table('attendances')
                    ->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $assignment->id)
                    ->where('tanggal', $tanggal)
                    ->first();

                $attendanceData = $this->stampSyncVersion('attendances', [
                    'status' => $data['status'],
                    'updated_at' => $updatedAt,
                ], $version);

                if (!$existingAttendance) {
                    $attendanceData['created_at'] = $updatedAt;
                }

                DB::table('attendances')->updateOrInsert(
                    [
                        'student_id' => $studentId,
                        'teaching_assignment_id' => $assignment->id,
                        'tanggal' => $tanggal,
                    ],
                    $attendanceData
                );

                /* ---------------- NILAI ---------------- */
                $scoreData = $this->stampSyncVersion('assessment_scores', [
                    'diagnostik' => $data['diagnostik'] ?? null,
                    'formatif' => $data['formatif'] ?? null,
                    'sumatif' => $data['sumatif'] ?? null,
                    'd1' => (int) ($data['d1'] ?? self::SIKAP_DEFAULT),
                    'd2' => (int) ($data['d2'] ?? self::SIKAP_DEFAULT),
                    'd3' => (int) ($data['d3'] ?? self::SIKAP_DEFAULT),
                    'd4' => (int) ($data['d4'] ?? self::SIKAP_DEFAULT),
                    'd5' => (int) ($data['d5'] ?? self::SIKAP_DEFAULT),
                    'd6' => (int) ($data['d6'] ?? self::SIKAP_DEFAULT),
                    'd7' => (int) ($data['d7'] ?? self::SIKAP_DEFAULT),
                    'd8' => (int) ($data['d8'] ?? self::SIKAP_DEFAULT),
                    'catatan_siswa' => trim((string) ($data['catatan_siswa'] ?? '')) ?: null,
                    'updated_at' => $updatedAt,
                ], $version);

                if ($materiColumn !== null) { $scoreData[$materiColumn] = $materi; }
                if ($jamKeColumn !== null) { $scoreData[$jamKeColumn] = $jamKe; }
                if ($pukulColumn !== null) { $scoreData[$pukulColumn] = $pukul; }

                $existingScore = DB::table('assessment_scores')
                    ->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $assignment->id)
                    ->where('tanggal', $tanggal)
                    ->first();

                if (!$existingScore) {
                    $scoreData['created_at'] = $updatedAt;
                }

                DB::table('assessment_scores')->updateOrInsert(
                    [
                        'student_id' => $studentId,
                        'teaching_assignment_id' => $assignment->id,
                        'tanggal' => $tanggal,
                    ],
                    $scoreData
                );

                $studentsPayload[] = [
                    'student_id' => $studentId,
                    'status' => $data['status'],
                    'diagnostik' => $data['diagnostik'] ?? null,
                    'formatif' => $data['formatif'] ?? null,
                    'sumatif' => $data['sumatif'] ?? null,
                    'd1' => (int) ($data['d1'] ?? self::SIKAP_DEFAULT),
                    'd2' => (int) ($data['d2'] ?? self::SIKAP_DEFAULT),
                    'd3' => (int) ($data['d3'] ?? self::SIKAP_DEFAULT),
                    'd4' => (int) ($data['d4'] ?? self::SIKAP_DEFAULT),
                    'd5' => (int) ($data['d5'] ?? self::SIKAP_DEFAULT),
                    'd6' => (int) ($data['d6'] ?? self::SIKAP_DEFAULT),
                    'd7' => (int) ($data['d7'] ?? self::SIKAP_DEFAULT),
                    'd8' => (int) ($data['d8'] ?? self::SIKAP_DEFAULT),
                    'catatan_siswa' => trim((string) ($data['catatan_siswa'] ?? '')) ?: null,
                ];
            }
            WorkProductPushService::markDirtyOrFail((int) $assignment->teacher_id);
        });

        /*
         * [06.2] Nilai berubah -> tandai guru pemilik TA; daemon
         * mendorong batch (daily_grade/final_grade/...) ke Cloud
         * otomatis -> fan-out ke semua device guru (wali & admin).
         */
        WorkProductPushService::markDirty((int) $assignment->teacher_id);

        /*
         * [FIX-RIWAYAT] Siarkan ke semua device guru TERLEPAS dari
         * siapa penulisnya (admin/pemilik) - riwayat pertemuan kini
         * muncul di PWA guru siapa pun yang memegang penugasan ini.
         */
        $this->pushAttendanceToTeacherPwa(
            $assignment, $tanggal, $jamKe, $pukul, $materi, $studentsPayload, $version
        );

        return redirect()
            ->route('guru.nilai', $assignment->id)
            ->with('success', "Data pertemuan tanggal {$tanggal} berhasil disimpan!");
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PERTEMUAN
    |--------------------------------------------------------------------------
    */

    public function destroyPertemuan(Request $request, $assignmentId): RedirectResponse
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $request->validate(['tanggal' => 'required|date_format:Y-m-d']);

        $tanggal = $request->input('tanggal');

        $version = VersionService::next();
        DB::transaction(function () use ($assignment, $tanggal, $version) {
            DB::table('assessment_scores')
                ->where('teaching_assignment_id', $assignment->id)
                ->where('tanggal', $tanggal)
                ->delete();

            DB::table('attendances')
                ->where('teaching_assignment_id', $assignment->id)
                ->where('tanggal', $tanggal)
                ->delete();
            DB::table('app_settings')->upsert([[
                'key' => 'attendance::deleted::ta::' . $assignment->id . '::' . $tanggal,
                'value' => (string) $version, 'created_at' => now(), 'updated_at' => now(),
            ]], ['key'], ['value', 'updated_at']);
            WorkProductPushService::markDailyScope((int) $assignment->id, $version);
            WorkProductPushService::markDirtyOrFail((int) $assignment->teacher_id);
        });

        /*
         * [06.2] Penghapusan juga harus menyebar -> batch berikutnya
         * (replace-per-scope) menghapus pertemuan di laptop lain.
         */
        WorkProductPushService::markDirty((int) $assignment->teacher_id);

        /*
         * [FIX-RIWAYAT] Penghapusan disiarkan ke semua device guru.
         */
        $this->pushAttendanceDeleteToTeacherPwa($assignment, $tanggal, $version);

        // [V21R72] Jangan gunakan Referer untuk hasil penghapusan.
        // GET ATP /api/assignment/{id} mengembalikan JSON grade_level/items,
        // yang bukan halaman Buku Nilai. Kembali eksplisit ke penugasan ini.
        return redirect()
            ->route('guru.nilai', ['id' => (int) $assignment->id])
            ->with('success', "Pertemuan tanggal {$tanggal} berhasil dihapus!");
    }

    /*
    |--------------------------------------------------------------------------
    | NILAI AKHIR
    |--------------------------------------------------------------------------
    */

    public function nilaiAkhir($assignmentId): View
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $students = $this->studentsForHomeroom((int) $assignment->homeroom_id);

        $aggregates = DB::table('assessment_scores')
            ->where('teaching_assignment_id', $assignment->id)
            ->groupBy('student_id')
            ->selectRaw(
                'student_id,
                 AVG(diagnostik) as avg_diagnostik,
                 AVG(formatif) as avg_formatif,
                 AVG(sumatif) as avg_sumatif,
                 AVG(d1) as avg_d1, AVG(d2) as avg_d2,
                 AVG(d3) as avg_d3, AVG(d4) as avg_d4,
                 AVG(d5) as avg_d5, AVG(d6) as avg_d6,
                 AVG(d7) as avg_d7, AVG(d8) as avg_d8'
            )
            ->get()
            ->keyBy('student_id');

        $finals = DB::table('final_grades')
            ->where('teaching_assignment_id', $assignment->id)
            ->get()
            ->keyBy('student_id');

        $bobotD = (int) ($assignment->bobot_diagnostik ?? 0);
        $bobotF = (int) ($assignment->bobot_formatif ?? 0);
        $bobotS = (int) ($assignment->bobot_sumatif ?? 0);

        foreach ($students as $student) {
            $agg = $aggregates->get($student->id);
            $final = $finals->get($student->id);

            $avgD = $agg?->avg_diagnostik;
            $avgF = $agg?->avg_formatif;
            $avgS = $agg?->avg_sumatif;
            $calculated = FinalGradeCalculator::fromAverages($avgD, $avgF, $avgS, $bobotD, $bobotF, $bobotS);

            /*
             * [VIEW-COMPAT] Seluruh nama properti yang pernah dipakai
             * versi view mana pun disediakan sekaligus:
             *
             *   avg_d / avg_f / avg_s          (nama singkat)
             *   avg_diagnostik / avg_formatif / avg_sumatif (nama panjang)
             *   calculated_akhir               (nilai akhir hitungan)
             *   avg_sikap                      (rata-rata 8 dimensi)
             *   avg_d1..avg_d8                 (per dimensi)
             *   nilai_akhir_view / deskripsi   (dari final_grades)
             */
            $student->avg_diagnostik = $avgD === null ? null : (float) $avgD;
            $student->avg_formatif = $avgF === null ? null : (float) $avgF;
            $student->avg_sumatif = $avgS === null ? null : (float) $avgS;

            $student->avg_d = $student->avg_diagnostik;
            $student->avg_f = $student->avg_formatif;
            $student->avg_s = $student->avg_sumatif;

            $student->calculated_akhir = $calculated;

            $sikapTotal = 0;

            foreach (range(1, 8) as $i) {
                $key = 'avg_d' . $i;

                $nilaiDimensi = $agg
                    ? (int) round((float) $agg->{$key})
                    : self::SIKAP_DEFAULT;

                $student->{$key} = $nilaiDimensi;
                $sikapTotal += $nilaiDimensi;
            }

            $student->avg_sikap = (int) round($sikapTotal / 8);

            /*
             * Nilai akhir tampil: final_grades bila sudah disimpan,
             * kalau belum pakai hitungan.
             */
            $student->nilai_akhir_view = $final->nilai_akhir ?? $calculated;
            $student->nilai_akhir_saved = $final?->nilai_akhir !== null;
            $student->deskripsi = $final->deskripsi ?? null;
        }

        return view('guru.nilai_akhir', compact('assignment', 'students'));
    }

    public function nilaiAkhirStore(Request $request, $assignmentId): RedirectResponse
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $request->validate([
            'bobot_diagnostik' => 'required|integer|min:0|max:100',
            'bobot_formatif' => 'required|integer|min:0|max:100',
            'bobot_sumatif' => 'required|integer|min:0|max:100',
            'students' => 'required|array|min:1',
            'students.*.student_id' => 'required|integer|exists:students,id',
            'students.*.nilai_akhir' => 'nullable|integer|min:0|max:100',
            'students.*.deskripsi' => 'nullable|string|max:1000',
        ]);

        $totalBobot = (int) $request->input('bobot_diagnostik')
            + (int) $request->input('bobot_formatif')
            + (int) $request->input('bobot_sumatif');

        if ($totalBobot !== 100) {
            return back()->withErrors([
                'bobot_diagnostik' => 'Total bobot Diagnostik + Formatif + Sumatif harus 100%.',
            ])->withInput();
        }

        $this->validateStudentsInHomeroom((int) $assignment->homeroom_id, $request->input('students'));

        $updatedAt = now();
        $version = VersionService::next();   /* [F1-VER] */

        DB::transaction(function () use ($request, $assignment, $updatedAt, $version) {
            DB::table('teaching_assignments')
                ->where('id', $assignment->id)
                ->update([
                    'bobot_diagnostik' => (int) $request->input('bobot_diagnostik'),
                    'bobot_formatif' => (int) $request->input('bobot_formatif'),
                    'bobot_sumatif' => (int) $request->input('bobot_sumatif'),
                    'updated_at' => $updatedAt,
                ]);

            DB::table('app_settings')->upsert([[
                'key' => 'grades::weights::' . $assignment->id, 'value' => (string) $version,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['key'], ['value', 'updated_at']);
            foreach ($request->input('students') as $data) {
                $studentId = (int) $data['student_id'];

                $existing = DB::table('final_grades')
                    ->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $assignment->id)
                    ->first();

                $values = $this->stampSyncVersion('final_grades', [
                    'nilai_akhir' => $data['nilai_akhir'] ?? null,
                    'deskripsi' => $data['deskripsi'] ?? null,
                    'updated_at' => $updatedAt,
                ], $version);

                if (!$existing) {
                    $values['created_at'] = $updatedAt;
                }

                DB::table('final_grades')->updateOrInsert(
                    [
                        'student_id' => $studentId,
                        'teaching_assignment_id' => $assignment->id,
                    ],
                    $values
                );
            }
            WorkProductPushService::markDirtyOrFail((int) $assignment->teacher_id);
        });

        /*
         * [06.2] Nilai akhir berubah -> batch final_grade otomatis.
         */
        WorkProductPushService::markDirty((int) $assignment->teacher_id);

        /* [FINAL-GRADE-AUTO-PUSH-v24] Sinkron langsung ke PWA Guru. */
        try {
            app(\App\Services\Sync\LmsSyncService::class)
                ->pushFinalGradeBatchForAssignment((int) $assignment->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[NILAI-AKHIR] Push langsung final_grade tertunda.',
                [
                    'teaching_assignment_id' => (int) $assignment->id,
                    'error' => $e->getMessage(),
                ]
            );
        }

        /*
         * [D16] Bobot D/F/S adalah kolom teaching_assignments —
         * tabel snapshot master → daemon push master otomatis.
         */
        try {
            MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
        } catch (\Throwable) {
        }

        return back()->with(
            'success',
            'Bobot, nilai akhir, dan deskripsi berhasil disimpan! Nilai akan tersinkron otomatis ke wali & admin.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EXPORT NILAI AKHIR
    |--------------------------------------------------------------------------
    */

    public function nilaiAkhirExport($assignmentId)
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $students = $this->studentsForHomeroom((int) $assignment->homeroom_id);

        $attendanceAgg = DB::table('attendances')
            ->where('teaching_assignment_id', $assignment->id)
            ->groupBy('student_id')
            ->selectRaw(
                "student_id,
                 SUM(CASE WHEN status = 'Sakit' THEN 1 ELSE 0 END) as sakit,
                 SUM(CASE WHEN status = 'Izin' THEN 1 ELSE 0 END) as izin,
                 SUM(CASE WHEN status = 'Alpa' THEN 1 ELSE 0 END) as alpa"
            )
            ->get()
            ->keyBy('student_id');

        $scoresAgg = DB::table('assessment_scores')
            ->where('teaching_assignment_id', $assignment->id)
            ->groupBy('student_id')
            ->selectRaw(
                'student_id,
                 AVG(diagnostik) as avg_diagnostik,
                 AVG(formatif) as avg_formatif,
                 AVG(sumatif) as avg_sumatif'
            )
            ->get()
            ->keyBy('student_id');

        $finals = DB::table('final_grades')
            ->where('teaching_assignment_id', $assignment->id)
            ->get()
            ->keyBy('student_id');

        $bobotD = (int) ($assignment->bobot_diagnostik ?? 0);
        $bobotF = (int) ($assignment->bobot_formatif ?? 0);
        $bobotS = (int) ($assignment->bobot_sumatif ?? 0);

        $rows = [];
        $nomor = 1;

        foreach ($students as $student) {
            $att = $attendanceAgg->get($student->id);
            $agg = $scoresAgg->get($student->id);
            $final = $finals->get($student->id);

            $avgD = $agg?->avg_diagnostik;
            $avgF = $agg?->avg_formatif;
            $avgS = $agg?->avg_sumatif;
            $calculated = FinalGradeCalculator::fromAverages($avgD, $avgF, $avgS, $bobotD, $bobotF, $bobotS);

            $rows[] = [
                $nomor++,
                (string) $student->nama,
                (string) ($student->nisn ?? ''),
                (int) ($att->sakit ?? 0),
                (int) ($att->izin ?? 0),
                (int) ($att->alpa ?? 0),
                $avgD === null ? null : round((float) $avgD),
                $avgF === null ? null : round((float) $avgF),
                $avgS === null ? null : round((float) $avgS),
                $final->nilai_akhir ?? $calculated,
                (string) ($final->deskripsi ?? '-'),
            ];
        }

        $headers = [
            'No.', 'Nama Siswa', 'NISN',
            'Sakit', 'Izin', 'Alpa',
            'Diagnostik', 'Formatif', 'Sumatif',
            'Nilai Akhir', 'Deskripsi Capaian',
        ];

        $fileName = 'Nilai_Akhir_'
            . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $assignment->mapel)
            . '_'
            . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $assignment->kelas)
            . '.xlsx';

        return Excel::download(new class ($rows, $headers) implements FromArray, WithHeadings, WithStyles, WithColumnWidths {
            public function __construct(
                private array $rows,
                private array $headers
            ) {
            }

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headers;
            }

            public function columnWidths(): array
            {
                return [
                    'A' => 6, 'B' => 30, 'C' => 20,
                    'D' => 8, 'E' => 8, 'F' => 8,
                    'G' => 12, 'H' => 10, 'I' => 10,
                    'J' => 12, 'K' => 50,
                ];
            }

            public function styles(Worksheet $sheet)
            {
                $headerRange = 'A1:' . $sheet->getHighestColumn() . '1';

                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9EAF7');
                $sheet->getStyle($headerRange)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $sheet->getStyle('A1:' . $sheet->getHighestColumn() . $sheet->getHighestRow())
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                $sheet->getStyle('C2:C' . $sheet->getHighestRow())
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

                $sheet->freezePane('A2');
            }
        }, $fileName);
    }

    /*
    |--------------------------------------------------------------------------
    | EXPORT DETAIL PER PERTEMUAN ([N+1-FIX]: 2 query + keyBy)
    |--------------------------------------------------------------------------
    */

    public function nilaiExport($assignmentId)
    {
        $assignment = $this->getSecureAssignment($assignmentId);

        $students = $this->studentsForHomeroom((int) $assignment->homeroom_id);

        $allScores = DB::table('assessment_scores')
            ->where('teaching_assignment_id', $assignment->id)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get()
            ->groupBy('student_id');

        $attendanceByKey = DB::table('attendances')
            ->where('teaching_assignment_id', $assignment->id)
            ->get()
            ->keyBy(fn ($row) => $row->student_id . '|' . $row->tanggal);

        $materiColumn = $this->resolveScoreColumn('materi', 'materi');
        $jamKeColumn = $this->resolveScoreColumn('jam_ke', 'jam_ke');
        $pukulColumn = $this->resolveScoreColumn('pukul', 'pukul');

        $rows = [];
        $nomor = 1;

        foreach ($students as $student) {
            $scores = $allScores->get($student->id);

            if (!$scores || $scores->isEmpty()) {
                continue;
            }

            foreach ($scores as $score) {
                $attendance = $attendanceByKey->get($student->id . '|' . $score->tanggal);

                $jamKe = $jamKeColumn !== null ? (string) ($score->{$jamKeColumn} ?? '') : '';
                $pukul = $pukulColumn !== null ? (string) ($score->{$pukulColumn} ?? '') : '';

                $jamPukul = trim(
                    ($jamKe !== '' ? 'Jam ' . $jamKe : '')
                    . ($jamKe !== '' && $pukul !== '' ? ' - ' : '')
                    . $pukul
                );

                $row = [
                    $nomor++,
                    (string) $score->tanggal,
                    $jamPukul !== '' ? $jamPukul : '-',
                    ($materiColumn !== null) ? (string) ($score->{$materiColumn} ?? '-') : '-',
                    (string) ($student->nisn ?? ''),
                    (string) $student->nama,
                    $attendance ? $attendance->status : 'Hadir',
                    $score->diagnostik ?? '-',
                    $score->formatif ?? '-',
                    $score->sumatif ?? '-',
                ];

                foreach (range(1, 8) as $i) {
                    $row[] = $score->{'d' . $i} ?? self::SIKAP_DEFAULT;
                }

                $row[] = $score->catatan_siswa ?? '-';

                $rows[] = $row;
            }
        }

        $headers = array_merge(
            ['No.', 'Tanggal', 'Jam/Pukul', 'Materi', 'NISN', 'Nama Siswa', 'Absensi', 'Diagnostik', 'Formatif', 'Sumatif'],
            array_map(fn ($i) => 'Dimensi ' . $i, range(1, 8)),
            ['Catatan Siswa']
        );

        $fileName = 'Nilai_Pertemuan_'
            . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $assignment->mapel)
            . '_'
            . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $assignment->kelas)
            . '.xlsx';

        return Excel::download(new class ($rows, $headers) implements FromArray, WithHeadings, WithStyles, WithColumnWidths {
            public function __construct(
                private array $rows,
                private array $headers
            ) {
            }

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headers;
            }

            public function columnWidths(): array
            {
                return [
                    'A' => 6, 'B' => 12, 'C' => 14, 'D' => 30,
                    'E' => 18, 'F' => 28, 'G' => 10,
                    'H' => 11, 'I' => 10, 'J' => 10,
                    'K' => 8, 'L' => 8, 'M' => 8, 'N' => 8,
                    'O' => 8, 'P' => 8, 'Q' => 8, 'R' => 8,
                    'S' => 36,
                ];
            }

            public function styles(Worksheet $sheet)
            {
                $headerRange = 'A1:' . $sheet->getHighestColumn() . '1';

                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9EAF7');
                $sheet->getStyle($headerRange)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                $sheet->getStyle('A1:' . $sheet->getHighestColumn() . $sheet->getHighestRow())
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                $sheet->getStyle('E2:E' . $sheet->getHighestRow())
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

                $sheet->freezePane('A2');
            }
        }, $fileName);
    }
}