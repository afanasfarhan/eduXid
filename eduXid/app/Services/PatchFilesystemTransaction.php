<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

class PatchFilesystemTransaction
{
    public function __construct(
        protected PatchPackageValidator $validator
    ) {
    }

    public function stage(
        string $zipPath,
        string $version
    ): array {
        /*
         * Inspection dilakukan sebelum transaction directory dibuat.
         * Jika inspection gagal, belum ada filesystem transaction
         * yang perlu dibersihkan.
         */
        $inspection = $this->validator->inspect(
            $zipPath,
            $version
        );

        $transactionId =
            date('Ymd_His') .
            '_' .
            bin2hex(random_bytes(6));

        $transactionRoot =
            storage_path(
                'app/patch_transactions/' .
                $transactionId
            );

        $stagingRoot =
            $transactionRoot .
            DIRECTORY_SEPARATOR .
            'staging';

        $backupRoot =
            $transactionRoot .
            DIRECTORY_SEPARATOR .
            'backup';

        try {
            $this->ensureDirectory($stagingRoot);
            $this->ensureDirectory($backupRoot);

            $zip = new ZipArchive();

            $open = $zip->open($zipPath);

            if ($open !== true) {
                throw new RuntimeException(
                    "Gagal membuka ZIP patch. Kode: {$open}"
                );
            }

            $stagedEntries = [];

            try {
                for (
                    $i = 0;
                    $i < $zip->numFiles;
                    $i++
                ) {
                    $stat = $zip->statIndex($i);

                    if (
                        !$stat
                        || !isset($stat['name'])
                    ) {
                        throw new RuntimeException(
                            "Metadata entry ZIP index {$i} tidak dapat dibaca."
                        );
                    }

                    $entry =
                        $this->validator->normalizeEntry(
                            (string) $stat['name']
                        );

                    /*
                     * Directory entry tidak ditulis sebagai file.
                     */
                    if (
                        $entry === ''
                        || str_ends_with(
                            $entry,
                            '/'
                        )
                    ) {
                        continue;
                    }

                    $this->validator->assertSafeEntry(
                        $entry
                    );

                    /*
                     * Hanya file yang telah lolos inspection
                     * yang boleh masuk staging.
                     */
                    if (
                        !in_array(
                            $entry,
                            $inspection['entries'],
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            'Entry ZIP tidak terdapat dalam hasil inspeksi: ' .
                            $entry
                        );
                    }

                    /*
                     * Baca berdasarkan INDEX.
                     *
                     * Ini penting pada Windows karena nama hasil
                     * normalizeEntry() tidak selalu identik dengan
                     * nama internal yang diperlukan ZipArchive.
                     */
                    $contents =
                        $zip->getFromIndex($i);

                    if ($contents === false) {
                        throw new RuntimeException(
                            "Gagal membaca entry ZIP index {$i}: {$entry}"
                        );
                    }

                    $expectedSize =
                        (int) (
                            $stat['size']
                            ?? strlen($contents)
                        );

                    if (
                        $expectedSize >= 0
                        && strlen($contents) !==
                            $expectedSize
                    ) {
                        throw new RuntimeException(
                            'Ukuran entry ZIP tidak sesuai: ' .
                            $entry
                        );
                    }

                    $target =
                        $this->safeJoin(
                            $stagingRoot,
                            $entry
                        );

                    $this->ensureDirectory(
                        dirname($target)
                    );

                    $written =
                        file_put_contents(
                            $target,
                            $contents,
                            LOCK_EX
                        );

                    if ($written === false) {
                        throw new RuntimeException(
                            'Gagal membuat file staging: ' .
                            $entry
                        );
                    }

                    if (
                        $written !==
                        strlen($contents)
                    ) {
                        throw new RuntimeException(
                            'File staging tidak tertulis sempurna: ' .
                            $entry
                        );
                    }

                    $stagedEntries[] = $entry;
                }
            } finally {
                $zip->close();
            }

            /*
             * Identitas staging harus sama persis dengan
             * hasil inspection.
             */
            if (
                count($stagedEntries)
                !== count(
                    $inspection['entries']
                )
            ) {
                throw new RuntimeException(
                    'Jumlah file staging tidak sama dengan hasil inspeksi ZIP.'
                );
            }

            foreach (
                $inspection['entries']
                as $expectedEntry
            ) {
                if (
                    !in_array(
                        $expectedEntry,
                        $stagedEntries,
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Entry wajib tidak berhasil masuk staging: ' .
                        $expectedEntry
                    );
                }
            }

            return [
                'id' => $transactionId,
                'root' => $transactionRoot,
                'staging' => $stagingRoot,
                'backup' => $backupRoot,
                'entries' => $inspection['entries'],
                'sha256' => $inspection['sha256'],
                'installed' => [],
                'created' => [],
                'overwritten' => [],
            ];

        } catch (Throwable $e) {
            /*
             * stage() belum mengembalikan transaction ke caller.
             * Karena itu stage() sendiri wajib membersihkan
             * transaction directory jika proses gagal.
             */
            if (is_dir($transactionRoot)) {
                try {
                    $this->deleteTree(
                        $transactionRoot
                    );
                } catch (Throwable $cleanupError) {
                    Log::error(
                        'Gagal membersihkan staging patch yang gagal.',
                        [
                            'transaction' =>
                                $transactionId,

                            'error' =>
                                $cleanupError
                                    ->getMessage(),
                        ]
                    );
                }
            }

            throw $e;
        }
    }

