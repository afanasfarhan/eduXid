<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'eduXid Admin Panel hanya boleh diakses dari komputer lokal.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payload tidak valid.']);
    exit;
}

const CLOUD_URL = 'https://eduxid-cloud.eduxid-sync-anas.workers.dev';
$action = trim((string)($data['action'] ?? ''));
$masterKey = trim((string)($data['master_key'] ?? ''));
$payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

function out(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function resolveCaBundle(): array {
    $candidates = [];

    $bundled = __DIR__ . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem';
    $candidates[] = ['path' => $bundled, 'source' => 'Admin Panel bundled CA'];

    $rootBundled = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'cacert.pem';
    $candidates[] = ['path' => $rootBundled, 'source' => 'eduXid portable PHP CA'];

    foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $name) {
        $value = trim((string)getenv($name));
        if ($value !== '') $candidates[] = ['path' => $value, 'source' => $name];
    }

    foreach (['curl.cainfo', 'openssl.cafile'] as $name) {
        $value = trim((string)ini_get($name));
        if ($value !== '') $candidates[] = ['path' => $value, 'source' => $name];
    }

    $candidates[] = ['path' => 'C:\\php\\cacert.pem', 'source' => 'C:\\php\\cacert.pem'];

    $seen = [];
    foreach ($candidates as $candidate) {
        $path = trim((string)($candidate['path'] ?? ''));
        if ($path === '') continue;
        $key = strtolower(str_replace('\\', '/', $path));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        if (is_file($path) && is_readable($path) && filesize($path) > 1024) {
            return ['path' => $path, 'source' => (string)$candidate['source']];
        }
    }

    return ['path' => null, 'source' => 'system/default'];
}

function cloudRequest(string $masterKey, string $method, string $path, array $payload = []): array {
    if ($masterKey === '') {
        return ['ok' => false, 'status' => 422, 'body' => ['success' => false, 'message' => 'Master EDUXID_ADMIN_KEY wajib diisi.']];
    }
    $ch = curl_init(CLOUD_URL . $path);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => ['success' => false, 'message' => 'Gagal menyiapkan koneksi HTTPS.']];
    }
    $headers = ['Accept: application/json', 'Authorization: Admin ' . $masterKey];
    $ca = resolveCaBundle();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (!empty($ca['path'])) {
        $opts[CURLOPT_CAINFO] = $ca['path'];
    }
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    if ($raw === false || $error !== '') {
        $message = 'Gagal menghubungi eduXid Cloud: ' . ($error ?: 'respons kosong');
        if (stripos($error, 'certificate') !== false || stripos($error, 'issuer') !== false || stripos($error, 'SSL') !== false) {
            $message .= '. CA yang digunakan: ' . ($ca['source'] ?? 'system/default');
        }
        return ['ok' => false, 'status' => $status ?: 502, 'body' => [
            'success' => false,
            'message' => $message,
            'ssl' => [
                'verify_peer' => true,
                'verify_host' => 2,
                'ca_source' => $ca['source'] ?? 'system/default',
                'ca_file' => !empty($ca['path']) ? basename((string)$ca['path']) : null,
            ],
        ]];
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => 502, 'body' => ['success' => false, 'message' => 'Respons eduXid Cloud bukan JSON valid.', 'response_preview' => mb_substr(trim((string)$raw), 0, 400)]];
    }
    return ['ok' => $status >= 200 && $status < 300 && ($decoded['success'] ?? false) === true, 'status' => $status, 'body' => $decoded];
}

function verifyMaster(string $masterKey): array {
    $r = cloudRequest($masterKey, 'GET', '/api/admin/donation/auth/verify');
    if (!$r['ok']) {
        // Compatibility fallback if a Worker predating the dedicated auth endpoint is still deployed.
        if ((int)$r['status'] === 404) {
            $r = cloudRequest($masterKey, 'GET', '/api/admin/keys/list');
        }
    }
    return $r;
}

