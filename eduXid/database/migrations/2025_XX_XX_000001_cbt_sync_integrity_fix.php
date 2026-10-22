<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * [FRESH-FIX] Migrasi perbaikan-data lama: di database fresh
         * tabel CBT belum ada (dibuat oleh step impor/instalasi lain).
         * Semua operasi kini di-guard — di fresh install: skip aman;
         * di laptop lama: dedupe tetap jalan.
         */

        if (Schema::hasTable('exam_sessions')) {
            DB::statement(
                'DELETE FROM exam_sessions WHERE id NOT IN '
                . '(SELECT MAX(id) FROM exam_sessions GROUP BY exam_id, student_id)'
            );
        }

        if (Schema::hasTable('exams')) {
            DB::statement(
                'DELETE FROM exams WHERE id NOT IN '
                . '(SELECT MAX(id) FROM exams GROUP BY token)'
            );
        }

        // ... blok operasi lain di file ini, bungkus sama polanya:
        // if (Schema::hasTable('<tabel>')) { DB::statement(...); }
    }

    public function down(): void
    {
        // Forward-only.
    }
};