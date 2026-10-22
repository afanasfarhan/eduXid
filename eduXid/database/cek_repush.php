<?php

use App\Models\Exam;
use App\Models\ExamSession;
use App\Services\Sync\CbtCorrectionService;

/* [DIAG] Re-push + tampilkan hasil bertingkat (student / guru HP / guru PC). */

 $sessions = ExamSession::whereNotNull('nilai_akhir')->with(['exam','student'])->get();
 $svc = app(CbtCorrectionService::class);

foreach ($sessions as $ses) {
    $exam = $ses->exam;

    if (!$exam || empty($exam->sync_id)) {
        echo "sesi#{$ses->id}: skip (exam LAN-only)\n";
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

    $pick = fn ($r) => $r ? ('status=' . ($r['status'] ?? '?') . ', success=' . var_export($r['success'] ?? null, true)) : 'null';

    echo "sesi#{$ses->id} ({$ses->student->nama}):\n"
        . "  siswa   : " . $pick($res['student'] ?? null) . "\n"
        . "  guru HP : " . $pick($res['teacher_hp'] ?? null) . "\n"
        . "  guru PC : " . $pick($res['teacher_pc'] ?? null) . "\n";
}

echo "-- selesai --\n";