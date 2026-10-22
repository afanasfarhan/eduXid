<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class HomeroomController extends Controller
{
    /**
     * [SIKAP-DEFAULT] Konsisten dengan NilaiController &
     * RaporController: dimensi profil lulusan yang belum dinilai
     * dianggap bernilai 3 (Mulai Berkembang). Skala 1-5:
     *
     *   1 = Perlu Intervensi
     *   2 = Sedang Berkembang
     *   3 = Mulai Berkembang
     *   4 = Berkembang Sesuai Harapan
     *   5 = Sangat Berkembang
     */
    protected const SIKAP_DEFAULT = 3;

    /**
     * [HISTORICAL-ACCESS] Semester aktif untuk guard masa depan.
     */
    private function getActiveSemester(): string
    {
        return (string) (session('semester_aktif') ?? '20261');
    }

    /**
     * Menampilkan daftar rombel.
     *
     * [HISTORICAL-ACCESS] Guru & admin/kepala sekolah dapat melihat
     * rombel semester AKTIF + LAMPAU (semester terbaru lebih dulu) —
     * sejalan dengan akses cetak rapor/legger historis. Hanya
     * semester masa depan yang tidak pernah memiliki data.
     */
    public function index()
    {
        $user = Auth::user();
        $semesterAktif = $this->getActiveSemester();

        /*
         * [TEACHER-DEL] Filter deleted_at DI DALAM closure join —
         * bila di WHERE biasa, LEFT JOIN berubah efektif menjadi
         * INNER JOIN dan rombel tanpa wali hilang dari daftar.
         * Guru yang sudah dihapus (SoftDeletes) tidak tampil.
         */
        $query = DB::table('homerooms')
            ->leftJoin('teachers', function ($join) {
                $join->on('homerooms.homeroom_teacher_id', '=', 'teachers.id')
                    ->whereNull('teachers.deleted_at');
            })
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
            ->where('homerooms.semester_id', '<=', $semesterAktif)
            ->orderByDesc('homerooms.semester_id')
            ->select(
                'homerooms.*',
                'teachers.nama as wali_kelas',
                'teachers.gelar_depan',
                'teachers.gelar_belakang'
            );

        /*
        |--------------------------------------------------------------------------
        | GATEKEEPER
        |--------------------------------------------------------------------------
        |
        | Admin dan Kepala Sekolah dapat melihat seluruh rombel.
        |
        | Guru hanya dapat melihat:
        | 1. Rombel yang diajar
        | 2. Rombel yang menjadi wali kelasnya
        |
        */
        if (!$this->isPrivileged($user)) {

            $guru = $user->teacher();

            if ($guru) {

                $validHomeroomIds = $this->guruAccessibleHomeroomIds($guru);

                $query->whereIn('homerooms.id', $validHomeroomIds);
            } else {

                $query->whereRaw('1 = 0');
            }
        }

        $rombels = $query
            ->orderBy('homerooms.nama')
            ->get();

        return view('rombel.index', compact('rombels'));
    }

    /**
     * Menampilkan detail rombel.
     *
     * [HISTORICAL-ACCESS] Rombel semester lampau dapat dibuka
     * (sesuai kebijakan cetak rapor historis); hanya semester
     * MASA DEPAN yang ditolak.
     */
    public function show(Request $request, $id)
    {
        /*
        |--------------------------------------------------------------------------
        | AMBIL ROMBEL
        |--------------------------------------------------------------------------
        */
        $rombel = DB::table('homerooms')
            ->where('id', $id)
            ->first();

        if (!$rombel) {
            abort(404, 'Data kelas tidak ditemukan atau belum tersinkronisasi.');
        }

        /*
         * [SEMESTER-GUARD] Hanya semester MASA DEPAN yang ditolak;
         * semester lampau dibolehkan (akses historis).
         */
        $semesterAktif = $this->getActiveSemester();

        if (
            isset($rombel->semester_id)
            && $rombel->semester_id !== null
            && (string) $rombel->semester_id > $semesterAktif
        ) {
            abort(404, 'Kelas belum tersedia (semester belum dimulai).');
        }

        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | GATEKEEPER
        |--------------------------------------------------------------------------
        |
        | Admin dan Kepala Sekolah: bebas mengakses.
        |
        | Guru: hanya jika menjadi wali kelas tersebut,
        | atau mengajar pada kelas tersebut.
        |
        */
        $this->authorizeRombel($user, $rombel);

        /*
        |--------------------------------------------------------------------------
        | ASSIGNMENT / MATA PELAJARAN
        |--------------------------------------------------------------------------
        |
        | [ASSIGN-GUARD] ID assignment divalidasi: harus benar-benar
        | milik rombel ini. Sebelumnya, ID assignment dari rombel lain
        | bisa disisipkan lewat URL sehingga kolom nilai & D1-D8
        | terisi dari assessment_scores kelas yang tidak berhak dilihat.
        |
        | Validasi + pengambilan nama mapel dilakukan dalam SATU query.
        |
        */
        $assignmentId = (int) $request->query('assignment');

        $assignmentRow = null;

        if ($assignmentId > 0) {

            $assignmentRow = DB::table('teaching_assignments')
                ->leftJoin('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
                ->where('teaching_assignments.id', $assignmentId)
                ->where('teaching_assignments.homeroom_id', $id)
                ->select(
                    'subjects.nama_mata_pelajaran',
                    'teaching_assignments.teacher_id'
                )
                ->first();
        }

        /*
         * [D2] Pengampu non-wali hanya boleh melihat mapel MILIKNYA.
         * Wali rombel & admin/kepsek bebas (pemilik kelas).
         */
        if ($assignmentRow !== null && !$this->isPrivileged($user)) {
            $guru = $user->teacher();

            $isWali = $guru !== null
                && (int) $rombel->homeroom_teacher_id === (int) $guru->id;

            $isOwner = $guru !== null
                && (int) $assignmentRow->teacher_id === (int) $guru->id;

            if (!$isWali && !$isOwner) {
                $assignmentRow = null;
            }
        }

        $mapelNama = $assignmentRow?->nama_mata_pelajaran ?? 'Rapor Semester';

        $assignmentId = $assignmentRow !== null ? $assignmentId : null;

        /*
        |--------------------------------------------------------------------------
        | REKAP ABSENSI
        |--------------------------------------------------------------------------
        |
        | [ATT-SCOPE] Absensi diskop ke teaching assignments milik
        | rombel ini. Sebelumnya subquery hanya memfilter student_id,
        | sehingga sakit/izin/alpa menjumlahkan seluruh riwayat
        | semester siswa (nilai rapor menjadi salah).
        |
        | Guard Schema::hasColumn menjaga kompatibilitas bila kolom
        | belum ada pada instalasi lama — perilaku lama dipertahankan.
        |
        */
        /*
         * [V21R76 / PROFILE-COMPAT]
         * Instalasi sekolah dapat memiliki migrasi nilai, dimensi, foto, atau
         * absensi yang belum lengkap. Sebelum JOIN/AVG, gunakan hanya kolom
         * yang benar-benar tersedia; informasi belum ada ditampilkan kosong.
         * Tidak mengubah data dan tidak memperluas hak akses pengguna.
         */
        $attendanceTableReady = Schema::hasTable('attendances')
            && Schema::hasColumn('attendances', 'student_id')
            && Schema::hasColumn('attendances', 'status');

        $assessmentTableReady = Schema::hasTable('assessment_scores')
            && Schema::hasColumn('assessment_scores', 'student_id')
            && Schema::hasColumn('assessment_scores', 'teaching_assignment_id');

        $attendanceSub = null;
        if ($attendanceTableReady) {
            $attendanceSub = DB::table('attendances')
                ->select('student_id',
                    DB::raw("SUM(CASE WHEN status = 'Sakit' THEN 1 ELSE 0 END) as total_sakit"),
                    DB::raw("SUM(CASE WHEN status = 'Izin' THEN 1 ELSE 0 END) as total_izin"),
                    DB::raw("SUM(CASE WHEN status = 'Alpa' THEN 1 ELSE 0 END) as total_alpa")
                )
                ->groupBy('student_id');

            // Tetap dalam ruang lingkup kelas; tanpa penugasan bukan berarti
            // boleh menjumlahkan riwayat kelas lain.
            if (Schema::hasColumn('attendances', 'teaching_assignment_id')) {
                $homeroomTaIds = DB::table('teaching_assignments')
                    ->where('homeroom_id', $id)->pluck('id')->all();
                if ($homeroomTaIds === []) {
                    $attendanceSub->whereRaw('1 = 0');
                } else {
                    $attendanceSub->whereIn('teaching_assignment_id', $homeroomTaIds);
                }
            }
        }

        $studentQuery = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.homeroom_id', $id);

        if ($assessmentTableReady) {
            $studentQuery->leftJoin('assessment_scores', function ($join) use ($assignmentId) {
                $join->on('students.id', '=', 'assessment_scores.student_id')
                     ->where('assessment_scores.teaching_assignment_id', $assignmentId);
            });
        }
        if ($attendanceSub !== null) {
            $studentQuery->leftJoinSub($attendanceSub, 'att', function ($join) {
                $join->on('students.id', '=', 'att.student_id');
            });
        }

        $columns = [
            'enrollments.id as enrollment_id',
            'students.id',
            'students.nama',
            'students.nisn',
            'students.jenis_kelamin',
        ];
        $groupColumns = [
            'enrollments.id', 'students.id', 'students.nama',
            'students.nisn', 'students.jenis_kelamin',
        ];

        foreach (['pos_x', 'pos_y'] as $position) {
            if (Schema::hasColumn('enrollments', $position)) {
                $columns[] = 'enrollments.' . $position;
                $groupColumns[] = 'enrollments.' . $position;
            } else {
                $columns[] = DB::raw('NULL as ' . $position);
            }
        }
        if (Schema::hasColumn('students', 'foto_path')) {
            $columns[] = 'students.foto_path';
            $groupColumns[] = 'students.foto_path';
        } else {
            $columns[] = DB::raw('NULL as foto_path');
        }

        foreach (['diagnostik', 'formatif', 'sumatif'] as $kind) {
            $hasColumn = $assessmentTableReady
                && Schema::hasColumn('assessment_scores', $kind);
            $columns[] = DB::raw($hasColumn
                ? 'AVG(assessment_scores.' . $kind . ') as nilai_' . $kind
                : 'NULL as nilai_' . $kind);
        }

        foreach (['sakit' => 'total_sakit', 'izin' => 'total_izin', 'alpa' => 'total_alpa'] as $alias => $column) {
            if ($attendanceSub !== null) {
                $columns[] = 'att.' . $column . ' as ' . $alias;
                $groupColumns[] = 'att.' . $column;
            } else {
                $columns[] = DB::raw('0 as ' . $alias);
            }
        }

        for ($dimension = 1; $dimension <= 8; $dimension++) {
            $key = 'd' . $dimension;
            $hasColumn = $assessmentTableReady
                && Schema::hasColumn('assessment_scores', $key);
            $columns[] = DB::raw($hasColumn
                ? 'ROUND(AVG(assessment_scores.' . $key . ')) as avg_' . $key
                : 'NULL as avg_' . $key);
        }

        $students = $studentQuery->select($columns)
            ->groupBy($groupColumns)
            ->orderBy('students.nama')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | NORMALISASI 8 DIMENSI
        |--------------------------------------------------------------------------
        |
        | [SIKAP-DEFAULT] Dimensi yang belum dinilai (NULL) dianggap
        | bernilai SIKAP_DEFAULT (3 = Mulai Berkembang), sesuai
        | kebijakan penilaian sikap.
        |
        | rata_sikap dihitung atas SELURUH 8 dimensi — dengan default,
        | semua dimensi selalu memiliki nilai.
        |
        */
        foreach ($students as $student) {

            $dimensions = [
                'd1' => $student->avg_d1,
                'd2' => $student->avg_d2,
                'd3' => $student->avg_d3,
                'd4' => $student->avg_d4,
                'd5' => $student->avg_d5,
                'd6' => $student->avg_d6,
                'd7' => $student->avg_d7,
                'd8' => $student->avg_d8,
            ];

            $total = 0;

            foreach ($dimensions as $key => $value) {

                /*
                | [SIKAP-DEFAULT] Belum dinilai -> default 3.
                */
                if ($value === null) {
                    $value = self::SIKAP_DEFAULT;
                }

                /*
                | Pastikan nilai berada pada rentang 1-5.
                */
                $value = max(
                    1,
                    min(
                        5,
                        (int) $value
                    )
                );

                $student->{'avg_' . $key} = $value;

                $total += $value;
            }

            $student->rata_sikap =
                $total / count($dimensions);
        }

        /*
        |--------------------------------------------------------------------------
        | VIEW
        |--------------------------------------------------------------------------
        */
        return view(
            'rombel.show',
            compact(
                'rombel',
                'students',
                'mapelNama'
            )
        );
    }

    /* ============================================================
     * HELPER — konsisten dengan pola controller lain
     * ============================================================ */

    /**
     * Admin & Kepala Sekolah bebas mengakses semua rombel.
     */
    private function isPrivileged($user): bool
    {
        return $user->isAdmin()
            || $user->role === 'kepala_sekolah';
    }

    /**
     * Rombel yang dapat diakses seorang guru:
     * rombel yang diajar + rombel yang diwalikan.
     */
    private function guruAccessibleHomeroomIds($guru): array
    {
        $teachingHomeroomIds = DB::table('teaching_assignments')
            ->where('teacher_id', $guru->id)
            ->pluck('homeroom_id')
            ->unique();

        $waliHomeroomIds = DB::table('homerooms')
            ->where('homeroom_teacher_id', $guru->id)
            ->pluck('id')
            ->unique();

        return $teachingHomeroomIds
            ->merge($waliHomeroomIds)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Otorisasi akses detail rombel.
     * Diekstrak dari duplikasi logika index/show.
     */
    private function authorizeRombel($user, $rombel): void
    {
        if ($this->isPrivileged($user)) {
            return;
        }

        $guru = $user->teacher();

        $hasAccess = false;

        if ($guru) {

            /*
            | Guru adalah wali kelas
            */
            if (
                (int) $rombel->homeroom_teacher_id
                ===
                (int) $guru->id
            ) {

                $hasAccess = true;
            } else {

                /*
                | Guru mengajar pada rombel ini
                */
                $hasAccess = DB::table('teaching_assignments')
                    ->where('teacher_id', $guru->id)
                    ->where('homeroom_id', $rombel->id)
                    ->exists();
            }
        }

        if (!$hasAccess) {

            abort(
                403,
                'Anda tidak memiliki hak akses ke kelas ini.'
            );
        }
    }
}