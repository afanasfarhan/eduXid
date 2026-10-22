<?php

use Illuminate\Support\Facades\DB;

/*
 * [FIX-CHIP v2] Pulihkan sync_id PWA — kini mencakup baris yang
 * NULL (selain yang tertimpa 'lan::'). Sumber kebenaran: payload
 * event cbt_answer synced (sync_id UUID buatan PWA).
 */

 $records = DB::table('lms_sync_records')
    ->where('entity_type', 'cbt_answer')
    ->where('status', 'synced')
    ->orderByDesc('id')
    ->limit(200)
    ->get(['entity_id', 'payload']);

 $fixed = 0;
 $seen = [];

foreach ($records as $rec) {
    $payload = is_array($rec->payload)
        ? $rec->payload
        : (json_decode((string) $rec->payload, true) ?: []);

    $sid = trim((string) ($payload['sync_id'] ?? $rec->entity_id ?? ''));

    if ($sid === '' || str_starts_with($sid, 'lan::')) {
        continue;
    }
    if (isset($seen[$sid])) { continue; }
    $seen[$sid] = true;

    $nisn = trim((string) ($payload['student_id'] ?? ''));
    if ($nisn === '') { continue; }

    $student = DB::table('students')->where('nisn', $nisn)->first(['id', 'nama']);
    if (!$student) { continue; }

    $updated = DB::table('exam_sessions')
        ->where('student_id', $student->id)
        ->where(function ($q) {
            $q->whereNull('sync_id')->orWhere('sync_id', 'like', 'lan::%');
        })
        ->update(['sync_id' => $sid]);

    if ($updated > 0) {
        echo "FIX: {$student->nama} ({$nisn}) → sync_id={$sid}\n";
        $fixed++;
    }
}

echo "selesai: {$fixed} sesi dipulihkan ke sync_id PWA\n";