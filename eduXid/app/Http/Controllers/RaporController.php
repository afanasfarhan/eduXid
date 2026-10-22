<?php

namespace App\Http\Controllers;

use App\Services\Sync\FinalGradeCalculator;

use App\Services\Sync\MasterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class RaporController extends Controller
{
    /**
     * [SIKAP-DEFAULT] Konsisten dengan HomeroomController &
     * NilaiController: dimensi profil lulusan yang belum dinilai
     * dianggap bernilai 3 (Mulai Berkembang). Skala 1-5:
     *
     *   1 = Perlu Intervensi
     *   2 = Sedang Berkembang
     *   3 = Mulai Berkembang
     *   4 = Berkembang Sesuai Harapan
     *   5 = Sangat Berkembang
     */
    protected const SIKAP_DEFAULT = 3;

    /*
     * ============================================================
     * [HISTORICAL-ACCESS] SEMESTER
     *
     * Guru (wali/pengampu) & Admin/Kepsek DIBOLEHKAN melihat,
     * mencetak, dan mengedit nilai semester lampau.
     * Hanya semester MASA DEPAN yang ditolak (belum ada data).
     *
     * Semester "dilihat" ditentukan:
     *   1. Query param ?semester= (format 5 digit, <= aktif)
     *   2. Semester rombel yang diakses (untuk legger/massal)
     *   3. Fallback: semester aktif (perilaku lama)
     *
     * Semua agregat (mapel, sikap, ekskul, kokurikuler) terikat
     * pada semester yang DILIHAT — tidak pernah mencampur semester.
     * ============================================================
     */

    /**
     * [FIX-SEMESTER] Fallback semester aktif kini DARI DATA
     * (rombel reguler tertinggi), bukan konstanta '20261' —
     * dulu sumber rapor bisa salah semester bila session kosong.
     */
    private function getActiveSemester(): string
    {
        $aktif = session('semester_aktif');

        if ($aktif !== null && trim((string) $aktif) !== '') {
            return (string) $aktif;
        }

        $max = DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->max('semester_id');

        return $max !== null ? (string) $max : '20261';
    }

    /**
     * Semester yang diminta via ?semester= — valid format 5 digit
     * dan tidak melewati semester aktif. Selain itu: semester aktif.
     */
    private function getViewingSemester(Request $request): string
    {
        $requested = trim((string) $request->query('semester'));
        $aktif = $this->getActiveSemester();

        if (
            preg_match('/^\d{5}$/', $requested) === 1
            && $requested <= $aktif
        ) {
            return $requested;
        }

        return $aktif;
    }

    private function predikatText(): array
    {
        return [
            1 => 'Perlu Intervensi',
            2 => 'Sedang Berkembang',
            3 => 'Mulai Berkembang',
            4 => 'Berkembang Sesuai Harapan',
            5 => 'Sangat Berkembang',
        ];
    }

    private function predikatByNilai($nilai): string
    {
        $n = (float) $nilai;

        if ($n >= 90) return 'Sangat Baik';
        if ($n >= 80) return 'Baik';
        if ($n >= 70) return 'Cukup';
        if ($n >= 60) return 'Perlu Bimbingan';

        return 'Perlu Bimbingan Khusus';
    }

    private function normalizeRating($value, int $default = self::SIKAP_DEFAULT): int
    {
        if (!is_numeric($value)) {
            $value = $default;
        }
        return max(1, min(5, (int) round($value)));
    }

    private function calculateEightDimensionAverage($data, int $default = self::SIKAP_DEFAULT): int
    {
        $total = 0;
        $count = 0;
        for ($i = 1; $i <= 8; $i++) {
            $value = $this->normalizeRating($data->{'d' . $i} ?? $default, $default);
            $total += $value;
            $count++;
        }
        if ($count === 0) {
            return $default;
        }
        return $this->normalizeRating(round($total / $count), $default);
    }

    private function calculateAverageFromAvgDimensions($data, int $default = self::SIKAP_DEFAULT): int
    {
        if (!$data) {
            return $default;
        }
        $total = 0;
        $count = 0;
        for ($i = 1; $i <= 8; $i++) {
            $value = $this->normalizeRating($data->{'avg_d' . $i} ?? $default, $default);
            $total += $value;
            $count++;
        }
        if ($count === 0) {
            return $default;
        }
        return $this->normalizeRating(round($total / $count), $default);
    }

    private function formatNama($nama): string
    {
        if (!$nama) {
            return '-';
        }
        return mb_convert_case(strtolower(trim($nama)), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * [HISTORICAL-ACCESS] Ambil rombel secara aman.
     *
     * - Admin/Kepsek: semua rombel, semester mana pun.
     * - Wali kelas: rombel miliknya, semester mana pun.
     * - Hanya semester MASA DEPAN yang ditolak; semester lampau
     *   diperbolehkan (cetak rapor/legger historis).
     */
    private function getSecureRombel(Request $request, $rombel_id)
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Anda harus login.');
        }

        if ($user->isAdmin() || $user->role === 'kepala_sekolah') {
            $rombel = DB::table('homerooms')->where('id', $rombel_id)->first();
        } else {
            $guru = $user->teacher();

            if (!$guru) {
                abort(403, 'Data guru tidak ditemukan.');
            }

            /*
             * [HISTORICAL-ACCESS] Wali kelas semester mana pun.
             * Guru pengampu (non-wali) semester lampau diperiksa
             * lewat validateRombelAssignmentAccess().
             */
            $rombel = DB::table('homerooms')
                ->where('id', $rombel_id)
                ->where('homeroom_teacher_id', $guru->id)
                ->first();
        }

        if (!$rombel) {
            abort(403, 'Anda tidak memiliki akses ke kelas ini.');
        }

        $aktif = $this->getActiveSemester();

        /*
         * [HISTORICAL-ACCESS] Semester lampau DIBOLEHKAN;
         * hanya masa depan ditolak.
         */
        if (
            isset($rombel->semester_id)
            && $rombel->semester_id !== null
            && (string) $rombel->semester_id > $aktif
        ) {
            abort(404, 'Kelas belum tersedia (semester belum dimulai).');
        }

        return $rombel;
    }

    /**
     * [D1] Cetak massal/legger rombel: hanya ADMIN dan WALI kelas
     * rombel tersebut. Pengampu non-wali ditolak (kebijakan Q1).
     */
    private function validateRombelAssignmentAccess($rombel): void
    {
        $user = Auth::user();

        if ($user->isAdmin() || $user->role === 'kepala_sekolah') {
            return;
        }

        $guru = $user->teacher();

        if (!$guru) {
            abort(403, 'Data guru tidak ditemukan.');
        }

        if ((int) ($rombel->homeroom_teacher_id ?? 0) === (int) $guru->id) {
            return;
        }

        abort(403, 'Cetak rapor massal/legger hanya untuk wali kelas dan admin.');
    }

    /**
     * [SEMESTER-SCOPE] ID rombel siswa (reguler + mapel pilihan,
     * tanpa ekstrakurikuler) pada SEMESTER TERTENTU.
     *
     * $semesterId = semester yang dilihat; bila null, fallback
     * semester aktif. Bila rombel tidak menyimpan semester_id
     * (data lama), filter dilewati.
     */
    private function getSiswaHomeroomIds(
        $studentId,
        ?string $semesterId = null,
        bool $includePilihan = true
    ): array {
        $query = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $studentId)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%');

        if (!$includePilihan) {
            $query->where('homerooms.jenis_rombel', 'not like', '%Pilihan%');
        }

        $semester = $semesterId ?? $this->getActiveSemester();

        if ($semester) {
            $query->where('homerooms.semester_id', $semester);
        }

        return $query
            ->pluck('enrollments.homeroom_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * [SEMESTER-SCOPE] Versi batch untuk banyak siswa (legger).
     */
    private function getSiswaHomeroomIdsBatch($studentIds, ?string $semesterId = null): array
    {
        $query = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->whereIn('enrollments.student_id', $studentIds)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%');

        $semester = $semesterId ?? $this->getActiveSemester();

        if ($semester) {
            $query->where('homerooms.semester_id', $semester);
        }

        return $query
            ->pluck('enrollments.homeroom_id')
            ->unique()
            ->values()
            ->all();
    }

    /*
     * ============================================================
     * INDEX — daftar rombel untuk cetak rapor
     *
     * [HISTORICAL-ACCESS] Menampilkan SEMUA semester (aktif + lampau),
     * semester terbaru lebih dulu, sehingga guru/admin dapat
     * memilih rombel semester lampau.
     * ============================================================
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Anda harus login.');
        }

        $aktif = $this->getActiveSemester();

        $query = DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->where('semester_id', '<=', $aktif)
            ->orderByDesc('semester_id');

        if ($user->isAdmin() || $user->role === 'kepala_sekolah') {
            $guru = null;
        } else {
            $guru = $user->teacher();

            if ($guru) {
                $query->where('homeroom_teacher_id', $guru->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $rombels = $query->orderBy('nama')->get();

        /*
         * [HISTORICAL-ACCESS] Daftar semester untuk badge/pemilih
         * pada view — hanya semester yang benar-benar memiliki rombel.
         */
        $semesterList = DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->where('semester_id', '<=', $aktif)
            ->select('semester_id')
            ->distinct()
            ->orderByDesc('semester_id')
            ->pluck('semester_id')
            ->map(fn ($s) => (string) $s)
            ->values();

        return view('rapor.index', compact('rombels', 'semesterList'));
    }

    public function getSiswaByKelas(Request $request, $rombel_id)
    {
        $this->getSecureRombel($request, $rombel_id);

        $students = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.homeroom_id', $rombel_id)
            ->select('students.id', 'students.nama', 'students.nisn')
            ->orderBy('students.nama')
            ->get()
            ->map(function ($student) {
                $student->nama = $this->formatNama($student->nama);
                return $student;
            });

        return response()->json($students);
    }

    /**
     * [D1] Cetak rapor: hanya ADMIN dan WALI KELAS.
     * Akses pengampu dihapus (kebijakan terkunci Q1).
     */
    private function validateStudentAccess($studentId): void
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Anda harus login.');
        }

        if ($user->isAdmin() || $user->role === 'kepala_sekolah') {
            return;
        }

        $guru = $user->teacher();

        if (!$guru) {
            abort(403, 'Data guru tidak ditemukan.');
        }

        $isHomeroomTeacher = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $studentId)
            ->where('homerooms.homeroom_teacher_id', $guru->id)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
            ->exists();

        if ($isHomeroomTeacher) {
            return;
        }

        abort(403, 'Cetak rapor hanya untuk wali kelas dan admin.');
    }

    /*
     * ============================================================
     * [RAPOR-FIX] GET SIKAP DATA
     *
     * Prioritas sumber sikap d1..d8:
     *   1. final_grades.d1..d8 (sinkronisasi dari guru pengampu).
     *   2. Fallback: AVG(assessment_scores) — [SCOPE-FIX] dibatasi
     *      ke teaching assignments milik homeroomIds (bukan seluruh
     *      riwayat siswa lintas semester).
     * ============================================================
     */
    private function getSikapData($studentId, array $homeroomIds)
    {
        /*
         * Sumber 1: final_grades.d1..d8 (sinkronisasi dari guru).
         */
        $fgSikap = DB::table('final_grades')
            ->join('teaching_assignments', 'final_grades.teaching_assignment_id', '=', 'teaching_assignments.id')
            ->whereIn('teaching_assignments.homeroom_id', $homeroomIds)
            ->where('final_grades.student_id', $studentId)
            ->selectRaw('
                AVG(final_grades.d1) as avg_d1,
                AVG(final_grades.d2) as avg_d2,
                AVG(final_grades.d3) as avg_d3,
                AVG(final_grades.d4) as avg_d4,
                AVG(final_grades.d5) as avg_d5,
                AVG(final_grades.d6) as avg_d6,
                AVG(final_grades.d7) as avg_d7,
                AVG(final_grades.d8) as avg_d8
            ')
            ->first();

        /*
         * Cek apakah ada minimal satu d1..d8 yang terisi dari sinkronisasi.
         */
        if ($fgSikap && $fgSikap->avg_d1 !== null) {
            return $fgSikap;
        }

        /*
         * Sumber 2 (fallback): AVG assessment_scores lokal, scoped.
         */
        $taIds = DB::table('teaching_assignments')
            ->whereIn('homeroom_id', $homeroomIds)
            ->pluck('id');

        return DB::table('assessment_scores')
            ->whereIn('teaching_assignment_id', $taIds)
            ->where('student_id', $studentId)
            ->selectRaw('
                ROUND(AVG(d1), 1) as avg_d1,
                ROUND(AVG(d2), 1) as avg_d2,
                ROUND(AVG(d3), 1) as avg_d3,
                ROUND(AVG(d4), 1) as avg_d4,
                ROUND(AVG(d5), 1) as avg_d5,
                ROUND(AVG(d6), 1) as avg_d6,
                ROUND(AVG(d7), 1) as avg_d7,
                ROUND(AVG(d8), 1) as avg_d8
            ')
            ->first();
    }

    /*
     * ============================================================
     * [RAPOR-FIX] GET NILAI DATA (dengan fallback)
     *
     * Nilai akhir per mapel:
     *   1. final_grades.nilai_akhir (sinkronisasi dari guru) — jika ada.
     *   2. Fallback: AVG(assessment_scores) nilai harian guru pengampu
     *      dengan bobot d/i/s dari teaching_assignments.
     *
     * [N+1-FIX] Fallback dulu 1 query PER MAPEL — kini SATU query
     * groupBy teaching_assignment_id untuk semua mapel yang perlu.
     * ============================================================
     */
    private function getNilaiData($studentId, array $homeroomIds)
    {
        $nilais = DB::table('teaching_assignments')
            ->join('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
            ->leftJoin('final_grades', function ($join) use ($studentId) {
                $join->on('teaching_assignments.id', '=', 'final_grades.teaching_assignment_id')
                    ->where('final_grades.student_id', '=', $studentId);
            })
            ->whereIn('teaching_assignments.homeroom_id', $homeroomIds)
            ->where(function ($q) {
                $q->where('subjects.nama_mata_pelajaran', 'not like', '%Bimbingan%')
                    ->where('subjects.nama_mata_pelajaran', 'not like', '%Konseling%')
                    ->where('subjects.nama_mata_pelajaran', 'not like', '%Konselor%');
            })
            ->select(
                'subjects.nama_mata_pelajaran',
                'subjects.urutan',
                'final_grades.nilai_akhir',
                'final_grades.deskripsi',
                'teaching_assignments.id as ta_id',
                'teaching_assignments.bobot_diagnostik',
                'teaching_assignments.bobot_formatif',
                'teaching_assignments.bobot_sumatif'
            )
            ->orderBy('subjects.urutan', 'asc')
            ->orderBy('subjects.nama_mata_pelajaran', 'asc')
            ->get();

        /*
         * [N+1-FIX] Satu query agregat untuk SEMUA TA yang
         * nilai akhirnya masih kosong.
         */
        $taIdsNeedingFallback = $nilais
            ->filter(fn ($row) => $row->nilai_akhir === null || $row->nilai_akhir === '')
            ->pluck('ta_id');

        $fallbacks = $taIdsNeedingFallback->isNotEmpty()
            ? DB::table('assessment_scores')
                ->where('student_id', $studentId)
                ->whereIn('teaching_assignment_id', $taIdsNeedingFallback)
                ->groupBy('teaching_assignment_id')
                ->selectRaw('
                    teaching_assignment_id,
                    AVG(diagnostik) as avg_d,
                    AVG(formatif) as avg_f,
                    AVG(sumatif) as avg_s
                ')
                ->get()
                ->keyBy('teaching_assignment_id')
            : collect();

        /*
         * [RAPOR-FIX] Fallback nilai harian + auto-generate deskripsi.
         */
        return $nilais->map(function ($row) use ($fallbacks) {
            $nilai = $row->nilai_akhir;
            $deskripsi = $row->deskripsi;

            if ($nilai === null || $nilai === '') {
                $avg = $fallbacks->get($row->ta_id);

                if ($avg) {
                    $nilai = FinalGradeCalculator::fromAverages(
                        $avg->avg_d, $avg->avg_f, $avg->avg_s,
                        (int) ($row->bobot_diagnostik ?? 0),
                        (int) ($row->bobot_formatif ?? 0),
                        (int) ($row->bobot_sumatif ?? 0)
                    );
                }
            }

            if (empty($deskripsi) && $nilai !== null && is_numeric($nilai)) {
                $predikat = $this->predikatByNilai($nilai);
                $deskripsi = "Menunjukkan penguasaan yang {$predikat} dalam mata pelajaran {$row->nama_mata_pelajaran}.";
            }

            return (object) [
                'nama_mata_pelajaran' => $row->nama_mata_pelajaran,
                'nilai_akhir' => $nilai,
                'deskripsi' => $deskripsi,
            ];
        });
    }

    /*
     * ============================================================
     * GET RAPOR DATA
     *
     * [HISTORICAL-ACCESS] $viewingSemester menentukan semester yang
     * dilihat: rombel utama, mapel, sikap, ekskul, kokurikuler, dan
     * kop T.P./semester semuanya mengikuti semester tersebut —
     * rapor lampau menampilkan data semester lampau.
     * ============================================================
     */
    private function getRaporData($id, $tempat_cetak = null, $tanggal_input = null, ?string $viewingSemester = null)
    {
        $sekolah = DB::table('schools')->first();
        $siswa = DB::table('students')->where('id', $id)->first();

        if (!$siswa) {
            throw new \Exception("Data siswa dengan ID {$id} tidak ditemukan di database lokal.");
        }

        /*
         * [ENROLL-FIX] Rombel utama dipilih deterministik:
         *   1. semester yang dilihat (bila diberikan)
         *   2. jenis_rombel = 'Kelas'
         * Join wali diberi filter deleted_at di closure agar
         * LEFT JOIN tetap utuh (konsisten HomeroomController).
         */
        $enrollmentQuery = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->leftJoin('teachers', function ($join) {
                $join->on('homerooms.homeroom_teacher_id', '=', 'teachers.id')
                    ->whereNull('teachers.deleted_at');
            })
            ->where('enrollments.student_id', $id)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%');

        if ($viewingSemester) {
            $enrollmentQuery->orderByRaw(
                'CASE WHEN homerooms.semester_id = ? THEN 0 ELSE 1 END',
                [$viewingSemester]
            );
        }

        $enrollment = $enrollmentQuery
            ->orderByRaw("CASE WHEN homerooms.jenis_rombel = 'Kelas' THEN 0 ELSE 1 END")
            ->select(
                'enrollments.homeroom_id',
                'homerooms.semester_id',
                'homerooms.nama as kelas',
                'teachers.nama_lengkap as wali_kelas',
                'teachers.nip as wali_nip',
                'enrollments.rapor_sakit',
                'enrollments.rapor_izin',
                'enrollments.rapor_alpa',
                'enrollments.catatan_wali'
            )
            ->first();

        if (!$enrollment) {
            throw new \Exception("Data enrollment siswa dengan ID {$id} tidak ditemukan.");
        }

        /*
         * [HISTORICAL-ACCESS] Semester efektif = semester rombel utama
         * yang terpilih (bisa lampau); bila kosong (data lama),
         * fallback ke semester yang dilihat / semester aktif.
         */
        $effectiveSemester = $enrollment->semester_id
            ? (string) $enrollment->semester_id
            : ($viewingSemester ?? $this->getActiveSemester());

        /*
         * [SEMESTER-SCOPE] Kokurikuler mengikuti semester efektif
         * (guard Schema bila kolom belum ada pada instalasi lama).
         */
        $kokurikulerQuery = DB::table('kokurikuler_grades')
            ->join('kokurikulers', 'kokurikuler_grades.kokurikuler_id', '=', 'kokurikulers.id')
            ->where('kokurikuler_grades.student_id', $id);

        if ($effectiveSemester && Schema::hasColumn('kokurikulers', 'semester_id')) {
            $kokurikulerQuery->where('kokurikulers.semester_id', $effectiveSemester);
        }

        $kokurikulers = $kokurikulerQuery
            ->select(
                'kokurikulers.tema',
                'kokurikulers.nama_kegiatan',
                'kokurikuler_grades.deskripsi',
                'kokurikuler_grades.predikat',
                'kokurikuler_grades.d1',
                'kokurikuler_grades.d2',
                'kokurikuler_grades.d3',
                'kokurikuler_grades.d4',
                'kokurikuler_grades.d5',
                'kokurikuler_grades.d6',
                'kokurikuler_grades.d7',
                'kokurikuler_grades.d8'
            )
            ->get();

        foreach ($kokurikulers as $k) {
            $k->rata_sikap = $this->calculateEightDimensionAverage($k, self::SIKAP_DEFAULT);
        }

        /*
         * [SEMESTER-SCOPE] Ekskul mengikuti semester efektif.
         */
        $ekskulQuery = DB::table('ekskul_grades')
            ->join('homerooms', 'ekskul_grades.homeroom_id', '=', 'homerooms.id')
            ->where('ekskul_grades.student_id', $id);

        if ($effectiveSemester) {
            $ekskulQuery->where('homerooms.semester_id', $effectiveSemester);
        }

        $ekskul_grades = $ekskulQuery
            ->select(
                'homerooms.nama as nama_ekskul',
                'ekskul_grades.deskripsi',
                'ekskul_grades.predikat',
                'ekskul_grades.bintang',
                'ekskul_grades.d1',
                'ekskul_grades.d2',
                'ekskul_grades.d3',
                'ekskul_grades.d4',
                'ekskul_grades.d5',
                'ekskul_grades.d6',
                'ekskul_grades.d7',
                'ekskul_grades.d8'
            )
            ->get();

        foreach ($ekskul_grades as $e) {
            $e->rata_sikap = ($e->bintang !== null && $e->bintang !== '')
                ? $this->normalizeRating($e->bintang, self::SIKAP_DEFAULT)
                : $this->calculateEightDimensionAverage($e, self::SIKAP_DEFAULT);
        }

        /*
         * [HISTORICAL-ACCESS] Mapel (reguler + pilihan) mengikuti
         * SEMESTER EFEKTIF — rapor lampau menampilkan mapel
         * semester lampau.
         */
        $homeroomIds = $this->getSiswaHomeroomIds($id, $effectiveSemester, true);

        /*
         * [RAPOR-FIX] Nilai akhir per mapel: prioritas final_grades
         * (sinkronisasi dari guru) → fallback ke AVG nilai harian.
         */
        $nilais = $this->getNilaiData($id, $homeroomIds);

        $nilaiNumerik = $nilais->pluck('nilai_akhir')->filter(function ($v) {
            return $v !== null && $v !== '' && is_numeric($v);
        })->map(function ($v) {
            return (float) $v;
        })->values();

        $rataRataNilai = $nilaiNumerik->count() > 0 ? round($nilaiNumerik->avg(), 2) : null;

        /*
         * [RAPOR-FIX] Sikap: prioritas final_grades.d1..d8 dari
         * sinkronisasi guru pengampu → fallback assessment_scores.
         */
        $sikap = $this->getSikapData($id, $homeroomIds);
        $rataSikapVal = $this->calculateAverageFromAvgDimensions($sikap, self::SIKAP_DEFAULT);

        /*
         * [HISTORICAL-ACCESS] Tahun ajaran/semester pada kop mengikuti
         * SEMESTER EFEKTIF (bukan selalu aktif) — rapor lampau
         * mencantumkan T.P. yang benar.
         */
        $tahunAjaran = '-';
        $semester = '-';

        if ($effectiveSemester && strlen($effectiveSemester) === 5) {
            $tahun1 = substr($effectiveSemester, 0, 4);
            $tahunAjaran = $tahun1 . '/' . (((int) $tahun1) + 1);
            $sem = substr($effectiveSemester, 4, 1);
            $semester = $sem === '1' ? 'Ganjil' : ($sem === '2' ? 'Genap' : '-');
        }

        /*
         * Identitas Kepala Sekolah berasal dari master GTK/Dapodik.
         * Tidak menggunakan akun users sebagai sumber identitas rapor.
         */
        $kepsekQuery = DB::table('teachers')
            ->where(function ($query) {
                $query->where('jabatan_ptk', 'like', '%Kepala Sekolah%')
                    ->orWhere('jenis_ptk', 'like', '%Kepala Sekolah%');
            });

        if (Schema::hasColumn('teachers', 'deleted_at')) {
            $kepsekQuery->whereNull('deleted_at');
        }

        $kepsek = $kepsekQuery
            ->select('nama_lengkap', 'nip')
            ->first();

        $kepala_sekolah = $kepsek?->nama_lengkap ?: '-';
        $kepala_nip = $kepsek?->nip ?: '-';

        $tempat_cetak = $tempat_cetak ?? ($sekolah->kabupaten_kota ?? '');

        $bulanIndo = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $tglObj = $tanggal_input ? date_create($tanggal_input) : date_create();

        if (!$tglObj) {
            $tglObj = date_create();
        }

        $tanggal_cetak = date_format($tglObj, 'd') . ' ' . $bulanIndo[(int) date_format($tglObj, 'm')] . ' ' . date_format($tglObj, 'Y');
        $siswa->nama = $this->formatNama($siswa->nama);

        return [
            'siswa' => $siswa,
            'sekolah' => $sekolah ?? new \stdClass(),
            'enrollment' => $enrollment ?? new \stdClass(),
            'nilais' => $nilais,
            'rataRataNilai' => $rataRataNilai,
            'jumlahMapelDinilai' => $nilaiNumerik->count(),
            'jumlahMapelTotal' => $nilais->count(),
            'sikap' => $sikap ?? new \stdClass(),
            'rataSikapVal' => $rataSikapVal,
            'predikatText' => $this->predikatText(),
            'tahunAjaran' => $tahunAjaran,
            'semester' => $semester,
            'kepala_sekolah' => $kepala_sekolah,
            'kepala_nip' => $kepala_nip,
            'tempat_cetak' => $tempat_cetak,
            'tanggal_cetak' => $tanggal_cetak,
            'kokurikulers' => $kokurikulers,
            'ekskul_grades' => $ekskul_grades,
        ];
    }

    /*
     * ============================================================
     * PELENGKAP RAPOR
     * ============================================================
     * Template 5 halaman:
     *   1. Sampul rapor
     *   2. Identitas sekolah
     *   3. Identitas peserta didik
     *   4. Keterangan pindah sekolah - KELUAR
     *   5. Keterangan pindah sekolah - MASUK
     *
     * Data yang tersedia di master eduXid diisi otomatis. Kolom yang
     * memang belum tersedia di master tetap berupa garis kosong agar
     * dapat diisi manual pada dokumen cetak.
     */
    private function formatTanggalPelengkap($value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            $date = new \DateTime((string) $value);
        } catch (\Throwable) {
            return (string) $value;
        }

        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return $date->format('d')
            . ' ' . $bulan[(int) $date->format('n')]
            . ' ' . $date->format('Y');
    }

    /**
     * Mengubah foto siswa menjadi data URI agar DomPDF dapat merender
     * foto secara konsisten tanpa bergantung pada HTTP/server lokal.
     */
    private function getStudentPhotoDataUri(object $siswa): ?string
    {
        /*
         * Foto Pelengkap Rapor hanya memakai students.foto_path,
         * yaitu hasil upload foto lokal yang dipetakan berdasarkan NISN.
         */
        $raw = trim((string) ($siswa->foto_path ?? ''));

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'data:image/')) {
            return $raw;
        }

        $normalized = ltrim(str_replace('\\', '/', $raw), '/');

        foreach (['storage/', 'media/'] as $prefix) {
            if (str_starts_with(strtolower($normalized), $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }

        $candidates = [];

        try {
            $diskPath = Storage::disk('public')->path($normalized);
            if ($diskPath) {
                $candidates[] = $diskPath;
            }
        } catch (\Throwable) {
            // Fallback ke kandidat filesystem di bawah.
        }

        $candidates[] = storage_path('app/public/' . $normalized);
        $candidates[] = public_path('storage/' . $normalized);
        $candidates[] = public_path($normalized);

        foreach (array_unique($candidates) as $path) {
            if (!is_string($path) || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                continue;
            }

            $mime = null;

            if (function_exists('mime_content_type')) {
                $detected = @mime_content_type($path);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    $mime = $detected;
                }
            }

            if (!$mime) {
                $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                    default => null,
                };
            }

            if (!$mime) {
                continue;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        return null;
    }

    private function getPelengkapRaporData(
        int $studentId,
        ?int $rombelId = null,
        ?string $tempatCetak = null,
        ?string $tanggalCetak = null
    ): array {
        $this->validateStudentAccess($studentId);

        $siswa = DB::table('students')->where('id', $studentId)->first();

        if (!$siswa) {
            throw new \RuntimeException('Data siswa tidak ditemukan.');
        }

        $sekolah = DB::table('schools')->first() ?? new \stdClass();

        /*
         * Kelas yang sedang dipilih pada halaman Rapor dipakai untuk
         * konteks cetak. Jika tidak tersedia, fallback ke kelas reguler
         * terbaru siswa.
         */
        $kelasQuery = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $studentId)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%');

        if ($rombelId) {
            $kelasQuery->where('homerooms.id', $rombelId);
        }

        $kelasAktif = $kelasQuery
            ->orderByDesc('homerooms.semester_id')
            ->select('homerooms.id', 'homerooms.nama', 'homerooms.semester_id')
            ->first();

        if (!$kelasAktif) {
            $kelasAktif = DB::table('enrollments')
                ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
                ->where('enrollments.student_id', $studentId)
                ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
                ->orderByDesc('homerooms.semester_id')
                ->select('homerooms.id', 'homerooms.nama', 'homerooms.semester_id')
                ->first();
        }

        /*
         * Kelas pertama siswa digunakan sebagai "Di kelas" ketika
         * diterima di sekolah ini. Ini lebih tepat daripada kelas
         * aktif untuk siswa kelas XI/XII.
         */
        $kelasMasuk = DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->where('enrollments.student_id', $studentId)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
            ->orderBy('homerooms.semester_id')
            ->orderBy('homerooms.id')
            ->value('homerooms.nama');

        /*
         * Kepala Sekolah khusus Pelengkap Rapor hanya bersumber dari
         * master GTK/Dapodik. Tidak ada fallback ke tabel users.
         */
        $kepsekQuery = DB::table('teachers')
            ->where(function ($query) {
                $query->where('jabatan_ptk', 'like', '%Kepala Sekolah%')
                    ->orWhere('jenis_ptk', 'like', '%Kepala Sekolah%');
            });

        if (Schema::hasColumn('teachers', 'deleted_at')) {
            $kepsekQuery->whereNull('deleted_at');
        }

        $kepsek = $kepsekQuery
            ->select('nama_lengkap', 'nip')
            ->first()
            ?? (object) [
                'nama_lengkap' => '',
                'nip' => '',
            ];

        $tempatCetak = trim((string) ($tempatCetak ?: ($sekolah->kabupaten_kota ?? '')));
        $tanggalCetak = $this->formatTanggalPelengkap($tanggalCetak ?: date('Y-m-d'));
        $tanggalMasuk = $this->formatTanggalPelengkap($siswa->tanggal_masuk_sekolah ?? null);
        $tanggalLahir = $this->formatTanggalPelengkap($siswa->tanggal_lahir ?? null);

        $jenisKelamin = match (strtoupper(trim((string) ($siswa->jenis_kelamin ?? '')))) {
            'L', 'LAKI-LAKI', 'LAKI LAKI' => 'Laki-Laki',
            'P', 'PEREMPUAN' => 'Perempuan',
            default => (string) ($siswa->jenis_kelamin ?? ''),
        };

        /*
         * Bentuk pendidikan harus apa adanya dari tabel schools
         * (hasil Dapodik). Jika kosong, biarkan kosong.
         */
        $bentuk = strtoupper(trim((string) ($sekolah->bentuk_pendidikan ?? '')));

        return [
            'siswa' => $siswa,
            'sekolah' => $sekolah,
            'kelasAktif' => $kelasAktif,
            'kelasMasuk' => $kelasMasuk ?: '',
            'kepalaSekolah' => $kepsek,
            'tempatCetak' => $tempatCetak,
            'tanggalCetak' => $tanggalCetak,
            'tanggalMasuk' => $tanggalMasuk,
            'tanggalLahir' => $tanggalLahir,
            'jenisKelamin' => $jenisKelamin,
            'bentukPendidikan' => $bentuk,
            'fotoDataUri' => $this->getStudentPhotoDataUri($siswa),
        ];
    }

    public function layoutMapel()
    {
        $subjects = DB::table('subjects')
            ->orderBy('subjects.urutan', 'asc')
            ->orderBy('subjects.nama_mata_pelajaran', 'asc')
            ->get();

        $total = $subjects->count();

        return view('admin.layout_mapel', compact('subjects', 'total'));
    }

    /**
     * [FIX-DIRTY] Urutan mapel ikut snapshot master — perubahan
     * kini ditandai push_dirty sehingga daemon mem-push snapshot
     * baru ke Cloud otomatis (dulu Cloud basi sampai Dapodik sync
     * berikutnya).
     */
    public function storeLayoutMapel(Request $request)
    {
        $request->validate(['urutan' => 'required|array']);

        DB::transaction(function () use ($request) {
            DB::table('subjects')->update(['urutan' => 0]);

            foreach ($request->urutan as $position => $subjectId) {
                if ($subjectId) {
                    DB::table('subjects')->where('id', $subjectId)->update(['urutan' => $position]);
                }
            }
        });

        try {
            MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
        } catch (\Throwable) {
            // Penanda gagal tidak menggagalkan penyimpanan.
        }

        return redirect()->back()->with('success', 'Layout urutan mata pelajaran berhasil disimpan! Master akan ter-push ke Cloud otomatis.');
    }

    /*
     * ============================================================
     * CETAK RAPOR (per siswa)
     *
     * [HISTORICAL-ACCESS] ?semester= dapat dipakai untuk mencetak
     * rapor semester lampau; tanpa param → semester aktif.
     * ============================================================
     */
    public function cetak(Request $request, $id)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        try {
            $this->validateStudentAccess($id);

            /*
             * Mode Pelengkap Rapor memakai endpoint cetak yang sama agar
             * tidak perlu menambah route baru dan tetap kompatibel dengan
             * instalasi eduXid yang sudah berjalan.
             */
            if ($request->boolean('pelengkap')) {
                $pelengkapData = $this->getPelengkapRaporData(
                    (int) $id,
                    $request->filled('kelas_id') ? (int) $request->input('kelas_id') : null,
                    $request->input('tempat'),
                    $request->input('tanggal')
                );

                $pdf = Pdf::loadView('rapor.pelengkap_template', $pelengkapData);
                $pdf->setPaper('A4', 'portrait');

                return $pdf->download(
                    'Pelengkap-Rapor-'
                    . str_replace(' ', '_', (string) $pelengkapData['siswa']->nama)
                    . '.pdf'
                );
            }

            $viewingSemester = $this->getViewingSemester($request);

            $tempat = $request->input('tempat', DB::table('schools')->value('kabupaten_kota') ?? '');
            $tanggal = $request->input('tanggal', date('Y-m-d'));

            $data = $this->getRaporData($id, $tempat, $tanggal, $viewingSemester);

            $pdf = Pdf::loadView('rapor.pdf_template', $data);
            $pdf->setPaper('A4', 'portrait');

            return $pdf->download('Rapor-' . str_replace(' ', '_', $data['siswa']->nama) . '.pdf');
        } catch (\Throwable $e) {
            report($e);
            return response('Gagal mencetak rapor. Silakan coba lagi atau hubungi pengelola sistem.', 500);
        }
    }

    /*
     * ============================================================
     * [RAPOR-FIX] CHECK STATUS
     *
     * Status per mapel:
     *   - final_grades terisi (✓)
     *   - kosong tapi nilai harian tersedia → fallback (⚠️)
     *   - tidak ada nilai sama sekali (✕)
     *
     * [N+1-FIX] 2 query groupBy untuk seluruh assignment.
     * ============================================================
     */
    public function checkStatus(Request $request, $rombel_id)
    {
        $rombel = $this->getSecureRombel($request, $rombel_id);

        $assignments = DB::table('teaching_assignments')
            ->join('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
            ->join('teachers', 'teaching_assignments.teacher_id', '=', 'teachers.id')
            ->where('teaching_assignments.homeroom_id', $rombel_id)
            ->where('subjects.nama_mata_pelajaran', 'not like', '%Bimbingan%')
            ->where('subjects.nama_mata_pelajaran', 'not like', '%Konseling%')
            ->where('subjects.nama_mata_pelajaran', 'not like', '%Konselor%')
            ->select('teaching_assignments.id as assignment_id', 'subjects.nama_mata_pelajaran', 'teachers.nama_lengkap as guru')
            ->get();

        $assignmentIds = $assignments->pluck('assignment_id');

        $studentIds = DB::table('enrollments')->where('homeroom_id', $rombel_id)
            ->pluck('student_id')->unique();
        $expected = $studentIds->count();
        $finalCounts = DB::table('final_grades')->whereIn('teaching_assignment_id', $assignmentIds)
            ->whereIn('student_id', $studentIds)->whereNotNull('nilai_akhir')
            ->whereNotNull('deskripsi')->whereRaw("TRIM(deskripsi) <> ''")
            ->selectRaw('teaching_assignment_id, COUNT(DISTINCT student_id) as total')
            ->groupBy('teaching_assignment_id')->pluck('total', 'teaching_assignment_id');
        $dailyCounts = DB::table('assessment_scores')->whereIn('teaching_assignment_id', $assignmentIds)
            ->whereIn('student_id', $studentIds)
            ->where(fn ($q) => $q->whereNotNull('diagnostik')->orWhereNotNull('formatif')->orWhereNotNull('sumatif'))
            ->selectRaw('teaching_assignment_id, COUNT(DISTINCT student_id) as total')
            ->groupBy('teaching_assignment_id')->pluck('total', 'teaching_assignment_id');
        $missing = [];
        foreach ($assignments as $assignment) {
            $complete = (int) ($finalCounts[$assignment->assignment_id] ?? 0);
            if ($expected === 0 || $complete < $expected) {
                $hasDaily = (int) ($dailyCounts[$assignment->assignment_id] ?? 0) > 0;
                $missing[] = [
                    'mapel' => $assignment->nama_mata_pelajaran, 'guru' => $assignment->guru ?? '-',
                    'status' => $hasDaily ? 'fallback' : 'missing',
                    'message' => 'Nilai akhir dan deskripsi lengkap untuk ' . $complete . '/' . $expected
                        . ' siswa. Lengkapi dan kirim nilai akhir sebelum cetak rapor.',
                ];
            }
        }

        return response()->json([
            'is_complete' => count($missing) === 0,
            'missing' => $missing,
        ]);
    }

    /*
     * ============================================================
     * CETAK RAPOR MASSAL
     *
     * [HISTORICAL-ACCESS] Semester yang dicetak = semester rombel
     * yang dipilih (bisa lampau). Guru non-wali (pengampu) juga
     * dibolehkan.
     * ============================================================
     */
    public function cetakRaporMassal(Request $request, $rombel_id)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        try {
            $rombel = $this->getSecureRombel($request, $rombel_id);

            /*
             * [HISTORICAL-ACCESS] Guru non-wali (pengampu) boleh
             * mencetak massal rombel yang diajarnya.
             */
            $this->validateRombelAssignmentAccess($rombel);

            /*
             * [HISTORICAL-ACCESS] Semester = semester rombel (bisa lampau);
             * bila rombel tanpa semester_id, fallback semester aktif.
             */
            $viewingSemester = $rombel->semester_id
                ? (string) $rombel->semester_id
                : $this->getViewingSemester($request);

            $tempat = $request->input('tempat', DB::table('schools')->value('kabupaten_kota') ?? '');
            $tanggal = $request->input('tanggal', date('Y-m-d'));

            $students = DB::table('enrollments')
                ->join('students', 'enrollments.student_id', '=', 'students.id')
                ->where('enrollments.homeroom_id', $rombel_id)
                ->orderBy('students.nama')
                ->select('students.id', 'students.nama', 'students.nisn')
                ->get();

            if ($students->isEmpty()) {
                return back()->with('error', 'Tidak ada siswa pada rombel ini.');
            }

            /*
             * ========================================================
             * PELENGKAP RAPOR MASSAL
             * ========================================================
             * Menggunakan endpoint cetak massal yang sudah ada dengan
             * query ?pelengkap=1. Tidak bergantung pada kelengkapan nilai.
             * Setiap siswa menghasilkan satu PDF Pelengkap Rapor dan
             * seluruh PDF dikemas menjadi satu ZIP per rombel.
             */
            if ($request->boolean('pelengkap')) {
                $zip = new \ZipArchive();
                $fileName = 'Pelengkap_Rapor_Massal_' . str_replace(' ', '_', $rombel->nama) . '.zip';
                $tempZipPath = storage_path('app/' . $fileName);

                if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    return back()->with('error', 'Gagal membuat file ZIP Pelengkap Rapor massal.');
                }

                foreach ($students as $student) {
                    $data = $this->getPelengkapRaporData(
                        (int) $student->id,
                        (int) $rombel_id,
                        $tempat,
                        $tanggal
                    );

                    $pdf = Pdf::loadView('rapor.pelengkap_template', $data);
                    $pdf->setPaper('A4', 'portrait');

                    $nisn = trim((string) ($student->nisn ?? ''));
                    $prefix = $nisn !== '' ? $nisn . '-' : '';
                    $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) $student->nama);

                    $zip->addFromString(
                        'Pelengkap-Rapor-' . $prefix . $safeName . '.pdf',
                        $pdf->output()
                    );

                    unset($pdf);
                    unset($data);
                }

                $zip->close();

                return response()->download($tempZipPath, $fileName)->deleteFileAfterSend(true);
            }

            $zip = new \ZipArchive();
            $fileName = 'Rapor_Massal_' . str_replace(' ', '_', $rombel->nama) . '.zip';
            $tempZipPath = storage_path('app/' . $fileName);

            if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return back()->with('error', 'Gagal membuat file ZIP untuk rapor massal.');
            }

            foreach ($students as $student) {
                $data = $this->getRaporData($student->id, $tempat, $tanggal, $viewingSemester);

                $pdf = Pdf::loadView('rapor.pdf_template', $data);
                $pdf->setPaper('A4', 'portrait');

                $zip->addFromString('Rapor-' . str_replace(' ', '_', $student->nama) . '.pdf', $pdf->output());

                unset($pdf);
                unset($data);
            }

            $zip->close();

            return response()->download($tempZipPath, $fileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'Gagal mencetak rapor massal. Silakan coba lagi atau hubungi pengelola sistem.');
        }
    }

    /*
     * ============================================================
     * CETAK LEGGER
     *
     * [HISTORICAL-ACCESS] Semester = semester rombel yang dipilih
     * (bisa lampau). Guru non-wali (pengampu) dibolehkan.
     *
     * [RAPOR-FIX] Sikap per siswa: prioritas final_grades.d1..d8
     * (guru pengampu via sinkronisasi), fallback assessment_scores,
     * default SIKAP_DEFAULT bila kosong total.
     *
     * [LEDGER-FIX] Nilai per mapel: fallback nilai harian (bobot TA)
     * — selaras dengan rapor PDF yang sudah fallback; dulu '-'
     * padahal PDF tercetak berisi angka.
     * ============================================================
     */
    public function cetakLegger(Request $request, $rombel_id)
    {
        $rombel = $this->getSecureRombel($request, $rombel_id);

        /*
         * [HISTORICAL-ACCESS] Guru non-wali (pengampu) boleh mencetak
         * legger rombel yang diajarnya.
         */
        $this->validateRombelAssignmentAccess($rombel);

        /*
         * [HISTORICAL-ACCESS] Semester = semester rombel (bisa lampau).
         */
        $viewingSemester = $rombel->semester_id
            ? (string) $rombel->semester_id
            : $this->getViewingSemester($request);

        $students = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.homeroom_id', $rombel_id)
            ->select('students.id', 'students.nama', 'students.nisn', 'enrollments.rapor_sakit', 'enrollments.rapor_izin', 'enrollments.rapor_alpa')
            ->orderBy('students.nama')
            ->get();

        $studentIds = $students->pluck('id');

        /*
         * [SEMESTER-SCOPE] homeroomIds siswa pada semester rombel ini
         * (mapel pilihan ikut; ekstrakurikuler tidak).
         */
        $homeroomIds = $this->getSiswaHomeroomIdsBatch($studentIds, $viewingSemester);

        if (!in_array($rombel_id, $homeroomIds)) {
            $homeroomIds[] = $rombel_id;
        }

        $subjects = DB::table('teaching_assignments')
            ->join('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
            ->whereIn('teaching_assignments.homeroom_id', $homeroomIds)
            ->where('subjects.nama_mata_pelajaran', 'not like', '%Konseling%')
            ->where('subjects.nama_mata_pelajaran', 'not like', '%Bimbingan%')
            ->where('subjects.nama_mata_pelajaran', 'not like', '%Konselor%')
            ->select(
                'teaching_assignments.id as assignment_id',
                'subjects.nama_mata_pelajaran',
                'subjects.urutan',
                'teaching_assignments.bobot_diagnostik',
                'teaching_assignments.bobot_formatif',
                'teaching_assignments.bobot_sumatif'
            )
            ->orderBy('subjects.urutan', 'asc')
            ->orderBy('subjects.nama_mata_pelajaran', 'asc')
            ->get()
            ->unique('assignment_id');

        $assignmentIds = $subjects->pluck('assignment_id');

        $grades = DB::table('final_grades')
            ->whereIn('student_id', $studentIds)
            ->whereIn('teaching_assignment_id', $assignmentIds)
            ->get()
            ->groupBy('student_id');

        /*
         * [LEDGER-FIX] Fallback nilai harian — SATU query agregat
         * per (student, assignment) untuk seluruh kelas.
         */
        $dailyAgg = DB::table('assessment_scores')
            ->whereIn('student_id', $studentIds)
            ->whereIn('teaching_assignment_id', $assignmentIds)
            ->groupBy('student_id', 'teaching_assignment_id')
            ->selectRaw('
                student_id,
                teaching_assignment_id,
                AVG(diagnostik) as avg_d,
                AVG(formatif) as avg_f,
                AVG(sumatif) as avg_s
            ')
            ->get()
            ->keyBy(fn ($row) => $row->student_id . '|' . $row->teaching_assignment_id);

        /*
         * [RAPOR-FIX] SIKAP BATCH — prioritas final_grades.d1..d8,
         * fallback assessment_scores.
         */
        $fgSikapBatch = DB::table('final_grades')
            ->join('teaching_assignments', 'final_grades.teaching_assignment_id', '=', 'teaching_assignments.id')
            ->whereIn('final_grades.student_id', $studentIds)
            ->whereIn('teaching_assignments.homeroom_id', $homeroomIds)
            ->selectRaw('
                final_grades.student_id,
                ROUND(AVG(final_grades.d1), 1) as a1,
                ROUND(AVG(final_grades.d2), 1) as a2,
                ROUND(AVG(final_grades.d3), 1) as a3,
                ROUND(AVG(final_grades.d4), 1) as a4,
                ROUND(AVG(final_grades.d5), 1) as a5,
                ROUND(AVG(final_grades.d6), 1) as a6,
                ROUND(AVG(final_grades.d7), 1) as a7,
                ROUND(AVG(final_grades.d8), 1) as a8
            ')
            ->groupBy('final_grades.student_id')
            ->get()
            ->filter(function ($row) {
                return $row->a1 !== null;
            })
            ->mapWithKeys(function ($row) {
                return [$row->student_id => $this->calculateAverageFromAvgDimensions($row, self::SIKAP_DEFAULT)];
            });

        $sikapBatch = DB::table('assessment_scores')
            ->whereIn('student_id', $studentIds)
            ->whereIn('teaching_assignment_id', $assignmentIds)
            ->selectRaw('student_id, ROUND((AVG(d1) + AVG(d2) + AVG(d3) + AVG(d4) + AVG(d5) + AVG(d6) + AVG(d7) + AVG(d8)) / 8, 1) as rata_sikap')
            ->groupBy('student_id')
            ->get()
            ->mapWithKeys(function ($row) {
                return [$row->student_id => $this->normalizeRating($row->rata_sikap, self::SIKAP_DEFAULT)];
            });

        $headers = ['No.', 'NISN', 'Nama Siswa'];

        foreach ($subjects as $subject) {
            $headers[] = $subject->nama_mata_pelajaran;
        }

        $headers = array_merge($headers, ['Rata-rata', 'Sakit', 'Izin', 'Alpa', 'Rata-rata Sikap']);

        $dataArray = [];

        foreach ($students as $index => $student) {
            $row = [$index + 1, !empty($student->nisn) ? (string) $student->nisn : '-', $student->nama];

            $totalNilai = 0;
            $jumlahMapel = 0;
            $studentGrades = $grades->get($student->id)?->keyBy('teaching_assignment_id');

            foreach ($subjects as $subject) {
                $finalGrade = $studentGrades?->get($subject->assignment_id)?->nilai_akhir;

                /*
                 * [LEDGER-FIX] Fallback nilai harian bila final kosong —
                 * bobot dari TA, identik dengan perhitungan rapor PDF.
                 */
                if (!is_numeric($finalGrade)) {
                    $agg = $dailyAgg->get($student->id . '|' . $subject->assignment_id);

                    if ($agg) {
                        $finalGrade = FinalGradeCalculator::fromAverages(
                            $agg->avg_d, $agg->avg_f, $agg->avg_s,
                            (int) ($subject->bobot_diagnostik ?? 0),
                            (int) ($subject->bobot_formatif ?? 0),
                            (int) ($subject->bobot_sumatif ?? 0)
                        );
                    }
                }

                $row[] = $finalGrade ?? '-';

                if (is_numeric($finalGrade)) {
                    $totalNilai += (float) $finalGrade;
                    $jumlahMapel++;
                }
            }

            $row[] = $jumlahMapel > 0 ? round($totalNilai / $jumlahMapel, 2) : '-';
            $row[] = $student->rapor_sakit ?? 0;
            $row[] = $student->rapor_izin ?? 0;
            $row[] = $student->rapor_alpa ?? 0;

            /*
             * [RAPOR-FIX] Prioritas sikap final_grades → assessment_scores
             * → default SIKAP_DEFAULT bila kosong total.
             */
            $row[] = $fgSikapBatch->get($student->id)
                ?? $sikapBatch->get($student->id)
                ?? self::SIKAP_DEFAULT;

            $dataArray[] = $row;
        }

        $exportClass = new class($dataArray, $headers) implements
            \Maatwebsite\Excel\Concerns\FromArray,
            \Maatwebsite\Excel\Concerns\WithHeadings,
            \Maatwebsite\Excel\Concerns\WithStyles,
            \Maatwebsite\Excel\Concerns\WithColumnWidths,
            \Maatwebsite\Excel\Concerns\WithEvents,
            \Maatwebsite\Excel\Concerns\WithColumnFormatting
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

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastColumn = $this->getLastColumn();
                $highestRow = $sheet->getHighestRow();

                $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
                $sheet->getStyle('A1:' . $lastColumn . '1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
                $sheet->getStyle('A1:' . $lastColumn . $highestRow)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
                $sheet->getStyle('A1:' . $lastColumn . $highestRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

                if ($highestRow >= 2) {
                    $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('B2:B' . $highestRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }

                $sheet->getRowDimension(1)->setRowHeight(35);

                return [];
            }

            public function columnWidths(): array
            {
                $widths = ['A' => 7, 'B' => 18, 'C' => 30];
                $totalColumns = count($this->headers);

                for ($i = 4; $i <= $totalColumns; $i++) {
                    $widths[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i)] = 20;
                }

                if ($totalColumns >= 4) {
                    $widths[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalColumns - 4)] = 14;
                }

                return $widths;
            }

            public function columnFormats(): array
            {
                return ['B' => \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT];
            }

            public function registerEvents(): array
            {
                return [
                    \Maatwebsite\Excel\Events\AfterSheet::class => function (\Maatwebsite\Excel\Events\AfterSheet $event) {
                        $sheet = $event->sheet->getDelegate();
                        $highestRow = $sheet->getHighestRow();
                        $highestColumn = $sheet->getHighestColumn();

                        $sheet->freezePane('A2');
                        $sheet->setAutoFilter('A1:' . $highestColumn . $highestRow);
                        $sheet->getStyle('A1:' . $highestColumn . '1')->getFont()->setBold(true);

                        for ($row = 2; $row <= $highestRow; $row++) {
                            $sheet->getRowDimension($row)->setRowHeight(30);
                        }

                        if ($highestRow >= 2) {
                            $sheet->getStyle('A2:B' . $highestRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                        }

                        if ($highestColumn !== 'C') {
                            $sheet->getStyle('D2:' . $highestColumn . $highestRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                        }

                        $sheet->getStyle('A1:' . $highestColumn . $highestRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                        $sheet->getStyle('A1:' . $highestColumn . $highestRow)->getAlignment()->setWrapText(true);
                        $sheet->getRowDimension(1)->setRowHeight(35);
                    },
                ];
            }

            private function getLastColumn(): string
            {
                return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($this->headers));
            }
        };

        return Excel::download($exportClass, 'Legger_' . str_replace(' ', '_', $rombel->nama) . '.xlsx');
    }
}