<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\CloudActivationCredentialV21;
use App\Services\Sync\OpaqueIdentityV21;
use App\Services\Sync\OpaqueRouteRegistryV21;
use App\Services\Sync\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/** Print exactly one credential after LOCAL school Admin password reauthentication. */
final class EduxidIdentityV21Activation extends Command
{
    protected $signature = 'eduxid:identity-v21-activation {alias : Alias Cloud akun} {--role= : teacher/student} {--confirm= : STAGING_ONLY}';
    protected $description = 'Lihat password aktivasi PWA sekali saja (Admin staging, autentikasi ulang).';

    public function handle(): int
    {
        if (!OpaqueRouteRegistryV21::enabled() || $this->option('confirm') !== 'STAGING_ONLY') {
            $this->error('BLOCKED V21: perintah ini hanya untuk Admin staging.'); return self::FAILURE;
        }
        $alias = (string) $this->argument('alias');
        $role = (string) $this->option('role');
        if (!in_array($role, ['teacher','student'], true) || !OpaqueIdentityV21::isAlias($alias, $role)) {
            $this->error('BLOCKED V21: peran/alias tidak cocok.'); return self::FAILURE;
        }
        try {
            $password = (string) $this->secret('Masukkan ulang password Admin lokal: ');
            $admins = DB::table('users')->where('role', 'admin')->select('password')->get();
            $authorized = false;
            foreach ($admins as $admin) {
                if (Hash::check($password, (string) $admin->password)) { $authorized = true; break; }
            }
            unset($password);
            if (!$authorized) throw new RuntimeException('Otorisasi tidak valid.');
            $school = app(SchoolIdentity::class)->schoolId();
            $temporary = app(CloudActivationCredentialV21::class)->revealOnce($school, $role, $alias);
            $this->warn('RAHASIA: tampil sekali, serahkan kepada pemilik akun secara privat.');
            $this->line('Alias login: ' . $alias);
            $this->line('Password aktivasi: ' . $temporary);
            unset($temporary);
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('BLOCKED V21: otorisasi gagal atau password sudah pernah diambil.');
            return self::FAILURE;
        }
    }
}
