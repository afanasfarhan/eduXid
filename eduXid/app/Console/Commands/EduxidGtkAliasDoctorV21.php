<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GtkBundle\Installation;
use App\Services\Sync\CloudDirectoryAliasV21;
use App\Services\Sync\OpaqueRouteRegistryV21;
use App\Services\Sync\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * V21R29 — Diagnose the *same teacher login selection* as GTK Provisioner.
 * Default = strictly read-only, no HTTP, no aliases or personal IDs printed.
 * Optional local repair is restricted to the School Admin installation and
 * requires fresh Admin password verification. It only fills missing mappings.
 */
final class EduxidGtkAliasDoctorV21 extends Command
{
    protected $signature = 'eduxid:gtk-alias-doctor-v21
        {--apply : Add missing GTK alias routes after reauthentication}
        {--confirm= : Must equal PROVISION_GTK when using --apply}';

    protected $description = 'Audit/reconcile GTK alias registry V21; read-only by default.';

    public function handle(): int
    {
        $this->line('eduXid V21R29 — DIAGNOSTIK ALIAS PAKET GTK');
        $this->line('Tidak menampilkan NIP/NIK/NISN, login ID, alias, APP_KEY atau secret sekolah.');
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'PROVISION_GTK') {
            $this->error('BLOCKED: --apply memerlukan --confirm=PROVISION_GTK.');
            return self::FAILURE;
        }
        try {
            if (!OpaqueRouteRegistryV21::enabled()) {
                $this->error('MODE: BUKAN v21-opaque. Proses dihentikan.');
                return self::FAILURE;
            }
            $this->line('MODE: v21-opaque');
            $provisionerPath = app_path('Services/GtkBundle/Provisioner.php');
            $source = is_file($provisionerPath) ? (string) file_get_contents($provisionerPath) : '';
            $v28 = str_contains($source, 'private function authorizedCloudLogin(')
                && str_contains($source, 'profile(int $teacherId, bool $provision = false)');
            $this->line('PROVISIONER_V21R28_ATAU_BARU: ' . ($v28 ? 'YA' : 'TIDAK'));
            if (!$v28) {
                $this->error('KODE_LAMA: aplikasi terpasang masih memanggil lookup sebelum provisioning.');
                return self::FAILURE;
            }
            if (Installation::owner() !== null) {
                $this->error('BLOCKED: ini instalasi GTK, bukan Admin Sekolah.');
                return self::FAILURE;
            }
            foreach (['schools', 'teachers', 'users', 'cloud_identity_alias_routes'] as $table) {
                if (!Schema::hasTable($table)) {
                    $this->error('TABEL_TIDAK_ADA: ' . $table . '. Periksa migration lokal.');
                    return self::FAILURE;
                }
            }
            $school = app(SchoolIdentity::class)->schoolId();
            $appKey = (string) config('app.key', '');
            $appKeyBytes = str_starts_with($appKey, 'base64:')
                ? base64_decode(substr($appKey, 7), true) : $appKey;
            if (!is_string($appKeyBytes) || strlen($appKeyBytes) < 32) {
                $this->error('APP_KEY_LOKAL: INVALID — tidak ada data diubah.');
                return self::FAILURE;
            }
            unset($appKey, $appKeyBytes);
            try {
                $aliaser = CloudDirectoryAliasV21::fromConfig();
            } catch (Throwable) {
                $this->error('SECRET_ALIAS_SEKOLAH: MISSING_ATAU_INVALID — pulihkan key sekolah yang sudah ada.');
                return self::FAILURE;
            }
            $this->line('APP_KEY_LOKAL: VALID');
            $this->line('IDENTITAS_SEKOLAH: VALID');
            $this->line('SECRET_ALIAS_SEKOLAH: VALID_32_BYTE');
            $this->line('REGISTRY_ROLE_TEACHER: ' . DB::table('cloud_identity_alias_routes')
                ->where('school_id', $school)->where('role', 'teacher')->count());
            $rows = DB::table('teachers')->orderBy('id');
            if (Schema::hasColumn('teachers', 'deleted_at')) $rows->whereNull('deleted_at');
            $rows = $rows->get();
            $counts = ['valid' => 0, 'missing' => 0, 'mismatch' => 0,
                'ambiguous' => 0, 'without_login' => 0, 'invalid_role' => 0, 'duplicates' => 0];
            $missingRoutes = [];
            $seenLogins = [];
            $registry = app(OpaqueRouteRegistryV21::class);
            foreach ($rows as $teacher) {
                $ids = array_values(array_unique(array_filter(array_map(
                    static fn ($v): string => trim((string) $v),
                    [$teacher->nip ?? '', $teacher->nik ?? '', $teacher->nuptk ?? '']
                ))));
                if ($ids === []) { $counts['without_login']++; continue; }
                $accounts = DB::table('users')->whereIn('login_id', $ids)->get();
                if ($accounts->count() > 1) { $counts['ambiguous']++; continue; }
                $account = $accounts->first();
                $login = trim((string) ($account->login_id ?? $ids[0]));
                if (!preg_match('/^[A-Za-z0-9._]{1,100}$/D', $login)) {
                    $counts['without_login']++; continue;
                }
                if ($account && !in_array(strtolower(trim((string) ($account->role ?? ''))),
                    ['guru','wali_kelas','guru_bk','kepala_sekolah','tendik'], true)) {
                    $counts['invalid_role']++; continue;
                }
                if (isset($seenLogins[$login])) { $counts['duplicates']++; continue; }
                $seenLogins[$login] = true;
                $expected = $aliaser->teacherAliasForAdmin($school, $login);
                $digest = OpaqueRouteRegistryV21::lookupDigest($school, 'teacher', $login);
                $stored = DB::table('cloud_identity_alias_routes')
                    ->where('school_id', $school)->where('role', 'teacher')
                    ->where('local_digest', $digest)->value('cloud_alias');
                if ($stored === null) {
                    // If APP_KEY changed, the same Cloud alias may still be
                    // bound to an older *different* local digest. Never create
                    // another route and hide the broken local identity key.
                    $priorDigest = DB::table('cloud_identity_alias_routes')
                        ->where('school_id', $school)->where('role', 'teacher')
                        ->where('cloud_alias', $expected)->value('local_digest');
                    if ($priorDigest !== null && !hash_equals((string) $priorDigest, $digest)) {
                        $counts['mismatch']++; continue;
                    }
                    // A prior Cloud activation for this digest must also agree.
                    if (Schema::hasTable('cloud_alias_activation_credentials')) {
                        $activation = DB::table('cloud_alias_activation_credentials')
                            ->where('school_id', $school)->where('role', 'teacher')
                            ->where('local_digest', $digest)->value('cloud_alias');
                        if ($activation !== null && !hash_equals((string) $activation, $expected)) {
                            $counts['mismatch']++; continue;
                        }
                    }
                    $counts['missing']++;
                    $missingRoutes[] = [$login, $expected];
                } elseif (is_string($stored) && hash_equals($stored, $expected)) {
                    $counts['valid']++;
                } else {
                    $counts['mismatch']++;
                }
            }
            unset($seenLogins);
            $this->line('GTK_ALIAS_VALID: ' . $counts['valid']);
            $this->line('GTK_ALIAS_MISSING: ' . $counts['missing']);
            $this->line('GTK_ALIAS_MISMATCH: ' . $counts['mismatch']);
            $this->line('GTK_LOGIN_AMBIGU: ' . $counts['ambiguous']);
            $this->line('GTK_TANPA_IDENTITAS_VALID: ' . $counts['without_login']);
            $this->line('GTK_ROLE_TIDAK_VALID: ' . $counts['invalid_role']);
            $this->line('GTK_LOGIN_DUPLIKAT: ' . $counts['duplicates']);
            if (array_sum(array_intersect_key($counts, array_flip(
                ['mismatch','ambiguous','without_login','invalid_role','duplicates']))) > 0) {
                $this->error('BLOCKED: konflik identitas. Tidak ada registry yang diubah. Perbaiki data asal/key lama terlebih dahulu.');
                return self::FAILURE;
            }
            if (!$apply) {
                $this->line('MODE_BACA_SAJA: tidak ada perubahan data.');
                if ($counts['missing'] > 0) {
                    $this->warn('GTK alias masih kosong; Admin dapat melakukan provisioning terautentikasi.');
                } else {
                    $this->line('GTK alias telah cocok. Jika web masih BLOCKED, periksa OPcache web atau pemanggil lain.');
                }
                return self::SUCCESS;
            }
            if ($counts['missing'] === 0) {
                $this->line('ALIAS_SUDAH_LENGKAP: tidak ada perubahan.');
                return self::SUCCESS;
            }
            // CLI has no web session. Require explicit operator approval and
            // fresh local Admin password; do not accept password on command line.
            $password = (string) $this->secret('Masukkan password Admin Sekolah lokal: ');
            $authorized = false;
            foreach (DB::table('users')->where('role', 'admin')->pluck('password') as $hash) {
                if (Hash::check($password, (string) $hash)) { $authorized = true; break; }
            }
            unset($password);
            if (!$authorized) {
                $this->error('OTORISASI_GAGAL: tidak ada data diubah.');
                return self::FAILURE;
            }
            DB::transaction(function () use ($missingRoutes, $school, $registry): void {
                foreach ($missingRoutes as [$login, $expected]) {
                    $registry->provision($school, 'teacher', $login, $expected);
                    $actual = $registry->alias($school, 'teacher', $login);
                    if (!hash_equals($expected, $actual)) {
                        throw new \RuntimeException('Registry alias hasil provisioning tidak cocok.');
                    }
                }
            });
            $this->info('PROVISION_PASS: ' . count($missingRoutes) . ' mapping guru ditambahkan.');
            $this->line('Tidak ada secret, token, data pribadi, perangkat, atau database lain diubah.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            // Do not leak exception messages that may contain local data.
            $this->error('BLOCKED: pemeriksaan/provisioning gagal tanpa membuka detail identitas.');
            $this->line('KELAS_KEGAGALAN: ' . class_basename($e));
            return self::FAILURE;
        }
    }
}