    public function install(array $tx): array
    {
        foreach (
            $tx['entries']
            as $entry
        ) {
            $source =
                $this->safeJoin(
                    $tx['staging'],
                    $entry
                );

            $target =
                $this->safeJoin(
                    base_path(),
                    $entry
                );

            if (!is_file($source)) {
                throw new RuntimeException(
                    'File staging tidak ditemukan: ' .
                    $entry
                );
            }

            $this->ensureDirectory(
                dirname($target)
            );

            if (file_exists($target)) {
                if (!is_file($target)) {
                    throw new RuntimeException(
                        'Target patch bukan file biasa: ' .
                        $entry
                    );
                }

                $backup =
                    $this->safeJoin(
                        $tx['backup'],
                        $entry
                    );

                $this->ensureDirectory(
                    dirname($backup)
                );

                if (!copy($target, $backup)) {
                    throw new RuntimeException(
                        'Gagal membuat snapshot file: ' .
                        $entry
                    );
                }

                $tx['overwritten'][] = $entry;

            } else {
                $tx['created'][] = $entry;
            }

            $tempTarget =
                $target .
                '.eduxid-update-' .
                bin2hex(random_bytes(4));

            if (!copy($source, $tempTarget)) {
                @unlink($tempTarget);

                throw new RuntimeException(
                    'Gagal menyiapkan file baru: ' .
                    $entry
                );
            }

            if (
                file_exists($target)
                && !@unlink($target)
            ) {
                @unlink($tempTarget);

                throw new RuntimeException(
                    'Gagal mengganti file lama: ' .
                    $entry
                );
            }

            if (
                !@rename(
                    $tempTarget,
                    $target
                )
            ) {
                @unlink($tempTarget);

                throw new RuntimeException(
                    'Gagal mengaktifkan file patch: ' .
                    $entry
                );
            }

            $tx['installed'][] = $entry;
        }

        return $tx;
    }

