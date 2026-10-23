<?php

namespace App\Console\Commands;

use App\Services\LocalMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Move legacy sensitive public files outside webroot; keep DB-relative paths stable. */
class SecureMediaMigrateCommand extends Command
{
    protected $signature = 'eduxid:media-private-migrate {--apply : Move and verify files (default: dry-run)}';
    protected $description = 'Migrate sensitive legacy school media to private storage (safe to rerun).';

    public function handle(): int
    {
        $source = Storage::disk('public');
        $private = Storage::disk('local');
        $apply = (bool)$this->option('apply');
        $seen = 0; $moved = 0; $errors = 0;
        foreach (LocalMediaService::privatePrefixes() as $prefix) {
            foreach ($source->allFiles(rtrim($prefix, '/')) as $relative) {
                $seen++;
                if (!$apply) { $this->line('DRY-RUN: ' . $relative); continue; }
                $target = 'eduxid-media/' . $relative;
                try {
                    $srcPath = $source->path($relative);
                    if (is_link($srcPath)) throw new \RuntimeException('Symlink tidak diizinkan.');
                    if ($private->exists($target)) {
                        if (!hash_equals((string)hash_file('sha256', $srcPath), (string)hash_file('sha256', $private->path($target)))) {
                            throw new \RuntimeException('Tujuan sudah ada dengan isi berbeda.');
                        }
                    } else {
                        $stream = $source->readStream($relative);
                        if (!is_resource($stream)) throw new \RuntimeException('Tidak dapat membuka file sumber.');
                        try {
                            if (!$private->put($target, $stream)) throw new \RuntimeException('Gagal menulis ke disk private.');
                        } finally { fclose($stream); }
                    }
                    if (!hash_equals((string)hash_file('sha256', $srcPath), (string)hash_file('sha256', $private->path($target)))) {
                        throw new \RuntimeException('Hash SHA-256 hasil salinan berbeda; sumber tidak dihapus.');
                    }
                    if (!$source->delete($relative)) throw new \RuntimeException('Gagal menghapus salinan publik.');
                    $moved++;
                } catch (Throwable $e) {
                    $this->error($relative . ': ' . $e->getMessage());
                    $errors++;
                }
            }
        }
        $this->info(sprintf('Media privat: mode=%s files=%d moved=%d errors=%d', $apply ? 'APPLY' : 'DRY-RUN', $seen, $moved, $errors));
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
