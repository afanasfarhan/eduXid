<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LmsSyncRecord;
use App\Services\Sync\LmsSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * V19R2: repair ONLY a queued LJK manual-session tombstone missing Cloud teacher scope.
 * Dry-run by default. Does not push, ACK, delete, reassign source or change grade data.
 */
class EduxidRepairLjkManualTombstoneCommand extends Command
{
    protected $signature = 'eduxid:repair-ljk-manual-outbox {--id=110 : ID lokal outbox} {--apply : Lakukan perbaikan setelah backup database dan daemon berhenti}';
    protected $description = 'Perbaiki scope otorisasi tombstone LJK manual lama secara terbatas dan aman.';

    public function handle(LmsSyncService $sync): int
    {
        $id = (int) $this->option('id');
        $apply = (bool) $this->option('apply');
        if ($id < 1) { $this->error('ID outbox tidak valid.'); return self::FAILURE; }
        $this->info('eduXid V19R2 | Perbaikan tombstone kunci LJK tanpa Paket Ujian');
        $this->line('Mode: ' . ($apply ? 'APPLY (hanya record yang lolos verifikasi)' : 'DRY RUN (hanya baca)'));
        try {
            $result = DB::transaction(function () use ($id, $apply, $sync): string {
                $record = LmsSyncRecord::query()->whereKey($id)->lockForUpdate()->first();
                if (!$record) throw new \InvalidArgumentException('Record outbox tidak ditemukan.');
                if (!in_array((string) $record->status, ['pending','failed'], true)) {
                    throw new \InvalidArgumentException('Record sudah selesai/dalam proses; tidak boleh diubah.');
                }
                if ((string) $record->entity_type !== 'lms_assignment_submission'
                    || (string) $record->operation !== 'delete') {
                    throw new \InvalidArgumentException('Record bukan tombstone LJK yang dituju.');
                }
                $payload = is_array($record->payload) ? $record->payload : [];
                $sessionId = (string) ($payload['manual_session_id'] ?? '');
                if (!preg_match('/^manual::[a-f0-9-]{36}$/i', $sessionId)
                    || ($payload['entity'] ?? null) !== 'ljk_private_package'
                    || ($payload['deleted'] ?? null) !== true
                    || ($payload['key_source'] ?? null) !== 'manual'
                    || (string) ($payload['exam_sync_id'] ?? '') !== $sessionId
                    || (string) ($payload['sync_id'] ?? '') !== 'ljkpkg::' . $sessionId
                    || (string) $record->entity_id !== 'ljkpkg::' . $sessionId
                    || !array_key_exists('exam_id', $payload) || $payload['exam_id'] !== null
                    || (int) ($payload['sync_version'] ?? 0) !== (int) $record->version) {
                    throw new \InvalidArgumentException('Payload bukan penghapusan sesi manual yang valid.');
                }
                $session = DB::table('ljk_manual_sessions')->where('session_id', $sessionId)->first();
                if (!$session || $session->deleted_at === null) {
                    throw new \InvalidArgumentException('Sesi yang sudah diarsipkan tidak ditemukan; hentikan perbaikan.');
                }
                if ((int) $session->teacher_id <= 0 || (int) $session->teaching_assignment_id <= 0
                    || (int) $session->homeroom_id <= 0) {
                    throw new \InvalidArgumentException('Lingkup penugasan pada sesi tidak lengkap.');
                }
                $taValid = DB::table('teaching_assignments')
                    ->where('id', (int) $session->teaching_assignment_id)
                    ->where('teacher_id', (int) $session->teacher_id)
                    ->where('homeroom_id', (int) $session->homeroom_id)
                    ->where('subject_id', (int) $session->subject_id)->exists();
                if (!$taValid) throw new \InvalidArgumentException('Penugasan resmi sesi tidak lagi sesuai.');
                // Do not permit replay from another PC; source and target are immutable.
                $pc = $sync->resolveTeacherPcDeviceId((int) $session->teacher_id);
                if (!$pc || !hash_equals($pc, (string) $record->node_id)
                    || !preg_match('/-PC$/i', $pc)
                    || !hash_equals((string) preg_replace('/-PC$/i','-HP',$pc), (string) $record->target_device_id)
                    || !hash_equals((string) $record->target_device_id, (string) ($payload['target_device_id'] ?? ''))
                    || $record->target_homeroom_id !== null) {
                    throw new \InvalidArgumentException('Identitas PC/HP atau target outbox tidak cocok; tidak aman diperbaiki otomatis.');
                }
                if (isset($payload['teacher_id']) || isset($payload['teaching_assignment_id']) || isset($payload['homeroom_id'])) {
                    throw new \InvalidArgumentException('Record telah memiliki metadata lingkup. Periksa penyebab penolakan lain.');
                }
                if ((string) ($record->last_error ?? '') !== 'Device tidak berhak mengirim entity/target ini.') {
                    throw new \InvalidArgumentException('Alasan terakhir bukan penolakan otorisasi yang sudah diidentifikasi.');
                }
                if (!$apply) return 'VALID: data sesi, ownership, dan target cocok; aman untuk menerapkan --apply setelah daemon dihentikan.';
                $payload['teacher_id'] = (int) $session->teacher_id;
                $payload['teaching_assignment_id'] = (int) $session->teaching_assignment_id;
                $payload['homeroom_id'] = (int) $session->homeroom_id;
                // Keep event_id, source, target, version, operation, attempts and status unchanged.
                $record->forceFill(['payload' => $payload, 'last_error' => null])->save();
                return 'DIPERBAIKI: payload #'.$id.' kini memiliki scope resmi. Status masih pending; daemon akan retry secara normal.';
            });
            $this->info($result);
            $this->line('Tidak ada nilai, hasil scan, ACK, target, atau ID device yang diubah.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            // All exceptions controlled above. Do not expose raw error or credentials.
            $this->error('Perbaikan dibatalkan: ' . ($e instanceof \InvalidArgumentException ? $e->getMessage() : 'verifikasi atau akses database gagal.'));
            return self::FAILURE;
        }
    }
}
