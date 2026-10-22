<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Services\UpdateService;
use App\Services\Update\CurrentVersion;
use App\Services\Update\ReleaseVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================
 * UpdateController — Admin Update & Patch (KANONIK)
 *
 * [DUPLICATE-FIX] Sebelumnya ada dua file dengan kelas sama:
 *   - app/Http/Controllers/UpdateController.php   (Versi A — di-skip composer)
 *   - app/Http/Controllers/Admin/UpdateController.php (Versi B — yang berjalan)
 * Kanonik ini = gabungan aman keduanya. Sisakan SATU file di Admin.
 *
 * [VERSION-SYNC] Regex versi selaras dengan ->where() pada
 * route admin.update.install. Dipakai install/check/uploadPatch.
 *
 * [COMPAT-INSTALL] install() menerima versi dari PARAMETER RUTE
 * (sesuai Route::post('/update/patch/{version}', ...)) ATAU dari
 * BODY POST — dua bentuk view lama sama-sama didukung.
 * ============================================================
 */
class UpdateController extends Controller
{
    protected const VERSION_REGEX = '/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/';

    /** Batas ukuran patch ZIP (KB) — 200 MB. */
    protected const MAX_PATCH_SIZE_KB = 204800;

    public function __construct(
        protected UpdateService $updateService
    ) {
    }

