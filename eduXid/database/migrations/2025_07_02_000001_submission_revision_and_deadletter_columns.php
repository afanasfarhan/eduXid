<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * ============================================================
     * [F1 / P0-3 / D21 / P3-1] KOLOM REVISI SUBMISSION + USIA RETRY
     * ============================================================
     *  - lms_assignment_submissions:
     *      grade_version   — LWW domain guru (nilai/feedback/revisi).
     *      answer_version  — LWW domain siswa (jawaban/foto/status).
     *      revision_unlocked_at — stempel event submission_unlock
     *                            (guru membuka kunci revisi per siswa).
     *      score_type      — jenis nilai harian dari penilaian tugas
     *                        (diagnostik/formatif/sumatif) — D4.
     *  - sync_dead_letters:
     *      first_seen_at   — dasar limit retry BERBASIS WAKTU
     *                        (P3-1; DK-01: limit siklus 720 @ 5 dtk
     *                        hanya = 1 jam, CBT ingest 5 menit).
     * Semua idempoten & forward-only.
     */

    public function up(): void
    {
        if (Schema::hasTable('lms_assignment_submissions')) {
            Schema::table('lms_assignment_submissions', function (Blueprint $t) {
                if (!Schema::hasColumn('lms_assignment_submissions', 'grade_version')) {
                    $t->unsignedBigInteger('grade_version')->default(0)->nullable();
                }
                if (!Schema::hasColumn('lms_assignment_submissions', 'answer_version')) {
                    $t->unsignedBigInteger('answer_version')->default(0)->nullable();
                }
                if (!Schema::hasColumn('lms_assignment_submissions', 'revision_unlocked_at')) {
                    $t->timestamp('revision_unlocked_at')->nullable();
                }
                if (!Schema::hasColumn('lms_assignment_submissions', 'score_type')) {
                    $t->string('score_type', 20)->nullable();
                }
            });
        }

        if (Schema::hasTable('sync_dead_letters') && !Schema::hasColumn('sync_dead_letters', 'first_seen_at')) {
            Schema::table('sync_dead_letters', function (Blueprint $t) {
                $t->timestamp('first_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Forward-only.
    }
};