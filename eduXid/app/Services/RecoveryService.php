<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RecoveryService
{
    public function __construct(
        protected BackupService $backupService
    ) {
    }

    /**
     * Memulihkan database eduXid dari file backup.
     *
     * Backup tidak menggantikan file SQLite utama secara langsung.
     * Data dipulihkan melalui transaksi agar database tetap aman.
     */
    public function restoreDatabase(string $backupPath): bool
    {
        $backupPath = trim($backupPath);

        if ($backupPath === '') {
            throw new RuntimeException(
                'Lokasi file backup tidak boleh kosong.'
            );
        }

        if (!file_exists($backupPath)) {
            throw new RuntimeException(
                'File backup untuk recovery tidak ditemukan: ' .
                $backupPath
            );
        }

        /*
         * ==========================================================
         * 1. VALIDASI BACKUP
         * ==========================================================
         */

        $this->backupService->validateBackup(
            $backupPath
        );

        $backupPdo = null;

        try {

            /*
             * ======================================================
             * 2. BUKA DATABASE BACKUP
             * ======================================================
             */

            $backupPdo = new \PDO(
                'sqlite:' . $backupPath,
                null,
                null,
                [
                    \PDO::ATTR_ERRMODE =>
                        \PDO::ERRMODE_EXCEPTION,
                ]
            );

            /*
             * Pastikan tabel users tersedia.
             */
            $usersExists = $backupPdo
                ->query(
                    "SELECT COUNT(*)
                     FROM sqlite_master
                     WHERE type = 'table'
                     AND name = 'users'"
                )
                ->fetchColumn();

            if ((int) $usersExists !== 1) {
                throw new RuntimeException(
                    'Backup tidak valid untuk recovery eduXid: ' .
                    'tabel users tidak ditemukan.'
                );
            }

            /*
             * ======================================================
             * 3. AMBIL DAFTAR TABEL
             * ======================================================
             *
             * Tabel sessions sengaja tidak dipulihkan karena
             * session aktif tidak boleh dibawa kembali dari backup.
             */

            $tables = $backupPdo
                ->query(
                    "SELECT name
                     FROM sqlite_master
                     WHERE type = 'table'
                     AND name NOT LIKE 'sqlite_%'
                     AND name != 'sessions'
                     ORDER BY name"
                )
                ->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($tables)) {
                throw new RuntimeException(
                    'Backup tidak mempunyai tabel yang dapat dipulihkan.'
                );
            }

            Log::warning(
                'Memulai database recovery eduXid.',
                [
                    'backup' => $backupPath,
                    'tables' => count($tables),
                ]
            );

            /*
             * ======================================================
             * 4. NONAKTIFKAN FOREIGN KEY
             * ======================================================
             */

            DB::statement(
                'PRAGMA foreign_keys = OFF'
            );

            /*
             * ======================================================
             * 5. PULIHKAN DATABASE DALAM TRANSAKSI
             * ======================================================
             */

            DB::transaction(
                function () use (
                    $backupPdo,
                    $tables
                ) {

                    foreach ($tables as $table) {

                        /*
                         * --------------------------------------------------
                         * Pastikan tabel tersedia pada database aktif.
                         * --------------------------------------------------
                         */

                        $activeTableExists = DB::select(
                            "SELECT name
                             FROM sqlite_master
                             WHERE type = 'table'
                             AND name = ?",
                            [$table]
                        );

                        if (empty($activeTableExists)) {
                            Log::warning(
                                'Tabel backup tidak ditemukan pada database aktif.',
                                [
                                    'table' => $table,
                                ]
                            );

                            continue;
                        }

                        /*
                         * --------------------------------------------------
                         * Kosongkan tabel aktif.
                         * --------------------------------------------------
                         */

                        DB::table($table)->delete();

                        /*
                         * --------------------------------------------------
                         * Ambil data dari backup.
                         * --------------------------------------------------
                         */

                        $quotedTable =
                            '"' .
                            str_replace(
                                '"',
                                '""',
                                $table
                            ) .
                            '"';

                        $rows = $backupPdo
                            ->query(
                                "SELECT * FROM {$quotedTable}"
                            )
                            ->fetchAll(
                                \PDO::FETCH_ASSOC
                            );

                        if (empty($rows)) {
                            continue;
                        }

                        /*
                         * --------------------------------------------------
                         * Masukkan data secara bertahap.
                         * --------------------------------------------------
                         */

                        foreach (
                            array_chunk(
                                $rows,
                                500
                            ) as $chunk
                        ) {

                            DB::table($table)
                                ->insert($chunk);
                        }

                        Log::info(
                            'Tabel berhasil dipulihkan.',
                            [
                                'table' => $table,
                                'rows'  => count($rows),
                            ]
                        );
                    }
                }
            );

            /*
             * ======================================================
             * 6. AKTIFKAN KEMBALI FOREIGN KEY
             * ======================================================
             */

            DB::statement(
                'PRAGMA foreign_keys = ON'
            );

            /*
             * ======================================================
             * 7. CEK INTEGRITAS DATABASE AKTIF
             * ======================================================
             */

            $integrity = DB::select(
                'PRAGMA integrity_check'
            );

            $integrityResult = null;

            if (!empty($integrity)) {
                $integrityResult =
                    $integrity[0]->integrity_check
                    ?? null;
            }

            if (
                $integrityResult !== null
                &&
                strtolower(
                    trim(
                        (string) $integrityResult
                    )
                ) !== 'ok'
            ) {

                throw new RuntimeException(
                    'Database hasil recovery tidak lolos ' .
                    'integrity check: ' .
                    $integrityResult
                );
            }

            /*
             * ======================================================
             * 8. SELESAI
             * ======================================================
             */

            $backupPdo = null;

            Log::warning(
                'Database eduXid berhasil dipulihkan.',
                [
                    'backup' => $backupPath,
                ]
            );

            return true;

        } catch (Throwable $e) {

            /*
             * Selalu hidupkan kembali foreign key.
             */
            try {
                DB::statement(
                    'PRAGMA foreign_keys = ON'
                );
            } catch (Throwable $pragmaException) {

                Log::error(
                    'Gagal mengaktifkan kembali foreign_keys.',
                    [
                        'error' =>
                            $pragmaException->getMessage(),
                    ]
                );
            }

            $backupPdo = null;

            Log::error(
                'DATABASE RECOVERY EDUXID GAGAL.',
                [
                    'backup' =>
                        $backupPath,

                    'error' =>
                        $e->getMessage(),

                    'exception' =>
                        get_class($e),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            throw new RuntimeException(
                'Recovery database gagal: ' .
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Membuat backup kemudian mengembalikannya.
     *
     * Method ini digunakan untuk pengujian recovery.
     */
    public function testRecovery(): bool
    {
        $backupPath =
            $this->backupService->createBackup();

        Log::info(
            'Backup recovery test berhasil dibuat.',
            [
                'backup' => $backupPath,
            ]
        );

        return $this->restoreDatabase(
            $backupPath
        );
    }
}