    /**
     * Halaman utama Update / Patch.
     *
     * [OFFLINE-FIX] Tidak pernah blank walau server Admin pusat
     * offline / tabel AppVersion gagal dibaca.
     * [MODEL-GUARD] current/latest_version bisa berupa model
     * AppVersion pada UpdateService tertentu — dinormalkan ke string.
     */
    public function index()
    {
        try {
            $status = $this->updateService->status();

            $currentVersion = $status['current_version'] ?? '1.0.0';

            if ($currentVersion instanceof AppVersion) {
                $currentVersion = $currentVersion->version;
            }
            if (is_object($currentVersion)) {
                $currentVersion = (string) ($currentVersion->version ?? '1.0.0');
            }

            $latestVersion = $status['latest_version'] ?? $currentVersion;

            if (is_object($latestVersion)) {
                $latestVersion = (string) ($latestVersion->version ?? $currentVersion);
            }

            return view('admin.update.index', [
                'status'           => $status,
                'currentVersion'   => (string) $currentVersion,
                'latestVersion'    => (string) $latestVersion,
                'updateAvailable'  => (bool) ($status['update_available'] ?? false),
                'adminConnected'   => (bool) ($status['admin_connected'] ?? false),
                'patches'          => $status['patches'] ?? collect(),
                'pageError'        => null,
            ]);
        } catch (Throwable $e) {

            Log::error('Gagal membuka halaman Update & Patch eduXid.', [
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            try {
                $currentVersion = method_exists($this->updateService, 'version')
                    ? (string) $this->updateService->version()
                    : '1.0.0';
            } catch (Throwable) {
                $currentVersion = '1.0.0';
            }

            return view('admin.update.index', [
                'status'          => [
                    'application'     => config('eduxid.name', 'eduXid'),
                    'current_version' => $currentVersion,
                    'latest_version'  => $currentVersion,
                    'update_available'=> false,
                    'admin_connected' => false,
                    'patches'         => collect(),
                ],
                'currentVersion'  => $currentVersion,
                'latestVersion'   => $currentVersion,
                'updateAvailable' => false,
                'adminConnected'  => false,
                'patches'         => collect(),
                'pageError'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Jalankan update menuju versi tertentu.
     *
     * [COMPAT-INSTALL] Sumber versi: parameter rute {version} dulu
     * (sesuai rute terdaftar), fallback ke body POST (bentuk view lama).
     * [LOG-FIX] Operasi paling kritis — sukses & gagal keduanya berjejak.
     */
    public function install(Request $request, ?string $version = null)
    {
        $version = trim((string) ($version ?? $request->input('version', '')));

        if ($version === '' || preg_match(self::VERSION_REGEX, $version) !== 1) {
            return redirect()
                ->route('admin.update')
                ->with('error', 'Format atau nilai versi update tidak valid.');
        }

        try {
            Log::info('Admin memulai update eduXid.', [
                'target_version' => $version,
                'ip'             => $request->ip(),
            ]);

            /*
             * UpdateService menangani: cek versi, ambil patch, backup
             * otomatis, validasi backup, eksekusi, aktivasi, recovery
             * otomatis bila gagal.
             */
            $patch = $this->updateService->updateTo($version);

            Log::info('Update eduXid berhasil dari Admin.', [
                'version' => $patch->version,
                'ip'      => $request->ip(),
            ]);

            return redirect()
                ->route('admin.update')
                ->with(
                    'success',
                    'Update berhasil. eduXid sekarang menggunakan versi '
                    . $patch->version . '. Backup otomatis telah dibuat sebelum update.'
                );
        } catch (Throwable $e) {

            Log::error('Update eduXid melalui AdminController gagal.', [
                'target_version' => $version,
                'error'          => $e->getMessage(),
                'exception'      => get_class($e),
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
                'ip'             => $request->ip(),
            ]);

            return redirect()
                ->route('admin.update')
                ->with(
                    'error',
                    'UPDATE GAGAL: ' . $e->getMessage()
                    . ' Sistem telah mencoba melakukan recovery database otomatis.'
                );
        }
    }

    /**
     * Terima paket update manual OFFLINE dengan format EDUXID-RELEASE-V2.
     *
     * TIDAK memasang file saat request web masih berjalan. Paket yang sudah
     * diverifikasi disimpan sebagai eduxid-patch-<versi>.zip di root instalasi;
     * updater resmi akan memprosesnya setelah eduXid ditutup/dibuka kembali.
     * Semua file Full File Replacement tanpa tanda tangan tetap ditolak.
     */
    public function uploadPatch(
        Request $request,
        ReleaseVerifier $releaseVerifier,
        CurrentVersion $currentVersion
    ) {
        $request->validate([
            'patch_zip' => 'required|file|mimes:zip|max:' . self::MAX_PATCH_SIZE_KB,
        ]);

        $uploaded = $request->file('patch_zip');
        if (!$uploaded || !$uploaded->isValid()) {
            return redirect()->route('admin.update')
                ->with('error', 'File ZIP tidak berhasil diunggah.');
        }

        $stagedTemp = null;
        $lockHandle = null;
        try {
            $edition = strtolower(trim((string) config('eduxid.edition', 'school')));
            if (!in_array($edition, ['school', 'teacher'], true)) {
                throw new \RuntimeException('Jenis instalasi eduXid tidak dikenali.');
            }

            $root = realpath((string) config('eduxid.install_root'));
            if ($root === false || !is_dir($root) || !is_writable($root)
                || !is_file($root . DIRECTORY_SEPARATOR . 'eduXid.exe')
                || !is_file($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'eduXidUpdater.bat')) {
                throw new \RuntimeException('Root instalasi atau updater Windows tidak siap.');
            }

            $statusDir = $root . DIRECTORY_SEPARATOR . '.eduxid-updater';
            if ((!is_dir($statusDir) && !@mkdir($statusDir, 0770, true)) || !is_dir($statusDir)) {
                throw new \RuntimeException('Folder status updater tidak dapat dibuat.');
            }
            $lockHandle = @fopen($statusDir . DIRECTORY_SEPARATOR . 'manual-upload.lock', 'c+');
            if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Unggahan update lain sedang diproses. Coba lagi setelah selesai.');
            }

            // Verifikasi penuh di TEMPORARY upload: daftar file, SHA-256, dan
            // signature Ed25519 resmi, bukan sekadar ekstensi ZIP.
            $manifest = $releaseVerifier->verify($uploaded->getRealPath(), $edition);
            $version = (string) $manifest['version'];
            $current = $currentVersion->get();

            if (version_compare($version, $current, '<=')) {
                throw new \RuntimeException("Rilis {$version} tidak lebih baru dari versi terpasang {$current}.");
            }
            if (version_compare($current, (string) $manifest['min_supported_version'], '<')) {
                throw new \RuntimeException('Rilis membutuhkan versi perantara sebelum dapat dipasang.');
            }

            $final = $root . DIRECTORY_SEPARATOR . 'eduxid-patch-' . $version . '.zip';
            if (is_link($final)) {
                throw new \RuntimeException('Target patch adalah symbolic link yang tidak aman.');
            }
            $stagedTemp = $root . DIRECTORY_SEPARATOR
                . '.eduxid-manual-stage-' . bin2hex(random_bytes(12)) . '.tmp';
            if (!@copy($uploaded->getRealPath(), $stagedTemp)) {
                throw new \RuntimeException('Tidak dapat menyalin paket ke staging lokal.');
            }

            // Verifikasi kedua, setelah bytes masuk ke disk instalasi.
            $second = $releaseVerifier->verify($stagedTemp, $edition);
            if (!hash_equals($version, (string) $second['version'])) {
                throw new \RuntimeException('Versi berubah selama penyalinan.');
            }

            if (is_file($final)) {
                if (!hash_equals((string) hash_file('sha256', $final), (string) hash_file('sha256', $stagedTemp))) {
                    throw new \RuntimeException('Versi ini sudah memiliki paket berbeda pada root. Periksa paket lama sebelum melanjutkan.');
                }
                @unlink($stagedTemp);
                $stagedTemp = null;
            } else {
                if (!@rename($stagedTemp, $final)) {
                    throw new \RuntimeException('Gagal memindahkan paket terverifikasi ke root instalasi.');
                }
                $stagedTemp = null;
            }

            Log::info('Rilis manual bertanda tangan siap untuk updater Windows.', [
                'version' => $version,
                'edition' => $edition,
                'sha256' => hash_file('sha256', $final),
            ]);

            return redirect()->route('admin.update')->with('success',
                "Rilis resmi {$version} berhasil diverifikasi (Ed25519 dan SHA-256) dan disiapkan. "
                . 'Tutup eduXid, lalu jalankan kembali launcher untuk memasang pembaruan saat kondisi aman. '
                . 'ZIP manual tanpa tanda tangan tidak diperbolehkan.'
            );
        } catch (Throwable $e) {
            Log::warning('Staging update manual eduXid ditolak.', [
                'reason' => $e->getMessage(),
                'type' => get_class($e),
            ]);
            return redirect()->route('admin.update')
                ->with('error', 'Paket update manual ditolak: ' . $e->getMessage());
        } finally {
            if ($stagedTemp !== null && is_file($stagedTemp)) {
                @unlink($stagedTemp);
            }
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }
    }

    /**
     * Dry-run rencana update (belum ter-route — lihat catatan di
     * bawah bila ingin mengaktifkan).
     *
     * [COMPAT-CHECK] Versi dari parameter rute ATAU body.
     *
     * Bila ingin dipakai, daftarkan:
     *   Route::post('/update/check/{version?}', [UpdateController::class, 'check'])
     *       ->where('version', '[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?')
     *       ->name('admin.update.check');
     */
    public function check(Request $request, ?string $version = null)
    {
        $version = trim((string) ($version ?? $request->input('version', '')));

        if ($version === '' || preg_match(self::VERSION_REGEX, $version) !== 1) {
            return redirect()
                ->route('admin.update')
                ->with('error', 'Format atau nilai versi tidak valid.');
        }

        try {
            $plan = $this->updateService->planUpdate($version);

            $planned = $plan['planned_patches'] ?? [];

            if (empty($planned)) {
                return redirect()
                    ->route('admin.update')
                    ->with('error', "Tidak ada patch baru yang perlu direncanakan menuju versi {$version}.");
            }

            return redirect()
                ->route('admin.update')
                ->with('success', 'Rencana update berhasil dibuat. Patch: ' . implode(', ', $planned))
                ->with('update_plan', $plan);
        } catch (Throwable $e) {

            Log::error('Gagal membuat rencana update eduXid.', [
                'target_version' => $version,
                'error'          => $e->getMessage(),
            ]);

            return redirect()
                ->route('admin.update')
                ->with('error', 'Gagal memeriksa update: ' . $e->getMessage());
        }
    }

    /**
     * Status update JSON (untuk AJAX bila diperlukan; belum ter-route).
     */
    public function status()
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->updateService->status(),
            ]);
        } catch (Throwable $e) {

            Log::error('Gagal mengambil status update eduXid.', [
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}