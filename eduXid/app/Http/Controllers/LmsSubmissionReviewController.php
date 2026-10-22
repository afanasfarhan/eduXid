<?php

namespace App\Http\Controllers;

use App\Models\LmsAssignment;
use App\Models\LmsAssignmentSubmission;
use App\Services\Sync\LmsSyncService;
use App\Services\Sync\WorkProductPushService;
use App\Services\Sync\VersionService;
use App\Services\Sync\DailyAssessmentWriter;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LmsSubmissionReviewController extends Controller
{
    /**
     * [SIKAP-DEFAULT] Konsisten dengan HomeroomController,
     * NilaiController, RaporController, WaliKelasController,
     * CbtController, EkskulController, dan KokurikulerController:
     * dimensi profil lulusan yang belum dinilai dianggap bernilai 3
     * (Mulai Berkembang). Skala 1-5.
     */
    protected const SIKAP_DEFAULT = 3;

    private static ?array $scoreColumns = null;
    private static ?array $syncVersionColumns = null;

    /*
     * ============================================================
     * [REWRITE-06.2-SUBMISSION] Perubahan vs versi lama:
     *
     *   1. [FIX-DAILY-RESULT] Return pushToDailyAssessment KINI
     *      ditangkap - dulu dibuang di dalam DB::transaction sehingga
     *      $dailyResult tak terdefinisi dan pesan ke guru SELALU
     *      "nilai harian tidak tercatat" meski tersimpan.
     *   2. [FIX-QUEUED-BRANCH] Branch antrean kini diperiksa dari
     *      $result['success']/['status'] - dulu membaca key 'queued'
     *      yang tidak pernah ada, sehingga push PENDING (mis. siswa
     *      belum pairing) selalu tampil sebagai "terkirim".
     *   3. [06.2] Cloud-sync kini FLAG (markDirty) - daemon mendorong
     *      batch otomatis; bukan push sinkron di tengah request.
     *   4. [06.1] Tulisan assessment_scores di-cap sync_version -
     *      LWW lintas jalur hidup untuk nilai harian dari tugas.
     *   5. [AUDIT] Log penilaian kini juga tercatat pada jalur
     *      warning (dulu return sebelum Log).
     *   6. [NAMA] Nama siswa fallback ke tabel students bila akun
     *      user tidak ditemukan.
     * ============================================================
     */

    private function getTeacher()
    {
        $user = auth()->user();
        $teacher = $user?->teacher();

        if (!$teacher) {
            abort(403, 'Akun yang login belum terhubung dengan data GTK.');
        }

        return $teacher;
    }

    private function authorizeAssignmentOwner(LmsAssignment $assignment)
    {
        $assignment->loadMissing('topic.course');

        if (!$assignment->topic || !$assignment->topic->course) {
            abort(404, 'Kelas LMS untuk aktivitas ini tidak ditemukan.');
        }

        $teacher = $this->getTeacher();

        if ((int) $assignment->topic->course->teacher_id !== (int) $teacher->id) {
            abort(403, 'Anda tidak memiliki akses ke aktivitas ini.');
        }

        return $teacher;
    }

    private function authorizeSubmissionOwner(LmsAssignmentSubmission $submission): LmsAssignment
    {
        $assignment = LmsAssignment::with('topic.course')->find($submission->assignment_id);

        if (!$assignment) {
            abort(404, 'Aktivitas untuk submission ini tidak ditemukan.');
        }

        $this->authorizeAssignmentOwner($assignment);

        return $assignment;
    }

    /*
     * ============================================================
     * [06.1] SCHEMA-TOLERANT HELPERS
     * ============================================================
     */

    private function tableHasSyncVersion(string $table): bool
    {
        self::$syncVersionColumns ??= [];

        if (!array_key_exists($table, self::$syncVersionColumns)) {
            try {
                self::$syncVersionColumns[$table] = Schema::hasColumn($table, 'sync_version');
            } catch (Throwable) {
                self::$syncVersionColumns[$table] = false;
            }
        }

        return self::$syncVersionColumns[$table];
    }

    private function stampSyncVersion(string $table, array $data, int $version): array
    {
        if ($this->tableHasSyncVersion($table)) {
            $data['sync_version'] = $version;
        }

        return $data;
    }

    /* ============================================================
     * INDEX
     * ============================================================ */

    public function index(LmsAssignment $assignment)
    {
        $this->authorizeAssignmentOwner($assignment);

        $submissions = LmsAssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('submitted_at')
            ->get();

        /*
         * [NAMA] login_id siswa (NISN) -> nama; fallback ke tabel
         * students bila akun user tidak ditemukan.
         */
        $studentNames = \App\Models\User::query()
            ->whereIn('login_id', $submissions->pluck('student_id'))
            ->pluck('name', 'login_id');

        $missingNames = $submissions->pluck('student_id')->diff($studentNames->keys());

        if ($missingNames->isNotEmpty()) {
            $fromStudents = DB::table('students')
                ->whereIn('nisn', $missingNames)
                ->pluck('nama', 'nisn');

            foreach ($fromStudents as $nisn => $nama) {
                $studentNames[$nisn] = $nama;
            }
        }

        return view('lms.guru.submissions', [
            'assignment' => $assignment,
            'submissions' => $submissions,
            'studentNames' => $studentNames,
        ]);
    }

    /* ============================================================
     * GRADE — penilaian tugas oleh guru
     * ============================================================
     *
     * W10-fanout — NILAI DARI LAPTOP ikut sampai ke HP guru:
     *   1) pushSubmission               → DIRECT ke device siswa
     *   2) pushGradedSubmissionToTeachers → TEACHER-ROUTED (fan-out)
     * Kedua event ber-event_id berbeda (UUID masing-masing) → tidak
     * saling duplicate di Cloud.
     */

    public function grade(
        Request $request,
        LmsAssignmentSubmission $submission,
        LmsSyncService $syncService
    ) {
        $assignment = $this->authorizeSubmissionOwner($submission);

        $rules = [
            'score' => 'required|numeric|min:0',
        ];

        if ($assignment->max_score !== null) {
            $rules['score'] .= '|max:' . (float) $assignment->max_score;
        }

        $data = $request->validate($rules + [
            'teacher_feedback' => 'nullable|string|max:2000',
            'score_type' => 'required|in:diagnostik,formatif,sumatif',
        ]);

        /*
         * [P0-3] Dua domain versi:
         *   grade_version = versi domain GURU (naik tiap koreksi).
         *   answer_version (siswa) TIDAK disentuh di sini.
         * [D21] Re-koreksi = grade_version baru → tidak tertelan
         * dedupe cloud/applier (event_id baru per versi).
         */
        $gradeVersion = VersionService::above(
            max((int) ($submission->grade_version ?? 0), (int) ($submission->sync_version ?? 0))
        );

        $submission->score = $data['score'];
        $submission->teacher_feedback = $data['teacher_feedback'] ?? null;
        $submission->status = 'graded';
        $submission->grade_version = $gradeVersion;
        $submission->score_type = $data['score_type'];

        if (Schema::hasColumn('lms_assignment_submissions', 'sync_version')) {
            $submission->sync_version = max((int) ($submission->sync_version ?? 0), $gradeVersion);
        }

        $dailyResult = DB::transaction(function () use ($submission, $assignment, $data): array {
            $submission->saveOrFail();
            $result = $this->pushToDailyAssessment(
                $submission->fresh(), $assignment, $data['score_type'], (float) $data['score']
            );
            if (($result['saved'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'score' => 'Nilai belum disimpan: ' . ($result['reason'] ?? 'Buku Nilai gagal ditulis.'),
                ]);
            }
            return $result;
        });

        Log::info('LMS submission dinilai guru (web).', [
            'submission_id' => $submission->id,
            'assignment_id' => $assignment->id,
            'student_login_id' => $submission->student_id,
            'score' => $data['score'],
            'score_type' => $data['score_type'],
            'grade_version' => $submission->grade_version,
            'daily_saved' => (bool) ($dailyResult['saved'] ?? false),
            'teacher_id' => (int) $this->getTeacher()->id,
        ]);

        /*
         * [AP-04] Payload FIELD-GURU SAJA ke siswa — jawaban siswa
         * tidak pernah ditimpa oleh event penilaian.
         */
        $studentDeviceId = null;

        try {
            $studentNisn = trim((string) ($submission->student_nisn ?? $submission->student_id));
            $studentDeviceId = $studentNisn !== ''
                ? app(\App\Services\Sync\SchoolIdentity::class)->deviceId($studentNisn, 'HP')
                : null;
        } catch (Throwable $e) {
            $studentDeviceId = null;
        }

        $gradePayload = $this->buildGradeFieldsPayload($submission, $assignment);

        $directResult = null;

        try {
            if ($studentDeviceId !== null) {
                $directResult = $syncService->queueAndPush(
                    'lms_assignment_submission',
                    (string) $submission->sync_id,
                    'upsert',
                    $gradeVersion,
                    $gradePayload,
                    $studentDeviceId,
                    null
                );
            } else {
                $directResult = [
                    'success' => false,
                    'status' => 'pending',
                    'error' => 'REJECTED::TARGET_DEVICE_NOT_FOUND',
                ];
            }
        } catch (Throwable $e) {
            $directResult = ['success' => false, 'status' => 'pending', 'error' => $e->getMessage()];
        }

        /* Fan-out guru: teacher-routed, payload field-guru juga. */
        try {
            $syncService->queueAndPush(
                'lms_assignment_submission',
                (string) $submission->sync_id,
                'upsert',
                $gradeVersion,
                $gradePayload,
                null,
                null
            );
        } catch (Throwable $e) {
            report($e);
        }

        $directOk = ($directResult['success'] ?? false) === true
            && in_array($directResult['status'] ?? '', ['accepted', 'duplicate', 'success'], true);

        if (!$directOk) {
            return back()->with(
                'sync_warning',
                '✓ Nilai & feedback TERSIMPAN AMAN di server guru (termasuk buku nilai harian). '
                . $this->queuedReasonFrom($directResult)
                . ' Nilai akan terkirim OTOMATIS ke perangkat siswa oleh antrean — tidak perlu tindakan apa pun. '
                . 'Login ID siswa: ' . ($submission->student_id ?? '-') . '.'
                . $this->dailyResultSuffix($dailyResult)
            );
        }

        $message = 'Nilai & feedback diterima antrean Cloud untuk siswa. '
            . 'Penerimaan di HP siswa belum terkonfirmasi sampai perangkat siswa melakukan sinkronisasi dan ACK.'
            . $this->dailyResultSuffix($dailyResult, true);

        return back()->with('sync_success', $message);
    }

    /**
     * [AP-04] Payload penilaian — field milik guru saja.
     * grade_version menuntun LWW domain guru di penerima.
     */
    protected function buildGradeFieldsPayload(LmsAssignmentSubmission $submission, LmsAssignment $assignment): array
    {
        $studentNisn = trim((string) ($submission->student_nisn ?? $submission->student_id));

        $studentName = DB::table('students')
            ->where('nisn', $studentNisn)
            ->value('nama');

        return [
            'sync_id' => $submission->sync_id,
            'entity' => 'lms_assignment_submission',
            'assignment_id' => $assignment->id,
            'assignment_sync_id' => $assignment->sync_id,
            'student_id' => $studentNisn,
            'student_nisn' => $studentNisn,
            'student_name' => $studentName ?? 'Siswa',
            'score' => $submission->score,
            'teacher_feedback' => $submission->teacher_feedback,
            'feedback' => $submission->teacher_feedback,
            'status' => 'graded',
            'score_type' => $submission->score_type,
            'grade_version' => (int) ($submission->grade_version ?? 0),
            'sync_version' => (int) ($submission->grade_version ?? $submission->sync_version ?? 1),
            'graded_at' => $submission->updated_at?->toISOString(),
            'assessment_date' => $submission->submitted_at?->toDateString(),
        ];
    }

    /**
     * [FIX-DAILY-RESULT] Sufiks pesan dari hasil nilai harian yang
     * SESUNGGUHNYA (dulu selalu membaca variabel tak terdefinisi).
     */
    private function dailyResultSuffix(?array $dailyResult, bool $verbose = false): string
    {
        if (($dailyResult['saved'] ?? false) === true) {
            if (!$verbose) {
                return '';
            }

            return ' Nilai harian (' . ($dailyResult['score_type'] ?? '-') . ') tercatat pada '
                . ($dailyResult['tanggal'] ?? '-') . '.';
        }

        $reason = trim((string) ($dailyResult['reason'] ?? ''));

        if ($reason === '') {
            return '';
        }

        return ' Catatan: nilai harian tidak tercatat — ' . $reason;
    }

    /*
     * Fan-out salinan nilai ke device teacher — TIDAK pernah gagalkan
     * respons guru: kegagalan hanya dicatat, outbox mengurus retry.
     */
    protected function fanOutGradedToTeachersSafely(
        LmsSyncService $syncService,
        LmsAssignmentSubmission $submission
    ): void {
        try {
            $syncService->pushGradedSubmissionToTeachers($submission->fresh());
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function queuedReasonFrom(?array $result): string
    {
        $error = (string) ($result['error'] ?? '');

        if (
            str_contains($error, 'TARGET_DEVICE_NOT_FOUND')
            || str_contains($error, 'Perangkat tujuan')
        ) {
            return 'Perangkat siswa belum terdaftar di Sync Server (siswa belum pernah membuka LMS).';
        }

        return 'Sync Server belum terjangkau.';
    }

    /* ============================================================
     * NILAI HARIAN DARI PENILAIAN TUGAS
     * ============================================================ */

    protected function pushToDailyAssessment(
        LmsAssignmentSubmission $submission,
        ?LmsAssignment $assignment,
        string $scoreType,
        float $score
    ): array {
        $fallback = [
            'saved' => false,
            'tanggal' => null,
            'reason' => '',
        ];

        if (!$assignment) {
            return array_replace($fallback, ['reason' => 'assignment tidak ditemukan.']);
        }

        $assignment->loadMissing('topic.course');

        $course = $assignment->topic->course ?? null;

        if (!$course || !$course->subject_id || !$course->homeroom_id) {
            return array_replace($fallback, [
                'reason' => 'kelas LMS belum terhubung ke mata pelajaran/rombel.',
            ]);
        }

        $teacher = $this->getTeacher();

        $teachingAssignment = DB::table('teaching_assignments')
            ->where('subject_id', $course->subject_id)
            ->where('homeroom_id', $course->homeroom_id)
            ->where('teacher_id', $teacher->id)
            ->orderByDesc('id')
            ->first();

        if (!$teachingAssignment) {
            return array_replace($fallback, [
                'reason' => 'penugasan mengajar untuk mapel/kelas ini belum ada atas nama Anda — tambahkan lewat menu Mengajar (data Dapodik).',
            ]);
        }

        $tanggal = $submission->submitted_at
            ? $submission->submitted_at->toDateString()
            : now()->toDateString();

        $studentId = $submission->getAttribute('student_local_id')
            ?? app(DailyAssessmentWriter::class)->resolveStudentId($submission->student_id);

        if (!$studentId) {
            return array_replace($fallback, [
                'reason' => 'siswa tidak ditemukan di tabel students (login_id: '
                    . $submission->student_id . ').',
            ]);
        }

        $materi = 'Tugas: ' . ($assignment->title ?? 'Aktivitas LMS');

        /*
         * [06.1] Versi baris = versi penilaian submission — LWW
         * lintas jalur (HP vs web vs batch) hidup untuk nilai ini.
         */
        $version = max(1, (int) $submission->sync_version);

        return app(DailyAssessmentWriter::class)->writeScore(
            (int) $studentId, (int) $teachingAssignment->id, $tanggal,
            $scoreType, app(DailyAssessmentWriter::class)->assignmentScore($score, $assignment->max_score),
            $materi, $version
        ) + ['score_type' => $scoreType];
    }

    /**
     * Resolusi student_id dari login_id siswa.
     *
     * Fallback by nama adalah JALUR TERAKHIR — bila NISN tidak match
     * (mis. import user belum sinkron siswa). Bisa ambigu untuk nama
     * kembar; dipertahankan demi kasus jarang namun realistis.
     */
    protected function resolveStudentId(?string $loginId): ?int
    {
        $loginId = trim((string) $loginId);

        if ($loginId === '') {
            return null;
        }

        $studentId = DB::table('students')
            ->where('nisn', $loginId)
            ->value('id');

        if ($studentId) {
            return (int) $studentId;
        }

        $userName = DB::table('users')
            ->where('login_id', $loginId)
            ->value('name');

        if ($userName) {
            $studentId = DB::table('students')
                ->where('nama', $userName)
                ->value('id');

            if ($studentId) {
                return (int) $studentId;
            }
        }

        return null;
    }
}
