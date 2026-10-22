<?php

namespace App\Services\Sync;

use App\Models\Exam;
use App\Models\QuestionBank;
use App\Models\SyncDevice;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * [LJK-RECOVERY-V23]
 *
 * Memulihkan Paket Ujian LJK yang hilang di laptop GTK dari snapshot privat
 * teacher-only yang sudah tersimpan di eduXid Cloud / dikirim ulang PWA Guru.
 *
 * Prinsip keamanan:
 * - identitas guru diturunkan dari receiver PC + teacher_login_id;
 * - snapshot harus memiliki exam_sync_id yang persis sama dengan event;
 * - hanya tipe soal LJK resmi yang diterima;
 * - kunci jawaban tidak pernah diambil dari payload murid; hanya snapshot privat guru;
 * - tidak pernah menimpa QuestionBank lokal yang ternyata milik scope lain.
 */
class LjkExamRecoveryService
{
    private const ALLOWED_TYPES = ['pilihan_ganda', 'majemuk', 'benar_salah'];

    public function __construct(protected LmsSyncService $sync)
    {
    }

    /**
     * @return array{exam:?Exam,question_id_map:array<string,int>,source:string,error:?string,snapshot:?array}
     */
    public function recoverFromCloud(array $payload, string $receiverDeviceId, int $eventVersion): array
    {
        $identity = $this->resolveTeacherIdentity($payload, $receiverDeviceId);
        if (!$identity['teacher']) {
            return $this->failure($identity['error'] ?: 'Guru event LJK tidak dapat dikenali.');
        }

        $examSyncId = $this->incomingExamSyncId($payload);
        if ($examSyncId === '') {
            return $this->failure('exam_sync_id LJK kosong; recovery Cloud dibatalkan.');
        }

        try {
            $device = SyncDevice::query()
                ->where('device_id', $receiverDeviceId)
                ->where('is_active', true)
                ->whereNotNull('sync_token')
                ->first();

            if (!$device || trim((string) $device->sync_token) === '') {
                return $this->failure('Device PC guru belum memiliki sync_token aktif untuk membaca snapshot privat Cloud.');
            }

            $server = rtrim((string) $this->sync->getSyncServer(), '/');
            if ($server === '') {
                return $this->failure('URL eduXid Cloud belum tersedia untuk recovery Paket Ujian LJK.');
            }

            $request = Http::timeout(20)
                ->acceptJson()
                ->withToken((string) $device->sync_token);

            $ca = trim((string) config('eduxid.ca_cert', ''));
            if ($ca !== '' && is_file($ca)) {
                $request = $request->withOptions(['verify' => $ca]);
            }

            $response = $request->get($server . '/api/pwa/teacher/context');

            if (!$response->successful()) {
                return $this->failure('Snapshot privat LJK Cloud gagal dibaca HTTP ' . $response->status() . '.');
            }

            $data = $response->json('data');
            if (!is_array($data)) {
                return $this->failure('Respons context PWA Guru tidak memiliki data valid.');
            }

            $schoolId = trim((string) ($data['school_id'] ?? ''));
            $loginId = trim((string) ($data['login_id'] ?? ''));
            if ($schoolId !== trim((string) $this->sync->getSchoolId()) || strcasecmp($loginId, $identity['login']) !== 0) {
                return $this->failure('Snapshot privat Cloud bukan milik school/login penerima event LJK.');
            }

            $ljk = is_array($data['ljk_keys'] ?? null) ? $data['ljk_keys'] : [];
            $exams = is_array($ljk['exams'] ?? null) ? $ljk['exams'] : [];
            $snapshot = $this->findExamSnapshot($exams, $examSyncId, (int) ($payload['exam_id'] ?? 0));

            if (!$snapshot) {
                return $this->failure('Snapshot privat Cloud tidak lagi memuat exam_sync_id ' . $examSyncId . '.');
            }

            $result = $this->materializeSnapshot(
                $payload,
                $snapshot,
                $receiverDeviceId,
                $eventVersion,
                'cloud_private_snapshot'
            );
            $result['snapshot'] = $snapshot;
            return $result;
        } catch (Throwable $e) {
            Log::warning('[LJK-RECOVERY] Gagal recovery Paket Ujian dari Cloud.', [
                'receiver_device_id' => $receiverDeviceId,
                'exam_sync_id' => $examSyncId,
                'error' => $e->getMessage(),
            ]);
            return $this->failure($e->getMessage());
        }
    }

