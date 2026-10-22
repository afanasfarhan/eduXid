<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\LmsAssignment;
use App\Services\Sync\LmsSyncService;
use Illuminate\Console\Command;
use Throwable;

class RepushExams extends Command
{
    protected $signature = 'eduxid:repush-exams {--exam_id=}';
    protected $description = 'Re-push paket CBT (kanonik, tanpa kunci) dan assignment CBT-nya agar perangkat siswa menggantikan payload lama (termasuk exam_sync_id yang kosong).';

    public function handle(LmsSyncService $sync): int
    {
        $filterId = $this->option('exam_id') ? (int) $this->option('exam_id') : null;

        /*
         * [URUTAN] Exam dulu (mendapat sync_id + payload kanonik),
         * lalu assignment (payload dengan exam_sync_id terisi).
         */
        $examIds = LmsAssignment::query()
            ->whereNotNull('exam_id')
            ->when($filterId, fn ($q) => $q->where('exam_id', $filterId))
            ->distinct()
            ->pluck('exam_id');

        foreach ($examIds as $examId) {
            $exam = Exam::find($examId);

            if (!$exam) { continue; }

            try {
                $r = $sync->pushExam($exam, 'update');
                $this->line(($r['success'] ?? false) ? "✓ exam #{$exam->id} {$exam->judul_ujian}" : "⚠ exam #{$exam->id} pending");
            } catch (Throwable $e) {
                $this->error("✗ exam #{$exam->id}: {$e->getMessage()}");
            }
        }

        $assignments = LmsAssignment::query()
            ->where('assignment_type', 'cbt')
            ->whereNotNull('exam_id')
            ->when($filterId, fn ($q) => $q->where('exam_id', $filterId))
            ->get();

        foreach ($assignments as $assignment) {
            try {
                $r = $sync->pushAssignmentToStudents($assignment->fresh(['topic.course']), 'update');
                $status = strtoupper((string) ($r['status'] ?? ''));

                $this->line(in_array($status, ['ACCEPTED', 'DUPLICATE', 'SUCCESS'], true)
                    ? "✓ assignment #{$assignment->id} {$assignment->title}"
                    : "⚠ assignment #{$assignment->id} pending ({$status})");
            } catch (Throwable $e) {
                $this->error("✗ assignment #{$assignment->id}: {$e->getMessage()}");
            }
        }

        $this->info('Selesai — perangkat siswa menerima payload baru saat sinkron berikutnya.');
        return 0;
    }
}