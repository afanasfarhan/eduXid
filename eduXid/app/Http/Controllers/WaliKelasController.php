<?php

namespace App\Http\Controllers;

use App\Services\Sync\MasterRegistry;
use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

class WaliKelasController extends Controller
{
    /**
     * [SIKAP-DEFAULT] Konsisten dengan HomeroomController,
     * NilaiController, dan RaporController: dimensi profil lulusan
     * yang belum dinilai dianggap bernilai 3 (Mulai Berkembang).
     * Skala 1-5:
     *
     *   1 = Perlu Intervensi
     *   2 = Sedang Berkembang
     *   3 = Mulai Berkembang
     *   4 = Berkembang Sesuai Harapan
     *   5 = Sangat Berkembang
     */
    protected const SIKAP_DEFAULT = 3;

    /*
    |--------------------------------------------------------------------------
    | SEMESTER
    |--------------------------------------------------------------------------
    */

    /**
     * [FIX-SEMESTER] Fallback DARI DATA (rombel reguler tertinggi),
     * bukan konstanta '20261' - dulu seluruh kelola catatan/rekap
     * bisa salah semester bila session kosong.
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
     * [HISTORICAL-ACCESS] Semester yang dilihat: ?semester= (format
     * 5 digit, tidak melewati semester aktif) atau semester aktif.
     * Wali kelas dibolehkan mengelola catatan & absensi rapor
     * semester lampau miliknya.
     */
    private function resolveViewingSemester(Request $request): string
    {
        $aktif = $this->getActiveSemester();

        $requested = trim((string) $request->query('semester'));

        if (
            preg_match('/^\d{5}$/', $requested) === 1
            && $requested <= $aktif
        ) {
            return $requested;
        }

        return $aktif;
    }

    /**
     * Mendapatkan rombel yang menjadi tanggung jawab wali kelas
     * yang sedang login, pada semester yang dilihat.
     *
     * [HISTORICAL-ACCESS] Semester lampau dibolehkan; fallback
     * defensif ke rombel tanpa semester_id (instalasi data lama).
     */
    private function getSecureHomeroomId(Request $request): ?object
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        $guru = $user->teacher();

        if (!$guru) {
            return null;
        }

        $base = DB::table('homerooms')
            ->where('homeroom_teacher_id', $guru->id)
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->orderByDesc('semester_id');

        $semester = $this->resolveViewingSemester($request);

        $rombel = (clone $base)
            ->where('semester_id', $semester)
            ->select('id', 'nama', 'semester_id')
            ->first()
            /*
             * Fallback: rombel lama yang belum menyimpan semester_id.
             */
            ?? $base
                ->whereNull('semester_id')
                ->select('id', 'nama', 'semester_id')
                ->first();