    /**
     * Materialisasi snapshot yang dikirim ulang PWA Guru.
     *
     * @return array{exam:?Exam,question_id_map:array<string,int>,source:string,error:?string,snapshot:?array}
     */
    public function materializeFromPwa(array $payload, string $receiverDeviceId, int $eventVersion): array
    {
        $snapshot = is_array($payload['exam_snapshot'] ?? null)
            ? $payload['exam_snapshot']
            : (is_array($payload['recovery_exam_snapshot'] ?? null)
                ? $payload['recovery_exam_snapshot']
                : (is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : null));

        if (!$snapshot) {
            return $this->failure('Payload recovery PWA tidak membawa exam_snapshot.');
        }

        return $this->materializeSnapshot(
            $payload,
            $snapshot,
            $receiverDeviceId,
            $eventVersion,
            'pwa_recovery_response'
        );
    }

    /** @return array<string,int> */
    public function storedQuestionMap(string $examSyncId): array
    {
        $examSyncId = trim($examSyncId);
        if ($examSyncId === '') {
            return [];
        }

        try {
            $raw = DB::table('app_settings')
                ->where('key', $this->mapSettingKey($examSyncId))
                ->value('value');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                return [];
            }

            $out = [];
            foreach ($decoded as $source => $local) {
                $localId = (int) $local;
                if ($localId > 0) {
                    $out[(string) $source] = $localId;
                }
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{teacher:?Teacher,login:string,error:?string} */
    private function resolveTeacherIdentity(array $payload, string $receiverDeviceId): array
    {
        $schoolId = trim((string) $this->sync->getSchoolId());
        $receiverLogin = $this->loginFromDevice($receiverDeviceId, $schoolId, 'PC');
        $payloadLogin = trim((string) ($payload['teacher_login_id'] ?? ''));
        $sourceDeviceId = trim((string) ($payload['source_device_id'] ?? $payload['sender_device_id'] ?? ''));
        $sourceLogin = $sourceDeviceId !== '' ? $this->loginFromDevice($sourceDeviceId, $schoolId, 'HP') : '';

        if ($receiverLogin === '') {
            return ['teacher' => null, 'login' => '', 'error' => 'Receiver event LJK bukan device PC guru canonical.'];
        }

        if ($payloadLogin !== '' && strcasecmp($payloadLogin, $receiverLogin) !== 0) {
            return ['teacher' => null, 'login' => '', 'error' => 'teacher_login_id event berbeda dari receiver PC.'];
        }
        if ($sourceDeviceId !== '' && $sourceLogin === '') {
            return ['teacher' => null, 'login' => '', 'error' => 'source_device_id recovery bukan HP guru canonical.'];
        }
        if ($sourceLogin !== '' && strcasecmp($sourceLogin, $receiverLogin) !== 0) {
            return ['teacher' => null, 'login' => '', 'error' => 'source HP recovery berbeda dari receiver PC.'];
        }

        $login = $payloadLogin !== '' ? $payloadLogin : ($sourceLogin !== '' ? $sourceLogin : $receiverLogin);
        if (OpaqueRouteRegistryV21::enabled()) {
            try {
                $route = app(OpaqueTeacherRouteV21::class);
                $teacher = $route->teacherForAlias($login);
                $route->assertDeviceOwnedByTeacher($receiverDeviceId, (int) $teacher->id, 'PC');
                if ($sourceDeviceId !== '') {
                    $route->assertDeviceOwnedByTeacher($sourceDeviceId, (int) $teacher->id, 'HP');
                }
            } catch (\Throwable) {
                return ['teacher' => null, 'login' => '', 'error' => 'BLOCKED V21: identitas pemulihan ujian tidak terverifikasi.'];
            }
        } else {
            $teacher = Teacher::query()
                ->where(function ($q) use ($login) {
                    $q->where('nip', $login)
                        ->orWhere('nik', $login)
                        ->orWhere('nuptk', $login);
                })
                ->first();
        }

        if (!$teacher) {
            return ['teacher' => null, 'login' => $login, 'error' => 'Data guru receiver belum tersedia lokal.'];
        }

        return ['teacher' => $teacher, 'login' => $login, 'error' => null];
    }

    /**
     * @return array{exam:?Exam,question_id_map:array<string,int>,source:string,error:?string,snapshot:?array}
     */
    private function materializeSnapshot(
        array $eventPayload,
        array $snapshot,
        string $receiverDeviceId,
        int $eventVersion,
        string $source
    ): array {
        $identity = $this->resolveTeacherIdentity($eventPayload, $receiverDeviceId);
        if (!$identity['teacher']) {
            return $this->failure($identity['error'] ?: 'Guru recovery tidak dapat dikenali.', $source);
        }

        $incomingSyncId = $this->incomingExamSyncId($eventPayload);
        $snapshotSyncId = $this->normalizeExamSyncId((string) (
            $snapshot['exam_sync_id']
            ?? $snapshot['sync_id']
            ?? $snapshot['exam']['sync_id']
            ?? ''
        ));

        if ($incomingSyncId === '' || $snapshotSyncId === '' || !hash_equals($incomingSyncId, $snapshotSyncId)) {
            return $this->failure('exam_sync_id snapshot recovery tidak sama dengan event LJK.', $source);
        }

        $questions = is_array($snapshot['questions'] ?? null) ? $snapshot['questions'] : [];
        if ($questions === []) {
            return $this->failure('Snapshot recovery tidak membawa daftar soal/kunci LJK.', $source);
        }

        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                return $this->failure('Snapshot recovery berisi format soal tidak valid pada posisi ' . ($index + 1) . '.', $source);
            }
            $type = strtolower(trim((string) ($question['jenis_soal'] ?? $question['type'] ?? '')));
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                return $this->failure('Snapshot recovery memuat tipe soal yang tidak kompatibel LJK: ' . ($type ?: 'kosong') . '.', $source);
            }
            if (!array_key_exists('kunci_jawaban', $question) && !array_key_exists('answer_key', $question)) {
                return $this->failure('Snapshot recovery tidak membawa kunci soal posisi ' . ($index + 1) . '.', $source);
            }
        }

