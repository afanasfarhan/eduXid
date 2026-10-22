<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AcademicPeriodService
{
    public const SESSION_KEY = 'semester_aktif';
    public const SESSION_YEAR_KEY = 'tahun_ajaran_aktif';
    public const SESSION_NAME_KEY = 'semester_nama_aktif';

    public static function availableSemesterIds(): array
    {
        try {
            $values = DB::table('homerooms')
                ->whereNotNull('semester_id')
                ->where('semester_id', '!=', '')
                ->distinct()
                ->pluck('semester_id')
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn ($value) => preg_match('/^\d{5}$/', $value) === 1)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }

        usort(
            $values,
            static fn (string $a, string $b): int => ((int) $b) <=> ((int) $a)
        );

        return $values;
    }

    public static function periods(): array
    {
        $latest = self::latestSemesterId();

        return array_map(
            static fn (string $id): array => [
                'id' => $id,
                'academic_year' => self::academicYear($id),
                'semester' => self::semesterName($id),
                'label' => self::label($id),
                'is_latest' => $id === $latest,
            ],
            self::availableSemesterIds()
        );
    }

    public static function latestSemesterId(): ?string
    {
        return self::availableSemesterIds()[0] ?? null;
    }

    public static function selectedIfValid(): ?string
    {
        if (!self::sessionAvailable()) {
            return null;
        }

        $selected = trim((string) session(self::SESSION_KEY, ''));

        if ($selected === '') {
            return null;
        }

        if (!in_array($selected, self::availableSemesterIds(), true)) {
            return null;
        }

        return $selected;
    }

    public static function selectedOrLatest(): ?string
    {
        return self::selectedIfValid() ?? self::latestSemesterId();
    }

    public static function select(string $semesterId): string
    {
        $semesterId = trim($semesterId);

        if (!in_array($semesterId, self::availableSemesterIds(), true)) {
            throw new \InvalidArgumentException(
                'Periode yang dipilih tidak tersedia pada database eduXid.'
            );
        }

        if (self::sessionAvailable()) {
            session([
                self::SESSION_KEY => $semesterId,
                self::SESSION_YEAR_KEY => self::academicYear($semesterId),
                self::SESSION_NAME_KEY => self::semesterName($semesterId),
            ]);
        }

        return $semesterId;
    }

    public static function selectLatestForCurrentSession(): ?string
    {
        $latest = self::latestSemesterId();

        if ($latest === null) {
            self::forgetSelection();
            return null;
        }

        return self::select($latest);
    }

    public static function ensureSelectedForCurrentSession(): ?string
    {
        $selected = self::selectedIfValid();

        if ($selected !== null) {
            return $selected;
        }

        return self::selectLatestForCurrentSession();
    }

    public static function forgetSelection(): void
    {
        if (!self::sessionAvailable()) {
            return;
        }

        session()->forget([
            self::SESSION_KEY,
            self::SESSION_YEAR_KEY,
            self::SESSION_NAME_KEY,
        ]);
    }

    public static function academicYear(?string $semesterId): string
    {
        $semesterId = trim((string) $semesterId);

        if (preg_match('/^(\d{4})(\d)$/', $semesterId, $m) !== 1) {
            return '-';
        }

        $start = (int) $m[1];

        return $start . '/' . ($start + 1);
    }

    public static function semesterName(?string $semesterId): string
    {
        $semesterId = trim((string) $semesterId);

        if (preg_match('/^\d{4}(\d)$/', $semesterId, $m) !== 1) {
            return '-';
        }

        return match ($m[1]) {
            '1' => 'Ganjil',
            '2' => 'Genap',
            default => 'Semester ' . $m[1],
        };
    }

    public static function label(?string $semesterId): string
    {
        $semesterId = trim((string) $semesterId);

        if ($semesterId === '') {
            return 'Belum memilih periode';
        }

        $year = self::academicYear($semesterId);
        $semester = self::semesterName($semesterId);

        if ($year === '-' || $semester === '-') {
            return $semesterId;
        }

        return $year . ' · ' . $semester;
    }

    public static function isGtkRole(?string $role): bool
    {
        return in_array(
            strtolower(trim((string) $role)),
            ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah', 'tendik'],
            true
        );
    }

    private static function sessionAvailable(): bool
    {
        try {
            return app()->bound('session');
        } catch (\Throwable) {
            return false;
        }
    }
}
