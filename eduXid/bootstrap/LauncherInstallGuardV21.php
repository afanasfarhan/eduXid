<?php
/**
 * eduXid V21R31: guard instalasi launcher sekolah/GTK.
 * PHP CLI portabel, tanpa dependency Laravel. Tidak menampilkan secret/identitas.
 * Penggunaan: php LauncherInstallGuardV21.php probe|prepare|finalize|verify <project_root> [state_file]
 */
declare(strict_types=1);

require_once __DIR__ . '/SchoolAliasSealV21R110.php';
use EduXid\Bootstrap\SchoolAliasSealV21R110;

final class LauncherInstallGuardV21
{
    private string $project;
    private string $envFile;
    private string $schoolMarker;
    private string $gtkMarker;
    private string $lockFile;

    public function __construct(string $project)
    {
        $real = realpath($project);
        if ($real === false || !is_file($real . DIRECTORY_SEPARATOR . 'artisan')) {
            throw new RuntimeException('Folder Laravel tidak valid.');
        }
        $this->project = rtrim($real, DIRECTORY_SEPARATOR);
        $this->envFile = $this->project . DIRECTORY_SEPARATOR . '.env';
        $this->schoolMarker = $this->project . '/storage/app/school-installation.json';
        $this->gtkMarker = $this->project . '/storage/app/installation.json';
        $this->lockFile = $this->project . '/storage/framework/eduxid-launcher-install.lock';
    }

