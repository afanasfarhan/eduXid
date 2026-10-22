<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\Teacher;
use App\Services\Ljk\LjkPrivatePackageService;
use App\Services\Sync\CbtCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class LjkCorrectionController extends Controller
{
    public function __construct(
        protected LjkPrivatePackageService $packages,
        protected CbtCorrectionService $correction,
    ) {
    }

    public function index(): View
    {
        $teacher = $this->currentTeacher();
        $exams = $teacher
            ? Exam::withCount('questions')->where('teacher_id', $teacher->id)->latest()->get()
            : collect();

        return view('guru.ljk.index', compact('exams'));
    }

    public function template()
    {
        $path = public_path('ljk/LJK-A4-V3.pdf');
        abort_unless(is_file($path), 404, 'Template LJK-A4-V3 belum terpasang.');
        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function privatePackage(Exam $exam): JsonResponse
    {
        $this->assertOwned($exam);
        return response()->json(['success' => true, 'data' => $this->packages->build($exam)]);
    }

    public function student(string $nisn): JsonResponse
    {
        $this->currentTeacherOrFail();
        $nisn = preg_replace('/\D+/', '', $nisn) ?? '';
        $student = $this->findStudentByNisn($nisn);

        return response()->json([
            'success' => (bool) $student,
            'data' => $student ? [
                'student_id' => (int) $student->id,
                'nisn' => (string) $student->nisn,
                'name' => (string) $student->nama,
            ] : null,
        ], $student ? 200 : 404);
    }

    public function syncPrivate(Exam $exam): JsonResponse
    {
        $this->assertOwned($exam);

        try {
            $result = $this->packages->syncToTeacherPwa($exam);
            return response()->json([
                'success' => true,
                'message' => 'Paket privat LJK dikirim ke PWA Guru (kunci + jenis soal + roster siswa).',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            Log::warning('LJK private package sync gagal.', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function commit(Request $request, Exam $exam): JsonResponse
    {
        $this->assertOwned($exam);

        $validated = $request->validate([
            'nisn' => ['required', 'string', 'max:20'],
            'answers' => ['required', 'array', 'max:50'],
            'answers.*' => ['array', 'max:5'],
            'answers.*.*' => ['nullable', 'string', 'max:2'],
        ]);

        $nisn = preg_replace('/\D+/', '', (string) $validated['nisn']) ?? '';
        $student = $this->findStudentByNisn($nisn);
        if (!$student) {
            return response()->json(['success' => false, 'message' => 'NISN tidak ditemukan pada data siswa.'], 422);
        }

        $questions = $exam->questions
            ->sortBy(fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? PHP_INT_MAX))
            ->values()
            ->take(50);

        if ($questions->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Paket ujian tidak memiliki soal.'], 422);
        }

        $normalized = [];
        $invalid = [];
        $unsupported = [];

        foreach ($questions as $index => $q) {
            $type = strtolower(trim((string) ($q->jenis_soal ?? '')));
            if (!in_array($type, ['pilihan_ganda', 'majemuk', 'benar_salah'], true)) {
                $unsupported[] = ($index + 1) . ' (' . ($type !== '' ? $type : 'tanpa jenis') . ')';
                continue;
            }

            $value = $validated['answers'][$index] ?? [];
            $set = $this->normalizeAnswer($value);
            $keySet = $this->normalizeAnswer($q->kunci_jawaban ?? null);
            $isMultipleType = str_contains($type, 'majemuk')
                || str_contains($type, 'multiple')
                || str_contains($type, 'multi')
                || count($keySet) > 1;

            if (!$isMultipleType && count($set) > 1) {
                $invalid[] = $index + 1;
            }

            $normalized[] = [
                'question' => $q,
                'answer' => $this->storageAnswerForQuestion($q, $set),
            ];
        }

        if ($unsupported !== []) {
            return response()->json([
                'success' => false,
                'message' => 'LJK hanya menerima Pilihan Ganda, Pilihan Majemuk, dan Benar/Salah. Periksa soal nomor: ' . implode(', ', $unsupported) . '.',
            ], 422);
        }

        if ($invalid !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Pilihan ganda biasa memiliki lebih dari satu isian pada nomor: ' . implode(', ', $invalid) . '. Periksa hasil scan sebelum menyimpan.',
            ], 422);
        }

        try {
            $session = DB::transaction(function () use ($exam, $student, $normalized) {
                $session = ExamSession::query()
                    ->where('exam_id', $exam->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();

                if ($session && $session->nilai_akhir !== null) {
                    throw new \RuntimeException('Nilai siswa untuk paket ini sudah final. Hapus/ubah nilai lama melalui alur koreksi resmi sebelum memindai ulang.');
                }

                if (!$session) {
                    $session = new ExamSession();
                    $session->forceFill([
                        'exam_id' => $exam->id,
                        'student_id' => $student->id,
                        'sync_id' => 'ljk::' . ($exam->sync_id ?: $exam->id) . '::' . $student->nisn,
                        'waktu_masuk' => now(),
                    ]);
                }

                $session->forceFill([
                    'status' => 'selesai',
                    'waktu_submit' => now(),
                    'nilai_akhir' => null,
                    'feedback' => 'Koreksi LJK-A4-V3 melalui Pemindai Kamera Presisi v13R11R1.',
                ])->save();

                foreach ($normalized as $row) {
                    StudentAnswer::updateOrCreate(
                        [
                            'exam_session_id' => $session->id,
                            'question_bank_id' => (int) $row['question']->id,
                        ],
                        [
                            'jawaban_siswa' => $row['answer'],
                            'is_benar' => null,
                            'nilai_per_soal' => 0,
                        ]
                    );
                }

                return $session->fresh();
            });

            try {
                $result = $this->correction->confirm(
                    $session,
                    null,
                    'Koreksi LJK-A4-V3 melalui Pemindai Kamera Presisi v13R11R1.'
                );
            } catch (Throwable $confirmError) {
                /*
                 * confirm() menulis nilai dalam transaksi lalu menjalankan side-effect
                 * markDirty di luar transaksi. Jika side-effect itu gagal, nilai dapat
                 * sudah tersimpan walau exception terlempar. Jangan melaporkan gagal
                 * palsu ke tombol Simpan Nilai.
                 */
                $savedSession = ExamSession::query()->find($session->id);
                if (!$savedSession || $savedSession->nilai_akhir === null) {
                    throw $confirmError;
                }

                Log::warning('LJK confirm menghasilkan exception setelah nilai tersimpan.', [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'error' => $confirmError->getMessage(),
                ]);

                $answerStats = StudentAnswer::query()
                    ->where('exam_session_id', $savedSession->id)
                    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_benar = 1 THEN 1 ELSE 0 END) as benar')
                    ->first();

                $result = [
                    'session_sync_id' => $savedSession->sync_id ?: ('ljk::' . ($exam->sync_id ?: $exam->id) . '::' . $student->nisn),
                    'exam_sync_id' => $exam->sync_id,
                    'student_id' => (string) $student->nisn,
                    'student_name' => (string) $student->nama,
                    'score' => (int) $savedSession->nilai_akhir,
                    'correct_count' => (int) ($answerStats->benar ?? 0),
                    'total_questions' => (int) ($answerStats->total ?? $questions->count()),
                    'results' => [],
                    'feedback' => $savedSession->feedback,
                    'waktu_submit' => optional($savedSession->waktu_submit)->toISOString(),
                    'sync_version' => (int) floor(microtime(true) * 1000),
                ];
            }

            try {
                $this->correction->pushCbtResult($result);
            } catch (Throwable $pushError) {
                Log::warning('LJK nilai tersimpan lokal tetapi push hasil gagal.', [
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'error' => $pushError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nilai LJK tersimpan.',
                'data' => [
                    'student_id' => (int) $student->id,
                    'nisn' => (string) $student->nisn,
                    'name' => (string) $student->nama,
                    'score' => (int) ($result['score'] ?? 0),
                    'correct_count' => (int) ($result['correct_count'] ?? 0),
                    'total_questions' => (int) ($result['total_questions'] ?? 0),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('LJK commit gagal.', [
                'exam_id' => $exam->id,
                'nisn' => $nisn,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function storageAnswerForQuestion($question, array $set): string
    {
        $type = strtolower(trim((string) ($question->jenis_soal ?? '')));
        $isTrueFalse = $type === 'benar_salah'
            || str_contains($type, 'benar')
            || str_contains($type, 'salah')
            || str_contains($type, 'true')
            || str_contains($type, 'false');

        if (!$isTrueFalse || $set === []) {
            return implode(',', $set);
        }

        // LJK fisik: A=Benar, B=Salah. Penyimpanan resmi CBT/Bank Soal:
        // B=Benar, S=Salah. Simpan dalam format kanonik agar semua jalur
        // koreksi memakai arti huruf yang sama.
        return match ($set[0] ?? '') {
            'A' => 'B',
            'B' => 'S',
            default => '',
        };
    }

    private function normalizeAnswer(mixed $value): array
    {
        if (is_string($value)) {
            $raw = strtoupper(trim($value));
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } elseif (preg_match('/^[A-E]{2,5}$/', $raw)) {
                $value = str_split($raw);
            } else {
                $value = preg_split('/[\s,;|+\/]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }

        if (is_array($value) && !array_is_list($value)) {
            $assoc = [];
            foreach ($value as $k => $v) {
                $letter = strtoupper(trim((string) $k));
                $truthy = $v === true || $v === 1 || $v === '1' || strtolower(trim((string) $v)) === 'true';
                if ($truthy && in_array($letter, ['A', 'B', 'C', 'D', 'E'], true)) {
                    $assoc[] = $letter;
                }
            }
            if ($assoc !== []) {
                $value = $assoc;
            }
        }

        if (!is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                foreach ($this->normalizeAnswer($item) as $sub) {
                    if (!in_array($sub, $out, true)) {
                        $out[] = $sub;
                    }
                }
                continue;
            }

            $v = strtoupper(trim((string) $item));
            if (preg_match('/^[A-E]{2,5}$/', $v)) {
                foreach (str_split($v) as $ch) {
                    if (!in_array($ch, $out, true)) {
                        $out[] = $ch;
                    }
                }
                continue;
            }

            if (in_array($v, ['A', 'B', 'C', 'D', 'E'], true) && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        sort($out);
        return $out;
    }

    private function normalizeNisn(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) < 10) {
            $digits = str_pad($digits, 10, '0', STR_PAD_LEFT);
        }
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function findStudentByNisn(string $nisn): ?Student
    {
        $normalized = $this->normalizeNisn($nisn);
        if ($normalized === '') {
            return null;
        }

        $student = Student::query()->where('nisn', $normalized)->first();
        if ($student) {
            return $student;
        }

        if (ctype_digit($normalized)) {
            $numeric = (int) ltrim($normalized, '0');
            if ($numeric === 0 && preg_match('/^0+$/', $normalized)) {
                $numeric = 0;
            }

            $student = Student::query()
                ->whereRaw('CAST(nisn AS INTEGER) = ?', [$numeric])
                ->first();

            if ($student && $this->normalizeNisn($student->nisn ?? '') === $normalized) {
                return $student;
            }
        }

        return Student::query()->get()->first(
            fn ($row) => $this->normalizeNisn($row->nisn ?? '') === $normalized
        );
    }

    private function currentTeacher(): ?Teacher
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? $user->username ?? $user->nip ?? $user->nik ?? ''));
        if ($loginId === '') {
            return null;
        }

        return Teacher::query()->where(function ($q) use ($loginId) {
            $q->where('nip', $loginId)->orWhere('nik', $loginId)->orWhere('nuptk', $loginId);
        })->first();
    }

    private function currentTeacherOrFail(): Teacher
    {
        $teacher = $this->currentTeacher();
        abort_unless($teacher, 403, 'Data guru tidak ditemukan.');
        return $teacher;
    }

    private function assertOwned(Exam $exam): Teacher
    {
        $teacher = $this->currentTeacherOrFail();
        abort_unless((int) $exam->teacher_id === (int) $teacher->id, 403, 'Paket ujian ini bukan milik Anda.');
        return $teacher;
    }
}
