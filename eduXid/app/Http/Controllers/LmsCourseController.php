<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsTopic;
use App\Models\SyncDevice;
use App\Models\TeachingAssignment;
use App\Services\Sync\LmsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LmsCourseController extends Controller
{
    public function index()
    {
        $teacher = $this->getTeacher();

        $courses = LmsCourse::with(['subject', 'homeroom', 'topics'])
            ->where('teacher_id', $teacher->id)
            ->latest()
            ->get();

        $user = auth()->user();
        $schoolId = trim((string) DB::table('schools')->value('npsn'));
        $eduxidPaired = false;

        if ($user && $schoolId !== '' && trim((string) $user->login_id) !== '') {
            try {
                $canonical = app(\App\Services\Sync\SchoolIdentity::class)
                    ->teacherPcDeviceId((string) $user->login_id);
                $eduxidPaired = SyncDevice::query()
                    ->where('school_id', $schoolId)
                    ->where('device_id', $canonical)
                    ->where('is_active', true)
                    ->whereNotNull('sync_token')
                    ->where('sync_token', '<>', '')
                    ->exists();
            } catch (\Throwable) {
                $eduxidPaired = false; // mapping belum ada: jangan fallback ke NIP/NIK.
            }
        }

        return view('lms.courses.index', compact('courses', 'eduxidPaired'));
    }

    /*
     * [PAIRING-FIX] LmsSyncService via method injection -
     * property $this->lmsSyncService sebelumnya tidak pernah ada
     * (fatal "Undefined property" saat form pairing disubmit).
     */
    public function cloudPair(Request $request, LmsSyncService $lmsSync)
    {
        $validated = $request->validate([
            'enrollment_token' => 'required|string|max:255',
        ]);

        try {
            $deviceId = $lmsSync->getDeviceId();

            $result = $lmsSync->registerTeacherDevice(
                $validated['enrollment_token'],
                $deviceId
            );

            /*
             * [PAIRING-FIX] JSON untuk fetch dashboard +
             * redirect fallback untuk form biasa.
             */
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Laptop berhasil terhubung ke eduXid Cloud.',
                    'device' => $result['device'],
                ]);
            }

            return back()->with('success', 'Laptop berhasil terhubung ke eduXid Cloud.');
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    private function getTeacher()
    {
        $user = auth()->user();
        $teacher = $user ? $user->teacher() : null;

        if (!$teacher) {
            abort(403, 'Akun yang login belum terhubung dengan data GTK.');
        }

        return $teacher;
    }

    private function getTeachingAssignments($teacher)
    {
        return TeachingAssignment::query()
            ->with(['subject', 'homeroom'])
            ->where('teacher_id', $teacher->id)
            ->get();
    }

    private function authorizeCourseOwner(LmsCourse $course, $teacher): void
    {
        if ((int) $course->teacher_id !== (int) $teacher->id) {
            abort(403, 'Anda tidak memiliki akses ke kelas LMS ini.');
        }
    }

    /*
     * [FIX-STATUS] Status SUCCESS kini dikenali (dulu push sukses
     * dilaporkan "belum terkonfirmasi").
     */
    private function syncIssueFrom(?array $result): ?string
    {
        if (!is_array($result)) {
            return 'Respon sinkronisasi tidak valid.';
        }

        $status = strtoupper((string) ($result['status'] ?? ''));

        if (
            ($result['success'] ?? false) === true
            && in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)
        ) {
            return null;
        }

        $error = trim((string) ($result['error'] ?? ($result['message'] ?? '')));

        if ($status === 'PENDING') {
            return 'menunggu sinkronisasi (target belum tersedia di Cloud).';
        }

        if ($status === 'FAILED') {
            return 'gagal disinkronkan' . ($error !== '' ? ': ' . $error : '') . '. Akan dicoba ulang otomatis oleh antrean.';
        }

        return 'belum terkonfirmasi oleh Sync Server.';
    }

    private function pushQuietly(callable $push, array &$warnings, string $label): void
    {
        try {
            $issue = $this->syncIssueFrom($push());

            if ($issue !== null) {
                $warnings[] = $label . ' ' . $issue;
            }
        } catch (Throwable $e) {
            $warnings[] = $label . ' error: ' . $e->getMessage();
        }
    }

    public function create()
    {
        $teacher = $this->getTeacher();
        $teachingAssignments = $this->getTeachingAssignments($teacher);

        $teachingPairs = $teachingAssignments
            ->filter(fn ($a) => $a->subject !== null && $a->homeroom !== null)
            ->map(fn ($a) => [
                'teaching_assignment_id' => $a->id,
                'subject_id' => $a->subject_id,
                'homeroom_id' => $a->homeroom_id,
                'semester_id' => $a->homeroom->semester_id ?? null,
            ])->values();

        $subjects = $teachingAssignments
            ->filter(fn ($a) => $a->subject !== null)
            ->map(function ($a) {
                $subject = $a->subject;
                $subject->name = $subject->nama_mata_pelajaran;
                return $subject;
            })->unique('id')->sortBy('nama_mata_pelajaran')->values();

        $homerooms = $teachingAssignments
            ->filter(fn ($a) => $a->homeroom !== null)
            ->map(function ($a) {
                $homeroom = $a->homeroom;
                $homeroom->name = $homeroom->nama;
                return $homeroom;
            })->unique('id')->sortBy('nama')->values();

        $firstAssignment = $teachingAssignments->first(fn ($a) => $a->homeroom !== null);
        $academicYear = null;
        $semester = null;

        if ($firstAssignment && $firstAssignment->homeroom) {
            $homeroom = $firstAssignment->homeroom;

            if (!empty($homeroom->academic_year)) { $academicYear = $homeroom->academic_year; }
            if (!empty($homeroom->tahun_pelajaran)) { $academicYear = $homeroom->tahun_pelajaran; }
            if (!empty($homeroom->semester)) { $semester = $homeroom->semester; }

            if ((!$academicYear || !$semester) && isset($homeroom->semester_id)) {
                $semesterId = (string) $homeroom->semester_id;

                if (!$semester) {
                    $kodeSemester = substr($semesterId, -1);
                    if ($kodeSemester === '1') { $semester = 'Ganjil'; }
                    elseif ($kodeSemester === '2') { $semester = 'Genap'; }
                }

                if (!$academicYear && strlen($semesterId) >= 5) {
                    $tahunAwal = substr($semesterId, 0, 4);
                    if (is_numeric($tahunAwal)) { $academicYear = $tahunAwal . '/' . ((int) $tahunAwal + 1); }
                }
            }
        }

        return view('lms.courses.create', [
            'teacher' => $teacher,
            'teachingPairs' => $teachingPairs,
            'subjects' => $subjects,
            'homerooms' => $homerooms,
            'academicYear' => $academicYear,
            'semester' => $semester,
        ]);
    }

    public function store(Request $request, LmsSyncService $lmsSync)
    {
        $validated = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'homeroom_id' => 'required|exists:homerooms,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'academic_year' => 'nullable|string|max:20',
            'semester' => 'nullable|string|max:20',
            'is_published' => 'nullable|boolean',
        ]);

        $teacher = $this->getTeacher();

        $teachingAssignment = TeachingAssignment::query()->with(['subject', 'homeroom'])
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $validated['subject_id'])
            ->where('homeroom_id', $validated['homeroom_id'])
            ->first();

        if (!$teachingAssignment) {
            return back()->withInput()->with('error', 'Kombinasi mata pelajaran dan kelas/rombel tersebut tidak terdapat pada TeachingAssignment Anda.');
        }

        if (!$teachingAssignment->subject || !$teachingAssignment->homeroom) {
            return back()->withInput()->with('error', 'TeachingAssignment ditemukan, tetapi data mata pelajaran atau rombel tidak lengkap.');
        }

        $homeroom = $teachingAssignment->homeroom;
        $academicYear = $validated['academic_year'] ?? null;
        $semester = $validated['semester'] ?? null;

        if (!$academicYear && isset($homeroom->semester_id)) {
            $semesterId = (string) $homeroom->semester_id;

            if (strlen($semesterId) >= 5) {
                $tahunAwal = substr($semesterId, 0, 4);
                if (is_numeric($tahunAwal)) { $academicYear = $tahunAwal . '/' . ((int) $tahunAwal + 1); }
            }
        }

        if (!$semester && isset($homeroom->semester_id)) {
            $kodeSemester = substr((string) $homeroom->semester_id, -1);
            if ($kodeSemester === '1') { $semester = 'Ganjil'; }
            elseif ($kodeSemester === '2') { $semester = 'Genap'; }
        }

        $existingCourse = LmsCourse::query()
            ->where('teacher_id', $teachingAssignment->teacher_id)
            ->where('subject_id', $teachingAssignment->subject_id)
            ->where('homeroom_id', $teachingAssignment->homeroom_id)
            ->first();

        if ($existingCourse) {
            return redirect()->route('lms.courses.show', $existingCourse)
                ->with('warning', 'Kelas LMS untuk mata pelajaran dan rombel tersebut sudah tersedia.');
        }

        $course = LmsCourse::create([
            'teacher_id' => $teachingAssignment->teacher_id,
            'subject_id' => $teachingAssignment->subject_id,
            'homeroom_id' => $teachingAssignment->homeroom_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'is_published' => (bool) ($validated['is_published'] ?? false),
            'sync_version' => 1,
        ]);

        try {
            $issue = $this->syncIssueFrom($lmsSync->pushCourse($course->fresh(), 'create'));

            if ($issue !== null) {
                return redirect()->route('lms.courses.show', $course)
                    ->with('warning', 'Kelas LMS berhasil dibuat di perangkat guru, tetapi ' . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Kelas LMS tersimpan lokal tetapi sinkronisasi mengalami error.', [
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('lms.courses.show', $course)
                ->with('warning', 'Kelas LMS berhasil dibuat di perangkat guru. Sinkronisasi akan dicoba kembali.');
        }

        return redirect()->route('lms.courses.show', $course)
            ->with('success', 'Kelas LMS berhasil dibuat dan disinkronkan.');
    }

    public function show(LmsCourse $course)
    {
        $teacher = $this->getTeacher();
        $this->authorizeCourseOwner($course, $teacher);

        $course->load(['subject', 'homeroom', 'topics.materials', 'topics.assignments']);

        return view('lms.courses.show', compact('course'));
    }

    public function edit(LmsCourse $course)
    {
        $teacher = $this->getTeacher();
        $this->authorizeCourseOwner($course, $teacher);

        $course->load(['subject', 'homeroom']);
        $teachingAssignments = $this->getTeachingAssignments($teacher);

        $teachingPairs = $teachingAssignments
            ->filter(fn ($a) => $a->subject !== null && $a->homeroom !== null)
            ->map(fn ($a) => [
                'teaching_assignment_id' => $a->id,
                'subject_id' => $a->subject_id,
                'homeroom_id' => $a->homeroom_id,
                'semester_id' => $a->homeroom->semester_id ?? null,
            ])->values();

        $subjects = $teachingAssignments
            ->filter(fn ($a) => $a->subject !== null)
            ->map(function ($a) {
                $subject = $a->subject;
                $subject->name = $subject->nama_mata_pelajaran;
                return $subject;
            })->unique('id')->sortBy('nama_mata_pelajaran')->values();

        $homerooms = $teachingAssignments
            ->filter(fn ($a) => $a->homeroom !== null)
            ->map(function ($a) {
                $homeroom = $a->homeroom;
                $homeroom->name = $homeroom->nama;
                return $homeroom;
            })->unique('id')->sortBy('nama')->values();

        return view('lms.courses.edit', compact('course', 'teacher', 'teachingPairs', 'subjects', 'homerooms'));
    }

    public function update(Request $request, LmsCourse $course, LmsSyncService $lmsSync)
    {
        $validated = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'homeroom_id' => 'required|exists:homerooms,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'academic_year' => 'nullable|string|max:20',
            'semester' => 'nullable|string|max:20',
            'is_published' => 'nullable|boolean',
        ]);

        $teacher = $this->getTeacher();
        $this->authorizeCourseOwner($course, $teacher);

        $teachingAssignment = TeachingAssignment::query()->with(['subject', 'homeroom'])
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $validated['subject_id'])
            ->where('homeroom_id', $validated['homeroom_id'])
            ->first();

        if (!$teachingAssignment) {
            return back()->withInput()->with('error', 'Kombinasi mata pelajaran dan kelas/rombel tersebut tidak terdapat pada TeachingAssignment Anda.');
        }

        $duplicateCourse = LmsCourse::query()
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $teachingAssignment->subject_id)
            ->where('homeroom_id', $teachingAssignment->homeroom_id)
            ->where('id', '!=', $course->id)
            ->exists();

        if ($duplicateCourse) {
            return back()->withInput()->with('error', 'Guru sudah memiliki Kelas LMS untuk mata pelajaran dan rombel tersebut.');
        }

        $oldHomeroomId = (int) $course->homeroom_id;
        $homeroomChanged = (int) $validated['homeroom_id'] !== $oldHomeroomId;
        $oldVersion = max(1, (int) $course->sync_version);

        if ($homeroomChanged && trim((string) $course->sync_id) !== '') {
            try {
                $lmsSync->queueAndPush('lms_course', (string) $course->sync_id, 'delete', $oldVersion, [
                    'sync_id' => $course->sync_id,
                    'entity' => 'lms_course',
                    'homeroom_id' => $oldHomeroomId,
                    'target_homeroom_id' => $oldHomeroomId,
                    'sync_version' => $oldVersion,
                ], null, $oldHomeroomId);
            } catch (Throwable $e) {
                Log::warning('Delete event ke rombel lama gagal saat perpindahan rombel course.', [
                    'course_id' => $course->id,
                    'old_homeroom_id' => $oldHomeroomId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $course->update([
            'teacher_id' => $teachingAssignment->teacher_id,
            'subject_id' => $teachingAssignment->subject_id,
            'homeroom_id' => $teachingAssignment->homeroom_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'academic_year' => $validated['academic_year'] ?? null,
            'semester' => $validated['semester'] ?? null,
            'is_published' => (bool) ($validated['is_published'] ?? false),
            'sync_version' => $oldVersion + 1,
        ]);

        try {
            $issue = $this->syncIssueFrom($lmsSync->pushCourse($course->fresh(), 'update'));

            if ($issue !== null) {
                return redirect()->route('lms.courses.show', $course)
                    ->with('warning', 'Perubahan berhasil disimpan di perangkat guru, tetapi ' . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Update LMS tersimpan lokal tetapi gagal sinkronisasi.', [
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('lms.courses.show', $course)
                ->with('warning', 'Perubahan berhasil disimpan di perangkat guru. Sinkronisasi akan dicoba kembali.');
        }

        return redirect()->route('lms.courses.show', $course)->with('success', $homeroomChanged
            ? 'Kelas LMS berhasil dipindahkan ke rombel baru dan disinkronkan (rombel lama menerima perintah penghapusan).'
            : 'Kelas LMS berhasil diperbarui dan disinkronkan.');
    }

    public function duplicateForm(LmsCourse $course)
    {
        $teacher = $this->getTeacher();
        $this->authorizeCourseOwner($course, $teacher);

        $course->load(['subject', 'homeroom', 'topics.materials', 'topics.assignments']);

        $teachingAssignments = TeachingAssignment::query()->with(['subject', 'homeroom'])
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $course->subject_id)
            ->get();

        $targetHomerooms = $teachingAssignments
            ->filter(function ($assignment) use ($course) {
                if (!$assignment->homeroom) { return false; }
                if ((int) $assignment->homeroom_id === (int) $course->homeroom_id) { return false; }

                $alreadyExists = LmsCourse::query()
                    ->where('teacher_id', $course->teacher_id)
                    ->where('subject_id', $course->subject_id)
                    ->where('homeroom_id', $assignment->homeroom_id)
                    ->exists();

                return !$alreadyExists;
            })
            ->map(function ($assignment) {
                $homeroom = $assignment->homeroom;
                $homeroom->name = $homeroom->nama;
                return $homeroom;
            })->unique('id')->sortBy('nama')->values();

        return view('lms.courses.duplicate', ['course' => $course, 'targetHomerooms' => $targetHomerooms]);
    }

    public function duplicate(Request $request, LmsCourse $course, LmsSyncService $lmsSync)
    {
        $teacher = $this->getTeacher();
        $this->authorizeCourseOwner($course, $teacher);

        $validated = $request->validate(['homeroom_id' => 'required|exists:homerooms,id']);

        $teachingAssignment = TeachingAssignment::query()->with(['subject', 'homeroom'])
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $course->subject_id)
            ->where('homeroom_id', $validated['homeroom_id'])
            ->first();

        if (!$teachingAssignment || !$teachingAssignment->subject || !$teachingAssignment->homeroom) {
            return back()->withInput()->with('error', 'Rombel tujuan bukan bagian dari TeachingAssignment Anda untuk mata pelajaran ini.');
        }

        if ((int) $course->homeroom_id === (int) $teachingAssignment->homeroom_id) {
            return back()->with('error', 'Rombel tujuan sama dengan kelas sumber.');
        }

        $existingCourse = LmsCourse::query()
            ->where('teacher_id', $teacher->id)
            ->where('subject_id', $course->subject_id)
            ->where('homeroom_id', $teachingAssignment->homeroom_id)
            ->first();

        if ($existingCourse) {
            return redirect()->route('lms.courses.show', $existingCourse)
                ->with('warning', 'Kelas LMS untuk rombel tujuan tersebut sudah tersedia.');
        }

        $course->load(['topics.materials', 'topics.assignments.exam.questions']);

        $clonedCourse = null;
        $clonedTopics = [];
        $clonedMaterials = [];
        $clonedAssignments = [];
        $clonedExams = [];
        $copiedFiles = [];

        try {
            DB::transaction(function () use ($course, $teacher, $teachingAssignment, &$clonedCourse, &$clonedTopics, &$clonedMaterials, &$clonedAssignments, &$clonedExams, &$copiedFiles) {
                $clonedCourse = LmsCourse::create([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $course->subject_id,
                    'homeroom_id' => $teachingAssignment->homeroom_id,
                    'name' => $course->name,
                    'description' => $course->description,
                    'academic_year' => $course->academic_year,
                    'semester' => $course->semester,
                    'is_published' => (bool) $course->is_published,
                    'sync_version' => 1,
                    'sync_id' => (string) Str::uuid(),
                ]);

                foreach ($course->topics as $sourceTopic) {
                    $clonedTopic = LmsTopic::create([
                        'sync_id' => (string) Str::uuid(),
                        'course_id' => $clonedCourse->id,
                        'title' => $sourceTopic->title,
                        'description' => $sourceTopic->description,
                        'sort_order' => $sourceTopic->sort_order,
                        'is_published' => (bool) $sourceTopic->is_published,
                        'sync_version' => 1,
                    ]);
                    $clonedTopics[] = $clonedTopic;

                    foreach ($sourceTopic->materials as $sourceMaterial) {
                        $materialData = [
                            'sync_id' => (string) Str::uuid(),
                            'topic_id' => $clonedTopic->id,
                            'title' => $sourceMaterial->title,
                            'atp_item_id' => $sourceMaterial->atp_item_id,
                            'type' => $sourceMaterial->type,
                            'content' => $sourceMaterial->content,
                            'external_url' => $sourceMaterial->external_url,
                            'file_path' => null,
                            'file_name' => $sourceMaterial->file_name,
                            'mime_type' => $sourceMaterial->mime_type,
                            'file_size' => $sourceMaterial->file_size,
                            'sort_order' => $sourceMaterial->sort_order,
                            'is_published' => (bool) $sourceMaterial->is_published,
                            'sync_version' => 1,
                        ];

                        if (!empty($sourceMaterial->file_path) && Storage::disk('public')->exists($sourceMaterial->file_path)) {
                            $extension = pathinfo($sourceMaterial->file_path, PATHINFO_EXTENSION);
                            $newPath = 'lms/materials/duplicates/' . Str::uuid() . ($extension ? '.' . $extension : '');
                            $copied = Storage::disk('public')->copy($sourceMaterial->file_path, $newPath);

                            if (!$copied) {
                                throw new \RuntimeException('File materi gagal disalin: ' . ($sourceMaterial->file_name ?? $sourceMaterial->file_path));
                            }

                            $copiedFiles[] = $newPath;
                            $materialData['file_path'] = $newPath;
                        }

                        $clonedMaterials[] = LmsMaterial::create($materialData);
                    }

                    foreach ($sourceTopic->assignments as $sourceAssignment) {
                        $newExamId = null;

                        if ($sourceAssignment->exam_id) {
                            $sourceExam = $sourceAssignment->exam;

                            if ($sourceExam) {
                                /*
                                 * [DUPLICATE-EXAM-FIX] Tiga perbaikan:
                                 *   1. Token DIREGENERASI unik - dulu token
                                 *      sumber disalin mentah, sehingga dua
                                 *      exam aktif bertoken sama dan login
                                 *      CBT bisa mendarat di ujian kelas lain.
                                 *   2. jenis_penilaian ikut disalin - dulu
                                 *      hilang sehingga nilai harian CBT
                                 *      selalu jatuh ke kolom 'sumatif'.
                                 *   3. sync_version = 1 eksplisit + status
                                 *      DIPAKSA 'draft' - duplikat tidak boleh
                                 *      langsung aktif; guru mengaktifkan
                                 *      manual lewat ExamController.
                                 */
                                do {
                                    $newToken = str_pad(
                                        (string) random_int(0, 999999),
                                        6,
                                        '0',
                                        STR_PAD_LEFT
                                    );
                                } while (Exam::where('token', $newToken)->exists());

                                $newExam = new Exam();
                                $newExam->sync_id = (string) Str::uuid();
                                $newExam->teacher_id = $sourceExam->teacher_id;
                                $newExam->subject_id = $sourceExam->subject_id;
                                $newExam->judul_ujian = $sourceExam->judul_ujian;
                                $newExam->deskripsi = $sourceExam->deskripsi;
                                $newExam->durasi_menit = $sourceExam->durasi_menit;
                                $newExam->waktu_mulai = $sourceExam->waktu_mulai;
                                $newExam->waktu_selesai = $sourceExam->waktu_selesai;
                                $newExam->token = $newToken;
                                $newExam->status = 'draft';
                                $newExam->jenis_penilaian = $sourceExam->jenis_penilaian;
                                $newExam->tingkat_kelas = $sourceExam->tingkat_kelas;
                                $newExam->acak_soal = $sourceExam->acak_soal;
                                $newExam->sync_version = 1;
                                $newExam->save();

                                foreach ($sourceExam->questions as $question) {
                                    $newExam->questions()->attach($question->id, ['urutan' => $question->pivot->urutan]);
                                }

                                $clonedExams[] = $newExam;
                                $newExamId = $newExam->id;
                            }
                        }

                        $clonedAssignments[] = LmsAssignment::create([
                            'sync_id' => (string) Str::uuid(),
                            'topic_id' => $clonedTopic->id,
                            'title' => $sourceAssignment->title,
                            'atp_item_id' => $sourceAssignment->atp_item_id,
                            'instructions' => $sourceAssignment->instructions,
                            'available_from' => $sourceAssignment->available_from,
                            'due_at' => $sourceAssignment->due_at,
                            'submission_type' => $sourceAssignment->submission_type,
                            'max_score' => $sourceAssignment->max_score,
                            'assignment_type' => $sourceAssignment->assignment_type,
                            'exam_id' => $newExamId,
                            'is_published' => (bool) $sourceAssignment->is_published,
                            'sync_version' => 1,
                        ]);
                    }
                }
            });
        } catch (Throwable $e) {
            foreach ($copiedFiles as $copiedFile) {
                try { Storage::disk('public')->delete($copiedFile); } catch (Throwable $cleanupError) {
                    Log::warning('Gagal membersihkan file hasil clone LMS.', ['file_path' => $copiedFile, 'error' => $cleanupError->getMessage()]);
                }
            }

            Log::error('Gagal menggandakan kelas LMS.', [
                'source_course_id' => $course->id,
                'teacher_id' => $teacher->id,
                'target_homeroom_id' => $teachingAssignment->homeroom_id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Kelas LMS gagal digandakan. Tidak ada perubahan yang diterapkan.');
        }

        $syncWarnings = [];

        $this->pushQuietly(fn () => $lmsSync->pushCourse($clonedCourse->fresh(), 'create'), $syncWarnings, 'Kelas utama');
        foreach ($clonedTopics as $clonedTopic) {
            $this->pushQuietly(fn () => $lmsSync->pushTopic($clonedTopic->fresh()->load('course'), 'create'), $syncWarnings, 'Topik ' . $clonedTopic->title);
        }
        foreach ($clonedMaterials as $clonedMaterial) {
            $this->pushQuietly(fn () => $lmsSync->pushMaterialToStudents($clonedMaterial->fresh()->load('topic.course'), 'create'), $syncWarnings, 'Materi ' . $clonedMaterial->title);
        }
        foreach ($clonedExams as $clonedExam) {
            $this->pushQuietly(fn () => $lmsSync->pushExam($clonedExam->fresh()->load('questions'), 'create'), $syncWarnings, 'Ujian CBT ' . $clonedExam->judul_ujian);
        }
        foreach ($clonedAssignments as $clonedAssignment) {
            $this->pushQuietly(fn () => $lmsSync->pushAssignmentToStudents($clonedAssignment->fresh()->load('topic'), 'create'), $syncWarnings, 'Tugas ' . $clonedAssignment->title);
        }

        if (!empty($syncWarnings)) {
            return redirect()->route('lms.courses.show', $clonedCourse)
                ->with('warning', 'Kelas berhasil digandakan di perangkat guru. Sebagian data menunggu sinkronisasi: ' . implode('; ', array_slice(array_unique($syncWarnings), 0, 5)));
        }

        return redirect()->route('lms.courses.show', $clonedCourse)
            ->with('success', 'Kelas LMS berhasil digandakan beserta materi, tugas, dan CBT. Ujian hasil duplikat berstatus DRAFT - aktifkan lewat menu CBT.');
    }

    public function publish(LmsCourse $course, LmsSyncService $lmsSync)
    {
        return $this->togglePublish($course, $lmsSync, true);
    }

    public function unpublish(LmsCourse $course, LmsSyncService $lmsSync)
    {
        return $this->togglePublish($course, $lmsSync, false);
    }

    private function togglePublish(LmsCourse $course, LmsSyncService $lmsSync, bool $published)
    {
        $teacher = $this->getTeacher();
        $this->authorizeCourseOwner($course, $teacher);

        $course->update([
            'is_published' => $published,
            'sync_version' => max(1, (int) $course->sync_version) + 1,
        ]);

        try {
            $issue = $this->syncIssueFrom($lmsSync->pushCourse($course->fresh(), 'update'));

            if ($issue !== null) {
                return back()->with('warning', 'Status publikasi tersimpan lokal, tetapi ' . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Toggle publikasi kelas LMS gagal sinkronisasi.', [
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Status publikasi tersimpan lokal. Sinkronisasi akan dicoba kembali.');
        }

        return back()->with('success', $published
            ? 'Kelas LMS dipublikasikan dan terkirim ke murid.'
            : 'Kelas LMS disembunyikan (draft) dan perubahan terkirim ke murid.');
    }

    /**
     * [GHOST-EXAM-FIX] Hapus satu exam milik course ini BILA tidak
     * lagi direferensikan assignment LMS mana pun (di course lain).
     * Mengirim delete event ke perangkat siswa agar tidak menjadi
     * ghost exam, lalu menghapus lokal.
     */
    private function deleteOrphanExamIfNeeded(Exam $exam, LmsSyncService $lmsSync, array &$failures): void
    {
        $stillReferenced = LmsAssignment::query()
            ->where('exam_id', $exam->id)
            ->exists();

        if ($stillReferenced) {
            return;
        }

        $syncId = trim((string) $exam->sync_id);

        if ($syncId !== '') {
            try {
                $exam->increment('sync_version');

                $lmsSync->pushExamDelete(
                    $syncId,
                    (int) $exam->sync_version
                );
            } catch (Throwable $e) {
                $failures[] = 'ujian:' . $exam->judul_ujian;
                Log::warning('Gagal mencatat perintah hapus CBT exam saat hapus course.', [
                    'exam_id' => $exam->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $exam->questions()->detach();
            $exam->delete();
        } catch (Throwable $e) {
            Log::warning('Gagal menghapus exam lokal saat hapus course.', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /*
     * ============================================================
     * [CASCADE-DELETE] Hapus kelas LMS = push delete BERANTAI:
     * assignment → material → topic → course (+ exam yatim sesuai
     * guard jawaban). Dulu: course dihapus lokal saja (cascade FK)
     * TANPA satu pun event → siswa menyimpan salinan selamanya.
     * Urutan push: anak dulu, course terakhir (arsip catch-up tetap
     * lengkap). Versi = epoch-ms.
     * ============================================================
     */
    public function destroy(LmsCourse $course, LmsSyncService $lmsSync)
    {
        $teacher = $this->authorizeCourseOwner($course);   // atau guard serupa yang sudah ada

        $homeroomId = (int) ($course->homeroom_id ?? 0);
        $epochVersion = (int) floor(microtime(true) * 1000);
        $failures = [];

        $topics = LmsTopic::where('course_id', $course->id)->get();
        $topicIds = $topics->pluck('id');

        $assignments = LmsAssignment::whereIn('topic_id', $topicIds)->get();
        $materials = LmsMaterial::whereIn('topic_id', $topicIds)->get();
        $examIds = $assignments->pluck('exam_id')->filter()->unique()->values();

        /* --- 1) Exam yatim (dengan guard jawaban pending) --- */
        foreach ($examIds as $examId) {
            $exam = Exam::find($examId);
            if (!$exam) { continue; }

            $punyaJawabanPending = DB::table('exam_sessions')
                ->where('exam_id', $exam->id)
                ->where('status', 'selesai')
                ->whereNull('nilai_akhir')
                ->exists();

            $examSyncId = trim((string) $exam->sync_id);

            if ($punyaJawabanPending) {
                // Exam tetap hidup (LAN-only) untuk koreksi — tidak ditarik.
                Log::warning('Exam dengan jawaban pending dilepaskan saat hapus course.', [
                    'course_id' => $course->id, 'exam_id' => $exam->id,
                ]);
                continue;
            }

            if ($examSyncId !== '' && $homeroomId > 0) {
                try {
                    $lmsSync->queueAndPush('cbt_exam', $examSyncId, 'delete', $epochVersion, [
                        'sync_id' => $examSyncId,
                        'entity' => 'cbt_exam',
                        'id' => $exam->id,
                        'homeroom_id' => $homeroomId,
                        'target_homeroom_id' => $homeroomId,
                        'sync_version' => $epochVersion,
                        'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
                    ], null, $homeroomId);
                } catch (Throwable $e) {
                    $failures[] = 'ujian:' . $exam->judul_ujian;
                }
            }
        }

        /* --- 2) Assignment --- */
        foreach ($assignments as $assignment) {
            $sid = trim((string) $assignment->sync_id);
            if ($sid === '') { continue; }
            try {
                $lmsSync->pushAssignmentDelete($sid, $epochVersion);
            } catch (Throwable $e) {
                $failures[] = 'tugas:' . $assignment->title;
            }
        }

        /* --- 3) Material --- */
        foreach ($materials as $material) {
            $sid = trim((string) $material->sync_id);
            if ($sid === '') { continue; }
            try {
                $lmsSync->pushMaterialDelete($sid, $epochVersion);
            } catch (Throwable $e) {
                $failures[] = 'materi:' . $material->title;
            }
        }

        /* --- 4) Topic --- */
        foreach ($topics as $topic) {
            $sid = trim((string) $topic->sync_id);
            if ($sid === '' || $homeroomId <= 0) { continue; }
            try {
                $lmsSync->queueAndPush('lms_topic', $sid, 'delete', $epochVersion, [
                    'sync_id' => $sid,
                    'entity' => 'lms_topic',
                    'course_id' => $course->id,
                    'course_sync_id' => $course->sync_id,
                    'homeroom_id' => $homeroomId,
                    'target_homeroom_id' => $homeroomId,
                    'sync_version' => $epochVersion,
                ], null, $homeroomId);
            } catch (Throwable $e) {
                $failures[] = 'topik:' . $topic->title;
            }
        }

        /* --- 5) Course --- */
        $courseSyncId = trim((string) $course->sync_id);
        if ($courseSyncId !== '' && $homeroomId > 0) {
            try {
                $lmsSync->queueAndPush('lms_course', $courseSyncId, 'delete', $epochVersion, [
                    'sync_id' => $courseSyncId,
                    'entity' => 'lms_course',
                    'homeroom_id' => $homeroomId,
                    'target_homeroom_id' => $homeroomId,
                    'sync_version' => $epochVersion,
                ], null, $homeroomId);
            } catch (Throwable $e) {
                $failures[] = 'kelas:' . $course->name;
            }
        }

        /* --- 6) Hapus lokal (exam tanpa jawaban pending ikut yatim) --- */
        foreach ($examIds as $examId) {
            $exam = Exam::find($examId);
            if (!$exam) { continue; }

            $punyaJawabanPending = DB::table('exam_sessions')
                ->where('exam_id', $exam->id)
                ->where('status', 'selesai')
                ->whereNull('nilai_akhir')
                ->exists();
            if ($punyaJawabanPending) { continue; }

            $stillReferenced = LmsAssignment::where('exam_id', $exam->id)->exists();
            if ($stillReferenced) { continue; }

            try {
                $exam->questions()->detach();
                $exam->delete();
            } catch (Throwable $e) {
                $failures[] = 'ujian lokal:' . $exam->judul_ujian;
            }
        }

        LmsAssignment::whereIn('topic_id', $topicIds)->delete();
        LmsMaterial::whereIn('topic_id', $topicIds)->delete();
        LmsTopic::where('course_id', $course->id)->delete();
        $course->delete();

        if (!empty($failures)) {
            return back()->with('warning', 'Kelas LMS dihapus. Catatan: '
                . implode(', ', array_slice(array_unique($failures), 0, 5))
                . '. Perintah hapus yang tertunda akan dicoba ulang oleh antrean.');
        }

        return back()->with('success', 'Kelas LMS dihapus — perintah penghapusan '
            . 'seluruh isinya telah diteruskan ke perangkat murid.');
    }
}