<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\SyncDevice;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * [LATEST-STATE-V3] LjkKeySnapshotPushService
 *
 * Kunci Paket Ujian dikirim sebagai snapshot PRIVATE teacher-only.
 * Tidak memakai broadcast konten siswa dan tidak pernah masuk endpoint PWA Siswa.
 */
final class LjkKeySnapshotPushService
{
    /* [LJK-PIPELINE-V13] SERVICE */
    public function flushAll(int $limit = 20, bool $force = false): array
    {
        $result = ['pushed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $schoolId = app(SchoolIdentity::class)->schoolId();

        $devices = SyncDevice::query()
            ->where('school_id', $schoolId)
            ->where('device_type', 'teacher')
            ->where('is_active', true)
            ->whereNotNull('sync_token')
            ->orderBy('device_id')
            ->limit(max(1, $limit))
            ->get();

        $seenLoginIds = [];

        foreach ($devices as $device) {
            try {
                $deviceId = trim((string) $device->device_id);
                if (!str_ends_with($deviceId, '-PC')) {
                    $result['skipped']++;
                    continue;
                }

                $loginId = $this->loginIdFromDevice($schoolId, $deviceId);
                if ($loginId === '' || isset($seenLoginIds[$loginId])) {
                    $result['skipped']++;
                    continue;
                }
                $seenLoginIds[$loginId] = true;

                $teacher = $this->teacherByLoginId($loginId);
                if (!$teacher) {
                    $result['skipped']++;
                    continue;
                }

                $snapshot = $this->buildSnapshot((int) $teacher->id);
                $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $hash = hash('sha256', $json);
                $hashKey = 'sync::ljk_key_hash::teacher::' . (int) $teacher->id;
                $versionKey = 'sync::ljk_key_version::teacher::' . (int) $teacher->id;

                $previousHash = (string) (DB::table('app_settings')->where('key', $hashKey)->value('value') ?? '');
                if (!$force && hash_equals($previousHash, $hash)) {
                    $result['skipped']++;
                    continue;
                }

                $lastVersion = (int) (DB::table('app_settings')->where('key', $versionKey)->value('value') ?? 0);
                $version = VersionService::above($lastVersion);

                $version = $this->pushSnapshot(
                    (string) $device->sync_token,
                    $version,
                    $snapshot
                );

                $this->setSetting($hashKey, $hash);
                $this->setSetting($versionKey, (string) $version);
                $result['pushed']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = (string) ($device->device_id ?? '?') . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /** [LJK-PIPELINE-V13] Paksa refresh snapshot tanpa menunggu hash guard daemon. */
    public function forceFlushAll(int $limit = 100): array
    {
        return $this->flushAll($limit, true);
    }

    private function normalizeLjkKey(mixed $key, string $jenis): mixed
    {
        $jenis = strtolower(trim($jenis));

        if ($jenis === 'benar_salah') {
            if (is_array($key)) {
                $key = reset($key);
            }
            $raw = strtoupper(trim((string) ($key ?? '')));
            return match ($raw) {
                'B', 'BENAR', 'TRUE', 'T' => 'A',
                'S', 'SALAH', 'FALSE', 'F' => 'B',
                'A' => 'A',
                default => $raw,
            };
        }

        if ($jenis === 'majemuk') {
            if (is_string($key)) {
                $decoded = json_decode($key, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $key = $decoded;
                } else {
                    $key = preg_split('/[\s,;|+\/]+/u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
            }
            $out = [];
            foreach ((array) $key as $item) {
                $v = strtoupper(trim((string) $item));
                if (in_array($v, ['A', 'B', 'C', 'D', 'E'], true) && !in_array($v, $out, true)) {
                    $out[] = $v;
                }
            }
            sort($out);
            return $out;
        }

        return strtoupper(trim((string) ($key ?? '')));
    }
    private function loginIdFromDevice(string $schoolId, string $deviceId): string
    {
        $prefix = $schoolId . '-';
        $suffix = '-PC';

        if (!str_starts_with($deviceId, $prefix) || !str_ends_with($deviceId, $suffix)) {
            return '';
        }

        return trim(substr($deviceId, strlen($prefix), -strlen($suffix)));
    }

    private function teacherByLoginId(string $loginId): ?Teacher
    {
        $query = Teacher::query()->where(function ($q) use ($loginId) {
            $q->where('nip', $loginId)
                ->orWhere('nik', $loginId)
                ->orWhere('nuptk', $loginId);
        });

        if (Schema::hasColumn('teachers', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }

    /**
     * [ASSIGNMENT-SCOPE-V11]
     * Daemon berjalan via CLI sehingga tidak mempunyai session browser.
     * Gunakan semester TERBARU yang benar-benar dimiliki guru sebagai scope
     * sinkronisasi PWA; fallback ke semester rombel reguler terbaru sekolah.
     */
    private function resolveActiveAssignmentSemesterId(int $teacherId): ?string
    {
        $semesterId = DB::table('teaching_assignments')
            ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->where('teaching_assignments.teacher_id', $teacherId)
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
            ->max('homerooms.semester_id');

        if ($semesterId === null || trim((string) $semesterId) === '') {
            $semesterId = DB::table('teaching_assignments')
                ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
                ->where('teaching_assignments.teacher_id', $teacherId)
                ->max('homerooms.semester_id');
        }

        if ($semesterId === null || trim((string) $semesterId) === '') {
            $semesterId = DB::table('homerooms')
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%')
                ->max('semester_id');
        }

        $value = trim((string) ($semesterId ?? ''));
        return $value !== '' ? $value : null;
    }
    private function buildSnapshot(int $teacherId): array
    {
        /* [ASSIGNMENT-SCOPE-V11] Hanya penugasan semester aktif/terbaru guru. */
        $activeSemesterId = $this->resolveActiveAssignmentSemesterId($teacherId);

        $assignmentQuery = DB::table('teaching_assignments')
            ->leftJoin('subjects', 'subjects.id', '=', 'teaching_assignments.subject_id')
            ->leftJoin('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->where('teaching_assignments.teacher_id', $teacherId);

        if ($activeSemesterId !== null && $activeSemesterId !== '') {
            $assignmentQuery->where('homerooms.semester_id', $activeSemesterId);
        }

        $assignmentRows = $assignmentQuery
            ->select([
                'teaching_assignments.id as ta_id',
                'teaching_assignments.subject_id',
                'teaching_assignments.homeroom_id',
                'subjects.nama_mata_pelajaran as subject_name',
                'homerooms.nama as homeroom_name',
                'homerooms.semester_id as semester_id',
            ])
            ->orderBy('homerooms.nama')
            ->orderBy('subjects.nama_mata_pelajaran')
            ->get();

        $assignments = [];
        $studentsSoftDelete = Schema::hasColumn('students', 'deleted_at');

        foreach ($assignmentRows as $assignment) {
            $homeroomId = (int) ($assignment->homeroom_id ?? 0);
            $roster = [];

            if ($homeroomId > 0) {
                $rosterQuery = DB::table('enrollments')
                    ->join('students', 'students.id', '=', 'enrollments.student_id')
                    ->where('enrollments.homeroom_id', $homeroomId);

                if ($studentsSoftDelete) {
                    $rosterQuery->whereNull('students.deleted_at');
                }

                $roster = $rosterQuery
                    ->select(['students.id', 'students.nisn', 'students.nama'])
                    ->orderBy('students.nama')
                    ->get()
                    ->map(fn ($student) => [
                        'id' => (int) $student->id,
                        'nisn' => $student->nisn,
                        'nama' => $student->nama,
                    ])
                    ->values()
                    ->all();
            }

            $assignments[] = [
                'ta_id' => (int) $assignment->ta_id,
                'subject_id' => $assignment->subject_id !== null ? (int) $assignment->subject_id : null,
                'subject_name' => $assignment->subject_name,
                'homeroom_id' => $homeroomId > 0 ? $homeroomId : null,
                'homeroom_name' => $assignment->homeroom_name,
                'semester_id' => $assignment->semester_id ?? null,
                'roster' => $roster,
            ];
        }

        $exams = DB::table('exams')
            ->where('teacher_id', $teacherId)
            ->orderBy('id')
            ->get();

        $items = [];

        foreach ($exams as $exam) {
            $questions = DB::table('exam_questions as eq')
                ->join('question_banks as qb', 'qb.id', '=', 'eq.question_bank_id')
                ->where('eq.exam_id', (int) $exam->id)
                ->orderBy('eq.urutan')
                ->select('qb.*', 'eq.urutan')
                ->get();

            $questionItems = [];
            $compatible = $questions->isNotEmpty();

            foreach ($questions as $question) {
                $jenis = strtolower(trim((string) ($question->jenis_soal ?? '')));
                $supported = in_array($jenis, ['pilihan_ganda', 'majemuk', 'benar_salah'], true);
                if (!$supported) {
                    $compatible = false;
                }

                $key = $supported
                    ? $this->normalizeLjkKey($question->kunci_jawaban ?? null, $jenis)
                    : null;

                $questionItems[] = [
                    'question_bank_id' => (int) $question->id,
                    'urutan' => (int) ($question->urutan ?? 0),
                    'jenis_soal' => $question->jenis_soal ?? null,
                    'kunci_jawaban' => $key,
                    'bobot_nilai' => (float) ($question->bobot_nilai ?? $question->bobot ?? 1),
                    'ljk_supported' => $supported,
                ];
            }

            // Snapshot ini khusus Pemindai LJK. Paket tanpa soal atau yang
            // mengandung tipe selain PG/Majemuk/Benar-Salah tidak boleh
            // muncul sebagai paket siap-scan di PWA Guru.
            if (!$compatible) {
                continue;
            }

            $items[] = [
                'exam_id' => (int) $exam->id,
                'exam_sync_id' => trim((string) ($exam->sync_id ?? '')) ?: ('exam::' . (int) $exam->id),
                'judul_ujian' => $exam->judul_ujian ?? ('Paket Ujian #' . (int) $exam->id),
                'subject_id' => isset($exam->subject_id) ? (int) $exam->subject_id : null,
                'jenis_penilaian' => $exam->jenis_penilaian ?? null,
                'status' => $exam->status ?? null,
                'questions' => $questionItems,
            ];
        }

        return [
            'teacher_id' => $teacherId,
            'active_semester_id' => $activeSemesterId,
            'assignments' => $assignments,
            'exams' => $items,
        ];
    }

    /**
     * [LJK-PIPELINE-V13] Push snapshot dengan recovery version-conflict.
     * Mengembalikan versi yang benar-benar diterima Cloud.
     */
    private function pushSnapshot(string $token, int $version, array $snapshot): int
    {
        $server = rtrim((string) EduxidCloudConfig::syncServer(), '/');
        if ($server === '') {
            throw new \RuntimeException('URL eduXid Cloud belum dikonfigurasi.');
        }

        $options = [];
        $ca = trim((string) config('eduxid.ca_cert', ''));
        if ($ca !== '' && is_file($ca)) {
            $options['verify'] = $ca;
        }

        $send = function (int $sendVersion) use ($server, $token, $snapshot, $options) {
            $request = Http::timeout(30)->acceptJson()->asJson()->withToken($token);
            if ($options !== []) { $request = $request->withOptions($options); }
            return $request->post($server . '/api/teacher/ljk-key-snapshot', [
                'version' => $sendVersion,
                'snapshot' => $snapshot,
            ]);
        };

        $response = $send($version);

        if ($response->status() === 409) {
            $json = $response->json();
            $code = (string) ($json['error']['code'] ?? '');
            $stored = (int) ($json['error']['fields']['stored_version'] ?? 0);
            if ($code === 'SNAPSHOT_VERSION_CONFLICT' && $stored > 0) {
                $version = VersionService::above(max($version, $stored));
                $response = $send($version);
            }
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Push snapshot kunci LJK gagal HTTP ' . $response->status() . ': '
                . mb_substr($response->body(), 0, 500)
            );
        }

        return $version;
    }
    private function setSetting(string $key, string $value): void
    {
        $exists = DB::table('app_settings')->where('key', $key)->exists();
        $values = ['value' => $value];

        if (Schema::hasColumn('app_settings', 'updated_at')) {
            $values['updated_at'] = now();
        }

        if ($exists) {
            DB::table('app_settings')->where('key', $key)->update($values);
            return;
        }

        $values['key'] = $key;
        if (Schema::hasColumn('app_settings', 'created_at')) {
            $values['created_at'] = now();
        }
        DB::table('app_settings')->insert($values);
    }
}