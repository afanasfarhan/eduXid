<?php

declare(strict_types=1);

$root = __DIR__;
$web = $root . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';
$nav = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'navigation.blade.php';

function failInstall(string $message): never
{
    fwrite(STDERR, "[LJK] GAGAL: {$message}" . PHP_EOL);
    exit(1);
}

function writeAtomic(string $path, string $content): void
{
    $tmp = $path . '.ljk-tmp';
    if (file_put_contents($tmp, $content) === false) {
        failInstall("Tidak dapat menulis file sementara: {$tmp}");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        failInstall("Tidak dapat mengganti file: {$path}");
    }
}

echo "[LJK] eduXid LJK OMR V1.1.0 — Koreksi Massal" . PHP_EOL;

$requiredModuleFiles = [
    $root . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'ljk.php',
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'eduxid-ljk-omr.js',
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'eduxid-ljk-batch.js',
    $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'guru' . DIRECTORY_SEPARATOR . 'ljk' . DIRECTORY_SEPARATOR . 'index.blade.php',
];

if (!is_file($root . DIRECTORY_SEPARATOR . 'artisan')) {
    failInstall('Jalankan paket setelah diekstrak ke root proyek eduXid yang berisi artisan.');
}
foreach ($requiredModuleFiles as $moduleFile) {
    if (!is_file($moduleFile)) {
        failInstall('File modul LJK tidak lengkap: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $moduleFile));
    }
}

if (!is_file($web)) {
    failInstall('routes/web.php tidak ditemukan.');
}
if (!is_file($nav)) {
    failInstall('resources/views/layouts/navigation.blade.php tidak ditemukan.');
}

$stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
$backupDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'ljk-install-backups' . DIRECTORY_SEPARATOR . $stamp;
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    failInstall('Tidak dapat membuat folder backup.');
}

copy($web, $backupDir . DIRECTORY_SEPARATOR . 'web.php');
copy($nav, $backupDir . DIRECTORY_SEPARATOR . 'navigation.blade.php');

echo "[LJK] Backup: storage/app/ljk-install-backups/{$stamp}" . PHP_EOL;

/* -------------------------------------------------------------------------
 * ROUTE INCLUDE
 * ------------------------------------------------------------------------- */
$webText = (string) file_get_contents($web);
$routeInclude = "require __DIR__ . '/ljk.php';";

if (!str_contains($webText, $routeInclude)) {
    if (preg_match('/\?>\s*$/', $webText)) {
        $webText = preg_replace('/\?>\s*$/', PHP_EOL . $routeInclude . PHP_EOL . '?>' . PHP_EOL, $webText, 1) ?? $webText;
    } else {
        $webText = rtrim($webText) . PHP_EOL . PHP_EOL
            . "/* eduXid LJK-A4-V2 — route modul Pindai LJK */" . PHP_EOL
            . $routeInclude . PHP_EOL;
    }
    writeAtomic($web, $webText);
    echo "[LJK] routes/web.php: include routes/ljk.php ditambahkan." . PHP_EOL;
} else {
    echo "[LJK] routes/web.php: sudah terpasang." . PHP_EOL;
}

/* -------------------------------------------------------------------------
 * NAVIGATION — ubah CBT -> Paket Ujian + tambah Pindai LJK
 * ------------------------------------------------------------------------- */
$navText = (string) file_get_contents($nav);

$desktopCbtPattern = '~<x-dropdown-link\b.*?route\(\'guru\.cbt\'\).*?</x-dropdown-link>~s';
$mobileCbtPattern = '~<x-responsive-nav-link\b.*?route\(\'guru\.cbt\'\).*?</x-responsive-nav-link>~s';

$countDesktopLabel = 0;
$navText = preg_replace_callback(
    $desktopCbtPattern,
    static function (array $m): string {
        $block = $m[0];
        $block = str_replace("{{ __('CBT') }}", "{{ __('Paket Ujian') }}", $block);
        $block = str_replace("{{ __('CBT (Ujian Online)') }}", "{{ __('Paket Ujian') }}", $block);
        return preg_replace('/^(\s*)(?:CBT(?: \(Ujian Online\))?|Paket Ujian)\s*$/m', '$1Paket Ujian', $block) ?? $block;
    },
    $navText,
    1,
    $countDesktopLabel
) ?? $navText;

