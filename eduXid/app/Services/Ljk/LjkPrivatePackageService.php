<?php

declare(strict_types=1);

namespace App\Services\Ljk;

use App\Models\Exam;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\SchoolIdentity;
use App\Services\Sync\VersionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LjkPrivatePackageService
{
    public function __construct(protected LmsSyncService $sync)
    {
    }

    public function build(Exam $exam): array
    {
        $exam->loadMissing(['questions']);

        if ($exam->questions->isEmpty()) {
            throw new \RuntimeException('Paket Ujian belum memiliki soal.');
        }

        if ($exam->questions->count() > 50) {
            throw new \RuntimeException('LJK A4 hanya memiliki 50 nomor. Setiap pernyataan kategori memakai satu nomor LJK; kurangi jumlah butir.');
        }
        $allowed = ['pilihan_ganda', 'majemuk', 'benar_salah'];
        $unsupported = [];
        foreach ($exam->questions->values() as $index => $question) {
            $type = strtolower(trim((string) ($question->jenis_soal ?? '')));
            if (!in_array($type, $allowed, true)) {
                $unsupported[] = ($index + 1) . ' (' . ($type !== '' ? $type : 'tanpa jenis') . ')';
            }
        }
        if ($unsupported !== []) {
            throw new \RuntimeException(
                'Paket LJK privat hanya menerima Pilihan Ganda, Pilihan Majemuk, dan Benar/Salah. '
                . 'Periksa soal nomor: ' . implode(', ', $unsupported) . '.'
            );
        }

        $questions = $exam->questions
            ->sortBy(fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? PHP_INT_MAX))
            ->values()
            ->map(function ($q, int $index) {
                $type = (string) ($q->jenis_soal ?? '');
                $rawKey = $q->kunci_jawaban ?? $q->kunci_pg ?? null;
                $key = $this->normalizeKey($rawKey, $type);

                return [
                    'no' => $index + 1,
                    'question_bank_id' => (int) $q->id,
                    'type' => $type,
                    'jenis_soal' => $type,
                    'key' => $key,
                    'kunci_jawaban' => $key,
                    'weight' => (float) ($q->bobot_nilai ?? 1),
                    'bobot_nilai' => (float) ($q->bobot_nilai ?? 1),
                ];
            })
            ->all();

        $homeroomIds = $this->resolveExamHomeroomIds($exam);
        $students = $this->buildRoster($homeroomIds, (int) $exam->teacher_id);
        $answerKeys = array_map(fn (array $q) => $q['key'], $questions);
        $questionTypes = array_map(fn (array $q) => $q['type'], $questions);
        $version = VersionService::next();

        return [
            'entity' => 'ljk_private_package',
            'schema' => 'eduxid.ljk.private.v2',
            'template_id' => 'LJK-A4-V3',
            'scanner_version' => '13R10',
            'exam_id' => (int) $exam->id,
            'exam_sync_id' => (string) ($exam->sync_id ?? ''),
            'title' => (string) ($exam->judul_ujian ?? 'Paket Ujian'),
            'judul_ujian' => (string) ($exam->judul_ujian ?? 'Paket Ujian'),
            'subject_id' => (int) ($exam->subject_id ?? 0),
            'teacher_id' => (int) $exam->teacher_id,
            'homeroom_ids' => $homeroomIds,
            'question_count' => count($questions),
            'questions' => $questions,
            'answer_keys' => $answerKeys,
            'kunci_jawaban' => $answerKeys,
            'question_types' => $questionTypes,
            'students' => $students,
            'roster' => $students,
            'student_count' => count($students),
            'key_count' => count(array_filter($answerKeys, fn ($v) => is_array($v) ? $v !== [] : trim((string) $v) !== '')),
            'version' => $version,
            'sync_version' => $version,
            'generated_at' => now()->toISOString(),
        ];
    }

    public function syncToTeacherPwa(Exam $exam): array
    {
        $payload = $this->build($exam);
        $teacher = Teacher::find((int) $exam->teacher_id);

        if (!$teacher) {
            throw new \RuntimeException('Guru pemilik paket ujian tidak ditemukan.');
        }

        $loginId = $this->teacherLoginId($teacher);
        if ($loginId === '') {
            throw new \RuntimeException('Login ID guru tidak ditemukan; paket privat LJK tidak dapat diarahkan ke PWA Guru.');
        }

        $deviceId = app(SchoolIdentity::class)->deviceId($loginId, 'HP');
        $entityId = 'ljk-private::' . ($payload['exam_sync_id'] !== '' ? $payload['exam_sync_id'] : $payload['exam_id']);

        $result = $this->sync->queueAndPush(
            'cbt_answer',
            $entityId,
            'upsert',
            (int) $payload['version'],
            $payload,
            $deviceId,
            null
        );

        Log::info('LJK v13R10 paket privat diarahkan ke PWA Guru.', [
            'exam_id' => $exam->id,
            'exam_sync_id' => $payload['exam_sync_id'],
            'target_device_id' => $deviceId,
            'key_count' => $payload['key_count'],
            'student_count' => $payload['student_count'],
        ]);

        return [
            'target_device_id' => $deviceId,
            'key_count' => $payload['key_count'],
            'student_count' => $payload['student_count'],
            'push' => $result,
        ];
    }

    /**
     * [LJK-DELETE-V27] Kirim tombstone privat ke PWA Guru.
     * Paket LJK privat hidup di device HP guru dan tidak ikut fan-out cbt_exam
     * ke siswa, sehingga penghapusan Exam wajib memiliki jalur private sendiri.
     */
    public function syncDeleteToTeacherPwa(Exam $exam, ?int $version = null): array
    {
        $teacher = Teacher::find((int) $exam->teacher_id);
        if (!$teacher) {
            throw new \RuntimeException('Guru pemilik paket ujian tidak ditemukan.');
        }

        $loginId = $this->teacherLoginId($teacher);
        if ($loginId === '') {
            throw new \RuntimeException('Login ID guru tidak ditemukan; tombstone Paket Ujian tidak dapat diarahkan ke PWA Guru.');
        }

        $examSyncId = trim((string) ($exam->sync_id ?? ''));
        $packageSyncId = 'ljkpkg::' . ($examSyncId !== '' ? $examSyncId : ('exam-' . (int) $exam->id));
        // Carrier dan identitas event harus sama dengan UPSERT paket privat.
        $cloudEntityId = 'ljk-private::' . ($examSyncId !== '' ? $examSyncId : (int) $exam->id);
        $deviceId = app(SchoolIdentity::class)->deviceId($loginId, 'HP');
        $version = $version !== null && $version > 0 ? $version : VersionService::next();

        $payload = [
            'sync_id' => $packageSyncId,
            'entity' => 'ljk_private_package',
            'deleted' => true,
            'exam_id' => (int) $exam->id,
            'exam_sync_id' => $examSyncId,
            'teacher_id' => (int) $exam->teacher_id, // Wajib untuk otorisasi scope Cloud.
            'target_device_id' => $deviceId,
            'sync_version' => $version,
            'updated_at' => now()->toISOString(),
        ];

        $result = $this->sync->queueAndPush(
            'cbt_answer',
            $cloudEntityId,
            'delete',
            $version,
            $payload,
            $deviceId,
            null
        );

        Log::info('[LJK-DELETE-V27] Tombstone Paket Ujian diarahkan ke PWA Guru.', [
            'exam_id' => $exam->id,
            'exam_sync_id' => $examSyncId,
            'target_device_id' => $deviceId,
            'status' => $result['status'] ?? null,
        ]);

        return [
            'target_device_id' => $deviceId,
            'package_sync_id' => $packageSyncId,
            'cloud_entity_id' => $cloudEntityId,
            'push' => $result,
        ];
    }

    private function resolveExamHomeroomIds(Exam $exam): array
    {
        $ids = collect();

        try {
            if (Schema::hasTable('lms_assignments') && Schema::hasTable('lms_topics') && Schema::hasTable('lms_courses')) {
                $linked = DB::table('lms_assignments as a')
                    ->join('lms_topics as t', 't.id', '=', 'a.topic_id')
                    ->join('lms_courses as c', 'c.id', '=', 't.course_id')
                    ->where('a.exam_id', (int) $exam->id)
                    ->pluck('c.homeroom_id');
                $ids = $ids->merge($linked);
            }
        } catch (Throwable $e) {
            Log::warning('LJK v13R10: gagal resolve rombel dari LMS assignment.', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($ids->filter(fn ($v) => (int) $v > 0)->isEmpty()) {
            $fallback = DB::table('teaching_assignments')
                ->where('teacher_id', (int) $exam->teacher_id)
                ->when((int) ($exam->subject_id ?? 0) > 0, fn ($q) => $q->where('subject_id', (int) $exam->subject_id))
                ->pluck('homeroom_id');
            $ids = $ids->merge($fallback);
        }

        return $ids
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function buildRoster(array $homeroomIds, int $teacherId): array
    {
        $query = DB::table('students')
            ->select('students.id', 'students.nisn', 'students.nama');

        if ($homeroomIds !== []) {
            $query->join('enrollments', 'enrollments.student_id', '=', 'students.id')
                ->whereIn('enrollments.homeroom_id', $homeroomIds)
                ->addSelect('enrollments.homeroom_id');
        } else {
            $teacherHomerooms = DB::table('teaching_assignments')
                ->where('teacher_id', $teacherId)
                ->pluck('homeroom_id')
                ->map(fn ($v) => (int) $v)
                ->filter(fn ($v) => $v > 0)
                ->unique()
                ->values()
                ->all();

            if ($teacherHomerooms === []) {
                return [];
            }

            $query->join('enrollments', 'enrollments.student_id', '=', 'students.id')
                ->whereIn('enrollments.homeroom_id', $teacherHomerooms)
                ->addSelect('enrollments.homeroom_id');
        }

        if (Schema::hasColumn('students', 'deleted_at')) {
            $query->whereNull('students.deleted_at');
        }

        return $query
            ->whereNotNull('students.nisn')
            ->where('students.nisn', '!=', '')
            ->orderBy('students.nama')
            ->get()
            ->map(function ($s) {
                $nisn = $this->normalizeNisn($s->nisn ?? '');
                return [
                    'id' => (int) $s->id,
                    'student_id' => (int) $s->id,
                    'nisn' => $nisn,
                    'student_nisn' => $nisn,
                    'nama' => (string) $s->nama,
                    'name' => (string) $s->nama,
                    'student_name' => (string) $s->nama,
                    'homeroom_id' => isset($s->homeroom_id) ? (int) $s->homeroom_id : null,
                ];
            })
            ->filter(fn ($s) => $s['nisn'] !== '')
            ->unique(fn ($s) => $s['nisn'])
            ->values()
            ->all();
    }

    private function teacherLoginId(Teacher $teacher): string
    {
        if (!empty($teacher->user_id)) {
            $login = trim((string) User::query()->whereKey($teacher->user_id)->value('login_id'));
            if ($login !== '') {
                return $login;
            }
        }

        foreach (['nip', 'nik', 'nuptk'] as $field) {
            $value = trim((string) ($teacher->{$field} ?? ''));
            if ($value === '') {
                continue;
            }
            $login = trim((string) User::query()->where('login_id', $value)->value('login_id'));
            if ($login !== '') {
                return $login;
            }

            // Paket GTK dapat memiliki Teacher sah tanpa user lokal NIP/NIK.
            // Device canonical tetap NPSN-loginId-HP, jadi identitas Teacher
            // boleh menjadi fallback selama record Teacher-nya sendiri valid.
            return $value;
        }

        return '';
    }

    private function normalizeNisn(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) < 10) {
            $digits = str_pad($digits, 10, '0', STR_PAD_LEFT);
        }
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function normalizeKey(mixed $key, string $type = ''): array
    {
        if (is_string($key)) {
            $raw = trim($key);
            if ($raw === '') {
                $key = [];
            } else {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $key = $decoded;
                } elseif (preg_match('/^[A-E]{2,5}$/i', $raw)) {
                    $key = str_split(strtoupper($raw));
                } else {
                    $key = preg_split('/[\s,;|+\/]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
            }
        }

        if (is_array($key) && !array_is_list($key)) {
            $assoc = [];
            foreach ($key as $k => $v) {
                $name = strtoupper(trim((string) $k));
                $truthy = $v === true || $v === 1 || $v === '1' || strtolower(trim((string) $v)) === 'true';
                if ($truthy && in_array($name, ['A', 'B', 'C', 'D', 'E'], true)) {
                    $assoc[] = $name;
                }
            }
            if ($assoc !== []) {
                $key = $assoc;
            }
        }

        if (!is_array($key)) {
            $key = $key === null ? [] : [$key];
        }

        $isTrueFalse = str_contains(strtolower($type), 'benar')
            || str_contains(strtolower($type), 'salah')
            || str_contains(strtolower($type), 'true')
            || str_contains(strtolower($type), 'false');

        $out = [];
        foreach ($key as $item) {
            if (is_array($item)) {
                foreach ($this->normalizeKey($item, $type) as $sub) {
                    if (!in_array($sub, $out, true)) {
                        $out[] = $sub;
                    }
                }
                continue;
            }

            $v = strtoupper(trim((string) $item));
            if (preg_match('/^[A-E]{2,5}$/', $v)) {
                foreach (str_split($v) as $ch) {
                    if (!in_array($ch, $out, true)) {
                        $out[] = $ch;
                    }
                }
                continue;
            }

            if ($isTrueFalse) {
                if (in_array($v, ['B', 'BENAR', 'TRUE', 'T'], true)) {
                    // Bank Soal: B = Benar → bubble LJK A.
                    $v = 'A';
                } elseif (in_array($v, ['S', 'SALAH', 'FALSE', 'F'], true)) {
                    // Bank Soal: S = Salah → bubble LJK B.
                    $v = 'B';
                }
            }

            if (in_array($v, ['A', 'B', 'C', 'D', 'E'], true) && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        sort($out);
        return $out;
    }
}
