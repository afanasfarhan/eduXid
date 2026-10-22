<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesTeacherWorkData;
use App\Services\UpdateClientService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PengaturanController extends Controller
{
    use ManagesTeacherWorkData;

    /*
     * [REWRITE-06.3-SPLIT] Controller ringan: konfigurasi LAN,
     * tarik master (LAN/Cloud/m2g), push Cloud, purge admin, foto ZIP.
     * Jalur nilai guru pindah ke trait ManagesTeacherWorkData -
     * semua method publik tetap tersedia (route/view tersentuh).
     */

    public function indexGuru(): View
    {
        $currentServerIp = trim(
            (string) DB::table('app_settings')->where('key', 'server_ip')->value('value')
        );

        $user = auth()->user();
        $cloudPaired = $user instanceof \App\Models\User
            ? app(\App\Services\Sync\GtkPairedDeviceV21::class)->isPaired($user)
            : null;

        return view('guru.pengaturan', [
            'currentServerIp' => $currentServerIp,
            'cloudPaired' => $cloudPaired,
        ]);
    }

    private function firstNonEmpty(...$values): ?string
    {
        foreach ($values as $value) {
            $trimmed = trim((string) $value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /* ============================================================
     * [ADMIN-PURGE]
     * ============================================================ */

    private function purgeLocalAdminAccounts(): int
    {
        $serverIp = DB::table('app_settings')->where('key', 'server_ip')->value('value');

        if (!$serverIp) {
            Log::warning('[EDUXID SECURITY] Purge admin DILEWATI: server_ip belum diatur - mesin terindikasi sebagai Server.', [
                'triggered_by_user_id' => Auth::id(),
                'triggered_by_login_id' => Auth::user()?->login_id,
                'occurred_at' => now()->toDateTimeString(),
            ]);

            return 0;
        }

        $currentUser = Auth::user();
        $currentRole = strtolower(trim((string) ($currentUser?->role ?? '')));

        if ($currentRole === '' || $currentRole === 'admin') {
            Log::warning('[EDUXID SECURITY] Purge admin DILEWATI: sesi saat ini masih menggunakan akun admin.', [
                'triggered_by_user_id' => Auth::id(),
                'triggered_by_login_id' => $currentUser?->login_id,
                'occurred_at' => now()->toDateTimeString(),
            ]);

            return 0;
        }

        $query = DB::table('users')->where('role', 'admin');

        if (Auth::id() !== null) {
            $query->where('id', '<>', Auth::id());
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            Log::warning('[EDUXID SECURITY] Akun admin lokal dihapus setelah penarikan Data Pokok.', [
                'deleted_admin_count' => $deleted,
                'triggered_by_user_id' => Auth::id(),
                'triggered_by_login_id' => Auth::user()?->login_id,
                'occurred_at' => now()->toDateTimeString(),
            ]);
        }

        return $deleted;
    }

    private function masterPullSuccessMessage(int $purgedAdmins): string
    {
        $message = '✅ Data Pokok berhasil ditarik! Silakan log out dan login kembali '
            . 'menggunakan username NIP/NIK dan password NPSN Sekolah. '
            . 'Segera ganti password Anda melalui menu Profil.';

        if ($purgedAdmins > 0) {
            $message .= ' Sebagai langkah keamanan, '
                . $purgedAdmins
                . ' akun admin lokal pada laptop ini telah dihapus.';
        }

        return $message;
    }

    /**
     * Buat/lengkapi akun login dari master yang diterapkan (idempoten).
     */
    private function syncUsersFromMasterData(): void
    {
        $npsn = DB::table('schools')->value('npsn');

        if (!$npsn) {
            return;
        }

        $defaultPassword = Hash::make($npsn);

        $gurus = DB::table('teachers')->get();

        foreach ($gurus as $guru) {
            $loginId = $this->firstNonEmpty(
                $guru->nip ?? null,
                $guru->nik ?? null,
                $guru->nuptk ?? null
            );

            if (!$loginId) {
                continue;
            }

            if (DB::table('users')->where('login_id', $loginId)->exists()) {
                continue;
            }

            $role = 'guru';
            $jenisPtk = strtolower($guru->jenis_ptk ?? '');
            $jabatanPtk = strtolower($guru->jabatan_ptk ?? '');

            if (strpos($jenisPtk, 'bk') !== false || strpos($jenisPtk, 'konselor') !== false) {
                $role = 'guru_bk';
            } elseif (strpos($jabatanPtk, 'kepala sekolah') !== false) {
                $role = 'kepala_sekolah';
            } elseif (
                strpos($jenisPtk, 'tendik') !== false
                || strpos($jenisPtk, 'tenaga kependidikan') !== false
                || strpos($jabatanPtk, 'tata usaha') !== false
                || strpos($jabatanPtk, 'staf') !== false
            ) {
                $role = 'tendik';
            }

            DB::table('users')->insert([
                'name' => $guru->nama,
                'login_id' => $loginId,
                'email' => $loginId . '@eduxid.id',
                'password' => $defaultPassword,
                'role' => $role,
                'must_change_password' => true,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $siswas = DB::table('students')->get();

        foreach ($siswas as $siswa) {
            if (!$siswa->nisn) {
                continue;
            }

            if (DB::table('users')->where('login_id', $siswa->nisn)->exists()) {
                continue;
            }

            DB::table('users')->insert([
                'name' => $siswa->nama,
                'login_id' => $siswa->nisn,
                'email' => $siswa->nisn . '@eduxid.id',
                'password' => $defaultPassword,
                'role' => 'siswa',
                'must_change_password' => true,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Sinkronisasi akun login dari master melalui satu pintu.
     *
     * MasterSyncService menjadi source of truth bila method tersedia.
     * Fallback lokal dipertahankan untuk kompatibilitas instalasi lama.
     */
    private function syncUsersFromAppliedMaster(): void
    {
        $service = app(\App\Services\Sync\MasterSyncService::class);

        if (method_exists($service, 'syncUsersFromMaster')) {
            $service->syncUsersFromMaster();
            return;
        }

        $this->syncUsersFromMasterData();
    }

    /* ============================================================
     * MASTER - M2G (GURU) & LAN (GURU)
     * ============================================================ */

    /**
     * [05.3] File m2g lewat MasterSyncService (checksum, versi,
     * NPSN, rekonsiliasi).
     */
    public function imporMasterGuru(Request $request): RedirectResponse
    {
        $request->validate([
            'json_file' => 'required|file|mimes:json,txt',
        ]);

        try {
            $result = app(\App\Services\Sync\MasterSyncService::class)
                ->applyFromM2gFile(
                    (string) $request->file('json_file')->getRealPath()
                );
        } catch (\Throwable $e) {
            return back()->with('error', 'Tarik Data Pokok (file m2g) gagal: ' . $e->getMessage());
        }

        try {
            $this->syncUsersFromAppliedMaster();
        } catch (\Throwable $e) {
            report($e);
            return back()->with('warning', 'Master diterapkan, tetapi pembuatan akun login gagal: ' . $e->getMessage());
        }

        $purgedAdmins = 0;

        try {
            DB::transaction(function () use (&$purgedAdmins) {
                $purgedAdmins = $this->purgeLocalAdminAccounts();
            });
        } catch (\Throwable $e) {
            report($e);
        }

        $status = (string) ($result['status'] ?? 'applied');
        $version = (int) ($result['version'] ?? 0);

        $ringkasan = $status === 'up_to_date'
            ? 'master sudah terbaru (tidak ada perubahan diterapkan)'
            : 'diterapkan versi ' . ($version > 0 ? $version : '-') . ' (rekonsiliasi penuh)';

        return redirect()
            ->route('dashboard')
            ->with('success', '✅ Data Pokok berhasil ditarik dari FILE m2g (' . $ringkasan . '). '
                . $this->masterPullSuccessMessage($purgedAdmins)
                . ' Silakan logout dan login kembali.');
    }

    public function updateServerIp(Request $request): RedirectResponse
    {
        $request->validate(
            [
                'server_ip' => 'required|ip|not_in:0.0.0.0',
            ],
            [
                'server_ip.not_in' => '0.0.0.0 adalah IP Server, bukan IP tujuan. Gunakan IP Address asli komputer Admin (cth: 192.168.1.5 atau 127.0.0.1).',
            ]
        );

        $existing = DB::table('app_settings')->where('key', 'server_ip')->first();

        if ($existing) {
            DB::table('app_settings')
                ->where('key', 'server_ip')
                ->update([
                    'value' => $request->server_ip,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('app_settings')->insert([
                'key' => 'server_ip',
                'value' => $request->server_ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return back()->with('success', 'IP Server berhasil disimpan. Silahkan melakukan Tarik atau Kirim Data ke komputer Admin (Server).');
    }

    private function checkForSystemUpdates(): ?string
    {
        try {
            $updateClient = app(UpdateClientService::class);

            if ($updateClient->ping()) {
                $versionInfo = $updateClient->checkVersion();

                if ($versionInfo['update_available']) {
                    return $versionInfo['latest_version'];
                }
            }
        } catch (\Throwable) {
            // Best-effort.
        }

        return null;
    }

    /**
     * [05.2] Tarik master via LAN - MasterSyncService.
     */
    public function pullMasterDataApi(): RedirectResponse
    {
        $newVersion = $this->checkForSystemUpdates();

        if ($newVersion) {
            return back()->with('warning', '⚠️ Pembaruan aplikasi tersedia (v' . $newVersion . '). Silakan buka menu "Update / Patch" di halaman ini untuk memasang pembaruan sebelum menarik data.');
        }

        $serverIp = trim((string) DB::table('app_settings')->where('key', 'server_ip')->value('value'));
        $loginId = auth()->user()?->login_id;

        if ($serverIp === '') {
            return back()->with('error', 'IP Server belum diatur di menu Pengaturan.');
        }

        try {
            $result = app(\App\Services\Sync\MasterSyncService::class)
                ->applyFromLan($serverIp, $loginId !== null ? (string) $loginId : null);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return back()->with('error', 'Tidak dapat terhubung ke server (Koneksi Ditolak/Timeout). Cek Firewall Admin atau pastikan IP benar.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Tarik Data Pokok gagal: ' . $e->getMessage());
        }

        try {
            $this->syncUsersFromAppliedMaster();
        } catch (\Throwable $e) {
            report($e);
            return back()->with('warning', 'Master diterapkan, tetapi pembuatan akun login gagal: ' . $e->getMessage());
        }

        $purgedAdmins = 0;

        try {
            DB::transaction(function () use (&$purgedAdmins) {
                $purgedAdmins = $this->purgeLocalAdminAccounts();
            });
        } catch (\Throwable $e) {
            report($e);
        }

        $status = (string) ($result['status'] ?? 'applied');
        $version = (int) ($result['version'] ?? 0);

        $ringkasan = $status === 'up_to_date'
            ? 'master sudah terbaru (tidak ada perubahan diterapkan)'
            : 'diterapkan versi ' . ($version > 0 ? $version : '-') . ' (rekonsiliasi penuh)';

        return back()->with('success', '✅ Data Pokok berhasil ditarik dari Server (' . $ringkasan . '). '
            . $this->masterPullSuccessMessage($purgedAdmins));
    }

    /**
     * [05.3] Tarik master VIA CLOUD - MasterSyncService.
     */
    public function pullMasterDataCloud(Request $request)
    {
        try {
            $result = app(\App\Services\Sync\MasterSyncService::class)->applyFromCloud();
        } catch (\Throwable $e) {
            $errorMessage = 'Tarik via Cloud gagal: ' . $e->getMessage()
                . ' - jalur LAN (Tarik Data Pokok) tetap dapat digunakan.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $errorMessage], 422);
            }

            return back()->with('error', $errorMessage);
        }

        try {
            $this->syncUsersFromAppliedMaster();
        } catch (\Throwable $e) {
            report($e);

            $msg = 'Master diterapkan, tetapi pembuatan akun login gagal: ' . $e->getMessage();

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return back()->with('warning', $msg);
        }

        $purgedAdmins = 0;

        try {
            DB::transaction(function () use (&$purgedAdmins) {
                $purgedAdmins = $this->purgeLocalAdminAccounts();
            });
        } catch (\Throwable $e) {
            report($e);
        }

        $status = (string) ($result['status'] ?? 'applied');
        $version = (int) ($result['version'] ?? 0);

        $ringkasan = $status === 'up_to_date'
            ? 'master sudah terbaru (tidak ada perubahan diterapkan)'
            : 'diterapkan versi ' . ($version > 0 ? $version : '-') . ' (rekonsiliasi penuh)';

        $message = '✅ Data Pokok berhasil ditarik VIA CLOUD (' . $ringkasan . '). '
            . $this->masterPullSuccessMessage($purgedAdmins);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    /**
     * [CLOUD-MASTER] Admin unggah snapshot master ke Cloud.
     */
    public function pushMasterDataCloud(Request $request): RedirectResponse
    {
        try {
            ini_set('memory_limit', '-1');

            $result = app(\App\Services\Sync\CloudMasterDataService::class)->pushSnapshot();

            $counts = $result['counts'] ?? [];

            return back()->with('success', '✅ Snapshot Data Pokok terkirim ke Cloud (versi ' . ($result['version'] ?? '-') . '). Isi: '
                . ($counts['siswas'] ?? 0) . ' murid, ' . ($counts['gurus'] ?? 0) . ' GTK, '
                . ($counts['rombels'] ?? 0) . ' rombel, ' . ($counts['pembelajaran'] ?? 0) . ' pembelajaran.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Push master ke Cloud gagal: ' . $e->getMessage());
        }
    }

    /**
     * ============================================================
     * [CLOUD-WORK][UX-HONEST 06.3] Kirim hasil kerja VIA CLOUD.
     *
     *   1. Pra-cek konfigurasi SEBELUM push.
     *   2. pushed=0 + skipped>0 = WARNING jujur (bukan sukses palsu).
     *   3. Status pairing laptop disebut.
     * ============================================================
     */
    public function pushWorkProductsCloud(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $guru = $user->teacher();

        if (!$guru) {
            return back()->with('error', 'Identitas guru tidak ditemukan.');
        }

        $sync = app(\App\Services\Sync\LmsSyncService::class);

        /* ---------- Pra-cek 1: identitas & konfigurasi ---------- */

        try {
            $sync->getSchoolId();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if (!$sync->isCloudConfigured()) {
            return back()->with('error', 'URL eduXid Cloud belum dikonfigurasi. Isi Setup Cloud di laptop Admin terlebih dahulu.');
        }

        /* ---------- Pra-cek 2: status pairing laptop ini ---------- */

        $npsn = $sync->getSchoolId();
        $loginId = trim((string) ($user->login_id ?? ''));

        $sourceDeviceId = $sync->resolveTeacherPcDeviceId((int) $guru->id);

        if (!$sourceDeviceId) {
            return back()->with(
                'error',
                'Laptop Guru belum ter-pairing aktif ke eduXid Cloud. '
                . 'Lakukan pairing Kode Guru terlebih dahulu agar data tidak dikirim dengan identitas perangkat yang salah.'
            );
        }

        /* ---------- Push ---------- */

        try {
            $result = $sync->pushAllWorkProductsForTeacher((int) $guru->id, $sourceDeviceId);
        } catch (\Throwable $e) {
            return back()->with('error', 'Kirim via Cloud gagal: ' . $e->getMessage());
        }

        $pushed  = (int) ($result['pushed'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $failed  = (int) ($result['failed'] ?? 0);

        /* ---------- Semua batch kosong - BUKAN sukses kirim ---------- */

        if ($failed === 0 && $pushed === 0 && $skipped > 0) {
            return back()->with(
                'warning',
                'Tidak ada data hasil kerja untuk dikirim - nilai harian, sikap, BK, '
                . 'catatan wali, rekap absensi, ekskul, dan kokurikuler semuanya '
                . 'kosong untuk guru ini di laptop ini.'
                . ' Isi nilai terlebih dahulu; setelah itu pengiriman berjalan '
                . 'otomatis lewat daemon sinkronisasi.'
            );
        }

        /* ---------- Ada kegagalan ---------- */

        if ($failed > 0) {
            $detail = implode('; ', array_slice($result['details'], 0, 3));

            return back()->with('warning', 'Kirim via Cloud selesai dengan '
                . $failed . ' kegagalan (terkirim: ' . $pushed
                . ', kosong: ' . $skipped . '). Detail: ' . $detail
                . ' - antrean akan mencoba ulang otomatis.');
        }

        /* ---------- Sukses sungguhan ---------- */

        return back()->with('success', '✅ Hasil pekerjaan terkirim VIA CLOUD dari perangkat ' . $sourceDeviceId . '. Terkirim: '
            . $pushed . ' batch, dikosongkan: ' . $skipped . '. '
            . 'Data guru diarahkan melalui scope penugasan ke Admin; Nilai Akhir Rapor juga membawa scope rombel/wali kelas terkait.');
    }

    /* ============================================================
     * UPLOAD FOTO SISWA (ZIP)
     * ============================================================ */
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
}