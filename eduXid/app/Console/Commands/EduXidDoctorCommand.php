<?php

namespace App\Console\Commands;

use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\LmsSyncController;
use App\Models\LmsSyncRecord;
use App\Models\SyncDeadLetter;
use App\Services\Sync\MasterRegistry;
use App\Services\Sync\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EduXidDoctorCommand extends Command
{
    protected $signature = 'eduxid:doctor';
    protected $description = 'Pemeriksaan kesehatan sistem eduXid (skema, identitas, cloud, daemon, outbox, dead-letter).';

    protected int $fails = 0;
    protected int $warns = 0;

    /**
     * Sinkron dengan LmsSyncCommand::HEARTBEAT_KEY.
     */
    protected const HEARTBEAT_KEY = 'daemon::heartbeat';

    public function handle(): int
    {
        $this->info('=== eduXid Doctor ===');
        $this->line('Proyek   : ' . base_path());
        $this->line('Database : ' . (string) config('database.connections.sqlite.database'));
        $this->newLine();

        $this->checkPragmas();
        $this->checkSchema();
        $this->checkMigrations();
        $this->checkIdentity();
        $this->checkCloudConfig();
        $this->checkMasterRegistry();
        $this->checkDaemon();
        $this->checkOutbox();

        $this->newLine();

        if ($this->fails > 0) {
            $this->error("SELESAI — {$this->fails} masalah kritis, {$this->warns} peringatan. Perbaiki dulu sebelum dipakai.");
            return self::FAILURE;
        }

        if ($this->warns > 0) {
            $this->warn("SELESAI — tidak ada masalah kritis, {$this->warns} peringatan.");
            return self::SUCCESS;
        }

        $this->info('SELESAI — semua pemeriksaan lolos.');
        return self::SUCCESS;
    }

    /* ============================================================
     * HELPERS
     * ============================================================ */

    protected function markOk(string $msg): void
    {
        $this->line("  [OK]    {$msg}");
    }

    protected function markWarn(string $msg): void
    {
        $this->warn("  [PERINGATAN] {$msg}");
        $this->warns++;
    }

    protected function markFail(string $msg): void
    {
        $this->error("  [GAGAL] {$msg}");
        $this->fails++;
    }

    protected function info2(string $msg): void
    {
        $this->line("  [INFO]  {$msg}");
    }

    /* ============================================================
     * 1. PRAGMA SQLITE
     * ============================================================ */

    protected function checkPragmas(): void
    {
        $this->line('SQLite PRAGMA:');

        if (config('database.default') !== 'sqlite') {
            $this->info2('Driver bukan SQLite — dilewati.');
            return;
        }

        try {
            $journal = strtolower((string) (DB::select('PRAGMA journal_mode')[0]->journal_mode ?? ''));
            $sync    = (int) (DB::select('PRAGMA synchronous')[0]->synchronous ?? 0);

            /*
             * [FIX] SQLite menamai kolom hasil PRAGMA busy_timeout
             * dengan 'timeout' — bukan 'busy_timeout'. (Dulu selalu
             * terbaca 0 padahal setting benar.)
             */
            $busyRow = DB::select('PRAGMA busy_timeout')[0] ?? null;
            $busy    = (int) ($busyRow->timeout ?? $busyRow->busy_timeout ?? 0);

            $journal === 'wal'
                ? $this->markOk("journal_mode = {$journal}")
                : $this->markFail("journal_mode = {$journal} (harus WAL) — pastikan AppServiceProvider terbaru, lalu RESTART server & daemon.");

            $busy >= 5000
                ? $this->markOk("busy_timeout = {$busy}")
                : $this->markWarn("busy_timeout = {$busy} (harusnya >= 5000) — pastikan AppServiceProvider terbaru, lalu RESTART.");

            $sync === 2
                ? $this->markOk('synchronous = FULL')
                : $this->markWarn("synchronous = {$sync} (disarankan FULL — durability saat listrik padam).");
        } catch (Throwable $e) {
            $this->markFail('PRAGMA tidak dapat dibaca: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * 2. SKEMA LEDGER SINKRON
     * ============================================================ */

    protected function checkSchema(): void
    {
        $this->line('Skema ledger sinkron:');

        if (!Schema::hasTable('lms_sync_records')) {
            $this->markFail('Tabel lms_sync_records tidak ada.');
            return;
        }

        Schema::hasColumn('lms_sync_records', 'last_error') && !Schema::hasColumn('lms_sync_records', 'error_message')
            ? $this->markOk('Kolom error: last_error')
            : $this->markFail('Kolom error tidak konsisten (harus last_error, tanpa error_message).');

        Schema::hasColumn('lms_sync_records', 'attempts') && Schema::hasColumn('lms_sync_records', 'last_attempt_at')
            ? $this->markOk('Kolom retry: attempts + last_attempt_at')
            : $this->markFail('Kolom retry tidak ada — jalankan migrasi upgrade_lms_sync_records_retry_and_idempotency.');

        Schema::hasTable('sync_dead_letters')
            ? $this->markOk('Tabel dead-letter: sync_dead_letters')
            : $this->markFail('Tabel sync_dead_letters tidak ada — jalankan migrasi create_sync_dead_letters_table.');

        try {
            $indexes = DB::select("PRAGMA index_list('lms_sync_records')");
            $hasUq = collect($indexes)->contains(
                fn ($i) => $i->name === 'uq_lms_sync_records_event' && (int) $i->unique === 1
            );

            $hasUq
                ? $this->markOk('Unique index idempotensi (uq_lms_sync_records_event)')
                : $this->markFail('Unique index idempotensi TIDAK ada — jalankan migrasi upgrade_lms_sync_records_retry_and_idempotency.');
        } catch (Throwable $e) {
            $this->markWarn('Index tidak dapat diperiksa: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * 3. MIGRASI
     * ============================================================ */

    protected function checkMigrations(): void
    {
        $this->line('Migrasi:');

        try {
            Artisan::call('migrate:status');
            $pending = substr_count(Artisan::output(), 'Pending');

            $pending === 0
                ? $this->markOk('Semua migrasi sudah dijalankan')
                : $this->markFail("{$pending} migrasi belum dijalankan — jalankan: php artisan migrate");
        } catch (Throwable $e) {
            $this->markWarn('Status migrasi gagal dibaca: ' . $e->getMessage());
        }
    }

    protected function checkRoutes(): void
    {
        $this->line('Keutuhan method controller rute utama:');

        try {
            $required = [
                [PengaturanController::class, [
                    'pullMasterDataCloud', 'pushMasterDataCloud', 'pushWorkProductsCloud',
                    'pushGradesApi', 'pullMasterDataApi', 'imporMasterGuru',
                    'processTransactionData', 'eksporTransaksiGuru', 'imporBackupGuruAdmin',
                ]],
                [LmsSyncController::class, ['sync', 'resetCursor', 'status']],
            ];

            foreach ($required as [$class, $methods]) {
                foreach ($methods as $method) {
                    method_exists($class, $method)
                        ? $this->markOk(class_basename($class) . '::' . $method)
                        : $this->markFail(class_basename($class) . '::' . $method . ' HILANG - controller terpotong saat update; pulihkan dari paket rollout.');
                }
            }
        } catch (\Throwable $e) {
            $this->markWarn('Pemeriksaan route gagal: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * 4. IDENTITAS SEKOLAH
     * ============================================================ */

    protected function checkIdentity(): void
    {
        $this->line('Identitas sekolah:');

        try {
            $status = app(SchoolIdentity::class)->status();

            $status['ready']
                ? $this->markOk("school_id = {$status['school_id']}")
                : $this->markFail($status['issue'] ?? 'Identitas sekolah belum siap.');
        } catch (Throwable $e) {
            $this->markFail('Identitas gagal diperiksa: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * 5. KONFIGURASI CLOUD
     * ============================================================ */

    protected function checkCloudConfig(): void
    {
        $this->line('Konfigurasi Cloud:');

        $server = trim((string) \App\Services\Sync\EduxidCloudConfig::syncServer());
        $token  = trim((string) \App\Services\Sync\EduxidCloudConfig::adminToken());

        $server !== '' ? $this->markOk('URL Cloud terisi') : $this->markWarn('URL Cloud kosong — jalur Cloud nonaktif.');
        $token !== '' ? $this->markOk('Kunci admin terisi') : $this->markWarn('Kunci admin kosong — push master/akun akan gagal.');
    }

    /* ============================================================
     * 6. REGISTRY MASTER
     * ============================================================ */

    protected function checkMasterRegistry(): void
    {
        $this->line('Registry master:');

        try {
            $applied = MasterRegistry::appliedVersion();
            $pushed  = MasterRegistry::pushedVersion();
            $dirty   = MasterRegistry::get(MasterRegistry::KEY_DIRTY);

            $this->info2("versi terpasang={$applied}, versi ter-push={$pushed}");

            if ($applied === 0 && $pushed > 0) {
                $this->info2('terpasang=0 normal di laptop ADMIN (sumber master); terisi di laptop guru setelah menarik master.');
            }

            $dirty === null
                ? $this->markOk('Tidak ada push master tertunda')
                : $this->markWarn('Push master TERTUNDA (flag dirty aktif) — daemon akan mengirim; bila >15 menit, cek log MASTER PUSH.');
        } catch (Throwable $e) {
            $this->markWarn('Registry master belum lengkap: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * 7. DAEMON (HEARTBEAT)
     * ============================================================ */

    protected function checkDaemon(): void
    {
        $this->line('Daemon sinkron:');

        try {
            $hb = MasterRegistry::get(self::HEARTBEAT_KEY);

            if ($hb === null) {
                $this->markWarn('Daemon tidak pernah hidup (belum ada heartbeat) — jalankan eduXid.exe.');
                return;
            }

            $age = now()->timestamp - strtotime($hb);

            if ($age <= 300) {
                $this->markOk("Daemon hidup (heartbeat {$age} detik lalu).");
                return;
            }

            $this->markWarn(
                'Daemon terdeteksi MATI (heartbeat ' . (int) $age
                . ' detik lalu) — jalankan eduXid.exe; instance baru akan mengambil alih otomatis.'
            );
        } catch (Throwable $e) {
            $this->markWarn('Heartbeat gagal dibaca: ' . $e->getMessage());
        }
    }

    /* ============================================================
     * 8. OUTBOX + DEAD-LETTER
     * ============================================================ */

    protected function checkOutbox(): void
    {
        $this->line('Outbox sinkron:');

        $counts = LmsSyncRecord::query()
            ->selectRaw('status, count(*) as c')
            ->whereIn('status', ['pending', 'failed', 'dead'])
            ->groupBy('status')
            ->pluck('c', 'status');

        $pending = (int) ($counts['pending'] ?? 0);
        $failed  = (int) ($counts['failed'] ?? 0);
        $outDead = (int) ($counts['dead'] ?? 0);

        $pending + $failed === 0
            ? $this->markOk('Tidak ada antrean tertunda')
            : $this->info2("pending={$pending}, failed={$failed} (di-retry fair-rotation otomatis)");

        if ($outDead > 0) {
            $this->markWarn("{$outDead} record outbox DEAD — periksa: select entity_type, last_error from lms_sync_records where status='dead'");
        }

        $oldest = LmsSyncRecord::query()
            ->whereIn('status', ['pending', 'failed'])
            ->min('created_at');

        if ($oldest !== null) {
            $ageHours = (int) now()->diffInHours($oldest);

            $ageHours >= 24
                ? $this->markWarn("Antrean tertua berumur {$ageHours} jam — ada pengiriman yang tidak kunjung berhasil.")
                : $this->markOk('Antrean tertua < 24 jam');
        }

        $targetMissing = LmsSyncRecord::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('last_error', 'like', '%TARGET_DEVICE_NOT_FOUND%')
            ->count();

        if ($targetMissing > 0) {
            $this->info2("{$targetMissing} event menunggu device yang belum pairing (normal sampai device dipasangkan).");
        }

        /*
         * [RESTORE-SYNC-STATE] Deteksi pairing hilang pasca-restore:
         * tanpa satu pun device ber-token aktif, daemon akan gagal
         * pull/push terus-menerus.
         */
        try {
            $pairedDevices = \App\Models\SyncDevice::query()
                ->where('is_active', true)
                ->whereNotNull('sync_token')
                ->count();

            $pairedDevices === 0
                ? $this->markWarn('Tidak ada device ter-pairing di laptop ini - jalur Cloud butuh re-pairing (Kode Guru).')
                : $this->markOk("Pairing tersedia ({$pairedDevices} device).");
        } catch (Throwable $e) {
            $this->markWarn('Status pairing gagal diperiksa: ' . $e->getMessage());
        }

        /*
         * [MODUL-4b] Dead-letter event masuk — dulu beku senyap /
         * ACK-skip; kini terlihat & replayable.
         */
        try {
            $dl = SyncDeadLetter::query()
                ->whereIn('status', ['retrying', 'dead'])
                ->selectRaw('status, count(*) c')
                ->groupBy('status')
                ->pluck('c', 'status');

            if ((int) ($dl['dead'] ?? 0) > 0) {
                $this->markWarn(
                    (int) $dl['dead'] . ' event DEAD-LETTER — replay dengan: php artisan eduxid:replay-dead'
                );
            }

            if ((int) ($dl['retrying'] ?? 0) > 0) {
                $this->info2(
                    (int) $dl['retrying'] . ' event menunggu referensi (retrying) — normal bila baru ada perubahan master.'
                );
            }
        } catch (Throwable $e) {
            $this->markWarn('Dead-letter gagal diperiksa (migrasi create_sync_dead_letters_table belum jalan?): ' . $e->getMessage());
        }
        /*
         * [06.2] Guru dengan nilai tertunda push otomatis.
         */
        try {
            $dirtyGrades = \App\Services\Sync\WorkProductPushService::pendingCount();

            $dirtyGrades > 0
                ? $this->info2("{$dirtyGrades} guru dengan nilai tertunda push otomatis (daemon mengerjakan, throttle 5 menit/guru).")
                : null;
        } catch (Throwable) {
        }

    }
}