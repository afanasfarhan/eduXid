<?php

namespace App\Http\Controllers\Siswa;


use App\Services\AcademicPeriodService;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsTopic;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LmsSiswaController extends Controller
{
    /* ============================================================
     * DASHBOARD LMS SISWA
     * ============================================================ */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'User belum login.');
        }

        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);
        $npsn = $this->getNpsn();

        /*
         * Semua rombel semester aktif (reguler, mapel pilihan,
         * kokurikuler, ekstrakurikuler) — LMS bisa dibuat guru pada
         * rombel mana pun yang dipegangnya.
         */
        $enrollments = $student->enrollments()
            ->with('homeroom')
            ->whereHas('homeroom', fn ($query) => $query->where('semester_id', $semesterId))
            ->orderBy('id')
            ->get();

        $homeroomIds = $enrollments->pluck('homeroom_id')->filter()->unique()->values();

        $homeroom = $enrollments
            ->map(fn ($enrollment) => $enrollment->homeroom)
            ->filter()
            ->first(fn ($room) => $room->jenis_rombel === 'Kelas');

        /*
         * [PUB-FIX] Topik/materi/tugas draft tidak dihitung & tidak tampil.
         * [COLUMN-FIX] orderBy sort_order (kolom yang dipakai seluruh sistem).
         */
        $courses = LmsCourse::query()
            ->with([
                'homeroom',
                'subject',
                'topics' => function ($query) {
                    $query
                        ->where('is_published', true)
                        ->withCount([
                            'materials' => fn ($q) => $q->where('is_published', true),
                            'assignments' => fn ($q) => $q->where('is_published', true),
                        ])
                        ->orderBy('sort_order');
                },
            ])
            ->whereIn('homeroom_id', $homeroomIds)
            ->where('is_published', true)
            ->whereHas('homeroom', fn ($query) => $query->where('semester_id', $semesterId))
            ->orderBy('name')
            ->get();

        return view('siswa.lms.index', [
            'student' => $student,
            'enrollments' => $enrollments,
            'homeroom' => $homeroom,
            'courses' => $courses,
            'semesterId' => $semesterId,
            'homeroomIds' => $homeroomIds,
            'npsn' => $npsn,
        ]);
    }

    /* ============================================================
     * DETAIL COURSE LMS
     * ============================================================ */
    public function course(LmsCourse $course)
    {
        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);
        $npsn = $this->getNpsn();

        $course->load([
            'homeroom',
            'subject',
            'topics' => function ($query) {
                $query
                    ->where('is_published', true)
                    ->withCount([
                        'materials' => fn ($q) => $q->where('is_published', true),
                        'assignments' => fn ($q) => $q->where('is_published', true),
                    ])
                    /*
                     * [COLUMN-FIX] Sebelumnya orderBy('urutan') — kolom topik
                     * adalah sort_order (dipakai pushTopic & duplicate).
                     */
                    ->orderBy('sort_order');
            },
        ]);

        if (!$course->homeroom) {
            abort(404, 'Rombel kelas LMS tidak ditemukan.');
        }

        if ((string) $course->homeroom->semester_id !== (string) $semesterId) {
            abort(404, 'Kelas LMS tidak ditemukan pada semester aktif.');
        }

        $this->ensureStudentBelongsToHomeroom($student, $course->homeroom_id, $semesterId);

        if (!$course->is_published) {
            abort(403, 'Kelas LMS ini belum dipublikasikan.');
        }

        return view('siswa.lms.course', [
            'student' => $student,
            'course' => $course,
            'semesterId' => $semesterId,
            'npsn' => $npsn,
        ]);
    }

    /* ============================================================
     * DETAIL TOPIK LMS
     * ============================================================ */
    public function topic(LmsTopic $topic)
    {
        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);
        $npsn = $this->getNpsn();

        /*
         * [PUB-FIX] Hanya materi & tugas yang sudah dipublikasikan.
         */
        $topic->load([
            'course.homeroom',
            'course.subject',
            'materials' => fn ($query) => $query
                ->where('is_published', true)
                ->orderBy('sort_order'),
            'assignments' => fn ($query) => $query
                ->where('is_published', true),
        ]);

        if (!$topic->course) {
            abort(404, 'Kelas LMS untuk topik ini tidak ditemukan.');
        }

        $course = $topic->course;

        if (!$course->homeroom) {
            abort(404, 'Rombel kelas LMS tidak ditemukan.');
        }

        if ((string) $course->homeroom->semester_id !== (string) $semesterId) {
            abort(404, 'Kelas LMS tidak ditemukan pada semester aktif.');
        }

        $this->ensureStudentBelongsToHomeroom($student, $course->homeroom_id, $semesterId);

        if (!$course->is_published) {
            abort(403, 'Kelas LMS ini belum dipublikasikan.');
        }

        if (!$topic->is_published) {
            abort(403, 'Topik LMS ini belum dipublikasikan.');
        }

        return view('siswa.lms.topic', [
            'student' => $student,
            'topic' => $topic,
            'course' => $course,
            'semesterId' => $semesterId,
            'npsn' => $npsn,
        ]);
    }

    /* ============================================================
     * DETAIL ASSIGNMENT SISWA
     * ============================================================ */
    public function assignment(LmsAssignment $assignment)
    {
        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);
        $npsn = $this->getNpsn();

        /*
         * IDENTITAS LMS SISWA:
         *   users.login_id -> lms_assignment_submissions.student_id
         * students.id TIDAK digunakan sebagai identitas submission.
         */
        $loginId = $this->getStudentLoginId();

        [$topic, $course] = $this->authorizeAssignmentForStudent($assignment, $student, $semesterId);

        $submission = $this->getExistingSubmission((int) $assignment->id, $loginId);

        return view('siswa.lms.assignment', [
            'student' => $student,
            'assignment' => $assignment,
            'topic' => $topic,
            'course' => $course,
            'submission' => $submission,
            'semesterId' => $semesterId,
            'npsn' => $npsn,
        ]);
    }

    /* ============================================================
     * HALAMAN UJIAN CBT OFFLINE
     *
     * Shell halaman saja — soal/timer/auto-save/submit berjalan di
     * browser (IndexedDB). [CBT-AUTH] Akses tetap divalidasi:
     * siswa harus anggota rombel course yang menerbitkan ujian,
     * konsisten dengan startCbt().
     * ============================================================ */
    public function cbtUjian(Request $request, string $examSyncId)
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'User belum login.');
        }

        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);
        $npsn = $this->getNpsn();

        $exam = Exam::query()->where('sync_id', $examSyncId)->first();

        if (!$exam) {
            abort(404, 'Ujian CBT tidak ditemukan.');
        }

        $assignment = LmsAssignment::query()
            ->with('topic.course.homeroom')
            ->where('exam_id', $exam->id)
            ->first();

        if (!$assignment || !$assignment->topic?->course?->homeroom) {
            abort(404, 'Aktivitas LMS untuk ujian ini tidak ditemukan.');
        }

        $course = $assignment->topic->course;

        if ((string) $course->homeroom->semester_id !== (string) $semesterId) {
            abort(404, 'Ujian tidak ditemukan pada semester aktif.');
        }

        $this->ensureStudentBelongsToHomeroom($student, $course->homeroom_id, $semesterId);

        if (!$course->is_published || !$assignment->is_published) {
            abort(403, 'Ujian ini belum dipublikasikan.');
        }

        return view('siswa.lms.cbt', [
            'student' => $student,
            'examSyncId' => $examSyncId,
            'npsn' => $npsn,
        ]);
    }

    /* ============================================================
     * MULAI CBT DARI LMS
     * ============================================================ */
    public function startCbt(LmsAssignment $assignment)
    {
        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);

        [$topic, $course] = $this->authorizeAssignmentForStudent($assignment, $student, $semesterId);

        if ($assignment->assignment_type !== 'cbt' || empty($assignment->exam_id)) {
            return redirect()
                ->route('siswa.lms.assignment', $assignment->id)
                ->with('error', 'Aktivitas ini bukan CBT atau belum terhubung dengan ujian.');
        }

        /*
         * LMS tidak membuat mekanisme CBT baru — modul CBT yang ada
         * tetap menangani ujian.
         */
        return redirect()
            ->route('cbt.index', [
                'exam_id' => $assignment->exam_id,
                'lms_assignment_id' => $assignment->id,
            ]);
    }

    /* ============================================================
     * SUBMIT ASSIGNMENT SISWA
     *
     * - Satu siswa = satu submission per assignment.
     * - sync_id permanen; tiap perubahan menaikkan sync_version.
     * - sync_status = pending, last_synced_at = null.
     * - score & teacher_feedback guru TIDAK disentuh.
     * - Tidak bergantung Sync Server.
     *
     * IDENTITAS: users.login_id -> submissions.student_id
     * ============================================================ */
    public function submitAssignment(Request $request, LmsAssignment $assignment)
    {
        $semesterId = $this->getActiveSemester();
        $student = $this->getStudent($semesterId);
        $loginId = $this->getStudentLoginId();

        [$topic, $course] = $this->authorizeAssignmentForStudent($assignment, $student, $semesterId);

        if ($assignment->assignment_type === 'cbt') {
            abort(403, 'Aktivitas CBT harus dikerjakan melalui modul ujian.');
        }

        $submissionType = $assignment->submission_type;

        if (!in_array($submissionType, ['text', 'file', 'text_file'], true)) {
            abort(422, 'Metode pengumpulan tugas tidak valid.');
        }

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string'],
            'answer_file' => ['nullable', 'file', 'max:10240'],
        ]);

        $answerText = trim((string) ($validated['answer_text'] ?? ''));
        $hasFile = $request->hasFile('answer_file');

        if ($submissionType === 'text') {
            if ($answerText === '') {
                return back()->withInput()->with('error', 'Jawaban teks tidak boleh kosong.');
            }
            $hasFile = false;
        }

        if ($submissionType === 'file') {
            if (!$hasFile) {
                return back()->withInput()->with('error', 'File jawaban wajib diunggah.');
            }
            $answerText = '';
        }

        if ($submissionType === 'text_file' && $answerText === '' && !$hasFile) {
            return back()->withInput()->with('error', 'Isi jawaban teks atau file jawaban.');
        }

        $submission = $this->getExistingSubmission((int) $assignment->id, $loginId);

        $oldFilePath = $submission?->file_path;

        $filePath = $oldFilePath;
        $fileName = $submission?->file_name;
        $mimeType = $submission?->mime_type;
        $fileSize = $submission?->file_size;

        /*
         * Di luar try: jika DB gagal setelah file tersimpan,
         * file baru dapat dibersihkan (tidak jadi file yatim).
         */
        $newFilePath = null;

        if ($hasFile) {
            $file = $request->file('answer_file');

            /*
             * students.id hanya untuk path storage — identitas
             * submission tetap users.login_id.
             */
            $directory = 'lms/submissions/' . $student->id . '/' . $assignment->id;

            $newFilePath = $file->store($directory, 'public');
            $filePath = $newFilePath;
            $fileName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();
        }

        try {
            DB::transaction(function () use (
                $submission,
                $assignment,
                $loginId,
                $answerText,
                $filePath,
                $fileName,
                $mimeType,
                $fileSize
            ) {
                $now = now();

                if ($submission) {
                    /*
                     * UPDATE — sync_id TIDAK berubah, version +1,
                     * score/teacher_feedback tidak disentuh.
                     */
                    $syncId = $submission->sync_id ?: (string) Str::uuid();
                    $syncVersion = ((int) ($submission->sync_version ?? 0)) + 1;

                    DB::table('lms_assignment_submissions')
                        ->where('id', $submission->id)
                        ->update([
                            'sync_id' => $syncId,
                            'student_id' => $loginId, // SELALU users.login_id
                            'answer_text' => $answerText !== '' ? $answerText : null,
                            'file_path' => $filePath,
                            'file_name' => $fileName,
                            'mime_type' => $mimeType,
                            'file_size' => $fileSize,
                            'submitted_at' => $now,
                            'sync_version' => $syncVersion,
                            'sync_status' => 'pending',
                            'last_synced_at' => null,
                            'status' => 'submitted',
                            'updated_at' => $now,
                        ]);

                    return;
                }

                DB::table('lms_assignment_submissions')->insert([
                    'sync_id' => (string) Str::uuid(),
                    'assignment_id' => $assignment->id,
                    'student_id' => $loginId, // users.login_id
                    'answer_text' => $answerText !== '' ? $answerText : null,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'submitted_at' => $now,
                    'score' => null,
                    'teacher_feedback' => null,
                    'status' => 'submitted',
                    'sync_status' => 'pending',
                    'sync_version' => 1,
                    'last_synced_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            /*
             * Hapus file lama HANYA setelah DB berhasil.
             */
            if ($hasFile && $oldFilePath && $oldFilePath !== $filePath) {
                Storage::disk('public')->delete($oldFilePath);
            }

            return redirect()
                ->route('siswa.lms.assignment', $assignment->id)
                ->with('success', 'Jawaban berhasil disimpan dan menunggu sinkronisasi.');
        } catch (Throwable $e) {
            if ($newFilePath) {
                Storage::disk('public')->delete($newFilePath);
            }

            report($e);

            return back()->withInput()->with('error', 'Jawaban gagal disimpan. Silakan coba lagi.');
        }
    }

    /* ============================================================
     * HELPER: otorisasi assignment (dipakai assignment/startCbt/submit)
     * ============================================================ */
    private function authorizeAssignmentForStudent(
        LmsAssignment $assignment,
        Student $student,
        string $semesterId
    ): array {
        $assignment->loadMissing(['topic.course.homeroom', 'topic.course.subject', 'exam']);

        if (!$assignment->topic) {
            abort(404, 'Topik aktivitas tidak ditemukan.');
        }

        $topic = $assignment->topic;

        if (!$topic->course) {
            abort(404, 'Kelas LMS untuk aktivitas ini tidak ditemukan.');
        }

        $course = $topic->course;

        if (!$course->homeroom) {
            abort(404, 'Rombel kelas LMS tidak ditemukan.');
        }

        if ((string) $course->homeroom->semester_id !== (string) $semesterId) {
            abort(404, 'Kelas LMS tidak ditemukan pada semester aktif.');
        }

        $this->ensureStudentBelongsToHomeroom($student, $course->homeroom_id, $semesterId);

        if (!$course->is_published) {
            abort(403, 'Kelas LMS ini belum dipublikasikan.');
        }

        if (!$assignment->is_published) {
            abort(403, 'Aktivitas ini belum dipublikasikan oleh guru.');
        }

        return [$topic, $course];
    }

    private function getExistingSubmission(int $assignmentId, string $loginId): ?object
    {
        return DB::table('lms_assignment_submissions')
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $loginId)
            ->orderByDesc('updated_at')
            ->first();
    }

    /* ============================================================
     * LOGIN ID SISWA — identitas resmi submission LMS
     * ============================================================ */
    private function getStudentLoginId(): string
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'User belum login.');
        }

        $loginId = trim((string) $user->login_id);

        if ($loginId === '') {
            abort(403, 'Login ID murid tidak ditemukan.');
        }

        return $loginId;
    }

    /* ============================================================
     * DATA STUDENT LOGIN
     *
     * [DUP-FIX] Implementasi sekarang sesuai komentar: bila NISN
     * terduplikasi, pilih record yang punya enrollment pada semester
     * aktif (paling relevan untuk LMS); fallback ke record pertama.
     * ============================================================ */
    private function getStudent(?string $semesterId = null): Student
    {
        $loginId = $this->getStudentLoginId();

        $query = Student::where('nisn', $loginId);

        if ($semesterId !== null) {
            $preferred = (clone $query)
                ->whereHas('enrollments.homeroom', fn ($q) => $q->where('semester_id', $semesterId))
                ->orderBy('id')
                ->first();

            if ($preferred) {
                return $preferred;
            }
        }

        $student = $query->orderBy('id')->first();

        if (!$student) {
            abort(404, 'Data murid tidak ditemukan.');
        }

        return $student;
    }

    /* ============================================================
     * SEMESTER AKTIF
     * ============================================================ */
    private function getActiveSemester(): string
    {
        return (string) (
            AcademicPeriodService::selectedOrLatest() ?? ''
        );
    }

    /* ============================================================
     * NPSN — school_id & bagian device ID siswa: <NPSN>-<NISN>-HP
     * Sumber: tabel schools (sync Dapodik) — nol konfigurasi manual.
     * ============================================================ */
    private function getNpsn(): string
    {
        $npsn = trim((string) DB::table('schools')->value('npsn'));

        if ($npsn !== '') {
            return $npsn;
        }

        return trim((string) config('eduxid.school_id', ''));
    }

    /* ============================================================
     * VALIDASI KEANGGOTAAN ROMBEL
     * ============================================================ */
    private function ensureStudentBelongsToHomeroom(Student $student, $homeroomId, string $semesterId): void
    {
        $isMember = $student->enrollments()
            ->where('homeroom_id', $homeroomId)
            ->whereHas('homeroom', fn ($query) => $query->where('semester_id', $semesterId))
            ->exists();

        if (!$isMember) {
            abort(403, 'Anda tidak terdaftar pada kelas LMS ini.');
        }
    }
}