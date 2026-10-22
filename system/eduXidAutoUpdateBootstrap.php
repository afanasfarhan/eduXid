<?php
/**
 * eduXid Silent Auto-Update bootstrap. Independent of Laravel / php.ini.
 * Windows Command Prompt + Task Scheduler only; no PowerShell, no elevation prompt.
 * Called by PASANG_AUTO_UPDATE.bat and (as a fallback for legacy EXE) Laravel boot.
 * A separate, location-bound Windows task is registered for each installation.
 *
 * CLI: php -n eduXidAutoUpdateBootstrap.php [--ensure|--if-due|--dry-run]
 */
declare(strict_types=1);

$root = realpath(dirname(__DIR__));
$agent = $root === false ? '' : $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'eduXidUpdateAgent.bat';
$app = $root === false ? '' : $root . DIRECTORY_SEPARATOR . 'eduXid' . DIRECTORY_SEPARATOR . 'artisan';
$directory = $root === false ? '' : $root . DIRECTORY_SEPARATOR . '.eduxid-updater';
$stateFile = $directory . DIRECTORY_SEPARATOR . 'task-bootstrap.json';
$logFile = $directory . DIRECTORY_SEPARATOR . 'task-bootstrap.log';
$dryRun = in_array('--dry-run', $argv, true);
$checkStatus = in_array('--status', $argv, true);
$ifDue = in_array('--if-due', $argv, true);
$testScheduler = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--test-scheduler=')) {
        $testScheduler = substr($arg, strlen('--test-scheduler='));
    }
}

function bootstrapLog(string $logFile, string $message): void
{
    @file_put_contents($logFile, gmdate('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function bootstrapFail(string $logFile, string $message, int $status = 1): never
{
    bootstrapLog($logFile, 'ERROR ' . $message);
    fwrite(STDERR, '[eduXid Auto-Update] ' . $message . PHP_EOL);
    exit($status);
}
function schedulerCall(string $program, array $args, string $root): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open(array_merge([$program], $args), $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        return [127, 'scheduler process could not start'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim((string) $stdout . ' ' . (string) $stderr)];
}

if ($root === false || !is_file($app)) {
    bootstrapFail($logFile, 'Folder instalasi eduXid tidak ditemukan.', 2);
}
if (!is_dir($directory)) @mkdir($directory, 0770, true);
$identity = strtolower(str_replace('\\', '/', rtrim($root, '\\/')));
$taskName = 'eduXid Auto Update ' . substr(hash('sha256', $identity), 0, 12);
if (in_array('--dry-run', $argv, true)) {
    echo json_encode(['mode' => 'startup_only', 'task_to_remove' => $taskName], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
if (PHP_OS_FAMILY !== 'Windows' && $testScheduler === null) {
    // Pada Linux pengujian/tinjauan kode tidak ada scheduled task Windows.
    exit(0);
}
$scheduler = $testScheduler ?? 'schtasks.exe';
[$exists] = schedulerCall($scheduler, ['/Query', '/TN', $taskName], $root);
if ($exists === 0) {
    [$removed, $output] = schedulerCall($scheduler, ['/Delete', '/F', '/TN', $taskName], $root);
    if ($removed !== 0) {
        bootstrapLog($logFile, 'WARNING tidak dapat menonaktifkan task lama: ' . $output);
        fwrite(STDERR, '[UPDATE] Task lama belum dapat dihapus: ' . $taskName . PHP_EOL);
        exit(8);
    }
    bootstrapLog($logFile, 'OK task periodik lama dinonaktifkan: ' . $taskName);
}
@file_put_contents($directory . DIRECTORY_SEPARATOR . 'startup-only.json', json_encode([
    'mode' => 'startup_only', 'task_name' => $taskName, 'updated_at' => gmdate('c')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
if (in_array('--status', $argv, true)) echo '[OK] Auto-Update: hanya saat launcher dibuka.' . PHP_EOL;
exit(0);
