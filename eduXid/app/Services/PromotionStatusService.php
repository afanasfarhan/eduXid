<?php

declare(strict_types=1);

namespace App\Services;

final class PromotionStatusService
{
    public const NAIK = 'naik';
    public const TIDAK_NAIK = 'tidak_naik';
    public const LULUS = 'lulus';
    public const TIDAK_LULUS = 'tidak_lulus';

    public static function activeSemesterId(): ?string
    {
        if (class_exists(\App\Services\AcademicPeriodService::class)) {
            try {
                return AcademicPeriodService::selectedOrLatest();
            } catch (\Throwable) {
                // Fallback di bawah.
            }
        }

        try {
            $session = trim((string) session('semester_aktif', ''));

            if ($session !== '') {
                return $session;
            }
        } catch (\Throwable) {
        }

        try {
            $value = \Illuminate\Support\Facades\DB::table('homerooms')
                ->whereNotNull('semester_id')
                ->max('semester_id');

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isEvenSemester(?string $semesterId): bool
    {
        $semesterId = trim((string) $semesterId);

        return $semesterId !== ''
            && substr($semesterId, -1) === '2';
    }

    public static function gradeLevel(mixed $homeroom): ?int
    {
        if ($homeroom === null) {
            return null;
        }

        $candidates = [];

        if (is_object($homeroom)) {
            foreach ([
                'tingkat_pendidikan',
                'tingkat',
                'grade_level',
                'kelas',
                'nama',
            ] as $field) {
                if (isset($homeroom->{$field})) {
                    $candidates[] = (string) $homeroom->{$field};
                }
            }
        } elseif (is_array($homeroom)) {
            foreach ([
                'tingkat_pendidikan',
                'tingkat',
                'grade_level',
                'kelas',
                'nama',
            ] as $field) {
                if (array_key_exists($field, $homeroom)) {
                    $candidates[] = (string) $homeroom[$field];
                }
            }
        } else {
            $candidates[] = (string) $homeroom;
        }

        foreach ($candidates as $value) {
            if (preg_match('/(?:kelas\s*)?(\d{1,2})\b/i', $value, $m) === 1) {
                $level = (int) $m[1];

                if ($level >= 1 && $level <= 12) {
                    return $level;
                }
            }
        }

        return null;
    }

    public static function isFinalLevel(mixed $homeroom): bool
    {
        return in_array(
            self::gradeLevel($homeroom),
            [6, 9, 12],
            true
        );
    }

    public static function allowed(mixed $homeroom): array
    {
        if (self::isFinalLevel($homeroom)) {
            return [
                self::LULUS => 'Lulus',
                self::TIDAK_LULUS => 'Tidak Lulus',
            ];
        }

        return [
            self::NAIK => 'Naik Kelas',
            self::TIDAK_NAIK => 'Tidak Naik Kelas',
        ];
    }

    public static function normalize(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array(
            $value,
            [
                self::NAIK,
                self::TIDAK_NAIK,
                self::LULUS,
                self::TIDAK_LULUS,
            ],
            true
        ) ? $value : null;
    }

    public static function isAllowedForHomeroom(
        mixed $homeroom,
        mixed $value
    ): bool {
        $value = self::normalize($value);

        return $value !== null
            && array_key_exists($value, self::allowed($homeroom));
    }

    public static function label(mixed $value): ?string
    {
        return match (self::normalize($value)) {
            self::NAIK => 'Naik Kelas',
            self::TIDAK_NAIK => 'Tidak Naik Kelas',
            self::LULUS => 'Lulus',
            self::TIDAK_LULUS => 'Tidak Lulus',
            default => null,
        };
    }

    public static function reportHeading(mixed $homeroom): string
    {
        return self::isFinalLevel($homeroom)
            ? 'Kelulusan'
            : 'Kenaikan Kelas';
    }
}
