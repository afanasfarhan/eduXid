<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Models\LmsTopic;
use App\Models\Teacher;
use App\Services\Sync\LmsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LmsAssignmentController extends Controller
{
    /*
     * ==========================================================
     * [REWRITE-12b → F5] LMS ASSIGNMENT CONTROLLER
     * F5: show() mengirim pendingCbtSessions + cbtAutoGradeable
     * ke view (panel koreksi per-siswa + tombol koreksi otomatis).
     * ==========================================================
     */

    private function getTeacher(): Teacher
    {
        $user = auth()->user();

        $teacher = $user ? $user->teacher() : null;

        if (!$teacher) {
            $loginId = trim((string) ($user->login_id ?? ''));

            if ($loginId !== '') {
                $teacher = Teacher::where(function ($q) use ($loginId) {
                    $q->where('nip', $loginId)
                      ->orWhere('nik', $loginId)
                      ->orWhere('nuptk', $loginId);
                })->first();
            }
        }

        if (!$teacher) {
            abort(403, 'Akun yang login belum terhubung dengan data GTK.');
        }

        return $teacher;
    }

    private function authorizeAssignmentOwner(LmsAssignment $assignment): Teacher
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

    private function authorizeTopicOwner(LmsTopic $topic): Teacher
    {
        $topic->loadMissing('course');

        if (!$topic->course) {
            abort(404, 'Kelas LMS untuk topik ini tidak ditemukan.');
        }

        $teacher = $this->getTeacher();

        if ((int) $topic->course->teacher_id !== (int) $teacher->id) {
            abort(403, 'Anda tidak memiliki akses ke topik ini.');
        }

        return $teacher;
    }

    private function normalizeTingkatKelas(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $grade = (int) $value;
            return in_array($grade, [10, 11, 12], true) ? $grade : null;
        }

        $text = trim((string) $value);

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

    private function topicGrade(LmsTopic $topic): ?int
    {
        $topic->loadMissing('course');
        $homeroomId = (int) ($topic->course?->homeroom_id ?? 0);

        if ($homeroomId <= 0) {
            return null;
        }

        return $this->normalizeTingkatKelas(
            DB::table('homerooms')->where('id', $homeroomId)->value('tingkat_pendidikan')
        );
    }

    private function validateExamForTopic($examId, LmsTopic $topic): ?string
    {
        if (empty($examId)) {
            return null;
        }

        $topic->loadMissing('course');
        $exam = Exam::find((int) $examId);

        if (!$exam) {
            return 'Ujian CBT tidak ditemukan.';
        }

        if ((int) $exam->teacher_id !== (int) $topic->course->teacher_id) {
            return 'Ujian CBT tersebut bukan milik Anda.';
        }

        if (!empty($topic->course->subject_id)
            && (int) $exam->subject_id !== (int) $topic->course->subject_id) {
            return 'Mata pelajaran ujian CBT tidak sama dengan mata pelajaran kelas LMS ini.';
        }

        $examGrade = $this->normalizeTingkatKelas($exam->tingkat_kelas ?? null);
        $topicGrade = $this->topicGrade($topic);

        if ($examGrade !== null && $topicGrade === null) {
            return 'Tingkat kelas rombel LMS belum teridentifikasi. Periksa data kelas/rombel pada Pembelajaran.';
        }

        if ($examGrade !== null && $topicGrade !== null && $examGrade !== $topicGrade) {
            return "Ujian CBT ini untuk Kelas {$examGrade}, sedangkan kelas LMS berada pada Kelas {$topicGrade}.";
        }

        return null;
    }

    private function syncIssueFrom(?array $result): ?string
    {
        if (!is_array($result)) {
            return 'respon sinkronisasi tidak valid';
        }

        $status = strtoupper((string) ($result['status'] ?? ''));

        if (($result['success'] ?? false) === true
            && in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)) {
            return null;
        }

        $error = trim((string) ($result['error'] ?? ($result['message'] ?? '')));

        if ($status === 'PENDING') {
            return 'menunggu sinkronisasi (akan dicoba ulang otomatis oleh antrean)';
        }

        if ($status === 'FAILED') {
            return 'gagal disinkronkan' . ($error !== '' ? ': ' . $error : '')
                . ' (akan dicoba ulang otomatis oleh antrean)';
        }

        return 'belum terkonfirmasi oleh Sync Server';
    }

    private function logSyncIssue(string $action, LmsAssignment $assignment, ?string $issue): void
    {
        Log::warning('Sinkronisasi LMS assignment bermasalah.', [
            'action' => $action,
            'assignment_id' => $assignment->id,
            'sync_id' => $assignment->sync_id,
            'topic_id' => $assignment->topic_id,
            'is_published' => (bool) $assignment->is_published,
            'issue' => $issue,
        ]);
    }

    private function pushLinkedExam(LmsAssignment $assignment, LmsSyncService $syncService, array &$warnings): void
    {
        if (strtolower((string) $assignment->assignment_type) !== 'cbt' || !$assignment->exam_id) {
            return;
        }

        $exam = Exam::find((int) $assignment->exam_id);

        if (!$exam) {
            $warnings[] = 'Paket ujian tidak ditemukan di lokal';
            return;
        }

        try {
            $issue = $this->syncIssueFrom($syncService->pushExam($exam, 'update'));

            if ($issue !== null) {
                $warnings[] = 'Paket ujian ' . $issue;
            }
        } catch (Throwable $e) {
            Log::warning('Push exam terkait aktivitas CBT gagal.', [
                'assignment_id' => $assignment->id,
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);

            $warnings[] = 'Paket ujian error: ' . $e->getMessage();
        }
    }

    public function index(LmsTopic $topic)
    {
        $this->authorizeTopicOwner($topic);

        $topic->load(['course', 'assignments' => function ($query) {
            $query->latest();
        }]);

        return view('lms.assignments.index', compact('topic'));
    }

    public function create(LmsTopic $topic)
    {
        $this->authorizeTopicOwner($topic);

        $topic->load('course');

        $topicGrade = $this->topicGrade($topic);

        $exams = Exam::query()
            ->where('teacher_id', $topic->course->teacher_id)
            ->when(!empty($topic->course->subject_id), fn ($q) => $q->where('subject_id', $topic->course->subject_id))
            ->when($topicGrade !== null, function ($q) use ($topicGrade) {
                $q->where(function ($gradeQuery) use ($topicGrade) {
                    $gradeQuery->where('tingkat_kelas', $topicGrade)
                        ->orWhereNull('tingkat_kelas');
                });
            })
            ->latest()
            ->get();

        return view('lms.assignments.create', compact('topic', 'exams'));
    }

    public function store(Request $request, LmsTopic $topic, LmsSyncService $syncService)
    {
        $teacher = $this->authorizeTopicOwner($topic);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'atp_item_id' => 'nullable|integer|exists:atp_items,id',
            'instructions' => 'nullable|string',
            'available_from' => 'nullable|date',
            'due_at' => 'nullable|date|after_or_equal:available_from',
            'submission_type' => 'required|in:text,file,text_file',
            'max_score' => 'required|numeric|min:0|max:1000',
            'assignment_type' => 'nullable|in:assignment,cbt',
            'exam_id' => 'nullable|integer|exists:exams,id',
            'is_published' => 'nullable|boolean',
        ]);

        $assignmentType = $validated['assignment_type'] ?? 'assignment';

        if ($assignmentType === 'cbt' && empty($validated['exam_id'])) {
            return back()->withErrors(['exam_id' => 'Aktivitas CBT harus terhubung dengan ujian CBT.'])->withInput();
        }

        $examError = $this->validateExamForTopic($validated['exam_id'] ?? null, $topic);

        if ($examError !== null) {
            return back()->withErrors(['exam_id' => $examError])->withInput();
        }

        if ($assignmentType === 'cbt') {
            $validated['submission_type'] = 'text';
        }

        $validated['is_published'] = $validated['is_published'] ?? false;

        $assignment = $topic->assignments()->create([
            ...$validated,
            'assignment_type' => $assignmentType,
            'sync_version' => 1,
        ]);

        if (!$validated['is_published']) {
            return redirect()
                ->route('lms.assignments.show', $assignment)
                ->with('success', 'Aktivitas berhasil dibuat sebagai DRAFT. '
                    . 'Tekan tombol "Publikasikan" saat siap untuk dikirim ke murid.');
        }

        $warnings = [];

        try {
            $issue = $this->syncIssueFrom(
                $syncService->pushAssignmentToStudents($assignment->fresh(['topic.course']), 'create')
            );

            if ($issue !== null) {
                $this->logSyncIssue('create', $assignment, $issue);
                $warnings[] = 'Aktivitas ' . $issue;
            }
        } catch (Throwable $e) {
            Log::warning('Aktivitas LMS berhasil dibuat tetapi gagal disinkronkan.', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Aktivitas error: ' . $e->getMessage();
        }

        $this->pushLinkedExam($assignment->fresh(['topic.course']), $syncService, $warnings);

        if ($warnings !== []) {
            return redirect()->route('lms.assignments.show', $assignment)
                ->with('warning', 'Aktivitas CBT berhasil dibuat. Sebagian menunggu sinkronisasi: '
                    . implode('; ', array_slice(array_unique($warnings), 0, 3)) . '.');
        }

        return redirect()->route('lms.assignments.show', $assignment)
            ->with('success', 'Aktivitas CBT dibuat dan disinkronkan — termasuk paket ujiannya ke perangkat murid.');
    }

    public function show(LmsAssignment $assignment)
    {
        $this->authorizeAssignmentOwner($assignment);

        $assignment->load(['topic.course', 'submissions.user', 'exam']);

        /*
         * [F5-UX] Daftar sesi CBT exam ini yang menunggu koreksi +
         * kelayakan koreksi otomatis (full-objektif).
         * [FIX] Kedua variabel WAJIB dikirim ke view — dulu dihitung
         * tapi compact('assignment') saja → panel koreksi tak pernah
         * tampil di halaman LMS.
         */
        $pendingCbtSessions = collect();
        $cbtAutoGradeable = false;

        if ($assignment->assignment_type === 'cbt' && $assignment->exam) {
            $pendingCbtSessions = DB::table('exam_sessions')
                ->join('students', 'students.id', '=', 'exam_sessions.student_id')
                ->where('exam_sessions.exam_id', $assignment->exam->id)
                ->where('exam_sessions.status', 'selesai')
                ->whereNull('exam_sessions.nilai_akhir')
                ->orderBy('exam_sessions.waktu_submit')
                ->select('exam_sessions.id', 'students.nama', 'students.nisn', 'exam_sessions.waktu_submit')
                ->get();

            $cbtAutoGradeable = $pendingCbtSessions->count() > 0
                && !$assignment->exam->questions->contains(function ($q): bool {
                    $jenis = strtolower(trim((string) ($q->jenis_soal ?? '')));
                    return str_contains($jenis, 'esai') || str_contains($jenis, 'singkat');
                });
        }

        return view('lms.assignments.show', [
            'assignment'         => $assignment,
            'pendingCbtSessions' => $pendingCbtSessions,
            'cbtAutoGradeable'   => $cbtAutoGradeable,
        ]);
    }

    public function edit(LmsAssignment $assignment)
    {
        $this->authorizeAssignmentOwner($assignment);

        $assignment->load(['topic.course', 'exam']);

        $topicGrade = $this->topicGrade($assignment->topic);

        $exams = Exam::query()
            ->where('teacher_id', $assignment->topic->course->teacher_id)
            ->when(!empty($assignment->topic->course->subject_id), fn ($q) => $q->where('subject_id', $assignment->topic->course->subject_id))
            ->when($topicGrade !== null, function ($q) use ($topicGrade) {
                $q->where(function ($gradeQuery) use ($topicGrade) {
                    $gradeQuery->where('tingkat_kelas', $topicGrade)
                        ->orWhereNull('tingkat_kelas');
                });
            })
            ->latest()
            ->get();

        return view('lms.assignments.edit', compact('assignment', 'exams'));
    }

    public function update(Request $request, LmsAssignment $assignment, LmsSyncService $syncService)
    {
        $this->authorizeAssignmentOwner($assignment);
        $topic = $assignment->topic;
        $topic->loadMissing('course');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'atp_item_id' => 'nullable|integer|exists:atp_items,id',
            'instructions' => 'nullable|string',
            'available_from' => 'nullable|date',
            'due_at' => 'nullable|date|after_or_equal:available_from',
            'submission_type' => 'required|in:text,file,text_file',
            'max_score' => 'required|numeric|min:0|max:1000',
            'assignment_type' => 'nullable|in:assignment,cbt',
            'exam_id' => 'nullable|integer|exists:exams,id',
            'is_published' => 'nullable|boolean',
        ]);

        $assignmentType = $validated['assignment_type'] ?? $assignment->assignment_type ?? 'assignment';

        if ($assignmentType === 'cbt' && empty($validated['exam_id']) && empty($assignment->exam_id)) {
            return back()->withErrors(['exam_id' => 'Aktivitas CBT harus terhubung dengan ujian CBT.'])->withInput();
        }

        $examError = $this->validateExamForTopic($validated['exam_id'] ?? null, $topic);

        if ($examError !== null) {
            return back()->withErrors(['exam_id' => $examError])->withInput();
        }

        if ($assignmentType === 'cbt') {
            $validated['submission_type'] = 'text';
        }

        $validated['assignment_type'] = $assignmentType;
        $validated['sync_version'] = DB::raw('sync_version + 1');   // [ATOMIC-VERSION]
        $validated['is_published'] = $validated['is_published'] ?? $assignment->is_published;

        $assignment->update($validated);

        if (!$validated['is_published']) {
            return redirect()
                ->route('lms.assignments.show', $assignment)
                ->with('success', 'Aktivitas berhasil diperbarui (masih draft). '
                    . 'Tekan tombol "Publikasikan" saat siap untuk dikirim ke murid.');
        }

        $warnings = [];

        try {
            $issue = $this->syncIssueFrom(
                $syncService->pushAssignmentToStudents($assignment->fresh(['topic.course']), 'update')
            );

            if ($issue !== null) {
                $this->logSyncIssue('update', $assignment, $issue);
                $warnings[] = 'Aktivitas ' . $issue;
            }
        } catch (Throwable $e) {
            Log::warning('Aktivitas LMS berhasil diperbarui tetapi gagal disinkronkan.', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Aktivitas error: ' . $e->getMessage();
        }

        $this->pushLinkedExam($assignment->fresh(['topic.course']), $syncService, $warnings);

        if ($warnings !== []) {
            return redirect()->route('lms.assignments.show', $assignment)
                ->with('warning', 'Aktivitas berhasil diperbarui. Sebagian menunggu sinkronisasi: '
                    . implode('; ', array_slice(array_unique($warnings), 0, 3)) . '.');
        }

        return redirect()->route('lms.assignments.show', $assignment)
            ->with('success', 'Aktivitas berhasil diperbarui dan disinkronkan.');
    }

    public function publish(LmsAssignment $assignment, LmsSyncService $syncService)
    {
        $this->authorizeAssignmentOwner($assignment);

        $assignment->update([
            'is_published' => true,
            'sync_version' => DB::raw('sync_version + 1'),   // [ATOMIC-VERSION]
        ]);

        $warnings = [];

        try {
            $issue = $this->syncIssueFrom(
                $syncService->pushAssignmentToStudents($assignment->fresh(['topic.course']), 'update')
            );

            if ($issue !== null) {
                $this->logSyncIssue('publish', $assignment, $issue);
                $warnings[] = 'Aktivitas ' . $issue;
            }
        } catch (Throwable $e) {
            Log::warning('Publikasi aktivitas gagal disinkronkan.', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Aktivitas error: ' . $e->getMessage();
        }

        /*
         * [CHAIN-FIX] Titik relay aktivasi: Publish aktivitas CBT
         * menjangkau exam (status apa adanya — bila ujian masih draft,
         * siswa menerima "belum diaktifkan guru" yang JUJUR sampai
         * guru menekan Aktifkan di modul CBT).
         */
        $this->pushLinkedExam($assignment->fresh(['topic.course']), $syncService, $warnings);

        if ($warnings !== []) {
            return back()->with('warning', 'Aktivitas berhasil dipublikasikan. Sebagian menunggu sinkronisasi: '
                . implode('; ', array_slice(array_unique($warnings), 0, 3)) . '.');
        }

        return back()->with('success', 'Aktivitas berhasil dipublikasikan dan disinkronkan'
            . (strtolower((string) $assignment->assignment_type) === 'cbt'
                ? ' (termasuk paket ujian terkait).' : '.'));
    }

    public function unpublish(LmsAssignment $assignment, LmsSyncService $syncService)
    {
        $this->authorizeAssignmentOwner($assignment);

        $assignment->loadMissing('topic.course');
        $course = $assignment->topic->course ?? null;
        $homeroomId = (int) ($course->homeroom_id ?? 0);

        $assignment->update([
            'is_published' => false,
            'sync_version' => DB::raw('sync_version + 1'),
        ]);

        /*
         * [D9] Unpublish aktivitas CBT JUGA MENARIK paket ujian dari
         * HP siswa (sejalan dengan destroy/CBT-PULL) — bila exam tidak
         * direferensikan aktivitas lain.
         */
        $warnings = [];

        if (
            strtolower((string) $assignment->assignment_type) === 'cbt'
            && $assignment->exam_id
            && $homeroomId > 0
        ) {
            $exam = Exam::find((int) $assignment->exam_id);

            $stillReferenced = $exam
                ? LmsAssignment::query()
                    ->where('exam_id', $exam->id)
                    ->where('id', '!=', $assignment->id)
                    ->exists()
                : true;

            $examSyncId = $exam ? trim((string) $exam->sync_id) : '';

            if ($exam && !$stillReferenced && $examSyncId !== '') {
                try {
                    $syncService->queueAndPush('cbt_exam', $examSyncId, 'delete', \App\Services\Sync\VersionService::next(), [
                        'sync_id' => $examSyncId,
                        'entity' => 'cbt_exam',
                        'id' => $exam->id,
                        'homeroom_id' => $homeroomId,
                        'target_homeroom_id' => $homeroomId,
                        'sync_version' => \App\Services\Sync\VersionService::next(),
                        'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
                    ], null, $homeroomId);
                } catch (Throwable $e) {
                    Log::warning('Penarikan paket CBT saat unpublish gagal pra-antrean.', [
                        'assignment_id' => $assignment->id,
                        'exam_id' => $exam->id,
                        'error' => $e->getMessage(),
                    ]);
                    $warnings[] = 'paket ujian "' . $exam->judul_ujian . '"';
                }
            }
        }

        try {
            $issue = $this->syncIssueFrom(
                $syncService->pushAssignmentToStudents($assignment->fresh(['topic.course']), 'update')
            );

            if ($issue !== null) {
                $this->logSyncIssue('unpublish', $assignment, $issue);
                $warnings[] = 'aktivitas ' . $issue;
            }
        } catch (Throwable $e) {
            Log::warning('Pembatalan publikasi gagal disinkronkan.', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'aktivitas error: ' . $e->getMessage();
        }

        if ($warnings !== []) {
            return back()->with('warning', 'Publikasi aktivitas dibatalkan. Sebagian penarikan menunggu sinkronisasi: '
                . implode('; ', array_slice(array_unique($warnings), 0, 3)) . '.');
        }

        return back()->with('success', 'Publikasi aktivitas dibatalkan'
            . (strtolower((string) $assignment->assignment_type) === 'cbt'
                ? ' — paket ujian terkait ikut ditarik dari perangkat murid.' : ' dan perubahan disinkronkan.'));
    }

    /*
     * ============================================================
     * [DELETE + CBT-PULL] Menghapus aktivitas CBT = menarik juga
     * paket ujiannya dari perangkat siswa. Exam DIPERTAHANKAN di
     * server (modul CBT/LAN) — hanya event delete-nya yang di-
     * broadcast ke siswa; dan hanya bila exam TIDAK direferensikan
     * assignment lain.
     * ============================================================
     */
    public function destroy(LmsAssignment $assignment, LmsSyncService $syncService)
    {
        $this->authorizeAssignmentOwner($assignment);

        $assignment->loadMissing('topic.course');

        $topic = $assignment->topic;
        $course = $topic->course;

        $syncId = trim((string) $assignment->sync_id);
        $assignmentId = $assignment->id;
        $courseId = $course->id;
        $homeroomId = (int) ($course->homeroom_id ?? 0);
        $deleteVersion = (int) floor(microtime(true) * 1000);
        $notices = [];

        /*
         * [CBT-PULL] Tentukan exam yang ikut ditarik — SEBELUM
         * assignment dihapus (referensi masih utuh).
         */
        $pullExam = null;

        if (strtolower((string) $assignment->assignment_type) === 'cbt' && $assignment->exam_id) {
            $exam = Exam::find((int) $assignment->exam_id);

            if ($exam) {
                $stillReferenced = LmsAssignment::query()
                    ->where('exam_id', $exam->id)
                    ->where('id', '!=', $assignment->id)
                    ->exists();

                if (!$stillReferenced && $homeroomId > 0) {
                    $pullExam = $exam;
                }
            }
        }

        /*
         * [CBT-PULL] Push delete exam DULU (homeroom eksplisit —
         * tidak bergantung assignment). Kegagalan tidak membatalkan
         * penghapusan assignment; masuk notice.
         */
        if ($pullExam) {
            $examSyncId = trim((string) $pullExam->sync_id);

            if ($examSyncId !== '') {
                try {
                    $syncService->queueAndPush('cbt_exam', $examSyncId, 'delete', $deleteVersion, [
                        'sync_id' => $examSyncId,
                        'entity' => 'cbt_exam',
                        'id' => $pullExam->id,
                        'homeroom_id' => $homeroomId,
                        'target_homeroom_id' => $homeroomId,
                        'sync_version' => $deleteVersion,
                        'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
                    ], null, $homeroomId);
                } catch (Throwable $e) {
                    Log::warning('Gagal menarik paket CBT saat hapus aktivitas.', [
                        'assignment_id' => $assignmentId,
                        'exam_id' => $pullExam->id,
                        'error' => $e->getMessage(),
                    ]);
                    $notices[] = 'paket ujian "' . $pullExam->judul_ujian . '"';
                }
            }
        }

        if ($syncId === '') {
            $assignment->delete();

            $suffix = $pullExam !== null
                ? ' Paket ujian terkait ikut ditarik dari perangkat murid.'
                : '';

            return redirect()->route('lms.courses.show', $courseId)
                ->with('success', 'Aktivitas berhasil dihapus (belum pernah tersinkronisasi, '
                    . 'tidak ada perintah hapus yang perlu dikirim).' . $suffix);
        }

        try {
            $result = $syncService->pushAssignmentDeleteToStudents($syncId, $deleteVersion);

            $status = strtoupper((string) ($result['status'] ?? ''));
            $ok = in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true);
            $queued = $status === 'PENDING' || ($result['success'] ?? false) === false;

            $assignment->delete();

            if ($notices !== []) {
                return redirect()->route('lms.courses.show', $courseId)
                    ->with('warning', 'Aktivitas dihapus. Perintah penarikan ' . implode(', ', $notices)
                        . ' masuk antrean dan akan dicoba ulang otomatis.');
            }

            if ($ok) {
                return redirect()->route('lms.courses.show', $courseId)
                    ->with('success', 'Aktivitas berhasil dihapus dan perintah hapus diteruskan ke perangkat murid.');
            }

            if ($queued) {
                Log::warning('DELETE aktivitas LMS masuk antrean — lokal dihapus.', [
                    'assignment_id' => $assignmentId,
                    'sync_id' => $syncId,
                    'status' => $status,
                ]);

                return redirect()->route('lms.courses.show', $courseId)
                    ->with('warning', 'Aktivitas dihapus di perangkat guru. Perintah hapus masuk '
                        . 'antrean sinkronisasi dan akan dicoba ulang otomatis.');
            }

            return redirect()->route('lms.courses.show', $courseId)
                ->with('warning', 'Aktivitas dihapus di perangkat guru, tetapi perintah hapus '
                    . 'belum terkonfirmasi (' . $status . ').');
        } catch (Throwable $e) {
            Log::warning('DELETE aktivitas LMS gagal masuk antrean. Penghapusan dibatalkan.', [
                'assignment_id' => $assignmentId,
                'sync_id' => $syncId,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Aktivitas belum dihapus karena perintah DELETE belum '
                . 'berhasil masuk antrean sinkronisasi (' . $e->getMessage() . ').');
        }
    }
}