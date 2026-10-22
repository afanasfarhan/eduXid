<?php
/**
 * eduXid Windows updater filesystem utility — PHP CLI only (no PowerShell).
 * Usage:
 *  php eduXidUpdateFiles.php inspect <zip> <output-file>
 *  php eduXidUpdateFiles.php extract <zip> <stage-dir>
 *  php eduXidUpdateFiles.php backup <stage-dir> <edition> <app-dir> <backup-dir>
 *  php eduXidUpdateFiles.php apply <stage-dir> <edition> <app-dir> <backup-dir>
 *  php eduXidUpdateFiles.php rollback <app-dir> <backup-dir>
 *  php eduXidUpdateFiles.php version <root> <version> <edition>
 *  php eduXidUpdateFiles.php process-check
 *
 * Extraction is performed AFTER Laravel Ed25519 verification in the batch driver.
 */
declare(strict_types=1);

function stop(string $message, int $exit = 1): never {
    fwrite(STDERR, '[EDUXID-CMD-UPDATE] ' . $message . PHP_EOL);
    exit($exit);
}
function directory(string $path): string {
    $p = realpath($path);
    if ($p === false || !is_dir($p)) stop('Direktori tidak tersedia: ' . $path);
    return rtrim($p, DIRECTORY_SEPARATOR);
}
function saveAtomic(string $file, string $data): void {
    $tmp = $file . '.tmp-' . getmypid();
    if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
        @unlink($tmp); stop('Gagal menyiapkan file sementara: ' . $file);
    }
    // Windows PHP can refuse rename when destination already exists.
    if (PHP_OS_FAMILY === 'Windows' && file_exists($file) && !unlink($file)) {
        @unlink($tmp); stop('Gagal mengganti file versi: ' . $file);
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp); stop('Gagal menyimpan: ' . $file);
    }
}
function jsonSave(string $file, array $data): void {
    saveAtomic($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
function safeRelative(string $name): string {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || $name[0] === '/' || str_contains($name, '//') || str_contains($name, ':') || preg_match('/[\x00-\x1f<>"|?*]/', $name)) {
        stop('Path ZIP tidak aman: ' . $name);
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_ends_with($part, '.') || str_ends_with($part, ' ')
            || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$/i', $part)) {
            stop('Segmen path tidak aman: ' . $name);
        }
    }
    return $name;
}
function protectedFile(string $relative): bool {
    $r = strtolower(str_replace('\\', '/', $relative));
    return $r === '.env' || $r === 'eduxid' || $r === 'database/eduxid'
        || $r === 'database/database.sqlite' || $r === 'database/database.sqlite-wal'
        || $r === 'database/database.sqlite-shm' || $r === 'database/eduxid-wal'
        || $r === 'database/eduxid-shm' || str_starts_with($r, 'storage/')
        || str_starts_with($r, '.git/') || str_starts_with($r, 'database/database.sqlite-')
        || str_starts_with($r, 'eduxid-') || $r === 'database/.env';
}
function safeDestination(string $root, string $relative): string {
    safeRelative($relative);
    if (protectedFile($relative)) stop('Patch mencoba menimpa data terlindungi: ' . $relative);
    $base = directory($root);
    $parts = explode('/', $relative);
    $path = $base;
    foreach ($parts as $part) {
        $path .= DIRECTORY_SEPARATOR . $part;
        if (is_link($path)) stop('Symlink/junction tidak diizinkan pada target: ' . $path);
    }
    if (is_dir($path)) stop('Target file merupakan direktori: ' . $relative);
    return $path;
}
function ensureParent(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) stop('Gagal membuat direktori: ' . $dir);
}
function copyChecked(string $from, string $to): void {
    if (!is_file($from) || is_link($from)) stop('Berkas sumber tidak aman: ' . $from);
    ensureParent($to);
    if (!copy($from, $to) || !hash_equals(hash_file('sha256', $from), hash_file('sha256', $to))) {
        stop('Salin/verifikasi file gagal: ' . $to);
    }
}
function manifest(string $zipFile): array {
    if (!extension_loaded('zip')) stop('Ekstensi PHP zip wajib tersedia.');
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) stop('Tidak dapat membuka ZIP.');
    try {
        $raw = $zip->getFromName('manifest.json');
        if (!is_string($raw)) stop('Manifest ZIP tidak ditemukan.');
        $m = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($m) || ($m['schema'] ?? '') !== 'EDUXID-RELEASE-V2'
            || !preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', (string) ($m['version'] ?? ''))) {
            stop('Manifest atau versi patch tidak valid.');
        }
        return $m;
    } finally { $zip->close(); }
}
function extractZip(string $zipFile, string $stage): void {
    $m = manifest($zipFile);
    $expected = array_fill_keys(array_map('strtolower', array_keys($m['files'] ?? [])), true);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) stop('ZIP gagal dibuka.');
    $seen = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) stop('Stat entri ZIP tidak dapat dibaca.');
            $raw = (string)$stat['name'];
            $isDir = str_ends_with($raw, '/');
            $relative = safeRelative($isDir ? substr($raw, 0, -1) : $raw);
            $key = strtolower($relative);
            if (isset($seen[$key])) stop('Entri ZIP duplikat: ' . $relative);
            $seen[$key] = true;
            $opsys = 0; $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                $kind = (($attr >> 16) & 0170000);
                if ($kind !== 0 && $kind !== ($isDir ? 0040000 : 0100000)) stop('Symlink ZIP ditolak.');
            }
            if ($isDir) {
                $parts = explode('/', $relative);
                $prefix = '';
                $allowed = false;
                foreach ($parts as $part) {
                    $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                    if (str_starts_with($prefix, 'payload/') && in_array($prefix, ['payload/core', 'payload/school', 'payload/teacher'], true)) $allowed = true;
                }
                if (!($relative === 'payload' || in_array($relative, ['payload/core', 'payload/school', 'payload/teacher'], true) || $allowed)) stop('Direktori ZIP tidak diizinkan: ' . $relative);
                continue;
            }
            if ($relative !== 'manifest.json' && !isset($expected[$key])) stop('Entri tidak tercantum dalam manifest: ' . $relative);
            $out = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            ensureParent($out);
            $read = $zip->getStream($raw);
            if ($read === false) stop('ZIP stream gagal: ' . $relative);
            $write = fopen($out, 'wb');
            if ($write === false) { fclose($read); stop('Gagal membuat staging.'); }
            $copied = stream_copy_to_stream($read, $write);
            fclose($read); fclose($write);
            if ($copied === false || $copied !== (int)$stat['size']) stop('Ekstraksi tidak lengkap: ' . $relative);
            if ($relative !== 'manifest.json' && !hash_equals(strtolower((string) $m['files'][$relative]), hash_file('sha256', $out))) {
                stop('Hash payload hasil ekstraksi tidak cocok: ' . $relative);
            }
        }
        foreach ($expected as $path => $_) if (!isset($seen[$path])) stop('Payload ZIP tidak lengkap: ' . $path);
    } finally { $zip->close(); }
    echo '[OK] Ekstraksi ZIP tervalidasi.' . PHP_EOL;
}
function payload(string $stage, string $edition): array {
    if (!in_array($edition, ['school', 'teacher'], true)) stop('Edition tidak sah.');
    $map = [];
    foreach (['core', $edition] as $channel) {
        $root = $stage . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . $channel;
        if (!is_dir($root)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isLink()) stop('Symlink staging ditolak.');
            if (!$file->isFile()) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            safeRelative($rel);
            if (protectedFile($rel)) stop('Payload terlindungi: ' . $rel);
            $map[strtolower($rel)] = ['relative' => $rel, 'source' => $file->getPathname()];
        }
    }
    if ($map === []) stop('Payload untuk edisi instalasi kosong.');
    return $map;
}
function backup(string $stage, string $edition, string $app, string $backup): void {
    $files = payload($stage, $edition);
    $entries = [];
    foreach ($files as $entry) {
        $rel = $entry['relative'];
        $dst = safeDestination($app, $rel);
        if (file_exists($dst)) {
            if (!is_file($dst)) stop('Target bukan file: ' . $rel);
            copyChecked($dst, $backup . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
            $entries[] = ['relative'=>$rel,'existed'=>true,'sha256'=>hash_file('sha256', $dst)];
        } else {
            $entries[] = ['relative'=>$rel,'existed'=>false];
        }
    }
    jsonSave($backup . DIRECTORY_SEPARATOR . 'update-file-journal.json', ['format'=>'eduxid-files-v1', 'files'=>$entries]);
    echo '[OK] File journal and rollback backup completed.' . PHP_EOL;
}
function journal(string $backup): array {
    $path = $backup . DIRECTORY_SEPARATOR . 'update-file-journal.json';
    if (!is_file($path)) stop('Jurnal backup tidak ditemukan.');
    $j = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($j['format'] ?? '') !== 'eduxid-files-v1' || !is_array($j['files'] ?? null)) stop('Jurnal backup tidak sah.');
    return $j['files'];
}
function apply(string $stage, string $edition, string $app, string $backup): void {
    $entries = journal($backup);
    $map = payload($stage, $edition);
    if (count($entries) !== count($map)) stop('Jurnal file dan payload tidak cocok.');
    foreach ($entries as $entry) {
        $rel = $entry['relative'] ?? '';
        if (!isset($map[strtolower($rel)])) stop('Jurnal berisi file tak terdaftar.');
        $src = $map[strtolower($rel)]['source'];
        $dst = safeDestination($app, $rel);
        copyChecked($src, $dst);
    }
    echo '[OK] File aplikasi terpasang.' . PHP_EOL;
}
function rollback(string $app, string $backup): void {
    foreach (journal($backup) as $entry) {
        $rel = $entry['relative'] ?? '';
        if (!is_string($rel) || $rel === '') stop('Jurnal rollback invalid.');
        $dst = safeDestination($app, $rel);
        if ($entry['existed'] ?? false) {
            $src = $backup . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($src) || !hash_equals((string)($entry['sha256'] ?? ''), hash_file('sha256', $src))) stop('File cadangan rusak: ' . $rel);
            copyChecked($src, $dst);
        } else {
            if (file_exists($dst) && !unlink($dst)) stop('File tambahan gagal dihapus: ' . $rel);
        }
    }
    echo '[OK] Rollback file berhasil.' . PHP_EOL;
}
function processCheck(): void {
    if (PHP_OS_FAMILY !== 'Windows') stop('Pemeriksaan proses hanya dapat berjalan di Windows.');
    // CMD's TASKLIST cannot reliably tell installation paths. Never taskkill by image name.
    // Fail closed if application or other PHP process remains active.
    foreach (['eduXid.exe', 'php.exe', 'php-cgi.exe', 'php-win.exe'] as $process) {
        $lines = [];
        $result = 0;
        exec('tasklist.exe /FI "IMAGENAME eq ' . $process . '" /FO CSV /NH 2>NUL', $lines, $result);
        if ($result !== 0) stop('tasklist gagal; pemasangan ditunda.', 10);
        foreach ($lines as $line) {
            $columns = str_getcsv($line);
            $image = trim((string)($columns[0] ?? ''));
            $pid = trim((string)($columns[1] ?? ''));
            if (strcasecmp($image, $process) === 0 && ctype_digit($pid) && (int)$pid !== getmypid()) {
                stop('Proses ' . $process . ' masih aktif (PID ' . $pid . '); pemasangan ditunda, tanpa mematikan proses lain.', 10);
            }
        }
    }
    echo '[OK] Tidak ada proses eduXid/PHP lain yang terdeteksi.' . PHP_EOL;
}
try {
    $cmd = $argv[1] ?? '';
    switch ($cmd) {
        case 'inspect':
            if (count($argv) !== 4) stop('Usage: inspect ZIP OUTPUT');
            saveAtomic($argv[3], (string)manifest($argv[2])['version']); break;
        case 'extract':
            if (count($argv) !== 4) stop('Usage: extract ZIP STAGING');
            extractZip($argv[2], directory($argv[3])); break;
        case 'backup':
        case 'apply':
            if (count($argv) !== 6) stop('Usage: backup|apply STAGING EDITION APP BACKUP');
            $cmd($argv[2], $argv[3], directory($argv[4]), directory($argv[5])); break;
        case 'rollback':
            if (count($argv) !== 4) stop('Usage: rollback APP BACKUP');
            rollback(directory($argv[2]), directory($argv[3])); break;
        case 'version':
            if (count($argv) !== 5 || !preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?$/', $argv[3])) stop('Version tidak sah.');
            $root = directory($argv[2]);
            if (!in_array($argv[4], ['teacher','school'], true)) stop('Edition tidak sah.');
            jsonSave($root . DIRECTORY_SEPARATOR . 'eduxid-version.json', ['version'=>$argv[3], 'edition'=>$argv[4], 'installed_at'=>date('c')]); break;
        case 'process-check': processCheck(); break;
        default: stop('Perintah tidak dikenal: ' . $cmd);
    }
} catch (Throwable $e) {
    stop($e->getMessage());
}
