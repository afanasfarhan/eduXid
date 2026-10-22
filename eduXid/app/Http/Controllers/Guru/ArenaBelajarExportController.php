<?php

namespace App\Http\Controllers\Guru;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use App\Services\Sync\GtkPairedDeviceV21;
use App\Services\Sync\SyncClient;

/** V21R113: export a teacher-owned exam as a separate formative practice file. */
final class ArenaBelajarExportController extends Controller
{
    private function prepare(Exam $exam): array
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if (!$teacher && trim((string) ($user->login_id ?? '')) !== '') {
            $login = trim((string) $user->login_id);
            $teacher = Teacher::query()->where('nip', $login)->orWhere('nik', $login)->orWhere('nuptk', $login)->first();
        }
        abort_unless($teacher && (int) $exam->teacher_id === (int) $teacher->id, 403);
        if (strtolower((string) $exam->status) === 'aktif') {
            throw new \RuntimeException('Tutup ujian aktif sebelum menyalin soalnya ke Arena Belajar.');
        }
        $source = $exam->questions()->get();
        if ($source->count() < 1 || $source->count() > 30) {
            throw new \RuntimeException('Arena Belajar mendukung 1–30 soal per paket.');
        }
        $questions = [];
        foreach ($source as $number => $question) {
            if ((string) $question->jenis_soal !== 'pilihan_ganda') {
                throw new \RuntimeException('Paket mengandung soal non-pilihan-ganda. Arena Belajar V1 hanya mendukung pilihan ganda satu jawaban.');
            }
            if (trim((string) ($question->gambar_soal ?? '')) !== '' || trim((string) ($question->video_url ?? '')) !== '') {
                throw new \RuntimeException('Paket mengandung gambar/video. Ekspor Arena Belajar V1 hanya mendukung soal teks.');
            }
            $text = trim(strip_tags((string) ($question->pertanyaan ?? '')));
            $raw = $question->opsi_jawaban;
            if (is_string($raw)) $raw = json_decode($raw, true);
            if (!is_array($raw)) $raw = [];
            $options = [];
            $seenGap = false;
            foreach (['A', 'B', 'C', 'D', 'E'] as $idx => $letter) {
                $entry = $raw[$letter] ?? $raw[strtolower($letter)] ?? $raw[$idx] ?? null;
                if (is_array($entry)) $entry = $entry['text'] ?? null;
                if ($entry === null || trim((string) $entry) === '') { $seenGap = true; continue; }
                if ($seenGap) throw new \RuntimeException('Opsi soal harus berurutan mulai dari A tanpa celah kosong.');
                $options[] = trim(strip_tags((string) $entry));
            }
            $key = strtoupper(trim((string) ($question->kunci_jawaban ?? '')));
            if (count($options) < 4 || count($options) > 5 || !preg_match('/^[A-E]$/', $key)) {
                throw new \RuntimeException('Soal nomor '.($number + 1).' memiliki opsi/kunci yang tidak sesuai format Arena Belajar.');
            }
            $correct = ord($key) - 65;
            if ($correct >= count($options) || $text === '' || mb_strlen($text) > 500) {
                throw new \RuntimeException('Soal nomor '.($number + 1).' tidak memenuhi ketentuan panjang/format.');
            }
            foreach ($options as $option) {
                if ($option === '' || mb_strlen($option) > 200) {
                    throw new \RuntimeException('Opsi soal nomor '.($number + 1).' terlalu panjang/kosong.');
                }
            }
            $questions[] = ['text' => $text, 'options' => $options, 'correct' => $correct];
        }
        $title = mb_substr(trim((string) ($exam->judul_ujian ?? 'Latihan')), 0, 100);
        // Fingerprint refers to the actual exam-question snapshot, not the separate Bank Soal.
        $snapshot = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        $data = [
            'format' => 'eduxid.arena.practice.v1',
            'title' => $title,
            'questions' => $questions,
            'source_exam' => [
                'origin' => 'eduxid_laptop_exam',
                'exam_id' => (int) $exam->id,
                'teacher_id' => (int) $teacher->id,
                'subject_id' => (int) ($exam->subject_id ?? 0),
                'question_count' => count($questions),
                'sha256' => hash('sha256', $snapshot),
            ],
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 45000) throw new \RuntimeException('Ukuran paket latihan melebihi batas Cloud 45 KB.');
        return $data;
    }

    public function __invoke(Exam $exam)
    {
        try { $data = $this->prepare($exam); }
        catch (\RuntimeException $e) { return redirect()->route('guru.cbt')->with('error', $e->getMessage()); }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="eduxid-arena-exam-'.(int)$exam->id.'.json"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** V21R128R2: share only teacher-owned, closed exam practice, with V113 proof intact. */
    public function publishToCloud(Exam $exam)
    {
        try {
            $data = $this->prepare($exam);
            $user = Auth::user();
            abort_unless($user, 403);
            $device = app(GtkPairedDeviceV21::class)->resolve($user);
            if (!$device) return redirect()->route('guru.cbt')->with('error', 'Pairing PC Guru ke Cloud diperlukan.');
            app(SyncClient::class)->publishArenaExamSource(
                (string) $device->school_id,
                (string) $device->device_id,
                [
                    'exam_id' => (int) $exam->id,
                    'title' => $data['title'],
                    'questions' => $data['questions'],
                    'format' => $data['format'],
                    'source_exam' => $data['source_exam'],
                ]
            );
            return redirect()->route('guru.cbt')->with('success', 'Sumber latihan dikirim ke Cloud. Tarik dari PWA Guru → Arena Belajar.');
        } catch (\RuntimeException $e) {
            \Log::warning('[ARENA R128R2] Publish gagal', ['exam_id' => $exam->id, 'reason' => get_class($e)]);
            return redirect()->route('guru.cbt')->with('error', 'Paket latihan gagal dikirim. Periksa pairing PC, direktori guru, dan versi Worker.');
        }
    }
}
