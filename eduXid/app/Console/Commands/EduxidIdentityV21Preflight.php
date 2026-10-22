<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\OpaqueIdentityV21;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class EduxidIdentityV21Preflight extends Command
{
    protected $signature = 'eduxid:identity-v21-preflight {--school= : NPSN sekolah, opsional}';
    protected $description = 'V21R2: audit hanya-baca kesiapan alias lokal; tidak mengubah Cloud, perangkat, atau pairing.';

    public function handle(): int
    {
        $this->line('=== EDUXID V21R2 | PEMERIKSAAN IDENTITAS (HANYA BACA) ===');
        $this->line('Identitas pribadi, secret, payload, dan token tidak akan ditampilkan.');
        $encodedSecret = trim((string) config('eduxid.identity_school_alias_key', ''));
        $secret = base64_decode(strtr($encodedSecret . str_repeat('=', (4 - strlen($encodedSecret) % 4) % 4), '-_', '+/'), true);
        if ($secret === false) $secret = '';
        $school = trim((string) ($this->option('school') ?: config('eduxid.school_id') ?: config('eduxid.npsn') ?: ''));
        if ($school === '' && Schema::hasTable('schools')) {
            $schools = DB::table('schools')->whereNotNull('npsn')->distinct()->pluck('npsn')->all();
            if (count($schools) === 1) $school = trim((string) $schools[0]);
        }
        $ready = true;
        if (!preg_match('/^[0-9]{8}$/D', $school)) {
            $this->error('BLOCKED: NPSN tidak valid atau ambigu.'); $ready = false;
        }
        if (strlen($secret) < 32) {
            $this->error('BLOCKED: kunci rahasia sekolah belum tersedia (minimal 32 byte).'); $ready = false;
        }
        $counts = ['teacher'=>0,'student'=>0];
        $collisions = 0;
        $seen = [];
        if ($ready && Schema::hasTable('users')) {
            $aliaser = new OpaqueIdentityV21($secret);
            DB::table('users')->select(['login_id','role'])->whereNotNull('login_id')->orderBy('id')->chunk(200, function ($rows) use ($aliaser,$school,&$counts,&$collisions,&$seen) {
                foreach ($rows as $row) {
                    $kind = strtolower(trim((string) ($row->role ?? '')));
                    if ($kind === 'siswa') $role = 'student';
                    elseif (in_array($kind, ['guru','wali_kelas','guru_bk','kepala_sekolah','tendik'], true)) $role = 'teacher';
                    else continue;
                    $alias = $aliaser->alias($school, $role, (string) $row->login_id);
                    $counts[$role]++;
                    if (isset($seen[$alias])) $collisions++;
                    $seen[$alias] = true;
                }
            });
        }
        $this->line('Akun GTK yang dapat dihitung: '.$counts['teacher']);
        $this->line('Akun Siswa yang dapat dihitung: '.$counts['student']);
        $this->line('Duplikasi alias / akun: '.$collisions);
        if ($collisions) $ready = false;
        foreach (['sync_devices'=>'device_id','lms_sync_records'=>'source_device_id'] as $table=>$column) {
            if (!Schema::hasTable($table)) continue;
            $column = Schema::hasColumn($table,$column) ? $column : (Schema::hasColumn($table,'target_device_id')?'target_device_id':null);
            if (!$column) continue;
            // Rows here are informational: source device may still have legacy IDs until re-pair.
            $this->line("Riwayat {$table} beridentitas perangkat: ".DB::table($table)->whereNotNull($column)->count());
        }
        $this->warn('BLOCKED: V21R2 hanya menyiapkan alias lokal. Direktori Cloud, semua payload, bundle, PWA dan route R2 belum ditransisikan sepenuhnya.');
        $this->line('Tidak ada data, pairing, token atau outbox yang diubah.');
        return self::FAILURE; // fail closed until later full migration
    }
}
