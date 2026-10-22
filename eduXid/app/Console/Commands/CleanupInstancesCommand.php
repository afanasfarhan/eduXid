<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * [REWRITE-OPS] PEMBERSIH INSTANCE ASING
 *
 * Menampilkan semua proses eduXid (php.exe daemon/server dan
 * eduXid.exe launcher) yang hidup, lalu mematikan yang BUKAN
 * milik folder proyek ini — mencegah dua sistem menulis dua
 * database berbeda (akar #7).
 *
 * COM/WMI diperlukan untuk mengidentifikasi path executable yang benar.
 * Jika COM tidak tersedia, jangan hentikan proses apapun (fail safe).
 * Pemeriksaan manual dengan tasklist.exe dapat dilakukan di CMD.
 */
class CleanupInstancesCommand extends Command
{
    protected $signature = 'eduxid:cleanup-instances {projectPath}';
    protected $description = 'Menampilkan & mematikan instance eduXid dari folder lain (Windows).';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->line('Non-Windows — dilewati.');
            return self::SUCCESS;
        }

        $project = rtrim(str_replace('/', '\\', (string) $this->argument('projectPath')), '\\');

        $this->line('=== Instance eduXid yang terdeteksi ===');

        $rows = $this->scanViaCom($project);

        if ($rows === null) {
            $this->line('  [PERINGATAN] Pemindaian tidak tersedia (ekstensi COM/WMI nonaktif).');
            $this->line('  Pembersihan otomatis DILEWATI. Periksa manual di terminal:');
            $this->line('    tasklist /FI "IMAGENAME eq eduXid.exe"');
            return self::SUCCESS;
        }

        $root = $this->rootOf($project);
        $killed = 0;
        $kept = 0;

        foreach ($rows as $row) {
            [$pid, $path, $cmdline] = $row;

            $isPhp = ($cmdline !== '' && stripos($cmdline, 'eduxid') !== false);
            $isExe = ($path !== '' && stripos($path, 'eduxid.exe') !== false);

            if (!$isPhp && !$isExe) {
                continue;
            }

            $mine = $isPhp
                ? ($cmdline !== '' && stripos($cmdline, $project) !== false)
                : ($path !== '' && $root !== '' && stripos($path, $root) === 0);

            if ($mine) {
                $this->line("  KEEP  PID {$pid}  milik salinan ini :: " . $this->shorten($cmdline ?: $path));
                $kept++;
            } else {
                $this->line("  KILL  PID {$pid}  FOLDER LAIN :: " . $this->shorten($cmdline ?: $path));
                $this->kill($pid);
                $killed++;
            }
        }

        $this->line("=== Pembersihan selesai (kill={$killed}, keep={$kept}) ===");

        return self::SUCCESS;
    }

    /* ============================================================
     * JALUR 1 — COM/WMI
     * ============================================================ */

    protected function scanViaCom(string $project): ?array
    {
        if (!class_exists('COM')) {
            return null;
        }

        try {
            $wmi = new \COM('winmgmts:\\\\.\\root\\cimv2');

            $rows = [];

            $phpProcs = $wmi->ExecQuery(
                "SELECT ProcessId, CommandLine, ExecutablePath FROM Win32_Process WHERE Name = 'php.exe'"
            );

            foreach ($phpProcs as $p) {
                $rows[] = [
                    (int) $p->ProcessId,
                    (string) ($p->ExecutablePath ?? ''),
                    (string) ($p->CommandLine ?? ''),
                ];
            }

            $exes = $wmi->ExecQuery(
                "SELECT ProcessId, ExecutablePath FROM Win32_Process WHERE Name = 'eduXid.exe'"
            );

            foreach ($exes as $e) {
                $rows[] = [
                    (int) $e->ProcessId,
                    (string) ($e->ExecutablePath ?? ''),
                    '',
                ];
            }

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    protected function kill(int $pid): void
    {
        try {
            $taskkill = (getenv('SystemRoot') ?: 'C:\\Windows')
                . '\\System32\\taskkill.exe';

            if (!is_file($taskkill)) {
                return;
            }

            shell_exec('"' . $taskkill . '" /PID ' . $pid . ' /F');
        } catch (Throwable) {
            // Guard berikutnya tetap menolak kondisi tak jelas.
        }
    }

    protected function rootOf(string $project): string
    {
        $pos = strrpos($project, '\\eduXid');

        return $pos !== false
            ? substr($project, 0, $pos)
            : '';
    }

    protected function shorten(string $text, int $max = 90): string
    {
        $text = trim($text);

        return mb_strlen($text) > $max
            ? mb_substr($text, 0, $max) . '…'
            : $text;
    }
}