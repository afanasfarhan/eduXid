<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class AbsensiController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | SEMESTER & KONTEKS AKSES
    |--------------------------------------------------------------------------
    */

    /**
     * [FIX-SEMESTER] Fallback DARI DATA (rombel reguler tertinggi),
     * bukan konstanta '20261' - dulu seluruh rekap bisa salah semester
     * bila session kosong.
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
     * Wali kelas dibolehkan melihat & mengelola absensi semester
     * lampau miliknya - konsisten dengan WaliKelas/Nilai/Rapor.
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
     * Konteks akses modul absensi.
     *
     * [SECURITY-FIX] Dulu wali kelas yang rombelnya tidak ditemukan
     * mendapat null - dan null diperlakukan sebagai "Admin/Kepsek"
     * oleh validateStudentAccess, sehingga wali tanpa kelas bisa
     * mengubah absensi siswa mana pun. Kini privileged & homeroomId
     * dipisah eksplisit:
     *
     *   - privileged = true            -> Admin/Kepsek (semua siswa)
     *   - privileged = false + id      -> Wali kelas terikat kelasnya
     *   - privileged = false + null    -> Wali TANPA kelas -> ditolak
     *
     * [HISTORICAL-ACCESS] Rombel wali mengikuti semester yang
     * dilihat; fallback rombel tanpa semester_id (data lama).
     */
    private function getAccessContext(Request $request): array
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Anda harus login.');
        }

        if ($user->isAdmin() || $user->role === 'kepala_sekolah') {
            return ['privileged' => true, 'homeroomId' => null];
        }

        if ($user->isWaliKelas()) {

            $guru = $user->teacher();

            if (!$guru) {
                abort(403, 'Data guru tidak ditemukan.');
            }

            $semester = $this->resolveViewingSemester($request);

            $rombel = DB::table('homerooms')
                ->where('homeroom_teacher_id', $guru->id)
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%')
                ->orderByDesc('semester_id')
                ->where(function ($q) use ($semester) {
                    $q->where('semester_id', $semester)
                        ->orWhereNull('semester_id');
                })
                ->orderByRaw('CASE WHEN semester_id = ? THEN 0 ELSE 1 END', [$semester])
                ->select('id')
                ->first();

            return [
                'privileged' => false,
                'homeroomId' => $rombel?->id ? (int) $rombel->id : null,
            ];
        }

        abort(403, 'Anda tidak memiliki akses ke modul absensi.');
    }

    /**
     * [SEMESTER-SCOPE] ID rombel semester yang dilihat:
     * - Wali        -> [rombel miliknya]
     * - Admin/Kepsek-> semua rombel reguler semester tersebut
     *
     * Return kosong bila tidak ada - pemanggil menampilkan halaman
     * kosong, BUKAN seluruh siswa.
     */
    private function getSemesterHomeroomIds(array $ctx, string $semester): array
    {
        if (!$ctx['privileged']) {
            return $ctx['homeroomId'] !== null ? [$ctx['homeroomId']] : [];
        }

        return DB::table('homerooms')
            ->where('semester_id', $semester)
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->pluck('id')
            ->all();
    }

    /**
     * [SEMESTER-SCOPE] Filter absensi ke teaching assignments milik
     * rombel semester yang dilihat. Baris milik modul ini
     * (teaching_assignment_id NULL) tetap disertakan - mereka tidak
     * dapat dipetakan ke semester tanpa informasi tambahan.
     *
     * Guard Schema menjaga instalasi lama tanpa kolom tersebut
     * (perilaku lama: tanpa filter).
     */
    private function applyAttendanceScope($query, array $homeroomIds): void
    {
        if (!Schema::hasColumn('attendances', 'teaching_assignment_id')) {
            return;
        }

        $taIds = $homeroomIds === []
            ? collect()
            : DB::table('teaching_assignments')
                ->whereIn('homeroom_id', $homeroomIds)
                ->pluck('id');

        $query->where(function ($q) use ($taIds) {
            if ($taIds->isNotEmpty()) {
                $q->whereIn('teaching_assignment_id', $taIds);
            }
            $q->orWhereNull('teaching_assignment_id');
        });
    }

    /**
     * Validasi: Wali kelas hanya boleh mengotak-atik siswa di
     * kelasnya sendiri (semester mana pun - rombel sudah
     * semester-scoped di getAccessContext).
     *
     * [SECURITY-FIX] Wali TANPA kelas kini DITOLAK - dulu null
     * diperalatan sebagai akses admin.
     */
    private function validateStudentAccess($student_id, array $ctx): void
    {
        if ($ctx['privileged']) {
            return;
        }

        if ($ctx['homeroomId'] === null) {
            abort(403, 'Anda belum terdaftar sebagai wali kelas.');
        }

        $isInClass = DB::table('enrollments')
            ->where('homeroom_id', $ctx['homeroomId'])
            ->where('student_id', $student_id)
            ->exists();

        if (!$isInClass) {
            abort(403, 'Anda tidak berhak mengubah absensi murid dari kelas lain.');
        }
    }

    /* ============================================================
     * [06.1] SCHEMA-TOLERANT HELPERS
     * ============================================================ */

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    private function stampSyncVersion(string $table, array $data, int $version): array
    {
        if ($this->hasColumn($table, 'sync_version')) {
            $data['sync_version'] = $version;
        }

        return $data;
    }

    /*
     * [FIX-KETERANGAN] Kolom 'keterangan' TIDAK dijamin ada di tabel
     * attendances (skema W13 tidak membuatnya). Bila tidak ada,
     * field keterangan DIBUANG senyap dari data - bukan crash saat
     * wali menyimpan.
     */
    private function hasKeteranganColumn(): bool
    {
        return $this->hasColumn('attendances', 'keterangan');
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $ctx = $this->getAccessContext($request);

        $semester = $this->resolveViewingSemester($request);

        $homeroomIds = $this->getSemesterHomeroomIds($ctx, $semester);

        $students = collect();
        $rekap = collect();
        $riwayat = collect();

        /*
         * [SECURITY-FIX] Wali tanpa kelas / semester tanpa rombel:
         * tampilkan halaman kosong - dulu null memicu tampil SEMUA
         * siswa sekolah.
         */
        if ($homeroomIds !== []) {

            $studentIds = DB::table('enrollments')
                ->whereIn('homeroom_id', $homeroomIds)
                ->pluck('student_id')
                ->unique()
                ->values();

            $students = DB::table('students')
                ->whereIn('id', $studentIds)
                ->orderBy('nama')
                ->get();

            /*
             * [PORTABILITY] SUM(CASE WHEN ...) - dulu
             * SUM(status = "Sakit") MySQL-specific.
             *
             * [SEMESTER-SCOPE] Absensi difilter ke rombel semester
             * yang dilihat - dulu menjumlah seluruh riwayat semester.
             */
            $rekapQuery = DB::table('attendances')
                ->join('students', 'attendances.student_id', '=', 'students.id')
                ->whereIn('attendances.student_id', $studentIds)
                ->select(
                    'students.id as student_id',
                    'students.nama',
                    DB::raw("SUM(CASE WHEN status = 'Sakit' THEN 1 ELSE 0 END) as total_sakit"),
                    DB::raw("SUM(CASE WHEN status = 'Izin' THEN 1 ELSE 0 END) as total_izin"),
                    DB::raw("SUM(CASE WHEN status = 'Alpa' THEN 1 ELSE 0 END) as total_alpa")
                )
                ->groupBy('students.id', 'students.nama');

            $this->applyAttendanceScope($rekapQuery, $homeroomIds);

            $rekap = $rekapQuery->get();

            $riwayatQuery = DB::table('attendances')
                ->join('students', 'attendances.student_id', '=', 'students.id')
                ->whereIn('attendances.student_id', $studentIds)
                ->select('attendances.*', 'students.nama')
                ->orderBy('attendances.tanggal', 'desc');

            $this->applyAttendanceScope($riwayatQuery, $homeroomIds);

            $riwayat = $riwayatQuery->paginate(15);
        }

        return view('absensi.index', compact(
            'students',
            'rekap',
            'riwayat',
            'semester'
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    /**
     * Simpan keterangan ketidakhadiran.
     *
     * [DATA-INTEGRITY-FIX] Grain modul ini = baris attendance TANPA
     * teaching_assignment_id (catatan wali), terpisah dari baris
     * pertemuan pengampu/HP (ber-TA) - keduanya tidak saling menimpa.
     *
     * [06.1] sync_version di-stamp bila kolom tersedia.
     *
     * [FIX-KETERANGAN] Kolom keterangan bersifat opsional di skema -
     * bila tidak ada, field dibuang (bukan crash).
     *
     * [GRAIN-NOTE] Baris modul ini (NULL-TA) sengaja TIDAK dikirim ke
     * cloud/PWA: attendance_session kanonik bergrain TA, sedangkan
     * rekap yang dibagi ke rapor mengalir lewat rapor_recap_batch
     * (jalur WaliKelas sudah ada). Modul ini = catatan wali di
     * perangkatnya + rekap lokal.
     *
     * [TS-FIX] created_at hanya di-set saat insert.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'tanggal' => 'required|date',
            'status' => 'required|in:Sakit,Izin,Alpa',
            'keterangan' => 'nullable|string|max:1000',
        ]);

        $ctx = $this->getAccessContext($request);

        $this->validateStudentAccess($request->student_id, $ctx);

        $hasTaColumn = $this->hasColumn('attendances', 'teaching_assignment_id');
        $hasKeterangan = $this->hasKeteranganColumn();

        $version = (int) floor(microtime(true) * 1000);

        $data = [
            'status' => $request->status,
            'updated_at' => now(),
        ];

        if ($hasKeterangan) {
            $data['keterangan'] = $request->keterangan;
        }

        $data = $this->stampSyncVersion('attendances', $data, $version);

        $existing = DB::table('attendances')
            ->where('student_id', $request->student_id)
            ->where('tanggal', $request->tanggal)
            ->when($hasTaColumn, fn ($q) => $q->whereNull('teaching_assignment_id'))
            ->first();

        if ($existing) {
            DB::table('attendances')
                ->where('id', $existing->id)
                ->update($data);
        } else {
            $data['student_id'] = $request->student_id;
            $data['tanggal'] = $request->tanggal;
            $data['created_at'] = now();

            DB::table('attendances')->insert($data);
        }

        return redirect()->back()->with('success', 'Keterangan ketidakhadiran berhasil disimpan!');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE / DESTROY
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'status' => 'required|in:Sakit,Izin,Alpa',
            'keterangan' => 'nullable|string|max:1000',
        ]);

        $ctx = $this->getAccessContext($request);

        $absensi = DB::table('attendances')->where('id', $id)->first();

        if (!$absensi) {
            abort(404);
        }

        $this->validateStudentAccess($absensi->student_id, $ctx);

        $version = (int) floor(microtime(true) * 1000);

        $data = [
            'status' => $request->status,
            'updated_at' => now(),
        ];

        if ($this->hasKeteranganColumn()) {
            $data['keterangan'] = $request->keterangan;
        }

        $data = $this->stampSyncVersion('attendances', $data, $version);

        DB::table('attendances')->where('id', $id)->update($data);

        return redirect()->back()->with('success', 'Data absensi berhasil diperbarui!');
    }

    public function destroy(Request $request, $id): RedirectResponse
    {
        $ctx = $this->getAccessContext($request);

        $absensi = DB::table('attendances')->where('id', $id)->first();

        if (!$absensi) {
            abort(404);
        }

        $this->validateStudentAccess($absensi->student_id, $ctx);

        DB::table('attendances')->where('id', $id)->delete();

        return redirect()->back()->with('success', 'Data absensi berhasil dihapus!');
    }

    /*
    |--------------------------------------------------------------------------
    | API - POP-UP RIWAYAT
    |--------------------------------------------------------------------------
    */

    public function history(Request $request, $student_id)
    {
        $ctx = $this->getAccessContext($request);

        $this->validateStudentAccess($student_id, $ctx);

        /*
         * Riwayat penuh lintas semester (popup keperluan wali melihat
         * rekam jejak) - tidak dibatasi semester yang dilihat.
         */
        $history = DB::table('attendances')
            ->where('student_id', $student_id)
            ->whereIn('status', ['Sakit', 'Izin', 'Alpa'])
            ->orderBy('tanggal', 'desc')
            ->get();

        return response()->json($history);
    }
}