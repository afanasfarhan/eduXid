<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AtpService
{
    public function semesterId(): ?string
    {
        $semester = trim((string) session('semester_aktif', ''));

        if ($semester !== '') {
            return $semester;
        }

        if (Schema::hasTable('homerooms') && Schema::hasColumn('homerooms', 'semester_id')) {
            $semester = trim((string) DB::table('homerooms')->max('semester_id'));
            return $semester !== '' ? $semester : null;
        }

        return null;
    }

    public function teacherSubjects(int $teacherId): Collection
    {
        if (!Schema::hasTable('teaching_assignments') || !Schema::hasTable('subjects')) {
            return collect();
        }

        return DB::table('teaching_assignments')
            ->join('subjects', 'teaching_assignments.subject_id', '=', 'subjects.id')
            ->where('teaching_assignments.teacher_id', $teacherId)
            ->select('subjects.id', 'subjects.nama_mata_pelajaran')
            ->distinct()
            ->orderBy('subjects.nama_mata_pelajaran')
            ->get();
    }

    public function teacherGradesForSubject(int $teacherId, int $subjectId): Collection
    {
        if (!Schema::hasTable('teaching_assignments') || !Schema::hasTable('homerooms')) {
            return collect();
        }

        $query = DB::table('teaching_assignments')
            ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->where('teaching_assignments.teacher_id', $teacherId)
            ->where('teaching_assignments.subject_id', $subjectId);

        $semester = $this->semesterId();

        if ($semester !== null && Schema::hasColumn('homerooms', 'semester_id')) {
            $query->where('homerooms.semester_id', $semester);
        }

        return $query
            ->pluck('homerooms.tingkat_pendidikan')
            ->map(fn ($value) => $this->normalizeGradeLevel($value))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    public function normalizeGradeLevel(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $level = (int) $value;
            return ($level >= 1 && $level <= 13) ? $level : null;
        }

        $text = strtoupper(trim((string) $value));

        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(1[0-3]|[1-9])\b/', $text, $m)) {
            return (int) $m[1];
        }

        $roman = [
            'XIII' => 13,
            'XII' => 12,
            'XI' => 11,
            'X' => 10,
            'IX' => 9,
            'VIII' => 8,
            'VII' => 7,
            'VI' => 6,
            'V' => 5,
            'IV' => 4,
            'III' => 3,
            'II' => 2,
            'I' => 1,
        ];

        foreach ($roman as $token => $level) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $text)) {
                return $level;
            }
        }

        return null;
    }

    public function assignmentGradeLevel(int $assignmentId): ?int
    {
        if (!Schema::hasTable('teaching_assignments') || !Schema::hasTable('homerooms')) {
            return null;
        }

        $value = DB::table('teaching_assignments')
            ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->where('teaching_assignments.id', $assignmentId)
            ->value('homerooms.tingkat_pendidikan');

        return $this->normalizeGradeLevel($value);
    }

    public function items(int $teacherId, int $subjectId, ?int $gradeLevel = null): Collection
    {
        if (!Schema::hasTable('atp_items')) {
            return collect();
        }

        $query = DB::table('atp_items')
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->where('is_active', true);

        if ($gradeLevel !== null && Schema::hasColumn('atp_items', 'grade_level')) {
            $query->where('grade_level', $gradeLevel);
        }

        $semester = $this->semesterId();

        if ($semester !== null) {
            $query->where(function ($q) use ($semester) {
                $q->whereNull('semester_id')
                    ->orWhere('semester_id', '')
                    ->orWhere('semester_id', $semester);
            });
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function generateDescription(int $assignmentId, int $studentId): string
    {
        if (
            !Schema::hasTable('teaching_assignments')
            || !Schema::hasTable('assessment_scores')
            || !Schema::hasTable('atp_items')
        ) {
            return 'Belum terdapat data penilaian yang cukup untuk menghasilkan deskripsi capaian.';
        }

        $assignment = DB::table('teaching_assignments')
            ->where('id', $assignmentId)
            ->first();

        if (!$assignment) {
            return 'Belum terdapat data penilaian yang cukup untuk menghasilkan deskripsi capaian.';
        }

        $gradeLevel = $this->assignmentGradeLevel($assignmentId);

        $items = $this->items(
            (int) $assignment->teacher_id,
            (int) $assignment->subject_id,
            $gradeLevel
        );

        if ($items->isEmpty()) {
            $suffix = $gradeLevel ? " kelas {$gradeLevel}" : '';
            return "ATP belum diisi untuk mata pelajaran{$suffix} ini.";
        }

        $scores = DB::table('assessment_scores')
            ->where('teaching_assignment_id', $assignmentId)
            ->where('student_id', $studentId)
            ->orderBy('tanggal')
            ->get();

        if ($scores->isEmpty()) {
            return 'Belum terdapat penilaian harian untuk menghasilkan deskripsi capaian.';
        }

        $weights = [
            'diagnostik' => max(0, (int) ($assignment->bobot_diagnostik ?? 0)),
            'formatif' => max(0, (int) ($assignment->bobot_formatif ?? 0)),
            'sumatif' => max(0, (int) ($assignment->bobot_sumatif ?? 0)),
        ];

        if (array_sum($weights) <= 0) {
            $weights = ['diagnostik' => 1, 'formatif' => 1, 'sumatif' => 1];
        }

        $buckets = [];

        foreach ($items as $item) {
            $buckets[$item->id] = [
                'item' => $item,
                'values' => [],
            ];
        }

        foreach ($scores as $score) {
            $materi = $this->normalize((string) ($score->materi ?? ''));

            if ($materi === '') {
                continue;
            }

            $matched = $this->matchItemByMaterial($items, $materi);

            if (!$matched) {
                continue;
            }

            $weighted = 0.0;
            $usedWeight = 0.0;

            foreach ($weights as $field => $weight) {
                $value = $score->{$field} ?? null;

                if ($value === null || $value === '' || !is_numeric($value)) {
                    continue;
                }

                $effectiveWeight = $weight > 0 ? $weight : 1;
                $weighted += ((float) $value) * $effectiveWeight;
                $usedWeight += $effectiveWeight;
            }

            if ($usedWeight > 0) {
                $buckets[$matched->id]['values'][] = $weighted / $usedWeight;
            }
        }

        $results = [];

        foreach ($buckets as $bucket) {
            if ($bucket['values'] === []) {
                continue;
            }

            $results[] = [
                'item' => $bucket['item'],
                'average' => array_sum($bucket['values']) / count($bucket['values']),
            ];
        }

        if ($results === []) {
            return 'Nilai harian sudah tersedia, tetapi Materi pada Buku Nilai belum cocok dengan ATP. Pilih Materi dari ATP saat melakukan penilaian.';
        }

        usort($results, fn ($a, $b) => $b['average'] <=> $a['average']);

        $strongest = $results[0];
        $weakest = $results[count($results) - 1];

        $strongTp = $this->competencyText($strongest['item']);
        $strongPhrase = $this->positiveLevel((float) $strongest['average']);

        if (count($results) === 1) {
            if ($strongest['average'] >= 75) {
                return "Menunjukkan {$strongPhrase} pada tujuan pembelajaran: {$strongTp}.";
            }

            return "Perlu meningkatkan capaian pada tujuan pembelajaran: {$strongTp}.";
        }

        if ($weakest['average'] < 75) {
            $weakTp = $this->competencyText($weakest['item']);

            return "Menunjukkan {$strongPhrase} pada tujuan pembelajaran: {$strongTp}; "
                . "perlu meningkatkan capaian pada tujuan pembelajaran: {$weakTp}.";
        }

        $second = $results[1] ?? null;

        if ($second) {
            return "Menunjukkan {$strongPhrase} pada tujuan pembelajaran: {$strongTp}; "
                . "serta telah mencapai dengan baik tujuan pembelajaran: "
                . $this->competencyText($second['item']) . '.';
        }

        return "Menunjukkan {$strongPhrase} pada tujuan pembelajaran: {$strongTp}.";
    }

    private function matchItemByMaterial(Collection $items, string $material): ?object
    {
        foreach ($items as $item) {
            $candidate = $this->normalize((string) $item->materi);

            if ($candidate !== '' && $candidate === $material) {
                return $item;
            }
        }

        foreach ($items as $item) {
            $candidate = $this->normalize((string) $item->materi);

            if (
                $candidate !== ''
                && (
                    str_contains($material, $candidate)
                    || str_contains($candidate, $material)
                )
            ) {
                return $item;
            }
        }

        return null;
    }

    private function positiveLevel(float $score): string
    {
        return match (true) {
            $score >= 90 => 'capaian sangat menonjol',
            $score >= 85 => 'capaian menonjol',
            $score >= 80 => 'capaian baik',
            $score >= 75 => 'capaian cukup baik',
            default => 'perkembangan',
        };
    }

    private function competencyText(object $item): string
    {
        $tp = trim((string) ($item->tujuan_pembelajaran ?? ''));
        $indikator = trim((string) ($item->indikator_penilaian ?? ''));
        $materi = trim((string) ($item->materi ?? ''));

        if ($tp !== '') {
            return $tp;
        }

        if ($indikator !== '') {
            return $indikator;
        }

        return $materi !== '' ? $materi : 'kompetensi yang dinilai';
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
