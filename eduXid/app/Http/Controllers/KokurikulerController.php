<?php

namespace App\Http\Controllers;

use App\Services\Sync\LmsSyncService;
use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class KokurikulerController extends Controller
{
    /*
     * [F2] Revisi:
     *  - [D17] Role-guard in-code pada fungsi admin (defense-in-depth
     *    di atas middleware role:admin pada routes).
     *  - [D16] store/destroy kegiatan = tabel master snapshot →
     *    MasterRegistry KEY_DIRTY (daemon push master otomatis).
     *  - [KK-01 FINAL] destroy: forceTombstone nilai kirim SEBELUM
     *    hapus lokal (selalu terkirim — bukan ber-footprint), lalu
     *    hapus lokal dalam transaksi.
     *  - storeGuru: versi via VersionService (satu versi/transaksi).
     */

    private const DEFAULT_RATING = 3;

    private ?bool $kokurikulerHasSyncVersion = null;

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

    /* ============================================================
     * [D17] Role-guard in-code
     * ============================================================ */

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        if (!$user || ($user->role ?? '') !== 'admin') {
            abort(403, 'Fungsi ini hanya untuk admin sekolah.');
        }
    }

    /* ============================================================
     * [D16] Tabel master berubah → daemon push master
     * ============================================================ */

    private function markMasterDirty(): void
    {
        try {
            \App\Services\Sync\MasterRegistry::set(
                \App\Services\Sync\MasterRegistry::KEY_DIRTY,
                '1'
            );
        } catch (Throwable) {
            // Penanda gagal tidak menggagalkan operasi.
        }
    }

    /* ============================================================
     * [06.1] SCHEMA-TOLERANT
     * ============================================================ */

    private function kokurikulerHasSyncVersion(): bool
    {
        if ($this->kokurikulerHasSyncVersion === null) {
            try {
                $this->kokurikulerHasSyncVersion = Schema::hasColumn('kokurikuler_grades', 'sync_version');
            } catch (Throwable) {
                $this->kokurikulerHasSyncVersion = false;
            }
        }

        return $this->kokurikulerHasSyncVersion;
    }

    private function stampSyncVersion(array $values, int $version): array
    {
        if ($this->kokurikulerHasSyncVersion()) {
            $values['sync_version'] = $version;
        }

        return $values;
    }

    /* ============================================================
     * SINKRON NILAI (dua lapis)
     * ============================================================ */

    private function syncKokurikulerToCloud(int $kokurikulerId): void
    {
        try {
            $sync = app(LmsSyncService::class);

            if (!$sync->isCloudConfigured()) {
                return;
            }

            $sync->pushKokurikulerGradeBatch($kokurikulerId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function markKoordinatorDirty(int $kokurikulerId): void
    {
        try {
            $koordinatorId = (int) (
                DB::table('kokurikulers')
                    ->where('id', $kokurikulerId)
                    ->value('koordinator_id')
                ?? 0
            );

            if ($koordinatorId > 0) {
                WorkProductPushService::markDirty($koordinatorId);
            }
        } catch (Throwable) {
        }
    }

    // ==========================================
    // FUNGSI UNTUK ADMIN
    // ==========================================

    public function index(Request $request): View
    {
        $this->authorizeAdmin();   /* [D17] */

        $semester = $this->resolveViewingSemester($request);

        $gurus = DB::table('teachers')
            ->whereNull('deleted_at')
            ->orderBy('nama')
            ->get();

        $rombels = DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->where('semester_id', $semester)
            ->orderBy('nama')
            ->get();

        $kokurikulers = DB::table('kokurikulers')
            ->join('teachers', 'kokurikulers.koordinator_id', '=', 'teachers.id')
            ->leftJoin('homerooms', 'kokurikulers.homeroom_id', '=', 'homerooms.id')
            ->where('homerooms.semester_id', $semester)
            ->select(
                'kokurikulers.*',
                'teachers.nama as koordinator',
                'homerooms.nama as kelas'
            )
            ->orderBy('homerooms.nama')
            ->get();

        return view(
            'admin.kokurikuler',
            compact(
                'gurus',
                'rombels',
                'kokurikulers',
                'semester'
            )
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();   /* [D17] */

        $request->validate([
            'tema' => 'required|string|max:255',
            'nama_kegiatan' => 'required|string|max:255',
            'koordinator_id' => 'required|exists:teachers,id',
            'homeroom_id' => 'required|exists:homerooms,id',
        ]);

        $rombel = DB::table('homerooms')
            ->where('id', $request->homeroom_id)
            ->first();

        if (!$rombel) {
            return back()->with('error', 'Rombel tidak ditemukan.');
        }

        if (
            str_contains(strtolower((string) $rombel->jenis_rombel), 'ekstrakurikuler')
        ) {
            return back()->with('error', 'Kokurikuler tidak dapat dibuat pada rombel Ekstrakurikuler.');
        }

        $aktif = $this->getActiveSemester();

        if (
            isset($rombel->semester_id)
            && $rombel->semester_id !== null
            && (string) $rombel->semester_id > $aktif
        ) {
            return back()->with('error', 'Kegiatan tidak dapat dibuat pada semester yang belum dimulai.');
        }

        DB::table('kokurikulers')->insert([
            'tema' => $request->tema,
            'nama_kegiatan' => $request->nama_kegiatan,
            'koordinator_id' => $request->koordinator_id,
            'homeroom_id' => $request->homeroom_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /* [D16] Kegiatan master baru → snapshot harus ter-push. */
        $this->markMasterDirty();

        return back()->with('success', 'Kegiatan Kokurikuler berhasil ditambahkan! Data akan tersinkron ke laptop lain otomatis.');
    }

    /**
     * [KK-01 FINAL / D12] Hapus kegiatan + seluruh nilainya.
     * Tombstone nilai dikirim PAKSaan SEBELUM hapus lokal, sehingga
     * laptop lain, PWA, dan cloud ikut bersih — bukan lagi klaim
     * yang mustahil dieksekusi.
     */
    public function destroy(Request $request, $id): RedirectResponse
    {
        $this->authorizeAdmin();   /* [D17] */

        $kokurikuler = DB::table('kokurikulers')
            ->where('id', (int) $id)
            ->first();

        if (!$kokurikuler) {
            return back()->with('error', 'Kegiatan tidak ditemukan.');
        }

        $deletedGrades = 0;

        try {
            $deletedGrades = (int) DB::table('kokurikuler_grades')
                ->where('kokurikuler_id', (int) $id)
                ->count();
        } catch (Throwable) {
            $deletedGrades = 0;
        }

        /*
         * [KK-01] Nilai ikut terhapus secara SADAR (konfirmasi admin) →
         * tombstone paksa ke semua device guru SEBELUM baris lokal hilang.
         */
        if ($deletedGrades > 0) {
            try {
                app(LmsSyncService::class)->forceTombstone(
                    'kokurikuler_grade_batch',
                    'kokurikuler_grade::' . (int) $id,
                    ['kokurikuler_id' => (int) $id]
                );
            } catch (Throwable $e) {
                return back()->with(
                    'error',
                    'Penghapusan dibatalkan: perintah pembersihan nilai belum berhasil masuk antrean sinkronisasi ('
                    . $e->getMessage() . '). Coba lagi.'
                );
            }
        }

        DB::transaction(function () use ($id) {
            DB::table('kokurikuler_grades')
                ->where('kokurikuler_id', $id)
                ->delete();

            DB::table('kokurikulers')
                ->where('id', $id)
                ->delete();
        });

        /* [D16] Kegiatan master dihapus → snapshot harus ter-push. */
        $this->markMasterDirty();

        $message = 'Kegiatan Kokurikuler berhasil dihapus!';

        if ($deletedGrades > 0) {
            $message .= " ({$deletedGrades} nilai murid ikut terhapus dan pembersihannya disiarkan ke laptop lain.)";
        }

        return back()->with('success', $message);
    }

    // ==========================================
    // FUNGSI UNTUK GURU (KOORDINATOR)
    // ==========================================

    public function indexGuru(Request $request): View
    {
        $user = Auth::user();

        $guru = $user?->teacher();

        $kokurikulers = collect();
        $selectedKokurikuler = null;
        $students = collect();
        $viewingSemester = $this->resolveViewingSemester($request);

        if ($guru) {

            $kokurikulers = DB::table('kokurikulers')
                ->join('homerooms', 'kokurikulers.homeroom_id', '=', 'homerooms.id')
                ->where('kokurikulers.koordinator_id', $guru->id)
                ->where(function ($q) use ($viewingSemester) {
                    $q->where('homerooms.semester_id', $viewingSemester)
                        ->orWhereNull('homerooms.semester_id');
                })
                ->select(
                    'kokurikulers.*',
                    'homerooms.nama as kelas',
                    'homerooms.semester_id'
                )
                ->orderBy('homerooms.nama')
                ->get();

            $requestedId = (int) $request->query('id');

            if ($requestedId > 0) {

                $selectedKokurikuler = $kokurikulers
                    ->where('id', $requestedId)
                    ->first();

                if ($selectedKokurikuler) {

                    $students = DB::table('students')
                        ->join('enrollments', 'students.id', '=', 'enrollments.student_id')
                        ->where('enrollments.homeroom_id', $selectedKokurikuler->homeroom_id)
                        ->leftJoin('kokurikuler_grades', function ($join) use ($selectedKokurikuler) {
                            $join->on('students.id', '=', 'kokurikuler_grades.student_id')
                                ->where('kokurikuler_grades.kokurikuler_id', $selectedKokurikuler->id);
                        })
                        ->select(
                            'students.id',
                            'students.nama',
                            'students.nisn',
                            'kokurikuler_grades.deskripsi',
                            'kokurikuler_grades.d1',
                            'kokurikuler_grades.d2',
                            'kokurikuler_grades.d3',
                            'kokurikuler_grades.d4',
                            'kokurikuler_grades.d5',
                            'kokurikuler_grades.d6',
                            'kokurikuler_grades.d7',
                            'kokurikuler_grades.d8'
                        )
                        ->orderBy('students.nama')
                        ->get();

                    $students->transform(function ($student) {

                        for ($i = 1; $i <= 8; $i++) {

                            $field = 'd' . $i;

                            if ($student->{$field} === null) {
                                $student->{$field} = self::DEFAULT_RATING;
                            }
                        }

                        return $student;
                    });
                }
            }
        }

        return view(
            'guru.kokurikuler',
            compact(
                'kokurikulers',
                'selectedKokurikuler',
                'students',
                'viewingSemester'
            )
        );
    }

    public function storeGuru(Request $request): RedirectResponse
    {
        $request->validate([
            'kokurikuler_id' => 'required|exists:kokurikulers,id',
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

        $guru = Auth::user()?->teacher();

        if (!$guru) {
            abort(403, 'Data guru tidak ditemukan untuk akun ini.');
        }

        $kokurikuler = DB::table('kokurikulers')
            ->where('id', $request->kokurikuler_id)
            ->where('koordinator_id', $guru->id)
            ->first();

        if (!$kokurikuler) {
            abort(403, 'Anda bukan koordinator kegiatan ini.');
        }

        $validStudentIds = DB::table('enrollments')
            ->where('homeroom_id', $kokurikuler->homeroom_id)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $saved = 0;
        $skipped = 0;

        $version = \App\Services\Sync\VersionService::next();   /* [F1-VER] satu versi/transaksi */

        DB::transaction(function () use ($request, $kokurikuler, $validStudentIds, $version, &$saved, &$skipped) {

            foreach ($request->students as $data) {

                $studentId = (int) $data['student_id'];

                if (!in_array($studentId, $validStudentIds, true)) {
                    $skipped++;
                    continue;
                }

                $d1 = (int) ($data['d1'] ?? self::DEFAULT_RATING);
                $d2 = (int) ($data['d2'] ?? self::DEFAULT_RATING);
                $d3 = (int) ($data['d3'] ?? self::DEFAULT_RATING);
                $d4 = (int) ($data['d4'] ?? self::DEFAULT_RATING);
                $d5 = (int) ($data['d5'] ?? self::DEFAULT_RATING);
                $d6 = (int) ($data['d6'] ?? self::DEFAULT_RATING);
                $d7 = (int) ($data['d7'] ?? self::DEFAULT_RATING);
                $d8 = (int) ($data['d8'] ?? self::DEFAULT_RATING);

                $avg = (
                    $d1 + $d2 + $d3 + $d4
                    + $d5 + $d6 + $d7 + $d8
                ) / 8;

                $predikat = 'B';

                if ($avg >= 4.5) {
                    $predikat = 'A';
                } elseif ($avg >= 3.5) {
                    $predikat = 'B';
                } elseif ($avg >= 2.5) {
                    $predikat = 'C';
                } elseif ($avg >= 1.5) {
                    $predikat = 'D';
                } else {
                    $predikat = 'E';
                }

                $values = $this->stampSyncVersion([
                    'deskripsi' => $data['deskripsi'] ?? null,
                    'predikat' => $predikat,
                    'd1' => $d1,
                    'd2' => $d2,
                    'd3' => $d3,
                    'd4' => $d4,
                    'd5' => $d5,
                    'd6' => $d6,
                    'd7' => $d7,
                    'd8' => $d8,
                    'updated_at' => now(),
                ], $version);

                $existing = DB::table('kokurikuler_grades')
                    ->where('student_id', $studentId)
                    ->where('kokurikuler_id', $kokurikuler->id)
                    ->first();

                if ($existing) {
                    DB::table('kokurikuler_grades')
                        ->where('id', $existing->id)
                        ->update($values);
                } else {
                    DB::table('kokurikuler_grades')->insert(
                        $values
                        + [
                            'student_id' => $studentId,
                            'kokurikuler_id' => $kokurikuler->id,
                            'created_at' => now(),
                        ]
                    );
                }

                $saved++;
            }
        });

        $this->syncKokurikulerToCloud((int) $kokurikuler->id);
        $this->markKoordinatorDirty((int) $kokurikuler->id);

        $message = 'Penilaian Kokurikuler massal berhasil disimpan!';

        if ($skipped > 0) {
            $message .= " ({$saved} tersimpan, {$skipped} dilewati karena bukan anggota kelas kegiatan.)";
        }

        return redirect()
            ->back()
            ->with('success', $message);
    }
}