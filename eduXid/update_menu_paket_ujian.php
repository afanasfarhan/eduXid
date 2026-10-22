<?php

declare(strict_types=1);

$root = __DIR__;
$nav = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'navigation.blade.php';

function failUpdate(string $message): never
{
    fwrite(STDERR, "[MENU UJIAN] GAGAL: {$message}" . PHP_EOL);
    exit(1);
}

function writeAtomicMenu(string $path, string $content): void
{
    $tmp = $path . '.paket-ujian-tmp';
    if (file_put_contents($tmp, $content) === false) {
        failUpdate("Tidak dapat menulis file sementara: {$tmp}");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        failUpdate("Tidak dapat mengganti file: {$path}");
    }
}

if (!is_file($root . DIRECTORY_SEPARATOR . 'artisan')) {
    failUpdate('Jalankan file ini dari root proyek eduXid yang berisi artisan.');
}
if (!is_file($nav)) {
    failUpdate('resources/views/layouts/navigation.blade.php tidak ditemukan.');
}

$stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
$backupDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'ljk-install-backups' . DIRECTORY_SEPARATOR . $stamp;
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    failUpdate('Tidak dapat membuat folder backup.');
}

$backup = $backupDir . DIRECTORY_SEPARATOR . 'navigation.blade.php';
if (!copy($nav, $backup)) {
    failUpdate('Tidak dapat membuat backup navigation.blade.php.');
}

$text = (string) file_get_contents($nav);

$desktopPattern = '~<x-dropdown-link\b.*?route\(\'guru\.cbt\'\).*?</x-dropdown-link>~s';
$mobilePattern = '~<x-responsive-nav-link\b.*?route\(\'guru\.cbt\'\).*?</x-responsive-nav-link>~s';

$desktopCount = 0;
$text = preg_replace_callback(
    $desktopPattern,
    static function (array $m): string {
        $block = $m[0];
        $block = str_replace("{{ __('CBT') }}", "{{ __('Paket Ujian') }}", $block);
        $block = str_replace("{{ __('CBT (Ujian Online)') }}", "{{ __('Paket Ujian') }}", $block);
        return preg_replace('/^(\s*)(?:CBT(?: \(Ujian Online\))?|Paket Ujian)\s*$/m', '$1Paket Ujian', $block) ?? $block;
    },
    $text,
    1,
    $desktopCount
) ?? $text;

$mobileCount = 0;
$text = preg_replace_callback(
    $mobilePattern,
    static function (array $m): string {
        $block = $m[0];
        $block = str_replace("{{ __('CBT') }}", "{{ __('Paket Ujian') }}", $block);
        $block = str_replace("{{ __('CBT (Ujian Online)') }}", "{{ __('Paket Ujian') }}", $block);
        return preg_replace('/^(\s*)(?:CBT(?: \(Ujian Online\))?|Paket Ujian)\s*$/m', '$1Paket Ujian', $block) ?? $block;
    },
    $text,
    1,
    $mobileCount
) ?? $text;

if ($desktopCount < 1 || $mobileCount < 1) {
    @copy($backup, $nav);
    failUpdate('Link route guru.cbt desktop/mobile tidak ditemukan. navigation.blade.php dipulihkan dari backup.');
}

writeAtomicMenu($nav, $text);

echo "[MENU UJIAN] Berhasil." . PHP_EOL;
echo "[MENU UJIAN] CBT → Paket Ujian (desktop + mobile)." . PHP_EOL;
echo "[MENU UJIAN] Route tetap guru.cbt; backend tidak diubah." . PHP_EOL;
echo "[MENU UJIAN] Backup: storage/app/ljk-install-backups/{$stamp}/navigation.blade.php" . PHP_EOL;
echo "[MENU UJIAN] Jalankan: php artisan optimize:clear" . PHP_EOL;
