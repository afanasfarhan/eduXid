<?php

namespace App\Console\Commands;

use App\Models\LmsSyncRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CleanupStuck extends Command
{
    protected $signature = 'eduxid:cleanup-stuck
        {--dry : Tampilkan saja apa yang akan dibersihkan (tanpa menghapus)}
        {--dead= : Batas umur dead-letter dalam hari (default 30)}';

    protected $description = 'Bersihkan event sinkron yang menggantung / dead-letter / ledger basi. TIDAK menyentuh jawaban, nilai final, atau sesi ujian.';

    public function handle(): int
    {
        $deadDays = (int) ($this->option('dead') ?: 30);
        $dry = (bool) $this->option('dry');

        $this->info('=== PEMBERSIHAN EVENT TERSENGKUT ===' . ($dry ? ' (DRY-RUN)' : ''));

        /* ============================================================
         * 1) Record cbt_answer yang menggantung di lms_sync_records.
         *    Ini LEDGER INBOUND applier — aman dihapus: event di Cloud
         *    masih ada; bila nanti event serupa diterima lagi, applier
         *    akan menanganinya dari nol (ingest idempoten).
         *    AKAR KASUS: exam telah dihapus → jawaban tak mungkin pulih.
         * ============================================================ */
        $stuck = LmsSyncRecord::query()
            ->where('entity_type', 'cbt_answer')
            ->whereIn('status', ['pending', 'failed'])
            ->get(['id', 'sync_id', 'entity_id', 'version', 'status', 'last_error']);

        $this->line("\n[1] cbt_answer pending/failed di ledger: {$stuck->count()}");

        foreach ($stuck as $r) {
            $this->line(sprintf(
                "    id=%d v=%s sync_id=%s | %s",
                $r->id,
                $r->version,
                Str::limit((string) $r->sync_id, 18),
                Str::limit((string) $r->last_error, 90)
            ));
        }

        /* ============================================================
         * 2) Dead-letter: lms_sync_records berstatus 'dead' ATAU tabel
         *    dead-letter terpisah (SyncDeadLetterService — nama tabel
         *    terdeteksi otomatis).
         * ============================================================ */
        $this->line("\n[2] DEAD-LETTER");

        $deadLedger = LmsSyncRecord::query()->where('status', 'dead')->get(['id', 'entity_type', 'entity_id', 'last_error']);
        $this->line("    lms_sync_records dead: {$deadLedger->count()}");
        foreach ($deadLedger as $r) {
            $this->line(sprintf("    [%s] %s | %s",
                $r->entity_type, Str::limit((string) $r->entity_id, 24),
                Str::limit((string) $r->last_error, 90)));
        }

        $deadTable = null;

        foreach (['sync_dead_letters', 'dead_letters'] as $candidate) {
            if (Schema::hasTable($candidate)) {
                $deadTable = $candidate;
                break;
            }
        }

        $deadTableCount = 0;

        if ($deadTable) {
            $this->line("    tabel dead-letter terpisah: {$deadTable}");
            $deadTableCount = (int) DB::table($deadTable)->count();
            $this->line("    baris: {$deadTableCount}");
        } else {
            $this->line('    (tidak ada tabel dead-letter terpisah)');
        }

        /* ============================================================
         * 3) Ledger OUTBOUND basi (status synced > 90 hari) — hanya
         *    housekeeping, opsional.
         * ============================================================ */
        $cutoffLedger = now()->subDays(90);
        $oldLedger = LmsSyncRecord::query()
            ->where('status', 'synced')
            ->where('synced_at', '<', $cutoffLedger)
            ->count();
        $this->line("\n[3] Ledger synced > 90 hari: {$oldLedger}");

        /* ============================================================
         * EKSEKUSI
         * ============================================================ */
        if ($dry) {
            $this->newLine();
            $this->info('DRY-RUN selesai — jalankan tanpa --dry untuk membersihkan.');
            return 0;
        }

        if ($stuck->isNotEmpty()) {
            LmsSyncRecord::query()
                ->where('entity_type', 'cbt_answer')
                ->whereIn('status', ['pending', 'failed'])
                ->delete();
            $this->info("\n✓ [1] Ledger cbt_answer pending/failed dihapus: {$stuck->count()}");
        }

        if ($deadLedger->isNotEmpty()) {
            LmsSyncRecord::query()->where('status', 'dead')->delete();
            $this->info("✓ [2] lms_sync_records dead dihapus: {$deadLedger->count()}");
        }

        if ($deadTable && $deadTableCount > 0) {
            try {
                $deleted = DB::table($deadTable)->delete();
                $this->info("✓ [2] {$deadTable} dihapus: {$deleted}");
            } catch (Throwable $e) {
                $this->error("✗ [2] Gagal menghapus {$deadTable}: " . $e->getMessage());
            }
        }

        if ($oldLedger > 0) {
            $deleted = LmsSyncRecord::query()
                ->where('status', 'synced')
                ->where('synced_at', '<', $cutoffLedger)
                ->delete();
            $this->info("✓ [3] Ledger synced lama dihapus: {$deleted}");
        }

        $this->newLine();
        $this->warn('PENTING — sisi CLOUD belum dibersihkan oleh command ini:');
        $this->line('Event cbt_answer di Cloud masih PENDING untuk device -PC dan akan');
        $this->line('terus di-pull daemon. Dua pilihan (lihat panduan di bawah):');
        $this->line('  A) Biarkan: setelah SyncApplier 09.1 aktif, event dead-letter ±5 mnt');
        $this->line('     lalu tidak di-pull lagi (ACK).');
        $this->line('  B) Bersihkan langsung di D1 (hapus baris event): lihat SQL di catatan.');

        return 0;
    }
}