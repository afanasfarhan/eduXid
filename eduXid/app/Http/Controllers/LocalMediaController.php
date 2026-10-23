<?php

namespace App\Http\Controllers;

use App\Services\LocalMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LocalMediaController extends Controller
{
    public function __construct(
        protected LocalMediaService $media
    ) {
    }

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $relative = $this->media->normalizeLegacyPath($path);

        if ($relative === null) {
            throw new NotFoundHttpException();
        }

        // File identifiers must never be bearer capabilities. Keep signed routes/web.php unchanged.
        $user = $request->user();
        if ($user === null) abort(403, 'Autentikasi diperlukan untuk mengakses media sekolah.');
        $role = strtolower(trim((string)($user->role ?? '')));
        $p = strtolower($relative);
        $staff = in_array($role, ['admin', 'tendik', 'kepala_sekolah', 'guru', 'wali_kelas', 'guru_bk'], true);
        if (str_starts_with($p, 'surat_masuk/') && !in_array($role, ['admin', 'tendik'], true)) abort(403);
        if ((str_starts_with($p, 'kop_surat/') || str_starts_with($p, 'tanda_tangan/')) && !$staff) abort(403);
        if (str_starts_with($p, 'photos/siswa/')) {
            // Students can only open their own roster photo by its exact NISN.
            $own = $role === 'siswa'
                && preg_match('/^photos\/siswa\/([0-9]{10})\.(jpg|jpeg|png|webp)$/iD', $relative, $m)
                && hash_equals((string)($user->login_id ?? ''), $m[1]);
            if (!$staff && !$own) abort(403);
        }
        if (str_starts_with($p, 'lms/submissions/')) {
            if ($role === 'siswa') {
                $student = DB::table('students')->where('nisn', (string)($user->login_id ?? ''))->first(['id']);
                $prefix = $student ? 'lms/submissions/' . (int)$student->id . '/' : '';
                if ($prefix === '' || !str_starts_with($p, $prefix)) abort(403);
            } elseif (!$staff) abort(403);
            // Teachers may open submissions only from their assigned classes.
            elseif (in_array($role, ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah'], true)) {
                $ownerTeacher = method_exists($user, 'teacher') ? $user->teacher() : null;
                $assigned = $ownerTeacher && DB::table('lms_assignment_submissions as s')
                    ->join('lms_assignments as a', 'a.id', '=', 's.assignment_id')
                    ->join('lms_topics as t', 't.id', '=', 'a.topic_id')
                    ->join('lms_courses as c', 'c.id', '=', 't.course_id')
                    ->join('teaching_assignments as ta', 'ta.homeroom_id', '=', 'c.homeroom_id')
                    ->where('s.file_path', $relative)->where('ta.teacher_id', $ownerTeacher->id)
                    ->whereColumn('ta.subject_id', 'c.subject_id')->exists();
                if (!$assigned) abort(403);
            }
        }
        if (str_starts_with($p, 'lms/materials/') && $role === 'siswa') {
            $student = DB::table('students')->where('nisn', (string)($user->login_id ?? ''))->first(['id']);
            if (!$student || !DB::table('lms_materials as m')
                ->join('lms_topics as t', 't.id', '=', 'm.topic_id')
                ->join('lms_courses as c', 'c.id', '=', 't.course_id')
                ->join('enrollments as e', 'e.homeroom_id', '=', 'c.homeroom_id')
                ->where('m.file_path', $relative)->where('m.is_published', 1)
                ->where('t.is_published', 1)->where('c.is_published', 1)
                ->where('e.student_id', $student->id)->exists()) abort(403);
        }
        // Unknown media directories are not allowed to arbitrary students.
        if ($role === 'siswa' && !str_starts_with($p, 'lms/materials/')
            && !str_starts_with($p, 'lms/submissions/') && !str_starts_with($p, 'photos/siswa/')) abort(403);

        $absolute = $this->media->path($relative);

        if ($absolute === null || !is_file($absolute)) {
            throw new NotFoundHttpException();
        }

        $mime = $this->media->mimeType($relative) ?? 'application/octet-stream';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
