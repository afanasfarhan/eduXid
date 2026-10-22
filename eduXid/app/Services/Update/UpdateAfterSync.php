<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * V21R34: Called by the sync daemon after each cycle, never by a background scheduler.
 * Check immediately after the first confirmed Cloud connection or when connectivity
 * comes back. Recheck a healthy connection periodically (15 minutes).
 * An offline check never blocks offline-first LMS processing.
 */
class UpdateAfterSync
{
    public const ONLINE_INTERVAL_SECONDS = 900;
    public const OFFLINE_RETRY_SECONDS = 60;
    public const PENDING_RETRY_SECONDS = 60;

    /**
     * V21R159: updates happen at launcher startup ONLY, not during LmsSync cycles.
     * Cloud discovery remains in EduxidUpdateAutoCommand --startup, using
     * authenticated HTTPS and Ed25519 verification before installation.
     */
    public function trigger(bool $authenticatedCloudContact = false): string
    {
        return 'STARTUP_ONLY';
    }

    /** Re-arm immediately after an authenticated sync transport failure. */
    public function markDisconnected(): void
    {
        $root = rtrim((string) config('eduxid.install_root'), '\\/');
        $file = $root . DIRECTORY_SEPARATOR . '.eduxid-updater' . DIRECTORY_SEPARATOR . 'cloud-connected-state.json';
        if (!is_file($file)) return;
        $raw = @file_get_contents($file);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || ($state['last_result'] ?? '') === 'OFFLINE') return;
        $state['last_result'] = 'OFFLINE';
        $state['next_attempt_at'] = 0;
        $this->saveState($file, $state);
    }

    private function cloudReachable(): bool
    {
        try {
            $base = rtrim((string) (config('eduxid.update_server_url') ?: config('eduxid.sync_server', '')), '/');
            if (!str_starts_with($base, 'https://')) return false;
            $request = Http::connectTimeout(3)->timeout(5)->withoutRedirecting();
            $ca = (string) config('eduxid.ca_cert');
            if ($ca !== '' && is_file($ca)) $request = $request->withOptions(['verify' => $ca]);
            return $request->get($base . '/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function saveState(string $file, array $data): void
    {
        // Only local timestamps / status codes. No token, device identity or URL.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) @unlink($tmp);
        }
    }
}
