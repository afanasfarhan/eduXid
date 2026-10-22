<?php

namespace App\Services\Sync;

use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsTopic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class LmsSyncReceiver
{
    protected const DOWNLOAD_PDF_ENABLED_KEY = 'eduxid_lms_receiver_download_pdf';

    public function __construct(
        protected SyncClient $client,
        protected LmsSyncApplyService $applyService
    ) {
    }

    public function sync(string $schoolId, string $deviceId): array
    {
        $cursor = $this->getCursor($schoolId, $deviceId);

        $response = $this->client->pull($schoolId, $deviceId, $cursor);

        $changes = is_array($response['changes'] ?? null) ? $response['changes'] : [];

        $processed = 0;
        $failed = 0;
        $errors = [];
        $ackEventIds = [];

        foreach ($changes as $change) {
            $eventId = trim((string) ($change['event_id'] ?? ''));

            try {
                if ($eventId === '') {
                    throw new RuntimeException('Event sync tidak memiliki event_id.');
                }

                $this->applyChange($change);

                $processed++;
                $ackEventIds[] = $eventId;
            } catch (Throwable $e) {
                $failed++;

                $errors[] = [
                    'event_id' => $eventId !== '' ? $eventId : null,
                    'entity_type' => $change['entity_type'] ?? null,
                    'entity_id' => $change['entity_id'] ?? null,
                    'error' => $e->getMessage(),
                ];

                Log::error('eduXid LMS Sync Receiver gagal.', [
                    'school_id' => $schoolId,
                    'device_id' => $deviceId,
                    'event_id' => $eventId !== '' ? $eventId : null,
                    'entity_type' => $change['entity_type'] ?? null,
                    'entity_id' => $change['entity_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ack = null;
        $acknowledged = 0;

        if (!empty($ackEventIds)) {
            try {
                $ack = $this->client->ack($schoolId, $deviceId, $ackEventIds);
                $acknowledged = max(0, (int) ($ack['acknowledged'] ?? 0));
            } catch (Throwable $e) {
                Log::warning('ACK receiver gagal — event tetap DELIVERED dan akan di-pull ulang (apply idempotent).', [
                    'school_id' => $schoolId,
                    'device_id' => $deviceId,
                    'pending_ack' => count($ackEventIds),
                    'error' => $e->getMessage(),
                ]);

                $ack = ['success' => false, 'acknowledged' => 0, 'error' => $e->getMessage()];
                $acknowledged = 0;
            }
        }

        return [
            'success' => true,
            'school_id' => $schoolId,
            'device_id' => $deviceId,
            'previous_cursor' => $cursor,
            'next_cursor' => $cursor,
            'received' => count($changes),
            'processed' => $processed,
            'failed' => $failed,
            'acknowledged' => $acknowledged,
            'ack_event_ids' => $ackEventIds,
            'errors' => $errors,
        ];
    }

    protected function applyChange(array $change): void
    {
        $entityType = (string) ($change['entity_type'] ?? '');
        $action = (string) ($change['action'] ?? '');
        $payload = $change['payload'] ?? null;

        if ($entityType === '' || !is_array($payload)) {
            throw new RuntimeException('Payload sync tidak valid.');
        }

        $version = max(1, (int) ($payload['sync_version'] ?? $payload['version'] ?? 1));
        $syncId = trim((string) ($payload['sync_id'] ?? $change['entity_id'] ?? ''));

        if ($syncId === '') {
            throw new RuntimeException('Payload sync tidak memiliki sync_id.');
        }

        $normalized = [
            'entity_type' => $entityType,
            'entity_id' => $syncId,
            'action' => in_array($action, ['create', 'update', 'delete'], true) ? $action : 'update',
            'version' => $version,
            'payload' => $payload,
        ];

        if ($entityType === 'lms_material' && $action !== 'delete') {
            $this->prepareMaterialFile($normalized['payload']);
        }

        $this->applyService->apply($normalized);
    }

    /*
     * ============================================================
     * MATERIAL FILE (KEKHUSUSAN SISWA)
     *
     * Sebelum apply, unduh PDF ke storage lokal perangkat ini dan
     * tulis path lokal ke payload. applyService menyimpan metadata;
     * file fisik hanya ada di device ini.
     * ============================================================
     */
    protected function prepareMaterialFile(array &$payload): void
    {
        $type = strtolower(trim((string) ($payload['type'] ?? 'text')));
        $mimeType = strtolower((string) ($payload['mime_type'] ?? ''));
        $fileName = $payload['file_name'] ?? null;
        $serverFilePath = $payload['file_path'] ?? null;

        $isPdf = $type === 'pdf'
            || $mimeType === 'application/pdf'
            || (is_string($fileName) && strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf');

        if (!$isPdf || !$serverFilePath) {
            return;
        }

        if (!$this->downloadEnabled()) {
            Log::info('eduXid LMS receiver: download PDF dilewati (dinonaktifkan).', [
                'sync_id' => $payload['sync_id'] ?? null,
            ]);
            return;
        }

        $syncId = trim((string) ($payload['sync_id'] ?? ''));
        $existing = LmsMaterial::query()->where('sync_id', $syncId)->first();

        $incomingVersion = max(1, (int) ($payload['sync_version'] ?? 1));

        if (
            $existing
            && (int) $existing->sync_version === $incomingVersion
            && $existing->file_path
            && $this->localMaterialFileExists($existing->file_path)
        ) {
            $payload['file_path'] = $existing->file_path;
            return;
        }

        $localPath = $this->downloadMaterialFile($syncId, $fileName, $serverFilePath);

        if ($localPath !== null) {
            $payload['file_path'] = $localPath;
        }
    }

    protected function downloadEnabled(): bool
    {
        return (bool) config('eduxid.lms_receiver_download_pdf', true);
    }

    protected function downloadMaterialFile(string $syncId, ?string $fileName, ?string $serverFilePath): ?string
    {
        $baseUrl = rtrim((string) (config('eduxid.lms_material_base_url') ?: config('services.eduxid_sync.url', '')), '/');

        if ($baseUrl === '') {
            Log::warning('eduXid LMS receiver: base URL materi tidak dikonfigurasi — PDF dilewati (metadata tetap disimpan).', [
                'sync_id' => $syncId,
            ]);
            return null;
        }

        $token = (string) config('eduxid.sync_token', '');

        if ($token === '') {
            Log::warning('eduXid LMS receiver: sync token tidak tersedia — PDF dilewati.', [
                'sync_id' => $syncId,
            ]);
            return null;
        }

        $safeName = $this->sanitizeFileName($fileName ?: basename((string) $serverFilePath));

        if ($safeName === '') {
            $safeName = $syncId . '.pdf';
        }

        $localPath = 'lms/materials/' . $syncId . '/' . $safeName;

        $url = $baseUrl . '/api/local/lms/material/' . rawurlencode($syncId) . '/download';

        Log::info('eduXid LMS: mengunduh file materi.', [
            'sync_id' => $syncId,
            'url' => $url,
            'local_path' => $localPath,
        ]);

        try {
            $response = Http::timeout(120)
                ->connectTimeout(20)
                ->withHeaders([
                    'X-Sync-Token' => $token,
                    'Accept' => 'application/pdf',
                ])
                ->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException('Gagal terhubung ke server untuk download materi: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Download materi gagal. HTTP ' . $response->status() . ': ' . $response->body());
        }

        if ($response->body() === '') {
            throw new RuntimeException('Server mengembalikan file materi kosong.');
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if ($contentType !== '' && !str_contains($contentType, 'application/pdf')) {
            throw new RuntimeException('File materi bukan PDF. Content-Type server: ' . $contentType);
        }

        $stored = Storage::disk('public')->put($localPath, $response->body());

        if (!$stored || !Storage::disk('public')->exists($localPath)) {
            throw new RuntimeException('File materi gagal disimpan ke storage lokal.');
        }

        Log::info('eduXid LMS: file materi berhasil diunduh.', [
            'sync_id' => $syncId,
            'local_path' => $localPath,
            'size' => Storage::disk('public')->size($localPath),
        ]);

        return $localPath;
    }

    protected function localMaterialFileExists(?string $path): bool
    {
        if (!$path || !is_string($path)) {
            return false;
        }
        return Storage::disk('public')->exists($path);
    }

    protected function deleteLocalMaterialFile(?string $path): void
    {
        if (!$path || !is_string($path)) {
            return;
        }
        if (!str_starts_with($path, 'lms/materials/')) {
            return;
        }
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function sanitizeFileName(string $fileName): string
    {
        $fileName = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $fileName));
        $fileName = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '_', $fileName);
        return trim((string) $fileName, ". ");
    }

    protected function getCursor(string $schoolId, string $deviceId): int
    {
        return (int) cache()->get($this->cursorKey($schoolId, $deviceId), 0);
    }

    protected function saveCursor(string $schoolId, string $deviceId, int $cursor): void
    {
        cache()->forever($this->cursorKey($schoolId, $deviceId), max(0, $cursor));
    }

    protected function cursorKey(string $schoolId, string $deviceId): string
    {
        return 'eduxid_lms_sync_cursor_' . $schoolId . '_' . $deviceId;
    }
}