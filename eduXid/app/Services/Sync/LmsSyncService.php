<?php

namespace App\Services\Sync;

use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Models\LmsAssignmentSubmission;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsSyncRecord;
use App\Models\LmsTopic;
use App\Models\Subject;
use App\Models\SyncDevice;
use App\Models\Teacher;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LmsSyncService
{
    /*
     * ============================================================
     * [F1] LMS SYNC SERVICE — consolidated (1.4 + Patch A/B + helper)
     *  [F1-VER]      Versi via VersionService.
     *  [F1-OUTBOX]   Record outbox SEBELUM validateConfiguration.
     *  [F1-BATCHVER] Semua batch: versi max(updated_at) sumber.
     *  [F1-TOMB]     Batch kosong → tombstone (dengan footprint guard).
     *  [F1-FINAL]    Produsen final_grade (d1..d8).
     *  [F1-NODE]     Retry push atas nama node_id pemilik record.
     *  [F1-REJECT]   Klasifikasi final/benign via error_code Worker.
     *  [F1-ATT]      Attendance push selalu teacher-routed.
     *  [D19]         pendingAckViaSync (apply-ack → HP guru).
     * ============================================================
     */

    protected const BULK_CHUNK_SIZE = 25;
    protected const BULK_TIMEOUT = 120;
    protected const EPOCH_FLOOR = 1600000000000;

    protected const FINAL_REJECT_CODES = [
        'INVALID_EVENT_ID', 'INVALID_ENTITY_TYPE', 'INVALID_ENTITY_ID',
        'INVALID_ACTION', 'INVALID_VERSION', 'INVALID_CREATED_AT',
        'INVALID_PAYLOAD', 'PAYLOAD_NOT_SERIALIZABLE',
        'INVALID_TARGET_DEVICE_ID', 'INVALID_TARGET_HOMEROOM_ID',
        'CROSS_SCHOOL_TARGET', 'INVALID_PERMISSION',
    ];

    protected const BENIGN_REJECT_CODES = ['CBT_ALREADY_SUBMITTED'];

    /** Usia maksimum record outbox sebelum dead-letter (24 jam). */
    public const OUTBOX_MAX_AGE_SECONDS = 24 * 3600;

    public function __construct(protected SyncClient $client)
    {
    }

    /* ============================================================
     * CONFIG
     * ============================================================ */

    public function syncServer(): string
    {
        return trim((string) EduxidCloudConfig::syncServer());
    }

    public function getSyncServer(): string
    {
        return $this->syncServer();
    }

    public function isCloudConfigured(): bool
    {
        return $this->syncServer() !== '';
    }

    public function getSchoolId(): string
    {
        return app(SchoolIdentity::class)->schoolId();
    }

    public function getDeviceId(): string
    {
        if (auth()->check()) {
            $user = auth()->user();

            $loginId = $user->login_id
                ?? $user->username
                ?? $user->nip
                ?? $user->nik
                ?? null;

            if ($loginId && trim((string) $loginId) !== '') {
                return app(SchoolIdentity::class)->teacherPcDeviceId((string) $loginId);
            }
        }

        $device = SyncDevice::query()
            ->where('school_id', $this->getSchoolId())
            ->where('device_type', 'teacher')
            ->where('is_active', true)
            ->whereNotNull('sync_token')
            ->orderBy('device_id')
            ->first();

        if ($device?->device_id) {
            if (OpaqueRouteRegistryV21::enabled()) {
                CloudOutboundPrivacyGuardV21::assertDevice($this->getSchoolId(), (string) $device->device_id, 'teacher');
            }
            return (string) $device->device_id;
        }

        throw new \RuntimeException(
            'Device ID eduXid Guru tidak dapat ditentukan — '
            . 'tidak ada device guru aktif dengan sync_token di laptop ini.'
        );
    }

    /** [F1-NODE] Device PC milik guru tertentu (daemon flush). */
    public function resolveTeacherPcDeviceId(int $teacherId): ?string
    {
        try {
            $teacher = Teacher::query()->find($teacherId);

            if (!$teacher) {
                return null;
            }

            $loginId = null;

            if (!empty($teacher->user_id)) {
                $loginId = User::query()->where('id', $teacher->user_id)->value('login_id');
            }

            if (!$loginId) {
                foreach (['nip', 'nik', 'nuptk'] as $col) {
                    $candidate = trim((string) ($teacher->{$col} ?? ''));

                    if ($candidate !== '') {
                        $hit = User::query()->where('login_id', $candidate)->value('login_id');

                        if ($hit) {
                            $loginId = $hit;
                            break;
                        }
                    }
                }
            }

            if (!$loginId) {
                return null;
            }

            $deviceId = app(SchoolIdentity::class)->teacherPcDeviceId((string) $loginId);

            $hasToken = SyncDevice::query()
                ->where('device_id', $deviceId)
                ->where('is_active', true)
                ->whereNotNull('sync_token')
                ->exists();

            return $hasToken ? $deviceId : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function validateConfiguration(): void
    {
        if ($this->syncServer() === '') {
            throw new \RuntimeException(
                'URL eduXid Cloud belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud.'
            );
        }

        $this->getSchoolId();
    }

    protected function validateAction(string $action): void
    {
        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            throw new \InvalidArgumentException("Action sync tidak valid: {$action}");
        }
    }

    protected function normalizeClientAction(string $action): string
    {
        $action = strtolower(trim($action));

        return match ($action) {
            'upsert', 'insert', 'save', 'put' => 'update',
            'remove' => 'delete',
            default => $action,
        };
    }

    /* ============================================================
     * VERSION / DATE — [F1-VER]
     * ============================================================ */

    protected function toIso($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d\TH:i:s.vP');
            }

            return (new DateTimeImmutable((string) $value))
                ->format('Y-m-d\TH:i:s.vP');
        } catch (Throwable) {
            return null;
        }
    }

    protected function currentVersion(): int
    {
        return VersionService::next();
    }

    protected function versionFromUpdatedAt($updatedAt): int
    {
        return VersionService::fromUpdatedAt($updatedAt);
    }

    protected function getCurrentVersion(?Model $model = null, ?array $payload = null): int
    {
        $payload ??= [];

        if (!empty($payload['sync_version'])) {
            return (int) $payload['sync_version'];
        }

        if (!empty($payload['updated_at'])) {
            return $this->versionFromUpdatedAt($payload['updated_at']);
        }

        if ($model?->updated_at) {
            return $this->versionFromUpdatedAt($model->updated_at);
        }

        return $this->currentVersion();
    }

    /* ============================================================
     * ATTENDANCE NORMALIZATION
     * ============================================================ */

    protected function assertWritableAttendanceDate(mixed $value): string
    {
        $tanggal = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) !== 1) {
            throw new \InvalidArgumentException('Tanggal pertemuan tidak valid. Gunakan tanggal kalender YYYY-MM-DD.');
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $dt->format('Y-m-d') !== $tanggal) {
            throw new \InvalidArgumentException('Tanggal pertemuan tidak valid.');
        }

        $year = (int) $dt->format('Y');
        $currentYear = (int) now()->format('Y');
        if ($year < ($currentYear - 1) || $year > ($currentYear + 1)) {
            throw new \InvalidArgumentException(
                'Tahun tanggal pertemuan di luar rentang yang diizinkan ('
                . ($currentYear - 1) . '–' . ($currentYear + 1) . ').'
            );
        }

        return $tanggal;
    }

    protected function normalizeAttendancePayload(array $payload, ?int $version = null): array
    {
        $updatedAt = $payload['updated_at'] ?? null;

        if (!$updatedAt) {
            $updatedAt = now()->format('Y-m-d\TH:i:s.vP');
        } else {
            $updatedAt = $this->toIso($updatedAt) ?? $updatedAt;
        }

        if ($version === null || $version <= 0) {
            $version = !empty($payload['sync_version'])
                ? (int) $payload['sync_version']
                : $this->versionFromUpdatedAt($updatedAt);
        }

        if ($version <= 0) {
            $version = $this->currentVersion();
        }

        $payload['updated_at'] = $updatedAt;
        $payload['sync_version'] = $version;

        if (!empty($payload['tanggal'])) {
            $payload['tanggal'] = trim((string) $payload['tanggal']);
        }

        if (empty($payload['entity']) && !empty($payload['entity_type'])) {
            $payload['entity'] = $payload['entity_type'];
        }

        return $payload;
    }

    protected function buildAttendanceEntityId(array $payload): string
    {
        if (!empty($payload['sync_id'])) {
            return (string) $payload['sync_id'];
        }

        if (!empty($payload['teaching_assignment_id']) && !empty($payload['tanggal'])) {
            return 'ta::'
                . $payload['teaching_assignment_id']
                . '::'
                . trim((string) $payload['tanggal']);
        }

        return 'attendance::' . Str::uuid();
    }

    /* ============================================================
     * MODEL / SYNC STATUS
     * ============================================================ */

    protected function ensureSyncId(Model $model): string
    {
        if (!$model->sync_id) {
            $model->sync_id = (string) Str::uuid();
            $model->save();
        }

        return (string) $model->sync_id;
    }

    protected function ensureEventId(?string $eventId, ?string $syncId = null): string
    {
        if ($eventId) {
            return $eventId;
        }

        if ($syncId) {
            return $syncId;
        }

        return (string) Str::uuid();
    }

    protected function shouldBroadcastToStudents(string $entity): bool
    {
        return SyncEventClassifier::category($entity) === SyncEventClassifier::BROADCAST;
    }

    protected function isTeacherRoutedEntity(string $entity): bool
    {
        return SyncEventClassifier::category($entity) === SyncEventClassifier::TEACHER_ROUTED;
    }

    protected function resolveRouting(
        string $entity,
        ?string $targetDeviceId,
        ?int $targetHomeroomId
    ): string {
        if ($this->shouldBroadcastToStudents($entity)) {
            return 'broadcast';
        }

        if (
            $this->isTeacherRoutedEntity($entity)
            && $targetDeviceId === null
            && $targetHomeroomId === null
        ) {
            return 'teacher_routed';
        }

        return 'private';
    }

    protected function resolveCourseHomeroomId(LmsCourse $course): int
    {
        $homeroomId = (int) ($course->homeroom_id ?? 0);

        if ($homeroomId <= 0) {
            throw new \RuntimeException("Course {$course->id} belum memiliki homeroom_id.");
        }

        return $homeroomId;
    }

    protected function resolveTopicHomeroomId(LmsTopic $topic): int
    {
        if (isset($topic->homeroom_id) && (int) $topic->homeroom_id > 0) {
            return (int) $topic->homeroom_id;
        }

        $course = $topic->course;

        if (!$course && $topic->course_id) {
            $course = LmsCourse::find($topic->course_id);
        }

        if (!$course) {
            throw new \RuntimeException("Course untuk topic {$topic->id} tidak ditemukan.");
        }

        return $this->resolveCourseHomeroomId($course);
    }

    protected function resolveHomeroomIdFromTopicReference(?int $topicId = null, ?string $topicSyncId = null): int
    {
        $query = LmsTopic::query();

        if ($topicId) {
            $query->where('id', $topicId);
        } elseif ($topicSyncId) {
            $query->where('sync_id', $topicSyncId);
        } else {
            throw new \RuntimeException('Referensi topic tidak tersedia.');
        }

        $topic = $query->first();

        if (!$topic) {
            throw new \RuntimeException('Topic LMS tidak ditemukan.');
        }

        return $this->resolveTopicHomeroomId($topic);
    }

    protected function resolveExamHomeroomId(Exam $exam): int
    {
        $assignment = LmsAssignment::query()
            ->with('topic.course')
            ->where('exam_id', $exam->id)
            ->first();

        if (!$assignment || !$assignment->topic) {
            throw new \RuntimeException(
                "EXAM_LAN_ONLY: Ujian \"{$exam->judul_ujian}\" belum terhubung aktivitas LMS "
                . '(berlaku lokal/LAN saja). Untuk PWA siswa: LMS Guru → Tambah Aktivitas → '
                . 'tipe CBT → hubungkan ujian ini → Publish.'
            );
        }

        return $this->resolveTopicHomeroomId($assignment->topic);
    }

    protected function resolveRecordHomeroomId(string $entity, array $payload): ?int
    {
        if (!empty($payload['target_homeroom_id'])) {
            return (int) $payload['target_homeroom_id'];
        }

        if (!empty($payload['homeroom_id'])) {
            return (int) $payload['homeroom_id'];
        }

        if ($entity === 'lms_topic') {
            return $this->resolveHomeroomIdFromTopicReference(
                !empty($payload['id']) ? (int) $payload['id'] : null,
                $payload['sync_id'] ?? null
            );
        }

        if ($entity === 'lms_material' || $entity === 'lms_assignment') {
            return $this->resolveHomeroomIdFromTopicReference(
                !empty($payload['topic_id']) ? (int) $payload['topic_id'] : null,
                $payload['topic_sync_id'] ?? null
            );
        }

        if ($entity === 'cbt_exam') {
            $examId = null;

            if (!empty($payload['exam_id'])) {
                $examId = (int) $payload['exam_id'];
            } elseif (!empty($payload['id'])) {
                $examId = (int) $payload['id'];
            }

            if ($examId) {
                $exam = Exam::find($examId);

                if ($exam) {
                    try {
                        return $this->resolveExamHomeroomId($exam);
                    } catch (Throwable) {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    protected function modelClassForEntity(string $entity): ?string
    {
        return match ($entity) {
            'lms_course' => LmsCourse::class,
            'lms_topic' => LmsTopic::class,
            'lms_material' => LmsMaterial::class,
            'lms_assignment' => LmsAssignment::class,
            'lms_assignment_submission' => LmsAssignmentSubmission::class,
            'cbt_exam' => Exam::class,
            default => null,
        };
    }

    protected function markLocalModelSynced(string $entity, string $entityId): void
    {
        $modelClass = $this->modelClassForEntity($entity);

        if (!$modelClass) {
            return;
        }

        try {
            $model = null;

            if (is_numeric($entityId)) {
                $model = $modelClass::find((int) $entityId);
            }

            if (!$model) {
                $model = $modelClass::query()->where('sync_id', $entityId)->first();
            }

            if (!$model) {
                return;
            }

            if (
                $model->isFillable('sync_status')
                || array_key_exists('sync_status', $model->getAttributes())
            ) {
                $model->sync_status = 'synced';
            }

            if (
                $model->isFillable('last_synced_at')
                || array_key_exists('last_synced_at', $model->getAttributes())
            ) {
                $model->last_synced_at = now();
            }

            $model->save();
        } catch (Throwable $e) {
            Log::warning('[LMS SYNC] Gagal update local sync status.', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function hasPendingSyncRecords(string $entity, string $entityId): bool
    {
        return LmsSyncRecord::query()
            ->where('entity_type', $entity)
            ->where(function ($query) use ($entityId) {
                $query
                    ->where('entity_id', $entityId)
                    ->orWhere('sync_id', $entityId);
            })
            ->whereIn('status', ['pending', 'failed'])
            ->exists();
    }

    protected function markLocalModelSyncedIfFullySynced(string $entity, string $entityId): void
    {
        if ($this->hasPendingSyncRecords($entity, $entityId)) {
            return;
        }

        $this->markLocalModelSynced($entity, $entityId);
    }

    /* ============================================================
     * TEACHING ASSIGNMENT DIRECTORY
     * ============================================================ */

    protected function homeroomHasSemesterId(): bool
    {
        try {
            return Schema::hasColumn('homerooms', 'semester_id');
        } catch (Throwable) {
            return false;
        }
    }

    protected function getHomeroomSemesterId(?int $homeroomId): mixed
    {
        if (!$homeroomId) {
            return null;
        }

        if (!$this->homeroomHasSemesterId()) {
            return null;
        }

        return DB::table('homerooms')->where('id', $homeroomId)->value('semester_id');
    }

    protected function studentsHaveSoftDelete(): bool
    {
        try {
            return Schema::hasColumn('students', 'deleted_at');
        } catch (Throwable) {
            return false;
        }
    }

    public function getTeachingAssignmentDirectory(): array
    {
        $user = auth()->user();

        if (!$user) {
            return [];
        }

        // V21R44: sebagian schema Dapodik tidak mempunyai teachers.user_id.
        // Identitas GTK lokal tetap dicocokkan ke NIP/NIK/NUPTK.
        $teacher = Schema::hasColumn('teachers', 'user_id')
            ? Teacher::query()->where('user_id', $user->id)->first()
            : null;

        if (!$teacher && !empty($user->login_id)) {
            $loginId = trim((string) $user->login_id);

            $teacher = Teacher::query()
                ->where(function ($q) use ($loginId) {
                    $q->where('nip', $loginId)
                        ->orWhere('nik', $loginId)
                        ->orWhere('nuptk', $loginId);
                })
                ->first();
        }

        if (!$teacher) {
            return [];
        }

        $teacherId = (int) $teacher->id;

        $rows = DB::table('teaching_assignments')
            ->leftJoin('subjects', 'subjects.id', '=', 'teaching_assignments.subject_id')
            ->leftJoin('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
            ->where('teaching_assignments.teacher_id', $teacherId)
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

        $result = [];
        $filterStudentDeleted = $this->studentsHaveSoftDelete();

        foreach ($rows as $row) {
            $students = [];

            if (!empty($row->homeroom_id)) {
                $rosterQuery = DB::table('enrollments')
                    ->join('students', 'students.id', '=', 'enrollments.student_id')
                    ->where('enrollments.homeroom_id', $row->homeroom_id);

                if ($filterStudentDeleted) {
                    $rosterQuery->whereNull('students.deleted_at');
                }

                $students = $rosterQuery
                    ->orderBy('students.nama')
                    ->get(['students.id', 'students.nisn', 'students.nama'])
                    ->map(fn ($student) => [
                        'id' => (int) $student->id,
                        'nisn' => $student->nisn ?? null,
                        'nama' => $student->nama ?? null,
                        'homeroom_id' => (int) $row->homeroom_id,
                    ])
                    ->values()
                    ->all();
            }

            $result[] = [
                'id' => $row->ta_id,
                'teaching_assignment_id' => $row->ta_id,
                'subject_id' => $row->subject_id,
                'subject_name' => $row->subject_name,
                'homeroom_id' => $row->homeroom_id,
                'homeroom_name' => $row->homeroom_name,
                'semester_id' => $row->semester_id,
                'students' => $students,
            ];
        }

        return $result;
    }

    /* ============================================================
     * COURSE / TOPIC
     * ============================================================ */

    public function pushCourse(LmsCourse $course, string $action = 'create'): array
    {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $syncId = $this->ensureSyncId($course);
        $homeroomId = $this->resolveCourseHomeroomId($course);
        $version = $this->getCurrentVersion($course);

        $homeroom = DB::table('homerooms')->where('id', $homeroomId)->first();

        $teachingAssignment = DB::table('teaching_assignments')
            ->where('teacher_id', $course->teacher_id)
            ->where('subject_id', $course->subject_id)
            ->where('homeroom_id', $homeroomId)
            ->orderByDesc('id')
            ->first();

        $rosterQuery = DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.homeroom_id', $homeroomId);

        if ($this->studentsHaveSoftDelete()) {
            $rosterQuery->whereNull('students.deleted_at');
        }

        $students = $rosterQuery
            ->orderBy('students.nama')
            ->get(['students.id', 'students.nisn', 'students.nama']);

        $semester = $course->semester_id ?? null;

        if ($semester === null) {
            $semester = $this->getHomeroomSemesterId($homeroomId);
        }

        $payload = [
            'sync_id' => $syncId,
            'id' => $course->id,
            'entity' => 'lms_course',
            'name' => $course->name,
            'description' => $course->description,
            'teacher_id' => $course->teacher_id,
            'subject_id' => $course->subject_id,
            'homeroom_id' => $homeroomId,
            'target_homeroom_id' => $homeroomId,
            'academic_year' => $homeroom->academic_year ?? $homeroom->tahun_ajaran ?? null,
            'semester' => $semester,
            'semester_id' => $semester,
            'is_published' => (bool) $course->is_published,
            'sync_version' => $version,
            'created_at' => $this->toIso($course->created_at),
            'updated_at' => $this->toIso($course->updated_at),
            'teaching_assignment_id' => $teachingAssignment?->id,
            'students' => $students->map(fn ($s) => [
                'id' => (int) $s->id,
                'nisn' => $s->nisn,
                'nama' => $s->nama,
            ])->values()->all(),
        ];

        return $this->queueAndPush('lms_course', $syncId, $action, $version, $payload, null, $homeroomId);
    }

    public function pushTopic(LmsTopic $topic, string $action = 'create'): array
    {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $syncId = $this->ensureSyncId($topic);
        $topic->loadMissing('course');
        $homeroomId = $this->resolveTopicHomeroomId($topic);
        $version = $this->getCurrentVersion($topic);

        $payload = [
            'sync_id' => $syncId,
            'id' => $topic->id,
            'entity' => 'lms_topic',
            'course_id' => $topic->course_id,
            'course_sync_id' => $topic->course?->sync_id,
            'homeroom_id' => $homeroomId,
            'target_homeroom_id' => $homeroomId,
            'title' => $topic->title,
            'description' => $topic->description,
            'sort_order' => (int) $topic->sort_order,
            'is_published' => (bool) $topic->is_published,
            'sync_version' => $version,
            'created_at' => $this->toIso($topic->created_at),
            'updated_at' => $this->toIso($topic->updated_at),
        ];

        return $this->queueAndPush('lms_topic', $syncId, $action, $version, $payload, null, $homeroomId);
    }

    /* ============================================================
     * MATERIAL — [FIX-403] hold-back
     * ============================================================ */

    public function pushMaterial(LmsMaterial $material, string $action = 'create'): array
    {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $syncId = $this->ensureSyncId($material);
        $material->loadMissing('topic.course');
        $homeroomId = $this->resolveTopicHomeroomId($material->topic);
        $version = $this->getCurrentVersion($material);

        $fileKey = null;
        $fileUploadFailed = false;
        $fileUploadError = null;

        if ($action !== 'delete' && !empty($material->file_path)) {
            $disk = Storage::disk('public');

            if (!$disk->exists($material->file_path)) {
                $fileUploadFailed = true;
                $fileUploadError = 'File tidak ada di disk lokal: ' . $material->file_path;
            } else {
                $device = $this->resolveFileUploadDevice();

                if (!$device?->sync_token) {
                    $fileUploadFailed = true;
                    $fileUploadError = 'Device guru sendiri belum pairing/aktif atau sync_token tidak tersedia. Upload dibatalkan.';
                } else {
                    try {
                        $filePath = $disk->path($material->file_path);
                        $extension = strtolower(pathinfo($material->file_path, PATHINFO_EXTENSION));
                        $fileKey = 'materials/' . $syncId
                            . ($extension !== '' ? '.' . $extension : '.bin');

                        $this->client->uploadFile(
                            $device->sync_token,
                            $fileKey,
                            $filePath,
                            $material->mime_type ?: 'application/octet-stream'
                        );
                    } catch (Throwable $e) {
                        $fileUploadFailed = true;
                        $fileUploadError = $e->getMessage();
                    }
                }
            }
        }

        $payload = [
            'sync_id' => $syncId,
            'id' => $material->id,
            'entity' => 'lms_material',
            'topic_id' => $material->topic_id,
            'topic_sync_id' => $material->topic?->sync_id,
            'homeroom_id' => $homeroomId,
            'target_homeroom_id' => $homeroomId,
            'title' => $material->title,
            'atp_item_id' => $material->atp_item_id,
            'type' => $material->type,
            'content' => $material->content,
            'external_url' => $material->external_url,
            'file' => [
                'path' => $material->file_path,
                'name' => $material->file_name,
                'mime_type' => $material->mime_type,
                'size' => $material->file_size,
            ],
            'file_path' => $material->file_path,
            'file_name' => $material->file_name,
            'mime_type' => $material->mime_type,
            'file_size' => $material->file_size,
            'file_key' => $fileKey,
            'file_upload_failed' => $fileUploadFailed,
            'sort_order' => (int) $material->sort_order,
            'is_published' => (bool) $material->is_published,
            'sync_version' => $version,
            'created_at' => $this->toIso($material->created_at),
            'updated_at' => $this->toIso($material->updated_at),
        ];

        if ($action !== 'delete' && !empty($material->file_path) && $fileUploadFailed) {
            $record = $this->createSyncRecord(
                'lms_material',
                $syncId,
                $action,
                $version,
                $payload,
                null,
                $homeroomId,
                null
            );

            $record->forceFill([
                'status' => 'pending',
                'last_error' => 'FILE_UPLOAD_FAILED: ' . $fileUploadError,
            ])->save();

            return [
                'success' => false,
                'status' => 'pending',
                'sync_id' => $record->sync_id,
                'event_id' => $record->sync_id,
                'entity' => 'lms_material',
                'entity_id' => $syncId,
                'target_device_id' => null,
                'target_homeroom_id' => $homeroomId,
                'teacher_routed' => false,
                'result' => null,
                'error' => 'FILE_UPLOAD_FAILED: ' . $fileUploadError,
            ];
        }

        return $this->queueAndPush('lms_material', $syncId, $action, $version, $payload, null, $homeroomId);
    }

    protected function resolveFileUploadDevice(): ?SyncDevice
    {
        try {
            $deviceId = trim((string) $this->getDeviceId());
            $schoolId = trim((string) $this->getSchoolId());

            if ($deviceId === '' || $schoolId === '') {
                Log::warning('[LMS SYNC] Upload file ditolak: identitas device/sekolah tidak lengkap.', [
                    'device_id' => $deviceId !== '' ? $deviceId : null,
                    'school_id' => $schoolId !== '' ? $schoolId : null,
                ]);

                return null;
            }

            $device = SyncDevice::query()
                ->where('device_id', $deviceId)
                ->where('school_id', $schoolId)
                ->where('device_type', 'teacher')
                ->where('is_active', true)
                ->whereNotNull('sync_token')
                ->first();

            if (!$device || trim((string) $device->sync_token) === '') {
                Log::warning('[LMS SYNC] Upload file ditolak: device guru sendiri belum aktif/pairing atau token tidak tersedia.', [
                    'device_id' => $deviceId,
                    'school_id' => $schoolId,
                ]);

                return null;
            }

            return $device;
        } catch (Throwable $e) {
            Log::warning('[LMS SYNC] Gagal memverifikasi device untuk upload file.', [
                'device_id' => $this->getDeviceId(),
                'school_id' => $this->getSchoolId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function pushMaterialToStudents(LmsMaterial $material, string $action = 'create'): array
    {
        return $this->pushMaterial($material, $action);
    }

    public function pushMaterialDelete(string $syncId, int $version = 1): array
    {
        return $this->queueEntityDelete('lms_material', $syncId, $version);
    }

    public function pushMaterialDeleteToStudents(string $syncId, int $version = 1): array
    {
        return $this->pushMaterialDelete($syncId, $version);
    }

    /* ============================================================
     * ASSIGNMENT
     * ============================================================ */

    public function pushAssignment(LmsAssignment $assignment, string $action = 'create'): array
    {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $syncId = $this->ensureSyncId($assignment);
        $assignment->loadMissing('topic.course');
        $homeroomId = $this->resolveTopicHomeroomId($assignment->topic);
        $version = $this->getCurrentVersion($assignment);

        $course = $assignment->topic?->course;
        $assignmentTeacherId = $course?->teacher_id !== null ? (int) $course->teacher_id : null;
        $assignmentSubjectId = $course?->subject_id !== null ? (int) $course->subject_id : null;
        $teachingAssignmentId = null;
        if ($assignmentTeacherId && $assignmentSubjectId && $homeroomId) {
            $teachingAssignmentId = DB::table('teaching_assignments')
                ->where('teacher_id', $assignmentTeacherId)
                ->where('subject_id', $assignmentSubjectId)
                ->where('homeroom_id', $homeroomId)
                ->orderByDesc('id')
                ->value('id');
        }

        $examSyncId = null;

        if ($assignment->exam_id) {
            $exam = Exam::find((int) $assignment->exam_id);

            if ($exam) {
                $examSyncId = $this->ensureSyncId($exam);
            }
        }

        $payload = [
            'sync_id' => $syncId,
            'id' => $assignment->id,
            'entity' => 'lms_assignment',
            'topic_id' => $assignment->topic_id,
            'topic_sync_id' => $assignment->topic?->sync_id,
            'homeroom_id' => $homeroomId,
            'target_homeroom_id' => $homeroomId,
            'teacher_id' => $assignmentTeacherId,
            'teaching_assignment_id' => $teachingAssignmentId !== null ? (int) $teachingAssignmentId : null,
            'title' => $assignment->title,
            'atp_item_id' => $assignment->atp_item_id,
            'instructions' => $assignment->instructions,
            'available_from' => $this->toIso($assignment->available_from),
            'due_at' => $this->toIso($assignment->due_at),
            'submission_type' => $assignment->submission_type,
            'max_score' => $assignment->max_score,
            'assignment_type' => $assignment->assignment_type,
            'exam_id' => $assignment->exam_id,
            'exam_sync_id' => $examSyncId,
            'is_published' => (bool) $assignment->is_published,
            'sync_version' => $version,
            'created_at' => $this->toIso($assignment->created_at),
            'updated_at' => $this->toIso($assignment->updated_at),
        ];

        return $this->queueAndPush('lms_assignment', $syncId, $action, $version, $payload, null, $homeroomId);
    }

    public function pushAssignmentToStudents(LmsAssignment $assignment, string $action = 'create'): array
    {
        return $this->pushAssignment($assignment, $action);
    }

    public function pushAssignmentDelete(string $syncId, int $version = 1): array
    {
        return $this->queueEntityDelete('lms_assignment', $syncId, $version);
    }

    public function pushAssignmentDeleteToStudents(string $syncId, int $version = 1): array
    {
        return $this->pushAssignmentDelete($syncId, $version);
    }

    protected function normalizeCbtGrade(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $grade = (int) $value;
            return in_array($grade, [10, 11, 12], true) ? $grade : null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/(?:kelas\s*)?(10|11|12)\b/i', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b(XII|XI|X)\b/i', $text, $m)) {
            return match (strtoupper($m[1])) {
                'X' => 10,
                'XI' => 11,
                'XII' => 12,
                default => null,
            };
        }

        return null;
    }

    /* ============================================================
     * CBT EXAM
     * ============================================================ */

    public function pushExam(Exam $exam, string $action = 'update'): array
    {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $assignment = LmsAssignment::query()
            ->with('topic.course')
            ->where('exam_id', $exam->id)
            ->first();

        if (!$assignment || !$assignment->topic) {
            throw new \RuntimeException(
                "EXAM_LAN_ONLY: Ujian \"{$exam->judul_ujian}\" berlaku lokal (LAN) saja. "
                . 'Untuk mengirim ke PWA siswa: LMS Guru → pilih kelas & topik → Tambah '
                . 'Aktivitas → tipe CBT → hubungkan dengan ujian ini → Publish.'
            );
        }

        $homeroomId = $this->resolveTopicHomeroomId($assignment->topic);
        $examCourse = $assignment->topic?->course;
        $examTeacherId = $exam->teacher_id !== null
            ? (int) $exam->teacher_id
            : ($examCourse?->teacher_id !== null ? (int) $examCourse->teacher_id : null);
        $examSubjectId = $exam->subject_id !== null
            ? (int) $exam->subject_id
            : ($examCourse?->subject_id !== null ? (int) $examCourse->subject_id : null);
        $examTeachingAssignmentId = null;
        if ($examTeacherId && $examSubjectId && $homeroomId) {
            $examTeachingAssignmentId = DB::table('teaching_assignments')
                ->where('teacher_id', $examTeacherId)
                ->where('subject_id', $examSubjectId)
                ->where('homeroom_id', $homeroomId)
                ->orderByDesc('id')
                ->value('id');
        }

        $examGrade = $this->normalizeCbtGrade($exam->tingkat_kelas ?? null);
        $homeroomGrade = $this->normalizeCbtGrade(
            DB::table('homerooms')->where('id', $homeroomId)->value('tingkat_pendidikan')
        );

        if ($examGrade !== null && $homeroomGrade === null) {
            throw new \RuntimeException(
                "EXAM_GRADE_MISMATCH: Tingkat kelas rombel tujuan PWA tidak teridentifikasi. "
                . 'Periksa tingkat pendidikan rombel pada data kelas.'
            );
        }

        if ($examGrade !== null && $homeroomGrade !== null && $examGrade !== $homeroomGrade) {
            throw new \RuntimeException(
                "EXAM_GRADE_MISMATCH: Ujian untuk Kelas {$examGrade} tidak boleh dikirim ke rombel Kelas {$homeroomGrade}."
            );
        }

        $syncId = $this->ensureSyncId($exam);
        $exam->loadMissing(['questions', 'subject', 'teacher']);
        $version = $this->getCurrentVersion($exam);

        $questions = $exam->questions
            ->sortBy(fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? 0))
            ->map(fn ($q) => $this->canonicalQuestion($q))
            ->values()->all();

        $subjectSyncId = null;

        if ($exam->subject_id) {
            $subjectSyncId = Subject::query()->where('id', $exam->subject_id)->value('sync_id');
        }

        $payload = [
            'sync_id' => $syncId,
            'id' => $exam->id,
            'entity' => 'cbt_exam',
            'exam_id' => $exam->id,
            'judul_ujian' => $exam->judul_ujian,
            'deskripsi' => $exam->deskripsi,
            'durasi_menit' => $exam->durasi_menit,
            'status' => $exam->status,
            'status_aktif' => ($exam->status === 'aktif'),
            'subject_id' => $exam->subject_id,
            'subject_sync_id' => $subjectSyncId,
            'tingkat_kelas' => $examGrade,
            'teacher_id' => $examTeacherId,
            'teaching_assignment_id' => $examTeachingAssignmentId !== null ? (int) $examTeachingAssignmentId : null,
            'teacher_name' => $exam->teacher?->nama ?? $exam->teacher?->name ?? null,
            'homeroom_id' => $homeroomId,
            'target_homeroom_id' => $homeroomId,
            'questions' => $questions,
            'sync_version' => $version,
            'created_at' => $this->toIso($exam->created_at),
            'updated_at' => $this->toIso($exam->updated_at),
        ];

        $this->assertNoAnswerKeyInPayload($payload);

        return $this->queueAndPush('cbt_exam', $syncId, $action, $version, $payload, null, $homeroomId);
    }

    protected function canonicalQuestion($q): array
    {
        $opsi = [];

        $opsiRaw = is_array($q->opsi_jawaban)
            ? $q->opsi_jawaban
            : json_decode((string) ($q->opsi_jawaban ?? ''), true);

        if ((string) $q->jenis_soal === 'benar_salah') {
            // Format kanonik CBT: B=Benar, S=Salah. Tipe benar/salah
            // dapat tersimpan tanpa opsi_jawaban di Bank Soal, jadi
            // payload PWA harus membentuk kedua pilihan secara eksplisit.
            $opsi = [
                ['key' => 'B', 'text' => 'Benar'],
                ['key' => 'S', 'text' => 'Salah'],
            ];
        } elseif (is_array($opsiRaw) && $opsiRaw !== []) {
            $i = 0;

            foreach ($opsiRaw as $k => $v) {
                $key = (is_string($k) && ctype_alpha($k)) ? strtoupper($k) : chr(65 + $i);
                $opsi[] = [
                    'key' => $key,
                    'text' => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v,
                ];
                $i++;
            }
        } else {
            foreach (['pilihan_a' => 'A', 'pilihan_b' => 'B', 'pilihan_c' => 'C', 'pilihan_d' => 'D', 'pilihan_e' => 'E'] as $col => $key) {
                if (isset($q->{$col}) && trim((string) $q->{$col}) !== '') {
                    $opsi[] = ['key' => $key, 'text' => (string) $q->{$col}];
                }
            }
        }

        return [
            'question_bank_id' => (int) $q->id,
            'jenis_soal' => $q->jenis_soal,
            'pertanyaan' => $q->pertanyaan,
            'opsi_jawaban' => $opsi,
            'bobot_nilai' => (float) ($q->bobot_nilai ?? 1),
            'urutan' => (int) ($q->pivot->urutan ?? $q->urutan ?? 0),
            'gambar' => $this->trimQuestionImage($q->gambar_soal ?? $q->gambar ?? null),
            'tka_group_code' => $q->tka_group_code ?? null,
            'tka_stimulus' => $q->tka_stimulus ?? null,
            'tka_category' => (bool)($q->tka_category ?? false),
            'tka_statement_order' => $q->tka_statement_order ?? null,
        ];
    }

    protected function trimQuestionImage($gambar)
    {
        if (!is_string($gambar) || !str_starts_with($gambar, 'data:')) {
            return $gambar;
        }

        try {
            $comma = strpos($gambar, ',');

            if ($comma === false) {
                return null;
            }

            $base64 = substr($gambar, $comma + 1);
            $size = strlen(base64_decode($base64, true) ?: '');

            if ($size > 400 * 1024) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $gambar;
    }

    protected function assertNoAnswerKeyInPayload(mixed $data, string $path = 'payload'): void
    {
        if (!is_array($data)) {
            return;
        }

        $blocked = ['kunci', 'answer_key', 'answerkey', 'correct_answer', 'correctanswer', 'jawaban_benar', 'jawabanbenar', 'correct_option', 'correctoption'];

        foreach ($data as $key => $value) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            foreach ($blocked as $blockedKey) {
                if ($normalized === $blockedKey || str_contains($normalized, $blockedKey)) {
                    throw new \RuntimeException("Answer key terdeteksi pada {$path}.{$key}");
                }
            }

            if (is_array($value)) {
                $this->assertNoAnswerKeyInPayload($value, $path . '.' . $key);
            }
        }
    }

    /**
     * Kumpulkan seluruh rombel yang pernah menjadi target broadcast sebuah entity.
     * Dipakai saat DELETE agar paket lama tetap dapat menyebarkan tombstone walau
     * relasi LMS saat ini sudah berubah/hilang.
     *
     * @return array<int>
     */
    protected function historicalBroadcastHomeroomIds(string $entity, string $entityId): array
    {
        try {
            return LmsSyncRecord::query()
                ->where('entity_type', $entity)
                ->where('entity_id', $entityId)
                ->get(['target_homeroom_id', 'payload'])
                ->flatMap(function ($record) {
                    $ids = [];

                    $target = (int) ($record->target_homeroom_id ?? 0);
                    if ($target > 0) {
                        $ids[] = $target;
                    }

                    $payload = $record->payload ?? null;
                    if (is_string($payload)) {
                        $decoded = json_decode($payload, true);
                        $payload = is_array($decoded) ? $decoded : [];
                    }

                    if (is_array($payload)) {
                        foreach (['target_homeroom_id', 'homeroom_id'] as $key) {
                            $value = (int) ($payload[$key] ?? 0);
                            if ($value > 0) {
                                $ids[] = $value;
                            }
                        }
                    }

                    return $ids;
                })
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('[CBT-DELETE-V26] Riwayat target broadcast tidak dapat dibaca.', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function pushExamDelete(string $syncId, int $version = 1): array
    {
        $exam = Exam::query()->where('sync_id', $syncId)->first();
        $homeroomIds = [];

        /*
         * 1) Scope kanonik saat ini: seluruh aktivitas LMS yang masih terhubung.
         *    Satu Paket Ujian dapat dipakai oleh lebih dari satu rombel.
         */
        if ($exam) {
            try {
                $assignments = LmsAssignment::query()
                    ->with('topic.course')
                    ->where('exam_id', $exam->id)
                    ->get();

                foreach ($assignments as $assignment) {
                    if (!$assignment->topic) {
                        continue;
                    }

                    try {
                        $id = $this->resolveTopicHomeroomId($assignment->topic);
                        if ($id > 0) {
                            $homeroomIds[] = $id;
                        }
                    } catch (Throwable) {
                    }
                }
            } catch (Throwable) {
            }

            /* Compatibility untuk skema yang pernah menyimpan homeroom di exams. */
            try {
                if (Schema::hasColumn('exams', 'homeroom_id')) {
                    $id = (int) (DB::table('exams')
                        ->where('id', $exam->id)
                        ->value('homeroom_id') ?? 0);
                    if ($id > 0) {
                        $homeroomIds[] = $id;
                    }
                }
            } catch (Throwable) {
            }
        }

        /*
         * 2) Historical scope: penting bila aktivitas LMS pernah dihapus/berubah
         *    sebelum paket CBT dihapus. Outbox lama adalah bukti target broadcast.
         */
        $homeroomIds = array_values(array_unique(array_merge(
            $homeroomIds,
            $this->historicalBroadcastHomeroomIds('cbt_exam', $syncId)
        )));

        /*
         * 3) Tidak ada scope sekarang DAN tidak pernah ada scope historis berarti
         *    paket ini LAN-only/belum pernah broadcast. Tidak ada perangkat PWA
         *    yang perlu menerima tombstone, jadi penghapusan lokal sah dilanjutkan.
         */
        if ($homeroomIds === []) {
            Log::info('[CBT-DELETE-V26] Tombstone exam dilewati — paket LAN-only/belum pernah broadcast.', [
                'sync_id' => $syncId,
                'exam_id' => $exam?->id,
            ]);

            return [
                'success' => true,
                'status' => 'skipped',
                'queued' => false,
                'reason' => 'EXAM_DELETE_LOCAL_ONLY_NO_BROADCAST_SCOPE',
                'entity' => 'cbt_exam',
                'entity_id' => $syncId,
            ];
        }

        $results = [];
        $hasPending = false;

        foreach ($homeroomIds as $homeroomId) {
            $payload = [
                'sync_id' => $syncId,
                'entity' => 'cbt_exam',
                'id' => $exam?->id,
                'homeroom_id' => $homeroomId,
                'target_homeroom_id' => $homeroomId,
                'sync_version' => $version,
                'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
            ];

            /* queueAndPush membuat outbox SEBELUM mencoba Cloud. */
            $result = $this->queueAndPush(
                'cbt_exam',
                $syncId,
                'delete',
                $version,
                $payload,
                null,
                $homeroomId
            );

            if (strtolower((string) ($result['status'] ?? '')) === 'pending') {
                $hasPending = true;
            }

            $results[] = [
                'homeroom_id' => $homeroomId,
                'result' => $result,
            ];
        }

        return [
            'success' => true,
            'status' => $hasPending ? 'pending' : 'queued',
            'queued' => true,
            'entity' => 'cbt_exam',
            'entity_id' => $syncId,
            'targets' => $homeroomIds,
            'results' => $results,
        ];
    }

    /* ============================================================
     * ATTENDANCE PUSH — [F1-ATT] selalu teacher-routed
     * ============================================================ */

    public function pushAttendanceSession(
        array $payload,
        ?string $targetDeviceId = null,
        string $action = 'update'
    ): array {
        $action = $this->normalizeClientAction($action);
        $this->validateAction($action);

        if ($action !== 'delete') {
            $payload['tanggal'] = $this->assertWritableAttendanceDate($payload['tanggal'] ?? null);
        }

        $payload = $this->normalizeAttendancePayload($payload);
        $entityId = $this->buildAttendanceEntityId($payload);
        $version = (int) $payload['sync_version'];

        unset($payload['target_device_id']);

        return $this->queueAndPush(
            'attendance_session',
            $entityId,
            $action,
            $version,
            $payload,
            null,
            null
        );
    }

    public function pushAttendanceSessionDelete(
        string $entityId,
        int $version,
        ?string $targetDeviceId = null,
        ?int $teachingAssignmentId = null,
        ?int $homeroomId = null
    ): array {
        $entityId = trim($entityId);

        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id attendance_session delete tidak boleh kosong.');
        }

        /*
         * Tombstone attendance harus mempertahankan identitas record sumber
         * secara persis. Ini penting untuk membersihkan data historis yang
         * telanjur memiliki tanggal salah, misalnya:
         *
         *     ta::9::262026-09-30
         *
         * Tanggal tersebut TIDAK dianggap valid dan tidak diperbaiki otomatis.
         * Nilainya hanya dibawa sebagai bagian identitas tombstone agar laptop
         * penerima dapat menghapus record lama yang sama persis.
         */
        $tanggal = null;

        if (preg_match('/^ta::(\d+)::(.+)$/', $entityId, $matches) === 1) {
            $entityTaId = (int) $matches[1];
            $entityTanggal = trim((string) $matches[2]);

            if ($teachingAssignmentId !== null && $teachingAssignmentId > 0 && $teachingAssignmentId !== $entityTaId) {
                throw new \InvalidArgumentException(
                    'teaching_assignment_id attendance_session delete tidak konsisten. Expected: '
                    . $entityTaId . ', received: ' . $teachingAssignmentId
                );
            }

            $teachingAssignmentId = $entityTaId;
            $tanggal = $entityTanggal !== '' ? $entityTanggal : null;
        }

        if (($teachingAssignmentId ?? 0) <= 0) {
            throw new \InvalidArgumentException(
                'teaching_assignment_id attendance_session delete tidak dapat ditentukan dari entity_id.'
            );
        }

        if ($tanggal === null) {
            throw new \InvalidArgumentException(
                'Tanggal attendance_session delete tidak dapat ditentukan dari entity_id.'
            );
        }

        if ($version <= 0) {
            $version = $this->currentVersion();
        }

        $payload = [
            'sync_id' => $entityId,
            'entity' => 'attendance_session',
            'teaching_assignment_id' => (int) $teachingAssignmentId,
            'homeroom_id' => max(0, (int) ($homeroomId ?? 0)),
            'tanggal' => $tanggal,
            'deleted' => true,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush(
            'attendance_session',
            $entityId,
            'delete',
            $version,
            $payload,
            null,
            null
        );
    }

    /* ============================================================
     * SUBMISSION
     * ============================================================ */

    public function pushSubmission(LmsAssignmentSubmission $submission, string $action = 'create'): array
    {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $syncId = $this->ensureSyncId($submission);
        $submission->loadMissing('assignment.topic.course');
        $assignment = $submission->assignment;

        if (!$assignment) {
            throw new \RuntimeException('Assignment submission tidak memiliki assignment.');
        }

        $studentIdRaw = trim((string) $submission->student_id);
        $student = null;

        if ($studentIdRaw !== '') {
            $student = DB::table('students')->where('nisn', $studentIdRaw)->first();

            if (!$student && ctype_digit($studentIdRaw)) {
                $student = DB::table('students')->where('id', (int) $studentIdRaw)->first();
            }

            if (!$student && ctype_digit($studentIdRaw)) {
                $student = Schema::hasColumn('students', 'user_id')
                    ? DB::table('students')->where('user_id', (int) $studentIdRaw)->first()
                    : null;
            }
        }

        $studentUser = $student
            ? User::where('login_id', $student->nisn)->first()
            : User::find((int) $submission->student_id);

        $studentNisn =
            $student?->nisn
            ?? $studentUser?->login_id
            ?? ($studentIdRaw !== '' ? $studentIdRaw : null);

        $studentName = $student?->nama ?? $studentUser?->name ?? 'Siswa';

        if (!$studentNisn) {
            throw new \RuntimeException("NISN siswa {$studentIdRaw} tidak ditemukan.");
        }

        $studentDeviceId = app(SchoolIdentity::class)->studentDeviceId((string) $studentNisn);
        $version = $this->getCurrentVersion($submission);

        $homeroomId = null;

        if ($assignment->topic) {
            $homeroomId = $this->resolveTopicHomeroomId($assignment->topic);
        }

        $feedbackValue = $submission->teacher_feedback ?? $submission->feedback ?? null;
        $answerValue = $submission->answer_text ?? $submission->answer ?? null;

        $payload = [
            'sync_id' => $syncId,
            'id' => $submission->id,
            'entity' => 'lms_assignment_submission',
            'assignment_id' => $assignment->id,
            'assignment_sync_id' => $assignment->sync_id,
            'student_id' => $studentNisn,
            'student_nisn' => $studentNisn,
            'student_name' => $studentName,
            'student_device_id' => $studentDeviceId,
            'homeroom_id' => $homeroomId,
            'answer' => $answerValue,
            'answer_text' => $answerValue,
            'file_path' => $submission->file_path,
            'file_name' => $submission->file_name,
            'submitted_at' => $this->toIso($submission->submitted_at),
            'score' => $submission->score,
            'grade_version' => (int) ($submission->grade_version ?? 0),
            'score_type' => $submission->score_type ?? 'sumatif',
            'feedback' => $feedbackValue,
            'teacher_feedback' => $feedbackValue,
            'status' => $submission->status,
            'sync_version' => $version,
            'created_at' => $this->toIso($submission->created_at),
            'updated_at' => $this->toIso($submission->updated_at),
        ];

        // V21R6: only Cloud envelope is aliased; never modify submission DB rows.
        if (CloudOutboundPrivacyGuardV21::enabled()) {
            $payload = CloudStudentSubmissionV21::outbound(
                $this->getSchoolId(), (string) $studentNisn, $payload
            );
        }

        return $this->queueAndPush(
            'lms_assignment_submission',
            $syncId,
            $action,
            $version,
            $payload,
            $studentDeviceId,
            null
        );
    }

    public function pushGradedSubmissionToTeachers(
        LmsAssignmentSubmission $submission,
        string $action = 'update',
        ?string $targetDeviceId = null
    ): array {
        $action = $this->normalizeClientAction($action);
        $this->validateConfiguration();
        $this->validateAction($action);

        $syncId = $this->ensureSyncId($submission);
        $submission->loadMissing('assignment.topic.course');
        $assignment = $submission->assignment;

        if (!$assignment) {
            throw new \RuntimeException('Assignment submission tidak memiliki assignment.');
        }

        $version = $this->getCurrentVersion($submission);

        $studentIdRaw = trim((string) $submission->student_id);

        $student = DB::table('students')->where('nisn', $studentIdRaw)->first();

        if (!$student && ctype_digit($studentIdRaw)) {
            $student = DB::table('students')->where('id', (int) $studentIdRaw)->first();
        }

        if (!$student && ctype_digit($studentIdRaw)) {
            $student = Schema::hasColumn('students', 'user_id')
                    ? DB::table('students')->where('user_id', (int) $studentIdRaw)->first()
                    : null;
        }

        $studentUser = $student
            ? User::where('login_id', $student->nisn)->first()
            : User::find((int) $submission->student_id);

        $studentNisn =
            $student?->nisn
            ?? $studentUser?->login_id
            ?? ($studentIdRaw !== '' ? $studentIdRaw : null);

        $studentName = $student?->nama ?? $studentUser?->name ?? 'Siswa';

        if (!$targetDeviceId) {
            $targetDeviceId = $this->resolveTeacherDeviceIdFromAssignment($assignment);
        }

        if (!$targetDeviceId) {
            throw new \RuntimeException('Device Guru target untuk nilai submission tidak ditemukan.');
        }

        $homeroomId = null;

        if ($assignment->topic) {
            $homeroomId = $this->resolveTopicHomeroomId($assignment->topic);
        }

        $feedbackValue = $submission->teacher_feedback ?? $submission->feedback ?? null;

        $payload = [
            'sync_id' => $syncId,
            'id' => $submission->id,
            'entity' => 'lms_assignment_submission',
            'assignment_id' => $assignment->id,
            'assignment_sync_id' => $assignment->sync_id,
            'student_id' => $studentNisn,
            'student_nisn' => $studentNisn,
            'student_name' => $studentName,
            'homeroom_id' => $homeroomId,
            'score' => $submission->score,
            'grade_version' => (int) ($submission->grade_version ?? 0),
            'score_type' => $submission->score_type ?? 'sumatif',
            'feedback' => $feedbackValue,
            'teacher_feedback' => $feedbackValue,
            'status' => $submission->status,
            'sync_version' => $version,
            'created_at' => $this->toIso($submission->created_at),
            'updated_at' => $this->toIso($submission->updated_at),
            'target_device_id' => $targetDeviceId,
        ];

        // V21R6: only Cloud envelope is aliased; never modify submission DB rows.
        if (CloudOutboundPrivacyGuardV21::enabled()) {
            $payload = CloudStudentSubmissionV21::outbound(
                $this->getSchoolId(), (string) $studentNisn, $payload
            );
        }

        return $this->queueAndPush(
            'lms_assignment_submission',
            $syncId,
            $action,
            $version,
            $payload,
            $targetDeviceId,
            null
        );
    }

    protected function resolveTeacherDeviceIdFromAssignment(LmsAssignment $assignment): ?string
    {
        $teacherId = $assignment->topic?->course?->teacher_id;

        if (!$teacherId) {
            return null;
        }

        $teacher = Teacher::find($teacherId);

        if (!$teacher) {
            return null;
        }

        $candidateLoginIds = array_filter([
            $teacher->nip ?? null,
            $teacher->nik ?? null,
            $teacher->nuptk ?? null,
            $teacher->login_id ?? null,
        ]);

        foreach ($candidateLoginIds as $loginId) {
            $user = User::query()->where('login_id', $loginId)->first();

            if ($user) {
                return app(SchoolIdentity::class)->teacherHpDeviceId((string) $user->login_id);
            }
        }

        if (isset($teacher->user_id) && $teacher->user_id) {
            $user = User::find($teacher->user_id);

            if ($user?->login_id) {
                return app(SchoolIdentity::class)->teacherHpDeviceId((string) $user->login_id);
            }
        }

        return null;
    }

    /* ============================================================
     * FINAL GRADE + APPLY-ACK
     * ============================================================ */

    public function pushFinalGradeToTeachers(array $payload): array
    {
        $this->validateConfiguration();

        $syncId = (string) ($payload['sync_id'] ?? ('final_grade::' . Str::uuid()));
        $version = (int) ($payload['sync_version'] ?? 0);

        if ($version <= 0) {
            $version = $this->currentVersion();
            $payload['sync_version'] = $version;
        }

        return $this->queueAndPush('final_grade', $syncId, 'upsert', $version, $payload, null, null);
    }

    /** [D19] Apply-ACK: laporan balik hasil apply laptop → HP guru. */
    public function pendingAckViaSync(string $ackSyncId, array $payload, string $targetDeviceId): array
    {
        return $this->queueAndPush(
            'apply_ack',
            $ackSyncId,
            'upsert',
            VersionService::next(),
            array_merge($payload, [
                'sync_id' => $ackSyncId,
                'entity' => 'apply_ack',
            ]),
            $targetDeviceId,
            null
        );
    }

    protected function weightVersion(int $taId): int
    {
        return (int) (DB::table('app_settings')->where('key', 'grades::weights::' . $taId)
            ->value('value') ?? 0);
    }

    /** [F1-FINAL] Batch nilai akhir satu TA — kosong → tombstone. */
    public function pushFinalGradeBatchForAssignment(int $teachingAssignmentId, ?string $sourceDeviceId = null): array
    {
        $this->validateConfiguration();

        $ta = DB::table('teaching_assignments')
            ->where('id', $teachingAssignmentId)
            ->first();

        if (!$ta) {
            throw new \RuntimeException("Teaching assignment {$teachingAssignmentId} tidak ditemukan.");
        }

        $homeroomTeacherId = $ta->homeroom_id
            ? DB::table('homerooms')->where('id', $ta->homeroom_id)->value('homeroom_teacher_id')
            : null;

        $rows = $this->stripSyncMetadata(
            DB::table('final_grades')
                ->where('teaching_assignment_id', $teachingAssignmentId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        $syncId = 'final_grade::ta::' . $teachingAssignmentId;

        if ($rows === []) {
            return $this->queueTombstone(
                'final_grade',
                $syncId,
                [
                    'teaching_assignment_id' => (int) $teachingAssignmentId,
                    'subject_id' => $ta->subject_id,
                    'homeroom_id' => $ta->homeroom_id,
                    'teacher_id' => $ta->teacher_id,
                    'homeroom_teacher_id' => $homeroomTeacherId !== null ? (int) $homeroomTeacherId : null,
                ],
                $sourceDeviceId
            );
        }

        $version = max($this->versionFromRowsUpdatedAt($rows), $this->weightVersion($teachingAssignmentId));

        foreach ($rows as &$row) {
            unset($row['id'], $row['created_at']);
        }
        unset($row);

        $payload = [
            'entity' => 'final_grade',
            'sync_id' => $syncId,
            'teaching_assignment_id' => (int) $teachingAssignmentId,
            'subject_id' => $ta->subject_id,
            'homeroom_id' => $ta->homeroom_id,
            'teacher_id' => $ta->teacher_id,
            'homeroom_teacher_id' => $homeroomTeacherId !== null ? (int) $homeroomTeacherId : null,
            'weights_version' => $this->weightVersion($teachingAssignmentId),
            'bobot_diagnostik' => (int) ($ta->bobot_diagnostik ?? 0),
            'bobot_formatif' => (int) ($ta->bobot_formatif ?? 0),
            'bobot_sumatif' => (int) ($ta->bobot_sumatif ?? 0),
            'grades' => $rows,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush(
            'final_grade',
            $syncId,
            'upsert',
            $version,
            $payload,
            null,
            null,
            $sourceDeviceId
        );
    }

    /* ============================================================
     * WORK PRODUCT BATCHES — [F1-BATCHVER] + [F1-TOMB]
     * ============================================================ */

    protected function stripSyncMetadata(array $rows): array
    {
        return array_map(static function ($row) {
            if (!is_array($row)) {
                return $row;
            }

            // sync_version adalah versi perubahan sumber, bukan metadata transport.

            return $row;
        }, $rows);
    }

    protected function versionFromRowsUpdatedAt(array $rows, ?string $scope = null): int
    {
        $version = 0;
        foreach ($rows as $row) {
            $version = max($version, (int) ($row['sync_version'] ?? 0));
            if (!empty($row['updated_at'])) {
                $version = max($version, $this->versionFromUpdatedAt($row['updated_at']));
            }
        }
        if ($scope !== null) {
            $canonical = $rows;
            foreach ($canonical as &$row) { ksort($row); }
            unset($row);
            usort($canonical, fn ($a, $b) => ((int) ($a['student_id'] ?? 0)) <=> ((int) ($b['student_id'] ?? 0)));
            $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return DB::transaction(function () use ($scope, $hash, $version): int {
                $key = 'sync::batch_version::' . $scope;
                DB::table('app_settings')->insertOrIgnore([
                    'key' => $key, 'value' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $stored = json_decode((string) DB::table('app_settings')->where('key', $key)
                    ->lockForUpdate()->value('value'), true) ?: [];
                $oldVersion = (int) ($stored['version'] ?? 0);
                $candidate = max($version,
                    (int) (DB::table('app_settings')->where('key', 'wpa::' . $scope)->value('value') ?? 0),
                    (int) (DB::table('app_settings')->where('key', 'grades::scope::' . $scope)->value('value') ?? 0));
                if (($stored['hash'] ?? '') === $hash) return max($oldVersion, $candidate);
                $next = $candidate > $oldVersion ? $candidate : VersionService::above($oldVersion);
                DB::table('app_settings')->where('key', $key)->update([
                    'value' => json_encode(['hash' => $hash, 'version' => $next]), 'updated_at' => now(),
                ]);
                return $next;
            }, 3);
        }
        return $version > 0 ? $version : $this->currentVersion();
    }

    /** [F1-TOMB + Footprint] Tombstone hanya dari laptop yang pernah memegang scope. */
    /**
     * [LATEST-STATE-V3] Snapshot yang benar-benar berubah harus selalu
     * mengalahkan versi lokal sebelumnya. Helper ini tidak bergantung pada
     * posisi helper versi lama di LmsSyncService.
     */
    protected function nextSnapshotVersion(string $entity, string $entityId, int $sourceFloor = 0): int
    {
        $lastLocalVersion = (int) (
            \App\Models\LmsSyncRecord::query()
                ->where('entity_type', $entity)
                ->where('entity_id', $entityId)
                ->max('version') ?? 0
        );

        return VersionService::above(max($lastLocalVersion, $sourceFloor));
    }
    public function queueTombstone(string $entity, string $syncId, array $scope, ?string $sourceDeviceId = null): array
    {
        if (!ScopeFootprint::has($syncId)) {
            Log::info('[LMS SYNC] Tombstone dilewati — laptop ini tidak pernah memegang scope.', [
                'entity' => $entity,
                'sync_id' => $syncId,
            ]);

            return [
                'success' => true,
                'status' => 'skipped',
                'message' => 'TOMBSTONE_SKIPPED_NO_FOOTPRINT',
            ];
        }

        $version = $this->currentVersion();

        $payload = array_merge($scope, [
            'sync_id' => $syncId,
            'entity' => $entity,
            'deleted' => true,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ]);

        $result = $this->queueAndPush($entity, $syncId, 'delete', $version, $payload, null, null, $sourceDeviceId);

        if (($result['success'] ?? false) === true) {
            ScopeFootprint::forget($syncId);
        }

        return $result;
    }

    /**
     * [F2 / KK-01] Tombstone PAKSA — untuk penghapusan SADAR oleh
     * pengguna (hapus kegiatan kokurikuler, destruktif-close exam):
     * TIDAK di-guard footprint, karena niatnya memang menyebarkan
     * penghapusan. Dipanggil SEBELUM baris lokal dihapus.
     */
    public function forceTombstone(string $entity, string $syncId, array $scope, ?string $sourceDeviceId = null): array
    {
        $version = $this->currentVersion();

        $payload = array_merge($scope, [
            'sync_id' => $syncId,
            'entity' => $entity,
            'deleted' => true,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ]);

        return $this->queueAndPush($entity, $syncId, 'delete', $version, $payload, null, null, $sourceDeviceId);
    }

    /**
     * Bootstrap/sinkronkan SELURUH riwayat pertemuan sebuah Teaching Assignment.
     *
     * Sumber kanonik laptop adalah gabungan assessment_scores + attendances.
     * Satu tanggal = satu attendance_session dengan entity id:
     *     ta::{teaching_assignment_id}::{YYYY-MM-DD}
     *
     * Method ini idempotent: queueAndPush + footprint/version mencegah event
     * identik menjadi duplikasi. Dipakai juga untuk membawa riwayat lama yang
     * sudah ada sebelum PWA Guru dipasangkan.
     */
    public function pushAttendanceHistoryForAssignment(
        int $teachingAssignmentId,
        ?string $sourceDeviceId = null
    ): array {
        $this->validateConfiguration();

        $ta = DB::table('teaching_assignments')
            ->where('id', $teachingAssignmentId)
            ->first();

        if (!$ta) {
            throw new \RuntimeException("Teaching assignment {$teachingAssignmentId} tidak ditemukan.");
        }

        $dates = DB::table('assessment_scores')
            ->where('teaching_assignment_id', $teachingAssignmentId)
            ->whereNotNull('tanggal')
            ->pluck('tanggal')
            ->merge(
                DB::table('attendances')
                    ->where('teaching_assignment_id', $teachingAssignmentId)
                    ->whereNotNull('tanggal')
                    ->pluck('tanggal')
            )
            ->map(fn ($v) => trim((string) $v))
            ->filter(function ($v) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                    return false;
                }

                try {
                    return Carbon::createFromFormat('Y-m-d', $v)->format('Y-m-d') === $v;
                } catch (Throwable) {
                    return false;
                }
            })
            ->unique()
            ->sort()
            ->values();

        $result = [
            'success' => true,
            'pushed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        /*
         * [LATEST-STATE-V3] Tanggal yang pernah kita kirim sebagai UPSERT,
         * tetapi sudah tidak ada lagi di database laptop, wajib dikirim sebagai
         * tombstone. Hanya state lokal TERAKHIR per entity yang dihitung agar
         * tombstone tidak dikirim berulang pada setiap flush.
         */
        $prefix = 'ta::' . $teachingAssignmentId . '::';
        $currentSyncIds = $dates
            ->map(fn ($tanggal) => $prefix . $tanggal)
            ->values();

        $knownStates = LmsSyncRecord::query()
            ->where('entity_type', 'attendance_session')
            ->where('entity_id', 'like', $prefix . '%')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get(['entity_id', 'operation', 'version']);

        $knownLiveIds = $knownStates
            ->unique('entity_id')
            ->filter(fn ($row) => strtolower((string) $row->operation) !== 'delete')
            ->pluck('entity_id')
            ->values();

        $missingSyncIds = $knownLiveIds->diff($currentSyncIds)->values();

        foreach ($missingSyncIds as $missingSyncId) {
            try {
                $tanggal = substr((string) $missingSyncId, strlen($prefix));

                $push = $this->forceTombstone(
                    'attendance_session',
                    (string) $missingSyncId,
                    [
                        'teaching_assignment_id' => $teachingAssignmentId,
                        'course_sync_id' => 'ta::' . $teachingAssignmentId,
                        'homeroom_id' => (int) $ta->homeroom_id,
                        'teacher_id' => (int) $ta->teacher_id,
                        'tanggal' => $tanggal,
                    ],
                    $sourceDeviceId
                );

                if (($push['status'] ?? '') === 'skipped') {
                    $result['skipped']++;
                } elseif (($push['success'] ?? false) === true) {
                    $result['pushed']++;
                } else {
                    $result['failed']++;
                    $result['details'][] = $missingSyncId . ': ' . ($push['error'] ?? $push['status'] ?? 'unknown');
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['details'][] = $missingSyncId . ': ' . $e->getMessage();
            }
        }

        if ($dates->isEmpty()) {
            if ($missingSyncIds->isEmpty()) {
                $result['skipped']++;
                $result['status'] = 'skipped';
            }
            $result['success'] = $result['failed'] === 0;
            return $result;
        }

        $roster = DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.homeroom_id', (int) $ta->homeroom_id)
            ->select(['students.id', 'students.nisn', 'students.nama'])
            ->orderBy('students.nama')
            ->get();

        $scoreColumns = Schema::getColumnListing('assessment_scores');
        $hasScoreVersion = in_array('sync_version', $scoreColumns, true);
        $hasAttendanceVersion = Schema::hasColumn('attendances', 'sync_version');

        foreach ($dates as $tanggal) {
            try {
                $scores = DB::table('assessment_scores')
                    ->where('teaching_assignment_id', $teachingAssignmentId)
                    ->where('tanggal', $tanggal)
                    ->get()
                    ->keyBy('student_id');

                $attendances = DB::table('attendances')
                    ->where('teaching_assignment_id', $teachingAssignmentId)
                    ->where('tanggal', $tanggal)
                    ->get()
                    ->keyBy('student_id');

                $students = [];
                $versionFloor = 1;
                $materi = null;
                $jamKe = null;
                $pukul = null;
                $latestUpdatedAt = null;

                foreach ($roster as $student) {
                    $studentId = (int) $student->id;
                    $score = $scores->get($studentId);
                    $attendance = $attendances->get($studentId);

                    if ($score) {
                        if ($materi === null && isset($score->materi) && trim((string) $score->materi) !== '') {
                            $materi = (string) $score->materi;
                        }
                        if ($jamKe === null && isset($score->jam_ke) && trim((string) $score->jam_ke) !== '') {
                            $jamKe = (string) $score->jam_ke;
                        }
                        if ($pukul === null && isset($score->pukul) && trim((string) $score->pukul) !== '') {
                            $pukul = (string) $score->pukul;
                        }
                        if ($hasScoreVersion && isset($score->sync_version)) {
                            $versionFloor = max($versionFloor, (int) $score->sync_version);
                        }
                        if (isset($score->updated_at) && ($latestUpdatedAt === null || (string) $score->updated_at > $latestUpdatedAt)) {
                            $latestUpdatedAt = (string) $score->updated_at;
                        }
                    }

                    if ($attendance) {
                        if ($hasAttendanceVersion && isset($attendance->sync_version)) {
                            $versionFloor = max($versionFloor, (int) $attendance->sync_version);
                        }
                        if (isset($attendance->updated_at) && ($latestUpdatedAt === null || (string) $attendance->updated_at > $latestUpdatedAt)) {
                            $latestUpdatedAt = (string) $attendance->updated_at;
                        }
                    }

                    $students[] = [
                        'student_id' => $studentId,
                        'nisn' => $student->nisn,
                        'nama' => $student->nama,
                        'status' => $attendance->status ?? 'Hadir',
                        'diagnostik' => $score->diagnostik ?? null,
                        'formatif' => $score->formatif ?? null,
                        'sumatif' => $score->sumatif ?? null,
                        'd1' => $score->d1 ?? null,
                        'd2' => $score->d2 ?? null,
                        'd3' => $score->d3 ?? null,
                        'd4' => $score->d4 ?? null,
                        'd5' => $score->d5 ?? null,
                        'd6' => $score->d6 ?? null,
                        'd7' => $score->d7 ?? null,
                        'd8' => $score->d8 ?? null,
                        'catatan_siswa' => $score->catatan_siswa ?? null,
                    ];
                }

                if ($versionFloor < self::EPOCH_FLOOR) {
                    if ($latestUpdatedAt) {
                        try {
                            $dt = new \DateTimeImmutable($latestUpdatedAt);
                            $versionFloor = ((int) $dt->format('U')) * 1000
                                + (int) floor(((int) $dt->format('u')) / 1000);
                        } catch (Throwable) {
                            $versionFloor = $this->currentVersion();
                        }
                    } else {
                        $versionFloor = $this->currentVersion();
                    }
                }

                $syncId = $prefix . $tanggal;
                $version = $this->nextSnapshotVersion('attendance_session', $syncId, $versionFloor);

                $payload = [
                    'sync_id' => $syncId,
                    'entity' => 'attendance_session',
                    'teaching_assignment_id' => $teachingAssignmentId,
                    'course_sync_id' => 'ta::' . $teachingAssignmentId,
                    'homeroom_id' => (int) $ta->homeroom_id,
                    'teacher_id' => (int) $ta->teacher_id,
                    'tanggal' => $tanggal,
                    'jam_ke' => $jamKe,
                    'pukul' => $pukul,
                    'materi' => $materi,
                    'students' => $students,
                    'sync_version' => $version,
                    'updated_at' => $latestUpdatedAt ?: now()->format('Y-m-d\TH:i:s.vP'),
                ];

                $push = $this->queueAndPush(
                    'attendance_session',
                    $syncId,
                    'upsert',
                    $version,
                    $payload,
                    null,
                    null,
                    $sourceDeviceId
                );

                if (($push['status'] ?? '') === 'skipped') {
                    $result['skipped']++;
                } elseif (($push['success'] ?? false) === true) {
                    $result['pushed']++;
                } else {
                    $result['failed']++;
                    $result['details'][] = $syncId . ': ' . ($push['error'] ?? $push['status'] ?? 'unknown');
                }
            } catch (Throwable $e) {
                $result['failed']++;
                $result['details'][] = 'TA ' . $teachingAssignmentId . ' / ' . $tanggal . ': ' . $e->getMessage();
            }
        }

        $result['success'] = $result['failed'] === 0;
        return $result;
    }

    /* [LATEST-STATE-V3] LmsSyncService attendance authoritative snapshot */
    public function pushDailyGradeBatchForAssignment(int $teachingAssignmentId, ?string $sourceDeviceId = null): array
    {
        $this->validateConfiguration();

        $ta = DB::table('teaching_assignments')
            ->where('id', $teachingAssignmentId)
            ->first();

        if (!$ta) {
            throw new \RuntimeException("Teaching assignment {$teachingAssignmentId} tidak ditemukan.");
        }

        $scores = $this->stripSyncMetadata(
            DB::table('assessment_scores')
                ->where('teaching_assignment_id', $teachingAssignmentId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->filter(function (array $row) {
                    $tanggal = trim((string) ($row['tanggal'] ?? ''));
                    if ($tanggal === '') {
                        return true;
                    }
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) !== 1) {
                        return false;
                    }
                    try {
                        return Carbon::createFromFormat('Y-m-d', $tanggal)->format('Y-m-d') === $tanggal;
                    } catch (Throwable) {
                        return false;
                    }
                })
                ->values()
                ->all()
        );

        $attitudes = $this->stripSyncMetadata(
            DB::table('attitude_logs')
                ->where('teaching_assignment_id', $teachingAssignmentId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        $syncId = 'daily_grade::ta::' . $teachingAssignmentId;

        if ($scores === [] && $attitudes === []) {
            return $this->queueTombstone(
                'daily_grade',
                $syncId,
                [
                    'teaching_assignment_id' => (int) $teachingAssignmentId,
                    'subject_id' => $ta->subject_id,
                    'homeroom_id' => $ta->homeroom_id,
                    'teacher_id' => $ta->teacher_id,
                ],
                $sourceDeviceId
            );
        }

        $sourceVersion = $this->versionFromRowsUpdatedAt(array_merge($scores, $attitudes));
        $version = $this->nextSnapshotVersion('daily_grade', $syncId, $sourceVersion);

        $payload = [
            'entity' => 'daily_grade',
            'sync_id' => $syncId,
            'teaching_assignment_id' => (int) $teachingAssignmentId,
            'subject_id' => $ta->subject_id,
            'homeroom_id' => $ta->homeroom_id,
            'teacher_id' => $ta->teacher_id,
            'assessment_scores' => $scores,
            'attitude_logs' => $attitudes,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush(
            'daily_grade',
            $syncId,
            'upsert',
            $version,
            $payload,
            null,
            null,
            $sourceDeviceId
        );
    }
    public function pushBkLogBatchForTeacher(int $teacherId, ?string $sourceDeviceId = null): array
    {
        $this->validateConfiguration();

        $logs = $this->stripSyncMetadata(
            DB::table('bk_logs')
                ->where('teacher_id', $teacherId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        $syncId = 'bk_batch::teacher::' . $teacherId;

        if ($logs === []) {
            return $this->queueTombstone(
                'bk_log_batch',
                $syncId,
                ['teacher_id' => $teacherId],
                $sourceDeviceId
            );
        }

        $version = $this->versionFromRowsUpdatedAt($logs);

        foreach ($logs as &$log) {
            unset($log['id'], $log['created_at']);
        }
        unset($log);

        $payload = [
            'entity' => 'bk_log_batch',
            'sync_id' => $syncId,
            'teacher_id' => $teacherId,
            'bk_logs' => $logs,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush('bk_log_batch', $syncId, 'upsert', $version, $payload, null, null, $sourceDeviceId);
    }

    /**
     * [WALI-SCOPE-V1] Hanya PC wali kelas yang berhak mengirim produk
     * catatan wali/rapor. Identitas memakai NIP/NIK/NUPTK kanonik; tidak
     * mengasumsikan teachers.user_id (kolom tersebut tidak selalu ada).
     *
     * null = master wali atau identitas tidak tersedia (fail closed).
     */
    protected function isHomeroomDeviceOwner(int $homeroomId, string $deviceId): ?bool
    {
        if ($homeroomId <= 0 || trim($deviceId) === '') {
            return null;
        }

        $ownerTeacherId = (int) (DB::table('homerooms')
            ->where('id', $homeroomId)
            ->value('homeroom_teacher_id') ?? 0);
        if ($ownerTeacherId <= 0) {
            return null;
        }

        $owner = DB::table('teachers')->where('id', $ownerTeacherId)
            ->first(['nip', 'nik', 'nuptk']);
        if (!$owner) {
            return null;
        }

        $schoolIdentity = app(SchoolIdentity::class);
        $possibleDeviceIds = [];
        foreach (['nip', 'nik', 'nuptk'] as $key) {
            $loginId = trim((string) ($owner->{$key} ?? ''));
            if ($loginId !== '') {
                $possibleDeviceIds[] = $schoolIdentity->teacherPcDeviceId($loginId);
            }
        }

        if ($possibleDeviceIds === []) {
            return null;
        }

        return in_array(trim($deviceId), $possibleDeviceIds, true);
    }

    protected function assertHomeroomSender(int $homeroomId, ?string $sourceDeviceId): void
    {
        $deviceId = trim((string) ($sourceDeviceId ?: $this->getDeviceId()));
        $owns = $this->isHomeroomDeviceOwner($homeroomId, $deviceId);
        if ($owns !== true) {
            throw new \RuntimeException(
                'HOMEROOM_SCOPE_MISMATCH: Perangkat ' . $deviceId
                . ' bukan PC wali kelas rombel #' . $homeroomId
                . '; snapshot lokal tetap tersimpan dan tidak diantrikan.'
            );
        }
    }

    protected function supersedeWrongOwnerHomeroomRecord(LmsSyncRecord $record): void
    {
        // SQLite enum lms_sync_records.status hanya menerima
        // pending/processing/synced/failed. Jangan gunakan 'superseded'.
        // 'failed' + kode terminal adalah arsip audit lokal, BUKAN ACK Cloud.
        // retryPending() mengecualikan kode terminal ini dari pengiriman ulang.
        $record->forceFill([
            'status' => 'failed',
            'last_error' => 'LOCAL_HOMEROOM_SCOPE_MISMATCH: asal perangkat bukan wali kelas; tidak dikirim ke Cloud.',
        ])->save();

        Log::warning('[WALI-SCOPE] Outbox lama salah pemilik diarsipkan, data rapor tidak dihapus.', [
            'record_id' => $record->id,
            'entity_type' => $record->entity_type,
            'node_id' => $record->node_id,
            'entity_id' => $record->entity_id,
        ]);
    }

    public function pushCatatanWaliBatchForHomeroom(int $homeroomId, ?string $sourceDeviceId = null): array
    {
        // Cegah enqueue maupun push menggunakan device guru lain.
        $this->assertHomeroomSender($homeroomId, $sourceDeviceId);
        $this->validateConfiguration();

        $rows = $this->stripSyncMetadata(
            DB::table('enrollments')
                ->where('homeroom_id', $homeroomId)
                ->select('id as enrollment_id', 'student_id', 'catatan_wali', 'status_akhir_semester', 'updated_at')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        if ($rows === []) {
            return ['success' => true, 'status' => 'skipped', 'message' => 'Tidak ada enrollment.'];
        }

        $version = $this->versionFromRowsUpdatedAt($rows, 'catatan_wali::homeroom::' . $homeroomId);

        foreach ($rows as &$row) {
            // Pertahankan waktu sumber untuk mencegah versi naik saat data diteruskan.
        }
        unset($row);

        $payload = [
            'entity' => 'catatan_wali_batch',
            'sync_id' => 'catatan_wali::homeroom::' . $homeroomId,
            'homeroom_id' => $homeroomId,
            'rows' => $rows,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush('catatan_wali_batch', $payload['sync_id'], 'upsert', $version, $payload, null, null, $sourceDeviceId);
    }

    public function pushRaporRecapBatchForHomeroom(int $homeroomId, ?string $sourceDeviceId = null): array
    {
        // Cegah enqueue maupun push menggunakan device guru lain.
        $this->assertHomeroomSender($homeroomId, $sourceDeviceId);
        $this->validateConfiguration();

        $rows = $this->stripSyncMetadata(
            DB::table('enrollments')
                ->where('homeroom_id', $homeroomId)
                ->select('id as enrollment_id', 'student_id', 'rapor_sakit', 'rapor_izin', 'rapor_alpa', 'updated_at')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        if ($rows === []) {
            return ['success' => true, 'status' => 'skipped', 'message' => 'Tidak ada enrollment.'];
        }

        $version = $this->versionFromRowsUpdatedAt($rows, 'rapor_recap::homeroom::' . $homeroomId);

        foreach ($rows as &$row) {
            // Pertahankan waktu sumber untuk mencegah versi naik saat data diteruskan.
        }
        unset($row);

        $payload = [
            'entity' => 'rapor_recap_batch',
            'sync_id' => 'rapor_recap::homeroom::' . $homeroomId,
            'homeroom_id' => $homeroomId,
            'rows' => $rows,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush('rapor_recap_batch', $payload['sync_id'], 'upsert', $version, $payload, null, null, $sourceDeviceId);
    }

    public function pushEkskulGradeBatchForHomeroom(int $ekskulHomeroomId, ?string $sourceDeviceId = null): array
    {
        $this->validateConfiguration();

        $rows = $this->stripSyncMetadata(
            DB::table('ekskul_grades')
                ->where('homeroom_id', $ekskulHomeroomId)
                ->select('student_id', 'bintang', 'predikat', 'deskripsi', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8', 'updated_at')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        $syncId = 'ekskul_grade::homeroom::' . $ekskulHomeroomId;

        if ($rows === []) {
            return $this->queueTombstone(
                'ekskul_grade_batch',
                $syncId,
                ['ekskul_homeroom_id' => $ekskulHomeroomId],
                $sourceDeviceId
            );
        }

        $version = $this->versionFromRowsUpdatedAt($rows);

        foreach ($rows as &$row) {
            // Pertahankan waktu sumber untuk mencegah versi naik saat data diteruskan.
        }
        unset($row);

        $payload = [
            'entity' => 'ekskul_grade_batch',
            'sync_id' => $syncId,
            'ekskul_homeroom_id' => $ekskulHomeroomId,
            'rows' => $rows,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush('ekskul_grade_batch', $syncId, 'upsert', $version, $payload, null, null, $sourceDeviceId);
    }

    public function pushKokurikulerGradeBatch(int $kokurikulerId, ?string $sourceDeviceId = null): array
    {
        $this->validateConfiguration();

        $rows = $this->stripSyncMetadata(
            DB::table('kokurikuler_grades')
                ->where('kokurikuler_id', $kokurikulerId)
                ->select('student_id', 'predikat', 'deskripsi', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8', 'updated_at')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all()
        );

        $syncId = 'kokurikuler_grade::' . $kokurikulerId;

        if ($rows === []) {
            return $this->queueTombstone(
                'kokurikuler_grade_batch',
                $syncId,
                ['kokurikuler_id' => $kokurikulerId],
                $sourceDeviceId
            );
        }

        $version = $this->versionFromRowsUpdatedAt($rows);

        foreach ($rows as &$row) {
            // Pertahankan waktu sumber untuk mencegah versi naik saat data diteruskan.
        }
        unset($row);

        $payload = [
            'entity' => 'kokurikuler_grade_batch',
            'sync_id' => $syncId,
            'kokurikuler_id' => $kokurikulerId,
            'rows' => $rows,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        return $this->queueAndPush('kokurikuler_grade_batch', $syncId, 'upsert', $version, $payload, null, null, $sourceDeviceId);
    }

    public function pushAllWorkProductsForTeacher(int $teacherId, ?string $sourceDeviceId = null): array
    {
        $results = [
            'pushed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $applyResult = function (array $result, string $label) use (&$results): void {
            if (($result['status'] ?? '') === 'skipped') {
                $results['skipped']++;
            } elseif (($result['success'] ?? false) === true) {
                $results['pushed']++;
            } else {
                $results['failed']++;
                $results['details'][] = $label . ': '
                    . ($result['error'] ?? $result['status'] ?? 'unknown');
            }
        };

        $taIds = DB::table('teaching_assignments')
            ->where('teacher_id', $teacherId)
            ->pluck('id');

        foreach ($taIds as $taId) {
            try {
                $history = $this->pushAttendanceHistoryForAssignment((int) $taId, $sourceDeviceId);
                $results['pushed'] += (int) ($history['pushed'] ?? 0);
                $results['skipped'] += (int) ($history['skipped'] ?? 0);
                $results['failed'] += (int) ($history['failed'] ?? 0);
                foreach (($history['details'] ?? []) as $detail) {
                    $results['details'][] = 'HISTORY ' . $taId . ': ' . $detail;
                }
            } catch (Throwable $e) {
                $results['failed']++;
                $results['details'][] = 'HISTORY ' . $taId . ': ' . $e->getMessage();
            }

            try {
                $applyResult(
                    $this->pushDailyGradeBatchForAssignment((int) $taId, $sourceDeviceId),
                    'TA ' . $taId
                );
            } catch (Throwable $e) {
                $results['failed']++;
                $results['details'][] = 'TA ' . $taId . ': ' . $e->getMessage();
            }

            try {
                $applyResult(
                    $this->pushFinalGradeBatchForAssignment((int) $taId, $sourceDeviceId),
                    'FINAL ' . $taId
                );
            } catch (Throwable $e) {
                $results['failed']++;
                $results['details'][] = 'FINAL ' . $taId . ': ' . $e->getMessage();
            }
        }

        try {
            $applyResult($this->pushBkLogBatchForTeacher($teacherId, $sourceDeviceId), 'BK');
        } catch (Throwable $e) {
            $results['failed']++;
            $results['details'][] = 'BK: ' . $e->getMessage();
        }

        $waliHomeroomIds = DB::table('homerooms')
            ->where('homeroom_teacher_id', $teacherId)
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->pluck('id');

        foreach ($waliHomeroomIds as $homeroomId) {
            foreach (['pushCatatanWaliBatchForHomeroom', 'pushRaporRecapBatchForHomeroom'] as $method) {
                try {
                    $applyResult(
                        $this->{$method}((int) $homeroomId, $sourceDeviceId),
                        $method . ' #' . $homeroomId
                    );
                } catch (Throwable $e) {
                    $results['failed']++;
                    $results['details'][] = $method . ' #' . $homeroomId . ': ' . $e->getMessage();
                }
            }
        }

        $ekskulHomeroomIds = DB::table('homerooms')
            ->where('homeroom_teacher_id', $teacherId)
            ->where('jenis_rombel', 'like', '%Ekstrakurikuler%')
            ->pluck('id');

        foreach ($ekskulHomeroomIds as $ekskulId) {
            try {
                $applyResult(
                    $this->pushEkskulGradeBatchForHomeroom((int) $ekskulId, $sourceDeviceId),
                    'Ekskul #' . $ekskulId
                );
            } catch (Throwable $e) {
                $results['failed']++;
                $results['details'][] = 'Ekskul #' . $ekskulId . ': ' . $e->getMessage();
            }
        }

        $kokurikulerIds = DB::table('kokurikulers')
            ->where('koordinator_id', $teacherId)
            ->pluck('id');

        foreach ($kokurikulerIds as $kokurikulerId) {
            try {
                $applyResult(
                    $this->pushKokurikulerGradeBatch((int) $kokurikulerId, $sourceDeviceId),
                    'Kokurikuler #' . $kokurikulerId
                );
            } catch (Throwable $e) {
                $results['failed']++;
                $results['details'][] = 'Kokurikuler #' . $kokurikulerId . ': ' . $e->getMessage();
            }
        }

        return $results;
    }

    /* ============================================================
     * QUEUE + PUSH — [F1-OUTBOX] record dulu, config kemudian
     * ============================================================ */

    public function queueAndPush(
        string $entity,
        string $entityId,
        string $action,
        int $version,
        array $payload,
        ?string $targetDeviceId = null,
        ?int $targetHomeroomId = null,
        ?string $sourceDeviceId = null
    ): array {
        $action = $this->normalizeClientAction($action);
        $this->validateAction($action);

        // V21R27: strip real NISN from LMS rosters in the Cloud copy only.
        if (OpaqueRouteRegistryV21::enabled() && CloudLmsRosterAliasV21::supports($entity)
            && $action !== 'delete') {
            $payload = app(CloudLmsRosterAliasV21::class)->outbound($entity, $payload);
        }

        // V21R9: project only the Cloud COPY, not grade tables/row identities.
        // Unsupported work-products and delete tombstones still fail closed.
        if (OpaqueRouteRegistryV21::enabled() && CloudWorkProductAliasV21::supports($entity)
            && $action !== 'delete') {
            if (!$sourceDeviceId) {
                $ownerId = (int) (DB::table('teaching_assignments')
                    ->where('id', (int) ($payload['teaching_assignment_id'] ?? 0))
                    ->value('teacher_id') ?? 0);
                $sourceDeviceId = $ownerId > 0 ? $this->resolveTeacherPcDeviceId($ownerId) : null;
            }
            $payload = app(CloudWorkProductAliasV21::class)->outbound($entity, $payload, $sourceDeviceId);
        }
        // V21R10: rekap kehadiran rapor anonim hanya dari PC wali kelas,
        // proyeksi salinan Cloud sebelum enqueue; catatan wali tetap blocked.
        if (OpaqueRouteRegistryV21::enabled() && CloudHomeroomRaporAliasV21::supports($entity)) {
            if ($action === 'delete') {
                throw new \RuntimeException('BLOCKED V21: tombstone rekap rapor belum terotorisasi.');
            }
            if (!$sourceDeviceId) {
                $waliId = (int) (DB::table('homerooms')
                    ->where('id', (int) ($payload['homeroom_id'] ?? 0))
                    ->value('homeroom_teacher_id') ?? 0);
                $sourceDeviceId = $waliId > 0 ? $this->resolveTeacherPcDeviceId($waliId) : null;
            }
            $payload = app(CloudHomeroomRaporAliasV21::class)->outbound($payload, trim((string) $sourceDeviceId));
        }
        CloudAssessmentBoundaryV21::outgoing($entity, $payload);

        if (SyncEventClassifier::category($entity) === SyncEventClassifier::UNKNOWN) {
            throw new \InvalidArgumentException("Entity sync tidak dikenal: {$entity}");
        }

        try {
            $this->validateConfiguration();
            $configOk = true;
            $configError = null;
        } catch (Throwable $e) {
            $configOk = false;
            $configError = $e->getMessage();
        }

        if ($action === 'delete' && $version < self::EPOCH_FLOOR) {
            $version = $this->currentVersion();
        }

        if ($action === 'delete') {
            $this->supersedePendingForDelete($entity, $entityId);
        }

        if ($entity === 'attendance_session') {
            if ($action !== 'delete') {
                $payload['tanggal'] = $this->assertWritableAttendanceDate($payload['tanggal'] ?? null);
            }
            $payload = $this->normalizeAttendancePayload($payload, $version);
            $version = (int) $payload['sync_version'];
            $payload['entity'] = 'attendance_session';

            if (!$entityId) {
                $entityId = $this->buildAttendanceEntityId($payload);
            }
        }

        $mode = $this->resolveRouting($entity, $targetDeviceId, $targetHomeroomId);

        if ($mode === 'broadcast') {
            if (!$targetHomeroomId) {
                $targetHomeroomId = $this->resolveRecordHomeroomId($entity, $payload);
            }

            if (!$targetHomeroomId) {
                throw new \RuntimeException("target_homeroom_id wajib untuk {$entity}.");
            }

            $targetDeviceId = null;
            $payload['target_homeroom_id'] = $targetHomeroomId;
            unset($payload['target_device_id']);
        } elseif ($mode === 'teacher_routed') {
            $targetDeviceId = null;
            $targetHomeroomId = null;
            $payload['entity'] = $entity;
            unset($payload['target_device_id'], $payload['target_homeroom_id']);
        } else {
            if (!$targetDeviceId) {
                $targetDeviceId = $payload['target_device_id'] ?? null;
            }

            if (!$targetDeviceId) {
                throw new \RuntimeException(
                    "target_device_id wajib untuk entity private {$entity}."
                );
            }

            $targetDeviceId = (string) $targetDeviceId;
            $targetHomeroomId = null;
            $payload['target_device_id'] = $targetDeviceId;
            unset($payload['target_homeroom_id']);
        }

        /*
         * [F1 / Footprint] Batch upsert teacher-routed = bukti laptop
         * ini memegang data scope → izinkan tombstone di masa depan.
         */
        if (
            $action !== 'delete'
            && $this->isTeacherRoutedEntity($entity)
            && in_array($entity, [
                'daily_grade', 'final_grade', 'bk_log_batch',
                'ekskul_grade_batch', 'kokurikuler_grade_batch',
            ], true)
        ) {
            ScopeFootprint::mark($entityId);
        }

        $record = $this->createSyncRecord(
            $entity,
            $entityId,
            $action,
            $version,
            $payload,
            $targetDeviceId,
            $targetHomeroomId,
            $sourceDeviceId
        );

        if (!$configOk) {
            $this->markRecordRetryable($record, 'CLOUD_NOT_CONFIGURED: ' . $configError);

            return [
                'success' => false,
                'status' => 'pending',
                'sync_id' => $record->sync_id,
                'event_id' => $record->sync_id,
                'entity' => $entity,
                'entity_id' => $entityId,
                'target_device_id' => $targetDeviceId,
                'target_homeroom_id' => $targetHomeroomId,
                'teacher_routed' => $mode === 'teacher_routed',
                'error' => 'CLOUD_NOT_CONFIGURED: ' . $configError,
            ];
        }

        try {
            $result = $this->pushChange(
                $entity,
                $entityId,
                $action,
                $version,
                $payload,
                $targetDeviceId,
                $targetHomeroomId,
                $record->sync_id,
                $sourceDeviceId
            );

            return $this->finishPush($record, $entity, $entityId, $result, $mode, $targetDeviceId, $targetHomeroomId);
        } catch (Throwable $e) {
            $message = $e->getMessage();

            $this->markRecordRetryable($record, $message);

            Log::warning('[LMS SYNC] Push gagal — masuk antrean ulang.', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'sync_id' => $record->sync_id,
                'mode' => $mode,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'status' => 'pending',
                'sync_id' => $record->sync_id,
                'event_id' => $record->sync_id,
                'entity' => $entity,
                'entity_id' => $entityId,
                'target_device_id' => $targetDeviceId,
                'target_homeroom_id' => $targetHomeroomId,
                'teacher_routed' => $mode === 'teacher_routed',
                'error' => $message,
            ];
        }
    }

    /** [F1-REJECT] Klasifikasi respons push (choke point tunggal). */
    protected function finishPush(
        LmsSyncRecord $record,
        string $entity,
        string $entityId,
        array $result,
        string $mode,
        ?string $targetDeviceId,
        ?int $targetHomeroomId
    ): array {
        $status = $this->extractPushStatus($result);
        $ok = in_array($status, ['accepted', 'duplicate', 'success'], true);

        if ($ok) {
            $this->markRecordSynced($record);
            $this->markLocalModelSyncedIfFullySynced($entity, $entityId);

            return [
                'success' => true,
                'status' => $status,
                'sync_id' => $record->sync_id,
                'event_id' => $record->sync_id,
                'entity' => $entity,
                'entity_id' => $entityId,
                'target_device_id' => $targetDeviceId,
                'target_homeroom_id' => $targetHomeroomId,
                'teacher_routed' => $mode === 'teacher_routed',
                'result' => $result,
            ];
        }

        $errorCode = strtoupper((string) $this->extractPushErrorCode($result));

        if (in_array($errorCode, self::BENIGN_REJECT_CODES, true)) {
            $this->markRecordSynced($record);

            return [
                'success' => true,
                'status' => 'benign_rejected',
                'sync_id' => $record->sync_id,
                'event_id' => $record->sync_id,
                'entity' => $entity,
                'entity_id' => $entityId,
                'target_device_id' => $targetDeviceId,
                'target_homeroom_id' => $targetHomeroomId,
                'teacher_routed' => $mode === 'teacher_routed',
                'error' => $errorCode,
            ];
        }

        if (in_array($errorCode, self::FINAL_REJECT_CODES, true)) {
            $this->markRecordDead($record, 'FINAL_REJECT: ' . $errorCode);
        } else {
            $this->markRecordRetryable($record, $this->extractPushError($result));
        }

        return [
            'success' => false,
            'status' => $status,
            'sync_id' => $record->sync_id,
            'event_id' => $record->sync_id,
            'entity' => $entity,
            'entity_id' => $entityId,
            'target_device_id' => $targetDeviceId,
            'target_homeroom_id' => $targetHomeroomId,
            'teacher_routed' => $mode === 'teacher_routed',
            'result' => $result,
        ];
    }

    protected function supersedePendingForDelete(string $entity, string $entityId): void
    {
        try {
            LmsSyncRecord::query()
                ->where('entity_type', $entity)
                ->where('entity_id', $entityId)
                ->whereIn('operation', ['create', 'update'])
                ->whereIn('status', ['pending', 'failed'])
                ->update([
                    // Status arsip terminal harus lolos CHECK constraint SQLite.
                    'status' => 'failed',
                    'last_error' => 'SUPERSEDED_BY_DELETE',
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('[LMS SYNC] Gagal supersede record saat delete.', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ============================================================
     * DELETE (generic) — [D13] batal bila push gagal pra-antrean
     * ============================================================ */

    protected function queueEntityDelete(
        string $entity,
        string $syncId,
        int $version = 1
    ): array {
        $payload = [
            'sync_id' => $syncId,
            'entity' => $entity,
            'sync_version' => $version,
            'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ];

        $homeroomId = null;

        if ($entity === 'lms_material') {
            $material = LmsMaterial::query()->with('topic.course')->where('sync_id', $syncId)->first();

            if ($material?->topic) {
                $homeroomId = $this->resolveTopicHomeroomId($material->topic);
            }

            $payload['topic_id'] = $material?->topic_id;
            $payload['topic_sync_id'] = $material?->topic?->sync_id;
        }

        if ($entity === 'lms_assignment') {
            $assignment = LmsAssignment::query()->with('topic.course')->where('sync_id', $syncId)->first();

            if ($assignment?->topic) {
                try {
                    $homeroomId = $this->resolveTopicHomeroomId($assignment->topic);
                } catch (Throwable) {
                    $homeroomId = null;
                }
            }

            $payload['topic_id'] = $assignment?->topic_id;
            $payload['topic_sync_id'] = $assignment?->topic?->sync_id;

            /*
             * Aktivitas lama dapat kehilangan relasi topic/course sebelum paket
             * ujian dihapus. Gunakan scope broadcast historis dari outbox agar
             * tombstone tetap menuju rombel yang pernah menerima aktivitas.
             */
            if (!$homeroomId) {
                $historical = $this->historicalBroadcastHomeroomIds('lms_assignment', $syncId);
                $homeroomId = (int) ($historical[0] ?? 0);
            }

            /* Assignment tanpa scope sekarang maupun historis tidak pernah
             * broadcast; delete remote tidak diperlukan. */
            if (!$homeroomId) {
                Log::info('[CBT-DELETE-V26] Tombstone assignment dilewati — aktivitas belum pernah broadcast.', [
                    'sync_id' => $syncId,
                    'assignment_id' => $assignment?->id,
                ]);

                return [
                    'success' => true,
                    'status' => 'skipped',
                    'queued' => false,
                    'reason' => 'ASSIGNMENT_DELETE_LOCAL_ONLY_NO_BROADCAST_SCOPE',
                    'entity' => 'lms_assignment',
                    'entity_id' => $syncId,
                ];
            }
        }

        if (!$homeroomId) {
            throw new \RuntimeException(
                "Homeroom entity {$entity} tidak dapat ditentukan saat delete."
            );
        }

        $payload['homeroom_id'] = $homeroomId;
        $payload['target_homeroom_id'] = $homeroomId;

        return $this->queueAndPush($entity, $syncId, 'delete', $version, $payload, null, $homeroomId);
    }

    /* ============================================================
     * CLIENT CHANGES (HP guru → Cloud)
     * ============================================================ */

    public function pushClientChanges(string $deviceId, array $changes): array
    {
        $this->validateConfiguration();

        $pushed = 0;
        $failed = 0;
        $rejected = 0;
        $queued = 0;

        $eventIds = [];
        $results = [];

        foreach ($changes as $change) {
            $body = null;

            try {
                $body = $this->buildClientChangeBody($deviceId, $change);
                $eventId = $body['event_id'];
                $result = $this->pushRaw($body);
                $status = $this->extractPushStatus($result);

                $eventIds[] = $eventId;

                $ok = in_array($status, ['accepted', 'duplicate', 'success'], true);

                $results[] = [
                    'event_id' => $eventId,
                    'entity' => $body['entity_type'],
                    'entity_id' => $body['entity_id'],
                    'status' => $status,
                    'result' => $result,
                ];

                if ($ok) {
                    $pushed++;
                } elseif (in_array($status, ['rejected', 'invalid'], true)) {
                    $errorCode = strtoupper((string) $this->extractPushErrorCode($result));

                    if (in_array($errorCode, self::BENIGN_REJECT_CODES, true)) {
                        $pushed++;
                        $results[count($results) - 1]['status'] = 'benign_rejected';
                    } else {
                        $rejected++;
                    }
                } else {
                    $failed++;
                    $this->queueRawClientChange($deviceId, $body, $this->extractPushError($result));
                    $queued++;
                }
            } catch (Throwable $e) {
                $failed++;

                $results[] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                if (isset($body) && is_array($body)) {
                    $this->queueRawClientChange($deviceId, $body, $e->getMessage());
                    $queued++;
                }
            }
        }

        return [
            'success' => $failed === 0 && $rejected === 0,
            'source_device_id' => $deviceId,
            'total' => count($changes),
            'pushed' => $pushed,
            'failed' => $failed,
            'rejected' => $rejected,
            'queued_for_retry' => $queued,
            'event_ids' => $eventIds,
            'results' => $results,
        ];
    }

    protected function queueRawClientChange(string $deviceId, array $body, ?string $error): void
    {
        try {
            LmsSyncRecord::create([
                'sync_id' => (string) Str::uuid(),
                'node_id' => $deviceId,
                'node_type' => $this->nodeTypeFor($deviceId),
                'entity_type' => (string) $body['entity_type'],
                'entity_id' => (string) $body['entity_id'],
                'operation' => (string) $body['action'],
                'version' => (int) $body['version'],
                'payload' => [
                    '__outbox' => 'raw_push',
                    'body' => $body,
                ],
                'target_device_id' => $body['target_device_id'] ?? null,
                'target_homeroom_id' => $body['target_homeroom_id'] ?? null,
                'status' => 'pending',
                'last_error' => $error,
            ]);
        } catch (QueryException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
        } catch (Throwable $e) {
            Log::warning('[LMS SYNC] Gagal mengantrekan client change untuk retry.', [
                'source_device_id' => $deviceId,
                'event_id' => $body['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function buildClientChangeBody(string $deviceId, array $change): array
    {
        $entity = (string) (
            $change['entity_type']
            ?? $change['entity']
            ?? ''
        );

        if (!$entity) {
            throw new \InvalidArgumentException('entity_type client change kosong.');
        }

        if (SyncEventClassifier::category($entity) === SyncEventClassifier::UNKNOWN) {
            throw new \InvalidArgumentException("Entity sync tidak dikenal: {$entity}");
        }

        $action = $this->normalizeClientAction(
            (string) (
                $change['action']
                ?? $change['operation']
                ?? 'update'
            )
        );

        $this->validateAction($action);

        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        $entityId = (string) (
            $change['entity_id']
            ?? $payload['entity_id']
            ?? $payload['sync_id']
            ?? ''
        );

        if ($entity === 'attendance_session') {
            if (!$entityId) {
                $entityId = $this->buildAttendanceEntityId($payload);
            }

            $payload = $this->normalizeAttendancePayload(
                $payload,
                !empty($change['version']) ? (int) $change['version'] : null
            );
        }

        $eventId = $this->ensureEventId(
            $change['event_id'] ?? $payload['event_id'] ?? null,
            $payload['sync_id'] ?? ($entityId !== '' ? $entityId : null)
        );

        $version = !empty($change['version']) ? (int) $change['version'] : 0;

        if ($version <= 0) {
            $version = !empty($payload['sync_version']) ? (int) $payload['sync_version'] : 0;
        }

        if ($version <= 0) {
            $version = !empty($payload['updated_at'])
                ? $this->versionFromUpdatedAt($payload['updated_at'])
                : $this->currentVersion();
        }

        if ($entity === 'attendance_session') {
            $payload['sync_version'] = $version;

            if (empty($payload['updated_at'])) {
                $payload['updated_at'] = now()->format('Y-m-d\TH:i:s.vP');
            }
        }

        $targetDeviceId = $change['target_device_id'] ?? $payload['target_device_id'] ?? null;
        $targetHomeroomId = $change['target_homeroom_id'] ?? $payload['target_homeroom_id'] ?? $payload['homeroom_id'] ?? null;

        if ($entity === 'attendance_session' && !$targetDeviceId) {
            $targetDeviceId = $this->deriveLaptopDeviceId($deviceId);
        }

        if ($entity === 'lms_assignment_submission' && !$targetDeviceId) {
            $studentNisn = $payload['student_nisn'] ?? $payload['nisn'] ?? null;

            if ($studentNisn) {
                $targetDeviceId = app(SchoolIdentity::class)->studentDeviceId((string) $studentNisn);
            }
        }

        $mode = $this->resolveRouting($entity, $targetDeviceId, $targetHomeroomId !== null ? (int) $targetHomeroomId : null);

        if ($mode === 'broadcast') {
            $targetDeviceId = null;

            if (!$targetHomeroomId) {
                $targetHomeroomId = $this->resolveRecordHomeroomId($entity, $payload);
            }

            if (!$targetHomeroomId) {
                throw new \RuntimeException("target_homeroom_id wajib untuk broadcast {$entity}.");
            }

            $payload['target_homeroom_id'] = (int) $targetHomeroomId;
            unset($payload['target_device_id']);
        } elseif ($mode === 'teacher_routed') {
            $targetDeviceId = null;
            $targetHomeroomId = null;
            unset($payload['target_device_id'], $payload['target_homeroom_id']);
        } else {
            if ($targetDeviceId && $targetHomeroomId) {
                $targetHomeroomId = null;
            }

            if (!$targetDeviceId) {
                throw new \RuntimeException("Target device tidak ditemukan untuk {$entity}.");
            }

            $targetDeviceId = (string) $targetDeviceId;
            $targetHomeroomId = null;
            unset($payload['target_homeroom_id']);
            $payload['target_device_id'] = $targetDeviceId;
        }

        $payload['entity'] = $entity;

        if ($entityId) {
            $payload['entity_id'] = $entityId;
        }

        $payload['sync_version'] = $version;

        return [
            'event_id' => $eventId,
            'school_id' => $this->getSchoolId(),
            'source_device_id' => $deviceId,
            'target_device_id' => $targetDeviceId,
            'target_homeroom_id' => $targetHomeroomId ? (int) $targetHomeroomId : null,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'version' => $version,
            'payload' => $payload,
        ];
    }

    protected function deriveLaptopDeviceId(string $sourceDeviceId): ?string
    {
        $suffix = '-HP';

        if (!str_ends_with($sourceDeviceId, $suffix)) {
            return null;
        }

        return substr($sourceDeviceId, 0, -strlen($suffix)) . '-PC';
    }

    /* ============================================================
     * RAW PUSH / PUSH CHANGE
     * ============================================================ */

    protected function pushRaw(array $body): array
    {
        return $this->client->push($body);
    }

    protected function pushChange(
        string $entity,
        string $entityId,
        string $action,
        int $version,
        array $payload,
        ?string $targetDeviceId = null,
        ?int $targetHomeroomId = null,
        ?string $eventId = null,
        ?string $sourceDeviceId = null
    ): array {
        $this->validateConfiguration();

        $action = $this->normalizeClientAction($action);
        $this->validateAction($action);

        $mode = $this->resolveRouting($entity, $targetDeviceId, $targetHomeroomId);

        if ($mode === 'broadcast') {
            $targetDeviceId = null;

            if (!$targetHomeroomId) {
                $targetHomeroomId = $this->resolveRecordHomeroomId($entity, $payload);
            }

            if (!$targetHomeroomId) {
                throw new \RuntimeException("Broadcast {$entity} membutuhkan target_homeroom_id.");
            }
        } elseif ($mode === 'teacher_routed') {
            $targetDeviceId = null;
            $targetHomeroomId = null;
        } else {
            if ($targetDeviceId && $targetHomeroomId) {
                throw new \InvalidArgumentException('Private event tidak boleh memiliki dua target.');
            }

            if (!$targetDeviceId) {
                throw new \RuntimeException("Private event {$entity} membutuhkan target_device_id.");
            }

            $targetHomeroomId = null;
        }

        $eventId = $this->ensureEventId($eventId, $entityId);

        if (OpaqueRouteRegistryV21::enabled() && CloudLmsRosterAliasV21::supports($entity)
            && $action !== 'delete') {
            $payload = app(CloudLmsRosterAliasV21::class)->outbound($entity, $payload);
        }

        return $this->pushRaw([
            'event_id' => $eventId,
            'school_id' => $this->getSchoolId(),
            'source_device_id' => $sourceDeviceId ?? $this->getDeviceId(),
            'target_device_id' => $targetDeviceId,
            'target_homeroom_id' => $targetHomeroomId,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'version' => $version,
            'payload' => $payload,
        ]);
    }

    /* ============================================================
     * RESPONSE HELPERS
     * ============================================================ */

    protected function extractPushStatus(array $response): string
    {
        $resultStatus =
            $response['data']['results'][0]['status']
            ?? $response['results'][0]['status']
            ?? null;

        if (is_string($resultStatus) && $resultStatus !== '') {
            return strtolower($resultStatus);
        }

        $candidates = [
            $response['status'] ?? null,
            $response['data']['status'] ?? null,
            $response['result']['status'] ?? null,
            $response['results']['status'] ?? null,
        ];

        foreach ($candidates as $status) {
            if (is_string($status) && $status !== '') {
                return strtolower($status);
            }
        }

        if (isset($response['accepted']) && $response['accepted'] === true) {
            return 'accepted';
        }

        if (isset($response['duplicate']) && $response['duplicate'] === true) {
            return 'duplicate';
        }

        if (isset($response['success']) && $response['success'] === true) {
            return 'success';
        }

        return 'unknown';
    }

    protected function extractPushErrorCode(array $response): ?string
    {
        return $response['data']['results'][0]['error_code']
            ?? $response['results'][0]['error_code']
            ?? $response['data']['error']['code']
            ?? $response['error']['code']
            ?? null;
    }

    protected function extractPushError(array $response): ?string
    {
        $resultError =
            $response['data']['results'][0]['error']
            ?? $response['results'][0]['error']
            ?? null;

        $candidates = [
            $resultError,
            $response['error'] ?? null,
            $response['message'] ?? null,
            $response['data']['error'] ?? null,
            $response['data']['message'] ?? null,
            $response['result']['error'] ?? null,
            $response['result']['message'] ?? null,
        ];

        foreach ($candidates as $error) {
            if (is_string($error) && $error !== '') {
                return $error;
            }

            if (is_array($error)) {
                return json_encode($error, JSON_UNESCAPED_UNICODE);
            }
        }

        return null;
    }

    /* ============================================================
     * SYNC RECORD — race-safe + node_id eksplisit
     * ============================================================ */

    protected function nodeTypeFor(string $deviceId): string
    {
        return 'teacher';
    }

    protected function createSyncRecord(
        string $entity,
        string $entityId,
        string $action,
        int $version,
        array $payload,
        ?string $targetDeviceId,
        ?int $targetHomeroomId,
        ?string $sourceDeviceId = null
    ): LmsSyncRecord {
        $nodeId = $sourceDeviceId;

        if ($nodeId === null || trim($nodeId) === '') {
            $nodeId = $this->getDeviceId();
        }

        // [WALI-SCOPE-V1] Idempotensi harus per node pemilik; jangan
        // pernah mendaur ulang outbox Anas sebagai outbox guru Zainal.
        $existing = LmsSyncRecord::query()
            ->where('node_id', $nodeId)
            ->where('entity_type', $entity)
            ->where('entity_id', $entityId)
            ->where('operation', $action)
            ->where('version', $version)
            ->where('target_device_id', $targetDeviceId)
            ->where('target_homeroom_id', $targetHomeroomId)
            ->whereIn('status', ['pending', 'failed'])
            ->latest('id')
            ->first();

        if ($existing) {
            $reset = [
                'payload' => $payload,
                'status' => 'pending',
                'last_error' => null,
            ];

            if ($this->hasRetryColumns()) {
                $reset['attempts'] = 0;
                $reset['last_attempt_at'] = null;
            }

            $existing->forceFill($reset)->save();

            return $existing;
        }

        $attributes = [
            'sync_id' => (string) Str::uuid(),
            'node_id' => $nodeId,
            'node_type' => $this->nodeTypeFor($nodeId),
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'operation' => $action,
            'version' => $version,
            'payload' => $payload,
            'target_device_id' => $targetDeviceId,
            'target_homeroom_id' => $targetHomeroomId,
            'status' => 'pending',
            'last_error' => null,
        ];

        try {
            return LmsSyncRecord::create($attributes);
        } catch (QueryException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }

            $winner = LmsSyncRecord::query()
                ->where('node_id', $attributes['node_id'])
                ->where('entity_type', $entity)
                ->where('entity_id', $entityId)
                ->where('operation', $action)
                ->where('version', $version)
                ->where('target_device_id', $targetDeviceId)
                ->where('target_homeroom_id', $targetHomeroomId)
                ->latest('id')
                ->first();

            if (!$winner) {
                throw $e;
            }

            $winner->forceFill([
                'payload' => $payload,
                'status' => 'pending',
                'last_error' => null,
            ])->save();

            return $winner;
        }
    }

    protected function markRecordSynced(LmsSyncRecord $record): void
    {
        $record->forceFill([
            'status' => 'synced',
            'last_error' => null,
            'synced_at' => now(),
        ])->save();
    }

    protected function markRecordRetryable(LmsSyncRecord $record, ?string $error): void
    {
        $record->forceFill([
            'status' => 'pending',
            'last_error' => $error,
        ])->save();
    }

    protected function markRecordDead(LmsSyncRecord $record, string $error): void
    {
        $record->forceFill([
            'status' => 'failed',
            'last_error' => $error,
        ])->save();

        Log::error('[LMS SYNC] Record final reject / age limit.', [
            'sync_id' => $record->sync_id,
            'entity' => $record->entity_type,
            'entity_id' => $record->entity_id,
            'error' => $error,
        ]);
    }

    /* ============================================================
     * RETRY — fair rotation + per-record device + age limit
     * ============================================================ */

    protected ?bool $retryColumnsCache = null;

    protected function hasRetryColumns(): bool
    {
        if ($this->retryColumnsCache === null) {
            try {
                $this->retryColumnsCache =
                    Schema::hasColumn('lms_sync_records', 'attempts')
                    && Schema::hasColumn('lms_sync_records', 'last_attempt_at');
            } catch (Throwable) {
                $this->retryColumnsCache = false;
            }
        }

        return $this->retryColumnsCache;
    }

    /**
     * Kode error final: tercatat sebagai 'failed' agar cocok CHECK SQLite,
     * namun tidak boleh di-retry ataupun menerima ACK palsu.
     * Marker disimpan di last_error sehingga tidak diperlukan migrasi.
     */
    protected const TERMINAL_OUTBOX_ERROR_PREFIXES = [
        'LOCAL_HOMEROOM_SCOPE_MISMATCH:',
        'SUPERSEDED_BY_DELETE',
        'FINAL_REJECT:',
        'OUTBOX_AGE_LIMIT:',
        'Material tidak ditemukan lagi di lokal.',
    ];

    public function retryPending(int $limit = 100): array
    {
        $query = LmsSyncRecord::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($filter) {
                $filter->whereNull('last_error')
                    ->orWhere(function ($active) {
                        foreach (self::TERMINAL_OUTBOX_ERROR_PREFIXES as $prefix) {
                            $active->where('last_error', 'not like', $prefix . '%');
                        }
                    });
            });

        if ($this->hasRetryColumns()) {
            $query->orderBy('last_attempt_at')->orderBy('id');
        } else {
            $query->orderBy('updated_at')->orderBy('id');
        }

        $records = $query->limit($limit)->get();

        $success = 0;
        $failed = 0;
        $deadLetter = 0;
        $results = [];

        foreach ($records as $record) {
            // [WALI-SCOPE-V1] Arsipkan salah pemilik sebelum pemeriksaan
            // usia outbox; event historis >24 jam juga tidak boleh
            // terjebak dalam siklus status failed yang dicoba ulang.
            $oldEntity = (string) $record->entity_type;
            if (in_array($oldEntity, ['catatan_wali_batch', 'rapor_recap_batch'], true)) {
                $oldPayload = is_array($record->payload) ? $record->payload
                    : (json_decode((string) $record->payload, true) ?: []);
                $room = (int) ($oldPayload['homeroom_id'] ?? 0);
                $expected = ($oldEntity === 'catatan_wali_batch'
                    ? 'catatan_wali::homeroom::' : 'rapor_recap::homeroom::') . $room;
                if ($room > 0 && (string) $record->entity_id === $expected
                    && $this->isHomeroomDeviceOwner($room, (string) $record->node_id) === false) {
                    $this->supersedeWrongOwnerHomeroomRecord($record);
                    $results[] = [
                        'sync_id' => $record->sync_id,
                        'entity' => $oldEntity,
                        'entity_id' => $record->entity_id,
                        'status' => 'superseded_wrong_owner',
                    ];
                    continue;
                }
            }

            $ageSeconds = $record->created_at
                ? now()->diffInSeconds($record->created_at)
                : 0;

            if ($ageSeconds >= self::OUTBOX_MAX_AGE_SECONDS) {
                $this->markRecordDead($record, 'OUTBOX_AGE_LIMIT: ' . (int) $ageSeconds . 's');
                $deadLetter++;
                $failed++;
                $results[] = [
                    'sync_id' => $record->sync_id,
                    'entity' => $record->entity_type,
                    'entity_id' => $record->entity_id,
                    'status' => 'failed',
                ];
                continue;
            }

            if ($this->hasRetryColumns()) {
                $record->attempts = (int) ($record->attempts ?? 0) + 1;
                $record->last_attempt_at = now();
                $record->save();
            }

            try {
                $entity = (string) $record->entity_type;
                $action = $this->normalizeClientAction((string) $record->operation);
                $version = (int) $record->version;

                if ($action === 'delete' && $version < self::EPOCH_FLOOR) {
                    $version = $this->currentVersion();
                }

                $payload = is_array($record->payload)
                    ? $record->payload
                    : (is_string($record->payload) ? (json_decode($record->payload, true) ?: []) : []);

                $status = null;

                if (($payload['__outbox'] ?? null) === 'raw_push') {
                    $body = is_array($payload['body'] ?? null) ? $payload['body'] : null;

                    if (!$body) {
                        throw new \RuntimeException('Outbox raw_push tanpa body.');
                    }

                    $result = $this->pushRaw($body);
                    $status = $this->extractPushStatus($result);

                    if (in_array($status, ['accepted', 'duplicate', 'success'], true)) {
                        $this->markRecordSynced($record);
                        $success++;
                        $results[] = [
                            'sync_id' => $record->sync_id,
                            'entity' => $entity,
                            'entity_id' => $record->entity_id,
                            'status' => $status,
                        ];
                        continue;
                    }

                    $errorCode = strtoupper((string) $this->extractPushErrorCode($result));

                    if (in_array($errorCode, self::BENIGN_REJECT_CODES, true)) {
                        $this->markRecordSynced($record);
                        $success++;
                    } elseif (in_array($errorCode, self::FINAL_REJECT_CODES, true)) {
                        $this->markRecordDead($record, 'FINAL_REJECT: ' . $errorCode);
                        $deadLetter++;
                        $failed++;
                    } else {
                        $this->finishFailedRetry($record, $this->extractPushError($result), $success, $failed, $deadLetter, $results);
                    }

                    $results[] = [
                        'sync_id' => $record->sync_id,
                        'entity' => $entity,
                        'entity_id' => $record->entity_id,
                        'status' => $status,
                    ];
                    continue;
                }

                if ($entity === 'lms_material' && $action !== 'delete') {
                    $material = !empty($payload['id'])
                        ? LmsMaterial::find((int) $payload['id'])
                        : null;

                    if ($material) {
                        $rebuild = $this->pushMaterial($material, $action);
                        $status = (string) ($rebuild['status'] ?? 'unknown');

                        if (in_array($status, ['accepted', 'duplicate', 'success', 'benign_rejected'], true)) {
                            $this->markRecordSynced($record);
                            $success++;
                        } else {
                            $this->finishFailedRetry($record, $rebuild['error'] ?? $status, $success, $failed, $deadLetter, $results);
                        }

                        $results[] = [
                            'sync_id' => $record->sync_id,
                            'entity' => $entity,
                            'entity_id' => $record->entity_id,
                            'status' => $status,
                        ];
                        continue;
                    }

                    $this->markRecordDead($record, 'Material tidak ditemukan lagi di lokal.');
                    $deadLetter++;
                    $failed++;
                    $results[] = [
                        'sync_id' => $record->sync_id,
                        'entity' => $entity,
                        'entity_id' => $record->entity_id,
                        'status' => 'failed',
                    ];
                    continue;
                }

                /* [WALI-SCOPE-V1] Arsipkan event historis yang dikirim dari
                 * identitas PC BUKAN pemilik wali kelas. Jangan beri ACK palsu,
                 * jangan hapus nilai/rapor, dan jangan ulang push ke Cloud. */
                if (in_array($entity, ['catatan_wali_batch', 'rapor_recap_batch'], true)) {
                    $homeroomId = (int) ($payload['homeroom_id'] ?? 0);
                    $expectedEntityId = ($entity === 'catatan_wali_batch'
                        ? 'catatan_wali::homeroom::' : 'rapor_recap::homeroom::') . $homeroomId;
                    if ($homeroomId <= 0 || (string) $record->entity_id !== $expectedEntityId) {
                        throw new \RuntimeException('HOMEROOM_SCOPE_INVALID: payload atau entity_id tidak konsisten.');
                    }
                    $ownerCheck = $this->isHomeroomDeviceOwner(
                        $homeroomId, (string) $record->node_id
                    );
                    if ($ownerCheck === false) {
                        $this->supersedeWrongOwnerHomeroomRecord($record);
                        $results[] = [
                            'sync_id' => $record->sync_id,
                            'entity' => $entity,
                            'entity_id' => $record->entity_id,
                            'status' => 'superseded_wrong_owner',
                        ];
                        continue;
                    }
                    if ($ownerCheck === null) {
                        throw new \RuntimeException('HOMEROOM_SCOPE_UNVERIFIED: identitas wali kelas belum dapat diverifikasi.');
                    }
                }

                $targetDeviceId = $record->target_device_id;
                $targetHomeroomId = $record->target_homeroom_id;

                if ($entity === 'attendance_session') {
                    $payload = $this->normalizeAttendancePayload($payload, $version);
                    $payload['sync_version'] = $version;

                    if (!$targetDeviceId) {
                        $targetDeviceId = $payload['target_device_id'] ?? null;
                    }

                    if (!$targetDeviceId && !$this->isTeacherRoutedEntity($entity)) {
                        throw new \RuntimeException('Target device attendance tidak tersedia saat retry.');
                    }

                    if ($targetDeviceId) {
                        $targetHomeroomId = null;
                    }
                }

                if ($entity === 'lms_assignment_submission' && !$targetDeviceId) {
                    $studentNisn = $payload['student_nisn'] ?? null;

                    if ($studentNisn) {
                        $targetDeviceId = app(SchoolIdentity::class)->studentDeviceId((string) $studentNisn);
                    }
                }

                if ($this->shouldBroadcastToStudents($entity)) {
                    $targetDeviceId = null;

                    if (!$targetHomeroomId) {
                        $targetHomeroomId = $payload['target_homeroom_id'] ?? $payload['homeroom_id'] ?? null;
                    }

                    if (!$targetHomeroomId) {
                        throw new \RuntimeException('Target homeroom broadcast tidak tersedia saat retry.');
                    }
                }

                if (
                    !$this->shouldBroadcastToStudents($entity)
                    && !$this->isTeacherRoutedEntity($entity)
                    && !$targetDeviceId
                ) {
                    throw new \RuntimeException(
                        "Target device private {$entity} tidak tersedia saat retry."
                    );
                }

                $result = $this->pushChange(
                    $entity,
                    (string) $record->entity_id,
                    $action,
                    $version,
                    $payload,
                    $targetDeviceId !== null ? (string) $targetDeviceId : null,
                    $targetHomeroomId !== null ? (int) $targetHomeroomId : null,
                    (string) $record->sync_id,
                    (string) $record->node_id
                );

                $status = $this->extractPushStatus($result);

                if (in_array($status, ['accepted', 'duplicate', 'success'], true)) {
                    $this->markRecordSynced($record);
                    $this->markLocalModelSyncedIfFullySynced($entity, (string) $record->entity_id);
                    $success++;
                } else {
                    $errorCode = strtoupper((string) $this->extractPushErrorCode($result));

                    if (in_array($errorCode, self::BENIGN_REJECT_CODES, true)) {
                        $this->markRecordSynced($record);
                        $success++;
                    } elseif (in_array($errorCode, self::FINAL_REJECT_CODES, true)) {
                        $this->markRecordDead($record, 'FINAL_REJECT: ' . $errorCode);
                        $deadLetter++;
                        $failed++;
                    } else {
                        $this->finishFailedRetry($record, $this->extractPushError($result), $success, $failed, $deadLetter, $results);
                    }
                }

                $results[] = [
                    'sync_id' => $record->sync_id,
                    'entity' => $entity,
                    'entity_id' => $record->entity_id,
                    'status' => $status ?? 'unknown',
                ];
            } catch (Throwable $e) {
                $this->finishFailedRetry($record, $e->getMessage(), $success, $failed, $deadLetter, $results);

                Log::warning('[LMS SYNC] Retry gagal.', [
                    'sync_id' => $record->sync_id,
                    'entity' => $record->entity_type,
                    'entity_id' => $record->entity_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => $failed === 0,
            'total' => $records->count(),
            'success_count' => $success,
            'failed_count' => $failed,
            'dead_lettered' => $deadLetter,
            'synced' => $success,
            'results' => $results,
        ];
    }

    protected function finishFailedRetry(
        LmsSyncRecord $record,
        ?string $error,
        int &$success,
        int &$failed,
        int &$deadLetter,
        array &$results
    ): void {
        $message = (string) ($error ?? 'unknown');

        $this->markRecordRetryable($record, $message);
        $failed++;
    }

    /* ============================================================
     * DEVICE REGISTRATION
     * ============================================================ */

    public function registerTeacherDevice(string $enrollmentToken, string $deviceId): array
    {
        $this->validateConfiguration();

        if (!auth()->check()) {
            throw new \RuntimeException('Guru harus login untuk melakukan pairing device.');
        }

        $user = auth()->user();

        $loginId = $user->login_id
            ?? $user->username
            ?? $user->nip
            ?? $user->nik
            ?? null;

        if (!$loginId) {
            throw new \RuntimeException('Login ID guru tidak ditemukan.');
        }

        $schoolId = $this->getSchoolId();

        $canonicalDevice = app(SchoolIdentity::class)->teacherPcDeviceId((string) $loginId);
        if (!hash_equals($canonicalDevice, $deviceId)) {
            throw new \RuntimeException('BLOCKED: device PC bukan identitas canonical guru ini.');
        }
        $cloudLoginId = OpaqueRouteRegistryV21::enabled()
            ? app(OpaqueRouteRegistryV21::class)->alias($schoolId, 'teacher', (string) $loginId)
            : trim((string) $loginId);

        $existing = SyncDevice::query()
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->first();
        /* [GTK-BUNDLE-AUTOPAIR-V1] LOCAL-TOKEN: token lokal tidak lagi dianggap bukti pairing Cloud. */
$response = $this->client->registerDevice([
            'school_id' => $schoolId,
            'device_id' => $deviceId,
            'device_type' => 'teacher',
            'login_id' => $cloudLoginId,
            'enrollment_token' => $enrollmentToken,
        ]);

        $syncToken =
            $response['device_token']
            ?? $response['data']['device_token']
            ?? $response['sync_token']
            ?? $response['data']['sync_token']
            ?? null;

        if (!$syncToken) {
            throw new \RuntimeException('Cloud tidak mengembalikan device_token.');
        }

        $device = SyncDevice::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'school_id' => $schoolId,
                'device_type' => 'teacher',
                'sync_token' => $syncToken,
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        return [
            'success' => true,
            'device_id' => $deviceId,
            'already_registered' => false,
            'device' => $this->sanitizeDeviceResponse($device),
        ];
    }

    protected function sanitizeDeviceResponse(SyncDevice $device): array
    {
        return [
            'id' => $device->id,
            'school_id' => $device->school_id,
            'device_id' => $device->device_id,
            'device_type' => $device->device_type,
            'is_active' => (bool) $device->is_active,
            'last_synced_at' => $this->toIso($device->last_synced_at),
        ];
    }

    /* ============================================================
     * PULL / ACK
     * ============================================================ */

    public function pull(string $deviceId, int $cursor = 0, array $skipEventIds = []): array
    {
        $this->validateConfiguration();

        return $this->client->pull($this->getSchoolId(), $deviceId, $cursor, null, $skipEventIds);
    }

    public function acknowledge(string $deviceId, array $changeIds): array
    {
        $this->validateConfiguration();

        return $this->client->ack($this->getSchoolId(), $deviceId, $changeIds);
    }

    /* ============================================================
     * STUDENT ACCOUNT DIRECTORY
     * ============================================================ */

    public function pushStudentAccountDirectory(): array
    {
        $schoolId = $this->getSchoolId();
        $schoolName = trim((string) DB::table('schools')->value('nama'));

        $homeroomsByStudent = [];

        DB::table('enrollments')
            ->join('homerooms', 'homerooms.id', '=', 'enrollments.homeroom_id')
            ->orderBy('enrollments.homeroom_id')
            ->select([
                'enrollments.student_id',
                'enrollments.homeroom_id',
                'homerooms.nama as homeroom_name',
            ])
            ->chunk(1000, function ($rows) use (&$homeroomsByStudent) {
                foreach ($rows as $e) {
                    if ($e->homeroom_id === null) {
                        continue;
                    }

                    $sid = (int) $e->student_id;
                    $homeroomsByStudent[$sid][(string) (int) $e->homeroom_id] = $e->homeroom_name;
                }
            });

        // V21R77: preflight seluruh siswa SEBELUM HTTP pertama.  Jika dua
        // baris Dapodik mempunyai NISN sama, jangan memilih salah satunya
        // diam-diam lalu membuat snapshot akun yang tidak deterministik.
        $duplicateStudent = DB::table('students')
            ->when($this->studentsHaveSoftDelete(), fn ($q) => $q->whereNull('deleted_at'))
            ->whereNotNull('nisn')
            ->whereRaw("TRIM(nisn) <> ''")
            ->selectRaw('TRIM(nisn) AS normalized_nisn, COUNT(*) AS jumlah')
            ->groupByRaw('TRIM(nisn)')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateStudent !== null) {
            throw new \RuntimeException(
                'BLOCKED V21R77: NISN ganda ditemukan pada data siswa lokal. '
                .'Perbaiki identitas ganda di Dapodik/SQLite melalui mekanisme resmi sebelum push. '
                .'Tidak ada akun baru yang dikirim ke Cloud.'
            );
        }

        $totalStudents = 0;
        $totalUpserted = 0;

        $filterDeleted = $this->studentsHaveSoftDelete();

        DB::table('students')
            ->when($filterDeleted, fn ($q) => $q->whereNull('deleted_at'))
            ->whereNotNull('nisn')
            ->where('nisn', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($students) use (
                $schoolId,
                $schoolName,
                $homeroomsByStudent,
                &$totalStudents,
                &$totalUpserted
            ) {
                $accounts = [];

                foreach ($students as $row) {
                    $nisn = trim((string) ($row->nisn ?? ''));

                    if ($nisn === '') {
                        continue;
                    }

                    $loginId = $nisn;
                    $deviceId = $schoolId . '-' . $nisn . '-HP';

                    $passwordHash = hash(
                        'sha256',
                        $schoolId . '|' . $loginId . '|' . $schoolId
                    );

                    $entry = $homeroomsByStudent[(int) $row->id] ?? [];
                    $homeroomIds = array_keys($entry);

                    $homeroomKey = $homeroomIds !== []
                        ? '|' . implode('|', $homeroomIds) . '|'
                        : null;

                    $homeroomNames = $entry !== []
                        ? json_encode($entry, JSON_UNESCAPED_UNICODE)
                        : null;

                    $accounts[] = [
                        'school_id' => $schoolId,
                        'student_id' => (int) $row->id,
                        'nisn' => $nisn,
                        'name' => $row->nama,
                        'school_name' => $schoolName !== '' ? $schoolName : null,
                        'login_id' => $loginId,
                        'password_hash' => $passwordHash,
                        'homeroom_key' => $homeroomKey,
                        'homeroom_names' => $homeroomNames,
                        'device_id' => $deviceId,
                    ];
                }

                if ($accounts === []) {
                    return true;
                }

                // V21R3 staging: do not send raw NISN/login_id to Cloud directory.
                if (CloudDirectoryAliasV21::stagingEnabled()) {
                    $transformer = CloudDirectoryAliasV21::fromConfig();
                    $transformer->provisionIds($schoolId, 'student', array_column($accounts, 'nisn'));
                    $accounts = array_map(fn (array $account): array =>
                        $transformer->student($schoolId, $account), $accounts);
                }

                $result = $this->client->pushStudentAccountDirectoryBulk($schoolId, $accounts);

                $totalStudents += count($accounts);
                $totalUpserted += max(0, (int) ($result['upserted'] ?? count($accounts)));
                return true;
            });

        if ($totalStudents === 0) {
            return [
                'success' => true,
                'pushed' => 0,
                'upserted' => 0,
                'message' => 'Tidak ada siswa valid (NISN kosong semua?).',
                'accounts' => [],
            ];
        }

        return [
            'success' => true,
            'pushed' => $totalUpserted,
            'upserted' => $totalUpserted,
            'students' => $totalStudents,
            'message' => "Direktori akun {$totalStudents} siswa terkirim ({$totalUpserted} upserted).",
        ];
    }

    /* ============================================================
     * TEACHER ACCOUNT DIRECTORY
     * ============================================================ */

    public function pushTeacherAccountDirectory(): array
    {
        $schoolId = $this->getSchoolId();
        $schoolName = trim((string) DB::table('schools')->value('nama'));

        $adminKey = EduxidCloudConfig::adminToken();

        if ($adminKey === '') {
            throw new \RuntimeException(
                'Kunci admin belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud, tempel '
                . 'kunci dari operator pusat.'
            );
        }

        // V21R98: manually entered GTK in `teachers` may have no `users`
        // login. Provision valid missing local users before this user-based
        // directory query; the existing V21 alias/HTTPS path remains unchanged.
        $manualGtkReadiness = app(CloudManualTeacherReadinessV21R98::class)->prepare();

        // Restore pra-V21 tidak selalu menyediakan users.nip/users.nik.
        $userColumns = ['id', 'login_id', 'name', 'role'];
        foreach (['nip', 'nik'] as $optionalColumn) {
            if (Schema::hasColumn('users', $optionalColumn)) {
                $userColumns[] = $optionalColumn;
            }
        }
        $users = DB::table('users')
            ->whereIn('role', ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah', 'tendik'])
            ->select($userColumns)
            ->orderBy('id')
            ->get();

        $accounts = [];
        $skipped = [];
        $teachersSoftDelete = $this->teachersHaveSoftDelete();
        // V21R77: satu master GTK hanya boleh memiliki satu akun Cloud.
        // Index ini hanya hidup selama push; tanpa menulis data identitas baru.
        $teacherLoginById = [];
        $teacherIdByLogin = [];
        $teacherIdByNip = [];
        $teacherIdByNik = [];
        // V21R81: deteksi bentrok identitas pada Tendik tanpa master GTK.
        $standaloneTendikByLogin = [];

        foreach ($users as $user) {
            // Kolom user_id adalah opsional pada tabel master teachers.
            // Jangan mencoba SELECT terhadap kolom yang tidak ada.
            $teacher = null;
            if (Schema::hasColumn('teachers', 'user_id')) {
                $teacherQuery = Teacher::query()->where('user_id', $user->id);
                if ($teachersSoftDelete) {
                    $teacherQuery->whereNull('deleted_at');
                }
                $teacher = $teacherQuery->first();
            }

            if (!$teacher && $user->login_id) {
                $matchQuery = Teacher::query()->where(function ($query) use ($user) {
                    $query->where('nip', $user->login_id)
                        ->orWhere('nik', $user->login_id)
                        ->orWhere('nuptk', $user->login_id);
                });

                if ($teachersSoftDelete) {
                    $matchQuery->whereNull('deleted_at');
                }

                $teacher = $matchQuery->first();
            }

            // V21R81 — akun Tendik dapat eksis secara sah tanpa baris teachers.
            // Akun ini tidak boleh mendapat teaching_assignments maupun roster.
            // Tetap memakai login_id resmi sebagai bahan alias opaque V21, sehingga
            // sinkronisasi ulang melakukan UPSERT terhadap akun Cloud yang sama.
            if (!$teacher && strtolower(trim((string) ($user->role ?? ''))) === 'tendik') {
                $staffLogin = trim((string) ($user->login_id ?? ''));
                // Akun template "tendik" dan identitas kosong bukan orang nyata.
                if (preg_match('/^[0-9]{8,20}$/D', $staffLogin) !== 1) {
                    $skipped[] = 'V21R81: Tendik tanpa master GTK memiliki login NIP/NIK tidak valid.';
                    continue;
                }
                if (isset($standaloneTendikByLogin[$staffLogin])
                    || (isset($teacherIdByLogin) && isset($teacherIdByLogin[$staffLogin]))) {
                    throw new \RuntimeException(
                        'BLOCKED V21R81: login Tendik duplikat dalam direktori. Perbaiki akun lokal sebelum push.'
                    );
                }
                $standaloneTendikByLogin[$staffLogin] = true;
                $accounts[] = [
                    'school_id' => $schoolId,
                    'user_id' => (int) $user->id,
                    'teacher_id' => null,
                    'login_id' => $staffLogin,
                    'password_hash' => hash('sha256', $schoolId . '|' . $staffLogin . '|' . $schoolId),
                    'name' => $user->name ?: 'Tendik',
                    'school_name' => $schoolName !== '' ? $schoolName : null,
                    'device_id' => $schoolId . '-' . $staffLogin . '-HP',
                    'pc_device_id' => $schoolId . '-' . $staffLogin . '-PC',
                    'assignments' => [],
                ];
                continue;
            }
            if (!$teacher) {
                $skipped[] = "user #{$user->id} ({$user->name}) tanpa master Teacher";
                continue;
            }

            $teacherId = (int) $teacher->id;
            $loginId = trim((string) $user->login_id);

            if (isset($standaloneTendikByLogin[$loginId])) {
                throw new \RuntimeException(
                    'BLOCKED V21R81: login Tendik tanpa master berbenturan dengan master GTK.'
                );
            }

            if ($loginId === '') {
                $skipped[] = "user #{$user->id} ({$user->name}) tanpa login_id";
                continue;
            }

            // Jangan pernah menyamakan orang hanya berdasarkan nama. Identitas
            // authoritative adalah teacher.id + NIP/NIK dari master Dapodik.
            if (isset($teacherLoginById[$teacherId])) {
                if ($teacherLoginById[$teacherId] !== $loginId) {
                    throw new \RuntimeException(
                        'BLOCKED V21R77: satu master GTK terhubung ke lebih dari satu login lokal. '
                        .'Perbaiki relasi akun guru sebelum push; akun Cloud tidak diubah.'
                    );
                }
                // Pengguna kedua dengan master dan login identik bukan akun baru.
                continue;
            }
            if (isset($teacherIdByLogin[$loginId]) && $teacherIdByLogin[$loginId] !== $teacherId) {
                throw new \RuntimeException(
                    'BLOCKED V21R77: satu login GTK menunjuk dua master guru berbeda. '
                    .'Perbaiki relasi guru; akun Cloud tidak diubah.'
                );
            }
            $nip = trim((string) ($teacher->nip ?? ''));
            $nik = trim((string) ($teacher->nik ?? ''));
            if (($nip !== '' && isset($teacherIdByNip[$nip]) && $teacherIdByNip[$nip] !== $teacherId)
                || ($nik !== '' && isset($teacherIdByNik[$nik]) && $teacherIdByNik[$nik] !== $teacherId)) {
                throw new \RuntimeException(
                    'BLOCKED V21R77: NIP/NIK yang sama ditemukan pada dua master GTK. '
                    .'Perbaiki data guru lokal sebelum push.'
                );
            }
            if ($nip !== '') $teacherIdByNip[$nip] = $teacherId;
            if ($nik !== '') $teacherIdByNik[$nik] = $teacherId;
            $teacherLoginById[$teacherId] = $loginId;
            $teacherIdByLogin[$loginId] = $teacherId;

            $assignments = DB::table('teaching_assignments')
                ->leftJoin('subjects', 'subjects.id', '=', 'teaching_assignments.subject_id')
                ->leftJoin('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
                ->where('teaching_assignments.teacher_id', $teacherId)
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

            $assignmentPayload = [];

            foreach ($assignments as $assignment) {
                $homeroomId = (int) $assignment->homeroom_id;

                $rosterQuery = DB::table('enrollments')
                    ->join('students', 'students.id', '=', 'enrollments.student_id')
                    ->where('enrollments.homeroom_id', $homeroomId);

                if ($this->studentsHaveSoftDelete()) {
                    $rosterQuery->whereNull('students.deleted_at');
                }

                $roster = $rosterQuery
                    ->select(['students.id', 'students.nisn', 'students.nama'])
                    ->orderBy('students.nama')
                    ->get();

                $assignmentPayload[] = [
                    'ta_id' => (int) $assignment->ta_id,
                    'teacher_id' => $teacherId,
                    'subject_id' => $assignment->subject_id !== null ? (int) $assignment->subject_id : null,
                    'subject_name' => $assignment->subject_name,
                    'homeroom_id' => $homeroomId,
                    'homeroom_name' => $assignment->homeroom_name,
                    'semester_id' => $assignment->semester_id !== null ? $assignment->semester_id : null,
                    'roster' => $roster->map(fn ($student) => [
                        'id' => (int) $student->id,
                        'nisn' => $student->nisn,
                        'nama' => $student->nama,
                    ])->values()->all(),
                ];
            }

            /* [V9] Scope routing Cloud. Record scope_only tidak ditampilkan
             * sebagai penugasan mengajar pada UI PWA Guru. */
            $waliHomerooms = DB::table('homerooms')
                ->where('homeroom_teacher_id', $teacherId)
                ->select(['id', 'nama', 'semester_id'])
                ->get();

            foreach ($waliHomerooms as $waliHomeroom) {
                $assignmentPayload[] = [
                    'ta_id' => null,
                    'teacher_id' => $teacherId,
                    'homeroom_id' => (int) $waliHomeroom->id,
                    'homeroom_name' => $waliHomeroom->nama,
                    'semester_id' => $waliHomeroom->semester_id,
                    'is_homeroom_teacher' => true,
                    'scope_only' => true,
                    'roster' => DB::table('enrollments')
                        ->join('students', 'students.id', '=', 'enrollments.student_id')
                        ->where('enrollments.homeroom_id', (int) $waliHomeroom->id)
                        ->when($this->studentsHaveSoftDelete(), fn ($q) => $q->whereNull('students.deleted_at'))
                        ->whereNotNull('students.nisn')->where('students.nisn', '!=', '')
                        ->orderBy('students.id')->get(['students.id', 'students.nisn', 'students.nama'])
                        ->map(fn ($student) => [
                            'id' => (int) $student->id,
                            'nisn' => $student->nisn,
                            'nama' => $student->nama,
                        ])->values()->all(),
                ];
            }

            if ($assignmentPayload === []) {
                $assignmentPayload[] = [
                    'ta_id' => null,
                    'teacher_id' => $teacherId,
                    'scope_only' => true,
                ];
            }

            $accounts[] = [
                'school_id' => $schoolId,
                'user_id' => (int) $user->id,
                'teacher_id' => $teacherId,
                'login_id' => $loginId,
                'password_hash' => hash('sha256', $schoolId . '|' . $loginId . '|' . $schoolId),
                'name' => $user->name ?? $teacher->nama ?? $teacher->name ?? 'Guru',
                'school_name' => (isset($schoolName) && $schoolName !== '') ? $schoolName : null,
                'device_id' => $schoolId . '-' . $loginId . '-HP',
                'pc_device_id' => $schoolId . '-' . $loginId . '-PC',
                'assignments' => $assignmentPayload,
            ];
        }

        if ($accounts === []) {
            return [
                'success' => true,
                'pushed' => 0,
                'message' => 'Tidak ada akun guru valid yang dapat disinkronkan.',
                'skipped' => $skipped,
                'manual_gtk' => $manualGtkReadiness,
            ];
        }

        // V21R3 staging: alias teacher + all NISN in teaching rosters before push.
        // Legacy mode is untouched, and no school alias secret leaves Laravel.
        if (CloudDirectoryAliasV21::stagingEnabled()) {
            $transformer = CloudDirectoryAliasV21::fromConfig();
            $accounts = array_map(fn (array $account): array =>
                $transformer->teacher($schoolId, $account), $accounts);
        }

        // V21R56: fixed 25-account chunks can contain >30,000 JSON nodes
        // once assignments and rosters are nested per teacher. Cloud correctly
        // rejects those requests with UNBOUNDED_IDENTITY_PAYLOAD (HTTP 422).
        // Split only at teacher boundaries: assignments are authoritative
        // snapshots and must never be split into separate HTTP requests.
        $batches = CloudTeacherDirectoryBatchV21::split($accounts, self::BULK_CHUNK_SIZE);
        $totalUpserted = 0;

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;

            $response = Http::timeout(self::BULK_TIMEOUT)
                ->connectTimeout(15)
                ->retry(2, 750, fn (Throwable $exception): bool => $exception instanceof \Illuminate\Http\Client\ConnectionException)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Authorization' => 'Admin ' . $adminKey])
                ->withOptions($this->httpOptions())
                ->post(
                    rtrim($this->syncServer(), '/') . '/api/admin/teacher-accounts/sync',
                    [
                        'school_id' => $schoolId,
                        'accounts' => $batch,
                    ]
                );

            if (!$response->successful()) {
                throw new \RuntimeException(
                    'Teacher account directory batch ' . $batchNumber . '/'
                    . count($batches) . ' gagal: ' . mb_substr($response->body(), 0, 500)
                );
            }

            $data = $response->json();

            if (!is_array($data) || ($data['success'] ?? false) !== true) {
                throw new \RuntimeException(
                    'Respons Cloud teacher account directory tidak valid (batch '
                    . $batchNumber . '): ' . mb_substr(json_encode($data), 0, 500)
                );
            }

            $upserted = (int) (
                $data['upserted']
                ?? $data['data']['upserted']
                ?? count($batch)
            );

            $totalUpserted += max(0, $upserted);
        }

        return [
            'success' => true,
            'pushed' => $totalUpserted,
            'teachers' => count($accounts),
            'batches' => count($batches),
            'skipped' => $skipped,
            'manual_gtk' => $manualGtkReadiness,
            'message' => "Direktori akun " . count($accounts) . " guru terkirim ({$totalUpserted} upserted)."
                . ($skipped !== [] ? ' Dilewati: ' . count($skipped) . ' akun.' : ''),
        ];
    }

    protected function teachersHaveSoftDelete(): bool
    {
        try {
            return Schema::hasColumn('teachers', 'deleted_at');
        } catch (Throwable) {
            return false;
        }
    }

    public function uploadAnswerPhotoCompatible(
        string $absolutePath,
        string $fileKey,
        string $mimeType,
        string $deviceToken
    ): void {
        $syncServer = trim((string) $this->syncServer());

        if ($syncServer === '') {
            throw new \RuntimeException('URL eduXid Cloud belum dikonfigurasi.');
        }

        $response = Http::timeout(180)
            ->withToken($deviceToken)
            ->withHeaders([
                'X-File-Key'   => $fileKey,
                'Content-Type' => $mimeType,
            ])
            ->withBody(
                file_get_contents($absolutePath),
                $mimeType
            )
            ->post(rtrim($syncServer, '/') . '/api/file/upload');

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Unggah gagal (HTTP ' . $response->status() . '): '
                . mb_substr($response->body(), 0, 300)
            );
        }
    }

    protected function httpOptions(): array
    {
        $caCert = trim((string) config('eduxid.ca_cert', ''));

        return $caCert !== '' && is_file($caCert)
            ? ['verify' => $caCert]
            : [];
    }
}
