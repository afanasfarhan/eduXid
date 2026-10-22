<?php

namespace App\Services\Update;

use RuntimeException;

class UpdateLauncher
{
    public function launch(int $delaySeconds = 3): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Silent updater hanya tersedia di Windows.');
        }
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $launcher = $root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'eduXidUpdateRun.cmd';
        if (!is_file($launcher)) {
            throw new RuntimeException('eduXidUpdateRun.cmd tidak ditemukan.');
        }
        // CMD START spawns a detached updater without PowerShell.
        // Protect the command-line interpreter from unexpected expansion.
        if (preg_match('/["%\r\n]/', $launcher)) {
            throw new RuntimeException('Lokasi launcher mengandung karakter terlarang.');
        }
        $cmd = 'cmd.exe /D /C start "" /MIN "' . $launcher . '"';
        $handle = @popen($cmd, 'r');
        if (!is_resource($handle)) {
            throw new RuntimeException('CMD gagal membuka eduXidUpdateRun.cmd.');
        }
        pclose($handle);
    }
}
