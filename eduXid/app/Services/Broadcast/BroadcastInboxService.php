<?php

namespace App\Services\Broadcast;

use App\Services\Sync\EduxidCloudConfig;
use App\Models\SyncDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Inbox Pesan eduXid. Sumber cache tetap lokal, Cloud hanya perantara.
 * Penerimaan (ack received) tidak menandai pesan sebagai dibaca.
 */
class BroadcastInboxService
{
    public const ROLES = ['admin', 'guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah', 'pengajar'];

    public function allowed($user): bool
    {
        return $user !== null && in_array(strtolower((string) $user->role), self::ROLES, true);
    }

    public function deviceId($user): string
    {
        if (!$this->allowed($user)) {
            return '';
        }
        if (strtolower((string) $user->role) === 'admin') {
            $schoolId = trim((string) config('eduxid.school_id'));
            if ($schoolId === '' && Schema::hasTable('schools')) {
                $schoolId = trim((string) (DB::table('schools')->value('npsn') ?? ''));
            }
            return $schoolId !== '' ? 'admin-school-' . $schoolId : '';
        }
        return trim((string) config('eduxid.device_id'));
    }

    /** @return Collection<int, object> */
    public function cached($user, int $limit = 3, bool $unreadOnly = true): Collection
    {
        if (!$this->allowed($user) || !Schema::hasTable('broadcast_inbox_cache')) {
            return collect();
        }
        $deviceId = $this->deviceId($user);
        if ($deviceId === '') {
            return collect();
        }
        $query = DB::table('broadcast_inbox_cache')->where('device_id', $deviceId);
        if (Schema::hasColumn('broadcast_inbox_cache', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        return $query->orderByDesc('issued_at')->limit(max(1, min(100, $limit)))->get();
    }

    public function unreadCount($user): int
    {
        if (!$this->allowed($user) || !Schema::hasTable('broadcast_inbox_cache')) {
            return 0;
        }
        $deviceId = $this->deviceId($user);
        if ($deviceId === '') {
            return 0;
        }
        $query = DB::table('broadcast_inbox_cache')->where('device_id', $deviceId)->whereNull('read_at');
        if (Schema::hasColumn('broadcast_inbox_cache', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        return $query->count();
    }

    /**
     * Ambil pesan Cloud dan pertahankan salinan lokal untuk mode offline.
     * Mengembalikan status agar kegagalan autentikasi tidak tersembunyi
     * ketika Dasbor belum menampilkan pesan apa pun.
     *
     * @return array{status:string,message:string}
     */
    public function refresh(Request $request, int $timeoutSeconds = 8): array
    {
        $user = $request->user();
        if (!$this->allowed($user)) {
            return $this->result('unavailable', 'Akun ini tidak memiliki akses ke pesan.');
        }
        if (!Schema::hasTable('broadcast_inbox_cache')) {
            return $this->result('setup', 'Penyimpanan pesan lokal belum disiapkan. Jalankan migrasi database eduXid.');
        }
        $deviceId = $this->deviceId($user);
        if ($deviceId === '') {
            return $this->result('setup', 'Identitas perangkat belum tersedia. Periksa pairing perangkat.');
        }

        try {
            $auth = $this->credentials($request);
            $client = $this->cloudClient($auth['scheme'], $auth['token']);
            $response = $client->connectTimeout(min(2, max(1, $timeoutSeconds)))
                ->timeout(max(1, min(8, $timeoutSeconds)))
                ->get('/api/broadcast/inbox');
            if (!$response->successful() || $response->json('success') !== true) {
                $code = (string) ($response->json('error.code') ?? '');
                if (in_array($response->status(), [401, 403], true)) {
                    Log::warning('Autentikasi inbox pesan ditolak Cloud', ['http_status' => $response->status(), 'code' => $code, 'device_id' => $deviceId]);
                    return $this->result('auth_error', 'Cloud menolak autentikasi perangkat (HTTP ' . $response->status() . '). Periksa pairing GTK.');
                }
                Log::warning('Permintaan inbox pesan gagal', ['http_status' => $response->status(), 'code' => $code]);
                return $this->result('cloud_error', 'Belum dapat mengambil pesan Cloud (HTTP ' . $response->status() . '). Pesan tersimpan tetap tersedia offline.');
            }

            $items = $response->json('data.messages');
            if (!is_array($items)) {
                return $this->result('cloud_error', 'Format kotak masuk Cloud tidak sesuai. Pesan lokal tetap tersedia.');
            }
            $receivedIds = [];
            foreach (array_slice($items, 0, 100) as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }
                $id = (string) $item['id'];
                $receivedIds[] = $id;
                DB::table('broadcast_inbox_cache')->updateOrInsert(
                    ['device_id' => $deviceId, 'message_id' => $id],
                    [
                        'title' => mb_substr((string) ($item['title'] ?? ''), 0, 160),
                        'body' => (string) ($item['body'] ?? ''),
                        'sender_type' => mb_substr((string) ($item['sender_type'] ?? ''), 0, 40),
                        'priority' => (($item['priority'] ?? '') === 'important') ? 'important' : 'normal',
                        'issued_at' => (string) ($item['created_at'] ?? now()->toISOString()),
                        'updated_at' => now(),
                        'created_at' => now(),
                        // read_at tidak disetel: status baca lokal tidak direset ketika dipoll.
                        // deleted_at juga tidak diubah: pesan yang dihapus tidak muncul lagi.
                    ]
                );
            }
            if ($receivedIds !== []) {
                try {
                    $client->connectTimeout(min(2, max(1, $timeoutSeconds)))
                        ->timeout(min(4, max(1, $timeoutSeconds)))->post('/api/broadcast/ack', [
                        'ids' => $receivedIds, 'read' => false,
                    ]);
                } catch (Throwable $ignored) {
                    // ACK penerimaan best-effort; jangan membuang cache berhasil.
                }
            }
            return $this->result('ok', count($items) > 0
                ? 'Pesan terbaru sudah diperiksa melalui eduXid Cloud.'
                : 'Terhubung ke eduXid Cloud. Belum ada pesan masuk untuk perangkat ini.');
        } catch (Throwable $e) {
            Log::warning('Gagal memuat inbox Cloud, memakai cache lokal', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Koneksi ke eduXid Cloud belum berhasil. Pesan lokal tetap tersedia.';
            return $this->result('offline', $message);
        }
    }

    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }

    /** Tandai terbaca saat halaman Pesan & Pengumuman benar-benar dibuka. */
    public function markRead(Request $request): void
    {
        $user = $request->user();
        if (!$this->allowed($user) || !Schema::hasTable('broadcast_inbox_cache')) {
            return;
        }
        $deviceId = $this->deviceId($user);
        if ($deviceId === '') {
            return;
        }
        $query = DB::table('broadcast_inbox_cache')->where('device_id', $deviceId)
            ->whereNull('read_at');
        $hasDeletedAt = Schema::hasColumn('broadcast_inbox_cache', 'deleted_at');
        if ($hasDeletedAt) {
            $query->whereNull('deleted_at');
        }
        $ids = $query->orderByDesc('issued_at')->limit(100)->pluck('message_id')->all();
        if ($ids === []) {
            return;
        }
        $updateQuery = DB::table('broadcast_inbox_cache')->where('device_id', $deviceId)
            ->whereIn('message_id', $ids)->whereNull('read_at');
        if ($hasDeletedAt) {
            $updateQuery->whereNull('deleted_at');
        }
        $updateQuery->update(['read_at' => now(), 'updated_at' => now()]);
        // Sinkronkan tanda sudah dibaca jika tersedia. Tidak membatalkan status lokal saat offline.
        try {
            $auth = $this->credentials($request);
            $http = $this->cloudClient($auth['scheme'], $auth['token'])->timeout(4);
            $http->post('/api/broadcast/ack', ['ids' => $ids, 'read' => true]);
        } catch (Throwable $e) {
            Log::info('Status baca pesan disimpan lokal', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Hapus pesan untuk perangkat yang sedang login saja.
     * Baris disimpan sebagai tombstone, agar polling Cloud tidak memunculkannya kembali.
     * Penghapusan ini bukan perintah global kepada Cloud atau GTK lain.
     */
    public function deleteMessage(Request $request, string $messageId): bool
    {
        $user = $request->user();
        if (!$this->allowed($user)) {
            throw new RuntimeException('Akun ini tidak diizinkan menghapus pesan.');
        }
        if (!Schema::hasTable('broadcast_inbox_cache') ||
            !Schema::hasColumn('broadcast_inbox_cache', 'deleted_at')) {
            throw new RuntimeException('Jalankan migrasi database eduXid untuk mengaktifkan penghapusan pesan.');
        }
        $deviceId = $this->deviceId($user);
        $messageId = trim($messageId);
        if ($deviceId === '' || $messageId === '' || strlen($messageId) > 191) {
            return false;
        }

        return DB::table('broadcast_inbox_cache')
            ->where('device_id', $deviceId)
            ->where('message_id', $messageId)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'read_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    /** Kredensial bersama untuk ambil, kirim dan ACK pesan. */
    public function credentials(Request $request): array
    {
        $user = $request->user();
        if (!$this->allowed($user)) {
            throw new RuntimeException('Akun tidak diizinkan mengakses Pesan & Pengumuman.');
        }
        $role = strtolower((string) $user->role);
        if ($role === 'admin') {
            $token = EduxidCloudConfig::adminToken();
            if ($token === '') {
                throw new RuntimeException('Token Admin Sekolah belum dipairing.');
            }
            return ['scheme' => 'Admin', 'token' => $token];
        }

        if (!$user->teacher()) {
            throw new RuntimeException('Identitas GTK belum ditemukan di Data Pokok.');
        }
        $deviceId = $this->deviceId($user);
        $login = trim((string) $user->login_id);
        if ($login === '' || $deviceId === '' || !str_ends_with($deviceId, '-' . $login . '-PC')) {
            throw new RuntimeException('Perangkat PC GTK yang login belum dipairing sesuai identitasnya.');
        }
        // WAJIB memakai model: SyncDevice memiliki cast `sync_token => encrypted`.
        // DB::table(...)->value('sync_token') menghasilkan ciphertext, bukan token Bearer!
        $device = SyncDevice::query()
            ->where('device_id', $deviceId)
            ->where('device_type', 'teacher')
            ->where('is_active', true)
            ->first();
        if (!$device) {
            throw new RuntimeException('Perangkat GTK belum aktif/terdaftar untuk sinkronisasi Cloud.');
        }
        $schoolId = trim((string) config('eduxid.school_id', ''));
        if ($schoolId !== '' && trim((string) $device->school_id) !== $schoolId) {
            throw new RuntimeException('Identitas NPSN perangkat GTK tidak sesuai dengan instalasi.');
        }
        $token = trim((string) $device->sync_token);
        if ($token === '') {
            throw new RuntimeException('Token pairing Cloud GTK belum tersedia.');
        }
        return ['scheme' => 'Bearer', 'token' => $token];
    }

    public function cloudUrl(): string
    {
        // Sumber dari Setup Cloud didahulukan, dengan fallback konfigurasi bundled.
        $url = EduxidCloudConfig::syncServer();
        if ($url === '') {
            $url = rtrim((string) config('eduxid.sync_server', ''), '/');
        }
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Alamat eduXid Cloud HTTPS belum dikonfigurasi dengan benar.');
        }
        return $url;
    }

    public function cloudClient(string $scheme, string $token): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::baseUrl($this->cloudUrl())
            ->acceptJson()
            ->withHeaders(['Authorization' => $scheme . ' ' . $token]);
        $ca = trim((string) config('eduxid.ca_cert', ''));
        if ($ca !== '' && is_file($ca)) {
            return $http->withOptions(['verify' => $ca]);
        }
        return $http;
    }
}