    private function parseEnv(): array
    {
        $text = is_file($this->envFile) ? file_get_contents($this->envFile) : '';
        if (!is_string($text)) throw new RuntimeException('File .env tidak dapat dibaca.');
        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $matches)) continue;
            $key = $matches[1];
            if (array_key_exists($key, $values)) throw new RuntimeException('Konfigurasi .env duplikat: ' . $key);
            $value = trim($matches[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private function marker(string $file): ?array
    {
        if (!is_file($file)) return null;
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) throw new RuntimeException('Marker instalasi rusak.');
        return $data;
    }

    private function mode(array $env): string
    {
        $edition = strtolower(trim((string)($env['EDUXID_EDITION'] ?? '')));
        $type = strtolower(trim((string)($env['EDUXID_INSTALLATION_TYPE'] ?? '')));
        $gtk = $this->marker($this->gtkMarker);
        $gtkType = strtolower(trim((string)($gtk['installation_type'] ?? '')));
        return in_array('teacher', [$edition, $type, $gtkType], true) ? 'teacher' : 'school';
    }

    private static function validKey(string $key): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $key)) return false;
        $bytes = base64_decode(strtr($key, '-_', '+/') . '=', true);
        return is_string($bytes) && strlen($bytes) === 32 &&
            rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $key;
    }

    private static function validAppKey(string $value): bool
    {
        if (!str_starts_with($value, 'base64:')) return false;
        $decoded = base64_decode(substr($value, 7), true);
        return is_string($decoded) && strlen($decoded) === 32;
    }

    private static function validLocalToken(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function verifySchool(array $env, bool $db = true): void
    {
        if (strtolower((string)($env['EDUXID_INSTALLATION_TYPE'] ?? '')) !== 'school') {
            throw new RuntimeException('Tipe instalasi Sekolah tidak valid.');
        }
        if (!self::validAppKey((string)($env['APP_KEY'] ?? ''))) {
            throw new RuntimeException('APP_KEY Sekolah tidak valid.');
        }
        if (($env['EDUXID_IDENTITY_PROTOCOL'] ?? '') !== 'v21-opaque') {
            throw new RuntimeException('EDUXID_IDENTITY_PROTOCOL wajib v21-opaque.');
        }
        if (!self::validKey((string)($env['EDUXID_SCHOOL_ALIAS_KEY'] ?? ''))) {
            throw new RuntimeException('Secret alias sekolah tidak valid/tersedia.');
        }
        if (!self::validLocalToken((string)($env['LOCAL_SYNC_TOKEN'] ?? ''))) {
            throw new RuntimeException('Token lokal sekolah tidak valid.');
        }
        if ($db) $this->databasePath($env);
        // V21R110: existing schools with a seal must not silently rekey.
        // Legacy schools without a seal continue to boot for identity recovery,
        // but CloudDirectoryAliasV21 rejects new provisioning until verified bind.
        if (is_file(SchoolAliasSealV21R110::path($this->project)) &&
            SchoolAliasSealV21R110::state($this->project) === 'KEY_BERUBAH_BLOCKED') {
            throw new RuntimeException('BLOCKED V21R110: kunci alias berbeda dari segel sekolah. Pulihkan identitas lama.');
        }
    }

    private function verifyTeacher(array $env): void
    {
        if (!self::validAppKey((string)($env['APP_KEY'] ?? ''))) {
            throw new RuntimeException('APP_KEY GTK tidak valid.');
        }
        if (($env['EDUXID_IDENTITY_PROTOCOL'] ?? '') !== 'v21-opaque') {
            throw new RuntimeException('Paket GTK tidak menggunakan v21-opaque.');
        }
        if (trim((string)($env['EDUXID_SCHOOL_ALIAS_KEY'] ?? '')) !== '') {
            throw new RuntimeException('Secret alias sekolah tidak boleh ada pada Paket GTK.');
        }
        $school = trim((string)($env['EDUXID_SCHOOL_ID'] ?? ($env['EDUXID_NPSN'] ?? '')));
        $device = trim((string)($env['EDUXID_DEVICE_ID'] ?? ''));
        if (preg_match('/^([0-9]{8})-t_[a-z2-7]{26}-PC$/D', $device, $matches) !== 1 || $school !== $matches[1]) {
            throw new RuntimeException('Device ID GTK bukan alias V21-PC atau NPSN tidak cocok.');
        }
        if (!is_file($this->gtkMarker)) throw new RuntimeException('Marker Paket GTK belum tersedia.');
        $this->databasePath($env);
    }

    /** Deterministik: sama dengan DB_DATABASE yang Laravel baca, tanpa fallback scan acak. */
    private function databasePath(array $env): string
    {
        $driver = strtolower(trim((string)($env['DB_CONNECTION'] ?? 'sqlite')));
        if ($driver !== 'sqlite') throw new RuntimeException('Distribusi ini mewajibkan SQLite.');
        if (trim((string)($env['DB_URL'] ?? '')) !== '') {
            throw new RuntimeException('DB_URL tidak didukung pada preflight distribusi.');
        }
        $database = trim((string)($env['DB_DATABASE'] ?? ''));
        if ($database === '') $database = 'database/database.sqlite';
        if ($database === ':memory:') throw new RuntimeException('SQLite memory tidak didukung.');
        $absolute = preg_match('/^[A-Za-z]:[\\\\\/]/D', $database) === 1 ||
            str_starts_with($database, '\\\\') || str_starts_with($database, '/');
        $path = $absolute ? $database : $this->project . DIRECTORY_SEPARATOR . $database;
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('Database SQLite aktif tidak tersedia.');
        }
        return $real;
    }

    /** Verifikasi kebersihan hanya untuk instalasi Sekolah baru/resume. */
    private function cleanDb(array $env): void
    {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Ekstensi pdo_sqlite wajib tersedia.');
        $path = $this->databasePath($env);
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $found = array_fill_keys($tables, true);
        if (!isset($found['users'])) throw new RuntimeException('Tabel users tidak ditemukan.');
        $ids = $pdo->query('SELECT login_id FROM users ORDER BY login_id')->fetchAll(PDO::FETCH_COLUMN);
        sort($ids);
        if ($ids !== ['admin', 'guru', 'tendik']) throw new RuntimeException('Akun bootstrap distribusi bukan 3 akun standar.');
        $dataTables = [
            'schools','teachers','students','homerooms','enrollments','subjects','teaching_assignments',
            'lms_courses','lms_topics','lms_materials','lms_assignments','lms_assignment_submissions',
            'lms_sync_records','question_banks','exams','assessment_scores','final_grades','atp_items',
            'sync_devices','sessions','app_settings','cloud_identity_alias_routes',
            'cloud_identity_alias_registry','lms_sync_outbox','sync_outbox','sync_inbox',
            'cbt_sessions','cbt_exams','exam_results','donation_claims','messages',
        ];
        foreach ($dataTables as $table) {
            if (!isset($found[$table])) continue;
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            $rows = (int)$pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            if ($rows !== 0) throw new RuntimeException('Database distribusi tidak bersih: ' . $table);
        }
    }

    public function probe(): int
    {
        $env = $this->parseEnv();
        if ($this->mode($env) === 'teacher') {
            try { $this->verifyTeacher($env); return 11; }
            catch (Throwable $e) { return 41; }
        }
        $marker = $this->marker($this->schoolMarker);
        $appKey = trim((string)($env['APP_KEY'] ?? ''));
        if ($marker !== null) {
            if (($marker['installation_type'] ?? '') !== 'school') return 42;
            if (($marker['initialized'] ?? null) === true) {
                try { $this->verifySchool($env); return 0; }
                catch (Throwable $e) { return 42; }
            }
            if (($marker['stage'] ?? '') === 'initializing') return 12;
            return 42;
        }
        if ($appKey !== '') {
            // Instalasi tanpa marker tidak diadopsi diam-diam.
            return 42;
        }
        foreach (['EDUXID_SCHOOL_ID','EDUXID_NPSN','EDUXID_DEVICE_ID','EDUXID_SYNC_TOKEN',
                  'EDUXID_ENROLLMENT_TOKEN','EDUXID_ADMIN_KEY','EDUXID_ADMIN_TOKEN',
                  'EDUXID_IDENTITY_SCHOOL_SECRET','EDUXID_IDENTITY_MASTER_KEY'] as $name) {
            if (trim((string)($env[$name] ?? '')) !== '') return 43;
        }
        if (trim((string)($env['EDUXID_SCHOOL_ALIAS_KEY'] ?? '')) !== '') return 43;
        if (($env['EDUXID_IDENTITY_PROTOCOL'] ?? 'v21-opaque') !== 'v21-opaque') return 43;
        return 10;
    }

    private function lock(callable $body): void
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder lock instalasi tidak dapat dibuat.');
        }
        $fp = fopen($this->lockFile, 'c');
        if (!$fp) throw new RuntimeException('Lock instalasi tidak dapat dibuka.');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('Lock instalasi gagal.');
            $body();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function saveEnv(array $updates): void
    {
        $source = is_file($this->envFile) ? file_get_contents($this->envFile) : '';
        if (!is_string($source)) throw new RuntimeException('.env tidak dapat dibaca untuk instalasi.');
        $nl = str_contains($source, "\r\n") ? "\r\n" : "\n";
        foreach ($updates as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '[ \t]*=[^\r\n]*/m';
            $count = 0;
            $new = preg_replace($pattern, $key . '=' . $value, $source, -1, $count);
            if (!is_string($new) || $count > 1) throw new RuntimeException('Konfigurasi .env ganda atau tidak dapat diubah: ' . $key);
            $source = $count === 0 ? rtrim($new, "\r\n") . $nl . $key . '=' . $value . $nl : $new;
        }
        $this->atomicWrite($this->envFile, $source);
    }

    private function atomicWrite(string $path, string $data): void
    {
        $tmp = $path . '.eduxid-tmp-' . bin2hex(random_bytes(5));
        try {
            if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data) || !rename($tmp, $path)) {
                throw new RuntimeException('Gagal menyimpan konfigurasi instalasi.');
            }
        } finally { if (is_file($tmp)) unlink($tmp); }
    }

    private function writeMarker(bool $ready): void
    {
        $dir = dirname($this->schoolMarker);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder marker instalasi tidak dapat dibuat.');
        }
        $data = [
            'initialized' => $ready,
            'installation_type' => 'school',
            'edition' => 'school',
            'stage' => $ready ? 'ready' : 'initializing',
            'launcher_first_install' => true,
            'updated_at' => date(DATE_ATOM),
        ];
        $this->atomicWrite($this->schoolMarker, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function prepare(): void
    {
        $this->lock(function (): void {
            $state = $this->probe();
            if (!in_array($state, [10, 12], true)) throw new RuntimeException('Instalasi tidak berada dalam status baru/resume yang sah.');
            $env = $this->parseEnv();
            // Database wajib bersih sebelum menulis key pertama kali.
            // Saat resume setelah migrasi, tabel sistem boleh sudah terisi.
            if ($state === 10 || trim((string)($env['APP_KEY'] ?? '')) === '') {
                $this->cleanDb($env);
            } else {
                $this->databasePath($env);
            }
            if ($state === 10) {
                $this->writeMarker(false); // marker sebelum secret: crash selalu dapat dilanjutkan
                $this->saveEnv([
                    'APP_ENV' => 'production',
                    'APP_DEBUG' => 'false',
                    'LOG_LEVEL' => 'info',
                    'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
                    'EDUXID_INSTALLATION_TYPE' => 'school',
                    'EDUXID_EDITION' => 'school',
                    'LOCAL_SYNC_TOKEN' => bin2hex(random_bytes(32)),
                    'EDUXID_IDENTITY_PROTOCOL' => 'v21-opaque',
                    'EDUXID_SCHOOL_ALIAS_KEY' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), // V21R121: generate only on clean, first install
                    'EDUXID_SYNC_SERVER' => 'https://eduxid-cloud.eduxid-sync-anas.workers.dev',
                ]);
            } elseif (trim((string)($env['APP_KEY'] ?? '')) === '') {
                // Resume aman bila crash sebelum .env pertama tersimpan atomik.
                foreach (['EDUXID_SCHOOL_ALIAS_KEY', 'LOCAL_SYNC_TOKEN'] as $key) {
                    if (trim((string)($env[$key] ?? '')) !== '') {
                        throw new RuntimeException('Identitas instalasi parsial tidak konsisten.');
                    }
                }
                $this->saveEnv([
                    'APP_ENV' => 'production',
                    'APP_DEBUG' => 'false',
                    'LOG_LEVEL' => 'info',
                    'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
                    'EDUXID_INSTALLATION_TYPE' => 'school',
                    'EDUXID_EDITION' => 'school',
                    'LOCAL_SYNC_TOKEN' => bin2hex(random_bytes(32)),
                    'EDUXID_IDENTITY_PROTOCOL' => 'v21-opaque',
                    'EDUXID_SCHOOL_ALIAS_KEY' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), // V21R121: generate only on clean, first install
                    'EDUXID_SYNC_SERVER' => 'https://eduxid-cloud.eduxid-sync-anas.workers.dev',
                ]);
            }
            if ($state === 12 && trim((string)($env['APP_KEY'] ?? '')) !== '' &&
                trim((string)($env['EDUXID_SCHOOL_ALIAS_KEY'] ?? '')) === '') {
                if (is_file(SchoolAliasSealV21R110::path($this->project))) {
                    throw new RuntimeException('Kunci lokal hilang tetapi segel ada; pemulihan diperlukan.');
                }
                $this->cleanDb($env); // no published/local school data allowed
                $this->saveEnv(['EDUXID_SCHOOL_ALIAS_KEY' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);
            }
            // V21R121: local install runs OFFLINE before any Admin pairing.
            // A key is generated exactly once on a clean school install.
            // If setup was interrupted, reuse the same key; never rotate it.
            $updated = $this->parseEnv();
            $this->verifySchool($updated);
            $sealState = SchoolAliasSealV21R110::state($this->project);
            if ($sealState === 'BELUM_DIIKAT') {
                // Only the genuine initializing launcher marker can create a seal.
                SchoolAliasSealV21R110::pinFirstInstall($this->project);
            } elseif (!in_array($sealState, ['SEGEL_AWAL_PERLU_EPOCH_CLOUD', 'COCOK_DENGAN_SEGEL_CLOUD'], true)) {
                throw new RuntimeException('Segel lokal tidak cocok; instalasi tidak diubah.');
            }
        });
        echo '[OK] Persiapan lokal selesai. Login Admin, lalu aktivasi Cloud dengan kode pairing.' . PHP_EOL;
    }

    public function finalize(): void
    {
        $this->lock(function (): void {
            if ($this->probe() !== 12) throw new RuntimeException('Instalasi tidak berada dalam tahap initializing.');
            $env = $this->parseEnv();
            $this->verifySchool($env);
            // Only a valid, immutable LOCAL seal is needed to open Laravel.
            // Cloud pairing happens AFTER login; Cloud publication will still
            // require a matching pinned epoch (V21R114 fail-closed).
            if (!in_array(SchoolAliasSealV21R110::state($this->project),
                    ['SEGEL_AWAL_PERLU_EPOCH_CLOUD', 'COCOK_DENGAN_SEGEL_CLOUD'], true)) {
                throw new RuntimeException('Segel instalasi lokal tidak valid.');
            }
            $this->writeMarker(true);
        });
        echo '[OK] Instalasi sekolah dinyatakan READY.' . PHP_EOL;
    }

    public function verify(): void
    {
        $env = $this->parseEnv();
        if ($this->mode($env) === 'teacher') {
            $this->verifyTeacher($env);
            echo '[OK] Identitas GTK V21 dan SQLite valid; pairing dipertahankan.' . PHP_EOL;
        } else {
            $marker = $this->marker($this->schoolMarker);
            if (!is_array($marker) || ($marker['initialized'] ?? null) !== true) {
                throw new RuntimeException('Instalasi Sekolah belum selesai.');
            }
            $this->verifySchool($env);
            echo '[OK] Identitas Sekolah V21 dan SQLite valid.' . PHP_EOL;
        }
    }

    public function verifyCa(): void
    {
        $expected = getenv('EDUXID_CA_CERT');
        if (!is_string($expected) || realpath($expected) === false) {
            throw new RuntimeException('CA portable belum tersedia.');
        }
        // Guard berjalan sebagai PHP CLI mandiri, bukan melalui "artisan".
        // Karena itu autoloader Composer WAJIB dimuat sebelum bootstrap/app.php.
        // Tanpa ini, Laravel melaporkan Class "Illuminate\Foundation\Application" not found.
        $autoload = $this->project . '/vendor/autoload.php';
        if (!is_file($autoload) || !is_readable($autoload)) {
            throw new RuntimeException('Dependensi Composer Laravel tidak tersedia: vendor/autoload.php. Pulihkan folder vendor dari build eduXid yang lengkap.');
        }
        require_once $autoload;
        if (!class_exists(\Illuminate\Foundation\Application::class)) {
            throw new RuntimeException('Laravel Framework tidak ditemukan pada vendor. Periksa instalasi Composer; jangan mengubah key atau database.');
        }
        $oldCwd = getcwd();
        chdir($this->project);
        try {
            $app = require $this->project . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $actual = config('eduxid.ca_cert');
        } finally {
            if (is_string($oldCwd)) chdir($oldCwd);
        }
        if (!is_string($actual) || !is_file($actual) || !is_readable($actual) ||
            strcasecmp((string) realpath($actual), (string) realpath($expected)) !== 0) {
            throw new RuntimeException('CA Laravel tidak sesuai dengan CA portable.');
        }
        echo '[OK] Konfigurasi CA Laravel cocok.' . PHP_EOL;
    }

    public function writeState(string $file): void
    {
        $status = (string)$this->probe() . PHP_EOL;
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $status, LOCK_EX) !== strlen($status) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Tidak dapat menulis status instalasi.');
        }
    }
}

try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Utilitas ini hanya untuk CLI.');
    if ($argc < 3) throw new RuntimeException('Pemakaian: guard.php probe|prepare|finalize|verify|verify-ca <folder Laravel> [state_file]');
    $guard = new LauncherInstallGuardV21((string)$argv[2]);
    $command = (string)$argv[1];
    switch ($command) {
        case 'probe':
            if ($argc < 4) throw new RuntimeException('File hasil probe belum ditentukan.');
            $guard->writeState((string)$argv[3]);
            break;
        case 'prepare': $guard->prepare(); break;
        case 'finalize': $guard->finalize(); break;
        case 'verify': $guard->verify(); break;
        case 'verify-ca': $guard->verifyCa(); break;
        default: throw new RuntimeException('Mode utilitas instalasi tidak dikenal.');
    }
} catch (Throwable $e) {
    // Jangan tampilkan identitas, .env, query/SQL atau nilai rahasia.
    fwrite(STDERR, '[INSTALL ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
