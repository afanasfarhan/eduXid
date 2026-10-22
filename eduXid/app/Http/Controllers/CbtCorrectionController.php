<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\Teacher;
use App\Services\Sync\CbtCorrectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CbtCorrectionController extends Controller
{
    /*
     * ==========================================================
     * [KONTRAK-KOREKSI] UI koreksi CBT guru (server-side).
     *
     *   index      → antrian: sesi selesai tanpa nilai (LAN & PWA).
     *   show       → satu siswa: suggestion objektif pre-filled,
     *                esai/jawaban singkat menunggu guru.
     *   confirm    → SATU-SATUNYA penulis nilai resmi (via service):
     *                nilai_akhir + assessment_scores (buku nilai
     *                harian) + cbt_result (angka + feedback ke PWA
     *                siswa) + markDirty (fan-out ke PWA guru).
     *   confirmAll → konfirmasi satu klik untuk exam full-objektif.
     * ==========================================================
     */

    public function __construct(protected CbtCorrectionService $correction)
    {
    }

    /*
     * [IDENTITAS] user_id dulu, lalu NIP/NIK/NUPTK — satu semantik
     * dengan ExamController & modul LMS lain.
     */
    private function currentTeacher(): ?Teacher
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $teacher = Teacher::where('user_id', $user->id)->first();

        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return null;
        }

        return Teacher::where(function ($q) use ($loginId) {
            $q->where('nip', $loginId)
              ->orWhere('nik', $loginId)
              ->orWhere('nuptk', $loginId);
        })->first();
    }

    /** Antrian koreksi: sesi selesai dengan nilai_akhir NULL. */
    public function index(Request $request)
    {
        $guru = $this->currentTeacher();

        /*
         * [CB-36] Sesi difilter eksplisit ke exam milik guru.
         * pendingSessions() mengembalikan Collection (bukan Builder),
         * jadi filternya via set exam-id (flip → O(1) lookup).
         */
        $sessions = collect();

        $examIds = collect();

        if ($guru) {
            $examIds = Exam::query()
                ->where('teacher_id', $guru->id)
                ->pluck('id')
                ->flip();

            $sessions = $this->correction->pendingSessions(
                $request->filled('exam_id') ? (int) $request->exam_id : null
            )
            ->filter(fn ($s) => $examIds->has($s->exam_id))
            ->values();   /* reset key agar foreach di Blade rapi */

            $exams = Exam::query()
                ->where('teacher_id', $guru->id)
                ->whereIn('id', function ($q) {
                    $q->select('exam_id')
                      ->from('exam_sessions')
                      ->where('status', 'selesai')
                      ->whereNull('nilai_akhir');
                })
                ->get();
        } else {
            $exams = collect();
        }

        return view('guru.cbt.koreksi', compact('sessions', 'exams'));
    }

    /** Soal yang membutuhkan keputusan guru dan tidak boleh auto-grade. */
    private function hasManualQuestion(Exam $exam): bool
    {
        return $exam->questions->contains(function ($q): bool {
            $type = strtolower(trim((string) ($q->jenis_soal ?? '')));
            return str_contains($type, 'esai') || str_contains($type, 'singkat');
        });
    }

    /**
     * [F5] KOREKSI OTOMATIS — nilai semua sesi pending exam ini memakai
     * kunci jawaban (hanya aman untuk exam full-objektif). Sesi dengan
     * soal manual otomatis dilewati oleh confirmAllForExam. Hasil: nilai resmi
     * + buku nilai harian + cbt_result ke HP siswa, per sesi.
     */
    public function autoGrade(Request $request, Exam $exam)
    {
        $guru = $this->currentTeacher();

        abort_unless($guru && (int) $exam->teacher_id === (int) $guru->id, 403);

        if ($this->hasManualQuestion($exam)) {
            return back()->with(
                'error',
                'Koreksi otomatis tidak dapat dijalankan: paket ujian "' . $exam->judul_ujian
                . '" mengandung soal esai/jawaban singkat yang membutuhkan koreksi guru.'
            );
        }

        $pending = DB::table('exam_sessions')
            ->where('exam_id', $exam->id)
            ->where('status', 'selesai')
            ->whereNull('nilai_akhir')
            ->count();

        if ($pending === 0) {
            return back()->with('error', 'Tidak ada jawaban yang menunggu koreksi untuk ujian ini.');
        }

        $summary = $this->correction->confirmAllForExam($exam->id);

        $confirmed = (int) ($summary['confirmed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
        $warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];

        $msg = "⚡ Koreksi otomatis selesai untuk \"{$exam->judul_ujian}\": "
            . "{$confirmed} murid dinilai"
            . ($skipped > 0 ? ", {$skipped} dilewati" : '')
            . '. Nilai yang berhasil dikoreksi sudah masuk Buku Nilai.';

        if ($errors !== []) {
            return back()->with('warning', $msg . ' Error: ' . implode('; ', array_slice($errors, 0, 3)));
        }

        if ($warnings !== []) {
            return back()->with('warning', $msg . ' Sinkronisasi: ' . implode('; ', array_slice($warnings, 0, 3)));
        }

        return back()->with('success', $msg . ' Hasil juga telah diantrikan/dikirim ke perangkat murid.');
    }

    /** Halaman koreksi satu siswa. */
    public function show(ExamSession $session)
    {
        $exam = Exam::with('questions')->findOrFail($session->exam_id);
        $guru = $this->currentTeacher();

        abort_unless($guru && (int) $exam->teacher_id === (int) $guru->id, 403);

        if ($session->nilai_akhir !== null) {
            return redirect()
                ->route('guru.cbt.koreksi')
                ->with('warning', 'Sesi ini sudah memiliki nilai resmi dan tidak lagi berada dalam antrean koreksi.');
        }

        $student = Student::findOrFail($session->student_id);
        $answers = StudentAnswer::where('exam_session_id', $session->id)
            ->get()->keyBy('question_bank_id');

        /*
         * [COMPAT-ROW + ARRAY-ACCESS FIX] gradeObjective() mengembalikan
         * ARRAY {is_benar, nilai} — dibaca dengan bracket, bukan arrow.
         * Baris dikirim sebagai stdClass agar view arrow-style jalan.
         */
        $rows = $exam->questions
            ->sortBy(fn ($q) => (int) ($q->pivot->urutan ?? $q->urutan ?? 0))
            ->map(function ($q) use ($answers) {
                $ans = $answers->get($q->id);
                $graded = $this->correction->gradeObjective($q, $ans?->jawaban_siswa);

                $suggestion = is_array($graded)
                    ? ($graded['is_benar'] ?? null)
                    : null;

                return (object) [
                    'question'   => $q,
                    'jawaban'    => $ans?->jawaban_siswa,
                    'suggestion' => $suggestion,   // null = soal manual
                ];
            });

        return view('guru.cbt.koreksi-detail', [
            'session'  => $session,
            'exam'     => $exam,
            'student'  => $student,
            'rows'     => $rows,
        ]);
    }

    /** Konfirmasi satu siswa → nilai resmi + buku nilai + cbt_result. */
    public function confirm(Request $request, ExamSession $session)
    {
        $exam = Exam::findOrFail($session->exam_id);
        $guru = $this->currentTeacher();

        abort_unless($guru && (int) $exam->teacher_id === (int) $guru->id, 403);

        if ($session->nilai_akhir !== null) {
            return redirect()
                ->route('guru.cbt.koreksi')
                ->with('warning', 'Nilai sesi ini sudah resmi tersimpan. Konfirmasi ulang dibatalkan untuk mencegah penulisan ganda.');
        }

        /*
         * [CB-34] Koreksi hanya untuk sesi yang benar-benar SELESAI —
         * jawaban setengah jadi tidak bisa dikonfirmasi sebagai nilai resmi.
         */
        if ($session->status !== 'selesai') {
            return back()->with(
                'error',
                'Sesi ini masih berstatus pengerjaan (' . ($session->status ?? '-') . '). '
                . 'Nilai hanya dapat dikonfirmasi setelah murid menekan Selesai/Kumpulkan.'
            );
        }

        /*
         * [OVERRIDE] Benar/salah eksplisit dari guru; objektif yang tidak
         * disentuh tetap memakai suggestion (confirm() menghitung ulang).
         */
        $validQuestionIds = $exam->questions()->pluck('question_banks.id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $overrides = [];

        foreach ((array) $request->input('benar', []) as $qid => $val) {
            $qid = (int) $qid;

            if ($qid > 0 && $validQuestionIds->has($qid)) {
                $overrides[$qid] = ['is_benar' => true];
            }
        }

        foreach ((array) $request->input('salah', []) as $qid => $val) {
            $qid = (int) $qid;

            if ($qid > 0 && $validQuestionIds->has($qid) && !array_key_exists($qid, $overrides)) {
                $overrides[$qid] = ['is_benar' => false];
            }
        }

        try {
            $result = $this->correction->confirm(
                $session,
                $overrides,
                trim((string) $request->input('feedback', '')) ?: null
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Konfirmasi gagal: ' . $e->getMessage());
        }

        /*
         * [CB-31] Nilai SUDAH resmi tersimpan — push hasil ke siswa
         * dipisah: kegagalan sinkronisasi tidak lagi berarti
         * "Konfirmasi gagal" (outbox mengejar otomatis).
         */
        try {
            $delivery = $this->correction->pushCbtResult($result);
            $toStudent = is_array($delivery['student'] ?? null) ? $delivery['student'] : [];
            $queued = ($toStudent['success'] ?? false) === true
                && in_array((string) ($toStudent['status'] ?? ''), ['accepted', 'duplicate', 'success'], true);

            if (!$queued) {
                return redirect()
                    ->route('guru.cbt.koreksi')
                    ->with('warning', "Nilai {$result['score']} dan feedback TERSIMPAN di Buku Nilai untuk "
                        . ($result['student_name'] ?? 'siswa')
                        . '. Pengiriman ke Cloud belum terkonfirmasi ('
                        . (string) ($toStudent['error'] ?? $toStudent['status'] ?? 'belum ada konfirmasi')
                        . '). Periksa outbox sinkronisasi sebelum menyatakan nilai telah diterima siswa.');
            }

            return redirect()
                ->route('guru.cbt.koreksi')
                ->with('success', "Nilai {$result['score']} dan feedback tersimpan untuk "
                    . ($result['student_name'] ?? 'siswa')
                    . ' — diterima antrean Cloud. Penerimaan pada HP siswa menunggu sinkronisasi dan ACK.');
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('guru.cbt.koreksi')
                ->with('warning', "Nilai {$result['score']} TERSIMPAN untuk "
                    . ($result['student_name'] ?? 'siswa')
                    . ' — masuk buku nilai harian. Pengiriman ke perangkat murid menunggu antrean sinkronisasi ('
                    . $e->getMessage() . ').');
        }
    }

    /** Konfirmasi semua siswa (exam full-objektif = satu klik). */
    public function confirmAll(Exam $exam)
    {
        $guru = $this->currentTeacher();

        abort_unless($guru && (int) $exam->teacher_id === (int) $guru->id, 403);

        $summary = $this->correction->confirmAllForExam($exam->id);

        $confirmed = (int) ($summary['confirmed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
        $warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];

        $msg = "Konfirmasi selesai: {$confirmed} murid dinilai, "
            . "{$skipped} dilewati (soal manual belum dikoreksi).";

        if ($errors !== []) {
            return back()->with('warning', $msg . ' Error: ' . implode('; ', array_slice($errors, 0, 3)));
        }

        if ($warnings !== []) {
            return back()->with('warning', $msg . ' Sinkronisasi: ' . implode('; ', array_slice($warnings, 0, 3)));
        }

        return back()->with('success', $msg);
    }
}