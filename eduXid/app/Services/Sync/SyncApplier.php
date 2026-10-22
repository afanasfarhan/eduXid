<?php

namespace App\Services\Sync;

use App\Models\LmsAssignmentSubmission;
use App\Models\LmsSyncRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncApplier
{
    /*
     * [F1] Revisi Wave 1b:
     *  - [P3-1] Limit retry BERBASIS WAKTU (usia event), bukan siklus.
     *  - [D4] Grade dari HP guru → juga menulis nilai harian
     *    (DailyAssessmentWriter) + markDirty — paritas penuh.
     *  - [D19] Apply-ACK balik ke HP guru (terapan / ditolak LWW /
     *    ditunda) untuk event grade & attendance dari -HP.
     *  - [D21] Handler submission_unlock (guru membuka revisi).
     */

    /** Usia maksimum event inbound sebelum dead-letter. */
    public const RETRY_MAX_AGE_SECONDS = 24 * 3600;

    /** Jawaban CBT tanpa exam: pulih bila exam belum sinkron — 6 jam. */
    public const CBT_INGEST_MAX_AGE_SECONDS = 6 * 3600;

    /** Simpan snapshot kasus recovery LJK sampai 72 jam, kemudian dead-letter agar tidak retry abadi. */
    public const LJK_RECOVERY_MAX_AGE_SECONDS = 72 * 3600;

    /* ---- Kontrak outcome untuk daemon (nilai string) ---- */
    public const OUTCOME_APPLIED           = 'applied';
    public const OUTCOME_SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const OUTCOME_SKIPPED_STALE     = 'skipped_stale';
    public const OUTCOME_RETRY_TRANSIENT   = 'retry_transient';
    public const OUTCOME_DEAD_LETTER       = 'dead_letter';

    protected const NEVER_DEAD_MARKERS = [
        'TARGET_DEVICE_NOT_FOUND',
        'NO_BROADCAST_RECIPIENTS',
        'BLOCKED V21', // staging: jangan ACK event sebelum protokol anonim benar.
    ];

    public function __construct(
        protected LmsSyncApplyService $applyService,
        protected WorkProductApplier $workProducts,
        protected CbtCorrectionService $cbtCorrection,
        protected LmsSyncService $syncService,
        protected LjkExamRecoveryService $ljkRecovery,
        protected SyncDeadLetterService $deadLetters
    ) {
    }

    public function apply(array $change, string $deviceId): array
    {
        $eventId  = trim((string) ($change['event_id'] ?? ''));
        $entity   = strtolower(trim((string) ($change['entity_type'] ?? $change['entity'] ?? '')));
        $payload  = is_array($change['payload'] ?? null) ? $change['payload'] : [];
        $entityId = trim((string) ($change['entity_id'] ?? $change['sync_id'] ?? ($payload['sync_id'] ?? '')));
        $action   = $this->normalizeAction((string) ($change['action'] ?? $change['operation'] ?? 'update'));
        $version  = max(1, (int) ($change['version'] ?? $change['sync_version'] ?? $payload['sync_version'] ?? 1));

        // [V21R16-STAGING] Direct applyChanges callers also fail closed.
        // Never record sealed LJK as unknown/dead-letter, duplicate, or manually ignored.
        if (CloudLjkInboundQuarantineV21::isProtected($change)) {
            return $this->outcome(self::OUTCOME_RETRY_TRANSIENT,
                'BLOCKED V21R16: sealed LJK menunggu consumer staging setelah commit');
        }

        if ($eventId === '' || $entity === '') {
            $this->deadLetters->recordDead($change, $deviceId, 'Format event tidak valid (event_id/entity kosong).');

            return $this->outcome(self::OUTCOME_DEAD_LETTER, 'format_tidak_valid');
        }

        /*
         * [SYNC-CLEANUP-V24] Event lama tertentu dapat ditandai manual sebagai
         * artefak yang aman dibuang. Daemon tidak memproses payload lagi; event
         * dikategorikan stale supaya LmsSyncEngine meng-ACK ke Cloud secara normal.
         * Ini sengaja event-specific, bukan aturan global LJK.
         */
        if ($this->isManuallyIgnoredEvent($eventId, $deviceId)) {
            $this->deadLetters->resolve($eventId, $deviceId);

            Log::warning('[SYNC-CLEANUP] Artefak sinkron diabaikan manual dan akan di-ACK.', [
                'event_id' => $eventId,
                'device_id' => $deviceId,
                'entity_type' => $entity,
            ]);

            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'manual_artifact_cleanup');
        }

        $category = SyncEventClassifier::category($entity);

        if ($category === SyncEventClassifier::UNKNOWN) {
            $this->deadLetters->recordDead($change, $deviceId, "Entity tidak dikenal: {$entity} — tidak ada handler.");

            return $this->outcome(self::OUTCOME_DEAD_LETTER, 'entity_tidak_dikenal');
        }

        if ($this->alreadyApplied($eventId)) {
            $this->deadLetters->resolve($eventId, $deviceId);

            return $this->outcome(self::OUTCOME_SKIPPED_DUPLICATE, 'sudah_diterapkan');
        }

        try {
            // V21R8 — tahan event yang belum dapat diterapkan tanpa risiko
            // salah pemilik atau kebocoran. Tidak menulis ledger ataupun ACK.
            CloudAssessmentBoundaryV21::inbound($entity, $payload);
            /*
             * attendance_session memang teacher-routed untuk DISTRIBUSI Cloud,
             * tetapi pada laptop penerima harus diterapkan oleh LmsSyncApplyService
             * karena handler applyAttendanceSession() berada di sana, bukan di
             * WorkProductApplier. Memisahkan klasifikasi distribusi dari dispatcher
             * apply mencegah event HP -> laptop ditolak sebagai "bukan work product".
             */
            if ($entity === 'attendance_session') {
                return $this->applyLmsEntity(
                    $change,
                    $deviceId,
                    $eventId,
                    $entity,
                    $entityId,
                    $action,
                    $version,
                    $payload
                );
            }

            // V21R129 STAGING: teacher approval must commit on PC, not through
            // the generic work-product writer or any student-origin event.
            if ($entity === 'arena_grade_request') {
                app(ArenaGradeCommitService::class)->apply(
                    $change, $deviceId, $this->syncService->getSchoolId()
                );
                $this->recordLedger($change, $deviceId, $entity, $entityId, $action, $version, $payload);
                return $this->outcome(self::OUTCOME_APPLIED, 'arena_formative_written_receipt_confirmed');
            }

            if ($category === SyncEventClassifier::TEACHER_ROUTED) {
                return $this->applyWorkProduct($change, $deviceId, $entity, $entityId, $action, $version, $payload);
            }

            return $this->applyLmsEntity($change, $deviceId, $eventId, $entity, $entityId, $action, $version, $payload);
        } catch (Throwable $e) {
            return $this->classifyFailure($change, $deviceId, $e);
        }
    }

    /* ============================================================
     * LMS ENTITY
     * ============================================================ */

    protected function applyLmsEntity(
        array $change,
        string $deviceId,
        string $eventId,
        string $entity,
        string $entityId,
        string $action,
        int $version,
        array $payload
    ): array {
        /* [D21] Guru membuka kunci revisi jawaban siswa. */
        if ($entity === 'submission_unlock') {
            return $this->applySubmissionUnlock($change, $deviceId, $payload);
        }

        /* [D19] ACK balik dari laptop lain — di-ACK tanpa aksi. */
        if ($entity === 'apply_ack') {
            return $this->outcome(self::OUTCOME_APPLIED, 'apply_ack_dicatat');
        }

        if (
            $entity === 'lms_assignment_submission'
            && strtolower(trim((string) ($payload['entity'] ?? ''))) === 'ljk_exam_recovery'
        ) {
            return $this->applyLjkExamRecovery(
                $change,
                $deviceId,
                $eventId,
                $entityId,
                $action,
                $version,
                $payload
            );
        }

        if ($entity === 'lms_assignment_submission'
            && strtolower(trim((string) ($payload['entity'] ?? ''))) === 'ljk_scan_result'
            && (str_starts_with((string) ($payload['exam_sync_id'] ?? ''), 'manual::')
                || str_starts_with((string) ($payload['manual_session_id'] ?? ''), 'manual::'))
        ) {
            return $this->applyLjkStandaloneManual($change, $deviceId, $eventId, $entityId, $action, $version, $payload);
        }

        if (
            $entity === 'lms_assignment_submission'
            && strtolower(trim((string) ($payload['entity'] ?? ''))) === 'ljk_scan_result'
        ) {
            return $this->applyLjkScanResult(
                $change,
                $deviceId,
                $eventId,
                $entityId,
                $action,
                $version,
                $payload
            );
        }

        if ($entity === 'lms_assignment_submission' && str_starts_with($eventId, 'grade::teacher::')) {
            return $this->applyTeacherGrade($change, $deviceId, $payload);
        }

        if ($entity === 'cbt_answer') {
            return $this->applyCbtAnswer($change, $deviceId, $action, $payload);
        }

        $normalized = $entity === 'attendance_session'
            ? $this->normalizeAttendanceChange($change)
            : $this->normalizeGenericChange($change);

        $result = $this->applyService->apply($normalized);
        $status = strtolower((string) ($result['status'] ?? ''));

        if ($status === 'skipped_no_id_or_table') {
            return $this->deadNow($change, $deviceId, "Referensi/tabel tidak tersedia untuk {$entity}.");
        }

        if ($status === 'unsupported_ignored') {
            return $this->deadNow($change, $deviceId, "Entity {$entity} tidak didukung ApplyService.");
        }

        if ($status === 'skipped_old_version') {
            $this->maybeSendApplyAck($change, 'stale_lww');

            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'versi_lebih_lama');
        }

        $this->recordLedger($change, $deviceId, $entity, $entityId, $action, $version, $payload);

        /* [D19] Laporkan hasil apply ke HP pengirim. */
        $this->maybeSendApplyAck($change, 'applied');

        return $this->outcome(self::OUTCOME_APPLIED, $status !== '' ? $status : 'applied');
    }

    /* ============================================================
     * WORK PRODUCT
     * ============================================================ */

    protected function applyWorkProduct(
        array $change,
        string $deviceId,
        string $entity,
        string $entityId,
        string $action,
        int $version,
        array $payload
    ): array {
        // V21R9: Cloud event / ledger stays alias-only. WorkProductApplier
        // receives a scoped local COPY with student_id resolved via registry.
        $localChange = $change;
        if (OpaqueRouteRegistryV21::enabled() && CloudWorkProductAliasV21::supports($entity)) {
            $source = trim((string) ($change['source_device_id'] ?? ''));
            if ($source === '') {
                throw new \RuntimeException('BLOCKED V21: sumber snapshot nilai wajib berasal dari Worker.');
            }
            $localChange['payload'] = app(CloudWorkProductAliasV21::class)->inbound($entity, $payload, $source);
        }
        // V21R10: rekap rapor menggunakan alias dalam ledger Cloud,
        // tetapi WorkProductApplier hanya menerima salinan ID siswa lokal.
        // Source device PC wali diverifikasi sebelum menyentuh enrollments.
        if (OpaqueRouteRegistryV21::enabled() && CloudHomeroomRaporAliasV21::supports($entity)) {
            if ($action === 'delete') {
                throw new \RuntimeException('BLOCKED V21: delete rekap rapor tanpa kontrak pemilik.');
            }
            $source = trim((string) ($change['source_device_id'] ?? ''));
            if ($source === '') {
                throw new \RuntimeException('BLOCKED V21: sumber rekap rapor dari Worker tidak tersedia.');
            }
            $localChange['payload'] = app(CloudHomeroomRaporAliasV21::class)->inbound($payload, $source);
        }
        $result = $this->workProducts->apply($localChange);

        if (($result['applied'] ?? false) === true) {
            $this->recordLedger($change, $deviceId, $entity, $entityId, $action, $version, $payload);

            return $this->outcome(self::OUTCOME_APPLIED, 'work_product_diterapkan');
        }

        $reason = (string) ($result['reason'] ?? 'unknown');

        if (str_starts_with($reason, 'stale/duplicate')) {
            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'sudah_diterapkan_wpa');
        }

        throw new \RuntimeException($reason);
    }

    /* ============================================================
     * LJK PWA GURU (HP → laptop)
     * ============================================================ */

    /**
     * Resolve Paket Ujian LJK secara toleran terhadap event lama.
     *
     * Snapshot privat ke PWA memakai:
     *   sync_id      = ljkpkg::<exam_sync_id>
     *   exam_sync_id = <exam_sync_id>
     *
     * Event versi lama dapat keliru membawa sync_id snapshot privat sebagai
     * exam_sync_id. Resolver ini menerima kedua bentuk dan fallback ke exam_id
     * lokal, lalu caller selalu menormalkan kembali ke Exam::sync_id kanonik.
     *
     * @return array{exam:?\App\Models\Exam,incoming:string,canonical:string,resolved_by:string,candidates:array<int,string>}
     */
    protected function resolveLjkExam(array $payload): array
    {
        $incoming = trim((string) (
            $payload['exam_sync_id']
            ?? $payload['exam']['sync_id']
            ?? ''
        ));

        $packageSyncId = trim((string) (
            $payload['package_sync_id']
            ?? $payload['private_package_sync_id']
            ?? ''
        ));

        $candidates = [];

        $add = static function (?string $value) use (&$candidates): void {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            $candidates[] = $value;

            if (str_starts_with($value, 'ljkpkg::')) {
                $canonical = trim(substr($value, strlen('ljkpkg::')));

                if ($canonical !== '') {
                    $candidates[] = $canonical;
                }
            }
        };

        $add($incoming);
        $add($packageSyncId);

        /*
         * Beberapa payload lama menaruh id snapshot pada field sync_id.
         * Hanya ambil bila jelas berprefix ljkpkg:: agar scan_id tidak
         * keliru dianggap exam sync id.
         */
        $payloadSyncId = trim((string) ($payload['sync_id'] ?? ''));

        if (str_starts_with($payloadSyncId, 'ljkpkg::')) {
            $add($payloadSyncId);
        }

        $candidates = array_values(array_unique(array_filter(
            $candidates,
            static fn ($v) => trim((string) $v) !== ''
        )));

        foreach ($candidates as $candidate) {
            $exam = \App\Models\Exam::query()
                ->with('questions')
                ->where('sync_id', $candidate)
                ->first();

            if ($exam) {
                return [
                    'exam' => $exam,
                    'incoming' => $incoming,
                    'canonical' => trim((string) $exam->sync_id),
                    'resolved_by' => $candidate === $incoming
                        ? 'exam_sync_id'
                        : 'normalized_package_sync_id',
                    'candidates' => $candidates,
                    'question_id_map' => $this->ljkRecovery->storedQuestionMap(trim((string) $exam->sync_id)),
                    'recovered_by' => null,
                ];
            }
        }

        /*
         * Fallback aman untuk snapshot privat yang berasal dari laptop yang
         * sama. exam_id pada ljk_private_package adalah local exams.id.
         * Ownership tetap diverifikasi setelah exam ditemukan.
         */
        $examId = (int) ($payload['exam_id'] ?? $payload['exam']['id'] ?? 0);

        if ($examId > 0) {
            $exam = \App\Models\Exam::query()
                ->with('questions')
                ->find($examId);

            if ($exam) {
                $localSyncId = trim((string) $exam->sync_id);
                $incomingCanonical = $incoming;
                if (str_starts_with($incomingCanonical, 'ljkpkg::')) {
                    $incomingCanonical = trim(substr($incomingCanonical, strlen('ljkpkg::')));
                }

                /*
                 * exam_id adalah ID lokal dari PC asal. Pada laptop GTK baru ID
                 * yang sama dapat menunjuk Paket Ujian lain. Fallback hanya aman
                 * bila event tidak membawa sync_id atau sync_id lokal masih kosong/
                 * sama dengan kandidat event.
                 */
                if (
                    $incomingCanonical !== ''
                    && $localSyncId !== ''
                    && !in_array($localSyncId, $candidates, true)
                    && strcasecmp($localSyncId, $incomingCanonical) !== 0
                ) {
                    $exam = null;
                }
            }

            if ($exam) {
                return [
                    'exam' => $exam,
                    'incoming' => $incoming,
                    'canonical' => trim((string) $exam->sync_id),
                    'resolved_by' => 'exam_id',
                    'candidates' => $candidates,
                    'question_id_map' => $this->ljkRecovery->storedQuestionMap(trim((string) $exam->sync_id)),
                    'recovered_by' => null,
                ];
            }
        }

        return [
            'exam' => null,
            'incoming' => $incoming,
            'canonical' => '',
            'resolved_by' => 'not_found',
            'candidates' => $candidates,
            'question_id_map' => [],
            'recovered_by' => null,
        ];
    }

    /**
     * Pastikan Paket Ujian yang mulai dipakai jalur LJK memiliki sync_id
     * permanen. Mengikuti pola ensureSyncId() eduXid: UUID dibuat sekali
     * lalu disimpan ke model.
     */
    protected function ensureLjkExamCanonicalSyncId(
        \App\Models\Exam $exam,
        string $eventId,
        string $scanId
    ): string {
        $current = trim((string) $exam->sync_id);

        if ($current !== '') {
            return $current;
        }

        $canonical = '';

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $exam,
            $eventId,
            $scanId,
            &$canonical
        ): void {
            $locked = \App\Models\Exam::query()
                ->whereKey((int) $exam->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new \RuntimeException(
                    'Paket Ujian LJK hilang saat membentuk sync_id kanonik. exam_id=' . $exam->id
                );
            }

            $canonical = trim((string) $locked->sync_id);

            if ($canonical === '') {
                $canonical = (string) \Illuminate\Support\Str::uuid();

                $values = [
                    'sync_id' => $canonical,
                    'updated_at' => now(),
                ];

                if (array_key_exists('sync_version', $locked->getAttributes())) {
                    $values['sync_version'] = max(
                        1,
                        (int) ($locked->sync_version ?? 0)
                    ) + 1;
                }

                $locked->forceFill($values)->save();

                Log::warning('[LJK-SYNC] Paket Ujian lokal diberi sync_id permanen.', [
                    'event_id' => $eventId,
                    'scan_id' => $scanId,
                    'exam_id' => $locked->id,
                    'exam_sync_id' => $canonical,
                    'sync_version' => $values['sync_version'] ?? null,
                ]);
            }
        });

        if ($canonical === '') {
            throw new \RuntimeException(
                'Gagal membentuk sync_id kanonik Paket Ujian LJK. exam_id=' . $exam->id
            );
        }

        $exam->forceFill(['sync_id' => $canonical]);
        $exam->loadMissing('questions');

        return $canonical;
    }


    /** [LJK-RECOVERY-V23] Snapshot paket dikirim ulang PWA Guru -> PC. */
    protected function applyLjkExamRecovery(
        array $change,
        string $deviceId,
        string $eventId,
        string $entityId,
        string $action,
        int $version,
        array $payload
    ): array {
        if ($action === 'delete') {
            return $this->deadNow($change, $deviceId, 'ljk_exam_recovery delete tidak didukung.');
        }

        $result = $this->ljkRecovery->materializeFromPwa($payload, $deviceId, $version);
        $exam = $result['exam'] ?? null;

        if (!$exam) {
            throw new \RuntimeException(
                'Recovery Paket Ujian LJK dari PWA gagal: '
                . (string) ($result['error'] ?? 'alasan tidak diketahui')
            );
        }

        $this->recordLedger(
            $change,
            $deviceId,
            'lms_assignment_submission',
            $entityId !== '' ? $entityId : ('ljk-recovery::' . (string) $exam->sync_id),
            'update',
            $version,
            $payload
        );
        $this->maybeSendApplyAck($change, 'applied');

        Log::warning('[LJK-RECOVERY] Snapshot PWA berhasil memulihkan Paket Ujian.', [
            'event_id' => $eventId,
            'exam_id' => $exam->id,
            'exam_sync_id' => $exam->sync_id,
            'receiver_device_id' => $deviceId,
            'source' => $result['source'] ?? 'pwa_recovery_response',
        ]);

        return $this->outcome(self::OUTCOME_APPLIED, 'ljk_exam_recovery_diterapkan');
    }

    /**
     * Bila snapshot Cloud sudah terhapus/tertimpa, minta PWA Guru yang masih
     * memiliki snapshot lokal untuk mengirim ulang paket privat yang tepat.
     * Request di-throttle 60 detik supaya daemon 5 detik tidak membanjiri HP.
     */
    protected function requestLjkExamRecoveryFromPwa(
        array $change,
        string $deviceId,
        array $payload,
        array $resolvedExam
    ): bool {
        try {
            $schoolId = trim((string) $this->syncService->getSchoolId());
            $sourceDeviceId = trim((string) (
                $change['source_device_id']
                ?? $change['sender_device_id']
                ?? $payload['source_device_id']
                ?? $payload['sender_device_id']
                ?? ''
            ));

            if ($sourceDeviceId === '' && str_ends_with(strtoupper($deviceId), '-PC')) {
                $sourceDeviceId = substr($deviceId, 0, -3) . '-HP';
            }

            if (
                $schoolId === ''
                || $sourceDeviceId === ''
                || !str_starts_with(strtoupper($sourceDeviceId), strtoupper($schoolId . '-'))
                || !str_ends_with(strtoupper($sourceDeviceId), '-HP')
            ) {
                return false;
            }

            $examSyncId = trim((string) ($resolvedExam['incoming'] ?? $payload['exam_sync_id'] ?? ''));
            if (str_starts_with($examSyncId, 'ljkpkg::')) {
                $examSyncId = trim(substr($examSyncId, strlen('ljkpkg::')));
            }
            if ($examSyncId === '') {
                return false;
            }

            $throttleKey = 'sync::ljk_recovery_request::' . hash('sha256', $examSyncId . '|' . $sourceDeviceId);
            $lastRaw = DB::table('app_settings')->where('key', $throttleKey)->value('value');
            $lastTs = is_numeric($lastRaw) ? (int) $lastRaw : 0;
            $nowTs = time();
            if ($lastTs > 0 && ($nowTs - $lastTs) < 60) {
                return true;
            }

            DB::table('app_settings')->updateOrInsert(
                ['key' => $throttleKey],
                ['value' => (string) $nowTs, 'updated_at' => now(), 'created_at' => now()]
            );

            $refEventId = trim((string) ($change['event_id'] ?? ''));
            $scanId = trim((string) ($payload['scan_id'] ?? $payload['sync_id'] ?? $change['entity_id'] ?? ''));
            $ackSyncId = 'ljk-recovery-request::' . hash('sha256', $examSyncId . '|' . $sourceDeviceId . '|' . $nowTs);

            $this->syncService->pendingAckViaSync($ackSyncId, [
                'ref_event_id' => $refEventId !== '' ? $refEventId : ('ljk::recovery::v' . $nowTs),
                'entity_type' => 'lms_assignment_submission',
                'entity_id' => $scanId,
                'apply_status' => 'request_ljk_package',
                'exam_sync_id' => $examSyncId,
                'exam_id' => (int) ($payload['exam_id'] ?? 0),
                'teacher_login_id' => $payload['teacher_login_id'] ?? null,
                'requested_at' => now()->toIso8601String(),
                'requested_by' => $deviceId,
            ], $sourceDeviceId);

            Log::warning('[LJK-RECOVERY] Meminta snapshot Paket Ujian ke PWA Guru.', [
                'event_id' => $refEventId,
                'exam_sync_id' => $examSyncId,
                'target_device_id' => $sourceDeviceId,
                'receiver_device_id' => $deviceId,
            ]);
            return true;
        } catch (Throwable $e) {
            Log::warning('[LJK-RECOVERY] Gagal mengantrekan request snapshot ke PWA Guru.', [
                'event_id' => $change['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** @param array<string|int,mixed> $answers @param array<string,int> $questionIdMap */
    protected function translateLjkAnswersToLocalIds(array $answers, array $questionIdMap): array
    {
        $translated = $answers;
        foreach ($questionIdMap as $sourceId => $localId) {
            $localId = (int) $localId;
            if ($localId <= 0 || (string) $sourceId === (string) $localId) {
                continue;
            }

            foreach ([$sourceId, (string) $sourceId, (int) $sourceId] as $sourceKey) {
                if (array_key_exists($sourceKey, $answers)) {
                    $translated[$localId] = $answers[$sourceKey];
                    $translated[(string) $localId] = $answers[$sourceKey];
                    break;
                }
            }
        }
        return $translated;
    }

    /**
     * Validasi identitas pengirim HP dan penerima PC tanpa mempercayai exam_id
     * numerik PWA (ID itu bisa dipakai ulang pada instalasi berbeda).
     * Hanya data guru lokal yang cocok dengan login perangkat diterima.
     */
    protected function verifyLjkEventSender(array $change, string $deviceId, array $payload): \App\Models\Teacher
    {
        $schoolId = trim((string) $this->syncService->getSchoolId());
        $source = trim((string) (
            $change['source_device_id']
            ?? $change['sender_device_id']
            ?? $payload['source_device_id']
            ?? $payload['sender_device_id']
            ?? ''
        ));
        if ($schoolId === '' || !str_ends_with($deviceId, '-PC')
            || !str_starts_with($deviceId, $schoolId . '-')
            || !str_ends_with($source, '-HP')
            || !str_starts_with($source, $schoolId . '-')) {
            throw new \RuntimeException('Penghapusan LJK ditolak: identitas HP/PC tidak valid.');
        }

        $login = substr($deviceId, strlen($schoolId) + 1, -3);
        $expectedHp = $schoolId . '-' . $login . '-HP';
        $payloadLogin = trim((string) ($payload['teacher_login_id'] ?? ''));
        if ($login === '' || strcasecmp($source, $expectedHp) !== 0
            || ($payloadLogin !== '' && strcasecmp($payloadLogin, $login) !== 0)) {
            throw new \RuntimeException('Penghapusan LJK ditolak: pengirim bukan HP guru pemilik PC.');
        }

        if (OpaqueRouteRegistryV21::enabled()) {
            $route = app(OpaqueTeacherRouteV21::class);
            $teacher = $route->teacherForAlias($login);
            $route->assertDeviceOwnedByTeacher($deviceId, (int) $teacher->id, 'PC');
            $route->assertDeviceOwnedByTeacher($source, (int) $teacher->id, 'HP');
            return $teacher;
        }
        $teacher = \App\Models\Teacher::query()
            ->where(function ($q) use ($login) {
                $q->where('nip', $login)->orWhere('nik', $login)->orWhere('nuptk', $login);
            })->first();
        if (!$teacher) {
            throw new \RuntimeException('Penghapusan LJK ditunda: identitas guru lokal belum dikenali.');
        }
        return $teacher;
    }

    /**
     * Tombstone scan diutamakan bahkan bila Paket Ujian telah dihapus.
     * Tidak menghapus nilai resmi dan tidak mencoba merekonstruksi exam lama.
     */
    protected function applyLjkScanDeletion(
        array $change,
        string $deviceId,
        string $scanId,
        int $version,
        array $payload
    ): array {
        $teacher = $this->verifyLjkEventSender($change, $deviceId, $payload);
        $existing = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();
        $examSyncId = preg_replace('/^ljkpkg::/', '', trim((string) ($payload['exam_sync_id'] ?? '')));
        if ($existing) {
            $localSyncId = trim((string) ($existing->exam_sync_id ?? ''));
            if ($examSyncId !== '' && $localSyncId !== '' && $examSyncId !== $localSyncId) {
                throw new \RuntimeException('Penghapusan LJK ditolak: exam_sync_id tidak cocok dengan scan lokal.');
            }
            $owner = DB::table('exams')->where('id', (int) $existing->exam_id)->value('teacher_id');
            if ($owner !== null && (int) $owner !== (int) $teacher->id) {
                throw new \RuntimeException('Penghapusan LJK ditolak: Paket Ujian dimiliki guru lain.');
            }
            $localSource = trim((string) ($existing->source_device_id ?? ''));
            $source = trim((string) ($change['source_device_id'] ?? $payload['source_device_id'] ?? ''));
            if ($localSource !== '' && strcasecmp($localSource, $source) !== 0) {
                throw new \RuntimeException('Penghapusan LJK ditolak: sumber scan tidak cocok.');
            }
            if ($examSyncId === '') $examSyncId = $localSyncId;
        }

        $latestVersion = $this->latestAppliedLjkVersion($scanId);
        if ($latestVersion > $version) {
            $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, 'delete', $version, $payload);
            $this->maybeSendApplyAck($change, 'stale_lww');
            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'ljk_delete_lebih_lama');
        }

        DB::transaction(function () use ($existing, $scanId, $examSyncId, $change, $payload, $version, $deviceId): void {
            if ($existing) {
                DB::table('ljk_scan_results')->where('scan_id', $scanId)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
            LjkScanDeletionMarker::mark(
                $scanId,
                $examSyncId,
                (string) ($change['source_device_id'] ?? $payload['source_device_id'] ?? ''),
                $version
            );
            $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, 'delete', $version, $payload);
        });
        $this->maybeSendApplyAck($change, 'applied');
        Log::info('[LJK-SYNC] Tombstone hasil diterapkan, tanpa perlu Paket Ujian lokal.', [
            'scan_id' => $scanId, 'event_id' => $change['event_id'] ?? '',
        ]);
        return $this->outcome(self::OUTCOME_APPLIED, 'ljk_delete_tanpa_exam');
    }

    /**
     * Versi LJK tertinggi yang sudah pernah diterapkan untuk scan_id ini.
     * Menggunakan ledger lms_sync_records sehingga tidak perlu migration baru.
     */
    protected function latestAppliedLjkVersion(string $scanId): int
    {
        return max(
            0,
            (int) (
                LmsSyncRecord::query()
                    ->where('entity_type', 'lms_assignment_submission')
                    ->where('entity_id', $scanId)
                    ->where('status', 'synced')
                    ->max('version')
                ?? 0
            )
        );
    }

    /**
     * Kunci manual LJK tidak memiliki exam_id. Validasi identitas HP/PC lebih
     * dulu, lalu lakukan penilaian ULANG memakai kunci server SQLite.
     */
    protected function applyLjkStandaloneManual(
        array $change, string $deviceId, string $eventId,
        string $entityId, string $action, int $version, array $payload
    ): array {
        $teacher = $this->verifyLjkEventSender($change, $deviceId, $payload);
        $scanId = trim((string) ($payload['scan_id'] ?? $payload['sync_id'] ?? $entityId));
        $id = trim((string) ($payload['manual_session_id'] ?? $payload['exam_sync_id'] ?? ''));
        $source = trim((string) ($change['source_device_id'] ?? $payload['source_device_id'] ?? ''));
        if ($scanId === '' || !str_starts_with($id, 'manual::')) {
            throw new \RuntimeException('Sesi manual atau scan_id PWA tidak tersedia.');
        }
        if ($this->latestAppliedLjkVersion($scanId) > $version) {
            $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, $action, $version, $payload);
            $this->maybeSendApplyAck($change, 'stale_lww');
            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'ljk_manual_stale');
        }
        $manual = app(\App\Services\Ljk\LjkStandaloneManualService::class);
        if ($action === 'delete') {
            $manual->deleteScan($id, $scanId, (int) $teacher->id, $source);
        } else {
            $manual->saveScan($id, $payload, null, (int) $teacher->id, $source);
            if (!empty($payload['publish_requested'])) {
                $result = $manual->publish($id, [$scanId], (int) $teacher->id);
                $errors = $result['errors'] ?? [];
                if ($errors) throw new \RuntimeException(implode(' | ', array_slice($errors, 0, 3)));
                if ((int) ($result['published'] ?? 0) === 0) {
                    // Event kirim ulang yang sudah masuk Buku Nilai = idempoten.
                    $already = DB::table('ljk_scan_results')->where('scan_id', $scanId)->whereNotNull('published_at')->exists();
                    if (!$already) throw new \RuntimeException('Nilai LJK Manual belum masuk Buku Nilai.');
                }
            }
        }
        $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, $action, $version, $payload);
        $this->maybeSendApplyAck($change, 'applied');
        return $this->outcome(self::OUTCOME_APPLIED, !empty($payload['publish_requested']) ? 'ljk_manual_nilai_tersimpan' : 'ljk_manual_tersimpan');
    }

    protected function applyLjkScanResult(
        array $change,
        string $deviceId,
        string $eventId,
        string $entityId,
        string $action,
        int $version,
        array $payload
    ): array {
        $scanId = trim((string) ($payload['scan_id'] ?? $payload['sync_id'] ?? $entityId));
        $nisn = preg_replace('/\D+/', '', (string) ($payload['nisn'] ?? '')) ?: '';

        if ($scanId === '') {
            throw new \RuntimeException('LJK PWA tanpa scan_id.');
        }

        /*
         * [LJK-DELETE-SYNC-V1] Penghapusan hasil PWA harus dapat diproses tanpa
         * Exam yang sudah dihapus dari laptop. Validasi HP/PC/guru lebih dulu,
         * sebelum mencari paket atau meminta snapshot. Bukti delete disimpan
         * persisten agar upsert terlambat tidak membangkitkan hasil uji coba.
         */
        if ($action === 'delete') {
            return $this->applyLjkScanDeletion($change, $deviceId, $scanId, $version, $payload);
        }

        // Kompatibilitas: hasil yang sudah diarsipkan oleh versi laptop lama
        // (sebelum tombstone persisten ada) juga bukan kandidat recovery.
        $archived = DB::table('ljk_scan_results')
            ->where('scan_id', $scanId)->where('is_active', false)->first();
        if ($archived) {
            $archivedExam = trim((string) ($archived->exam_sync_id ?? ''));
            $incomingExam = preg_replace('/^ljkpkg::/', '', trim((string) ($payload['exam_sync_id'] ?? '')));
            $archivedSource = trim((string) ($archived->source_device_id ?? ''));
            $incomingSource = trim((string) ($change['source_device_id'] ?? $payload['source_device_id'] ?? ''));
            if (($archivedExam === '' || $incomingExam === '' || $archivedExam === $incomingExam)
                && ($archivedSource === '' || strcasecmp($archivedSource, $incomingSource) === 0)) {
                $this->verifyLjkEventSender($change, $deviceId, $payload);
                LjkScanDeletionMarker::mark($scanId, $archivedExam ?: $incomingExam, $incomingSource, $version);
                $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, 'update', $version, $payload);
                $this->maybeSendApplyAck($change, 'stale_deleted');
                return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'ljk_sudah_diarsipkan_lokal');
            }
        }

        if (LjkScanDeletionMarker::isDeleted(
            $scanId,
            (string) ($payload['exam_sync_id'] ?? ''),
            (string) ($change['source_device_id'] ?? $payload['source_device_id'] ?? '')
        )) {
            // Jangan ACK event milik perangkat lain yang memalsukan scan_id.
            $this->verifyLjkEventSender($change, $deviceId, $payload);
            $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, 'update', $version, $payload);
            $this->maybeSendApplyAck($change, 'stale_deleted');
            Log::info('[LJK-SYNC] Hasil yang sudah dihapus diabaikan dan di-ACK.', [
                'scan_id' => $scanId, 'event_id' => $eventId,
            ]);
            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'ljk_hasil_sudah_dihapus');
        }

        /*
         * V15R6.1 — latest-version-wins khusus hasil LJK.
         * Event lama yang baru lolos dari backlog TIDAK boleh menimpa
         * jawaban/skor/publish yang versinya lebih baru.
         */
        $latestAppliedVersion = $this->latestAppliedLjkVersion($scanId);

        if ($latestAppliedVersion > 0 && $version < $latestAppliedVersion) {
            Log::warning('[LJK-SYNC] Event lama diabaikan oleh LWW.', [
                'event_id' => $eventId,
                'scan_id' => $scanId,
                'incoming_version' => $version,
                'latest_applied_version' => $latestAppliedVersion,
                'publish_requested' => !empty($payload['publish_requested']),
            ]);

            /*
             * Catat event stale sebagai selesai agar Cloud dapat di-ACK dan
             * tidak terus dikirim ulang, tetapi jangan sentuh ljk_scan_results
             * atau Buku Nilai.
             */
            $this->recordLedger(
                $change,
                $deviceId,
                'lms_assignment_submission',
                $scanId,
                $action,
                $version,
                $payload
            );

            $this->maybeSendApplyAck($change, 'stale_lww');

            return $this->outcome(
                self::OUTCOME_SKIPPED_STALE,
                'ljk_versi_lama_diabaikan'
            );
        }

        $resolvedExam = $this->resolveLjkExam($payload);
        $exam = $resolvedExam['exam'];
        $recoveryError = null;
        $recoveryRequested = false;

        if (!$exam) {
            $embedded = $this->ljkRecovery->materializeFromPwa($payload, $deviceId, $version);
            $recovered = (($embedded['exam'] ?? null) instanceof \App\Models\Exam)
                ? $embedded
                : $this->ljkRecovery->recoverFromCloud($payload, $deviceId, $version);

            if (($recovered['exam'] ?? null) instanceof \App\Models\Exam) {
                $exam = $recovered['exam'];
                $resolvedExam = [
                    'exam' => $exam,
                    'incoming' => trim((string) ($payload['exam_sync_id'] ?? '')),
                    'canonical' => trim((string) $exam->sync_id),
                    'resolved_by' => ($recovered['source'] ?? '') === 'pwa_recovery_response'
                        ? 'self_heal_embedded'
                        : 'self_heal_cloud',
                    'candidates' => $resolvedExam['candidates'] ?? [],
                    'question_id_map' => $recovered['question_id_map'] ?? [],
                    'recovered_by' => $recovered['source'] ?? 'cloud_private_snapshot',
                ];
            } else {
                $embeddedError = trim((string) ($embedded['error'] ?? ''));
                $cloudError = trim((string) ($recovered['error'] ?? ''));
                $recoveryError = implode(' | ', array_values(array_filter([$embeddedError, $cloudError])));
                $recoveryRequested = $this->requestLjkExamRecoveryFromPwa($change, $deviceId, $payload, $resolvedExam);
            }
        }

        if (!$exam) {
            throw new \RuntimeException(
                'Paket Ujian LJK dari PWA belum dapat dicocokkan di laptop. '
                . 'incoming_exam_sync_id=' . ($resolvedExam['incoming'] ?: '-')
                . '; exam_id=' . (string) ($payload['exam_id'] ?? '-')
                . '; candidates=' . implode(',', $resolvedExam['candidates'])
                . '; recovery=' . ($recoveryRequested ? 'LJK_RECOVERY_PENDING: permintaan snapshot PWA telah diantrikan' : ($recoveryError ?: 'tidak tersedia'))
            );
        }

        /*
         * Exam LAN/lokal lama dapat sah tetapi belum pernah mendapat sync_id.
         * Begitu dipakai PWA LJK, beri UUID permanen sekali dan lanjutkan.
         */
        $examSyncId = $this->ensureLjkExamCanonicalSyncId(
            $exam,
            $eventId,
            $scanId
        );

        $questionIdMap = is_array($resolvedExam['question_id_map'] ?? null)
            ? $resolvedExam['question_id_map']
            : $this->ljkRecovery->storedQuestionMap($examSyncId);
        if ($questionIdMap !== [] && is_array($payload['answers'] ?? null)) {
            $payload['answers'] = $this->translateLjkAnswersToLocalIds($payload['answers'], $questionIdMap);
        }

        if ($resolvedExam['incoming'] !== $examSyncId) {
            Log::warning('[LJK-SYNC] exam_sync_id event dinormalisasi.', [
                'event_id' => $eventId,
                'scan_id' => $scanId,
                'incoming_exam_sync_id' => $resolvedExam['incoming'],
                'canonical_exam_sync_id' => $examSyncId,
                'resolved_by' => $resolvedExam['resolved_by'],
                'exam_id' => $exam->id,
            ]);
        }

        /*
         * V15R5.1 — IDENTITAS SUMBER vs PENERIMA
         *
         * $deviceId pada SyncApplier adalah device PENERIMA yang sedang
         * ditarik oleh daemon (PC), bukan device pengirim event.
         *
         * Validasi lama memaksa $deviceId berakhiran -HP sehingga setiap
         * event LJK HP -> PC pasti ditolak.
         *
         * Sekarang:
         * - source_device_id event/payload HARUS HP guru;
         * - $deviceId HARUS PC guru yang sama;
         * - login guru HARUS cocok dengan pemilik Paket Ujian.
         */
        $teacher = \App\Models\Teacher::query()->find((int) $exam->teacher_id);

        if (!$teacher) {
            throw new \RuntimeException(
                'Identitas guru event LJK tidak valid: pemilik Paket Ujian tidak ditemukan.'
            );
        }

        $schoolId = trim((string) $this->syncService->getSchoolId());

        $sourceDeviceId = trim((string) (
            $change['source_device_id']
            ?? $change['sender_device_id']
            ?? $payload['source_device_id']
            ?? $payload['sender_device_id']
            ?? ''
        ));

        $payloadLogin = trim((string) ($payload['teacher_login_id'] ?? ''));

        $extractDeviceLogin = static function (
            string $candidateDeviceId,
            string $schoolId,
            string $suffix
        ): string {
            $candidateDeviceId = trim($candidateDeviceId);
            $schoolId = trim($schoolId);
            $suffix = strtoupper(trim($suffix));

            if ($candidateDeviceId === '' || $schoolId === '' || $suffix === '') {
                return '';
            }

            $prefix = $schoolId . '-';
            $ending = '-' . $suffix;

            if (
                !str_starts_with(strtoupper($candidateDeviceId), strtoupper($prefix))
                || !str_ends_with(strtoupper($candidateDeviceId), $ending)
            ) {
                return '';
            }

            $middleLength = strlen($candidateDeviceId) - strlen($prefix) - strlen($ending);

            if ($middleLength <= 0) {
                return '';
            }

            return trim(substr($candidateDeviceId, strlen($prefix), $middleLength));
        };

        $sourceLogin = $extractDeviceLogin($sourceDeviceId, $schoolId, 'HP');
        $receiverLogin = $extractDeviceLogin($deviceId, $schoolId, 'PC');

        if (OpaqueRouteRegistryV21::enabled()) {
            $route = app(OpaqueTeacherRouteV21::class);
            $route->assertDeviceOwnedByTeacher($deviceId, (int) $teacher->id, 'PC');
            $route->assertDeviceOwnedByTeacher($sourceDeviceId, (int) $teacher->id, 'HP');
            $login = $route->aliasForTeacher((int) $teacher->id);
            if ($payloadLogin !== '' && !hash_equals($login, $payloadLogin)) {
                throw new \RuntimeException('BLOCKED V21: alias payload LJK tidak cocok dengan pemilik ujian.');
            }
            if (!hash_equals($login, $sourceLogin) || !hash_equals($login, $receiverLogin)) {
                throw new \RuntimeException('BLOCKED V21: rute perangkat LJK tidak konsisten.');
            }
            $legacySourceInferred = false; // strict: no inference from raw NIP/NIK
        } else {
        $teacherIds = array_values(array_unique(array_filter([
            trim((string) ($teacher->nip ?? '')),
            trim((string) ($teacher->nik ?? '')),
            trim((string) ($teacher->nuptk ?? '')),
        ], static fn ($v) => $v !== '')));

        /*
         * Event baru: teacher_login_id tetap authoritative dan bila salah
         * langsung ditolak. Event legacy yang belum membawa field itu
         * boleh diturunkan dari source HP atau receiver PC, TETAPI hanya
         * bila identitas tersebut cocok dengan guru pemilik Paket Ujian.
         */
        if ($payloadLogin !== '' && !in_array($payloadLogin, $teacherIds, true)) {
            throw new \RuntimeException(
                'Event LJK ditolak: teacher_login_id tidak sesuai pemilik Paket Ujian.'
            );
        }

        $login = '';

        foreach ([$payloadLogin, $sourceLogin, $receiverLogin] as $candidateLogin) {
            if (
                $candidateLogin !== ''
                && in_array($candidateLogin, $teacherIds, true)
            ) {
                $login = $candidateLogin;
                break;
            }
        }

        if ($login === '') {
            throw new \RuntimeException(
                'Identitas guru event LJK tidak valid: login tidak dapat diverifikasi '
                . 'dari payload/source/receiver terhadap pemilik Paket Ujian.'
            );
        }

        /*
         * Bila source/receiver membawa login eksplisit, keduanya harus
         * konsisten dengan login yang sudah tervalidasi.
         */
        if ($sourceLogin !== '' && strcasecmp($sourceLogin, $login) !== 0) {
            throw new \RuntimeException(
                'Event LJK ditolak: login source HP berbeda dari pemilik Paket Ujian.'
            );
        }

        if ($receiverLogin !== '' && strcasecmp($receiverLogin, $login) !== 0) {
            throw new \RuntimeException(
                'Event LJK ditolak: login receiver PC berbeda dari pemilik Paket Ujian.'
            );
        }

        $expectedHp = $schoolId . '-' . $login . '-HP';
        $expectedPc = $schoolId . '-' . $login . '-PC';

        /*
         * Event modern wajib membawa source HP yang benar.
         * Event legacy tertentu dari antrean lama belum memilikinya.
         * Untuk kasus itu source boleh diinferensikan HANYA setelah:
         * - exam owner cocok,
         * - receiver PC cocok,
         * - login guru cocok.
         */
        $legacySourceInferred = false;

        if ($sourceDeviceId === '') {
            if (strcasecmp($deviceId, $expectedPc) !== 0) {
                throw new \RuntimeException(
                    'Event LJK legacy ditolak: source HP kosong dan receiver PC tidak cocok.'
                );
            }

            $sourceDeviceId = $expectedHp;
            $legacySourceInferred = true;
        }

        if (strcasecmp($sourceDeviceId, $expectedHp) !== 0) {
            throw new \RuntimeException(
                'Event LJK ditolak: source_device_id tidak sesuai HP guru. '
                . 'source=' . $sourceDeviceId
                . ', expected=' . $expectedHp
            );
        }

        if (strcasecmp($deviceId, $expectedPc) !== 0) {
            throw new \RuntimeException(
                'Event LJK ditolak: device penerima bukan PC guru pemilik Paket Ujian. '
                . 'receiver=' . $deviceId
                . ', expected=' . $expectedPc
            );
        }

        if ($payloadLogin === '' || $legacySourceInferred) {
            Log::warning('[LJK-SYNC] Identitas event legacy berhasil dipulihkan.', [
                'event_id' => $eventId,
                'scan_id' => $scanId,
                'teacher_login_id_payload' => $payloadLogin !== '' ? $payloadLogin : null,
                'source_device_id_original' => trim((string) (
                    $change['source_device_id']
                    ?? $change['sender_device_id']
                    ?? $payload['source_device_id']
                    ?? $payload['sender_device_id']
                    ?? ''
                )) ?: null,
                'source_device_id_effective' => $sourceDeviceId,
                'receiver_device_id' => $deviceId,
                'resolved_login' => $login,
                'source_inferred' => $legacySourceInferred,
            ]);
        }

        }

        Log::info('[LJK-SYNC] Identitas HP -> PC tervalidasi.', [
            'event_id' => $eventId,
            'scan_id' => $scanId,
            'source_device_id' => $sourceDeviceId,
            'receiver_device_id' => $deviceId,
            'teacher_login_id' => $login,
            'exam_sync_id' => $examSyncId,
            'exam_resolved_by' => $resolvedExam['resolved_by'],
        ]);

        $student = $nisn !== ''
            ? \App\Models\Student::query()->where('nisn', $nisn)->first()
            : null;

        $existing = DB::table('ljk_scan_results')->where('scan_id', $scanId)->first();

        $data = [
            'exam_id' => (int) $exam->id,
            'exam_sync_id' => $examSyncId,
            'student_id' => $student?->id,
            'nisn' => $nisn !== '' ? $nisn : null,
            'student_name' => $student?->nama ?: ($payload['student_name'] ?? null),
            'template_version' => 'LJK-A4-V3',
            'source_type' => 'pwa',
            'answers_json' => json_encode($payload['answers'] ?? [], JSON_UNESCAPED_UNICODE),
            'question_count' => max(0, (int) ($payload['question_count'] ?? 0)),
            'correct_count' => max(0, (int) ($payload['correct_count'] ?? 0)),
            'score' => (float) ($payload['score'] ?? 0),
            'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            'status' => (string) ($payload['status'] ?? 'review'),
            'issue_code' => $payload['issue_code'] ?? null,
            'issue_message' => $payload['issue_message'] ?? null,
            'is_active' => true,
            'device_id' => $deviceId,
            'source_device_id' => $sourceDeviceId,
            'scanned_at' => now(),
            /*
             * Upsert hasil/edit dari HP membuka kembali status publikasi.
             * Event publish berikutnya akan menulis ulang nilai resmi.
             */
            'published_at' => !empty($payload['publish_requested'])
                ? ($existing->published_at ?? null)
                : null,
            'updated_at' => now(),
        ];

        if ($existing) {
            /*
             * Jangan menurunkan status published_at pada edit/sync biasa.
             * Jika jawaban diedit setelah publikasi, PWA mengosongkan status lokal
             * dan publish berikutnya akan memperbarui nilai resmi.
             */
            DB::table('ljk_scan_results')->where('id', $existing->id)->update($data);
        } else {
            $data['scan_id'] = $scanId;
            $data['created_at'] = now();
            DB::table('ljk_scan_results')->insert($data);
        }

        if (!empty($payload['publish_requested'])) {
            if (($data['status'] ?? '') !== 'success' || !$student) {
                throw new \RuntimeException('Hasil LJK belum valid untuk dikirim ke Buku Nilai.');
            }

            $summary = app(\App\Services\Ljk\LjkAutoCorrectionService::class)
                ->publishFromSync((int) $exam->id, [$scanId]);

            if ((int) ($summary['published'] ?? 0) < 1) {
                $errors = $summary['errors'] ?? [];
                throw new \RuntimeException(
                    $errors !== []
                        ? implode(' | ', array_slice($errors, 0, 3))
                        : 'Nilai LJK belum berhasil masuk Buku Nilai.'
                );
            }
        }

        $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $scanId, 'update', $version, $payload);
        $this->maybeSendApplyAck($change, 'applied');

        return $this->outcome(
            self::OUTCOME_APPLIED,
            !empty($payload['publish_requested']) ? 'ljk_masuk_buku_nilai' : 'ljk_disimpan'
        );
    }

    /* ============================================================
     * GRADE GURU (HP → laptop) — [D4] + [D19] + dua-domain versi
     * ============================================================ */

    protected function applyTeacherGrade(array $change, string $deviceId, array $payload): array
    {
        $syncId = trim((string) ($payload['sync_id'] ?? $change['entity_id'] ?? ''));

        if ($syncId === '') {
            return $this->deadNow($change, $deviceId, 'Grade event tanpa sync_id — submission tidak dapat ditemukan.');
        }

        $submission = LmsAssignmentSubmission::query()->where('sync_id', $syncId)->first();

        if (!$submission) {
            return $this->waitOrDead(
                $change,
                $deviceId,
                "Submission {$syncId} belum ada lokal — menunggu event submission."
            );
        }

        // V21R7: fail closed BEFORE updating submission/grade version.
        // A PWA must not change a student's score by supplying another alias.
        $gradeLocalNisn = CloudInboundStudentV21::enabled()
            ? app(CloudInboundStudentV21::class)->localGradeNisn(
                $payload, $submission, (string) ($change['source_device_id'] ?? '')
            )
            : null;

        $incoming = max(1, (int) ($payload['grade_version'] ?? $payload['sync_version'] ?? $change['version'] ?? 1));
        $localGrade = max(0, (int) ($submission->grade_version ?? 0));

        if ($incoming < $localGrade) {
            $this->maybeSendApplyAck($change, 'stale_lww');

            return $this->outcome(self::OUTCOME_SKIPPED_STALE, 'grade_lebih_lama');
        }

        if (array_key_exists('score', $payload)) {
            $submission->score = $payload['score'];
        }

        if (array_key_exists('teacher_feedback', $payload)) {
            $submission->teacher_feedback = $payload['teacher_feedback'];
        }

        $submission->status = 'graded';
        $submission->grade_version = $incoming;

        if (array_key_exists('score_type', $payload) && in_array($payload['score_type'], ['diagnostik', 'formatif', 'sumatif'], true)) {
            $submission->score_type = $payload['score_type'];
        }

        if ($this->submissionHasColumn($submission, 'sync_version')) {
            $submission->sync_version = max((int) ($submission->sync_version ?? 0), $incoming);
        }

        $submission->save();

        /*
         * [D4] PARITAS NILAI HARIAN — grade dari HP juga masuk buku
         * nilai, sama seperti grade dari web laptop.
         */
        $dailyNote = '';

        try {
            $writer = app(DailyAssessmentWriter::class);

            $studentId = $writer->resolveStudentId(
                $gradeLocalNisn ?? $payload['student_nisn'] ?? $payload['student_id'] ?? $submission->student_id
            );
            if (CloudInboundStudentV21::enabled() && !$studentId) {
                throw new \RuntimeException('BLOCKED V21: siswa hasil penilaian tidak ditemukan lokal.');
            }
            $scoreType = $submission->score_type ?? ($payload['score_type'] ?? 'sumatif');

            $course = $submission->assignment?->topic?->course;

            if (CloudInboundStudentV21::enabled() && !$course) {
                throw new \RuntimeException('BLOCKED V21: penugasan untuk nilai harian belum tersedia.');
            }
            if ($studentId && $course) {
                $taId = $writer->resolveTaId(
                    (int) $course->teacher_id,
                    $course->subject_id,
                    $course->homeroom_id
                );

                if ($taId) {
                    $writeResult = $writer->writeScore(
                        $studentId,
                        $taId,
                        now()->toDateString(),
                        $scoreType,
                        $submission->score,
                        null // materi = milik absensi; baris baru tanpa label.
                    );
                    if (CloudInboundStudentV21::enabled() && !($writeResult['saved'] ?? false)) {
                        throw new \RuntimeException('BLOCKED V21: Buku Nilai menolak penyimpanan hasil penilaian.');
                    }

                    if ((int) $course->teacher_id > 0) {
                        WorkProductPushService::markDirty((int) $course->teacher_id);
                    }

                    $dailyNote = ' + nilai harian';
                } else {
                    if (CloudInboundStudentV21::enabled()) {
                        throw new \RuntimeException('BLOCKED V21: teaching assignment nilai tidak tersedia.');
                    }
                    Log::warning('[GRADE-HP] TA tidak ditemukan — nilai harian dilewati.', [
                        'sync_id' => $syncId,
                    ]);
                }
            }
        } catch (Throwable $e) {
            if (CloudInboundStudentV21::enabled()) {
                // Do not acknowledge teacher grades that failed to reach Buku Nilai.
                // The event remains retryable, using existing sync_id/version semantics.
                throw $e;
            }
            Log::error('[GRADE-HP] Penulisan nilai harian gagal (nilai submission tetap tersimpan).', [
                'sync_id' => $syncId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->recordLedger($change, $deviceId, 'lms_assignment_submission', $syncId, 'update', $incoming, $payload);

        $this->maybeSendApplyAck($change, 'applied');

        return $this->outcome(self::OUTCOME_APPLIED, 'grade_diterapkan' . $dailyNote);
    }

    /* ============================================================
     * [D21] UNLOCK REVISI
     * ============================================================ */

    protected function applySubmissionUnlock(array $change, string $deviceId, array $payload): array
    {
        $syncId = trim((string) ($payload['sync_id'] ?? $change['entity_id'] ?? ''));

        if ($syncId === '') {
            return $this->deadNow($change, $deviceId, 'submission_unlock tanpa sync_id.');
        }

        $submission = LmsAssignmentSubmission::query()->where('sync_id', $syncId)->first();

        if (!$submission) {
            return $this->waitOrDead(
                $change,
                $deviceId,
                "Submission {$syncId} belum ada lokal — menunggu event submission."
            );
        }

        $submission->revision_unlocked_at = now();

        if ($this->submissionHasColumn($submission, 'sync_version')) {
            $submission->sync_version = VersionService::above((int) ($submission->sync_version ?? 0));
        }

        $submission->save();

        $this->recordLedger(
            $change,
            $deviceId,
            'submission_unlock',
            $syncId,
            'update',
            max(1, (int) ($change['version'] ?? 1)),
            $payload
        );

        Log::info('[UNLOCK] Guru membuka revisi jawaban siswa.', [
            'sync_id' => $syncId,
            'by_device' => $change['source_device_id'] ?? null,
        ]);

        return $this->outcome(self::OUTCOME_APPLIED, 'revisi_dibuka');
    }

    /* ============================================================
     * [D19] APPLY-ACK balik ke HP guru
     * ============================================================ */

    protected function maybeSendApplyAck(array $change, string $status): void
    {
        try {
            $source = trim((string) ($change['source_device_id'] ?? ''));

            if ($source === '' || !str_ends_with(strtoupper($source), '-HP')) {
                return; // Hanya laporan balik ke HP guru; antar-laptop tidak.
            }

            $eventId = (string) ($change['event_id'] ?? '');

            if ($eventId === '') {
                return;
            }

            $ackSyncId = 'applyack::' . $eventId . '::' . $status;

$this->syncService->pendingAckViaSync($ackSyncId, [
                'ref_event_id' => $eventId,
                'entity_type' => $change['entity_type'] ?? null,
                'entity_id' => $change['entity_id'] ?? null,
                'apply_status' => $status,
                'applied_at' => now()->toIso8601String(),
                'applied_by' => $this->syncService->getDeviceId(),
            ], $source);
        } catch (Throwable $e) {
            Log::warning('[APPLY-ACK] Gagal mengantrekan laporan balik.', [
                'event_id' => $change['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ============================================================
     * CBT ANSWER (tetap — limit kini berbasis waktu)
     * ============================================================ */

    protected function applyCbtAnswer(array $change, string $deviceId, string $action, array $payload): array
    {
        if ($action === 'delete') {
            return $this->deadNow($change, $deviceId, 'cbt_answer delete tidak didukung — kontrak PWA hanya upsert.');
        }

        // CBT V21: metadata Cloud tetap alias; hanya salinan untuk ingest
        // yang di-resolve ke siswa lokal, setelah scope ujian/rombel diverifikasi.
        $localPayload = OpaqueRouteRegistryV21::enabled()
            ? app(CloudCbtInboundV21::class)->localize($payload, $this->sourceDeviceOf($change, $deviceId))
            : $payload;
        $receipt = $this->cbtCorrection->ingest($localPayload, $this->sourceDeviceOf($change, $deviceId));

        if ($receipt === null) {
            return $this->waitOrDead(
                $change,
                $deviceId,
                'CBT ingest: exam/siswa belum dikenali lokal (exam mungkin telah dihapus) atau sesi LAN masih berjalan.',
                self::CBT_INGEST_MAX_AGE_SECONDS
            );
        }

        $this->recordLedger(
            $change,
            $deviceId,
            'cbt_answer',
            (string) ($payload['sync_id'] ?? $change['entity_id'] ?? ''),
            $action === 'upsert' ? 'update' : $action,
            max(1, (int) ($change['version'] ?? $payload['sync_version'] ?? 1)),
            $payload
        );

        $status = (string) ($receipt['status'] ?? 'queued_for_correction');

        return $this->outcome(
            self::OUTCOME_APPLIED,
            $status === 'duplicate_ignored'
                ? 'cbt_duplikat_ditolak_first_submit_wins'
                : 'cbt_ingest_diterima_menunggu_koreksi'
        );
    }

    /* ============================================================
     * KLASIFIKASI KEGAGALAN — [P3-1] limit berbasis waktu
     * ============================================================ */

    protected function classifyFailure(array $change, string $deviceId, Throwable $e): array
    {
        $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        Log::warning('[SYNC-APPLIER] Apply gagal.', [
            'device_id' => $deviceId,
            'source_device_id' => $change['source_device_id'] ?? ($payload['source_device_id'] ?? null),
            'event_id' => $change['event_id'] ?? null,
            'entity_type' => $change['entity_type'] ?? $change['entity'] ?? null,
            'payload_entity' => $payload['entity'] ?? null,
            'entity_id' => $change['entity_id'] ?? null,
            'scan_id' => $payload['scan_id'] ?? null,
            'exam_sync_id' => $payload['exam_sync_id'] ?? null,
            'reason' => $e->getMessage(),
        ]);

        // Recovery yang tidak menemukan paket tidak boleh gagal SELAMANYA.
        // Setelah 72 jam payload tetap tersimpan di dead-letter untuk replay
        // manual, tetapi event di-ACK agar antrean Cloud tidak macet.
        $reason = $e->getMessage();
        $maxAge = str_contains(strtoupper($reason), 'LJK_RECOVERY_PENDING')
            ? self::LJK_RECOVERY_MAX_AGE_SECONDS : null;
        return $this->waitOrDead($change, $deviceId, $reason, $maxAge);
    }

    protected function waitOrDead(array $change, string $deviceId, string $reason, ?int $maxAgeSeconds = null): array
    {
        $verdict = $this->deadLetters->touch(
            $change,
            $deviceId,
            $reason,
            $maxAgeSeconds ?? self::RETRY_MAX_AGE_SECONDS
        );

        if (($verdict['status'] ?? '') === 'dead' && !$this->isNeverDead($reason)) {
            $this->deadLetters->recordDead($change, $deviceId, $reason . $this->remedyHint($reason));

            return $this->outcome(self::OUTCOME_DEAD_LETTER, 'melewati_batas_usia');
        }

        Log::warning('[SYNC-APPLIER] Event ditunda (retry).', [
            'event_id' => $change['event_id'] ?? null,
            'entity'   => $change['entity_type'] ?? null,
            'entity_id' => $change['entity_id'] ?? null,
            'attempts' => $verdict['attempts'] ?? null,
            'age_seconds' => $verdict['age_seconds'] ?? null,
            'reason' => Str::limit((string) $reason, 200),
        ]);

        return $this->outcome(self::OUTCOME_RETRY_TRANSIENT, $reason);
    }

    protected function deadNow(array $change, string $deviceId, string $reason): array
    {
        $this->deadLetters->recordDead($change, $deviceId, $reason);

        return $this->outcome(self::OUTCOME_DEAD_LETTER, $reason);
    }

    protected function isNeverDead(string $reason): bool
    {
        $upper = strtoupper($reason);

        foreach (self::NEVER_DEAD_MARKERS as $marker) {
            if (str_contains($upper, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function remedyHint(string $reason): string
    {
        $lower = mb_strtolower($reason);

        if (str_contains($lower, 'enrollment_missing')) {
            return ' → Jalankan sinkron master/push Data Pokok di laptop ini, lalu eduxid:replay-dead.';
        }

        if (str_contains($lower, 'exam mungkin telah dihapus')) {
            return ' → Jawaban siswa untuk ujian yang telah DIHAPUS tidak dapat dikoreksi. Berikan ujian pengganti; replay dari panel dead-letter bila exam dipulihkan.';
        }

        if (str_contains($lower, 'ljk_recovery_pending')) {
            return ' → Buka PWA Guru pada perangkat HP yang sama dan jalankan sinkron; PWA akan mengirim ulang snapshot Paket Ujian secara otomatis.';
        }

        if (str_contains($lower, 'cbt ingest')) {
            return ' → Pastikan master & paket ujian tersinkron di laptop ini; sesi LAN terkunci: selesaikan/batalkan lalu eduxid:replay-dead.';
        }

        if (str_contains($lower, 'belum ada lokal')) {
            return ' → Event induk belum tiba; akan pulih otomatis bila induk tiba dalam batas usia. Lalu eduxid:replay-dead bila perlu.';
        }

        return '';
    }

    /* ============================================================
     * IDEMPOTENSI + LEDGER (tetap)
     * ============================================================ */

    protected function alreadyApplied(string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        return LmsSyncRecord::query()
            ->where('sync_id', $eventId)
            ->where('status', 'synced')
            ->exists();
    }

    protected function recordLedger(
        array $change,
        string $deviceId,
        string $entity,
        string $entityId,
        string $action,
        int $version,
        array $payload
    ): void {
        try {
            $eventId = trim((string) ($change['event_id'] ?? ''));

            $record = null;

            if ($eventId !== '') {
                $record = LmsSyncRecord::query()->where('sync_id', $eventId)->first();
            }

            if (!$record) {
                $record = new LmsSyncRecord();
                $record->sync_id = $eventId !== '' ? $eventId : (string) Str::uuid();
            }

            $record->forceFill([
                'node_id'     => $deviceId,
                'node_type'   => 'teacher',
                'entity_type' => $entity,
                'entity_id'   => $entityId,
                'operation'   => $action,
                'version'     => $version,
                'payload'     => $payload,
                'status'      => 'synced',
                'synced_at'   => now(),
            ])->save();

            if ($eventId !== '') {
                $this->deadLetters->resolve($eventId, $deviceId);
            }
        } catch (Throwable $e) {
            Log::warning('[SYNC-APPLIER] Gagal mencatat ledger.', [
                'entity' => $entity,
                'entity_id' => $entityId,
                'event_id' => $change['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ============================================================
     * NORMALIZER + HELPERS
     * ============================================================ */

    /** [SYNC-CLEANUP-V24] Cek registry event yang sengaja diabaikan operator. */
    protected function isManuallyIgnoredEvent(string $eventId, string $deviceId): bool
    {
        try {
            $key = 'sync::ignored_event::' . hash('sha256', $eventId);
            $raw = DB::table('app_settings')->where('key', $key)->value('value');

            if (!is_string($raw) || trim($raw) === '') {
                return false;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return false;
            }

            if (!hash_equals((string) ($data['event_id'] ?? ''), $eventId)) {
                return false;
            }

            $targetDevice = trim((string) ($data['device_id'] ?? ''));
            return $targetDevice === '' || strcasecmp($targetDevice, $deviceId) === 0;
        } catch (Throwable) {
            return false;
        }
    }

    protected function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        if ($action === 'upsert') {
            $action = 'update';
        }

        return in_array($action, ['create', 'update', 'delete'], true) ? $action : 'update';
    }

    protected function normalizeAttendanceChange(array $change): array
    {
        $normalized = $change;
        $normalized['action'] = $this->normalizeAction(
            (string) ($normalized['action'] ?? $normalized['operation'] ?? 'update')
        );
        $normalized['operation'] = $normalized['action'];
        $normalized['version'] = max(1, (int) ($normalized['version'] ?? $normalized['sync_version'] ?? 1));

        if (isset($normalized['payload']) && is_array($normalized['payload'])
            && !isset($normalized['payload']['sync_version'])) {
            $normalized['payload']['sync_version'] = $normalized['version'];
        }

        return $normalized;
    }

    protected function normalizeGenericChange(array $change): array
    {
        $normalized = $change;
        $normalized['action'] = $this->normalizeAction(
            (string) ($normalized['action'] ?? $normalized['operation'] ?? 'update')
        );
        $normalized['operation'] = $normalized['action'];
        $normalized['version'] = max(1, (int) ($normalized['version'] ?? $normalized['sync_version'] ?? 1));

        return $normalized;
    }

    protected function submissionHasColumn(LmsAssignmentSubmission $submission, string $column): bool
    {
        return array_key_exists($column, $submission->getAttributes())
            || $submission->isFillable($column);
    }

    protected function sourceDeviceOf(array $change, string $fallback): string
    {
        $src = trim((string) ($change['source_device_id'] ?? ''));

        return $src !== '' ? $src : $fallback;
    }

    /** @return array{outcome: string, reason: string} */
    protected function outcome(string $outcome, string $reason): array
    {
        return ['outcome' => $outcome, 'reason' => $reason];
    }
}