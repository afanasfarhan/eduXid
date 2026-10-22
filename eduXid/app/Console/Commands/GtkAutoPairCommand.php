<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncDevice;
use App\Services\Sync\OpaqueRouteRegistryV21;
use App\Services\Sync\OpaqueIdentityV21;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class GtkAutoPairCommand extends Command
{
    protected $signature = 'eduxid:gtk-auto-pair {--force : Pair ulang bila ticket masih tersedia}';
    protected $description = 'First-run auto-pair untuk Paket GTK eduXid.';

    public function handle(): int
    {
        $ticketPath = storage_path('app/gtk-autopair.json');
        if (!is_file($ticketPath)) {
            $this->line('[GTK AUTO-PAIR] Tidak ada ticket pending.');
            return self::SUCCESS;
        }

        try {
            $raw = file_get_contents($ticketPath);
            $ticket = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
            if (!is_array($ticket)) {
                throw new RuntimeException('Ticket auto-pair tidak valid.');
            }

            $strict = OpaqueRouteRegistryV21::enabled();
            $expectedSchema = $strict ? 'EDUXID-GTK-AUTOPAIR-V21-OPAQUE' : 'EDUXID-GTK-AUTOPAIR-V1';
            if (($ticket['schema'] ?? '') !== $expectedSchema) {
                throw new RuntimeException('Schema ticket auto-pair tidak dikenali.');
            }

            $schoolId = trim((string) ($ticket['school_id'] ?? ''));
            $loginId = trim((string) ($ticket['login_id'] ?? ''));
            $deviceId = trim((string) ($ticket['device_id'] ?? ''));
            $enrollmentToken = trim((string) ($ticket['enrollment_token'] ?? ''));
            $cloudLogin = $strict ? trim((string) ($ticket['cloud_alias'] ?? '')) : $loginId;

            if ($schoolId === '' || $loginId === '' || $deviceId === '' || $enrollmentToken === '') {
                throw new RuntimeException('Ticket auto-pair tidak lengkap.');
            }

            if ($strict) {
                if (!OpaqueIdentityV21::isAlias($cloudLogin, 'teacher')
                    || !Schema::hasTable('cloud_identity_alias_routes')) {
                    throw new RuntimeException('BLOCKED V21: registry/alias ticket GTK belum siap.');
                }
                // Ensure APP_KEY can bind the Dapodik account to this alias BEFORE
                // consuming a single-use BND1 token. The bound token protects
                // against alias substitution in transit.
                OpaqueRouteRegistryV21::lookupDigest($schoolId, 'teacher', $loginId);

            }
            $expectedDeviceId = $schoolId . '-' . $cloudLogin . '-PC';
            if (!hash_equals($expectedDeviceId, $deviceId)) {
                throw new RuntimeException('Device ID ticket tidak canonical.');
            }

            $dbSchool = trim((string) DB::table('schools')->value('npsn'));
            if ($dbSchool === '' || !hash_equals($schoolId, $dbSchool)) {
                throw new RuntimeException('NPSN bundle tidak sama dengan ticket auto-pair.');
            }

            $user = DB::table('users')->where('login_id', $loginId)->first();
            if (!$user) {
                throw new RuntimeException('Akun pemilik GTK tidak ditemukan dalam data lokal.');
            }

            $server = rtrim((string) config('eduxid.sync_server', env('EDUXID_SYNC_SERVER', '')), '/');
            if ($server === '') {
                throw new RuntimeException('EDUXID_SYNC_SERVER belum dikonfigurasi.');
            }
            if ($strict && !str_starts_with(strtolower($server), 'https://')) {
                throw new RuntimeException('BLOCKED V21: HTTPS wajib untuk pairing.');
            }

            $response = $this->request()->post($server . '/api/device/register', [
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'device_type' => 'teacher',
                'user_id' => (string) $user->id,
                'login_id' => $cloudLogin,
                'device_name' => $strict ? 'eduXid GTK PC' : 'eduXid Guru ' . $loginId,
                'enrollment_token' => $enrollmentToken,
            ]);

            if (!$response->successful()) {
                $code = trim((string) ($response->json('error.code') ?? 'HTTP_' . $response->status()));
                $message = trim((string) ($response->json('error.message') ?? $response->body()));
                throw new RuntimeException('Cloud menolak auto-pair [' . $code . ']: ' . mb_substr($message, 0, 500));
            }

            $deviceToken = trim((string) ($response->json('data.device_token') ?? $response->json('device_token') ?? ''));
            if ($deviceToken === '') {
                throw new RuntimeException('Cloud tidak mengembalikan device_token.');
            }

            $me = $this->request()->withToken($deviceToken)->get($server . '/api/device/me');
            if (!$me->successful()) {
                throw new RuntimeException('Verifikasi token baru gagal HTTP ' . $me->status() . ': ' . mb_substr($me->body(), 0, 300));
            }

            $remoteSchool = trim((string) ($me->json('data.school_id') ?? ''));
            $remoteLogin = trim((string) ($me->json('data.login_id') ?? ''));
            $remoteDevice = trim((string) ($me->json('data.device_id') ?? ''));
            $remoteType = trim((string) ($me->json('data.device_type') ?? ''));

            if (!hash_equals($schoolId, $remoteSchool) || !hash_equals($cloudLogin, $remoteLogin) || !hash_equals($deviceId, $remoteDevice) || $remoteType !== 'teacher') {
                throw new RuntimeException('Identitas device hasil auto-pair tidak cocok dengan ticket bundle.');
            }

            DB::transaction(function () use ($schoolId, $loginId, $cloudLogin, $strict, $deviceId, $deviceToken, $user): void {
                if ($strict) {
                    app(OpaqueRouteRegistryV21::class)->provision($schoolId, 'teacher', $loginId, $cloudLogin);
                }
                if (Schema::hasTable('sync_devices')) {
                    SyncDevice::query()->where('device_type', 'teacher')->delete();

                    $device = new SyncDevice();
                    $device->school_id = $schoolId;
                    $device->device_id = $deviceId;
                    $device->device_type = 'teacher';
                    if (Schema::hasColumn('sync_devices', 'user_id')) {
                        $device->user_id = (int) $user->id;
                    }
                    if (Schema::hasColumn('sync_devices', 'device_name')) {
                        $device->device_name = $strict ? 'eduXid GTK PC' : 'eduXid Guru ' . $loginId;
                    }
                    $device->sync_token = $deviceToken;
                    $device->is_active = true;
                    if (Schema::hasColumn('sync_devices', 'last_synced_at')) {
                        $device->last_synced_at = now();
                    }
                    $device->save();
                }

                foreach (['sync_cursors', 'sync_dead_letters'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                if (Schema::hasTable('app_settings')) {
                    DB::table('app_settings')
                        ->whereIn('key', ['daemon::heartbeat', 'master::owner_device'])
                        ->delete();
                }
            });

            $done = [
                'schema' => 'EDUXID-GTK-AUTOPAIR-DONE-V1',
                'school_id' => $schoolId,
                'login_id' => $strict ? null : $loginId,
                'cloud_alias' => $strict ? $cloudLogin : null,
                'device_id' => $deviceId,
                'paired_at' => now()->toIso8601String(),
            ];
            file_put_contents(storage_path('app/gtk-autopair.done.json'), json_encode($done, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
            @unlink($ticketPath);

            $this->info('[GTK AUTO-PAIR] BERHASIL: ' . ($strict ? 'alias anonim telah terverifikasi' : $deviceId));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('[GTK AUTO-PAIR] GAGAL: ' . $e->getMessage());
            $this->line('[GTK AUTO-PAIR] Ticket dipertahankan agar pairing otomatis dicoba lagi pada startup berikutnya.');
            return self::FAILURE;
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(30)->acceptJson()->asJson();
        $ca = trim((string) config('eduxid.ca_cert', ''));
        if ($ca !== '' && is_file($ca)) {
            $request = $request->withOptions(['verify' => $ca]);
        }
        return $request;
    }
}
