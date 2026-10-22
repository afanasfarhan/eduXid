<?php

use Illuminate\Support\Facades\DB;

 $count = DB::table('exam_sessions')
    ->whereNull('sync_id')
    ->update([
        'sync_id' => DB::raw("'lan::' || exam_id || '::' || student_id"),
    ]);

echo 'backfill selesai: ' . $count . " baris diperbarui\n";

 $sesi = DB::table('exam_sessions')->orderByDesc('id')->limit(5)->get(['id', 'sync_id']);

foreach ($sesi as $s) {
    echo $s->id . ' | sync_id=' . (string) ($s->sync_id ?? '') . "\n";
}