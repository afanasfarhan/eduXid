<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use App\Services\BackupCompatibilityGuardV21R165;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class BackupController extends Controller
{
    /**
     * Service backup database eduXid.
     */
    public function __construct(
        protected BackupService $backupService
    ) {
    }

    /**
     * Tabel yang TIDAK ikut di-restore.
     *
     * - sessions          : sesi aktif harus dibuat ulang setelah login.
     * - cache/cache_locks : lock sync LMS (Cache::lock) bisa menjadi
     *   orphan bila ditimpa dengan snapshot cache dari laptop lain.
     */
    private const RESTORE_EXCLUDED_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        // Metadata target tidak boleh mundur mengikuti versi backup lama.
        'migrations',
        'app_versions',
        'patches',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /**
     * ================================================================
     * BACKUP DATABASE
     *
     * Membuat backup database SQLite kemudian mengunduhnya
     * ke komputer Admin.
     * ================================================================
     */
    public function createBackup()
    {
        try {
            $backupPath = $this->backupService->createBackup();

            /*
             * Validasi kembali hasil backup — lapisan keamanan
             * tambahan sebelum file diberikan kepada pengguna.
             */
            $this->backupService->validateBackup($backupPath);

            if (
                !file_exists($backupPath)
                || filesize($backupPath) <= 0
            ) {
                throw new RuntimeException(
                    'File backup gagal dibuat atau kosong.'
                );
            }

            return response()->download(
                $backupPath,
                basename($backupPath),
                [
                    'Content-Type' => 'application/x-sqlite3',
                ]
            )->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            Log::error('Backup database gagal.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return back()->with(
                'error',
                'Backup gagal: ' . $e->getMessage()
            );
        }
    }

    /**
     * ================================================================
     * RESTORE DATABASE
     *
     * Mengunggah database SQLite hasil backup kemudian
     * mengganti isi database aktif.
     *
     * [PRE-RESTORE-BACKUP] Sebelum menimpa, database AKTIF di-backup
     * otomatis ke storage/app/pre_restore_backup/. Bila restore
     * gagal di tengah jalan (listrik/proses mati), Admin punya
     * jalan pulang tanpa bergantung pada transaksi.
     *
     * [RESTORE-IDENTITY] Menimpa tabel users berarti menukar
     * identitas login laptop ini — berinteraksi dengan kebijakan
     * [ADMIN-PURGE]: restore DB Server di laptop guru akan
     * MEMUNCULKAN kembali akun admin, dan sebaliknya. Dilog eksplisit.
     * ================================================================
     */
    public function restoreBackup(Request $request)
    {
        /*
         * ------------------------------------------------------------
         * 1. VALIDASI FILE UPLOAD
         * ------------------------------------------------------------
         */
        $request->validate([
            'sql_file' => [
                'required',
                'file',
                'max:512000',
            ],
        ]);

        $uploaded = $request->file('sql_file');

        if (!$uploaded || !$uploaded->isValid()) {
            return back()->with('error', 'File restore tidak valid.');
        }

        /*
         * ------------------------------------------------------------
         * 2. DAPATKAN LOKASI DATABASE AKTIF
         * ------------------------------------------------------------
         */
        try {
            $databasePath = $this->databasePath();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if (!file_exists($databasePath)) {
            return back()->with('error', 'Database aktif eduXid tidak ditemukan.');
        }

        /*
         * ------------------------------------------------------------
         * 3. SIAPKAN FOLDER RESTORE
         * ------------------------------------------------------------
         */
        $restoreDirectory = storage_path('app/restore_queue');

        if (!is_dir($restoreDirectory) && !File::makeDirectory($restoreDirectory, 0777, true, true) && !is_dir($restoreDirectory)) {
            return back()->with('error', 'Direktori restore tidak dapat dibuat.');
        }

        /*
         * ------------------------------------------------------------
         * 4. BUAT NAMA FILE RESTORE SEMENTARA
         * ------------------------------------------------------------
         */
        try {
            $restoreId = now()->format('YmdHis') . '_' . bin2hex(random_bytes(4));
        } catch (Throwable) {
            return back()->with('error', 'Gagal membuat identitas file restore.');
        }

        $restorePath = $restoreDirectory . DIRECTORY_SEPARATOR . 'eduxid_restore_' . $restoreId . '.sqlite';

        /*
         * [PRE-RESTORE-BACKUP] Jalur "pulang" bila restore gagal —
         * dibuat SEBELUM apa pun ditimpa.
         */
        $preRestoreBackupPath = null;

        $backupPdo = null;

        try {

            /*
             * ========================================================
             * 5. SIMPAN FILE UPLOAD
             * ========================================================
             */
            $uploaded->move($restoreDirectory, basename($restorePath));

            if (!file_exists($restorePath)) {
                throw new RuntimeException('File restore gagal disimpan.');
            }

            /*
             * ========================================================
             * 6. VALIDASI DATABASE RESTORE
             * ========================================================
             */
            $this->backupService->validateBackup($restorePath);

            // Backward-compatible restore: cek skema/identitas sebelum perubahan data.
            // Migrasi versi baru dan status rilis tetap milik instalasi tujuan.
            (new BackupCompatibilityGuardV21R165())->inspect($restorePath, $databasePath);

            /*
             * ========================================================
             * [PRE-RESTORE-BACKUP] BACKUP DATABASE AKTIF
             * ========================================================
             * Bila gagal, restore DILANJUTKAN (Admin sudah diberi tahu
             * via log) — jangan jadikan backup pra-restore penghalang.
             */
            try {
                $preRestoreBackupPath = $this->backupService->createBackup();
                Log::info('[PRE-RESTORE-BACKUP] Database aktif dicadangkan sebelum restore.', [
                    'path' => $preRestoreBackupPath,
                    'triggered_by_user_id' => Auth::id(),
                ]);
            } catch (Throwable $preBackupError) {
                Log::error('[PRE-RESTORE-BACKUP] Backup pra-restore wajib berhasil; restore dibatalkan.', [
                    'error' => $preBackupError->getMessage(),
                ]);
                throw new RuntimeException('Restore dibatalkan: cadangan database aktif gagal dibuat.', 0, $preBackupError);
            }

            /*
             * ========================================================
             * 7. BUKA DATABASE BACKUP
             * ========================================================
             */
            $backupPdo = new \PDO(
                'sqlite:' . $restorePath,
                null,
                null,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]
            );

            /*
             * ========================================================
             * 8. AMBIL SEMUA TABEL
             * ========================================================
             *
             * [EXCLUDE-FIX] cache & cache_locks ikut dikecualikan —
             * menimpa cache laptop lain bisa meninggalkan lock orphan.
             */
            $placeholders = implode(', ', array_fill(0, count(self::RESTORE_EXCLUDED_TABLES), '?'));
            $tables = $backupPdo
                ->prepare(
                    "SELECT name
                     FROM sqlite_master
                     WHERE type='table'
                       AND name NOT LIKE 'sqlite_%'
                       AND name NOT IN ({$placeholders})"
                );
            $tables->execute(self::RESTORE_EXCLUDED_TABLES);
            $tables = $tables->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($tables)) {
                throw new RuntimeException('File backup tidak memiliki tabel sama sekali.');
            }

            /*
             * ========================================================
             * [RESTORE-IDENTITY] AUDIT IDENTITAS
             * ========================================================
             * Tabel users akan ditimpa — catat apa isi identitas
             * database backup vs database aktif. Berinteraksi dengan
             * kebijakan [ADMIN-PURGE] (lihat PengaturanController).
             */
            try {
                if (in_array('users', $tables, true)) {
                    $stmt = $backupPdo->query(
                        "SELECT role, COUNT(*) AS total FROM users GROUP BY role"
                    );
                    $backupRoles = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    $activeAdmins = DB::table('users')->where('role', 'admin')->count();

                    Log::warning('[RESTORE-IDENTITY] Tabel users akan ditimpa oleh restore.', [
                        'triggered_by_user_id' => Auth::id(),
                        'active_admin_count_now' => $activeAdmins,
                        'backup_role_counts' => $backupRoles,
                        'pre_restore_backup' => $preRestoreBackupPath,
                    ]);
                }
            } catch (Throwable $identityAuditError) {
                Log::warning('[RESTORE-IDENTITY] Audit identitas gagal (restore tetap lanjut).', [
                    'error' => $identityAuditError->getMessage(),
                ]);
            }

            /*
             * ========================================================
             * [RESTORE-SYNC-STATE] AUDIT IDENTITAS SINKRON
             * ========================================================
             * Restore menimpa SELURUH state sinkron dari backup:
             * sync_devices (pairing + token), app_settings (registry
             * master, flag dirty), eduxid_settings (kunci Cloud).
             *
             * - Backup laptop yang sama : kohoren, aman.
             * - Backup lintas laptop    : pairing laptop ini HILANG -
             *   daemon akan error sampai re-pair. Wajib tercatat &
             *   diinformasikan ke Admin.
             */
            try {
                $backupSyncDevices = 0;
                $backupHasOwnPairing = false;

                if (in_array('sync_devices', $tables, true)) {
                    $backupSyncDevices = (int) $backupPdo
                        ->query("SELECT COUNT(*) FROM sync_devices")
                        ->fetchColumn();
                }

                $activeSyncDevices = DB::table('sync_devices')->count();

                Log::warning('[RESTORE-SYNC-STATE] State sinkron akan ditimpa oleh restore.', [
                    'triggered_by_user_id' => Auth::id(),
                    'backup_sync_device_count' => $backupSyncDevices,
                    'active_sync_device_count_now' => $activeSyncDevices,
                    'pre_restore_backup' => $preRestoreBackupPath,
                ]);

                /*
                 * Simpan hitungan untuk pesan pasca-restore.
                 */
                $GLOBALS['restore_sync_device_count'] = $backupSyncDevices;
            } catch (Throwable $syncAuditError) {
                Log::warning('[RESTORE-SYNC-STATE] Audit gagal (restore tetap lanjut).', [
                    'error' => $syncAuditError->getMessage(),
                ]);
            }

            /*
             * ========================================================
             * 9. NONAKTIFKAN FOREIGN KEY SEMENTARA
             * ========================================================
             */
            DB::statement('PRAGMA foreign_keys = OFF');

            /*
             * ========================================================
             * 10. RESTORE DALAM TRANSACTION
             * ========================================================
             */
            DB::transaction(function () use ($backupPdo, $tables) {
                foreach ($tables as $table) {

                    /*
                     * Nama tabel berasal dari sqlite_master, bukan
                     * input pengguna — tetap di-quote defensif.
                     */
                    $quotedTable = '"' . str_replace('"', '""', $table) . '"';

                    /*
                     * Hapus data lama. delete() lebih aman daripada
                     * truncate() pada SQLite.
                     */
                    DB::table($table)->delete();

                    /*
                     * Ambil data dari database backup.
                     */
                    $rows = $backupPdo
                        ->query("SELECT * FROM {$quotedTable}")
                        ->fetchAll(\PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        continue;
                    }

                    /*
                     * [SCHEMA-FIX] Buang kolom yang tidak ada di tabel
                     * AKTIF — backup dari versi aplikasi berbeda tidak
                     * lagi menggagalkan seluruh restore.
                     */
                    $activeColumns = array_flip(Schema::getColumnListing($table));

                    $rows = array_map(
                        fn ($row) => $activeColumns !== []
                            ? array_intersect_key($row, $activeColumns)
                            : $row,
                        $rows
                    );

                    /*
                     * Masukkan data bertahap — chunk 500 menjaga
                     * penggunaan memory tetap terkendali.
                     */
                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }

                    /*
                     * [RESTORE-SYNC-STATE] Heartbeat dari backup bisa
                     * membuat deteksi daemon keliru; bersihkan agar
                     * daemon aktif mengambil alih bersih.
                     */
                    if (in_array('app_settings', $tables, true)) {
                        DB::table('app_settings')
                            ->where('key', 'daemon::heartbeat')
                            ->delete();
                    }

                    // Tidak boleh commit jika data yang dipulihkan memutus relasi FK.
                    $foreignKeyViolations = DB::select('PRAGMA foreign_key_check');
                    if (!empty($foreignKeyViolations)) {
                        throw new RuntimeException('Restore dibatalkan: foreign key tidak konsisten dengan skema baru.');
                    }
                    $quickCheckRows = DB::select('PRAGMA quick_check');
                    if (empty($quickCheckRows) || strtolower(trim((string) array_values((array) $quickCheckRows[0])[0])) !== 'ok') {
                        throw new RuntimeException('Restore dibatalkan: integritas SQLite hasil restore tidak lulus.');
                    }

            });

            /*
             * ========================================================
             * 11. AKTIFKAN KEMBALI FOREIGN KEY
             * ========================================================
             */
            DB::statement('PRAGMA foreign_keys = ON');

            /*
             * Tutup koneksi backup.
             */
            $backupPdo = null;

            /*
             * ========================================================
             * 12. HAPUS FILE RESTORE SEMENTARA
             * ========================================================
             */
            if (file_exists($restorePath)) {
                @unlink($restorePath);
            }

            /*
             * ========================================================
             * 13. LOGOUT OTOMATIS
             * ========================================================
             * Setelah restore, akun users berasal dari database
             * hasil backup. Session lama tidak boleh dipertahankan.
             */
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            /*
             * ========================================================
             * 14. ARAHKAN KE LOGIN
             * ========================================================
             */
            return redirect()
                ->route('login')
                ->with(
                    'status',
                    'Database berhasil dipulihkan ke skema aplikasi sekarang; riwayat migrasi dan versi rilis tidak ditimpa. '
                    . 'Silakan login kembali menggunakan akun dari database yang baru. '
                    . 'Cadangan database SEBELUM restore: '
                    . ($preRestoreBackupPath !== null ? basename($preRestoreBackupPath) : 'tidak tersedia')
                    . ' (folder storage/app/backup).'
                    . ' PULIHKAN SINKRONISASI: (1) jalankan ulang eduXid.exe (server + daemon), '
                    . '(2) php artisan eduxid:doctor - pastikan tanpa [GAGAL], '
                    . '(3) bila doctor melaporkan perangkat/pairing bermasalah, lakukan pairing ulang '
                    . '(Kode Guru) - state sinkron kini berasal dari file backup.'
                );
        } catch (Throwable $e) {

            /*
             * Tutup koneksi backup jika masih terbuka.
             */
            $backupPdo = null;

            /*
             * Pastikan foreign key kembali aktif.
             */
            try {
                DB::statement('PRAGMA foreign_keys = ON');
            } catch (Throwable $foreignKeyError) {
                Log::warning('Gagal mengaktifkan kembali foreign key SQLite.', [
                    'error' => $foreignKeyError->getMessage(),
                ]);
            }

            /*
             * Hapus file restore sementara.
             */
            if (isset($restorePath) && file_exists($restorePath)) {
                @unlink($restorePath);
            }

            Log::error('Restore database gagal.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'pre_restore_backup' => $preRestoreBackupPath,
                'trace' => $e->getTraceAsString(),
            ]);

            $message = 'Restore gagal: ' . $e->getMessage();

            if ($preRestoreBackupPath !== null) {
                $message .= ' Cadangan database sebelum restore tersedia: '
                    . basename($preRestoreBackupPath)
                    . ' (folder storage/app/backup) — dapat di-restore kembali bila diperlukan.';
            }

            return back()->with('error', $message);
        }
    }

    /**
     * ================================================================
     * DATABASE PATH
     *
     * Mengambil lokasi database SQLite aktif.
     * ================================================================
     */
    private function databasePath(): string
    {
        $database = config('database.connections.sqlite.database');

        if (!$database) {
            throw new RuntimeException(
                'Lokasi database SQLite eduXid tidak ditemukan.'
            );
        }

        if ($database === ':memory:') {
            throw new RuntimeException(
                'eduXid tidak dapat menggunakan restore untuk database :memory:.'
            );
        }

        /*
         * Windows absolute path.
         * Contoh: C:\eduXidMaster\eduXid\eduxid
         */
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $database);

        /*
         * Linux / Unix absolute path.
         */
        $isUnixAbsolute = str_starts_with($database, '/');

        /*
         * Jika relatif, ubah menjadi absolute path.
         */
        if (!$isWindowsAbsolute && !$isUnixAbsolute) {
            $database = base_path($database);
        }

        return $database;
    }
}