$countMobileLabel = 0;
$navText = preg_replace_callback(
    $mobileCbtPattern,
    static function (array $m): string {
        $block = $m[0];
        $block = str_replace("{{ __('CBT') }}", "{{ __('Paket Ujian') }}", $block);
        $block = str_replace("{{ __('CBT (Ujian Online)') }}", "{{ __('Paket Ujian') }}", $block);
        return preg_replace('/^(\s*)(?:CBT(?: \(Ujian Online\))?|Paket Ujian)\s*$/m', '$1Paket Ujian', $block) ?? $block;
    },
    $navText,
    1,
    $countMobileLabel
) ?? $navText;

if ($countDesktopLabel < 1 || $countMobileLabel < 1) {
    @copy($backupDir . DIRECTORY_SEPARATOR . 'web.php', $web);
    @copy($backupDir . DIRECTORY_SEPARATOR . 'navigation.blade.php', $nav);
    failInstall('Link route guru.cbt desktop/mobile tidak ditemukan. File yang dipatch dipulihkan dari backup.');
}

echo "[LJK] navigation.blade.php: Ujian → CBT diubah menjadi Ujian → Paket Ujian (desktop + mobile)." . PHP_EOL;

if (!str_contains($navText, "route('guru.ljk.index')")) {
    $desktopInsert = <<<'BLADE'

                                            <x-dropdown-link
                                                :href="route('guru.ljk.index')"
                                                :active="request()->routeIs('guru.ljk.*')">
                                                Pindai LJK
                                            </x-dropdown-link>
BLADE;

    $mobileInsert = <<<'BLADE'

                        <x-responsive-nav-link
                            :href="route('guru.ljk.index')"
                            :active="request()->routeIs('guru.ljk.*')">
                            {{ __('Pindai LJK') }}
                        </x-responsive-nav-link>
BLADE;

    $desktopPattern = '~(<x-dropdown-link\b.*?route\(\'guru\.cbt\'\).*?</x-dropdown-link>)~s';
    $mobilePattern = '~(<x-responsive-nav-link\b.*?route\(\'guru\.cbt\'\).*?</x-responsive-nav-link>)~s';

    $countDesktop = 0;
    $patched = preg_replace($desktopPattern, '$1' . $desktopInsert, $navText, 1, $countDesktop);
    if ($patched === null || $countDesktop < 1) {
        @copy($backupDir . DIRECTORY_SEPARATOR . 'web.php', $web);
        @copy($backupDir . DIRECTORY_SEPARATOR . 'navigation.blade.php', $nav);
        failInstall('Menu Paket Ujian desktop tidak ditemukan. File yang dipatch dipulihkan dari backup.');
    }

    $countMobile = 0;
    $patched2 = preg_replace($mobilePattern, '$1' . $mobileInsert, $patched, 1, $countMobile);
    if ($patched2 === null || $countMobile < 1) {
        @copy($backupDir . DIRECTORY_SEPARATOR . 'web.php', $web);
        @copy($backupDir . DIRECTORY_SEPARATOR . 'navigation.blade.php', $nav);
        failInstall('Menu Paket Ujian mobile tidak ditemukan. File yang dipatch dipulihkan dari backup.');
    }

    $navText = $patched2;
    echo "[LJK] navigation.blade.php: Ujian → Pindai LJK ditambahkan (desktop + mobile)." . PHP_EOL;
} else {
    echo "[LJK] navigation.blade.php: menu Pindai LJK sudah terpasang." . PHP_EOL;
}

writeAtomic($nav, $navText);

echo PHP_EOL;
echo "[LJK] Patch selesai." . PHP_EOL;
echo "[LJK] Lanjutkan:" . PHP_EOL;
echo "      php artisan migrate --force" . PHP_EOL;
echo "      php artisan optimize:clear" . PHP_EOL;
echo "      php artisan route:list --name=guru.ljk" . PHP_EOL;
echo "[LJK] Route V1.1: index, preview, finalize, batchPreview, batchFinalize." . PHP_EOL;
