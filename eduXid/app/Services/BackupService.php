<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BackupService
{
    /**
     * ==============================================================
     * DATABASE PATH
     * ==============================================================
     */

    /**
     * Mendapatkan lokasi database SQLite eduXid.
     */
    protected function databasePath(): string
    {
        $database = config(
            'database.connections.sqlite.database'
        );

        if (!$database) {
            throw new RuntimeException(
                'Lokasi database SQLite eduXid tidak ditemukan.'
            );
        }

        if ($database === ':memory:') {
            throw new RuntimeException(
                'Database :memory: tidak dapat digunakan untuk backup.'
            );
        }

        $isWindowsAbsolute =
            preg_match(
                '/^[A-Za-z]:[\\\\\/]/',
                $database
            );

        $isUnixAbsolute =
            str_starts_with(
                $database,
                '/'
            );

        if (
            !$isWindowsAbsolute
            &&
            !$isUnixAbsolute
        ) {
            $database = base_path($database);
        }

        return $database;
    }

    /**
     * ==============================================================
     * DIRECTORY
     * ==============================================================
     */

    /**
     * Folder penyimpanan backup.
     */
    protected function ensureBackupDirectory(): string
    {
        $directory =
            storage_path(
                'app/backups'
            );

        if (!is_dir($directory)) {

            if (
                !mkdir(
                    $directory,
                    0777,
                    true
                )
                &&
                !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Gagal membuat folder backup: ' .
                    $directory
                );
            }
        }

        return $directory;
    }

    /**
     * Folder sementara untuk proses restore.
     */
    protected function ensureRestoreDirectory(): string
    {
        $directory =
            storage_path(
                'app/restore_queue'
            );

        if (!is_dir($directory)) {

            if (
                !mkdir(
                    $directory,
                    0777,
                    true
                )
                &&
                !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Gagal membuat folder restore: ' .
                    $directory
                );
            }
        }

        return $directory;
    }

    /**
     * ==============================================================
     * BACKUP
     * ==============================================================
     */

    /**
     * Membuat nama file backup.
     */
    protected function generateBackupFilename(): string
    {
        return
            'eduxid_backup_' .
            date('Y-m-d_H-i-s') .
            '_' .
            bin2hex(random_bytes(3)) .
            '.sqlite';
    }

    /**
     * Membuat backup database SQLite.
     *
     * Menggunakan VACUUM INTO sehingga salinan database
     * dibuat sebagai database SQLite baru yang konsisten.
     */
    public function createBackup(): string
    {
        $databasePath =
            $this->databasePath();

        if (!file_exists($databasePath)) {
            throw new RuntimeException(
                'Database eduXid tidak ditemukan: ' .
                $databasePath
            );
        }

        if (!is_file($databasePath)) {
            throw new RuntimeException(
                'Lokasi database eduXid bukan merupakan file.'
            );
        }

        if (!is_readable($databasePath)) {
            throw new RuntimeException(
                'Database eduXid tidak dapat dibaca.'
            );
        }

        $backupDirectory =
            $this->ensureBackupDirectory();

        $backupPath =
            $backupDirectory .
            DIRECTORY_SEPARATOR .
            $this->generateBackupFilename();

        try {

            /*
             * Pastikan WAL database tersinkron.
             */
            $pdo =
                new \PDO(
                    'sqlite:' . $databasePath,
                    null,
                    null,
                    [
                        \PDO::ATTR_ERRMODE =>
                            \PDO::ERRMODE_EXCEPTION,
                    ]
                );

            try {

                /*
                 * Checkpoint WAL.
                 *
                 * Tidak semua konfigurasi SQLite mengizinkan
                 * TRUNCATE, sehingga jika gagal kita lanjutkan
                 * dengan VACUUM INTO.
                 */
                try {
                    $pdo->exec(
                        'PRAGMA wal_checkpoint(TRUNCATE)'
                    );
                } catch (Throwable $checkpointException) {

                    Log::warning(
                        'WAL checkpoint backup tidak berhasil.',
                        [
                            'error' =>
                                $checkpointException
                                    ->getMessage(),
                        ]
                    );
                }

                /*
                 * Escape path untuk SQLite.
                 */
                $escapedPath =
                    str_replace(
                        "'",
                        "''",
                        $backupPath
                    );

                /*
                 * Buat database backup baru.
                 */
                $pdo->exec(
                    "VACUUM INTO '{$escapedPath}'"
                );

            } finally {

                $pdo = null;
            }

            /*
             * Pastikan file benar-benar ada.
             */
            if (!file_exists($backupPath)) {
                throw new RuntimeException(
                    'File backup tidak berhasil dibuat.'
                );
            }

            if (filesize($backupPath) <= 0) {

                @unlink(
                    $backupPath
                );

                throw new RuntimeException(
                    'File backup kosong.'
                );
            }

            /*
             * Validasi backup sebelum dianggap berhasil.
             */
            $this->validateBackup(
                $backupPath
            );

            Log::info(
                'Backup database eduXid berhasil dibuat.',
                [
                    'database' =>
                        $databasePath,

                    'backup' =>
                        $backupPath,

                    'size' =>
                        filesize($backupPath),
                ]
            );

            return $backupPath;

        } catch (Throwable $e) {

            if (
                isset($backupPath)
                &&
                file_exists($backupPath)
            ) {
                @unlink(
                    $backupPath
                );
            }

            Log::error(
                'Gagal membuat backup database eduXid.',
                [
                    'database' =>
                        $databasePath,

                    'backup' =>
                        $backupPath,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            throw new RuntimeException(
                'Backup database gagal: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * ==============================================================
     * VALIDATE BACKUP
     * ==============================================================
     */

    /**
     * Memvalidasi database SQLite.
     */
    public function validateBackup(
        string $backupPath
    ): bool {

        if (!file_exists($backupPath)) {
            throw new RuntimeException(
                'File backup tidak ditemukan.'
            );
        }

        if (!is_file($backupPath)) {
            throw new RuntimeException(
                'Path backup bukan merupakan file.'
            );
        }

        if (!is_readable($backupPath)) {
            throw new RuntimeException(
                'File backup tidak dapat dibaca.'
            );
        }

        if (filesize($backupPath) <= 0) {
            throw new RuntimeException(
                'File backup kosong.'
            );
        }

        /*
         * Periksa SQLite header.
         */
        $header =
            file_get_contents(
                $backupPath,
                false,
                null,
                0,
                16
            );

        if (
            $header !==
            "SQLite format 3\0"
        ) {
            throw new RuntimeException(
                'File bukan database SQLite yang valid.'
            );
        }

        try {

            $pdo =
                new \PDO(
                    'sqlite:' . $backupPath,
                    null,
                    null,
                    [
                        \PDO::ATTR_ERRMODE =>
                            \PDO::ERRMODE_EXCEPTION,
                    ]
                );

            /*
             * Integrity check.
             */
            $integrity =
                $pdo
                    ->query(
                        'PRAGMA integrity_check'
                    )
                    ->fetchColumn();

            if (
                strtolower(
                    trim(
                        (string) $integrity
                    )
                ) !== 'ok'
            ) {
                $pdo = null;

                throw new RuntimeException(
                    'Database backup rusak. ' .
                    'Integrity check: ' .
                    $integrity
                );
            }

            /*
             * Pastikan tabel users ada.
             *
             * Ini membantu mencegah file SQLite umum
             * digunakan sebagai backup eduXid.
             */
            $usersTable =
                $pdo
                    ->query(
                        "SELECT count(*)
                         FROM sqlite_master
                         WHERE type='table'
                         AND name='users'"
                    )
                    ->fetchColumn();

            if ((int) $usersTable !== 1) {

                $pdo = null;

                throw new RuntimeException(
                    'Database backup bukan database eduXid karena tabel users tidak ditemukan.'
                );
            }

            $pdo = null;

            return true;

        } catch (Throwable $e) {

            Log::error(
                'Validasi backup eduXid gagal.',
                [
                    'backup' =>
                        $backupPath,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            throw new RuntimeException(
                'File backup tidak valid: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * ==============================================================
     * RESTORE
     * ==============================================================
     */

    /**
     * Restore database dari file SQLite.
     *
     * File backup tidak langsung ditimpa ke database aktif.
     * Data dipindahkan melalui transaksi.
     *
     * Tabel sessions sengaja tidak di-restore agar session
     * aplikasi tidak rusak selama proses.
     */
    public function restoreBackup(
        string $backupPath
    ): bool {

        /*
         * Validasi backup terlebih dahulu.
         */
        $this->validateBackup(
            $backupPath
        );

        $databasePath =
            $this->databasePath();

        if (!file_exists($databasePath)) {
            throw new RuntimeException(
                'Database aktif eduXid tidak ditemukan.'
            );
        }

        /*
         * Jangan pernah restore file database yang sama
         * dengan database aktif.
         */
        if (
            realpath($backupPath)
            &&
            realpath($databasePath)
            &&
            realpath($backupPath) ===
            realpath($databasePath)
        ) {
            throw new RuntimeException(
                'File backup tidak boleh sama dengan database aktif.'
            );
        }

        $backupPdo = null;

        try {

            /*
             * Buka database backup.
             */
            $backupPdo =
                new \PDO(
                    'sqlite:' . $backupPath,
                    null,
                    null,
                    [
                        \PDO::ATTR_ERRMODE =>
                            \PDO::ERRMODE_EXCEPTION,
                    ]
                );

            /*
             * Ambil seluruh tabel backup.
             *
             * sessions tidak ikut dipulihkan.
             */
            $tables =
                $backupPdo
                    ->query(
                        "SELECT name
                         FROM sqlite_master
                         WHERE type='table'
                         AND name NOT LIKE 'sqlite_%'
                         AND name != 'sessions'
                         ORDER BY name"
                    )
                    ->fetchAll(
                        \PDO::FETCH_COLUMN
                    );

            if (empty($tables)) {
                throw new RuntimeException(
                    'File backup tidak memiliki tabel.'
                );
            }

            /*
             * Pastikan tabel users tersedia.
             */
            if (
                !in_array(
                    'users',
                    $tables,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Backup tidak memiliki tabel users.'
                );
            }

            /*
             * Nonaktifkan foreign key untuk proses pemindahan data.
             */
            DB::statement(
                'PRAGMA foreign_keys = OFF'
            );

            try {

                DB::transaction(
                    function () use (
                        $backupPdo,
                        $tables
                    ) {

                        foreach ($tables as $table) {

                            /*
                             * Pastikan nama tabel aman.
                             *
                             * Nama tabel berasal dari sqlite_master,
                             * bukan input langsung pengguna.
                             */
                            $safeTable =
                                str_replace(
                                    '"',
                                    '""',
                                    $table
                                );

                            /*
                             * Pastikan tabel tersedia di database aktif.
                             *
                             * Jika tabel tidak ada, jangan membuat
                             * struktur secara otomatis.
                             */
                            $activeTableExists =
                                DB::select(
                                    "SELECT name
                                     FROM sqlite_master
                                     WHERE type='table'
                                     AND name = ?",
                                    [$table]
                                );

                            if (
                                empty(
                                    $activeTableExists
                                )
                            ) {
                                throw new RuntimeException(
                                    "Tabel {$table} dari backup tidak ditemukan di database aktif."
                                );
                            }

                            /*
                             * Hapus data lama.
                             */
                            DB::table(
                                $table
                            )->delete();

                            /*
                             * Ambil data backup.
                             */
                            $statement =
                                $backupPdo->query(
                                    'SELECT * FROM "' .
                                    $safeTable .
                                    '"'
                                );

                            /*
                             * Masukkan data secara bertahap.
                             */
                            $chunk = [];

                            while (
                                $row =
                                    $statement->fetch(
                                        \PDO::FETCH_ASSOC
                                    )
                            ) {

                                $chunk[] =
                                    $row;

                                if (
                                    count(
                                        $chunk
                                    ) >= 500
                                ) {

                                    DB::table(
                                        $table
                                    )->insert(
                                        $chunk
                                    );

                                    $chunk = [];
                                }
                            }

                            /*
                             * Masukkan sisa data.
                             */
                            if (
                                !empty(
                                    $chunk
                                )
                            ) {

                                DB::table(
                                    $table
                                )->insert(
                                    $chunk
                                );
                            }
                        }
                    }
                );

            } finally {

                /*
                 * Aktifkan kembali foreign key.
                 */
                DB::statement(
                    'PRAGMA foreign_keys = ON'
                );
            }

            $backupPdo = null;

            /*
             * Periksa database aktif setelah restore.
             */
            $integrity =
                $this->integrityCheck();

            if (
                strtolower(
                    trim(
                        $integrity
                    )
                ) !== 'ok'
            ) {
                throw new RuntimeException(
                    'Database setelah restore tidak valid. ' .
                    'Integrity check: ' .
                    $integrity
                );
            }

            Log::warning(
                'RESTORE DATABASE eduXid berhasil.',
                [
                    'backup' =>
                        $backupPath,

                    'database' =>
                        $databasePath,

                    'tables' =>
                        count($tables),
                ]
            );

            return true;

        } catch (Throwable $e) {

            /*
             * Pastikan koneksi backup ditutup.
             */
            $backupPdo = null;

            /*
             * Pastikan foreign key aktif kembali.
             */
            try {
                DB::statement(
                    'PRAGMA foreign_keys = ON'
                );
            } catch (Throwable $foreignKeyException) {

                Log::error(
                    'Gagal mengaktifkan kembali foreign keys.',
                    [
                        'error' =>
                            $foreignKeyException
                                ->getMessage(),
                    ]
                );
            }

            Log::error(
                'RESTORE DATABASE eduXid GAGAL.',
                [
                    'backup' =>
                        $backupPath,

                    'database' =>
                        $databasePath,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            throw new RuntimeException(
                'Restore database gagal: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * ==============================================================
     * LIST BACKUPS
     * ==============================================================
     */

    /**
     * Mengembalikan daftar backup.
     */
    public function listBackups(): array
    {
        $directory =
            $this->ensureBackupDirectory();

        $files = [];

        $paths =
            glob(
                $directory .
                DIRECTORY_SEPARATOR .
                'eduxid_backup_*.sqlite'
            ) ?: [];

        foreach ($paths as $file) {

            if (!is_file($file)) {
                continue;
            }

            $createdAt =
                filemtime($file);

            $size =
                filesize($file);

            $files[] = [
                'name' =>
                    basename($file),

                'path' =>
                    $file,

                'size' =>
                    $size,

                'size_formatted' =>
                    $this->formatSize(
                        $size
                    ),

                'created_at' =>
                    $createdAt,

                'created_at_formatted' =>
                    date(
                        'd-m-Y H:i:s',
                        $createdAt
                    ),
            ];
        }

        usort(
            $files,
            fn(
                $a,
                $b
            ) =>
                $b['created_at']
                <=>
                $a['created_at']
        );

        return $files;
    }

    /**
     * ==============================================================
     * DELETE BACKUP
     * ==============================================================
     */

    /**
     * Hapus backup tertentu.
     */
    public function deleteBackup(
        string $backupPath
    ): bool {

        if (!file_exists($backupPath)) {
            return false;
        }

        $backupDirectory =
            realpath(
                $this->ensureBackupDirectory()
            );

        $realFile =
            realpath(
                $backupPath
            );

        /*
         * Keamanan:
         * hanya file di dalam folder backups yang boleh dihapus.
         */
        if (
            !$backupDirectory
            ||
            !$realFile
            ||
            !str_starts_with(
                $realFile,
                $backupDirectory .
                DIRECTORY_SEPARATOR
            )
        ) {
            throw new RuntimeException(
                'File backup tidak berada di folder backup eduXid.'
            );
        }

        /*
         * Jangan menghapus file yang bukan pola backup eduXid.
         */
        if (
            !str_starts_with(
                basename($realFile),
                'eduxid_backup_'
            )
            ||
            !str_ends_with(
                basename($realFile),
                '.sqlite'
            )
        ) {
            throw new RuntimeException(
                'Nama file backup tidak valid.'
            );
        }

        if (!unlink($realFile)) {
            throw new RuntimeException(
                'Gagal menghapus file backup.'
            );
        }

        Log::info(
            'File backup eduXid dihapus.',
            [
                'file' =>
                    $realFile,
            ]
        );

        return true;
    }

    /**
     * ==============================================================
     * KEEP LATEST
     * ==============================================================
     */

    /**
     * Mempertahankan sejumlah backup terbaru.
     *
     * Contoh:
     * keepLatest(10)
     *
     * berarti hanya menyimpan 10 backup terbaru.
     */
    public function keepLatest(
        int $maximum = 10
    ): int {

        if ($maximum < 1) {
            throw new RuntimeException(
                'Jumlah backup minimal adalah 1.'
            );
        }

        $backups =
            $this->listBackups();

        $deleted = 0;

        $oldBackups =
            array_slice(
                $backups,
                $maximum
            );

        foreach (
            $oldBackups
            as $backup
        ) {

            try {

                if (
                    $this->deleteBackup(
                        $backup['path']
                    )
                ) {
                    $deleted++;
                }

            } catch (Throwable $e) {

                Log::warning(
                    'Gagal menghapus backup lama.',
                    [
                        'file' =>
                            $backup['path'],

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }
        }

        return $deleted;
    }

    /**
     * ==============================================================
     * FORMAT SIZE
     * ==============================================================
     */

    /**
     * Mengubah ukuran byte menjadi format manusia.
     */
    public function formatSize(
        int $bytes
    ): string {

        if ($bytes <= 0) {
            return '0 B';
        }

        $units = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
        ];

        $power =
            (int) floor(
                log(
                    $bytes,
                    1024
                )
            );

        $power =
            min(
                $power,
                count($units) - 1
            );

        return number_format(
            $bytes /
            pow(
                1024,
                $power
            ),
            2
        ) .
        ' ' .
        $units[$power];
    }

    /**
     * ==============================================================
     * INTEGRITY CHECK
     * ==============================================================
     */

    /**
     * Menjalankan integrity check database aktif.
     */
    public function integrityCheck(): string
    {
        $databasePath =
            $this->databasePath();

        if (!file_exists($databasePath)) {
            throw new RuntimeException(
                'Database eduXid tidak ditemukan.'
            );
        }

        try {

            $pdo =
                new \PDO(
                    'sqlite:' . $databasePath,
                    null,
                    null,
                    [
                        \PDO::ATTR_ERRMODE =>
                            \PDO::ERRMODE_EXCEPTION,
                    ]
                );

            $result =
                $pdo
                    ->query(
                        'PRAGMA integrity_check'
                    )
                    ->fetchColumn();

            $pdo = null;

            return (string) $result;

        } catch (Throwable $e) {

            Log::error(
                'Integrity check database eduXid gagal.',
                [
                    'database' =>
                        $databasePath,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            throw new RuntimeException(
                'Pemeriksaan database gagal: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * ==============================================================
     * STATUS
     * ==============================================================
     */

    /**
     * Mengambil ringkasan kondisi backup.
     */
    public function status(): array
    {
        $backups =
            $this->listBackups();

        $latest =
            $backups[0] ?? null;

        return [
            'database' =>
                $this->databasePath(),

            'backup_directory' =>
                $this->ensureBackupDirectory(),

            'total_backups' =>
                count($backups),

            'latest_backup' =>
                $latest,

            'integrity' =>
                $this->integrityCheck(),
        ];
    }
}