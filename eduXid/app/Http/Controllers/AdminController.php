<?php

namespace App\Http\Controllers;


use App\Services\AcademicPeriodService;
use App\Services\Sync\MasterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use Adereksisusanto\DapodikAPI\Dapodik;
use Maatwebsite\Excel\Facades\Excel;

class AdminController extends Controller
{
    /**
     * ============================================================
     * [05.4-HELPER] Tandai master berubah - daemon akan mem-push
     * snapshot + direktori akun ke Cloud otomatis (throttle 5 mnt).
     * ============================================================
     */
    private function markMasterDirty(): void
    {
        try {
            MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
        } catch (\Throwable) {
            // Penanda gagal tidak boleh menggagalkan impor.
        }
    }

    /**
     * ============================================================
     * DASHBOARD
     * ============================================================
     */
    public function dashboard()
    {
        $stats = [];

        $stats['sekolah'] = DB::table('schools')->first();
        $stats['totalGuru'] = DB::table('teachers')->count();
        $stats['totalSiswa'] = DB::table('students')->count();

        $stats['totalRombel'] = DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->count();

        $stats['totalMapel'] = DB::table('subjects')->count();

        $stats['is_synced'] = $stats['sekolah'] !== null && $stats['totalSiswa'] > 0;

        $stats['cloudStatus'] = \App\Services\Sync\EduxidCloudConfig::status();

        $semester_id = session('semester_aktif', DB::table('homerooms')->max('semester_id'));

        $stats['tahunAjaran'] = '-';
        $stats['semester'] = '-';

        if ($semester_id && strlen((string) $semester_id) === 5) {
            $tahun1 = substr((string) $semester_id, 0, 4);
            $tahun2 = ((int) $tahun1) + 1;
            $stats['tahunAjaran'] = $tahun1 . '/' . $tahun2;

            $sem = substr((string) $semester_id, 4, 1);
            $stats['semester'] = $sem === '1' ? 'Ganjil' : 'Genap';
        }

        return view('dashboard', compact('stats'));
    }

    /**
     * ============================================================
     * PENGATURAN
     * ============================================================
     */
    public function pengaturan()
    {
        $gurus = DB::table('teachers')
            ->orderBy('nama')
            ->get()
            ->map(function ($guru) {
                $namaLengkap = trim($guru->nama ?? '');
                if (!empty($guru->gelar_depan)) {
                    $namaLengkap = trim($guru->gelar_depan) . ' ' . $namaLengkap;
                }
                if (!empty($guru->gelar_belakang)) {
                    $namaLengkap .= ', ' . trim($guru->gelar_belakang);
                }
                $guru->nama_lengkap = trim($namaLengkap);
                return $guru;
            });

        $users = DB::table('users')
            ->leftJoin('teachers', function ($join) {
                $join->on('users.login_id', '=', 'teachers.nip')
                    ->orOn('users.login_id', '=', 'teachers.nik')
                    ->orOn('users.login_id', '=', 'teachers.nuptk');
            })
            ->whereIn('users.role', ['guru', 'wali_kelas', 'guru_bk', 'tendik', 'kepala_sekolah', 'siswa'])
            ->select(
                'users.id',
                'users.name',
                'users.login_id',
                'users.role',
                'teachers.nama as nama_gtk',
                'teachers.gelar_depan',
                'teachers.gelar_belakang',
                'teachers.nip',
                'teachers.nik',
                'teachers.nuptk',
                'teachers.jenis_ptk',
                'teachers.jabatan_ptk',
                'teachers.status_kepegawaian',
                'teachers.pangkat_golongan_terakhir'
            )
            ->orderBy('users.name')
            ->get()
            ->map(function ($user) {
                $nama = trim($user->nama_gtk ?? $user->name ?? '');
                $namaLengkap = $nama;
                if (!empty($user->gelar_depan)) {
                    $namaLengkap = trim($user->gelar_depan) . ' ' . $namaLengkap;
                }
                if (!empty($user->gelar_belakang)) {
                    $namaLengkap .= ', ' . trim($user->gelar_belakang);
                }
                $user->nama_lengkap = trim($namaLengkap);
                return $user;
            });

        $pendingActivations = \Illuminate\Support\Facades\Schema::hasTable('eduxid_initial_credentials')
            ? DB::table('eduxid_initial_credentials as ac')
                ->join('users as u', 'u.id', '=', 'ac.user_id')
                ->where('ac.expires_at', '>', now())
                ->orderBy('u.name')
                ->select('ac.user_id', 'u.name', 'u.role')
                ->limit(500)->get()
            : collect();

        return response()->view('admin.pengaturan', compact('gurus', 'users', 'pendingActivations'))
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * ============================================================
     * RESET PASSWORD
     * ============================================================
     */
    public function resetPassword(Request $request)
    {
        // Aksi sensitif: verifikasi ulang password Admin, bukan hanya sesi login.
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'current_password' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['current_password'], (string) $request->user()?->password)) {
            return back()->withErrors(['current_password' => 'Password Admin tidak sesuai.'])->withInput(['user_id' => $validated['user_id']]);
        }

        // Akun Admin tidak boleh diambil alih lewat endpoint reset GTK/Siswa.
        $allowedRoles = ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah', 'tendik', 'pengajar', 'siswa'];
        $target = DB::table('users')->where('id', $validated['user_id'])
            ->whereIn('role', $allowedRoles)->first(['id', 'name', 'role']);
        if (!$target) {
            return back()->with('error', 'Akun tidak diizinkan untuk reset dari menu GTK/Siswa.');
        }

        $temporaryPassword = \App\Support\SecureTemporaryPassword::generate();
        $updated = DB::table('users')->where('id', $target->id)
            ->whereIn('role', $allowedRoles)
            ->update([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return back()->with('error', 'Reset ditolak karena akun tidak ditemukan atau telah berubah.');
        }

        // Hanya flash untuk satu tampilan hasil; tidak tersimpan dalam log,
        // kolom database, file ZIP, atau respons API Cloud.
        return back()->with('success', 'Password lokal berhasil direset. Berikan password sementara secara pribadi kepada pemilik akun.')
            ->with('one_time_password', $temporaryPassword)
            ->with('one_time_account', (string) $target->name);
    }

