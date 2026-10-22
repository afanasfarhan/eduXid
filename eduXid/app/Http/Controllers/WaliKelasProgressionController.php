<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PromotionStatusService;
use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WaliKelasProgressionController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $semesterId = PromotionStatusService::activeSemesterId();

        if (!PromotionStatusService::isEvenSemester($semesterId)) {
            return response()->json([
                'enabled' => false,
                'semester_id' => $semesterId,
                'rows' => [],
            ]);
        }

        $teacherId = $this->resolveTeacherId($request);
        $homerooms = $this->waliHomerooms($teacherId, $semesterId);

        if ($homerooms->isEmpty()) {
            return response()->json([
                'enabled' => true,
                'semester_id' => $semesterId,
                'rows' => [],
            ]);
        }

        $homeroomMap = $homerooms->keyBy('id');

        $rows = DB::table('enrollments')
            ->join(
                'students',
                'students.id',
                '=',
                'enrollments.student_id'
            )
            ->whereIn(
                'enrollments.homeroom_id',
                $homerooms->pluck('id')->all()
            )
            ->orderBy('students.nama')
            ->get([
                'enrollments.id as enrollment_id',
                'enrollments.student_id',
                'enrollments.homeroom_id',
                'enrollments.status_akhir_semester',
                'students.nama as student_name',
            ])
            ->map(function ($row) use ($homeroomMap) {
                $homeroom = $homeroomMap->get($row->homeroom_id);
                $allowed = PromotionStatusService::allowed($homeroom);

                return [
                    'enrollment_id' => (int) $row->enrollment_id,
                    'student_id' => (int) $row->student_id,
                    'homeroom_id' => (int) $row->homeroom_id,
                    'student_name' => (string) $row->student_name,
                    'grade_level' => PromotionStatusService::gradeLevel($homeroom),
                    'is_final_level' => PromotionStatusService::isFinalLevel($homeroom),
                    'heading' => PromotionStatusService::reportHeading($homeroom),
                    'status' => PromotionStatusService::normalize(
                        $row->status_akhir_semester
                    ),
                    'options' => $allowed,
                ];
            })
            ->values();

        return response()->json([
            'enabled' => true,
            'semester_id' => $semesterId,
            'rows' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $semesterId = PromotionStatusService::activeSemesterId();

        if (!PromotionStatusService::isEvenSemester($semesterId)) {
            return response()->json([
                'message' => 'Status kenaikan/kelulusan hanya dapat diisi pada semester genap.',
            ], 422);
        }

        $validated = $request->validate([
            'enrollment_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $teacherId = $this->resolveTeacherId($request);
        $homerooms = $this->waliHomerooms($teacherId, $semesterId);

        $enrollment = DB::table('enrollments')
            ->where('id', (int) $validated['enrollment_id'])
            ->whereIn(
                'homeroom_id',
                $homerooms->pluck('id')->all()
            )
            ->first();

        if (!$enrollment) {
            abort(403, 'Enrollment bukan siswa pada kelas wali Anda untuk periode ini.');
        }

        $homeroom = $homerooms
            ->firstWhere('id', (int) $enrollment->homeroom_id);

        $statusRaw = trim((string) ($validated['status'] ?? ''));
        $status = $statusRaw === ''
            ? null
            : PromotionStatusService::normalize($statusRaw);

        if (
            $status !== null
            && !PromotionStatusService::isAllowedForHomeroom(
                $homeroom,
                $status
            )
        ) {
            return response()->json([
                'message' => 'Pilihan status tidak sesuai dengan tingkat kelas.',
            ], 422);
        }

        DB::table('enrollments')
            ->where('id', (int) $enrollment->id)
            ->update([
                'status_akhir_semester' => $status,
                'updated_at' => now(),
            ]);

        if (
            class_exists(WorkProductPushService::class)
            && method_exists(WorkProductPushService::class, 'markDirty')
        ) {
            try {
                WorkProductPushService::markDirty($teacherId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'ok' => true,
            'status' => $status,
            'label' => PromotionStatusService::label($status),
            'heading' => PromotionStatusService::reportHeading($homeroom),
        ]);
    }

    private function resolveTeacherId(Request $request): int
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $teacher = DB::table('teachers')
            ->where('user_id', $user->id)
            ->first();

        if (!$teacher && trim((string) ($user->login_id ?? '')) !== '') {
            $loginId = trim((string) $user->login_id);

            $teacher = DB::table('teachers')
                ->where(function ($query) use ($loginId) {
                    $query
                        ->where('nip', $loginId)
                        ->orWhere('nik', $loginId)
                        ->orWhere('nuptk', $loginId);
                })
                ->first();
        }

        if (!$teacher) {
            abort(403, 'Data GTK tidak ditemukan.');
        }

        return (int) $teacher->id;
    }

    private function waliHomerooms(
        int $teacherId,
        ?string $semesterId
    ) {
        $query = DB::table('homerooms')
            ->where('homeroom_teacher_id', $teacherId)
            ->where(function ($q) {
                $q->whereNull('jenis_rombel')
                    ->orWhere(function ($r) {
                        $r->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                            ->where('jenis_rombel', 'not like', '%Pilihan%');
                    });
            });

        if ($semesterId !== null && $semesterId !== '') {
            $query->where('semester_id', $semesterId);
        }

        return $query
            ->orderBy('id')
            ->get([
                'id',
                'nama',
                'tingkat_pendidikan',
                'semester_id',
                'jenis_rombel',
            ]);
    }
}
