<?php

use Illuminate\Support\Facades\DB;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\Student;
use App\Services\Sync\WorkProductPushService;
use App\Services\Sync\CbtCorrectionService;

/* [F5] Re-push work product + cbt_result ke Cloud — untuk mengisi
 * PWA guru yang belum pernah menerima daily_grade (di-ACK 'unknown'
 * sebelum fix). Versi batch baru (max updated_at) menang LWW. */

 $taIds = DB::table('teaching_assignments')->pluck('id');

foreach ($taIds as $taId) {
    $r = app(\App\Services\Sync\LmsSyncService::class)
        ->pushDailyGradeBatchForAssignment((int) $taId);
    echo "daily_grade TA#{$taId}: " . ($r['status'] ?? '?') . "\n";
}

/* cbt_result semua sesi yang sudah ternilai */
 $sessions = ExamSession::whereNotNull('nilai_akhir')->with(['exam','student'])->get();
 $svc = app(CbtCorrectionService::class);

foreach ($sessions as $ses) {
    $exam = $ses->exam;
    if (!$exam || empty($exam->sync_id)) {
        echo "cbt_result sesi#{$ses->id}: skip (exam LAN-only)\n";
        continue;
    }

    $res = $svc->pushCbtResult([
        'session_sync_id' => $ses->sync_id ?: ('lan::' . $ses->exam_id . '::' . $ses->student_id),
        'exam_sync_id'    => $exam->sync_id,
        'student_id'      => (string) $ses->student->nisn,
        'student_name'    => $ses->student->nama,
        'score'           => $ses->nilai_akhir,
        'correct_count'   => null,
        'total_questions' => $exam->questions->count(),
        'results'         => [],
        'feedback'        => $ses->feedback,
        'waktu_submit'    => optional($ses->waktu_submit)->toISOString(),
        'sync_version'    => (int) floor(microtime(true) * 1000),
    ]);

    echo "cbt_result sesi#{$ses->id}: " . ($res['status'] ?? '?') . "\n";
}

echo "-- selesai --\n";