<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\OpaqueRouteRegistryV21;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** V21R15 staging diagnostic. Read-only: no migrations, user data, grading, Cloud or ACK. */
final class EduxidLjkV21r15Preflight extends Command
{
    protected $signature = 'eduxid:ljk-v21r15-preflight';
    protected $description = 'Read-only V21R15 LJK inbound staging readiness; never publishes scores.';

    public function handle(): int
    {
        $this->line('eduXid V21R15 | LJK sealed inbound — PREFLIGHT HANYA BACA');
        $issues = [];
        try {
            if (!OpaqueRouteRegistryV21::enabled()) $issues[] = 'Mode alias v21-opaque belum aktif.';
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'sqlite') $issues[] = 'Staging memerlukan pemeriksaan SQLite.';
            foreach ([
                'teachers','students','enrollments','teaching_assignments','assessment_scores',
                'ljk_manual_sessions','ljk_scan_results','cloud_ljk_pair_keys_v21',
                'cloud_ljk_nonces_v21','cloud_ljk_publications_v21r14','cloud_identity_alias_routes',
            ] as $table) {
                if (!Schema::hasTable($table)) $issues[] = "Tabel belum ada: {$table}";
            }
            if (Schema::hasTable('ljk_scan_results')) {
                foreach (['manual_keys_json','key_source','published_at','scan_id'] as $column) {
                    if (!Schema::hasColumn('ljk_scan_results', $column)) {
                        $issues[] = "Kolom LJK belum ada: {$column}";
                    }
                }
            }
            if (Schema::hasTable('ljk_manual_sessions') && !Schema::hasColumn('ljk_manual_sessions','deleted_at')) {
                $issues[] = 'Soft-delete sesi manual belum tersedia.';
            }
            if ($connection->getDriverName() === 'sqlite') {
                $this->verifyUniqueIndexes($issues);
            }
        } catch (Throwable) {
            $issues[] = 'Database tidak dapat diperiksa: driver/koneksi/skema tidak siap.';
        }
        if (!$issues) {
            $this->info('PASS: struktur skema dan indeks transaksi staging tersedia.');
            $this->warn('BELUM LULUS: autentikasi pull, PWA kamera, Cloudflare, dan integrasi nyata PC–HP belum diuji.');
        } else {
            foreach ($issues as $issue) $this->warn('BLOCKED: '.$issue);
        }
        $this->line('Tidak ada database, outbox, nilai, identitas atau kunci yang dimodifikasi.');
        return $issues ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<string> $issues */
    private function verifyUniqueIndexes(array &$issues): void
    {
        $checks = [
            'cloud_ljk_publications_v21r14' => [
                ['school_id','teacher_id','session_ref','student_id'],
                ['school_id','teacher_id','session_ref','scan_id'],
                ['nonce_hash'],
                ['teacher_id','student_id','teaching_assignment_id','tanggal','score_type'],
            ],
            'cloud_ljk_nonces_v21' => [['nonce_hash']],
            'ljk_scan_results' => [['scan_id']],
        ];
        foreach ($checks as $table => $expected) {
            if (!Schema::hasTable($table)) continue;
            $unique = [];
            foreach (DB::select('PRAGMA index_list("'.$table.'")') as $idx) {
                if ((int)($idx->unique ?? 0) !== 1) continue;
                $name = (string)$idx->name;
                // Index name originates from trusted local SQLite metadata, escaped as identifier.
                $info = DB::select('PRAGMA index_info("'.str_replace('"','""',$name).'")');
                $columns = array_map(static fn ($row): string => (string)$row->name, $info);
                $unique[] = implode('|', $columns);
            }
            foreach ($expected as $columns) {
                if (!in_array(implode('|',$columns), $unique, true)) {
                    $issues[] = 'Indeks unik wajib tidak tersedia pada tabel '.$table.' ('.count($columns).' kolom).';
                }
            }
        }
    }
}
