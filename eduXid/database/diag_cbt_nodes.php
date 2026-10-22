<?php

use Illuminate\Support\Facades\DB;

/*
 * [DIAG] Jejak submit CBT — dari perangkat mana (node) tiap event.
 * node berakhiran -HP = PWA siswa; -PC/CLI = LAN/laptop.
 */

echo "== EVENT cbt_answer (10 terakhir) ==\n";

 $recs = DB::table('lms_sync_records')
    ->where('entity_type', 'cbt_answer')
    ->orderByDesc('id')
    ->limit(10)
    ->get(['entity_id', 'status', 'node_id']);

foreach ($recs as $r) {
    echo substr((string) $r->entity_id, 0, 13) . '…'
        . ' | ' . $r->status
        . ' | node=' . $r->node_id . "\n";
}

echo "\n== SESI + STATUS CHIP ==\n";

 $rows = DB::table('exam_sessions as es')
    ->join('students as s', 's.id', '=', 'es.student_id')
    ->get(['es.id', 's.nama', 'es.sync_id', 'es.nilai_akhir']);

foreach ($rows as $r) {
    $sid = trim((string) ($r->sync_id ?? ''));
    $chip = ($sid !== '' && !str_starts_with($sid, 'lan::')) ? '📱 PWA' : '💻 LAN';
    echo $r->id . ' | ' . $r->nama
        . ' | sync_id=' . substr($sid, 0, 12) . '…'
        . ' | nilai=' . (string) $r->nilai_akhir
        . ' | chip=' . $chip . "\n";
}

echo "\n-- selesai --\n";