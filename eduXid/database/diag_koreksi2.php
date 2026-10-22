<?php

use Illuminate\Support\Facades\DB;
use App\Services\Sync\CbtCorrectionService;

echo "== 1. TABEL TERKAIT EXAM ==\n";
 $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE '%exam%' OR name LIKE '%question%')"))->pluck('name');
echo $tables->implode(', ') . "\n";

/* deteksi pivot: punya exam_id + question_bank_id */
 $pivot = null;
foreach ($tables as $t) {
    $cols = collect(DB::select("PRAGMA table_info({$t})"))->pluck('name');
    if ($cols->contains('exam_id') && ($cols->contains('question_bank_id') || $cols->contains('question_id')) && $t !== 'student_answers') {
        $pivot = $t;
        echo "\n== 2. PIVOT TERDETEKSI: {$t} ==\n";
        echo $cols->implode(', ') . "\n";
    }
}

 $examId = (int) (DB::table('exam_sessions')->where('id', 1)->value('exam_id') ?? 0);
echo "\nexam_id sesi#1 = {$examId}\n";

if ($pivot) {
    $qcol = collect(DB::select("PRAGMA table_info({$pivot})"))->pluck('name')->contains('question_bank_id') ? 'question_bank_id' : 'question_id';
    $count = DB::table($pivot)->where('exam_id', $examId)->count();
    echo "soal terhubung exam#{$examId} via pivot = {$count}\n";
}

echo "\n== 3. SAMPEL KUNCI JAWABAN (3 soal pertama sesi#1) ==\n";
 $keys = DB::table('student_answers as sa')
    ->join('question_banks as q', 'q.id', '=', 'sa.question_bank_id')
    ->where('sa.exam_session_id', 1)
    ->limit(3)
    ->get(['sa.question_bank_id', 'q.jenis_soal', 'q.kunci_jawaban', 'sa.jawaban_siswa']);

foreach ($keys as $k) {
    echo '#' . $k->question_bank_id . ' | ' . $k->jenis_soal
        . "\n   kunci  : " . substr((string) $k->kunci_jawaban, 0, 80)
        . "\n   jawaban: " . substr((string) $k->jawaban_siswa, 0, 80) . "\n";
}

echo "\n== 4. SUGGESTION ENGINE per soal (read-only) ==\n";
 $svc = app(CbtCorrectionService::class);

 $rows = DB::table('student_answers as sa')
    ->join('question_banks as q', 'q.id', '=', 'sa.question_bank_id')
    ->where('sa.exam_session_id', 1)
    ->get(['sa.*', 'q.jenis_soal']);

foreach ($rows as $sa) {
    $q = DB::table('question_banks')->where('id', $sa->question_bank_id)->first();
    $g = $svc->gradeObjective($q, $sa->jawaban_siswa);
    echo '#' . $sa->question_bank_id . ' | ' . $sa->jenis_soal . ' | suggestion='
        . ($g === null ? 'NULL (dianggap esai!)' : var_export($g['is_benar'], true))
        . "\n";
}

echo "\n-- selesai --\n";