    /** Ambil hanya sekali password awal hasil impor Excel (tidak pernah ditulis ke log). */
    public function takeInitialPassword(Request $request)
    {
        $input = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'current_password' => ['required', 'string'],
        ]);
        $operator = $request->user();
        if (!$operator || $operator->role !== 'admin'
            || !Hash::check($input['current_password'], (string) $operator->password)) {
            return back()->with('error', 'Verifikasi Admin gagal.');
        }
        $owner = DB::table('users')->where('id', $input['user_id'])
            ->whereIn('role', ['guru','wali_kelas','guru_bk','kepala_sekolah','tendik','siswa'])
            ->first(['id','name','role']);
        if (!$owner) return back()->with('error', 'Akun tidak diizinkan untuk aktivasi ini.');
        try {
            $temporaryPassword = \App\Services\Security\InitialCredentialVault::consume((int) $owner->id);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Kredensial tidak dapat dibuka. Periksa APP_KEY dan log internal.');
        }
        if (!$temporaryPassword) {
            return back()->with('error', 'Kredensial sudah diambil atau masa berlaku habis. Gunakan Reset Password yang aman.');
        }
        return back()->with('one_time_password', $temporaryPassword)
            ->with('one_time_account', $owner->name)
            ->with('success', 'Kredensial aktivasi diambil sekali. Segera serahkan secara pribadi.');
    }

    /**
     * ============================================================
     * HALAMAN IMPOR
     * ============================================================
     */
    public function impor()
    {
        $rekap = [
            'sekolah' => DB::table('schools')->count(),
            'guru' => DB::table('teachers')->count(),
            'siswa' => DB::table('students')->count(),
            'rombel' => DB::table('homerooms')->count(),
            'mapel' => DB::table('subjects')->count(),
            'pembelajaran' => DB::table('teaching_assignments')->count(),
        ];

        return view('admin.impor', compact('rekap'));
    }

    /**
     * ============================================================
     * SINKRONISASI JSON
     *
     * [FIX] Exit code dapodik:sync kini DIPERIKSA - dulu selalu
     * melaporkan "berhasil" walau sync gagal (sukses palsu).
     * ============================================================
     */
    public function syncJson()
    {
        try {
            $exitCode = Artisan::call('dapodik:sync');

            if ($exitCode !== 0) {
                $output = trim(Artisan::output());

                return redirect()->route('admin.impor')->with(
                    'error',
                    'Sinkronisasi Dapodik GAGAL. Detail: ' . mb_substr($output, 0, 500)
                );
            }

            return redirect()->route('admin.impor')->with(
                'success',
                'Sinkronisasi data Dapodik berhasil dijalankan! Master & akun akan ter-push ke Cloud otomatis oleh daemon.'
            );
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('admin.impor')->with('error', 'Gagal sinkronisasi: ' . $e->getMessage());
        }
    }

    /**
     * V21R150 — Kelompokkan kegagalan Web Service tanpa mencetak URL,
     * credential, NPSN, pesan exception, ataupun isi data siswa.
     * Kode HTTP hanyalah petunjuk penyebab, bukan bukti credential salah.
     */
    private function dapodikFailureCategory(\Throwable $error): array
    {
        $code = (int) $error->getCode();
        $message = strtolower($error->getMessage()); // hanya di memori; jangan di-log
        if (!in_array($code, [401, 403, 404, 408, 422, 429, 500, 502, 503, 504], true)
            && preg_match('/(?:http\/\d(?:\.\d)?[ \t]+|\b)(401|403|404|408|422|429|500|502|503|504)\b/', $message, $matches)) {
            $code = (int) $matches[1];
        }
        return match ($code) {
            401 => ['HTTP_401', 'Token ditolak Web Service; cocokkan dengan token diagnostik yang berhasil'],
            403 => ['HTTP_403', 'Akses ditolak; periksa NPSN dan izin komputer klien'],
            404 => ['HTTP_404', 'Endpoint Web Service tidak ditemukan; periksa alamat dan versi Dapodik'],
            408, 504 => ['TIMEOUT', 'Permintaan Web Service mengalami timeout'],
            422 => ['HTTP_422', 'Parameter permintaan tidak diterima Web Service'],
            429 => ['HTTP_429', 'Terlalu banyak permintaan; tunggu sebelum mencoba lagi'],
            500, 502, 503 => ['HTTP_SERVER', 'Web Service mengalami galat server'],
            default => $this->dapodikFailureCategoryNoHttp($message),
        };
    }

    private function dapodikFailureCategoryNoHttp(string $message): array
    {
        if (str_contains($message, 'curl error 7') || str_contains($message, 'connection refused')
            || str_contains($message, 'failed to connect') || str_contains($message, 'could not resolve')) {
            return ['NETWORK', 'Web Service tidak dapat dihubungi oleh proses Admin'];
        }
        if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out')) {
            return ['TIMEOUT', 'Web Service tidak menjawab dalam batas waktu'];
        }
        if (str_contains($message, 'rows') || str_contains($message, 'json')
            || str_contains($message, 'syntax error') || str_contains($message, 'malformed')) {
            return ['RESPONSE_FORMAT', 'Struktur jawaban Web Service tidak sesuai pustaka PHP'];
        }
        return ['SDK_RESPONSE', 'Pustaka Web Service menolak respons; jenis lebih lanjut belum diketahui'];
    }

    /**
     * ============================================================
     * SINKRONISASI API DAPODIK
     * ============================================================
     */
    public function syncApi(Request $request)
    {
        // V21R151: hanya Web Service Dapodik pada KOMPUTER INI.
        // Parameter dapodik_mode/dapodik_ip dan connection_test sengaja diabaikan;
        // backend tidak dapat lagi diarahkan melalui LAN dari request.
        $request->validate([
            'npsn' => ['required', 'string', 'max:50'],
            'token' => ['required', 'string', 'max:255'],
        ]);
        $npsn = trim((string) $request->input('npsn'));
        $token = trim((string) $request->input('token'));
        $host = '127.0.0.1';
        $port = '5774';
        $dataDir = storage_path('app/dapodik_temp');

        try {
            if (File::isDirectory($dataDir)) {
                File::deleteDirectory($dataDir);
            }
            File::makeDirectory($dataDir, 0777, true, true);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal menyiapkan folder sementara Dapodik: ' . $e->getMessage());
        }

        try {
            $api = (new Dapodik($host, $port))->api($token, $npsn);
        } catch (\Throwable $e) {
            // Exception jaringan/library dapat memuat URL atau token; jangan log pesan mentahnya.
            try { File::deleteDirectory($dataDir); } catch (\Throwable $ignore) {}
            return back()->with('error', 'Gagal terhubung ke Web Service Dapodik. Periksa IP, port 5774, izin Web Service, NPSN, dan token.');
        }

        $jobs = [
            'sekolah' => fn() => $api->sekolah(),
            'pengguna' => fn() => $api->pengguna(),
            'rombel' => fn() => $api->rombel(),
            'peserta_didik' => fn() => $api->pd(),
            'gtk' => fn() => $api->gtk(),
        ];

        $successLog = [];
        $errorLog = [];

        foreach ($jobs as $name => $call) {
            try {
                $response = $call();
                if (is_object($response)) {
                    if (method_exists($response, 'toArray')) {
                        $data = $response->toArray();
                    } else {
                        $data = $this->normalizeData($response);
                    }
                } elseif (is_array($response)) {
                    $data = $response;
                } else {
                    $data = $this->normalizeData($response);
                }

                if (!is_array($data)) {
                    throw new \Exception('Response endpoint ' . $name . ' bukan array.');
                }

                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $filePath = $dataDir . DIRECTORY_SEPARATOR . $name . '.json';
                File::put($filePath, $json);

                if (!File::exists($filePath) || File::size($filePath) === 0) {
                    throw new \Exception('File hasil penarikan kosong atau gagal dibuat: ' . $filePath);
                }

                $successLog[] = $name;
            } catch (\Throwable $e) {
                [$category, $hint] = $this->dapodikFailureCategory($e);
                // Jangan log pesan exception mentah: SDK dapat menyertakan URL/header/rahasia.
                \Illuminate\Support\Facades\Log::warning('Sinkronisasi Dapodik endpoint gagal', [
                    'endpoint' => $name,
                    'exception' => get_class($e),
                    'reason' => $category,
                    'mode' => $host === '127.0.0.1' ? 'local' : 'lan',
                ]);
                $errorLog[] = $name . ' (' . $category . ': ' . $hint . ')';
            }
        }

        if (empty($successLog)) {
            return back()->with('error', 'Sinkronisasi GAGAL TOTAL. Tidak ada data Dapodik yang berhasil ditarik. ' . implode(' | ', $errorLog) . '. Folder dapodik_temp dipertahankan untuk pemeriksaan.');
        }

        $requiredFiles = ['sekolah.json', 'gtk.json', 'peserta_didik.json', 'rombel.json'];
        $missingFiles = [];

        foreach ($requiredFiles as $requiredFile) {
            $path = $dataDir . DIRECTORY_SEPARATOR . $requiredFile;
            if (!File::exists($path) || File::size($path) === 0) {
                $missingFiles[] = $requiredFile;
            }
        }

        if (!empty($missingFiles)) {
            return back()->with('error', 'Data Dapodik berhasil ditarik sebagian, tetapi file penting tidak lengkap: ' . implode(', ', $missingFiles) . '. Folder dapodik_temp dipertahankan untuk pemeriksaan.');
        }

        try {
            $exitCode = Artisan::call('dapodik:sync', ['--path' => $dataDir]);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Data Dapodik berhasil ditarik, tetapi proses sinkronisasi database gagal dijalankan. Folder dapodik_temp dipertahankan. Pesan: ' . $e->getMessage());
        }

        if ($exitCode !== 0) {
            return back()->with('error', 'Data Dapodik berhasil ditarik, tetapi sinkronisasi database GAGAL. File hasil penarikan dipertahankan di: ' . $dataDir . '. Periksa storage/logs/laravel.log.');
        }

        AcademicPeriodService::selectLatestForCurrentSession();
        $this->markMasterDirty();

        try { File::deleteDirectory($dataDir); } catch (\Throwable $e) { report($e); }

        $message = 'Sinkronisasi Dapodik berhasil. Data: ' . implode(', ', $successLog) . '.';
        if (!empty($errorLog)) {
            $message .= ' Namun terdapat endpoint yang gagal: ' . implode(' | ', $errorLog);
        }
        $message .= ' Master & akun akan ter-push ke Cloud otomatis oleh daemon.';

        return back()->with('success', $message);
    }

    private function normalizeData(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) return $value;
        if ($value instanceof \Traversable) {
            $out = [];
            foreach ($value as $key => $item) $out[$key] = $this->normalizeData($item);
            return $out;
        }
        if (is_object($value)) return $this->normalizeData(get_object_vars($value));
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) $out[$key] = $this->normalizeData($item);
            return $out;
        }
        return (string) $value;
    }

    /**
     * ============================================================
     * EKSPOR MASTER PAGE
     * ============================================================
     */
    public function eksporMasterPage()
    {
        $stats = [
            'siswa' => DB::table('students')->count(),
            'gtk' => DB::table('teachers')->count(),
            'kelas' => DB::table('homerooms')->count(),
            'mapel' => DB::table('subjects')->count(),
        ];

        return view('admin.ekspor_master', compact('stats'));
    }

    /**
     * ============================================================
     * EKSPOR MASTER (file m2g - jalur Offline/flashdisk)
     * ============================================================
     */
    public function eksporMaster()
    {
        $data = app(\App\Services\MasterSnapshotService::class)->build();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $namaSekolah = str_replace(' ', '_', DB::table('schools')->value('nama') ?? 'Sekolah');
        $filename = 'm2g_' . $namaSekolah . '_' . date('Y_m_d') . '.json';

        return Response::make($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * ============================================================
     * IMPOR MASTER (admin - file m2g)
     *
     * [FIX-1] Panggilan akun diperbaiki: dulu menyebut method yang
     * TIDAK ADA (syncUsersFromMasterDataPublic) sehingga akun tidak
     * pernah dibuat - error tertelan report().
     * [FIX-6] Master berubah -> push_dirty dipasang (Cloud otomatis).
     * ============================================================
     */
    public function imporMaster(Request $request)
    {
        $request->validate(['json_file' => 'required|file|mimes:json,txt']);

        try {
            $result = app(\App\Services\Sync\MasterSyncService::class)
                ->applyFromM2gFile(
                    (string) $request->file('json_file')->getRealPath()
                );
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Impor Data Pokok (m2g) gagal: ' . $e->getMessage());
        }

        try {
            app(\App\Services\Sync\MasterSyncService::class)->syncUsersFromMaster();
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Data Pokok diterapkan, tetapi sinkronisasi akun login gagal: ' . $e->getMessage());
        }

        $this->markMasterDirty();

        $status = (string) ($result['status'] ?? 'applied');
        $version = (int) ($result['version'] ?? 0);

        $ringkasan = $status === 'up_to_date'
            ? 'master sudah terbaru (tidak ada perubahan diterapkan)'
            : 'diterapkan versi ' . ($version > 0 ? $version : '-') . ' (rekonsiliasi penuh)';

        return back()->with('success', '✅ Data Pokok (m2g) berhasil diimpor (' . $ringkasan . ')! '
            . 'Master & akun akan ter-push ke Cloud otomatis. Sistem eduXid siap digunakan.');
    }

    /**
     * ============================================================
     * EKSPOR MASTER API - sumber data jalur LAN
     * ============================================================
     */
    public function eksporMasterApi(Request $request)
    {
        $loginId = $request->header('X-Login-Id');

        $data = app(\App\Services\MasterSnapshotService::class)->build($loginId);

        return response()->json($data);
    }

    /**
     * ============================================================
     * TEMPLATE EXCEL
     * ============================================================
     */
    private function downloadExcelTemplate(array $headers, string $filename)
    {
        // V21R151: template XLSX tiga sheet; hanya DATA_IMPOR diproses importer.
        // Daftar nama tetap eksplisit agar tidak terjadi pembacaan path arbitrer.
        $allowed = [
            'Template_Impor_Sekolah.xlsx',
            'Template_Impor_Siswa.xlsx',
            'Template_Impor_Guru.xlsx',
            'Template_Impor_Kelas.xlsx',
            'Template_Impor_Pembelajaran.xlsx',
            'Template_Impor_Mapel.xlsx',
        ];
        if (!in_array($filename, $allowed, true)) {
            abort(404);
        }
        $path = resource_path('import-templates/' . $filename);
        if (!is_file($path)) {
            abort(503, 'Template resmi tidak ditemukan; periksa instalasi V21R151.');
        }
        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadTemplateSekolah()
    {
        return $this->downloadExcelTemplate(
            ['NPSN', 'Nama Sekolah', 'Bentuk Pendidikan', 'Status Sekolah', 'Alamat Jalan', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi'],
            'Template_Impor_Sekolah.xlsx'
        );
    }

    public function downloadTemplateSiswa()
    {
        return $this->downloadExcelTemplate(
            ['Nama Kelas', 'NIPD', 'Nama Lengkap', 'NISN', 'Jenis Kelamin (L/P)', 'Tempat Lahir', 'Tanggal Lahir (YYYY-MM-DD)', 'Agama', 'Alamat Jalan', 'Nama Ayah', 'Nama Ibu'],
            'Template_Impor_Siswa.xlsx'
        );
    }

    public function downloadTemplateGuru()
    {
        return $this->downloadExcelTemplate(
            ['NIK', 'Nama Lengkap', 'Gelar Depan', 'Gelar Belakang', 'Jenis Kelamin (L/P)', 'NUPTK', 'NIP', 'Jenis GTK', 'Status Kepegawaian', 'Pendidikan Terakhir'],
            'Template_Impor_Guru.xlsx'
        );
    }

    public function downloadTemplateKelas()
    {
        return $this->downloadExcelTemplate(
            ['Nama Kelas', 'Tingkat Pendidikan (cth: Kelas 10)', 'Semester ID (cth: 20241)', 'Jenis Rombel (cth: Kelas Reguler)', 'Kurikulum', 'NIP atau NIK Wali Kelas', 'Jurusan'],
            'Template_Impor_Kelas.xlsx'
        );
    }

    public function downloadTemplatePembelajaran()
    {
        return $this->downloadExcelTemplate(
            ['Nama Kelas', 'Nama Mata Pelajaran', 'NIP atau NIK Guru', 'Jam Mengajar Per Minggu'],
            'Template_Impor_Pembelajaran.xlsx'
        );
    }

    public function downloadTemplateMapel()
    {
        return $this->downloadExcelTemplate(
            ['Kode Mapel (Opsional)', 'Nama Mata Pelajaran'],
            'Template_Impor_Mapel.xlsx'
        );
    }

    /**
     * ============================================================
     * IMPOR SEKOLAH (EXCEL)
     * ============================================================
     */
    public function imporExcelSekolah(Request $request)
    {
        $request->validate(['excel_file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $data = Excel::toArray([], $request->file('excel_file'))[0];
            if (count($data) < 2) {
                return back()->with('error', 'File Excel kosong atau hanya berisi header.');
            }

            $expectedHeader = ['NPSN', 'Nama Sekolah', 'Bentuk Pendidikan', 'Status Sekolah', 'Alamat Jalan', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi'];
            if (array_diff($expectedHeader, $data[0]) || array_diff($data[0], $expectedHeader)) {
                return back()->with('error', 'Format Excel Tidak Valid. Unduh template terbaru (Template_Impor_Sekolah.xlsx).');
            }

            $count = 0;

            DB::transaction(function () use ($data, &$count) {
                foreach ($data as $key => $row) {
                    if ($key === 0) continue;

                    $npsn = trim((string) ($row[0] ?? ''));
                    $nama = trim((string) ($row[1] ?? ''));

                    if ($npsn === '' || $nama === '') {
                        throw new \Exception('Baris ' . ($key + 2) . ': NPSN dan Nama Sekolah wajib diisi.');
                    }

                    if (!ctype_digit($npsn) || strlen($npsn) < 8) {
                        throw new \Exception('Baris ' . ($key + 2) . ': NPSN harus angka minimal 8 digit.');
                    }

                    $schoolPayload = [
                        'nama'              => $nama,
                        'bentuk_pendidikan' => trim((string) ($row[2] ?? '')) ?: null,
                        'status_sekolah'    => trim((string) ($row[3] ?? '')) ?: null,
                        'alamat_jalan'      => trim((string) ($row[4] ?? '')) ?: null,
                        'kecamatan'         => trim((string) ($row[5] ?? '')) ?: null,
                        'kabupaten_kota'    => trim((string) ($row[6] ?? '')) ?: null,
                        'provinsi'          => trim((string) ($row[7] ?? '')) ?: null,
                        'updated_at'        => now(),
                    ];
                    // schools.dapodik_sekolah_id NOT NULL + UNIQUE pada migrasi dasar.
                    // Hanya sekolah BARU yang mendapat identitas sintetis Excel.
                    // Jangan pernah menimpa ID Dapodik milik sekolah yang sudah ada.
                    if (!DB::table('schools')->where('npsn', $npsn)->exists()) {
                        $schoolPayload['dapodik_sekolah_id'] = 'excel-' . Str::uuid();
                        $schoolPayload['created_at'] = now();
                    }
                    DB::table('schools')->updateOrInsert(['npsn' => $npsn], $schoolPayload);
                    $count++;
                }
            });

            $this->markMasterDirty();

            return back()->with(
                'success',
                "{$count} data sekolah berhasil diimpor. NPSN kini menjadi identitas sinkronisasi - "
                . 'Master & akun akan ter-push ke Cloud otomatis. Lanjutkan: Impor Guru → Impor Kelas → Impor Murid.'
            );

        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal impor Excel Sekolah. ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * IMPOR SISWA (EXCEL)
     *
     * [FIX-10] Lookup kelas kini sadar-semester - dulu bisa
     * meng-enroll siswa ke rombel semester lain yang kebetulan
     * bernama sama.
     * ============================================================
     */
    public function imporExcelSiswa(Request $request)
    {
        $request->validate(['excel_file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $data = Excel::toArray([], $request->file('excel_file'))[0];
            if (count($data) < 2) {
                return back()->with('error', 'File Excel kosong atau hanya berisi header.');
            }

            $expectedHeader = ['Nama Kelas', 'NIPD', 'Nama Lengkap', 'NISN', 'Jenis Kelamin (L/P)', 'Tempat Lahir', 'Tanggal Lahir (YYYY-MM-DD)', 'Agama', 'Alamat Jalan', 'Nama Ayah', 'Nama Ibu'];
            if (array_diff($expectedHeader, $data[0]) || array_diff($data[0], $expectedHeader)) {
                return back()->with('error', 'Format Excel Tidak Valid. Unduh template terbaru (harus memiliki kolom "Nama Kelas").');
            }

            $npsn = trim((string) DB::table('schools')->value('npsn'));
            if ($npsn === '') {
                return back()->with('error', 'NPSN sekolah belum ada. Impor Template_Impor_Sekolah.xlsx terlebih dahulu.');
            }

            $defaultSemesterId = DB::table('homerooms')->max('semester_id');

            $countSiswa = 0;
            $countEnroll = 0;
            $countUser = 0;
            $errors = [];

            DB::transaction(function () use ($data, $npsn, $defaultSemesterId, &$countSiswa, &$countEnroll, &$countUser, &$errors) {

                foreach ($data as $key => $row) {
                    if ($key === 0) continue;

                    $namaKelas = trim((string) ($row[0] ?? ''));
                    $nipd      = trim((string) ($row[1] ?? ''));
                    $nama      = trim((string) ($row[2] ?? ''));
                    $nisn      = trim((string) ($row[3] ?? ''));

                    if ($nama === '' || $nisn === '') {
                        $errors[] = 'Baris ' . ($key + 2) . ': Nama Lengkap dan NISN wajib diisi.';
                        continue;
                    }

                    $existing = DB::table('students')->where('nisn', $nisn)->first();

                    $studentPayload = [
                        'nama'          => $nama,
                        'nipd'          => $nipd !== '' ? $nipd : null,
                        'jenis_kelamin' => in_array(strtoupper((string) ($row[4] ?? '')), ['L', 'P']) ? strtoupper((string) $row[4]) : null,
                        'tempat_lahir'  => trim((string) ($row[5] ?? '')) ?: null,
                        'tanggal_lahir' => trim((string) ($row[6] ?? '')) ?: null,
                        'agama'         => trim((string) ($row[7] ?? '')) ?: null,
                        'alamat_jalan'  => trim((string) ($row[8] ?? '')) ?: null,
                        'nama_ayah'     => trim((string) ($row[9] ?? '')) ?: null,
                        'nama_ibu'      => trim((string) ($row[10] ?? '')) ?: null,
                        'updated_at'    => now(),
                    ];

                    if ($existing) {
                        DB::table('students')->where('id', $existing->id)->update($studentPayload);
                        $studentId = (int) $existing->id;
                    } else {
                        $studentPayload['nisn'] = $nisn;
                        $studentPayload['dapodik_peserta_didik_id'] = 'excel-' . Str::uuid();
                        $studentPayload['created_at'] = now();
                        $studentId = (int) DB::table('students')->insertGetId($studentPayload);
                    }
                    $countSiswa++;

                    if ($namaKelas !== '') {
                        /*
                         * [FIX-10] Sadar-semester: cocokkan kelas HANYA
                         * dalam semester aktif impor.
                         */
                        $homeroomQuery = DB::table('homerooms')->where('nama', $namaKelas);

                        if ($defaultSemesterId !== null) {
                            $homeroomQuery->where('semester_id', $defaultSemesterId);
                        } else {
                            $homeroomQuery->whereNull('semester_id');
                        }

                        $homeroom = $homeroomQuery->first();

                        if (!$homeroom) {
                            $homeroomId = (int) DB::table('homerooms')->insertGetId([
                                'nama'               => $namaKelas,
                                'dapodik_rombel_id'  => 'excel-' . Str::uuid(),
                                'tingkat_pendidikan' => null,
                                'semester_id'        => $defaultSemesterId,
                                'jenis_rombel'       => 'Kelas Reguler',
                                'kurikulum'          => null,
                                'jurusan'            => null,
                                'created_at'         => now(),
                                'updated_at'         => now(),
                            ]);
                        } else {
                            $homeroomId = (int) $homeroom->id;
                        }

                        $hasEnroll = DB::table('enrollments')
                            ->where('student_id', $studentId)
                            ->where('homeroom_id', $homeroomId)
                            ->exists();

                        if (!$hasEnroll) {
                            DB::table('enrollments')->insert([
                                'dapodik_anggota_rombel_id' => 'excel-' . Str::uuid(),
                                'student_id'  => $studentId,
                                'homeroom_id' => $homeroomId,
                                'jenis_pendaftaran' => 'Siswa Aktif',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                        $countEnroll++;
                    }

                    $userExists = DB::table('users')->where('login_id', $nisn)->first();

                    if (!$userExists) {
                        \App\Services\Security\InitialCredentialVault::createForNewUser([
                            'name'        => $nama,
                            'login_id'    => $nisn,
                            'email'       => $nisn . '@eduxid.local',
                            'role'        => 'siswa',
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                        $countUser++;
                    }
                }

                if ($errors !== []) {
                    throw new \Exception(implode(' | ', $errors));
                }
            });

            $this->markMasterDirty();

            $msg = "{$countSiswa} murid berhasil diimpor, {$countEnroll} enrollment rombel diproses, {$countUser} akun login dibuat.";
            if ($countUser > 0) {
                $msg .= ' Password awal murid dibuat acak; ambil satu kali pada Pengaturan > Aktivasi Akun.';
            }
            $msg .= ' Master & akun akan ter-push ke Cloud otomatis.';

            return back()->with('success', $msg);

        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal impor Excel Murid. ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * IMPOR GTK (EXCEL)
     * ============================================================
     */
    public function imporExcelGuru(Request $request)
    {
        $request->validate(['excel_file' => 'required|file|mimes:xlsx,xls,csv']);

        try {
            $data = Excel::toArray([], $request->file('excel_file'))[0];
            if (count($data) < 2) {
                return back()->with('error', 'File Excel kosong atau hanya berisi header.');
            }

            $expectedHeader = ['NIK', 'Nama Lengkap', 'Gelar Depan', 'Gelar Belakang', 'Jenis Kelamin (L/P)', 'NUPTK', 'NIP', 'Jenis GTK', 'Status Kepegawaian', 'Pendidikan Terakhir'];
            if (array_diff($expectedHeader, $data[0]) || array_diff($data[0], $expectedHeader)) {
                return back()->with('error', 'Format Excel Tidak Valid.');
            }

            $npsn = trim((string) DB::table('schools')->value('npsn'));
            if ($npsn === '') {
                return back()->with('error', 'NPSN sekolah belum ada. Impor Template_Impor_Sekolah.xlsx terlebih dahulu.');
            }

            $countGuru = 0;
            $countUser = 0;
            $errors = [];

            DB::transaction(function () use ($data, $npsn, &$countGuru, &$countUser, &$errors) {

                foreach ($data as $key => $row) {
                    if ($key === 0) continue;

                    $nik   = trim((string) ($row[0] ?? ''));
                    $nama  = trim((string) ($row[1] ?? ''));
                    $nuptk = trim((string) ($row[5] ?? ''));
                    $nip   = trim((string) ($row[6] ?? ''));

                    if ($nik === '' || $nama === '') {
                        $errors[] = 'Baris ' . ($key + 2) . ': NIK dan Nama Lengkap wajib diisi.';
                        continue;
                    }

                    $existing = DB::table('teachers')->where('nik', $nik)->first();

                    $gelarDepan    = trim((string) ($row[2] ?? ''));
                    $gelarBelakang = trim((string) ($row[3] ?? ''));

                    $teacherPayload = [
                        'nama'          => $nama,
                        'gelar_depan'   => $gelarDepan !== '' ? $gelarDepan : null,
                        'gelar_belakang'=> $gelarBelakang !== '' ? $gelarBelakang : null,
                        'jenis_kelamin' => in_array(strtoupper((string) ($row[4] ?? '')), ['L', 'P']) ? strtoupper((string) $row[4]) : null,
                        'nuptk'         => $nuptk !== '' ? $nuptk : null,
                        'nip'           => $nip !== '' ? $nip : null,
                        'jenis_ptk'     => trim((string) ($row[7] ?? '')) ?: null,
                        'status_kepegawaian' => trim((string) ($row[8] ?? '')) ?: null,
                        'pendidikan_terakhir'=> trim((string) ($row[9] ?? '')) ?: null,
                        'updated_at'    => now(),
                    ];

                    if ($existing) {
                        DB::table('teachers')->where('id', $existing->id)->update($teacherPayload);
                        $teacherId = (int) $existing->id;
                    } else {
                        $teacherPayload['nik'] = $nik;
                        $teacherPayload['dapodik_ptk_id'] = 'excel-' . Str::uuid();
                        $teacherPayload['created_at'] = now();
                        $teacherId = (int) DB::table('teachers')->insertGetId($teacherPayload);
                    }
                    $countGuru++;

                    $loginId = $nip !== '' ? $nip : ($nik !== '' ? $nik : $nuptk);

                    if ($loginId === '') {
                        continue;
                    }

                    $userExists = DB::table('users')->where('login_id', $loginId)->first();

                    if (!$userExists) {
                        \App\Services\Security\InitialCredentialVault::createForNewUser([
                            'name'        => $nama,
                            'login_id'    => $loginId,
                            'email'       => $loginId . '@eduxid.local',
                            'role'        => str_contains(strtolower((string) ($row[7] ?? '')), 'guru') ? 'guru' : 'tendik',
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                        $countUser++;
                    }
                }

                if ($errors !== []) {
                    throw new \Exception(implode(' | ', $errors));
                }
            });

            $this->markMasterDirty();

            $msg = "{$countGuru} GTK berhasil diimpor, {$countUser} akun login dibuat.";
            if ($countUser > 0) {
                $msg .= ' Password awal GTK dibuat acak; ambil satu kali pada Pengaturan > Aktivasi Akun.';
            }
            $msg .= ' Master & akun akan ter-push ke Cloud otomatis.';

            return back()->with('success', $msg);

        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal impor Excel GTK. ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * IMPOR EXCEL KELAS
     *
     * [FIX-8] Kunci impor kini (nama + semester) - dulu kelas
     * semester baru menimpa semester_id kelas lama.
     * [FIX-7] Baris Dapodik yang cocok TIDAK lagi kehilangan
     * dapodik_rombel_id (dulu ditimpa excel-uuid -> sync Dapodik
     * berikutnya menduplikasi rombel).
     * ============================================================
     */
    public function imporExcelKelas(Request $request)
    {
        $request->validate(['excel_file' => 'required|file|mimes:xlsx,xls,csv']);
        try {
            $data = Excel::toArray([], $request->file('excel_file'))[0];
            if (count($data) < 2) return back()->with('error', 'File Excel Kosong atau hanya berisi Header.');

            $expectedHeader = ['Nama Kelas', 'Tingkat Pendidikan (cth: Kelas 10)', 'Semester ID (cth: 20241)', 'Jenis Rombel (cth: Kelas Reguler)', 'Kurikulum', 'NIP atau NIK Wali Kelas', 'Jurusan'];
            if (array_diff($expectedHeader, $data[0]) || array_diff($data[0], $expectedHeader)) return back()->with('error', 'Format Excel Tidak Valid.');

            $count = 0;
            DB::transaction(function () use ($data, &$count) {
                foreach ($data as $key => $row) {
                    if ($key === 0) continue;
                    if (empty($row[0])) throw new \Exception('Error pada baris ' . ($key + 2) . ': Nama Kelas tidak boleh kosong.');

                    $semesterId = isset($row[2]) && trim((string) $row[2]) !== ''
                        ? (int) $row[2]
                        : null;

                    $kunciGuru = trim((string) ($row[5] ?? ''));
                    $teacher = null;

                    if ($kunciGuru !== '') {
                        $teacher = DB::table('teachers')->where(function ($query) use ($kunciGuru) {
                            $query->where('nip', $kunciGuru)->orWhere('nik', $kunciGuru);
                        })->first();
                    }

                    $homeroomQuery = DB::table('homerooms')->where('nama', $row[0]);

                    if ($semesterId === null) {
                        $homeroomQuery->whereNull('semester_id');
                    } else {
                        $homeroomQuery->where('semester_id', $semesterId);
                    }

                    $existing = $homeroomQuery->first();

                    if ($existing) {
                        /*
                         * [FIX-7] Update TANPA menyentuh dapodik_rombel_id.
                         */
                        DB::table('homerooms')->where('id', $existing->id)->update([
                            'tingkat_pendidikan' => $row[1] ?? null,
                            'jenis_rombel' => $row[3] ?? 'Kelas Reguler',
                            'kurikulum' => $row[4] ?? null,
                            'homeroom_teacher_id' => $teacher->id ?? null,
                            'jurusan' => $row[6] ?? null,
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('homerooms')->insert([
                            'nama' => $row[0],
                            'dapodik_rombel_id' => 'excel-' . Str::uuid(),
                            'tingkat_pendidikan' => $row[1] ?? null,
                            'semester_id' => $semesterId,
                            'jenis_rombel' => $row[3] ?? 'Kelas Reguler',
                            'kurikulum' => $row[4] ?? null,
                            'homeroom_teacher_id' => $teacher->id ?? null,
                            'jurusan' => $row[6] ?? null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]);
                    }
                    $count++;
                }
            });

            $this->markMasterDirty();

            return back()->with('success', "{$count} data Kelas berhasil diimpor dari Excel! Master & akun akan ter-push ke Cloud otomatis.");
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal impor Excel Kelas. ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * IMPOR EXCEL PEMBELAJARAN
     *
     * [FIX-7] Baris Dapodik yang cocok TIDAK lagi kehilangan
     * dapodik_pembelajaran_id.
     * ============================================================
     */
    public function imporExcelPembelajaran(Request $request)
    {
        $request->validate(['excel_file' => 'required|file|mimes:xlsx,xls,csv']);
        try {
            $data = Excel::toArray([], $request->file('excel_file'))[0];
            if (count($data) < 2) return back()->with('error', 'File Excel Kosong atau hanya berisi Header.');

            $expectedHeader = ['Nama Kelas', 'Nama Mata Pelajaran', 'NIP atau NIK Guru', 'Jam Mengajar Per Minggu'];
            if (array_diff($expectedHeader, $data[0]) || array_diff($data[0], $expectedHeader)) return back()->with('error', 'Format Excel Tidak Valid.');

            $count = 0;
            DB::transaction(function () use ($data, &$count) {
                foreach ($data as $key => $row) {
                    if ($key === 0) continue;
                    if (empty($row[0]) || empty($row[1]) || empty($row[2])) throw new \Exception('Error pada baris ' . ($key + 2) . ': Kolom Kelas, Mapel, dan NIP/NIK tidak boleh kosong.');

                    $homeroom = DB::table('homerooms')->where('nama', $row[0])->first();
                    $subject = DB::table('subjects')->where('nama_mata_pelajaran', $row[1])->first();
                    $teacher = DB::table('teachers')->where(function ($query) use ($row) {
                        $query->where('nip', $row[2])->orWhere('nik', $row[2]);
                    })->first();

                    if (!$homeroom || !$subject || !$teacher) {
                        $missing = [];
                        if (!$homeroom) $missing[] = "Kelas '{$row[0]}' tidak ditemukan";
                        if (!$subject) $missing[] = "Mapel '{$row[1]}' tidak ditemukan";
                        if (!$teacher) $missing[] = "Guru dengan NIP/NIK '{$row[2]}' tidak ditemukan";
                        throw new \Exception('Error pada baris ' . ($key + 2) . ': ' . implode(', ', $missing));
                    }

                    $existing = DB::table('teaching_assignments')
                        ->where('homeroom_id', $homeroom->id)
                        ->where('subject_id', $subject->id)
                        ->where('teacher_id', $teacher->id)
                        ->first();

                    if ($existing) {
                        /*
                         * [FIX-7] Update TANPA menyentuh dapodik_pembelajaran_id.
                         */
                        DB::table('teaching_assignments')->where('id', $existing->id)->update([
                            'jam_mengajar_per_minggu' => $row[3] ?? null,
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('teaching_assignments')->insert([
                            'dapodik_pembelajaran_id' => 'excel-' . Str::uuid(),
                            'homeroom_id' => $homeroom->id,
                            'subject_id' => $subject->id,
                            'teacher_id' => $teacher->id,
                            'jam_mengajar_per_minggu' => $row[3] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $count++;
                }
            });

            $this->markMasterDirty();

            return back()->with('success', "{$count} data Pembelajaran berhasil diimpor dari Excel! Master & akun akan ter-push ke Cloud otomatis.");
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal impor Excel Pembelajaran. ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * IMPOR EXCEL MAPEL
     *
     * [FIX-9] Tidak lagi menimpa dapodik_subject_id milik baris
     * Dapodik (dulu -> sync Dapodik berikutnya menduplikasi mapel).
     * ============================================================
     */
    public function imporExcelMapel(Request $request)
    {
        $request->validate(['excel_file' => 'required|file|mimes:xlsx,xls,csv']);
        try {
            $data = Excel::toArray([], $request->file('excel_file'))[0];
            if (count($data) < 2) return back()->with('error', 'File Excel Kosong atau hanya berisi Header.');

            $expectedHeader = ['Kode Mapel (Opsional)', 'Nama Mata Pelajaran'];
            if (array_diff($expectedHeader, $data[0]) || array_diff($data[0], $expectedHeader)) return back()->with('error', 'Format Excel Tidak Valid.');

            $count = 0;
            DB::transaction(function () use ($data, &$count) {
                foreach ($data as $key => $row) {
                    if ($key === 0) continue;
                    if (empty($row[1])) throw new \Exception('Error pada baris ' . ($key + 2) . ': Nama Mata Pelajaran tidak boleh kosong.');

                    $kode = !empty($row[0]) ? (string) $row[0] : null;

                    $existing = DB::table('subjects')
                        ->where('nama_mata_pelajaran', $row[1])
                        ->first();

                    if ($existing) {
                        $currentId = (string) ($existing->dapodik_subject_id ?? '');
                        $payload = ['updated_at' => now()];

                        /*
                         * [FIX-9] dapodik_subject_id hanya diisi bila
                         * baris belum punya kunci Dapodik yang sah.
                         */
                        if ($kode !== null && ($currentId === '' || str_starts_with($currentId, 'excel-'))) {
                            $payload['dapodik_subject_id'] = $kode;
                        } elseif ($kode === null && $currentId === '') {
                            $payload['dapodik_subject_id'] = 'excel-' . Str::uuid();
                        }

                        DB::table('subjects')->where('id', $existing->id)->update($payload);
                    } else {
                        DB::table('subjects')->insert([
                            'nama_mata_pelajaran' => $row[1],
                            'dapodik_subject_id'  => $kode ?? ('excel-' . Str::uuid()),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $count++;
                }
            });

            $this->markMasterDirty();

            return back()->with('success', "{$count} data Mata Pelajaran berhasil diimpor dari Excel! Master & akun akan ter-push ke Cloud otomatis.");
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Gagal impor Excel Mapel. ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * UPLOAD FOTO SISWA (ZIP)
     * ============================================================
     */
    public function uploadFotoZip(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'zip_file' => 'required|file|mimes:zip|max:102400',
        ], [
            'zip_file.max' => 'Ukuran ZIP foto murid maksimal 100 MB.',
        ]);

        try {
            $result = app(\App\Services\Students\StudentPhotoZipImporter::class)
                ->import((string) $request->file('zip_file')->getRealPath());

            $message = 'Upload ZIP foto siswa: ' . $result['saved'] . ' foto berhasil, '
                . $result['skipped'] . ' dilewati dari ' . $result['total'] . ' file.';
            $status = $result['saved'] > 0 ? 'success' : 'error';

            return back()->with($status, $message)->with('foto_zip_report', $result);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Upload foto siswa gagal: ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * CLEAN UP SYSTEM
     *
     * [FIX-4] File -wal/-shm MILIK DATABASE HIDUP dan TIDAK PERNAH
     * lagi dihapus di sini - menghapusnya saat server/daemon
     * berjalan dapat menghilangkan transaksi yang sudah terkommit.
     * Hanya sisa cadangan lama (*.old, *.new) yang dibersihkan.
     * ============================================================
     */
    public function cleanUpSystem(Request $request)
    {
        $deletedFiles = 0;
        $freedSpace = 0;

        $directoriesToClean = [
            storage_path('app/restore_queue'),
            storage_path('app/backups'),
            storage_path('app/dapodik_temp'),
        ];

        foreach ($directoriesToClean as $dir) {
            if (File::isDirectory($dir)) {
                $files = File::allFiles($dir);
                foreach ($files as $file) {
                    $size = $file->getSize();
                    if (File::delete($file->getRealPath())) {
                        $deletedFiles++;
                        $freedSpace += $size;
                    }
                }
            }
        }

        $databasePath = config('database.connections.sqlite.database');
        if ($databasePath !== ':memory:') {
            $dbDir = dirname($databasePath);

            $junkDbFiles = array_merge(
                glob($dbDir . '/*.old') ?: [],
                glob($dbDir . '/*.new') ?: []
            );

            foreach ($junkDbFiles as $junkFile) {
                if (!file_exists($junkFile)) continue;
                $size = filesize($junkFile);
                if (@unlink($junkFile)) {
                    $deletedFiles++;
                    $freedSpace += $size;
                }
            }
        }

        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath) && filesize($logPath) > 5 * 1024 * 1024) {
            $freedSpace += filesize($logPath);
            file_put_contents($logPath, '');
            $deletedFiles++;
        }

        try { Artisan::call('optimize:clear'); } catch (\Throwable $e) {}

        $freedSpaceMB = number_format($freedSpace / (1024 * 1024), 2);
        $message = $deletedFiles > 0 ? "Optimasi berhasil! {$deletedFiles} file sampah dihapus. Membebaskan {$freedSpaceMB} MB ruang penyimpanan." : "Sistem sudah bersih, tidak ada file sampah yang ditemukan.";

        return back()->with('success', $message);
    }

    /**
     * ============================================================
     * RESET TOTAL DATA
     *
     * [FIX-2] Registry sinkron baru kini ikut dibersihkan:
     *   - master::applied_* -> tanpa ini, tarik master setelah reset
     *     dijawab "up_to_date" pada DB KOSONG (hash lama masih
     *     terekam) dan data tidak pernah terisi ulang.
     *   - wpa::*            -> guard basi membuat event replay
     *     dianggap stale.
     * [FIX-3] push_dirty DIHAPUS -> tanpa ini, flag yang tertinggal
     * membuat daemon mem-push master KOSONG hasil reset ke Cloud.
     * [FIX-11] sync_dead_letters ikut dibersihkan.
     * ============================================================
     */
    public function resetTotalData(Request $request)
    {
        $request->validate([
            'konfirmasi' => 'required|string'
        ]);

        if (strtoupper(trim($request->konfirmasi)) !== 'HAPUS SEMUA DATA') {
            return back()->with('error', 'Teks konfirmasi salah. Ketik persis: HAPUS SEMUA DATA');
        }

        $tables = [
            // Hasil penilaian & absensi
            'assessment_scores',
            'final_grades',
            'attendances',
            'attitude_logs',
            'bk_logs',
            'ekskul_grades',

            // CBT
            'student_answers',
            'exam_sessions',
            'exam_questions',
            'exams',
            'question_banks',

            // LMS (kelas, materi, tugas, submission)
            'lms_assignment_submissions',
            'lms_assignments',
            'lms_materials',
            'lms_topics',
            'lms_courses',

            // Surat & kokurikuler
            'surat_records',
            'kokurikulers',

            // Sinkronisasi (outbox, device, cursor, dead-letter)
            'lms_sync_records',
            'sync_dead_letters',
            'sync_cursors',
            'sync_devices',

            // Data pokok (paling akhir)
            'enrollments',
            'teaching_assignments',
            'homerooms',
            'subjects',
            'students',
            'teachers',
            'schools',
        ];

        try {
            DB::statement('PRAGMA foreign_keys = OFF;');

            foreach ($tables as $table) {
                if (\Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            /*
             * Akun user: hapus semua KECUALI akun setup bawaan
             * (admin, guru, tendik) agar admin tetap bisa login.
             */
            DB::table('users')
                ->whereNotIn('login_id', ['admin', 'guru', 'tendik'])
                ->delete();

            /*
             * [FIX-2/3] Registry & guard sistem sinkron.
             * eduxid_settings (kunci Cloud) SENGAJA dipertahankan.
             */
            if (\Schema::hasTable('app_settings')) {
                DB::table('app_settings')->where('key', 'like', 'master::applied%')->delete();
                DB::table('app_settings')->where('key', 'like', 'wpa::%')->delete();
                DB::table('app_settings')->where('key', 'master::push_dirty')->delete();
                DB::table('app_settings')->where('key', 'daemon::heartbeat')->delete();
            }

            DB::statement('PRAGMA foreign_keys = ON;');

            try { Artisan::call('optimize:clear'); } catch (\Throwable $e) {}

            return back()->with(
                'success',
                '✅ Semua data sistem berhasil dihapus! Akun setup (Admin, Guru, Tendik) dan koneksi ke eduXid Cloud dipertahankan. '
                . 'LANGKAH BERIKUTNYA: (1) Impor Dapodik atau Excel - master & akun akan ter-push ke Cloud OTOMATIS oleh daemon, '
                . '(2) Pairing ulang laptop guru dengan Kode Guru baru, '
                . '(3) Laptop guru menarik Data Pokok (Cloud/LAN/m2g) - tarik pertama TIDAK akan tertahan guard karena registry sudah dibersihkan.'
            );

        } catch (\Throwable $e) {
            DB::statement('PRAGMA foreign_keys = ON;');

            return back()->with('error', 'Gagal menghapus data: ' . $e->getMessage());
        }
    }
}