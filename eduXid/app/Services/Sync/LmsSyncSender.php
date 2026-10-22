<?php

namespace App\Services\Sync;

use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsSyncRecord;
use App\Models\LmsTopic;
use App\Models\SyncDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LmsSyncSender
{
    public function __construct(protected SyncClient $client)
    {
    }

    public function getClient(): SyncClient
    {
        return $this->client;
    }

    protected function getSchoolId(): string
    {
        $npsn = trim((string) DB::table('schools')->value('npsn'));
        if ($npsn !== '') {
            return $npsn;
        }
        $configured = trim((string) config('eduxid.school_id', ''));
        if ($configured === '') {
            throw new \RuntimeException('School ID sinkronisasi belum dikonfigurasi.');
        }
        return $configured;
    }

    protected function getDeviceId(): string
    {
        $schoolId = $this->getSchoolId();
        $user = auth()->user();
        if ($user) {
            $loginId = trim((string) $user->login_id);
            if ($loginId !== '') {
                return app(SchoolIdentity::class)->teacherPcDeviceId($loginId);
            }
        }
        $registered = trim(
            (string) SyncDevice::query()
                ->where('school_id', $schoolId)
                ->where('device_type', 'teacher')
                ->where('is_active', true)
                ->orderByDesc('last_synced_at')
                ->value('device_id')
        );
        if ($registered !== '') {
            if (OpaqueRouteRegistryV21::enabled()) {
                CloudOutboundPrivacyGuardV21::assertDevice($schoolId, $registered, 'teacher');
            }
            return $registered;
        }
        $configured = trim((string) config('eduxid.device_id', ''));
        if ($configured === '') {
            throw new \RuntimeException('Device ID guru belum tersedia.');
        }
        if (OpaqueRouteRegistryV21::enabled()) {
            CloudOutboundPrivacyGuardV21::assertDevice($schoolId, $configured, 'teacher');
        }
        return $configured;
    }

    protected function resolveCourseHomeroomId(?LmsCourse $course): int
    {
        if (!$course) {
            throw new \RuntimeException('Course LMS tidak ditemukan untuk menentukan target rombel.');
        }
        $homeroomId = (int) $course->homeroom_id;
        if ($homeroomId <= 0) {
            throw new \RuntimeException('Course LMS ' . ($course->id ?? '?') . ' tidak memiliki homeroom_id.');
        }
        return $homeroomId;
    }

    protected function resolveTopicHomeroomId(?LmsTopic $topic): int
    {
        if (!$topic) {
            throw new \RuntimeException('Topic LMS tidak ditemukan untuk menentukan target rombel.');
        }
        $topic->loadMissing('course');
        return $this->resolveCourseHomeroomId($topic->course);
    }

    protected function assertEntitySyncable(string $syncId): void
    {
        if (trim((string) $syncId) === '') {
            throw new \RuntimeException('entity sync_id kosong — jalankan ensureSyncId atau simpan model terlebih dahulu.');
        }
    }

    public function course(LmsCourse $course, string $action = 'update'): array
    {
        $this->assertEntitySyncable($course->sync_id);
        $homeroomId = $this->resolveCourseHomeroomId($course);
        $version = max(1, (int) $course->sync_version);
        return $this->pushToStudents(
            entityType: 'lms_course',
            entityId: (string) $course->sync_id,
            action: $action,
            version: $version,
            homeroomId: $homeroomId,
            payload: [
                'sync_id' => $course->sync_id,
                'id' => $course->id,
                'entity' => 'lms_course',
                'name' => $course->name,
                'description' => $course->description,
                'teacher_id' => $course->teacher_id,
                'subject_id' => $course->subject_id,
                'homeroom_id' => $homeroomId,
                'target_homeroom_id' => $homeroomId,
                'academic_year' => $course->academic_year,
                'semester' => $course->semester,
                'is_published' => (bool) $course->is_published,
                'sync_version' => $version,
                'created_at' => $course->created_at?->toISOString(),
                'updated_at' => $course->updated_at?->toISOString(),
            ]
        );
    }

    public function topic(LmsTopic $topic, string $action = 'update'): array
    {
        $this->assertEntitySyncable($topic->sync_id);
        $topic->loadMissing('course');
        $homeroomId = $this->resolveTopicHomeroomId($topic);
        $version = max(1, (int) $topic->sync_version);
        return $this->pushToStudents(
            entityType: 'lms_topic',
            entityId: (string) $topic->sync_id,
            action: $action,
            version: $version,
            homeroomId: $homeroomId,
            payload: [
                'sync_id' => $topic->sync_id,
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
                'created_at' => $topic->created_at?->toISOString(),
                'updated_at' => $topic->updated_at?->toISOString(),
            ]
        );
    }

    public function material(LmsMaterial $material, string $action = 'update'): array
    {
        $this->assertEntitySyncable($material->sync_id);
        $material->loadMissing('topic');
        $homeroomId = $this->resolveTopicHomeroomId($material->topic);
        $version = max(1, (int) $material->sync_version);
        return $this->pushToStudents(
            entityType: 'lms_material',
            entityId: (string) $material->sync_id,
            action: $action,
            version: $version,
            homeroomId: $homeroomId,
            payload: [
                'sync_id' => $material->sync_id,
                'id' => $material->id,
                'entity' => 'lms_material',
                'topic_id' => $material->topic_id,
                'topic_sync_id' => $material->topic?->sync_id,
                'homeroom_id' => $homeroomId,
                'target_homeroom_id' => $homeroomId,
                'title' => $material->title,
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
                'sort_order' => (int) $material->sort_order,
                'is_published' => (bool) $material->is_published,
                'sync_version' => $version,
                'created_at' => $material->created_at?->toISOString(),
                'updated_at' => $material->updated_at?->toISOString(),
            ]
        );
    }

    public function assignment(LmsAssignment $assignment, string $action = 'update'): array
    {
        $this->assertEntitySyncable($assignment->sync_id);
        $assignment->loadMissing('topic');
        $homeroomId = $this->resolveTopicHomeroomId($assignment->topic);
        $version = max(1, (int) $assignment->sync_version);
        $examSyncId = null;
        if ($assignment->exam_id) {
            $examSyncId = DB::table('exams')->where('id', $assignment->exam_id)->value('sync_id');
        }
        return $this->pushToStudents(
            entityType: 'lms_assignment',
            entityId: (string) $assignment->sync_id,
            action: $action,
            version: $version,
            homeroomId: $homeroomId,
            payload: [
                'sync_id' => $assignment->sync_id,
                'id' => $assignment->id,
                'entity' => 'lms_assignment',
                'topic_id' => $assignment->topic_id,
                'topic_sync_id' => $assignment->topic?->sync_id,
                'homeroom_id' => $homeroomId,
                'target_homeroom_id' => $homeroomId,
                'title' => $assignment->title,
                'instructions' => $assignment->instructions,
                'available_from' => $assignment->available_from?->toISOString(),
                'due_at' => $assignment->due_at?->toISOString(),
                'submission_type' => $assignment->submission_type,
                'max_score' => $assignment->max_score,
                'assignment_type' => $assignment->assignment_type,
                'exam_id' => $assignment->exam_id,
                'exam_sync_id' => $examSyncId,
                'is_published' => (bool) $assignment->is_published,
                'sync_version' => $version,
                'created_at' => $assignment->created_at?->toISOString(),
                'updated_at' => $assignment->updated_at?->toISOString(),
            ]
        );
    }

    public function pushToStudents(
        string $entityType,
        string $entityId,
        string $action,
        int $version,
        array $payload,
        ?int $homeroomId = null
    ): array {
        $schoolId = $this->getSchoolId();
        $deviceId = $this->getDeviceId();

        $entityId = trim($entityId);
        if ($entityId === '') {
            throw new \RuntimeException('entity_id broadcast tidak boleh kosong.');
        }

        $version = max(1, $version);

        if ($homeroomId === null || $homeroomId <= 0) {
            $homeroomId = isset($payload['target_homeroom_id']) && (int) $payload['target_homeroom_id'] > 0
                ? (int) $payload['target_homeroom_id']
                : null;
        }

        if ($homeroomId === null || $homeroomId <= 0) {
            throw new \RuntimeException('Broadcast LMS ' . $entityType . ' membutuhkan target_homeroom_id yang valid.');
        }

        $eventId = $this->buildEventId($entityId, $action, $version);

        $response = $this->client->push([
            'event_id' => $eventId,
            'school_id' => $schoolId,
            'source_device_id' => $deviceId,
            'target_device_id' => null,
            'target_homeroom_id' => $homeroomId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'version' => $version,
            'payload' => $payload,
        ]);

        $cloudResult = $response['data']['results'][0] ?? null;
        $cloudStatus = is_array($cloudResult) ? strtolower((string) ($cloudResult['status'] ?? '')) : '';
        if (!is_array($cloudResult) || (string) ($cloudResult['event_id'] ?? '') !== $eventId
            || !in_array($cloudStatus, ['accepted', 'duplicate'], true)) {
            throw new \RuntimeException('Broadcast ditolak/belum dikonfirmasi Cloud: '
                . (string) ($cloudResult['error_code'] ?? $cloudResult['error'] ?? 'MISSING_EVENT_RESULT'));
        }

        Log::info('LmsSyncSender: konten guru di-broadcast ke Sync Server.', [
            'school_id' => $schoolId,
            'source_device_id' => $deviceId,
            'event_id' => $eventId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'version' => $version,
            'target_homeroom_id' => $homeroomId,
        ]);

        return [
            'success' => true,
            'broadcast' => true,
            'school_id' => $schoolId,
            'source_device_id' => $deviceId,
            'event_id' => $eventId,
            'target_homeroom_id' => $homeroomId,
            'targets' => null,
            'sent' => 1,
            'failed' => 0,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'version' => $version,
            'status' => strtoupper($cloudStatus),
        ];
    }

    protected function buildEventId(string $syncId, string $action, int $version): string
    {
        $hasOutbox = LmsSyncRecord::query()
            ->where('entity_id', $syncId)
            ->where('operation', $action)
            ->where('version', $version)
            ->exists();

        if ($hasOutbox) {
            $syncId = trim((string) LmsSyncRecord::query()
                ->where('entity_id', $syncId)
                ->where('operation', $action)
                ->where('version', $version)
                ->orderByDesc('id')
                ->value('sync_id'));
            if ($syncId !== '') {
                return $syncId;
            }
        }

        return trim($syncId) . '::v' . $version;
    }
}