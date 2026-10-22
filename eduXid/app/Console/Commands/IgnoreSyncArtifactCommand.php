<?php

namespace App\Console\Commands;

use App\Services\Sync\LmsSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class IgnoreSyncArtifactCommand extends Command
{
    protected $signature = 'eduxid:sync-ignore-event
                            {event_id : Event ID Cloud yang akan dibersihkan}
                            {--device= : Device penerima; kosong = device lokal canonical}
                            {--reason=Artefak lama yang tidak lagi diperlukan : Catatan audit}
                            {--yes : Lewati konfirmasi interaktif}';

    protected $description = 'Tandai satu event sinkron lama sebagai artefak, lalu ACK secara aman agar tidak ditarik ulang.';

    public function handle(LmsSyncService $syncService): int
    {
        $eventId = trim((string) $this->argument('event_id'));
        $deviceId = trim((string) $this->option('device'));
        $reason = trim((string) $this->option('reason')) ?: 'Artefak lama yang tidak lagi diperlukan';

        if ($eventId === '') {
            $this->error('event_id wajib diisi.');
            return self::FAILURE;
        }

        if ($deviceId === '') {
            try {
                $deviceId = trim((string) $syncService->getDeviceId());
            } catch (Throwable) {
                $deviceId = '';
            }
        }

        if ($deviceId === '') {
            $this->error('Device ID lokal tidak dapat ditentukan. Gunakan --device=...');
            return self::FAILURE;
        }

        $this->warn('Event akan DIABAIKAN dan di-ACK dari antrean Cloud.');
        $this->line('Event  : ' . $eventId);
        $this->line('Device : ' . $deviceId);
        $this->line('Alasan : ' . $reason);

        if (!(bool) $this->option('yes') && !$this->confirm('Lanjutkan pembersihan artefak ini?', false)) {
            $this->info('Dibatalkan.');
            return self::SUCCESS;
        }

        $now = now();
        $key = 'sync::ignored_event::' . hash('sha256', $eventId);
        $payload = json_encode([
            'event_id' => $eventId,
            'device_id' => $deviceId,
            'reason' => $reason,
            'ignored_at' => $now->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $payload, 'updated_at' => $now, 'created_at' => $now]
        );

        foreach (['sync_dead_letters', 'dead_letters'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table)->where('event_id', $eventId);
            if (Schema::hasColumn($table, 'device_id')) {
                $query->where('device_id', $deviceId);
            }

            $values = [];
            if (Schema::hasColumn($table, 'status')) {
                $values['status'] = 'resolved';
            }
            if (Schema::hasColumn($table, 'resolved_at')) {
                $values['resolved_at'] = $now;
            }
            if (Schema::hasColumn($table, 'last_reason')) {
                $values['last_reason'] = '[MANUAL CLEANUP] ' . $reason;
            }
            if ($values !== []) {
                $query->update($values);
            }
        }

        try {
            $result = $syncService->acknowledge($deviceId, [$eventId]);
            $ack = is_array($result) ? (int) ($result['acknowledged'] ?? 0) : 0;

            if ($ack > 0) {
                $this->info('✓ Event berhasil di-ACK ke eduXid Cloud dan tidak akan ditarik ulang.');
                return self::SUCCESS;
            }

            $this->warn('ACK langsung belum dikonfirmasi. Registry ignore sudah dibuat.');
            $this->line('Daemon akan mengabaikan event ini dan mencoba ACK pada siklus sinkron berikutnya.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn('ACK langsung gagal: ' . $e->getMessage());
            $this->line('Registry ignore sudah dibuat; daemon akan ACK otomatis saat Cloud dapat dihubungi.');
            return self::SUCCESS;
        }
    }
}
