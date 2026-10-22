<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LmsSyncApplyService
{
    /*
     * [F1] Revisi Wave 1b:
     *  - [D3] attendance_session: MERGE PER-FIELD — null tidak menimpa;
     *    materi = milik absensi (ditulis hanya oleh event ini); CBT/
     *    tugas yang menulis dulu tidak lagi terhapus.
     *  - [AS-01] Ownership dilonggarkan sesuai intent FIX-RIWAYAT
     *    (File #1): event absensi diterima dari device guru sekolah
     *    mana pun (login terdaftar), penulis dicatat di log. Wali/
     *    admin tidak lagi dead-letter.
     *  - [AS-05] delete generic kini ber-LWW (versi lama tidak
     *    menghapus data lebih baru).
     * Kontrak dengan SyncApplier TIDAK berubah.
     */

    protected static array $columnCache = [];

    protected const ENTITY_TABLES = [
        'lms_course'    => 'lms_courses',
        'lms_topic'     => 'lms_topics',
        'lms_material'  => 'lms_materials',
        'lms_assignment'=> 'lms_assignments',
        'cbt_exam'      => 'exams',
    ];

    public function apply(array $change): array
    {
        $entityType = strtolower(trim((string) ($change['entity_type'] ?? $change['entity'] ?? '')));
        $action     = strtolower(trim((string) ($change['action'] ?? $change['operation'] ?? 'update')));
        $version    = max(1, (int) ($change['version'] ?? $change['sync_version'] ?? 1));
        $payload    = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $syncId     = trim((string) ($payload['sync_id'] ?? $change['entity_id'] ?? ''));

        if ($action === 'upsert') {
            $action = 'update';
        }

        if ($entityType === 'attendance_session') {
            return $this->applyAttendanceSession($syncId, $action, $version, $payload, $change);
        }

        if ($entityType === 'lms_assignment_submission') {
            return $this->applySubmission($syncId, $action, $version, $payload, (string) ($change['source_device_id'] ?? ''));
        }

        if (isset(self::ENTITY_TABLES[$entityType])) {
            return $this->applyGenericModel(
                self::ENTITY_TABLES[$entityType],
                $syncId,
                $action,
                $version,
                $payload
            );
        }

        Log::info('ApplyService: entity tidak dikenal, di-ACK tanpa apply.', [
            'entity_type' => $entityType,
            'sync_id'     => $syncId,
        ]);

        return $this->result($syncId, $action, 'unsupported_ignored');
    }

    /* ============================================================
     * GENERIC MODEL (LWW + [AS-05] delete LWW)
     * ============================================================ */

    protected function applyGenericModel(
        string $table,
        string $syncId,
        string $action,
        int $version,
        array $payload
    ): array {
        if ($syncId === '' || !$this->tableExists($table)) {
            return $this->result($syncId, $action, 'skipped_no_id_or_table');
        }

        $existing = DB::table($table)->where('sync_id', $syncId)->first();

        if ($action === 'delete') {
            if ($existing) {
                if (
                    $this->hasColumn($table, 'sync_version')
                    && (int) ($existing->sync_version ?? 0) > $version
                ) {
                    return $this->result($syncId, 'delete', 'skipped_old_version');
                }

                DB::table($table)->where('sync_id', $syncId)->delete();
            }

            return $this->result($syncId, 'delete', 'deleted');
        }

        if (
            $existing
            && $this->hasColumn($table, 'sync_version')
            && (int) ($existing->sync_version ?? 0) > $version
        ) {
            return $this->result($syncId, $action, 'skipped_old_version');
        }

        $data = $this->filterColumns($table, $payload);

        foreach (['created_at', 'updated_at', 'submitted_at', 'due_at', 'available_from'] as $timeKey) {
            if (array_key_exists($timeKey, $data)) {
                $data[$timeKey] = $this->normTime($data[$timeKey]);
            }
        }

        $data['sync_id'] = $syncId;

        if ($this->hasColumn($table, 'sync_version')) {
            $data['sync_version'] = $version;
        }

        if ($existing) {
            unset($data['created_at']);
            $data['updated_at'] = $this->normTime($payload['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s');

            DB::table($table)->where('sync_id', $syncId)->update($data);
        } else {
            $data['created_at'] = $this->normTime($payload['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s');
            $data['updated_at'] = $this->normTime($payload['updated_at'] ?? null) ?? $data['created_at'];

            DB::table($table)->insert($data);
        }

        return $this->result($syncId, $action, 'applied');
    }

    /* ============================================================
     * SUBMISSION — dua domain versi [P0-3 / D21]
     * ============================================================ */

    protected function applySubmission(
        string $syncId,
        string $action,
        int $version,
        array $payload,
        string $sourceDevice = ''
    ): array {
        // V21R7: never write an opaque Cloud alias directly into the local
        // submission table. Resolve against the registered class membership.
        if (CloudInboundStudentV21::enabled()) {
            $school = app(SchoolIdentity::class)->schoolId();
            if (preg_match('/^' . preg_quote($school, '/') . '-t_[a-z2-7]{26}-HP$/D', $sourceDevice)) {
                // Teacher-originated grade domain (not necessarily event_id grade::teacher).
                // A pre-existing submission is mandatory to prove the student's owner.
                $localSubmission = \App\Models\LmsAssignmentSubmission::query()
                    ->with('assignment.topic.course')->where('sync_id', $syncId)->first();
                if (!$localSubmission) {
                    throw new \RuntimeException('BLOCKED V21: grade inbound belum memiliki submission lokal.');
                }
                $localNisn = app(CloudInboundStudentV21::class)
                    ->localGradeNisn($payload, $localSubmission, $sourceDevice);
                $payload['student_id'] = $localNisn;
                unset($payload['student_nisn'], $payload['nisn'], $payload['student_nik']);
            } else {
                $payload = app(CloudInboundStudentV21::class)->normalizeSubmission(
                    $payload, $action, $sourceDevice
                );
            }
        }
        $table = 'lms_assignment_submissions';

        if ($syncId === '' || !$this->tableExists($table)) {
            return $this->result($syncId, $action, 'skipped_no_id_or_table');
        }

        $existing = DB::table($table)->where('sync_id', $syncId)->first();

        if ($action === 'delete') {
            if ($existing) {
                if (
                    $this->hasColumn($table, 'sync_version')
                    && (int) ($existing->sync_version ?? 0) > $version
                ) {
                    return $this->result($syncId, 'delete', 'skipped_old_version');
                }

                DB::table($table)->where('sync_id', $syncId)->delete();
            }

            return $this->result($syncId, 'delete', 'deleted');
        }

        $hasGradeVersion = isset($payload['grade_version']) && (int) $payload['grade_version'] > 0;
        $hasGuruFields =
            (array_key_exists('score', $payload) && $payload['score'] !== null)
            || array_key_exists('teacher_feedback', $payload)
            || array_key_exists('feedback', $payload)
            || strtolower((string) ($payload['status'] ?? '')) === 'graded';

        /*
         * DOMAIN GURU (grade event) — LWW pada grade_version.
         */
        if ($hasGradeVersion || ($hasGuruFields && $existing && $this->isGradeAhead($existing, $payload))) {
            $incomingGrade = max(1, (int) ($payload['grade_version'] ?? ($payload['sync_version'] ?? $version)));
            $localGrade = max(0, (int) ($existing->grade_version ?? 0));

            if ($existing && $incomingGrade < $localGrade) {
                return $this->result($syncId, $action, 'skipped_old_version');
            }

            $data = [];

            if (array_key_exists('score', $payload)) {
                $data['score'] = $payload['score'];
            }

            if (array_key_exists('teacher_feedback', $payload)) {
                $data['teacher_feedback'] = $payload['teacher_feedback'];
            } elseif (array_key_exists('feedback', $payload)) {
                $data['teacher_feedback'] = $payload['feedback'];
            }

            if (array_key_exists('score_type', $payload)) {
                $data['score_type'] = $payload['score_type'];
            }

            if (strtolower((string) ($payload['status'] ?? '')) === 'graded') {
                $data['status'] = 'graded';
            }

            $data['revision_unlocked_at'] = null;
            $data['grade_version'] = $incomingGrade;
            $data['sync_version'] = max($version, $incomingGrade);
            $data['updated_at'] = now()->format('Y-m-d H:i:s');

            if ($existing) {
                DB::table($table)->where('sync_id', $syncId)->update($data);
            } else {
                $data['sync_id'] = $syncId;
                $data['student_id'] = $payload['student_id'] ?? $payload['student_nisn'] ?? null;
                $data['assignment_sync_id'] = $payload['assignment_sync_id'] ?? null;
                $data['created_at'] = now()->format('Y-m-d H:i:s');

                DB::table($table)->insert($data);
            }

            return $this->result($syncId, $action, 'applied');
        }

        /*
         * DOMAIN SISWA (jawaban) — LWW pada answer_version.
         */
        $incomingAnswer = max(1, (int) ($payload['answer_version'] ?? $version));
        $localAnswer = max(0, (int) ($existing->answer_version ?? 0));

        if ($existing && $incomingAnswer < $localAnswer) {
            return $this->result($syncId, $action, 'skipped_old_version');
        }

        /*
         * [D21] Terkunci: sudah dinilai & guru belum membuka revisi.
         * Event di-ACK (tidak di-retry) — UI siswa memblokir edit;
         * ini jaring pengaman jalur API.
         */
        if (
            $existing
            && strtolower((string) ($existing->status ?? '')) === 'graded'
            && $existing->revision_unlocked_at === null
            && !$hasGuruFields
        ) {
            Log::warning('[APPLY] Revisi jawaban ditolak — tugas terkunci (guru belum membuka revisi).', [
                'sync_id' => $syncId,
            ]);

            return $this->result($syncId, $action, 'submission_locked');
        }

        $data = $this->filterColumns($table, $payload);

        /*
         * Field guru TIDAK pernah disentuh oleh jalur siswa.
         */
        unset($data['score'], $data['teacher_feedback'], $data['grade_version'], $data['score_type']);

        foreach (['created_at', 'updated_at', 'submitted_at'] as $timeKey) {
            if (array_key_exists($timeKey, $data)) {
                $data[$timeKey] = $this->normTime($data[$timeKey]);
            }
        }

        $data['sync_id'] = $syncId;
        $data['answer_version'] = $incomingAnswer;

        if ($this->hasColumn($table, 'sync_version')) {
            $data['sync_version'] = max($version, $incomingAnswer);
        }

        if ($existing) {
            unset($data['created_at']);
            $data['updated_at'] = $this->normTime($payload['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s');

            DB::table($table)->where('sync_id', $syncId)->update($data);
        } else {
            $data['created_at'] = $this->normTime($payload['created_at'] ?? null) ?? now()->format('Y-m-d H:i:s');
            $data['updated_at'] = $this->normTime($payload['updated_at'] ?? null) ?? $data['created_at'];

            DB::table($table)->insert($data);
        }

        return $this->result($syncId, $action, 'applied');
    }

    /** Heuristic: payload guru lebih baru daripada grade lokal? */
    protected function isGradeAhead($existing, array $payload): bool
    {
        $localGrade = max(0, (int) ($existing->grade_version ?? 0));
        $incoming = max(0, (int) ($payload['sync_version'] ?? 0));

        return $incoming > $localGrade;
    }

    /* ============================================================
     * ATTENDANCE SESSION — [D3] merge per-field + [AS-01] longgar
     * ============================================================ */

    protected function applyAttendanceSession(
        string $syncId,
        string $action,
        int $version,
        array $payload,
        array $change
    ): array {
        $action = strtolower(trim($action));

        if ($action === 'upsert') {
            $action = 'update';
        }

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            throw new \RuntimeException('Action attendance_session tidak valid: ' . $action);
        }

        $incomingVersion = max(1, (int) $version);

        if (isset($payload['sync_version']) && is_numeric($payload['sync_version'])) {
            $payloadVersion = (int) $payload['sync_version'];

            if ($payloadVersion > $incomingVersion) {
                $incomingVersion = $payloadVersion;
            }
        }

        $taId = isset($payload['teaching_assignment_id']) ? (int) $payload['teaching_assignment_id'] : 0;

        if ($taId <= 0) {
            throw new \RuntimeException('teaching_assignment_id tidak valid: ' . $taId);
        }

        $ta = DB::table('teaching_assignments')->where('id', $taId)->first();

        if (!$ta) {
            throw new \RuntimeException('Teaching assignment tidak ditemukan: ' . $taId);
        }

        /*
         * [AS-01] Sumber = device guru sekolah mana pun (login
         * terdaftar di users). Penulis dicatat — FIX-RIWAYAT menjanjikan
         * riwayat tampil di semua device guru, termasuk input admin.
         */
        $sourceDevice = trim((string) ($change['source_device_id'] ?? ''));

        if ($sourceDevice === '') {
            throw new \RuntimeException('source_device_id tidak tersedia pada attendance_session.');
        }

        $deviceBase = preg_replace('/-(PC|HP)$/i', '', $sourceDevice);
        $deviceParts = explode('-', (string) $deviceBase, 2);
        $npsnSegment = trim((string) ($deviceParts[0] ?? ''));
        $loginSegment = trim((string) ($deviceParts[1] ?? ''));

        if ($npsnSegment === '' || $loginSegment === '') {
            throw new \RuntimeException('Format source_device_id tidak valid: ' . $sourceDevice);
        }

        /*
         * [ATT-PENGAJAR-V25]
         * Paket GTK Kepala Sekolah / Guru BK yang mendapat penugasan mengajar
         * tidak selalu mempunyai row users lokal dengan login_id NIP/NIK yang
         * sama. Device tetap sah bila:
         *   1. NPSN device sama dengan sekolah lokal; dan
         *   2. login device cocok dengan NIP/NIK/NUPTK Teacher PEMILIK
         *      teaching_assignment_id yang sedang diterapkan.
         *
         * Jalur users lama tetap dipertahankan demi kompatibilitas. Fallback
         * Teacher ini sengaja tidak menerima guru lain yang kebetulan berada
         * di sekolah yang sama.
         */
        $localSchoolId = $this->tableExists('schools')
            ? trim((string) DB::table('schools')->value('npsn'))
            : '';
        if ($localSchoolId === '') {
            $localSchoolId = trim((string) (config('eduxid.school_id') ?: config('eduxid.npsn') ?: ''));
        }

        if ($localSchoolId !== '' && strcasecmp($localSchoolId, $npsnSegment) !== 0) {
            throw new \RuntimeException(
                'Attendance session ditolak: NPSN source device tidak cocok dengan sekolah lokal.'
            );
        }

        if (OpaqueRouteRegistryV21::enabled()) {
            $deviceSuffix = str_ends_with($sourceDevice, '-PC') ? 'PC' : 'HP';
            app(OpaqueTeacherRouteV21::class)->assertDeviceOwnedByTeacher(
                $sourceDevice, (int) ($ta->teacher_id ?? 0), $deviceSuffix
            );
        }

        $writerExists = OpaqueRouteRegistryV21::enabled()
            ? true // verified above using teacher_id + authoritative local alias map
            : DB::table('users')->where('login_id', $loginSegment)->exists();
        $assignmentTeacherMatches = false;
        $assignmentTeacher = null;

        if (!$writerExists && isset($ta->teacher_id) && (int) $ta->teacher_id > 0 && $this->tableExists('teachers')) {
            $teacherQuery = DB::table('teachers')->where('id', (int) $ta->teacher_id);

            if ($this->hasColumn('teachers', 'deleted_at')) {
                $teacherQuery->whereNull('deleted_at');
            }

            $assignmentTeacher = $teacherQuery->first();

            if ($assignmentTeacher) {
                $teacherLoginIds = array_values(array_filter(array_map(
                    static fn ($value) => trim((string) $value),
                    [
                        $assignmentTeacher->nip ?? null,
                        $assignmentTeacher->nik ?? null,
                        $assignmentTeacher->nuptk ?? null,
                    ]
                ), static fn ($value) => $value !== ''));

                foreach ($teacherLoginIds as $candidate) {
                    if (hash_equals($candidate, $loginSegment)) {
                        $assignmentTeacherMatches = true;
                        break;
                    }
                }
            }
        }

        if (!$writerExists && !$assignmentTeacherMatches) {
            throw new \RuntimeException(
                'Attendance session ditolak: device bukan akun/pengajar sah untuk penugasan ini. '
                . 'login_id=' . $loginSegment . '; teaching_assignment_id=' . $taId
            );
        }

        if (!$writerExists && $assignmentTeacherMatches) {
            Log::info('[ATT-PENGAJAR-V25] Attendance diterima melalui fallback Teacher + TeachingAssignment.', [
                'source_device_id' => $sourceDevice,
                'login_id' => $loginSegment,
                'teacher_id' => (int) ($assignmentTeacher->id ?? 0),
                'teaching_assignment_id' => $taId,
            ]);
        }

        $payloadTanggal = trim((string) ($payload['tanggal'] ?? ''));
        $payloadSyncId = trim((string) ($payload['sync_id'] ?? ''));

        /*
         * DELETE legacy memakai entity_id sebagai identitas tombstone yang
         * otoritatif. Ini penting untuk membersihkan riwayat lama yang pernah
         * mempunyai tanggal malformed dan kemudian terpotong saat payload
         * dinormalisasi. Nilai tanggal tidak diperbaiki atau ditebak.
         *
         * CREATE/UPDATE tetap wajib memakai tanggal kalender Y-m-d yang valid
         * dan canonical sync_id ta::{teaching_assignment_id}::{tanggal}.
         */
        if ($action === 'delete') {
            $prefix = 'ta::' . $taId . '::';

            if (!str_starts_with($syncId, $prefix)) {
                throw new \RuntimeException(
                    'entity_id attendance_session delete tidak konsisten dengan teaching_assignment_id. Received: '
                    . $syncId
                );
            }

            $tanggal = substr($syncId, strlen($prefix));

            if ($tanggal === '') {
                throw new \RuntimeException('Tanggal attendance_session delete tidak tersedia pada entity_id.');
            }

            if ($payloadSyncId !== '' && $payloadSyncId !== $syncId) {
                throw new \RuntimeException(
                    'sync_id attendance_session delete tidak konsisten. Expected: '
                    . $syncId . ', received: ' . $payloadSyncId
                );
            }
        } else {
            $tanggal = $payloadTanggal;

            if ($tanggal === '') {
                throw new \RuntimeException('Tanggal attendance_session tidak tersedia.');
            }

            try {
                $tanggalObj = \Carbon\Carbon::createFromFormat('!Y-m-d', $tanggal);
                $errors = \Carbon\Carbon::getLastErrors();

                if (
                    !$tanggalObj
                    || $tanggalObj->format('Y-m-d') !== $tanggal
                    || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
                ) {
                    throw new \RuntimeException();
                }
            } catch (Throwable) {
                throw new \RuntimeException('Format tanggal attendance_session tidak valid: ' . $tanggal);
            }

            $canonicalSyncId = 'ta::' . $taId . '::' . $tanggal;

            if ($payloadSyncId !== '' && $payloadSyncId !== $canonicalSyncId) {
                throw new \RuntimeException(
                    'sync_id attendance_session tidak konsisten. Expected: '
                    . $canonicalSyncId . ', received: ' . $payloadSyncId
                );
            }

            if ($syncId !== $canonicalSyncId) {
                throw new \RuntimeException(
                    'entity_id attendance_session tidak konsisten. Expected: '
                    . $canonicalSyncId . ', received: ' . $syncId
                );
            }
        }

        $sourceUpdatedAt = null;

        if (isset($payload['updated_at']) && trim((string) $payload['updated_at']) !== '') {
            try {
                $sourceUpdatedAt = \Carbon\Carbon::parse((string) $payload['updated_at']);
            } catch (Throwable) {
                $sourceUpdatedAt = null;
            }
        }

        if (!$sourceUpdatedAt) {
            $sourceUpdatedAt = now();
        }

        if ($action === 'delete') {
            return DB::transaction(function () use ($syncId, $incomingVersion, $taId, $tanggal, $sourceDevice, $ta) {
                $localVersion = max(
                    (int) (DB::table('app_settings')->where('key', 'attendance::deleted::' . $syncId)->value('value') ?? 0),
                    (int) (DB::table('attendances')
                        ->where('teaching_assignment_id', $taId)
                        ->where('tanggal', $tanggal)
                        ->max('sync_version') ?? 0),
                    (int) (DB::table('assessment_scores')
                        ->where('teaching_assignment_id', $taId)
                        ->where('tanggal', $tanggal)
                        ->max('sync_version') ?? 0)
                );

                if ($localVersion > 0 && $incomingVersion < $localVersion) {
                    return array_merge(
                        $this->result($syncId, 'delete', 'attendance_ignored_old_version'),
                        ['ignored' => true]
                    );
                }

                $attendanceDeleted = DB::table('attendances')
                    ->where('teaching_assignment_id', $taId)
                    ->where('tanggal', $tanggal)
                    ->delete();

                $scoreDeleted = DB::table('assessment_scores')
                    ->where('teaching_assignment_id', $taId)
                    ->where('tanggal', $tanggal)
                    ->delete();

                DB::table('app_settings')->upsert([[
                    'key' => 'attendance::deleted::' . $syncId, 'value' => (string) $incomingVersion,
                    'created_at' => now(), 'updated_at' => now(),
                ]], ['key'], ['value', 'updated_at']);
                WorkProductPushService::markDailyScope($taId, $incomingVersion);
                WorkProductPushService::markDirtyOrFail((int) $ta->teacher_id);

                Log::info('Attendance session dihapus via sync.', [
                    'sync_id' => $syncId,
                    'ta_id' => $taId,
                    'tanggal' => $tanggal,
                    'attendance_deleted' => $attendanceDeleted,
                    'scores_deleted' => $scoreDeleted,
                    'source_device_id' => $sourceDevice,
                ]);

                return array_merge(
                    $this->result($syncId, 'delete', 'attendance_deleted'),
                    ['attendance_deleted' => $attendanceDeleted, 'assessment_scores_deleted' => $scoreDeleted]
                );
            });
        }

        $deletedVersion = (int) (DB::table('app_settings')
            ->where('key', 'attendance::deleted::' . $syncId)->value('value') ?? 0);
        if ($deletedVersion >= $incomingVersion) {
            return $this->result($syncId, $action, 'skipped_old_version');
        }
        // Pengiriman lama memakai null = tidak dikirim; form baru menyebut
        // field yang sengaja diganti, sehingga guru dapat mengosongkan nilai.
        $replaceFields = array_flip(is_array($payload['replace_fields'] ?? null) ? $payload['replace_fields'] : []);

        $students = is_array($payload['students'] ?? null) ? $payload['students'] : [];

        if ($students === []) {
            throw new \RuntimeException('attendance_session tidak lengkap: students kosong.');
        }

        $materi = $payload['materi'] ?? null;
        $jamKe = $payload['jam_ke'] ?? null;
        $pukul = $payload['pukul'] ?? null;

        $attendanceCount = 0;
        $scoreCount = 0;
        $attendanceSkipped = 0;
        $scoreSkipped = 0;

        $attendanceHasSyncVersion = Schema::hasColumn('attendances', 'sync_version');
        $scoreHasSyncVersion = Schema::hasColumn('assessment_scores', 'sync_version');

        DB::transaction(function () use (
            &$attendanceCount, &$scoreCount, &$attendanceSkipped, &$scoreSkipped,
            $students, $taId, $tanggal, $materi, $jamKe, $pukul, $payload, $replaceFields, $ta,
            $incomingVersion, $sourceUpdatedAt,
            $attendanceHasSyncVersion, $scoreHasSyncVersion
        ) {
            foreach ($students as $student) {
                if (!is_array($student)) {
                    throw new \RuntimeException('Baris siswa attendance_session tidak valid.');
                }

                $studentId = (int) ($student['student_id'] ?? 0);

                if ($studentId <= 0 || !DB::table('enrollments')->where('student_id', $studentId)
                    ->where('homeroom_id', $ta->homeroom_id)->exists()) {
                    throw new \RuntimeException('ENROLLMENT_MISSING: siswa pertemuan belum terdaftar di rombel.');
                }

                $status = in_array($student['status'] ?? '', ['Hadir', 'Sakit', 'Izin', 'Alpa'], true)
                    ? $student['status']
                    : 'Hadir';

                /* ---------------- ABSENSI (tetap penuh) ---------------- */

                $att = DB::table('attendances')
                    ->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $taId)
                    ->where('tanggal', $tanggal)
                    ->first();

                $localAttendanceVersion = ($att && $attendanceHasSyncVersion)
                    ? max(0, (int) ($att->sync_version ?? 0))
                    : 0;

                if ($att && $attendanceHasSyncVersion && $incomingVersion < $localAttendanceVersion) {
                    $attendanceSkipped++;
                } else {
                    $attendanceData = [
                        'status' => $status,
                        'updated_at' => $sourceUpdatedAt,
                    ];

                    if (!$att) {
                        $attendanceData['created_at'] = $sourceUpdatedAt;
                    }

                    if ($attendanceHasSyncVersion) {
                        $attendanceData['sync_version'] = $incomingVersion;
                    }

                    DB::table('attendances')->updateOrInsert(
                        [
                            'student_id' => $studentId,
                            'teaching_assignment_id' => $taId,
                            'tanggal' => $tanggal,
                        ],
                        $attendanceData
                    );

                    $attendanceCount++;
                }

                /* ------------- NILAI: [D3] MERGE PER-FIELD ------------- */

                $score = DB::table('assessment_scores')
                    ->where('student_id', $studentId)
                    ->where('teaching_assignment_id', $taId)
                    ->where('tanggal', $tanggal)
                    ->first();

                $localScoreVersion = ($score && $scoreHasSyncVersion)
                    ? max(0, (int) ($score->sync_version ?? 0))
                    : 0;

                if ($score && $scoreHasSyncVersion && $incomingVersion < $localScoreVersion) {
                    $scoreSkipped++;
                    continue;
                }

                /*
                 * HANYA field yang benar-benar DIBAWA payload yang
                 * ditulis. Null = "tidak dikirim" → data CBT/tugas
                 * milik penulis lain AMAN. d1..d8 juga null-guarded
                 * (bukan lagi default 3 atas baris existing).
                 */
                $scoreUpdate = [];

                foreach (['diagnostik', 'formatif', 'sumatif'] as $col) {
                    if (array_key_exists($col, $student)
                        && (isset($replaceFields[$col]) || ($student[$col] !== null && $student[$col] !== ''))) {
                        $scoreUpdate[$col] = $student[$col] === null || $student[$col] === '' ? null : (int) $student[$col];
                    }
                }

                for ($i = 1; $i <= 8; $i++) {
                    $key = 'd' . $i;

                    if (array_key_exists($key, $student) && $student[$key] !== null && $student[$key] !== '') {
                        $scoreUpdate[$key] = (int) $student[$key];
                    }
                }

                if (array_key_exists('catatan_siswa', $student)
                    && ($student['catatan_siswa'] !== null || isset($replaceFields['catatan_siswa']))) {
                    $scoreUpdate['catatan_siswa'] = trim((string) $student['catatan_siswa']) ?: null;
                }

                /*
                 * [D3] materi = milik absensi pertemuan. Baris existing
                 * → materi ditulis hanya bila payload membawanya
                 * (non-null, non-empty). Baris baru → lengkap.
                 */
                if ($score) {
                    if (isset($replaceFields['materi']) || ($materi !== null && trim((string) $materi) !== '')) {
                        $scoreUpdate['materi'] = $materi;
                    }
                    foreach (['jam_ke', 'pukul'] as $field) {
                        if (array_key_exists($field, $payload)
                            && (isset($replaceFields[$field]) || $payload[$field] !== null)) {
                            $scoreUpdate[$field] = $payload[$field];
                        }
                    }

                    if ($scoreUpdate === []) {
                        // Tidak ada field nilai baru — jangan bump versi
                        // (hindari attendance memenangkan LWW atas CBT
                        // tanpa perubahan isi).
                        $scoreCount++;
                        continue;
                    }

                    $scoreUpdate['updated_at'] = $sourceUpdatedAt;

                    if ($scoreHasSyncVersion) {
                        $scoreUpdate['sync_version'] = $incomingVersion;
                    }

                    DB::table('assessment_scores')
                        ->where('id', $score->id)
                        ->update($scoreUpdate);

                    $scoreCount++;
                } else {
                    $scoreData = [
                        'student_id' => $studentId,
                        'teaching_assignment_id' => $taId,
                        'tanggal' => $tanggal,
                        'materi' => $materi,
                        'jam_ke' => $jamKe,
                        'pukul' => $pukul,
                        'diagnostik' => (isset($student['diagnostik']) && $student['diagnostik'] !== '' && $student['diagnostik'] !== null)
                            ? (int) $student['diagnostik'] : null,
                        'formatif' => (isset($student['formatif']) && $student['formatif'] !== '' && $student['formatif'] !== null)
                            ? (int) $student['formatif'] : null,
                        'sumatif' => (isset($student['sumatif']) && $student['sumatif'] !== '' && $student['sumatif'] !== null)
                            ? (int) $student['sumatif'] : null,
                        'catatan_siswa' => trim((string) ($student['catatan_siswa'] ?? '')) ?: null,
                        'updated_at' => $sourceUpdatedAt,
                    ];

                    for ($i = 1; $i <= 8; $i++) {
                        $key = 'd' . $i;
                        $scoreData[$key] = (isset($student[$key]) && $student[$key] !== '' && $student[$key] !== null)
                            ? (int) $student[$key]
                            : 3; // SIKAP_DEFAULT — hanya untuk baris BARU.
                    }

                    $scoreData['created_at'] = $sourceUpdatedAt;

                    if ($scoreHasSyncVersion) {
                        $scoreData['sync_version'] = $incomingVersion;
                    }

                    DB::table('assessment_scores')->insert($scoreData);

                    $scoreCount++;
                }
            }
            WorkProductPushService::markDirtyOrFail((int) $ta->teacher_id);
        });

        /*
         * PWA/HP adalah salah satu sumber resmi Buku Nilai. Setelah
         * attendance_session berhasil diterapkan, laptop WAJIB menandai
         * work-product guru sebagai dirty agar snapshot daily_grade baru
         * dipush kembali ke Cloud dan dapat diterima PWA/perangkat guru lain.
         * Tidak ada loop: daily_grade yang diterima dari Cloud tidak memanggil
         * markDirty, hanya attendance_session dari perangkat lain yang memicu.
         */


        Log::info('Attendance session diterapkan (merge per-field).', [
            'sync_id' => $syncId,
            'ta_id' => $taId,
            'tanggal' => $tanggal,
            'action' => $action,
            'incoming_version' => $incomingVersion,
            'source_device_id' => $change['source_device_id'] ?? null,
            'attendance_count' => $attendanceCount,
            'attendance_skipped' => $attendanceSkipped,
            'score_count' => $scoreCount,
            'score_skipped' => $scoreSkipped,
        ]);

        return array_merge(
            $this->result($syncId, $action, 'attendance_applied'),
            [
                'teaching_assignment_id' => $taId,
                'tanggal' => $tanggal,
                'attendance_count' => $attendanceCount,
                'assessment_score_count' => $scoreCount,
                'attendance_skipped' => $attendanceSkipped,
                'assessment_score_skipped' => $scoreSkipped,
                'incoming_version' => $incomingVersion,
                'source_updated_at' => $sourceUpdatedAt->toIso8601String(),
                'lww' => true,
            ]
        );
    }

    /* ============================================================
     * HELPERS (tetap)
     * ============================================================ */

    protected function result(string $syncId, string $action, string $status): array
    {
        return [
            'sync_id' => $syncId,
            'action'  => $action,
            'status'  => $status,
        ];
    }

    protected function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    /** @return string[] */
    protected function columns(string $table): array
    {
        if (!array_key_exists($table, self::$columnCache)) {
            try {
                self::$columnCache[$table] = Schema::getColumnListing($table);
            } catch (Throwable) {
                self::$columnCache[$table] = [];
            }
        }

        return self::$columnCache[$table];
    }

    protected function filterColumns(string $table, array $payload): array
    {
        $cols = $this->columns($table);
        $out = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $cols, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    protected function normTime($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}