<?php

namespace App\Console\Commands;

use App\Services\Update\CurrentVersion;
use Illuminate\Console\Command;

/** Read-only verification for V21R34. Never prints tokens, identities or keys. */
class EduxidUpdateConnectionStatusCommand extends Command
{
    protected $signature = 'eduxid:update:connection-status';
    protected $description = 'Status pemeriksaan update setelah Cloud tersambung (read only)';

    public function handle(CurrentVersion $current): int
    {
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $stateFile = $root . DIRECTORY_SEPARATOR . '.eduxid-updater' . DIRECTORY_SEPARATOR . 'cloud-connected-state.json';
        $raw = @file_get_contents($stateFile);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) $state = [];
        $this->info('EDUXID V21R34 — UPDATE CONNECTION STATUS (READ ONLY)');
        $this->line('EDITION: ' . strtolower((string) config('eduxid.edition', 'school')));
        $this->line('VERSION: ' . $current->get());
        $this->line('PROTOCOL: ' . (string) config('eduxid.identity_protocol', 'unknown'));
        $this->line('STATUS: ' . (string) ($state['last_result'] ?? 'NOT_CHECKED'));
        $this->line('LAST_CHECK: ' . (!empty($state['last_checked_at']) ? date(DATE_ATOM, (int) $state['last_checked_at']) : 'NEVER'));
        $this->line('NEXT_ATTEMPT: ' . (!empty($state['next_attempt_at']) ? date(DATE_ATOM, (int) $state['next_attempt_at']) : 'ON_NEXT_CONNECTED_CYCLE'));
        $key = trim((string) config('eduxid.update_public_key', ''));
        $decoded = ctype_xdigit($key) && strlen($key) === 64 ? @hex2bin($key) : @base64_decode($key, true);
        $this->line('ED25519_TRUST_PUBLIC_KEY: ' . (is_string($decoded) && strlen($decoded) === 32 ? 'READY' : 'MISSING_OR_INVALID'));
        $this->line('WINDOWS_UPDATER: ' . (is_file($root . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'eduXidUpdateRun.cmd') ? 'READY' : 'MISSING'));
        $this->line('NOTE: PWA diperbarui melalui Service Worker masing-masing, bukan ZIP Windows.');
        return self::SUCCESS;
    }
}
