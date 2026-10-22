<?php

namespace App\Console\Commands;

use App\Services\Sync\ManualCloudDirectoryAccountsV21R80;
use App\Services\Sync\SchoolIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProvisionManualCloudAccountsV21R80 extends Command
{
    protected $signature = 'eduxid:manual-cloud-accounts {--apply : Buat akun lokal yang belum ada} {--confirm= : Konfirmasi PROVISION-NPSN}';
    protected $description = 'Audit atau siapkan akun lokal GTK/murid manual tanpa push Cloud dan tanpa menampilkan identitas.';

    public function handle(ManualCloudDirectoryAccountsV21R80 $service): int
    {
        try {
            $schoolId = app(SchoolIdentity::class)->schoolId();
            if (!Schema::hasTable('users') || !Schema::hasTable('students') || !Schema::hasTable('teachers')) {
                throw new \RuntimeException('Tabel direktori lokal belum lengkap.');
            }
            $apply = (bool) $this->option('apply');
            if ($apply && $this->option('confirm') !== 'PROVISION-'.$schoolId) {
                throw new \RuntimeException('Konfirmasi tidak sesuai. Gunakan --confirm=PROVISION-'.$schoolId);
            }
            $teacherQuery = DB::table('teachers');
            if (Schema::hasColumn('teachers','dapodik_ptk_id')) {
                $teacherQuery->where(function ($q) {
                    $q->whereNull('dapodik_ptk_id')->orWhere('dapodik_ptk_id','like','manual-%');
                });
            }
            if (Schema::hasColumn('teachers','deleted_at')) $teacherQuery->whereNull('deleted_at');
            $teachers = $teacherQuery->orderBy('id')->get();
            $studentQuery = DB::table('students');
            if (Schema::hasColumn('students','dapodik_peserta_didik_id')) {
                $studentQuery->where('dapodik_peserta_didik_id','like','manual-%');
            }
            if (Schema::hasColumn('students','deleted_at')) $studentQuery->whereNull('deleted_at');
            $students = $studentQuery->orderBy('id')->get();
            $counts = ['GTK'=>[], 'MURID'=>[]];
            foreach ($teachers as $row) $counts['GTK'][$service->auditTeacher($row)] = ($counts['GTK'][$service->auditTeacher($row)] ?? 0) + 1;
            foreach ($students as $row) $counts['MURID'][$service->auditStudent($row)] = ($counts['MURID'][$service->auditStudent($row)] ?? 0) + 1;
            $this->line('V21R80 | NPSN disahkan | mode '.($apply?'APPLY':'READ ONLY'));
            foreach ($counts as $role=>$summary) {
                $this->line($role.' total: '.array_sum($summary));
                foreach ($summary as $name=>$n) $this->line('  '.$name.': '.$n);
            }
            $invalid = array_sum(array_map(fn ($v) => array_sum(array_filter($v, fn($k)=>!in_array($k,['new','existing','missing_identity'],true),ARRAY_FILTER_USE_KEY)), $counts));
            if ($invalid) throw new \RuntimeException('Ada identitas bentrok/tidak valid. Tidak ada akun dibuat; perbaiki dahulu.');
            if (!$apply) { $this->info('PASS READ ONLY: gunakan --apply --confirm=PROVISION-'.$schoolId.' setelah audit.'); return self::SUCCESS; }
            $created = DB::transaction(function () use ($teachers, $students, $service) {
                $t=0;$s=0;
                foreach ($teachers as $teacher) {
                    if ($service->provisionTeacher((int) $teacher->id)==='new') ++$t;
                }
                foreach ($students as $student) {
                    if ($service->provisionStudent((int) $student->id)==='new') ++$s;
                }
                return [$t,$s];
            });
            $this->info('PASS: akun lokal baru GTK='.$created[0].', murid='.$created[1].'. Sinkron Cloud berjalan pada siklus Admin berikutnya.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('[BLOCKED V21R80] '.(str_contains($e->getMessage(), 'SQL') ? 'Kesalahan database; periksa log lokal.' : $e->getMessage()));
            return self::FAILURE;
        }
    }
}
