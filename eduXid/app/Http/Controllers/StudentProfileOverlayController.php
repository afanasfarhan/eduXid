<?php

namespace App\Http\Controllers;

use App\Services\LocalMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V21R87 — Profil murid untuk modal lintas halaman.
 * Tidak menggunakan pencarian nama; semua hak akses diperiksa dari data lokal.
 */
class StudentProfileOverlayController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $input = $request->validate([
            'student_id' => ['nullable', 'integer', 'min:1'],
            'nisn' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if (empty($input['student_id']) && empty($input['nisn'])) {
            return response()->json(['message' => 'Identitas murid diperlukan.'], 422);
        }
        $q = DB::table('students');
        if (!empty($input['student_id'])) {
            $q->where('id', (int) $input['student_id']);
        } else {
            $q->where('nisn', (string) $input['nisn']);
        }
        // Jika NISN tidak unik, jangan ambil siswa yang salah.
        $matching = $q->limit(2)->get();
        if ($matching->count() !== 1) {
            return response()->json(['message' => 'Profil tidak ditemukan atau identitas tidak unik.'], 404);
        }
        $student = $matching->first();
        $user = $request->user();
        abort_unless($user, 401);
        $role = method_exists($user, 'roleName') ? $user->roleName() : (string) $user->role;

        // Hanya siswa sendiri boleh mengakses data siswa ketika role siswa.
        if ($role === 'siswa') {
            abort_unless((string) $user->login_id === (string) $student->nisn, 403);
        }

        $privileged = in_array($role, ['admin', 'kepala_sekolah', 'tendik'], true);
        $teacher = !$privileged && $role !== 'siswa' ? $user->teacher() : null;
        if (!$privileged && $role !== 'siswa') {
            abort_unless($teacher, 403);
        }

        $enrollments = DB::table('enrollments as e')
            ->join('homerooms as h', 'h.id', '=', 'e.homeroom_id')
            ->where('e.student_id', (int) $student->id)
            ->select('h.id', 'h.nama', 'h.semester_id', 'h.homeroom_teacher_id')
            ->orderByDesc('h.semester_id')
            ->orderByDesc('h.id')
            ->get();
        $allowed = $enrollments;
        if ($teacher) {
            $teachingRombels = DB::table('teaching_assignments')
                ->where('teacher_id', (int) $teacher->id)
                ->pluck('homeroom_id')->map(fn ($id) => (int) $id)->all();
            $allowed = $enrollments->filter(function ($r) use ($teacher, $teachingRombels) {
                return (int) $r->homeroom_teacher_id === (int) $teacher->id
                    || in_array((int) $r->id, $teachingRombels, true);
            })->values();
            abort_if($allowed->isEmpty(), 403);
        }
        $active = (string) (session('semester_aktif') ?? '');
        $current = $allowed->first(fn ($r) => $active !== '' && (string) $r->semester_id === $active)
            ?? $allowed->first();

        $assignment = null;
        if ($current) {
            $assignments = DB::table('teaching_assignments as t')
                ->leftJoin('subjects as s', 's.id', '=', 't.subject_id')
                ->where('t.homeroom_id', (int) $current->id);
            if ($teacher) {
                $isWali = (int) $current->homeroom_teacher_id === (int) $teacher->id;
                if (!$isWali) {
                    $assignments->where('t.teacher_id', (int) $teacher->id);
                }
            }
            if (!empty($input['assignment_id'])) {
                $assignments->where('t.id', (int) $input['assignment_id']);
            }
            $assignment = $assignments->select('t.id', 't.teacher_id', 's.nama_mata_pelajaran')
                ->orderBy('t.id')->first();
            // Tidak boleh jatuh ke assignment lain bila ID spesifik tidak sah.
            if (!empty($input['assignment_id']) && !$assignment) {
                abort(403);
            }
        } elseif (!$privileged && $role !== 'siswa') {
            abort(403);
        }

        $values = ['diagnostik' => null, 'formatif' => null, 'sumatif' => null];
        $dimensions = array_fill_keys(['d1','d2','d3','d4','d5','d6','d7','d8'], 3);
        if ($assignment && Schema::hasTable('assessment_scores')) {
            $columns = [];
            foreach (array_merge(array_keys($values), array_keys($dimensions)) as $field) {
                if (Schema::hasColumn('assessment_scores', $field)) {
                    $columns[] = DB::raw('AVG('.$field.') as '.$field);
                }
            }
            if ($columns) {
                $aggregate = DB::table('assessment_scores')
                    ->where('student_id', (int) $student->id)
                    ->where('teaching_assignment_id', (int) $assignment->id)
                    ->select($columns)->first();
                foreach (array_keys($values) as $k) {
                    $values[$k] = isset($aggregate->$k) ? (int) round((float) $aggregate->$k) : null;
                }
                foreach (array_keys($dimensions) as $k) {
                    $dimensions[$k] = isset($aggregate->$k) ? max(1, min(5, (int) round((float) $aggregate->$k))) : 3;
                }
            }
        }

        $absence = ['sakit' => 0, 'izin' => 0, 'alpa' => 0];
        if ($current && Schema::hasTable('attendances')) {
            $att = DB::table('attendances')->where('student_id', (int) $student->id);
            if (Schema::hasColumn('attendances', 'teaching_assignment_id')) {
                $ids = DB::table('teaching_assignments')
                    ->where('homeroom_id', (int) $current->id)->pluck('id');
                if ($ids->isNotEmpty()) {
                    $att->whereIn('teaching_assignment_id', $ids);
                } else {
                    $att->whereRaw('1=0');
                }
            }
            foreach ($att->select('status', DB::raw('COUNT(*) as jumlah'))->groupBy('status')->get() as $entry) {
                $status = strtolower(trim((string) $entry->status));
                if ($status === 'tanpa keterangan') $status = 'alpa';
                if (array_key_exists($status, $absence)) {
                    $absence[$status] += (int) $entry->jumlah;
                }
            }
        }

        $photoUrl = null;
        try {
            $photo = trim((string) ($student->foto_path ?? ''));
            if ($photo !== '') {
                $media = app(LocalMediaService::class);
                if ($media->exists($photo)) {
                    $photoUrl = $media->url($photo);
                }
            }
        } catch (\Throwable $e) {
            // Foto bersifat opsional; jangan menggagalkan profil.
        }
        return response()->json([
            'data' => [
                'id' => (int) $student->id,
                'nama' => (string) ($student->nama ?? ''),
                'nisn' => (string) ($student->nisn ?? ''),
                'jenis_kelamin' => (string) ($student->jenis_kelamin ?? ''),
                'rombel' => (string) ($current->nama ?? '-'),
                'mapel' => (string) ($assignment->nama_mata_pelajaran ?? 'Rapor Semester'),
                'foto_url' => $photoUrl,
                'nilai' => $values,
                'absensi' => $absence,
                'dimensi' => $dimensions,
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
