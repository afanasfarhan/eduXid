<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Teacher;
use App\Services\Ljk\LjkCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class LjkScannerController extends Controller
{
    public function __construct(protected LjkCorrectionService $correction)
    {
    }

    protected function currentTeacher(): ?Teacher
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $teacher = Teacher::query()->where('user_id', $user->id)->first();

        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return null;
        }

        return Teacher::query()
            ->where(function ($q) use ($loginId) {
                $q->where('nip', $loginId)
                    ->orWhere('nik', $loginId)
                    ->orWhere('nuptk', $loginId);
            })
            ->first();
    }

    public function index(Request $request)
    {
        $teacher = $this->currentTeacher();

        if (!$teacher) {
            abort(403, 'Profil guru tidak ditemukan.');
        }

        $examConfigs = [];

        $exams = Exam::query()
            ->with('questions')
            ->where('teacher_id', $teacher->id)
            ->whereHas('questions')
            ->orderByDesc('id')
            ->get()
            ->filter(function ($exam) use (&$examConfigs) {
                $count = $exam->questions->count();
                $exam->setAttribute('questions_count', $count);

                if ($count < 1 || $count > LjkCorrectionService::MAX_QUESTIONS) {
                    return false;
                }

                try {
                    $examConfigs[(string) $exam->id] = $this->correction->questionBlueprint($exam);
                    return true;
                } catch (InvalidArgumentException) {
                    // Paket yang memuat esai/isian tidak ditawarkan pada LJK-A4-V2.
                    return false;
                }
            })
            ->values();

        $subjectNames = collect();
        if ($exams->isNotEmpty()) {
            $subjectNames = DB::table('subjects')
                ->whereIn('id', $exams->pluck('subject_id')->filter()->unique())
                ->get()
                ->keyBy('id');
        }

        return view('guru.ljk.index', [
            'teacher' => $teacher,
            'exams' => $exams,
            'subjectNames' => $subjectNames,
            'templateVersion' => LjkCorrectionService::TEMPLATE_VERSION,
            'selectedExamId' => $request->integer('exam_id') ?: null,
            'examConfigs' => $examConfigs,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $teacher = $this->currentTeacher();

        if (!$teacher) {
            return response()->json(['message' => 'Profil guru tidak ditemukan.'], 403);
        }

        $data = $request->validate($this->rules(false));

        try {
            $exam = Exam::findOrFail((int) $data['exam_id']);
            $this->correction->assertExamOwnedByTeacher($exam, (int) $teacher->id);

            $result = $this->correction->preview(
                $exam,
                (string) $data['nisn'],
                $data['answers'] ?? [],
                (string) $data['exam_date'],
                $data['confidence'] ?? [],
                (string) ($data['source'] ?? 'scanner')
            );

            return response()->json(['ok' => true, 'result' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => 'Gagal memvalidasi hasil LJK: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function finalize(Request $request): JsonResponse
    {
        $teacher = $this->currentTeacher();

        if (!$teacher) {
            return response()->json(['message' => 'Profil guru tidak ditemukan.'], 403);
        }

        $data = $request->validate($this->rules(true));
        $ambiguousCount = max(0, (int) ($data['ambiguous_count'] ?? 0));

        if ($ambiguousCount > 0 && empty($data['confirmed_review'])) {
            return response()->json([
                'ok' => false,
                'message' => 'Masih ada hasil OMR ambigu. Periksa dan konfirmasi sebelum finalisasi.',
            ], 422);
        }

        try {
            $exam = Exam::findOrFail((int) $data['exam_id']);
            $this->correction->assertExamOwnedByTeacher($exam, (int) $teacher->id);

            $result = $this->correction->finalize(
                $exam,
                (string) $data['nisn'],
                $data['answers'] ?? [],
                (string) $data['exam_date'],
                $data['confidence'] ?? [],
                $ambiguousCount,
                (string) ($data['source'] ?? 'scanner')
            );

            return response()->json([
                'ok' => true,
                'message' => 'Nilai LJK berhasil masuk ke Buku Nilai dan ditandai untuk sinkronisasi PWA Guru.',
                'result' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => 'Gagal menyimpan nilai LJK: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function batchPreview(Request $request): JsonResponse
    {
        $teacher = $this->currentTeacher();
        if (!$teacher) {
            return response()->json(['message' => 'Profil guru tidak ditemukan.'], 403);
        }

        $data = $request->validate($this->batchRules(false));

        try {
            $exam = Exam::findOrFail((int) $data['exam_id']);
            $this->correction->assertExamOwnedByTeacher($exam, (int) $teacher->id);

            $result = $this->correction->previewBatch(
                $exam,
                (array) $data['items'],
                (string) $data['exam_date']
            );

            return response()->json(['ok' => true] + $result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => 'Gagal memvalidasi batch LJK: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function batchFinalize(Request $request): JsonResponse
    {
        $teacher = $this->currentTeacher();
        if (!$teacher) {
            return response()->json(['message' => 'Profil guru tidak ditemukan.'], 403);
        }

        $data = $request->validate($this->batchRules(true));

        try {
            $exam = Exam::findOrFail((int) $data['exam_id']);
            $this->correction->assertExamOwnedByTeacher($exam, (int) $teacher->id);

            $result = $this->correction->finalizeBatch(
                $exam,
                (array) $data['items'],
                (string) $data['exam_date'],
                (string) $data['batch_uuid'],
                (int) ($data['batch_total'] ?? count((array) $data['items']))
            );

            return response()->json([
                'ok' => true,
                'message' => $result['finalized_count'] . ' nilai LJK berhasil masuk ke Buku Nilai.',
                'result' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => 'Finalisasi massal LJK gagal: ' . $e->getMessage(),
            ], 500);
        }
    }

    protected function rules(bool $finalize): array
    {
        $rules = [
            'exam_id' => ['required', 'integer', 'min:1'],
            'nisn' => ['required', 'string', 'regex:/^\\d{10}$/'],
            'exam_date' => ['required', 'date'],
            'source' => ['nullable', Rule::in(['scanner', 'camera'])],
            'answers' => ['required', 'array', 'min:1', 'max:50'],
            'answers.*' => ['nullable', 'string', 'max:16', 'regex:/^(?:[A-E](?:,[A-E])*)?$/'],
            'confidence' => ['nullable', 'array'],
            'ambiguous_count' => ['nullable', 'integer', 'min:0', 'max:60'],
        ];

        if ($finalize) {
            $rules['confirmed_review'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    protected function batchRules(bool $finalize): array
    {
        $rules = [
            'exam_id' => ['required', 'integer', 'min:1'],
            'exam_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.client_id' => ['required', 'string', 'max:100'],
            'items.*.nisn' => ['nullable', 'string', 'max:10'],
            'items.*.source' => ['nullable', Rule::in(['scanner', 'camera', 'pdf'])],
            'items.*.source_ref' => ['nullable', 'string', 'max:255'],
            'items.*.answers' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.answers.*' => ['nullable', 'string', 'max:16', 'regex:/^(?:[A-E](?:,[A-E])*)?$/'],
            'items.*.confidence' => ['nullable', 'array'],
            'items.*.ambiguous_count' => ['nullable', 'integer', 'min:0', 'max:60'],
        ];

        if ($finalize) {
            $rules['batch_uuid'] = ['required', 'string', 'min:8', 'max:64', 'regex:/^[a-zA-Z0-9-]+$/'];
            $rules['batch_total'] = ['nullable', 'integer', 'min:1', 'max:10000'];
            $rules['items.*.confirmed_review'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

}
