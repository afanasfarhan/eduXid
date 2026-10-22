<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\OpaqueRouteRegistryV21;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * V21R17 STAGING-only, read-only assessment/LJK readiness gate.
 * Does not open a write transaction, migrate, publish, sync, ACK or touch Cloud.
 * Use on a disposable Windows STAGING COPY of eduXid/eduxid only.
 */
final class EduxidLjkV21r17Preflight extends Command
{
    protected $signature = 'eduxid:ljk-v21r17-preflight';
    protected $description = 'Read-only LJK V21R17 staging schema, unique index, daily-row diagnostics';

    public function handle(): int
    {
        $issues = [];
        $this->line('eduXid LJK V21R17 — PREFLIGHT READ-ONLY (COPY STAGING)');
        try {
            if (!OpaqueRouteRegistryV21::enabled()) {
                $issues[] = 'Registry alias anonim V21 belum aktif.';
            }
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'sqlite') {
                $issues[] = 'Hanya SQLite lokal yang didukung untuk penilaian staging.';
            }
            if ($connection->transactionLevel() !== 0) {
                $issues[] = 'Masih ada transaksi database terbuka.';
            }
            // Verify schema BEFORE making any row queries.
            $required = [
                'cloud_ljk_nonces_v21' => ['nonce_hash','school_id','teacher_id','source_hp','target_pc',
                    'session_ref','scan_id','status','receipt_hash'],
                'cloud_ljk_publications_v21r14' => ['school_id','teacher_id','session_ref','student_id',
                    'scan_id','nonce_hash','answers_sha256','quality_tag_hash','key_fingerprint',
                    'teaching_assignment_id','tanggal','score_type','correct_count','score','status'],
                'ljk_scan_results' => ['scan_id','exam_id','exam_sync_id','student_id',
                    'teaching_assignment_id','source_device_id','status','is_active','source_type',
                    'key_source','answers_json','score','correct_count','question_count','published_at','manual_keys_json'],
                'assessment_scores' => ['student_id','teaching_assignment_id','tanggal',
                    'diagnostik','formatif','sumatif'],
                'ljk_manual_sessions' => ['session_id','teacher_id','teaching_assignment_id',
                    'tanggal','jenis_penilaian','keys_json','key_fingerprint','deleted_at'],
                'cloud_ljk_pair_keys_v21' => ['school_id','teacher_id','source_hp','target_pc','encrypted_key'],
                'teachers' => [], 'students' => ['id','nisn'],
                'enrollments' => ['student_id','homeroom_id'],
                'teaching_assignments' => ['id','teacher_id','homeroom_id','subject_id'],
                'cloud_identity_alias_routes' => [],
            ];
            $complete = true;
            foreach ($required as $table => $columns) {
                if (!Schema::hasTable($table)) {
                    $issues[] = "Tabel hilang: {$table}";
                    $complete = false;
                    continue;
                }
                foreach ($columns as $column) {
                    if (!Schema::hasColumn($table, $column)) {
                        $issues[] = "Kolom hilang: {$table}.{$column}";
                        $complete = false;
                    }
                }
            }
            if ($connection->getDriverName() === 'sqlite' && $complete) {
                foreach ([
                    'cloud_ljk_nonces_v21' => [['nonce_hash']],
                    'cloud_ljk_publications_v21r14' => [
                        ['school_id','teacher_id','session_ref','student_id'],
                        ['school_id','teacher_id','session_ref','scan_id'],
                        ['nonce_hash'],
                        ['teacher_id','student_id','teaching_assignment_id','tanggal','score_type'],
                    ],
                    'ljk_scan_results' => [['scan_id']],
                    'ljk_manual_sessions' => [['session_id']],
                ] as $table => $indexColumns) {
                    $uniqueIndexes = [];
                    foreach (DB::select('PRAGMA index_list("'.$table.'")') as $index) {
                        if ((int) ($index->unique ?? 0) !== 1) continue;
                        $name = str_replace('"', '""', (string) $index->name);
                        $cols = DB::select('PRAGMA index_info("'.$name.'")');
                        $uniqueIndexes[] = implode('|', array_map(static fn ($c): string => (string) $c->name, $cols));
                    }
                    foreach ($indexColumns as $cols) {
                        if (!in_array(implode('|', $cols), $uniqueIndexes, true)) {
                            $issues[] = "Unique index hilang: {$table} (".implode(',', $cols).')';
                        }
                    }
                }
                // The legacy assessment_scores table may have multiple rows per student/day.
                // Reject ambiguity rather than choosing first() and ACKing a wrong value.
                $duplicate = DB::table('assessment_scores')
                    ->select('student_id', 'teaching_assignment_id', 'tanggal')
                    ->whereNotNull('tanggal')
                    ->groupBy('student_id', 'teaching_assignment_id', 'tanggal')
                    ->havingRaw('COUNT(*) > 1')->limit(1)->first();
                if ($duplicate !== null) {
                    $issues[] = 'Ada duplikasi baris Buku Nilai per siswa–penugasan–tanggal. ' .
                        'Perbaiki salinan staging secara terkontrol; V21R17 tidak menghapus apa pun.';
                }
            }
        } catch (Throwable $e) {
            // Avoid dumping SQL/DB paths or student data into logs.
            $issues[] = 'Koneksi atau metadata SQLite gagal diperiksa: '.get_class($e);
        }
        foreach ($issues as $issue) $this->warn('BLOCKED: '.$issue);
        if ($issues === []) {
            $this->info('PASS: skema, indeks unik dan duplikasi Buku Nilai tidak bermasalah pada COPY ini.');
        }
        $this->line('Tidak menulis nilai, ACK, nonce, database, pairing maupun Cloud.');
        $this->warn('Belum menguji Laravel publish nyata, identitas transport, kamera HP atau Cloudflare.');
        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
