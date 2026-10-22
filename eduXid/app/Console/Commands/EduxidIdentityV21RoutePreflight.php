<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\CloudOutboundPrivacyGuardV21;
use App\Services\Sync\OpaqueRouteRegistryV21;
use App\Services\Sync\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only safety gate. No personal values or secrets printed. */
final class EduxidIdentityV21RoutePreflight extends Command
{
    protected $signature = 'eduxid:identity-v21-route-preflight';
    protected $description = 'Staging V21R4: periksa cakupan alias, perangkat, dan outbox secara hanya-baca.';

    public function handle(): int
    {
        $this->line('=== EDUXID V21R4 | ROUTING ANONIM (HANYA BACA) ===');
        $this->line('Tidak menampilkan login_id, NIP/NIK/NISN, token, payload atau kunci.');
        $issues = [];
        if (!OpaqueRouteRegistryV21::enabled()) {
            $issues[] = 'Protokol anonim belum aktif.';
        }
        $school = null;
        try { $school = app(SchoolIdentity::class)->schoolId(); }
        catch (Throwable) { $issues[] = 'Identitas sekolah ambigu atau tidak tersedia.'; }
        if (!Schema::hasTable('cloud_identity_alias_routes')) $issues[] = 'Tabel alias lokal belum ada.';
        $covered = 0;
        $missing = 0;
        if ($school && OpaqueRouteRegistryV21::enabled() && Schema::hasTable('users') && Schema::hasTable('cloud_identity_alias_routes')) {
            $registry = app(OpaqueRouteRegistryV21::class);
            DB::table('users')->select(['id','role','login_id'])->whereNotNull('login_id')->orderBy('id')
              ->chunk(200, function ($rows) use ($registry, $school, &$covered, &$missing): void {
                foreach ($rows as $row) {
                    $role = strtolower(trim((string) ($row->role ?? '')));
                    $type = $role === 'siswa' ? 'student'
                        : (in_array($role, ['guru','wali_kelas','guru_bk','kepala_sekolah','tendik'], true) ? 'teacher' : null);
                    if (!$type) continue;
                    try { $registry->alias($school, $type, (string) $row->login_id); $covered++; }
                    catch (Throwable) { $missing++; }
                }
              });
        }
        $this->line('Alias tersedia pada akun lokal: ' . $covered);
        $this->line('Akun lokal belum punya alias: ' . $missing);
        if ($missing > 0) $issues[] = 'Cakupan alias akun lokal belum lengkap.';
        if (Schema::hasTable('sync_devices')) {
            $total = DB::table('sync_devices')->count();
            // Use shape validation instead of SQL LIKE assumptions.
            $raw = 0;
            DB::table('sync_devices')->select('id','device_id')->orderBy('id')->chunk(200, function ($rows) use (&$raw): void {
                foreach ($rows as $row) {
                    if (!preg_match('/^[0-9]{8}-[ts]_[a-z2-7]{26}-(PC|HP)$/D', (string) ($row->device_id ?? ''))) $raw++;
                }
            });
            $this->line("Device lokal: {$total}; memakai format lama: {$raw}");
            if ($raw > 0) $issues[] = 'Perangkat lama belum dipairing ulang.';
        }
        if (Schema::hasTable('lms_sync_records')) {
            $pending = DB::table('lms_sync_records')->whereIn('status', ['pending','processing','failed'])->count();
            $this->line('Record outbox belum final: ' . $pending);
            if ($pending) $issues[] = 'Outbox lama masih ada; jangan migrasikan tanpa strategi replay/ACK.';
        }
        // Full payload and PWA/R2 coverage have NOT been certified at this stage.
        $issues[] = 'Provisioning GTK/PWA, semua event LMS/LJK dan path R2 belum lulus uji end-to-end.';
        foreach ($issues as $issue) $this->warn('BLOCKED: ' . $issue);
        $this->line('Tidak ada data/perangkat/sinkronisasi yang diubah.');
        return self::FAILURE;
    }
}