function rootPath(array $payload): string {
    $candidate = trim((string)($payload['root_path'] ?? ''));
    if ($candidate === '') {
        $candidate = dirname(__DIR__);
    }
    $real = realpath($candidate);
    return $real !== false ? $real : $candidate;
}

function validateRoot(string $root): ?string {
    if (!is_dir($root)) return 'Folder root tidak ditemukan: ' . $root;
    if (!is_file($root . DIRECTORY_SEPARATOR . 'eduXid' . DIRECTORY_SEPARATOR . 'artisan')) return 'Folder eduXid/artisan tidak ditemukan di root.';
    return null;
}

function runProcess(string $command, ?string $cwd = null, ?string $stdin = null, int $timeout = 900): array {
    if (!function_exists('proc_open')) {
        return ['success' => false, 'exit_code' => -1, 'output' => 'proc_open tidak tersedia pada PHP ini.'];
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd ?: null, null, ['bypass_shell' => false]);
    if (!is_resource($process)) {
        return ['success' => false, 'exit_code' => -1, 'output' => 'Gagal menjalankan proses.'];
    }
    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $start = time();
    $lastExit = -1;
    while (true) {
        $status = proc_get_status($process);
        $out .= stream_get_contents($pipes[1]) ?: '';
        $out .= stream_get_contents($pipes[2]) ?: '';
        if (!$status['running']) { $lastExit = (int)($status['exitcode'] ?? -1); break; }
        if ((time() - $start) > $timeout) {
            proc_terminate($process);
            $out .= "\n[ERROR] Proses melewati batas waktu {$timeout} detik.";
            break;
        }
        usleep(100000);
    }
    $out .= stream_get_contents($pipes[1]) ?: '';
    $out .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code < 0 && $lastExit >= 0) $code = $lastExit;
    if (strlen($out) > 180000) $out = substr($out, -180000);
    return ['success' => $code === 0, 'exit_code' => $code, 'output' => trim($out)];
}

if ($action === 'local_info') {
    $root = rootPath($payload);
    $ca = resolveCaBundle();
    out(['success' => true, 'data' => [
        'root_path' => $root,
        'ssl_ca_source' => $ca['source'] ?? 'system/default',
        'ssl_ca_file' => !empty($ca['path']) ? basename((string)$ca['path']) : null,
        'has_eduxid' => is_file($root . DIRECTORY_SEPARATOR . 'eduXid' . DIRECTORY_SEPARATOR . 'artisan'),
        'has_cloud' => is_dir($root . DIRECTORY_SEPARATOR . 'eduxid-cloud'),
        'has_guru_pwa' => is_dir($root . DIRECTORY_SEPARATOR . 'eduxid-guru'),
        'has_siswa_pwa' => is_dir($root . DIRECTORY_SEPARATOR . 'eduxid-siswa'),
        'has_launcher' => is_file($root . DIRECTORY_SEPARATOR . 'eduXid.exe'),
        'has_updater' => is_file($root . DIRECTORY_SEPARATOR . 'eduXidUpdater.exe'),
    ]]);
}

$cloudMap = [
    'auth_verify' => ['GET', '/api/admin/donation/auth/verify'],
    'central_summary' => ['GET', '/api/admin/central/summary'],
    'central_schools' => ['GET', '/api/admin/central/schools'],
    'keys_list' => ['GET', '/api/admin/keys/list'],
    'keys_create' => ['POST', '/api/admin/keys/create'],
    'keys_revoke' => ['POST', '/api/admin/keys/revoke'],
    'keys_delete' => ['POST', '/api/admin/keys/delete'],
    'enrollment_create' => ['POST', '/api/admin/enrollment/create'],
    'device_resync' => ['POST', '/api/admin/device/resync'],
    'device_status' => ['POST', '/api/admin/central/device/status'],
    'donation_claims' => ['GET', '/api/admin/donation/claims/list?status=pending'],
    'donation_verified' => ['GET', '/api/admin/donation/accounts/list?status=verified'],
    'donation_approve' => ['POST', '/api/admin/donation/claim/approve'],
    'donation_reject' => ['POST', '/api/admin/donation/claim/reject'],
    'donation_delete' => ['POST', '/api/admin/donation/account/delete'],
    'donation_restore_key' => ['POST', '/api/admin/donation/account/key/restore'],
];

