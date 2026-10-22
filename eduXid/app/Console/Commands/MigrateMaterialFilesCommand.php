<?php

namespace App\Console\Commands;

use App\Models\LmsMaterial;
use App\Services\Sync\LmsSyncService;
use App\Models\SyncDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MigrateMaterialFilesCommand extends Command
{
    protected $signature = 'eduxid:migrate-material-files
                            {--force : Unggah ulang walau file_key sudah ada}';

    protected $description = 'Unggah file PDF materi lokal ke R2 (eduXid Cloud) - migrasi materi lama pra-R2.';

    public function handle(LmsSyncService $syncService): int
    {
        $force = (bool) $this->option('force');

        if (!Schema::hasColumn('lms_materials', 'file_key')) {
            $this->error('Kolom file_key tidak ada di tabel lms_materials - jalankan migrasi dulu.');
            return self::FAILURE;
        }

        $materials = LmsMaterial::query()
            ->where('type', 'pdf')
            ->whereNotNull('file_path')
            ->when(!$force, fn ($q) => $q->whereNull('file_key'))
            ->get();

        if ($materials->isEmpty()) {
            $this->info('Tidak ada materi PDF yang perlu dimigrasi.');
            return self::SUCCESS;
        }

        /*
         * Upload butuh device token guru yang ter-pairing (SW
         * memakai device token). Ambil dari sync_devices guru mana
         * pun yang aktif di laptop ini.
         */
        $device = SyncDevice::query()
            ->where('device_type', 'teacher')
            ->where('is_active', true)
            ->whereNotNull('sync_token')
            ->orderBy('device_id')
            ->first();

        if (!$device) {
            $this->error('Tidak ada device guru ter-pairing di laptop ini - jalankan eduxid:migrate-material-files SETELAH pairing.');
            return self::FAILURE;
        }

        $this->info('Memigrasi ' . $materials->count() . ' materi PDF ke R2...');
        $this->newLine();

        $ok = 0;
        $fail = 0;

        foreach ($materials as $material) {
            $this->line('  [' . $material->id . '] ' . $material->title);

            if (!Storage::disk('public')->exists($material->file_path)) {
                $this->warn('      SKIP - file lokal tidak ada: ' . $material->file_path);
                $fail++;
                continue;
            }

            $absolutePath = Storage::disk('public')->path($material->file_path);
            $extension = strtolower(pathinfo($material->file_path, PATHINFO_EXTENSION));

            /*
             * Key konsisten dengan pola pushMaterial (LmsSyncService):
             *   materials/{sync_id}.{ext}
             */
            $fileKey = 'materials/' . $material->sync_id
                . ($extension !== '' ? '.' . $extension : '.bin');

            try {
                $syncService->uploadAnswerPhotoCompatible(
                    $absolutePath,
                    $fileKey,
                    $material->mime_type ?: 'application/pdf',
                    $device->sync_token
                );

                $material->file_key = $fileKey;
                $material->save();

                $ok++;
                $this->info('      OK -> ' . $fileKey);
            } catch (Throwable $e) {
                $fail++;
                $this->error('      GAGAL: ' . $e->getMessage());

                Log::warning('[MIGRATE-MATERIAL] Upload gagal.', [
                    'material_id' => $material->id,
                    'file_key' => $fileKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Selesai. Berhasil: {$ok}, Gagal: {$fail}.");

        if ($fail > 0) {
            $this->warn('Jalankan ulang perintah ini untuk mencoba ulang yang gagal.');
            return self::FAILURE;
        }

        /*
         * Setelah file_key terisi, push ulang metadata materi agar
         * PWA siswa mengetahui file_key (bila materi sudah published).
         */
        $published = LmsMaterial::query()
            ->where('type', 'pdf')
            ->whereNotNull('file_key')
            ->where('is_published', true)
            ->get();

        $this->info('Mem-push ulang metadata ' . $published->count() . ' materi published...');

        foreach ($published as $material) {
            try {
                $syncService->pushMaterialToStudents(
                    $material->fresh()->load('topic.course'),
                    'update'
                );
            } catch (Throwable $e) {
                Log::warning('[MIGRATE-MATERIAL] Push metadata gagal (outbox akan mengejar).', [
                    'material_id' => $material->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Semua metadata ter-push. Materi kini dapat diunduh dari PWA siswa.');
        return self::SUCCESS;
    }
}