    public function rollback(array $tx): void
    {
        $errors = [];

        foreach (
            array_reverse(
                $tx['created'] ?? []
            )
            as $entry
        ) {
            try {
                $target =
                    $this->safeJoin(
                        base_path(),
                        $entry
                    );

                if (
                    file_exists($target)
                    && !@unlink($target)
                ) {
                    throw new RuntimeException(
                        'Gagal menghapus file baru: ' .
                        $entry
                    );
                }

            } catch (Throwable $e) {
                $errors[] =
                    $e->getMessage();
            }
        }

        foreach (
            array_reverse(
                $tx['overwritten'] ?? []
            )
            as $entry
        ) {
            try {
                $backup =
                    $this->safeJoin(
                        $tx['backup'],
                        $entry
                    );

                $target =
                    $this->safeJoin(
                        base_path(),
                        $entry
                    );

                if (!is_file($backup)) {
                    throw new RuntimeException(
                        'Snapshot hilang: ' .
                        $entry
                    );
                }

                $this->ensureDirectory(
                    dirname($target)
                );

                $tempTarget =
                    $target .
                    '.eduxid-rollback-' .
                    bin2hex(random_bytes(4));

                if (
                    !copy(
                        $backup,
                        $tempTarget
                    )
                ) {
                    throw new RuntimeException(
                        'Gagal menyiapkan rollback: ' .
                        $entry
                    );
                }

                if (
                    file_exists($target)
                    && !@unlink($target)
                ) {
                    @unlink($tempTarget);

                    throw new RuntimeException(
                        'Gagal melepas file patch: ' .
                        $entry
                    );
                }

                if (
                    !@rename(
                        $tempTarget,
                        $target
                    )
                ) {
                    @unlink($tempTarget);

                    throw new RuntimeException(
                        'Gagal memulihkan file: ' .
                        $entry
                    );
                }

            } catch (Throwable $e) {
                $errors[] =
                    $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'Rollback filesystem tidak sempurna: ' .
                implode(
                    ' | ',
                    $errors
                )
            );
        }

        Log::warning(
            'Rollback filesystem patch eduXid berhasil.',
            [
                'transaction' =>
                    $tx['id'] ?? null,
            ]
        );
    }

    public function cleanup(array $tx): void
    {
        $root = $tx['root'] ?? null;

        if (
            !is_string($root)
            || $root === ''
            || !is_dir($root)
        ) {
            return;
        }

        $expectedRoot =
            realpath(
                storage_path(
                    'app/patch_transactions'
                )
            );

        $realRoot =
            realpath($root);

        if (
            !$expectedRoot
            || !$realRoot
            || !str_starts_with(
                $realRoot,
                rtrim(
                    $expectedRoot,
                    DIRECTORY_SEPARATOR
                ) .
                DIRECTORY_SEPARATOR
            )
        ) {
            throw new RuntimeException(
                'Folder transaksi patch tidak aman untuk dihapus.'
            );
        }

        $this->deleteTree(
            $realRoot
        );
    }

    protected function safeJoin(
        string $root,
        string $entry
    ): string {
        $entry =
            $this->validator->normalizeEntry(
                $entry
            );

        $this->validator->assertSafeEntry(
            $entry
        );

        $root =
            rtrim(
                $root,
                DIRECTORY_SEPARATOR
            );

        $candidate =
            $root .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $entry
            );

        $normalizedRoot =
            str_replace(
                '\\',
                '/',
                $root
            ) .
            '/';

        $normalizedCandidate =
            str_replace(
                '\\',
                '/',
                $candidate
            );

        if (
            !str_starts_with(
                $normalizedCandidate,
                $normalizedRoot
            )
        ) {
            throw new RuntimeException(
                'Path keluar dari root transaksi: ' .
                $entry
            );
        }

        return $candidate;
    }

    protected function ensureDirectory(
        string $directory
    ): void {
        if (
            !is_dir($directory)
            && !mkdir(
                $directory,
                0775,
                true
            )
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Gagal membuat folder: ' .
                $directory
            );
        }
    }

    protected function deleteTree(
        string $directory
    ): void {
        $items =
            scandir($directory);

        if ($items === false) {
            throw new RuntimeException(
                'Gagal membaca folder: ' .
                $directory
            );
        }

        foreach ($items as $item) {
            if (
                $item === '.'
                || $item === '..'
            ) {
                continue;
            }

            $path =
                $directory .
                DIRECTORY_SEPARATOR .
                $item;

            if (
                is_dir($path)
                && !is_link($path)
            ) {
                $this->deleteTree(
                    $path
                );
            } else {
                if (!@unlink($path)) {
                    throw new RuntimeException(
                        'Gagal menghapus: ' .
                        $path
                    );
                }
            }
        }

        if (!@rmdir($directory)) {
            throw new RuntimeException(
                'Gagal menghapus folder: ' .
                $directory
            );
        }
    }
}