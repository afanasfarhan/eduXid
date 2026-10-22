<?php

use Illuminate\Support\Facades\DB;

/*
 * [DIAG] Koreksi CBT — jalankan: php artisan tinker database\diag_koreksi.php
 * (atau: php artisan tinker lalu include; aman read-only kecuali bagian 4E)
 */

/* ---------- B. Baris sesi siswa terbaru ---------- */
echo "== SESI (id | status | nilai | sync_id | nama) ==\n";

 $sesi = DB::table('exam_sessions as es')
    ->join('students as s', 's.id', '=', 'es.student_id')
    ->orderByDesc('es.id')
    ->limit(5)
    ->get(['es.id', 'es.status', 'es.nilai_akhir', 'es.sync_id', 's.nama']);

foreach ($sesi as $r) {
    echo $r->id . ' | ' . $r->status
        . ' | nilai=' . (string) $r->nilai_akhir
        . ' | sync_id=' . (string) ($r->sync_id ?? '')
        . ' | ' . $r->nama . "\n";
}

/* ---------- C. Progres koreksi ---------- */
echo "\n== PROGRES (session | total | belum_dinilai) ==\n";

 $prog = DB::table('student_answers')
    ->selectRaw('exam_session_id, COUNT(*) AS total, SUM(CASE WHEN is_benar IS NULL THEN 1 ELSE 0 END) AS belum')
    ->groupBy('exam_session_id')
    ->orderByDesc('exam_session_id')
    ->limit(5)
    ->get();

foreach ($prog as $r) {
    echo $r->exam_session_id . ' | total=' . $r->total . ' | belum=' . $r->belum . "\n";
}

/* ---------- D. Soal yang belum dikoreksi (sesi pertama di atas) ---------- */
 $targetId = (int) ($sesi->first()->id ?? 0);

if ($targetId > 0) {
    echo "\n== BELUM DIKOREKSI (sesi {$targetId}) ==\n";

    $soal = DB::table('student_answers as sa')
        ->join('question_banks as q', 'q.id', '=', 'sa.question_bank_id')
        ->where('sa.exam_session_id', $targetId)
        ->whereNull('sa.is_benar')
        ->get(['sa.question_bank_id', 'q.jenis_soal', 'q.pertanyaan']);

    if ($soal->isEmpty()) {
        echo "(tidak ada — semua sudah dinilai)\n";
    }

    foreach ($soal as $r) {
        echo $r->question_bank_id . ' | ' . $r->jenis_soal . ' | '
            . substr((string) $r->pertanyaan, 0, 40) . "\n";
    }

    /* ---------- E. SIMULASI gradeObjective per soal (tanpa menulis!) ---------- */
    echo "\n== SUGGESTION ENGINE (apakah semua bisa di-pre-fill?) ==\n";

    $exam = DB::table('exam_sessions')->where('id', $targetId)->first();
    $examId = (int) $exam->exam_id;

    $countSoalExam = DB::table('exams as e')
        ->join('exam_question as eq', 'eq.exam_id', '=', 'e.id')   // tabel pivot: sesuaikan bila nama beda
        ->where('e.id', $examId)
        ->count();

    $countJawaban = DB::table('student_answers')->where('exam_session_id', $targetId)->count();

    echo 'soal di exam=' . $countSoalExam . ' | baris jawaban tersimpan=' . $countJawaban . "\n";
    echo "(koreksi butuh keduanya sama; bila jawaban < soal → materialize gagal → cek pivot)\n";
}

echo "\n-- selesai --\n";