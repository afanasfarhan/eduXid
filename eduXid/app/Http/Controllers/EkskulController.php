<?php

namespace App\Http\Controllers;

use App\Services\Sync\MasterRegistry;
use App\Services\Sync\VersionService;
use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class EkskulController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | DEFAULT NILAI
    |--------------------------------------------------------------------------
    |
    | Semua dimensi Profil Lulusan menggunakan nilai awal 3.
    |
    | 3 = Mulai Berkembang
    |
    */

    private const DEFAULT_RATING = 3;

    private $predikatMap = [
        5 => 'A',
        4 => 'B',
        3 => 'C',
        2 => 'D',
        1 => 'E',
    ];

    private ?bool $ekskulHasSyncVersion = null;

    /*
    |--------------------------------------------------------------------------
    | SEMESTER
    |--------------------------------------------------------------------------
    */

    /**
     * [FIX-SEMESTER] Fallback DARI DATA (rombel reguler tertinggi),
     * bukan konstanta '20261' - dulu seluruh penilaian bisa salah
     * semester bila session kosong.
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
     * Pembina ekskul dibolehkan menilai ekskul semester lampau
     * miliknya - konsisten dengan WaliKelas/Nilai/Rapor/Absensi.
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
     * Seluruh rombel ekskul yang dibina guru pada semester yang
     * dilihat.
     *
     * [HISTORICAL-ACCESS] Semester lampau dibolehkan; fallback
     * rombel ekskul tanpa semester_id (instalasi data lama).
     */
    private function getSecureEkskulList(Request $request): array
    {
        $user = Auth::user();

        if (!$user) {
            return [];
        }

        $guru = $user->teacher();

        if (!$guru) {
            return [];
        }

        $semester = $this->resolveViewingSemester($request);

        return DB::table('homerooms')
            ->where('homeroom_teacher_id', $guru->id)
            ->where('jenis_rombel', 'like', '%Ekstrakurikuler%')
            ->where(function ($q) use ($semester) {
                $q->where('semester_id', $semester)
                    ->orWhereNull('semester_id');
            })
            ->orderByRaw('CASE WHEN semester_id = ? THEN 0 ELSE 1 END', [$semester])
            ->orderBy('nama')
            ->select('id', 'nama', 'semester_id')
            ->get()
            ->all();
    }

    /**
     * Ekskul yang dipilih: ?ekskul_id= (divalidasi kepemilikan)
     * atau ekskul pertama milik guru.
     *
     * [MULTI-EKSKUL] Dulu ->first() sembarang tanpa cara memilih
     * bila guru membina lebih dari satu ekskul.
     */
    private function getSecureEkskul(Request $request): ?object
    {
        $ekskulList = $this->getSecureEkskulList($request);

        if ($ekskulList === []) {
            return null;
        }

        $requestedId = (int) $request->query('ekskul_id');

        if ($requestedId > 0) {
            foreach ($ekskulList as $ekskul) {
                if ((int) $ekskul->id === $requestedId) {
                    return $ekskul;
                }
            }
        }

        return $ekskulList[0];
    }

    /* ============================================================
     * [06.1] SCHEMA-TOLERANT HELPERS
     * ============================================================ */

    private function ekskulHasSyncVersion(): bool
    {
        if ($this->ekskulHasSyncVersion === null) {
            try {
                $this->ekskulHasSyncVersion = Schema::hasColumn('ekskul_grades', 'sync_version');
            } catch (Throwable) {
                $this->ekskulHasSyncVersion = false;
            }
        }

        return $this->ekskulHasSyncVersion;
    }

    private function stampSyncVersion(array $values, int $version): array
    {
        if ($this->ekskulHasSyncVersion()) {
            $values['sync_version'] = $version;
        }

        return $values;
    }

    /*
    |--------------------------------------------------------------------------
    | [RAPOR-SYNC] SINKRONISASI CLOUD - dua lapis
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim nilai ekskul rombel ini ke Cloud (best-effort langsung).
     *
     * Payload = SELURUH baris nilai rombel ini; penerima
     * (WorkProductApplier, replace-per-scope) membuat penghapusan
     * anggota ikut terpropagasi antar laptop.
     *
     * Gagal sinkronisasi tidak mempengaruhi penyimpanan lokal -
     * [06.2] markDirty menjamin daemon mengejar (antrean outbox
     * me-retry otomatis) meski push langsung ini gagal.
     */
    private function syncEkskulToCloud(int $ekskulHomeroomId): void
    {
        try {
            $sync = app(\App\Services\Sync\LmsSyncService::class);

            if (!$sync->isCloudConfigured()) {
                return;
            }

            $sync->pushEkskulGradeBatchForHomeroom($ekskulHomeroomId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function markPembinaDirty(object $ekskul): void
    {
        try {
            $pembinaId = (int) ($ekskul->homeroom_teacher_id ?? 0);

            if ($pembinaId > 0) {
                WorkProductPushService::markDirty($pembinaId);
            }
        } catch (Throwable) {
            // Penanda gagal tidak menggagalkan operasi.
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $ekskul = $this->getSecureEkskul($request);

        $ekskulList = $this->getSecureEkskulList($request);
        $viewingSemester = $this->resolveViewingSemester($request);
        $semesterAktif = $this->getActiveSemester();

        $students = collect();
        $availableStudents = collect();

        if ($ekskul) {

            $students = DB::table('enrollments')
                ->join('students', 'enrollments.student_id', '=', 'students.id')
                ->leftJoin('ekskul_grades', function ($join) use ($ekskul) {
                    $join->on('students.id', '=', 'ekskul_grades.student_id')
                        ->where('ekskul_grades.homeroom_id', $ekskul->id);
                })
                ->where('enrollments.homeroom_id', $ekskul->id)
                ->select(
                    'students.id',
                    'students.nama',
                    'students.nisn',
                    'ekskul_grades.bintang',
                    'ekskul_grades.deskripsi',
                    'ekskul_grades.d1',
                    'ekskul_grades.d2',
                    'ekskul_grades.d3',
                    'ekskul_grades.d4',
                    'ekskul_grades.d5',
                    'ekskul_grades.d6',
                    'ekskul_grades.d7',
                    'ekskul_grades.d8'
                )
                ->orderBy('students.nama')
                ->get();

            /*
             * Jika nilai belum ada,
             * tampilkan default 3 pada D1-D8.
             */
            $students->transform(function ($student) {

                for ($i = 1; $i <= 8; $i++) {

                    $field = 'd' . $i;

                    if ($student->{$field} === null) {
                        $student->{$field} = self::DEFAULT_RATING;
                    }
                }

                /*
                 * Jika bintang belum ada,
                 * default keseluruhan juga 3.
                 */
                if ($student->bintang === null) {
                    $student->bintang = self::DEFAULT_RATING;
                }

                return $student;
            });

            /*
             * Ambil siswa yang BELUM
             * terdaftar di ekskul ini.
             */
            $currentMemberIds = $students->pluck('id')->toArray();

            $availableStudents = DB::table('students')
                ->whereNotIn('id', $currentMemberIds)
                ->orderBy('nama')
                ->get();
        }

        return view('guru.ekskul', compact(
            'ekskul',
            'ekskulList',
            'students',
            'availableStudents',
            'viewingSemester',
            'semesterAktif'
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | SIMPAN PENILAIAN
    |--------------------------------------------------------------------------
    */

    /**
     * Simpan penilaian ekskul.
     *
     * [HISTORICAL-ACCESS] Boleh untuk ekskul semester lampau
     * milik pembina.
     *
     * [TS-FIX] created_at hanya di-set saat insert.
     *
     * [06.1] sync_version di-stamp - LWW lintas jalur untuk baris
     * ekskul (web vs batch vs HP).
     *
     * [06.2] Sinkron dua lapis: push langsung best-effort + markDirty
     * sebagai jaminan (daemon mengejar bila push langsung gagal).
     */
    public function store(Request $request): RedirectResponse
    {
        $ekskul = $this->getSecureEkskul($request);

        if (!$ekskul) {
            return back()->with(
                'error',
                'Anda tidak terdaftar sebagai pembina ekstrakurikuler.'
            );
        }

        $request->validate([
            'students' => 'required|array',
            'students.*.student_id' => 'required|exists:students,id',
            'students.*.deskripsi' => 'nullable|string|max:1000',
            'students.*.d1' => 'nullable|integer|min:1|max:5',
            'students.*.d2' => 'nullable|integer|min:1|max:5',
            'students.*.d3' => 'nullable|integer|min:1|max:5',
            'students.*.d4' => 'nullable|integer|min:1|max:5',
            'students.*.d5' => 'nullable|integer|min:1|max:5',
            'students.*.d6' => 'nullable|integer|min:1|max:5',
            'students.*.d7' => 'nullable|integer|min:1|max:5',
            'students.*.d8' => 'nullable|integer|min:1|max:5',
        ]);

        /*
         * Ambil siswa yang sah
         * sebagai anggota ekskul.
         */
        $validStudentIds = DB::table('enrollments')
            ->where('homeroom_id', $ekskul->id)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $saved = 0;
        $skipped = 0;
        $version = (int) floor(microtime(true) * 1000);

        DB::transaction(function () use ($request, $ekskul, $validStudentIds, $version, &$saved, &$skipped) {

            foreach ($request->students as $data) {

                $studentId = (int) $data['student_id'];

                /*
                 * Pastikan siswa benar-benar
                 * anggota ekskul.
                 *
                 * [SKIP-PRESERVE] Dilewati (bukan abort) agar satu
                 * input tidak valid tidak menggagalkan seluruh
                 * batch - kini dihitung & dilaporkan.
                 */
                if (!in_array($studentId, $validStudentIds, true)) {
                    $skipped++;
                    continue;
                }

                /*
                 * =================================================
                 * DEFAULT SEMUA DIMENSI = 3
                 * =================================================
                 */
                $d1 = (int) ($data['d1'] ?? self::DEFAULT_RATING);
                $d2 = (int) ($data['d2'] ?? self::DEFAULT_RATING);
                $d3 = (int) ($data['d3'] ?? self::DEFAULT_RATING);
                $d4 = (int) ($data['d4'] ?? self::DEFAULT_RATING);
                $d5 = (int) ($data['d5'] ?? self::DEFAULT_RATING);
                $d6 = (int) ($data['d6'] ?? self::DEFAULT_RATING);
                $d7 = (int) ($data['d7'] ?? self::DEFAULT_RATING);
                $d8 = (int) ($data['d8'] ?? self::DEFAULT_RATING);

                /*
                 * Rata-rata 8 dimensi.
                 */
                $rataBintang = round(
                    (
                        $d1 + $d2 + $d3 + $d4
                        + $d5 + $d6 + $d7 + $d8
                    ) / 8
                );

                /*
                 * Predikat berdasarkan
                 * rating 1-5.
                 */
                $predikat = $this->predikatMap[$rataBintang] ?? 'C';

                $values = $this->stampSyncVersion([
                    'bintang' => $rataBintang,
                    'predikat' => $predikat,
                    'd1' => $d1,
                    'd2' => $d2,
                    'd3' => $d3,
                    'd4' => $d4,
                    'd5' => $d5,
                    'd6' => $d6,
                    'd7' => $d7,
                    'd8' => $d8,
                    'deskripsi' => $data['deskripsi'] ?? null,
                    'updated_at' => now(),
                ], $version);

                /*
                 * [TS-FIX] created_at hanya dibuat
                 * ketika record baru.
                 */
                $existing = DB::table('ekskul_grades')
                    ->where('student_id', $studentId)
                    ->where('homeroom_id', $ekskul->id)
                    ->first();

                if ($existing) {
                    DB::table('ekskul_grades')
                        ->where('id', $existing->id)
                        ->update($values);
                } else {
                    DB::table('ekskul_grades')->insert(
                        $values
                        + [
                            'student_id' => $studentId,
                            'homeroom_id' => $ekskul->id,
                            'created_at' => now(),
                        ]
                    );
                }

                $saved++;
            }
        });

        /*
         * [RAPOR-SYNC] Nilai ekskul -> Cloud (best-effort langsung)...
         */
        $this->syncEkskulToCloud((int) $ekskul->id);

        /*
         * [06.2] ...plus markDirty sebagai jaminan: bila push langsung
         * gagal, daemon mendorong batch berikutnya (menyertakan
         * ekskul ini) secara otomatis.
         */
        $this->markPembinaDirty($ekskul);

        $message = 'Penilaian Ekstrakurikuler berhasil disimpan!';

        if ($skipped > 0) {
            $message .= " ({$saved} tersimpan, {$skipped} dilewati karena bukan anggota ekskul ini.)";
        }

        return redirect()
            ->back()
            ->with('success', $message);
    }

    // ==========================================
    // TAMBAH ANGGOTA
    // ==========================================

    public function addMember(Request $request): RedirectResponse
    {
        $ekskul = $this->getSecureEkskul($request);

        if (!$ekskul) {
            return back()->with('error', 'Akses ditolak.');
        }

        $request->validate([
            'student_id' => 'required|exists:students,id',
        ]);

        $exists = DB::table('enrollments')
            ->where('homeroom_id', $ekskul->id)
            ->where('student_id', $request->student_id)
            ->exists();

        if (!$exists) {

            DB::table('enrollments')->insert([
                'homeroom_id' => $ekskul->id,
                'student_id' => $request->student_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * [D16] Keanggotaan ada di snapshot master → daemon push.
             */
            try {
                MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
            } catch (Throwable) {
            }

            return back()->with('success', 'Anggota ekskul berhasil ditambahkan. Data akan tersinkron ke laptop lain otomatis.');
        }

        return back()->with('error', 'Murid sudah terdaftar di ekskul ini.');
    }

    // ==========================================
    // HAPUS ANGGOTA
    // ==========================================

    /**
     * Hapus anggota ekskul beserta nilainya.
     *
     * [VALIDATE-FIX] student_id kini divalidasi & Request di-inject
     * untuk konteks semester - dulu parameter rute dipakai mentah.
     *
     * [RAPOR-SYNC] Keadaan terbaru (anggota keluar) ikut disiarkan
     * ke Cloud agar laptop lain tidak menyimpan nilai basi.
     */
    public function removeMember(Request $request, $student_id): RedirectResponse
    {
        $ekskul = $this->getSecureEkskul($request);

        if (!$ekskul) {
            return back()->with('error', 'Akses ditolak.');
        }

        $request->merge(['student_id' => $student_id]);

        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
        ]);

        DB::table('enrollments')
            ->where('homeroom_id', $ekskul->id)
            ->where('student_id', $student_id)
            ->delete();

        DB::table('ekskul_grades')
            ->where('homeroom_id', $ekskul->id)
            ->where('student_id', $student_id)
            ->delete();

        /*
         * [D16] Keanggotaan (snapshot) berubah → push master.
         * Nilai siswa yang keluar ikut tersiar via flush batch
         * replace-per-scope berikutnya (F1).
         */
        try {
            MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
        } catch (Throwable) {
        }

        $this->syncEkskulToCloud((int) $ekskul->id);
        $this->markPembinaDirty($ekskul);

        return back()->with('success', 'Anggota berhasil dikeluarkan dari ekskul. Perubahan akan tersinkron ke laptop lain otomatis.');
    }
}