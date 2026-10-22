<?php
/**
 * eduXid SQLite backup/restore for the Windows silent updater.
 * Invoke only from the trusted local updater, after entering maintenance mode.
 * PHP CLI, PDO SQLite, no Laravel bootstrap required.
 *
 * Usage: php eduXidUpdateSnapshot.php create  <app_dir> <backup_dir>
 *        php eduXidUpdateSnapshot.php restore <app_dir> <backup_dir>
 */
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "[EDUXID-SNAPSHOT] ERROR: {$message}\n");
    exit(1);
}

function dbCheck(string $path): void
{
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout=15000');
        $result = $pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
        $pdo = null;
        if ($result !== ['ok']) {
            fail('SQLite quick_check gagal: ' . $path);
        }
    } catch (Throwable $e) {
        fail('Tidak dapat memvalidasi database ' . $path . ': ' . $e->getMessage());
    }
}

function writeJsonAtomic(string $path, array $value): void
{
    $temporary = $path . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
        fail('Gagal menyimpan manifest snapshot.');
    }
}

function main(array $args): void
{
    if (count($args) !== 4 || !in_array($args[1], ['create', 'restore'], true)) {
        fail('Gunakan create|restore <app_dir> <backup_dir>.');
    }
    if (!extension_loaded('pdo_sqlite')) {
        fail('PHP portabel harus memuat ekstensi pdo_sqlite.');
    }

    [$command, $appInput, $backupInput] = array_slice($args, 1);
    $app = realpath($appInput);
    $backup = realpath($backupInput);
    if ($app === false || !is_file($app . DIRECTORY_SEPARATOR . 'artisan')) {
        fail('Direktori aplikasi atau artisan tidak ditemukan.');
    }
    if ($backup === false || !is_dir($backup)) {
        fail('Direktori backup tidak ditemukan.');
    }
    $manifestPath = $backup . DIRECTORY_SEPARATOR . 'sqlite-snapshots.json';
    $allowed = ['eduxid', 'database/database.sqlite'];

    if ($command === 'create') {
        if (is_file($manifestPath)) {
            fail('Snapshot untuk transaksi ini sudah dibuat; tidak boleh ditimpa.');
        }
        $entries = [];
        foreach ($allowed as $index => $relative) {
            $source = $app . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($source)) {
                continue;
            }
            dbCheck($source);
            $snapshotName = 'sqlite-' . $index . '.snapshot';
            $snapshot = $backup . DIRECTORY_SEPARATOR . $snapshotName;
            if (file_exists($snapshot)) {
                fail('File snapshot lama ditemukan; transaksi ditolak.');
            }
            try {
                $pdo = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA busy_timeout=15000');
                // VACUUM INTO provides a consistent SQLite snapshot even in WAL mode.
                $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $snapshot) . "'");
                $pdo = null;
            } catch (Throwable $e) {
                fail('Gagal membuat snapshot konsisten: ' . $e->getMessage());
            }
            dbCheck($snapshot);
            $entries[] = [
                'relative' => $relative,
                'snapshot' => $snapshotName,
                'sha256' => hash_file('sha256', $snapshot),
                'bytes' => filesize($snapshot),
            ];
        }
        if ($entries === []) {
            fail('Tidak ditemukan database eduXid atau database/database.sqlite; pemasangan dibatalkan.');
        }
        writeJsonAtomic($manifestPath, ['format' => 'eduxid-sqlite-snapshot-v1', 'databases' => $entries]);
        echo '[EDUXID-SNAPSHOT] CREATED ' . count($entries) . " database(s)\n";
        return;
    }

    if (!is_file($manifestPath)) {
        fail('Manifest backup tidak ditemukan; tidak boleh mengklaim rollback berhasil.');
    }
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fail('Manifest rollback tidak valid: ' . $e->getMessage());
    }
    if (($manifest['format'] ?? '') !== 'eduxid-sqlite-snapshot-v1' || !is_array($manifest['databases'] ?? null)
        || count($manifest['databases']) === 0) {
        fail('Format manifest rollback tidak sah.');
    }

    // Fully prevalidate every snapshot before touching any live database.
    $jobs = [];
    $seen = [];
    foreach ($manifest['databases'] as $entry) {
        if (!is_array($entry) || !in_array($entry['relative'] ?? '', $allowed, true)
            || !preg_match('/^sqlite-[01]\.snapshot$/', (string) ($entry['snapshot'] ?? ''))
            || !preg_match('/^[0-9a-f]{64}$/', (string) ($entry['sha256'] ?? ''))) {
            fail('Path atau hash manifest snapshot tidak sah.');
        }
        $relative = $entry['relative'];
        if (isset($seen[$relative])) {
            fail('Database tercatat ganda dalam manifest.');
        }
        $seen[$relative] = true;
        $snapshot = $backup . DIRECTORY_SEPARATOR . $entry['snapshot'];
        if (!is_file($snapshot) || !hash_equals($entry['sha256'], hash_file('sha256', $snapshot))) {
            fail('Checksum snapshot rollback gagal: ' . $relative);
        }
        dbCheck($snapshot);
        $dest = $app . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $temporary = $dest . '.eduxid-restore-' . getmypid();
        if (file_exists($temporary) || !copy($snapshot, $temporary)) {
            fail('Tidak bisa menyiapkan file restore: ' . $relative);
        }
        if (!hash_equals($entry['sha256'], hash_file('sha256', $temporary))) {
            fail('Checksum file restore sementara gagal.');
        }
        $jobs[] = [$dest, $temporary];
    }

    foreach ($jobs as [$dest, $temporary]) {
        // No application process may hold the SQLite file at this point.
        foreach ([$dest . '-wal', $dest . '-shm'] as $sidecar) {
            if (file_exists($sidecar) && !unlink($sidecar)) {
                fail('Masih ada koneksi/sidecar SQLite yang menghalangi rollback: ' . $sidecar);
            }
        }
        if (file_exists($dest) && !unlink($dest)) {
            fail('Database aktif masih terkunci; rollback tidak aman: ' . $dest);
        }
        if (!rename($temporary, $dest)) {
            fail('Gagal menempatkan database hasil rollback.');
        }
        dbCheck($dest);
    }
    echo '[EDUXID-SNAPSHOT] RESTORED ' . count($jobs) . " database(s)\n";
}

try {
    main($argv);
} catch (Throwable $e) {
    fail('Snapshot terhenti: ' . $e->getMessage());
}
