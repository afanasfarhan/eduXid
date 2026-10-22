<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AcademicPeriodService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AcademicPeriodDoctor extends Command
{
    protected $signature = 'eduxid:period-doctor';

    protected $description = 'Audit periode akademik Dapodik/eduXid dan riwayat penugasan GTK';

    public function handle(): int
    {
        $this->info('eduXid Academic Period Doctor');
        $this->newLine();

        $periods = AcademicPeriodService::periods();

        if ($periods === []) {
            $this->warn('Belum ada semester_id valid pada tabel homerooms.');
            return self::SUCCESS;
        }

        $this->table(
            ['Semester ID', 'Tahun Ajaran', 'Semester', 'Terbaru'],
            array_map(
                static fn (array $p): array => [
                    $p['id'],
                    $p['academic_year'],
                    $p['semester'],
                    $p['is_latest'] ? 'YA' : '',
                ],
                $periods
            )
        );

        $rows = DB::table('teaching_assignments')
            ->join(
                'homerooms',
                'teaching_assignments.homeroom_id',
                '=',
                'homerooms.id'
            )
            ->selectRaw(
                'homerooms.semester_id as semester_id, COUNT(*) as jumlah'
            )
            ->groupBy('homerooms.semester_id')
            ->orderByDesc('homerooms.semester_id')
            ->get();

        $this->newLine();
        $this->info('Penugasan per periode:');
        $this->table(
            ['Semester ID', 'Jumlah Teaching Assignment'],
            $rows->map(
                static fn ($row): array => [
                    (string) $row->semester_id,
                    (int) $row->jumlah,
                ]
            )->all()
        );

        $edition = strtolower((string) config('eduxid.edition', 'school'));

        if ($edition === 'teacher') {
            $this->newLine();
            $this->info(
                'Mode teacher/GTK: semua semester yang ada di database lokal ' .
                'akan tersedia pada halaman pemilihan periode.'
            );
        }

        return self::SUCCESS;
    }
}
