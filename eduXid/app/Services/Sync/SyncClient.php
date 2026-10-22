<?php

namespace App\Services\Sync;

use App\Models\SyncDevice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncClient
{
    protected string $syncServer;
    protected ?string $caCert;
    protected ?string $syncToken;
    protected int $timeout;
    protected int $retryTimes;
    protected const MAX_ACK_EVENTS = 100;

    /*
     * [G1-FIX] Ukuran batch push direktori siswa.
     * 100 akun/batch terbukti melampaui waktu proses Worker
     * (HTTP 500 / timeout 30 dtk). 25 akun/batch selesai < 5 dtk.
     */
    protected const BULK_CHUNK_SIZE = 25;

    /* Timeout khusus jalur bulk (detik). */
    protected const BULK_TIMEOUT = 120;

    /* Timeout khusus unggah file (detik) — PDF hingga 50MB. */
    protected const UPLOAD_TIMEOUT = 180;

    public function __construct()
    {
        /*
         * [G1] URL Cloud via EduxidCloudConfig — database (Setup Cloud)
         * dulu, fallback ke config .env. Konsisten dengan panel & service.
         */
        $this->syncServer = \App\Services\Sync\EduxidCloudConfig::syncServer();

        $caCert = config('eduxid.ca_cert');
        $caCert = is_string($caCert) ? trim($caCert) : '';

        $this->caCert = $caCert !== '' && is_file($caCert) ? $caCert : null;

        if ($caCert !== '' && $this->caCert === null) {
            Log::warning('CA certificate eduXid dikonfigurasi tetapi file tidak ditemukan — verifikasi SSL memakai CA sistem (php.ini curl.cainfo).', [
                'ca_cert' => $caCert,
            ]);
        }

        $token = config('eduxid.sync_token');
        $this->syncToken = is_string($token) && trim($token) !== '' ? trim($token) : null;
        $this->timeout = max(5, (int) config('eduxid.timeout', 15));
        $this->retryTimes = max(0, (int) config('eduxid.retry_times', 2));
    }

    /* ============================================================
     * FILE UPLOAD — [REWRITE-16] KONTRAK TERKUNCI
     * ============================================================
     * AKAR BUG PDF-403 ADA DI SINI. Urutan parameter:
     *
     *   uploadFile(
     *       1. $deviceToken  — token device guru (Bearer),
     *       2. $fileKey      — key objek R2, mis. 'materials/{syncId}.pdf',
     *       3. $absolutePath — PATH FILE LOKAL di laptop,
     *       4. $mime         — content type.
     *   )
     *
     * Pemanggil lama menukar posisi 1↔3: token mendarat di slot
     * $absolutePath → is_file('edx_dev_...') gagal → dulu ditelan
     * senyap oleh catch → payload terkirim tanpa file → 403 permanen
     * di PWA. JANGAN mengubah urutan ini tanpa memeriksa SEMUA
     * pemanggil (LmsSyncService::pushMaterial, perintah
     * eduxid:migrate-material-files).
     *
     * Contoh pemanggilan yang benar:
     *   $client->uploadFile(
     *       $device->sync_token,
     *       'materials/' . $syncId . '.pdf',
     *       $disk->path($material->file_path),
     *       'application/pdf'
     *   );
     *
     * [UPLOAD-CA]   CA certificate kini diterapkan (dulu Http mentah
     *               — SSL gagal di sekolah dengan CA kustom).
     * [UPLOAD-RETRY] ConnectionException di-retry (idempoten: key
     *               sama = overwrite di R2).
     * [UPLOAD-STREAM] Body dikirim sebagai stream — file 50MB tidak
     *               lagi membebani memory_limit.
     * ============================================================ */
    public function uploadFile(
        string $deviceToken,
        string $fileKey,
        string $absolutePath,
        string $mime = 'application/pdf'
    ): array {
        $deviceToken = trim($deviceToken);
        $fileKey = trim($fileKey);
        if (CloudOutboundPrivacyGuardV21::enabled()) CloudOutboundPrivacyGuardV21::assertFileKey($fileKey);

        if ($deviceToken === '') {
            throw new RuntimeException(
                'Device token kosong — perangkat guru belum ter-pairing '
                . '(tidak ada sync_token di sync_devices).'
            );
        }

        if ($fileKey === '') {
            throw new RuntimeException('File key kosong untuk unggah file materi.');
        }

        if (!is_file($absolutePath)) {
            throw new RuntimeException('File tidak ditemukan: ' . $absolutePath);
        }

        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('File tidak dapat dibaca: ' . $absolutePath);
        }

        try {
            $response = Http::timeout(self::UPLOAD_TIMEOUT)
                ->connectTimeout(15)
                ->retry(
                    2,
                    750,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException
                )
                ->withToken($deviceToken)
                ->withHeaders([
                    'X-File-Key'   => $fileKey,
                    'Content-Type' => $mime,
                ])
                ->withBody($stream, $mime)
                ->withOptions($this->applyCaCertificateOptions())
                ->post($this->endpoint('/api/file/upload'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->assertHttpOk($response, 'FILE UPLOAD');
        $result = $this->assertJsonObject($response, 'FILE UPLOAD');
        $this->assertSuccess($result, 'FILE UPLOAD');

        return $result;
    }

    /**
     * Header otorisasi Admin untuk jalur bulk direktori akun.
     * [F5-FIX] Dulu dipanggil tapi tidak pernah didefinisikan →
     * "Call to undefined method" saat Sinkronkan Sekarang ditekan.
     * Kunci diambil dari sumber tunggal (EduxidCloudConfig —
     * Setup Cloud → .env school → .env master).
     */
    protected function adminAuthHeader(): string
    {
        $adminKey = \App\Services\Sync\EduxidCloudConfig::adminToken();

        if ($adminKey === '') {
            throw new RuntimeException(
                'Kunci admin belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud, tempel '
                . 'kunci dari operator pusat.'
            );
        }

        return 'Admin ' . $adminKey;
    }

    /* ============================================================
     * DIREKTORI AKUN SISWA (bulk)
     * ============================================================ */

    public function pushStudentAccountDirectoryBulk(
        string $schoolId,
        array $accounts
    ): array {
        $schoolId = trim($schoolId);

        if ($schoolId === '') {
            throw new RuntimeException(
                'school_id wajib diisi untuk sinkronisasi akun siswa.'
            );
        }

        if ($accounts === []) {
            return [
                'success' => true,
                'school_id' => $schoolId,
                'upserted' => 0,
                'batches' => 0,
            ];
        }

        /*
         * [G1] Kunci via EduxidCloudConfig — token sekolah dari
         * Setup Cloud dulu, fallback master (.env laptop operator).
         */
        $adminKey = \App\Services\Sync\EduxidCloudConfig::adminToken();

        if ($adminKey === '') {
            throw new RuntimeException(
                'Kunci admin belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud, tempel '
                . 'kunci dari operator pusat.'
            );
        }

        /*
         * [G1-FIX] Batch KECIL (25 akun) + idempotensi ON CONFLICT di
         * Worker membuat retry aman.
         */
        if (CloudOutboundPrivacyGuardV21::enabled()) {
            CloudOutboundPrivacyGuardV21::assertPayload(['accounts' => $accounts], $schoolId);
        }
        $batches = array_chunk($accounts, self::BULK_CHUNK_SIZE);

        $totalUpserted = 0;
        $batchResults = [];

        Log::info('[G1] PUSH STUDENT ACCOUNTS dimulai.', [
            'school_id' => $schoolId,
            'total_accounts' => count($accounts),
            'batches' => count($batches),
            'chunk_size' => self::BULK_CHUNK_SIZE,
        ]);

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;

            $response = Http::timeout(self::BULK_TIMEOUT)
                ->connectTimeout(15)
                ->retry(
                    2,
                    750,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException
                )
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => $this->adminAuthHeader(),
                ])
                ->withOptions($this->applyCaCertificateOptions())
                ->post(
                    $this->endpoint('/api/admin/student-accounts/sync'),
                    [
                        'school_id' => $schoolId,
                        'accounts' => $batch,
                    ]
                );

            $this->assertHttpOk($response, 'PUSH STUDENT ACCOUNTS BATCH ' . $batchNumber);
            $result = $this->assertJsonObject($response, 'PUSH STUDENT ACCOUNTS BATCH ' . $batchNumber);
            $this->assertSuccess($result, 'PUSH STUDENT ACCOUNTS BATCH ' . $batchNumber);

            $data = $this->arrayValue($result['data'] ?? null);

            $upserted = (int) (
                $data['upserted']
                ?? $result['upserted']
                ?? count($batch)
            );

            $totalUpserted += max(0, $upserted);

            $batchResults[] = [
                'batch' => $batchNumber,
                'count' => count($batch),
                'upserted' => $upserted,
            ];

            Log::info('[G1] Batch siswa terkirim.', [
                'batch' => $batchNumber,
                'of' => count($batches),
                'count' => count($batch),
                'upserted' => $upserted,
            ]);
        }

        return [
            'success' => true,
            'school_id' => $schoolId,
            'upserted' => $totalUpserted,
            'batches' => count($batches),
            'batch_results' => $batchResults,
        ];
    }

    protected function applyCaCertificateOptions(): array
    {
        return $this->caCert !== null
            ? ['verify' => $this->caCert]
            : [];
    }

    /* ============================================================
     * PULL
     * ============================================================
     * [PULL-HASMORE] Cloud mengelola delivery via status event
     * (PENDING → DELIVERED → ACKNOWLEDGED) — cursor efektif vestigial
     * (Cloud tidak membaca/mengembalikannya; fallback menjaga
     * kontrak pemanggil lama). `has_more` kini diteruskan sebagai
     * kunci TAMBAHAN (aditif — pemanggil lama tidak terpengaruh).
     * ============================================================ */
    public function pull(string $schoolId, string $deviceId, int $cursor = 0, ?int $homeroomId = null, array $skipEventIds = []): array
    {
        $schoolId = trim($schoolId);
        $deviceId = trim($deviceId);
        $this->validateIdentity($schoolId, $deviceId);
        $cursor = max(0, $cursor);
        if ($homeroomId !== null) {
            $homeroomId = (int) $homeroomId;
            if ($homeroomId <= 0) {
                throw new RuntimeException('Homeroom ID tidak valid.');
            }
        }
        // Worker V21 authenticates the target device via Bearer token; its URL privacy
        // guard forbids device_id even when it contains an opaque alias.
        $params = ['cursor' => $cursor];
        if ($homeroomId !== null) {
            $params['homeroom_id'] = $homeroomId;
        }
        // V21R21: lewatkan event yang gagal hanya di request ini. Tidak ACK.
        $skipEventIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id), $skipEventIds
        ), static fn (string $id): bool => $id !== '')));
        if (count($skipEventIds) > 100 || array_filter($skipEventIds,
            static fn (string $id): bool => strlen($id) > 255)) {
            throw new RuntimeException('Daftar skip event tidak valid (maksimal 100 ID, 255 karakter/ID).');
        }
        if ($skipEventIds !== []) {
            $params['skip_event_ids'] = json_encode($skipEventIds, JSON_THROW_ON_ERROR);
        }
        $response = $this->cloudHttp($schoolId, $deviceId)->get($this->endpoint('/api/sync/pull'), $params);
        $this->assertHttpOk($response, 'PULL');
        $result = $this->assertJsonObject($response, 'PULL');
        $this->assertSuccess($result, 'PULL');
        $data = $this->arrayValue($result['data'] ?? null);
        $events = $this->arrayValue($data['events'] ?? null);
        $nextCursor = (int) ($data['next_cursor'] ?? $result['next_cursor'] ?? $cursor);
        return [
            'success' => true,
            'changes' => array_values($events),
            'next_cursor' => max($cursor, $nextCursor),
            'cursor' => $cursor,
            /* [PULL-HASMORE] Aditif — sinyal antrean belum habis. */
            'has_more' => (bool) ($data['has_more'] ?? false),
            'count' => count($events),
        ];
    }

    public function ack(string $schoolId, string $deviceId, array $eventIds): array
    {
        $schoolId = trim($schoolId);
        $deviceId = trim($deviceId);
        $this->validateIdentity($schoolId, $deviceId);
        $eventIds = collect($eventIds)
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
        if ($eventIds === []) {
            return ['success' => true, 'acknowledged' => 0];
        }
        if (count($eventIds) > self::MAX_ACK_EVENTS) {
            throw new RuntimeException('ACK maksimal ' . self::MAX_ACK_EVENTS . ' event dalam satu request.');
        }
        $response = $this->cloudHttp($schoolId, $deviceId)->post($this->endpoint('/api/sync/ack'), [
            'school_id' => $schoolId,
            'device_id' => $deviceId,
            'event_ids' => $eventIds,
        ]);
        $this->assertHttpOk($response, 'ACK');
        $result = $this->assertJsonObject($response, 'ACK');
        $this->assertSuccess($result, 'ACK');
        $data = $this->arrayValue($result['data'] ?? null);
        // V21R21: HTTP 200 bukan berarti semua ACK berhasil. Hanya
        // event_id yang benar-benar dikonfirmasi boleh dihitung selesai.
        $rows = $data['results'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException('ACK Cloud tidak memberikan hasil per event; event belum dikonfirmasi.');
        }
        $confirmed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['event_id'] ?? '');
            $status = strtolower((string) ($row['status'] ?? ''));
            if (in_array($id, $eventIds, true) && in_array($status, ['acknowledged', 'already_acknowledged'], true)) {
                $confirmed[$id] = true;
            }
        }
        return ['success' => true, 'acknowledged' => count($confirmed),
            'rejected' => count($eventIds) - count($confirmed)];
    }

    /* ============================================================
     * PUSH (satu event per request — kontrak dipertahankan)
     * ============================================================
     * [PUSH-VALIDATE] Payload wajib array bila ada — fail-fast dengan
     * pesan jelas alih-alih ditolak Worker dengan error generik.
     * ============================================================ */
    public function push(array $data): array
    {
        if ($data === []) {
            throw new RuntimeException('Data PUSH tidak boleh kosong.');
        }
        $schoolId = trim((string) ($data['school_id'] ?? ''));
        $deviceId = trim((string) ($data['source_device_id'] ?? ''));
        if ($schoolId === '') {
            throw new RuntimeException('school_id wajib ada pada payload PUSH.');
        }
        if ($deviceId === '') {
            throw new RuntimeException('source_device_id wajib ada pada payload PUSH.');
        }
        $eventId = trim((string) ($data['event_id'] ?? ''));
        if ($eventId === '') {
            throw new RuntimeException('event_id wajib ada pada payload PUSH.');
        }
        $entityType = trim((string) ($data['entity_type'] ?? ''));
        if ($entityType === '') {
            throw new RuntimeException('entity_type wajib ada pada payload PUSH.');
        }
        $entityId = trim((string) ($data['entity_id'] ?? ''));
        if ($entityId === '') {
            throw new RuntimeException('entity_id wajib ada pada payload PUSH.');
        }
        $action = trim((string) ($data['action'] ?? ''));
        if ($action === '') {
            throw new RuntimeException('action wajib ada pada payload PUSH.');
        }
        $version = (int) ($data['version'] ?? 0);
        if ($version < 1) {
            throw new RuntimeException('version PUSH harus >= 1.');
        }
        if (array_key_exists('payload', $data) && !is_array($data['payload'])) {
            throw new RuntimeException('payload PUSH harus berupa array/object.');
        }
        $createdAt = trim((string) ($data['created_at'] ?? ''));
        $data['created_at'] = $createdAt !== '' ? $createdAt : now()->utc()->toIso8601String();
        $data['action'] = match ($action) {
            'create', 'update', 'upsert' => 'upsert',
            'delete' => 'delete',
            default => throw new RuntimeException('Action PUSH tidak dapat ditranslasikan ke EDUXID Cloud: ' . $action),
        };
        $data['event_id'] = $eventId;
        $data['school_id'] = $schoolId;
        $data['source_device_id'] = $deviceId;
        $data['entity_type'] = $entityType;
        $data['entity_id'] = $entityId;
        $data['version'] = $version;
        if (CloudOutboundPrivacyGuardV21::enabled()) {
            CloudOutboundPrivacyGuardV21::assertDevice($schoolId, $deviceId);
            CloudOutboundPrivacyGuardV21::assertPayload($data, $schoolId);
            CloudAssessmentBoundaryV21::outgoing($entityType, (array) ($data['payload'] ?? []));
        }
        $response = $this->cloudHttp($schoolId, $deviceId)->post(
            $this->endpoint('/api/sync/push'),
            ['events' => [$data]]
        );
        $this->assertHttpOk($response, 'PUSH');
        $result = $this->assertJsonObject($response, 'PUSH');
        $this->assertSuccess($result, 'PUSH');
        // Respons sukses envelope tetap harus memuat verdict event yang
        // tepat. REJECTED dikembalikan agar klasifikasi retry/dead-letter
        // tetap dilakukan oleh LmsSyncService (bukan dilempar di sini).
        $rows = $result['data']['results'] ?? null;
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])
            || (string) ($rows[0]['event_id'] ?? '') !== $eventId
            || !in_array(strtolower((string) ($rows[0]['status'] ?? '')),
                ['accepted', 'duplicate', 'rejected'], true)) {
            throw new RuntimeException('PUSH Cloud tanpa konfirmasi event_id/status yang sah; outbox tetap pending.');
        }
        return $result;
    }

    /** V21R128: authenticated teacher PC → private Arena catalog (never student-broadcast). */
    public function publishArenaExamSource(string $schoolId, string $deviceId, array $source): array
    {
        $response = $this->cloudHttp($schoolId, $deviceId)
            ->timeout(45)->post($this->endpoint('/api/arena/exam-sources'), $source);
        $this->assertHttpOk($response, 'ARENA SOURCE');
        $result = $this->assertJsonObject($response, 'ARENA SOURCE');
        $this->assertSuccess($result, 'ARENA SOURCE');
        return $result;
    }

    public function registerDevice(array $data): array
    {
        if ($data === []) {
            throw new RuntimeException('Data registrasi perangkat tidak boleh kosong.');
        }
        if (CloudOutboundPrivacyGuardV21::enabled()) {
            $school = (string) ($data['school_id'] ?? '');
            $role = (string) ($data['device_type'] ?? '');
            CloudOutboundPrivacyGuardV21::assertDevice($school, (string) ($data['device_id'] ?? ''), $role);
            CloudOutboundPrivacyGuardV21::assertPayload($data, $school);
        }
        $response = $this->http()->post($this->endpoint('/api/device/register'), $data);
        $this->assertHttpOk($response, 'REGISTER DEVICE');
        $result = $this->assertJsonObject($response, 'REGISTER DEVICE');
        $this->assertSuccess($result, 'REGISTER DEVICE');
        return $result;
    }

    public function studentDevices(string $schoolId): array
    {
        throw new RuntimeException(
            'Endpoint /api/device/students tidak tersedia pada EDUXID CLOUD. '
            . 'Gunakan roster siswa lokal / devices di Cloud untuk menentukan target.'
        );
    }

    /* ============================================================
     * HTTP BUILDERS
     * ============================================================ */

    protected function http(): PendingRequest
    {
        $request = Http::timeout($this->timeout)
            ->retry($this->retryTimes, 250, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->acceptJson()
            ->asJson();
        $request = $this->applyCaCertificate($request);
        if ($this->syncToken !== null) {
            $request = $request->withHeaders(['X-Sync-Token' => $this->syncToken]);
        }
        return $request;
    }

    protected function cloudHttp(string $schoolId, string $deviceId): PendingRequest
    {
        $schoolId = trim($schoolId);
        $deviceId = trim($deviceId);
        $this->validateIdentity($schoolId, $deviceId);
        $device = SyncDevice::query()
            ->where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->first();
        if (!$device) {
            throw new RuntimeException('Perangkat belum terdaftar secara lokal untuk EDUXID CLOUD atau perangkat tidak aktif. Silakan lakukan pairing terlebih dahulu.');
        }
        $deviceToken = trim((string) $device->sync_token);
        if ($deviceToken === '') {
            throw new RuntimeException('Cloud device_token belum tersedia untuk perangkat ini.');
        }
        $request = Http::timeout($this->timeout)
            ->retry($this->retryTimes, 250, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->acceptJson()
            ->asJson()
            ->withToken($deviceToken);
        return $this->applyCaCertificate($request);
    }

    protected function applyCaCertificate(PendingRequest $request): PendingRequest
    {
        if ($this->caCert === null) {
            return $request;
        }
        return $request->withOptions(['verify' => $this->caCert]);
    }

    protected function validateIdentity(string $schoolId, string $deviceId): void
    {
        if (trim($schoolId) === '') {
            throw new RuntimeException('School ID tidak boleh kosong.');
        }
        if (trim($deviceId) === '') {
            throw new RuntimeException('Device ID tidak boleh kosong.');
        }
        if (CloudOutboundPrivacyGuardV21::enabled()) {
            CloudOutboundPrivacyGuardV21::assertDevice($schoolId, $deviceId);
        }
    }

    protected function endpoint(string $path): string
    {
        if ($this->syncServer === '') {
            throw new RuntimeException(
                'URL eduXid Cloud belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud.'
            );
        }
        return $this->syncServer . '/' . ltrim($path, '/');
    }

    /* ============================================================
     * ASSERT HELPERS
     * ============================================================ */

    protected function assertHttpOk(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }
        $body = mb_substr($response->body(), 0, 1000);
        Log::error("EDUXID Sync Server HTTP error saat {$operation}.", [
            'operation' => $operation,
            'status' => $response->status(),
            'body' => $body,
        ]);
        throw new RuntimeException(
            "Gagal {$operation} dari/ke Sync Server. HTTP " . $response->status()
            . ($body !== '' ? ': ' . $body : '')
        );
    }

    protected function assertJsonObject(Response $response, string $operation): array
    {
        $result = $response->json();
        if (!is_array($result)) {
            Log::error("EDUXID Sync Server mengembalikan JSON yang tidak valid saat {$operation}.", [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);
            throw new RuntimeException("Respons {$operation} dari Sync Server tidak valid.");
        }
        return $result;
    }

    protected function assertSuccess(array $result, string $operation): void
    {
        if (($result['success'] ?? false) === true) {
            return;
        }
        throw new RuntimeException('Sync Server menolak ' . $operation . ': ' . $this->encode($result));
    }

    protected function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    protected function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}