        return $rombel;
    }

    /*
    |--------------------------------------------------------------------------
    | PREDIKAT & DIMENSI
    |--------------------------------------------------------------------------
    */

    /**
     * Predikat rating 1-5.
     */
    private function getPredicate(int $rating): string
    {
        return match ($rating) {
            1 => 'Perlu Intervensi',
            2 => 'Sedang Berkembang',
            3 => 'Mulai Berkembang',
            4 => 'Berkembang Sesuai Harapan',
            5 => 'Sangat Berkembang',
            default => 'Mulai Berkembang',
        };
    }

    /**
     * Nama aspek perkembangan.
     *
     * Nama dibuat singkat agar dapat digunakan
     * secara natural dalam narasi.
     */
    private function getDimensionNames(): array
    {
        return [
            'd1' => 'keimanan',
            'd2' => 'sikap kewargaan',
            'd3' => 'kemampuan penalaran',
            'd4' => 'kreativitas',
            'd5' => 'kolaborasi',
            'd6' => 'kemandirian',
            'd7' => 'kesehatan',
            'd8' => 'komunikasi',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | [RAPOR-SYNC] SINKRONISASI CLOUD - dua lapis
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim catatan wali + rekap absensi rapor ke Cloud (best-effort
     * langsung).
     *
     * Gagal sinkronisasi tidak mempengaruhi penyimpanan lokal -
     * [06.2] markDirty menjamin daemon mengejar via antrean outbox.
     */
    private function syncRaporToCloud(int $homeroomId): void
    {
        try {
            $sync = app(\App\Services\Sync\LmsSyncService::class);

            if (!$sync->isCloudConfigured()) {
                return;
            }

            $sync->pushCatatanWaliBatchForHomeroom($homeroomId);
            $sync->pushRaporRecapBatchForHomeroom($homeroomId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * [06.2] markDirty wali - daemon mendorong kedua batch
     * (catatan_wali + rapor_recap) bila push langsung gagal.
     */
    private function markWaliDirty(int $homeroomId): void
    {
        try {
            $waliTeacherId = (int) (
                DB::table('homerooms')
                    ->where('id', $homeroomId)
                    ->value('homeroom_teacher_id')
                ?? 0
            );

            if ($waliTeacherId > 0) {
                WorkProductPushService::markDirty($waliTeacherId);
            }
        } catch (Throwable) {
            // Penanda gagal tidak menggagalkan operasi.
        }
    }

    private function markWaliDirtyOrFail(int $homeroomId): void
    {
        $teacherId = (int) DB::table('homerooms')->where('id', $homeroomId)
            ->value('homeroom_teacher_id');
        $version = \App\Services\Sync\VersionService::next();
        foreach (['catatan_wali', 'rapor_recap'] as $entity) {
            DB::table('app_settings')->upsert([[
                'key' => 'grades::scope::' . $entity . '::homeroom::' . $homeroomId,
                'value' => (string) $version, 'created_at' => now(), 'updated_at' => now(),
            ]], ['key'], ['value', 'updated_at']);
        }
        WorkProductPushService::markDirtyOrFail($teacherId);
    }

    /**
     * [D16] Catatan wali & rekap rapor menempel di enrollments —
     * tabel yang ikut snapshot master → perubahan runtime wajib
     * memicu push master agar tidak tertimpa snapshot basi.
     */
    private function markMasterDirty(): void
    {
        try {
            MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
        } catch (Throwable) {
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BATCH LOADER - [N+1-FIX]
    |--------------------------------------------------------------------------
    |
     * Seluruh data kelas diambil dengan 4 query (dulu 8-10 query
     * PER SISWA):
     *
     *   1. students + enrollments
     *   2. teaching_assignments + subjects
     *   3. assessment_scores (d1-d8) seluruh siswa
     *   4. final_grades terbaru seluruh siswa
     *
     * Perhitungan profil & akademik kemudian berjalan di memori.
     */

    private function loadHomeroomData(int $homeroomId): array
    {
        $students = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.homeroom_id', $homeroomId)
            ->select(
                'students.id',
                'students.nama',
                'students.nisn',
                'enrollments.id as enrollment_id',
                'enrollments.rapor_sakit',
                'enrollments.rapor_izin',
                'enrollments.rapor_alpa',
                'enrollments.catatan_wali'
            )
            ->orderBy('students.nama')
            ->get();

        $assignments = DB::table('teaching_assignments')
            ->join('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
            ->where('teaching_assignments.homeroom_id', $homeroomId)
            ->select(
                'teaching_assignments.id',
                'subjects.nama_mata_pelajaran'
            )
            ->get();

        $taIds = $assignments->pluck('id');
        $studentIds = $students->pluck('id');

        $scoresByStudent = collect();
        $finalByStudent = collect();

        if ($taIds->isNotEmpty() && $studentIds->isNotEmpty()) {

            /*
             * Semua baris nilai perkembangan (d1-d8) seluruh siswa.
             */
            $scoresByStudent = DB::table('assessment_scores')
                ->whereIn('student_id', $studentIds)
                ->whereIn('teaching_assignment_id', $taIds)
                ->select(
                    'student_id',
                    'd1', 'd2', 'd3', 'd4',
                    'd5', 'd6', 'd7', 'd8'
                )
                ->get()
                ->groupBy('student_id');

            /*
             * Nilai akhir TERBARU per (siswa, mapel) - [BEHAVIOR-PRESERVE]
             * urutan updated_at desc + unique per TA mengambil yang
             * termutakhir, identik dengan logika lama.
             */
            $finalByStudent = DB::table('final_grades')
                ->whereIn('student_id', $studentIds)
                ->whereIn('teaching_assignment_id', $taIds)
                ->whereNotNull('nilai_akhir')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('student_id')
                ->map(
                    fn ($rows) => $rows
                        ->unique('teaching_assignment_id')
                        ->pluck('nilai_akhir', 'teaching_assignment_id')
                );
        }

        return [
            'students' => $students,
            'subjectNames' => $assignments->pluck('nama_mata_pelajaran', 'id'),
            'scoresByStudent' => $scoresByStudent,
            'finalByStudent' => $finalByStudent,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PROFIL PERKEMBANGAN (pure - dari data batch)
    |--------------------------------------------------------------------------
    */

    /**
     * Menghitung perkembangan siswa dari baris assessment_scores
     * yang sudah dimuat (d1-d8).
     */
    private function computeProfileFromRows($rows): array
    {
        $dimensions = $this->getDimensionNames();
        $ratings = [];

        foreach (array_keys($dimensions) as $key) {

            $values = $rows
                ->pluck($key)
                ->filter(function ($value) {
                    return $value !== null && is_numeric($value);
                })
                ->map(function ($value) {
                    return max(1, min(5, (int) $value));
                });

            if ($values->isEmpty()) {
                $ratings[$key] = self::SIKAP_DEFAULT;
            } else {
                $ratings[$key] = max(
                    1,
                    min(5, (int) round($values->avg()))
                );
            }
        }

        return $this->buildProfileResult($ratings, $rows->isNotEmpty());
    }

    /**
     * Membentuk hasil perkembangan siswa.
     */
    private function buildProfileResult(array $ratings, bool $hasAssessment): array
    {
        $dimensions = $this->getDimensionNames();

        /*
        |--------------------------------------------------------------------------
        | RATA-RATA
        |--------------------------------------------------------------------------
        */
        $average = array_sum($ratings) / count($ratings);

        $overallRating = max(1, min(5, (int) round($average)));

        $predicate = $this->getPredicate($overallRating);

        /*
        |--------------------------------------------------------------------------
        | ASPEK TERKUAT
        |--------------------------------------------------------------------------
        */
        $maxRating = max($ratings);
        $strengths = [];

        foreach ($ratings as $key => $rating) {
            if ($rating === $maxRating) {
                $strengths[] = $dimensions[$key];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ASPEK YANG MASIH PERLU DIKEMBANGKAN
        |--------------------------------------------------------------------------
        */
        $minRating = min($ratings);
        $improvements = [];

        foreach ($ratings as $key => $rating) {
            if ($rating === $minRating) {
                $improvements[] = $dimensions[$key];
            }
        }

        return [
            'ratings' => $ratings,
            'average_rating' => round($average, 2),
            'overall_rating' => $overallRating,
            'predicate' => $predicate,
            'has_assessment' => $hasAssessment,
            'strengths' => $strengths,
            'strength_rating' => $maxRating,
            'improvements' => $improvements,
            'improvement_rating' => $minRating,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PROFIL AKADEMIK (pure - dari data batch)
    |--------------------------------------------------------------------------
    */

    /**
     * Profil akademik siswa dari nilai akhir TERBARU per mapel.
     *
     * HANYA menggunakan final_grades.nilai_akhir (TIDAK diagnostik/
     * formatif/sumatif) - [BEHAVIOR-PRESERVE] tanpa filter
     * Bimbingan/Konseling, identik dengan perilaku lama.
     *
     * $latestGradesByTa = map {ta_id => nilai_akhir terbaru}.
     * $subjectNames     = map {ta_id => nama_mata_pelajaran}.
     */
    private function computeAcademicFromRows($latestGradesByTa, $subjectNames): array
    {
        $empty = [
            'has_assessment' => false,
            'average' => null,
            'highest_subjects' => [],
            'highest_score' => null,
            'lowest_subjects' => [],
            'lowest_score' => null,
            'subjects' => [],
        ];

        if (!$latestGradesByTa || $latestGradesByTa->isEmpty()) {
            return $empty;
        }

        $subjectResults = [];

        foreach ($latestGradesByTa as $taId => $nilai) {

            $namaMapel = $subjectNames[$taId] ?? null;

            if ($namaMapel === null) {
                continue;
            }

            if ($nilai === null || !is_numeric($nilai)) {
                continue;
            }

            $subjectResults[] = [
                'nama' => $namaMapel,
                'nilai' => round((float) $nilai, 2),
            ];
        }

        if (empty($subjectResults)) {
            return $empty;
        }

        /*
        |--------------------------------------------------------------------------
        | RATA-RATA NILAI AKHIR
        |--------------------------------------------------------------------------
        */
        $overallAverage = round(collect($subjectResults)->avg('nilai'), 2);

        /*
        |--------------------------------------------------------------------------
        | NILAI TERTINGGI
        |--------------------------------------------------------------------------
        */
        $highestScore = max(array_column($subjectResults, 'nilai'));

        $highestSubjects = collect($subjectResults)
            ->filter(function ($item) use ($highestScore) {
                return $item['nilai'] == $highestScore;
            })
            ->pluck('nama')
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | NILAI TERENDAH
        |--------------------------------------------------------------------------
        */
        $lowestScore = min(array_column($subjectResults, 'nilai'));

        $lowestSubjects = collect($subjectResults)
            ->filter(function ($item) use ($lowestScore) {
                return $item['nilai'] == $lowestScore;
            })
            ->pluck('nama')
            ->values()
            ->all();

        return [
            'has_assessment' => true,
            'average' => $overallAverage,
            'highest_subjects' => $highestSubjects,
            'highest_score' => $highestScore,
            'lowest_subjects' => $lowestSubjects,
            'lowest_score' => $lowestScore,
            'subjects' => $subjectResults,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | NARASI
    |--------------------------------------------------------------------------
    */
    /**
     * Menggabungkan beberapa nama menjadi kalimat.
     */
    private function joinList(array $items): string
    {
        $items = array_values(
            array_unique(
                array_filter($items, function ($item) {
                    return trim((string) $item) !== '';
                })
            )
        );

        if (count($items) === 0) {
            return '-';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        if (count($items) === 2) {
            return $items[0] . ' dan ' . $items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ', dan ' . $last;
    }

    /**
     * Membuat nama siswa dengan format yang rapi.
     *
     * Fungsi ini TIDAK menambahkan "Ananda".
     */
    private function formatStudentName(string $name): string
    {
        return mb_convert_case(
            strtolower(trim($name)),
            MB_CASE_TITLE,
            'UTF-8'
        );
    }

    /**
     * Membuat satu paragraf narasi perkembangan siswa.
     *
     * [N+1-FIX] Kini FUNGSI MURNI - menerima profil & akademik yang
     * sudah dihitung dari data batch; tidak lagi melakukan query
     * sendiri (dulu mengulang 4 query per pemanggilan).
     *
     * Catatan:
     *
     * - Perkembangan karakter dan akademik menyatu.
     * - Tidak menggunakan judul kategori.
     * - Tidak menggunakan istilah "Profil Lulusan".
     * - Nilai akademik hanya dari final_grades.
     * - "Ananda" hanya muncul di dalam narasi.
     */
    private function buildCatatanWali(
        string $namaSiswa,
        array $profile,
        array $academic
    ): array {

        /*
        |--------------------------------------------------------------------------
        | NAMA
        |--------------------------------------------------------------------------
        */
        $nama = $this->formatStudentName($namaSiswa);

        /*
        |--------------------------------------------------------------------------
        | NAMA UNTUK NARASI
        |--------------------------------------------------------------------------
        */
        $namaAnanda = 'Ananda ' . $nama;

        /*
        |--------------------------------------------------------------------------
        | ASPEK YANG MENONJOL
        |--------------------------------------------------------------------------
        */
        $strengthText = $this->joinList($profile['strengths']);
        $improvementText = $this->joinList($profile['improvements']);

        /*
        |--------------------------------------------------------------------------
        | KALIMAT PEMBUKA PERKEMBANGAN
        |--------------------------------------------------------------------------
        */
        if ($profile['overall_rating'] >= 5) {

            $profileOpening =
                "{$namaAnanda} menunjukkan perkembangan yang sangat baik dan konsisten dalam belajar, bersikap, berinteraksi, serta mengembangkan berbagai kemampuan.";

        } elseif ($profile['overall_rating'] >= 4) {

            $profileOpening =
                "{$namaAnanda} menunjukkan perkembangan yang baik dan cukup konsisten dalam proses belajar, bersikap, berinteraksi, serta mengembangkan berbagai kemampuan.";

        } elseif ($profile['overall_rating'] >= 3) {

            $profileOpening =
                "{$namaAnanda} menunjukkan perkembangan yang cukup baik dan terus mengalami penguatan dalam proses belajar, bersikap, berinteraksi, serta mengembangkan berbagai kemampuan.";

        } elseif ($profile['overall_rating'] >= 2) {

            $profileOpening =
                "{$namaAnanda} menunjukkan perkembangan yang sedang tumbuh dan masih membutuhkan penguatan serta pendampingan dalam beberapa kemampuan.";

        } else {

            $profileOpening =
                "{$namaAnanda} masih membutuhkan pendampingan yang lebih intensif untuk mengembangkan kemampuan belajar, sikap, kemandirian, dan interaksi dalam kegiatan sehari-hari.";
        }

        /*
        |--------------------------------------------------------------------------
        | KEKUATAN
        |--------------------------------------------------------------------------
        */
        if (
            !empty($profile['strengths'])
            && $profile['strength_rating'] >= 4
        ) {

            $profileStrength =
                "Kemampuan yang paling menonjol terlihat pada {$strengthText}.";

        } else {

            $profileStrength =
                "Beberapa kemampuan yang sudah berkembang perlu terus dipertahankan dan diperkuat melalui pembiasaan yang positif.";
        }

        /*
        |--------------------------------------------------------------------------
        | BAGIAN YANG PERLU DIKEMBANGKAN
        |--------------------------------------------------------------------------
        */
        if (
            !empty($profile['improvements'])
            && $profile['improvement_rating'] <= 2
        ) {

            $profileImprovement =
                "Aspek {$improvementText} masih memerlukan perhatian dan pendampingan secara lebih terarah.";

        } elseif (
            !empty($profile['improvements'])
            && $profile['improvement_rating'] == 3
        ) {

            $profileImprovement =
                "Aspek {$improvementText} masih perlu terus dikembangkan melalui pembiasaan, latihan, dan pendampingan yang konsisten.";

        } else {

            $profileImprovement =
                "Kemampuan yang telah berkembang perlu terus dipertahankan sekaligus ditingkatkan agar semakin optimal.";
        }

        /*
        |--------------------------------------------------------------------------
        | NARASI AKADEMIK
        |--------------------------------------------------------------------------
        */
        if (!$academic['has_assessment']) {

            $academicNarrative =
                "Pada pembelajaran, nilai akhir yang tersimpan belum tersedia secara memadai sehingga capaian akademik belum dapat digambarkan secara menyeluruh.";

        } else {

            $academicAverage = $academic['average'];

            $highestText = $this->joinList($academic['highest_subjects']);
            $lowestText = $this->joinList($academic['lowest_subjects']);

            $highestScore = $academic['highest_score'];
            $lowestScore = $academic['lowest_score'];

            /*
            |--------------------------------------------------------------------------
            | PEMBUKA AKADEMIK
            |--------------------------------------------------------------------------
            */
            if ($academicAverage >= 90) {

                $academicOpening =
                    "Dalam pembelajaran, {$namaAnanda} menunjukkan capaian yang sangat baik dengan rata-rata nilai akhir {$academicAverage}.";

            } elseif ($academicAverage >= 80) {

                $academicOpening =
                    "Dalam pembelajaran, {$namaAnanda} menunjukkan capaian yang baik dengan rata-rata nilai akhir {$academicAverage} dan penguasaan materi yang cukup konsisten.";

            } elseif ($academicAverage >= 70) {

                $academicOpening =
                    "Dalam pembelajaran, {$namaAnanda} menunjukkan capaian yang cukup baik dengan rata-rata nilai akhir {$academicAverage}, meskipun masih terdapat beberapa kemampuan yang perlu diperkuat.";

            } elseif ($academicAverage >= 60) {

                $academicOpening =
                    "Dalam pembelajaran, {$namaAnanda} memiliki rata-rata nilai akhir {$academicAverage} dan masih memerlukan penguatan dalam penguasaan materi agar hasil belajar menjadi lebih konsisten.";

            } else {

                $academicOpening =
                    "Dalam pembelajaran, {$namaAnanda} masih memerlukan pendampingan yang lebih intensif dengan rata-rata nilai akhir {$academicAverage}, terutama untuk memperkuat penguasaan materi.";
            }

            /*
            |--------------------------------------------------------------------------
            | MAPEL MENONJOL
            |--------------------------------------------------------------------------
            */
            $academicStrength =
                "Capaian yang paling menonjol terlihat pada mata pelajaran {$highestText} dengan nilai akhir {$highestScore}.";

            /*
            |--------------------------------------------------------------------------
            | MAPEL YANG MASIH PERLU DIKEMBANGKAN
            |--------------------------------------------------------------------------
            */
            if ($lowestScore < 70) {

                $academicImprovement =
                    "Sementara itu, capaian pada {$lowestText} dengan nilai akhir {$lowestScore} masih memerlukan perhatian dan pendampingan lebih lanjut agar penguasaan kompetensinya meningkat.";

            } elseif ($lowestScore < 80) {

                $academicImprovement =
                    "Kemampuan pada {$lowestText} dengan nilai akhir {$lowestScore} masih perlu ditingkatkan melalui latihan dan pendalaman materi secara konsisten.";

            } else {

                $academicImprovement =
                    "Capaian pada {$lowestText} dengan nilai akhir {$lowestScore} sudah cukup baik dan perlu terus dipertahankan serta ditingkatkan.";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | KESIMPULAN
        |--------------------------------------------------------------------------
        */
        if (
            $profile['overall_rating'] >= 4
            && $academic['has_assessment']
            && $academic['average'] >= 80
        ) {

            $conclusion =
                "Secara keseluruhan, perkembangan {$namaAnanda} menunjukkan kemajuan yang positif, sehingga kekuatan yang telah dimiliki perlu terus dipertahankan dan diarahkan untuk mendukung perkembangan berikutnya.";

        } elseif (
            $profile['overall_rating'] >= 3
            && $academic['has_assessment']
            && $academic['average'] >= 70
        ) {

            $conclusion =
                "Secara keseluruhan, perkembangan {$namaAnanda} menunjukkan kemajuan yang cukup baik dan perlu terus diperkuat melalui kebiasaan belajar yang positif, latihan, serta pendampingan yang sesuai dengan kebutuhannya.";

        } elseif (
            $profile['overall_rating'] <= 2
            || (
                $academic['has_assessment']
                && $academic['average'] < 70
            )
        ) {

            $conclusion =
                "Secara keseluruhan, {$namaAnanda} masih memerlukan pendampingan yang lebih terarah dan berkelanjutan agar perkembangan kemampuan belajar dan berbagai kemampuan lainnya dapat meningkat secara bertahap.";

        } else {

            $conclusion =
                "Secara keseluruhan, perkembangan {$namaAnanda} terus menunjukkan proses yang positif dengan tetap mempertahankan kemampuan yang sudah baik serta memberikan perhatian pada kemampuan yang masih perlu diperkuat.";
        }

        /*
        |--------------------------------------------------------------------------
        | SATUKAN MENJADI SATU PARAGRAF
        |--------------------------------------------------------------------------
        */
        if (!$academic['has_assessment']) {

            $narrative =
                $profileOpening . ' '
                . $profileStrength . ' '
                . $profileImprovement . ' '
                . $academicNarrative . ' '
                . $conclusion;

        } else {

            $narrative =
                $profileOpening . ' '
                . $profileStrength . ' '
                . $profileImprovement . ' '
                . $academicOpening . ' '
                . $academicStrength . ' '
                . $academicImprovement . ' '
                . $conclusion;
        }

        /*
        |--------------------------------------------------------------------------
        | RAPATKAN SPASI
        |--------------------------------------------------------------------------
        */
        $narrative = preg_replace('/\s+/', ' ', trim($narrative));

        return [
            'catatan' => $narrative,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    /**
     * Menampilkan halaman Wali Kelas.
     *
     * [N+1-FIX] Seluruh profil & akademik dihitung dari data batch
     * (4 query untuk seluruh kelas) - dulu +-300+ query.
     *
     * [HISTORICAL-ACCESS] ?semester= untuk mengelola rombel semester
     * lampau; default semester aktif.
     */
    public function index(Request $request)
    {
        $rombel = $this->getSecureHomeroomId($request);

        $viewingSemester = $this->resolveViewingSemester($request);
        $semesterAktif = $this->getActiveSemester();

        $students = collect();

        if ($rombel) {

            $data = $this->loadHomeroomData($rombel->id);

            $students = $data['students']->map(
                function ($student) use ($data) {

                    /*
                    |--------------------------------------------------------------------------
                    | PROFIL PERKEMBANGAN
                    |--------------------------------------------------------------------------
                    */
                    $profile = $this->computeProfileFromRows(
                        $data['scoresByStudent']->get($student->id, collect())
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | AKADEMIK
                    |--------------------------------------------------------------------------
                    */
                    $academic = $this->computeAcademicFromRows(
                        $data['finalByStudent']->get($student->id, collect()),
                        $data['subjectNames']
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | DESKRIPSI OTOMATIS
                    |--------------------------------------------------------------------------
                    |
                    | Selalu dibuat untuk ditampilkan oleh tombol
                    | "Deskripsi Otomatis", tetapi TIDAK menimpa
                    | catatan manual yang sudah ada.
                    |
                    */
                    $autoDescription = $this->buildCatatanWali(
                        $student->nama,
                        $profile,
                        $academic
                    )['catatan'];

                    /*
                    |--------------------------------------------------------------------------
                    | RATING DIMENSI
                    |--------------------------------------------------------------------------
                    */
                    foreach ($profile['ratings'] as $key => $value) {
                        $student->{$key} = $value;
                    }

                    $student->average_rating = $profile['average_rating'];
                    $student->overall_rating = $profile['overall_rating'];
                    $student->predicate = $profile['predicate'];
                    $student->has_assessment = $profile['has_assessment'];
                    $student->profile_strengths = $profile['strengths'];
                    $student->profile_improvements = $profile['improvements'];

                    /*
                    |--------------------------------------------------------------------------
                    | DATA AKADEMIK
                    |--------------------------------------------------------------------------
                    */
                    $student->academic_average = $academic['average'];
                    $student->academic_highest_subjects = $academic['highest_subjects'];
                    $student->academic_highest_score = $academic['highest_score'];
                    $student->academic_lowest_subjects = $academic['lowest_subjects'];
                    $student->academic_lowest_score = $academic['lowest_score'];
                    $student->academic_has_assessment = $academic['has_assessment'];

                    /*
                    |--------------------------------------------------------------------------
                    | DESKRIPSI OTOMATIS UNTUK JAVASCRIPT
                    |--------------------------------------------------------------------------
                    */
                    $student->auto_description = $autoDescription;

                    return $student;
                }
            );
        }

        return view(
            'guru.wali_kelas',
            compact(
                'rombel',
                'students',
                'viewingSemester',
                'semesterAktif'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE DESKRIPSI OTOMATIS
    |--------------------------------------------------------------------------
    */

    /**
     * Membuat ulang seluruh catatan wali kelas
     * secara otomatis dan menyimpannya ke database.
     *
     * Method ini tetap dipertahankan agar tombol
     * Deskripsi Otomatis global tetap berfungsi.
     *
     * [N+1-FIX] Data kelas dimuat sekali (batch) - dulu 4 query
     * per siswa di dalam transaksi.
     *
     * [RAPOR-SYNC] Setelah selesai, hasil dikirim ke Cloud.
     */
    public function generateDescriptions(Request $request): RedirectResponse
    {
        $rombel = $this->getSecureHomeroomId($request);

        if (!$rombel) {
            abort(403, 'Anda bukan wali kelas atau kelas tidak ditemukan.');
        }

        /*
         * [D11] Mode: 'overwrite' (ganti semua) | 'keep' (default —
         * hanya siswa yang catatannya masih kosong). View mengirim
         * konfirmasi eksplisit sebelum mode overwrite.
         */
        $mode = $request->input('mode', 'keep') === 'overwrite' ? 'overwrite' : 'keep';

        $data = $this->loadHomeroomData($rombel->id);

        $generated = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $mode, $rombel, &$generated, &$skipped) {

            foreach ($data['students'] as $student) {

                /*
                 * [D11] Pertahankan catatan manual yang sudah ada.
                 */
                if ($mode === 'keep' && trim((string) ($student->catatan_wali ?? '')) !== '') {
                    $skipped++;
                    continue;
                }

                $profile = $this->computeProfileFromRows(
                    $data['scoresByStudent']->get($student->id, collect())
                );

                $academic = $this->computeAcademicFromRows(
                    $data['finalByStudent']->get($student->id, collect()),
                    $data['subjectNames']
                );

                $catatan = $this->buildCatatanWali(
                    $student->nama,
                    $profile,
                    $academic
                )['catatan'];

                DB::table('enrollments')
                    ->where('id', $student->enrollment_id)
                    ->update([
                        'catatan_wali' => $catatan,
                        'updated_at' => now(),
                    ]);

                $generated++;
            }
            if ($generated > 0) $this->markWaliDirtyOrFail((int) $rombel->id);
        });

        if ($generated > 0) {
            $this->syncRaporToCloud((int) $rombel->id);
            $this->markWaliDirty((int) $rombel->id);
            $this->markMasterDirty();   /* [D16] */
        }

        $message = "Deskripsi otomatis selesai: {$generated} siswa dibuat.";

        if ($mode === 'keep' && $skipped > 0) {
            $message .= " {$skipped} catatan manual DIPERTAHANKAN (gunakan mode 'Ganti Semua' bila ingin menggantikan).";
        }

        return redirect()
            ->back()
            ->with('success', $message);
    }

    /*
    |--------------------------------------------------------------------------
    | [BARU] HAPUS DESKRIPSI
    |--------------------------------------------------------------------------
    */

    /**
     * Menghapus (mengosongkan) seluruh catatan wali kelas pada
     * rombel yang diampu - pasangan dari tombol "Deskripsi Otomatis".
     *
     * [RAPOR-SYNC] Penghapusan ikut disiarkan ke Cloud agar laptop
     * Admin & guru lain tidak menyimpan catatan basi.
     */
    public function destroyDescriptions(Request $request): RedirectResponse
    {
        $rombel = $this->getSecureHomeroomId($request);

        if (!$rombel) {
            abort(403, 'Anda bukan wali kelas atau kelas tidak ditemukan.');
        }

        $cleared = DB::transaction(function () use ($rombel): int {
            $count = DB::table('enrollments')
            ->where('homeroom_id', $rombel->id)
            ->whereNotNull('catatan_wali')
            ->update([
                'catatan_wali' => null,
                'updated_at' => now(),
            ]);
            if ($count > 0) $this->markWaliDirtyOrFail((int) $rombel->id);
            return $count;
        });

        /*
         * [RAPOR-SYNC] Keadaan kosong ikut disinkronkan ke Cloud
         * (best-effort langsung) + [06.2] markDirty sebagai jaminan -
         * tanpa ini, laptop lain menyimpan catatan yang sudah dihapus
         * di sini.
         */
        if ($cleared > 0) {
            $this->syncRaporToCloud((int) $rombel->id);
            $this->markWaliDirty((int) $rombel->id);
        }

        return redirect()
            ->back()
            ->with(
                'success',
                $cleared > 0
                    ? "Berhasil! {$cleared} deskripsi/catatan wali kelas berhasil dihapus."
                    : 'Tidak ada deskripsi yang perlu dihapus - catatan wali kelas sudah kosong.'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | SIMPAN ABSENSI RAPOR + CATATAN WALI
    |--------------------------------------------------------------------------
    */

    /**
     * Menyimpan absensi dan catatan wali kelas.
     *
     * [SECURITY-FIX] Kepemilikan enrollment divalidasi SELURUHNYA
     * SEBELUM transaksi (dulu abort di tengah loop - sebagian update
     * bisa terlanjur berjalan lalu rollback paksa).
     *
     * [HISTORICAL-ACCESS] Semester mengikuti ?semester= - wali dapat
     * memperbaiki absensi/catatan rapor semester lampau.
     *
     * [RAPOR-SYNC] Setelah simpan, catatan & rekap dikirim ke Cloud.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'students' => 'required|array',
            'students.*.enrollment_id' => 'required|exists:enrollments,id',
            'students.*.rapor_sakit' => 'nullable|integer|min:0|max:366',
            'students.*.rapor_izin' => 'nullable|integer|min:0|max:366',
            'students.*.rapor_alpa' => 'nullable|integer|min:0|max:366',
            'students.*.catatan_wali' => 'nullable|string|max:5000',
        ]);

        $rombel = $this->getSecureHomeroomId($request);

        if (!$rombel) {
            abort(403, 'Anda bukan wali kelas atau kelas tidak ditemukan.');
        }

        $validEnrollmentIds = DB::table('enrollments')
            ->where('homeroom_id', $rombel->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalid = collect($request->students)
            ->pluck('enrollment_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => in_array($id, $validEnrollmentIds, true));

        if ($invalid->isNotEmpty()) {
            abort(403, 'Akses ditolak: Anda mencoba memodifikasi siswa dari kelas lain.');
        }

        DB::transaction(function () use ($request, $rombel) {

            foreach ($request->students as $data) {

                /*
                 * [WK-01] Hanya field yang BENAR-BENAR dikirim form yang
                 * ditulis — field absen tidak dinullkan.
                 */
                $values = [];

                if (array_key_exists('rapor_sakit', $data)) {
                    $values['rapor_sakit'] = (int) ($data['rapor_sakit'] ?? 0);
                }

                if (array_key_exists('rapor_izin', $data)) {
                    $values['rapor_izin'] = (int) ($data['rapor_izin'] ?? 0);
                }

                if (array_key_exists('rapor_alpa', $data)) {
                    $values['rapor_alpa'] = (int) ($data['rapor_alpa'] ?? 0);
                }

                if (array_key_exists('catatan_wali', $data)) {
                    $values['catatan_wali'] = $data['catatan_wali'] ?? null;
                }

                if ($values === []) {
                    continue;
                }

                $values['updated_at'] = now();

                DB::table('enrollments')
                    ->where('id', (int) $data['enrollment_id'])
                    ->update($values);
            }
            $this->markWaliDirtyOrFail((int) $rombel->id);
        });

        $this->syncRaporToCloud((int) $rombel->id);
        $this->markWaliDirty((int) $rombel->id);
        $this->markMasterDirty();   /* [D16] */

        return redirect()
            ->back()
            ->with(
                'success',
                'Data absensi dan catatan perkembangan berhasil disimpan! Data akan tersinkron otomatis.'
            );
    }
}