if ($action === 'central_school') {
    $schoolId = trim((string)($payload['school_id'] ?? ''));
    if ($schoolId === '') out(['success' => false, 'message' => 'school_id wajib diisi.'], 422);
    $r = cloudRequest($masterKey, 'GET', '/api/admin/central/school?school_id=' . rawurlencode($schoolId));
    out($r['body'], $r['status'] ?: 502);
}

if (isset($cloudMap[$action])) {
    [$method, $path] = $cloudMap[$action];
    $r = cloudRequest($masterKey, $method, $path, $payload);
    out($r['body'], $r['status'] ?: 502);
}

if (in_array($action, ['build_distribution', 'deploy_cloud', 'deploy_guru', 'deploy_siswa', 'rotate_master'], true)) {
    $auth = verifyMaster($masterKey);
    if (!$auth['ok']) out($auth['body'], $auth['status'] ?: 401);
    $root = rootPath($payload);
    if ($msg = validateRoot($root)) out(['success' => false, 'message' => $msg], 422);

    if ($action === 'build_distribution') {
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'build_distribution.cmd';
        $cmd = 'cmd.exe /d /c ""' . $script . '" "' . $root . '""';
        $res = runProcess($cmd, __DIR__, null, 1800);
        out(['success' => $res['success'], 'data' => $res], $res['success'] ? 200 : 500);
    }

    $scripts = [
        'deploy_cloud' => $root . DIRECTORY_SEPARATOR . 'eduxid-cloud' . DIRECTORY_SEPARATOR . 'deploy-cloud.cmd',
        'deploy_guru' => $root . DIRECTORY_SEPARATOR . 'eduxid-guru' . DIRECTORY_SEPARATOR . 'deploy-pwa.cmd',
        'deploy_siswa' => $root . DIRECTORY_SEPARATOR . 'eduxid-siswa' . DIRECTORY_SEPARATOR . 'deploy-pwa.cmd',
    ];
    if (isset($scripts[$action])) {
        if (!is_file($scripts[$action])) out(['success' => false, 'message' => 'Script deploy tidak ditemukan: ' . $scripts[$action]], 404);
        $cmd = 'cmd.exe /d /c ""' . $scripts[$action] . '""';
        $res = runProcess($cmd, dirname($scripts[$action]), null, 1800);
        out(['success' => $res['success'], 'data' => $res], $res['success'] ? 200 : 500);
    }

    if ($action === 'rotate_master') {
        $newKey = trim((string)($payload['new_master_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/', $newKey)) {
            out(['success' => false, 'message' => 'Master key baru minimal 32 karakter dan hanya boleh huruf, angka, _ atau -.'], 422);
        }
        $workerDir = $root . DIRECTORY_SEPARATOR . 'eduxid-cloud';
        if (!is_dir($workerDir)) out(['success' => false, 'message' => 'Folder eduxid-cloud tidak ditemukan.'], 404);
        $cmd = 'cmd.exe /d /c "npx wrangler secret put EDUXID_ADMIN_KEY"';
        $res = runProcess($cmd, $workerDir, $newKey . PHP_EOL, 300);
        if (!$res['success']) out(['success' => false, 'message' => 'Rotasi Worker secret gagal.', 'data' => $res], 500);
        out(['success' => true, 'message' => 'EDUXID_ADMIN_KEY Worker berhasil dirotasi. Key tidak disimpan oleh Admin Panel.', 'data' => $res]);
    }
}

out(['success' => false, 'message' => 'Action tidak dikenali.'], 422);
