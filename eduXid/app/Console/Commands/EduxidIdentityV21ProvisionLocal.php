<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\CloudDirectoryAliasV21;
use App\Services\Sync\OpaqueIdentityV21;
use App\Services\Sync\OpaqueRouteRegistryV21;
use App\Services\Sync\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Trusted school-Admin staging operation only. Does not export school secret or
 * personal IDs. GTK receives verified aliases separately from a future signed
 * enrollment ticket; this command never sends mappings to Cloud.
 */
final class EduxidIdentityV21ProvisionLocal extends Command
{
    protected $signature = 'eduxid:identity-v21-provision-local {--apply : Isi registry lokal STAGING} {--confirm= : Harus STAGING_ONLY}';
    protected $description = 'Siapkan mapping alias akun lokal di Admin staging (default hanya menghitung).';

    public function handle(): int
    {
        $this->line('=== EDUXID V21R4 | PROVISION ALIAS ADMIN STAGING ===');
        if (!OpaqueRouteRegistryV21::enabled()) {
            $this->error('BLOCKED: jalankan hanya pada instalasi staging v21-opaque.'); return self::FAILURE;
        }
        if (!Schema::hasTable('cloud_identity_alias_routes') || !Schema::hasTable('users')) {
            $this->error('BLOCKED: migrasi registry atau tabel users belum ada.'); return self::FAILURE;
        }
        try {
            $school = app(SchoolIdentity::class)->schoolId();
            $aliaser = CloudDirectoryAliasV21::fromConfig(); // validates secret without exposing it
            unset($aliaser);
            $encoded = trim((string) config('eduxid.identity_school_alias_key', ''));
            $key = base64_decode(strtr($encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4), '-_', '+/'), true);
            if (!is_string($key) || strlen($key) !== 32) throw new \RuntimeException('Secret sekolah belum valid.');
            $generator = new OpaqueIdentityV21($key);
            $registry = app(OpaqueRouteRegistryV21::class);
            $apply = (bool) $this->option('apply');
            if ($apply && $this->option('confirm') !== 'STAGING_ONLY') {
                $this->error('BLOCKED: pengisian registry memerlukan --confirm=STAGING_ONLY.'); return self::FAILURE;
            }
            $count = 0; $skipped = 0;
            DB::table('users')->select('id','role','login_id')->whereNotNull('login_id')->orderBy('id')
                ->chunk(200, function ($rows) use ($school, $generator, $registry, $apply, &$count, &$skipped): void {
                    foreach ($rows as $row) {
                        $role = strtolower(trim((string) ($row->role ?? '')));
                        $type = $role === 'siswa' ? 'student'
                            : (in_array($role, ['guru','wali_kelas','guru_bk','kepala_sekolah','tendik'], true) ? 'teacher' : null);
                        if (!$type) { $skipped++; continue; }
                        $login = (string) $row->login_id;
                        $alias = $generator->alias($school, $type, $login);
                        if ($apply) $registry->provision($school, $type, $login, $alias);
                        $count++;
                    }
                });
            $this->line('Akun dapat dipetakan: ' . $count . '; dilewati (bukan GTK/Siswa): ' . $skipped);
            $this->line($apply ? 'Mapping lokal telah diisi, pairing/Cloud belum diubah.' : 'Dry-run selesai, tidak ada perubahan.');
            $this->warn('BLOCKED untuk produksi: mapping GTK/HP, PWA dan payload belum seluruhnya diverifikasi.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('BLOCKED: provisioning gagal. Periksa konfigurasi staging tanpa menampilkan rahasia.');
            return self::FAILURE;
        }
    }
}
