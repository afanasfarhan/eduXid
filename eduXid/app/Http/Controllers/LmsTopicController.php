<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsTopic;
use App\Models\Teacher;
use App\Services\Sync\LmsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LmsTopicController extends Controller
{
    /*
     * ==========================================================
     * [REWRITE-13b.2] LMS TOPIC CONTROLLER
     * ==========================================================
     * Perubahan kumulatif (semua dari lapangan):
     *
     *   1. [GHOST-EXAM-FIX v2] destroy(): delete exam dikirim SEBELUM
     *      assignment lokal dihapus, dengan homeroom yang DIKUMPULKAN
     *      lebih awal (payload eksplisit — tidak bergantung
     *      resolveExamHomeroomId yang butuh assignment). Dulu: hapus
     *      assignment dulu → resolusi buta arah → throw → tanpa
     *      outbox → exam dihapus lokal → GHOST EXAM permanen di PWA.
     *
     *   2. [GUARD-JAWABAN] Exam yang masih punya jawaban belum
     *      terkoreksi TIDAK dihapus dan TIDAK ditarik dari siswa —
     *      dilepaskan dari topik (menjadi exam LAN-only) agar masih
     *      bisa dikoreksi di Modul CBT. Dulu: hapus topik setelah
     *      siswa mengerjakan → jawaban yatim → dead-letter
     *      "exam tidak ditemukan".
     *
     *   3. [FIX-EXPRESSION] Bump sync_version sebagai INTEGER BIASA
     *      (set atribut + update()), BUKAN DB::raw pada update()
     *      Eloquent. Dulu DB::raw menempelkan objek Expression ke
     *      atribut model di memori → cast integer meledak saat model
     *      di-serialize (HTTP 500 "could not be converted to int").
     *      updated_at tetap naik otomatis → versi push (epoch-ms dari
     *      updated_at di service) tetap segar.
     *
     *   4. [DELETE-EPOCH] Semua versi delete = epoch-ms.
     *
     *   5. [DRAFT-SKIP] update() menghormati is_published — topic
     *      draft tidak bocor ke Cloud/PWA.
     *
     *   6. [HOMEROOM-GUARD] course.homeroom_id kosong → delete topic
     *      gagal jelas (dulu target 0 → throw samar).
     *
     *   7. [IDENTITAS] getTeacher(): relasi teacher() dulu, fallback
     *      NIP/NIK/NUPTK — satu semantik dengan modul lain.
     *
     *   8. [DELETE-EXAM-LOKAL] Kegagalan hapus exam lokal masuk
     *      $failures (dulu log senyap → guru lihat sukses penuh).
     *
     *   9. Supersede pending-update saat delete ditangani choke point
     *      service (queueAndPush) — controller cukup memanggil.
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

    private function authorizeCourseOwner(LmsCourse $course): Teacher
    {
        $teacher = $this->getTeacher();

        if ((int) $course->teacher_id !== (int) $teacher->id) {
            abort(403, 'Anda tidak memiliki akses ke kelas LMS ini.');
        }

        return $teacher;
    }

    private function authorizeTopicOwner(LmsTopic $topic): Teacher
    {
        $topic->loadMissing('course');

        if (!$topic->course) {
            abort(404, 'Kelas LMS untuk topik ini tidak ditemukan.');
        }

        return $this->authorizeCourseOwner($topic->course);
    }

    private function syncIssueFrom(?array $result): ?string
    {
        if (!is_array($result)) {
            return 'Respon sinkronisasi tidak valid.';
        }

        $status = strtoupper((string) ($result['status'] ?? ''));

        if (($result['success'] ?? false) === true
            && in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)) {
            return null;
        }

        $error = trim((string) ($result['error'] ?? ($result['message'] ?? '')));

        if (str_contains($error, 'FILE_UPLOAD_FAILED')) {
            return 'file materi belum ter-upload ke Cloud (dicoba ulang otomatis).';
        }

        if ($status === 'PENDING') {
            return 'menunggu sinkronisasi (akan dicoba ulang otomatis oleh antrean).';
        }

        if ($status === 'FAILED') {
            return 'gagal disinkronkan' . ($error !== '' ? ': ' . $error : '')
                . '. Akan dicoba ulang otomatis oleh antrean.';
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

    private function logSyncIssue(string $action, LmsTopic $topic, ?string $issue): void
    {
        Log::warning('Sinkronisasi LMS topic bermasalah.', [
            'action' => $action,
            'topic_id' => $topic->id,
            'sync_id' => $topic->sync_id,
            'course_id' => $topic->course_id,
            'is_published' => (bool) $topic->is_published,
            'issue' => $issue,
        ]);
    }

    public function store(Request $request, LmsCourse $course, LmsSyncService $lmsSync)
    {
        $this->authorizeCourseOwner($course);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_published' => 'nullable|boolean',
        ]);

        $validated['is_published'] = $validated['is_published'] ?? false;

        $topic = DB::transaction(function () use ($course, $validated) {
            $sortOrder = ((int) ($course->topics()->max('sort_order'))) + 1;

            return $course->topics()->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'sort_order' => $sortOrder,
                'is_published' => $validated['is_published'],
                'sync_version' => 1,
                'sync_status' => 'pending',
            ]);
        });

        /* [DRAFT-SKIP] Draft tidak di-push. */
        if (!$validated['is_published']) {
            return back()->with('success', 'Topik berhasil ditambahkan sebagai DRAFT. '
                . 'Publikasikan lewat tombol Publish saat siap untuk dikirim ke murid.');
        }

        try {
            $issue = $this->syncIssueFrom(
                $lmsSync->pushTopic($topic->fresh()->load('course'), 'create')
            );

            if ($issue !== null) {
                $this->logSyncIssue('create', $topic, $issue);

                return back()->with('warning', 'Topik berhasil disimpan di perangkat guru, tetapi ' . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Topik tersimpan lokal tetapi sinkronisasi error.', [
                'topic_id' => $topic->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Topik berhasil disimpan di perangkat guru, tetapi sinkronisasi belum berhasil.');
        }

        return back()->with('success', 'Topik berhasil ditambahkan dan disinkronkan.');
    }

    public function update(Request $request, LmsTopic $topic, LmsSyncService $lmsSync)
    {
        $this->authorizeTopicOwner($topic);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        /*
         * [DRAFT-SKIP] Topic draft tidak di-push — judul/deskripsi
         * tidak bocor ke Cloud/PWA (dulu update draft tetap terkirim).
         */
        if (!$topic->is_published) {
            $topic->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
            ]);

            return back()->with('success', 'Topik berhasil diperbarui (masih draft). '
                . 'Publikasikan lewat tombol Publish saat siap untuk dikirim ke murid.');
        }

        /*
         * [FIX-EXPRESSION] Bump versi sebagai integer biasa — dulu
         * DB::raw('sync_version + 1') pada update() Eloquent menempelkan
         * objek Expression ke atribut di memori → cast integer meledak
         * saat model di-serialize (HTTP 500). updated_at tetap naik
         * otomatis → versi push (epoch-ms) tetap segar.
         */
        $topic->sync_version = (int) ($topic->sync_version ?? 0) + 1;

        $topic->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sync_status' => 'pending',
        ]);

        try {
            $issue = $this->syncIssueFrom(
                $lmsSync->pushTopic($topic->fresh()->load('course'), 'update')
            );

            if ($issue !== null) {
                $this->logSyncIssue('update', $topic, $issue);

                return back()->with('warning', 'Topik berhasil diperbarui di perangkat guru, tetapi ' . $issue);
            }
        } catch (Throwable $e) {
            Log::warning('Update topik tersimpan lokal tetapi sinkronisasi error.', [
                'topic_id' => $topic->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Topik berhasil diperbarui di perangkat guru, tetapi sinkronisasi belum berhasil.');
        }

        return back()->with('success', 'Topik berhasil diperbarui dan disinkronkan.');
    }

    public function publish(LmsTopic $topic, LmsSyncService $lmsSync)
    {
        $this->authorizeTopicOwner($topic);

        /*
         * [FIX-EXPRESSION] Integer bump — dulu DB::raw pada update()
         * topic & materials menempelkan Expression (fatal saat
         * model di-serialize).
         */
        $topic->sync_version = (int) ($topic->sync_version ?? 0) + 1;
        $topic->update([
            'is_published' => true,
            'sync_status' => 'pending',
        ]);

        $materials = LmsMaterial::query()->where('topic_id', $topic->id)->get();

        foreach ($materials as $material) {
            $material->sync_version = (int) ($material->sync_version ?? 0) + 1;
            $material->update([
                'is_published' => true,
                'sync_status' => 'pending',
            ]);
        }

        $warnings = [];

        $this->pushQuietly(
            fn () => $lmsSync->pushTopic($topic->fresh()->load('course'), 'update'),
            $warnings,
            'Topik'
        );

        foreach ($materials as $material) {
            $this->pushQuietly(
                fn () => $lmsSync->pushMaterialToStudents($material->fresh()->load('topic'), 'update'),
                $warnings,
                'Materi ' . $material->title
            );
        }

        if (!empty($warnings)) {
            return back()->with('warning', 'Topik dan materi berhasil dipublikasikan. Sebagian menunggu '
                . 'sinkronisasi: ' . implode('; ', array_slice(array_unique($warnings), 0, 5)));
        }

        return back()->with('success', 'Topik dan seluruh materi berhasil dipublikasikan dan disinkronkan.');
    }

    public function unpublish(LmsTopic $topic, LmsSyncService $lmsSync)
    {
        $this->authorizeTopicOwner($topic);

        /*
         * [UNPUBLISH] Flag-only — konsisten dengan PWA siswa yang
         * memfilter isPub(): materi/aktivitas tak lolos filter tidak
         * tampil dan PDF-nya tak bisa dibuka dari UI. (Penarikan file
         * individual lewat Edit materi → unpublish, yang mengirim
         * DELETE event sungguhan.)
         */
        $topic->sync_version = (int) ($topic->sync_version ?? 0) + 1;
        $topic->update([
            'is_published' => false,
            'sync_status' => 'pending',
        ]);

        $materials = LmsMaterial::query()->where('topic_id', $topic->id)->get();

        foreach ($materials as $material) {
            $material->sync_version = (int) ($material->sync_version ?? 0) + 1;
            $material->update([
                'is_published' => false,
                'sync_status' => 'pending',
            ]);
        }

        $warnings = [];

        $this->pushQuietly(
            fn () => $lmsSync->pushTopic($topic->fresh()->load('course'), 'update'),
            $warnings,
            'Topik'
        );

        foreach ($materials as $material) {
            $this->pushQuietly(
                fn () => $lmsSync->pushMaterialToStudents($material->fresh()->load('topic'), 'update'),
                $warnings,
                'Materi ' . $material->title
            );
        }

        if (!empty($warnings)) {
            return back()->with('warning', 'Publikasi topik dan materi berhasil dibatalkan. Sebagian '
                . 'menunggu sinkronisasi: ' . implode('; ', array_slice(array_unique($warnings), 0, 5)));
        }

        return back()->with('success', 'Publikasi topik dan seluruh materi berhasil dibatalkan — '
            . 'disembunyikan dari perangkat murid.');
    }

    /*
     * ============================================================
     * [GHOST-EXAM-FIX v2] + [GUARD-JAWABAN] + [DELETE-EPOCH]
     *
     * Urutan yang benar:
     *   1) Kumpulkan mapping exam → homeroom SEBELUM apa pun dihapus.
     *   2) Push delete exam (payload homeroom eksplisit — tidak
     *      bergantung resolveExamHomeroomId yang butuh assignment).
     *   3) Push delete assignment / material / topic.
     *   4) Hapus lokal (assignment → material → topic → exam yatim).
     *
     * [GUARD-JAWABAN] Exam dengan jawaban belum terkoreksi TIDAK
     * dihapus dan TIDAK ditarik dari siswa — dilepaskan dari topik
     * (menjadi exam LAN-only) agar masih bisa dikoreksi di Modul CBT.
     * ============================================================
     */
    public function destroy(LmsTopic $topic, LmsSyncService $lmsSync)
    {
        $this->authorizeTopicOwner($topic);

        $topic->loadMissing(['materials', 'assignments', 'course']);

        $course = $topic->course;
        $topicId = (int) $topic->id;
        $failures = [];
        $epochVersion = (int) floor(microtime(true) * 1000);

        /*
         * [HOMEROOM-GUARD] Course tanpa homeroom_id → broadcast tak
         * mungkin (target 0 ditolak). Gagal jelas sebelum apa pun.
         */
        $homeroomId = (int) ($course->homeroom_id ?? 0);

        if ($homeroomId <= 0) {
            return back()->with('warning', 'Topik tidak dapat dihapus: kelas LMS ini belum memiliki '
                . 'rombel (homeroom_id kosong). Perbaiki data course terlebih dahulu.');
        }

        /*
         * [1] Mapping exam → homeroom, SEBELUM assignment dihapus.
         */
        $examIds = $topic->assignments
            ->pluck('exam_id')
            ->filter()
            ->unique()
            ->values();

        $examHomeroomId = $homeroomId; // topic ini adalah konteks homeroom-nya

        /*
         * [2] Push delete exam DULU — homeroom eksplisit. Exam dengan
         * jawaban pending DILEWATI (tetap hidup untuk koreksi).
         */
        foreach ($examIds as $examId) {
            $exam = Exam::find($examId);

            if (!$exam) { continue; }

            /*
             * [GUARD-JAWABAN] Jawaban belum terkoreksi → exam TETAP
             * HIDUP: tidak di-push delete, tidak dihapus lokal.
             */
            $punyaJawabanPending = DB::table('exam_sessions')
                ->where('exam_id', $exam->id)
                ->where('status', 'selesai')
                ->whereNull('nilai_akhir')
                ->exists();

            if ($punyaJawabanPending) {
                Log::warning('Exam dengan jawaban pending dilepaskan dari topik (tidak dihapus/ditarik).', [
                    'topic_id' => $topicId,
                    'exam_id' => $exam->id,
                ]);

                $failures[] = 'ujian "' . $exam->judul_ujian . '" (masih ada jawaban belum dikoreksi — tidak ikut dihapus)';

                continue;
            }

            $syncId = trim((string) $exam->sync_id);

            if ($syncId !== '') {
                try {
                    $lmsSync->queueAndPush('cbt_exam', $syncId, 'delete', $epochVersion, [
                        'sync_id' => $syncId,
                        'entity' => 'cbt_exam',
                        'id' => $exam->id,
                        'homeroom_id' => $examHomeroomId,
                        'target_homeroom_id' => $examHomeroomId,
                        'sync_version' => $epochVersion,
                        'updated_at' => now()->format('Y-m-d\TH:i:s.vP'),
                    ], null, $examHomeroomId);
                } catch (Throwable $e) {
                    $failures[] = 'ujian:' . $exam->judul_ujian;
                    Log::warning('Gagal mencatat perintah hapus CBT exam saat hapus topic.', [
                        'topic_id' => $topicId,
                        'exam_id' => $exam->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        /*
         * [3] Push delete assignment / material / topic.
         */
        foreach ($topic->assignments as $assignment) {
            if (trim((string) $assignment->sync_id) === '') { continue; }

            try {
                $lmsSync->pushAssignmentDelete((string) $assignment->sync_id, $epochVersion);
            } catch (Throwable $e) {
                Log::warning('Gagal mencatat delete assignment LMS saat hapus topic.', [
                    'topic_id' => $topicId,
                    'assignment_id' => $assignment->id,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = 'tugas:' . $assignment->title;
            }
        }

        foreach ($topic->materials as $material) {
            if (trim((string) $material->sync_id) === '') { continue; }

            try {
                $lmsSync->pushMaterialDelete((string) $material->sync_id, $epochVersion);
            } catch (Throwable $e) {
                Log::warning('Gagal mencatat delete material LMS saat hapus topic.', [
                    'topic_id' => $topicId,
                    'material_id' => $material->id,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = 'materi:' . $material->title;
            }
        }

        if (trim((string) $topic->sync_id) !== '') {
            try {
                $lmsSync->queueAndPush('lms_topic', (string) $topic->sync_id, 'delete', $epochVersion, [
                    'sync_id' => $topic->sync_id,
                    'entity' => 'lms_topic',
                    'course_id' => $course->id,
                    'course_sync_id' => $course->sync_id,
                    'homeroom_id' => $homeroomId,
                    'target_homeroom_id' => $homeroomId,
                    'sync_version' => $epochVersion,
                ], null, $homeroomId);
            } catch (Throwable $e) {
                Log::warning('Gagal mencatat delete topic LMS.', [
                    'topic_id' => $topicId,
                    'sync_id' => $topic->sync_id,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = 'topik:' . $topic->title;
            }
        }

        /*
         * [4] Hapus lokal. Exam yatim TANPA jawaban pending ikut
         * dihapus (perintah hapusnya sudah terkirim di langkah [2]).
         * Exam DENGAN jawaban pending di-skip — tetap hidup untuk
         * koreksi (menjadi LAN-only setelah assignment terhapus).
         */
        LmsAssignment::where('topic_id', $topicId)->delete();
        LmsMaterial::where('topic_id', $topicId)->delete();
        $topic->delete();

        foreach ($examIds as $examId) {
            $exam = Exam::find($examId);

            if (!$exam) { continue; }

            /*
             * [GUARD-JAWABAN] Konsisten dengan langkah [2] — exam
             * ber-jawaban-pending tidak dihapus lokal.
             */
            $punyaJawabanPending = DB::table('exam_sessions')
                ->where('exam_id', $exam->id)
                ->where('status', 'selesai')
                ->whereNull('nilai_akhir')
                ->exists();

            if ($punyaJawabanPending) { continue; }

            $stillReferenced = LmsAssignment::query()
                ->where('exam_id', $exam->id)
                ->exists();

            if ($stillReferenced) { continue; }

            try {
                $exam->questions()->detach();
                $exam->delete();
            } catch (Throwable $e) {
                /* [DELETE-EXAM-LOKAL] Masuk failures — dulu log senyap
                 * dan guru melihat sukses penuh. */
                $failures[] = 'ujian lokal:' . $exam->judul_ujian;
                Log::warning('Gagal menghapus exam lokal saat hapus topic.', [
                    'exam_id' => $exam->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($failures)) {
            return back()->with('warning', 'Topik dihapus. Catatan: '
                . implode(', ', array_slice(array_unique($failures), 0, 5))
                . '. Perubahan lain yang tertunda akan dicoba kembali oleh antrean sinkronisasi.');
        }

        return back()->with('success', 'Topik beserta seluruh materi, tugas, dan ujian terkait '
            . 'berhasil dihapus dan perintah penghapusan diteruskan ke perangkat murid.');
    }
}