        $teacher = $identity['teacher'];
        $subjectId = (int) ($snapshot['subject_id'] ?? $snapshot['exam']['subject_id'] ?? 0);
        if ($subjectId <= 0 || !DB::table('subjects')->where('id', $subjectId)->exists()) {
            return $this->failure('Mata pelajaran Paket Ujian recovery belum tersedia di laptop.', $source);
        }

        try {
            $result = DB::transaction(function () use (
                $snapshot,
                $incomingSyncId,
                $questions,
                $teacher,
                $subjectId,
                $eventVersion,
                $source
            ) {
                $existing = Exam::query()->where('sync_id', $incomingSyncId)->lockForUpdate()->first();
                if ($existing) {
                    return [
                        'exam' => $existing->load('questions'),
                        'question_id_map' => $this->storedQuestionMap($incomingSyncId),
                    ];
                }

                $now = now();
                $examValues = [
                    'teacher_id' => (int) $teacher->id,
                    'subject_id' => $subjectId,
                    'judul_ujian' => trim((string) ($snapshot['judul_ujian'] ?? $snapshot['title'] ?? 'Paket Ujian LJK')) ?: 'Paket Ujian LJK',
                    'deskripsi' => 'Dipulihkan otomatis dari snapshot privat LJK eduXid Cloud/PWA.',
                    'durasi_menit' => max(1, (int) ($snapshot['durasi_menit'] ?? 90)),
                    'token' => $this->uniqueRecoveryToken($incomingSyncId),
                    'status' => 'selesai',
                    'acak_soal' => false,
                    'sync_id' => $incomingSyncId,
                    'sync_version' => max(1, (int) ($snapshot['sync_version'] ?? $snapshot['version'] ?? $eventVersion)),
                    'sync_status' => 'synced',
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('exams', 'jenis_penilaian')) {
                    $jenis = strtolower(trim((string) ($snapshot['jenis_penilaian'] ?? 'sumatif')));
                    $examValues['jenis_penilaian'] = in_array($jenis, ['diagnostik', 'formatif', 'sumatif'], true) ? $jenis : 'sumatif';
                }
                if (Schema::hasColumn('exams', 'tingkat_kelas')) {
                    $grade = (int) ($snapshot['tingkat_kelas'] ?? 0);
                    $examValues['tingkat_kelas'] = in_array($grade, [10, 11, 12], true) ? $grade : null;
                }

                $examValues = $this->filterColumns('exams', $examValues);
                $examId = (int) DB::table('exams')->insertGetId($examValues);

                $questionMap = [];
                $ordered = collect($questions)->sortBy(fn ($q, $i) => (int) ($q['urutan'] ?? $q['number'] ?? ($i + 1)))->values();

                foreach ($ordered as $index => $question) {
                    $sourceQuestionId = (int) ($question['question_bank_id'] ?? $question['id'] ?? 0);
                    $localQuestionId = $this->resolveOrCreateQuestion(
                        $question,
                        $sourceQuestionId,
                        (int) $teacher->id,
                        $subjectId,
                        $incomingSyncId,
                        $index + 1,
                        (float) ($question['bobot_nilai'] ?? $question['weight'] ?? 1)
                    );

                    $questionMap[(string) ($sourceQuestionId > 0 ? $sourceQuestionId : ($index + 1))] = $localQuestionId;

                    $pivot = $this->filterColumns('exam_questions', [
                        'exam_id' => $examId,
                        'question_bank_id' => $localQuestionId,
                        'urutan' => (int) ($question['urutan'] ?? $question['number'] ?? ($index + 1)),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('exam_questions')->insert($pivot);
                }

                $this->storeQuestionMap($incomingSyncId, $questionMap);

                Log::warning('[LJK-RECOVERY] Paket Ujian dimaterialisasi otomatis.', [
                    'exam_id' => $examId,
                    'exam_sync_id' => $incomingSyncId,
                    'teacher_id' => (int) $teacher->id,
                    'subject_id' => $subjectId,
                    'question_count' => count($questionMap),
                    'source' => $source,
                ]);

                return [
                    'exam' => Exam::query()->with('questions')->find($examId),
                    'question_id_map' => $questionMap,
                ];
            }, 3);

            return [
                'exam' => $result['exam'] ?? null,
                'question_id_map' => $result['question_id_map'] ?? [],
                'source' => $source,
                'error' => null,
                'snapshot' => $snapshot,
            ];
        } catch (Throwable $e) {
            Log::error('[LJK-RECOVERY] Materialisasi Paket Ujian gagal.', [
                'exam_sync_id' => $incomingSyncId,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            return $this->failure($e->getMessage(), $source);
        }
    }

    private function resolveOrCreateQuestion(
        array $question,
        int $sourceQuestionId,
        int $teacherId,
        int $subjectId,
        string $examSyncId,
        int $position,
        float $weight
    ): int {
        $type = strtolower(trim((string) ($question['jenis_soal'] ?? $question['type'] ?? 'pilihan_ganda')));
        $key = $question['kunci_jawaban'] ?? $question['answer_key'] ?? null;
        $officialKey = $this->officialKeyFromLjkSnapshot($key, $type);

        if ($sourceQuestionId > 0) {
            $existing = QuestionBank::query()->find($sourceQuestionId);
            if ($existing && $this->questionCompatible($existing, $teacherId, $subjectId, $type, $officialKey)) {
                return (int) $existing->id;
            }

            if (!$existing) {
                $values = $this->questionValues(
                    $teacherId,
                    $subjectId,
                    $type,
                    $officialKey,
                    $examSyncId,
                    $position,
                    $weight
                );
                $values['id'] = $sourceQuestionId;
                try {
                    DB::table('question_banks')->insert($this->filterColumns('question_banks', $values));
                    return $sourceQuestionId;
                } catch (Throwable $e) {
                    Log::warning('[LJK-RECOVERY] Tidak dapat mempertahankan ID soal snapshot; membuat shadow ID.', [
                        'exam_sync_id' => $examSyncId,
                        'source_question_id' => $sourceQuestionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $values = $this->questionValues(
            $teacherId,
            $subjectId,
            $type,
            $officialKey,
            $examSyncId,
            $position,
            $weight
        );
        return (int) DB::table('question_banks')->insertGetId($this->filterColumns('question_banks', $values));
    }

    private function questionCompatible(
        QuestionBank $question,
        int $teacherId,
        int $subjectId,
        string $type,
        mixed $officialKey
    ): bool {
        if ((int) $question->teacher_id !== $teacherId || (int) $question->subject_id !== $subjectId) {
            return false;
        }
        if (strtolower(trim((string) $question->jenis_soal)) !== $type) {
            return false;
        }

        return $this->normalizedKeyString($question->kunci_jawaban, $type)
            === $this->normalizedKeyString($officialKey, $type);
    }

    private function questionValues(
        int $teacherId,
        int $subjectId,
        string $type,
        mixed $officialKey,
        string $examSyncId,
        int $position,
        float $weight
    ): array {
        $opsi = null;
        if ($type === 'benar_salah') {
            $opsi = json_encode(['B' => 'Benar', 'S' => 'Salah'], JSON_UNESCAPED_UNICODE);
        }

        return [
            'teacher_id' => $teacherId,
            'subject_id' => $subjectId,
            'jenis_soal' => $type,
            'pertanyaan' => '[Recovery LJK] Soal ' . $position . ' · ' . $examSyncId,
            'opsi_jawaban' => $opsi,
            'kunci_jawaban' => is_array($officialKey)
                ? json_encode(array_values($officialKey), JSON_UNESCAPED_UNICODE)
                : (string) $officialKey,
            'bobot_nilai' => $weight > 0 ? $weight : 1.0,
            'judul_materi' => 'Recovery Paket Ujian LJK',
            'indikator' => 'Dipulihkan otomatis dari snapshot privat LJK.',
            'level_kognitif' => 'C3',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function officialKeyFromLjkSnapshot(mixed $key, string $type): mixed
    {
        if ($type === 'benar_salah') {
            if (is_array($key)) {
                $key = reset($key);
            }
            $raw = strtoupper(trim((string) ($key ?? '')));
            return match ($raw) {
                'A', 'BENAR', 'TRUE', 'T' => 'B',
                'B', 'SALAH', 'FALSE', 'F' => 'S',
                'S' => 'S',
                default => $raw,
            };
        }

        if ($type === 'majemuk') {
            if (is_string($key)) {
                $decoded = json_decode($key, true);
                $key = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                    ? $decoded
                    : (preg_split('/[\s,;|+\/]+/u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: []);
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

    private function normalizedKeyString(mixed $key, string $type): string
    {
        $normalized = $this->officialKeyFromLjkSnapshot($key, $type);
        if (is_array($normalized)) {
            return json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        return (string) $normalized;
    }

    private function uniqueRecoveryToken(string $examSyncId): string
    {
        $base = 'R' . strtoupper(substr(hash('sha256', $examSyncId), 0, 11));
        $token = $base;
        $n = 0;
        while (DB::table('exams')->where('token', $token)->exists()) {
            $n++;
            $token = substr($base, 0, max(1, 12 - strlen((string) $n))) . $n;
        }
        return $token;
    }

    /** @param array<int,array> $exams */
    private function findExamSnapshot(array $exams, string $examSyncId, int $examId): ?array
    {
        foreach ($exams as $exam) {
            if (!is_array($exam)) {
                continue;
            }
            $candidate = $this->normalizeExamSyncId((string) ($exam['exam_sync_id'] ?? $exam['sync_id'] ?? ''));
            if ($candidate !== '' && hash_equals($examSyncId, $candidate)) {
                return $exam;
            }
        }

        if ($examSyncId === '' && $examId > 0) {
            foreach ($exams as $exam) {
                if (is_array($exam) && (int) ($exam['exam_id'] ?? $exam['id'] ?? 0) === $examId) {
                    return $exam;
                }
            }
        }

        return null;
    }

    private function incomingExamSyncId(array $payload): string
    {
        return $this->normalizeExamSyncId((string) (
            $payload['exam_sync_id']
            ?? $payload['exam']['sync_id']
            ?? $payload['package_sync_id']
            ?? $payload['private_package_sync_id']
            ?? ''
        ));
    }

    private function normalizeExamSyncId(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'ljkpkg::')) {
            $value = trim(substr($value, strlen('ljkpkg::')));
        }
        return $value;
    }

    private function loginFromDevice(string $deviceId, string $schoolId, string $suffix): string
    {
        $deviceId = trim($deviceId);
        $prefix = $schoolId . '-';
        $ending = '-' . strtoupper($suffix);
        if ($deviceId === '' || $schoolId === '') {
            return '';
        }
        if (!str_starts_with(strtoupper($deviceId), strtoupper($prefix)) || !str_ends_with(strtoupper($deviceId), $ending)) {
            return '';
        }
        $length = strlen($deviceId) - strlen($prefix) - strlen($ending);
        return $length > 0 ? trim(substr($deviceId, strlen($prefix), $length)) : '';
    }

    private function storeQuestionMap(string $examSyncId, array $map): void
    {
        $key = $this->mapSettingKey($examSyncId);
        $value = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $exists = DB::table('app_settings')->where('key', $key)->exists();
        if ($exists) {
            DB::table('app_settings')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);
        } else {
            DB::table('app_settings')->insert([
                'key' => $key,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function mapSettingKey(string $examSyncId): string
    {
        return 'sync::ljk_recovery_qmap::' . hash('sha256', $examSyncId);
    }

    private function filterColumns(string $table, array $row): array
    {
        try {
            $columns = array_flip(Schema::getColumnListing($table));
            return array_intersect_key($row, $columns);
        } catch (Throwable) {
            return $row;
        }
    }

    /**
     * @return array{exam:null,question_id_map:array<string,int>,source:string,error:string,snapshot:null}
     */
    private function failure(string $error, string $source = 'none'): array
    {
        return [
            'exam' => null,
            'question_id_map' => [],
            'source' => $source,
            'error' => $error,
            'snapshot' => null,
        ];
    }
}
