<?php

namespace App\Console\Commands;

use App\Models\LmsMaterial;
use App\Models\LmsSyncRecord;
use App\Services\Sync\LmsSyncService;
use Illuminate\Console\Command;
use Throwable;

class RepairMaterialFiles extends Command
{
    protected $signature = 'eduxid:repair-material-files
                            {--all : Re-push SEMUA materi published (abaikan deteksi rusak)}
                            {--sync_id= : Hanya materi dengan sync_id ini}';

    protected $description = 'Deteksi & perbaiki materi LMS yang filenya tidak pernah ter-upload ke Cloud (file_key null di event sync terakhir) dengan re-push payload + file.';

    public function handle(LmsSyncService $sync): int
    {
        if (!$sync->isCloudConfigured()) {
            $this->error('Cloud belum dikonfigurasi — buka Admin → eduXid Cloud → Setup Cloud.');
            return 1;
        }

        $query = LmsMaterial::query()
            ->where('is_published', true)
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->whereNotNull('sync_id');

        if ($sid = $this->option('sync_id')) {
            $query->where('sync_id', $sid);
        }

        $materials = $query->get();
        $this->info("Materi published dengan file: {$materials->count()}");

        $repaired = 0;
        $healthy = 0;
        $failed = 0;

        foreach ($materials as $material) {
            $needsRepair = $this->option('all') || $this->isPayloadMissingFileKey((string) $material->sync_id);

            if (!$needsRepair) {
                $healthy++;
                continue;
            }

            $this->line("Perbaiki: {$material->title} (sync_id {$material->sync_id})...");

            try {
                /*
                 * pushMaterial penuh: resolve device (fallback guru
                 * aktif) → upload file ke R2 (key kanonik
                 * materials/{syncId}.{ext}) → push event update dengan
                 * file_key benar. Hold-back menahan bila upload gagal.
                 */
                $result = $sync->pushMaterial($material->fresh(), 'update');
                $status = strtoupper((string) ($result['status'] ?? ''));

                if (($result['success'] ?? false) === true && in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)) {
                    $this->info("  ✓ OK ({$status})");
                    $repaired++;
                } else {
                    $this->warn("  ⚠ Pending: " . ($result['error'] ?? $status));
                    $failed++;
                }
            } catch (Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Selesai — diperbaiki: {$repaired}, sehat: {$healthy}, gagal/pending: {$failed}");
        $this->line('Perangkat PWA (guru & siswa) akan menerima file_key baru saat sinkron berikutnya.');

        return 0;
    }

    /**
     * Event sync BERHASIL terakhir untuk materi ini membawa file_key
     * kosong? → payload di PWA pasti rusak (kategori A/B).
     */
    protected function isPayloadMissingFileKey(string $syncId): bool
    {
        $record = LmsSyncRecord::query()
            ->where('entity_type', 'lms_material')
            ->where(function ($q) use ($syncId) {
                $q->where('entity_id', $syncId)->orWhere('sync_id', $syncId);
            })
            ->where('status', 'synced')
            ->latest('id')
            ->first();

        if (!$record) {
            return true; // belum pernah sukses → perlu re-push
        }

        $payload = is_array($record->payload) ? $record->payload : [];

        return empty($payload['file_key']);
    }
}