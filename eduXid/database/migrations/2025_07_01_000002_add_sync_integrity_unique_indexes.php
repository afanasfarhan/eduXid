<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * ============================================================
     * [FASE-0 / P3-12 / M-03 / MR-01 / DL-04 / CB-08]
     * INDEX UNIK INTEGRITAS SINKRONISASI — dedupe dulu, index kemudian
     * ============================================================
     *
     * Urutan wajib: dedupe baris duplikat warisan (pemenang = versi
     * tertinggi → updated_at terbaru → id terbesar), baru pasang
     * index unik. Tanpa dedupe, migrasi gagal di data lama.
     *
     * Keputusan sadar:
     *   - lms_sync_records TIDAK diberi unique komposit. Alasan:
     *     (1) kolom target nullable — SQLite/MySQL memperlakukan NULL
     *     sebagai berbeda-beda, sehingga constraint tidak efektif
     *     untuk teacher-routed (target NULL); (2) re-push versi sama
     *     pada record 'synced' adalah operasi sah. Ganti: index biasa
     *     untuk performa lookup retryPending/ledger.
     *   - Baris duplikat TIDAK dihapus untuk exam yang kehilangan
     *     jawaban — jawaban sesi kalah di-merge ke sesi pemenang.
     */

    public function up(): void
    {
        $changedTokens = $this->regenerateDuplicateExamTokens();
        $mergedSessions = $this->dedupeExamSessions();

        $deduped = [
            'attendances'            => $this->dedupeGeneric('attendances', ['student_id', 'teaching_assignment_id', 'tanggal']),
            'assessment_scores'      => $this->dedupeGeneric('assessment_scores', ['student_id', 'teaching_assignment_id', 'tanggal']),
            'final_grades'           => $this->dedupeGeneric('final_grades', ['student_id', 'teaching_assignment_id']),
            'lms_assignment_submissions' => $this->dedupeGeneric('lms_assignment_submissions', ['sync_id'], true),
            'app_settings'           => $this->dedupeGeneric('app_settings', ['key']),
            'sync_dead_letters'      => $this->dedupeGeneric('sync_dead_letters', ['event_id', 'device_id']),
        ];

        Log::info('[FASE-0] Dedupe integritas sinkron selesai.', [
            'exam_tokens_diregenerasi' => $changedTokens,
            'exam_sessions_dimerge'    => $mergedSessions,
            'baris_dihapus'            => $deduped,
        ]);

        $this->addUnique('attendances', ['student_id', 'teaching_assignment_id', 'tanggal']);
        $this->addUnique('assessment_scores', ['student_id', 'teaching_assignment_id', 'tanggal']);
        $this->addUnique('final_grades', ['student_id', 'teaching_assignment_id']);
        $this->addUnique('exam_sessions', ['exam_id', 'student_id']);
        $this->addUnique('exams', ['token']);
        $this->addUnique('app_settings', ['key']);
        $this->addUnique('sync_dead_letters', ['event_id', 'device_id']);
        $this->addUnique('lms_assignment_submissions', ['sync_id']);

        $this->addIndexSafe(
            'lms_sync_records',
            ['entity_type', 'entity_id', 'status'],
            'lms_sync_records_entity_status_index'
        );
    }

    public function down(): void
    {
        // Forward-only.
    }

    /* ============================================================
     * DEDUPE
     * ============================================================ */

    /**
     * Hapus baris duplikat pada kelompok kolom. Pemenang:
     * sync_version tertinggi → updated_at terbaru → id terbesar.
     * $ignoreNullGroup: kelompok dengan SEMUA kolom NULL dilewati
     * (index unik memang mengizinkan banyak NULL).
     */
    protected function dedupeGeneric(string $table, array $groupCols, bool $ignoreNullGroup = false): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $select = array_merge(['id'], $groupCols);
        $hasVersion = Schema::hasColumn($table, 'sync_version');
        $hasUpdated = Schema::hasColumn($table, 'updated_at');

        if ($hasVersion) { $select[] = 'sync_version'; }
        if ($hasUpdated) { $select[] = 'updated_at'; }

        $rows = DB::table($table)->select($select)->orderBy('id')->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = implode('||', array_map(
                static fn (string $col): string => (string) ($row->{$col} ?? ''),
                $groupCols
            ));

            if ($ignoreNullGroup && trim($key, '|') === '') {
                continue;
            }

            $groups[$key][] = $row;
        }

        $deleted = 0;

        foreach ($groups as $list) {
            if (count($list) < 2) {
                continue;
            }

            usort($list, static function ($a, $b) use ($hasVersion, $hasUpdated) {
                if ($hasVersion) {
                    $va = (int) ($a->sync_version ?? 0);
                    $vb = (int) ($b->sync_version ?? 0);
                    if ($va !== $vb) {
                        return $vb <=> $va;
                    }
                }
                if ($hasUpdated) {
                    $cmp = strcmp((string) ($b->updated_at ?? ''), (string) ($a->updated_at ?? ''));
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return $b->id <=> $a->id;
            });

            $keepId = (int) $list[0]->id;
            $dropIds = array_values(array_filter(
                array_map(static fn ($r) => (int) $r->id, $list),
                static fn (int $id): bool => $id !== $keepId
            ));

            if ($dropIds !== []) {
                DB::table($table)->whereIn('id', $dropIds)->delete();
                $deleted += count($dropIds);
            }
        }

        return $deleted;
    }

    /**
     * Duplikat (exam_id, student_id) [CB-08]: pemenang = sesi dengan
     * jawaban terbanyak → status 'selesai' → versi → id. Jawaban sesi
     * kalah di-merge ke pemenang bila pemenang masih kosong.
     */
    protected function dedupeExamSessions(): int
    {
        if (!Schema::hasTable('exam_sessions')) {
            return 0;
        }

        $rows = DB::table('exam_sessions')
            ->select('id', 'exam_id', 'student_id', 'status', 'sync_version')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($rows as $r) {
            $groups[$r->exam_id . '||' . $r->student_id][] = $r;
        }

        $deleted = 0;

        foreach ($groups as $list) {
            if (count($list) < 2) {
                continue;
            }

            $scored = [];
            foreach ($list as $row) {
                $scored[] = [
                    'row'     => $row,
                    'answers' => Schema::hasTable('student_answers')
                        ? DB::table('student_answers')->where('exam_session_id', $row->id)->count()
                        : 0,
                ];
            }

            usort($scored, static function ($a, $b) {
                return $b['answers'] <=> $a['answers']
                    ?: ((int) ($b['row']->status === 'selesai') <=> (int) ($a['row']->status === 'selesai'))
                    ?: ((int) ($b['row']->sync_version ?? 0) <=> (int) ($a['row']->sync_version ?? 0))
                    ?: ($b['row']->id <=> $a['row']->id);
            });

            $keepId = (int) $scored[0]['row']->id;
            $keepAnswers = (int) $scored[0]['answers'];

            foreach (array_slice($scored, 1) as $cand) {
                $loser = $cand['row'];

                if ($keepAnswers === 0 && $cand['answers'] > 0) {
                    try {
                        DB::table('student_answers')
                            ->where('exam_session_id', $loser->id)
                            ->update(['exam_session_id' => $keepId]);
                        $keepAnswers = $cand['answers'];
                    } catch (Throwable) {
                        // Duplikat soal antar sesi — jawaban ikut terhapus.
                    }
                }

                DB::table('exam_sessions')->where('id', $loser->id)->delete();
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Token exam duplikat/kosong [EX-03]: baris terlama mempertahankan
     * tokennya, sisanya diganti token acak unik (baris TIDAK dihapus).
     */
    protected function regenerateDuplicateExamTokens(): int
    {
        if (!Schema::hasTable('exams') || !Schema::hasColumn('exams', 'token')) {
            return 0;
        }

        $rows = DB::table('exams')->select('id', 'token')->orderBy('id')->get();

        $taken = [];
        $toRegenerate = [];

        foreach ($rows as $row) {
            $token = trim((string) ($row->token ?? ''));

            if ($token === '') {
                $toRegenerate[] = (int) $row->id;
                continue;
            }

            if (isset($taken[$token])) {
                $toRegenerate[] = (int) $row->id;
                continue;
            }

            $taken[$token] = (int) $row->id;
        }

        $changed = 0;

        foreach ($toRegenerate as $id) {
            do {
                $new = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while (isset($taken[$new]));

            $taken[$new] = $id;

            DB::table('exams')->where('id', $id)->update([
                'token'      => $new,
                'updated_at' => now(),
            ]);

            $changed++;
        }

        return $changed;
    }

    /* ============================================================
     * INDEX
     * ============================================================ */

    protected function indexExists(string $table, string $indexName): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (strcasecmp((string) ($index['name'] ?? ''), $indexName) === 0) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Deteksi tidak tersedia — biarkan try/catch di addUnique bekerja.
        }

        return false;
    }

    protected function addUnique(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) {
                return;
            }
        }

        $name = $table . '_' . implode('_', $columns) . '_unique';

        if ($this->indexExists($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                $t->unique($columns, $name);
            });
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());

            if (str_contains($message, 'already exists')) {
                return;
            }

            if (str_contains($message, 'unique constraint failed') || str_contains($message, 'duplicate entry')) {
                throw new RuntimeException(
                    "Index unik {$name} gagal: masih ada data duplikat di '{$table}'. "
                    . 'Perbaiki data lalu jalankan migrasi ulang. Detail: ' . $e->getMessage()
                );
            }

            throw $e;
        }
    }

    protected function addIndexSafe(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) {
                return;
            }
        }

        if ($this->indexExists($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                $t->index($columns, $name);
            });
        } catch (Throwable) {
            // Index performa — kegagalan tidak fatal.
        }
    }
};